<?php
/**
 * Site MCP tools: the registry, the four definition types and the runner.
 *
 * A tool is either built in (code in Uich_Agent_Builtin_Tools) or a JSON
 * definition file in the private folder, tools/{name}/tool.json. A definition
 * never contains code: it fills in one of four fixed, reviewed types.
 *
 *   collection  a public post type, with the fields and filters agents may use
 *   ability     a registered WordPress ability, called as an anonymous visitor
 *   rest        a public REST GET route, called as an anonymous visitor
 *   link        a same-site URL pattern with typed inputs (changes nothing)
 *
 * Every tool runs as user 0. Inputs are checked against the tool's schema,
 * outputs are reduced to plain text and capped. New or changed definitions are
 * saved disabled; enabling is a separate step. Definition files are hash-checked
 * by the store, so a planted or edited file is never loaded.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Tools' ) ) {

	/**
	 * Tool registry and runner.
	 */
	class Uich_Agent_Tools {

		/**
		 * Definition types.
		 */
		const TYPES = array( 'collection', 'ability', 'rest', 'link' );

		/**
		 * Largest tool result, in bytes of JSON.
		 */
		const MAX_BYTES = 100000;

		/**
		 * Post types that are never exposed through a collection.
		 */
		const BLOCKED_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription', 'uichemy_template', 'elementor_library', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'e-floating-buttons', 'bricks_template', 'nxt_builder', 'jet-theme-core', 'jet-popup', 'jet-engine', 'jet-woo-builder', 'jet-menu', 'kadence_element', 'kadence_wootemplate', 'fl-builder-template', 'ct_template', 'oxy_user_library', 'et_pb_layout', 'astra-advanced-hook', 'gp_elements', 'llms_my_certificate', 'llms_my_achievement' );

		/**
		 * Whether a post type is left out of agent listings (llms files, search):
		 * always for the blocked types; for a public type the site itself keeps out
		 * of search (builder templates, course lessons, certificates), unless the
		 * owner chose it under agent pages > post types.
		 *
		 * @param string $type Post type.
		 * @return bool
		 */
		public static function listing_blocked( $type ) {
			if ( ! in_array( $type, self::candidate_types(), true ) ) {
				return true;
			}
			$included = (array) Uich_Agent_Settings::value( 'agent_pages.post_types', array() );
			return $included && ! in_array( $type, $included, true );
		}

		/**
		 * Post types that can have agent pages at all: public, not blocked, and not
		 * kept out of the site's own search (builder templates, course lessons,
		 * certificates) unless the owner listed them under agent pages > post types.
		 * The dashboard's per-type switches choose from these.
		 *
		 * @return string[]
		 */
		public static function candidate_types() {
			$included = (array) Uich_Agent_Settings::value( 'agent_pages.post_types', array() );
			$out      = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
				if ( in_array( $name, self::BLOCKED_TYPES, true ) ) {
					continue;
				}
				if ( ! empty( $obj->exclude_from_search ) && ! in_array( $name, $included, true ) ) {
					continue;
				}
				$out[] = $name;
			}
			return $out;
		}

		/**
		 * Meta keys that must never be exposed, whatever an owner lists.
		 */
		const SENSITIVE_META = '/(pass|pwd|secret|token|api[_-]?key|private|email|e-mail|phone|mobile|ssn|card|iban|account|licen[cs]e|session|auth|nonce|salt|_edit_lock|_edit_last|_wp_old|ip_address|user_id|customer)/i';

		/**
		 * Request cache of enabled tools.
		 *
		 * @var array|null
		 */
		private static $enabled = null;

		/**
		 * Set by the dashboard route when a person (not the AI ability) saves.
		 *
		 * @var bool
		 */
		public static $human = false;

		/* -------------------------------------------------------------
		 * Registry
		 * ----------------------------------------------------------- */

		/**
		 * Every tool that is switched on, name => tool.
		 *
		 * Tool shape: name, title, description, input_schema, annotations, source,
		 * write (bool), run (callable taking the checked arguments).
		 *
		 * @return array
		 */
		public static function enabled() {
			if ( null !== self::$enabled ) {
				return self::$enabled;
			}
			$tools = Uich_Agent_Builtin_Tools::tools();
			foreach ( self::definitions() as $name => $def ) {
				if ( empty( $def['enabled'] ) || isset( $tools[ $name ] ) ) {
					continue;
				}
				$tool = self::from_definition( $def );
				if ( $tool ) {
					$tools[ $name ] = $tool;
				}
			}

			/**
			 * Filter the site MCP's tools (e.g. tools added by a module in code).
			 *
			 * Each tool must keep the shape above and must be safe for anonymous callers.
			 *
			 * @param array $tools name => tool.
			 */
			$tools = apply_filters( 'uichemy_agent_ready_tools', $tools );

			// Tools added in code (a Module Studio module, a plugin) give only what they
			// need: fill in the rest, and drop any that cannot run.
			self::$enabled = array();
			foreach ( is_array( $tools ) ? $tools : array() as $name => $tool ) {
				$name = (string) $name;
				if ( ! is_array( $tool ) || ! preg_match( '/^[a-z0-9_]{1,64}$/', $name ) || empty( $tool['run'] ) || ! is_callable( $tool['run'] ) ) {
					continue;
				}
				$write                  = ! empty( $tool['write'] );
				self::$enabled[ $name ] = array_merge(
					array(
						'title'        => $name,
						'description'  => '',
						'input_schema' => array(
							'type'       => 'object',
							'properties' => new stdClass(),
						),
						'annotations'  => array(
							'readOnlyHint'    => ! $write,
							'destructiveHint' => false,
							'openWorldHint'   => false,
						),
						'source'       => 'code',
						'write'        => $write,
					),
					$tool,
					array( 'name' => $name )
				);
			}
			return self::$enabled;
		}

		/**
		 * Drop the request cache.
		 *
		 * @return void
		 */
		public static function flush() {
			self::$enabled = null;
		}

		/**
		 * Every stored definition, name => definition.
		 *
		 * @return array
		 */
		public static function definitions() {
			$out = array();
			foreach ( Uich_Agent_Store::tools() as $name => $def ) {
				if ( is_array( $def ) && isset( $def['name'] ) && $def['name'] === $name ) {
					$out[ $name ] = $def;
				}
			}
			ksort( $out );
			return $out;
		}

		/**
		 * Build a runnable tool from a stored definition.
		 *
		 * @param array $def Definition.
		 * @return array|null
		 */
		private static function from_definition( array $def ) {
			$clean = self::validate( $def );
			if ( is_wp_error( $clean ) ) {
				return null;
			}
			$type   = $clean['type'];
			$schema = self::schema_for( $clean );
			$write  = 'ability' === $type && ! empty( $clean['config']['allow_write'] ) && Uich_Agent_Settings::writes_enabled();
			return array(
				'name'         => $clean['name'],
				'title'        => $clean['title'],
				'description'  => $clean['description'],
				'input_schema' => $schema,
				'annotations'  => array(
					'readOnlyHint'    => ! $write,
					'destructiveHint' => false,
					'idempotentHint'  => ! $write,
					'openWorldHint'   => false,
				),
				'source'       => 'file',
				'write'        => $write,
				'run'          => function ( $args ) use ( $clean ) {
					return Uich_Agent_Tools::run_definition( $clean, $args );
				},
			);
		}

		/* -------------------------------------------------------------
		 * Calling
		 * ----------------------------------------------------------- */

		/**
		 * Call a tool by name as an anonymous visitor.
		 *
		 * @param string $name Tool name.
		 * @param array  $args Arguments.
		 * @return array|WP_Error Plain-data result.
		 */
		public static function call( $name, $args ) {
			$tools = self::enabled();
			if ( ! isset( $tools[ $name ] ) ) {
				return new WP_Error( 'uich_agent_no_tool', sprintf( 'Unknown tool "%s".', $name ) );
			}
			$tool = $tools[ $name ];
			$args = is_array( $args ) ? $args : array();

			$checked = self::check_args( $tool['input_schema'], $args );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$user = get_current_user_id();
			if ( 0 !== $user ) {
				wp_set_current_user( 0 );
			}
			try {
				$result = call_user_func( $tool['run'], $checked );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'uich_agent_tool_failed', 'The tool failed.' );
			}
			if ( 0 !== $user ) {
				wp_set_current_user( $user );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return self::shape( $result );
		}

		/**
		 * Check arguments against a JSON Schema subset: object with typed
		 * properties, required, enum, min/max length and range, max items.
		 * Unknown properties are refused.
		 *
		 * @param array $schema Schema.
		 * @param array $args   Arguments.
		 * @return array|WP_Error Arguments with types coerced.
		 */
		public static function check_args( array $schema, array $args ) {
			$props    = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			$required = isset( $schema['required'] ) ? (array) $schema['required'] : array();
			$out      = array();

			foreach ( $args as $key => $value ) {
				if ( ! isset( $props[ $key ] ) ) {
					return new WP_Error( 'uich_agent_bad_args', sprintf( 'Unknown argument "%s".', $key ) );
				}
			}
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $args ) ) {
					return new WP_Error( 'uich_agent_bad_args', sprintf( 'Missing required argument "%s".', $key ) );
				}
			}
			foreach ( $props as $key => $prop ) {
				if ( ! array_key_exists( $key, $args ) ) {
					continue;
				}
				$value = self::check_value( $key, $prop, $args[ $key ] );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		/**
		 * Check one value.
		 *
		 * @param string $key   Name (for messages).
		 * @param array  $prop  Schema.
		 * @param mixed  $value Value.
		 * @return mixed|WP_Error
		 */
		private static function check_value( $key, array $prop, $value ) {
			$type = isset( $prop['type'] ) ? $prop['type'] : 'string';
			switch ( $type ) {
				case 'integer':
					if ( ! is_numeric( $value ) || (int) $value != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- numeric check.
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" must be a whole number.', $key ) );
					}
					$value = (int) $value;
					if ( isset( $prop['minimum'] ) && $value < $prop['minimum'] ) {
						$value = (int) $prop['minimum'];
					}
					if ( isset( $prop['maximum'] ) && $value > $prop['maximum'] ) {
						$value = (int) $prop['maximum'];
					}
					break;
				case 'number':
					if ( ! is_numeric( $value ) ) {
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" must be a number.', $key ) );
					}
					$value = (float) $value;
					break;
				case 'boolean':
					$value = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					break;
				case 'array':
					if ( ! is_array( $value ) ) {
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" must be a list.', $key ) );
					}
					$max = isset( $prop['maxItems'] ) ? (int) $prop['maxItems'] : 20;
					if ( count( $value ) > $max ) {
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" has more than %d items.', $key, $max ) );
					}
					$items = isset( $prop['items'] ) && is_array( $prop['items'] ) ? $prop['items'] : array( 'type' => 'string' );
					$clean = array();
					foreach ( array_values( $value ) as $item ) {
						if ( isset( $items['type'] ) && 'object' === $items['type'] ) {
							if ( ! is_array( $item ) ) {
								return new WP_Error( 'uich_agent_bad_args', sprintf( 'Each "%s" item must be an object.', $key ) );
							}
							$item = self::check_args( $items, $item );
						} else {
							$item = self::check_value( $key, $items, $item );
						}
						if ( is_wp_error( $item ) ) {
							return $item;
						}
						$clean[] = $item;
					}
					$value = $clean;
					break;
				default:
					if ( ! is_scalar( $value ) ) {
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" must be text.', $key ) );
					}
					$value = sanitize_text_field( (string) $value );
					$max   = isset( $prop['maxLength'] ) ? (int) $prop['maxLength'] : 200;
					if ( mb_strlen( $value ) > $max ) {
						return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" is longer than %d characters.', $key, $max ) );
					}
			}
			if ( isset( $prop['enum'] ) && ! in_array( $value, (array) $prop['enum'], true ) ) {
				return new WP_Error( 'uich_agent_bad_args', sprintf( '"%s" must be one of: %s.', $key, implode( ', ', (array) $prop['enum'] ) ) );
			}
			return $value;
		}

		/**
		 * Reduce a result to plain data: strings without HTML or invisible
		 * characters, each string capped, the whole result capped.
		 *
		 * @param mixed $data Result.
		 * @return array
		 */
		public static function shape( $data ) {
			$data = self::plain( $data, 0 );
			if ( ! is_array( $data ) ) {
				$data = array( 'result' => $data );
			}
			$json = wp_json_encode( $data );
			while ( is_string( $json ) && strlen( $json ) > self::MAX_BYTES && isset( $data['items'] ) && is_array( $data['items'] ) && count( $data['items'] ) > 1 ) {
				$data['items']     = array_slice( $data['items'], 0, (int) floor( count( $data['items'] ) / 2 ) );
				$data['truncated'] = true;
				$json              = wp_json_encode( $data );
			}
			if ( is_string( $json ) && strlen( $json ) > self::MAX_BYTES ) {
				return array(
					'error' => 'The result was too large. Ask for fewer items or a narrower search.',
				);
			}
			return $data;
		}

		/**
		 * Recursive plain-text conversion.
		 *
		 * @param mixed $value Value.
		 * @param int   $depth Depth.
		 * @return mixed
		 */
		private static function plain( $value, $depth ) {
			if ( $depth > 6 ) {
				return null;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$out = array();
				foreach ( (array) $value as $key => $item ) {
					$out[ is_int( $key ) ? $key : substr( preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $key ), 0, 64 ) ] = self::plain( $item, $depth + 1 );
				}
				return $out;
			}
			if ( is_string( $value ) ) {
				// Markdown from agent files is already clean text; only strip tags.
				$value = wp_strip_all_tags( $value );
				$value = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{E0000}-\x{E007F}]/u', '', $value );
				return mb_substr( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ), 0, 60000 );
			}
			return is_scalar( $value ) || null === $value ? $value : null;
		}

		/* -------------------------------------------------------------
		 * Definitions: validation and schemas
		 * ----------------------------------------------------------- */

		/**
		 * Validate and normalise a definition. Anything unknown is dropped.
		 *
		 * @param array $def Definition.
		 * @return array|WP_Error
		 */
		public static function validate( array $def ) {
			$name = isset( $def['name'] ) ? (string) $def['name'] : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{2,47}$/', $name ) ) {
				return new WP_Error( 'uich_agent_tool_name', 'Tool names are 3-48 characters: lower-case letters, digits and underscores, starting with a letter.' );
			}
			if ( isset( Uich_Agent_Builtin_Tools::tools()[ $name ] ) || in_array( $name, Uich_Agent_Builtin_Tools::names(), true ) ) {
				return new WP_Error( 'uich_agent_tool_name', sprintf( '"%s" is a built-in tool name.', $name ) );
			}
			$type = isset( $def['type'] ) ? (string) $def['type'] : '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return new WP_Error( 'uich_agent_tool_type', 'type must be one of: ' . implode( ', ', self::TYPES ) . '.' );
			}
			$title       = self::clean_line( isset( $def['title'] ) ? $def['title'] : $name, 80 );
			$description = self::clean_line( isset( $def['description'] ) ? $def['description'] : '', 500 );
			if ( '' === $description ) {
				return new WP_Error( 'uich_agent_tool_description', 'Give the tool a description that tells an agent when to use it.' );
			}
			$config = isset( $def['config'] ) && is_array( $def['config'] ) ? $def['config'] : array();
			$method = 'validate_' . $type;
			$config = self::$method( $config );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			return array(
				'name'        => $name,
				'title'       => $title,
				'description' => $description,
				'type'        => $type,
				'config'      => $config,
				'enabled'     => ! empty( $def['enabled'] ),
				'updated_at'  => isset( $def['updated_at'] ) ? (int) $def['updated_at'] : 0,
				'updated_by'  => isset( $def['updated_by'] ) ? self::clean_line( $def['updated_by'], 60 ) : '',
			);
		}

		/**
		 * Collection config.
		 *
		 * @param array $c Config.
		 * @return array|WP_Error
		 */
		private static function validate_collection( array $c ) {
			$type = isset( $c['post_type'] ) ? sanitize_key( $c['post_type'] ) : '';
			$obj  = get_post_type_object( $type );
			if ( ! $obj || ! $obj->public || in_array( $type, self::BLOCKED_TYPES, true ) ) {
				return new WP_Error( 'uich_agent_tool_config', sprintf( '"%s" is not a public post type that can be exposed.', $type ) );
			}
			$allowed_fields = array( 'title', 'url', 'agent_url', 'excerpt', 'content', 'date', 'modified', 'image', 'author' );
			$fields         = array_values( array_intersect( array_map( 'sanitize_key', isset( $c['fields'] ) ? (array) $c['fields'] : array( 'title', 'url', 'excerpt' ) ), $allowed_fields ) );
			$fields         = $fields ? $fields : array( 'title', 'url', 'excerpt' );

			$taxonomies = array();
			foreach ( isset( $c['taxonomies'] ) ? (array) $c['taxonomies'] : array() as $tax ) {
				$tax = sanitize_key( $tax );
				$t   = get_taxonomy( $tax );
				if ( $t && $t->public && in_array( $type, (array) $t->object_type, true ) ) {
					$taxonomies[] = $tax;
				}
			}

			$meta = array();
			foreach ( isset( $c['meta'] ) ? (array) $c['meta'] : array() as $item ) {
				$key   = is_array( $item ) && isset( $item['key'] ) ? (string) $item['key'] : (string) $item;
				$label = is_array( $item ) && isset( $item['label'] ) ? self::clean_line( $item['label'], 40 ) : '';
				if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $key ) || preg_match( self::SENSITIVE_META, $key ) ) {
					return new WP_Error( 'uich_agent_tool_config', sprintf( 'The field "%s" cannot be exposed.', $key ) );
				}
				$meta[] = array(
					'key'   => $key,
					'label' => '' === $label ? ltrim( $key, '_' ) : sanitize_key( $label ),
				);
			}

			return array(
				'post_type'  => $type,
				'fields'     => $fields,
				'taxonomies' => array_slice( $taxonomies, 0, 5 ),
				'meta'       => array_slice( $meta, 0, 20 ),
				'orderby'    => isset( $c['orderby'] ) && in_array( $c['orderby'], array( 'date', 'title', 'menu_order', 'modified' ), true ) ? $c['orderby'] : 'date',
				'order'      => isset( $c['order'] ) && 'ASC' === strtoupper( (string) $c['order'] ) ? 'ASC' : 'DESC',
				'per_page'   => isset( $c['per_page'] ) ? max( 1, min( 50, (int) $c['per_page'] ) ) : 10,
			);
		}

		/**
		 * Ability bridge config.
		 *
		 * @param array $c Config.
		 * @return array|WP_Error
		 */
		private static function validate_ability( array $c ) {
			$ability = isset( $c['ability'] ) ? strtolower( (string) $c['ability'] ) : '';
			if ( ! preg_match( '#^[a-z0-9\-]+/[a-z0-9\-]+(/[a-z0-9\-]+)*$#', $ability ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'config.ability must be an ability name like "plugin/action".' );
			}
			if ( preg_match( '#^(uichemy-composer|uichemy|core|mcp-adapter|wp-abilities)/|^woocommerce/(order|product-(create|update|delete)|customer|coupon|refund|settings)#', $ability ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'This ability cannot be exposed on the public site MCP.' );
			}
			return array(
				'ability'     => $ability,
				'output'      => self::paths( isset( $c['output'] ) ? $c['output'] : array() ),
				'allow_write' => ! empty( $c['allow_write'] ),
			);
		}

		/**
		 * REST read config.
		 *
		 * @param array $c Config.
		 * @return array|WP_Error
		 */
		private static function validate_rest( array $c ) {
			// Routes match case-insensitively in core, so every check runs on the
			// lower-cased, slash-normalised route.
			$route = isset( $c['route'] ) ? '/' . trim( preg_replace( '#/+#', '/', strtolower( (string) $c['route'] ) ), '/' ) : '';
			if ( ! preg_match( '#^/[a-z0-9_\-./]+$#', $route ) || false !== strpos( $route, '..' ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'config.route must be a REST route such as "/wc/store/v1/products".' );
			}
			// WordPress core: only public content routes.
			if ( 0 === strpos( $route . '/', '/wp/' ) && ! preg_match( '#^/wp/v2/(posts|pages|categories|tags|search|types|taxonomies)(/|$)#', $route ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'Only public content routes of WordPress core (posts, pages, categories, tags, search) can be exposed.' );
			}
			// WooCommerce also registers unversioned aliases (/wc/store/cart), so the
			// version segment is optional here.
			if ( preg_match( '#^/(uichemy[a-z0-9\-]*|wp-abilities|mcp|wc/v[0-9]|wc-admin[a-z0-9\-]*|wc-analytics|wc-telemetry|wc/private|wc/pos|wccom-site|wc/store(/v[0-9]+)?/(cart|checkout|order|batch)|jetpack|oembed/1.0/proxy|wp-site-health|application-passwords)(/|$)#', $route ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'This route cannot be exposed on the public site MCP.' );
			}
			$params = self::param_schema( isset( $c['params'] ) ? $c['params'] : array() );
			if ( is_wp_error( $params ) ) {
				return $params;
			}
			$fixed = array();
			foreach ( isset( $c['fixed'] ) && is_array( $c['fixed'] ) ? $c['fixed'] : array() as $k => $v ) {
				if ( preg_match( '/^[a-z0-9_\-\[\]]{1,40}$/', (string) $k ) && is_scalar( $v ) ) {
					$fixed[ (string) $k ] = sanitize_text_field( (string) $v );
				}
			}
			return array(
				'route'  => $route,
				'params' => $params,
				'fixed'  => $fixed,
				'output' => self::paths( isset( $c['output'] ) ? $c['output'] : array() ),
			);
		}

		/**
		 * Link builder config.
		 *
		 * @param array $c Config.
		 * @return array|WP_Error
		 */
		private static function validate_link( array $c ) {
			$template = isset( $c['url'] ) ? trim( (string) $c['url'] ) : '';
			if ( '' === $template ) {
				return new WP_Error( 'uich_agent_tool_config', 'config.url is required, e.g. "/book/?service={service}".' );
			}
			if ( preg_match( '#^https?://#i', $template ) && wp_parse_url( $template, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'Link tools may only point to this site.' );
			}
			if ( ! preg_match( '#^(https?://|/)#i', $template ) ) {
				return new WP_Error( 'uich_agent_tool_config', 'config.url must start with "/" or this site\'s address.' );
			}
			$params = self::param_schema( isset( $c['params'] ) ? $c['params'] : array() );
			if ( is_wp_error( $params ) ) {
				return $params;
			}
			preg_match_all( '/\{([a-z0-9_]+)\}/', $template, $m );
			foreach ( $m[1] as $placeholder ) {
				if ( ! isset( $params[ $placeholder ] ) ) {
					return new WP_Error( 'uich_agent_tool_config', sprintf( 'Placeholder {%s} has no matching parameter.', $placeholder ) );
				}
			}
			return array(
				'url'    => esc_url_raw( $template ) ? $template : '',
				'params' => $params,
			);
		}

		/**
		 * Parameter definitions: name => { type: string|integer|number|boolean, description, enum?, maxLength?, required? }.
		 *
		 * @param mixed $params Raw.
		 * @return array|WP_Error
		 */
		private static function param_schema( $params ) {
			$out = array();
			foreach ( (array) $params as $name => $p ) {
				if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', (string) $name ) || ! is_array( $p ) ) {
					return new WP_Error( 'uich_agent_tool_config', sprintf( 'Bad parameter "%s".', $name ) );
				}
				$type  = isset( $p['type'] ) && in_array( $p['type'], array( 'string', 'integer', 'number', 'boolean' ), true ) ? $p['type'] : 'string';
				$entry = array(
					'type'        => $type,
					'description' => self::clean_line( isset( $p['description'] ) ? $p['description'] : '', 200 ),
				);
				if ( 'string' === $type ) {
					$entry['maxLength'] = isset( $p['maxLength'] ) ? max( 1, min( 200, (int) $p['maxLength'] ) ) : 100;
				}
				if ( isset( $p['enum'] ) && is_array( $p['enum'] ) ) {
					$entry['enum'] = array_slice( array_values( array_map( 'sanitize_text_field', array_map( 'strval', $p['enum'] ) ) ), 0, 50 );
				}
				if ( isset( $p['minimum'] ) && is_numeric( $p['minimum'] ) ) {
					$entry['minimum'] = (int) $p['minimum'];
				}
				if ( isset( $p['maximum'] ) && is_numeric( $p['maximum'] ) ) {
					$entry['maximum'] = (int) $p['maximum'];
				}
				$entry['required'] = ! empty( $p['required'] );
				$out[ (string) $name ] = $entry;
				if ( count( $out ) >= 15 ) {
					break;
				}
			}
			return $out;
		}

		/**
		 * Output allowlist: dotted paths like "name" or "prices.price".
		 *
		 * @param mixed $paths Raw.
		 * @return string[]
		 */
		private static function paths( $paths ) {
			$out = array();
			foreach ( (array) $paths as $path ) {
				$path = (string) $path;
				if ( preg_match( '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+){0,4}$/', $path ) ) {
					$out[] = $path;
				}
			}
			return array_slice( array_values( array_unique( $out ) ), 0, 40 );
		}

		/**
		 * The input schema a definition gives agents.
		 *
		 * @param array $def Validated definition.
		 * @return array
		 */
		public static function schema_for( array $def ) {
			$c = $def['config'];
			switch ( $def['type'] ) {
				case 'collection':
					$props = array(
						'query' => array(
							'type'        => 'string',
							'maxLength'   => 100,
							'description' => 'Words to search for.',
						),
						'slug'  => array(
							'type'        => 'string',
							'maxLength'   => 200,
							'description' => 'Get one item by its slug (from a previous result) instead of searching.',
						),
						'page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 50,
							'description' => 'Result page, from 1.',
						),
					);
					foreach ( $c['taxonomies'] as $tax ) {
						$t              = get_taxonomy( $tax );
						$props[ $tax ] = array(
							'type'        => 'string',
							'maxLength'   => 100,
							'description' => 'Filter by ' . ( $t ? $t->labels->singular_name : $tax ) . ' (slug).',
						);
					}
					return array(
						'type'       => 'object',
						'properties' => $props,
					);

				case 'ability':
					$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $c['ability'] ) : null;
					$schema  = $ability ? $ability->get_input_schema() : array();
					if ( is_array( $schema ) && isset( $schema['type'] ) && 'object' === $schema['type'] ) {
						return array(
							'type'       => 'object',
							'properties' => isset( $schema['properties'] ) ? (array) $schema['properties'] : array(),
							'required'   => isset( $schema['required'] ) ? (array) $schema['required'] : array(),
						);
					}
					return array(
						'type'       => 'object',
						'properties' => array(),
					);

				default: // rest, link.
					$props    = array();
					$required = array();
					foreach ( $c['params'] as $name => $p ) {
						$prop = $p;
						unset( $prop['required'] );
						$props[ $name ] = $prop;
						if ( ! empty( $p['required'] ) ) {
							$required[] = $name;
						}
					}
					return array(
						'type'       => 'object',
						'properties' => $props,
						'required'   => $required,
					);
			}
		}

		/* -------------------------------------------------------------
		 * Running definitions
		 * ----------------------------------------------------------- */

		/**
		 * Run a validated definition.
		 *
		 * @param array $def  Definition.
		 * @param array $args Checked arguments.
		 * @return array|WP_Error
		 */
		public static function run_definition( array $def, array $args ) {
			switch ( $def['type'] ) {
				case 'collection':
					return self::run_collection( $def['config'], $args );
				case 'ability':
					return self::run_ability( $def['config'], $args );
				case 'rest':
					return self::run_rest( $def['config'], $args );
				case 'link':
					return self::run_link( $def['config'], $args );
			}
			return new WP_Error( 'uich_agent_tool_type', 'Unknown tool type.' );
		}

		/**
		 * Collection: search or fetch one item of a public post type.
		 *
		 * @param array $c    Config.
		 * @param array $args Arguments.
		 * @return array
		 */
		private static function run_collection( array $c, array $args ) {
			$max   = (int) Uich_Agent_Settings::value( 'mcp.max_results', 20 );
			$query = array(
				'post_type'           => $c['post_type'],
				'post_status'         => 'publish',
				'has_password'        => false,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'posts_per_page'      => min( $max, $c['per_page'] ),
				'paged'               => isset( $args['page'] ) ? (int) $args['page'] : 1,
				'orderby'             => $c['orderby'],
				'order'               => $c['order'],
				'meta_query'          => Uich_Agent_Access::meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hidden-from-agents flags.
			);
			if ( ! empty( $args['slug'] ) ) {
				$query['name']           = sanitize_title( $args['slug'] );
				$query['posts_per_page'] = 1;
			} elseif ( ! empty( $args['query'] ) ) {
				$query['s'] = $args['query'];
			}
			$tax_query = array();
			foreach ( $c['taxonomies'] as $tax ) {
				if ( ! empty( $args[ $tax ] ) ) {
					$tax_query[] = array(
						'taxonomy' => $tax,
						'field'    => 'slug',
						'terms'    => sanitize_title( $args[ $tax ] ),
					);
				}
			}
			if ( $tax_query ) {
				$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- agent filter.
			}

			$q     = new WP_Query( $query );
			$items = array();
			foreach ( Uich_Agent_Access::filter( $q->posts ) as $post ) {
				$items[] = self::collection_item( $post, $c, ! empty( $args['slug'] ) );
			}
			return array(
				'items' => $items,
				'total' => (int) $q->found_posts,
				'page'  => (int) $query['paged'],
				'pages' => (int) $q->max_num_pages,
			);
		}

		/**
		 * One collection item with only the configured fields.
		 *
		 * @param WP_Post $post   Post.
		 * @param array   $c      Config.
		 * @param bool    $detail Whether this is a single-item fetch (longer content).
		 * @return array
		 */
		private static function collection_item( WP_Post $post, array $c, $detail ) {
			$item = array( 'slug' => $post->post_name );
			foreach ( $c['fields'] as $field ) {
				switch ( $field ) {
					case 'title':
						$item['title'] = get_the_title( $post );
						break;
					case 'url':
						$item['url'] = get_permalink( $post );
						break;
					case 'agent_url':
						$item['agent_url'] = Uich_Agent_Urls::md_url( get_permalink( $post ) );
						break;
					case 'excerpt':
						$item['excerpt'] = Uich_Agent_Access::public_text( $post, 40, true );
						break;
					case 'content':
						$item['content'] = Uich_Agent_Access::public_text( $post, $detail ? 600 : 80 );
						break;
					case 'date':
						$item['date'] = gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) );
						break;
					case 'modified':
						$item['modified'] = gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) );
						break;
					case 'image':
						$thumb = get_post_thumbnail_id( $post );
						if ( $thumb ) {
							$item['image'] = array(
								'url' => wp_get_attachment_image_url( $thumb, 'large' ),
								'alt' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
							);
						}
						break;
					case 'author':
						$item['author'] = get_the_author_meta( 'display_name', $post->post_author );
						break;
				}
			}
			foreach ( $c['taxonomies'] as $tax ) {
				$terms = get_the_terms( $post, $tax );
				if ( is_array( $terms ) ) {
					$item[ $tax ] = wp_list_pluck( $terms, 'name' );
				}
			}
			foreach ( $c['meta'] as $m ) {
				$value = get_post_meta( $post->ID, $m['key'], true );
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$item[ $m['label'] ] = (string) $value;
				}
			}
			return $item;
		}

		/**
		 * Ability bridge: call a registered ability as an anonymous visitor.
		 *
		 * @param array $c    Config.
		 * @param array $args Arguments.
		 * @return array|WP_Error
		 */
		private static function run_ability( array $c, array $args ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $c['ability'] ) : null;
			if ( ! $ability ) {
				return new WP_Error( 'uich_agent_tool_unavailable', 'This tool is not available right now.' );
			}
			$meta     = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
			$readonly = ! empty( $meta['annotations']['readonly'] );
			if ( ! $readonly && ( empty( $c['allow_write'] ) || ! Uich_Agent_Settings::writes_enabled() ) ) {
				return new WP_Error( 'uich_agent_tool_unavailable', 'This tool is not available right now.' );
			}
			$result = $ability->execute( $args ? $args : null );
			if ( is_wp_error( $result ) ) {
				// The plugin's own permission check refused an anonymous visitor.
				return new WP_Error( 'uich_agent_tool_refused', 'The site did not allow this request.' );
			}
			return self::pick( $result, $c['output'] );
		}

		/**
		 * REST read: an internal GET request as an anonymous visitor.
		 *
		 * @param array $c    Config.
		 * @param array $args Arguments.
		 * @return array|WP_Error
		 */
		private static function run_rest( array $c, array $args ) {
			// Honour site policies that block anonymous REST access: rest_do_request()
			// does not run the authentication check that a real HTTP request does.
			$auth = apply_filters( 'rest_authentication_errors', null );
			if ( is_wp_error( $auth ) ) {
				return new WP_Error( 'uich_agent_tool_refused', 'The site did not allow this request.' );
			}
			$request = new WP_REST_Request( 'GET', $c['route'] );
			foreach ( array_merge( $args, $c['fixed'] ) as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$response = rest_do_request( $request );
			if ( $response->is_error() || $response->get_status() >= 400 ) {
				return new WP_Error( 'uich_agent_tool_refused', 'The site did not allow this request.' );
			}
			$data = rest_get_server()->response_to_data( $response, false );
			return self::pick( $data, $c['output'] );
		}

		/**
		 * Link builder: fill the URL pattern.
		 *
		 * @param array $c    Config.
		 * @param array $args Arguments.
		 * @return array
		 */
		private static function run_link( array $c, array $args ) {
			$url = preg_replace_callback(
				'/\{([a-z0-9_]+)\}/',
				function ( $m ) use ( $args ) {
					return isset( $args[ $m[1] ] ) ? rawurlencode( (string) $args[ $m[1] ] ) : '';
				},
				$c['url']
			);
			if ( '/' === $url[0] ) {
				$url = home_url( $url );
			}
			return array( 'url' => esc_url_raw( $url ) );
		}

		/**
		 * Keep only allowlisted paths; whole result when no list is set.
		 * Lists of objects are filtered item by item.
		 *
		 * @param mixed    $data  Data.
		 * @param string[] $paths Dotted paths.
		 * @return array
		 */
		private static function pick( $data, array $paths ) {
			if ( ! $paths ) {
				return is_array( $data ) ? ( self::is_list( $data ) ? array( 'items' => $data ) : $data ) : array( 'result' => $data );
			}
			if ( is_array( $data ) && self::is_list( $data ) ) {
				$items = array();
				foreach ( array_slice( $data, 0, (int) Uich_Agent_Settings::value( 'mcp.max_results', 20 ) ) as $row ) {
					$items[] = self::pick_one( $row, $paths );
				}
				return array( 'items' => $items );
			}
			return self::pick_one( $data, $paths );
		}

		/**
		 * Allowlisted paths from one object.
		 *
		 * @param mixed    $row   Object.
		 * @param string[] $paths Paths.
		 * @return array
		 */
		private static function pick_one( $row, array $paths ) {
			$row = json_decode( wp_json_encode( $row ), true );
			$out = array();
			foreach ( $paths as $path ) {
				$node = $row;
				foreach ( explode( '.', $path ) as $seg ) {
					if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
						$node = null;
						break;
					}
					$node = $node[ $seg ];
				}
				if ( null !== $node ) {
					$out[ str_replace( '.', '_', $path ) ] = $node;
				}
			}
			return $out;
		}

		/**
		 * Whether an array is a list.
		 *
		 * @param array $a Array.
		 * @return bool
		 */
		private static function is_list( array $a ) {
			return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
		}

		/**
		 * One line of plain text.
		 *
		 * @param mixed $text Text.
		 * @param int   $max  Max length.
		 * @return string
		 */
		private static function clean_line( $text, $max ) {
			$text = sanitize_text_field( wp_strip_all_tags( (string) $text ) );
			$text = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u', '', $text );
			return mb_substr( $text, 0, $max );
		}

		/* -------------------------------------------------------------
		 * Saving definitions (admin)
		 * ----------------------------------------------------------- */

		/**
		 * Save a definition. New and changed definitions are saved disabled.
		 *
		 * @param array $def Definition.
		 * @return array|WP_Error Saved definition.
		 */
		public static function save( array $def ) {
			$clean = self::validate( $def );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$existing = self::definitions();
			$old      = isset( $existing[ $clean['name'] ] ) ? $existing[ $clean['name'] ] : null;
			$changed  = ! $old || $old['type'] !== $clean['type'] || $old['config'] !== $clean['config'] || $old['description'] !== $clean['description'] || $old['title'] !== $clean['title'];
			$clean['enabled']    = $changed ? false : ! empty( $old['enabled'] );
			// Letting a plugin ability WRITE is a person's decision, made on the
			// dashboard (Uich_Agent_Rest), never through the AI-facing ability.
			if ( 'ability' === $clean['type'] && ! self::$human ) {
				$clean['config']['allow_write'] = $old && ! empty( $old['config']['allow_write'] );
			}
			$clean['updated_at'] = time();
			$user                = wp_get_current_user();
			$clean['updated_by'] = $user && $user->exists() ? $user->user_login : 'system';

			$saved = Uich_Agent_Store::write_json( 'tools/' . $clean['name'] . '/tool.json', $clean );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::flush();
			return $clean;
		}

		/**
		 * Switch a definition on or off.
		 *
		 * @param string $name    Tool name.
		 * @param bool   $enabled On or off.
		 * @return array|WP_Error
		 */
		public static function set_state( $name, $enabled ) {
			$defs = self::definitions();
			if ( ! isset( $defs[ $name ] ) ) {
				return new WP_Error( 'uich_agent_no_tool', sprintf( 'No tool definition named "%s".', $name ) );
			}
			$clean = self::validate( $defs[ $name ] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$clean['enabled']    = (bool) $enabled;
			$clean['updated_at'] = time();
			$saved               = Uich_Agent_Store::write_json( 'tools/' . $name . '/tool.json', $clean );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::flush();
			return $clean;
		}

		/**
		 * Delete a definition.
		 *
		 * @param string $name Tool name.
		 * @return true|WP_Error
		 */
		public static function delete( $name ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]{2,47}$/', (string) $name ) ) {
				return new WP_Error( 'uich_agent_no_tool', 'Bad tool name.' );
			}
			$done = Uich_Agent_Store::delete( 'tools/' . $name . '/tool.json' );
			self::flush();
			return $done;
		}
	}
}
