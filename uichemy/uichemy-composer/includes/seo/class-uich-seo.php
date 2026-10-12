<?php
/**
 * UiChemy SEO — module bootstrap.
 *
 * Its own module (D18): no dependency on the builder sequence, and no edits to
 * Composer's files (D16). It reads Composer through the existing driver layer
 * and hooks WordPress storage and output.
 *
 * The module always derives (cheap, at save time) so that switching output back
 * on restores everything; the `module_enabled` setting gates OUTPUT only.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO' ) ) {

	/**
	 * Bootstrap.
	 */
	class Uich_SEO {

		const VERSION = '1.0.0-dev';

		/**
		 * When the SEO feature was switched off (a timestamp), while it is off.
		 */
		const PAUSED_OPTION = 'uichemy_seo_paused_at';

		/**
		 * Booted?
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Load and wire the module.
		 *
		 * @return void
		 */
		public static function init() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			/**
			 * Kill switch for hosts that must not load the module at all.
			 *
			 * @param bool $load Load the SEO module.
			 */
			if ( ! apply_filters( 'uichemy_seo_load', true ) ) {
				return;
			}

			$dir = __DIR__ . '/';
			require_once $dir . 'class-uich-seo-html.php';
			require_once $dir . 'class-uich-seo-head.php';

			// Settings > Features > SEO, which is the same value as SEO's own switch
			// (module_enabled). Off unloads the module - no tab, editor panel, REST,
			// MCP ability, sitemap, redirects or AI files - with ONE exception: SEO
			// values live as tags in page and site head code, and without the module
			// those would print raw, giving a site that switched to Yoast two titles
			// on every page. So the tags are still stripped, and nothing is printed.
			if ( function_exists( 'uich_feature_enabled' ) && ! uich_feature_enabled( 'seo' ) ) {
				add_filter( 'uichemy_custom_code_output', array( __CLASS__, 'strip_head_tags' ), 10, 3 );
				// Saves made while off derive nothing; the next boot with SEO on re-reads them.
				if ( ! get_option( self::PAUSED_OPTION ) ) {
					update_option( self::PAUSED_OPTION, time(), false );
				}
				return;
			}

			require_once $dir . 'class-uich-seo-deriver.php';
			require_once $dir . 'class-uich-seo-settings.php';
			require_once $dir . 'class-uich-seo-sections.php';
			require_once $dir . 'class-uich-seo-record.php';
			require_once $dir . 'class-uich-seo-ownership.php';
			require_once $dir . 'class-uich-seo-resolver.php';
			require_once $dir . 'class-uich-seo-emitter.php';
			require_once $dir . 'class-uich-seo-audit.php';
			require_once $dir . 'class-uich-seo-mcp.php';
			require_once $dir . 'class-uich-seo-redirects.php';
			require_once $dir . 'class-uich-seo-crawl.php';
			require_once $dir . 'class-uich-seo-ai.php';
			require_once $dir . 'class-uich-seo-schema.php';
			require_once $dir . 'class-uich-seo-import.php';
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once $dir . 'class-uich-seo-cli.php';
			}
			require_once $dir . 'class-uich-seo-rest.php';
			require_once $dir . 'class-uich-seo-admin.php';

			Uich_SEO_Record::init();
			Uich_SEO_Audit::init();
			Uich_SEO_Emitter::init();
			Uich_SEO_MCP::init();
			Uich_SEO_Redirects::init();
			Uich_SEO_Crawl::init();
			Uich_SEO_AI::init();
			Uich_SEO_Schema::init();
			Uich_SEO_Rest::init();
			// Bricks' builder is a front-end screen, so its mount cannot wait for is_admin().
			add_action( 'wp_enqueue_scripts', array( 'Uich_SEO_Admin', 'bricks_editor' ), 20 );
			if ( is_admin() ) {
				Uich_SEO_Admin::init();
			}

			add_action( 'uichemy_seo_settings_updated', array( 'Uich_SEO_Ownership', 'flush' ) );
			// SEO tags in site/page head code are not printed raw when the module prints
			// them itself (each one once); otherwise they are printed as written.
			add_filter( 'uichemy_custom_code_output', array( __CLASS__, 'strip_head_tags' ), 10, 3 );
			add_filter( 'uichemy_site_head_merge', array( __CLASS__, 'merge_site_head' ), 10, 3 );
			add_action( 'admin_init', array( __CLASS__, 'maybe_queue_first_build' ) );
			// Cleanup on delete lives with the rest of the plugin's in
			// uichemy-composer/uninstall.php (step 4), run from the root uninstall.php.
			add_action( 'admin_init', array( __CLASS__, 'maybe_catch_up' ) );
		}

		/**
		 * Once per module version: queue a background read of every published
		 * item that has no SEO data yet, so the Overview audit has results without
		 * anyone clicking "Refresh SEO data" first.
		 *
		 * @return void
		 */
		public static function maybe_queue_first_build() {
			if ( get_option( 'uichemy_seo_built_ver' ) === self::VERSION || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			update_option( 'uichemy_seo_built_ver', self::VERSION, false );
			$types = array();
			foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
				if ( 'attachment' !== $pt ) {
					$types[] = $pt;
				}
			}
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( array( 'key' => Uich_SEO_Record::META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $ids ) {
				Uich_SEO_Record::queue( $ids );
			}
		}

		/**
		 * After the SEO feature comes back on: re-read everything edited while it
		 * was off, because those saves derived nothing and the page data would
		 * otherwise be from before the switch. Queued in the background, like the
		 * first build.
		 *
		 * @return void
		 */
		public static function maybe_catch_up() {
			$since = (int) get_option( self::PAUSED_OPTION, 0 );
			if ( ! $since || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			delete_option( self::PAUSED_OPTION );

			$types = array();
			foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
				if ( 'attachment' !== $pt ) {
					$types[] = $pt;
				}
			}
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array(
						array(
							'column' => 'post_modified_gmt',
							'after'  => gmdate( 'Y-m-d H:i:s', $since ),
						),
					),
				)
			);
			if ( $ids ) {
				Uich_SEO_Record::queue( $ids );
			}
		}

		/**
		 * `uichemy_custom_code_output` — drop the SEO tags this module prints itself.
		 *
		 * Only on a request where the module actually prints the head (it owns the
		 * head, output is on and SEO is on for this content). Otherwise — another
		 * SEO plugin, output switched off, SEO off for this type — the owner's
		 * hand-written tags are printed as written rather than silently lost.
		 *
		 * @param string $code     Code about to print.
		 * @param string $scope    site | page.
		 * @param string $location head | body_end.
		 * @return string
		 */
		public static function strip_head_tags( $code, $scope, $location ) {
			unset( $scope );
			if ( 'head' !== $location ) {
				return $code;
			}
			// The SEO tags in head code are this module's stored values (Twig templates
			// included), not markup to print as written. When we emit, the emitter prints
			// them rendered; when we do not (SEO off, "Nothing", another plugin owns the
			// head, a draft or an SEO-off type) printing them raw gave several <title>
			// tags and literal "{{ title }}". JSON-LD still reaches another owner's graph
			// through the parsed head (Uich_SEO_Schema::external_*), so always strip.
			return Uich_SEO_Head::strip( (string) $code );
		}

		/**
		 * `uichemy_site_head_merge` — an editor saved the site head from a stale copy.
		 * Tag by tag: what the editor changed wins; what it did not touch keeps the
		 * value stored now (set from SEO → Site Settings or MCP since it loaded).
		 *
		 * @param string $head   Head the editor sent.
		 * @param string $base   Head the editor started from.
		 * @param string $stored Head stored now.
		 * @return string
		 */
		public static function merge_site_head( $head, $base, $stored ) {
			return Uich_SEO_Head::compose(
				$head,
				Uich_SEO_Head::merge3( Uich_SEO_Head::parse( $base ), Uich_SEO_Head::parse( $head ), Uich_SEO_Head::parse( $stored ) )
			);
		}

		/**
		 * Is output on (module switch)?
		 *
		 * @return bool
		 */
		public static function output_enabled() {
			return (bool) Uich_SEO_Settings::get( 'module_enabled', true );
		}
	}
}
