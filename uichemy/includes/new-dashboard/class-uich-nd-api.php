<?php
/**
 * REST API for the new dashboard.
 *
 * Namespace: `uichemy/v2/nd`. All routes require `manage_options`.
 *
 *   GET  /env             — env_check() snapshot
 *   GET  /builders        — detect_builders() snapshot
 *   GET  /state           — full dashboard state (env + builders + mode + onboarded)
 *   POST /builder         — { builder } persists builder choice
 *   POST /mode            — { mode }    persists mode choice
 *   POST /onboarded       — { done }    marks wizard complete
 *
 * Phase 1: read routes implemented; write routes implemented with light
 * validation. Token / MCP routes land in Phase 2.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Api' ) ) {

	final class Uich_ND_Api {

		const NS = 'uichemy/v2/nd';

		public static function boot() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		public static function register_routes() {
			$auth = array( __CLASS__, 'permission_check' );

			register_rest_route( self::NS, '/env', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_env' ),
				'permission_callback' => $auth,
			) );

			register_rest_route( self::NS, '/builders', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_builders' ),
				'permission_callback' => $auth,
			) );

			register_rest_route( self::NS, '/state', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_state' ),
				'permission_callback' => $auth,
			) );

			register_rest_route( self::NS, '/builder', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_set_builder' ),
				'permission_callback' => $auth,
				'args'                => array(
					'builder' => array( 'required' => true, 'type' => 'string' ),
				),
			) );

			register_rest_route( self::NS, '/mode', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_set_mode' ),
				'permission_callback' => $auth,
				'args'                => array(
					'mode' => array( 'required' => true, 'type' => 'string' ),
				),
			) );

			register_rest_route( self::NS, '/onboarded', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_set_onboarded' ),
				'permission_callback' => $auth,
				'args'                => array(
					'done'    => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
					'consent' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
				),
			) );

			register_rest_route( self::NS, '/install', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_install' ),
				'permission_callback' => array( __CLASS__, 'permission_install' ),
				'args'                => array(
					'builder' => array( 'required' => true, 'type' => 'string' ),
				),
			) );

			register_rest_route( self::NS, '/protuno/install', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_protuno_install' ),
				'permission_callback' => array( __CLASS__, 'permission_protuno_install' ),
			) );

			register_rest_route( self::NS, '/register-session', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_register_session' ),
				'permission_callback' => $auth,
				'args'                => array(
					'licenseId' => array( 'required' => true, 'type' => 'string' ),
				),
			) );

			register_rest_route( self::NS, '/licenses/user', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_licenses_user' ),
				'permission_callback' => $auth,
			) );

			register_rest_route( self::NS, '/change-license', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_change_license' ),
				'permission_callback' => $auth,
				'args'                => array(
					'newLicenseId' => array( 'required' => true, 'type' => 'string' ),
				),
			) );
		}

		/**
		 * Installing Protuno needs install_plugins + activate_plugins.
		 */
		public static function permission_protuno_install() {
			return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		}

		/**
		 * Installer needs install_plugins / switch_themes — stricter than
		 * the rest of the REST surface.
		 */
		public static function permission_install() {
			return current_user_can( 'install_plugins' ) && current_user_can( 'switch_themes' );
		}

		public static function permission_check() {
			return current_user_can( 'manage_options' );
		}

		public static function route_env() {
			return rest_ensure_response( Uich_ND_Settings::env_check() );
		}

		public static function route_builders() {
			return rest_ensure_response( Uich_ND_Settings::detect_builders() );
		}

		public static function route_state( WP_REST_Request $req ) {
			return rest_ensure_response( array(
				'auth'        => Uich_ND_Auth::get_boot_state(),
				'env'         => Uich_ND_Settings::env_check(),
				'builders'    => Uich_ND_Settings::detect_builders(),
				'builder'     => Uich_ND_Settings::get_builder(),
				'mode'        => Uich_ND_Settings::get_mode(),
				'onboarded'   => Uich_ND_Settings::is_onboarded(),
				'appPassword' => Uich_ND_App_Password::get_dashboard_state(),
				'localEnv'    => Uich_ND_Settings::get_local_env_state(),
				'protuno'     => Uich_ND_Settings::detect_protuno(),
				'site'        => array(
					'name'       => get_bloginfo( 'name' ),
					'url'        => get_option( 'siteurl' ),
					'restUrl'    => rest_url(),
					'connectUrl' => esc_url_raw( Uich_ND_Enqueue::connect_url_base() ),
				),
			) );
		}

		public static function route_set_builder( WP_REST_Request $req ) {
			$builder = sanitize_key( (string) $req->get_param( 'builder' ) );
			if ( ! in_array( $builder, Uich_ND_Settings::BUILDERS, true ) ) {
				return new WP_Error( 'invalid_builder', __( 'Unknown builder.', 'uichemy' ), array( 'status' => 400 ) );
			}

			// Persist — `update_option` returns false when the value didn't
			// change, but that's fine; we still want the recommended
			// settings to run on every Continue press.
			Uich_ND_Settings::set_builder( $builder );

			$recommended = Uich_ND_Recommended_Settings::apply_for( $builder );

			return rest_ensure_response( array(
				'builder'     => Uich_ND_Settings::get_builder(),
				'recommended' => $recommended,
			) );
		}

		public static function route_set_mode( WP_REST_Request $req ) {
			$ok = Uich_ND_Settings::set_mode( (string) $req->get_param( 'mode' ) );
			if ( ! $ok ) {
				return new WP_Error( 'invalid_mode', __( 'Unknown mode.', 'uichemy' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'mode' => Uich_ND_Settings::get_mode() ) );
		}

		public static function route_set_onboarded( WP_REST_Request $req ) {
			$done    = (bool) $req->get_param( 'done' );
			$consent = (bool) $req->get_param( 'consent' );
			Uich_ND_Settings::set_onboarded( $done );

			// Best-effort analytics ping — only on completion, only once,
			// and only with the user's explicit opt-in consent. WordPress.org
			// guidelines require opt-in before sending site data to an
			// external server. Failures are swallowed; never blocks the user.
			$ping = null;
			if ( $done && $consent ) {
				$ping = Uich_ND_Analytics::ping_onboarding();
			}

			return rest_ensure_response( array(
				'onboarded' => Uich_ND_Settings::is_onboarded(),
				'analytics' => $ping,
			) );
		}

		public static function route_install( WP_REST_Request $req ) {
			$result = Uich_ND_Installer::install_or_activate( (string) $req->get_param( 'builder' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		}

		public static function route_protuno_install() {
			$result = Uich_ND_Installer::install_protuno();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		}

		public static function route_register_session( WP_REST_Request $req ) {
			$license_id = sanitize_text_field( (string) $req->get_param( 'licenseId' ) );
			$token      = Uich_ND_Auth::get_token();

			if ( '' === $token ) {
				return new WP_Error( 'not_authed', __( 'Not connected to UiChemy.', 'uichemy' ), array( 'status' => 401 ) );
			}

			$session = Uich_ND_Auth::register_license_session( $token, $license_id );

			if ( is_wp_error( $session ) ) {
				return new WP_Error(
					$session->get_error_code(),
					$session->get_error_message(),
					array( 'status' => 'license_session_limit_reached' === $session->get_error_code() ? 409 : 400 )
				);
			}

			return rest_ensure_response( array( 'session' => $session ) );
		}

		/**
		 * GET /licenses/user — server-side proxy to the UiChemy API so the
		 * browser never makes the cross-origin call itself (no CORS). Forwards
		 * the stored SSO token as a Bearer header. A 401 from the API means the
		 * token is dead, so we clear it (and the cached license/session options)
		 * and report 401 — the dashboard then signs the user out.
		 */
		public static function route_licenses_user() {
			$token = Uich_ND_Auth::get_token();
			if ( '' === $token ) {
				return new WP_Error( 'not_authed', __( 'Not connected to UiChemy.', 'uichemy' ), array( 'status' => 401 ) );
			}

			$result = Uich_ND_Auth::fetch_license_user( $token );

			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

				if ( 401 === $status ) {
					Uich_ND_Auth::clear_token();
					return new WP_Error( 'unauthorized', $result->get_error_message(), array( 'status' => 401 ) );
				}

				return new WP_Error(
					$result->get_error_code(),
					$result->get_error_message(),
					array( 'status' => $status >= 400 ? $status : 502 )
				);
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /change-license — switch this site's session onto a different
		 * license via the UiChemy API (POST /licenses/change-license-activation).
		 * A 401 clears the token so the dashboard returns to sign-in.
		 */
		public static function route_change_license( WP_REST_Request $req ) {
			$new_id = sanitize_text_field( (string) $req->get_param( 'newLicenseId' ) );
			$token  = Uich_ND_Auth::get_token();

			if ( '' === $token ) {
				return new WP_Error( 'not_authed', __( 'Not connected to UiChemy.', 'uichemy' ), array( 'status' => 401 ) );
			}

			$result = Uich_ND_Auth::change_license_activation( $token, $new_id );

			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

				if ( 401 === $status ) {
					Uich_ND_Auth::clear_token();
				}

				return new WP_Error(
					$result->get_error_code(),
					$result->get_error_message(),
					array( 'status' => $status >= 400 ? $status : 400 )
				);
			}

			return rest_ensure_response( array( 'session' => $result ) );
		}
	}
}
