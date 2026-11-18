<?php
/**
 * Page cache actions for AI (uichemy-composer/performance) and the dashboard.
 *
 * Both call these methods, so they return the same results. Every caller has
 * already checked manage_options (the ability's permission_check, the
 * dashboard's nonce + capability check). Reason texts come from data/copy.json.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Ai' ) ) {

	class UiChemy_Cache_Ai {

		/** @var array|null */
		private static $copy = null;

		/**
		 * data/copy.json, decoded once.
		 *
		 * @return array
		 */
		public static function copy() {
			if ( null === self::$copy ) {
				$raw        = file_get_contents( __DIR__ . '/data/copy.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local data file.
				$json       = is_string( $raw ) ? json_decode( $raw, true ) : null;
				self::$copy = is_array( $json ) ? $json : array();
			}
			return self::$copy;
		}

		/**
		 * A reason with its texts, placeholders filled.
		 *
		 * @param string $code Reason code.
		 * @param array  $vars Placeholder values.
		 * @return array{code:string,label:string,explanation:string,fix:string}
		 */
		public static function reason( $code, array $vars = array() ) {
			foreach ( isset( self::copy()['reasons'] ) ? self::copy()['reasons'] : array() as $r ) {
				if ( $r['code'] === $code ) {
					$fill = static function ( $text ) use ( $vars ) {
						foreach ( $vars as $k => $v ) {
							$text = str_replace( '{' . $k . '}', (string) $v, $text );
						}
						return $text;
					};
					return array(
						'code'        => $code,
						'label'       => $fill( $r['label'] ),
						'explanation' => $fill( $r['explanation'] ),
						'fix'         => $fill( $r['fix'] ),
					);
				}
			}
			return array(
				'code' => $code,
				'label' => $code,
				'explanation' => '',
				'fix' => '',
			);
		}

		/**
		 * Placeholder values for a reason's detail.
		 *
		 * @param string $code   Reason code.
		 * @param string $detail Detail recorded with it.
		 * @return array
		 */
		private static function vars_for( $code, $detail ) {
			$vars = array(
				'site_host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'folder'    => 'wp-content/uichemy/cache',
				'plugin'    => $detail ? $detail : 'A plugin',
			);
			switch ( $code ) {
				case 'cookie':
				case 'sets_cookie':
					$vars['cookie'] = $detail;
					if ( class_exists( 'UiChemy_Cache_Rules' ) ) {
						$vars['plugin'] = UiChemy_Cache_Rules::plugin_for_cookie( $detail );
					}
					break;
				case 'query_string':
					$vars['param'] = $detail;
					break;
				case 'vary':
					$vars['detail'] = $detail ? strtolower( $detail ) : 'cookie';
					break;
				case 'status':
					$vars['status'] = $detail;
					break;
				case 'blocked_content':
					$vars['component'] = $detail;
					break;
			}
			return $vars;
		}

		// ------------------------------------------------------------------
		// Actions.
		// ------------------------------------------------------------------

		/**
		 * get-cache-settings.
		 *
		 * @return array
		 */
		public static function get_settings() {
			return array(
				'settings' => UiChemy_Cache_Settings::public_settings(),
				'status'   => self::status_summary(),
			);
		}

		/**
		 * set-cache-settings: same validation as the screen.
		 *
		 * @param array $params { changes: object }.
		 * @return array|WP_Error
		 */
		public static function set_settings( array $params ) {
			$changes = isset( $params['changes'] ) && is_array( $params['changes'] ) ? $params['changes'] : array();
			if ( ! $changes ) {
				return new WP_Error( 'invalid_value', 'Pass "changes" with the settings to change (see get-cache-settings).' );
			}
			$pro_only = array( 'preload_enabled', 'preload_speed' );
			if ( array_intersect( array_keys( $changes ), $pro_only ) && ! self::is_pro() ) {
				return new WP_Error( 'pro_required', 'Preload is part of UiChemy Pro. Without it, pages rebuild when the first visitor arrives.' );
			}
			$saved = UiChemy_Cache_Settings::save( $changes );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return array(
				'settings' => UiChemy_Cache_Settings::public_settings(),
				'status'   => self::status_summary(),
			);
		}

		/**
		 * cache-status: is a page cached, by whom, and why not.
		 *
		 * @param array $params { url?: string, post_id?: int }.
		 * @return array|WP_Error
		 */
		public static function cache_status( array $params ) {
			$url = self::resolve_url( $params );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$status = UiChemy_Cache::status();
			$out    = array(
				'url'    => $url,
				'result' => 'BYPASS',
				'cache'  => $status['state'],
			);

			if ( 'on' !== $status['state'] ) {
				if ( 'host' === $status['state'] && $status['host'] ) {
					return $out + array( 'reason' => self::reason( 'host_handles', array( 'host' => $status['host'] ) ) );
				}
				$code = 'unavailable' === $status['state'] && $status['reason'] ? $status['reason'] : 'cache_off';
				return $out + array( 'reason' => self::reason( $code, self::vars_for( $code, '' ) ) );
			}

			if ( UiChemy_Cache_Files::is_cached( $url ) ) {
				$out['result'] = 'HIT';
				return $out + array( 'message' => 'Cached. Visitors get the saved copy.' );
			}

			// Rules that apply before WordPress, for an anonymous GET.
			$check = self::anonymous_check( $url );
			if ( $check && $check['reason'] ) {
				return $out + array( 'reason' => self::reason( $check['reason'], self::vars_for( $check['reason'], $check['detail'] ) ) );
			}

			$post_id = url_to_postid( $url );
			if ( $post_id ) {
				if ( get_post_meta( $post_id, UiChemy_Cache_Settings::POST_META, true ) || in_array( $post_id, UiChemy_Cache_Settings::get()['exclude_pages'], true ) ) {
					return $out + array( 'reason' => self::reason( 'excluded_page' ) );
				}
				$hook = apply_filters( 'uichemy/cache/post_reason', null, $post_id );
				if ( is_array( $hook ) && ! empty( $hook[0] ) ) {
					return $out + array( 'reason' => self::reason( $hook[0], self::vars_for( $hook[0], isset( $hook[1] ) ? $hook[1] : '' ) ) );
				}
			}

			$last = UiChemy_Cache::last_outcome( $url );
			if ( $last && 'stored' !== $last['r'] ) {
				return $out + array(
					'reason'      => self::reason( $last['r'], self::vars_for( $last['r'], $last['d'] ) ),
					'recorded_at' => gmdate( 'c', $last['t'] ),
				);
			}

			$out['result'] = 'MISS';
			return $out + array( 'message' => 'Not cached yet. It will be saved on the next visit.' );
		}

		/**
		 * cache-test: the home page twice as a logged-out visitor.
		 *
		 * @return array
		 */
		public static function cache_test() {
			UiChemy_Cache_Host::detect( true );
			$t    = UiChemy_Cache_Host::run_test();
			$copy = array();
			foreach ( isset( self::copy()['actions'] ) ? self::copy()['actions'] : array() as $a ) {
				if ( 'A03' === $a['id'] ) {
					$copy = $a;
				}
			}
			$msg  = isset( $copy[ $t['result'] ] ) ? $copy[ $t['result'] ] : '';
			$vars = array(
				'cached_ms'   => $t['cached_ms'],
				'uncached_ms' => $t['uncached_ms'],
				'host'        => $t['host'],
			);
			if ( 'fail_bypass' === $t['result'] ) {
				$why                  = self::cache_status( array( 'url' => home_url( '/' ) ) );
				$r                    = isset( $why['reason'] ) ? $why['reason'] : self::reason( 'cache_off' );
				$vars['reason_label'] = $r['label'];
				$vars['reason_fix']   = $r['fix'];
				$t['reason']          = $r;
			}
			foreach ( $vars as $k => $v ) {
				$msg = str_replace( '{' . $k . '}', (string) $v, $msg );
			}
			$t['message'] = $msg;
			$t['status']  = self::status_summary();
			return $t;
		}

		/**
		 * cache-clear: one page, a list of pages on this site, or everything.
		 *
		 * @param array $params { scope: page|urls|all, url?, post_id?, urls? }.
		 * @return array|WP_Error
		 */
		public static function cache_clear( array $params ) {
			$scope = isset( $params['scope'] ) ? (string) $params['scope'] : 'all';
			if ( 'all' === $scope ) {
				if ( ! UiChemy_Cache_Files::clear_all() ) {
					return new WP_Error( 'storage', 'The cache couldn\'t be cleared because the cache folder isn\'t writable. Ask your host to make wp-content/uichemy writable by WordPress.' );
				}
				do_action( 'uichemy/cache/cleared', 'all', array() );
				return array(
					'cleared' => 'all',
					'message' => 'Cache cleared. Pages will rebuild as visitors arrive.',
				);
			}
			if ( 'page' === $scope ) {
				$url = self::resolve_url( $params );
				if ( is_wp_error( $url ) ) {
					return $url;
				}
				$urls = array( $url );
			} elseif ( 'urls' === $scope ) {
				$urls = array();
				foreach ( isset( $params['urls'] ) && is_array( $params['urls'] ) ? $params['urls'] : array() as $u ) {
					$r = self::resolve_url( array( 'url' => $u ) );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					$urls[] = $r;
				}
				if ( ! $urls ) {
					return new WP_Error( 'invalid_value', 'Pass "urls": a list of addresses on this site.' );
				}
			} else {
				return new WP_Error( 'invalid_value', 'Use scope "page", "urls" or "all".' );
			}
			$count = UiChemy_Cache_Files::clear_urls( $urls );
			do_action( 'uichemy/cache/cleared', 'urls', $urls );
			return array(
				'cleared' => $urls,
				'pages'   => $count,
				'message' => 1 === count( $urls ) ? 'This page was cleared from the cache.' : 'Cache cleared. Pages will rebuild as visitors arrive.',
			);
		}

		// ------------------------------------------------------------------
		// Helpers.
		// ------------------------------------------------------------------

		/**
		 * Status card data, without the raw detection blob.
		 *
		 * @return array
		 */
		public static function status_summary() {
			$s     = UiChemy_Cache::status();
			$stats = 'on' === $s['state'] ? UiChemy_Cache_Files::stats() : array(
				'count' => 0,
				'bytes' => 0,
			);

			/**
			 * Page cache status for the dashboard and AI. Pro adds `preload`
			 * ({done,total}) while preloading and `cf` ({available,connected,zone,
			 * last4}); never a secret.
			 *
			 * @param array $summary Status.
			 */
			return apply_filters(
				'uichemy/cache/status',
				array(
					'state'       => $s['state'],
					'configured'  => UiChemy_Cache_Settings::is_configured(),
					'mode'        => $s['mode'],
					'host'        => $s['host'],
					'host_purge'  => $s['host_purge'],
					'plugin'      => $s['plugin'],
					'reason'      => $s['reason'],
					'pages'       => $stats['count'],
					'bytes'       => $stats['bytes'],
					'cloudflare'  => ! empty( $s['detect']['cloudflare'] ),
					'suggestions' => $s['detect']['suggestions'],
					'evidence'    => $s['detect']['host'] ? $s['detect']['host']['evidence'] : array(),
					'checked_at'  => $s['detect']['checked_at'],
					'loopback'    => $s['detect']['loopback']['ok'],
					'config_line' => UiChemy_Cache_Files::config_line(),
					'last_test'   => get_option( UiChemy_Cache_Host::TEST_OPTION, null ),
					'usage'       => 'on' === $s['state'] ? UiChemy_Cache_Files::usage() : null,
					'mobile_hint' => ! UiChemy_Cache_Settings::get()['mobile_separate'] && (int) get_option( UiChemy_Cache::MOBILE_HINT_OPTION, 0 ) > time() - 7 * DAY_IN_SECONDS,
					'clears'      => class_exists( 'UiChemy_Cache_Clear' ) ? UiChemy_Cache_Clear::recent( 5 ) : array(),
					'limit_bytes' => UiChemy_Cache_Files::MAX_BYTES,
				)
			);
		}

		/**
		 * A URL on this site from { url } or { post_id }.
		 *
		 * @param array $params Action parameters.
		 * @return string|WP_Error
		 */
		public static function resolve_url( array $params ) {
			if ( ! empty( $params['post_id'] ) ) {
				$url = get_permalink( (int) $params['post_id'] );
				if ( ! $url ) {
					return new WP_Error( 'invalid_value', 'No published post with that ID.' );
				}
				return $url;
			}
			$url = isset( $params['url'] ) ? esc_url_raw( trim( (string) $params['url'] ) ) : '';
			if ( '' === $url ) {
				return new WP_Error( 'invalid_value', 'Pass "url" (an address on this site) or "post_id".' );
			}
			if ( '/' === $url[0] ) {
				$url = home_url( $url );
			}
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return new WP_Error( 'invalid_value', 'Only addresses on this site can be checked or cleared.' );
			}
			return $url;
		}

		/**
		 * Run check 1 for an anonymous GET of $url.
		 *
		 * @param string $url Absolute URL.
		 * @return array|null
		 */
		private static function anonymous_check( $url ) {
			$config = UiChemy_Cache_Engine::config( true );
			if ( ! $config ) {
				return null;
			}
			$p      = wp_parse_url( $url );
			$server = array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => strtolower( $p['host'] ) . ( empty( $p['port'] ) ? '' : ':' . (int) $p['port'] ),
				'REQUEST_URI'    => ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ),
				'HTTPS'          => 'https' === ( isset( $p['scheme'] ) ? $p['scheme'] : '' ) ? 'on' : '',
			);
			return UiChemy_Cache_Engine::check_request( $server, array(), $config );
		}

		/**
		 * @return bool
		 */
		private static function is_pro() {
			return function_exists( 'uichemy_is_pro' ) && uichemy_is_pro();
		}
	}
}
