<?php
/**
 * The UiChemy Custom Fields model provider - post types, taxonomies and field
 * groups kept by UiChemy's own module (includes/fields/ at the plugin root).
 *
 * Every write goes through the module's REST routes (uichemy-fields/v1) with
 * rest_do_request(), not through its storage classes. The routes are where the
 * module validates: reserved keys, the 20-character post type limit, the
 * Free/Pro field-type and location tiers, conditional-logic gating and key
 * finalisation. Calling the writer underneath them would skip all of it, and
 * what is created here must be exactly what the dashboard would have created,
 * so the user can keep editing it there.
 *
 * Present only while the Custom Fields feature is on (Settings > Features).
 * When it is one of several active modelling providers, Uich_Model::pick()
 * refuses until the caller names one - the same rule as ACF + JetEngine.
 *
 * @link       https://posimyth.com/
 * @since      5.2.6
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/model
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Model_Uichemy' ) ) {

	/**
	 * Writes the content model through UiChemy Custom Fields.
	 */
	final class Uich_Model_Uichemy extends Uich_Model_Provider {

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
		 * Whether the module and its REST surface are loaded.
		 *
		 * @return bool
		 */
		public function is_active() {
			return defined( 'UICH_FIELDS_REST_NS' )
				&& class_exists( 'Uich_Fields_Admin_Logic' )
				&& class_exists( 'Uich_Fields_Definitions_Repository' );
		}

		/**
		 * The module's field type for a neutral type.
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
				'date'          => 'date',
				'datetime'      => 'datetime',
				'time'          => 'time',
				'color'         => 'color',
				'link'          => 'link',
				'post_relation' => 'relationship',
				'page_link'     => 'page_link',
				'user'          => 'user',
				'range'         => 'range',
				'taxonomy'      => 'taxonomy',
				'repeater'      => 'repeater',
			);

			if ( isset( $map[ $type ] ) ) {
				return $map[ $type ];
			}

			if ( 'gallery' === $type ) {
				return new WP_Error(
					'uich_model_type_unsupported',
					'UiChemy Custom Fields has no gallery type. Use a "repeater" with one "image" sub-field - it stores the same list and each image keeps its own alt text.'
				);
			}

			return new WP_Error( 'uich_model_type_unsupported', sprintf( 'UiChemy Custom Fields has no "%s" type.', $type ) );
		}

		// ============================================================
		// POST TYPES AND TAXONOMIES
		// ============================================================

		/**
		 * Register a post type definition.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_post_type( $args ) {
			$args = $this->normalise_post_type_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$saved = $this->rest(
				'POST',
				'/cpt',
				array(
					'id'   => 0,
					'data' => array(
						'kind'                => 'cpt',
						'post_type_key'       => $args['slug'],
						'label'               => $args['label'],
						'singular_label'      => $args['singular_label'],
						'description'         => $args['description'],
						'active'              => true,
						'public'              => $args['public'],
						'publicly_queryable'  => $args['public'],
						'exclude_from_search' => ! $args['public'],
						'show_ui'             => true,
						'show_in_menu'        => true,
						'show_in_nav_menus'   => $args['public'],
						'show_in_rest'        => $args['show_in_rest'],
						'hierarchical'        => $args['hierarchical'],
						'has_archive'         => $args['has_archive'],
						'archive_slug'        => $args['has_archive'] ? $args['rewrite_slug'] : '',
						'supports'            => $args['supports'],
						'taxonomies'          => $args['taxonomies'],
						'menu_icon'           => $args['menu_icon'],
						'rewrite_enabled'     => true,
						'rewrite_slug'        => $args['rewrite_slug'],
					),
				)
			);

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->after_model_write();

			return array(
				'provider'       => 'uichemy',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'id'             => isset( $saved['id'] ) ? (int) $saved['id'] : 0,
				'registered_now' => post_type_exists( $args['slug'] ),
				'supports'       => $args['supports'],
				'taxonomies'     => $args['taxonomies'],
				'has_archive'    => $args['has_archive'],
				'archive_url'    => $args['has_archive'] ? home_url( '/' . $args['rewrite_slug'] . '/' ) : null,
				'edit_url'       => self::dashboard_url(),
				'notes'          => array_merge(
					$args['notes'],
					array(
						'UiChemy Custom Fields registers post types on "init", so "' . $args['slug'] . '" exists from the NEXT request. Anything that has to see it - a field group, a loop, a template condition - belongs in a separate call.',
						'A post type with no field group has no custom fields. Call action="register-field-group" next, with provider="uichemy" and object_subtype="' . $args['slug'] . '".',
						'It has no template either: a single ' . $args['singular_label'] . ' renders through the theme until you build one with uichemy-composer/theme-builder (action="create", type="single").',
					)
				),
			);
		}

		/**
		 * Register a taxonomy definition.
		 *
		 * @param array $args Incoming arguments.
		 * @return array|WP_Error
		 */
		public function register_taxonomy( $args ) {
			$args = $this->normalise_taxonomy_args( $args );
			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$saved = $this->rest(
				'POST',
				'/taxonomy',
				array(
					'id'   => 0,
					'data' => array(
						'kind'              => 'taxonomy',
						'taxonomy_key'      => $args['slug'],
						'label'             => $args['label'],
						'singular_label'    => $args['singular_label'],
						'description'       => $args['description'],
						'active'            => true,
						'object_type'       => $args['object_types'],
						'hierarchical'      => $args['hierarchical'],
						'public'            => $args['public'],
						'show_ui'           => true,
						'show_in_rest'      => $args['show_in_rest'],
						'show_admin_column' => $args['show_admin_column'],
						'rewrite'           => true,
						'rewrite_slug'      => $args['rewrite_slug'],
					),
				)
			);

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->after_model_write();

			return array(
				'provider'       => 'uichemy',
				'slug'           => $args['slug'],
				'label'          => $args['label'],
				'id'             => isset( $saved['id'] ) ? (int) $saved['id'] : 0,
				'object_types'   => $args['object_types'],
				'registered_now' => taxonomy_exists( $args['slug'] ),
				'edit_url'       => self::dashboard_url(),
				'notes'          => array(
					'The taxonomy exists from the NEXT request, and it has NO TERMS. Create them with action="ensure-term" and put them on posts with action="set-terms" in a later call, before anything reads this taxonomy - an empty one renders an empty archive and an empty filter, silently.',
				),
			);
		}

		// ============================================================
		// FIELD GROUPS
		// ============================================================

		/**
		 * Create a field group.
		 *
		 * @param array $args   { title, object_type, object_subtype, description? }.
		 * @param array $fields Normalised field descriptors.
		 * @return array|WP_Error
		 */
		public function register_field_group( $args, $fields ) {
			$args  = is_array( $args ) ? $args : array();
			$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';

			if ( '' === $title ) {
				return new WP_Error( 'uich_model_no_group_title', '"title" is required - it is what the user sees above the fields in the editor.' );
			}

			$location = $this->location_rules( $args );
			if ( is_wp_error( $location ) ) {
				return $location;
			}

			$configs = $this->field_configs( $fields );
			if ( is_wp_error( $configs ) ) {
				return $configs;
			}

			$saved = $this->rest(
				'POST',
				'/field-groups',
				array(
					'data' => array(
						'title'          => $title,
						'key'            => self::group_key_for( $title ),
						'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
						'fields'         => $configs,
						'location_rules' => $location,
						'active'         => true,
					),
				)
			);

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->after_model_write();

			$meta = isset( $saved['data'] ) && is_array( $saved['data'] ) ? $saved['data'] : array();

			return array(
				'provider'       => 'uichemy',
				'group_key'      => isset( $meta['key'] ) ? (string) $meta['key'] : '',
				'title'          => $title,
				'id'             => isset( $saved['id'] ) ? (int) $saved['id'] : 0,
				'object_type'    => isset( $args['object_type'] ) ? $args['object_type'] : 'post',
				'object_subtype' => isset( $args['object_subtype'] ) ? $args['object_subtype'] : '',
				'fields'         => self::field_summary( isset( $meta['fields'] ) ? $meta['fields'] : array() ),
				'edit_url'       => self::dashboard_url(),
			);
		}

		/**
		 * Append fields to an existing group.
		 *
		 * @param string $group_key Native group key.
		 * @param array  $fields    Normalised descriptors.
		 * @return array|WP_Error
		 */
		public function add_fields( $group_key, $fields ) {
			$group = $this->find_group( $group_key );
			if ( is_wp_error( $group ) ) {
				return $group;
			}

			$existing = isset( $group['meta']['fields'] ) ? (array) $group['meta']['fields'] : array();

			foreach ( $existing as $field ) {
				foreach ( $fields as $new ) {
					if ( is_array( $field ) && isset( $field['name'] ) && (string) $field['name'] === $new['name'] ) {
						return new WP_Error(
							'uich_model_field_exists',
							sprintf(
								'"%s" already exists in group "%s" (type %s). Nothing was added. Change its label or choices with action="update-field"; a different field needs a different name.',
								$new['name'],
								$group['title'],
								isset( $field['type'] ) ? $field['type'] : '?'
							)
						);
					}
				}
			}

			$configs = $this->field_configs( $fields );
			if ( is_wp_error( $configs ) ) {
				return $configs;
			}

			$saved = $this->rest( 'PUT', '/field-groups/' . (int) $group['id'], array( 'data' => array( 'fields' => array_merge( array_values( $existing ), $configs ) ) ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->after_model_write();

			$names = wp_list_pluck( $fields, 'name' );
			$meta  = isset( $saved['data'] ) && is_array( $saved['data'] ) ? $saved['data'] : array();
			$added = array_values(
				array_filter(
					self::field_summary( isset( $meta['fields'] ) ? $meta['fields'] : array() ),
					static function ( $row ) use ( $names ) {
						return in_array( $row['name'], $names, true );
					}
				)
			);

			return array(
				'provider'  => 'uichemy',
				'group_key' => $group['key'],
				'title'     => $group['title'],
				'added'     => $added,
				'edit_url'  => self::dashboard_url(),
			);
		}

		/**
		 * Change a field's label, instructions, required flag, choices, default.
		 *
		 * @param string $group_key Native group key, or '' to search every group.
		 * @param string $name      Field name.
		 * @param array  $changes   Requested changes.
		 * @return array|WP_Error
		 */
		public function update_field( $group_key, $name, $changes ) {
			$changes = $this->allowed_field_changes( $changes );
			if ( is_wp_error( $changes ) ) {
				return $changes;
			}

			$found = $this->find_field( $group_key, $name );
			if ( is_wp_error( $found ) ) {
				return $found;
			}

			$group  = $found['group'];
			$index  = $found['index'];
			$fields = array_values( (array) $group['meta']['fields'] );
			$field  = $fields[ $index ];
			$before = array();
			$after  = array();

			foreach ( $changes as $key => $value ) {
				switch ( $key ) {
					case 'label':
					case 'instructions':
					case 'placeholder':
						$before[ $key ] = isset( $field[ $key ] ) ? $field[ $key ] : '';
						$field[ $key ]  = (string) $value;
						$after[ $key ]  = $field[ $key ];
						break;

					case 'required':
						$before['required'] = ! empty( $field['required'] );
						$field['required']  = (bool) $value;
						$after['required']  = $field['required'];
						break;

					case 'default':
						$before['default']      = isset( $field['default_value'] ) ? $field['default_value'] : '';
						$field['default_value'] = $value;
						$after['default']       = $value;
						break;

					case 'choices':
					case 'options':
						if ( ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'select', 'radio', 'checkbox' ), true ) ) {
							return new WP_Error( 'uich_model_no_choices_here', sprintf( '"%s" is a %s field, which has no choices.', $name, isset( $field['type'] ) ? $field['type'] : '?' ) );
						}

						$choices = Uich_Model::normalise_choices( $value );
						if ( ! $choices ) {
							return new WP_Error( 'uich_model_no_choices', 'The new choice list is empty. A choice field with no choices accepts nothing.' );
						}

						$old               = Uich_Field_Provider_Uichemy::choices( isset( $field['choices'] ) ? $field['choices'] : null );
						$before['choices'] = Uich_Model::choices_for_output( $old );
						$field['choices']  = self::choices_text( $choices );
						$after['choices']  = Uich_Model::choices_for_output( $choices );

						$dropped = array_diff( array_keys( (array) $old ), array_keys( $choices ) );
						if ( $dropped ) {
							$after['dropped_choices_warning'] = sprintf(
								'The choices %s were removed from the list but NOT from any object already storing them. Those objects keep a value outside the list, which the module drops on the next save. Find and fix them with uichemy-composer/custom-fields (action="get").',
								'"' . implode( '", "', $dropped ) . '"'
							);
						}
						break;
				}
			}

			$fields[ $index ] = $field;

			$saved = $this->rest( 'PUT', '/field-groups/' . (int) $group['id'], array( 'data' => array( 'fields' => $fields ) ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->after_model_write();

			return array(
				'provider'  => 'uichemy',
				'group_key' => $group['key'],
				'name'      => $name,
				'key'       => isset( $field['key'] ) ? $field['key'] : null,
				'type'      => isset( $field['type'] ) ? $field['type'] : '',
				'before'    => $before,
				'after'     => $after,
				'note'      => 'The field NAME and TYPE are unchanged, so every existing binding and every stored value still works. That is the only kind of field change UiChemy makes.',
			);
		}

		// ============================================================
		// OWNERSHIP
		// ============================================================

		/**
		 * Post types this module defines.
		 *
		 * @return array<int,string>
		 */
		public function owned_post_types() {
			return $this->owned_keys( 'cpt', 'post_type_key' );
		}

		/**
		 * Taxonomies this module defines.
		 *
		 * @return array<int,string>
		 */
		public function owned_taxonomies() {
			return $this->owned_keys( 'tax', 'taxonomy_key' );
		}

		/**
		 * Keys of the published definitions of one kind.
		 *
		 * @param string $bucket by_kind() bucket: cpt | tax.
		 * @param string $field  Meta key holding the slug.
		 * @return array<int,string>
		 */
		private function owned_keys( $bucket, $field ) {
			if ( ! $this->is_active() ) {
				return array();
			}

			$rows = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
			$out  = array();

			foreach ( isset( $rows[ $bucket ] ) ? $rows[ $bucket ] : array() as $row ) {
				if ( 'publish' === $row['status'] && ! empty( $row['meta'][ $field ] ) ) {
					$out[] = (string) $row['meta'][ $field ];
				}
			}

			return $out;
		}

		// ============================================================
		// HELPERS
		// ============================================================

		/**
		 * Location rules for { object_type, object_subtype }.
		 *
		 * A post type or taxonomy this module defined but has not registered
		 * yet (it registers on the next request) is accepted, so a model can be
		 * built in consecutive calls without waiting for a page load between.
		 *
		 * @param array $args { object_type, object_subtype }.
		 * @return array|WP_Error
		 */
		private function location_rules( $args ) {
			$type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : 'post';
			$sub  = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';

			switch ( $type ) {
				case 'post':
					if ( '' === $sub ) {
						return new WP_Error( 'uich_model_no_subtype', '"object_subtype" is required for object_type="post" - the post type slug the group appears on. A group with no post-type rule shows nowhere, so its fields can never be filled in.' );
					}
					if ( ! post_type_exists( $sub ) && ! in_array( $sub, $this->owned_post_types(), true ) ) {
						return new WP_Error( 'uich_model_unknown_post_type', sprintf( 'No post type "%s" exists. Register it first (action="register-post-type"), or use one of: %s.', $sub, implode( ', ', get_post_types( array(), 'names' ) ) ) );
					}
					return array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $sub ) ) );

				case 'term':
					if ( '' === $sub || ( ! taxonomy_exists( $sub ) && ! in_array( $sub, $this->owned_taxonomies(), true ) ) ) {
						return new WP_Error( 'uich_model_unknown_taxonomy', sprintf( '"object_subtype" must be a registered taxonomy for object_type="term". Real ones: %s.', implode( ', ', get_taxonomies( array(), 'names' ) ) ) );
					}
					return array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => $sub ) ) );

				case 'user':
					return array( array( array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ) ) );

				case 'options':
					return new WP_Error( 'uich_model_options_unsupported', 'UiChemy Custom Fields has no options pages. Put site-wide values on a dedicated page or a single post of a "settings" post type instead, or use ACF (provider="acf") if an options page is required.' );
			}

			return new WP_Error( 'uich_model_bad_object_type', sprintf( 'Unknown object_type "%s". Use post, term or user.', $type ) );
		}

		/**
		 * Neutral descriptors -> the module's field configs.
		 *
		 * @param array $fields Normalised descriptors from Uich_Model::normalise_fields().
		 * @return array|WP_Error
		 */
		private function field_configs( $fields ) {
			$out = array();

			foreach ( (array) $fields as $field ) {
				$type = $this->map_type( $field['type'] );
				if ( is_wp_error( $type ) ) {
					return new WP_Error( $type->get_error_code(), sprintf( 'Field "%s": %s', $field['name'], $type->get_error_message() ) );
				}

				if ( 'post_relation' === $field['type'] && ! $field['multiple'] ) {
					$type = 'post_object';
				}

				$config = array(
					'name'         => $field['name'],
					'label'        => $field['label'],
					'type'         => $type,
					'instructions' => $field['instructions'],
					'required'     => (bool) $field['required'],
				);

				if ( '' !== $field['default'] && null !== $field['default'] ) {
					$config['default_value'] = $field['default'];
				}

				switch ( $type ) {
					case 'number':
						foreach ( array( 'min', 'max' ) as $k ) {
							if ( '' !== $field[ $k ] ) {
								$config[ $k ] = $field[ $k ];
							}
						}
						break;

					case 'select':
					case 'radio':
					case 'checkbox':
						$config['choices'] = self::choices_text( (array) $field['choices'] );
						if ( 'select' === $type ) {
							$config['multiple'] = (bool) $field['multiple'];
						}
						break;

					// ISO storage: the module stores a date in its return format, so
					// pinning that to ISO keeps the stored value sortable and the same
					// string a caller sends. The editor still DISPLAYS d/m/Y.
					case 'date':
						$config['date_display_format'] = 'd/m/Y';
						$config['date_return_format']  = 'Y-m-d';
						break;
					case 'datetime':
						$config['datetime_display_format'] = 'd/m/Y g:i a';
						$config['datetime_return_format']  = 'Y-m-d H:i:s';
						break;
					case 'time':
						$config['time_display_format'] = 'g:i a';
						$config['time_return_format']  = 'H:i:s';
						break;

					case 'image':
					case 'file':
						if ( '' !== $field['return_format'] ) {
							$config['return_format'] = $field['return_format'];
						}
						break;

					case 'relationship':
						$config['relationship_post_types'] = $field['post_type'];
						break;
					case 'post_object':
						$config['post_object_post_types'] = $field['post_type'];
						break;

					case 'taxonomy':
						$config['taxonomy_name']       = $field['taxonomy'];
						$config['multiple']            = (bool) $field['multiple'];
						$config['taxonomy_appearance'] = $field['multiple'] ? 'checkbox' : 'select';
						break;

					case 'repeater':
						$subs = $this->field_configs( $field['sub_fields'] );
						if ( is_wp_error( $subs ) ) {
							return $subs;
						}
						$config['fields'] = $subs;
						break;
				}

				$out[] = $config;
			}

			return $out;
		}

		/**
		 * value => label as the editor's "value : label" lines.
		 *
		 * @param array $choices value => label.
		 * @return string
		 */
		private static function choices_text( $choices ) {
			$lines = array();
			foreach ( $choices as $value => $label ) {
				$lines[] = (string) $value === (string) $label ? (string) $value : $value . ' : ' . $label;
			}
			return implode( "\n", $lines );
		}

		/**
		 * name / label / type rows for a response.
		 *
		 * @param array $fields Stored field configs.
		 * @return array<int,array>
		 */
		private static function field_summary( $fields ) {
			$out = array();
			foreach ( (array) $fields as $field ) {
				if ( Uich_Field_Provider_Uichemy::has_name( $field ) ) {
					$out[] = array(
						'name'  => (string) $field['name'],
						'label' => isset( $field['label'] ) ? (string) $field['label'] : '',
						'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
						'key'   => isset( $field['key'] ) ? (string) $field['key'] : '',
					);
				}
			}
			return $out;
		}

		/**
		 * A readable group key from its title, e.g. "Movie Details" -> movie_details.
		 *
		 * The module makes it unique if it is taken, and the response carries the
		 * key that was actually stored.
		 *
		 * @param string $title Group title.
		 * @return string
		 */
		private static function group_key_for( $title ) {
			$key = trim( preg_replace( '/_+/', '_', preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( $title ) ) ) ), '_' );
			// Trimmed AFTER the cut too: a key ending in "_" is refused by the module.
			$key = rtrim( substr( $key, 0, 40 ), '_' );
			return '' !== $key ? $key : 'field_group';
		}

		/**
		 * One published group by key.
		 *
		 * @param string $group_key Native group key.
		 * @return array{id:int,key:string,title:string,meta:array}|WP_Error
		 */
		private function find_group( $group_key ) {
			$group_key = (string) $group_key;

			foreach ( $this->all_groups() as $group ) {
				if ( $group['key'] === $group_key ) {
					return $group;
				}
			}

			// The schema accepts a title too ("Coffee Details"); an ambiguous one is
			// reported rather than guessed, since the wrong group gets real fields.
			$by_title = array_values(
				array_filter(
					$this->all_groups(),
					static function ( $group ) use ( $group_key ) {
						return 0 === strcasecmp( trim( $group['title'] ), trim( $group_key ) );
					}
				)
			);

			if ( 1 === count( $by_title ) ) {
				return $by_title[0];
			}

			if ( count( $by_title ) > 1 ) {
				return new WP_Error(
					'uich_model_ambiguous_group',
					sprintf( 'Several UiChemy Custom Fields groups are titled "%s" (keys: %s). Pass the key.', $group_key, implode( ', ', wp_list_pluck( $by_title, 'key' ) ) )
				);
			}

			$keys = wp_list_pluck( $this->all_groups(), 'key' );

			return new WP_Error(
				'uich_model_unknown_group',
				sprintf( 'No UiChemy Custom Fields group with key "%s". Its groups: %s. Read them with action="describe".', $group_key, $keys ? implode( ', ', $keys ) : 'none' )
			);
		}

		/**
		 * One field by name, in one group or in whichever group holds it.
		 *
		 * @param string $group_key Native group key, or ''.
		 * @param string $name      Field name.
		 * @return array{group:array,index:int}|WP_Error
		 */
		private function find_field( $group_key, $name ) {
			$groups = '' !== (string) $group_key ? array( $this->find_group( $group_key ) ) : $this->all_groups();
			$hits   = array();

			foreach ( $groups as $group ) {
				if ( is_wp_error( $group ) ) {
					return $group;
				}
				foreach ( array_values( (array) $group['meta']['fields'] ) as $i => $field ) {
					if ( is_array( $field ) && isset( $field['name'] ) && (string) $field['name'] === (string) $name ) {
						$hits[] = array(
							'group' => $group,
							'index' => $i,
						);
					}
				}
			}

			if ( ! $hits ) {
				return new WP_Error( 'uich_model_unknown_field', sprintf( 'No UiChemy Custom Fields field named "%s"%s.', $name, '' !== (string) $group_key ? ' in group "' . $group_key . '"' : '' ) );
			}

			if ( count( $hits ) > 1 ) {
				return new WP_Error(
					'uich_model_ambiguous_field',
					sprintf( '"%s" exists in several groups (%s). Pass "group_key" to say which one.', $name, implode( ', ', wp_list_pluck( wp_list_pluck( $hits, 'group' ), 'key' ) ) )
				);
			}

			return $hits[0];
		}

		/**
		 * Every published group.
		 *
		 * @return array<int,array{id:int,key:string,title:string,meta:array}>
		 */
		private function all_groups() {
			$rows = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
			$out  = array();

			foreach ( $rows['group_fields'] as $row ) {
				if ( 'publish' !== $row['status'] ) {
					continue;
				}
				$meta  = is_array( $row['meta'] ) ? $row['meta'] : array();
				$out[] = array(
					'id'    => (int) $row['id'],
					'key'   => isset( $meta['key'] ) ? (string) $meta['key'] : '',
					'title' => ! empty( $meta['title'] ) ? (string) $meta['title'] : (string) $row['title'],
					'meta'  => $meta,
				);
			}

			return $out;
		}

		/**
		 * Call one of the module's REST routes in-process.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route below the namespace, e.g. "/cpt".
		 * @param array  $params Body parameters.
		 * @return array|WP_Error Response data.
		 */
		private function rest( $method, $route, $params ) {
			$request = new WP_REST_Request( $method, '/' . UICH_FIELDS_REST_NS . $route );
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}

			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				$error = $response->as_error();
				return new WP_Error( 'uich_model_uichemy_refused', 'UiChemy Custom Fields refused this: ' . $error->get_error_message(), array( 'code' => $error->get_error_code() ) );
			}

			$data = $response->get_data();

			if ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] ) {
				return new WP_Error( 'uich_model_uichemy_refused', 'UiChemy Custom Fields refused this: ' . ( isset( $data['message'] ) ? $data['message'] : 'no reason given.' ) );
			}

			return is_array( $data ) ? $data : array();
		}

		/**
		 * The Custom Fields screen in the UiChemy dashboard.
		 *
		 * @return string
		 */
		private static function dashboard_url() {
			return admin_url( 'admin.php?page=uichemy#/content-fields' );
		}

		/**
		 * Drop the caches a read straight after this write would hit.
		 *
		 * @return void
		 */
		private function after_model_write() {
			Uich_Fields_Definitions_Repository::get_instance()->flush();
			Uich_Field_Registry::flush();
			delete_option( 'rewrite_rules' );
		}
	}
}
