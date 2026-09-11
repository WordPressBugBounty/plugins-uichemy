<?php
/**
 * The contract a content-model provider implements.
 *
 * Five write operations, all additive, plus two ownership readers. Nothing here
 * renames, retypes or deletes: those are identity-changing and go through the
 * owning plugin's own admin screen, where the user is shown what they are about
 * to break. See Uich_Model::boundary() for the boundary as a caller reads it.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/model
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Model_Provider' ) ) {

	/**
	 * Base class for the ACF and JetEngine model providers.
	 */
	abstract class Uich_Model_Provider {

		/**
		 * Provider slug (`acf`, `jetengine`).
		 *
		 * @return string
		 */
		abstract public function slug();

		/**
		 * Human label.
		 *
		 * @return string
		 */
		abstract public function label();

		/**
		 * Whether the plugin is here with the API this provider calls.
		 *
		 * @return bool
		 */
		abstract public function is_active();

		/**
		 * Register a post type through the plugin's own API.
		 *
		 * @param array $args Normalised post-type arguments.
		 * @return array|WP_Error
		 */
		abstract public function register_post_type( $args );

		/**
		 * Register a taxonomy through the plugin's own API.
		 *
		 * @param array $args Normalised taxonomy arguments.
		 * @return array|WP_Error
		 */
		abstract public function register_taxonomy( $args );

		/**
		 * Create a field group targeting an object type.
		 *
		 * @param array $args   { title, object_type, object_subtype }.
		 * @param array $fields Normalised field descriptors (may be empty).
		 * @return array|WP_Error
		 */
		abstract public function register_field_group( $args, $fields );

		/**
		 * Append fields to an existing group.
		 *
		 * @param string $group_key Provider-native group key.
		 * @param array  $fields    Normalised field descriptors.
		 * @return array|WP_Error
		 */
		abstract public function add_fields( $group_key, $fields );

		/**
		 * Change a field's presentation and validation only.
		 *
		 * @param string $group_key Provider-native group key, or '' to search.
		 * @param string $name      Field name.
		 * @param array  $changes   Allowed changes.
		 * @return array|WP_Error
		 */
		abstract public function update_field( $group_key, $name, $changes );

		/**
		 * Post types this provider registered, so UiChemy knows which it can
		 * write back to.
		 *
		 * @return array<int,string>
		 */
		abstract public function owned_post_types();

		/**
		 * Taxonomies this provider registered.
		 *
		 * @return array<int,string>
		 */
		abstract public function owned_taxonomies();

		/**
		 * The provider-native field type for one of UiChemy's neutral types, or
		 * a WP_Error naming what this plugin has instead.
		 *
		 * @param string $type Neutral type.
		 * @return string|WP_Error
		 */
		abstract public function map_type( $type );

		/**
		 * Normalise the post-type arguments a caller may send.
		 *
		 * Shared, so `movie` gets the same defaults whichever plugin registers
		 * it - otherwise the same request produces a public archive on one
		 * plugin and no archive on the other.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		protected function normalise_post_type_args( $args ) {
			$args = is_array( $args ) ? $args : array();

			$slug = Uich_Model::validate_slug( isset( $args['slug'] ) ? $args['slug'] : '', 'post type' );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}

			if ( post_type_exists( $slug ) ) {
				return new WP_Error(
					'uich_model_post_type_exists',
					sprintf( 'The post type "%s" already exists. Add fields to it with action="register-field-group" or "add-fields" instead - UiChemy does not re-register or reconfigure an existing type, because changing its slug or supports breaks stored references.', $slug )
				);
			}

			$plural   = isset( $args['label'] ) && '' !== $args['label'] ? (string) $args['label'] : ucwords( str_replace( '_', ' ', $slug ) ) . 's';
			$singular = isset( $args['singular_label'] ) && '' !== $args['singular_label'] ? (string) $args['singular_label'] : ucwords( str_replace( '_', ' ', $slug ) );

			$supports = isset( $args['supports'] ) ? (array) $args['supports'] : array( 'title', 'editor', 'thumbnail', 'excerpt' );
			$supports = array_values( array_filter( array_map( 'sanitize_key', $supports ) ) );

			if ( ! $supports ) {
				$supports = array( 'title' );
			}

			// A post type built to be laid out by UiChemy needs a title and a
			// featured image at minimum: a template binding post.thumbnail on a
			// type with no thumbnail support renders empty forever, and the
			// binding is the thing an agent writes next.
			$notes = array();

			if ( ! in_array( 'title', $supports, true ) ) {
				$supports[] = 'title';
				$notes[]    = 'Added "title" to supports: a type with no title has nothing for post.title to bind to, and every listing prints a blank heading.';
			}

			$taxonomies = isset( $args['taxonomies'] ) ? array_map( 'sanitize_key', (array) $args['taxonomies'] ) : array();
			$missing    = array();

			foreach ( $taxonomies as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					$missing[] = $tax;
				}
			}

			if ( $missing ) {
				return new WP_Error(
					'uich_model_unknown_taxonomy',
					sprintf(
						'The taxonomies %s do not exist yet, so attaching them would register a post type pointing at nothing. Create them first with action="register-taxonomy" (object_types: ["%s"]), or drop them from this call and attach them when they exist.',
						'"' . implode( '", "', $missing ) . '"',
						$slug
					)
				);
			}

			return array(
				'slug'           => $slug,
				'label'          => $plural,
				'singular_label' => $singular,
				'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
				'public'         => ! isset( $args['public'] ) || (bool) $args['public'],
				'hierarchical'   => ! empty( $args['hierarchical'] ),
				'has_archive'    => ! isset( $args['has_archive'] ) ? true : (bool) $args['has_archive'],
				'show_in_rest'   => ! isset( $args['show_in_rest'] ) || (bool) $args['show_in_rest'],
				'supports'       => array_values( array_unique( $supports ) ),
				'taxonomies'     => $taxonomies,
				'menu_icon'      => isset( $args['menu_icon'] ) ? sanitize_text_field( (string) $args['menu_icon'] ) : 'dashicons-admin-post',
				'rewrite_slug'   => isset( $args['rewrite_slug'] ) && '' !== $args['rewrite_slug'] ? sanitize_title( (string) $args['rewrite_slug'] ) : str_replace( '_', '-', $slug ),
				'notes'          => $notes,
			);
		}

		/**
		 * Normalise the taxonomy arguments a caller may send.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		protected function normalise_taxonomy_args( $args ) {
			$args = is_array( $args ) ? $args : array();

			$slug = Uich_Model::validate_slug( isset( $args['slug'] ) ? $args['slug'] : '', 'taxonomy' );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}

			if ( taxonomy_exists( $slug ) ) {
				return new WP_Error(
					'uich_model_taxonomy_exists',
					sprintf( 'The taxonomy "%s" already exists, and UiChemy does not reconfigure one - changing a taxonomy\'s slug or hierarchy breaks every stored reference to it. Edit it in the owning plugin\'s screens. To create fields ON its terms, use action="register-field-group" with object_type="term", object_subtype="%s".', $slug, $slug )
				);
			}

			$objects = isset( $args['object_types'] ) ? array_map( 'sanitize_key', (array) $args['object_types'] ) : array();
			$objects = array_values( array_filter( $objects ) );

			if ( ! $objects ) {
				return new WP_Error(
					'uich_model_taxonomy_no_objects',
					'"object_types" is required: the post type slugs this taxonomy attaches to. A taxonomy attached to nothing is registered, invisible in the admin, and unqueryable.'
				);
			}

			$missing = array();

			foreach ( $objects as $pt ) {
				if ( ! post_type_exists( $pt ) ) {
					$missing[] = $pt;
				}
			}

			if ( $missing ) {
				return new WP_Error(
					'uich_model_unknown_post_type',
					sprintf( 'The post types %s do not exist. Register them first (action="register-post-type"), or fix the slugs - real ones: %s.', '"' . implode( '", "', $missing ) . '"', implode( ', ', get_post_types( array(), 'names' ) ) )
				);
			}

			$plural   = isset( $args['label'] ) && '' !== $args['label'] ? (string) $args['label'] : ucwords( str_replace( '_', ' ', $slug ) ) . 's';
			$singular = isset( $args['singular_label'] ) && '' !== $args['singular_label'] ? (string) $args['singular_label'] : ucwords( str_replace( '_', ' ', $slug ) );

			return array(
				'slug'              => $slug,
				'label'             => $plural,
				'singular_label'    => $singular,
				'description'       => isset( $args['description'] ) ? (string) $args['description'] : '',
				'object_types'      => $objects,
				'hierarchical'      => ! empty( $args['hierarchical'] ),
				'public'            => ! isset( $args['public'] ) || (bool) $args['public'],
				'show_in_rest'      => ! isset( $args['show_in_rest'] ) || (bool) $args['show_in_rest'],
				'show_admin_column' => ! isset( $args['show_admin_column'] ) || (bool) $args['show_admin_column'],
				'rewrite_slug'      => isset( $args['rewrite_slug'] ) && '' !== $args['rewrite_slug'] ? sanitize_title( (string) $args['rewrite_slug'] ) : str_replace( '_', '-', $slug ),
			);
		}

		/**
		 * The changes update_field() accepts, and the refusal for the rest.
		 *
		 * @param array $changes Incoming changes.
		 * @return array|WP_Error
		 */
		protected function allowed_field_changes( $changes ) {
			$changes = is_array( $changes ) ? $changes : array();

			$allowed = array( 'label', 'instructions', 'required', 'choices', 'options', 'default', 'placeholder' );
			$refused = Uich_Model::boundary()['refused'];

			$out = array();

			foreach ( $changes as $key => $value ) {
				$key = (string) $key;

				// `name` never arrives here (the router strips it - it addresses
				// the field), so a rename attempt shows up as one of the names a
				// caller reaches for instead. Naming them explicitly is what
				// turns a generic "unknown change" into the answer.
				if ( in_array( $key, array( 'name', 'field_name', 'new_name', 'rename', 'rename_to', 'meta_key' ), true ) ) {
					return new WP_Error( 'uich_model_rename_refused', 'Renaming a field is refused. ' . $refused['rename a field'] );
				}

				if ( in_array( $key, array( 'type', 'field_type' ), true ) ) {
					return new WP_Error( 'uich_model_retype_refused', 'Changing a field\'s type is refused. ' . $refused['change a field type'] );
				}

				if ( 'key' === $key ) {
					return new WP_Error( 'uich_model_rekey_refused', 'A field\'s key is its identity in the owning plugin\'s own storage and is never changed.' );
				}

				if ( ! in_array( $key, $allowed, true ) ) {
					return new WP_Error(
						'uich_model_unknown_change',
						sprintf( '"%s" is not something update-field changes. It accepts: %s. Anything else is either identity-changing (refused) or a setting to change in %s\'s own screen.', $key, implode( ', ', array_unique( $allowed ) ), $this->label() )
					);
				}

				$out[ $key ] = $value;
			}

			if ( ! $out ) {
				return new WP_Error(
					'uich_model_nothing_to_change',
					sprintf( 'Nothing to change. update-field accepts: %s.', implode( ', ', array_unique( $allowed ) ) )
				);
			}

			return $out;
		}
	}
}
