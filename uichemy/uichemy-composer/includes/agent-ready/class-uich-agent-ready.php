<?php
/**
 * Agent Ready: make a site usable by AI agents.
 *
 * One Pro module with its own settings area. When it is on, the site gets an
 * agent file (Markdown) for every public URL, an llms.txt written for action,
 * discovery files and headers, and a public site MCP that visitors' AI agents
 * can connect to. Everything runs on the site itself; nothing is sent to or
 * served from POSIMYTH.
 *
 * Boot order:
 * 1. Pro only. Free builds show the locked tab (uichemy_pro_feature_map()).
 * 2. Off by default on every site (opt-in): the owner turns it on in
 *    Settings > Features. First boot stores that off switch.
 * 3. Settings > Features > Agent Ready is the master switch. Off behaves like a
 *    deactivated plugin: no routes, no files served, no abilities.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Ready' ) ) {

	/**
	 * Module bootstrap.
	 */
	class Uich_Agent_Ready {

		/**
		 * Module version.
		 */
		const VERSION = '0.1.0-dev';

		/**
		 * Feature slug in uich_features().
		 */
		const FEATURE = 'agent_ready';

		/**
		 * Whether init() already ran.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Boot the module.
		 *
		 * @return void
		 */
		public static function init() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			/**
			 * Return false to stop Agent Ready from loading at all.
			 *
			 * @param bool $load Whether to load.
			 */
			if ( ! apply_filters( 'uichemy_agent_ready_load', true ) ) {
				return;
			}

			if ( ! function_exists( 'uichemy_is_pro' ) || ! uichemy_is_pro() ) {
				return;
			}

			$dir = __DIR__ . '/';
			require_once $dir . 'class-uich-agent-store.php';

			// Older versions kept the configuration in files: move it into the options once.
			Uich_Agent_Store::maybe_migrate();

			self::maybe_set_default();

			if ( function_exists( 'uich_feature_enabled' ) && ! uich_feature_enabled( self::FEATURE ) ) {
				// Module off: keep only the admin-only status + read settings routes so the
				// dashboard can show an "off" state instead of a rest_no_route error.
				// Nothing public (llms.txt, .md, /mcp, discovery) is loaded.
				require_once $dir . 'class-uich-agent-settings.php';
				require_once $dir . 'class-uich-agent-status.php';
				require_once $dir . 'class-uich-agent-rest.php';
				Uich_Agent_Rest::$limited = true;
				Uich_Agent_Rest::init();
				return;
			}

			require_once $dir . 'class-uich-agent-settings.php';
			require_once $dir . 'class-uich-agent-status.php';
			require_once $dir . 'class-uich-agent-rest.php';
			require_once $dir . 'class-uich-agent-ability.php';
			require_once $dir . 'class-uich-agent-urls.php';
			require_once $dir . 'class-uich-agent-access.php';
			require_once $dir . 'class-uich-agent-limiter.php';
			require_once $dir . 'class-uich-agent-convert.php';
			require_once $dir . 'class-uich-agent-pages.php';
			require_once $dir . 'class-uich-agent-page-actions.php';
			require_once $dir . 'class-uich-agent-log.php';
			require_once $dir . 'class-uich-agent-tools.php';
			require_once $dir . 'class-uich-agent-builtin-tools.php';
			require_once $dir . 'class-uich-agent-mcp.php';
			require_once $dir . 'class-uich-agent-tool-actions.php';
			require_once $dir . 'class-uich-agent-integrations.php';
			require_once $dir . 'class-uich-agent-audit.php';
			require_once $dir . 'class-uich-agent-llms.php';
			require_once $dir . 'class-uich-agent-discovery.php';
			require_once $dir . 'class-uich-agent-profile.php';
			require_once $dir . 'class-uich-agent-migrate.php';
			require_once $dir . 'class-uich-agent-forms.php';
			require_once $dir . 'class-uich-agent-inbox.php';

			Uich_Agent_Rest::init();
			Uich_Agent_Ability::init();
			Uich_Agent_Log::init();
			Uich_Agent_Page_Actions::init();
			Uich_Agent_Tool_Actions::init();
			Uich_Agent_Integrations::init();
			Uich_Agent_Audit::init();
			Uich_Agent_Profile::init();
			Uich_Agent_Migrate::init();
			Uich_Agent_Forms::init();
			Uich_Agent_Inbox::init();
			// The site MCP, llms.txt and discovery files answer and exit before
			// agent pages look at the request.
			Uich_Agent_MCP::init();
			Uich_Agent_Llms::init();
			Uich_Agent_Discovery::init();
			Uich_Agent_Pages::init();
		}

		/**
		 * On first boot, store the master switch as off so Settings > Features shows
		 * the real state. Agent Ready publishes llms.txt, Markdown pages and browser
		 * AI tools, so it is always the site owner's choice to turn it on. A switch
		 * already stored is never touched.
		 *
		 * @return void
		 */
		private static function maybe_set_default() {
			$state = Uich_Agent_Store::state();
			if ( array_key_exists( 'enabled', $state ) ) {
				return;
			}

			Uich_Agent_Store::update_state(
				array(
					'enabled'    => false,
					'decided_at' => time(),
					'decided_as' => 'default-off',
				)
			);
		}

		/**
		 * Whether the module is switched on.
		 *
		 * @return bool
		 */
		public static function enabled() {
			return function_exists( 'uich_feature_enabled' ) ? uich_feature_enabled( self::FEATURE ) : ! empty( Uich_Agent_Store::state()['enabled'] );
		}
	}
}
