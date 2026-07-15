<?php
/**
 * Settings + option getters for the new dashboard.
 *
 * Centralises every option key used by the new dashboard so feature code
 * never reads/writes options directly. Add a getter here when you add a
 * new piece of dashboard state.
 *
 * Option keys (all prefixed `uich_nd_`):
 *   uich_nd_builder       — selected page builder slug.
 *   uich_nd_mode          — 'figma' | 'compose'.
 *   uich_nd_onboarded     — '1' once wizard finishes.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_ND_Settings' ) ) {

	final class Uich_ND_Settings {

		const OPT_BUILDER   = 'uich_nd_builder';
		const OPT_MODE      = 'uich_nd_mode';
		const OPT_ONBOARDED = 'uich_nd_onboarded';

		const BUILDERS = array( 'elementor', 'bricks', 'gutenberg' );
		const MODES    = array( 'figma', 'compose' );

		public static function boot() {
			// No hooks yet. REST routes for read/write live in Uich_ND_Api.
		}

		public static function get_builder() {
			$v = (string) get_option( self::OPT_BUILDER, '' );
			return in_array( $v, self::BUILDERS, true ) ? $v : '';
		}

		public static function set_builder( $v ) {
			$v = sanitize_key( (string) $v );
			if ( ! in_array( $v, self::BUILDERS, true ) ) {
				return false;
			}
			return update_option( self::OPT_BUILDER, $v );
		}

		public static function get_mode() {
			$v = (string) get_option( self::OPT_MODE, '' );
			return in_array( $v, self::MODES, true ) ? $v : '';
		}

		public static function set_mode( $v ) {
			$v = sanitize_key( (string) $v );
			if ( ! in_array( $v, self::MODES, true ) ) {
				return false;
			}
			return update_option( self::OPT_MODE, $v );
		}

		public static function is_onboarded() {
			// Dev toggle in class-uich-nd-loader.php:
			//   UICH_ND_ONBOARDING_PERSIST = false → har refresh pe wizard.
			if ( defined( 'UICH_ND_ONBOARDING_PERSIST' ) && false === UICH_ND_ONBOARDING_PERSIST ) {
				return false;
			}
			return '1' === (string) get_option( self::OPT_ONBOARDED, '0' );
		}

		public static function set_onboarded( $done = true ) {
			return update_option( self::OPT_ONBOARDED, $done ? '1' : '0' );
		}

		/**
		 * Detect which page builders are installed / active on this site.
		 *
		 * @return array { elementor:{installed,active}, bricks:..., gutenberg:... }
		 */
		public static function detect_builders() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();

			$elementor_file = 'elementor/elementor.php';
			$bricks_theme   = wp_get_theme( 'bricks' );

			return array(
				'elementor' => array(
					'installed' => isset( $plugins[ $elementor_file ] ),
					'active'    => is_plugin_active( $elementor_file ),
				),
				'bricks'    => array(
					'installed' => $bricks_theme->exists(),
					'active'    => 'bricks' === get_stylesheet(),
				),
				'gutenberg' => array(
					// Core block editor always present on supported WP.
					'installed' => true,
					'active'    => true,
				),
			);
		}

		/**
		 * Locate the installed Protuno plugin file (folder/main.php).
		 *
		 * Matched by TextDomain / Name rather than a fixed folder path, so it
		 * works regardless of the folder name the install zip unpacks to.
		 *
		 * @return string Plugin file relative to wp-content/plugins, or '' if absent.
		 */
		public static function find_protuno_file() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$text_domain = isset( $data['TextDomain'] ) ? strtolower( (string) $data['TextDomain'] ) : '';
				$name        = isset( $data['Name'] ) ? strtolower( (string) $data['Name'] ) : '';
				if ( 'protuno' === $text_domain || 'protuno' === $name ) {
					return (string) $file;
				}
			}
			return '';
		}

		/**
		 * Raw fetch of the full /plugins/versions payload from the UiChemy API,
		 * with NO persistent cache — memoized only within the current request so
		 * repeated reads on one page load don't refetch. This is what makes a
		 * "check every time" read possible (see get_protuno_latest).
		 *
		 * @return array  slug => array (e.g. [ 'latest_version' => '1.2.3' ]).
		 */
		private static function fetch_managed_versions_raw() {
			static $memo = null;
			if ( is_array( $memo ) ) {
				return $memo;
			}

			$result = array();

			$base = class_exists( 'Uich_ND_Auth' ) ? Uich_ND_Auth::API_BASE : 'https://core.uichemy.com';
			$url  = rtrim( $base, '/' ) . '/plugins/versions';

			$response = wp_remote_get( $url, array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			) );

			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
					if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
						$result = $decoded['data'];
					}
				}
			}

			$memo = $result;
			return $result;
		}

		/**
		 * Latest versions of every managed plugin (protuno + the wp.org-hosted
		 * elementor / uichemy / nexter / wp), read fresh from the API. There's NO
		 * WP-side persistent cache — the API already Redis-caches the wp.org
		 * lookups (~1 hr), so a WP transient would only add duplicate staleness.
		 * Memoized per request via fetch_managed_versions_raw().
		 *
		 * @return array  slug => array (e.g. [ 'latest_version' => '1.2.3' ]).
		 */
		public static function get_managed_versions() {
			return self::fetch_managed_versions_raw();
		}

		/**
		 * API-reported latest version for a single wp.org-hosted managed plugin
		 * slug (elementor / uichemy / nexter / wp), or '' if the API has no value.
		 *
		 * @param string $slug
		 * @return string
		 */
		public static function get_managed_latest( $slug ) {
			$all = self::get_managed_versions();
			if ( isset( $all[ $slug ]['latest_version'] ) && '' !== (string) $all[ $slug ]['latest_version'] ) {
				return (string) $all[ $slug ]['latest_version'];
			}
			return '';
		}

		/**
		 * Latest published Protuno release (version + zip URL). Read FRESH from the
		 * API on every request (no persistent cache) — Protuno's version is set
		 * manually on the API, so a change must reflect immediately rather than
		 * waiting up to an hour like the wp.org plugins.
		 *
		 * @return array { version:string, zip_url:string }  Empty strings on failure.
		 */
		public static function get_protuno_latest() {
			$all = self::fetch_managed_versions_raw();
			$p   = isset( $all['protuno'] ) && is_array( $all['protuno'] ) ? $all['protuno'] : array();
			return array(
				'version' => isset( $p['latest_version'] ) ? (string) $p['latest_version'] : '',
				'zip_url' => isset( $p['zip_url'] ) ? (string) $p['zip_url'] : '',
			);
		}


		/**
		 * Detect whether the Protuno plugin (Proton widget + MCP) is
		 * installed / active on this site, and whether the API reports a newer
		 * release than what's installed.
		 *
		 * @return array { installed:bool, active:bool, file:string, version:string,
		 *                 latest_version:?string, update_available:bool }
		 */
		public static function detect_protuno() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$file    = self::find_protuno_file();
			$active  = $file && is_plugin_active( $file );
			$version = '';
			if ( $file ) {
				// Prefer Protuno's own PROTUNO_BETA_VERSION constant (defined at
				// runtime while the plugin is active) so beta builds report their
				// beta version for the comparison; fall back to the plugin header
				// Version for inactive installs where the constant isn't loaded.
				if ( defined( 'PROTUNO_BETA_VERSION' ) && '' !== (string) PROTUNO_BETA_VERSION ) {
					$version = (string) PROTUNO_BETA_VERSION;
				} else {
					$all     = get_plugins();
					$version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
				}
			}

			// Compare the installed version against the API-managed latest.
			$latest           = self::get_protuno_latest();
			$latest_version   = '' !== $latest['version'] ? $latest['version'] : '';
			$update_available = ( $file && '' !== $version && '' !== $latest_version )
				? version_compare( $version, $latest_version, '<' )
				: false;

			return array(
				'installed'        => (bool) $file,
				'active'           => (bool) $active,
				'file'             => (string) $file,
				'version'          => $version,
				'latest_version'   => '' !== $latest_version ? $latest_version : null,
				'update_available' => (bool) $update_available,
			);
		}

		/**
		 * Snapshot of the environment for the wizard's Step 0.
		 *
		 * Each field is mirrored by an *_ok boolean so the React side
		 * routes to the right calm-error card without re-running checks.
		 */
		public static function env_check() {
			global $wp_version;

			$required_ext = array( 'json', 'mbstring' );
			$missing_ext  = array_values( array_filter(
				$required_ext,
				static function ( $ext ) { return ! extension_loaded( $ext ); }
			) );

			$memory_raw   = (string) ini_get( 'memory_limit' );
			$memory_bytes = wp_convert_hr_to_bytes( $memory_raw );
			$memory_ok    = $memory_bytes >= ( 128 * 1024 * 1024 );

			return array(
				'wp_version'   => $wp_version,
				'wp_ok'        => version_compare( $wp_version, '6.9', '>=' ),
				'php_version'  => PHP_VERSION,
				'php_ok'       => version_compare( PHP_VERSION, '7.4', '>=' ),
				'is_admin'     => current_user_can( 'manage_options' ),
				'memory'       => $memory_raw,
				'memory_bytes' => (int) $memory_bytes,
				'memory_ok'    => $memory_ok,
				'missing_ext'  => $missing_ext,
				'ext_ok'       => empty( $missing_ext ),
			);
		}

		/**
		 * Snapshot of connection-time checks (permalinks, REST, security
		 * plugin presence, site reachability, app-passwords availability).
		 *
		 * Run at boot so the wizard surfaces the right calm-error card
		 * before the user wastes time clicking through.
		 */
		public static function connection_check() {
			$permalinks_pretty = '' !== get_option( 'permalink_structure' );

			// REST API "disabled" detection — the two common patterns.
			$rest_disabled_plugin = false;
			if ( function_exists( 'is_plugin_active' ) ) {
				$rest_disabled_plugin = is_plugin_active( 'disable-json-api/disable-json-api.php' )
					|| is_plugin_active( 'disable-wp-rest-api/disable-wp-rest-api.php' );
			}
			$rest_filter_blocking = false;
			if ( has_filter( 'rest_authentication_errors' ) ) {
				$saved_user_id = get_current_user_id();
				$probe = apply_filters( 'rest_authentication_errors', null );
				if ( is_wp_error( $probe ) ) {
					$rest_filter_blocking = true;
				}
				if ( get_current_user_id() !== $saved_user_id ) {
					wp_set_current_user( $saved_user_id );
				}
			}
			$rest_ok = ! ( $rest_disabled_plugin || $rest_filter_blocking );

			// Known security plugins — presence is enough to hint the user.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins         = get_plugins();
			$security_known  = array(
				'wordfence/wordfence.php'                             => 'Wordfence',
				'sucuri-scanner/sucuri.php'                           => 'Sucuri',
				'better-wp-security/better-wp-security.php'           => 'Solid Security',
				'all-in-one-wp-security-and-firewall/wp-security.php' => 'All-In-One WP Security',
			);
			$security_active = array();
			foreach ( $security_known as $file => $label ) {
				if ( isset( $plugins[ $file ] ) && is_plugin_active( $file ) ) {
					$security_active[] = $label;
				}
			}

			// Reachable: localhost / private IPs / .local TLDs fail
			// reachability from public Figma plugin / MCP servers.
			$host       = (string) wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
			$private_re = '/(^localhost$)|(^127\.)|(^10\.)|(^192\.168\.)|(^172\.(1[6-9]|2[0-9]|3[01])\.)|(\.(test|local|localhost)$)/i';
			$reachable  = ! preg_match( $private_re, (string) $host );

			// Honor the actual filtered availability — including our own
			// `wp_is_application_passwords_available` force-override.
			// Using `is_native_site_available()` here temporarily strips
			// the force filter, which made the error screen re-appear
			// even after the user clicked "Enable for my account".
			$app_passwords_ok = function_exists( 'wp_is_application_passwords_available' )
				? (bool) wp_is_application_passwords_available()
				: false;

			return array(
				'permalinks_pretty' => $permalinks_pretty,
				'permalinks_ok'     => $permalinks_pretty,
				'rest_ok'           => $rest_ok,
				'security_active'   => $security_active,
				'security_ok'       => empty( $security_active ),
				'reachable'         => $reachable,
				'reachable_ok'      => $reachable,
				'host'              => $host,
				'app_passwords_ok'  => $app_passwords_ok,
			);
		}

		/**
		 * True when the current request is served from a local host
		 * (localhost / 127.0.0.1 / ::1 / *.local / *.test / *.localhost).
		 */
		public static function is_localhost() {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
			if ( '' === $host ) {
				return false;
			}
			return 0 === strpos( $host, 'localhost' )
				|| 0 === strpos( $host, '127.0.0.1' )
				|| 0 === strpos( $host, '[::1]' )
				|| (bool) preg_match( '/\.(local|test|localhost)(:\d+)?$/', $host );
		}

		/**
		 * Read-only snapshot for the dashboard's local-env error card.
		 * No setter — the user edits wp-config.php themselves.
		 *
		 *   available — render the card at all? True for a hostname-detected
		 *               localhost OR any non-SSL (HTTP) site. WordPress blocks
		 *               Application Passwords over HTTP unless the environment
		 *               is 'local'/'development', so the wp-config snippet is
		 *               the fix on every HTTP host — not just *.local / 127.0.0.1
		 *               (covers QA running on a LAN IP or a custom HTTP domain).
		 *   current   — current value of WP_ENVIRONMENT_TYPE
		 *   snippet   — exact line to paste into wp-config.php
		 */
		public static function get_local_env_state() {
			$current = function_exists( 'wp_get_environment_type' )
				? wp_get_environment_type()
				: ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production' );
			return array(
				'available' => self::is_localhost() || ! is_ssl(),
				'current'   => (string) $current,
				'snippet'   => "define( 'WP_ENVIRONMENT_TYPE', 'local' );",
			);
		}
	}
}
