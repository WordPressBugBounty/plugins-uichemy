<?php
/**
 * MCP Bootstrap for UiChemy
 *
 * Registers UiChemy's tools as WordPress abilities (uichemy-composer/
 * namespace - see v2/class-uichemy-abilities.php) and serves them through the
 * one MCP surface, /wp-json/uichemy/v2/mcp (UiChemy_MCP_Server_V2). The
 * execute_* handlers those abilities reach live on UiChemy_Composer_MCP_Server
 * (shared/). Code Mode, /wp-json/uichemy/v3/mcp, also answers on the old
 * /wp-json/uichemy/v1/mcp; v2 is routed to v3 after 2026-12-12 (see v3).
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- shared engine: the execute_* handlers the v2 router calls.
require_once plugin_dir_path( __FILE__ ) . 'shared/class-uichemy-composer-mcp-server.php';
require_once plugin_dir_path( __FILE__ ) . 'shared/class-uichemy-upload-slots.php';
require_once plugin_dir_path( __FILE__ ) . 'shared/class-uichemy-composer-upload.php';

// Per-plugin modules (AI chat + the agent endpoint today; the Pro dynamic-data
// and license modules from Phase 3 onward). Free and Pro each ship their own copy
// of includes/plugin-modules.php — it is the one file under includes/ allowed to
// differ, so this shared loader never has to ask which build it is in.
// See docs/free-pro-split-plan.md.
require_once UICHEMY_PATH . 'includes/plugin-modules.php';

/**
 * The custom-field and content-model layers.
 *
 * Required BEFORE the abilities that route into them: the cpt ability builds its
 * own action schema from Uich_Model::field_types(), and the custom-fields
 * ability decides whether to expose its *-relations actions by asking
 * Uich_Field_Relations - both at registration time, not on first call.
 *
 * Ordering inside the group matters only for inheritance: the two abstract base
 * classes have to be declared before their subclasses are.
 */
$uichemy_field_layer = plugin_dir_path( dirname( __FILE__ ) ) . 'fields/';
require_once $uichemy_field_layer . 'class-uich-field-guard.php';
require_once $uichemy_field_layer . 'class-uich-field-value.php';
require_once $uichemy_field_layer . 'class-uich-field-provider.php';
require_once $uichemy_field_layer . 'class-uich-field-provider-meta.php';
require_once $uichemy_field_layer . 'class-uich-field-provider-acf.php';
require_once $uichemy_field_layer . 'class-uich-field-provider-jet.php';
require_once $uichemy_field_layer . 'class-uich-field-provider-uichemy.php';
require_once $uichemy_field_layer . 'class-uich-field-registry.php';
require_once $uichemy_field_layer . 'class-uich-field-relations.php';
require_once $uichemy_field_layer . 'class-uich-field-service.php';

$uichemy_model_layer = plugin_dir_path( dirname( __FILE__ ) ) . 'model/';
require_once $uichemy_model_layer . 'class-uich-model-provider.php';
require_once $uichemy_model_layer . 'class-uich-model-acf.php';
require_once $uichemy_model_layer . 'class-uich-model-jet.php';
require_once $uichemy_model_layer . 'class-uich-model-uichemy.php';
require_once $uichemy_model_layer . 'class-uich-model.php';
require_once $uichemy_model_layer . 'class-uich-model-terms.php';

/**
 * Expose Composer's pipeline tools as first-class WordPress abilities
 * (uichemy/ namespace, meta.mcp.public) so a single site-wide MCP gateway
 * serves them. This is the ONLY path UiChemy's tools reach MCP by.
 */
// --- v2 surface: routers first, then the abilities that dispatch into them.
// The Composer Builder document actions page / post switch to in Composer
// Builder mode (UiChemy_Builder_Context::is_composer_mode()).
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-composer-builder-actions.php';
// Site / admin context and the AI's memory (Settings > MCP), served as the
// uichemy-composer/memory ability and at the end of the briefing.
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-mcp-context.php';
UiChemy_MCP_Context::init();
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-mcp-v2-router.php';
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-abilities.php';
UiChemy_Abilities::init();

/**
 * The two abilities over the field and model layers.
 *
 * Registered whether or not ACF or JetEngine is present. An ability that
 * disappears with its provider teaches a model the capability does not exist
 * here at all; one that is present and answers "install ACF or JetEngine, or
 * model this with plain posts and categories" tells it what to do instead. The
 * guard lives in the handler, not in the registration.
 */
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uich-custom-fields-ability.php';
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uich-cpt-ability.php';
Uich_Custom_Fields_Ability::init();
Uich_CPT_Ability::init();

