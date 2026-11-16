<?php
/**
 * Code Mode as WordPress abilities, for every MCP gateway.
 *
 * UiChemy's own endpoints serve Code Mode as three MCP tools. Other gateways
 * (the MCP Adapter's default server, third-party MCP plugins, the core abilities
 * REST API)
 * see WordPress abilities instead, and used to find UiChemy's 23 per-feature
 * abilities there - the v2 surface, one call at a time, with no scripts, dry
 * runs, undo or budgets. While Code Mode is on:
 *
 *   - the same three tools are registered as abilities,
 *       uichemy-composer/get-instructions, uichemy-composer/search, uichemy-composer/execute,
 *     running the very same handlers (UiChemy_MCP_Server_V3), so a gateway gets
 *     Code Mode with all of its checks;
 *   - the per-feature abilities are hidden from gateways (meta.mcp.public and
 *     show_in_rest off) but stay registered, marked meta.uichemy.served, so
 *     UiChemy's own endpoints and Code Mode itself keep using them.
 *
 * With Code Mode switched off nothing here runs: the per-feature abilities are
 * public as before (v2).
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Abilities' ) ) {

	/**
	 * Registers the Code Mode abilities and hides the per-feature ones from gateways.
	 */
	final class UiChemy_Code_Mode_Abilities {

		const INSTRUCTIONS = 'uichemy-composer/get-instructions';
		const SEARCH       = 'uichemy-composer/search';
		const EXECUTE      = 'uichemy-composer/execute';

		/**
		 * @return array<int,string> The Code Mode abilities.
		 */
		public static function names() {
			return array( self::INSTRUCTIONS, self::SEARCH, self::EXECUTE );
		}

		/**
		 * Hook in, while Code Mode is on.
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'UiChemy_Code_Mode' ) || ! UiChemy_Code_Mode::is_enabled() ) {
				return;
			}
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 20 );
			add_filter( 'wp_register_ability_args', array( __CLASS__, 'hide_from_gateways' ), 999, 2 );
		}

		/**
		 * A per-feature UiChemy ability: kept for UiChemy's own endpoints, hidden from the rest.
		 *
		 * @param array  $args Ability args.
		 * @param string $name Ability name.
		 * @return array
		 */
		public static function hide_from_gateways( $args, $name ) {
			if ( ! is_array( $args ) || 0 !== strpos( (string) $name, UiChemy_MCP_Server_V2::NAMESPACE_SLUG . '/' ) || in_array( $name, self::names(), true ) ) {
				return $args;
			}
			$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
			if ( empty( $meta['mcp']['public'] ) ) {
				return $args;
			}
			$meta['uichemy']['served'] = true;
			$meta['mcp']['public']     = false;
			$meta['show_in_rest']      = false;
			$args['meta']              = $meta;
			return $args;
		}

		/**
		 * Register the three abilities.
		 */
		public static function register() {
			$specs = array(
				self::INSTRUCTIONS => array(
					'label'       => 'UiChemy Code Mode: get instructions',
					'description' => 'Call this first, once. Returns how to write a script for UiChemy Code Mode (steps, an example, rules and limits), then what UiChemy is and how it builds WordPress sites here, with every UiChemy class and method.',
					'input'       => array(
						'type'       => 'object',
						'properties' => new stdClass(),
					),
					'callback'    => array( 'UiChemy_MCP_Server_V3', 'handle_get_instructions' ),
					'readonly'    => true,
				),
				self::SEARCH       => array(
					'label'       => 'UiChemy Code Mode: search',
					'description' => 'Find the UiChemy methods that do something, by keyword (e.g. "seo audit page"), as ready-to-use PHP signatures. An exact name such as "Page::grep" returns its full definition.',
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'What you want to do, in a few words, or exact names such as "Page::grep, Seo::auditPageSeo".',
							),
							'limit' => array(
								'type'        => 'integer',
								'description' => 'Most results to return, 1 to 50. Default 16.',
							),
						),
					),
					'callback'    => array( 'UiChemy_MCP_Server_V3', 'handle_search' ),
					'readonly'    => true,
				),
				self::EXECUTE      => array(
					'label'       => 'UiChemy Code Mode: execute',
					'description' => 'Run one PHP script that calls UiChemy methods and get back only what it returns. For a script that changes the site, run it with dry_run: true first. A run that changed pages or posts answers with undo.token; send undo instead of code to put them back.',
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'code'         => array(
								'type'        => 'string',
								'description' => 'The script body, without <?php. End with `return <value>;`.',
							),
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'Preview: reads run for real, and each write returns what it would do instead of doing it. Default false.',
							),
							'allow_delete' => array(
								'type'        => 'boolean',
								'description' => 'Let the script\'s delete calls run (permanent, at most 5). Only after the user has agreed to exactly what will be deleted. Default false.',
							),
							'undo'         => array(
								'type'        => 'string',
								'description' => 'Undo a previous run: its undo.token. Send it instead of code.',
							),
						),
					),
					'callback'    => array( 'UiChemy_MCP_Server_V3', 'handle_execute' ),
					'readonly'    => false,
				),
			);

			foreach ( $specs as $name => $spec ) {
				if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
					continue;
				}
				wp_register_ability(
					$name,
					array(
						'label'               => $spec['label'],
						'description'         => $spec['description'],
						'category'            => UiChemy_MCP_Server_V2::NAMESPACE_SLUG,
						'input_schema'        => $spec['input'],
						'execute_callback'    => static function ( $input = array() ) use ( $spec ) {
							return call_user_func( $spec['callback'], is_array( $input ) ? $input : array() );
						},
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'meta'                => array(
							'show_in_rest' => true,
							'mcp'          => array( 'public' => true ),
							'annotations'  => array(
								'readonly'    => $spec['readonly'],
								'destructive' => ! $spec['readonly'],
								'idempotent'  => $spec['readonly'],
							),
						),
					)
				);
			}
		}

		/**
		 * The rule every UiChemy ability uses: an administrator, with the MCP server on.
		 *
		 * @return bool
		 */
		public static function permission_check() {
			if ( class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'permission_check' ) ) {
				return (bool) UiChemy_Abilities::permission_check();
			}
			return current_user_can( 'manage_options' );
		}
	}
}
