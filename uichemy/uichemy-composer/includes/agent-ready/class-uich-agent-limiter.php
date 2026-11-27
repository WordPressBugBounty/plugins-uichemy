<?php
/**
 * Agent Ready rate limiter: fixed one-minute windows per bucket and client.
 *
 * Used before any work that costs PHP time: rendering an uncached agent file,
 * every MCP tool call, and every write. Cached files are cheap and get their
 * own, higher bucket. AI services fetch from a few shared addresses, so limits
 * are per bucket and generous enough not to cut off every user of one service;
 * the site-wide bucket protects the host whoever is calling.
 *
 * Counters live in the object cache when it is persistent, otherwise in
 * transients.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Limiter' ) ) {

	/**
	 * Rate limiter.
	 */
	class Uich_Agent_Limiter {

		/**
		 * Count one hit and say whether it is allowed.
		 *
		 * @param string $bucket Bucket name (cached, uncached, write, site).
		 * @param string $client Client key (IP), '' for site-wide.
		 * @param int    $limit  Hits allowed per period.
		 * @param string $period 'minute' (default) or 'hour'.
		 * @return bool
		 */
		public static function hit( $bucket, $client, $limit, $period = 'minute' ) {
			$limit  = max( 1, (int) $limit );
			$window = 'hour' === $period ? gmdate( 'YmdH' ) : gmdate( 'YmdHi' );
			// Keyed hash: counter file names never reveal the visitor's address.
			$id     = substr( hash_hmac( 'sha256', $bucket . '|' . $client, wp_salt( 'nonce' ) ), 0, 20 );

			// A persistent object cache increments atomically.
			if ( wp_using_ext_object_cache() ) {
				$key = 'uich_ag_rl_' . $id . '_' . $window;
				wp_cache_add( $key, 0, 'uich_agent', 'hour' === $period ? 3700 : 70 );
				return (int) wp_cache_incr( $key, 1, 'uich_agent' ) <= $limit;
			}

			// Otherwise one small file per client and bucket, read and written under
			// an exclusive lock, so parallel requests cannot all read the same count.
			$count = self::file_incr( $id, $window );
			if ( null === $count ) {
				// No writable cache folder: fall back to a transient (not atomic).
				$key   = 'uich_ag_rl_' . $id . '_' . $window;
				$count = (int) get_transient( $key ) + 1;
				set_transient( $key, $count, 'hour' === $period ? 3700 : 70 );
			}
			return $count <= $limit;
		}

		/**
		 * Increment a counter file for the current window under flock().
		 *
		 * @param string $id     Counter id.
		 * @param string $window Window label; a new window starts again at 1.
		 * @return int|null New count, or null when the file cannot be used.
		 */
		private static function file_incr( $id, $window ) {
			$root = Uich_Agent_Store::cache_root();
			if ( is_wp_error( $root ) ) {
				return null;
			}
			$dir = $root . 'rl/';
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions -- counter needs flock().
			$fh = @fopen( $dir . $id, 'c+' );
			if ( ! $fh ) {
				return null;
			}
			flock( $fh, LOCK_EX );
			$raw   = (string) stream_get_contents( $fh );
			$parts = explode( ':', $raw );
			$count = ( 2 === count( $parts ) && $parts[0] === $window ) ? (int) $parts[1] + 1 : 1;
			ftruncate( $fh, 0 );
			rewind( $fh );
			fwrite( $fh, $window . ':' . $count );
			fflush( $fh );
			flock( $fh, LOCK_UN );
			fclose( $fh );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			return $count;
		}

		/**
		 * Run a callback while holding a named exclusive lock (a check followed by
		 * a write, such as the inbox's per-visitor cap).
		 *
		 * @param string   $name Lock name.
		 * @param callable $fn   Callback.
		 * @return mixed The callback's result.
		 */
		public static function with_lock( $name, $fn ) {
			$root = Uich_Agent_Store::cache_root();
			$fh   = false;
			if ( ! is_wp_error( $root ) && ( is_dir( $root . 'rl/' ) || wp_mkdir_p( $root . 'rl/' ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lock file.
				$fh = @fopen( $root . 'rl/lock-' . sanitize_key( $name ), 'c' );
			}
			// Wait at most about two seconds for the lock, then refuse.
			if ( $fh ) {
				$got = false;
				for ( $i = 0; $i < 40 && ! $got; $i++ ) {
					$got = flock( $fh, LOCK_EX | LOCK_NB );
					if ( ! $got ) {
						usleep( 50000 );
					}
				}
				if ( ! $got ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lock file.
					return new WP_Error( 'uich_agent_busy', 'The site is busy. Please try again in a moment.' );
				}
			}
			try {
				return call_user_func( $fn );
			} finally {
				if ( $fh ) {
					flock( $fh, LOCK_UN );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lock file.
				}
			}
		}

		/**
		 * Delete counter files not touched for an hour.
		 *
		 * @return void
		 */
		public static function prune() {
			$root = Uich_Agent_Store::cache_root();
			if ( is_wp_error( $root ) || ! is_dir( $root . 'rl/' ) ) {
				return;
			}
			foreach ( (array) glob( $root . 'rl/*' ) as $file ) {
				if ( is_file( $file ) && 0 !== strpos( basename( $file ), 'lock-' ) && filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
					wp_delete_file( $file );
				}
			}
		}

		/**
		 * Check the client bucket and the site-wide bucket together.
		 *
		 * @param string $bucket Client bucket.
		 * @return bool
		 */
		public static function allow( $bucket ) {
			// The site's own loopback (get_page, the audit) was already counted on the
			// visitor's call that caused it; counting it again would make every visitor
			// share one bucket keyed to the server's IP.
			if ( Uich_Agent_Urls::is_loopback() ) {
				return true;
			}
			$rate = Uich_Agent_Settings::value( 'mcp.rate', array() );
			$ip   = Uich_Agent_Urls::client_ip( (string) Uich_Agent_Settings::value( 'mcp.trusted_proxy', '' ) );
			$per  = isset( $rate[ $bucket ] ) ? (int) $rate[ $bucket ] : 30;

			/**
			 * Filter one client's per-minute limit for a bucket.
			 *
			 * @param int    $per    Limit.
			 * @param string $bucket Bucket.
			 * @param string $ip     Client IP.
			 */
			$per = (int) apply_filters( 'uichemy_agent_ready_rate_limit', $per, $bucket, $ip );

			if ( ! self::hit( $bucket, $ip, $per ) ) {
				return false;
			}
			return self::hit( 'site', '', isset( $rate['site'] ) ? (int) $rate['site'] : 600 );
		}

		/**
		 * Send a 429 with Retry-After and stop.
		 *
		 * @param string $type 'markdown' or 'json'.
		 * @return void
		 */
		public static function refuse( $type = 'markdown' ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			header( 'Cache-Control: no-store' );
			if ( 'json' === $type ) {
				header( 'Content-Type: application/json; charset=utf-8' );
				echo wp_json_encode( array( 'error' => 'Too many requests. Try again in a minute.' ) );
			} else {
				header( 'Content-Type: text/markdown; charset=utf-8' );
				echo "# Too many requests\n\nTry again in a minute.\n";
			}
			exit;
		}
	}
}
