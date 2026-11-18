<?php
/**
 * Page cache settings: one source of truth for the dashboard and AI.
 *
 * Controls C01–C12 from docs/page-cache/copy.json, stored in one non-autoloaded
 * option. validate() is shared by every writer, so the screen and AI accept and
 * reject exactly the same values. snapshot() writes the small JSON file the
 * drop-in reads (only what check 1 needs; never a secret).
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Settings' ) ) {

	class UiChemy_Cache_Settings {

		const OPTION = 'uichemy_cache_settings';

		/** Per-post "Don't cache this page" (C05). */
		const POST_META = '_uichemy_cache_disabled';

		/** Lifespan choices (C02), seconds. 10 h is the hard maximum. */
		const LIFESPANS = array(
			'2h'  => 7200,
			'6h'  => 21600,
			'10h' => 36000,
		);

		const PRELOAD_SPEEDS = array(
			'gentle' => 4,
			'normal' => 12,
			'fast'   => 30,
		);

		/**
		 * Defaults exactly as copy.json.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'mode'            => 'off', // Off until an administrator (or AI, when asked) turns it on.
				'lifespan'        => '10h',
				'mobile_separate' => false,
				'exclude_pages'   => array(),
				'exclude_paths'   => array(),
				'exclude_cookies' => array(),
				'ignored_params'  => self::default_params(),
				'clear_mode'      => 'smart',
				'preload_enabled' => true,
				'preload_speed'   => 'normal',
			);
		}

		/**
		 * C07 defaults ("Restore defaults").
		 *
		 * @return string[]
		 */
		public static function default_params() {
			// Not "ref": affiliate plugins read it on the server to set their cookie, which a
			// cached copy would skip (gap analysis, ?ref= note).
			return array( 'utm_*', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga' );
		}

		/**
		 * Current settings, defaults filled in.
		 *
		 * @return array
		 */
		public static function get() {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		}

		/**
		 * Has an administrator (or AI) ever saved these settings? The cache only
		 * installs itself after that, so an update never turns it on silently.
		 *
		 * @return bool
		 */
		public static function is_configured() {
			return is_array( get_option( self::OPTION, false ) );
		}

		/**
		 * Validate a partial change against the current settings.
		 *
		 * @param array $changes Keys from defaults().
		 * @return array|WP_Error Full settings, or 'invalid_value' naming the field.
		 */
		public static function validate( array $changes ) {
			$out = self::get();
			foreach ( $changes as $key => $value ) {
				if ( ! array_key_exists( $key, $out ) ) {
					return self::invalid( $key, 'Unknown setting.' );
				}
				switch ( $key ) {
					case 'mode':
						if ( ! in_array( $value, array( 'auto', 'uichemy', 'host', 'off' ), true ) ) {
							return self::invalid( $key, 'Use auto, uichemy, host or off.' );
						}
						break;
					case 'lifespan':
						if ( ! is_string( $value ) || ! isset( self::LIFESPANS[ $value ] ) ) {
							return self::invalid( $key, 'Use 2h, 6h or 10h. The maximum is 10h.' );
						}
						break;
					case 'mobile_separate':
					case 'preload_enabled':
						$value = (bool) $value;
						break;
					case 'exclude_pages':
						if ( ! is_array( $value ) ) {
							return self::invalid( $key, 'Use a list of post IDs.' );
						}
						$value = array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
						break;
					case 'exclude_paths':
						$value = self::clean_list( $value, '~^/[^\s?#*<>"\'\\\\]*\*?$~' );
						if ( is_wp_error( $value ) ) {
							return self::invalid( $key, 'Enter a path that starts with /, such as /members/ or /members/*.' );
						}
						break;
					case 'exclude_cookies':
						$value = self::clean_list( $value, '/^[A-Za-z0-9_-]+$/' );
						if ( is_wp_error( $value ) ) {
							return self::invalid( $key, 'Use letters, numbers, dashes and underscores only.' );
						}
						break;
					case 'ignored_params':
						$value = self::clean_list( $value, '/^[A-Za-z0-9_.-]+\*?$/' );
						if ( is_wp_error( $value ) ) {
							return self::invalid( $key, 'Use parameter names; * is allowed at the end.' );
						}
						break;
					case 'clear_mode':
						if ( ! in_array( $value, array( 'smart', 'all' ), true ) ) {
							return self::invalid( $key, 'Use smart or all.' );
						}
						break;
					case 'preload_speed':
						if ( ! is_string( $value ) || ! isset( self::PRELOAD_SPEEDS[ $value ] ) ) {
							return self::invalid( $key, 'Use gentle, normal or fast.' );
						}
						break;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		/**
		 * Validate and save, then let the cache apply the new state.
		 *
		 * @param array $changes Keys from defaults().
		 * @return array|WP_Error Saved settings.
		 */
		public static function save( array $changes ) {
			$clean = self::validate( $changes );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$before = self::get();
			update_option( self::OPTION, $clean, false );

			/**
			 * Fires after page cache settings are saved.
			 *
			 * @param array $clean  New settings.
			 * @param array $before Previous settings.
			 */
			do_action( 'uichemy/cache/settings_saved', $clean, $before );
			return $clean;
		}

		/**
		 * The settings the drop-in needs, as written to config.json.
		 *
		 * @param bool $enabled    Serve and store.
		 * @param int  $generation Current generation.
		 * @return array
		 */
		public static function snapshot( $enabled, $generation ) {
			$s    = self::get();
			$home = wp_parse_url( home_url( '/' ) );
			$host = strtolower( isset( $home['host'] ) ? $home['host'] : '' );
			if ( ! empty( $home['port'] ) ) {
				$host .= ':' . (int) $home['port'];
			}

			$exclude = array();
			foreach ( $s['exclude_paths'] as $p ) {
				$prefix    = '*' === substr( $p, -1 );
				$exclude[] = array(
					'p' => $prefix ? substr( $p, 0, -1 ) : $p,
					'prefix' => $prefix,
				);
			}
			foreach ( self::excluded_post_ids() as $id ) {
				$path = self::post_rel_path( $id );
				if ( $path ) {
					$exclude[] = array(
						'p' => $path,
						'prefix' => false,
					);
				}
			}

			$rule_paths = array();
			$cookies    = $s['exclude_cookies'];
			$params     = $s['ignored_params'];
			if ( class_exists( 'UiChemy_Cache_Rules' ) ) {
				$rule_paths = UiChemy_Cache_Rules::paths();
				$cookies    = array_values( array_unique( array_merge( $cookies, UiChemy_Cache_Rules::cookies() ) ) );
				// A pack that needs a parameter (AffiliateWP's ?ref=) takes it off the ignore list.
				$params = array_values( array_diff( $params, UiChemy_Cache_Rules::unignored_params() ) );
			}

			return array(
				'enabled'        => (bool) $enabled,
				'generation'     => (int) $generation,
				'host'           => $host,
				'home_path'      => isset( $home['path'] ) ? $home['path'] : '/',
				'lifespan'       => self::LIFESPANS[ $s['lifespan'] ],
				'mobile'         => (bool) $s['mobile_separate'],
				'cookies'        => $cookies,
				'ignored_params' => $params,
				'exclude'        => $exclude,
				'rule_paths'     => $rule_paths,
				'charset'        => get_option( 'blog_charset', 'UTF-8' ),
				// Where WordPress's scheduled tasks run; the drop-in starts them in the
				// background now and then, since cached visits never load WordPress.
				'cron_path'      => (string) wp_parse_url( site_url( 'wp-cron.php' ), PHP_URL_PATH ),
			);
		}

		/**
		 * Post IDs excluded by C04 or C05.
		 *
		 * @return int[]
		 */
		public static function excluded_post_ids() {
			$ids  = self::get()['exclude_pages'];
			$meta = get_posts(
				array(
					'post_type'        => 'any',
					'post_status'      => 'publish',
					'meta_key'         => self::POST_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- small, admin-time only.
					'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
					'fields'           => 'ids',
					'posts_per_page'   => 500,
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			return array_values( array_unique( array_map( 'intval', array_merge( $ids, $meta ) ) ) );
		}

		/**
		 * A post's path below the home path, for the drop-in's exclusion list.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function post_rel_path( $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				return '';
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return class_exists( 'UiChemy_Cache_Engine', false ) ? UiChemy_Cache_Engine::relative_path( $path, $home ) : $path;
		}

		/**
		 * Settings for AI and the dashboard, with the Pro lock applied.
		 *
		 * @return array
		 */
		public static function public_settings() {
			$s           = self::get();
			$s['is_pro'] = function_exists( 'uichemy_is_pro' ) ? (bool) uichemy_is_pro() : false;
			return $s;
		}

		/**
		 * @param mixed  $value Submitted list (array, or text with one item per line or comma).
		 * @param string $re    Pattern each item must match.
		 * @return string[]|WP_Error
		 */
		private static function clean_list( $value, $re ) {
			if ( is_string( $value ) ) {
				$value = preg_split( '/[\r\n,]+/', $value );
			}
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'invalid_value' );
			}
			$out = array();
			foreach ( $value as $item ) {
				$item = trim( (string) $item );
				if ( '' === $item ) {
					continue;
				}
				if ( ! preg_match( $re, $item ) || strlen( $item ) > 200 ) {
					return new WP_Error( 'invalid_value' );
				}
				$out[] = $item;
			}
			return array_values( array_unique( array_slice( $out, 0, 200 ) ) );
		}

		/**
		 * @param string $key     Field.
		 * @param string $message What to do.
		 * @return WP_Error
		 */
		private static function invalid( $key, $message ) {
			return new WP_Error(
				'invalid_value',
				$message,
				array(
					'field' => $key,
					'status' => 400,
				)
			);
		}
	}
}
