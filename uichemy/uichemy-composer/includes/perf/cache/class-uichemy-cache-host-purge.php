<?php
/**
 * Host cache clearing (K09).
 *
 * When UiChemy clears pages, clear the same pages in the host's cache where
 * the host documents a PHP method for it (data/hosts.json "purge"). Today only
 * WP Engine has a grade A method. Every call is guarded, so a host without the
 * method never errors; those hosts show notice N08 instead.
 *
 * Runs in every mode, including "Let my host handle it": that is the point of
 * host mode.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Host_Purge' ) ) {

	class UiChemy_Cache_Host_Purge {

		/** Option: last host clear, for the status card. */
		const LAST_OPTION = 'uichemy_cache_host_purge';

		/** @var bool A purge header went out with this response. */
		private static $header_sent = false;

		/**
		 * Header-based purges (LiteSpeed) must ride on the response itself, so they
		 * are sent when the clear is queued, while headers can still be sent.
		 *
		 * @param string   $scope all | urls.
		 * @param string[] $urls  URLs when scope is urls.
		 * @return void
		 */
		public static function early( $scope, array $urls = array() ) {
			$host = self::purgeable_host();
			if ( ! $host || 'header' !== $host['purge']['method'] || headers_sent() ) {
				return;
			}
			if ( 'all' === $scope ) {
				header( $host['purge']['header'] . ': *' );
			} else {
				$paths = array();
				foreach ( $urls as $u ) {
					$path = wp_parse_url( $u, PHP_URL_PATH );
					if ( is_string( $path ) && '' !== $path ) {
						$paths[] = $path;
					}
				}
				if ( ! $paths ) {
					return;
				}
				header( $host['purge']['header'] . ': ' . implode( ', ', array_unique( $paths ) ), false );
			}
			self::$header_sent = true;
		}

		/**
		 * Hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'uichemy/cache/cleared', array( __CLASS__, 'purge' ), 10, 2 );
		}

		/**
		 * The detected host entry, if UiChemy can clear its cache.
		 *
		 * @return array|null
		 */
		public static function purgeable_host() {
			$detect = UiChemy_Cache_Host::detect();
			if ( empty( $detect['host'] ) || empty( $detect['host']['purge_supported'] ) ) {
				return null;
			}
			return UiChemy_Cache_Host::host( $detect['host']['id'] );
		}

		/**
		 * uichemy/cache/cleared.
		 *
		 * @param string   $scope all | urls.
		 * @param string[] $urls  URLs when scope is urls.
		 * @return bool Whether the host cache was cleared.
		 */
		public static function purge( $scope, $urls = array() ) {
			$host = self::purgeable_host();
			if ( ! $host ) {
				return false;
			}
			$purge = $host['purge'];
			$done  = false;
			try {
				if ( 'static_call' === $purge['method'] && class_exists( $purge['class'], false ) && is_callable( array( $purge['class'], $purge['function'] ) ) ) {
					if ( 'urls' === $scope && $urls ) {
						// WP Engine takes an optional post ID; without one it clears the site.
						$ids = array_filter( array_map( 'url_to_postid', (array) $urls ) );
						if ( $ids && count( $ids ) === count( $urls ) ) {
							foreach ( array_unique( $ids ) as $id ) {
								call_user_func( array( $purge['class'], $purge['function'] ), (int) $id );
							}
						} else {
							call_user_func( array( $purge['class'], $purge['function'] ) );
						}
					} else {
						call_user_func( array( $purge['class'], $purge['function'] ) );
					}
					$done = true;
				} elseif ( 'function' === $purge['method'] && ! empty( $purge['function'] ) && function_exists( $purge['function'] ) ) {
					call_user_func( $purge['function'] );
					$done = true;
				} elseif ( 'action' === $purge['method'] && ! empty( $purge['action'] ) && has_action( $purge['action'] ) ) {
					do_action( $purge['action'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the host's own documented hook.
					$done = true;
				} elseif ( 'header' === $purge['method'] ) {
					$done = self::$header_sent;
				}
			} catch ( \Throwable $e ) {
				$done = false;
			}
			update_option(
				self::LAST_OPTION,
				array(
					'host' => $host['name'],
					'ok' => $done,
					't' => time(),
				),
				false
			);
			return $done;
		}
	}
}
