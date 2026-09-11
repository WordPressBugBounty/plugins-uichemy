<?php
/**
 * The ACF field provider.
 *
 * ACF stores TWO rows per value: `price` holding the value, and `_price`
 * holding the field key. `update_post_meta()` writes the first and not the
 * second, so the value renders on the front end, shows blank in the editor, and
 * is erased by the next human save. `update_field()` writes both. Everything
 * here exists to make sure the second door is the only one used.
 *
 * BY NAME, never by key. Verified on ACF 6.8.9: writing a repeater by field key
 * stores its rows keyed `field_<key>_<sub>` where the reader looks for
 * `<name>_<i>_<sub>`, and the whole repeater renders empty with no error.
 *
 * ACF FREE vs PRO - read this before trusting a repeater test. Verified:
 * `acf_get_field_type('repeater')` returns false on ACF 6.8.9 free. `repeater`,
 * `flexible_content`, `gallery` and `clone` do not exist as field types there. A
 * field declared as a repeater on free falls through to ACF's scalar path and
 * stores ONE serialised array - which reads back intact through get_field(), so
 * a write passes, a read passes and a preview passes. ACF Pro reads that same
 * key as a row-count integer and looks for `name_0_*` rows, so it sees one
 * empty row. assert_shape() is what catches this, and field_type_available()
 * is what stops us pretending the type exists.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Field_Provider_ACF' ) ) {

	/**
	 * Reads and writes ACF fields through ACF's own API.
	 */
	final class Uich_Field_Provider_ACF extends Uich_Field_Provider {

		/**
		 * Provider slug.
		 *
		 * @return string
		 */
		public function slug() {
			return 'acf';
		}

		/**
		 * Provider label.
		 *
		 * @return string
		 */
		public function label() {
			return 'Advanced Custom Fields';
		}

		/**
		 * Whether ACF is active with the API surface we use.
		 *
		 * @return bool
		 */
		public function is_active() {
			return function_exists( 'acf_get_field_groups' )
				&& function_exists( 'acf_get_fields' )
				&& function_exists( 'update_field' )
				&& function_exists( 'get_field' );
		}

		/**
		 * Whether this ACF build really has a field type.
		 *
		 * The four types people buy ACF for are Pro-only, and ACF free does not
		 * refuse them - it stores them down a scalar path that looks correct.
		 *
		 * @param string $type ACF field type.
		 * @return bool
		 */
		public function field_type_available( $type ) {
			if ( ! function_exists( 'acf_get_field_type' ) ) {
				return true;
			}

			return (bool) acf_get_field_type( (string) $type );
		}

		/**
		 * Whether this is ACF Pro.
		 *
		 * @return bool
		 */
		public function is_acf_pro() {
			return $this->field_type_available( 'repeater' );
		}

		// ============================================================
		// DISCOVERY
		// ============================================================

		/**
		 * Field definitions ACF owns for one target.
		 *
		 * @param array $target Resolved target.
		 * @return array<string,array>
		 */
		public function definitions( $target ) {
			if ( ! $this->is_active() ) {
				return array();
			}

			$this->set_target_context( $target );

			$out = array();

			foreach ( acf_get_field_groups() as $group ) {
				if ( ! $this->group_targets( $group, $target ) ) {
					continue;
				}

				$fields = acf_get_fields( $group['key'] );
				if ( ! $fields ) {
					continue;
				}

				foreach ( $this->flatten( $fields, $group ) as $name => $def ) {
					$out[ $name ] = $def;
				}
			}

			return $out;
		}

		/**
		 * Every ACF field group on the site, with the object types it targets.
		 *
		 * Used by discovery (`custom-fields/list` with no object_id) and by
		 * `cpt/describe`, which has to answer "which field groups reach this post
		 * type" - the question that decides whether a binding can resolve.
		 *
		 * @return array<int,array>
		 */
		public function groups() {
			if ( ! $this->is_active() ) {
				return array();
			}

			$out = array();

			foreach ( acf_get_field_groups() as $group ) {
				$scope = self::group_scope( $group );

				$out[] = array(
					'provider'    => 'acf',
					'key'         => isset( $group['key'] ) ? (string) $group['key'] : '',
					'title'       => isset( $group['title'] ) ? (string) $group['title'] : '',
					'active'      => ! isset( $group['active'] ) || (bool) $group['active'],
					'scope'       => $scope,
					'field_count' => count( (array) acf_get_fields( $group['key'] ) ),
					'edit_url'    => isset( $group['ID'] ) ? admin_url( 'post.php?post=' . (int) $group['ID'] . '&action=edit' ) : '',
				);
			}

			return $out;
		}

		/**
		 * What an ACF group's location rules actually target.
		 *
		 * Returned as WordPress's own triple rather than a picker bucket, because
		 * an options page is NOT a post: filing one under `post` advertises a
		 * token that can never resolve, and `bind-field` then confirms it.
		 *
		 * @param array $group ACF field group.
		 * @return array{object_types:array<int,string>,post_types:array<int,string>,taxonomies:array<int,string>,options_pages:array<int,string>,unscoped:bool}
		 */
		public static function group_scope( $group ) {
			$scope = array(
				'object_types'  => array(),
				'post_types'    => array(),
				'taxonomies'    => array(),
				'options_pages' => array(),
				'unscoped'      => false,
			);

			if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
				// No location rules at all: the group shows nowhere in the admin,
				// so treating it as "every post" would be an invention.
				$scope['unscoped'] = true;
				return $scope;
			}

			foreach ( $group['location'] as $or ) {
				foreach ( (array) $or as $rule ) {
					$param = isset( $rule['param'] ) ? (string) $rule['param'] : '';
					$val   = isset( $rule['value'] ) ? (string) $rule['value'] : '';
					$op    = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';

					// A "!=" rule excludes; it never tells us what is included.
					if ( '==' !== $op ) {
						continue;
					}

					switch ( $param ) {
						case 'post_type':
							$scope['object_types'][] = 'post';
							if ( '' !== $val ) {
								$scope['post_types'][] = $val;
							}
							break;

						case 'post_template':
						case 'post_status':
						case 'post_format':
						case 'post_category':
						case 'post_taxonomy':
						case 'post':
						case 'page_type':
						case 'page_template':
						case 'page_parent':
						case 'page':
							$scope['object_types'][] = 'post';
							break;

						case 'taxonomy':
							$scope['object_types'][] = 'term';
							if ( '' !== $val ) {
								$scope['taxonomies'][] = $val;
							}
							break;

						case 'user_form':
						case 'user_role':
							$scope['object_types'][] = 'user';
							break;

						case 'options_page':
							$scope['object_types'][]  = 'options';
							$scope['options_pages'][] = '' !== $val ? $val : 'options';
							break;

						case 'attachment':
							$scope['object_types'][] = 'post';
							$scope['post_types'][]   = 'attachment';
							break;

						case 'comment':
						case 'widget':
						case 'nav_menu':
						case 'nav_menu_item':
							// Real ACF locations the field layer cannot address.
							break;
					}
				}
			}

			foreach ( array( 'object_types', 'post_types', 'taxonomies', 'options_pages' ) as $k ) {
				$scope[ $k ] = array_values( array_unique( $scope[ $k ] ) );
			}

			if ( ! $scope['object_types'] ) {
				$scope['unscoped'] = true;
			}

			return $scope;
		}

		/**
		 * Whether a group's location reaches this target.
		 *
		 * @param array $group  ACF field group.
		 * @param array $target Resolved target.
		 * @return bool
		 */
		private function group_targets( $group, $target ) {
			$scope = self::group_scope( $group );
			$type  = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$sub   = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';

			if ( ! in_array( $type, $scope['object_types'], true ) ) {
				return false;
			}

			if ( 'post' === $type && $scope['post_types'] && '' !== $sub ) {
				return in_array( $sub, $scope['post_types'], true );
			}

			if ( 'term' === $type && $scope['taxonomies'] && '' !== $sub ) {
				return in_array( $sub, $scope['taxonomies'], true );
			}

			if ( 'options' === $type && $scope['options_pages'] && '' !== $sub && 'option' !== $sub ) {
				return in_array( $sub, $scope['options_pages'], true );
			}

			return true;
		}

		/**
		 * Flatten a group's fields into name => normalised definition.
		 *
		 * acf_get_fields() returns the top level only, so without the recursion
		 * a model can build a repeater loop and then cannot write its body - it
		 * has no way to learn the sub-field names.
		 *
		 * @param array $fields ACF field arrays.
		 * @param array $group  Owning field group.
		 * @param int   $depth  Recursion depth guard.
		 * @return array<string,array>
		 */
		private function flatten( $fields, $group, $depth = 0 ) {
			$out = array();

			foreach ( (array) $fields as $field ) {
				if ( empty( $field['name'] ) ) {
					// Layout-only fields (tab, message, accordion) have no name
					// and hold no value.
					continue;
				}

				$def = $this->describe_field( $field, $group, $depth );

				$out[ (string) $field['name'] ] = $def;
			}

			return $out;
		}

		/**
		 * Normalise one ACF field array.
		 *
		 * @param array $field ACF field.
		 * @param array $group Owning field group.
		 * @param int   $depth Recursion depth.
		 * @return array
		 */
		private function describe_field( $field, $group, $depth = 0 ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$shape = Uich_Field_Value::shape_for( $type, 'acf' );

			$constraints = array();
			foreach ( array( 'min', 'max', 'step', 'maxlength', 'min_rows', 'max_rows', 'multiple', 'taxonomy', 'post_type', 'allow_null', 'layout' ) as $k ) {
				if ( isset( $field[ $k ] ) && '' !== $field[ $k ] && array() !== $field[ $k ] ) {
					$constraints[ $k ] = $field[ $k ];
				}
			}

			$def = array(
				'name'            => (string) $field['name'],
				'key'             => isset( $field['key'] ) ? (string) $field['key'] : null,
				'label'           => isset( $field['label'] ) ? (string) $field['label'] : '',
				'instructions'    => isset( $field['instructions'] ) ? (string) $field['instructions'] : '',
				'type'            => $type,
				'value_shape'     => $shape,
				'shape'           => Uich_Field_Value::structure_for( $type ),
				'required'        => ! empty( $field['required'] ),
				'choices'         => isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : null,
				'constraints'     => $constraints,
				'default'         => isset( $field['default_value'] ) ? $field['default_value'] : null,
				// Carried because it is NOT cosmetic. Verified:
				// {{ post.meta('hero').src('large') }} works for Image ID and
				// Image Array and renders EMPTY for Image URL - while
				// describe-site's own binding hint recommends exactly that token.
				'return_format'   => isset( $field['return_format'] ) ? (string) $field['return_format'] : null,
				'group'           => isset( $group['title'] ) ? (string) $group['title'] : '',
				'group_key'       => isset( $group['key'] ) ? (string) $group['key'] : '',
				'object_subtypes' => self::group_scope( $group )['post_types'],
			);

			// Sub-fields and layouts, recursed once - deep enough for a repeater
			// inside a repeater, shallow enough not to walk a clone loop.
			if ( $depth < 3 && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$def['sub_fields'] = array_values( $this->flatten( $field['sub_fields'], $group, $depth + 1 ) );
			}

			if ( $depth < 3 && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$layouts = array();
				foreach ( $field['layouts'] as $layout ) {
					$layouts[] = array(
						'name'       => isset( $layout['name'] ) ? (string) $layout['name'] : '',
						'label'      => isset( $layout['label'] ) ? (string) $layout['label'] : '',
						'sub_fields' => ! empty( $layout['sub_fields'] )
							? array_values( $this->flatten( $layout['sub_fields'], $group, $depth + 1 ) )
							: array(),
					);
				}
				$def['layouts'] = $layouts;
			}

			// Writability, and the reason when not.
			if ( 'flexible_content' === $type ) {
				$def['writable'] = false;
				$def['reason']   = 'ACF Flexible Content is on UiChemy\'s declared-unsupported list for writes: each row\'s shape depends on its layout name, which needs per-layout partials the render engine does not have. Readable, not writable.';
			} elseif ( ! $this->field_type_available( $type ) ) {
				$def['writable'] = false;
				$def['reason']   = sprintf(
					'Field type "%s" is not registered on this ACF build - it is an ACF PRO type and this site runs ACF free. A write would not fail; ACF would store one serialised array down its scalar path, which reads back intact here and appears as a single empty row the moment ACF Pro is installed. Refused for that reason. Install ACF Pro, or change the field to a type this build has.',
					$type
				);
			}

			return $this->normalise( $def );
		}

		// ============================================================
		// READ
		// ============================================================

		/**
		 * Read one ACF field.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,formatted:mixed}
		 */
		public function read( $def, $target ) {
			$acf_id = isset( $target['acf_id'] ) ? $target['acf_id'] : '';
			$name   = isset( $def['name'] ) ? $def['name'] : '';

			// $format_value = false gives the STORED value: the one that is safe
			// to send back in a write. true gives the formatted one, which is for
			// display only.
			$raw       = get_field( $name, $acf_id, false );
			$formatted = get_field( $name, $acf_id, true );

			return array(
				'value'     => $raw,
				'formatted' => $formatted,
			);
		}

		// ============================================================
		// WRITE
		// ============================================================

		/**
		 * Write one ACF field through update_field(), by name.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Coerced value.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function write( $def, $value, $target ) {
			if ( empty( $def['writable'] ) ) {
				return new WP_Error( 'uich_field_not_writable', isset( $def['reason'] ) ? $def['reason'] : 'This field is not writable.' );
			}

			$name   = isset( $def['name'] ) ? $def['name'] : '';
			$acf_id = isset( $target['acf_id'] ) ? $target['acf_id'] : '';

			// By NAME. update_field() resolves the name to the field object and
			// writes both the value row and the `_name` reference row; passing
			// the key here is the verified corruption path for repeaters.
			$ok = update_field( $name, $value, $acf_id );

			if ( false === $ok ) {
				return new WP_Error(
					'uich_field_write_failed',
					sprintf( 'ACF refused the write to "%s" on %s. update_field() returned false, which usually means the field is not attached to this object - check that a field group targeting it exists (uichemy-composer/cpt, action="describe").', $name, isset( $target['label'] ) ? $target['label'] : 'that object' )
				);
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Clear one ACF field, including its reference row.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function clear( $def, $target ) {
			$name   = isset( $def['name'] ) ? $def['name'] : '';
			$acf_id = isset( $target['acf_id'] ) ? $target['acf_id'] : '';

			if ( function_exists( 'delete_field' ) ) {
				delete_field( $name, $acf_id );
			} else {
				update_field( $name, '', $acf_id );
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Assert the storage shape ACF expects, not that the value echoes back.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array<int,string>
		 */
		public function assert_shape( $def, $target ) {
			$problems = array();

			$name = isset( $def['name'] ) ? $def['name'] : '';
			$type = isset( $def['type'] ) ? $def['type'] : '';

			$ref = $this->read_reference_row( $name, $target );

			// The reference row is the whole reason update_field() exists. Its
			// absence is what makes a value render on the front end and show
			// blank in the editor, and it is invisible to any read.
			if ( '' === (string) $ref ) {
				$problems[] = sprintf(
					'ACF\'s reference row "_%s" is missing on %s. The value will render but the editor will show the field as empty, and the next human save will erase it. This means the write went through update_post_meta() somewhere rather than update_field().',
					$name,
					isset( $target['label'] ) ? $target['label'] : 'the object'
				);
			} elseif ( ! empty( $def['key'] ) && (string) $ref !== (string) $def['key'] ) {
				$problems[] = sprintf( 'ACF\'s reference row "_%s" points at "%s" but this field\'s key is "%s".', $name, $ref, $def['key'] );
			}

			// A repeater is a row COUNT plus flat `name_0_sub` rows. One
			// serialised array in the parent key is the ACF-free failure.
			if ( 'repeater' === $type ) {
				$stored = $this->read_raw_meta( $name, $target );

				if ( is_array( $stored ) || ( is_string( $stored ) && is_array( maybe_unserialize( $stored ) ) ) ) {
					$problems[] = sprintf(
						'Repeater "%s" is stored as ONE serialised array instead of a row count plus "%s_0_*" rows. It reads back intact here and ACF Pro sees a single empty row. This is the ACF-free scalar path - the repeater field type does not exist on this build.',
						$name,
						$name
					);
				} elseif ( ! is_numeric( $stored ) ) {
					$problems[] = sprintf( 'Repeater "%s" should store a row-count integer; "%s" is stored instead.', $name, Uich_Field_Value::describe( $stored ) );
				}
			}

			return $problems;
		}

		/**
		 * ACF's `_name` reference row for one field on one object.
		 *
		 * @param string $name   Field name.
		 * @param array  $target Resolved target.
		 * @return string
		 */
		private function read_reference_row( $name, $target ) {
			return (string) $this->read_raw_meta( '_' . $name, $target );
		}

		/**
		 * Read a raw meta row for the target, whatever object type it is.
		 *
		 * Deliberately bypasses ACF: the point of assert_shape() is to see what
		 * is actually in storage, which is exactly what ACF's readers hide.
		 *
		 * @param string $key    Meta key.
		 * @param array  $target Resolved target.
		 * @return mixed
		 */
		private function read_raw_meta( $key, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					return get_term_meta( $id, $key, true );
				case 'user':
					return get_user_meta( $id, $key, true );
				case 'options':
					return get_option( 'options_' . $key, '' );
				default:
					return get_post_meta( $id, $key, true );
			}
		}
	}
}
