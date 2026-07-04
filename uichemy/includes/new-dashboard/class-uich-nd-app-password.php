<?php
/**
 * App Password handling for the new dashboard.
 *
 * Clean-slate port of the legacy `Uich_App_Password`. Coexists with it
 * during Phase 1-3: same WP application-passwords store, distinct name
 * prefix (`uichemy-nd-`) and distinct meta keys so the new dashboard's
 * remembered state is independent.
 *
 * Force-availability filters reuse the legacy `uich_force_app_passwords`
 * user-meta key so that an admin who enabled the override from either
 * dashboard sees a consistent on/off state. We only register the
 * filters here if the legacy class isn't already registering them, so
 * the cutover in Phase 3 doesn't lose this behaviour.
 *
 * AJAX actions (admin-only, nonce `uich_nd_ajax`):
 *   uich_nd_generate_app_password
 *   uich_nd_enable_app_passwords
 *   uich_nd_disable_app_passwords
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_App_Password' ) ) {

	final class Uich_ND_App_Password {

		const NONCE_ACTION      = 'uich_nd_ajax';
		const NAME_PREFIX       = 'uichemy-';            // shared root, mode appended live
		const FIGMA_PREFIX      = 'uichemy-figma-';
		const MCP_PREFIX        = 'uichemy-mcp-';
		const USER_META_COUNTER = 'uich_nd_app_pass_counter';
		const USER_META_FORCE   = 'uich_force_app_passwords'; // shared with legacy

		public static function boot() {
			self::maybe_register_force_filters();
			add_action( 'wp_ajax_uich_nd_generate_app_password', array( __CLASS__, 'ajax_generate' ) );
			add_action( 'wp_ajax_uich_nd_enable_app_passwords', array( __CLASS__, 'ajax_enable' ) );
			add_action( 'wp_ajax_uich_nd_disable_app_passwords', array( __CLASS__, 'ajax_disable' ) );
		}

		/**
		 * Only register force filters if the legacy class hasn't. Both
		 * read the same user meta, so duplicating wastes cycles on every
		 * app-passwords check.
		 */
		private static function maybe_register_force_filters() {
			if ( class_exists( 'Uich_App_Password' )
				&& has_filter( 'wp_is_application_passwords_available', array( 'Uich_App_Password', 'filter_force_available' ) ) ) {
				return;
			}
			add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'filter_force_available' ), 999 );
			add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_force_available_for_user' ), 999, 2 );
		}

		public static function is_user_force_enabled( $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 ) {
				return false;
			}
			return '1' === (string) get_user_meta( $user_id, self::USER_META_FORCE, true );
		}

		public static function filter_force_available( $available ) {
			if ( $available ) {
				return true;
			}
			if ( doing_filter( 'determine_current_user' ) ) {
				return false;
			}
			$current_id = get_current_user_id();
			if ( $current_id > 0 && self::is_user_force_enabled( $current_id ) ) {
				return true;
			}
			return false;
		}

		public static function filter_force_available_for_user( $available, $user ) {
			if ( $available ) {
				return true;
			}
			if ( $user instanceof WP_User && $user->exists() && self::is_user_force_enabled( $user->ID ) ) {
				return true;
			}
			return (bool) $available;
		}

		/**
		 * Snapshot for the React side.
		 *
		 * The Application Password is treated as a transient one-shot
		 * secret: we never persist last4 / name in user meta and always
		 * boot the dashboard with `hasToken: false`. The dashboard's
		 * Connection card shows a "Generate" button on every load — the
		 * user picks when to issue a fresh password (no auto-issue).
		 */
		public static function get_dashboard_state() {
			$user      = wp_get_current_user();
			$available = function_exists( 'wp_is_application_passwords_available' )
				? (bool) wp_is_application_passwords_available()
				: false;
			$available_for_user = function_exists( 'wp_is_application_passwords_available_for_user' )
				? (bool) wp_is_application_passwords_available_for_user( $user )
				: $available;

			$native_available = self::is_native_site_available();
			$native_for_user  = $native_available && self::is_native_available_for_user( $user );
			$force_enabled    = $user ? self::is_user_force_enabled( $user->ID ) : false;

			return array(
				'available'        => $available,
				'availableForUser' => $available_for_user,
				'nativeAvailable'  => $native_available,
				'forceEnabled'     => $force_enabled,
				'canForceEnable'   => ! $native_for_user && ! $force_enabled,
				'canForceDisable'  => $force_enabled,
				'disabledReason'   => self::get_disabled_reason( $user ),
				'userLogin'        => $user ? $user->user_login : '',
				'isSsl'            => is_ssl(),
				'permalinkPretty'  => '' !== get_option( 'permalink_structure' ),
				'namePrefix'       => self::NAME_PREFIX,
				// Transient by design — user clicks Generate to issue fresh.
				'hasToken'         => false,
				'profileUrl'       => admin_url( 'profile.php#application-passwords-section' ),
			);
		}

		public static function ajax_generate() {
			self::guard();

			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				wp_send_json_error( array( 'code' => 'no_class', 'message' => __( 'Application Passwords need WordPress 5.6+.', 'uichemy' ) ), 400 );
			}

			$user = wp_get_current_user();

			// Auto-enable for HTTP / local dev — same UX as legacy.
			if ( self::is_native_disabled_for_user( $user ) && ! self::is_user_force_enabled( $user->ID ) ) {
				update_user_meta( $user->ID, self::USER_META_FORCE, '1' );
			}

			if ( function_exists( 'wp_is_application_passwords_available_for_user' )
				&& ! wp_is_application_passwords_available_for_user( $user ) ) {
				wp_send_json_error( array(
					'code'    => 'user_blocked',
					'message' => __( 'Application Passwords are disabled for this user. Enable them, then try again.', 'uichemy' ),
				), 400 );
			}

			// Mode determines the naming convention: uichemy-figma-N /
			// uichemy-mcp-N. Default to figma when the JS side doesn't
			// pass it (e.g. dashboard Generate before mode is set).
			$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'figma';
			if ( ! in_array( $mode, array( 'figma', 'mcp' ), true ) ) {
				$mode = 'figma';
			}

			// No cleanup — every Generate adds a new entry, old entries
			// stay in the user's WP profile (they can revoke manually if
			// they want). Counter is monotonic so names never collide.
			$counter = (int) get_user_meta( $user->ID, self::USER_META_COUNTER, true );
			$counter++;
			update_user_meta( $user->ID, self::USER_META_COUNTER, $counter );

			$name = sprintf( 'uichemy-%s-%d', $mode, $counter );

			$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );
			if ( is_wp_error( $created ) ) {
				wp_send_json_error( array( 'code' => 'wp_error', 'message' => $created->get_error_message() ), 500 );
			}

			list( $password, $details ) = $created;

			// Plain Application Password is the only secret the React side
			// needs — every surface (Connection card, wizard, MCP config)
			// embeds it directly into the URL or env var. The old
			// `Authorization: Basic …` flow is gone, so we don't compute
			// the base64 token or the masked variant any more.

			wp_send_json_success( array(
				'uuid'      => isset( $details['uuid'] ) ? (string) $details['uuid'] : '',
				'name'      => $name,
				'created'   => isset( $details['created'] ) ? (int) $details['created'] : time(),
				'userLogin' => $user->user_login,
				'password'  => $password,
				'state'     => self::get_dashboard_state(),
			) );
		}

		public static function ajax_enable() {
			self::guard();

			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				wp_send_json_error( array( 'code' => 'no_class', 'message' => __( 'Application Passwords need WordPress 5.6+.', 'uichemy' ) ), 400 );
			}

			$user = wp_get_current_user();
			if ( ! self::is_native_disabled_for_user( $user ) ) {
				wp_send_json_error( array( 'code' => 'already', 'message' => __( 'Application Passwords are already available for your account.', 'uichemy' ) ), 400 );
			}

			update_user_meta( $user->ID, self::USER_META_FORCE, '1' );
			wp_send_json_success( self::get_dashboard_state() );
		}

		public static function ajax_disable() {
			self::guard();
			$user = wp_get_current_user();
			if ( ! self::is_user_force_enabled( $user->ID ) ) {
				wp_send_json_error( array( 'code' => 'noop', 'message' => __( 'No UiChemy override is active for your account.', 'uichemy' ) ), 400 );
			}
			delete_user_meta( $user->ID, self::USER_META_FORCE );
			wp_send_json_success( self::get_dashboard_state() );
		}

		private static function guard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'Insufficient permissions.', 'uichemy' ) ), 403 );
			}
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		}


		public static function is_native_site_available() {
			$has_legacy = has_filter( 'wp_is_application_passwords_available', array( 'Uich_App_Password', 'filter_force_available' ) );
			$has_self   = has_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'filter_force_available' ) );
			if ( $has_legacy ) {
				remove_filter( 'wp_is_application_passwords_available', array( 'Uich_App_Password', 'filter_force_available' ), 999 );
			}
			if ( $has_self ) {
				remove_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'filter_force_available' ), 999 );
			}
			$available = function_exists( 'wp_is_application_passwords_available' )
				? (bool) wp_is_application_passwords_available()
				: false;
			if ( $has_legacy ) {
				add_filter( 'wp_is_application_passwords_available', array( 'Uich_App_Password', 'filter_force_available' ), 999 );
			}
			if ( $has_self ) {
				add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'filter_force_available' ), 999 );
			}
			return $available;
		}

		private static function is_native_available_for_user( $user ) {
			$has_legacy = has_filter( 'wp_is_application_passwords_available_for_user', array( 'Uich_App_Password', 'filter_force_available_for_user' ) );
			$has_self   = has_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_force_available_for_user' ) );
			if ( $has_legacy ) {
				remove_filter( 'wp_is_application_passwords_available_for_user', array( 'Uich_App_Password', 'filter_force_available_for_user' ), 999 );
			}
			if ( $has_self ) {
				remove_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_force_available_for_user' ), 999 );
			}
			$available = function_exists( 'wp_is_application_passwords_available_for_user' )
				? (bool) wp_is_application_passwords_available_for_user( $user )
				: false;
			if ( $has_legacy ) {
				add_filter( 'wp_is_application_passwords_available_for_user', array( 'Uich_App_Password', 'filter_force_available_for_user' ), 999, 2 );
			}
			if ( $has_self ) {
				add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_force_available_for_user' ), 999, 2 );
			}
			return $available;
		}

		private static function is_native_disabled_for_user( $user ) {
			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				return false;
			}
			if ( ! self::is_native_site_available() ) {
				return true;
			}
			return ! self::is_native_available_for_user( $user );
		}

		private static function get_disabled_reason( $user ) {
			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				return 'no_class';
			}
			if ( ! self::is_native_site_available() ) {
				if ( ! is_ssl() && function_exists( 'wp_get_environment_type' )
					&& ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
					return 'no_ssl';
				}
				return 'blocked';
			}
			if ( $user && ! self::is_native_available_for_user( $user ) ) {
				return 'user';
			}
			return null;
		}
	}
}
