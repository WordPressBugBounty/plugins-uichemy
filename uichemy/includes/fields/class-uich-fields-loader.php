<?php
/**
 * UiChemy Fields loader — boots the merged Content Fields runtime.
 *
 * The content-types module and its abilities layer now live inside this plugin, under
 * `includes/fields/`. The PHP was moved across with its structure intact; what changed is the
 * identifier surface — classes are `Uich_Fields_*`, the plumbing functions are `uich_fields_*`,
 * ability names are `uichemy/*` and the text domain is `uichemy`.
 *
 * That relocation works for the same reason the composer merge did: the whole module reaches the
 * filesystem and the browser through three constants, so defining them here re-points the
 * subsystem without touching a single require or enqueue inside it.
 *
 * Constant map:
 *   UICH_FIELDS_DIR   → uichemy/includes/fields/        (previously pointed at the plugin root)
 *   UICH_FIELDS_URL   → same, as a URL
 *   UICH_FIELDS_VER   → UICH_VERSION — one version for the plugin, not two
 *   UICH_FIELDS_BUILD_URL → uichemy/new-dashboard/build/
 *
 * UICH_FIELDS_BUILD_URL exists because the React bundle did NOT move with the PHP: the field
 * builder is built by the dashboard's webpack now, so its output sits in `new-dashboard/build/`
 * rather than under the module. The old path — module root + `content-fields/build/` — points at a
 * directory that does not exist here.
 *
 * @package Uichemy
 * @subpackage Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Fields_Loader' ) ) {

	final class Uich_Fields_Loader {

		/**
		 * Subdirectory (relative to the plugin root) holding the merged runtime.
		 */
		const DIR = 'includes/fields/';

		/**
		 * Singleton.
		 *
		 * @var Uich_Fields_Loader|null
		 */
		private static $instance;

		/**
		 * Get the instance.
		 *
		 * @return Uich_Fields_Loader
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Boot.
		 */
		private function __construct() {
			/*
			 * Another plugin on this site can carry the same module by history, not by current
			 * identity: the two copies now use separate post types (`uich_content_types` vs the
			 * other copy's own), separate REST namespaces (UICH_FIELDS_REST_NS vs the other
			 * copy's own), separate cache groups, transients and `uich/*` hooks — so the two no
			 * longer share a dataset and cannot clobber each other's writes. Both are free to run
			 * side by side, each managing its own independent set of definitions.
			 */
			// Never boot twice in one request.
			if ( defined( 'UICH_FIELDS_BOOTED' ) ) {
				return;
			}

			// Settings > Features > Custom Fields. Off means the module behaves as if it were not
			// installed: no post types or taxonomies registered from its definitions, no metaboxes,
			// no REST routes, no abilities, no dashboard data. Stored definitions and values are
			// left untouched, so switching it back on restores everything.
			if ( function_exists( 'uich_feature_enabled' ) && ! uich_feature_enabled( 'custom_fields' ) ) {
				return;
			}

			$this->define_constants();

			// Its only hook is admin_enqueue_scripts, so a public request never needs it.
			$dashboard = UICH_FIELDS_DIR . 'class-uich-fields-dashboard.php';
			if ( is_admin() && file_exists( $dashboard ) ) {
				require_once $dashboard;
				Uich_Fields_Dashboard::init();
			}

			define( 'UICH_FIELDS_BOOTED', true );

			/*
			 * No migration from another plugin's data: UiChemy's Fields module is independent and only
			 * ever reads its own storage. Identifiers another plugin uses may belong to that plugin's
			 * LIVE data, and a migration keyed only on a name could not tell the difference.
			 */
			$this->boot_runtime();
		}

		/**
		 * Define the three constants the module reads, plus the build URL.
		 *
		 * @return void
		 */
		private function define_constants() {
			if ( ! defined( 'UICH_FIELDS_DIR' ) ) {
				define( 'UICH_FIELDS_DIR', UICH_PATH . self::DIR );
			}
			if ( ! defined( 'UICH_FIELDS_URL' ) ) {
				define( 'UICH_FIELDS_URL', UICH_URL . self::DIR );
			}
			if ( ! defined( 'UICH_FIELDS_VER' ) ) {
				define( 'UICH_FIELDS_VER', UICH_VERSION );
			}
			// The React bundle is the dashboard's, not the module's.
			if ( ! defined( 'UICH_FIELDS_BUILD_URL' ) ) {
				define( 'UICH_FIELDS_BUILD_URL', UICH_URL . 'new-dashboard/build/' );
			}

			/*
			 * The module's REST namespace, defined in ONE place.
			 *
			 * Four REST classes, the ability dispatcher, the import manager and the dashboard config
			 * all name it; before this they each held their own literal, so renaming it meant editing
			 * eight files and any one of them could be missed — which is how a route ends up answering
			 * on a namespace nothing calls.
			 *
			 * It is a hard separation from the other Fields module on this site, which serves
			 * `content-types/v1`. Both can register, and whichever wins is not something either
			 * controls, so they must not share a namespace at all.
			 *
			 * Also defined (guarded) at the top of content-types/uich-fields-bootstrap.php, because
			 * the REST classes read it in a class-constant expression — a context that fatals rather
			 * than warns if the constant is absent — and the bootstrap is reachable without the loader.
			 */
			if ( ! defined( 'UICH_FIELDS_REST_NS' ) ) {
				define( 'UICH_FIELDS_REST_NS', 'uichemy-fields/v1' );
			}
		}

		/**
		 * Require the module and its abilities.
		 *
		 * The abilities layer registers lazily — nothing inside it exists until something reads the
		 * registry — so it is required here but never invoked here. Any entry point that touches a
		 * function or constant from `abilities/` must call `wp_get_abilities()` first.
		 *
		 * @return void
		 */
		private function boot_runtime() {
			$base = UICH_FIELDS_DIR;

			// The Free/Pro tier map, required FIRST and unconditionally: the REST save path, the
			// metabox and the value API all consult it, and a missing gate would read as "nothing is
			// locked" rather than failing loudly.
			require_once $base . 'uich-fields-pro-gate.php';

			// The module's own bootstrap owns the require order for the 29 content-types files.
			$bootstrap = $base . 'content-types/uich-fields-bootstrap.php';
			if ( file_exists( $bootstrap ) ) {
				require_once $bootstrap;
			}

			$this->boot_abilities( $base );
		}

		/**
		 * Boot the abilities layer.
		 *
		 * Core's Abilities API arrived in WP 6.9; without it there is nothing to register into, and
		 * the layer is simply absent rather than broken.
		 *
		 * The journal is loaded here rather than with the ability files because its installer has to
		 * reach `admin_init` whether or not anything reads the registry on this request — abilities
		 * register lazily, so a request that never touches them would otherwise never install the
		 * table.
		 *
		 * @param string $base UICH_FIELDS_DIR.
		 * @return void
		 */
		private function boot_abilities( $base ) {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			/**
			 * Turn the Fields abilities off without deactivating the plugin.
			 *
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'uichemy_fields_abilities_enabled', true ) ) {
				return;
			}

			$journal = $base . 'abilities/class-uich-fields-ability-journal.php';
			if ( file_exists( $journal ) ) {
				require_once $journal;
				Uich_Fields_Ability_Journal::init();
			}

			$abilities = $base . 'abilities/class-uich-fields-mcp-abilities.php';
			if ( file_exists( $abilities ) ) {
				require_once $abilities;
				// Declaring the class is not registering it — the registry hooks hang off instance().
				Uich_Fields_MCP_Abilities::instance();
			}
		}

	}

	Uich_Fields_Loader::get_instance();
}
