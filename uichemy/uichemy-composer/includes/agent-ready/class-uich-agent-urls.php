<?php
/**
 * Agent Ready URL helpers: map a page address to its agent file and back,
 * and read what the client asked for.
 *
 * The mapping is one rule both ways, so every URL type works the same:
 *   /            <-> /index.md
 *   /menu/       <-> /menu.md
 *   /fr/menu/    <-> /fr/menu.md        (language prefixes need no special case)
 *   /blog/page/2/<-> /blog/page/2.md
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Urls' ) ) {

	/**
	 * URL mapping and request inspection.
	 */
	class Uich_Agent_Urls {

		/**
		 * The site's home path, always with leading and trailing slash.
		 *
		 * @return string
		 */
		public static function home_path() {
			$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return '/' . trim( $path, '/' ) . ( '' === trim( $path, '/' ) ? '' : '/' );
		}

		/**
		 * The request path (no query), as the browser sent it.
		 *
		 * @return string
		 */
		public static function request_path() {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed below, never output raw.
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$path = '/' . ltrim( rawurldecode( $path ), '/' );
			return preg_replace( '#/+#', '/', $path );
		}

		/**
		 * Agent file path for a page path.
		 *
		 * @param string $path Page path, e.g. "/menu/".
		 * @return string
		 */
		public static function md_path( $path ) {
			$home = self::home_path();
			$path = '/' . ltrim( (string) $path, '/' );
			$rel  = trim( substr( $path, 0, strlen( $home ) ) === $home ? substr( $path, strlen( $home ) ) : ltrim( $path, '/' ), '/' );
			return $home . ( '' === $rel ? 'index' : $rel ) . '.md';
		}

		/**
		 * Page path for an agent file path, or false when it is not one.
		 *
		 * @param string $md_path Path ending in .md.
		 * @return string|false
		 */
		public static function page_path( $md_path ) {
			if ( ! preg_match( '#\.md$#i', $md_path ) ) {
				return false;
			}
			$home = self::home_path();
			if ( substr( $md_path, 0, strlen( $home ) ) !== $home ) {
				return false;
			}
			$rel = substr( $md_path, strlen( $home ), -3 );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				return false;
			}
			if ( 'index' === $rel ) {
				return $home;
			}
			if ( '/index' === substr( $rel, -6 ) ) {
				$rel = substr( $rel, 0, -6 );
			}
			$slash = '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? '/' : '';
			return $home . $rel . $slash;
		}

		/**
		 * Absolute agent-file URL for a page URL (same host only).
		 *
		 * @param string $url Page URL.
		 * @return string
		 */
		public static function md_url( $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return home_url( ltrim( substr( self::md_path( '' === $path ? '/' : $path ), strlen( self::home_path() ) ), '/' ) );
		}

		/**
		 * Whether the client prefers Markdown over HTML.
		 *
		 * Reads the Accept header's media ranges and quality values. Markdown wins
		 * when it is listed and its quality is at least HTML's (ties go to the one
		 * listed first). Browsers never list text/markdown, so they always get HTML.
		 *
		 * @param string|null $accept Accept header; null reads the current request.
		 * @return bool
		 */
		public static function prefers_markdown( $accept = null ) {
			if ( null === $accept ) {
				$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, never output.
			}
			if ( false === stripos( $accept, 'text/markdown' ) ) {
				return false;
			}
			$md   = -1;
			$html = -1;
			$md_at   = PHP_INT_MAX;
			$html_at = PHP_INT_MAX;
			foreach ( explode( ',', strtolower( $accept ) ) as $i => $range ) {
				$parts = array_map( 'trim', explode( ';', $range ) );
				$type  = array_shift( $parts );
				$q     = 1.0;
				foreach ( $parts as $param ) {
					if ( 0 === strpos( $param, 'q=' ) ) {
						$q = (float) substr( $param, 2 );
					}
				}
				if ( 'text/markdown' === $type && $q > $md ) {
					$md    = $q;
					$md_at = $i;
				} elseif ( 'text/html' === $type && $q > $html ) {
					$html    = $q;
					$html_at = $i;
				}
			}
			if ( $md <= 0 ) {
				return false;
			}
			return $md > $html || ( $md === $html && $md_at < $html_at );
		}

		/**
		 * Header that marks the site's own loopback requests (get_page, audit), so the
		 * visitor's call that caused them is not counted twice against the server's IP.
		 */
		const LOOPBACK_HEADER_NAME = 'X-UiChemy-Agent-Loopback';

		/**
		 * Signed token for a loopback request, valid for the minute it was made.
		 *
		 * @param int $time Unix time.
		 * @return string
		 */
		public static function loopback_token( $time ) {
			return hash_hmac( 'sha256', 'uichemy-agent-loopback|' . (int) floor( $time / MINUTE_IN_SECONDS ) . '|' . home_url( '/' ), wp_salt( 'auth' ) );
		}

		/**
		 * Whether this request is the site's own loopback (signed this minute or the last).
		 *
		 * @return bool
		 */
		public static function is_loopback() {
			$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', self::LOOPBACK_HEADER_NAME ) );
			$given = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with hash_equals only.
			if ( '' === $given ) {
				return false;
			}
			$now = time();
			return hash_equals( self::loopback_token( $now ), $given ) || hash_equals( self::loopback_token( $now - MINUTE_IN_SECONDS ), $given );
		}

		/**
		 * Fetch one of this site's own agent URLs. Uses the safe HTTP client and
		 * follows at most two redirects, and only ones that stay on this site, so
		 * a page that redirects elsewhere can never make the site fetch an outside
		 * or internal address.
		 *
		 * @param string $url    URL on this site.
		 * @param string $accept Accept header (default Markdown).
		 * @return array|WP_Error HTTP response.
		 */
		public static function fetch_own( $url, $accept = 'text/markdown' ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			for ( $hop = 0; $hop < 3; $hop++ ) {
				if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
					return new WP_Error( 'uich_agent_offsite', 'Only this site can be fetched.' );
				}
				$resp = wp_safe_remote_get(
					$url,
					array(
						'timeout'     => 15,
						'redirection' => 0,
						'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
						'headers'     => array(
							'Accept'                    => $accept,
							self::LOOPBACK_HEADER_NAME => self::loopback_token( time() ),
						),
						'cookies'     => array(),
					)
				);
				if ( is_wp_error( $resp ) ) {
					return $resp;
				}
				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( $code < 300 || $code >= 400 ) {
					return $resp;
				}
				$next = (string) wp_remote_retrieve_header( $resp, 'location' );
				if ( '' === $next ) {
					return $resp;
				}
				$url = '/' === $next[0] ? home_url( $next ) : $next;
			}
			return new WP_Error( 'uich_agent_redirects', 'Too many redirects.' );
		}

		/**
		 * Cloudflare's published edge ranges (cloudflare.com/ips-v4 and /ips-v6,
		 * fetched 2026-11-03). Extend or replace with the
		 * uichemy_agent_ready_cloudflare_ranges filter.
		 */
		const CLOUDFLARE = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
			'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
			'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
			'2a06:98c0::/29', '2c0f:f248::/32',
		);

		/**
		 * The visitor's IP, honouring a trusted proxy only when configured.
		 *
		 * - cloudflare: CF-Connecting-IP, only when the request really came from a
		 *   Cloudflare address.
		 * - x-forwarded-for: the right-most address, the one the site's own proxy
		 *   appended (addresses to its left are whatever the client sent).
		 *
		 * @param string $proxy '', 'cloudflare' or 'x-forwarded-for'.
		 * @return string
		 */
		public static function client_ip( $proxy = '' ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			$ip     = $remote;
			if ( 'cloudflare' === $proxy && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, (array) apply_filters( 'uichemy_agent_ready_cloudflare_ranges', self::CLOUDFLARE ) ) ) {
				$ip = (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			} elseif ( 'x-forwarded-for' === $proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$list = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
				$ip   = $list ? (string) end( $list ) : $remote;
			}
			return filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ? trim( $ip ) : '0.0.0.0';
		}

		/**
		 * Whether an IP falls in any of the CIDR ranges (IPv4 or IPv6).
		 *
		 * @param string   $ip     Address.
		 * @param string[] $ranges CIDR ranges.
		 * @return bool
		 */
		public static function in_ranges( $ip, array $ranges ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
			if ( false === $bin ) {
				return false;
			}
			foreach ( $ranges as $range ) {
				$parts = explode( '/', (string) $range, 2 );
				$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
				if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
					continue;
				}
				$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
				$bytes = intdiv( $bits, 8 );
				if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
					continue;
				}
				$rest = $bits % 8;
				if ( 0 === $rest || ( ( ord( $bin[ $bytes ] ) ^ ord( $net[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
					return true;
				}
			}
			return false;
		}
	}
}
