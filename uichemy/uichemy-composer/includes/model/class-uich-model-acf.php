<?php
/**
 * The ACF content-model provider.
 *
 * Everything here goes through ACF's own internal-post-type API, so what is
 * created is indistinguishable from what the ACF UI creates: the post type
 * appears under ACF > Post Types, is editable there, and exports with the rest
 * of the site's ACF configuration.
 *
 * Verified on ACF 6.8.9 FREE: acf_update_internal_post_type() is present, and a
 * post type plus taxonomy created through it register as real, public,
 * archive-enabled WordPress objects that the ACF UI can then edit. Post types
 * and taxonomies are NOT an ACF-Pro-only feature.
 *
 * FIELD TYPES ARE. repeater, flexible_content, gallery and clone do not exist on
 * ACF free, and ACF does not refuse them - it stores a field of an unknown type
 * and treats its value as a scalar. So a repeater created on free looks created,
 * accepts a write, reads back intact, and becomes one empty row the moment ACF
 * Pro is installed. map_type() refuses those types on free for that reason,
 * naming what is missing rather than substituting silently.
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

if ( ! class_exists( 'Uich_Model_ACF' ) ) {

	/**
	 * Creates post types, taxonomies and field groups through ACF.
	 */
	final class Uich_Model_ACF extends Uich_Model_Provider {

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
		 * Whether ACF is here with the model API.
		 *
		 * @return bool
		 */
		public function is_active() {
			return function_exists( 'acf_update_field_group' )
				&& function_exists( 'acf_update_field' )
				&& function_exists( 'acf_get_field_groups' );
		}

		/**
		 * Whether ACF's post-type / taxonomy registration API is present.
		 *
		 * Separate from is_active(): it arrived in ACF 6.1, and a site on 6.0 can
		 * still have field groups.
		 *
		 * @return bool
		 */
		private function has_internal_post_types() {
			return function_exists( 'acf_update_internal_post_type' )
				&& function_exists( 'acf_get_internal_post_type_posts' );
		}

		// ============================================================
		// TYPE MAP
		// ============================================================

		/**
		 * UiChemy's neutral field type => ACF's own.
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
				'email'         => 'email',
				'url'           => 'url',
				'boolean'       => 'true_false',
				'select'        => 'select',
				'radio'         => 'radio',
				'checkbox'      => 'checkbox',
				'image'         => 'image',
				'file'          => 'file',
				'gallery'       => 'gallery',
				'date'          => 'date_picker',
				'datetime'      => 'date_time_picker',
				'time'          => 'time_picker',
				'color'         => 'color_picker',
				'link'          => 'link',
				'post_relation' => 'post_object',
				'taxonomy'      => 'taxonomy',
				'repeater'      => 'repeater',
			);

			$type = (string) $type;

			if ( ! isset( $map[ $type ] ) ) {
				return new WP_Error( 'uich_model_bad_field_type', sprintf( 'ACF has no field for UiChemy type "%s".', $type ) );
			}

			$acf_type = $map[ $type ];

			if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $acf_type ) ) {
				return new WP_Error(
					'uich_model_acf_pro_type',
					sprintf(
						'"%s" needs the ACF field type "%s", which is not registered on this build - it is an ACF PRO type and this site runs ACF free. ACF would NOT refuse the field: it would create it, store its value as a single serialised scalar, read it back intact, and show it as one empty row the moment ACF Pro is installed. UiChemy refuses instead of creating that. Either install ACF Pro, or use %s - or model it as a separate post type with a relation, which works on free.',
						$type,
						$acf_type,
						'repeater' === $type ? 'a textarea and parse it yourself' : 'a plain field'
					)
				);
			}

			return $acf_type;
		}

		// ============================================================
		// POST TYPES
		// ============================================================

		/**
		 * Register a post type through ACF.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_post_type( $args ) {
			if ( ! $this->has_internal_post_types() ) {
				return new WP_Error(
					'uich_model_acf_too_old',
					'This ACF version has no post-type registration API (it arrived in ACF 6.1). Update ACF, or register the type in JetEngine if that is installed.'
				);
			}

			$args = $this->normalise_post_type_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$payload = array(
				'key'                    => uniqid( 'post_type_' ),
				'title'                  => $args['label'],
				'active'                 => true,
				'post_type'              => $args['slug'],
				'advanced_configuration' => true,
				'labels'                 => array(
					'name'          => $args['label'],
					'singular_name' => $args['singular_label'],
					'menu_name'     => $args['label'],
					'all_items'     => sprintf( 'All %s', $args['label'] ),
					'add_new_item'  => sprintf( 'Add New %s', $args['singular_label'] ),
					'edit_item'     => sprintf( 'Edit %s', $args['singular_label'] ),
					'view_item'     => sprintf( 'View %s', $args['singular_label'] ),
					'search_items'  => sprintf( 'Search %s', $args['label'] ),
					'not_found'     => sprintf( 'No %s found', strtolower( $args['label'] ) ),
				),
				'description'            => $args['description'],
				'public'                 => $args['public'],
				'hierarchical'           => $args['hierarchical'],
				'publicly_queryable'     => $args['public'],
				'show_ui'                => true,
				'show_in_menu'           => true,
				'show_in_nav_menus'      => true,
				'show_in_rest'           => $args['show_in_rest'],
				'supports'               => $args['supports'],
				'taxonomies'             => $args['taxonomies'],
				'has_archive'            => $args['has_archive'],
				'has_archive_slug'       => $args['has_archive'] ? $args['rewrite_slug'] : '',
				'menu_icon'              => $args['menu_icon'],
				'rewrite'                => array(
					'permalink_rewrite' => 'custom_permalink',
					'slug'              => $args['rewrite_slug'],
					'with_front'        => true,
					'feeds'             => false,
					'pages'             => true,
				),
				'query_var'              => 'post_type_key',
				'can_export'             => true,
			);

			$saved = acf_update_internal_post_type( $payload, 'acf-post-type' );

			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				return new WP_Error( 'uich_model_acf_post_type_failed', sprintf( 'ACF did not save the post type "%s".', $args['slug'] ) );
			}

			$this->after_model_write();

			return array(
				'provider'       => 'acf',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'key'            => $saved['key'],
				'id'             => (int) $saved['ID'],
				'registered_now' => post_type_exists( $args['slug'] ),
				'supports'       => $args['supports'],
				'taxonomies'     => $args['taxonomies'],
				'has_archive'    => $args['has_archive'],
				'archive_url'    => $args['has_archive'] ? home_url( '/' . $args['rewrite_slug'] . '/' ) : null,
				'edit_url'       => admin_url( 'post.php?post=' . (int) $saved['ID'] . '&action=edit' ),
				'notes'          => array_merge(
					$args['notes'],
					array(
						'A post type with no field group has no custom fields. Call action="register-field-group" next, with object_subtype="' . $args['slug'] . '".',
						'It has no template either: a single ' . $args['singular_label'] . ' renders through the theme until you build one with uichemy-composer/theme-builder (action="create", type="single").',
					)
				),
			);
		}

		/**
		 * Register a taxonomy through ACF.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_taxonomy( $args ) {
			if ( ! $this->has_internal_post_types() ) {
				return new WP_Error( 'uich_model_acf_too_old', 'This ACF version has no taxonomy registration API (it arrived in ACF 6.1). Update ACF.' );
			}

			$args = $this->normalise_taxonomy_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$payload = array(
				'key'                    => uniqid( 'taxonomy_' ),
				'title'                  => $args['label'],
				'active'                 => true,
				'taxonomy'               => $args['slug'],
				'object_type'            => $args['object_types'],
				'advanced_configuration' => 1,
				'labels'                 => array(
					'name'          => $args['label'],
					'singular_name' => $args['singular_label'],
					'menu_name'     => $args['label'],
					'all_items'     => sprintf( 'All %s', $args['label'] ),
					'edit_item'     => sprintf( 'Edit %s', $args['singular_label'] ),
					'add_new_item'  => sprintf( 'Add New %s', $args['singular_label'] ),
					'search_items'  => sprintf( 'Search %s', $args['label'] ),
					'not_found'     => sprintf( 'No %s found', strtolower( $args['label'] ) ),
				),
				'description'            => $args['description'],
				'public'                 => $args['public'],
				'publicly_queryable'     => $args['public'],
				'hierarchical'           => $args['hierarchical'],
				'show_ui'                => true,
				'show_in_menu'           => true,
				'show_in_nav_menus'      => true,
				'show_in_rest'           => $args['show_in_rest'],
				'show_tagcloud'          => true,
				'show_in_quick_edit'     => true,
				'show_admin_column'      => $args['show_admin_column'],
				'rewrite'                => array(
					'permalink_rewrite'    => 'custom_permalink',
					'slug'                 => $args['rewrite_slug'],
					'with_front'           => true,
					'rewrite_hierarchical' => $args['hierarchical'],
				),
				'query_var'              => 'taxonomy_key',
			);

			$saved = acf_update_internal_post_type( $payload, 'acf-taxonomy' );

			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				return new WP_Error( 'uich_model_acf_taxonomy_failed', sprintf( 'ACF did not save the taxonomy "%s".', $args['slug'] ) );
			}

			$this->after_model_write();

			return array(
				'provider'       => 'acf',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'key'            => $saved['key'],
				'id'             => (int) $saved['ID'],
				'registered_now' => taxonomy_exists( $args['slug'] ),
				'object_types'   => $args['object_types'],
				'hierarchical'   => $args['hierarchical'],
				'edit_url'       => admin_url( 'post.php?post=' . (int) $saved['ID'] . '&action=edit' ),
				// The warning belongs HERE, in the response to the call that
				// creates the taxonomy, because this is the moment the modelling
				// decision is made. Delivered any later - at the first write, say
				// - it arrives after the content has been shaped around a
				// taxonomy that is still empty, and the only way back is a
				// parallel select field driving the front end off a shadow copy.
				'warning'        => sprintf( 'A taxonomy with no terms renders as nothing everywhere: an empty archive, an empty filter, a term field with no options, and a `{%% for term in ... %%}` loop that never runs. None of that errors. POPULATE IT NOW, in this session, before you model or write anything that depends on it: action="ensure-term" { "taxonomy": "%s", "terms": ["..."] }, then action="set-terms" to put them on each post. If the values are a fixed list nobody will ever browse by - a status, a size, a tier - a `select` field is the simpler model and needs no terms at all.', $args['slug'] ),
				'terms'          => array(
					'count'      => 0,
					'created_by' => 'uichemy-composer/cpt action="ensure-term"',
					'assigned_by' => 'uichemy-composer/cpt action="set-terms"',
					'required_before' => 'Any archive, filtered loop, term field or taxonomy-driven template that uses this taxonomy.',
				),
				'notes'          => array(
					'A term slug that does not exist is accepted silently by loop builders and produces a permanently empty listing - resolve real terms with uichemy-composer/describe-site (action="entities", kind="terms").',
				),
			);
		}

		// ============================================================
		// FIELD GROUPS
		// ============================================================

		/**
		 * Create an ACF field group targeting one object type.
		 *
		 * @param array $args   { title, object_type, object_subtype }.
		 * @param array $fields Normalised field descriptors.
		 * @return array|WP_Error
		 */
		public function register_field_group( $args, $fields ) {
			$args = is_array( $args ) ? $args : array();

			$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';

			if ( '' === $title ) {
				return new WP_Error( 'uich_model_no_group_title', '"title" is required - it is what the user sees above the fields in the editor.' );
			}

			$location = $this->location_rule( $args );
			if ( is_wp_error( $location ) ) {
				return $location;
			}

			$group_key = uniqid( 'group_' );

			$saved = acf_update_field_group(
				array(
					'key'                   => $group_key,
					'title'                 => $title,
					'location'              => $location,
					'menu_order'            => 0,
					'position'              => 'normal',
					'style'                 => 'default',
					'label_placement'       => 'top',
					'instruction_placement' => 'label',
					'hide_on_screen'        => array(),
					'active'                => true,
					'description'           => isset( $args['description'] ) ? (string) $args['description'] : '',
					// So the group's values are visible to the REST API, which is
					// what makes them reachable outside the admin at all.
					'show_in_rest'          => true,
				)
			);

			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				return new WP_Error( 'uich_model_acf_group_failed', sprintf( 'ACF did not save the field group "%s".', $title ) );
			}

			$added = array();

			if ( $fields ) {
				// The group's numeric ID, never its key. acf_update_field()
				// resolves a non-numeric `parent` through acf_get_field_post(),
				// which queries post_type=acf-field ONLY - so a group key finds
				// nothing there and the parent silently becomes 0. The fields are
				// created, orphaned from the group, and invisible: acf_get_fields()
				// returns none of them, the editor shows an empty group, and
				// nothing errors.
				$added = $this->write_fields( (int) $saved['ID'], $fields, 0 );

				if ( is_wp_error( $added ) ) {
					// The group exists and is reported, because leaving the caller
					// thinking nothing happened is worse than a partial result it
					// can finish with add-fields.
					return new WP_Error(
						$added->get_error_code(),
						sprintf( 'The field group "%s" (key %s) WAS created, but a field was rejected and no fields were added: %s', $title, $saved['key'], $added->get_error_message() )
					);
				}
			}

			$this->after_model_write();

			return array(
				'provider'       => 'acf',
				'group_key'      => $saved['key'],
				'title'          => $title,
				'id'             => (int) $saved['ID'],
				'object_type'    => isset( $args['object_type'] ) ? $args['object_type'] : 'post',
				'object_subtype' => isset( $args['object_subtype'] ) ? $args['object_subtype'] : '',
				'fields'         => $added,
				'edit_url'       => admin_url( 'post.php?post=' . (int) $saved['ID'] . '&action=edit' ),
			);
		}

		/**
		 * Append fields to an existing ACF group.
		 *
		 * @param string $group_key ACF group key.
		 * @param array  $fields    Normalised descriptors.
		 * @return array|WP_Error
		 */
		public function add_fields( $group_key, $fields ) {
			$group = $this->find_group( $group_key );
			if ( is_wp_error( $group ) ) {
				return $group;
			}

			$existing = array();

			foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$existing[ (string) $field['name'] ] = $field;
				}
			}

			foreach ( $fields as $field ) {
				if ( isset( $existing[ $field['name'] ] ) ) {
					return new WP_Error(
						'uich_model_field_exists',
						sprintf(
							'"%s" already exists in group "%s" (type %s). Nothing was added. Change its label or choices with action="update-field"; a different field needs a different name, because two fields cannot share a meta key.',
							$field['name'],
							$group['title'],
							$existing[ $field['name'] ]['type']
						)
					);
				}
			}

			$added = $this->write_fields( (int) $group['ID'], $fields, count( $existing ) );

			if ( is_wp_error( $added ) ) {
				return $added;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'acf',
				'group_key' => $group['key'],
				'title'     => $group['title'],
				'added'     => $added,
				'edit_url'  => isset( $group['ID'] ) ? admin_url( 'post.php?post=' . (int) $group['ID'] . '&action=edit' ) : '',
			);
		}

		/**
		 * Change a field's label, instructions, required flag, choices, default.
		 *
		 * @param string $group_key ACF group key, or ''.
		 * @param string $name      Field name.
		 * @param array  $changes   Allowed changes.
		 * @return array|WP_Error
		 */
		public function update_field( $group_key, $name, $changes ) {
			$changes = $this->allowed_field_changes( $changes );
			if ( is_wp_error( $changes ) ) {
				return $changes;
			}

			$found = $this->find_field( $group_key, (string) $name );
			if ( is_wp_error( $found ) ) {
				return $found;
			}

			$field  = $found['field'];
			$before = array();
			$after  = array();

			foreach ( $changes as $key => $value ) {
				switch ( $key ) {
					case 'label':
						$before['label'] = $field['label'];
						$field['label']  = (string) $value;
						$after['label']  = $field['label'];
						break;

					case 'instructions':
						$before['instructions'] = isset( $field['instructions'] ) ? $field['instructions'] : '';
						$field['instructions']  = (string) $value;
						$after['instructions']  = $field['instructions'];
						break;

					case 'placeholder':
						$before['placeholder'] = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
						$field['placeholder']  = (string) $value;
						$after['placeholder']  = $field['placeholder'];
						break;

					case 'required':
						$before['required'] = ! empty( $field['required'] );
						$field['required']  = $value ? 1 : 0;
						$after['required']  = (bool) $field['required'];
						break;

					case 'default':
						$before['default']      = isset( $field['default_value'] ) ? $field['default_value'] : '';
						$field['default_value'] = $value;
						$after['default']       = $value;
						break;

					case 'choices':
					case 'options':
						if ( ! in_array( $field['type'], array( 'select', 'radio', 'checkbox', 'button_group' ), true ) ) {
							return new WP_Error(
								'uich_model_choices_not_applicable',
								sprintf( 'Field "%s" is a %s, which has no choices.', $name, $field['type'] )
							);
						}

						$choices = Uich_Model::normalise_choices( $value );

						if ( ! $choices ) {
							return new WP_Error( 'uich_model_no_choices', sprintf( 'The choices given for "%s" are empty. A select with no choices cannot be filled in.', $name ) );
						}

						$before['choices'] = isset( $field['choices'] ) ? $field['choices'] : array();
						$field['choices']  = $choices;
						$after['choices']  = $choices;

						// Removing a choice does not remove the values already
						// stored against it: those objects keep a value that is
						// now outside the list and renders as a blank label.
						$dropped = array_diff( array_keys( (array) $before['choices'] ), array_keys( $choices ) );

						if ( $dropped ) {
							$after['dropped_choices_warning'] = sprintf(
								'The choices %s were removed from the list but NOT from any object already storing them. Those objects keep a value outside the list, which renders as an empty label. Find and fix them with uichemy-composer/custom-fields (action="get").',
								'"' . implode( '", "', $dropped ) . '"'
							);
						}
						break;
				}
			}

			acf_update_field( $field );

			$this->after_model_write();

			return array(
				'provider'  => 'acf',
				'group_key' => $found['group']['key'],
				'name'      => $field['name'],
				'key'       => $field['key'],
				'type'      => $field['type'],
				'before'    => $before,
				'after'     => $after,
				'note'      => 'The field NAME and TYPE are unchanged, so every existing binding and every stored value still works. That is the only kind of field change UiChemy makes.',
			);
		}

		// ============================================================
		// OWNERSHIP
		// ============================================================

		/**
		 * Post types ACF registered.
		 *
		 * @return array<int,string>
		 */
		public function owned_post_types() {
			if ( ! $this->has_internal_post_types() ) {
				return array();
			}

			$out = array();

			foreach ( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) as $row ) {
				if ( ! empty( $row['post_type'] ) ) {
					$out[] = (string) $row['post_type'];
				}
			}

			return $out;
		}

		/**
		 * Taxonomies ACF registered.
		 *
		 * @return array<int,string>
		 */
		public function owned_taxonomies() {
			if ( ! $this->has_internal_post_types() ) {
				return array();
			}

			$out = array();

			foreach ( (array) acf_get_internal_post_type_posts( 'acf-taxonomy' ) as $row ) {
				if ( ! empty( $row['taxonomy'] ) ) {
					$out[] = (string) $row['taxonomy'];
				}
			}

			return $out;
		}

		// ============================================================
		// INTERNALS
		// ============================================================

		/**
		 * Build ACF's location rules for an object type + subtype.
		 *
		 * @param array $args { object_type, object_subtype }.
		 * @return array|WP_Error
		 */
		private function location_rule( $args ) {
			$type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : 'post';
			$sub  = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';

			switch ( $type ) {
				case 'post':
					if ( '' === $sub ) {
						return new WP_Error(
							'uich_model_no_subtype',
							'"object_subtype" is required for object_type="post" - the post type slug the group appears on. A group with no post-type rule shows nowhere, so its fields can never be filled in.'
						);
					}

					if ( ! post_type_exists( $sub ) ) {
						return new WP_Error(
							'uich_model_unknown_post_type',
							sprintf( 'No post type "%s" exists. Register it first (action="register-post-type"), or use one of: %s.', $sub, implode( ', ', get_post_types( array(), 'names' ) ) )
						);
					}

					return array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $sub ) ) );

				case 'term':
					if ( '' === $sub || ! taxonomy_exists( $sub ) ) {
						return new WP_Error(
							'uich_model_unknown_taxonomy',
							sprintf( '"object_subtype" must be a registered taxonomy for object_type="term". Real ones: %s.', implode( ', ', get_taxonomies( array(), 'names' ) ) )
						);
					}

					return array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => $sub ) ) );

				case 'user':
					return array( array( array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ) ) );

				case 'options':
					return new WP_Error(
						'uich_model_options_page',
						'An ACF options page is created in ACF\'s own screens (or with acf_add_options_page() in code), not here - it needs a menu slug and a capability, which are site-structure decisions rather than content-model ones. Once it exists, its fields are readable and writable through uichemy-composer/custom-fields with object_type="options".'
					);
			}

			return new WP_Error(
				'uich_model_bad_object_type',
				sprintf( 'Unknown object_type "%s". Valid here: post, term, user.', $type )
			);
		}

		/**
		 * Create the ACF field rows for a set of descriptors.
		 *
		 * @param int   $parent_id ACF post ID of the owning field group, or of the
		 *                         parent field when writing repeater sub-fields.
		 *                         Numeric on purpose: acf_update_field() resolves a
		 *                         string `parent` only against acf-field posts, so a
		 *                         group KEY resolves to 0 and orphans the field.
		 * @param array $fields    Normalised descriptors.
		 * @param int   $offset    Starting menu_order.
		 * @return array<int,array>|WP_Error
		 */
		private function write_fields( $parent_id, $fields, $offset = 0 ) {
			$out = array();

			// Every type is mapped BEFORE anything is written, so an ACF-Pro-only
			// type in the middle of the list does not leave half a group created.
			foreach ( $fields as $field ) {
				$acf_type = $this->map_type( $field['type'] );
				if ( is_wp_error( $acf_type ) ) {
					return $acf_type;
				}

				foreach ( $field['sub_fields'] as $sub ) {
					$sub_type = $this->map_type( $sub['type'] );
					if ( is_wp_error( $sub_type ) ) {
						return $sub_type;
					}
				}
			}

			foreach ( array_values( $fields ) as $i => $field ) {
				$acf_type = $this->map_type( $field['type'] );
				$key      = uniqid( 'field_' );

				$payload = array(
					'key'          => $key,
					'label'        => $field['label'],
					'name'         => $field['name'],
					'type'         => $acf_type,
					'instructions' => $field['instructions'],
					'required'     => $field['required'] ? 1 : 0,
					'parent'       => (int) $parent_id,
					'menu_order'   => $offset + $i,
				);

				$payload = array_merge( $payload, $this->type_settings( $field, $acf_type ) );

				$saved = acf_update_field( $payload );

				if ( ! is_array( $saved ) ) {
					return new WP_Error( 'uich_model_acf_field_failed', sprintf( 'ACF did not save the field "%s".', $field['name'] ) );
				}

				$row = array(
					'name'       => $field['name'],
					'key'        => $saved['key'],
					'label'      => $field['label'],
					'type'       => $field['type'],
					'acf_type'   => $acf_type,
					'required'   => (bool) $field['required'],
					// The token that binds it, so the caller does not have to
					// guess and does not have to make a second call for it.
					'bind_token' => sprintf( "{{ post.meta('%s') }}", $field['name'] ),
				);

				if ( $field['choices'] ) {
					$row['choices'] = $field['choices'];
				}

				if ( $field['sub_fields'] ) {
					$subs = $this->write_fields( (int) $saved['ID'], $field['sub_fields'], 0 );

					if ( is_wp_error( $subs ) ) {
						return $subs;
					}

					$row['sub_fields'] = $subs;
					$row['bind_token'] = sprintf( "{%% for row in post.meta('%s') %%}...{%% endfor %%}", $field['name'] );
				}

				$out[] = $row;
			}

			return $out;
		}

		/**
		 * Per-type ACF settings for one descriptor.
		 *
		 * @param array  $field    Normalised descriptor.
		 * @param string $acf_type ACF field type.
		 * @return array
		 */
		private function type_settings( $field, $acf_type ) {
			$settings = array();

			if ( '' !== (string) $field['default'] ) {
				$settings['default_value'] = $field['default'];
			}

			switch ( $acf_type ) {
				case 'select':
				case 'radio':
				case 'checkbox':
					$settings['choices'] = (array) $field['choices'];
					if ( 'select' === $acf_type ) {
						$settings['multiple']   = $field['multiple'] ? 1 : 0;
						$settings['allow_null'] = $field['required'] ? 0 : 1;
					}
					break;

				case 'number':
					if ( '' !== (string) $field['min'] ) {
						$settings['min'] = $field['min'];
					}
					if ( '' !== (string) $field['max'] ) {
						$settings['max'] = $field['max'];
					}
					break;

				case 'true_false':
					$settings['ui'] = 1;
					break;

				case 'image':
				case 'file':
					// ID, not URL or array. Verified: the image token chain
					// ({{ post.meta('x').src('large') }}) resolves for ID and
					// Array and renders EMPTY for URL - and the ID form is the
					// one every other size and attribute is derived from.
					$settings['return_format'] = 'ID';
					break;

				case 'gallery':
					$settings['return_format'] = 'id';
					break;

				case 'date_picker':
					$settings['display_format'] = 'd/m/Y';
					// ISO on the way out, so |date and every consumer parses it
					// unambiguously. ACF's own d/m/Y return format is read as
					// m/d/Y downstream, which turns 7 September into 9 July.
					$settings['return_format'] = 'Y-m-d';
					break;

				case 'date_time_picker':
					$settings['display_format'] = 'd/m/Y g:i a';
					$settings['return_format'] = 'Y-m-d H:i:s';
					break;

				case 'time_picker':
					$settings['display_format'] = 'g:i a';
					$settings['return_format'] = 'H:i:s';
					break;

				case 'taxonomy':
					$settings['taxonomy']      = $field['taxonomy'];
					$settings['field_type']    = $field['multiple'] ? 'multi_select' : 'select';
					$settings['return_format'] = 'id';
					$settings['add_term']      = 0;
					$settings['save_terms']    = 0;
					break;

				case 'post_object':
					if ( $field['post_type'] ) {
						$settings['post_type'] = $field['post_type'];
					}
					$settings['multiple']      = $field['multiple'] ? 1 : 0;
					$settings['return_format'] = 'id';
					break;

				case 'wysiwyg':
					$settings['media_upload'] = 1;
					$settings['tabs']         = 'all';
					break;

				case 'repeater':
					$settings['layout']      = 'table';
					$settings['button_label'] = 'Add row';
					if ( '' !== (string) $field['min'] ) {
						$settings['min'] = $field['min'];
					}
					if ( '' !== (string) $field['max'] ) {
						$settings['max'] = $field['max'];
					}
					break;
			}

			if ( '' !== (string) $field['return_format'] ) {
				$settings['return_format'] = $field['return_format'];
			}

			return $settings;
		}

		/**
		 * Find one ACF group by key or title.
		 *
		 * @param string $group_key Key or title.
		 * @return array|WP_Error
		 */
		private function find_group( $group_key ) {
			$group_key = trim( (string) $group_key );

			if ( '' === $group_key ) {
				return new WP_Error(
					'uich_model_no_group',
					'"group_key" is required. List the groups with uichemy-composer/cpt (action="describe") - each post type carries the groups that target it.'
				);
			}

			$groups = (array) acf_get_field_groups();

			foreach ( $groups as $group ) {
				if ( isset( $group['key'] ) && $group['key'] === $group_key ) {
					return $group;
				}
			}

			// A title is what a caller is likely to have; accepting it saves a
			// round trip, and an ambiguous title is reported rather than guessed.
			$matches = array();

			foreach ( $groups as $group ) {
				if ( isset( $group['title'] ) && strtolower( (string) $group['title'] ) === strtolower( $group_key ) ) {
					$matches[] = $group;
				}
			}

			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}

			if ( count( $matches ) > 1 ) {
				$keys = wp_list_pluck( $matches, 'key' );

				return new WP_Error(
					'uich_model_ambiguous_group',
					sprintf( '%d field groups are titled "%s". Pass one of these keys instead: %s.', count( $matches ), $group_key, implode( ', ', $keys ) )
				);
			}

			return new WP_Error(
				'uich_model_unknown_group',
				sprintf(
					'No ACF field group "%s". Existing groups: %s.',
					$group_key,
					implode( ', ', array_map( static function ( $g ) {
						return $g['title'] . ' (' . $g['key'] . ')';
					}, $groups ) ) ?: 'none'
				)
			);
		}

		/**
		 * Find one field by name, in a given group or anywhere.
		 *
		 * @param string $group_key Group key, or ''.
		 * @param string $name      Field name.
		 * @return array{group:array,field:array}|WP_Error
		 */
		private function find_field( $group_key, $name ) {
			$groups = array();

			if ( '' !== trim( (string) $group_key ) ) {
				$group = $this->find_group( $group_key );
				if ( is_wp_error( $group ) ) {
					return $group;
				}
				$groups = array( $group );
			} else {
				$groups = (array) acf_get_field_groups();
			}

			$matches = array();

			foreach ( $groups as $group ) {
				foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
					if ( isset( $field['name'] ) && (string) $field['name'] === $name ) {
						$matches[] = array(
							'group' => $group,
							'field' => $field,
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
					$where[] = $m['group']['title'] . ' (' . $m['group']['key'] . ')';
				}

				return new WP_Error(
					'uich_model_ambiguous_field',
					sprintf( 'A field named "%s" exists in %d groups: %s. Pass "group_key" to say which.', $name, count( $matches ), implode( ', ', $where ) )
				);
			}

			return new WP_Error(
				'uich_model_unknown_field',
				sprintf( 'No ACF field named "%s"%s. Read the real names with uichemy-composer/custom-fields (action="list").', $name, '' !== trim( (string) $group_key ) ? ' in that group' : ' anywhere on this site' )
			);
		}

		/**
		 * Flush what a model change invalidates.
		 *
		 * Rewrite rules matter: a post type registered without them has a
		 * permalink that 404s until something flushes, which reads as "the CPT
		 * did not work".
		 *
		 * @return void
		 */
		private function after_model_write() {
			// ACF caches groups, fields and values in per-request stores, so a
			// read straight after a write in the same request returns the state
			// from before it - which is exactly what the field layer does when
			// it verifies.
			if ( function_exists( 'acf_get_store' ) ) {
				foreach ( array( 'field-groups', 'fields', 'values', 'post-types', 'taxonomies' ) as $name ) {
					$store = acf_get_store( $name );

					if ( $store && method_exists( $store, 'reset' ) ) {
						$store->reset();
					}
				}
			}

			Uich_Field_Registry::flush();

			// Deferred: register_post_type() has to run again on the next request
			// before the new rules exist, so flushing now writes the old set.
			delete_option( 'rewrite_rules' );
		}
	}
}
