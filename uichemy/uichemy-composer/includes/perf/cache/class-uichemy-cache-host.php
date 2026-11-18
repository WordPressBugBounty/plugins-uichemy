<?php
/**
 * Page cache: who already caches this site's pages, and the cache test.
 *
 * Detection reads three kinds of evidence and never the current request's
 * headers (edge headers are added after PHP, so they are not there):
 *   1. One anonymous loopback GET to the home page (no cookies, 5 s timeout),
 *      matched against the header signals in data/hosts.json.
 *   2. Host constants and classes (WP Engine's WpeCommon, Kinsta's MU plugin).
 *   3. Files and plugins: an advanced-cache.php that is not ours, and active
 *      page-cache plugins from the same data file.
 *
 * Only grade A evidence switches "Automatic" to "Let my host handle it"; grade
 * B and C evidence is reported as a suggestion. The result is kept for 12 hours
 * and re-run on demand.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Host' ) ) {

	class UiChemy_Cache_Host {

		/** Option holding the last detection (not autoloaded). */
		const DETECT_OPTION = 'uichemy_cache_detect';

		/** Option holding the last cache test result (not autoloaded). */
		const TEST_OPTION = 'uichemy_cache_last_test';

		/** How long a detection stays valid. */
		const DETECT_TTL = 12 * HOUR_IN_SECONDS;

		/** Loopback timeout, seconds. */
		const TIMEOUT = 5;

		/** Line that marks advanced-cache.php as ours. Keep in step with the shipped drop-in. */
		const DROPIN_MARKER = 'UiChemy page cache drop-in';

		/** Our public response header. */
		const OWN_HEADER = 'x-uichemy-cache';

		/** @var array|null */
		private static $data = null;

		/**
		 * hosts.json, decoded once per request.
		 *
		 * @return array{hosts:array,cache_plugins:array}
		 */
		public static function data() {
			if ( null === self::$data ) {
				$raw        = file_get_contents( __DIR__ . '/data/hosts.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local data file.
				$json       = is_string( $raw ) ? json_decode( $raw, true ) : null;
				self::$data = array(
					'hosts'         => isset( $json['hosts'] ) && is_array( $json['hosts'] ) ? $json['hosts'] : array(),
					'cache_plugins' => isset( $json['cache_plugins'] ) && is_array( $json['cache_plugins'] ) ? $json['cache_plugins'] : array(),
				);
			}
			return self::$data;
		}

		/**
		 * One host entry by id.
		 *
		 * @param string $id Host id.
		 * @return array|null
		 */
		public static function host( $id ) {
			foreach ( self::data()['hosts'] as $host ) {
				if ( isset( $host['id'] ) && $host['id'] === $id ) {
					return $host;
				}
			}
			return null;
		}

		/**
		 * The last detection, re-run when older than 12 hours or when forced.
		 *
		 * @param bool $force Re-run now.
		 * @return array See run_detection().
		 */
		public static function detect( $force = false ) {
			if ( ! $force ) {
				$saved = get_option( self::DETECT_OPTION );
				if ( is_array( $saved ) && isset( $saved['checked_at'] ) && ( time() - (int) $saved['checked_at'] ) < self::DETECT_TTL ) {
					return $saved;
				}
			}
			$result = self::run_detection();
			update_option( self::DETECT_OPTION, $result, false );
			return $result;
		}

		/**
		 * Gather every kind of evidence and decide.
		 *
		 * @return array{
		 *   checked_at:int,
		 *   loopback:array{ok:bool,error:string,status:int,ms:int},
		 *   multisite:bool,
		 *   dropin:array{state:string,plugin:string},
		 *   plugin:array|null,
		 *   host:array|null,
		 *   suggestions:array,
		 *   cloudflare:bool,
		 *   recommended_mode:string,
		 *   reason:string
		 * }
		 */
		public static function run_detection() {
			$loop    = self::loopback( home_url( '/' ) );
			$headers = $loop['ok'] ? $loop['headers'] : array();

			$matches = array_merge(
				self::classify_headers( $headers ),
				self::classify_environment()
			);

			return self::decide(
				$matches,
				array(
					'loopback'  => array(
						'ok'     => $loop['ok'],
						'error'  => $loop['error'],
						'status' => $loop['status'],
						'ms'     => $loop['ms'],
					),
					'multisite' => is_multisite(),
					'dropin'    => self::dropin_state(),
					'plugins'   => self::active_cache_plugins(),
				)
			);
		}

		/**
		 * Turn the evidence into a recommendation. Pure: no I/O, so the tests can
		 * feed it fixtures.
		 *
		 * @param array $matches Signal matches from classify_headers() / classify_environment().
		 * @param array $facts   loopback, multisite, dropin, plugin.
		 * @return array See run_detection().
		 */
		public static function decide( array $matches, array $facts ) {
			$host        = null;
			$suggestions = array();
			$cloudflare  = false;

			foreach ( $matches as $m ) {
				$entry = self::host( $m['host'] );
				if ( ! $entry ) {
					continue;
				}
				if ( ! empty( $entry['edge'] ) ) {
					$cloudflare = true;
					continue;
				}
				if ( empty( $entry['caches_pages'] ) ) {
					continue;
				}
				if ( 'A' === $m['grade'] ) {
					if ( null === $host ) {
						$host = array(
							'id'              => $entry['id'],
							'name'            => $entry['name'],
							'grade'           => 'A',
							'evidence'        => array(),
							'ttl'             => isset( $entry['ttl'] ) ? $entry['ttl'] : '',
							'purge_supported' => self::purge_supported( $entry ),
						);
					}
					if ( $host['id'] === $entry['id'] ) {
						$host['evidence'][] = $m['evidence'];
					}
				} else {
					$suggestions[] = array(
						'host'     => $entry['id'],
						'name'     => $entry['name'],
						'grade'    => $m['grade'],
						'evidence' => $m['evidence'],
					);
				}
			}

			// A host proven with grade A evidence makes the weaker hints redundant.
			if ( $host ) {
				$suggestions = array_values(
					array_filter(
						$suggestions,
						static function ( $s ) use ( $host ) {
							return $s['host'] !== $host['id'];
						}
					)
				);
			}

			$dropin = isset( $facts['dropin'] ) ? $facts['dropin'] : array(
				'state' => 'none',
				'plugin' => '',
			);
			// Every active page-cache plugin counts, except one that only caches on
			// its own server type (LiteSpeed Cache) when that server isn't seen:
			// that one is a hint.
			$plugins = isset( $facts['plugins'] ) ? (array) $facts['plugins'] : ( empty( $facts['plugin'] ) ? array() : array( $facts['plugin'] ) );
			unset( $facts['plugins'] );
			$plugin = null;
			foreach ( $plugins as $p ) {
				if ( ! empty( $p['needs_server'] ) ) {
					$server_seen = false;
					foreach ( $matches as $m ) {
						if ( $m['host'] === $p['needs_server'] ) {
							$server_seen = true;
						}
					}
					if ( ! $server_seen ) {
						$suggestions[] = array(
							'host'     => $p['needs_server'],
							'name'     => $p['name'],
							'grade'    => 'C',
							'evidence' => sprintf( 'plugin %s active, but no %s server seen', $p['id'], $p['needs_server'] ),
						);
						continue;
					}
				}
				if ( ! $plugin ) {
					$plugin = $p;
				}
			}

			// Order matters: each line is a reason our cache must not run.
			if ( ! empty( $facts['multisite'] ) ) {
				$mode   = 'unavailable';
				$reason = 'multisite';
			} elseif ( 'foreign' === $dropin['state'] ) {
				$mode   = 'other_plugin';
				$reason = 'foreign_dropin';
			} elseif ( $plugin ) {
				$mode   = 'other_plugin';
				$reason = 'cache_plugin';
			} elseif ( $host ) {
				$mode   = 'host';
				$reason = 'host_cache';
			} else {
				$mode   = 'uichemy';
				$reason = empty( $facts['loopback']['ok'] ) ? 'no_loopback' : 'no_page_cache';
			}

			return array_merge(
				array( 'checked_at' => time() ),
				$facts,
				array(
					'dropin'           => $dropin,
					'plugin'           => $plugin,
					'host'             => $host,
					'suggestions'      => $suggestions,
					'cloudflare'       => $cloudflare,
					'recommended_mode' => $mode,
					'reason'           => $reason,
				)
			);
		}

		/**
		 * Match response headers against every host's header signals.
		 *
		 * @param array $headers Lower-case header name => string value.
		 * @return array<int,array{host:string,grade:string,evidence:string}>
		 */
		public static function classify_headers( array $headers ) {
			$out = array();
			foreach ( self::data()['hosts'] as $host ) {
				foreach ( isset( $host['signals'] ) ? $host['signals'] : array() as $sig ) {
					if ( 'header' !== $sig['type'] ) {
						continue;
					}
					$name = strtolower( $sig['name'] );
					if ( ! array_key_exists( $name, $headers ) ) {
						continue;
					}
					$out[] = array(
						'host'     => $host['id'],
						'grade'    => $sig['grade'],
						'evidence' => sprintf( 'header %s: %s', $name, self::short( $headers[ $name ] ) ),
					);
				}
			}
			return $out;
		}

		/**
		 * Constant, class and server-software signals from this PHP process.
		 *
		 * @return array<int,array{host:string,grade:string,evidence:string}>
		 */
		public static function classify_environment() {
			$out      = array();
			$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
			foreach ( self::data()['hosts'] as $host ) {
				foreach ( isset( $host['signals'] ) ? $host['signals'] : array() as $sig ) {
					$hit = false;
					switch ( $sig['type'] ) {
						case 'constant':
							$hit = defined( $sig['name'] );
							break;
						case 'class':
							$hit = class_exists( $sig['name'], false );
							break;
						case 'server_software':
							$hit = '' !== $software && false !== strpos( $software, $sig['match'] );
							break;
					}
					if ( $hit ) {
						$out[] = array(
							'host'     => $host['id'],
							'grade'    => $sig['grade'],
							'evidence' => sprintf( '%s %s', $sig['type'], isset( $sig['name'] ) ? $sig['name'] : $sig['match'] ),
						);
					}
				}
			}
			return $out;
		}

		/**
		 * Whose advanced-cache.php is installed.
		 *
		 * @return array{state:string,plugin:string} state: none | ours | foreign.
		 */
		public static function dropin_state() {
			$file = WP_CONTENT_DIR . '/advanced-cache.php';
			if ( ! file_exists( $file ) ) {
				return array(
					'state' => 'none',
					'plugin' => '',
				);
			}
			$head = file_get_contents( $file, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- first 2 KB of a local file.
			if ( is_string( $head ) && false !== strpos( $head, self::DROPIN_MARKER ) ) {
				return array(
					'state' => 'ours',
					'plugin' => '',
				);
			}
			$plugin = self::active_cache_plugin();
			return array(
				'state' => 'foreign',
				'plugin' => $plugin ? $plugin['name'] : '',
			);
		}

		/**
		 * Active plugins from the known page-cache plugin list, in list order.
		 *
		 * @return array<int,array{id:string,name:string,needs_server:string}>
		 */
		public static function active_cache_plugins() {
			$active = (array) get_option( 'active_plugins', array() );
			$slugs  = array();
			foreach ( $active as $file ) {
				$slugs[ strtok( (string) $file, '/' ) ] = true;
			}
			$out = array();
			foreach ( self::data()['cache_plugins'] as $p ) {
				if ( isset( $slugs[ $p['slug'] ] ) ) {
					$out[] = array(
						'id'           => $p['id'],
						'name'         => $p['name'],
						'needs_server' => isset( $p['needs_server'] ) ? $p['needs_server'] : '',
					);
				}
			}
			return $out;
		}

		/**
		 * The first active page-cache plugin, for naming a foreign drop-in.
		 *
		 * @return array|null
		 */
		public static function active_cache_plugin() {
			$all = self::active_cache_plugins();
			return $all ? $all[0] : null;
		}

		/**
		 * Can UiChemy clear this host's cache from PHP?
		 *
		 * @param array $entry Host entry.
		 * @return bool
		 */
		public static function purge_supported( array $entry ) {
			$purge = isset( $entry['purge'] ) ? $entry['purge'] : array();
			if ( empty( $purge['method'] ) || 'none' === $purge['method'] ) {
				return false;
			}
			if ( 'static_call' === $purge['method'] ) {
				return class_exists( $purge['class'], false ) && is_callable( array( $purge['class'], $purge['function'] ) );
			}
			if ( 'function' === $purge['method'] ) {
				return ! empty( $purge['function'] ) && function_exists( $purge['function'] );
			}
			if ( 'action' === $purge['method'] ) {
				return ! empty( $purge['action'] ) && has_action( $purge['action'] );
			}
			if ( 'header' === $purge['method'] ) {
				return true; // The server itself acts on the header.
			}
			return false;
		}

		/**
		 * The cache test (A03): the home page twice as a logged-out visitor.
		 *
		 * The first request may build the page (and save it); the second shows
		 * whether a cache served it. Saved for "Last test" on the status card.
		 *
		 * @return array{result:string,served_by:string,host:string,cached_ms:int,uncached_ms:int,status:int,cache_header:string,tested_at:int}
		 */
		public static function run_test() {
			$url = home_url( '/' );
			// When UiChemy caches, start from a cold page so the first request
			// measures a real WordPress build and the second the cached copy.
			if ( class_exists( 'UiChemy_Cache_Files' ) ) {
				UiChemy_Cache_Files::clear_urls( array( $url ) );
				sleep( 1 ); // The clear's tombstone blocks saves from renders started in the same second.
			}
			$first  = self::loopback( $url );
			$second = $first['ok'] ? self::loopback( $url ) : $first;

			$result = self::judge_test( $first, $second );
			update_option( self::TEST_OPTION, $result, false );
			return $result;
		}

		/**
		 * Decide what a pair of test requests shows. Pure, for the tests.
		 *
		 * @param array $first  loopback() result.
		 * @param array $second loopback() result.
		 * @return array See run_test().
		 */
		public static function judge_test( array $first, array $second ) {
			$out = array(
				'result'       => 'fail_unknown',
				'served_by'    => 'none',
				'host'         => '',
				'cached_ms'    => 0,
				'uncached_ms'  => (int) $first['ms'],
				'status'       => (int) $second['status'],
				'cache_header' => '',
				'tested_at'    => time(),
			);

			if ( ! $first['ok'] || ! $second['ok'] ) {
				$out['result'] = 'fail_no_loopback';
				return $out;
			}
			if ( 200 !== (int) $second['status'] ) {
				return $out;
			}

			$h   = $second['headers'];
			$own = isset( $h[ self::OWN_HEADER ] ) ? strtoupper( self::short( $h[ self::OWN_HEADER ] ) ) : '';
			$out['cache_header'] = $own;
			$out['cached_ms']    = (int) $second['ms'];

			if ( 'HIT' === $own ) {
				$out['result']    = 'pass';
				$out['served_by'] = 'uichemy';
				return $out;
			}

			foreach ( self::data()['hosts'] as $host ) {
				foreach ( isset( $host['hit_headers'] ) ? $host['hit_headers'] : array() as $name => $needle ) {
					if ( isset( $h[ $name ] ) && false !== stripos( self::short( $h[ $name ] ), $needle ) ) {
						$out['result']    = 'pass_host';
						$out['served_by'] = 'host';
						$out['host']      = $host['name'];
						return $out;
					}
				}
			}

			$out['result'] = 'fail_bypass';
			return $out;
		}

		/**
		 * One anonymous GET to our own site: no cookies, no auth, 5 s timeout.
		 *
		 * @param string $url  URL on this site.
		 * @param int    $hops Redirects followed so far.
		 * @return array{ok:bool,error:string,status:int,ms:int,headers:array}
		 */
		public static function loopback( $url, $hops = 0 ) {
			$start = microtime( true );
			$res   = wp_remote_get(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'cookies'     => array(),
					'headers'     => array( 'Accept' => 'text/html' ),
					'user-agent'  => 'Mozilla/5.0 (compatible; UiChemy cache test)',
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				)
			);
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $res ) ) {
				return array(
					'ok' => false,
					'error' => $res->get_error_code(),
					'status' => 0,
					'ms' => $ms,
					'headers' => array(),
				);
			}

			// Follow a redirect only to this same host (http → https, a trailing
			// slash), at most twice; never somewhere else.
			$code     = (int) wp_remote_retrieve_response_code( $res );
			$location = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $location && $hops < 2 ) {
				$next = 0 === strpos( $location, '/' ) ? home_url( $location ) : $location;
				if ( strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
					return self::loopback( $next, $hops + 1 );
				}
			}

			$headers = array();
			// Header names are case-insensitive: keep them lower case. The response
			// object is iterable (Requests' case-insensitive dictionary).
			foreach ( wp_remote_retrieve_headers( $res ) as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = $value;
			}
			return array(
				'ok'      => true,
				'error'   => '',
				'status'  => (int) wp_remote_retrieve_response_code( $res ),
				'ms'      => $ms,
				'headers' => $headers,
			);
		}

		/**
		 * A header value as one short string (repeated headers come as arrays).
		 *
		 * @param mixed $value Header value.
		 * @return string
		 */
		private static function short( $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			return substr( (string) $value, 0, 120 );
		}
	}
}
