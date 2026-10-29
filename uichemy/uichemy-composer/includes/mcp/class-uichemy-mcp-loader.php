<?php
/**
 * MCP Bootstrap for UiChemy
 *
 * Registers UiChemy's tools as WordPress abilities (uichemy-composer/
 * namespace - see v2/class-uichemy-abilities.php) and serves them through the
 * one MCP surface, /wp-json/uichemy/v2/mcp (UiChemy_MCP_Server_V2). The
 * execute_* handlers those abilities reach live on UiChemy_Composer_MCP_Server
 * (shared/). The legacy /wp-json/uichemy/v1/mcp flat-tool endpoint is gone.
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
 * The bundled mcp-adapter fires shared, non-namespaced hooks
 * ('mcp_adapter_init', 'mcp_adapter_default_server_config',
 * 'mcp_adapter_create_default_server'), which is why running two copies used to
 * collide. Isolation is handled at the listener level instead: both server
 * classes type-guard their $adapter argument against the scoped McpAdapter, so a
 * foreign adapter firing the shared hook can never register our servers onto the
 * wrong instance — and vice versa.
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
		 * Drop OUR copy's default server before it is built.
		 *
		 * McpAdapter::init() hooks its own DefaultServerFactory::create onto
		 * 'mcp_adapter_init' at priority 10, and that factory resolves the adapter
		 * through its own scoped singleton — it does not check whether the route is
		 * already taken. With two adapter copies on the site both would register
		 * REST routes at /wp-json/mcp/mcp-adapter-default-server and clash.
		 *
		 * Removing the callback at priority 1 (before 10 runs) targets ONLY the
		 * UiChemy-scoped factory: UiChemy's is a different class and therefore a
		 * different callable, so its default server — and the shared
		 * `mcp-adapter/*` abilities it registers — are left intact. Filtering
		 * 'mcp_adapter_create_default_server' instead would have hit every copy,
		 * since that hook is un-namespaced.
		 */
		add_action(
			'mcp_adapter_init',
			function () {
				remove_action(
					'mcp_adapter_init',
					array( \UiChemy\Deps\WP\MCP\Servers\DefaultServerFactory::class, 'create' )
				);
			},
			1
		);

		// Guarantee the adapter initializes so 'mcp_adapter_init' fires.
		\UiChemy\Deps\WP\MCP\Core\McpAdapter::instance();

		UiChemy_MCP_Server_V2::init();
	}
}

UiChemy_Composer_Upload::init();

// Whatever this build ships beyond the shared core (see plugin-modules.php).
uichemy_load_plugin_modules();
