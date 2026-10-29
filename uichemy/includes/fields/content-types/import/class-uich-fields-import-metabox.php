<?php
/**
 * Meta Box import source.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_MetaBox
 */
class Uich_Fields_Import_MetaBox extends Uich_Fields_Import_Source {

	/**
	 * Meta Box type => this module's type. Meta Box ships ~53 types against this module's 24, so several
	 * collapse onto one target and a dozen have no equivalent at all.
	 *
	 * @return array<string, string>
	 */
	public static function type_map() {
		return array(
			'text'              => 'text',
			'textarea'          => 'textarea',
			'number'            => 'number',
			'range'             => 'range',
			'slider'            => 'range',
			'email'             => 'email',
			'url'               => 'url',
			'wysiwyg'           => 'wysiwyg',
			'select'            => 'select',
			'select_advanced'   => 'select',
			'radio'             => 'radio',
			'button_group'      => 'radio',
			'image_select'      => 'radio',
			'checkbox_list'     => 'checkbox',
			'input_list'        => 'checkbox',
			'checkbox'          => 'true_false',
			'switch'            => 'true_false',
			'date'              => 'date',
			'datetime'          => 'datetime',
			'time'              => 'time',
			'color'             => 'color',
			'image'             => 'image',
			'single_image'      => 'image',
			'image_advanced'    => 'image',
			'image_upload'      => 'image',
			'file'              => 'file',
			'file_input'        => 'file',
			'file_upload'       => 'file',
			'media'             => 'file',
			'video'             => 'file',
			'post'              => 'post_object',
			'taxonomy'          => 'taxonomy',
			'taxonomy_advanced' => 'taxonomy',
			'user'              => 'user',
			'link'              => 'link',
			'oembed'            => 'url',
			'map'               => 'text',
			'osm'               => 'text',
		);
	}

	/**
	 * Types intentionally not imported.
	 *
	 * @return array<string, string>
	 */
	public static function skip_reasons() {
		return array(
			'password'        => __( 'Password fields store cleartext. UiChemy does not offer this type.', 'uichemy' ),
			'heading'         => __( 'Layout-only field, stores no value.', 'uichemy' ),
			'divider'         => __( 'Layout-only field, stores no value.', 'uichemy' ),
			'custom_html'     => __( 'Layout-only field, stores no value.', 'uichemy' ),
			'button'          => __( 'Layout-only field, stores no value.', 'uichemy' ),
			'key_value'       => __( 'No UiChemy equivalent.', 'uichemy' ),
			'text_list'       => __( 'No UiChemy equivalent.', 'uichemy' ),
			'fieldset_text'   => __( 'No UiChemy equivalent.', 'uichemy' ),
			'autocomplete'    => __( 'No UiChemy equivalent.', 'uichemy' ),
			'background'      => __( 'No UiChemy equivalent.', 'uichemy' ),
			'icon'            => __( 'No UiChemy equivalent.', 'uichemy' ),
			'sidebar'         => __( 'No UiChemy equivalent.', 'uichemy' ),
			'block_editor'    => __( 'No UiChemy equivalent.', 'uichemy' ),
			'select_tree'     => __( 'No UiChemy equivalent.', 'uichemy' ),
			'choice'          => __( 'No UiChemy equivalent.', 'uichemy' ),
			'object_choice'   => __( 'No UiChemy equivalent.', 'uichemy' ),
			'multiple_values' => __( 'No UiChemy equivalent.', 'uichemy' ),
		);
	}

	/**
	 * Machine slug for this source.
	 *
	 * @return string
	 */
	public function slug() {
		return 'metabox';
	}

