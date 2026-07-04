<?php
/**
 * MCP Server Bootstrap for UiChemy
 *
 * Loads UiChemy's scoped copy of the WordPress MCP Adapter (prefixed under
 * UiChemy\Deps\WP\MCP\) and registers UiChemy's MCP servers on it.
 *
 * The adapter ships in vendor-prefixed/ with all class names prefixed, so it
 * is fully isolated from any other plugin (e.g. Rank Math SEO) that may bundle
 * a different version of wordpress/mcp-adapter under the original WP\MCP\ namespace.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-uich-mcp-server.php';

/**
 * Load UiChemy's scoped (prefixed) MCP Adapter and register servers.
 *
 * The scoped adapter lives under UiChemy\Deps\WP\MCP\... so it never
 * conflicts with the WP\MCP\... namespace used by other plugins.
 *
 * Note: Strauss does NOT rename WP action/filter string literals, so the
 * hooks 'mcp_adapter_init' and 'mcp_adapter_create_default_server' keep
 * their original names inside the scoped adapter.
 */
$uich_mcp_autoload = UICH_PATH . 'vendor-prefixed/autoload.php';

if ( is_readable( $uich_mcp_autoload ) ) {
	require_once $uich_mcp_autoload;

	if ( class_exists( '\UiChemy\Deps\WP\MCP\Core\McpAdapter' ) ) {
		// Guarantee the adapter initializes so the action fires.
		\UiChemy\Deps\WP\MCP\Core\McpAdapter::instance();

		// The server hooks the adapter init action to register itself.
		Uich_MCP_Server::init();
	}
}
