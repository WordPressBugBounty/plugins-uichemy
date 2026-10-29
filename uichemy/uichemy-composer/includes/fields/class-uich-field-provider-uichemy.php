<?php
/**
 * The UiChemy Custom Fields provider - fields built in UiChemy's own module.
 *
 * The module (includes/fields/ at the plugin root) keeps its own definitions
 * and stores each value under an internal key derived from the group and the
 * field, never under the field's plain name. So the generic meta provider
 * cannot see these fields, `update_post_meta( $id, 'subtitle', ... )` writes a
 * loose row the module never reads, and every read and write here goes through
 * the module's public API instead: uich_field_value(), uich_save_field_value(),
 * uich_erase_field_value(). Those resolve the field, apply its own sanitiser,
 * honour its Free/Pro tiers and purge its caches - the same door the editor's
 * metabox uses.
 *
 * Only present when the Custom Fields feature is switched on (Settings >
 * Features). Off, the module does not load, is_active() is false, and the
 * field layer behaves exactly as it did before the module existed.
 *
 * Options pages are not addressed: the module has no options-page location, so
 * an options target reports no fields here rather than every group on the site.
 *
 * @link       https://posimyth.com/
 * @since      5.2.6
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Field_Provider_Uichemy' ) ) {

	/**
	 * Reads and writes UiChemy Custom Fields through the module's own API.
	 */
	final class Uich_Field_Provider_Uichemy extends Uich_Field_Provider {

		/**
		 * Provider slug.
		 *
		 * @return string
		 */
		public function slug() {
			return 'uichemy';
		}

		/**
		 * Provider label.
		 *
		 * @return string
		 */
		public function label() {
			return 'UiChemy Custom Fields';
		}

		/**
		 * Whether the module is loaded with the API this provider calls.
		 *
		 * @return bool
		 */
		public function is_active() {
			return function_exists( 'uich_field_value' )
				&& function_exists( 'uich_save_field_value' )
				&& function_exists( 'uich_erase_field_value' )
				&& function_exists( 'uich_get_all_groups_for_context' )
				&& function_exists( 'uich_build_meta_key' )
				&& class_exists( 'Uich_Fields_Definitions_Repository' );
		}

		// ============================================================
		// DISCOVERY
		// ============================================================

		/**
		 * Field definitions the module attaches to one target.
		 *
		 * Matched through the module's own location engine, so a group scoped
		 * by page template or post status is reported exactly where the editor
		 * would show it. With no object_id, the subtype stands in for the object
		 * - the discovery call "what does a movie have" before any movie exists.
		 *
		 * @param array $target Resolved target.
		 * @return array<string,array>
		 */
		public function definitions( $target ) {
			if ( ! $this->is_active() ) {
				return array();
			}

			$this->set_target_context( $target );

			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			if ( 'options' === $type ) {
				return array();
			}

			$id  = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
			$sub = isset( $target['object_subtype'] ) ? (string) $target['object_subtype'] : '';

			$overrides = array();
			if ( ! $id && '' !== $sub ) {
				// build_context_for_object() defaults an id-less post to post_type "post".
				$overrides = 'term' === $type ? array( 'taxonomy' => $sub ) : ( 'post' === $type ? array( 'post_type' => $sub ) : array() );
			}

			$context = uich_build_context_for_object( $type, $id, $overrides );
			$out     = array();

			foreach ( (array) uich_get_all_groups_for_context( $context ) as $group ) {
				foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $field ) {
					if ( ! is_array( $field ) || empty( $field['name'] ) ) {
						continue;
					}

					$name = (string) $field['name'];

					// The module resolves a name to the FIRST group that carries it, on
					// reads and writes alike; reporting a later duplicate would describe
					// a field no call can reach.
					if ( isset( $out[ $name ] ) ) {
						continue;
					}

					$out[ $name ] = $this->describe_field( $field, $group, $target );
				}
			}

			return $out;
		}

		/**
		 * Every native field group, one row per object type it targets.
		 *
		 * Shaped for Uich_Model::groups_targeting(): `object_type` plus
		 * `object_subtypes`, where an empty list means every subtype.
		 *
		 * @return array<int,array>
		 */
		public function groups() {
			if ( ! $this->is_active() ) {
				return array();
			}

			$rows = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
			$out  = array();

			foreach ( $rows['group_fields'] as $row ) {
				$meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();

				if ( 'publish' !== $row['status'] || ( isset( $meta['active'] ) && ! $meta['active'] ) ) {
					continue;
				}

				$fields = isset( $meta['fields'] ) ? array_filter( (array) $meta['fields'], array( __CLASS__, 'has_name' ) ) : array();

				foreach ( self::group_scope( isset( $meta['location_rules'] ) ? $meta['location_rules'] : array() ) as $object_type => $subtypes ) {
					$out[] = array(
						'provider'        => 'uichemy',
						'key'             => isset( $meta['key'] ) ? (string) $meta['key'] : '',
						'title'           => ! empty( $meta['title'] ) ? (string) $meta['title'] : (string) $row['title'],
						'object_type'     => $object_type,
						'object_subtypes' => $subtypes,
						'field_count'     => count( $fields ),
					);
				}
			}

			return $out;
		}

		/**
		 * Which object types a group's location rules reach, with subtypes.
		 *
		 * Accepts both stored shapes: a flat AND list, or a list of OR'ed AND
		 * lists. Only "==" rules include anything; a "!=" rule narrows a match
		 * and never tells us what is covered.
		 *
		 * @param array $rules Location rules.
		 * @return array<string,array<int,string>> object_type => subtypes ([] = all).
		 */
		public static function group_scope( $rules ) {
			$rules = is_array( $rules ) ? $rules : array();
			$first = reset( $rules );
			$sets  = ( is_array( $first ) && ! isset( $first['param'] ) ) ? $rules : array( $rules );

			$scope = array();
			$add   = static function ( $type, $sub ) use ( &$scope ) {
				if ( ! isset( $scope[ $type ] ) ) {
					$scope[ $type ] = array( 'all' => false, 'subs' => array() );
				}
				if ( '' === $sub ) {
					$scope[ $type ]['all'] = true;
				} else {
					$scope[ $type ]['subs'][] = $sub;
				}
			};

			foreach ( $sets as $set ) {
				// Within one AND set the narrowest rule wins: "post_type == movie AND
				// page_template == x" reaches movies only, not every post type.
				$set_scope = array();

				foreach ( (array) $set as $rule ) {
					if ( ! is_array( $rule ) || '==' !== ( isset( $rule['operator'] ) ? (string) $rule['operator'] : '==' ) ) {
						continue;
					}

					$param = isset( $rule['param'] ) ? (string) $rule['param'] : '';
					$value = isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '';

					switch ( $param ) {
						case 'post_type':
							if ( 'all' !== $value && '' !== $value ) {
								$set_scope['post'] = $value;
							} elseif ( ! isset( $set_scope['post'] ) ) {
								$set_scope['post'] = '';
							}
							break;
						case 'taxonomy':
							if ( 'all' !== $value && '' !== $value ) {
								$set_scope['term'] = $value;
							} elseif ( ! isset( $set_scope['term'] ) ) {
								$set_scope['term'] = '';
							}
							break;
						case 'user_form':
						case 'user_role':
							$set_scope['user'] = '';
							break;
						case 'page':
						case 'page_type':
						case 'page_parent':
							if ( empty( $set_scope['post'] ) ) {
								$set_scope['post'] = 'page';
							}
							break;
						case 'attachment':
							if ( empty( $set_scope['post'] ) ) {
								$set_scope['post'] = 'attachment';
							}
							break;
						case 'post':
						case 'post_template':
						case 'page_template':
						case 'post_status':
						case 'post_format':
						case 'post_category':
						case 'post_taxonomy':
							if ( ! isset( $set_scope['post'] ) ) {
								$set_scope['post'] = '';
							}
							break;
					}
				}

				foreach ( $set_scope as $type => $sub ) {
					$add( $type, $sub );
				}
			}

			$out = array();
			foreach ( $scope as $type => $row ) {
				$out[ $type ] = $row['all'] ? array() : array_values( array_unique( $row['subs'] ) );
			}

			return $out;
		}

		/**
		 * Normalise one native field config.
		 *
		 * @param array $field  Native field config.
		 * @param array $group  Owning group (key, title, location_rules).
		 * @param array $target Resolved target.
		 * @param int   $depth  Recursion depth.
		 * @return array
		 */
		private function describe_field( $field, $group, $target, $depth = 0 ) {
			$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$multiple = ! empty( $field['multiple'] );
			$shape    = Uich_Field_Value::shape_for( $type, 'uichemy' );

			// A multi-select stores a list, so it validates as one.
			if ( 'select' === $type && $multiple ) {
				$shape = 'choices';
			}

			$constraints = array();
			foreach ( array( 'min', 'max', 'step', 'multiple', 'taxonomy_name', 'relationship_post_types', 'post_object_post_types', 'repeater_min_rows', 'repeater_max_rows', 'allowed_types' ) as $k ) {
				if ( isset( $field[ $k ] ) && '' !== $field[ $k ] && array() !== $field[ $k ] ) {
					$constraints[ $k ] = $field[ $k ];
				}
			}
			// coerce_relation() reads the ACF spelling.
			if ( isset( $constraints['taxonomy_name'] ) ) {
				$constraints['taxonomy'] = $constraints['taxonomy_name'];
			}

			$return_format = null;
			foreach ( array( $type . '_return_format', 'return_format' ) as $k ) {
				if ( isset( $field[ $k ] ) && '' !== (string) $field[ $k ] ) {
					$return_format = (string) $field[ $k ];
					break;
				}
			}

			$has_logic = ! empty( $field['conditional_logic_enabled'] ) && ! empty( $field['conditional_logic'] );

			$scope    = self::group_scope( isset( $group['location_rules'] ) ? $group['location_rules'] : array() );
			$subtypes = isset( $scope['post'] ) ? $scope['post'] : array();

			$def = array(
				'name'            => (string) $field['name'],
				'key'             => isset( $field['key'] ) ? (string) $field['key'] : null,
				'label'           => isset( $field['label'] ) ? (string) $field['label'] : '',
				'instructions'    => isset( $field['instructions'] ) ? wp_strip_all_tags( (string) $field['instructions'] ) : '',
				'type'            => $type,
				'value_shape'     => $shape,
				'shape'           => Uich_Field_Value::structure_for( $type ),
				'required'        => ! empty( $field['required'] ),
				'choices'         => class_exists( 'Uich_Model' ) ? Uich_Model::choices_for_output( self::choices( isset( $field['choices'] ) ? $field['choices'] : null ) ) : null,
				'constraints'     => $constraints,
				'default'         => isset( $field['default_value'] ) && '' !== $field['default_value'] ? $field['default_value'] : null,
				'return_format'   => $return_format,
				'group'           => isset( $group['title'] ) ? (string) $group['title'] : '',
				'group_key'       => isset( $group['key'] ) ? (string) $group['key'] : '',
				'object_subtypes' => $subtypes,
			);

			if ( $has_logic ) {
				$def['conditional'] = true;
			}

			if ( 'repeater' === $type && $depth < 3 && ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$subs = array();
				foreach ( $field['fields'] as $sub ) {
					if ( self::has_name( $sub ) ) {
						$subs[] = $this->describe_field( $sub, $group, $target, $depth + 1 );
					}
				}
				$def['sub_fields'] = $subs;
			}

			if ( 'password' === $type ) {
				$def['writable'] = false;
				$def['reason']   = 'Password fields are withdrawn in UiChemy Custom Fields: their values were stored as cleartext meta. Readable here, not writable. Use a different field type, or keep secrets out of custom fields entirely.';
			} elseif ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( $type ) ) {
				$def['writable'] = false;
				$def['reason']   = sprintf(
					'"%s" is a UiChemy Pro field type and this site is not licensed for Pro. Its value reads back as empty and writes are refused, so the stored value survives untouched until Pro is active. %s',
					$type,
					function_exists( 'uichemy_upgrade_url' ) ? 'Upgrade: ' . uichemy_upgrade_url( 'custom-fields' ) : ''
				);
			}

			$def = $this->normalise( $def );

			if ( $has_logic ) {
				$def['write_hint'] .= ' CONDITIONAL: this field has conditional logic. While its condition is not met it renders EMPTY and reads back with "formatted" null even though "value" is stored - set the field it depends on first.';
			}

			return $def;
		}

		/**
		 * Native choices -> value => label.
		 *
		 * Stored either as the editor's "value : label" lines or as an array -
		 * value=>label, a scalar list, or a list of { value, label }.
		 *
		 * @param mixed $choices Raw choices.
		 * @return array<string,string>|null
		 */
		public static function choices( $choices ) {
			if ( is_string( $choices ) ) {
				$parsed = array();
				foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( preg_match( '/^(.+?)\s*:\s*(.+)$/', $line, $m ) ) {
						$parsed[] = array( 'value' => trim( $m[1] ), 'label' => trim( $m[2] ) );
					} else {
						$parsed[] = array( 'value' => $line, 'label' => $line );
					}
				}
				// A { value, label } list, not value => label: PHP turns "0".."3" keys into
				// integers, and a 0-based integer map reads as a plain list of labels.
				return class_exists( 'Uich_Model' ) ? Uich_Model::normalise_choices( $parsed ) : null;
			}

			return class_exists( 'Uich_Model' ) ? Uich_Model::normalise_choices( $choices ) : null;
		}

		/**
		 * Whether a field config has a usable name (layout-only rows have none).
		 *
		 * @param mixed $field Field config.
		 * @return bool
		 */
		public static function has_name( $field ) {
			return is_array( $field ) && ! empty( $field['name'] );
		}

		// ============================================================
		// READ
		// ============================================================

		/**
		 * Read one native field.
		 *
		 * "value" is the stored row read directly, NOT uich_field_value( ..., false ):
		 * that one returns null for a field whose conditional logic currently
		 * fails, and a null "value" invites a caller to write the field back
		 * empty. "formatted" is what the page renders, so it does go through
		 * the module, conditions and defaults included.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,formatted:mixed}
		 */
		public function read( $def, $target ) {
			$name = isset( $def['name'] ) ? (string) $def['name'] : '';
			$addr = $this->address( $target );

			$value = null;
			if ( '' !== (string) $def['group_key'] && function_exists( 'uich_get_stored_value' ) ) {
				$key   = uich_build_meta_key( $this->object_type( $target ), $def['group_key'], $name );
				$value = uich_get_stored_value( $key, $this->object_type( $target ), $this->object_id( $target ), null );
			}

			return array(
				'value'     => $value,
				'formatted' => uich_field_value( $name, $addr, true ),
			);
		}

		// ============================================================
		// WRITE
		// ============================================================

		/**
		 * Write one native field through uich_save_field_value().
		 *
		 * The module answers every refusal with a bare false - unknown field, no
		 * capability on the object, Pro type on Free - so each is checked first
		 * and returned as the specific error, rather than one generic "failed".
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Coerced value.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function write( $def, $value, $target ) {
			$pre = $this->precheck( $def, $target );
			if ( is_wp_error( $pre ) ) {
				return $pre;
			}

			$name = (string) $def['name'];

			if ( ! uich_save_field_value( $name, $value, $this->address( $target ) ) ) {
				return new WP_Error(
					'uich_field_write_failed',
					sprintf( 'UiChemy Custom Fields refused the write to "%s" on %s. The field resolved and the capability check passed, so the module\'s own sanitiser or storage rejected the value - read it back with action="get" and compare with what was sent.', $name, isset( $target['label'] ) ? $target['label'] : 'that object' )
				);
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Clear one native field.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function clear( $def, $target ) {
			$pre = $this->precheck( $def, $target );
			if ( is_wp_error( $pre ) ) {
				return $pre;
			}

			// False here also means "there was nothing stored", which is the
			// outcome a clear asks for, so it is not an error.
			uich_erase_field_value( (string) $def['name'], $this->address( $target ) );

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Assert the stored shape the module reads back.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array<int,string>
		 */
		public function assert_shape( $def, $target ) {
			$problems = array();
			$stored   = $this->read( $def, $target )['value'];

			if ( 'repeater' === ( isset( $def['type'] ) ? $def['type'] : '' ) && null !== $stored && ! is_array( $stored ) ) {
				$problems[] = sprintf( 'Repeater "%s" should be stored as an array of rows; "%s" is stored instead, which the module reads as no rows.', $def['name'], Uich_Field_Value::describe( $stored ) );
			}

			return $problems;
		}

		/**
		 * The refusals uich_save_field_value() would otherwise flatten to false.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		private function precheck( $def, $target ) {
			if ( empty( $def['writable'] ) ) {
				return new WP_Error( 'uich_field_not_writable', isset( $def['reason'] ) && '' !== $def['reason'] ? $def['reason'] : 'This field is not writable.' );
			}

			if ( 'options' === ( isset( $target['object_type'] ) ? $target['object_type'] : '' ) ) {
				return new WP_Error( 'uich_field_options_unsupported', 'UiChemy Custom Fields has no options pages, so there is no site-wide value to write. Store it on a post or term instead.' );
			}

			$name = (string) $def['name'];

			if ( function_exists( 'uich_maybe_get_field_info' ) && ! uich_maybe_get_field_info( $name, $this->address( $target ) ) ) {
				return new WP_Error(
					'uich_field_unknown',
					sprintf( 'UiChemy Custom Fields has no field "%s" reaching %s. Check the group\'s location rules with uichemy-composer/cpt (action="describe").', $name, isset( $target['label'] ) ? $target['label'] : 'that object' )
				);
			}

			if ( function_exists( 'uich_current_user_can_edit_object' ) && ! uich_current_user_can_edit_object(
				array(
					'type' => $this->object_type( $target ),
					'id'   => $this->object_id( $target ),
				)
			) ) {
				return new WP_Error( 'uich_field_forbidden', sprintf( 'You cannot edit %s, so its fields cannot be changed.', isset( $target['label'] ) ? $target['label'] : 'that object' ) );
			}

			return true;
		}

		// ============================================================
		// ADDRESSING
		// ============================================================

		/**
		 * The module's object address: 12, 'term_5', 'user_3'.
		 *
		 * Uich_Field_Guard already builds exactly this as `acf_id`.
		 *
		 * @param array $target Resolved target.
		 * @return int|string
		 */
		private function address( $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = $this->object_id( $target );

			if ( 'term' === $type || 'user' === $type ) {
				return $type . '_' . $id;
			}

			return $id;
		}

		/**
		 * The module's object type name for a target.
		 *
		 * @param array $target Resolved target.
		 * @return string post | term | user | option.
		 */
		private function object_type( $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			return 'options' === $type ? 'option' : $type;
		}

		/**
		 * The target's numeric id.
		 *
		 * @param array $target Resolved target.
		 * @return int
		 */
		private function object_id( $target ) {
			return isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
		}
	}
}
