<?php
/**
 * UiChemy SSO authentication for the new dashboard.
 *
 * Single-sign-on against app.uichemy.com. The flow:
 *
 *   1. React Login screen sends the admin to
 *      https://app.uichemy.com/sso?site_url=<this site>&app_name=UiChemy+WP+Plugin
 *   2. After the user authenticates there, UiChemy redirects the browser
 *      back to this site's callback:
 *      <site>/wp-admin/admin-ajax.php?action=uichemy_auth&token=TOKEN
 *   3. handle_callback() stores TOKEN in the `uich_nd_sso_token` option and
 *      bounces back to the dashboard. The token is what we later verify
 *      against UiChemy when calling its API.
 *
 * The callback runs through admin-ajax's logged-in `wp_ajax_` hook and is
 * additionally gated on `manage_options`, so only an authenticated admin
 * — the same person who started the flow from wp-admin — can write the
 * token.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Auth' ) ) {

	final class Uich_ND_Auth {

		const OPT_TOKEN        = 'uich_nd_sso_token';
		const OPT_TOKEN_AT     = 'uich_nd_sso_token_at';
		const OPT_LICENSE_DATA = 'uich_nd_license_data';
		const OPT_SESSION_DATA = 'uich_nd_license_session';

		const AJAX_AUTH    = 'uichemy_auth';        // SSO redirect-back target.
		const AJAX_LOGOUT  = 'uich_nd_sso_logout';  // Clear stored token.
		const NONCE_LOGOUT = 'uich_nd_sso_logout';
		const AJAX_START   = 'uich_nd_sso_start';   // Begin SSO flow (CSRF guard).
		const NONCE_START  = 'uich_nd_sso_start';

		// const SSO_BASE = 'http://localhost:5191/sso';
		const SSO_BASE = 'https://app.uichemy.com/sso';
		const APP_NAME = 'UiChemy WP Plugin';

		// const API_BASE = 'http://localhost:8000';
		const API_BASE = 'https://core.uichemy.com';

		public static function boot() {
			add_action( 'wp_ajax_' . self::AJAX_AUTH, array( __CLASS__, 'handle_callback' ) );
			add_action( 'wp_ajax_' . self::AJAX_LOGOUT, array( __CLASS__, 'ajax_logout' ) );
			add_action( 'wp_ajax_' . self::AJAX_START, array( __CLASS__, 'ajax_start' ) );
		}

		/* -------------------------------------------------------------
		   Pending-flow marker + state nonce (CSRF / session-fixation guard)

		   ajax_start() mints a single-use, per-user `state` nonce that travels
		   to UiChemy in the SSO URL; the callback is only accepted when that
		   exact nonce is echoed back, binding the token to this flow.

		   The marker is stored in USER META, not a transient. On a site with a
		   persistent object cache (Redis/Memcached), transients are written ONLY
		   to that cache and never to the DB — so a broken or non-persistent
		   object cache silently drops the marker between the ajax_start request
		   and the callback request, and every login fails with uich_sso=expired.
		   User meta always hits the DB (a cache miss falls back to a DB read), so
		   the marker survives regardless of object-cache health. Because there's
		   no TTL on user meta, we carry an explicit `expires` timestamp in the
		   value and enforce it ourselves in consume_pending().
		   ------------------------------------------------------------- */

		const META_PENDING = 'uich_nd_sso_pending';
		const PENDING_TTL   = 15 * MINUTE_IN_SECONDS;

		/** Start an SSO flow; returns the single-use state nonce to echo back. */
		public static function set_pending() {
			$state = wp_generate_password( 40, false );
			update_user_meta( get_current_user_id(), self::META_PENDING, array(
				'state'   => $state,
				'expires' => time() + self::PENDING_TTL,
			) );
			return $state;
		}

		/**
		 * Consume the pending marker. Returns true exactly once after a
		 * set_pending() whose state nonce matches the value echoed back and
		 * hasn't expired. Single-use: the marker is deleted on every read,
		 * matching or not.
		 *
		 * @param string $state The `state` value returned on the callback.
		 */
		public static function consume_pending( $state ) {
			$uid  = get_current_user_id();
			$data = get_user_meta( $uid, self::META_PENDING, true );
			delete_user_meta( $uid, self::META_PENDING );

			if ( ! is_array( $data ) || empty( $data['state'] ) || empty( $data['expires'] ) ) {
				return false;
			}
			if ( time() > (int) $data['expires'] ) {
				return false; // Expired — same outcome the transient TTL gave us.
			}
			return hash_equals( (string) $data['state'], (string) $state );
		}

		/* -------------------------------------------------------------
		   Token store
		   ------------------------------------------------------------- */

		public static function get_token() {
			return (string) get_option( self::OPT_TOKEN, '' );
		}

		public static function set_token( $token ) {
			$token = sanitize_text_field( (string) $token );
			if ( '' === $token ) {
				return false;
			}
			update_option( self::OPT_TOKEN, $token, false );
			update_option( self::OPT_TOKEN_AT, time(), false );
			return true;
		}

		public static function clear_token() {
			delete_option( self::OPT_TOKEN );
			delete_option( self::OPT_TOKEN_AT );
			delete_option( self::OPT_LICENSE_DATA );
			delete_option( self::OPT_SESSION_DATA );
		}

		/* -------------------------------------------------------------
		   License data (fetched from UiChemy API after token is stored)
		   ------------------------------------------------------------- */

		/**
		 * Shared helper: make an authorised request to the UiChemy API.
		 *
		 * @param string $method        'GET' or 'POST'.
		 * @param string $path          e.g. '/licenses/user'.
		 * @param string $token         Bearer token.
		 * @param array  $body          JSON body for POST requests.
		 * @param array  $extra_headers Extra request headers (e.g. X-License-Id).
		 * @return array|WP_Error  Decoded response array, or WP_Error on failure.
		 */
		private static function api_request( $method, $path, $token, $body = null, $extra_headers = array() ) {
			$url  = rtrim( self::API_BASE, '/' ) . '/' . ltrim( $path, '/' );
			$args = array(
				'method'  => strtoupper( $method ),
				'headers' => array_merge(
					array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
					),
					is_array( $extra_headers ) ? $extra_headers : array()
				),
				'timeout' => 15,
			);

			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 ) {
				$message = isset( $decoded['error'] ) ? $decoded['error'] : "API error ({$code})";
				// Carry the HTTP status in the error data so callers can react to
				// it (e.g. a 401 means the token is no longer valid).
				return new WP_Error(
					isset( $decoded['error_code'] ) ? $decoded['error_code'] : 'api_error',
					$message,
					array( 'status' => $code )
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Fetch GET /licenses/user, cache the result, and return it.
		 * Returns null on any failure.
		 *
		 * @param string $token  Bearer token.
		 * @return array|null    { user, licenses } payload, or null.
		 */
		public static function fetch_and_store_license_data( $token ) {
			$result = self::api_request( 'GET', '/licenses/user', $token );

			if ( is_wp_error( $result ) ) {
				return null;
			}

			update_option( self::OPT_LICENSE_DATA, $result, false );
			return $result;
		}

		/**
		 * GET /licenses/user against the UiChemy API with the stored token, used
		 * to validate the session on every dashboard visit. Like
		 * fetch_and_store_license_data, but it returns the WP_Error (whose data
		 * carries the HTTP `status`) instead of null, so the caller can react to
		 * a 401. Refreshes the cached license data on success.
		 *
		 * @param string $token  Bearer token.
		 * @return array|WP_Error
		 */
		public static function fetch_license_user( $token ) {
			$result = self::api_request( 'GET', '/licenses/user', $token );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			update_option( self::OPT_LICENSE_DATA, $result, false );
			return $result;
		}

		/**
		 * Pick the best license from a licenses array.
		 * Prefers the first active license; falls back to the first license of any status.
		 *
		 * @param array $licenses  Array of license objects from the API.
		 * @return array|null      Single license array, or null if list is empty.
		 */
		public static function auto_select_license( $licenses ) {
			if ( empty( $licenses ) || ! is_array( $licenses ) ) {
				return null;
			}

			foreach ( $licenses as $license ) {
				if ( isset( $license['status'] ) && 'active' === $license['status'] ) {
					return $license;
				}
			}

			return $licenses[0]; // fallback to first if none active
		}

		/**
		 * POST /licenses/register-session to bind this WP site to a license.
		 * Stores the returned session data on success.
		 *
		 * @param string $token      Bearer token.
		 * @param string $licenseId  The license _id to register.
		 * @return array|WP_Error    Session data array, or WP_Error on failure.
		 */
		public static function register_license_session( $token, $licenseId ) {
			$result = self::api_request(
				'POST',
				'/licenses/register-session',
				$token,
				array(
					'licenseId' => $licenseId,
					'plugin'    => 'wp',
					// Bind the session to the site's address rather than its
					// display name, so it's unambiguous which site a session
					// belongs to.
					'name'      => site_url(),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// The API wraps the payload in a `data` key.
			$session = isset( $result['data'] ) ? $result['data'] : $result;
			update_option( self::OPT_SESSION_DATA, $session, false );
			return $session;
		}

		/**
		 * POST /licenses/change-license-activation to move this site's session
		 * onto a different license. Sends the current license id in the
		 * `X-License-Id` header (server middleware requires the active session)
		 * and the target license in the body. Refreshes the cached session.
		 *
		 * @param string $token          Bearer token.
		 * @param string $new_license_id  The license _id to switch to.
		 * @return array|WP_Error         New session data, or WP_Error on failure.
		 */
		public static function change_license_activation( $token, $new_license_id ) {
			$session    = self::get_session_data();
			$current_id = is_array( $session ) && ! empty( $session['licenseId'] ) ? (string) $session['licenseId'] : '';

			$result = self::api_request(
				'POST',
				'/licenses/change-license-activation',
				$token,
				array( 'newLicenseId' => $new_license_id ),
				array( 'X-License-Id' => $current_id )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Build the new session to persist in the DB. Prefer the payload the
			// API returns (wrapped in `data`); fall back to the previous session
			// if the change endpoint doesn't echo one back. Either way, force the
			// licenseId to the one we just activated so everything that reads the
			// session (logout's X-License-Id, the next switch, etc.) stays correct.
			$payload     = isset( $result['data'] ) ? $result['data'] : $result;
			$new_session = ( is_array( $payload ) && ! empty( $payload ) )
				? $payload
				: ( is_array( $session ) ? $session : array() );
			$new_session['licenseId'] = $new_license_id;

			update_option( self::OPT_SESSION_DATA, $new_session, false );

			// Refresh the cached license data too, so the UI reflects the newly
			// activated license (the picker/active flags read this) after reload.
			// Best-effort: a stale snapshot shouldn't fail the switch itself.
			self::fetch_license_user( $token );

			return $new_session;
		}

		/** Return the cached license data, or null if none stored. */
		public static function get_license_data() {
			$data = get_option( self::OPT_LICENSE_DATA, null );
			return is_array( $data ) ? $data : null;
		}

		/** Return the cached session data, or null if none stored. */
		public static function get_session_data() {
			$data = get_option( self::OPT_SESSION_DATA, null );
			return is_array( $data ) ? $data : null;
		}

		public static function is_authed() {
			return '' !== self::get_token();
		}

		/* -------------------------------------------------------------
		   URLs
		   ------------------------------------------------------------- */

		/**
		 * The UiChemy SSO entry point the Login button sends the admin to.
		 * UiChemy reads `site_url` to know where to redirect the token
		 * back to (it appends /wp-admin/admin-ajax.php?action=uichemy_auth).
		 */
		public static function get_sso_url( $state = '' ) {
			$args = array(
				'site_url' => site_url(),
				'app_name' => self::APP_NAME,
			);
			if ( '' !== $state ) {
				$args['state'] = $state;
			}
			return add_query_arg( $args, self::SSO_BASE );
		}

		/** Where UiChemy hands the token back to. */
		public static function get_callback_url() {
			return add_query_arg( 'action', self::AJAX_AUTH, admin_url( 'admin-ajax.php' ) );
		}

		/** Bootstrap snapshot for the React side. */
		public static function get_boot_state() {
			return array(
				'isAuthed'    => self::is_authed(),
				'ssoUrl'      => self::get_sso_url(),
				'callbackUrl' => self::get_callback_url(),
				'logoutNonce' => wp_create_nonce( self::NONCE_LOGOUT ),
				'startNonce'  => wp_create_nonce( self::NONCE_START ),
				'licenseData' => self::get_license_data(),
				'sessionData' => self::get_session_data(),
			);
		}

		/* -------------------------------------------------------------
		   AJAX handlers
		   ------------------------------------------------------------- */

		/**
		 * SSO redirect-back target. UiChemy sends the browser here with
		 * ?action=uichemy_auth&token=TOKEN. We store the token and bounce
		 * back to the dashboard with a status flag the React side reads to
		 * show a toast.
		 */
		public static function handle_callback() {
			$dashboard = admin_url( 'admin.php?page=uichemy' );

			// Only the logged-in admin who started the flow may set the token.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( add_query_arg( 'uich_sso', 'forbidden', $dashboard ) );
				exit;
			}

			// CSRF / session-fixation guard: only accept a token when this
			// admin actually initiated the flow from this site (single-use
			// pending marker set by ajax_start()) and UiChemy echoed back the
			// matching state nonce.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			if ( ! self::consume_pending( $state ) ) {
				wp_safe_redirect( add_query_arg( 'uich_sso', 'expired', $dashboard ) );
				exit;
			}

			$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			if ( '' === $token ) {
				wp_safe_redirect( add_query_arg( 'uich_sso', 'error', $dashboard ) );
				exit;
			}

			self::set_token( $token );

			// Fetch licenses and auto-select one.
			$license_data = self::fetch_and_store_license_data( $token );
			$licenses     = isset( $license_data['data']['licenses'] ) ? $license_data['data']['licenses']
			              : ( isset( $license_data['licenses'] ) ? $license_data['licenses'] : array() );
			$license      = self::auto_select_license( $licenses );

			if ( null === $license ) {
				self::clear_token();
				wp_safe_redirect( add_query_arg( 'uich_sso', 'no_license', $dashboard ) );
				exit;
			}

			$license_id = isset( $license['_id'] ) ? $license['_id'] : ( isset( $license['id'] ) ? $license['id'] : '' );
			if ( '' === $license_id ) {
				self::clear_token();
				wp_safe_redirect( add_query_arg( 'uich_sso', 'no_license', $dashboard ) );
				exit;
			}

			// Register this WP site as a session for the selected license.
			$session = self::register_license_session( $token, $license_id );

			if ( is_wp_error( $session ) ) {
				if ( 'license_session_limit_reached' === $session->get_error_code() ) {
					// Keep the token — React will show the license picker so the
					// user can choose a different license or revoke an old session.
					wp_safe_redirect( add_query_arg( 'uich_sso', 'session_limit', $dashboard ) );
					exit;
				}

				self::clear_token();
				wp_safe_redirect( add_query_arg(
					array(
						'uich_sso'     => 'session_error',
						'uich_sso_msg' => rawurlencode( $session->get_error_message() ),
					),
					$dashboard
				) );
				exit;
			}

			wp_safe_redirect( add_query_arg( 'uich_sso', 'success', $dashboard ) );
			exit;
		}

		/** Disconnect — calls remove-session on the API, then clears local state. */
		public static function ajax_logout() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'Insufficient permissions.', 'uichemy' ) ), 403 );
			}
			check_ajax_referer( self::NONCE_LOGOUT, 'nonce' );

			$token   = self::get_token();
			$session = self::get_session_data();

			// Best-effort: call the API to revoke the license session.
			// We clear local state regardless of whether the API call succeeds.
			if ( '' !== $token && ! empty( $session['licenseId'] ) ) {
				$url = rtrim( self::API_BASE, '/' ) . '/licenses/remove-session';
				wp_remote_request( $url, array(
					'method'  => 'DELETE',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'X-License-Id'  => $session['licenseId'],
						'Accept'        => 'application/json',
					),
					'timeout' => 10,
				) );
			}

			self::clear_token();
			wp_send_json_success( array( 'isAuthed' => false ) );
		}

		/**
		 * Begin an SSO flow. Drops the single-use pending marker that
		 * handle_callback() requires (CSRF / session-fixation guard) and
		 * returns the SSO URL the browser should redirect to.
		 */
		public static function ajax_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'Insufficient permissions.', 'uichemy' ) ), 403 );
			}
			check_ajax_referer( self::NONCE_START, 'nonce' );

			$state = self::set_pending();
			wp_send_json_success( array( 'ssoUrl' => self::get_sso_url( $state ) ) );
		}
	}
}