/**
 * Bug reports and feature requests from agents to the UiChemy team, drafted
 * on the site and sent only after the user says yes in the chat.
 */
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-team-ability.php';
UiChemy_Team_Ability::init();

/**
 * Contribute UiChemy's briefing to UiChemy's v2 discovery document, so a client
 * learns what UiChemy is and how to sequence its tools from the first call
 * instead of from the user.
 */
require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-skills.php';
UiChemy_Skills::init();

require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-usage-guide.php';
UiChemy_Usage_Guide::init();

/**
 * The MCP endpoint: /wp-json/uichemy/v2/mcp, the discover / inspect / execute
 * gateway (UiChemy_MCP_Server_V2). It is the only MCP surface UiChemy serves.
 *
 * The bundled mcp-adapter is patched after every Strauss run
 * (scripts/patch-mcp-adapter.php) so the hooks that pass its prefixed objects
 * carry a `uichemy_` prefix: 'uichemy_mcp_adapter_init',
 * 'uichemy_mcp_adapter_tool_call_result', and so on. A shared hook name would
 * hand UiChemy\Deps\WP\MCP\Core\McpAdapter to another plugin's listener that
 * type-hints \WP\MCP\Core\McpAdapter, and that TypeError breaks every REST
 * request. Both server classes still type-guard their $adapter argument.
 *
 * MERGED BUILD: these servers now run on UiChemy's single Strauss-prefixed
 * adapter copy (UiChemy\Deps\WP\MCP\) instead of a second UiChemy-prefixed one.
 * Both plugins pinned the same wordpress/mcp-adapter 0.5.0 and only differed in
 * prefix, so shipping two copies inside one plugin would have doubled the
 * dependency for no isolation benefit — the listener-level type guard above is
 * what keeps other plugins' adapters out, not the prefix.
 */
$uichemy_mcp_autoload = UICH_PATH . 'vendor-prefixed/autoload.php';

if ( is_readable( $uichemy_mcp_autoload ) ) {
	require_once $uichemy_mcp_autoload;

	if ( class_exists( '\UiChemy\Deps\WP\MCP\Core\McpAdapter' ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'v2/class-uichemy-mcp-server-v2.php';

		/*
		 * Drop our copy's default server before it is built: McpAdapter::init()
		 * hooks DefaultServerFactory::create onto 'uichemy_mcp_adapter_init' at
		 * priority 10. UiChemy serves only its own v2/v3 servers, and another
		 * adapter copy on the site may already own /wp-json/mcp/mcp-adapter-default-server.
		 */
		add_action(
			'uichemy_mcp_adapter_init',
			function () {
				remove_action(
					'uichemy_mcp_adapter_init',
					array( \UiChemy\Deps\WP\MCP\Servers\DefaultServerFactory::class, 'create' )
				);
			},
			1
		);

		// Guarantee the adapter initializes so 'uichemy_mcp_adapter_init' fires.
		\UiChemy\Deps\WP\MCP\Core\McpAdapter::instance();

		UiChemy_MCP_Server_V2::init();

		/*
		 * Code Mode: /wp-json/uichemy/v3/mcp (get_instructions, search, execute),
		 * also on /wp-json/uichemy/v1/mcp. On by default; Settings > AI clients >
		 * Code Mode, or UICHEMY_CODE_MODE false in wp-config.php, switches it off,
		 * and then those URLs serve the v2 tools, so a client set up with the v3
		 * URL keeps working. See code-mode/class-uichemy-code-mode.php.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'code-mode/class-uichemy-code-mode.php';
		require_once plugin_dir_path( __FILE__ ) . 'v3/class-uichemy-mcp-server-v3.php';
		UiChemy_MCP_Server_V3::init();
		// The same three tools as abilities, for other MCP gateways; while Code Mode
		// is on they replace the per-feature abilities there.
		require_once plugin_dir_path( __FILE__ ) . 'v3/class-uichemy-code-mode-abilities.php';
		UiChemy_Code_Mode_Abilities::init();
	}
}

UiChemy_Composer_Upload::init();

// Whatever this build ships beyond the shared core (see plugin-modules.php).
uichemy_load_plugin_modules();
