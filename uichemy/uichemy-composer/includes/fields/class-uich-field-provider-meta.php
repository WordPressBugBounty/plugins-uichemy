<?php
/**
 * The native registered-meta provider.
 *
 * Only meta that was declared through `register_meta()` / `register_post_meta()`
 * is offered here, and that is the point: a registered key has a plugin or theme
 * behind it that reads it back, a declared type, and a `show_in_rest` shape.
 *
 * There is deliberately NO generic fallback that writes any key the caller
 * names. A generic update_post_meta() escape hatch is how you get a field the
 * site never reads and an agent reporting success - the write returns true, the
 * read-back returns the value, and nothing on the site ever looks at it. So an
 * unknown name is refused, with the list of known ones, the way create-loop
 * already refuses an unknown post type.
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

if ( ! class_exists( 'Uich_Field_Provider_Meta' ) ) {

	/**
	 * Reads and writes meta declared through register_meta().
	 */
	final class Uich_Field_Provider_Meta extends Uich_Field_Provider {

		/**
		 * Provider slug.
		 *
		 * @return string
		 */
		public function slug() {
			return 'meta';
		}

		/**
		 * Provider label.
		 *
		 * @return string
		 */
		public function label() {
			return 'WordPress registered meta';
		}

		/**
		 * Always available - this is WordPress itself.
		 *
		 * @return bool
		 */
		public function is_active() {
			return true;
		}

		/**
		 * Registered meta for one target.
		 *
		 * @param array $target Resolved target.
		 * @return array<string,array>
		 */
		public function definitions( $target ) {
			$this->set_target_context( $target );

			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$sub  = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';

			if ( 'options' === $type ) {
				return array();
			}

			$registered = get_registered_meta_keys( $type, $sub );

			// Subtype-less registrations apply to every subtype of the type.
			if ( '' !== $sub ) {
				$registered = array_merge( get_registered_meta_keys( $type, '' ), (array) $registered );
			}

			$out = array();

			foreach ( (array) $registered as $key => $args ) {
				$key = (string) $key;

				// Protected keys are excluded from discovery, not just from
				// writes: offering one would advertise a field that is then
				// refused, which reads as a bug rather than a boundary.
				if ( is_wp_error( Uich_Field_Guard::meta_allowed( $key, $target ) ) ) {
					continue;
				}

				// `wp_`-prefixed registered meta is WordPress's own editor
				// plumbing - wp_pattern_sync_status, wp_persisted_preferences.
				// It is writable and it is never content, so offering it as a
				// bindable field is noise in the one payload that has to be
				// trustworthy.
				if ( 0 === strpos( $key, 'wp_' ) ) {
					continue;
				}

				$declared = isset( $args['type'] ) ? (string) $args['type'] : 'string';
				$single   = ! isset( $args['single'] ) || (bool) $args['single'];

				$shape = 'string';
				switch ( $declared ) {
					case 'integer':
					case 'number':
						$shape = 'number';
						break;
					case 'boolean':
						$shape = 'bool';
						break;
					case 'array':
						$shape = 'array';
						break;
					case 'object':
						$shape = 'object';
						break;
				}

				if ( ! $single ) {
					$shape = 'array';
				}

				$out[ $key ] = $this->normalise(
					array(
						'name'            => $key,
						'label'           => $key,
						'instructions'    => isset( $args['description'] ) ? (string) $args['description'] : '',
						'type'            => $declared,
						'value_shape'     => $shape,
						'default'         => isset( $args['default'] ) ? $args['default'] : null,
						'constraints'     => array( 'single' => $single ),
						'group'           => 'Registered meta',
						'group_key'       => 'registered_meta',
						'object_subtypes' => '' !== $sub ? array( $sub ) : array(),
					)
				);
			}

			return $out;
		}

		/**
		 * Read one registered meta value.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,formatted:mixed}
		 */
		public function read( $def, $target ) {
			$single = ! isset( $def['constraints']['single'] ) || (bool) $def['constraints']['single'];
			$value  = $this->meta( $target, $def['name'], $single );

			return array(
				'value'     => $value,
				'formatted' => $value,
			);
		}

		/**
		 * Write one registered meta value.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Coerced value.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function write( $def, $value, $target ) {
			$allowed = Uich_Field_Guard::meta_allowed( $def['name'], $target );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					update_term_meta( $id, $def['name'], $value );
					break;
				case 'user':
					update_user_meta( $id, $def['name'], $value );
					break;
				default:
					update_post_meta( $id, $def['name'], $value );
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Clear one registered meta value.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		public function clear( $def, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					delete_term_meta( $id, $def['name'] );
					break;
				case 'user':
					delete_user_meta( $id, $def['name'] );
					break;
				default:
					delete_post_meta( $id, $def['name'] );
			}

			$this->purge_caches( $target );

			return true;
		}

		/**
		 * Read a meta row for whichever object type the target is.
		 *
		 * @param array  $target Resolved target.
		 * @param string $key    Meta key.
		 * @param bool   $single Whether the key is single-valued.
		 * @return mixed
		 */
		private function meta( $target, $key, $single = true ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			switch ( $type ) {
				case 'term':
					return get_term_meta( $id, $key, $single );
				case 'user':
					return get_user_meta( $id, $key, $single );
				default:
					return get_post_meta( $id, $key, $single );
			}
		}
	}
}
