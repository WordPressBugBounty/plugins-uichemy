<?php
/**
 * Agent Ready status: one read-only summary for the Overview tab and the
 * get-status action.
 *
 * Later work packages add their own parts through the
 * `uichemy_agent_ready_status` filter (files built, warm progress, MCP calls,
 * readiness score), so this class never needs to know every feature.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Status' ) ) {

	/**
	 * Status summary.
	 */
	class Uich_Agent_Status {

		/**
		 * Build the status summary.
		 *
		 * Paths are reported relative to wp-content, never absolute, so the
		 * summary can be shown to an AI client without leaking server layout.
		 *
		 * @return array
		 */
		public static function get() {
			$state = Uich_Agent_Store::state();

			// Everything the owner sets lives in WordPress options.
			$storage = array(
				'ok'       => true,
				'location' => 'database',
				'folder'   => '',
				'error'    => '',
			);

			$untrusted = Uich_Agent_Store::untrusted();
			$settings  = Uich_Agent_Settings::get();

			$status = array(
				'module'    => array(
					'version'    => Uich_Agent_Ready::VERSION,
					'enabled'    => Uich_Agent_Ready::enabled(),
					'decided_as' => isset( $state['decided_as'] ) ? $state['decided_as'] : '',
				),
				'storage'   => $storage,
				'integrity' => array(
					'ok'        => empty( $untrusted ),
					'untrusted' => $untrusted,
					'files'     => count( Uich_Agent_Store::hashes() ),
				),
				// While the module is off only settings + status are loaded (see Uich_Agent_Ready::init()).
				'overrides' => class_exists( 'Uich_Agent_Discovery' ) ? Uich_Agent_Discovery::overrides() : array(),
				'urls'      => array(
					'home'      => home_url( '/' ),
					'mcp'       => class_exists( 'Uich_Agent_MCP' ) && Uich_Agent_MCP::public_server() ? home_url( '/' . $settings['mcp']['path'] ) : '',
					'llms'      => home_url( '/llms.txt' ),
					'agent_url' => add_query_arg( $settings['agent_pages']['param_name'], $settings['agent_pages']['param_value'], home_url( '/' ) ),
				),
				'detected'  => array(
					'woocommerce'  => class_exists( 'WooCommerce' ),
					'multilingual' => class_exists( 'Uich_L10n_Links' ),
					'page_cache'   => class_exists( 'UiChemy_Cache_Engine' ),
					'seo_module'   => class_exists( 'Uich_SEO' ),
				),
			);

			/**
			 * Add parts to the Agent Ready status summary.
			 *
			 * @param array $status Summary.
			 */
			return apply_filters( 'uichemy_agent_ready_status', $status );
		}

		/**
		 * A path relative to wp-content (or the absolute path's basename when outside it).
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		private static function relative( $path ) {
			$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
			$path    = wp_normalize_path( $path );
			return 0 === strpos( $path, $content ) ? 'wp-content/' . substr( $path, strlen( $content ) ) : basename( untrailingslashit( $path ) ) . '/';
		}
	}
}
