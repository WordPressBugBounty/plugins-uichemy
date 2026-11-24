<?php
/**
 * Gmail XOAUTH2 for PHPMailer, without an OAuth library.
 *
 * WordPress ships PHPMailer's OAuthTokenProvider interface. This implements it
 * with one call to Google's token endpoint (refresh_token -> access_token),
 * cached until shortly before it expires. Loaded only from phpmailer_init, when
 * PHPMailer and the interface are already in memory.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'PHPMailer\\PHPMailer\\OAuthTokenProvider' ) && is_readable( ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php' ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
}

if ( interface_exists( 'PHPMailer\\PHPMailer\\OAuthTokenProvider' ) && ! class_exists( 'Uich_SMTP_Gmail_Token' ) ) {

	/**
	 * Supplies the XOAUTH2 string for one Gmail account.
	 */
	class Uich_SMTP_Gmail_Token implements \PHPMailer\PHPMailer\OAuthTokenProvider {

		/** Transient caching the current access token. */
		const CACHE = 'uichemy_smtp_gmail_access';

		/** @var string */
		private $email;

		/**
		 * @param string $email Gmail address the token belongs to.
		 */
		public function __construct( $email ) {
			$this->email = (string) $email;
		}

		/**
		 * The base64 XOAUTH2 string PHPMailer sends with AUTH XOAUTH2.
		 *
		 * @return string
		 */
		public function getOauth64() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- interface method name.
			$token = self::access_token();
			return base64_encode( 'user=' . $this->email . "\001auth=Bearer " . $token . "\001\001" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SASL XOAUTH2 wire format.
		}

		/**
		 * A valid access token, refreshed from the stored refresh token when needed.
		 *
		 * @return string '' when Google refuses (PHPMailer then reports the auth failure).
		 */
		public static function access_token() {
			$s = Uich_SMTP::settings();
			// A cached token counts only for the account it was issued to.
			$plain = Uich_SMTP::cached_gmail_access( $s['gmail'] );
			if ( '' !== $plain ) {
				return $plain;
			}

			$refresh = Uich_SMTP_Secret::decrypt( $s['gmail']['refresh_token'] );
			$secret  = Uich_SMTP_Secret::decrypt( $s['gmail']['client_secret'] );
			if ( '' === $refresh || '' === $s['gmail']['client_id'] || '' === $secret ) {
				return '';
			}

			$res = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 15,
					'body'    => array(
						'client_id'     => $s['gmail']['client_id'],
						'client_secret' => $secret,
						'refresh_token' => $refresh,
						'grant_type'    => 'refresh_token',
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				return '';
			}
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( empty( $body['access_token'] ) ) {
				return '';
			}
			$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3000;
			Uich_SMTP::cache_gmail_access( (string) $body['access_token'], $ttl, $s['gmail'] );
			return (string) $body['access_token'];
		}
	}
}
