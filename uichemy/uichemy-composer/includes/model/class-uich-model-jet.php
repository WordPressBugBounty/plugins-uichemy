<?php
/**
 * The JetEngine content-model provider.
 *
 * JetEngine's own data controllers are the API: each component exposes a
 * `->data` object taking a flat request array through `set_request()` and then
 * `create_item( false )` / `edit_item( false )`. Those are the same calls
 * JetEngine's admin screens make, and the same ones its own MCP tools make - so
 * what UiChemy creates appears in JetEngine > Post Types / Taxonomies / Meta
 * Boxes and is editable there.
 *
 * Two shape traps, both handled here rather than pushed onto the caller:
 *
 *   - `set_request()` takes a FLAT array, while `get_item_for_edit()` returns a
 *     NESTED one (general_settings / labels / advanced_settings / meta_fields).
 *     Appending a field means flattening the second back into the first, or the
 *     edit silently drops every setting it does not see.
 *   - `create_item()` returns nothing on failure and pushes the reason onto the
 *     component's notice stack instead, so a bare falsy return has to be turned
 *     into the notices' text or the caller is told only that "it didn't work".
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

if ( ! class_exists( 'Uich_Model_Jet' ) ) {

	/**
	 * Creates post types, taxonomies and meta boxes through JetEngine.
	 */
	final class Uich_Model_Jet extends Uich_Model_Provider {

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
		 * Whether JetEngine is here with a data controller we can drive.
		 *
		 * @return bool
		 */
		public function is_active() {
			return function_exists( 'jet_engine' )
				&& ( $this->component( 'cpt' ) || $this->component( 'meta_boxes' ) );
		}

		/**
		 * One JetEngine component's data controller, or null.
		 *
		 * Probed with method_exists rather than assumed: JetEngine's components
		 * are individually switchable, so the plugin can be active with the
		 * post-types module off. A missing component becomes a specific refusal,
		 * never a fatal.
		 *
		 * @param string $name cpt | taxonomies | meta_boxes.
		 * @return object|null
		 */
		private function component( $name ) {
			if ( ! function_exists( 'jet_engine' ) ) {
				return null;
			}

			$engine = jet_engine();

			if ( empty( $engine->{$name} ) || empty( $engine->{$name}->data ) ) {
				return null;
			}

			$data = $engine->{$name}->data;

			if ( ! method_exists( $data, 'set_request' ) || ! method_exists( $data, 'create_item' ) ) {
				return null;
			}

			return $engine->{$name};
		}

		/**
		 * The refusal for a component that is switched off.
		 *
		 * @param string $name  Component property name.
		 * @param string $label What the user would call it.
		 * @return WP_Error
		 */
		private function no_component( $name, $label ) {
			return new WP_Error(
				'uich_model_jet_component_off',
				sprintf( 'JetEngine is active but its %s component is not available (property "%s"). Switch it on in JetEngine > Modules, then call this again.', $label, $name )
			);
		}

		// ============================================================
		// TYPE MAP
		// ============================================================

		/**
		 * UiChemy's neutral field type => JetEngine's own.
		 *
		 * @param string $type Neutral type.
		 * @return string|WP_Error
		 */
		public function map_type( $type ) {
			$map = array(
				'text'          => 'text',
				'textarea'      => 'textarea',
				'wysiwyg'       => 'wysiwyg',
				'number'        => 'number',
				// JetEngine has no dedicated email or url field; both are text
				// with a different HTML input type, which is what its own UI
				// produces too.
				'email'         => 'text',
				'url'           => 'text',
				'boolean'       => 'switcher',
				'select'        => 'select',
				'radio'         => 'radio',
				'checkbox'      => 'checkbox',
				'image'         => 'media',
				'file'          => 'media',
				'gallery'       => 'gallery',
				'date'          => 'date',
				'datetime'      => 'datetime-local',
				'time'          => 'time',
				'color'         => 'colorpicker',
				'post_relation' => 'posts',
				'taxonomy'      => 'taxonomy',
				'repeater'      => 'repeater',
			);

			$type = (string) $type;

			if ( 'link' === $type ) {
				return new WP_Error(
					'uich_model_jet_no_link_field',
					'JetEngine has no link field (ACF\'s { url, title, target } object). Model it as two text fields - one for the URL and one for the label - which is what JetEngine users do, and what a template can bind member by member.'
				);
			}

			if ( ! isset( $map[ $type ] ) ) {
				return new WP_Error( 'uich_model_bad_field_type', sprintf( 'JetEngine has no field for UiChemy type "%s".', $type ) );
			}

			return $map[ $type ];
		}

		// ============================================================
		// POST TYPES
		// ============================================================

		/**
		 * Register a post type through JetEngine.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_post_type( $args ) {
			$cpt = $this->component( 'cpt' );

			if ( ! $cpt ) {
				return $this->no_component( 'cpt', 'Custom Post Types' );
			}

			$args = $this->normalise_post_type_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$cpt->data->set_request(
				array(
					'name'                => $args['label'],
					'slug'                => $args['slug'],
					'singular_name'       => $args['singular_label'],
					'menu_name'           => $args['label'],
					'name_admin_bar'      => $args['singular_label'],
					'add_new_item'        => sprintf( 'Add New %s', $args['singular_label'] ),
					'edit_item'           => sprintf( 'Edit %s', $args['singular_label'] ),
					'view_item'           => sprintf( 'View %s', $args['singular_label'] ),
					'all_items'           => sprintf( 'All %s', $args['label'] ),
					'search_items'        => sprintf( 'Search %s', $args['label'] ),
					'not_found'           => sprintf( 'No %s found', strtolower( $args['label'] ) ),
					'public'              => $args['public'],
					'publicly_queryable'  => $args['public'],
					'exclude_from_search' => ! $args['public'],
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_nav_menus'   => true,
					'show_in_rest'        => $args['show_in_rest'],
					'query_var'           => true,
					'rewrite'             => true,
					'with_front'          => true,
					'has_archive'         => $args['has_archive'],
					'hierarchical'        => $args['hierarchical'],
					'rewrite_slug'        => $args['rewrite_slug'],
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
					'menu_position'       => null,
					'menu_icon'           => $args['menu_icon'],
					'supports'            => $args['supports'],
					'admin_columns'       => array(),
					'admin_filters'       => array(),
					'meta_fields'         => array(),
					'custom_storage'      => false,
					'show_edit_link'      => false,
					'hide_field_names'    => false,
					'delete_metadata'     => false,
				)
			);

			$item_id = $this->run( $cpt, 'create_item' );

			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}

			$this->after_model_write();

			$notes = array_merge(
				$args['notes'],
				array(
					'JetEngine registers a post type on the next page load, so post_type_exists() can be false for the rest of this request. Anything that has to see it - a field group, a loop, a template condition - belongs in a separate call.',
					'Custom fields go in a JetEngine meta box: call action="register-field-group" with object_subtype="' . $args['slug'] . '".',
				)
			);

			if ( $args['taxonomies'] ) {
				// JetEngine attaches a taxonomy from the TAXONOMY's own side
				// (object_type), not from the post type's - so passing them here
				// would be accepted and do nothing.
				$notes[] = sprintf(
					'The taxonomies %s were NOT attached: JetEngine attaches a taxonomy from the taxonomy\'s own side. Call action="register-taxonomy" with object_types: ["%s"], or edit the existing taxonomy in JetEngine > Taxonomies.',
					'"' . implode( '", "', $args['taxonomies'] ) . '"',
					$args['slug']
				);
			}

			return array(
				'provider'       => 'jetengine',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'item_id'        => $item_id,
				'registered_now' => post_type_exists( $args['slug'] ),
				'supports'       => $args['supports'],
				'has_archive'    => $args['has_archive'],
				'archive_url'    => $args['has_archive'] ? home_url( '/' . $args['rewrite_slug'] . '/' ) : null,
				'edit_url'       => admin_url( 'admin.php?page=jet-engine-cpt&cpt_action=edit&id=' . $item_id ),
				'items_url'      => admin_url( 'edit.php?post_type=' . $args['slug'] ),
				'notes'          => $notes,
			);
		}

		/**
		 * Register a taxonomy through JetEngine.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_taxonomy( $args ) {
			$tax = $this->component( 'taxonomies' );

			if ( ! $tax ) {
				return $this->no_component( 'taxonomies', 'Taxonomies' );
			}

			$args = $this->normalise_taxonomy_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$tax->data->set_request(
				array(
					'name'                 => $args['label'],
					'slug'                 => $args['slug'],
					'object_type'          => $args['object_types'],
					'singular_name'        => $args['singular_label'],
					'menu_name'            => $args['label'],
					'all_items'            => sprintf( 'All %s', $args['label'] ),
					'edit_item'            => sprintf( 'Edit %s', $args['singular_label'] ),
					'view_item'            => sprintf( 'View %s', $args['singular_label'] ),
					'update_item'          => sprintf( 'Update %s', $args['singular_label'] ),
					'add_new_item'         => sprintf( 'Add New %s', $args['singular_label'] ),
					'new_item_name'        => sprintf( 'New %s Name', $args['singular_label'] ),
					'search_items'         => sprintf( 'Search %s', $args['label'] ),
					'not_found'            => sprintf( 'No %s found', strtolower( $args['label'] ) ),
					'public'               => $args['public'],
					'publicly_queryable'   => $args['public'],
					'show_ui'              => true,
					'show_in_menu'         => true,
					'show_in_nav_menus'    => true,
					'show_in_rest'         => $args['show_in_rest'],
					'query_var'            => true,
					'rewrite'              => true,
					'with_front'           => true,
					'capability_type'      => 'post',
					'hierarchical'         => $args['hierarchical'],
					'rewrite_slug'         => $args['rewrite_slug'],
					'rewrite_hierarchical' => $args['hierarchical'],
					'description'          => $args['description'],
					'meta_fields'          => array(),
					'show_edit_link'       => false,
					'hide_field_names'     => false,
					'delete_metadata'      => false,
				)
			);

			$item_id = $this->run( $tax, 'create_item' );

			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}

			$this->after_model_write();

			return array(
				'provider'       => 'jetengine',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'item_id'        => $item_id,
				'registered_now' => taxonomy_exists( $args['slug'] ),
				'object_types'   => $args['object_types'],
				'hierarchical'   => $args['hierarchical'],
				'edit_url'       => admin_url( 'admin.php?page=jet-engine-tax&tax_action=edit&id=' . $item_id ),
				// See the same block in class-uich-model-acf.php: the term
				// warning has to arrive with the taxonomy, not with the first
				// value written against it.
				'warning'        => sprintf( 'A taxonomy with no terms renders as nothing everywhere: an empty archive, an empty filter, a term field with no options, and a `{%% for term in ... %%}` loop that never runs. None of that errors. POPULATE IT NOW, in this session, before you model or write anything that depends on it: action="ensure-term" { "taxonomy": "%s", "terms": ["..."] }, then action="set-terms" to put them on each post. If the values are a fixed list nobody will ever browse by - a status, a size, a tier - a `select` field is the simpler model and needs no terms at all.', $args['slug'] ),
				'terms'          => array(
					'count'      => 0,
					'created_by' => 'uichemy-composer/cpt action="ensure-term"',
					'assigned_by' => 'uichemy-composer/cpt action="set-terms"',
					'required_before' => 'Any archive, filtered loop, term field or taxonomy-driven template that uses this taxonomy.',
				),
				'notes'          => array(
					'JetEngine registers the taxonomy on the next page load, so it can be invisible to taxonomy_exists() for the rest of this request - and ensure-term will refuse it until then. Create the terms in a SEPARATE call.',
				),
			);
		}

		// ============================================================
		// META BOXES (field groups)
		// ============================================================

		/**
		 * Create a JetEngine meta box targeting one object type.
		 *
		 * @param array $args   { title, object_type, object_subtype }.
		 * @param array $fields Normalised field descriptors.
		 * @return array|WP_Error
		 */
		public function register_field_group( $args, $fields ) {
			$boxes = $this->component( 'meta_boxes' );

			if ( ! $boxes ) {
				return $this->no_component( 'meta_boxes', 'Meta Boxes' );
			}

			$args  = is_array( $args ) ? $args : array();
			$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';

			if ( '' === $title ) {
				return new WP_Error( 'uich_model_no_group_title', '"title" is required - it is the heading the user sees above the fields.' );
			}

			$scope = $this->box_scope( $args );
			if ( is_wp_error( $scope ) ) {
				return $scope;
			}

			$jet_fields = $this->build_fields( $fields );
			if ( is_wp_error( $jet_fields ) ) {
				return $jet_fields;
			}

			$boxes->data->set_request(
				array(
					'args'        => array_merge(
						array(
							'name'             => $title,
							'position'         => 'normal',
							'priority'         => 'high',
							'show_edit_link'   => false,
							'hide_field_names' => false,
							'delete_metadata'  => false,
						),
						$scope
					),
					'meta_fields' => $jet_fields,
				)
			);

			$item_id = $this->run( $boxes, 'create_item' );

			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}

			$this->after_model_write();

			return array(
				'provider'       => 'jetengine',
				'group_key'      => (string) $item_id,
				'title'          => $title,
				'object_type'    => isset( $args['object_type'] ) ? $args['object_type'] : 'post',
				'object_subtype' => isset( $args['object_subtype'] ) ? $args['object_subtype'] : '',
				'fields'         => $this->report_fields( $fields ),
				'edit_url'       => admin_url( 'admin.php?page=jet-engine-meta&cpt_meta_action=edit&id=' . $item_id ),
				'notes'          => array(
					'JetEngine registers meta fields on "init", so they become visible to a field read on the NEXT request, not this one.',
				),
			);
		}

		/**
		 * Append fields to an existing JetEngine meta box.
		 *
		 * @param string $group_key Meta box id (e.g. "meta-3") or its name.
		 * @param array  $fields    Normalised descriptors.
		 * @return array|WP_Error
		 */
		public function add_fields( $group_key, $fields ) {
			$boxes = $this->component( 'meta_boxes' );

			if ( ! $boxes ) {
				return $this->no_component( 'meta_boxes', 'Meta Boxes' );
			}

			// JetEngine has TWO places a field can live: a meta box, and the
			// "Meta Fields" list on the post type or taxonomy itself. Its own
			// field store reports the second keyed by the post-type slug, so a
			// caller reading cpt/describe naturally passes "movie" as the group
			// key - which is not a meta box id. Both are supported rather than
			// refused, because the second is where JetEngine users actually put
			// their fields.
			if ( $this->owns_object_fields( $group_key ) ) {
				return $this->add_fields_to_object( $group_key, $fields );
			}

			$found = $this->find_box( $group_key );
			if ( is_wp_error( $found ) ) {
				return $found;
			}

			$existing = array();

			foreach ( (array) ( isset( $found['box']['meta_fields'] ) ? $found['box']['meta_fields'] : array() ) as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$existing[ (string) $field['name'] ] = $field;
				}
			}

			foreach ( $fields as $field ) {
				if ( isset( $existing[ $field['name'] ] ) ) {
					return new WP_Error(
						'uich_model_field_exists',
						sprintf( '"%s" already exists in meta box "%s". Nothing was added - two fields cannot share a meta key. Change its label or choices with action="update-field", or pick a different name.', $field['name'], $found['name'] )
					);
				}
			}

			$jet_fields = $this->build_fields( $fields );
			if ( is_wp_error( $jet_fields ) ) {
				return $jet_fields;
			}

			$boxes->data->set_request(
				array(
					'id'          => $found['id'],
					'args'        => isset( $found['box']['args'] ) ? (array) $found['box']['args'] : array(),
					'meta_fields' => array_merge( array_values( $existing ), $jet_fields ),
				)
			);

			$ok = $this->run( $boxes, 'edit_item' );

			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'jetengine',
				'group_key' => (string) $found['id'],
				'title'     => $found['name'],
				'added'     => $this->report_fields( $fields ),
				'edit_url'  => admin_url( 'admin.php?page=jet-engine-meta&cpt_meta_action=edit&id=' . $found['id'] ),
				'notes'     => array(
					'The new fields register on "init", so they are readable from the next request onward.',
				),
			);
		}

		/**
		 * Change a field's presentation and validation.
		 *
		 * Handles both places a JetEngine field can live: a meta box, and the
		 * "Meta Fields" list on the post type or taxonomy itself.
		 *
		 * @param string $group_key Meta box id / name, or a post type or taxonomy
		 *                          slug, or '' to search every meta box.
		 * @param string $name      Field name.
		 * @param array  $changes   Allowed changes.
		 * @return array|WP_Error
		 */
		public function update_field( $group_key, $name, $changes ) {
			$changes = $this->allowed_field_changes( $changes );
			if ( is_wp_error( $changes ) ) {
				return $changes;
			}

			$which = $this->owns_object_fields( $group_key );

			if ( $which ) {
				return $this->update_object_field( $group_key, $which, (string) $name, $changes );
			}

			$boxes = $this->component( 'meta_boxes' );

			if ( ! $boxes ) {
				return $this->no_component( 'meta_boxes', 'Meta Boxes' );
			}

			$found = $this->find_box_with_field( $group_key, (string) $name );
			if ( is_wp_error( $found ) ) {
				return $found;
			}

			$applied = $this->apply_field_changes(
				array_values( (array) ( isset( $found['box']['meta_fields'] ) ? $found['box']['meta_fields'] : array() ) ),
				(string) $name,
				$changes
			);

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			$boxes->data->set_request(
				array(
					'id'          => $found['id'],
					'args'        => isset( $found['box']['args'] ) ? (array) $found['box']['args'] : array(),
					'meta_fields' => $applied['meta_fields'],
				)
			);

			$ok = $this->run( $boxes, 'edit_item' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'jetengine',
				'group_key' => (string) $found['id'],
				'name'      => (string) $name,
				'before'    => $applied['before'],
				'after'     => $applied['after'],
				'note'      => 'The field NAME and TYPE are unchanged, so every existing binding and every stored value still works.',
			);
		}

		/**
		 * Change a field defined on a JetEngine post type or taxonomy.
		 *
		 * @param string $slug    Post type or taxonomy slug.
		 * @param string $which   cpt | taxonomies.
		 * @param string $name    Field name.
		 * @param array  $changes Allowed changes.
		 * @return array|WP_Error
		 */
		private function update_object_field( $slug, $which, $name, $changes ) {
			$component = $this->component( $which );

			if ( ! $component ) {
				return $this->no_component( $which, 'cpt' === $which ? 'Custom Post Types' : 'Taxonomies' );
			}

			$item_id = 0;

			foreach ( (array) $component->data->get_items() as $row ) {
				if ( is_array( $row ) && isset( $row['slug'] ) && (string) $row['slug'] === (string) $slug ) {
					$item_id = (int) $row['id'];
					break;
				}
			}

			if ( ! $item_id ) {
				return new WP_Error( 'uich_model_unknown_group', sprintf( 'JetEngine has no record for "%s".', $slug ) );
			}

			$item = $component->data->get_item_for_edit( $item_id );

			if ( ! is_array( $item ) ) {
				return new WP_Error( 'uich_model_jet_unreadable', sprintf( 'JetEngine could not load "%s" for editing.', $slug ) );
			}

			$applied = $this->apply_field_changes(
				array_values( (array) ( isset( $item['meta_fields'] ) ? $item['meta_fields'] : array() ) ),
				$name,
				$changes
			);

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			// Flat, and carrying every existing setting: edit_item() rebuilds the
			// whole row from the request, so anything left out reverts to a
			// default - which on a post type means its visibility, supports and
			// rewrite rules.
			$request = array_merge(
				isset( $item['general_settings'] ) ? (array) $item['general_settings'] : array(),
				isset( $item['labels'] ) ? (array) $item['labels'] : array(),
				isset( $item['advanced_settings'] ) ? (array) $item['advanced_settings'] : array(),
				array(
					'id'            => $item_id,
					'slug'          => $slug,
					'admin_columns' => isset( $item['admin_columns'] ) ? (array) $item['admin_columns'] : array(),
					'admin_filters' => isset( $item['admin_filters'] ) ? (array) $item['admin_filters'] : array(),
					'meta_fields'   => $applied['meta_fields'],
				)
			);

			if ( 'taxonomies' === $which && isset( $item['general_settings']['object_type'] ) ) {
				$request['object_type'] = $item['general_settings']['object_type'];
			}

			$component->data->set_request( $request );

			$ok = $this->run( $component, 'edit_item' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'jetengine',
				'group_key' => (string) $slug,
				'stored_on' => 'cpt' === $which ? 'post type' : 'taxonomy',
				'name'      => $name,
				'before'    => $applied['before'],
				'after'     => $applied['after'],
				'note'      => 'The field NAME and TYPE are unchanged, so every existing binding and every stored value still works.',
			);
		}

		/**
		 * Apply the allowed changes to one field inside a meta_fields list.
		 *
		 * Shared by the meta-box and post-type paths so a label change behaves
		 * identically wherever the field happens to live.
		 *
		 * @param array  $meta_fields JetEngine field rows.
		 * @param string $name        Field name to change.
		 * @param array  $changes     Allowed changes.
		 * @return array{meta_fields:array,before:array,after:array}|WP_Error
		 */
		private function apply_field_changes( $meta_fields, $name, $changes ) {
			$before = array();
			$after  = array();
			$found  = false;

			foreach ( $meta_fields as $i => $field ) {
				if ( ! is_array( $field ) || ( isset( $field['name'] ) ? (string) $field['name'] : '' ) !== $name ) {
					continue;
				}

				$found = true;

				foreach ( $changes as $key => $value ) {
					switch ( $key ) {
						case 'label':
							$before['label'] = isset( $field['title'] ) ? $field['title'] : '';
							$field['title']  = (string) $value;
							$after['label']  = $field['title'];
							break;

						case 'instructions':
							$before['instructions'] = isset( $field['description'] ) ? $field['description'] : '';
							$field['description']   = (string) $value;
							$after['instructions']  = $field['description'];
							break;

						case 'placeholder':
							$before['placeholder'] = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
							$field['placeholder']  = (string) $value;
							$after['placeholder']  = $field['placeholder'];
							break;

						case 'required':
							$before['required']   = ! empty( $field['is_required'] );
							$field['is_required'] = (bool) $value;
							$after['required']    = (bool) $field['is_required'];
							break;

						case 'default':
							$before['default'] = isset( $field['default'] ) ? $field['default'] : '';
							$field['default']  = $value;
							$after['default']  = $value;
							break;

						case 'choices':
						case 'options':
							$type = isset( $field['type'] ) ? (string) $field['type'] : '';

							if ( ! in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
								return new WP_Error(
									'uich_model_choices_not_applicable',
									sprintf( 'Field "%s" is a %s, which has no choices.', $name, '' !== $type ? $type : 'field' )
								);
							}

							if ( ! empty( $field['glossary_id'] ) || 'glossary' === ( isset( $field['options_source'] ) ? $field['options_source'] : '' ) ) {
								return new WP_Error(
									'uich_model_glossary_backed',
									sprintf( 'Field "%s" takes its options from JetEngine glossary %d, not from a list on the field. Edit the glossary in JetEngine > Glossaries - changing it there updates every field that uses it, which is the point of a glossary.', $name, (int) $field['glossary_id'] )
								);
							}

							$choices = Uich_Model::normalise_choices( $value );

							if ( ! $choices ) {
								return new WP_Error( 'uich_model_no_choices', sprintf( 'The choices given for "%s" are empty. A select with no choices cannot be filled in.', $name ) );
							}

							$before['choices'] = $this->existing_choices( $field );

							$field['options_source'] = 'manual_bulk';
							$field['bulk_options']   = $this->bulk_options( $choices );
							unset( $field['options'] );

							$after['choices'] = $choices;

							// Removing a choice does not remove the values already
							// stored against it: those objects keep a value that is
							// now outside the list and renders as a blank label.
							$dropped = array_diff( array_keys( (array) $before['choices'] ), array_keys( $choices ) );

							if ( $dropped ) {
								$after['dropped_choices_warning'] = sprintf(
									'The choices %s were removed from the list but NOT from any object already storing them. Those objects keep a value outside the list, which renders as an empty label.',
									'"' . implode( '", "', $dropped ) . '"'
								);
							}
							break;
					}
				}

				$meta_fields[ $i ] = $field;
				break;
			}

			if ( ! $found ) {
				return new WP_Error( 'uich_model_unknown_field', sprintf( 'No field named "%s" there.', $name ) );
			}

			return array(
				'meta_fields' => $meta_fields,
				'before'      => $before,
				'after'       => $after,
			);
		}

		// ============================================================
		// OWNERSHIP
		// ============================================================

		/**
		 * Post types JetEngine registered.
		 *
		 * @return array<int,string>
		 */
		public function owned_post_types() {
			$cpt = $this->component( 'cpt' );

			if ( ! $cpt || ! method_exists( $cpt->data, 'get_items' ) ) {
				return array();
			}

			$out = array();

			foreach ( (array) $cpt->data->get_items() as $row ) {
				$slug = is_array( $row ) && isset( $row['slug'] ) ? (string) $row['slug'] : '';

				if ( '' !== $slug ) {
					$out[] = $slug;
				}
			}

			return $out;
		}

		/**
		 * Taxonomies JetEngine registered.
		 *
		 * @return array<int,string>
		 */
		public function owned_taxonomies() {
			$tax = $this->component( 'taxonomies' );

			if ( ! $tax || ! method_exists( $tax->data, 'get_items' ) ) {
				return array();
			}

			$out = array();

			foreach ( (array) $tax->data->get_items() as $row ) {
				$slug = is_array( $row ) && isset( $row['slug'] ) ? (string) $row['slug'] : '';

				if ( '' !== $slug ) {
					$out[] = $slug;
				}
			}

			return $out;
		}

		// ============================================================
		// INTERNALS
		// ============================================================

		/**
		 * Run a data-controller method and turn JetEngine's notice stack into a
		 * real error.
		 *
		 * create_item()/edit_item() return nothing on failure and push the reason
		 * onto the component's notices, so without this a caller learns only that
		 * something did not happen.
		 *
		 * @param object $component JetEngine component.
		 * @param string $method    create_item | edit_item.
		 * @return mixed|WP_Error
		 */
		private function run( $component, $method ) {
			try {
				$result = $component->data->{$method}( false );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'uich_model_jet_exception', sprintf( 'JetEngine threw while saving: %s', $e->getMessage() ) );
			}

			if ( $result ) {
				return $result;
			}

			$messages = array();

			if ( method_exists( $component, 'get_notices' ) ) {
				foreach ( (array) $component->get_notices() as $notice ) {
					if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
						$messages[] = (string) $notice['message'];
					} elseif ( is_string( $notice ) ) {
						$messages[] = $notice;
					}
				}
			}

			return new WP_Error(
				'uich_model_jet_failed',
				$messages
					? sprintf( 'JetEngine refused the save: %s', implode( ' | ', $messages ) )
					: 'JetEngine refused the save and reported no reason. The usual causes are a slug that is already taken and a slug over 20 characters.'
			);
		}

		/**
		 * JetEngine's meta-box scope settings for an object type + subtype.
		 *
		 * @param array $args { object_type, object_subtype }.
		 * @return array|WP_Error
		 */
		private function box_scope( $args ) {
			$type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : 'post';
			$sub  = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';

			switch ( $type ) {
				case 'post':
					if ( '' === $sub ) {
						return new WP_Error(
							'uich_model_no_subtype',
							'"object_subtype" is required for object_type="post" - the post type slug this meta box appears on. A meta box scoped to nothing shows nowhere, so its fields can never be filled in.'
						);
					}

					// A post type JetEngine created earlier in THIS request is not
					// registered yet - JetEngine registers on `init`. It is still
					// a real target: accepting it here is what lets
					// register-post-type and register-field-group be one sequence
					// instead of two requests with a wait in between.
					if ( ! post_type_exists( $sub ) && ! in_array( $sub, $this->owned_post_types(), true ) ) {
						return new WP_Error(
							'uich_model_unknown_post_type',
							sprintf(
								'No post type "%s" is registered, and JetEngine has no record of one. Register it first (action="register-post-type"), or use one of: %s.',
								$sub,
								implode( ', ', get_post_types( array(), 'names' ) )
							)
						);
					}

					return array(
						'object_type'       => 'post',
						'allowed_post_type' => array( $sub ),
						'active_conditions' => array(),
					);

				case 'term':
					if ( '' === $sub || ( ! taxonomy_exists( $sub ) && ! in_array( $sub, $this->owned_taxonomies(), true ) ) ) {
						return new WP_Error(
							'uich_model_unknown_taxonomy',
							sprintf( '"object_subtype" must be a registered taxonomy for object_type="term". Real ones: %s.', implode( ', ', get_taxonomies( array(), 'names' ) ) )
						);
					}

					return array(
						'object_type'       => 'tax',
						'allowed_tax'       => array( $sub ),
						'active_conditions' => array(),
					);

				case 'user':
					return array(
						'object_type'          => 'user',
						'allowed_user_screens' => 'all',
						'active_conditions'    => array(),
					);

				case 'options':
					return new WP_Error(
						'uich_model_options_page',
						'A JetEngine options page is created in JetEngine > Options Pages, not here - it needs a menu slug and a capability, which are site-structure decisions rather than content-model ones.'
					);
			}

			return new WP_Error(
				'uich_model_bad_object_type',
				sprintf( 'Unknown object_type "%s". Valid here: post, term, user.', $type )
			);
		}

		/**
		 * Turn normalised descriptors into JetEngine field rows.
		 *
		 * @param array $fields Normalised descriptors.
		 * @param bool  $nested Whether these are repeater sub-fields.
		 * @return array<int,array>|WP_Error
		 */
		private function build_fields( $fields, $nested = false ) {
			$out = array();

			foreach ( array_values( (array) $fields ) as $field ) {
				$jet_type = $this->map_type( $field['type'] );

				if ( is_wp_error( $jet_type ) ) {
					return $jet_type;
				}

				$row = array(
					'title'           => $field['label'],
					'name'            => $field['name'],
					'object_type'     => 'field',
					'type'            => $jet_type,
					'width'           => '100%',
					// JetEngine's own UI uses a random integer here as a row
					// identifier; it is not addressable and not a meta key.
					'id'              => wp_rand( 10000, 99999 ),
					'isNested'        => (bool) $nested,
					'is_nested'       => (bool) $nested,
					'options'         => array(),
					'quick_editable'  => true,
					'is_required'     => (bool) $field['required'],
					'default'         => $field['default'],
					'description'     => $field['instructions'],
					'placeholder'     => '',
					'args'            => array(),
					'conditions'      => array(),
					'repeater-fields' => array(),
				);

				if ( 'media' === $jet_type ) {
					// id, not url: the id is what every size and attribute is
					// derived from, and a stored URL cannot be resized.
					$row['value_format'] = 'id';
				}

				if ( in_array( $jet_type, array( 'date', 'datetime-local', 'time' ), true ) ) {
					$row['input_type']   = $jet_type;
					$row['autocomplete'] = 'off';
					$row['is_timestamp'] = true;
				}

				if ( 'text' === $jet_type && in_array( $field['type'], array( 'email', 'url' ), true ) ) {
					$row['input_type'] = $field['type'];
				}

				if ( 'number' === $jet_type ) {
					if ( '' !== (string) $field['min'] ) {
						$row['min'] = $field['min'];
					}
					if ( '' !== (string) $field['max'] ) {
						$row['max'] = $field['max'];
					}
				}

				if ( in_array( $jet_type, array( 'select', 'radio', 'checkbox' ), true ) && $field['choices'] ) {
					$row['options_source'] = 'manual_bulk';
					$row['bulk_options']   = $this->bulk_options( $field['choices'] );

					if ( 'checkbox' === $jet_type ) {
						// Without this a multi-checkbox stores one scalar and
						// only the last box ticked survives.
						$row['is_array'] = true;
					}
				}

				if ( 'taxonomy' === $jet_type ) {
					$row['taxonomy_options'] = $field['taxonomy'];
					$row['object_tax']       = $field['taxonomy'];
				}

				if ( 'posts' === $jet_type && $field['post_type'] ) {
					$row['post_type'] = $field['post_type'];
				}

				if ( 'repeater' === $jet_type ) {
					$subs = $this->build_fields( $field['sub_fields'], true );

					if ( is_wp_error( $subs ) ) {
						return $subs;
					}

					$row['repeater-fields'] = $subs;
				}

				$out[] = $row;
			}

			return $out;
		}

		/**
		 * A caller-facing report of the fields created.
		 *
		 * @param array $fields Normalised descriptors.
		 * @return array<int,array>
		 */
		private function report_fields( $fields ) {
			$out = array();

			foreach ( (array) $fields as $field ) {
				$row = array(
					'name'       => $field['name'],
					'label'      => $field['label'],
					'type'       => $field['type'],
					'jet_type'   => $this->map_type( $field['type'] ),
					'required'   => (bool) $field['required'],
					// A JetEngine date stores a Unix timestamp, so the bare token
					// prints a raw number on the page. The token handed back is
					// the one that actually renders a date.
					'bind_token' => $this->bind_token( $field ),
				);

				if ( $field['choices'] ) {
					$row['choices'] = $field['choices'];
				}

				if ( $field['sub_fields'] ) {
					$row['sub_fields'] = $this->report_fields( $field['sub_fields'] );
				}

				$out[] = $row;
			}

			return $out;
		}

		/**
		 * The token that prints one field correctly.
		 *
		 * @param array $field Normalised descriptor.
		 * @return string
		 */
		private function bind_token( $field ) {
			$name = $field['name'];

			if ( 'repeater' === $field['type'] ) {
				$first = ! empty( $field['sub_fields'][0]['name'] ) ? $field['sub_fields'][0]['name'] : 'sub_field_name';

				return sprintf( "{%% for row in post.meta('%s') %%}{{ row.%s }}{%% endfor %%}", $name, $first );
			}

			if ( in_array( $field['type'], array( 'date', 'datetime', 'time' ), true ) ) {
				return sprintf( "{{ post.meta('%s')|date('j F Y') }}", $name );
			}

			if ( in_array( $field['type'], array( 'image', 'file' ), true ) ) {
				return sprintf( "{{ post.meta('%s').src('large') }}", $name );
			}

			return sprintf( "{{ post.meta('%s') }}", $name );
		}

		/**
		 * JetEngine's newline-separated "value::label" option string.
		 *
		 * @param array $choices Value => label.
		 * @return string
		 */
		private function bulk_options( $choices ) {
			$lines = array();

			foreach ( (array) $choices as $value => $label ) {
				$lines[] = $value . '::' . $label;
			}

			return implode( "\n", $lines );
		}

		/**
		 * The choices a JetEngine field currently declares, whichever shape they
		 * are stored in.
		 *
		 * @param array $field Raw JetEngine field.
		 * @return array<string,string>
		 */
		private function existing_choices( $field ) {
			if ( 'manual_bulk' === ( isset( $field['options_source'] ) ? $field['options_source'] : '' ) ) {
				$out = array();

				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( isset( $field['bulk_options'] ) ? $field['bulk_options'] : '' ) ) as $line ) {
					$line = trim( $line );

					if ( '' === $line ) {
						continue;
					}

					$parts         = explode( '::', $line );
					$out[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : $parts[0];
				}

				return $out;
			}

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
		 * Whether a group key names a post type or taxonomy JetEngine itself
		 * registered, rather than a meta box.
		 *
		 * @param string $group_key Candidate key.
		 * @return string|false 'cpt' | 'taxonomies', or false.
		 */
		private function owns_object_fields( $group_key ) {
			$group_key = trim( (string) $group_key );

			if ( '' === $group_key ) {
				return false;
			}

			if ( in_array( $group_key, $this->owned_post_types(), true ) ) {
				return 'cpt';
			}

			if ( in_array( $group_key, $this->owned_taxonomies(), true ) ) {
				return 'taxonomies';
			}

			return false;
		}

		/**
		 * Append fields to the "Meta Fields" list on a JetEngine post type or
		 * taxonomy.
		 *
		 * Round-tripping through JetEngine's own editor shape is the fiddly part:
		 * get_item_for_edit() returns a NESTED array (general_settings / labels /
		 * advanced_settings / meta_fields) and set_request() takes a FLAT one, so
		 * the nesting has to be undone or edit_item() drops every setting it does
		 * not see and the post type comes back with default visibility, supports
		 * and rewrite rules.
		 *
		 * @param string $slug   Post type or taxonomy slug.
		 * @param array  $fields Normalised descriptors.
		 * @return array|WP_Error
		 */
		private function add_fields_to_object( $slug, $fields ) {
			$which     = $this->owns_object_fields( $slug );
			$component = $this->component( $which );

			if ( ! $component ) {
				return $this->no_component( $which, 'cpt' === $which ? 'Custom Post Types' : 'Taxonomies' );
			}

			$item_id = 0;

			foreach ( (array) $component->data->get_items() as $row ) {
				if ( is_array( $row ) && isset( $row['slug'] ) && (string) $row['slug'] === (string) $slug ) {
					$item_id = (int) $row['id'];
					break;
				}
			}

			if ( ! $item_id ) {
				return new WP_Error( 'uich_model_unknown_group', sprintf( 'JetEngine has no record for "%s".', $slug ) );
			}

			$item = $component->data->get_item_for_edit( $item_id );

			if ( ! is_array( $item ) ) {
				return new WP_Error( 'uich_model_jet_unreadable', sprintf( 'JetEngine could not load "%s" for editing.', $slug ) );
			}

			$existing = array();

			foreach ( (array) ( isset( $item['meta_fields'] ) ? $item['meta_fields'] : array() ) as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$existing[ (string) $field['name'] ] = $field;
				}
			}

			foreach ( $fields as $field ) {
				if ( isset( $existing[ $field['name'] ] ) ) {
					return new WP_Error(
						'uich_model_field_exists',
						sprintf( '"%s" already exists on "%s". Nothing was added - two fields cannot share a meta key.', $field['name'], $slug )
					);
				}
			}

			$new = $this->build_fields( $fields );
			if ( is_wp_error( $new ) ) {
				return $new;
			}

			$request = array_merge(
				isset( $item['general_settings'] ) ? (array) $item['general_settings'] : array(),
				isset( $item['labels'] ) ? (array) $item['labels'] : array(),
				isset( $item['advanced_settings'] ) ? (array) $item['advanced_settings'] : array(),
				array(
					'id'            => $item_id,
					'slug'          => $slug,
					'admin_columns' => isset( $item['admin_columns'] ) ? (array) $item['admin_columns'] : array(),
					'admin_filters' => isset( $item['admin_filters'] ) ? (array) $item['admin_filters'] : array(),
					'meta_fields'   => array_merge( array_values( $existing ), $new ),
				)
			);

			if ( 'taxonomies' === $which && isset( $item['general_settings']['object_type'] ) ) {
				$request['object_type'] = $item['general_settings']['object_type'];
			}

			$component->data->set_request( $request );

			$ok = $this->run( $component, 'edit_item' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'jetengine',
				'group_key' => (string) $slug,
				'title'     => $slug,
				'stored_on' => 'cpt' === $which ? 'post type' : 'taxonomy',
				'added'     => $this->report_fields( $fields ),
				'edit_url'  => admin_url(
					'cpt' === $which
						? 'admin.php?page=jet-engine-cpt&cpt_action=edit&id=' . $item_id
						: 'admin.php?page=jet-engine-tax&tax_action=edit&id=' . $item_id
				),
				'notes'     => array(
					'These fields live on the ' . ( 'cpt' === $which ? 'post type' : 'taxonomy' ) . ' itself (JetEngine => ' . ( 'cpt' === $which ? 'Post Types' : 'Taxonomies' ) . ' => Meta Fields), not in a meta box.',
					'They register on "init", so they are readable from the next request onward.',
				),
			);
		}

		/**
		 * Find one JetEngine meta box by id or name.
		 *
		 * @param string $group_key Meta box id ("meta-3") or its name.
		 * @return array{id:string,name:string,box:array}|WP_Error
		 */
		private function find_box( $group_key ) {
			$group_key = trim( (string) $group_key );

			if ( '' === $group_key ) {
				return new WP_Error(
					'uich_model_no_group',
					'"group_key" is required - the JetEngine meta box id. List them with uichemy-composer/cpt (action="describe").'
				);
			}

			$raw = (array) jet_engine()->meta_boxes->data->get_raw();

			if ( isset( $raw[ $group_key ] ) ) {
				return array(
					'id'   => (string) $group_key,
					'name' => isset( $raw[ $group_key ]['args']['name'] ) ? (string) $raw[ $group_key ]['args']['name'] : (string) $group_key,
					'box'  => (array) $raw[ $group_key ],
				);
			}

			$matches = array();

			foreach ( $raw as $id => $box ) {
				$name = isset( $box['args']['name'] ) ? (string) $box['args']['name'] : '';

				if ( strtolower( $name ) === strtolower( $group_key ) ) {
					$matches[] = array(
						'id'   => (string) $id,
						'name' => $name,
						'box'  => (array) $box,
					);
				}
			}

			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}

			if ( count( $matches ) > 1 ) {
				return new WP_Error(
					'uich_model_ambiguous_group',
					sprintf( '%d meta boxes are named "%s". Pass one of these ids: %s.', count( $matches ), $group_key, implode( ', ', wp_list_pluck( $matches, 'id' ) ) )
				);
			}

			$known = array();

			foreach ( $raw as $id => $box ) {
				$known[] = ( isset( $box['args']['name'] ) ? $box['args']['name'] : '(unnamed)' ) . ' (' . $id . ')';
			}

			return new WP_Error(
				'uich_model_unknown_group',
				sprintf( 'No JetEngine meta box "%s". Existing: %s.', $group_key, implode( ', ', $known ) ?: 'none' )
			);
		}

		/**
		 * Find the meta box holding one field.
		 *
		 * @param string $group_key Meta box id or name, or ''.
		 * @param string $name      Field name.
		 * @return array{id:string,name:string,box:array}|WP_Error
		 */
		private function find_box_with_field( $group_key, $name ) {
			if ( '' !== trim( (string) $group_key ) ) {
				$found = $this->find_box( $group_key );

				if ( is_wp_error( $found ) ) {
					return $found;
				}

				foreach ( (array) ( isset( $found['box']['meta_fields'] ) ? $found['box']['meta_fields'] : array() ) as $field ) {
					if ( ( isset( $field['name'] ) ? (string) $field['name'] : '' ) === $name ) {
						return $found;
					}
				}

				return new WP_Error(
					'uich_model_unknown_field',
					sprintf( 'Meta box "%s" has no field named "%s".', $found['name'], $name )
				);
			}

			$raw     = (array) jet_engine()->meta_boxes->data->get_raw();
			$matches = array();

			foreach ( $raw as $id => $box ) {
				foreach ( (array) ( isset( $box['meta_fields'] ) ? $box['meta_fields'] : array() ) as $field ) {
					if ( ( isset( $field['name'] ) ? (string) $field['name'] : '' ) === $name ) {
						$matches[] = array(
							'id'   => (string) $id,
							'name' => isset( $box['args']['name'] ) ? (string) $box['args']['name'] : (string) $id,
							'box'  => (array) $box,
						);
					}
				}
			}

			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}

			if ( count( $matches ) > 1 ) {
				$where = array();

				foreach ( $matches as $m ) {
					$where[] = $m['name'] . ' (' . $m['id'] . ')';
				}

				return new WP_Error(
					'uich_model_ambiguous_field',
					sprintf( 'A field named "%s" exists in %d meta boxes: %s. Pass "group_key" to say which.', $name, count( $matches ), implode( ', ', $where ) )
				);
			}

			return new WP_Error(
				'uich_model_unknown_field',
				sprintf( 'No JetEngine field named "%s" in any meta box. Fields defined directly on a CPT (JetEngine => Post Types => Meta Fields) are not meta boxes and are edited there.', $name )
			);
		}

		/**
		 * Flush what a model change invalidates.
		 *
		 * @return void
		 */
		private function after_model_write() {
			if ( function_exists( 'jet_engine' ) ) {
				foreach ( array( 'cpt', 'taxonomies', 'meta_boxes' ) as $name ) {
					$component = $this->component( $name );

					if ( $component && method_exists( $component->data, 'reset_raw_cache' ) ) {
						$component->data->reset_raw_cache();
					}
				}

				// jet_engine()->db keeps a per-request snapshot of each table in
				// $query_cache and never invalidates it on write. Without this,
				// everything that reads a JetEngine table after a write in the
				// same request - get_items(), get_item_for_edit(), and so the
				// ownership index behind every "does this type exist" check -
				// answers from the state before the write. So creating a post
				// type and then adding a field to it in one call would report the
				// type as unknown.
				if ( ! empty( jet_engine()->db ) && isset( jet_engine()->db->query_cache ) ) {
					jet_engine()->db->query_cache = array();
				}
			}

			Uich_Field_Registry::flush();
			delete_option( 'rewrite_rules' );
		}
	}
}
