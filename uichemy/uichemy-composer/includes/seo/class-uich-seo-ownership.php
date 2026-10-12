<?php
/**
 * Who writes the head (D7, 02 §6). Resolved per request, never cached in an option,
 * so installing Yoast six months later stops our output on the very next request.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Ownership' ) ) {

	/**
	 * Ownership resolver.
	 */
	class Uich_SEO_Ownership {

		/**
		 * Request cache.
		 *
		 * @var array|null
		 */
		private static $cache = null;

		/**
		 * Known SEO plugins: slug => [ label, detector ].
		 *
		 * @return array<string,array{0:string,1:callable}>
		 */
		private static function known() {
			return array(
				'yoast'     => array( 'Yoast SEO', function () {
					return defined( 'WPSEO_VERSION' );
				} ),
				'rankmath'  => array( 'Rank Math', function () {
					return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
				} ),
				'surerank'  => array( 'SureRank', function () {
					return defined( 'SURERANK_VERSION' ) || defined( 'SURERANK_FILE' );
				} ),
				'aioseo'    => array( 'All in One SEO', function () {
					return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
				} ),
				'seopress'  => array( 'SEOPress', function () {
					return defined( 'SEOPRESS_VERSION' );
				} ),
				'tsf'       => array( 'The SEO Framework', function () {
					return defined( 'THE_SEO_FRAMEWORK_VERSION' );
				} ),
				'slimseo'   => array( 'Slim SEO', function () {
					return defined( 'SLIM_SEO_VER' );
				} ),
				// Nexter Extension loads this class only while its SEO module switch is on.
				'nexter'    => array( 'Nexter SEO', function () {
					return class_exists( 'Nexter_Content_SEO', false );
				} ),
			);
		}

		/**
		 * The first active competing SEO plugin, or null.
		 *
		 * @return array{slug:string,label:string}|null
		 */
		public static function competitor() {
			foreach ( self::known() as $slug => $def ) {
				if ( call_user_func( $def[1] ) ) {
					return array(
						'slug'  => $slug,
						'label' => $def[0],
					);
				}
			}
			/**
			 * Filter: report another SEO plugin we do not know about.
			 *
			 * @param array|null $competitor { slug, label } or null.
			 */
			$custom = apply_filters( 'uichemy_seo_competitor', null );
			return is_array( $custom ) && ! empty( $custom['label'] ) ? $custom : null;
		}

		/**
		 * Resolve ownership.
		 *
		 * @return array{owner:string,mode:string,competitor:?array,conflict:bool}
		 *         owner ∈ uichemy | external | off.
		 */
		public static function resolve() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}
			$mode       = (string) Uich_SEO_Settings::get( 'head_owner', 'auto' );
			$competitor = self::competitor();
			$conflict   = false;

			if ( ! Uich_SEO_Settings::get( 'module_enabled', true ) || 'off' === $mode ) {
				$owner = 'off';
			} elseif ( 'external' === $mode ) {
				$owner = 'external';
			} elseif ( $competitor ) {
				// auto steps aside; forced uichemy also steps aside and raises a blocker
				// rather than shipping duplicate titles and canonicals.
				$owner    = 'external';
				$conflict = ( 'uichemy' === $mode );
			} else {
				$owner = 'uichemy';
			}

			self::$cache = array(
				'owner'      => $owner,
				'mode'       => $mode,
				'competitor' => $competitor,
				'conflict'   => $conflict,
			);
			return self::$cache;
		}

		/**
		 * Do we write head tags on this request?
		 *
		 * @return bool
		 */
		public static function we_emit() {
			$r = self::resolve();
			return 'uichemy' === $r['owner'];
		}

		/**
		 * Forget the request cache (tests, settings save).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$cache = null;
		}

		/**
		 * PRD §12.1 live status line.
		 *
		 * @return string
		 */
		public static function status_text() {
			$r = self::resolve();
			if ( 'off' === $r['owner'] ) {
				return __( 'SEO output is switched off. Nothing is being added to your pages.', 'uichemy' );
			}
			if ( $r['competitor'] ) {
				/* translators: %s: plugin name. */
				return sprintf( __( '%s is installed, so it handles SEO and this plugin stays out of the way. Your settings are kept and ready if you switch.', 'uichemy' ), $r['competitor']['label'] );
			}
			if ( 'external' === $r['owner'] ) {
				return __( 'This plugin is not handling SEO right now. Your settings are kept and ready if you switch.', 'uichemy' );
			}
			return __( 'No other SEO plugin is installed. This plugin is handling SEO.', 'uichemy' );
		}
	}
}