	/**
	 * Human label for the UI and reports.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Meta Box', 'uichemy' );
	}

	/**
	 * Whether this source has anything to import on this site.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		return (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'meta-box' LIMIT 1" ) || $this->code_registered_count() > 0;
	}

	/**
	 * How many field groups Meta Box holds in its registry (registered in code, or by the Builder).
	 *
	 * @return int
	 */
	private function code_registered_count() {
		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			return 0;
		}
		$registry = rwmb_get_registry( 'meta_box' );
		return ( is_object( $registry ) && method_exists( $registry, 'all' ) ) ? count( (array) $registry->all() ) : 0;
	}

	/**
	 * Says plainly that only Meta Box Builder groups can be imported.
	 *
	 * @return string[]
	 */
	public function scan_notes() {
		if ( $this->code_registered_count() < 1 ) {
			return array();
		}
		return array(
			__( 'Only field groups created with the Meta Box Builder can be imported. Groups registered in code (the rwmb_meta_boxes filter) have no stored definition to read, so recreate them in UiChemy; their saved values stay in the database.', 'uichemy' ),
		);
	}

	/**
	 * Post-type definitions shaped for Admin_Logic::save_cpt().
	 *
	 * @return array
	 */
	public function read_post_types() {
		return $this->read_object_defs( 'mb-post-type', 'post_type_key' );
	}

	/**
	 * Taxonomy definitions shaped for Admin_Logic::save_taxonomy().
	 *
	 * @return array
	 */
	public function read_taxonomies() {
		return $this->read_object_defs( 'mb-taxonomy', 'taxonomy_key' );
	}

	/**
	 * Meta Box stores CPT/taxonomy definitions as a JSON-ish post; the useful bits are the slug,
	 * labels and a handful of flags.
	 *
	 * @param string $post_type mb-post-type | mb-taxonomy.
	 * @param string $key_field Target key field name.
	 * @return array
	 */
	private function read_object_defs( $post_type, $key_field ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' ORDER BY ID ASC",
				$post_type
			)
		);

		$out = array();
		foreach ( $rows as $p ) {
			$key = $this->clean_name( $p->post_name );
			if ( '' === $key ) {
				continue;
			}
			$settings = get_post_meta( (int) $p->ID, 'settings', true );
			$settings = is_array( $settings ) ? $settings : array();
			$labels   = isset( $settings['labels'] ) && is_array( $settings['labels'] ) ? $settings['labels'] : array();

			$warnings = array();
			$is_cpt   = ( 'post_type_key' === $key_field );
			$public   = ! isset( $settings['public'] ) || ! empty( $settings['public'] );
			$show_ui  = $this->mb_bool( $settings, 'show_ui', $public );

			$data = array(
				$key_field       => $key,
				'label'          => '' !== $p->post_title ? $p->post_title : $key,
				'singular_label' => isset( $labels['singular_name'] ) ? $labels['singular_name'] : $p->post_title,
				'public'         => $public,
				'hierarchical'   => ! empty( $settings['hierarchical'] ),
				'show_ui'        => $show_ui,
				'description'    => isset( $settings['description'] ) ? (string) $settings['description'] : '',
			);

			// Shared advanced keys. Meta Box stores register_post_type()/register_taxonomy() argument
			// names verbatim, so these read straight across.
			$data['show_in_nav_menus'] = $this->mb_bool( $settings, 'show_in_nav_menus', $public );
			$data['show_in_rest']      = $this->mb_bool( $settings, 'show_in_rest', $public );
			$data['query_var']         = ( false !== ( isset( $settings['query_var'] ) ? $settings['query_var'] : true ) );
			if ( ! empty( $settings['query_var'] ) && is_string( $settings['query_var'] ) && $settings['query_var'] !== $key ) {
				$data['query_var_string'] = (string) $settings['query_var'];
			}
			if ( $data['show_in_rest'] ) {
				if ( ! empty( $settings['rest_base'] ) && $settings['rest_base'] !== $key ) {
					$data['rest_base'] = (string) $settings['rest_base'];
				}
				if ( ! empty( $settings['rest_controller_class'] ) ) {
					$default_controller = $is_cpt ? 'WP_REST_Posts_Controller' : 'WP_REST_Terms_Controller';
					if ( $default_controller !== $settings['rest_controller_class'] ) {
						$data['rest_controller_class'] = (string) $settings['rest_controller_class'];
					}
				}
				if ( ! empty( $settings['rest_namespace'] ) && 'wp/v2' !== $settings['rest_namespace'] ) {
					$warnings[] = sprintf(
						/* translators: 1: definition key, 2: REST namespace. */
						__( '"%1$s": the REST namespace "%2$s" was not carried over — UiChemy always registers under wp/v2.', 'uichemy' ),
						$key,
						(string) $settings['rest_namespace']
					);
				}
			}

			$rewrite = isset( $settings['rewrite'] ) ? $settings['rewrite'] : true;
			$data    = array_merge( $data, $this->map_wp_rewrite( $rewrite, $key, $is_cpt ? 'cpt' : 'taxonomy' ) );

			if ( $is_cpt ) {
				$data['has_archive'] = ! empty( $settings['has_archive'] );
				$data['supports']    = isset( $settings['supports'] ) ? (array) $settings['supports'] : array();
				$data['taxonomies']  = isset( $settings['taxonomies'] ) ? (array) $settings['taxonomies'] : array();

				// Meta Box accepts a slug string in has_archive; Uichemy keeps that in archive_slug.
				if ( ! empty( $settings['has_archive'] ) && is_string( $settings['has_archive'] ) ) {
					$data['archive_slug'] = (string) $settings['has_archive'];
				}
				$data['show_in_menu']        = $this->mb_bool( $settings, 'show_in_menu', $show_ui );
				$data['show_in_admin_bar']   = $this->mb_bool( $settings, 'show_in_admin_bar', $data['show_in_menu'] );
				$data['exclude_from_search'] = $this->mb_bool( $settings, 'exclude_from_search', ! $public );
				$data['publicly_queryable']  = $this->mb_bool( $settings, 'publicly_queryable', $public );
				$data['can_export']          = $this->mb_bool( $settings, 'can_export', true );
				$data['delete_with_user']    = $this->mb_bool( $settings, 'delete_with_user', false );
				$data['map_meta_cap']        = $this->mb_bool( $settings, 'map_meta_cap', true );
				if ( isset( $settings['menu_position'] ) && '' !== $settings['menu_position'] ) {
					$data['menu_position'] = (int) $settings['menu_position'];
				}
				if ( ! empty( $settings['menu_icon'] ) ) {
					$data['menu_icon'] = (string) $settings['menu_icon'];
				}
				// register_post_type() takes a string or a [singular, plural] pair; Uichemy stores one key.
				if ( ! empty( $settings['capability_type'] ) ) {
					$cap = $settings['capability_type'];
					if ( is_array( $cap ) ) {
						$singular = isset( $cap[0] ) ? sanitize_key( $cap[0] ) : '';
						$plural   = isset( $cap[1] ) ? sanitize_key( $cap[1] ) : '';
						if ( '' !== $singular ) {
							$data['capability_type'] = $singular;
							if ( '' !== $plural && $plural !== $singular . 's' ) {
								$warnings[] = sprintf(
									/* translators: 1: post type key, 2: plural capability name, 3: derived plural. */
									__( '"%1$s": the plural capability name "%2$s" was not carried over — UiChemy stores one capability type, so WordPress will derive "%3$s". Grant the correct capabilities manually.', 'uichemy' ),
									$key,
									$plural,
									$singular . 's'
								);
							}
						}
					} else {
						$data['capability_type'] = sanitize_key( (string) $cap );
					}
				}
				// Per-capability overrides. Uichemy keeps a 7-key allowlist and drops the rest, so
				// create_posts (Meta Box's way of blocking creation) cannot come across.
				if ( ! empty( $settings['capabilities'] ) && is_array( $settings['capabilities'] ) ) {
					$data['capabilities'] = $settings['capabilities'];
					$unsupported          = array_diff( array_keys( $settings['capabilities'] ), Uich_Fields_Fields::get_cpt_capability_keys() );
					if ( $unsupported ) {
						$warnings[] = sprintf(
							/* translators: 1: post type key, 2: comma-separated capability names. */
							__( '"%1$s": these capability overrides have no UiChemy equivalent and were dropped: %2$s.', 'uichemy' ),
							$key,
							implode( ', ', $unsupported )
						);
					}
				}
			} else {
				$data['object_type']       = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
				$data['show_admin_column'] = $this->mb_bool( $settings, 'show_admin_column', false );
				$data['show_tag_cloud']    = $this->mb_bool( $settings, 'show_tagcloud', $show_ui );
				if ( isset( $settings['sort'] ) ) {
					$data['sort'] = ! empty( $settings['sort'] );
				}
				if ( ! empty( $settings['capabilities'] ) && is_array( $settings['capabilities'] ) ) {
					$data['capabilities'] = $settings['capabilities'];
				}
				$dt = isset( $settings['default_term'] ) ? $settings['default_term'] : null;
				if ( ! empty( $dt ) ) {
					// register_taxonomy() accepts either a term name or an array of name/slug/description.
					$dt_arr                       = is_array( $dt ) ? $dt : array( 'name' => (string) $dt );
					$data['default_term_enabled'] = ! empty( $dt_arr['name'] );
					$data['default_term']         = array(
						'name'        => isset( $dt_arr['name'] ) ? (string) $dt_arr['name'] : '',
						'slug'        => isset( $dt_arr['slug'] ) ? (string) $dt_arr['slug'] : '',
						'description' => isset( $dt_arr['description'] ) ? (string) $dt_arr['description'] : '',
					);
				}
				if ( isset( $settings['show_in_quick_edit'] ) && empty( $settings['show_in_quick_edit'] ) ) {
					$warnings[] = sprintf(
						/* translators: %s: taxonomy key. */
						__( '"%s": "Show in Quick Edit" was off in the source but UiChemy has no such setting, so it stays on.', 'uichemy' ),
						$key
					);
				}
				if ( is_array( $rewrite ) && ! empty( $rewrite['with_front'] ) && self::permalink_has_front() ) {
					$warnings[] = sprintf(
						/* translators: %s: taxonomy key. */
						__( '"%s": the source prepended the permalink front base to term URLs; UiChemy always omits it, so term URLs will differ.', 'uichemy' ),
						$key
					);
				}
			}

			$overrides = $this->map_labels( $labels );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, $is_cpt ? 'cpt' : 'taxonomy' );

			$out[] = array(
				'source_id' => (int) $p->ID,
				'warnings'  => $warnings,
				'data'      => $data,
			);
		}
		return $out;
	}

	/**
	 * Read a Meta Box boolean setting, honouring the fallback WordPress itself uses when unset.
	 *
	 * @param array  $settings Definition settings.
	 * @param string $key      Setting name.
	 * @param bool   $fallback Value when the setting is not present at all.
	 * @return bool
	 */
	private function mb_bool( $settings, $key, $fallback ) {
		if ( ! array_key_exists( $key, $settings ) || null === $settings[ $key ] || '' === $settings[ $key ] ) {
			return (bool) $fallback;
		}
		// Test is_bool() FIRST: Meta Box stores real booleans, and (string) false is '' — not '0' — so a
		// string comparison alone read every stored false as true.
		if ( is_bool( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}
		return ! in_array( (string) $settings[ $key ], array( '0', 'false' ), true );
	}

	/**
	 * Field groups shaped for the field-groups REST controller.
	 *
	 * @return array
	 */
	public function read_groups() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'meta-box' AND post_status != 'trash' ORDER BY ID ASC"
		);

		$out = array();
		foreach ( $rows as $g ) {
			$skipped  = array();
			$warnings = array();

			$mb_fields = get_post_meta( (int) $g->ID, 'fields', true );
			if ( ! is_array( $mb_fields ) ) {
				$mb_fields = json_decode( (string) $mb_fields, true );
			}
			$settings = get_post_meta( (int) $g->ID, 'settings', true );
			$settings = is_array( $settings ) ? $settings : array();

			$fields = $this->map_fields( is_array( $mb_fields ) ? $mb_fields : array(), $skipped, $warnings );

			$key = $this->clean_name( $g->post_name );
			if ( '' === $key ) {
				$key = 'mb_group_' . (int) $g->ID;
			}

			$out[] = array(
				'source_id' => (int) $g->ID,
				'warnings'  => $warnings,
				'skipped'   => $skipped,
				'data'      => array(
					'title'           => $g->post_title,
					'key'             => $key,
					'fields'          => $fields,
					'location_rules'  => $this->map_location( $settings, $warnings ),
					'position'        => isset( $settings['context'] ) && 'side' === $settings['context'] ? 'side' : 'normal',
					'style'           => isset( $settings['style'] ) ? $settings['style'] : 'default',
					'label_placement' => isset( $settings['label_placement'] ) ? $settings['label_placement'] : 'top',
					'active'          => 'publish' === $g->post_status,
					'description'     => isset( $settings['description'] ) ? $settings['description'] : '',
				),
			);
		}
		return $out;
	}

	/**
	 * Map Meta Box's nested field array onto Uichemy fields.
	 *
	 * @param array  $mb_fields Meta Box `fields` array (assoc, keyed by internal id).
	 * @param array  $skipped   Collected skips (by reference).
	 * @param array  $warnings  Collected warnings (by reference).
	 * @param string $prefix    Name prefix used when flattening a non-clone group.
	 * @return array
	 */
	private function map_fields( $mb_fields, &$skipped, &$warnings, $prefix = '' ) {
		$map    = self::type_map();
		$skips  = self::skip_reasons();
		$fields = array();

		foreach ( (array) $mb_fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';

			// `id` is the meta key; `name` is the label. Reading `name` here is the classic Meta Box
			// import bug — it produces a field whose key never matches any stored value.
			$name  = $this->clean_name( isset( $f['id'] ) ? $f['id'] : '' );
			$label = isset( $f['name'] ) && '' !== $f['name'] ? (string) $f['name'] : $name;

			if ( '' === $name ) {
				$skipped[] = array(
					'field'  => $label,
					'type'   => $type,
					'reason' => isset( $skips[ $type ] ) ? $skips[ $type ] : __( 'Field has no id, so it stores no value.', 'uichemy' ),
				);
				continue;
			}
			if ( isset( $skips[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $name,
					'type'   => $type,
					'reason' => $skips[ $type ],
				);
				continue;
			}

			// A cloneable group is Meta Box's repeater; a plain group flattens like ACF's.
			if ( 'group' === $type ) {
				$sub_source = isset( $f['fields'] ) ? $f['fields'] : array();
				if ( ! empty( $f['clone'] ) ) {
					$field = array(
						'name'   => $prefix . $name,
						'label'  => $label,
						'type'   => 'repeater',
						'fields' => $this->map_fields( $sub_source, $skipped, $warnings ),
					);
					if ( ! empty( $f['max_clone'] ) ) {
						$field['repeater_max_rows'] = (int) $f['max_clone'];
					}
					$fields[] = $field;
				} else {
					$fields = array_merge( $fields, $this->map_fields( $sub_source, $skipped, $warnings, $prefix . $name . '_' ) );
				}
				continue;
			}

			if ( ! isset( $map[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $name,
					'type'   => $type,
					'reason' => __( 'No UiChemy equivalent for this Meta Box type.', 'uichemy' ),
				);
				continue;
			}

			$ne_type = $map[ $type ];
			$field   = array(
				'name'         => $prefix . $name,
				'label'        => $label,
				'type'         => $ne_type,
				'instructions' => isset( $f['desc'] ) ? (string) $f['desc'] : '',
				'required'     => ! empty( $f['required'] ),
			);

			if ( in_array( $ne_type, array( 'select', 'checkbox', 'radio' ), true ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				$field['choices'] = $f['options'];
				if ( ! empty( $f['multiple'] ) ) {
					$field['multiple'] = true;
				}
			}
			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				if ( isset( $f[ $k ] ) && '' !== $f[ $k ] && in_array( $ne_type, array( 'number', 'range' ), true ) ) {
					$field[ $k ] = $f[ $k ];
				}
			}
			if ( isset( $f['std'] ) && '' !== $f['std'] && ! is_array( $f['std'] ) ) {
				$field['default_value'] = $f['std'];
			}
			if ( isset( $f['placeholder'] ) && '' !== $f['placeholder'] ) {
				$field['placeholder'] = $f['placeholder'];
			}
			if ( 'taxonomy' === $ne_type && ! empty( $f['taxonomy'] ) ) {
				$field['taxonomy_name'] = is_array( $f['taxonomy'] ) ? reset( $f['taxonomy'] ) : (string) $f['taxonomy'];
			}
			if ( 'post_object' === $ne_type && ! empty( $f['post_type'] ) ) {
				$field['post_object_post_types'] = (array) $f['post_type'];
			}

			// Meta Box marks multi-value with `multiple` or `clone` on a non-group field; that is
			// what makes its values land as several meta rows.
			if ( ! empty( $f['multiple'] ) || ! empty( $f['clone'] ) ) {
				$field['_mb_multi'] = true;
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Meta Box group settings => Uichemy location rules.
	 *
	 * @param array $settings Group settings.
	 * @param array $warnings Collected warnings (by reference).
	 * @return array
	 */
	private function map_location( $settings, &$warnings ) {
		$object_type = isset( $settings['object_type'] ) ? (string) $settings['object_type'] : 'post';
		$rules       = array();

		if ( 'term' === $object_type && ! empty( $settings['taxonomies'] ) ) {
			foreach ( (array) $settings['taxonomies'] as $tax ) {
				$rules[] = array(
					array(
						'param'    => 'taxonomy',
						'operator' => '==',
						'value'    => (string) $tax,
					),
				);
			}
		} elseif ( 'user' === $object_type ) {
			$rules[] = array(
				array(
					'param'    => 'user_form',
					'operator' => '==',
					'value'    => 'all',
				),
			);
		} elseif ( 'comment' === $object_type ) {
			$rules[] = array(
				array(
					'param'    => 'comment',
					'operator' => '==',
					'value'    => 'all',
				),
			);
		} else {
			foreach ( (array) ( isset( $settings['post_types'] ) ? $settings['post_types'] : array( 'post' ) ) as $pt ) {
				$pt = (string) $pt;
				if ( 'attachment' === $pt ) {
					$warnings[] = __( 'The "attachment" location was dropped: Meta Box adds it automatically and it is rarely intended.', 'uichemy' );
					continue;
				}
				$rules[] = array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => $pt,
					),
				);
			}
		}

		if ( ! $rules ) {
			$rules      = array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
				),
			);
			$warnings[] = __( 'No usable location rule was found; the group defaults to Posts.', 'uichemy' );
		}
		return $rules;
	}

	/**
	 * Read one field's stored value from this source's own storage.
	 *
	 * @param array  $field       Source field descriptor.
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return mixed|null Null when the source holds nothing.
	 */
	public function read_value( $field, $object_type, $object_id ) {
		$name = isset( $field['source_name'] ) ? $field['source_name'] : ( isset( $field['name'] ) ? $field['name'] : '' );
		if ( '' === $name ) {
			return null;
		}

		// Multi-value fields are several rows of the same key, not one serialized array.
		// A cloneable group (Meta Box's repeater) is stored the same way — ONE META ROW PER CLONE —
		// so without 'repeater' here the single-value read below saw only the first clone and
		// normalise() then flattened that row map into a bare value list ( {gt:'a'} => ['a'] ),
		// which the repeater sanitizer cannot match to sub-fields and stored as an empty array:
		// every clone row was lost.
		if ( ! empty( $field['_mb_multi'] ) || in_array( $field['type'], array( 'relationship', 'taxonomy', 'repeater' ), true ) ) {
			$rows = $this->raw_meta( $object_type, $object_id, $name, false );
			$rows = array_values(
				array_filter(
					(array) $rows,
					function ( $v ) {
						return '' !== $v && null !== $v;
					}
				)
			);
			if ( ! $rows ) {
				return null;
			}

			if ( 'repeater' === $field['type'] ) {
				$rows = array_map( array( $this, 'decode_stored' ), $rows );
				// A non-cloned group stores ONE row that already holds the whole list; unwrap it so
				// both storage shapes arrive at the sanitizer as a plain list of row maps.
				if ( 1 === count( $rows ) && is_array( $rows[0] ) && isset( $rows[0][0] ) && is_array( $rows[0][0] ) ) {
					$rows = array_values( $rows[0] );
				}
				// Only real row maps are repeater data; a flat scalar list is not.
				$rows = array_values( array_filter( $rows, 'is_array' ) );
				return $rows ? $rows : null;
			}

			return $this->normalise( $rows, $field['type'], ! empty( $field['multiple'] ) );
		}

		$raw = $this->raw_meta( $object_type, $object_id, $name, true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			return null;
		}
		return $this->normalise( $this->decode_stored( $raw ), isset( $field['type'] ) ? $field['type'] : 'text', ! empty( $field['multiple'] ) );
	}

	/**
	 * Meta Box stored value => Uichemy shape.
	 *
	 * @param mixed  $raw      Stored value.
	 * @param string $ne_type  Target type.
	 * @param bool   $multiple Whether the target field holds a list.
	 * @return mixed
	 */
	private function normalise( $raw, $ne_type, $multiple = false ) {
		// A single-value target cannot hold a list. Meta Box data that itself came from another
		// plugin (mb-acf-migration converting an ACF multi-select) keeps the array of values but
		// loses the `multiple` flag, so Uichemy registers the meta key as a single string and the
		// array is stringified into the literal "Array" — a PHP warning plus a corrupt row that
		// the run reports as failed. Keep the first real value instead, which is all a single
		// select/radio/text can ever show.
		if ( is_array( $raw ) && ! $multiple && in_array( $ne_type, array( 'select', 'radio', 'text', 'textarea', 'number', 'range', 'email', 'url', 'color', 'time', 'date', 'datetime', 'wysiwyg' ), true ) ) {
			foreach ( $raw as $one ) {
				if ( is_scalar( $one ) ) {
					return $one;
				}
			}
			return null;
		}

		switch ( $ne_type ) {
			case 'true_false':
				return ! empty( $raw ) ? 1 : 0;
			case 'relationship':
			case 'taxonomy':
				return array_values( array_map( 'intval', (array) $raw ) );
			case 'image':
			case 'file':
			case 'post_object':
			case 'user':
				if ( is_array( $raw ) ) {
					$first = reset( $raw );
					return is_numeric( $first ) ? (int) $first : $first;
				}
				return is_numeric( $raw ) ? (int) $raw : $raw;
			case 'repeater':
				return is_array( $raw ) ? array_values( $raw ) : null;
			default:
				if ( is_array( $raw ) ) {
					// A multi-row scalar (checkbox list) collapses to the array Uichemy expects.
					return array_values( $raw );
				}
				return $raw;
		}
	}

	/**
	 * Mark this source's definitions inactive after a successful import.
	 *
	 * @param array $source_ids Definition post IDs that were migrated.
	 * @return int Number disabled.
	 */
	public function disable_definitions( $source_ids ) {
		$n = 0;
		foreach ( (array) $source_ids as $id ) {
			$id   = (int) $id;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || ! in_array( $post->post_type, array( 'meta-box', 'mb-post-type', 'mb-taxonomy' ), true ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
			++$n;
		}
		return $n;
	}
}
