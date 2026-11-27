<?php
/**
 * Agent Ready ability actions for site MCP tools: the lifecycle.
 *
 * Find (scan) -> draft (save-tool, always saved disabled) -> test (test-tool,
 * runs as an anonymous visitor) -> review -> enable (set-tool-state).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Tool_Actions' ) ) {

	/**
	 * Tool lifecycle actions.
	 */
	class Uich_Agent_Tool_Actions {

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
		}

		/**
		 * Add the actions.
		 *
		 * @param array $actions Existing actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$name = array(
				'type'        => 'string',
				'description' => 'Tool name (lower-case letters, digits, underscores).',
			);

			$actions['list-tool-types'] = array(
				'description' => 'The four kinds of tool a site can offer visitors\' AI (in their browser), with the config each needs and an example. Read this before save-tool.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'list_types' ),
			);
			$actions['list-tools']      = array(
				'description' => 'Every tool the site offers visitors\' AI: built-in ones and saved definitions, with type and on/off state.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'list_tools' ),
			);
			$actions['get-tool']        = array(
				'description' => 'One saved tool definition with the input schema agents see.',
				'params'      => array( 'name' => $name ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_tool' ),
			);
			$actions['save-tool']       = array(
				'description' => 'Create or replace a tool definition: { name, title, description, type, config }. It is always saved switched OFF. Test it with test-tool; the site owner switches it on in Agent Ready > Setup > step 4.',
				'params'      => array(
					'tool' => array(
						'type'        => 'object',
						'description' => 'The definition. See list-tool-types for config by type.',
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'save_tool' ),
			);
			$actions['set-tool-state']  = array(
				'description' => 'Switch a saved tool on or off for visitors\' AI. Only switch a tool on after the site owner has agreed.',
				'params'      => array(
					'name'    => $name,
					'enabled' => array( 'type' => 'boolean' ),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'set_state' ),
			);
			$actions['delete-tool']     = array(
				'description' => 'Delete a saved tool definition (the previous version is kept in the history).',
				'params'      => array( 'name' => $name ),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'delete_tool' ),
			);
			$actions['test-tool']       = array(
				'description' => 'Run a tool exactly as an anonymous visitor\'s agent would, even if it is switched off, and return its output. Use it to check that nothing private appears before enabling.',
				'params'      => array(
					'name'      => $name,
					'arguments' => array(
						'type'        => 'object',
						'description' => 'Tool arguments.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'test_tool' ),
			);
			return $actions;
		}

		/**
		 * Action: list-tool-types.
		 *
		 * @return array
		 */
		public static function list_types() {
			return array(
				'types' => array(
					'collection' => array(
						'use_for' => 'Any plugin that stores its data as posts: events, courses, services, team, listings, portfolio.',
						'config'  => 'post_type (public), fields (title,url,agent_url,excerpt,content,date,modified,image,author), taxonomies (filters), meta (list of {key,label}; keys that look private are refused), orderby, order, per_page.',
						'example' => array(
							'name'        => 'search_events',
							'title'       => 'Search events',
							'description' => 'Find upcoming events by keyword or category, with dates and links.',
							'type'        => 'collection',
							'config'      => array(
								'post_type'  => 'tribe_events',
								'fields'     => array( 'title', 'url', 'excerpt' ),
								'taxonomies' => array( 'tribe_events_cat' ),
								'meta'       => array(
									array(
										'key'   => '_EventStartDate',
										'label' => 'starts',
									),
								),
								'orderby'    => 'date',
							),
						),
					),
					'ability'    => array(
						'use_for' => 'A plugin (or a Module Studio module) that registers a WordPress ability anonymous visitors may call.',
						'config'  => 'ability (name), output (optional list of dotted fields to keep), allow_write (only for abilities that change data; needs the owner\'s agreement).',
					),
					'rest'       => array(
						'use_for' => 'A plugin with a public REST GET route.',
						'config'  => 'route, params ({name:{type,description,required,enum,maxLength}}), fixed (pinned query args), output (dotted fields to keep).',
						'example' => array(
							'name'        => 'store_products',
							'description' => 'Products from the store API.',
							'type'        => 'rest',
							'config'      => array(
								'route'  => '/wc/store/v1/products',
								'params' => array(
									'search' => array(
										'type'        => 'string',
										'description' => 'Words to search for.',
									),
								),
								'fixed'  => array( 'per_page' => '10' ),
								'output' => array( 'id', 'name', 'permalink', 'prices.price', 'is_in_stock' ),
							),
						),
					),
					'link'       => array(
						'use_for' => 'Hand-offs that change nothing: a booking page for a service, a contact form, a quote page.',
						'config'  => 'url (pattern on this site with {placeholders}), params (one per placeholder).',
						'example' => array(
							'name'        => 'booking_page_link',
							'description' => 'Link to the booking page for a service.',
							'type'        => 'link',
							'config'      => array(
								'url'    => '/book/?service={service}',
								'params' => array(
									'service' => array(
										'type'     => 'string',
										'enum'     => array( 'haircut', 'colour' ),
										'required' => true,
									),
								),
							),
						),
					),
				),
				'rules' => 'Every tool runs as an anonymous visitor. Saved tools start switched off. Nothing is code: a definition only fills in one of these types.',
			);
		}

		/**
		 * Action: list-tools.
		 *
		 * @return array
		 */
		public static function list_tools() {
			$off      = (array) Uich_Agent_Settings::value( 'mcp.disabled_builtins', array() );
			$builtin  = array();
			$all      = Uich_Agent_Builtin_Tools::tools( true );
			$switches = array_merge( Uich_Agent_Builtin_Tools::names(), array( 'check_open_now' ) );
			if ( ! isset( $all['check_open_now'] ) && in_array( 'check_open_now', $off, true ) ) {
				$all['check_open_now'] = array(
					'title'  => 'Check if open now',
					'source' => 'builtin',
				);
			}
			// The request tool is offered only while requests are on; list it anyway,
			// so Setup can switch it on.
			if ( ! isset( $all['send_contact_request'] ) && class_exists( 'Uich_Agent_Inbox' ) ) {
				$all['send_contact_request'] = array(
					'title'  => 'Send a contact request',
					'source' => 'builtin',
					'write'  => true,
				);
			}
			foreach ( $all as $name => $tool ) {
				$source    = isset( $tool['source'] ) ? $tool['source'] : 'builtin';
				$managed   = 'form' === $source ? 'forms' : ( 'send_contact_request' === $name ? 'inbox' : '' );
				$builtin[] = array(
					'name'       => $name,
					'title'      => isset( $tool['title'] ) ? $tool['title'] : $name,
					// The request tool follows the requests switch (mcp.inbox.enabled).
					'enabled'    => 'send_contact_request' === $name ? Uich_Agent_Inbox::enabled() : ! in_array( $name, $off, true ),
					// Core tools switch with mcp.disabled_builtins; form and inbox tools
					// are switched where they are set up.
					'switchable' => in_array( $name, $switches, true ),
					'managed_by' => $managed,
					'write'      => ! empty( $tool['write'] ) || ( isset( $tool['annotations']['readOnlyHint'] ) && false === $tool['annotations']['readOnlyHint'] ),
				);
			}
			// Tools a module adds in code (uichemy_agent_ready_tools), e.g. one built with
			// Module Studio. They are on while their module is on.
			$known = array_merge( array_keys( $all ), array_keys( Uich_Agent_Tools::definitions() ) );
			foreach ( Uich_Agent_Tools::enabled() as $name => $tool ) {
				if ( in_array( $name, $known, true ) ) {
					continue;
				}
				$builtin[] = array(
					'name'       => $name,
					'title'      => isset( $tool['title'] ) ? $tool['title'] : $name,
					'enabled'    => true,
					'switchable' => false,
					'managed_by' => 'module',
					'write'      => ! empty( $tool['write'] ) || ( isset( $tool['annotations']['readOnlyHint'] ) && false === $tool['annotations']['readOnlyHint'] ),
				);
			}
			$saved = array();
			foreach ( Uich_Agent_Tools::definitions() as $name => $def ) {
				$valid   = Uich_Agent_Tools::validate( $def );
				$saved[] = array(
					'name'    => $name,
					'title'   => isset( $def['title'] ) ? $def['title'] : $name,
					'type'    => isset( $def['type'] ) ? $def['type'] : '',
					'enabled' => ! empty( $def['enabled'] ),
					'valid'   => ! is_wp_error( $valid ),
					'problem' => is_wp_error( $valid ) ? $valid->get_error_message() : '',
				);
			}
			return array(
				'mcp_url'  => Uich_Agent_MCP::public_server() ? Uich_Agent_MCP::url() : '',
				'enabled'  => (bool) Uich_Agent_Settings::value( 'mcp.enabled', true ),
				'built_in' => $builtin,
				'saved'    => $saved,
			);
		}

		/**
		 * Action: get-tool.
		 *
		 * @param array $params { name }.
		 * @return array|WP_Error
		 */
		public static function get_tool( array $params ) {
			$defs = Uich_Agent_Tools::definitions();
			$name = isset( $params['name'] ) ? (string) $params['name'] : '';
			if ( ! isset( $defs[ $name ] ) ) {
				$all = Uich_Agent_Builtin_Tools::tools( true );
				if ( isset( $all[ $name ] ) ) {
					return array(
						'tool'         => array(
							'name'        => $name,
							'title'       => isset( $all[ $name ]['title'] ) ? $all[ $name ]['title'] : $name,
							'description' => isset( $all[ $name ]['description'] ) ? $all[ $name ]['description'] : '',
							'type'        => 'built-in',
						),
						'input_schema' => isset( $all[ $name ]['input_schema'] ) ? $all[ $name ]['input_schema'] : null,
						'problem'      => '',
					);
				}
				return new WP_Error( 'uich_agent_no_tool', sprintf( 'No tool named "%s".', $name ) );
			}
			$valid = Uich_Agent_Tools::validate( $defs[ $name ] );
			return array(
				'tool'         => $defs[ $name ],
				'input_schema' => is_wp_error( $valid ) ? null : Uich_Agent_Tools::schema_for( $valid ),
				'problem'      => is_wp_error( $valid ) ? $valid->get_error_message() : '',
			);
		}

		/**
		 * Action: save-tool.
		 *
		 * @param array $params { tool }.
		 * @return array|WP_Error
		 */
		public static function save_tool( array $params ) {
			if ( empty( $params['tool'] ) || ! is_array( $params['tool'] ) ) {
				return new WP_Error( 'uich_agent_bad_tool', 'Pass "tool": { name, title, description, type, config }.' );
			}
			$saved = Uich_Agent_Tools::save( $params['tool'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return array(
				'saved' => $saved,
				'next'  => 'The tool is switched off. Run test-tool to see its output as an anonymous visitor, then ask the site owner before set-tool-state.',
			);
		}

		/**
		 * Action: set-tool-state.
		 *
		 * @param array $params { name, enabled }.
		 * @return array|WP_Error
		 */
		public static function set_state( array $params ) {
			$done = Uich_Agent_Tools::set_state( isset( $params['name'] ) ? (string) $params['name'] : '', ! empty( $params['enabled'] ) );
			return is_wp_error( $done ) ? $done : array( 'tool' => $done );
		}

		/**
		 * Action: delete-tool.
		 *
		 * @param array $params { name }.
		 * @return array|WP_Error
		 */
		public static function delete_tool( array $params ) {
			$done = Uich_Agent_Tools::delete( isset( $params['name'] ) ? (string) $params['name'] : '' );
			return is_wp_error( $done ) ? $done : array( 'deleted' => true );
		}

		/**
		 * Action: test-tool. Runs saved (even disabled) and built-in tools as user 0.
		 *
		 * @param array $params { name, arguments }.
		 * @return array
		 */
		public static function test_tool( array $params ) {
			$name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

			$defs = Uich_Agent_Tools::definitions();
			if ( isset( $defs[ $name ] ) && empty( $defs[ $name ]['enabled'] ) ) {
				// Temporarily include the disabled tool for this one call.
				$def        = $defs[ $name ];
				$def['enabled'] = true;
				$filter     = function ( $tools ) use ( $def ) {
					$valid = Uich_Agent_Tools::validate( $def );
					if ( ! is_wp_error( $valid ) ) {
						$tools[ $def['name'] ] = array(
							'name'         => $valid['name'],
							'title'        => $valid['title'],
							'description'  => $valid['description'],
							'input_schema' => Uich_Agent_Tools::schema_for( $valid ),
							'annotations'  => array(),
							'source'       => 'file',
							'write'        => false,
							'run'          => function ( $a ) use ( $valid ) {
								return Uich_Agent_Tools::run_definition( $valid, $a );
							},
						);
					}
					return $tools;
				};
				add_filter( 'uichemy_agent_ready_tools', $filter );
				Uich_Agent_Tools::flush();
				$result = Uich_Agent_Tools::call( $name, $args );
				remove_filter( 'uichemy_agent_ready_tools', $filter );
				Uich_Agent_Tools::flush();
			} else {
				$result = Uich_Agent_Tools::call( $name, $args );
			}

			return array(
				'tool'   => $name,
				'ok'     => ! is_wp_error( $result ),
				'output' => is_wp_error( $result ) ? $result->get_error_message() : $result,
				'note'   => 'This ran as an anonymous visitor: it is exactly what an agent would receive.',
			);
		}
	}
}
