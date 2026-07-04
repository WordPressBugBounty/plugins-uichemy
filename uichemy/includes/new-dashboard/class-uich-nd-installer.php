<?php
/**
 * Builder installer / activator for the new dashboard.
 *
 * Drives the wizard's "Install <builder>" affordance:
 *
 *   elementor → install from wordpress.org if missing, then activate.
 *   bricks    → activate the theme if already installed (it's premium,
 *               so we can't pull it from WP repo); otherwise return a
 *               `redirect` action pointing at bricksbuilder.io so the
 *               React side can open the buy page in a new tab.
 *   gutenberg → always present; nothing to do.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Installer' ) ) {

	final class Uich_ND_Installer {

		const ELEMENTOR_FILE     = 'elementor/elementor.php';
		const ELEMENTOR_WPORG_URL = 'https://wordpress.org/plugins/elementor/';
		const BRICKS_BUY_URL     = 'https://bricksbuilder.io/';

		/**
		 * Hosted Protuno install package.
		 *
		 * Can be overridden at runtime via the `uich_protuno_zip_url` filter
		 * without editing this file.
		 */
		const PROTUNO_ZIP_URL = 'https://uichemy.com/wp-content/uploads/2026/07/protuno-wp-v1.0.0-f5e565b.zip';

		public static function boot() {
			// On-demand class — REST handler in Uich_ND_Api calls install_or_activate.
		}

		/**
		 * @param string $builder One of 'elementor' | 'bricks' | 'gutenberg'.
		 * @return array|WP_Error  { ok, action, builder, message, redirect?, detected }
		 */
		public static function install_or_activate( $builder ) {
			$builder = sanitize_key( $builder );

			switch ( $builder ) {
				case 'elementor':
					return self::handle_elementor();

				case 'bricks':
					return self::handle_bricks();

				case 'gutenberg':
					return array(
						'ok'       => true,
						'action'   => 'noop',
						'builder'  => 'gutenberg',
						'message'  => __( 'Gutenberg is built into WordPress.', 'uichemy' ),
						'detected' => Uich_ND_Settings::detect_builders(),
					);

				default:
					return new WP_Error( 'invalid_builder', __( 'Unknown builder.', 'uichemy' ), array( 'status' => 400 ) );
			}
		}

		/* ---------- Elementor ---------- */

		private static function handle_elementor() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = get_plugins();
			$file    = self::ELEMENTOR_FILE;

			if ( ! isset( $plugins[ $file ] ) ) {
				// Not installed → send the user to the wordpress.org page
				// so they install it from the trusted source themselves.
				return array(
					'ok'       => true,
					'action'   => 'redirect',
					'builder'  => 'elementor',
					'message'  => __( 'Install Elementor from WordPress.org, then come back.', 'uichemy' ),
					'redirect' => self::ELEMENTOR_WPORG_URL,
					'detected' => Uich_ND_Settings::detect_builders(),
				);
			}

			if ( is_plugin_active( $file ) ) {
				return self::success( 'elementor', 'already_active', __( 'Elementor is already active.', 'uichemy' ) );
			}

			return self::activate_or_link( $file, 'elementor', __( 'Elementor activated.', 'uichemy' ) );
		}

		/**
		 * Try silent activation in-process. If the plugin's bootstrap
		 * throws (e.g. Elementor 4.1.x cloud-library uncaught 403), or
		 * activation otherwise fails, hand the user a one-click WP
		 * activation URL — `plugins.php` sandboxes the plugin include
		 * and surfaces errors gracefully, where our REST request just
		 * returns a "critical error" 500.
		 *
		 * @return array Response payload (ok+action) — never a WP_Error,
		 *               because the activate_url fallback always works.
		 */
		private static function activate_or_link( $file, $builder, $success_message ) {
			$threw = null;
			try {
				$result = activate_plugin( $file, '', false, true );
				if ( is_wp_error( $result ) ) {
					$threw = $result->get_error_message();
				}
			} catch ( \Throwable $e ) {
				$threw = $e->getMessage();
			}

			// Belt-and-braces — re-check the live state. activate_plugin
			// can persist into active_plugins even when its sandbox
			// include throws; conversely a successful return on a stale
			// cache can lie.
			$is_active = function_exists( 'is_plugin_active' ) ? is_plugin_active( $file ) : false;
			if ( $is_active && ! $threw ) {
				return self::success( $builder, 'activated', $success_message );
			}

			return array(
				'ok'           => true,
				'action'       => 'activate_url',
				'builder'      => $builder,
				'message'      => $threw
					? __( 'Activation needs to finish in WP-Admin.', 'uichemy' )
					: __( 'Click to activate in WP-Admin.', 'uichemy' ),
				'activate_url' => self::activation_url( $file ),
				'detected'     => Uich_ND_Settings::detect_builders(),
			);
		}

		/**
		 * Standard WP activation URL with nonce. plugins.php's activate
		 * action sandboxes the include — if the plugin's bootstrap
		 * throws, WP surfaces the error instead of 500'ing the response.
		 * Tagged with `from=uichemy` so the post-activate hook (in
		 * class-uich-nd-menu.php) can bounce the user back here.
		 */
		private static function activation_url( $plugin_file ) {
			return wp_nonce_url(
				self_admin_url( 'plugins.php?action=activate&from=uichemy&plugin=' . urlencode( $plugin_file ) ),
				'activate-plugin_' . $plugin_file
			);
		}

		/* ---------- Bricks ---------- */

		private static function handle_bricks() {
			$theme = wp_get_theme( 'bricks' );

			// Premium theme — can't pull from a public repo. If absent
			// we point the user at the buy page; if present, just switch.
			if ( ! $theme->exists() ) {
				return array(
					'ok'       => true,
					'action'   => 'redirect',
					'builder'  => 'bricks',
					'message'  => __( 'Bricks is a premium theme — install it from bricksbuilder.io, then come back.', 'uichemy' ),
					'redirect' => self::BRICKS_BUY_URL,
					'detected' => Uich_ND_Settings::detect_builders(),
				);
			}

			if ( 'bricks' === get_stylesheet() ) {
				return self::success( 'bricks', 'already_active', __( 'Bricks theme is already active.', 'uichemy' ) );
			}

			switch_theme( 'bricks' );

			return self::success( 'bricks', 'activated', __( 'Bricks theme activated.', 'uichemy' ) );
		}

		/* ---------- Protuno (Proton widget + MCP) ---------- */

		/**
		 * One-click install + activate for the Protuno plugin.
		 *
		 * Flow:
		 *   active            → already_active (noop).
		 *   installed/inactive → activate in-process (WP-Admin fallback on throw).
		 *   not installed     → download the hosted zip, install, then activate.
		 *
		 * @return array|WP_Error
		 */
		public static function install_protuno() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$file = Uich_ND_Settings::find_protuno_file();

			// Already present — just (re)activate if needed.
			if ( $file ) {
				if ( is_plugin_active( $file ) ) {
					return self::protuno_payload( 'already_active', __( 'Protuno is already active.', 'uichemy' ) );
				}
				return self::activate_protuno_file( $file );
			}

			// Not installed → pull the hosted package and install it.
			$zip_url = (string) apply_filters( 'uich_protuno_zip_url', self::PROTUNO_ZIP_URL );
			if ( '' === trim( $zip_url ) ) {
				return new WP_Error( 'protuno_no_zip', __( 'Protuno download URL is not configured.', 'uichemy' ), array( 'status' => 500 ) );
			}

			$installed = self::install_plugin_from_zip( $zip_url );
			if ( is_wp_error( $installed ) ) {
				return $installed;
			}

			// Locate the freshly unpacked plugin (folder name comes from the zip).
			$file = Uich_ND_Settings::find_protuno_file();
			if ( ! $file ) {
				return new WP_Error(
					'protuno_post_install_missing',
					__( 'Protuno was installed but could not be located. Activate it from the Plugins screen.', 'uichemy' ),
					array( 'status' => 500 )
				);
			}

			return self::activate_protuno_file( $file );
		}

		/**
		 * Activate the Protuno plugin file in-process; fall back to a sandboxed
		 * WP-Admin activation URL if its bootstrap throws (so we never 500).
		 *
		 * @param string $file Plugin file relative to the plugins dir.
		 * @return array
		 */
		private static function activate_protuno_file( $file ) {
			$threw = null;
			try {
				$result = activate_plugin( $file, '', false, true );
				if ( is_wp_error( $result ) ) {
					$threw = $result->get_error_message();
				}
			} catch ( \Throwable $e ) {
				$threw = $e->getMessage();
			}

			$is_active = function_exists( 'is_plugin_active' ) ? is_plugin_active( $file ) : false;
			if ( $is_active && ! $threw ) {
				return self::protuno_payload( 'activated', __( 'Protuno installed and activated.', 'uichemy' ) );
			}

			return array(
				'ok'           => true,
				'action'       => 'activate_url',
				'plugin'       => 'protuno',
				'message'      => $threw
					? __( 'Protuno installed. Finish activation in WP-Admin.', 'uichemy' )
					: __( 'Protuno installed. Click to activate in WP-Admin.', 'uichemy' ),
				'activate_url' => self::activation_url( $file ),
				'protuno'      => Uich_ND_Settings::detect_protuno(),
			);
		}

		/**
		 * Download + install a plugin from a remote .zip URL using WordPress'
		 * own upgrader, with a silent (non-interactive) skin.
		 *
		 * @param string $zip_url Publicly reachable plugin .zip URL.
		 * @return true|WP_Error
		 */
		private static function install_plugin_from_zip( $zip_url ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
				return new WP_Error( 'protuno_upgrader_missing', __( 'WordPress plugin installer is unavailable.', 'uichemy' ), array( 'status' => 500 ) );
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $zip_url );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_wp_error( $skin->result ) ) {
				return $skin->result;
			}
			if ( true !== $result ) {
				$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
				$detail   = is_array( $messages ) ? trim( implode( ' ', $messages ) ) : '';
				return new WP_Error(
					'protuno_install_failed',
					trim( __( 'Protuno installation failed.', 'uichemy' ) . ' ' . $detail ),
					array( 'status' => 500 )
				);
			}

			return true;
		}

		/**
		 * Standard Protuno success payload with a fresh detection snapshot.
		 *
		 * @param string $action  already_active | activated.
		 * @param string $message Human-readable status.
		 * @return array
		 */
		private static function protuno_payload( $action, $message ) {
			return array(
				'ok'      => true,
				'action'  => $action,
				'plugin'  => 'protuno',
				'message' => $message,
				'protuno' => Uich_ND_Settings::detect_protuno(),
			);
		}

		/* ---------- helpers ---------- */

		private static function success( $builder, $action, $message ) {
			return array(
				'ok'       => true,
				'action'   => $action,
				'builder'  => $builder,
				'message'  => $message,
				'detected' => Uich_ND_Settings::detect_builders(),
			);
		}
	}
}
