<?php
/**
 * Asset enqueue for the new dashboard.
 *
 * Loads new-dashboard/build/index.js + index.css on the UiChemy (New)
 * admin screen only, and localises the bootstrap payload into the global
 * `uich_nd_boot`.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Enqueue' ) ) {

	final class Uich_ND_Enqueue {

		const HANDLE_JS  = 'uich-nd-script';
		const HANDLE_CSS = 'uich-nd-style';

		public static function boot() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10, 1 );
		}

		/**
		 * Which admin page slugs the new dashboard renders on.
		 */
		public static function page_hooks() {
			return array(
				'toplevel_page_uichemy',
			);
		}

		public static function enqueue( $page ) {
			if ( ! in_array( $page, self::page_hooks(), true ) ) {
				return;
			}

			$css_path = UICH_ND_BUILD_PATH . 'index.css';
			$js_path  = UICH_ND_BUILD_PATH . 'index.js';
			$ver_css  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : UICH_VERSION;
			$ver_js   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : UICH_VERSION;

			if ( file_exists( $css_path ) ) {
				wp_enqueue_style( self::HANDLE_CSS, UICH_ND_BUILD_URL . 'index.css', array(), $ver_css, 'all' );
			}

			// Google Fonts — Plus Jakarta Sans, per design system.
			wp_enqueue_style(
				'uich-nd-fonts',
				'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
				array(),
				null
			);

			if ( file_exists( $js_path ) ) {
				wp_enqueue_script(
					self::HANDLE_JS,
					UICH_ND_BUILD_URL . 'index.js',
					array( 'react', 'react-dom', 'wp-dom-ready', 'wp-element', 'wp-i18n' ),
					$ver_js,
					true
				);
				wp_localize_script( self::HANDLE_JS, 'uich_nd_boot', self::boot_payload() );
				wp_set_script_translations( self::HANDLE_JS, 'uichemy' );
			} else {
				add_action( 'admin_notices', array( __CLASS__, 'missing_build_notice' ) );
			}
		}

		public static function missing_build_notice() {
			?>
			<div class="notice notice-warning">
				<p><strong>UiChemy (New):</strong> the React build is missing.
				Run <code>cd new-dashboard &amp;&amp; npm install &amp;&amp; npm run build</code> from the plugin root.</p>
			</div>
			<?php
		}

		/**
		 * Base URL for the user-facing Connection Link.
		 *
		 * Pretty / index permalinks already give a clean, path-based REST
		 * root via rest_url() (".../wp-json/" or ".../index.php/wp-json/").
		 * Plain permalinks would give ".../?rest_route=/" — instead we return
		 * the bare ".../index.php" entry point and let the Figma plugin add
		 * the ?rest_route=/<endpoint> query itself at request time.
		 */
		public static function connect_url_base() {
			if ( get_option( 'permalink_structure' ) ) {
				return rest_url();
			}
			return home_url( '/index.php' );
		}

		/**
		 * Gravatar URL for the connected UiChemy account (the same email the
		 * profile menu shows), falling back to the WP user's email. Mirrors the
		 * Figma plugin — d=404 so the UI shows the name initial when the account
		 * has no Gravatar.
		 *
		 * @param WP_User|null $wp_user
		 * @return string
		 */
		private static function account_gravatar_url( $wp_user ) {
			$email   = '';
			$license = Uich_ND_Auth::get_license_data();
			if ( is_array( $license ) ) {
				if ( isset( $license['data']['user']['email'] ) ) {
					$email = (string) $license['data']['user']['email'];
				} elseif ( isset( $license['user']['email'] ) ) {
					$email = (string) $license['user']['email'];
				}
			}
			if ( '' === $email && $wp_user ) {
				$email = (string) $wp_user->user_email;
			}
			if ( '' === $email ) {
				return '';
			}
			return esc_url_raw( 'https://www.gravatar.com/avatar/' . md5( strtolower( trim( $email ) ) ) . '?s=128&d=404' );
		}

		/**
		 * Bootstrap data injected as `window.uich_nd_boot`.
		 */
		public static function boot_payload() {
			$user = wp_get_current_user();

			return array(
				'mountId'    => UICH_ND_MOUNT,
				'restRoot'   => esc_url_raw( rest_url( Uich_ND_Api::NS ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'  => wp_create_nonce( Uich_ND_App_Password::NONCE_ACTION ),
				'adminUrl'   => admin_url(),
				'pluginUrl'  => UICH_URL,
				'version'    => UICH_VERSION,
				'siteName'   => get_bloginfo( 'name' ),
				'siteUrl'    => get_option( 'siteurl' ),
				'restUrl'    => rest_url(),
				// Base for the user-facing Connection Link. On pretty
				// permalinks this is the clean ".../wp-json/" root; on plain
				// permalinks rest_url() would hand back ".../?rest_route=/",
				// so we expose the ".../index.php" entry point instead and let
				// the Figma plugin append ?rest_route=/<endpoint> itself. Keeps
				// the copied link short and route-free.
				'connectUrl' => esc_url_raw( self::connect_url_base() ),
				// Permalink-aware MCP endpoints. rest_url() handles pretty vs
				// plain permalinks (?rest_route= / index.php). Send the full
				// URLs from PHP so React never has to guess.
				'mcpUrls'    => array(
					'regular'  => esc_url_raw( rest_url( 'uichemy/v1/mcp' ) ),
				),
				'user'       => array(
					'id'         => $user ? (int) $user->ID : 0,
					'name'       => $user ? esc_html( $user->display_name ) : '',
					'login'      => $user ? sanitize_user( $user->user_login ) : '',
					'email'      => $user ? sanitize_email( $user->user_email ) : '',
					'avatar'     => $user ? esc_url( get_avatar_url( $user->ID ) ) : '',
					'gravatar'   => self::account_gravatar_url( $user ),
					'isAdmin'    => current_user_can( 'manage_options' ),
				),
				'auth'       => Uich_ND_Auth::get_boot_state(),
				'state'      => array(
					'env'         => Uich_ND_Settings::env_check(),
					'connection'  => Uich_ND_Settings::connection_check(),
					'builders'    => Uich_ND_Settings::detect_builders(),
					'builder'     => Uich_ND_Settings::get_builder(),
					'mode'        => Uich_ND_Settings::get_mode(),
					'onboarded'   => Uich_ND_Settings::is_onboarded(),
					'appPassword' => Uich_ND_App_Password::get_dashboard_state(),
					'localEnv'    => Uich_ND_Settings::get_local_env_state(),
					'protuno'     => Uich_ND_Settings::detect_protuno(),
				),
				'urls'       => array(
					'docs'      => 'https://uichemy.com/docs',
					'chat'      => 'https://uichemy.com/chat',
					'community' => 'https://store.posimyth.com/helpdesk',
				),
			);
		}
	}
}
