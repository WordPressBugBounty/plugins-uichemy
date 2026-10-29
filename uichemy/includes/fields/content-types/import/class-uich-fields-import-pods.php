<?php
/**
 * Pods import source.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_Pods
 */
class Uich_Fields_Import_Pods extends Uich_Fields_Import_Source {

	/**
	 * Non-pick Pods types => Uichemy types.
	 *
	 * @return array<string, string>
	 */
	public static function type_map() {
		return array(
			'text'      => 'text',
			'paragraph' => 'textarea',
			'wysiwyg'   => 'wysiwyg',
			'number'    => 'number',
			'currency'  => 'number',
			'email'     => 'email',
			'website'   => 'url',
			'phone'     => 'text',
			'date'      => 'date',
			'datetime'  => 'datetime',
			'time'      => 'time',
			'boolean'   => 'true_false',
			'color'     => 'color',
			'file'      => 'file',
			'avatar'    => 'image',
			'oembed'    => 'url',
			'code'      => 'textarea',
			'slug'      => 'text',
		);
	}

	/**
	 * Types intentionally not imported.
	 *
	 * @return array<string, string>
	 */
	public static function skip_reasons() {
		return array(
			'password' => __( 'Password fields store cleartext. UiChemy does not offer this type, and Pods stores the value unencrypted.', 'uichemy' ),
			'heading'  => __( 'Layout-only field, stores no value.', 'uichemy' ),
			'html'     => __( 'Layout-only field, stores no value.', 'uichemy' ),
		);
	}

	/**
	 * Machine slug for this source.
	 *
	 * @return string
	 */
	public function slug() {
		return 'pods';
	}

