<?php
/**
 * Page cache: status, turning the cache on and off, check 2 inside WordPress,
 * and diagnostics.
 *
 * The drop-in (advanced-cache.php, UiChemy_Cache_Engine) serves pages and does
 * check 1. While WordPress builds a missed page, verdict() runs the checks that
 * need WordPress (logged in, preview, search, 404, password, exclusions, rule
 * packs) and tells the engine whether the finished page may be saved. With no
 * verdict the engine never saves.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache' ) ) {

	class UiChemy_Cache {

		/** Diagnostics: last outcome per URL hash, bounded (SECURITY S10). */
		const DIAG_OPTION = 'uichemy_cache_diag';
		const DIAG_MAX    = 200;

		/** Last error from turning the cache on (needs_setup, storage, foreign_dropin). */
		const ERROR_OPTION = 'uichemy_cache_error';

		/** The drop-in engine API this plugin code expects (UiChemy_Cache_Engine::API). */
		const ENGINE_API = 4;

		/** Option: when a page last showed phone-specific HTML while the mobile copy was off. */
		const MOBILE_HINT_OPTION = 'uichemy_cache_mobile_hint';

		/** @var bool wp_is_mobile() was asked while this page rendered. */
		private static $mobile_asked = false;

		/** Hourly cleanup and size cap, separate from the one-off run after a clear. */
		const GC_HOURLY = 'uichemy_cache_gc_hourly';

		/**
		 * Is the loaded engine the one this code was written for? Right after an
		 * update the old drop-in's engine is loaded first (its class wins).
		 *
		 * @return bool
		 */
		public static function engine_ok() {
			return class_exists( 'UiChemy_Cache_Engine', false ) && defined( 'UiChemy_Cache_Engine::API' ) && self::ENGINE_API === UiChemy_Cache_Engine::API;
		}

		/**
		 * Outdated engine: no cache features this request (without a verdict the
		 * old engine never saves), just replace our drop-in and clear, once.
		 *
		 * @return void
		 */
		public static function init_outdated() {
			add_action( 'init', array( __CLASS__, 'refresh_outdated_engine' ), 1 );
		}

		/**
		 * Copy the shipped drop-in over ours and start a fresh generation.
		 *
		 * @return void
		 */
		public static function refresh_outdated_engine() {
			if ( 'ours' !== UiChemy_Cache_Host::dropin_state()['state'] || get_transient( 'uichemy_cache_refreshing' ) ) {
				return;
			}
			set_transient( 'uichemy_cache_refreshing', 1, MINUTE_IN_SECONDS );
			UiChemy_Cache_Files::install_dropin();
			$s = get_option( UiChemy_Cache_Files::STATE_OPTION, array() );
			$s = is_array( $s ) ? $s : array();
			$s['generation'] = ( isset( $s['generation'] ) ? (int) $s['generation'] : 1 ) + 1;
			update_option( UiChemy_Cache_Files::STATE_OPTION, $s, false );
			// Old engine still loaded in this request: read the snapshot as plain
			// JSON rather than through it, then write the new generation.
			// API 2 kept the cache in wp-content/cache/uichemy (development builds only).
			$raw = @file_get_contents( WP_CONTENT_DIR . '/uichemy/cache/config.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_string( $raw ) ) {
				$raw = @file_get_contents( WP_CONTENT_DIR . '/cache/uichemy/config.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			$json = is_string( $raw ) ? json_decode( $raw, true ) : null;
			UiChemy_Cache_Files::write_config( is_array( $json ) && ! empty( $json['enabled'] ) );
		}

		/**
		 * Hooks.
		 *
		 * @return void
		 */
		public static function init() {
			if ( class_exists( 'UiChemy_Cache_Engine', false ) ) {
				UiChemy_Cache_Engine::$reporter = array( __CLASS__, 'record' );
			}
			add_action( 'template_redirect', array( __CLASS__, 'verdict' ), 99999 );
			add_action( 'uichemy/cache/settings_saved', array( __CLASS__, 'on_settings_saved' ), 10, 2 );
			add_action( UiChemy_Cache_Files::GC_HOOK, array( 'UiChemy_Cache_Files', 'gc' ) );
			add_action( self::GC_HOURLY, array( 'UiChemy_Cache_Files', 'gc' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_refresh_dropin' ) );
			add_action( 'init', array( __CLASS__, 'refresh_dropin_if_stale' ), 20 );
			add_filter( 'wp_is_mobile', array( __CLASS__, 'note_mobile' ), PHP_INT_MAX );
			add_action( 'update_option_home', array( __CLASS__, 'on_site_address_change' ) );
			add_action( 'update_option_siteurl', array( __CLASS__, 'on_site_address_change' ) );
		}

		// ------------------------------------------------------------------
		// Status.
		// ------------------------------------------------------------------

		/**
		 * What the status card shows (copy.json status_card.states).
		 *
		 * @param bool $redetect Run host detection again.
		 * @return array{state:string,mode:string,host:string,host_purge:bool,plugin:string,reason:string,detect:array}
		 */
		public static function status( $redetect = false ) {
			$settings = UiChemy_Cache_Settings::get();
			$detect   = UiChemy_Cache_Host::detect( $redetect );
			$out      = array(
				'state'      => 'off',
				'mode'       => $settings['mode'],
				'host'       => $detect['host'] ? $detect['host']['name'] : '',
				'host_purge' => $detect['host'] ? (bool) $detect['host']['purge_supported'] : false,
				'plugin'     => '',
				'reason'     => '',
				'detect'     => $detect,
			);

			$want = self::wanted_owner( $settings['mode'], $detect );
			if ( 'run' !== $want ) {
				$out['state'] = $want;
				if ( 'other_plugin' === $want ) {
					$out['plugin'] = $detect['plugin'] ? $detect['plugin']['name'] : $detect['dropin']['plugin'];
				}
				if ( 'unavailable' === $want ) {
					$out['reason'] = 'multisite';
				}
				return $out;
			}

			if ( ! UiChemy_Cache_Settings::is_configured() ) {
				return $out; // Off until an administrator turns it on.
			}

			$error = get_option( self::ERROR_OPTION, '' );
			if ( 'storage' === $error ) {
				$out['state']  = 'unavailable';
				$out['reason'] = 'storage';
				return $out;
			}
			if ( 'needs_setup' === $error ) {
				$out['state'] = 'needs_setup';
				return $out;
			}
			if ( 'foreign_dropin' === $error ) {
				$out['state']  = 'other_plugin';
				$out['plugin'] = $detect['dropin']['plugin'];
				return $out;
			}
			$out['state'] = self::running() ? 'on' : 'needs_setup';
			return $out;
		}

		/**
		 * Who should cache, before checking whether we can.
		 *
		 * @param string $mode   C01 value.
		 * @param array  $detect UiChemy_Cache_Host::detect().
		 * @return string run | off | host | other_plugin | unavailable
		 */
		public static function wanted_owner( $mode, array $detect ) {
			if ( is_multisite() ) {
				return 'unavailable';
			}
			if ( 'off' === $mode ) {
				return 'off';
			}
			if ( 'host' === $mode ) {
				return 'host';
			}
			if ( 'foreign' === $detect['dropin']['state'] ) {
				return 'other_plugin';
			}
			if ( 'uichemy' === $mode ) {
				return 'run';
			}
			switch ( $detect['recommended_mode'] ) {
				case 'host':
				case 'other_plugin':
				case 'unavailable':
					return $detect['recommended_mode'];
			}
			return 'run';
		}

		/**
		 * Is our cache actually serving: drop-in ours (an older copy right after
		 * an update still serves; admin_init refreshes it), WP_CACHE on,
		 * snapshot enabled?
		 *
		 * @return bool
		 */
		public static function running() {
			$config = UiChemy_Cache_Engine::config( true );
			return 'ours' === UiChemy_Cache_Host::dropin_state()['state']
				&& defined( 'WP_CACHE' ) && WP_CACHE
				&& $config && ! empty( $config['enabled'] );
		}

		// ------------------------------------------------------------------
		// Turning on and off.
		// ------------------------------------------------------------------

		/**
		 * Make the files match the settings. Safe to call any time.
		 *
		 * @return array status()
		 */
		public static function apply() {
			delete_option( self::ERROR_OPTION );
			$settings = UiChemy_Cache_Settings::get();
			$want     = self::wanted_owner( $settings['mode'], UiChemy_Cache_Host::detect() );

			if ( 'run' !== $want || ! UiChemy_Cache_Settings::is_configured() ) {
				self::turn_off();
				return self::status();
			}

			if ( ! UiChemy_Cache_Files::ensure_root() || ! UiChemy_Cache_Files::write_config( true ) ) {
				update_option( self::ERROR_OPTION, 'storage', false );
				return self::status();
			}
			$dropin = UiChemy_Cache_Files::install_dropin();
			if ( is_wp_error( $dropin ) ) {
				UiChemy_Cache_Files::write_config( false );
				update_option( self::ERROR_OPTION, 'foreign_dropin' === $dropin->get_error_code() ? 'foreign_dropin' : 'storage', false );
				return self::status();
			}
			$config = UiChemy_Cache_Files::ensure_wp_cache();
			if ( is_wp_error( $config ) ) {
				update_option( self::ERROR_OPTION, 'needs_setup', false );
				return self::status();
			}
			UiChemy_Cache_Files::schedule_gc();
			if ( ! wp_next_scheduled( self::GC_HOURLY ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::GC_HOURLY );
			}
			return self::status();
		}

		/**
		 * Stop serving: snapshot disabled, our drop-in and wp-config line removed,
		 * stored pages deleted. Settings are kept.
		 *
		 * @return void
		 */
		public static function turn_off() {
			if ( is_dir( UiChemy_Cache_Engine::root() ) ) {
				UiChemy_Cache_Files::write_config( false );
			}
			UiChemy_Cache_Files::remove_dropin();
			UiChemy_Cache_Files::remove_wp_cache_line();
			UiChemy_Cache_Files::clear_all();
			wp_clear_scheduled_hook( self::GC_HOURLY );
		}

		/**
		 * Settings changed: apply them, then clear (exclusions, lifespan and the
		 * mobile split all change what may be served).
		 *
		 * @return void
		 */
		public static function on_settings_saved() {
			self::apply();
			if ( self::running() ) {
				UiChemy_Cache_Files::clear_all();
			}
		}

		/**
		 * After a UiChemy update the shipped drop-in may be newer: replace ours.
		 *
		 * @return void
		 */
		public static function maybe_refresh_dropin() {
			if ( get_option( 'uichemy_cache_reapply' ) && current_user_can( 'manage_options' ) ) {
				delete_option( 'uichemy_cache_reapply' );
				self::apply();
				return;
			}
			// Detection changed (another cache plugin activated, host found): stop
			// serving instead of running two caches.
			if ( self::running() && current_user_can( 'manage_options' ) && 'run' !== self::wanted_owner( UiChemy_Cache_Settings::get()['mode'], UiChemy_Cache_Host::detect() ) ) {
				self::apply();
				return;
			}
			if ( 'ours' === UiChemy_Cache_Host::dropin_state()['state'] && ! UiChemy_Cache_Files::dropin_current() && current_user_can( 'manage_options' ) ) {
				UiChemy_Cache_Files::install_dropin();
				UiChemy_Cache_Files::write_config( true );
			}
		}

		/**
		 * The site address is part of the key: rewrite the snapshot and clear.
		 *
		 * @return void
		 */
		public static function on_site_address_change() {
			if ( is_dir( UiChemy_Cache_Engine::root() ) ) {
				$config = UiChemy_Cache_Engine::config( true );
				UiChemy_Cache_Files::write_config( $config ? $config['enabled'] : false );
				UiChemy_Cache_Files::clear_all();
			}
		}

		// ------------------------------------------------------------------
		// Check 2, the WordPress half.
		// ------------------------------------------------------------------

		/**
		 * Tell the engine whether this page may be saved. Runs on every missed
		 * page; does nothing when the drop-in isn't capturing.
		 *
		 * @return void
		 */
		public static function verdict() {
			if ( ! class_exists( 'UiChemy_Cache_Engine', false ) || 'miss' !== UiChemy_Cache_Engine::$state ) {
				return;
			}
			list( $reason, $detail ) = self::page_reason();
			if ( '' === $reason && self::$mobile_asked && ! UiChemy_Cache_Settings::get()['mobile_separate'] ) {
				list( $reason, $detail ) = array( 'device', '' );
			}
			UiChemy_Cache_Engine::$verdict = '' === $reason
				? array( 'store' => true )
				: array(
					'store' => false,
					'reason' => $reason,
					'detail' => $detail,
				);
			if ( class_exists( 'UiChemy_Cache_Rules' ) ) {
				UiChemy_Cache_Engine::$guard = UiChemy_Cache_Rules::guard();
			}
		}

		/**
		 * Why the current page must not be saved ('' when it may).
		 *
		 * @return array{0:string,1:string}
		 */
		public static function page_reason() {
			if ( is_user_logged_in() ) {
				return array( 'logged_in', '' );
			}
			if ( is_preview() || is_customize_preview() || isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
				return array( 'preview', '' );
			}
			if ( is_search() ) {
				return array( 'search', '' );
			}
			if ( is_404() ) {
				return array( 'status', '404' );
			}
			if ( is_feed() || is_robots() || is_trackback() || is_favicon() ) {
				return array( 'not_html', '' );
			}
			if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
				return array( 'system_path', '' );
			}

			$post_id = 0;
			if ( is_singular() ) {
				$post = get_queried_object();
				if ( ! $post instanceof WP_Post ) {
					return array( 'unknown', '' );
				}
				$post_id = (int) $post->ID;
				if ( post_password_required( $post ) || '' !== $post->post_password ) {
					return array( 'password', '' );
				}
				if ( 'publish' !== $post->post_status ) {
					return array( 'preview', '' );
				}
				$type = get_post_type_object( $post->post_type );
				if ( ! $type || ! $type->public || ( ! $type->publicly_queryable && 'page' !== $post->post_type ) ) {
					return array( 'system_path', '' );
				}
				if ( in_array( $post_id, UiChemy_Cache_Settings::get()['exclude_pages'], true ) || get_post_meta( $post_id, UiChemy_Cache_Settings::POST_META, true ) ) {
					return array( 'excluded_page', '' );
				}
			}

			/**
			 * Rule packs, Theme Builder conditions and Composer's own dynamic parts
			 * answer here. Return array( reason, detail ) to refuse the page.
			 *
			 * @param array|null $reason  Null = no objection.
			 * @param int        $post_id Queried post, 0 when not singular.
			 */
			// Only the page's own address is stored. WordPress answers some other
			// spellings (/ABOUT/, /index.php/about/) with the same page and no
			// redirect; a copy under those would never be cleared (gap analysis H5).
			if ( ! self::is_canonical_request( $post_id ) ) {
				return array( 'not_canonical', '' );
			}

			$hook = apply_filters( 'uichemy/cache/page_reason', null, $post_id );
			if ( is_array( $hook ) && ! empty( $hook[0] ) ) {
				return array( (string) $hook[0], isset( $hook[1] ) ? (string) $hook[1] : '' );
			}
			return array( '', '' );
		}

		// ------------------------------------------------------------------
		// Diagnostics.
		// ------------------------------------------------------------------

		/**
		 * Record the outcome of check 2 for one URL hash. Hashes and reason codes
		 * only: no cookies, headers, IPs or URLs. Rewritten at most every 10
		 * minutes per URL and outcome.
		 *
		 * @param string $hash   Key.
		 * @param string $reason Reason code, '' when saved.
		 * @param string $detail Detail (cookie name, plugin, status).
		 * @return void
		 */
		public static function record( $hash, $reason, $detail ) {
			if ( 'device' === $reason ) {
				// Tell the screen once a day: turning the mobile copy on fixes it.
				if ( (int) get_option( self::MOBILE_HINT_OPTION, 0 ) < time() - DAY_IN_SECONDS ) {
					update_option( self::MOBILE_HINT_OPTION, time(), false );
				}
			}
			$diag = get_option( self::DIAG_OPTION, array() );
			$diag = is_array( $diag ) ? $diag : array();
			$code = '' === $reason ? 'stored' : $reason;
			// Error pages (any random address) would turn anonymous traffic into
			// database writes; their reason is obvious anyway.
			// Same for other spellings of an address and a full hourly quota: any
			// visitor could otherwise turn requests into database writes.
			if ( in_array( $code, array( 'status', 'not_canonical', 'size_limit' ), true ) ) {
				return;
			}
			if ( isset( $diag[ $hash ] ) && $diag[ $hash ]['r'] === $code && time() - $diag[ $hash ]['t'] < 600 ) {
				return;
			}
			unset( $diag[ $hash ] );
			$diag[ $hash ] = array(
				'r' => $code,
				'd' => substr( (string) $detail, 0, 80 ),
				't' => time(),
			);
			if ( count( $diag ) > self::DIAG_MAX ) {
				$diag = array_slice( $diag, -self::DIAG_MAX, null, true );
			}
			update_option( self::DIAG_OPTION, $diag, false );
		}

		/**
		 * wp_is_mobile, during a page the cache may store: with one shared copy for
		 * every device, a page that differs on phones must not be stored. Also set
		 * the verdict if it was already given (the call usually comes while the
		 * theme renders, after template_redirect).
		 *
		 * @param bool $is_mobile Core's answer.
		 * @return bool Unchanged.
		 */
		public static function note_mobile( $is_mobile ) {
			if ( class_exists( 'UiChemy_Cache_Engine', false ) && 'miss' === UiChemy_Cache_Engine::$state ) {
				self::$mobile_asked = true;
				if ( is_array( UiChemy_Cache_Engine::$verdict ) && ! empty( UiChemy_Cache_Engine::$verdict['store'] ) && ! UiChemy_Cache_Settings::get()['mobile_separate'] ) {
					UiChemy_Cache_Engine::$verdict = array(
						'store' => false,
						'reason' => 'device',
						'detail' => '',
					);
				}
			}
			return $is_mobile;
		}

		/**
		 * Is this request for the page's own address (or its page 2, 3… / comment
		 * pages)? Archives are not checked.
		 *
		 * @param int $post_id Queried post, 0 when not singular.
		 * @return bool
		 */
		public static function is_canonical_request( $post_id ) {
			$req = class_exists( 'UiChemy_Cache_Engine', false ) && isset( UiChemy_Cache_Engine::$request['path'] ) ? (string) UiChemy_Cache_Engine::$request['path'] : '';
			if ( '' === $req ) {
				return true;
			}
			$link = '';
			if ( $post_id ) {
				$link = get_permalink( $post_id );
			} elseif ( is_home() && (int) get_option( 'page_for_posts' ) ) {
				$link = get_permalink( (int) get_option( 'page_for_posts' ) );
			} elseif ( is_front_page() ) {
				$link = home_url( '/' );
			} elseif ( is_category() || is_tag() || is_tax() ) {
				$term = get_queried_object();
				$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
				$link = is_wp_error( $link ) ? '' : $link;
			} elseif ( is_author() ) {
				$author = get_queried_object();
				$link   = $author instanceof WP_User ? get_author_posts_url( $author->ID ) : '';
			} elseif ( is_post_type_archive() ) {
				$type = get_query_var( 'post_type' );
				$link = get_post_type_archive_link( is_array( $type ) ? reset( $type ) : $type );
			}
			if ( ! $link ) {
				// Other archives (dates…): WordPress addresses are lower case and never
				// go through /index.php here, so any other spelling is an alias.
				$plain = preg_replace( '/%[0-9A-F]{2}/', '', $req );
				$ok    = false === strpos( $req, '/index.php' ) && ! preg_match( '/[A-Z]/', (string) $plain );
				return (bool) apply_filters( 'uichemy/cache/is_canonical', $ok, $req, '', $post_id );
			}
			$want = UiChemy_Cache_Engine::normalize_path( (string) ( wp_parse_url( $link, PHP_URL_PATH ) ?: '/' ) );
			$ok   = null !== $want && ( $req === $want || ( 0 === strpos( $req, $want ) && preg_match( '#^/?((page/)?\d+|comment-page-\d+)/?$#', substr( $req, strlen( $want ) ) ) ) );
			/**
			 * Whether a request is at the page's own address (stored only when it is).
			 *
			 * @param bool   $ok      Result.
			 * @param string $req     Request path.
			 * @param string $want    The page's path.
			 * @param int    $post_id Queried post.
			 */
			return (bool) apply_filters( 'uichemy/cache/is_canonical', $ok, $req, (string) $want, $post_id );
		}

		/**
		 * After an update the shipped drop-in may differ from the installed copy.
		 * Checked from any request (hourly), so visitors get fixes even when no
		 * administrator opens wp-admin.
		 *
		 * @return void
		 */
		public static function refresh_dropin_if_stale() {
			if ( get_transient( 'uichemy_cache_dropin_checked' ) ) {
				return;
			}
			set_transient( 'uichemy_cache_dropin_checked', 1, HOUR_IN_SECONDS );
			if ( 'ours' === UiChemy_Cache_Host::dropin_state()['state'] && ! UiChemy_Cache_Files::dropin_current() ) {
				UiChemy_Cache_Files::install_dropin();
				$config = UiChemy_Cache_Engine::config( true );
				UiChemy_Cache_Files::write_config( $config ? $config['enabled'] : false );
			}
		}

		/**
		 * Last recorded outcome for a URL, any variant.
		 *
		 * @param string $url Absolute URL.
		 * @return array{r:string,d:string,t:int}|null
		 */
		public static function last_outcome( $url ) {
			$config = UiChemy_Cache_Engine::config( true );
			$diag   = get_option( self::DIAG_OPTION, array() );
			if ( ! $config || ! is_array( $diag ) ) {
				return null;
			}
			$best = null;
			foreach ( UiChemy_Cache_Files::hashes_for_url( $url, $config ) as $hash ) {
				if ( isset( $diag[ $hash ] ) && ( ! $best || $diag[ $hash ]['t'] > $best['t'] ) ) {
					$best = $diag[ $hash ];
				}
			}
			return $best;
		}
	}
}
