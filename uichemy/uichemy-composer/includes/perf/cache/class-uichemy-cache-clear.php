<?php
/**
 * Page cache clear map (docs/page-cache/PLAN.md section 6).
 *
 * Content events clear our files straight away (each URL once per request,
 * "everything" once), so a page rendered later in the same request, or by a
 * loopback it makes, never sees the old copy. Clearing only deletes files;
 * nothing is rebuilt inside the save request. At the end of the request,
 * `uichemy/cache/cleared` fires once so host and Cloudflare adapters clear the
 * same pages with one call.
 *
 * Smart mode (C08 = smart): a post change clears that post (old and new URL),
 * home, the posts page, its archives, its terms and its author. Global changes
 * (menus, templates, global styles, builder CSS, plugins, permalinks) always
 * clear everything. "all" mode clears everything for post changes too.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Clear' ) ) {

	class UiChemy_Cache_Clear {

		/** @var bool */
		private static $all = false;

		/** @var string[] */
		private static $urls = array();

		/** @var array<int,string> Permalinks before an update, for slug changes. */
		private static $old_links = array();

		/** @var bool */
		private static $hooked = false;

		/** @var bool flush() is running: its own writes must not queue more clears. */
		private static $flushing = false;

		/** @var bool Everything already cleared in this request. */
		private static $all_done = false;

		/** @var array<string,true> URLs already cleared in this request. */
		private static $done = array();

		/** @var string Why everything is being cleared (for the log). */
		public static $why = '';

		/** Options whose change clears everything. */
		const GLOBAL_OPTIONS = array(
			'uichemy_globals_variable',
			'uichemy_globals_class',
			'uichemy_composer_site_custom_code',
			'uichemy_composer_site_deps',
			'uichemy_settings',
			'uichemy_fonts',
			'permalink_structure',
			'category_base',
			'tag_base',
			'sidebars_widgets',
			'blogname',
			'blogdescription',
			'site_icon',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'elementor_active_kit',
		);

		/** Post types whose change clears everything (templates and global styles). */
		const GLOBAL_TYPES = array(
			'uichemy_template',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'elementor_library',
			'bricks_template',
			// Synced patterns appear inside other pages (gap analysis H4).
			'wp_block',
			// Header/footer/layout parts of other themes and builders. Absent types cost nothing.
			'gp_elements',
			'kadence_element',
			'astra-advanced-hook',
			'et_template',
			'et_header_layout',
			'et_body_layout',
			'et_footer_layout',
			'ct_template',
			'breakdance_template',
			'breakdance_header',
			'breakdance_footer',
			'breakdance_block',
			'fl-theme-layout',
			'fl-builder-template',
		);

		/** Option: the last clears, newest first (what, why, where from). */
		const LOG_OPTION = 'uichemy_cache_clear_log';

		/** Transient: a clear-all from a visitor's request happened recently. */
		const FRONT_THROTTLE = 'uichemy_cache_front_clear';

		/**
		 * Hooks.
		 *
		 * @return void
		 */
		public static function init() {
			// Posts, pages, custom post types.
			add_action( 'pre_post_update', array( __CLASS__, 'remember_link' ) );
			add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
			add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
			add_action( 'clean_post_cache', array( __CLASS__, 'on_clean_post_cache' ), 10, 2 );

			// Comments.
			add_action( 'wp_insert_comment', array( __CLASS__, 'on_comment' ), 10, 2 );
			add_action( 'edit_comment', array( __CLASS__, 'on_comment_id' ) );
			add_action( 'wp_set_comment_status', array( __CLASS__, 'on_comment_id' ) );
			add_action( 'delete_comment', array( __CLASS__, 'on_comment_id' ) );

			// Terms.
			add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
			add_action( 'pre_delete_term', array( __CLASS__, 'on_term_delete' ), 10, 2 );

			// Everything.
			add_action( 'wp_update_nav_menu', array( __CLASS__, 'all_menu' ) );
			add_action( 'customize_save_after', array( __CLASS__, 'all_customizer' ) );
			add_action( 'switch_theme', array( __CLASS__, 'all_theme' ) );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'all_update' ), 10, 2 );
			add_action( 'activated_plugin', array( __CLASS__, 'all_plugins' ) );
			add_action( 'deactivated_plugin', array( __CLASS__, 'all_plugins' ) );
			add_action( 'updated_option', array( __CLASS__, 'on_option' ) );
			add_action( 'added_option', array( __CLASS__, 'on_option' ) );

			// ACF: options pages clear everything; post fields clear that post.
			add_action( 'acf/save_post', array( __CLASS__, 'on_acf' ), 20 );

			// WooCommerce stock changes made outside a product save. Hook names to
			// confirm on a real install (research grade C).
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product' ) );
			add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product' ) );
			add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_product' ) );

			// Builders (K08).
			add_action( 'uichemy/builder/after_write', array( __CLASS__, 'on_builder_write' ) );
			add_action( 'uichemy/theme_builder/changed', array( __CLASS__, 'all_templates' ) );
			add_action( 'uichemy/code_files/written', array( __CLASS__, 'all_builder_css' ) );
			add_action( 'elementor/document/after_save', array( __CLASS__, 'on_elementor_save' ) );
			add_action( 'elementor/core/files/clear_cache', array( __CLASS__, 'all_builder_css' ) );
			// Optimisation plugins that delete their combined CSS/JS: cached pages would point at missing files.
			add_action( 'autoptimize_action_cachepurged', array( __CLASS__, 'all_builder_css' ) );
			add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
			add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		}

		// ------------------------------------------------------------------
		// Queue.
		// ------------------------------------------------------------------

		/**
		 * Clear everything at the end of the request.
		 *
		 * @param string $why Short reason for the log.
		 * @return void
		 */
		public static function queue_all( $why = '' ) {
			// A theme or plugin that writes an option during a visitor's request would
			// otherwise empty the cache on every page view: allow that once a minute.
			if ( self::is_visitor_request() ) {
				if ( get_transient( self::FRONT_THROTTLE ) ) {
					return;
				}
				set_transient( self::FRONT_THROTTLE, 1, MINUTE_IN_SECONDS );
			}
			self::$all = true;
			if ( '' === self::$why ) {
				self::$why = $why;
			}
			if ( ! self::$all_done ) {
				self::$all_done = true;
				UiChemy_Cache_Files::clear_all();
				if ( class_exists( 'UiChemy_Cache_Host_Purge' ) ) {
					UiChemy_Cache_Host_Purge::early( 'all' );
				}
			}
			self::hook_flush();
		}

		/**
		 * Clear these URLs at the end of the request.
		 *
		 * @param string[] $urls Absolute URLs.
		 * @return void
		 */
		public static function queue_urls( array $urls ) {
			$now = array();
			foreach ( $urls as $u ) {
				if ( is_string( $u ) && '' !== $u ) {
					self::$urls[] = $u;
					if ( ! isset( self::$done[ $u ] ) ) {
						self::$done[ $u ] = true;
						$now[]            = $u;
					}
				}
			}
			if ( $now && ! self::$all_done ) {
				UiChemy_Cache_Files::clear_urls( $now );
				if ( class_exists( 'UiChemy_Cache_Host_Purge' ) ) {
					UiChemy_Cache_Host_Purge::early( 'urls', $now );
				}
			}
			self::hook_flush();
		}

		/**
		 * Clear one post and its related pages (or everything in "all" mode).
		 *
		 * @param int $post_id Post.
		 * @return void
		 */
		public static function queue_post( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
				return;
			}
			if ( in_array( $post->post_type, self::GLOBAL_TYPES, true ) ) {
				self::queue_all( 'template:' . $post->post_type );
				return;
			}
			if ( ! self::cacheable_type( $post->post_type ) ) {
				return;
			}
			if ( 'all' === UiChemy_Cache_Settings::get()['clear_mode'] ) {
				self::queue_all( 'post' );
				return;
			}
			self::queue_urls( self::related_urls( $post ) );
		}

		/**
		 * @return void
		 */
		private static function hook_flush() {
			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
			}
		}

		/**
		 * End of request: tell host and Cloudflare adapters once. Public so tests
		 * and long CLI runs can flush now.
		 *
		 * @return array{all:bool,urls:string[]}
		 */
		public static function flush() {
			if ( self::$flushing ) {
				return array(
					'all' => false,
					'urls' => array(),
				);
			}
			$all  = self::$all;
			$why  = self::$why;
			$urls = array_values( array_unique( self::$urls ) );
			self::$all      = false;
			self::$urls     = array();
			self::$hooked   = false;
			self::$why      = '';
			self::$all_done = false;
			self::$done     = array();
			if ( ! $all && ! $urls ) {
				return array(
					'all' => false,
					'urls' => array(),
				);
			}
			// Clear again at the end: terms, featured image and meta are often saved
			// after the post itself, and a visit in between may have stored the old page.
			self::$flushing = true;
			try {
				if ( $all ) {
					UiChemy_Cache_Files::clear_all();
				} else {
					UiChemy_Cache_Files::clear_urls( $urls );
				}
				self::log( $all ? 'all' : 'urls', $all ? $why : count( $urls ) . ' pages' );
			} finally {
				self::$flushing = false;
			}
			if ( $all ) {
				do_action( 'uichemy/cache/cleared', 'all', array() );
			} else {
				do_action( 'uichemy/cache/cleared', 'urls', $urls );
			}
			return array(
				'all' => $all,
				'urls' => $all ? array() : $urls,
			);
		}

		/**
		 * A front-end page view by someone who can't edit (not admin, REST, cron or CLI).
		 *
		 * @return bool
		 */
		private static function is_visitor_request() {
			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return false;
			}
			return ! ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) );
		}

		/**
		 * Keep the last 20 clears for diagnostics (status card and AI).
		 *
		 * @param string $scope all | urls.
		 * @param string $what  Reason, or how many pages.
		 * @return void
		 */
		private static function log( $scope, $what ) {
			$log = get_option( self::LOG_OPTION, array() );
			$log = is_array( $log ) ? $log : array();
			if ( wp_doing_cron() ) {
				$from = 'cron';
			} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
				$from = 'cli';
			} elseif ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				$from = 'editor';
			} else {
				$from = 'visitor';
			}
			array_unshift(
				$log,
				array(
					't' => time(),
					'scope' => $scope,
					'why' => (string) $what,
					'from' => $from,
				)
			);
			update_option( self::LOG_OPTION, array_slice( $log, 0, 20 ), false );
		}

		/**
		 * The last clears, newest first.
		 *
		 * @param int $n How many.
		 * @return array<int,array{t:int,scope:string,why:string,from:string}>
		 */
		public static function recent( $n = 5 ) {
			$log = get_option( self::LOG_OPTION, array() );
			return array_slice( is_array( $log ) ? $log : array(), 0, $n );
		}

		// ------------------------------------------------------------------
		// Which pages a post change touches.
		// ------------------------------------------------------------------

		/**
		 * Is a post type cached at all? Public and publicly queryable (pages are
		 * public but not publicly_queryable in core).
		 *
		 * @param string $type Post type.
		 * @return bool
		 */
		public static function cacheable_type( $type ) {
			$obj = get_post_type_object( $type );
			return $obj && $obj->public && ( $obj->publicly_queryable || 'page' === $type );
		}

		/**
		 * The post, home, posts page, archives, terms, author, plus an old URL
		 * when the slug changed. Archive pages 2–5 are included.
		 *
		 * @param WP_Post $post Post.
		 * @return string[]
		 */
		public static function related_urls( WP_Post $post ) {
			$urls = array( home_url( '/' ) );
			$link = get_permalink( $post );
			if ( $link ) {
				$urls[] = $link;
			}
			if ( isset( self::$old_links[ $post->ID ] ) ) {
				$urls[] = self::$old_links[ $post->ID ];
			}

			$archives = array();
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page && 'post' === $post->post_type ) {
				$archives[] = get_permalink( $posts_page );
			}
			$type_archive = get_post_type_archive_link( $post->post_type );
			if ( $type_archive ) {
				$archives[] = $type_archive;
			}
			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
				if ( ! $tax->public ) {
					continue;
				}
				$terms = get_the_terms( $post, $tax->name );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$t = get_term_link( $term );
					if ( ! is_wp_error( $t ) ) {
						$archives[] = $t;
					}
				}
			}
			if ( post_type_supports( $post->post_type, 'author' ) ) {
				$archives[] = get_author_posts_url( (int) $post->post_author );
			}
			$archives[] = home_url( '/' );

			foreach ( array_filter( $archives ) as $a ) {
				$urls[] = $a;
				for ( $n = 2; $n <= 5; $n++ ) {
					$urls[] = trailingslashit( $a ) . 'page/' . $n . '/';
				}
			}
			return array_values( array_unique( $urls ) );
		}

		// ------------------------------------------------------------------
		// Event handlers.
		// ------------------------------------------------------------------

		/**
		 * pre_post_update: remember the permalink before a possible slug change.
		 *
		 * @param int $post_id Post.
		 * @return void
		 */
		public static function remember_link( $post_id ) {
			if ( 'publish' === get_post_status( $post_id ) ) {
				$link = get_permalink( $post_id );
				if ( $link ) {
					self::$old_links[ (int) $post_id ] = $link;
				}
			}
		}

		/**
		 * transition_post_status: published, updated, unpublished, scheduled live.
		 *
		 * @param string  $new  New status.
		 * @param string  $old  Old status.
		 * @param WP_Post $post Post.
		 * @return void
		 */
		public static function on_transition( $new, $old, $post ) {
			if ( 'publish' !== $new && 'publish' !== $old ) {
				return;
			}
			if ( 'publish' !== $new && 'publish' === $old && ! isset( self::$old_links[ $post->ID ] ) ) {
				// Unpublished: its old address must go too.
				$link = get_permalink( $post );
				if ( $link ) {
					self::$old_links[ $post->ID ] = str_replace( '__trashed', '', $link );
				}
			}
			// A post appearing or disappearing changes "recent posts" lists, query
			// loops and menus on other pages too (gap analysis H4).
			if ( $new !== $old && self::cacheable_type( $post->post_type ) && ! wp_is_post_revision( $post ) ) {
				self::queue_all( 'publish:' . $post->post_type );
				return;
			}
			self::queue_post( $post->ID );
		}

		/**
		 * before_delete_post.
		 *
		 * @param int $post_id Post.
		 * @return void
		 */
		public static function on_delete( $post_id ) {
			if ( 'publish' === get_post_status( $post_id ) ) {
				self::queue_post( $post_id );
			}
		}

		/**
		 * clean_post_cache: other code changed a published post directly.
		 *
		 * @param int     $post_id Post.
		 * @param WP_Post $post    Post.
		 * @return void
		 */
		public static function on_clean_post_cache( $post_id, $post ) {
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				self::queue_post( $post_id );
			}
		}

		/**
		 * @param int        $id      Comment ID.
		 * @param WP_Comment $comment Comment.
		 * @return void
		 */
		public static function on_comment( $id, $comment ) {
			if ( $comment instanceof WP_Comment && '1' === (string) $comment->comment_approved ) {
				self::queue_comment_post( (int) $comment->comment_post_ID );
			}
		}

		/**
		 * @param int $id Comment ID.
		 * @return void
		 */
		public static function on_comment_id( $id ) {
			$comment = get_comment( $id );
			if ( $comment ) {
				self::queue_comment_post( (int) $comment->comment_post_ID );
			}
		}

		/**
		 * A comment change touches only its post.
		 *
		 * @param int $post_id Post.
		 * @return void
		 */
		private static function queue_comment_post( $post_id ) {
			$link = get_permalink( $post_id );
			if ( $link && 'publish' === get_post_status( $post_id ) ) {
				self::queue_urls( array( $link ) );
			}
		}

		/**
		 * edited_term: that term's archive and home.
		 *
		 * @param int    $term_id  Term.
		 * @param int    $tt_id    Term taxonomy.
		 * @param string $taxonomy Taxonomy.
		 * @return void
		 */
		public static function on_term( $term_id, $tt_id, $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! $tax->public ) {
				return;
			}
			if ( 'nav_menu' === $taxonomy ) {
				self::queue_all( 'menu' );
				return;
			}
			$link = get_term_link( (int) $term_id, $taxonomy );
			self::queue_urls( array_filter( array( home_url( '/' ), is_wp_error( $link ) ? '' : $link ) ) );
		}

		/**
		 * pre_delete_term: the archive goes away.
		 *
		 * @param int    $term_id  Term.
		 * @param string $taxonomy Taxonomy.
		 * @return void
		 */
		public static function on_term_delete( $term_id, $taxonomy ) {
			self::on_term( $term_id, 0, $taxonomy );
		}

		/**
		 * updated_option / added_option.
		 *
		 * @param string $option Option name.
		 * @return void
		 */
		public static function on_option( $option ) {
			// Our own bookkeeping (generation, log, diagnostics) never clears anything.
			if ( 0 === strpos( (string) $option, 'uichemy_cache_' ) || self::$flushing ) {
				return;
			}
			// Any settings page that saves through the Settings API (SEO titles,
			// header/footer scripts, theme options) can change every page.
			if ( is_admin() && isset( $GLOBALS['pagenow'] ) && 'options.php' === $GLOBALS['pagenow'] && 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
				self::queue_all( 'settings:' . $option );
				return;
			}
			if ( in_array( $option, self::GLOBAL_OPTIONS, true ) || 0 === strpos( $option, 'widget_' ) || 0 === strpos( $option, 'theme_mods_' ) || 0 === strpos( $option, 'bricks_global' ) ) {
				self::queue_all( 'option:' . $option );
			}
		}

		/**
		 * acf/save_post.
		 *
		 * @param int|string $post_id Post ID or "options".
		 * @return void
		 */
		public static function on_acf( $post_id ) {
			if ( is_numeric( $post_id ) ) {
				self::queue_post( (int) $post_id );
			} elseif ( is_string( $post_id ) && 0 === strpos( $post_id, 'option' ) ) {
				self::queue_all( 'acf_options' );
			}
		}

		/**
		 * WooCommerce stock: product page, shop, its categories, home.
		 *
		 * @param mixed $product WC_Product or ID.
		 * @return void
		 */
		public static function on_product( $product ) {
			$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
			if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
				$id = (int) $product->get_parent_id();
			}
			if ( $id ) {
				self::queue_post( $id );
				if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
					self::queue_urls( array( get_permalink( wc_get_page_id( 'shop' ) ) ) );
				}
			}
		}

		/**
		 * A Composer section write (front-end editor, MCP, section ops).
		 *
		 * @param int $post_id Post.
		 * @return void
		 */
		public static function on_builder_write( $post_id ) {
			self::queue_post( (int) $post_id );
		}

		/**
		 * elementor/document/after_save: the page, or everything for library
		 * templates (headers, footers, kit).
		 *
		 * @param object $document Elementor document.
		 * @return void
		 */
		public static function on_elementor_save( $document ) {
			if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
				self::queue_post( (int) $document->get_main_id() );
			}
		}

		/**
		 * Bricks saves page content as post meta without firing save_post.
		 *
		 * @param int    $meta_id  Meta ID.
		 * @param int    $post_id  Post.
		 * @param string $meta_key Key.
		 * @return void
		 */
		public static function on_post_meta( $meta_id, $post_id, $meta_key ) {
			if ( 0 === strpos( (string) $meta_key, '_bricks_page_' ) ) {
				self::queue_post( (int) $post_id );
			}
		}

		/** @return void */
		public static function all_menu() {
			self::queue_all( 'menu' );
		}

		/** @return void */
		public static function all_customizer() {
			self::queue_all( 'customizer' );
		}

		/** @return void */
		public static function all_theme() {
			self::queue_all( 'theme' );
		}

		/**
		 * Plugin, theme and core updates. Translation-only updates don't change markup.
		 *
		 * @param mixed $upgrader Upgrader.
		 * @param array $options  Upgrade details.
		 * @return void
		 */
		public static function all_update( $upgrader = null, $options = array() ) {
			if ( is_array( $options ) && isset( $options['type'] ) && 'translation' === $options['type'] ) {
				return;
			}
			self::queue_all( 'update' );
		}

		/**
		 * Plugins changed: rule packs may have changed, so the snapshot too.
		 *
		 * @return void
		 */
		public static function all_plugins() {
			if ( class_exists( 'UiChemy_Cache_Rules' ) ) {
				UiChemy_Cache_Rules::reset();
			}
			$config = UiChemy_Cache_Engine::config( true );
			if ( $config ) {
				UiChemy_Cache_Files::write_config( $config['enabled'] );
			}
			self::queue_all( 'plugins' );
		}

		/** @return void */
		public static function all_templates() {
			self::queue_all( 'theme_builder' );
		}

		/**
		 * Builder CSS regenerated: old HTML points to deleted CSS files.
		 *
		 * @return void
		 */
		public static function all_builder_css() {
			self::queue_all( 'builder_css' );
		}
	}
}
