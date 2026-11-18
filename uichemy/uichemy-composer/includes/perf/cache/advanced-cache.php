<?php
/**
 * UiChemy page cache drop-in
 *
 * UiChemy copies this file to wp-content/advanced-cache.php when the page cache
 * is turned on, replaces it when UiChemy is updated, and removes it when the
 * cache is turned off or UiChemy is deactivated. Do not edit the copy.
 *
 * WordPress loads it very early (wp-settings.php, when WP_CACHE is true), before
 * plugins, themes and the database. It does two things:
 *   1. Check 1, before WordPress: decide from the request alone whether this
 *      visitor may get a cached page, and send it if a fresh one exists.
 *   2. On a miss, buffer the output so check 2 can save the finished page once
 *      WordPress has built it. Check 2 only saves when the UiChemy plugin has
 *      looked at the page and said it may (see UiChemy_Cache_Capture).
 *
 * Rules (docs/page-cache/SECURITY.md):
 *   - Anything uncertain falls through to the normal WordPress page.
 *   - Settings come from a JSON file read with json_decode; nothing in the cache
 *     folder is ever included or evaluated.
 *   - File names are hashes; no request text becomes part of a path, and every
 *     path is checked with realpath() to stay inside the cache folder.
 *   - The public header says only HIT, MISS or BYPASS.
 *
 * Also loaded by the plugin (require_once of this source file) so key and path
 * rules exist in one place only; serving runs only from the drop-in.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Engine', false ) ) {

	class UiChemy_Cache_Engine {

		/** Bumped when the stored file layout changes. */
		const LAYOUT = 3;

		/**
		 * Bumped when the engine's methods change. The plugin uses the cache only
		 * when the loaded engine (an older drop-in right after an update) matches.
		 */
		const API = 4;

		/** No cached page lives longer than this, whatever the settings say (SECURITY S12). */
		const MAX_LIFESPAN = 36000;

		/**
		 * At most this many pages built and saved per hour (visitor misses). A
		 * flood of new addresses can't fill the disk faster than the hourly
		 * cleanup removes the oldest pages.
		 */
		const MAX_SAVES_PER_HOUR = 5000;

		/** Days of hourly traffic counts kept for the usage chart. */
		const STATS_DAYS = 14;

		/** A lock older than this is treated as abandoned. */
		const LOCK_TTL = 30;

		/** Start WordPress's scheduled tasks from a cached visit at most this often (seconds). */
		const CRON_EVERY = 60;

		/** Public response header. */
		const HEADER = 'X-UiChemy-Cache';

		/**
		 * Cookies that always mean "this visitor may see a personal page".
		 * Prefix match. Not configurable: settings and AI can add, never remove.
		 * Source: docs research DYNAMIC-PLUGINS.md section 8.
		 */
		const SAFETY_COOKIES = array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'woocommerce_recently_viewed',
			'store_notice',
			'wcml_client_',
			'edd_',
			'cart_discounts',
			'preset_discount',
			'rcp-',
			'affwp_ref',
			'affwp_campaign',
			'wp_affwp_',
			'pmpro_',
			'PHPSESSID',
			// Currency switchers and geo/age gates: the same address shows different
			// content per visitor (gap analysis H3).
			'wmc_current_currency',
			'woocs_current_currency',
			'aelia_cs_',
			'wcpbc_',
			'yith_wcmcs_',
			'geot_',
			'age_gate',
			'age-gate',
		);

		/**
		 * Language cookies. Harmless on language URLs (/fr/...), but on the home
		 * page they decide which language the visitor is sent to, so a visitor
		 * carrying one bypasses the cache there.
		 */
		const HOME_COOKIES = array(
			'pll_language',
			'wp-wpml_current_language',
			'_icl_current_language',
		);

		/**
		 * Cookies every visitor may carry that don't change the page (consent,
		 * analytics, core's test cookie). Prefix match. A request with any other
		 * cookie may read the cached page but never saves one: an unknown cookie
		 * can be a session that personalises the page (SECURITY S1).
		 */
		const ANONYMOUS_COOKIES = array(
			'wordpress_test_cookie',
			'wp-settings-',
			'_ga',
			'_gid',
			'_gat',
			'_gcl_',
			'_fbp',
			'_fbc',
			'_hj',
			'__utm',
			'_clck',
			'_clsk',
			'_pk_',
			'_uet',
			'cmplz_',
			'complianz_',
			'cookieyes-consent',
			'CookieConsent',
			'cookielawinfo-',
			'viewed_cookie_policy',
			// Bot protection and marketing tags that never change the page (gap analysis M1).
			'__cf_bm',
			'__cfruid',
			'_cfuvid',
			'cf_clearance',
			'__stripe_mid',
			'__stripe_sid',
			'hubspotutk',
			'__hstc',
			'__hssc',
			'__hssrc',
			'_ttp',
			'_tt_',
			'_pin_unauth',
			'_rdt_uuid',
			'_scid',
			'intercom-',
		);

		/**
		 * Response headers a cached page keeps (security, SEO, CORS). Lower case;
		 * a trailing "-" matches a prefix. Never Set-Cookie (gap analysis H1).
		 */
		const REPLAY_HEADERS = array(
			'content-security-policy',
			'content-security-policy-report-only',
			'strict-transport-security',
			'x-robots-tag',
			'x-frame-options',
			'x-content-type-options',
			'x-xss-protection',
			'referrer-policy',
			'permissions-policy',
			'cross-origin-',
			'access-control-',
			'link',
			'content-language',
		);

		/** Sent with every cached page so browsers check back instead of guessing (H2). */
		const HIT_CACHE_CONTROL = 'no-cache, must-revalidate, max-age=0';

		/** Paths WordPress serves dynamically. Substring match on the path. */
		const SYSTEM_PATHS = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/admin-ajax.php',
			'/wp-content/',
			'/wp-includes/',
			'/wp-signup.php',
			'/wp-activate.php',
			'/wp-comments-post.php',
			'/wp-trackback.php',
		);

		/** @var array|null|false Loaded settings snapshot; false = unusable. */
		private static $config = null;

		/** @var array|null Result of check 1 for this request. */
		public static $request = null;

		/** @var string hit | miss | bypass | '' (drop-in not run). */
		public static $state = '';

		/**
		 * Check 2 verdict from the plugin: null = not decided (never store),
		 * array( 'store' => true ) or array( 'store' => false, 'reason' => ..., 'detail' => ... ).
		 *
		 * @var array|null
		 */
		public static $verdict = null;

		/** @var array<int,array{pattern:string,reason:string,detail:string}> Content guard from rule packs. */
		public static $guard = array();

		/** @var callable|null Called as ( $hash, $reason, $detail ) after check 2. */
		public static $reporter = null;

		/** @var float When rendering started (microtime), compared with tombstones. */
		private static $started = 0.0;

		/** @var int Generation seen when the page started rendering. */
		private static $generation = 0;

		/** @var bool This request is UiChemy's own preload (signed): rebuild, don't count. */
		private static $preload = false;

		/** @var bool The buffer was flushed early, so it is not the whole page. */
		private static $partial = false;

		// ------------------------------------------------------------------
		// Drop-in entry point.
		// ------------------------------------------------------------------

		/**
		 * Run check 1, then serve a hit or start capturing a miss.
		 *
		 * @return void
		 */
		public static function boot() {
			if ( 'cli' === PHP_SAPI || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
				return;
			}
			try {
				$config = self::config();
				if ( ! $config || empty( $config['enabled'] ) ) {
					return;
				}
				$request       = self::check_request( $_SERVER, $_COOKIE, $config ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- check_request only compares and hashes.
				self::$request = $request;

				if ( $request['reason'] ) {
					self::$state = 'bypass';
					self::header( 'BYPASS' );
					return;
				}

				// UiChemy's own preload (signed with the site's keys) rebuilds the page even
				// when a fresh copy exists, so pages are refreshed before they expire.
				self::$preload = self::is_preload( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with hash_equals only.
				$file          = self::$preload ? null : self::fresh_file( $request['hash'], $config );
				if ( $file ) {
					self::serve( $file, $config );
					return;
				}

				self::$state = 'miss';
				self::header( 'MISS' );
				if ( $request['can_store'] ) {
					self::$started    = microtime( true );
					self::$generation = (int) $config['generation'];
					ob_start( array( __CLASS__, 'capture' ) );
				}
			} catch ( \Throwable $e ) {
				self::$state = '';
				return;
			}
		}

		// ------------------------------------------------------------------
		// Traffic counter (the usage chart).
		// ------------------------------------------------------------------

		/**
		 * Count one request: append a single byte to this hour's file for the
		 * kind, so the file's size IS the count. One append, no read, no lock
		 * (O_APPEND is atomic for one byte), no per-request history, and files
		 * older than STATS_DAYS are removed by the cleanup. Never throws.
		 *
		 * @param string $kind 'h' (served from the cache) or 'm' (built fresh and saved).
		 * @return void
		 */
		private static function tick( $kind ) {
			try {
				$dir = self::root() . '/stats';
				if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
					return;
				}
				@file_put_contents( $dir . '/' . $kind . '-' . gmdate( 'YmdH' ), '.', FILE_APPEND ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return;
			}
		}

		// ------------------------------------------------------------------
		// Settings snapshot.
		// ------------------------------------------------------------------

		/**
		 * The cache folder.
		 *
		 * @return string
		 */
		public static function root() {
			return WP_CONTENT_DIR . '/uichemy/cache';
		}

		/**
		 * The settings snapshot, validated. Read once per request unless $fresh.
		 *
		 * @param bool $fresh Read from disk again.
		 * @return array|null
		 */
		public static function config( $fresh = false ) {
			if ( null !== self::$config && ! $fresh ) {
				return self::$config ? self::$config : null;
			}
			self::$config = false;
			$file         = self::root() . '/config.json';
			if ( $fresh ) {
				clearstatcache( true, $file );
			}
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return null;
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs before WordPress.
			if ( ! is_array( $data ) || empty( $data['host'] ) || ! isset( $data['generation'] ) ) {
				return null;
			}
			$list = static function ( $v ) {
				return is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : array();
			};
			$rules = static function ( $v ) {
				$out = array();
				foreach ( is_array( $v ) ? $v : array() as $r ) {
					if ( is_array( $r ) && isset( $r['p'] ) && is_string( $r['p'] ) ) {
						$out[] = array(
							'p'      => $r['p'],
							'prefix' => ! empty( $r['prefix'] ),
							'plugin' => isset( $r['plugin'] ) && is_string( $r['plugin'] ) ? $r['plugin'] : '',
						);
					}
				}
				return $out;
			};
			self::$config = array(
				'enabled'        => ! empty( $data['enabled'] ),
				'generation'     => (int) $data['generation'],
				'host'           => strtolower( (string) $data['host'] ),
				'home_path'      => isset( $data['home_path'] ) && is_string( $data['home_path'] ) ? $data['home_path'] : '/',
				'lifespan'       => max( 60, min( self::MAX_LIFESPAN, isset( $data['lifespan'] ) ? (int) $data['lifespan'] : self::MAX_LIFESPAN ) ),
				'mobile'         => ! empty( $data['mobile'] ),
				'cookies'        => $list( isset( $data['cookies'] ) ? $data['cookies'] : array() ),
				'ignored_params' => $list( isset( $data['ignored_params'] ) ? $data['ignored_params'] : array() ),
				'exclude'        => $rules( isset( $data['exclude'] ) ? $data['exclude'] : array() ),
				'rule_paths'     => $rules( isset( $data['rule_paths'] ) ? $data['rule_paths'] : array() ),
				'charset'        => isset( $data['charset'] ) && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $data['charset'] ) ? $data['charset'] : 'UTF-8',
				'cron_path'      => isset( $data['cron_path'] ) && is_string( $data['cron_path'] ) && preg_match( '#^/[A-Za-z0-9/_.-]*wp-cron\.php$#', $data['cron_path'] ) ? $data['cron_path'] : '',
			);
			return self::$config;
		}

		/**
		 * Forget the snapshot read in this request (after the plugin rewrites it).
		 *
		 * @return void
		 */
		public static function reset_config() {
			self::$config = null;
		}

		// ------------------------------------------------------------------
		// Check 1 and the cache key. Pure: the tests call it with fixtures.
		// ------------------------------------------------------------------

		/**
		 * Decide from the request alone whether it may use the cache.
		 *
		 * @param array $server  $_SERVER-shaped array.
		 * @param array $cookies $_COOKIE-shaped array.
		 * @param array $config  Snapshot from config().
		 * @return array{reason:string,detail:string,hash:string,path:string,can_store:bool,device:string,scheme:string}
		 */
		public static function check_request( array $server, array $cookies, array $config ) {
			$out = array(
				'reason'    => '',
				'detail'    => '',
				'hash'      => '',
				'path'      => '',
				'can_store' => false,
				'device'    => 'desktop',
				'scheme'    => 'http',
			);

			$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return self::bypass( $out, 'method', $method );
			}

			if ( ! empty( $server['HTTP_AUTHORIZATION'] ) || ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $server['PHP_AUTH_USER'] ) ) {
				return self::bypass( $out, 'logged_in', 'authorization' );
			}

			$extra        = isset( $config['cookies'] ) ? $config['cookies'] : array();
			$unknown_seen = false;
			$home_cookie  = '';
			foreach ( array_keys( $cookies ) as $name ) {
				$name = (string) $name;
				if ( self::prefix_in( $name, self::HOME_COOKIES ) ) {
					$home_cookie = $name;
					continue;
				}
				if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
					return self::bypass( $out, 'logged_in', '' );
				}
				foreach ( array_merge( self::SAFETY_COOKIES, $extra ) as $prefix ) {
					if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
						return self::bypass( $out, 'cookie', $name );
					}
				}
				if ( ! self::prefix_in( $name, self::ANONYMOUS_COOKIES ) ) {
					$unknown_seen = true;
				}
			}

			$host = isset( $server['HTTP_HOST'] ) ? strtolower( (string) $server['HTTP_HOST'] ) : '';
			if ( '' === $host || $host !== $config['host'] ) {
				return self::bypass( $out, 'host_mismatch', '' );
			}

			$uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
			$qpos  = strpos( $uri, '?' );
			$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
			$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );

			$path = self::normalize_path( $path );
			if ( null === $path ) {
				return self::bypass( $out, 'system_path', '' );
			}
			$out['path'] = $path;

			$lower = strtolower( $path );
			foreach ( self::SYSTEM_PATHS as $sys ) {
				if ( false !== strpos( $lower, $sys ) ) {
					return self::bypass( $out, 'system_path', '' );
				}
			}

			$rel = self::relative_path( $path, $config['home_path'] );
			if ( '' !== $home_cookie && '/' === $rel ) {
				return self::bypass( $out, 'cookie', $home_cookie );
			}
			foreach ( $config['exclude'] as $rule ) {
				if ( self::path_matches( $rel, $rule ) ) {
					return self::bypass( $out, 'excluded_page', '' );
				}
			}
			foreach ( $config['rule_paths'] as $rule ) {
				if ( self::path_matches( $rel, $rule ) ) {
					return self::bypass( $out, 'rule_pack', $rule['plugin'] );
				}
			}

			// Query strings: a page with any query is never saved. One whose
			// parameters are all on the ignore list may read the clean copy.
			$can_store = true;
			if ( '' !== $query ) {
				foreach ( explode( '&', $query ) as $pair ) {
					if ( '' === $pair ) {
						continue;
					}
					$eq   = strpos( $pair, '=' );
					$name = urldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
					if ( ! self::param_ignored( $name, $config['ignored_params'] ) ) {
						return self::bypass( $out, 'query_string', substr( $name, 0, 60 ) );
					}
					$can_store = false;
				}
			}

			$out['scheme'] = self::is_https( $server ) ? 'https' : 'http';
			if ( ! empty( $config['mobile'] ) ) {
				$ua            = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';
				// Same signals as core's wp_is_mobile(), so the copy matches what the theme rendered.
				$ch_mobile     = isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) && false !== strpos( (string) $server['HTTP_SEC_CH_UA_MOBILE'], '1' );
				$out['device'] = $ch_mobile || preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi|iPhone|iPod|IEMobile/i', $ua ) ? 'mobile' : 'desktop';
			}
			$out['hash']      = self::hash( $out['scheme'], $config['host'], $path, $out['device'] );
			$out['can_store'] = $can_store && ! $unknown_seen && 'GET' === $method;
			return $out;
		}

		/**
		 * Does $name start with one of $prefixes?
		 *
		 * @param string   $name     Cookie name.
		 * @param string[] $prefixes Prefixes.
		 * @return bool
		 */
		private static function prefix_in( $name, array $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * The cache key: the site's own host from settings, never the request's.
		 *
		 * @param string $scheme http | https.
		 * @param string $host   Host from settings.
		 * @param string $path   Normalised path.
		 * @param string $device desktop | mobile.
		 * @return string 40 hex characters.
		 */
		public static function hash( $scheme, $host, $path, $device ) {
			$data = self::LAYOUT . '|' . $scheme . '|' . $host . '|' . $path . '|' . $device;
			// Keyed with the site's own wp-config.php keys: where the server lets
			// anyone read the cache folder (nginx ignores .htaccess), file names
			// still can't be worked out from a page's address.
			if ( defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) && '' !== AUTH_KEY . AUTH_SALT ) {
				return hash_hmac( 'sha1', $data, 'uichemy-cache|' . AUTH_KEY . AUTH_SALT );
			}
			return sha1( $data );
		}

		/**
		 * A request path made safe to key on, or null when it looks hostile.
		 *
		 * Percent-encoding is put in one canonical form, so every spelling of the
		 * same address (/%D0%BF/ and /%d0%bf/, /%61bout/ and /about/) has one key
		 * and one clear removes it: unreserved characters are decoded and other
		 * escapes are uppercased (RFC 3986 section 6.2.2).
		 *
		 * @param string $path Raw path (no query).
		 * @return string|null
		 */
		public static function normalize_path( $path ) {
			if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 1024 ) {
				return null;
			}
			$decoded = rawurldecode( $path );
			if ( false !== strpos( $decoded, "\0" ) || false !== strpos( $decoded, '\\' ) || preg_match( '#(^|/)\.\.?(/|$)#', $decoded ) ) {
				return null;
			}
			if ( preg_match( '#%2f|%5c|%00#i', $path ) || preg_match( '#%(?![0-9A-Fa-f]{2})#', $path ) ) {
				return null;
			}
			$path = preg_replace_callback(
				'#%([0-9A-Fa-f]{2})#',
				static function ( $m ) {
					$chr = chr( hexdec( $m[1] ) );
					return preg_match( '#[A-Za-z0-9._~-]#', $chr ) ? $chr : '%' . strtoupper( $m[1] );
				},
				$path
			);
			return preg_replace( '#/{2,}#', '/', $path );
		}

		/**
		 * The path below the site's home path, starting with "/".
		 *
		 * @param string $path      Request path.
		 * @param string $home_path Home path from settings ("/" or "/sub/").
		 * @return string
		 */
		public static function relative_path( $path, $home_path ) {
			$home = strtolower( '/' . trim( (string) $home_path, '/' ) );
			if ( '/' === $home ) {
				return $path;
			}
			$lower = strtolower( $path );
			if ( 0 === strpos( $lower, $home . '/' ) || $lower === $home ) {
				$rest = substr( $path, strlen( $home ) );
				return '' === $rest ? '/' : $rest;
			}
			return $path;
		}

		/**
		 * Exact match (trailing slash optional) or prefix match. Both sides are
		 * compared normalised and case-insensitive, so /Members/ or /%6Dembers/
		 * can't slip past an exclusion.
		 *
		 * @param string $rel  Relative path (normalised).
		 * @param array  $rule { p, prefix }.
		 * @return bool
		 */
		public static function path_matches( $rel, array $rule ) {
			$p = self::normalize_path( (string) $rule['p'] );
			if ( null === $p || '' === $p ) {
				return false;
			}
			$p   = strtolower( $p );
			$rel = strtolower( $rel );
			if ( ! empty( $rule['prefix'] ) ) {
				return 0 === strpos( $rel, $p );
			}
			return rtrim( $rel, '/' ) === rtrim( $p, '/' ) || ( '/' === $p && '/' === $rel );
		}

		/**
		 * Is a query parameter on the ignore list? Trailing * = prefix.
		 *
		 * @param string $name    Parameter name.
		 * @param array  $ignored Ignore list.
		 * @return bool
		 */
		public static function param_ignored( $name, array $ignored ) {
			foreach ( $ignored as $rule ) {
				if ( '*' === substr( $rule, -1 ) ) {
					if ( strlen( $rule ) > 1 && 0 === strpos( $name, substr( $rule, 0, -1 ) ) ) {
						return true;
					}
				} elseif ( $name === $rule ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Same test as core is_ssl(); forwarded headers are not trusted here.
		 *
		 * @param array $server $_SERVER-shaped array.
		 * @return bool
		 */
		private static function is_https( array $server ) {
			if ( isset( $server['HTTPS'] ) && ( '1' === (string) $server['HTTPS'] || 'on' === strtolower( (string) $server['HTTPS'] ) ) ) {
				return true;
			}
			return isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'];
		}

		/**
		 * @param array  $out    Result so far.
		 * @param string $reason Reason code (copy.json "reasons").
		 * @param string $detail Detail for admins only.
		 * @return array
		 */
		private static function bypass( array $out, $reason, $detail ) {
			$out['reason']    = $reason;
			$out['detail']    = (string) $detail;
			$out['can_store'] = false;
			return $out;
		}

		// ------------------------------------------------------------------
		// Files.
		// ------------------------------------------------------------------

		/**
		 * Folder of one generation.
		 *
		 * @param int $generation Generation number.
		 * @return string
		 */
		public static function generation_dir( $generation ) {
			return self::root() . '/pages/g' . (int) $generation;
		}

		/**
		 * Where a key's files live.
		 *
		 * @param string $hash       Key.
		 * @param int    $generation Generation number.
		 * @return string Path without extension.
		 */
		public static function file_base( $hash, $generation ) {
			return self::generation_dir( $generation ) . '/' . substr( $hash, 0, 2 ) . '/' . $hash;
		}

		/**
		 * The tombstone written when one page is cleared, so a render that started
		 * before the clear cannot save the old page after it.
		 *
		 * @param string $hash Key.
		 * @return string
		 */
		public static function tombstone( $hash ) {
			return self::root() . '/purged/' . substr( $hash, 0, 2 ) . '/' . $hash;
		}

		/**
		 * Is $path inside the cache folder? Resolves symlinks and "..".
		 *
		 * @param string $path Existing file or folder.
		 * @return bool
		 */
		public static function inside_root( $path ) {
			$root = realpath( self::root() );
			$real = realpath( $path );
			return $root && $real && ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) );
		}

		/**
		 * The cached file for a key if it exists and is within its lifespan.
		 *
		 * @param string $hash   Key.
		 * @param array  $config Snapshot.
		 * @return string|null
		 */
		public static function fresh_file( $hash, array $config ) {
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $hash ) ) {
				return null;
			}
			$file = self::file_base( $hash, $config['generation'] ) . '.html';
			clearstatcache( true, $file );
			if ( ! is_file( $file ) || ! self::inside_root( $file ) ) {
				return null;
			}
			$age = time() - (int) filemtime( $file );
			if ( $age < 0 || $age >= min( (int) $config['lifespan'], self::MAX_LIFESPAN ) ) {
				return null;
			}
			return $file;
		}

		/**
		 * Send a cached page and stop.
		 *
		 * @param string $file   .html file.
		 * @param array  $config Snapshot.
		 * @return void
		 */
		private static function serve( $file, array $config ) {
			$gz      = $file . '.gz';
			$accepts = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( (string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			// The .gz must be from the same save as the .html, never older.
			$use_gz  = $accepts && is_file( $gz ) && filemtime( $gz ) >= filemtime( $file ) && ! ini_get( 'zlib.output_compression' );
			$send    = $use_gz ? $gz : $file;
			$size    = filesize( $send );
			$mtime   = (int) filemtime( $file );
			if ( false === $size || headers_sent() ) {
				return;
			}

			self::$state = 'hit';
			self::tick( 'h' );
			header( 'Content-Type: text/html; charset=' . $config['charset'] );
			header( self::HEADER . ': HIT' );
			header( 'Vary: Accept-Encoding' );
			header( 'Cache-Control: ' . self::HIT_CACHE_CONTROL );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
			foreach ( self::stored_headers( $file ) as $line ) {
				header( $line, false );
			}

			// The browser's copy is still the current one: answer 304, no body.
			$since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed as a date only.
			if ( $since && $since >= $mtime ) {
				http_response_code( 304 );
				exit;
			}

			if ( $use_gz ) {
				header( 'Content-Encoding: gzip' );
			}
			header( 'Content-Length: ' . $size );
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			if ( 'HEAD' !== $method ) {
				readfile( $send ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- runs before WordPress.
			}
			self::poke_cron( $config );
			exit;
		}

		/**
		 * Cached visits never load WordPress, so on a busy, fully cached site its
		 * scheduled tasks (scheduled posts, preload, cleanup) would rarely run. At
		 * most once a minute, after this visitor's page has been sent, ask the site
		 * to run them in the background, the same way WordPress itself does. Skipped
		 * when the site uses a real server cron (DISABLE_WP_CRON).
		 *
		 * @param array $config Snapshot.
		 * @return void
		 */
		private static function poke_cron( array $config ) {
			try {
				if ( '' === $config['cron_path'] || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) || ! function_exists( 'curl_init' ) ) {
					return;
				}
				$lock = self::root() . '/cron.lock';
				$last = @filemtime( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $last && time() - $last < self::CRON_EVERY ) {
					return;
				}
				@touch( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// Let the visitor go first: the page is complete, the request ends here for them.
				$finished = false;
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					$finished = fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					$finished = litespeed_finish_request();
				}
				$scheme = isset( self::$request['scheme'] ) ? self::$request['scheme'] : 'http';
				$ch     = curl_init( $scheme . '://' . $config['host'] . $config['cron_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- runs before WordPress.
				curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
					$ch,
					array(
						CURLOPT_POST           => true,
						CURLOPT_POSTFIELDS     => '',
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => false,
						CURLOPT_NOSIGNAL       => true,
						// Own site only (host from settings): skip certificate checks as core does for loopbacks.
						CURLOPT_SSL_VERIFYPEER => false,
						CURLOPT_SSL_VERIFYHOST => 0,
						// Like core's spawn_cron(): fire and forget. When the visitor was already
						// released we can afford to wait a little longer for the connection.
						CURLOPT_TIMEOUT_MS     => $finished ? 1000 : 50,
						CURLOPT_USERAGENT      => 'UiChemy page cache (WordPress cron)',
					)
				);
				curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
				curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return;
			}
		}

		/**
		 * Headers saved with a page (REPLAY_HEADERS only), from its .meta file.
		 *
		 * @param string $file The page's .html file.
		 * @return string[] "Name: value" lines.
		 */
		private static function stored_headers( $file ) {
			$meta = substr( $file, 0, -5 ) . '.meta';
			if ( ! is_file( $meta ) || filemtime( $meta ) < filemtime( $file ) ) {
				return array();
			}
			$data = json_decode( (string) file_get_contents( $meta ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs before WordPress.
			$out  = array();
			foreach ( isset( $data['h'] ) && is_array( $data['h'] ) ? $data['h'] : array() as $line ) {
				if ( is_string( $line ) && self::replayable( $line ) ) {
					$out[] = $line;
				}
			}
			return $out;
		}

		/**
		 * Is a "Name: value" header line one a cached page may send again?
		 *
		 * @param string $line Header line.
		 * @return bool
		 */
		public static function replayable( $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos || strlen( $line ) > 4096 || preg_match( '/[\r\n]/', $line ) ) {
				return false;
			}
			$name = strtolower( trim( substr( $line, 0, $pos ) ) );
			foreach ( self::REPLAY_HEADERS as $h ) {
				if ( $name === $h || ( '-' === substr( $h, -1 ) && 0 === strpos( $name, $h ) ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param string $value HIT | MISS | BYPASS.
		 * @return void
		 */
		private static function header( $value ) {
			if ( ! headers_sent() ) {
				header( self::HEADER . ': ' . $value );
			}
		}

		// ------------------------------------------------------------------
		// Check 2 and store.
		// ------------------------------------------------------------------

		/**
		 * Output buffer callback. Always returns the page unchanged.
		 *
		 * @param string $html  Buffer contents.
		 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
		 * @return string
		 */
		public static function capture( $html, $phase ) {
			if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
				// Flushed early, or discarded by ob_end_clean(): what we see is not
				// what the visitor got.
				self::$partial = true;
				return $html;
			}
			try {
				if ( ! self::$partial ) {
					self::finish( (string) $html );
				}
			} catch ( \Throwable $e ) {
				// The visitor still gets the page; it just isn't saved.
				return $html;
			}
			return $html;
		}

		/**
		 * Check 2: decide whether the finished page may be saved, then save it.
		 *
		 * @param string $html Whole page.
		 * @return void
		 */
		private static function finish( $html ) {
			$req = self::$request;
			if ( ! $req || empty( $req['can_store'] ) ) {
				return;
			}
			list( $reason, $detail ) = self::response_check( $html, http_response_code(), headers_list() );
			if ( '' === $reason ) {
				$reason = self::store( $req['hash'], $html );
				if ( '' === $reason && ! self::$preload ) {
					// Counted only when a page was built AND saved. Favicon redirects,
					// robots.txt, 404s and other responses that can never be cached
					// would otherwise read as a miss on every page view.
					self::tick( 'm' );
				}
			}
			self::report( $req['hash'], $reason, $detail );
		}

		/**
		 * Every reason a rendered page must not be saved.
		 *
		 * @param string   $html    Whole page.
		 * @param int|bool $status  HTTP status.
		 * @param array    $headers headers_list().
		 * @return array{0:string,1:string} reason code ('' = may store) and detail.
		 */
		public static function response_check( $html, $status, array $headers ) {
			$v = self::$verdict;
			if ( ! is_array( $v ) ) {
				return array( 'unknown', '' );
			}
			if ( empty( $v['store'] ) ) {
				return array( isset( $v['reason'] ) ? (string) $v['reason'] : 'unknown', isset( $v['detail'] ) ? (string) $v['detail'] : '' );
			}
			if ( 200 !== (int) $status ) {
				return array( 'status', (string) (int) $status );
			}
			$html_type = false;
			foreach ( $headers as $line ) {
				$pos = strpos( $line, ':' );
				if ( false === $pos ) {
					continue;
				}
				$name  = strtolower( trim( substr( $line, 0, $pos ) ) );
				$value = trim( substr( $line, $pos + 1 ) );
				if ( 'set-cookie' === $name ) {
					return array( 'sets_cookie', substr( $value, 0, (int) strcspn( $value, '=' ) ) );
				}
				if ( 'cache-control' === $name && preg_match( '/private|no-store/i', $value ) ) {
					return array( 'private_header', '' );
				}
				// The page says it differs by cookie, device or language: one shared
				// copy would be wrong for someone (Accept-Encoding is handled here).
				if ( 'vary' === $name && '' !== trim( (string) preg_replace( '/accept-encoding/i', '', $value ), " ,\t" ) ) {
					return array( 'vary', substr( $value, 0, 60 ) );
				}
				if ( 'content-type' === $name ) {
					$html_type = false !== stripos( $value, 'text/html' );
					if ( ! $html_type ) {
						return array( 'not_html', '' );
					}
				}
			}
			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				return array( 'donotcache', '' );
			}
			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				return array( 'logged_in', '' );
			}
			if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
				return array( 'not_html', '' );
			}
			foreach ( self::$guard as $g ) {
				if ( @preg_match( $g['pattern'], $html ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern must not break the page.
					return array( $g['reason'], $g['detail'] );
				}
			}
			return array( '', '' );
		}

		/**
		 * Save a page: temp file + rename, one writer per key.
		 *
		 * @param string $hash Key.
		 * @param string $html Whole page.
		 * @return string '' when saved, else a reason code.
		 */
		public static function store( $hash, $html ) {
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $hash ) ) {
				return 'unknown';
			}
			// A clear while this page was rendering wins: never republish the old page.
			$config = self::config( true );
			if ( ! $config || empty( $config['enabled'] ) || (int) $config['generation'] !== self::$generation ) {
				return 'busy';
			}
			$tomb = self::tombstone( $hash );
			if ( self::cleared_since( $tomb ) ) {
				return 'busy';
			}
			$saves = @filesize( self::root() . '/stats/m-' . gmdate( 'YmdH' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $saves && $saves >= self::MAX_SAVES_PER_HOUR ) {
				return 'size_limit';
			}

			$base = self::file_base( $hash, $config['generation'] );
			$dir  = dirname( $base );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				return 'storage';
			}
			if ( ! self::inside_root( $dir ) ) {
				return 'storage';
			}

			$lock = $base . '.lock';
			if ( ! self::lock( $lock ) ) {
				return 'busy';
			}
			try {
				// An older .gz or .meta must never outlive the .html it was made from.
				foreach ( array( '.html.gz', '.meta' ) as $ext ) {
					if ( is_file( $base . $ext ) ) {
						@unlink( $base . $ext ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				}
				$stamp = "\n<!-- Cached by UiChemy page cache, " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
				$body  = $html . $stamp;
				$ok    = self::write( $base . '.html', $body );
				if ( $ok && function_exists( 'gzencode' ) ) {
					$gz = gzencode( $body, 6 );
					if ( false !== $gz ) {
						self::write( $base . '.html.gz', $gz );
					}
				}
				if ( $ok ) {
					$keep = array_values( array_filter( function_exists( 'headers_list' ) ? headers_list() : array(), array( __CLASS__, 'replayable' ) ) );
					if ( $keep ) {
						self::write( $base . '.meta', (string) json_encode( array( 'h' => array_slice( $keep, 0, 20 ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs before WordPress.
					}
				}
			} finally {
				@unlink( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( ! $ok ) {
				return 'storage';
			}

			// A clear that landed while we were writing wins: take our copy back.
			// Tombstones are never deleted here (gc ages them out), so a slower
			// render that started before a clear can't undo it later.
			$after = self::config( true );
			if ( self::cleared_since( $tomb ) || ! $after || (int) $after['generation'] !== self::$generation ) {
				foreach ( array( '.html', '.html.gz', '.meta' ) as $ext ) {
					@unlink( $base . $ext ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return 'busy';
			}
			return '';
		}

		/**
		 * Atomic write: temp file in the same folder, then rename.
		 *
		 * @param string $file Target.
		 * @param string $data Contents.
		 * @return bool
		 */
		public static function write( $file, $data ) {
			$tmp     = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			$written = @file_put_contents( $tmp, $data, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			// A full disk can write part of the data: never rename a truncated file into place.
			if ( strlen( $data ) !== $written ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return false;
			}
			@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return false;
			}
			return true;
		}

		/**
		 * The signature preload sends in X-UiChemy-Preload: an HMAC of a 5-minute
		 * window keyed with the site's own wp-config.php keys, so nothing secret is
		 * stored and outsiders can't force rebuilds.
		 *
		 * @param int|null $window Time window (default: now).
		 * @return string '' when the site has no keys.
		 */
		public static function preload_token( $window = null ) {
			if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) || '' === AUTH_KEY . AUTH_SALT ) {
				return '';
			}
			$window = null === $window ? (int) floor( time() / 300 ) : (int) $window;
			return hash_hmac( 'sha256', 'uichemy-preload|' . $window, AUTH_KEY . AUTH_SALT );
		}

		/**
		 * Is this request UiChemy's own preload? (This window or the previous one.)
		 *
		 * @param array $server $_SERVER-shaped array.
		 * @return bool
		 */
		public static function is_preload( array $server ) {
			if ( empty( $server['HTTP_X_UICHEMY_PRELOAD'] ) ) {
				return false;
			}
			$given = (string) $server['HTTP_X_UICHEMY_PRELOAD'];
			$now   = (int) floor( time() / 300 );
			foreach ( array( $now, $now - 1 ) as $w ) {
				$want = self::preload_token( $w );
				if ( '' !== $want && hash_equals( $want, $given ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Was this page cleared after the current render started? The clear writes
		 * its exact time into the tombstone (older tombstones are empty: fall back
		 * to the file time, whole seconds, erring on "cleared").
		 *
		 * @param string $tomb Tombstone path.
		 * @return bool
		 */
		private static function cleared_since( $tomb ) {
			clearstatcache( true, $tomb );
			if ( ! is_file( $tomb ) ) {
				return false;
			}
			$at = (float) trim( (string) @file_get_contents( $tomb ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $at > 0 ) {
				return $at >= self::$started;
			}
			return (int) filemtime( $tomb ) >= (int) floor( self::$started );
		}

		/**
		 * Take a short lock; a busy lock means another request is saving.
		 *
		 * @param string $lock Lock file.
		 * @return bool
		 */
		private static function lock( $lock ) {
			$h = @fopen( $lock, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $h ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return true;
			}
			clearstatcache( true, $lock );
			if ( is_file( $lock ) && time() - (int) filemtime( $lock ) > self::LOCK_TTL ) {
				@unlink( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$h = @fopen( $lock, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $h ) {
					fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return true;
				}
			}
			return false;
		}

		/**
		 * Hand the outcome to the plugin's diagnostics, if it is listening.
		 *
		 * @param string $hash   Key.
		 * @param string $reason Reason code, '' when saved.
		 * @param string $detail Detail.
		 * @return void
		 */
		private static function report( $hash, $reason, $detail ) {
			if ( is_callable( self::$reporter ) ) {
				call_user_func( self::$reporter, $hash, $reason, $detail );
			}
		}
	}
}

// Serve only when WordPress loads this as the drop-in, never when the plugin
// requires the source file for the shared rules above.
if ( function_exists( 'did_action' ) && ! did_action( 'muplugins_loaded' ) && defined( 'WP_CACHE' ) && WP_CACHE ) {
	UiChemy_Cache_Engine::boot();
}
