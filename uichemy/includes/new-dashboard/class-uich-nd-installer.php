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
		 * Protuno install package — resolved from the UiChemy API rather than a
		 * hardcoded URL, so the published zip can move without a plugin update.
		 *
		 * `{API_BASE}/plugins/protuno/download` 302-redirects to the currently
		 * hosted package; WP's upgrader follows the redirect transparently.
		 * Overridable at runtime via the `uich_protuno_zip_url` filter.
		 *
		 * @return string
		 */
		private static function protuno_zip_url() {
			$base = class_exists( 'Uich_ND_Auth' ) ? Uich_ND_Auth::API_BASE : 'http://localhost:8000';
			return rtrim( $base, '/' ) . '/plugins/protuno/download';
		}

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

			// Already present.
			if ( $file ) {
				// An update is available (installed < API latest) → overwrite-install
				// the newer package and restore its active state. install_protuno()
				// is the single endpoint behind both the WP dashboard and the Figma
				// "Update" button, so the update path lives here too.
				$detect = Uich_ND_Settings::detect_protuno();
				if ( ! empty( $detect['update_available'] ) ) {
					return self::update_protuno( $file );
				}

				if ( is_plugin_active( $file ) ) {
					return self::protuno_payload( 'already_active', __( 'Protuno is already active.', 'uichemy' ) );
				}
				return self::activate_protuno_file( $file );
			}

			// Not installed → pull the hosted package (via the API redirect) and install it.
			$zip_url = (string) apply_filters( 'uich_protuno_zip_url', self::protuno_zip_url() );
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
		 * Update an already-installed Protuno to the latest hosted package.
		 *
		 * Protuno isn't on wp.org and its zip can unpack to a version-specific
		 * folder name, so we can't rely on WP's in-place upgrader (which keys off
		 * the wp.org update transient and a stable folder). Instead we deactivate,
		 * delete the old copy, install the fresh zip, then reactivate if it was
		 * active before — a clean-slate replace that works regardless of folder
		 * naming.
		 *
		 * @param string $file Currently-installed Protuno plugin file.
		 * @return array|WP_Error
		 */
		private static function update_protuno( $file ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$zip_url = (string) apply_filters( 'uich_protuno_zip_url', self::protuno_zip_url() );
			if ( '' === trim( $zip_url ) ) {
				return new WP_Error( 'protuno_no_zip', __( 'Protuno download URL is not configured.', 'uichemy' ), array( 'status' => 500 ) );
			}

			$was_active = is_plugin_active( $file );

			// Deactivate before deleting so no stale hooks fire mid-swap.
			if ( $was_active ) {
				deactivate_plugins( $file, true );
			}

			// Remove the old install (the whole plugin folder).
			$deleted = delete_plugins( array( $file ) );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			if ( false === $deleted ) {
				return new WP_Error( 'protuno_delete_failed', __( 'Could not remove the old Protuno before updating.', 'uichemy' ), array( 'status' => 500 ) );
			}

			// Install the fresh package.
			$installed = self::install_plugin_from_zip( $zip_url );
			if ( is_wp_error( $installed ) ) {
				return $installed;
			}

			// Re-locate the plugin (its folder name may differ between versions)
			// so detect_protuno() reflects the freshly-installed state. No cache
			// to flush — versions are read fresh from the API each request.
			$file = Uich_ND_Settings::find_protuno_file();
			if ( ! $file ) {
				return new WP_Error(
					'protuno_post_update_missing',
					__( 'Protuno was updated but could not be located. Activate it from the Plugins screen.', 'uichemy' ),
					array( 'status' => 500 )
				);
			}

			// Restore the previous active state.
			if ( $was_active ) {
				return self::activate_protuno_file( $file, 'updated', __( 'Protuno updated to the latest version.', 'uichemy' ) );
			}
			return self::protuno_payload( 'updated', __( 'Protuno updated to the latest version.', 'uichemy' ) );
		}

		/**
		 * Activate the Protuno plugin file in-process; fall back to a sandboxed
		 * WP-Admin activation URL if its bootstrap throws (so we never 500).
		 *
		 * @param string $file            Plugin file relative to the plugins dir.
		 * @param string $success_action  Payload action on success (activated | updated).
		 * @param string $success_message Payload message on success; defaults to install copy.
		 * @return array
		 */
		private static function activate_protuno_file( $file, $success_action = 'activated', $success_message = null ) {
			if ( null === $success_message ) {
				$success_message = __( 'Protuno installed and activated.', 'uichemy' );
			}
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
				return self::protuno_payload( $success_action, $success_message );
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

			$package = self::download_package_to_temp( $zip_url );
			if ( is_wp_error( $package ) ) {
				return $package;
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package );

			if ( file_exists( $package ) ) {
				wp_delete_file( $package );
			}

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
		 * Download a remote package to a temp file using wp_remote_get (which,
		 * unlike download_url's wp_safe_remote_get, doesn't reject private hosts or
		 * non-standard ports). Follows redirects, so the API's 302 to the real zip
		 * is resolved transparently.
		 *
		 * @param string $url
		 * @return string|WP_Error  Temp file path, or WP_Error on failure.
		 */
		private static function download_package_to_temp( $url ) {
			if ( '' === trim( (string) $url ) ) {
				return new WP_Error( 'protuno_no_zip', __( 'Protuno download URL is not configured.', 'uichemy' ), array( 'status' => 500 ) );
			}

			// Fetch into memory (not stream-to-file): the package is small and this
			// avoids a WP_Http quirk where `stream => true` + a redirect can write
			// the redirect's empty body instead of the final zip. redirection=5
			// follows the API's 302 to the real zip.
			$response = wp_remote_get( $url, array(
				'timeout'     => 300,
				'redirection' => 5,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'protuno_download_failed',
					sprintf( __( 'Protuno download failed (HTTP %d).', 'uichemy' ), $code ),
					array( 'status' => 500 )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return new WP_Error( 'protuno_download_empty', __( 'Protuno download returned an empty package.', 'uichemy' ), array( 'status' => 500 ) );
			}

			$tmp = wp_tempnam( 'protuno-' );
			if ( ! $tmp ) {
				return new WP_Error( 'protuno_tmp_failed', __( 'Could not create a temporary file for the download.', 'uichemy' ), array( 'status' => 500 ) );
			}

			if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				wp_delete_file( $tmp );
				return new WP_Error( 'protuno_write_failed', __( 'Could not write the downloaded package.', 'uichemy' ), array( 'status' => 500 ) );
			}

			return $tmp;
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
