<?php
/**
 * At-rest protection for the SMTP password and the Gmail OAuth secrets.
 *
 * AES-256-GCM with a key generated once per site and kept in its own option.
 * Not derived from the wp-config salts on purpose: those differ between staging
 * and live, and a restored backup would then lose every stored credential.
 * UICHEMY_SECRET_KEY in wp-config.php overrides the stored key.
 *
 * A value without the prefix is cleartext (or empty) and is returned as is.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SMTP_Secret' ) ) {

	/**
	 * Encrypt / decrypt short secrets.
	 */
	class Uich_SMTP_Secret {

		/** Envelope marker. */
		const PREFIX = 'uichenc1:';

		/** Option holding this site's key (64 hex characters). */
		const KEY_OPTION = 'uichemy_secret_key';

		/**
		 * Encrypt a secret. Returns '' for an empty value.
		 *
		 * @param string $plain Cleartext.
		 * @return string
		 */
		public static function encrypt( $plain ) {
			$plain = (string) $plain;
			if ( '' === $plain || self::is_encrypted( $plain ) || ! self::available() ) {
				return $plain;
			}
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return $plain;
			}
			return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
		}

		/**
		 * Decrypt a stored secret. '' when it cannot be read.
		 *
		 * @param string $stored Stored value.
		 * @return string
		 */
		public static function decrypt( $stored ) {
			if ( ! is_string( $stored ) ) {
				return '';
			}
			if ( ! self::is_encrypted( $stored ) ) {
				return $stored;
			}
			if ( ! self::available() ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
			if ( false === $raw || strlen( $raw ) < 29 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? '' : $plain;
		}

		/**
		 * @param mixed $value Candidate.
		 * @return bool
		 */
		public static function is_encrypted( $value ) {
			return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
		}

		/** @return bool */
		private static function available() {
			return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' );
		}

		/** @return string 32-byte key. */
		private static function key() {
			if ( defined( 'UICHEMY_SECRET_KEY' ) && '' !== (string) UICHEMY_SECRET_KEY ) {
				$seed = (string) UICHEMY_SECRET_KEY;
			} else {
				$seed = get_option( self::KEY_OPTION );
				if ( ! is_string( $seed ) || 64 !== strlen( $seed ) ) {
					add_option( self::KEY_OPTION, bin2hex( random_bytes( 32 ) ), '', false );
					// Another request may have created it first; read back so both agree.
					$seed = (string) get_option( self::KEY_OPTION );
				}
			}
			return hash( 'sha256', 'uichemy-secret|' . $seed, true );
		}
	}
}
