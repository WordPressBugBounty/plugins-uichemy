<?php
/**
 * The JetEngine field provider.
 *
 * JetEngine's storage IS raw post/term/user meta - there is no reference row and
 * no accessor of its own - so `update_post_meta()` is the correct write here,
 * and using ACF's `update_field()` on a JetEngine field would create an ACF
 * reference row JetEngine never reads. That is why the two providers do not
 * share code even where the concepts match.
 *
 * The concepts match and the STORAGE does not, in four places that all fail
 * silently:
 *
 *   boolean   ACF "1"/"0"          JetEngine 'true'/''
 *   repeater  flat name_0_sub rows JetEngine one serialised array
 *   checkbox  a list of values     JetEngine a { value: true } map
 *   media     the attachment id    JetEngine id OR url, per field (value_format)
 *
 * Discovery reads jet_engine()->meta_boxes, whose store is keyed by post type,
 * taxonomy slug, or the literal string "Default user fields". Those keys are
 * NOT all post types - treating them as post types is the mistake that makes a
 * user field appear as a post field.
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

if ( ! class_exists( 'Uich_Field_Provider_Jet' ) ) {

	/**
	 * Reads and writes JetEngine meta fields.
	 */
	final class Uich_Field_Provider_Jet extends Uich_Field_Provider {

		/**
		 * The literal store key JetEngine uses for its default user fields.
		 */
		const USER_STORE_KEY = 'Default user fields';

		/**
		 * Provider slug.
		 *
		 * @return string
		 */
		public function slug() {
			return 'jetengine';
		}

		/**
		 * Provider label.
		 *
		 * @return string
		 */
		public function label() {
			return 'JetEngine';
		}

		/**
		 * Whether JetEngine is active with the meta-boxes component loaded.
		 *
		 * Probed rather than assumed: JetEngine's components are individually
		 * switchable, so the plugin can be active with no meta-boxes manager.
		 *
		 * @return bool
		 */
		public function is_active() {
			return function_exists( 'jet_engine' )
				&& ! empty( jet_engine()->meta_boxes )
				&& method_exists( jet_engine()->meta_boxes, 'get_registered_fields' );
		}

		// ============================================================
		// DISCOVERY
		// ============================================================

		/**
		 * Field definitions JetEngine owns for one target.
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
			$sub  = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';

			if ( 'options' === $type ) {
				// JetEngine options pages have their own store
				// (jet_engine()->options_pages) and their own read/write path;
				// they are not meta on any object. Declared, not guessed.
				return array();
			}

			$store_key = 'user' === $type ? self::USER_STORE_KEY : $sub;

			if ( '' === (string) $store_key ) {
				return array();
			}

			$fields = $this->fields_for_store( $store_key );
			$out    = array();

			foreach ( $fields as $field ) {
				$def = $this->describe_field( $field, $store_key );
				if ( $def ) {
					$out[ $def['name'] ] = $def;
				}
			}

			return $out;
		}

		/**
		 * Raw JetEngine field rows for one store key.
		 *
		 * @param string $store_key Post type, taxonomy slug, or USER_STORE_KEY.
		 * @return array<int,array>
		 */
		private function fields_for_store( $store_key ) {
			$manager = jet_engine()->meta_boxes;

			if ( method_exists( $manager, 'get_meta_fields_for_object' ) ) {
				return (array) $manager->get_meta_fields_for_object( $store_key );
			}

			$all = (array) $manager->get_registered_fields();

			return isset( $all[ $store_key ] ) ? (array) $all[ $store_key ] : array();
		}

		/**
		 * Everything JetEngine has registered, grouped by its own store key.
		 *
		 * Used by discovery and by cpt/describe. Each group reports what KIND of
		 * store key it is, so a caller never has to guess whether
		 * "Default user fields" is a post type.
		 *
		 * @return array<int,array>
		 */
		public function groups() {
			if ( ! $this->is_active() ) {
				return array();
			}

			$all        = (array) jet_engine()->meta_boxes->get_registered_fields();
			$post_types = get_post_types( array(), 'names' );
			$taxonomies = get_taxonomies( array(), 'names' );
			$out        = array();

			foreach ( $all as $store_key => $fields ) {
				$store_key = (string) $store_key;

				if ( self::USER_STORE_KEY === $store_key ) {
					$object_type = 'user';
					$subtype     = 'user';
				} elseif ( isset( $post_types[ $store_key ] ) ) {
					$object_type = 'post';
					$subtype     = $store_key;
				} elseif ( isset( $taxonomies[ $store_key ] ) ) {
					$object_type = 'term';
					$subtype     = $store_key;
				} else {
					$object_type = 'unknown';
					$subtype     = $store_key;
				}

				$out[] = array(
					'provider'       => 'jetengine',
					// The store key IS the addressable key for these: fields
					// declared on the post type or taxonomy itself (JetEngine =>
					// Post Types => Meta Fields) are reached by its slug.
					'key'            => $store_key,
					'title'          => $store_key,
					'stored_on'      => 'post' === $object_type ? 'post type' : ( 'term' === $object_type ? 'taxonomy' : $object_type ),
					'object_type'    => $object_type,
					'object_subtype' => $subtype,
					'field_count'    => count( (array) $fields ),
					'edit_url'       => 'post' === $object_type
						? admin_url( 'admin.php?page=jet-engine-cpt' )
						: admin_url( 'admin.php?page=jet-engine-meta' ),
				);
			}

			// Meta boxes as rows of their own. JetEngine merges a box's fields
			// into the same per-object store as the post type's own, so without
			// this a caller reading cpt/describe never learns a box's id and has
			// no way to address it with add-fields.
			foreach ( $this->meta_boxes() as $box ) {
				$out[] = $box;
			}

			return $out;
		}

		/**
		 * JetEngine meta boxes, as group rows.
		 *
		 * @return array<int,array>
		 */
		private function meta_boxes() {
			$manager = jet_engine()->meta_boxes;

			if ( empty( $manager->data ) || ! method_exists( $manager->data, 'get_raw' ) ) {
				return array();
			}

			$out = array();

			foreach ( (array) $manager->data->get_raw() as $id => $box ) {
				if ( ! is_array( $box ) ) {
					continue;
				}

				$args = isset( $box['args'] ) ? (array) $box['args'] : array();
				$kind = isset( $args['object_type'] ) ? (string) $args['object_type'] : 'post';

				// JetEngine calls the taxonomy scope "tax"; WordPress - and every
				// other payload here - calls the object type "term".
				$object_type = 'tax' === $kind ? 'term' : $kind;

				$subtypes = 'term' === $object_type
					? (array) ( isset( $args['allowed_tax'] ) ? $args['allowed_tax'] : array() )
					: (array) ( isset( $args['allowed_post_type'] ) ? $args['allowed_post_type'] : array() );

				if ( 'user' === $object_type ) {
					$subtypes = array( 'user' );
				}

				$out[] = array(
					'provider'       => 'jetengine',
					'key'            => (string) $id,
					'title'          => isset( $args['name'] ) ? (string) $args['name'] : (string) $id,
					'stored_on'      => 'meta box',
					'object_type'    => $object_type,
					'object_subtype' => isset( $subtypes[0] ) ? (string) $subtypes[0] : '',
					'object_subtypes' => array_values( array_map( 'strval', $subtypes ) ),
					'field_count'    => count( (array) ( isset( $box['meta_fields'] ) ? $box['meta_fields'] : array() ) ),
					'edit_url'       => admin_url( 'admin.php?page=jet-engine-meta&cpt_meta_action=edit&id=' . $id ),
				);
			}

			return $out;
		}

		/**
		 * Normalise one JetEngine field row.
		 *
		 * @param array  $field     Raw JetEngine field.
		 * @param string $store_key Store key it came from.
		 * @return array|null Null for rows that hold no value.
		 */
		private function describe_field( $field, $store_key ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				return null;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			if ( in_array( $type, array( 'html', 'tab', 'heading' ), true ) ) {
				return null;
			}

			$shape = Uich_Field_Value::shape_for( $type, 'jetengine' );

			$constraints = array();
			foreach ( array( 'min', 'max', 'step', 'is_timestamp', 'is_array', 'input_type', 'allow_custom' ) as $k ) {
				if ( isset( $field[ $k ] ) && '' !== $field[ $k ] ) {
					$constraints[ $k ] = $field[ $k ];
				}
			}

			$def = array(
				'name'            => (string) $field['name'],
				// JetEngine's `id` is a UI row identifier, not an addressable key
				// - the meta key IS the name. Reported so two identically named
				// fields in different meta boxes can be told apart.
				'key'             => isset( $field['id'] ) ? (string) $field['id'] : null,
				'label'           => isset( $field['title'] ) ? (string) $field['title'] : '',
				'instructions'    => isset( $field['description'] ) ? (string) $field['description'] : '',
				'type'            => $type,
				'value_shape'     => $shape,
				'shape'           => Uich_Field_Value::structure_for( $type ),
				'required'        => ! empty( $field['is_required'] ),
				'choices'         => $this->choices_for( $field ),
				'constraints'     => $constraints,
				'default'         => isset( $field['default'] ) ? $field['default'] : null,
				// For media fields this is JetEngine's value_format: 'id' or
				// 'url'. Storing the wrong one renders nothing.
				'return_format'   => isset( $field['value_format'] ) ? (string) $field['value_format'] : null,
				'group'           => $store_key,
				'group_key'       => $store_key,
				'object_subtypes' => self::USER_STORE_KEY === $store_key ? array() : array( $store_key ),
			);

			if ( 'repeater' === $type ) {
				$subs = array();
				foreach ( (array) ( isset( $field['repeater-fields'] ) ? $field['repeater-fields'] : array() ) as $sub ) {
					$sub_def = $this->describe_field( $sub, $store_key );
					if ( $sub_def ) {
						$subs[] = $sub_def;
					}
				}
				$def['sub_fields'] = $subs;
			}

			return $this->normalise( $def );
		}

		/**
		 * Resolve a field's allowed values, including through a glossary.
		 *
		 * A literal choice list is not enough: JetEngine lets a select's options
		 * come from a glossary, and a field configured that way carries an empty
		 * `options` array. Reporting no choices there tells a caller the field
		 * accepts anything, and it does not.
		 *
		 * @param array $field Raw JetEngine field.
		 * @return array<string,string>|null Value => label.
		 */
		private function choices_for( $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( ! in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				return null;
			}

			$source = isset( $field['options_source'] ) ? (string) $field['options_source'] : '';

			// Glossary-backed options.
			$glossary_id = isset( $field['glossary_id'] ) ? (int) $field['glossary_id'] : 0;

			if ( ( 'glossary' === $source || ! empty( $field['options_from_glossary'] ) ) && $glossary_id ) {
				$resolved = $this->glossary_choices( $glossary_id );
				if ( null !== $resolved ) {
					return $resolved;
				}

				return array();
			}

			// Bulk options: newline-separated "value::label".
			if ( 'manual_bulk' === $source ) {
				$out = array();
				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( isset( $field['bulk_options'] ) ? $field['bulk_options'] : '' ) ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$parts             = explode( '::', $line );
					$value             = $parts[0];
					$out[ $value ]     = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $value;
				}
				return $out;
			}

			if ( 'taxonomy' === $source ) {
				$tax = isset( $field['options_tax'] ) ? (string) $field['options_tax'] : '';
				return array(
					'__source' => sprintf( 'Terms of taxonomy "%s" - resolve them with uichemy-composer/describe-site (action="entities", kind="terms").', $tax ),
				);
			}

			// Manual options: [ { key, value, is_checked } ].
			$out = array();
			foreach ( (array) ( isset( $field['options'] ) ? $field['options'] : array() ) as $key => $option ) {
				if ( is_array( $option ) ) {
					$value         = isset( $option['key'] ) ? (string) $option['key'] : (string) $key;
					$out[ $value ] = isset( $option['value'] ) ? (string) $option['value'] : $value;
					continue;
				}
				$out[ (string) $key ] = (string) $option;
			}

			return $out;
		}

		/**
		 * Value => label pairs from one JetEngine glossary.
		 *
		 * @param int $glossary_id Glossary id.
		 * @return array<string,string>|null Null when glossaries are unavailable.
		 */
		private function glossary_choices( $glossary_id ) {
			if ( empty( jet_engine()->glossaries ) || empty( jet_engine()->glossaries->settings ) ) {
				return null;
			}

			$items = jet_engine()->glossaries->settings->get();

			if ( ! is_array( $items ) ) {
				return null;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || (int) ( isset( $item['id'] ) ? $item['id'] : 0 ) !== (int) $glossary_id ) {
					continue;
				}

				$out = array();
				foreach ( (array) ( isset( $item['fields'] ) ? $item['fields'] : array() ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$value         = isset( $row['key'] ) ? (string) $row['key'] : '';
					$out[ $value ] = isset( $row['value'] ) ? (string) $row['value'] : $value;
				}

				return $out;
			}

			return null;
		}

		// ============================================================
		// READ / WRITE
		// ============================================================

		/**
		 * Read one JetEngine field.
		 *
		 * JetEngine has no formatting accessor of its own on the read side, so
		 * `formatted` differs from `value` only where UiChemy can add something
		 * a caller cannot derive - a choice label, or a timestamp as a date.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,formatted:mixed}
		 */
		public function read( $def, $target ) {
			$raw = $this->read_meta( $def['name'], $target );

			return array(
				'value'     => $raw,
				'formatted' => $this->format( $def, $raw ),
			);
		}

		/**
		 * A display form for a JetEngine value.
		 *
		 * @param array $def Normalised definition.
		 * @param mixed $raw Stored value.
		 * @return mixed
		 */
		private function format( $def, $raw ) {
			$shape = isset( $def['value_shape'] ) ? $def['value_shape'] : 'string';

			if ( in_array( $shape, array( 'date', 'datetime', 'time' ), true ) && is_numeric( $raw ) && (int) $raw > 100000 ) {
				$fmt = 'time' === $shape ? 'H:i' : ( 'datetime' === $shape ? 'Y-m-d H:i' : 'Y-m-d' );
				return gmdate( $fmt, (int) $raw );
			}

			if ( 'bool' === $shape ) {
				return ( 'true' === $raw || true === $raw || '1' === (string) $raw );
			}

			$choices = isset( $def['choices'] ) && is_array( $def['choices'] ) ? $def['choices'] : array();

			if ( $choices && 'choice' === $shape && isset( $choices[ (string) $raw ] ) ) {
				return $choices[ (string) $raw ];
			}

			if ( $choices && 'choices' === $shape && is_array( $raw ) ) {
				$labels = array();
				// JetEngine stores a multi-checkbox as { value: true }, so the
				// selected values are the KEYS, not the values.
				foreach ( $raw as $key => $on ) {
					if ( ! $on ) {
						continue;
					}
					$labels[] = isset( $choices[ (string) $key ] ) ? $choices[ (string) $key ] : (string) $key;
				}
				return $labels;
			}

			return $raw;
		}

		/**
		 * Write one JetEngine field as raw meta.
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

			$ok = $this->write_meta( $def['name'], $value, $target );

			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Clear one JetEngine field.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function clear( $def, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
			$key  = $def['name'];

			switch ( $type ) {
				case 'term':
					delete_term_meta( $id, $key );
					break;
				case 'user':
					delete_user_meta( $id, $key );
					break;
				default:
					delete_post_meta( $id, $key );
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Read one meta row for the target.
		 *
		 * @param string $key    Meta key.
		 * @param array  $target Resolved target.
		 * @return mixed
		 */
		private function read_meta( $key, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					return get_term_meta( $id, $key, true );
				case 'user':
					return get_user_meta( $id, $key, true );
				default:
					return get_post_meta( $id, $key, true );
			}
		}

		/**
		 * Write one meta row for the target.
		 *
		 * @param string $key    Meta key.
		 * @param mixed  $value  Value.
		 * @param array  $target Resolved target.
		 * @return true|WP_Error
		 */
		private function write_meta( $key, $value, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					$res = update_term_meta( $id, $key, $value );
					break;
				case 'user':
					$res = update_user_meta( $id, $key, $value );
					break;
				default:
					$res = update_post_meta( $id, $key, $value );
			}

			if ( is_wp_error( $res ) ) {
				return $res;
			}

			// update_*_meta() returns false both for a failure and for "the value
			// was already exactly this", so a bare false is not an error - a
			// read-back is the only way to tell, and the writer does that.
			return true;
		}

		/**
		 * Assert JetEngine's storage shape.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array<int,string>
		 */
		public function assert_shape( $def, $target ) {
			$problems = array();
			$name     = isset( $def['name'] ) ? $def['name'] : '';
			$type     = isset( $def['type'] ) ? $def['type'] : '';
			$stored   = $this->read_meta( $name, $target );

			// JetEngine reads a repeater as one array. Flat ACF-style rows here
			// would mean an ACF write reached a JetEngine field.
			if ( 'repeater' === $type && '' !== $stored && ! is_array( $stored ) ) {
				$problems[] = sprintf(
					'JetEngine repeater "%s" should store one array; "%s" is stored instead. If flat "%s_0_*" rows exist, an ACF-style write reached a JetEngine field - JetEngine will read nothing.',
					$name,
					Uich_Field_Value::describe( $stored ),
					$name
				);
			}

			// An ACF reference row on a JetEngine field means the wrong provider
			// wrote it. JetEngine ignores the row, so this is invisible.
			$acf_ref = $this->read_meta( '_' . $name, $target );
			if ( is_string( $acf_ref ) && 0 === strpos( $acf_ref, 'field_' ) ) {
				$problems[] = sprintf(
					'"_%s" holds an ACF field key, so this field is claimed by ACF as well as JetEngine. Whichever plugin the template reads through will win; pass provider="acf" or provider="jetengine" explicitly to stop the ambiguity.',
					$name
				);
			}

			return $problems;
		}
	}
}