	/**
	 * Human label for the UI and reports.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Pods', 'uichemy' );
	}

	/**
	 * Whether this source has anything to import on this site.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		return (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' LIMIT 1" );
	}

	/**
	 * Post-type definitions shaped for Admin_Logic::save_cpt().
	 *
	 * @return array
	 */
	public function read_post_types() {
		$out = array();
		foreach ( $this->pods_of_type( 'post_type' ) as $pod ) {
			if ( $this->is_table_storage( $pod ) ) {
				continue; // reported by read_groups(); a CPT cannot be created for it either.
			}
			$key = $this->clean_name( $pod->post_name );
			if ( '' === $key ) {
				continue;
			}
			$m        = $this->pod_meta( (int) $pod->ID );
			$warnings = array();
			$public   = ! isset( $m['public'] ) || ! empty( $m['public'] );

			$data = array(
				'post_type_key'  => $key,
				'label'          => '' !== $pod->post_title ? $pod->post_title : $key,
				'singular_label' => isset( $m['label_singular'] ) ? $m['label_singular'] : $pod->post_title,
				'public'         => $public,
				'hierarchical'   => ! empty( $m['hierarchical'] ),
				'has_archive'    => ! empty( $m['has_archive'] ),
				'supports'       => $this->pod_supports( $m ),
				'description'    => isset( $m['description'] ) ? $m['description'] : '',
			);

			// Advanced: visibility. Pods derives several of these from `public` when unset, so mirror
			// that rather than assuming a hardcoded true.
			$data['show_ui']             = $this->pod_bool( $m, 'show_ui', $public );
			$data['show_in_nav_menus']   = $this->pod_bool( $m, 'show_in_nav_menus', $public );
			$data['publicly_queryable']  = $this->pod_bool( $m, 'publicly_queryable', $public );
			$data['exclude_from_search'] = $this->pod_bool( $m, 'exclude_from_search', ! $public );
			$data['can_export']          = $this->pod_bool( $m, 'can_export', true );
			$data['delete_with_user']    = $this->pod_bool( $m, 'delete_with_user', true );
			$show_in_menu                = $this->pod_bool( $m, 'show_in_menu', true );
			$data['show_in_menu']        = $show_in_menu;
			$data['show_in_admin_bar']   = $this->pod_bool( $m, 'show_in_admin_bar', $show_in_menu );
			if ( isset( $m['menu_position'] ) && '' !== $m['menu_position'] ) {
				$data['menu_position'] = (int) $m['menu_position'];
			}
			if ( ! empty( $m['menu_icon'] ) ) {
				$data['menu_icon'] = (string) $m['menu_icon'];
			}
			// Pods can point a CPT at another admin menu; this module's show_in_menu is boolean only.
			if ( $show_in_menu && ! empty( $m['menu_location_custom'] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: post type key, 2: parent menu slug. */
					__( '"%1$s": the source nested this type under the "%2$s" admin menu; UiChemy only offers show/hide, so it gets its own top-level menu.', 'uichemy' ),
					$key,
					(string) $m['menu_location_custom']
				);
			}

			if ( ! empty( $m['has_archive'] ) && ! empty( $m['has_archive_slug'] ) ) {
				$data['archive_slug'] = (string) $m['has_archive_slug'];
			}

			// Advanced: permalinks. Pods keeps the slug in its own key, not inside a rewrite array.
			$data = array_merge(
				$data,
				$this->map_wp_rewrite(
					$this->pod_bool( $m, 'rewrite', true )
						? array(
							'slug'       => isset( $m['rewrite_custom_slug'] ) ? $m['rewrite_custom_slug'] : '',
							'with_front' => $this->pod_bool( $m, 'rewrite_with_front', true ),
							'pages'      => $this->pod_bool( $m, 'rewrite_pages', true ),
							'feeds'      => $this->pod_bool( $m, 'rewrite_feeds', ! empty( $m['has_archive'] ) ),
						)
						: false,
					$key,
					'cpt'
				)
			);

			// Advanced: query var.
			$data['query_var'] = $this->pod_bool( $m, 'query_var', true );
			if ( $data['query_var'] && ! empty( $m['query_var_string'] ) && $m['query_var_string'] !== $key ) {
				$data['query_var_string'] = (string) $m['query_var_string'];
			}

			// Advanced: REST. Pods gates the whole block behind rest_enable, defaulting to OFF.
			$data['show_in_rest'] = $this->pod_bool( $m, 'rest_enable', false );
			if ( $data['show_in_rest'] ) {
				if ( ! empty( $m['rest_base'] ) && $m['rest_base'] !== $key ) {
					$data['rest_base'] = (string) $m['rest_base'];
				}
				if ( ! empty( $m['rest_namespace'] ) && 'wp/v2' !== $m['rest_namespace'] ) {
					$warnings[] = sprintf(
						/* translators: 1: post type key, 2: REST namespace. */
						__( '"%1$s": the REST namespace "%2$s" was not carried over — UiChemy always registers under wp/v2.', 'uichemy' ),
						$key,
						(string) $m['rest_namespace']
					);
				}
			}

			// Advanced: capabilities. Pods spells the custom type in its own key and stores
			// map_meta_cap under capability_type_extra.
			$cap_type = isset( $m['capability_type'] ) ? (string) $m['capability_type'] : 'post';
			if ( 'custom' === $cap_type ) {
				$cap_type = ! empty( $m['capability_type_custom'] ) ? (string) $m['capability_type_custom'] : $key;
			}
			$data['capability_type'] = sanitize_key( '' !== $cap_type ? $cap_type : 'post' );
			$data['map_meta_cap']    = $this->pod_bool( $m, 'capability_type_extra', true );

			// Pods can block creation outright via a `create_posts => false` capability. This module's
			// capability allowlist has no create_posts key, so this cannot be represented.
			if ( $this->pod_bool( $m, 'disable_create_posts', false ) ) {
				$warnings[] = sprintf(
					/* translators: %s: post type key. */
					__( '"%s": the source disabled creating new items; UiChemy has no equivalent, so creation stays enabled.', 'uichemy' ),
					$key
				);
			}

			$overrides = $this->map_labels( $this->pod_labels( $m ) );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'cpt' );

			$out[] = array(
				'source_id' => (int) $pod->ID,
				'warnings'  => $warnings,
				'data'      => $data,
			);
		}
		return $out;
	}

	/**
	 * Taxonomy definitions shaped for Admin_Logic::save_taxonomy().
	 *
	 * @return array
	 */
	public function read_taxonomies() {
		$out = array();
		foreach ( $this->pods_of_type( 'taxonomy' ) as $pod ) {
			if ( $this->is_table_storage( $pod ) ) {
				continue;
			}
			$key = $this->clean_name( $pod->post_name );
			if ( '' === $key ) {
				continue;
			}
			$m            = $this->pod_meta( (int) $pod->ID );
			$warnings     = array();
			$public       = ! isset( $m['public'] ) || ! empty( $m['public'] );
			$hierarchical = ! empty( $m['hierarchical'] );

			$data = array(
				'taxonomy_key'   => $key,
				'label'          => '' !== $pod->post_title ? $pod->post_title : $key,
				'singular_label' => isset( $m['label_singular'] ) ? $m['label_singular'] : $pod->post_title,
				'hierarchical'   => $hierarchical,
				'public'         => $public,
				// Pods attaches a taxonomy through built_in_post_types_{key} flags, one per post type.
				'object_type'    => $this->pod_object_types( $m ),
				'description'    => isset( $m['description'] ) ? $m['description'] : '',
			);

			// Advanced: visibility. Pods cascades these off show_ui, which itself falls back to public.
			$show_ui                   = $this->pod_bool( $m, 'show_ui', $public );
			$data['show_ui']           = $show_ui;
			$data['show_in_nav_menus'] = $this->pod_bool( $m, 'show_in_nav_menus', $public );
			$data['show_tag_cloud']    = $this->pod_bool( $m, 'show_tagcloud', $show_ui );
			$data['show_admin_column'] = $this->pod_bool( $m, 'show_admin_column', false );
			$data['sort']              = $this->pod_bool( $m, 'sort', false );

			// Advanced: permalinks.
			$data = array_merge(
				$data,
				$this->map_wp_rewrite(
					$this->pod_bool( $m, 'rewrite', true )
						? array(
							'slug'         => isset( $m['rewrite_custom_slug'] ) ? $m['rewrite_custom_slug'] : '',
							'with_front'   => $this->pod_bool( $m, 'rewrite_with_front', true ),
							'hierarchical' => $this->pod_bool( $m, 'rewrite_hierarchical', $hierarchical ),
						)
						: false,
					$key,
					'taxonomy'
				)
			);
			// Uichemy hardcodes with_front=false for taxonomies — there is no key to carry it into.
			if ( $this->pod_bool( $m, 'rewrite_with_front', true ) && self::permalink_has_front() ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": the source prepended the permalink front base to term URLs; UiChemy always omits it, so term URLs will differ.', 'uichemy' ),
					$key
				);
			}

			$data['query_var'] = $this->pod_bool( $m, 'query_var', true );
			if ( $data['query_var'] && ! empty( $m['query_var_string'] ) && $m['query_var_string'] !== $key ) {
				$data['query_var_string'] = (string) $m['query_var_string'];
			}

			$data['show_in_rest'] = $this->pod_bool( $m, 'rest_enable', false );
			if ( $data['show_in_rest'] ) {
				if ( ! empty( $m['rest_base'] ) && $m['rest_base'] !== $key ) {
					$data['rest_base'] = (string) $m['rest_base'];
				}
				if ( ! empty( $m['rest_namespace'] ) && 'wp/v2' !== $m['rest_namespace'] ) {
					$warnings[] = sprintf(
						/* translators: 1: taxonomy key, 2: REST namespace. */
						__( '"%1$s": the REST namespace "%2$s" was not carried over — UiChemy always registers under wp/v2.', 'uichemy' ),
						$key,
						(string) $m['rest_namespace']
					);
				}
			}

			// Advanced: capabilities. Pods builds the seven-cap array from one custom name, appending
			// _term/_terms; this module's allowlist keeps only the four plural term caps.
			$cap_type = isset( $m['capability_type'] ) ? (string) $m['capability_type'] : 'default';
			if ( 'custom' === $cap_type && ! empty( $m['capability_type_custom'] ) && 'default' !== $m['capability_type_custom'] ) {
				$base                 = sanitize_key( $m['capability_type_custom'] ) . '_terms';
				$data['capabilities'] = array(
					'manage_terms' => 'manage_' . $base,
					'edit_terms'   => 'edit_' . $base,
					'delete_terms' => 'delete_' . $base,
					'assign_terms' => 'assign_' . $base,
				);
			}

			// Advanced: default term. Pods stores the three parts flat and has no enable flag.
			if ( ! empty( $m['default_term_name'] ) ) {
				$data['default_term_enabled'] = true;
				$data['default_term']         = array(
					'name'        => (string) $m['default_term_name'],
					'slug'        => isset( $m['default_term_slug'] ) ? (string) $m['default_term_slug'] : '',
					'description' => isset( $m['default_term_description'] ) ? (string) $m['default_term_description'] : '',
				);
			}

			if ( isset( $m['show_in_quick_edit'] ) && ! $this->pod_bool( $m, 'show_in_quick_edit', $show_ui ) ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": "Show in Quick Edit" was off in the source but UiChemy has no such setting, so it stays on.', 'uichemy' ),
					$key
				);
			}
			if ( ! empty( $m['update_count_callback'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": a custom term-count callback was configured in the source and was not carried over.', 'uichemy' ),
					$key
				);
			}

			$overrides = $this->map_labels( $this->pod_labels( $m ) );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'taxonomy' );

			$out[] = array(
				'source_id' => (int) $pod->ID,
				'warnings'  => $warnings,
				'data'      => $data,
			);
		}
		return $out;
	}

	/**
	 * Read a Pods boolean option, honouring the fallback Pods itself uses when the key is absent.
	 *
	 * Pod meta arrives as strings, so '0' must read as false — a plain cast would make it true.
	 *
	 * @param array  $m        Pod meta.
	 * @param string $key      Option name.
	 * @param bool   $fallback Value when the option is not set at all.
	 * @return bool
	 */
	private function pod_bool( $m, $key, $fallback ) {
		if ( ! array_key_exists( $key, $m ) || null === $m[ $key ] || '' === $m[ $key ] ) {
			return (bool) $fallback;
		}
		// Test is_bool() FIRST: (string) false is '' — not '0' — so a string comparison alone would
		// read a stored false as true.
		if ( is_bool( $m[ $key ] ) ) {
			return $m[ $key ];
		}
		return ! in_array( (string) $m[ $key ], array( '0', 'false' ), true );
	}

	/**
	 * Post types a Pods taxonomy is attached to.
	 *
	 * Pods records the association as one built_in_post_types_{key} flag per post type rather than a
	 * list, so the whole meta array has to be scanned.
	 *
	 * @param array $m Pod meta.
	 * @return string[]
	 */
	private function pod_object_types( $m ) {
		$out = array();
		foreach ( $m as $k => $v ) {
			if ( 0 === strpos( $k, 'built_in_post_types_' ) && ! in_array( (string) $v, array( '0', '', 'false' ), true ) ) {
				$pt = substr( $k, 20 );
				if ( '' !== $pt ) {
					$out[] = $pt;
				}
			}
		}
		return $out ? array_values( array_unique( $out ) ) : array( 'post' );
	}

	/**
	 * Pods label options mapped onto WordPress label keys.
	 *
	 * Pods prefixes most with label_ but not menu_name / name_admin_bar, and spells one differently
	 * from WordPress (label_choose_from_the_most_used).
	 *
	 * @param array $m Pod meta.
	 * @return array
	 */
	private function pod_labels( $m ) {
		$renames = array( 'choose_from_the_most_used' => 'choose_from_most_used' );
		$out     = array();
		foreach ( $m as $k => $v ) {
			if ( ! is_scalar( $v ) || '' === trim( (string) $v ) ) {
				continue;
			}
			if ( in_array( $k, array( 'menu_name', 'name_admin_bar' ), true ) ) {
				$out[ $k ] = (string) $v;
				continue;
			}
			if ( 0 !== strpos( $k, 'label_' ) ) {
				continue;
			}
			$wp_key = substr( $k, 6 );
			// label_singular already became singular_label.
			if ( 'singular' === $wp_key || '' === $wp_key ) {
				continue;
			}
			if ( isset( $renames[ $wp_key ] ) ) {
				$wp_key = $renames[ $wp_key ];
			}
			$out[ $wp_key ] = (string) $v;
		}
		return $out;
	}

	/**
	 * Field groups shaped for the field-groups REST controller.
	 *
	 * @return array
	 */
	public function read_groups() {
		$out = array();
		foreach ( $this->all_pods() as $pod ) {
			$skipped  = array();
			$warnings = array();
			$pod_meta = $this->pod_meta( (int) $pod->ID );
			$pod_type = isset( $pod_meta['type'] ) ? (string) $pod_meta['type'] : 'post_type';

			// Refuse table storage loudly.
			if ( $this->is_table_storage( $pod ) ) {
				$out[] = array(
					'source_id' => (int) $pod->ID,
					'warnings'  => array(),
					'skipped'   => array(
						array(
							'field'  => $pod->post_name,
							'type'   => 'storage=table',
							'reason' => __( 'This Pod stores its content in its own database table, and those records are not WordPress posts. UiChemy has no equivalent, so neither its fields nor its content can be imported.', 'uichemy' ),
						),
					),
					'blocked'   => true,
					'data'      => array(
						'title'  => $pod->post_title,
						'key'    => $this->clean_name( $pod->post_name ),
						'fields' => array(),
					),
				);
				continue;
			}

			$fields = $this->map_fields( (int) $pod->ID, $skipped, $warnings );
			if ( ! $fields ) {
				continue;
			}

			$key = $this->clean_name( $pod->post_name );
			if ( '' === $key ) {
				$key = 'pods_group_' . (int) $pod->ID;
			}

			$out[] = array(
				'source_id' => (int) $pod->ID,
				'warnings'  => $warnings,
				'skipped'   => $skipped,
				'data'      => array(
					'title'          => '' !== $pod->post_title ? $pod->post_title : $key,
					'key'            => $key,
					'fields'         => $fields,
					'location_rules' => $this->location_for_pod( $pod_type, $pod->post_name ),
					'active'         => true,
					'description'    => isset( $pod_meta['description'] ) ? $pod_meta['description'] : '',
				),
			);
		}
		return $out;
	}

	/**
	 * `_pods_field` children of one Pod => Uichemy fields.
	 *
	 * @param int   $pod_id   Pod post ID.
	 * @param array $skipped  Collected skips (by reference).
	 * @param array $warnings Collected warnings (by reference).
	 * @return array
	 */
	private function map_fields( $pod_id, &$skipped, &$warnings ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_title FROM {$wpdb->posts}
			 WHERE post_type = '_pods_field' AND post_parent = %d AND post_status != 'trash'
			 ORDER BY menu_order ASC, ID ASC",
				$pod_id
			)
		);

		$map    = self::type_map();
		$skips  = self::skip_reasons();
		$fields = array();

		foreach ( $rows as $r ) {
			$m    = $this->pod_meta( (int) $r->ID );
			$type = isset( $m['type'] ) ? (string) $m['type'] : 'text';
			$name = $this->clean_name( $r->post_name );

			if ( '' === $name ) {
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

			$field = array(
				'name'         => $name,
				'label'        => '' !== $r->post_title ? $r->post_title : $name,
				'instructions' => isset( $m['description'] ) ? $m['description'] : '',
				'required'     => ! empty( $m['required'] ),
			);

			if ( 'pick' === $type ) {
				if ( ! $this->map_pick( $m, $field, $warnings, $name ) ) {
					$skipped[] = array(
						'field'  => $name,
						'type'   => 'pick/' . ( isset( $m['pick_object'] ) ? $m['pick_object'] : '?' ),
						'reason' => __( 'This Pods relationship target has no UiChemy equivalent.', 'uichemy' ),
					);
					continue;
				}
			} elseif ( isset( $map[ $type ] ) ) {
				$field['type'] = $map[ $type ];
				// A Pods code field keeps its markup only in a type that does not strip tags; textarea does.
				if ( 'code' === $type ) {
					$warnings[] = sprintf(
						/* translators: %s: field name */
						__( '"%s" is a code field. It becomes a textarea, which strips HTML tags from its value, so markup in existing values will be lost.', 'uichemy' ),
						$name
					);
				}
			} else {
				$skipped[] = array(
					'field'  => $name,
					'type'   => $type,
					'reason' => __( 'No UiChemy equivalent for this Pods type.', 'uichemy' ),
				);
				continue;
			}

			foreach ( array(
				'number_min'  => 'min',
				'number_max'  => 'max',
				'number_step' => 'step',
			) as $src => $dst ) {
				if ( isset( $m[ $src ] ) && '' !== $m[ $src ] ) {
					$field[ $dst ] = $m[ $src ];
				}
			}
			if ( isset( $m['default_value'] ) && '' !== $m['default_value'] ) {
				$field['default_value'] = $m['default_value'];
			}

			$field['_pods_type'] = $type;
			$fields[]            = $field;
		}

		return $fields;
	}

	/**
	 * Resolve a Pods `pick` field into a concrete Uichemy type.
	 *
	 * @param array  $m        Field meta.
	 * @param array  $field    Field being built (by reference).
	 * @param array  $warnings Collected warnings (by reference).
	 * @param string $name     Field name, for messages.
	 * @return bool Whether the pick could be mapped.
	 */
	private function map_pick( $m, &$field, &$warnings, $name ) {
		$object = isset( $m['pick_object'] ) ? (string) $m['pick_object'] : '';
		$multi  = isset( $m['pick_format_type'] ) && 'multi' === $m['pick_format_type'];

		if ( 'custom-simple' === $object ) {
			$field['type']    = $multi ? 'checkbox' : 'select';
			$field['choices'] = $this->parse_custom_choices( isset( $m['pick_custom'] ) ? $m['pick_custom'] : '' );
			if ( $multi ) {
				$field['multiple'] = true;
			}
			return true;
		}
		if ( 'post_type' === $object ) {
			$field['type'] = $multi ? 'relationship' : 'post_object';
			if ( ! empty( $m['pick_val'] ) ) {
				$key           = $field['type'] . '_post_types';
				$field[ $key ] = (array) $m['pick_val'];
			}
			return true;
		}
		if ( 'taxonomy' === $object ) {
			$field['type'] = 'taxonomy';
			if ( ! empty( $m['pick_val'] ) ) {
				$field['taxonomy_name'] = (string) $m['pick_val'];
			}
			return true;
		}
		if ( 'user' === $object ) {
			$field['type'] = 'user';
			return true;
		}
		if ( '' === $object ) {
			// A pick with no target behaves as a plain select.
			$field['type']    = $multi ? 'checkbox' : 'select';
			$field['choices'] = $this->parse_custom_choices( isset( $m['pick_custom'] ) ? $m['pick_custom'] : '' );
			$warnings[]       = sprintf(
				/* translators: %s: field name */
				__( '"%s" is a Pods relationship with no target set; it was imported as a choice field.', 'uichemy' ),
				$name
			);
			return true;
		}
		return false;
	}

	/**
	 * Pods stores custom choices as "value|Label" lines.
	 *
	 * @param string $raw pick_custom value.
	 * @return array<string, string>
	 */
	private function parse_custom_choices( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, '|' ) ) {
				list( $v, $l )     = explode( '|', $line, 2 );
				$out[ trim( $v ) ] = trim( $l );
			} else {
				$out[ $line ] = $line;
			}
		}
		return $out;
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
		$ne_type = isset( $field['type'] ) ? $field['type'] : 'text';

		// Relational and multi-choice values: prefer the `_pods_{name}` shadow row.
		if ( in_array( $ne_type, array( 'relationship', 'taxonomy', 'post_object', 'user', 'file', 'image', 'checkbox' ), true ) ) {
			$shadow = $this->raw_meta( $object_type, $object_id, '_pods_' . $name, true );
			$shadow = $this->decode_stored( $shadow );
			if ( is_array( $shadow ) && $shadow ) {
				return $this->normalise( array_values( $shadow ), $ne_type );
			}
			$rows = $this->raw_meta( $object_type, $object_id, $name, false );
			$rows = array_values(
				array_filter(
					(array) $rows,
					function ( $v ) {
						return '' !== $v && null !== $v;
					}
				)
			);
			return $rows ? $this->normalise( $rows, $ne_type ) : null;
		}

		$raw = $this->raw_meta( $object_type, $object_id, $name, true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			return null;
		}
		return $this->normalise( $this->decode_stored( $raw ), $ne_type );
	}

	/**
	 * Pods stored value => Uichemy shape.
	 *
	 * @param mixed  $raw     Stored value.
	 * @param string $ne_type Target type.
	 * @return mixed
	 */
	private function normalise( $raw, $ne_type ) {
		switch ( $ne_type ) {
			case 'true_false':
				return ! empty( $raw ) ? 1 : 0;
			case 'relationship':
			case 'taxonomy':
				return array_values( array_map( 'intval', (array) $raw ) );
			case 'post_object':
			case 'user':
			case 'image':
			case 'file':
				if ( is_array( $raw ) ) {
					$first = reset( $raw );
					return is_numeric( $first ) ? (int) $first : $first;
				}
				return is_numeric( $raw ) ? (int) $raw : $raw;
			case 'checkbox':
				return array_values( (array) $raw );
			default:
				return is_array( $raw ) ? array_values( $raw ) : $raw;
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
			if ( ! $post || '_pods_pod' !== $post->post_type ) {
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

	// ----------------------------------------------------------------- Internals
	// -----------------------------------------------------------------.

	/**
	 * All Pod definition posts.
	 *
	 * @return WP_Post[]
	 */
	private function all_pods() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_status != 'trash' ORDER BY ID ASC"
		);
	}

	/**
	 * Pods of one `type` meta value.
	 *
	 * @param string $type post_type | taxonomy | pod | user | comment | settings.
	 * @return WP_Post[]
	 */
	private function pods_of_type( $type ) {
		$out = array();
		foreach ( $this->all_pods() as $pod ) {
			$m = $this->pod_meta( (int) $pod->ID );
			if ( isset( $m['type'] ) && $type === $m['type'] ) {
				$out[] = $pod;
			}
		}
		return $out;
	}

	/**
	 * Whether a Pod uses its own table rather than meta.
	 *
	 * @param WP_Post $pod Pod definition post.
	 * @return bool
	 */
	private function is_table_storage( $pod ) {
		$m = $this->pod_meta( (int) $pod->ID );
		return ( isset( $m['storage'] ) && 'table' === $m['storage'] )
			|| ( isset( $m['type'] ) && 'pod' === $m['type'] );
	}

	/**
	 * Flat postmeta map for a definition post.
	 *
	 * @param int $post_id Definition post ID.
	 * @return array<string, mixed>
	 */
	private function pod_meta( $post_id ) {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $k => $v ) {
			$out[ $k ] = $this->decode_stored( is_array( $v ) ? reset( $v ) : $v );
		}
		return $out;
	}

	/**
	 * Pods `supports_*` flags => a Uichemy supports array.
	 *
	 * @param array $m Pod meta.
	 * @return string[]
	 */
	private function pod_supports( $m ) {
		$out = array();
		foreach ( $m as $k => $v ) {
			if ( 0 === strpos( $k, 'supports_' ) && ! empty( $v ) ) {
				$out[] = str_replace( '_', '-', substr( $k, 9 ) );
			}
		}
		return $out;
	}

	/**
	 * Where a Pod's fields should appear in Uichemy terms.
	 *
	 * @param string $pod_type Pod type meta.
	 * @param string $name     Pod name.
	 * @return array
	 */
	private function location_for_pod( $pod_type, $name ) {
		if ( 'taxonomy' === $pod_type ) {
			return array(
				array(
					array(
						'param'    => 'taxonomy',
						'operator' => '==',
						'value'    => $this->clean_name( $name ),
					),
				),
			);
		}
		if ( 'user' === $pod_type ) {
			return array(
				array(
					array(
						'param'    => 'user_form',
						'operator' => '==',
						'value'    => 'all',
					),
				),
			);
		}
		if ( 'comment' === $pod_type ) {
			return array(
				array(
					array(
						'param'    => 'comment',
						'operator' => '==',
						'value'    => 'all',
					),
				),
			);
		}
		return array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $this->clean_name( $name ),
				),
			),
		);
	}
}
