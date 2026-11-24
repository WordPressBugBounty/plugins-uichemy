<?php
/**
 * Signature check for the UiChemy Pro package the dashboard installs.
 *
 * A Pro update is installed only when the zip was signed with the UiChemy
 * release key. The release owner signs `uichemy-pro|<version>|<sha256>` with an
 * Ed25519 key held offline (scripts/sign-release.mjs), the app publishes the
 * result in /api/plugins/versions (package_sha256, package_signature,
 * package_key_id, package_size), and this class checks it against the public
 * keys below before WordPress unpacks anything. A hijacked app server, storage
 * bucket or DNS can then serve a zip, but cannot make one that installs.
 *
 * This is the free plugin's copy of UiChemy Pro's Uich_ND_Package_Verifier,
 * used by the "Install / Update UiChemy Pro" button (Uich_ND_Installer), which
 * runs before Pro exists on the site. Keep the two key lists identical.
 *
 * Ed25519 comes from libsodium, or from WordPress' bundled sodium_compat
 * (always loaded by wp-includes/compat.php), so there is no PHP extension
 * requirement.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Package_Verifier' ) ) {

	final class Uich_ND_Package_Verifier {

		/** First part of the signed message. */
		const PLUGIN = 'uichemy-pro';

		/**
		 * Storage host the app's download route redirects to. Downloads may only
		 * touch the app host and this host, always over https.
		 */
		const DOWNLOAD_HOST = 'storage.uichemy.com';

		/** The app redirects once; leave room for one more hop, no further. */
		const MAX_REDIRECTS = 2;

		/** Hard cap on a downloaded package (the app accepts zips up to 50 MB). */
		const MAX_BYTES = 52428800;

		/**
		 * Release public keys, by key id. k1 signs releases; k2 is the offline
		 * backup, shipped now so the key can be rotated without breaking updates.
		 *
		 * @return array<string,string> key id => base64 Ed25519 public key.
		 */
		public static function public_keys() {
			$keys = array(
				'k1' => '12Fi0e4Nkg5mUpsjrQT9zVa02S9RiPp90FK6hNgFlLw=',
				'k2' => 'jPcEZk5qnqwvppJNp7oD2HN5qcndVvo1nD4n2y7PNOU=',
			);

			/**
			 * Extra public keys, for a staging app that signs with its own key.
			 * Only adds keys: the release keys above always stay trusted.
			 *
			 * @param array $extra key id => base64 public key.
			 */
			$extra = apply_filters( 'uich_pro_update_public_keys', array() );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $id => $key ) {
					if ( is_string( $id ) && is_string( $key ) && ! isset( $keys[ $id ] ) ) {
						$keys[ $id ] = $key;
					}
				}
			}
			return $keys;
		}

		/**
		 * Read the signed package fields from the app's `uichemy_pro` entry.
		 *
		 * @param array $pro The `data.uichemy_pro` object of /api/plugins/versions.
		 * @return array|null { version, sha256, signature, key_id, size } or null
		 *                    when the release is unsigned or the fields are malformed.
		 */
		public static function meta_from_api( $pro ) {
			if ( ! is_array( $pro ) ) {
				return null;
			}
			$meta = array(
				'version'   => isset( $pro['latest_version'] ) && is_string( $pro['latest_version'] ) ? $pro['latest_version'] : '',
				'sha256'    => isset( $pro['package_sha256'] ) && is_string( $pro['package_sha256'] ) ? strtolower( $pro['package_sha256'] ) : '',
				'signature' => isset( $pro['package_signature'] ) && is_string( $pro['package_signature'] ) ? $pro['package_signature'] : '',
				'key_id'    => isset( $pro['package_key_id'] ) && is_string( $pro['package_key_id'] ) ? $pro['package_key_id'] : '',
				'size'      => isset( $pro['package_size'] ) && is_numeric( $pro['package_size'] ) ? (int) $pro['package_size'] : 0,
			);
			return self::well_formed( $meta ) ? $meta : null;
		}

		/**
		 * @param mixed $meta
		 * @return bool
		 */
		private static function well_formed( $meta ) {
			return is_array( $meta )
				&& isset( $meta['version'], $meta['sha256'], $meta['signature'], $meta['key_id'] )
				&& preg_match( '/^\d{1,4}(?:\.\d{1,4}){1,3}$/', (string) $meta['version'] )
				&& preg_match( '/^[0-9a-f]{64}$/', (string) $meta['sha256'] )
				&& '' !== (string) $meta['signature']
				&& '' !== (string) $meta['key_id'];
		}

		/**
		 * Is the release metadata signed by one of our keys?
		 *
		 * @param array|null $meta From meta_from_api().
		 * @return true|WP_Error
		 */
		public static function verify_meta( $meta ) {
			if ( ! self::well_formed( $meta ) ) {
				return new WP_Error( 'uichemy_pro_unsigned', __( 'This UiChemy Pro package is not signed, so it was not installed.', 'uichemy' ) );
			}
			$keys = self::public_keys();
			if ( empty( $keys[ $meta['key_id'] ] ) ) {
				return new WP_Error( 'uichemy_pro_unknown_key', __( 'This UiChemy Pro package is signed with an unknown key, so it was not installed.', 'uichemy' ) );
			}
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return new WP_Error( 'uichemy_pro_no_sodium', __( 'This site cannot check the UiChemy Pro update signature, so it was not installed.', 'uichemy' ) );
			}

			$public    = base64_decode( $keys[ $meta['key_id'] ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$signature = base64_decode( (string) $meta['signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$message   = self::PLUGIN . '|' . $meta['version'] . '|' . $meta['sha256'];

			$valid = false;
			if ( is_string( $public ) && 32 === strlen( $public ) && is_string( $signature ) && 64 === strlen( $signature ) ) {
				try {
					$valid = sodium_crypto_sign_verify_detached( $signature, $message, $public );
				} catch ( \Throwable $e ) {
					$valid = false;
				}
			}
			if ( ! $valid ) {
				return new WP_Error( 'uichemy_pro_bad_signature', __( 'The UiChemy Pro package signature is not valid, so it was not installed.', 'uichemy' ) );
			}
			return true;
		}

		/**
		 * Download the package and return it as a temp file only if its bytes
		 * are the signed ones. Checks the signature first, so an unsigned release
		 * is refused without downloading anything.
		 *
		 * @param string $url      The app's download route.
		 * @param array  $meta     From meta_from_api().
		 * @param string $app_url  App base URL; its host is allowed alongside DOWNLOAD_HOST.
		 * @return string|WP_Error Temp file path (the caller deletes it).
		 */
		public static function download( $url, $meta, $app_url ) {
			$signed = self::verify_meta( $meta );
			if ( is_wp_error( $signed ) ) {
				return $signed;
			}

			$body = self::fetch( $url, $app_url );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			if ( ! empty( $meta['size'] ) && strlen( $body ) !== (int) $meta['size'] ) {
				return new WP_Error( 'uichemy_pro_size_mismatch', __( 'The downloaded UiChemy Pro package is not the signed release (size differs), so it was not installed.', 'uichemy' ) );
			}
			if ( ! hash_equals( (string) $meta['sha256'], hash( 'sha256', $body ) ) ) {
				return new WP_Error( 'uichemy_pro_hash_mismatch', __( 'The downloaded UiChemy Pro package is not the signed release (checksum differs), so it was not installed.', 'uichemy' ) );
			}

			// wp_tempnam() lives in wp-admin/includes/file.php, which WordPress does not
			// load for REST requests, so on a fresh site the call would be undefined.
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp = wp_tempnam( 'uichemy-pro-' );
			if ( ! $tmp ) {
				return new WP_Error( 'uichemy_pro_tmp_failed', __( 'Could not create a temporary file for the UiChemy Pro download.', 'uichemy' ) );
			}
			if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				wp_delete_file( $tmp );
				return new WP_Error( 'uichemy_pro_write_failed', __( 'Could not write the UiChemy Pro download.', 'uichemy' ) );
			}
			return $tmp;
		}

		/**
		 * Fetch into memory, following at most MAX_REDIRECTS redirects by hand so
		 * every hop is checked: https only, and only the app host or the storage
		 * host. A local dev app (UICH_WEBPAGE_APP_URL on http/localhost) keeps its
		 * own origin allowed, since that is a constant the site owner set.
		 *
		 * @param string $url
		 * @param string $app_url
		 * @return string|WP_Error Response body.
		 */
		private static function fetch( $url, $app_url ) {
			$app_host = strtolower( (string) wp_parse_url( $app_url, PHP_URL_HOST ) );
			$app_safe = 'https' === strtolower( (string) wp_parse_url( $app_url, PHP_URL_SCHEME ) );

			for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$is_app = '' !== $app_host && $host === $app_host;
				if ( ! $is_app && self::DOWNLOAD_HOST !== $host ) {
					return new WP_Error( 'uichemy_pro_bad_host', __( 'The UiChemy Pro download pointed to a host that is not allowed, so it was stopped.', 'uichemy' ) );
				}
				if ( 'https' !== $scheme && ! ( $is_app && ! $app_safe ) ) {
					return new WP_Error( 'uichemy_pro_not_https', __( 'The UiChemy Pro download did not use https, so it was stopped.', 'uichemy' ) );
				}

				$args     = array(
					'timeout'             => 300,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
				);
				$response = wp_http_validate_url( $url ) ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 300 && $code < 400 ) {
					$location = (string) wp_remote_retrieve_header( $response, 'location' );
					if ( '' === $location ) {
						break;
					}
					$url = WP_Http::make_absolute_url( $location, $url );
					continue;
				}
				if ( $code < 200 || $code >= 300 ) {
					return new WP_Error(
						'uichemy_pro_download_failed',
						/* translators: %d is the HTTP status code returned by the download request. */
						sprintf( __( 'UiChemy Pro download failed (HTTP %d).', 'uichemy' ), $code )
					);
				}

				$body = wp_remote_retrieve_body( $response );
				if ( '' === $body ) {
					return new WP_Error( 'uichemy_pro_download_empty', __( 'UiChemy Pro download returned an empty package.', 'uichemy' ) );
				}
				return $body;
			}

			return new WP_Error( 'uichemy_pro_too_many_redirects', __( 'The UiChemy Pro download redirected too many times, so it was stopped.', 'uichemy' ) );
		}
	}
}
