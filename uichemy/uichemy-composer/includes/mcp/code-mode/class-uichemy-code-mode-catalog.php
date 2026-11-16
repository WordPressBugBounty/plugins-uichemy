<?php
/**
 * Code Mode catalog: the served abilities, as a language-neutral IR.
 *
 * One module per ability, one function per action:
 *
 *   uichemy-composer/page  action "grep"  ->  { module: Page, fn: grep, id: "page.grep" }
 *
 * The IR is what every emitter reads, so the per-language cost of typing is
 * the emitter alone. An ability without actions (read-skill) becomes a
 * module with one `call` function whose id is the bare slug.
 *
 * The abilities are exactly the ones the v2 endpoint serves
 * (UiChemy_MCP_Server_V2::served_abilities()), so v3 adds no ability of its
 * own and hides none that v2 offers.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Catalog' ) ) {

	/**
	 * Builds the Code Mode IR from the Abilities API.
	 */
	class UiChemy_Code_Mode_Catalog {

		/**
		 * The abilities v2 serves, keyed by full name. The briefing ability
		 * (uichemy-composer/instructions) is held back there too: a model reads
		 * it through get_instructions, a script has no use for it.
		 *
		 * @return array<string,\WP_Ability>
		 */
		public static function abilities() {
			return class_exists( 'UiChemy_MCP_Server_V2' ) ? UiChemy_MCP_Server_V2::served_abilities() : array();
		}

		/**
		 * The IR for every served ability, or only the named ones.
		 *
		 * @param array<int,string> $only Slugs ("describe-site"), class names ("DescribeSite") or full names to keep; empty for all.
		 * @return array<int,array>
		 */
		public static function modules( array $only = array() ) {
			$only    = array_map( 'strtolower', array_map( array( __CLASS__, 'slug' ), $only ) );
			$modules = array();

			foreach ( self::all() as $module ) {
				if ( $only && ! in_array( $module['slug'], $only, true ) && ! in_array( strtolower( $module['module'] ), $only, true ) ) {
					continue;
				}
				$modules[] = $module;
			}

			return $modules;
		}

		/** @var array<string,array>|null Built IR, by a key of the served abilities. */
		private static $cache = null;

		/**
		 * Every served ability as IR, built once per request (and again if the
		 * set of abilities changes), with v2 wording in the docs rewritten to v3
		 * names (UiChemy_Code_Mode_Text).
		 *
		 * @return array<int,array>
		 */
		private static function all() {
			$abilities = self::abilities();
			// The actions an ability offers can change within a request (the builder
			// mode decides Page's), so they are part of the key.
			$key = get_current_user_id();
			foreach ( $abilities as $name => $ability ) {
				$meta = $ability->get_meta();
				$key .= '|' . $name . ':' . implode( ',', wp_list_pluck( array_filter( (array) ( $meta['actions'] ?? array() ), 'is_array' ), 'name' ) );
			}
			$key = md5( $key );
			if ( null !== self::$cache && $key === self::$cache['key'] ) {
				return self::$cache['modules'];
			}

			$modules = array();
			foreach ( $abilities as $ability ) {
				$modules[] = self::module( $ability );
			}

			// Docs are written for v2 ("call get-code first", action="list"): say them in v3 names.
			if ( class_exists( 'UiChemy_Code_Mode_Text' ) ) {
				$map = UiChemy_Code_Mode_Text::map( $modules );
				foreach ( $modules as $m => $module ) {
					$owner                    = $module['module'];
					$modules[ $m ]['about']   = UiChemy_Code_Mode_Text::to_v3( $module['about'], $owner, $map, true );
					foreach ( $module['fns'] as $f => $fn ) {
						$modules[ $m ]['fns'][ $f ]['doc'] = UiChemy_Code_Mode_Text::to_v3( $fn['doc'], $owner, $map, true );
						foreach ( $fn['params'] as $p => $param ) {
							$modules[ $m ]['fns'][ $f ]['params'][ $p ]['description'] = UiChemy_Code_Mode_Text::to_v3( $param['description'], $owner, $map, true );
							$modules[ $m ]['fns'][ $f ]['params'][ $p ]['schema']      = self::translate_schema( $param['schema'], $owner, $map );
						}
					}
				}
			}

			self::$cache = array(
				'key'     => $key,
				'modules' => $modules,
			);
			return $modules;
		}

		/**
		 * Nested descriptions in a parameter schema, in v3 names.
		 *
		 * @param array  $schema Schema.
		 * @param string $owner  Class.
		 * @param array  $map    UiChemy_Code_Mode_Text::map().
		 * @param int    $depth  Depth.
		 * @return array
		 */
		private static function translate_schema( array $schema, $owner, array $map, $depth = 0 ) {
			if ( $depth > 4 ) {
				return $schema;
			}
			if ( isset( $schema['description'] ) && is_string( $schema['description'] ) ) {
				$schema['description'] = UiChemy_Code_Mode_Text::to_v3( self::plain( $schema['description'] ), $owner, $map, true );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				$schema['items'] = self::translate_schema( $schema['items'], $owner, $map, $depth + 1 );
			}
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $k => $property ) {
					if ( is_array( $property ) ) {
						$schema['properties'][ $k ] = self::translate_schema( $property, $owner, $map, $depth + 1 );
					}
				}
			}
			return $schema;
		}

		/**
		 * What a class is for: the ability's own description and its input schema's,
		 * without v2's call-envelope rules (which a script never meets).
		 *
		 * @param \WP_Ability $ability Ability.
		 * @return string
		 */
		private static function about( $ability ) {
			$schema = $ability->get_input_schema();
			$parts  = array( (string) $ability->get_description(), is_array( $schema ) && isset( $schema['description'] ) ? (string) $schema['description'] : '' );
			$text   = trim( implode( ' ', array_filter( array_map( 'trim', $parts ) ) ) );
			if ( class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'contract' ) ) {
				$text = trim( str_replace( trim( (string) UiChemy_Abilities::contract() ), '', $text ) );
			}
			// v2's envelope advice: the action goes inside arguments, and the like.
			$text = (string) preg_replace( '/[^.]*\b(?:action_parameters|execute-ability|envelope|arguments object)\b[^.]*\.\s*/i', '', $text );
			return self::plain( $text );
		}

		/**
		 * One ability as an IR module.
		 *
		 * The ability's own description is left out on purpose: it is written for
		 * the execute-ability envelope ("action goes INSIDE arguments ..."), which
		 * a script never sees. The label names the module; each action carries
		 * its own description.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @return array{module:string, slug:string, ability:string, label:string, fns:array<int,array>}
		 */
		private static function module( $ability ) {
			$slug     = self::slug( $ability->get_name() );
			$meta     = $ability->get_meta();
			$readonly = UiChemy_Code_Mode_Dispatcher::ability_is_readonly( $ability );
			$fns      = array();

			if ( ! empty( $meta['actions'] ) && is_array( $meta['actions'] ) ) {
				foreach ( $meta['actions'] as $action ) {
					if ( ! is_array( $action ) || empty( $action['name'] ) ) {
						continue;
					}
					$schema_doc = isset( $action['action_parameters_schema']['description'] ) && is_string( $action['action_parameters_schema']['description'] ) ? $action['action_parameters_schema']['description'] : '';
					$fns[]      = self::fn(
						$slug,
						(string) $action['name'],
						trim( (string) ( $action['description'] ?? '' ) . ' ' . $schema_doc ),
						self::action_schema( $action ),
						$readonly || ! empty( $action['readonly'] )
					);
				}
			} else {
				$schema = $ability->get_input_schema();
				$fns[]  = self::fn( $slug, '', (string) $ability->get_description(), is_array( $schema ) ? $schema : array(), $readonly );
			}

			return array(
				'module'  => ! empty( $meta['code_mode']['class'] ) && is_string( $meta['code_mode']['class'] ) ? $meta['code_mode']['class'] : self::studly( $slug ),
				'slug'    => $slug,
				'ability' => $ability->get_name(),
				'label'   => self::plain( (string) $ability->get_label() ),
				'about'   => self::about( $ability ),
				'fns'     => $fns,
			);
		}

		/**
		 * An action's parameters as one JSON schema. Abilities declare them in
		 * one of two shapes, and both must come through, or the method has no
		 * parameters and every call to it fails:
		 *
		 *   action_parameters_schema: { type: object, properties: {...}, required: [...] }
		 *   parameters: { name: schema, ... } with required: [...] next to it (cpt, custom-fields)
		 *
		 * @param array $action One entry of meta.actions.
		 * @return array
		 */
		private static function action_schema( array $action ) {
			if ( isset( $action['action_parameters_schema'] ) && is_array( $action['action_parameters_schema'] ) ) {
				return $action['action_parameters_schema'];
			}
			if ( isset( $action['parameters'] ) && ( is_array( $action['parameters'] ) || is_object( $action['parameters'] ) ) ) {
				return array(
					'type'       => 'object',
					'properties' => $action['parameters'],
					'required'   => isset( $action['required'] ) && is_array( $action['required'] ) ? array_values( array_filter( $action['required'], 'is_string' ) ) : array(),
				);
			}
			return array();
		}

		/**
		 * One action as an IR function.
		 *
		 * @param string $slug        Module slug.
		 * @param string $action      Action name, '' for an action-less ability.
		 * @param string $description Action description.
		 * @param array  $schema      The action's parameter schema.
		 * @param bool   $readonly    The ability's own readonly annotation.
		 * @return array{fn:string, id:string, action:string, doc:string, kind:string, params:array<int,array>}
		 */
		private static function fn( $slug, $action, $description, array $schema, $readonly ) {
			$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
			$properties = is_object( $properties ) ? get_object_vars( $properties ) : ( is_array( $properties ) ? $properties : array() );
			$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
			$params     = array();

			foreach ( $properties as $name => $property ) {
				$property = is_array( $property ) ? $property : array();
				$params[] = array(
					'name'        => (string) $name,
					'schema'      => $property,
					'required'    => in_array( $name, $required, true ),
					// An alias the v2 handler also takes is not a parameter here.
					'description' => self::plain( (string) preg_replace( '/[^.]*\bis accepted as an alias\b[^.]*\.\s*/i', '', (string) ( $property['description'] ?? '' ) ) ),
				);
			}

			$id = self::fn_id( $slug, $action );

			return array(
				'fn'        => '' === $action ? 'call' : lcfirst( self::studly( $action ) ),
				'id'        => $id,
				'action'    => $action,
				'doc'       => self::plain( $description ),
				'kind'      => UiChemy_Code_Mode_Dispatcher::kind( $action, $readonly ),
				'params'    => $params,
			);
		}

		/**
		 * Text as v3 serves it: em and en dashes become a plain hyphen.
		 *
		 * The ability descriptions are shared with v2 and stay as they are; only
		 * what this endpoint hands a model is normalised.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public static function plain( $text ) {
			return str_replace( array( " \u{2014} ", "\u{2014}", " \u{2013} ", "\u{2013}" ), array( ' - ', ' - ', ' - ', '-' ), (string) $text );
		}

		/**
		 * The call id the dispatcher gets: "page.grep", or "read-skill".
		 *
		 * @param string $slug   Module slug.
		 * @param string $action Action name.
		 * @return string
		 */
		public static function fn_id( $slug, $action ) {
			return '' === $action ? $slug : $slug . '.' . $action;
		}

		/**
		 * "uichemy-composer/describe-site" or "describe-site" -> "describe-site".
		 *
		 * @param string $name Full name or slug.
		 * @return string
		 */
		public static function slug( $name ) {
			$name   = (string) $name;
			$prefix = UiChemy_MCP_Server_V2::NAMESPACE_SLUG . '/';
			return 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : $name;
		}

		/**
		 * "describe-site" -> "DescribeSite", "list-404s" -> "List404s".
		 *
		 * @param string $name Hyphenated name.
		 * @return string
		 */
		public static function studly( $name ) {
			return str_replace( ' ', '', ucwords( preg_replace( '/[^a-zA-Z0-9]+/', ' ', $name ) ) );
		}
	}
}
