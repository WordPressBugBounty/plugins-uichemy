<?php
/**
 * UiChemy MCP Server v3 - the Code Mode endpoint.
 *
 * Registers /wp-json/uichemy/v3/mcp with three tools instead of v2's
 * discover / inspect / execute triad:
 *
 *   - get_instructions  how to write a script for this server, then what UiChemy is
 *   - search            find the methods that do something, by keyword, as signatures;
 *                       an exact name ("Page::grep") returns its full definition
 *   - execute           one PHP script that calls many abilities; only its return value comes back
 *
 * It sits next to v2 and is built on it: the same abilities and actions
 * (UiChemy_MCP_Server_V2::served_abilities()), the same execute path, the same
 * admin gate and per-request setup. What v3 adds is the script: one PHP
 * script calls many abilities and only its result comes back. On by default;
 * UICHEMY_CODE_MODE false switches it off (see UiChemy_Code_Mode). Deletes run
 * only when a call asks with allow_delete, and changed posts can be undone.
 *
 * The same server also answers on /wp-json/uichemy/v1/mcp, the endpoint
 * removed in 5.5.0, so a client still configured for v1 connects again: it
 * lists the tools on connect and gets v3's. v1 goes straight to v3, not
 * through v2.
 *
 * v2 SUNSET: v2 (/wp-json/uichemy/v2/mcp, UiChemy_MCP_Server_V2) stays
 * supported as it is until 2026-12-12, one month after v3's release. After
 * that date, v2's endpoint is replaced by routing every v2 request to v3, the
 * way v1 is routed here (add 'uichemy/v2' to ALIASES and stop registering the
 * v2 server). The abilities v2 serves stay: v3 is built on them.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_MCP_Server_V3' ) ) {

	/**
	 * Registers the Code Mode MCP v3 server.
	 */
	class UiChemy_MCP_Server_V3 {

		const SERVER_ID      = 'uichemy-wordpress-mcp-v3';
		const SERVER_NAME    = 'UiChemy WordPress MCP v3 (Code Mode)';
		const SERVER_VERSION = '3.0.0-alpha';
		const REST_NAMESPACE = 'uichemy/v3';
		const REST_ROUTE     = 'mcp';

		/**
		 * Older endpoints this server also answers on, as server id => namespace.
		 * v2 joins this list after its sunset on 2026-12-12 (see the file header).
		 */
		const ALIASES = array(
			'uichemy-wordpress-mcp-v1' => 'uichemy/v1',
		);

		/** @var string The Mcp-Session-Id the client itself sent on this request, '' for none. */
		private static $client_session = '';

		const SERVER_DESCRIPTION = 'UiChemy Code Mode: write one PHP script that calls as many UiChemy methods as it needs, and get back only what it returns. Call `get_instructions` once, find methods with `search`, then `execute` the script.';

		/**
		 * Hook server registration onto the MCP Adapter.
		 */
		public static function init() {
			add_action( 'mcp_adapter_init', array( __CLASS__, 'register_mcp_server' ) );
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'prime_request' ), 10, 3 );
		}

		/**
		 * v2's per-request setup on this route too: the builder pin
		 * (?builder= or X-UiChemy-Builder) and a relay session for a client that
		 * sends no Mcp-Session-Id.
		 *
		 * @param mixed           $result  Pre-dispatch result.
		 * @param WP_REST_Server  $server  Server.
		 * @param WP_REST_Request $request Request.
		 * @return mixed
		 */
		public static function prime_request( $result, $server, $request ) {
			if ( $request instanceof \WP_REST_Request && self::is_own_route( (string) $request->get_route() ) ) {
				// Before v2's setup adds a relay session for a client that sent none.
				self::$client_session = (string) $request->get_header( 'Mcp-Session-Id' );
				UiChemy_MCP_Server_V2::prime_mcp_request( $request );
			}
			return $result;
		}

		/**
		 * Is this route v3's, or one of the older endpoints it answers for?
		 *
		 * @param string $route REST route.
		 * @return bool
		 */
		private static function is_own_route( $route ) {
			foreach ( array_merge( array( self::REST_NAMESPACE ), array_values( self::ALIASES ) ) as $namespace ) {
				if ( 0 === strpos( $route, '/' . $namespace . '/' . self::REST_ROUTE ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Who a run belongs to, for pairing a dry run with the real run after
		 * it: the user, and the MCP session when the client keeps one.
		 *
		 * @return string
		 */
		public static function session_key() {
			return get_current_user_id() . ':' . self::$client_session;
		}

		/**
		 * Register the v3 server on our scoped adapter instance only.
		 *
		 * @param \UiChemy\Deps\WP\MCP\Core\McpAdapter $adapter The MCP Adapter instance.
		 */
		public static function register_mcp_server( $adapter ) {
			if ( ! $adapter instanceof \UiChemy\Deps\WP\MCP\Core\McpAdapter ) {
				return;
			}

			$code_mode = UiChemy_Code_Mode::is_enabled();
			foreach ( array_merge( array( self::SERVER_ID => self::REST_NAMESPACE ), self::ALIASES ) as $server_id => $namespace ) {
				// Code Mode switched off: the same URLs answer with v2's tools, so a
				// client already set up with the v3 URL keeps working.
				if ( ! $code_mode ) {
					UiChemy_MCP_Server_V2::register_on( $adapter, $server_id, $namespace );
					continue;
				}
				$adapter->create_server(
					$server_id,
					$namespace,
					self::REST_ROUTE,
					self::SERVER_NAME,
					self::SERVER_DESCRIPTION,
					self::SERVER_VERSION,
					array( \UiChemy\Deps\WP\MCP\Transport\HttpTransport::class ),
					\UiChemy\Deps\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\UiChemy\Deps\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					self::tools(),
					array(),
					array(),
					array( 'UiChemy_MCP_Server_V2', 'check_permission' )
				);
			}
		}

		/**
		 * @return array<int,\UiChemy\Deps\WP\MCP\Domain\Tools\McpTool>
		 */
		private static function tools() {
			$read_only = array(
				'readOnlyHint'   => true,
				'idempotentHint' => true,
			);

			$specs = array(
				array(
					'name'        => 'get_instructions',
					'description' => 'Call this first, once. Returns how to write a script for this server (steps, an example, rules and limits), then what UiChemy is and how it builds WordPress sites.',
					'inputSchema' => array( 'type' => 'object' ),
					'handler'     => array( __CLASS__, 'handle_get_instructions' ),
					'annotations' => $read_only,
				),
				array(
					'name'        => 'search',
					'description' => 'Find the methods for a task by keyword, e.g. "seo audit page" or "list pages". Each result is a full PHP signature you can use in an `execute` script, with a one-line summary and whether it reads or writes. An exact name such as "Page::grep" returns that method\'s full definition: what each parameter does and its allowed values. No query returns every class and method.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'What you want to do, in a few words, or exact names such as "Page::grep, Seo::auditPageSeo" for their parameter details. Leave out for the whole list.',
							),
							'limit' => array(
								'type'        => 'integer',
								'description' => 'Most results to return, 1 to 50. Default 16.',
							),
						),
					),
					'handler'     => array( __CLASS__, 'handle_search' ),
					'annotations' => $read_only,
				),
				array(
					'name'        => 'execute',
					'description' => 'Run one PHP script that calls UiChemy methods and get back only what it returns. Example: $hits = Page::grep(pattern: "old.example/cta", max_results: 500); return count($hits[\'matches\'] ?? []); On an error, the answer gives the line, the message and the correct signatures to fix it. For a script that changes the site, run it with dry_run: true first. A run that changed pages or posts answers with undo.token; send undo instead of code to put them back.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'code'    => array(
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
								'description' => 'Undo a previous run: its undo.token. Puts back the pages and posts it changed (within 24 hours). Send it instead of code.',
							),
						),
					),
					'handler'     => array( __CLASS__, 'handle_execute' ),
					'annotations' => UiChemy_Code_Mode_Dispatcher::writes_enabled()
						? array(
							'readOnlyHint'    => false,
							'destructiveHint' => true,
							'openWorldHint'   => false,
						)
						: array(
							'readOnlyHint'  => true,
							'openWorldHint' => false,
						),
				),
			);

			$tools = array();
			foreach ( $specs as $spec ) {
				$tool = \UiChemy\Deps\WP\MCP\Domain\Tools\McpTool::fromArray(
					array_merge( $spec, array( 'permission' => array( 'UiChemy_MCP_Server_V2', 'default_tool_permission' ) ) )
				);
				if ( $tool instanceof \UiChemy\Deps\WP\MCP\Domain\Tools\McpTool ) {
					$tools[] = $tool;
				}
			}

			return $tools;
		}

		/**
		 * @return array
		 */
		public static function handle_get_instructions() {
			return UiChemy_Code_Mode::instructions();
		}

		/**
		 * @param array $arguments { query?: string, limit?: int }.
		 * @return array
		 */
		public static function handle_search( $arguments = array() ) {
			$arguments = is_array( $arguments ) ? $arguments : array();

			return UiChemy_Code_Mode::search(
				isset( $arguments['query'] ) && is_scalar( $arguments['query'] ) ? (string) $arguments['query'] : '',
				isset( $arguments['limit'] ) && is_numeric( $arguments['limit'] ) ? (int) $arguments['limit'] : 16
			);
		}

		/**
		 * A boolean argument. The adapter does not enforce the input schema, so
		 * a client can send "false" or 0, and ! empty( "false" ) is true. Only a
		 * clear yes counts as yes.
		 *
		 * @param mixed $value Argument.
		 * @return bool|null True or false, or null when it is neither.
		 */
		private static function flag( $value ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_int( $value ) || ( is_string( $value ) && '' !== trim( $value ) ) ) {
				$text = strtolower( trim( (string) $value ) );
				if ( in_array( $text, array( '1', 'true', 'yes' ), true ) ) {
					return true;
				}
				if ( in_array( $text, array( '0', 'false', 'no' ), true ) ) {
					return false;
				}
			}
			return null === $value || '' === $value ? false : null;
		}

		/**
		 * Errors come back as data (error.kind, message, line and help), not as
		 * a tool error, so the model can read the line and fix the script.
		 *
		 * @param array $arguments { code?: string, dry_run?: bool, allow_delete?: bool, undo?: string }.
		 * @return array
		 */
		public static function handle_execute( $arguments = array() ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			foreach ( array( 'code', 'undo' ) as $name ) {
				if ( isset( $arguments[ $name ] ) && ! is_string( $arguments[ $name ] ) ) {
					return new WP_Error( 'uichemy_code_mode_bad_argument', sprintf( '%s must be a string.', $name ) );
				}
			}
			$code = isset( $arguments['code'] ) ? $arguments['code'] : '';
			$undo = isset( $arguments['undo'] ) ? trim( $arguments['undo'] ) : '';

			// A delete runs only on a clear yes. A dry run is the safe side, so
			// anything that is not a clear no keeps it on.
			$allow_delete = true === self::flag( $arguments['allow_delete'] ?? false );
			$dry_run      = false !== self::flag( $arguments['dry_run'] ?? false );

			if ( '' !== $undo ) {
				if ( '' !== trim( $code ) ) {
					return new WP_Error( 'uichemy_code_mode_undo_and_code', 'Send either code or undo, not both.' );
				}
				// An undo rewrites posts: not while the site owner has made scripts read-only.
				if ( ! UiChemy_Code_Mode_Dispatcher::writes_enabled() ) {
					return new WP_Error( 'uichemy_code_mode_read_only', 'Undo changes the site, and the site owner has made scripts read-only (UICHEMY_CODE_MODE_WRITES is false). Tell the user.' );
				}
				return UiChemy_Code_Mode::undo( $undo );
			}
			if ( '' === trim( $code ) ) {
				return new WP_Error( 'uichemy_code_mode_missing_code', 'Missing required parameter: code (or undo).' );
			}

			return UiChemy_Code_Mode::for_model( UiChemy_Code_Mode::run( $code, $dry_run, $allow_delete ) );
		}
	}
}
