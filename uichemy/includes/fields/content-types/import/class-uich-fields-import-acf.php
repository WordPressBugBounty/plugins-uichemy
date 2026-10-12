<?php
/**
 * ACF (and Secure Custom Fields) import source.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_ACF
 */
class Uich_Fields_Import_ACF extends Uich_Fields_Import_Source {

	/**
	 * ACF field type => Uichemy type. Types absent here are reported as skipped, with a reason.
	 *
	 * @return array<string, string>
	 */
	public static function type_map() {
		return array(
			'text'             => 'text',
			'textarea'         => 'textarea',
			'number'           => 'number',
			'range'            => 'range',
			'email'            => 'email',
			'url'              => 'url',
			'wysiwyg'          => 'wysiwyg',
			'select'           => 'select',
			'checkbox'         => 'checkbox',
			'radio'            => 'radio',
			'button_group'     => 'radio',
			'true_false'       => 'true_false',
			'image'            => 'image',
			'file'             => 'file',
			'link'             => 'link',
			'post_object'      => 'post_object',
			'page_link'        => 'page_link',
			'relationship'     => 'relationship',
			'taxonomy'         => 'taxonomy',
			'user'             => 'user',
			'date_picker'      => 'date',
			'time_picker'      => 'time',
			'date_time_picker' => 'datetime',
			'color_picker'     => 'color',
			'repeater'         => 'repeater',
			'oembed'           => 'url',
			'google_map'       => 'text',
			// Secure Custom Fields only.
			'icon_picker'      => 'text',
			'nav_menu'         => 'number',
		);
	}

	/**
	 * Types that are intentionally not imported, with the reason shown in the scan report.
	 *
	 * @return array<string, string>
	 */
	public static function skip_reasons() {
		return array(
			'password'         => 'Password fields store cleartext. UiChemy does not offer this type; importing would copy the secret into a second place.',
			'flexible_content' => 'No UiChemy equivalent. Needs a repeater-per-layout split — planned, not in this importer.',
			'gallery'          => 'No UiChemy gallery type yet. Interim: a repeater of image fields.',
			'clone'            => 'Clone fields are resolved at render time in ACF; there is nothing stored to copy.',
			'tab'              => 'Layout-only field, stores no value.',
			'accordion'        => 'Layout-only field, stores no value.',
			'message'          => 'Layout-only field, stores no value.',
		);
	}

	/**
	 * Machine slug for this source.
	 *
	 * @return string
	 */
	public function slug() {
		return 'acf';
	}

	/**
	 * Human label for the UI and reports.
	 *
	 * @return string
	 */
	public function label() {
		$acf = false;
		$scf = false;
		foreach ( self::active_plugin_files() as $file ) {
			if ( 0 === strpos( $file, 'secure-custom-fields/' ) ) {
				$scf = true;
			} elseif ( 0 === strpos( $file, 'advanced-custom-fields' ) ) {
				// Covers advanced-custom-fields/ and advanced-custom-fields-pro/.
				$acf = true;
			}
		}
		if ( $scf && $acf ) {
			return __( 'Advanced Custom Fields / Secure Custom Fields', 'uichemy' );
		}
		if ( $scf ) {
			return __( 'Secure Custom Fields', 'uichemy' );
		}
		if ( $acf ) {
			return __( 'Advanced Custom Fields', 'uichemy' );
		}
		// Definitions exist but neither plugin is active — someone deactivated it and the rows
		// remain. Still importable, so name both rather than guessing wrong.
		return __( 'Advanced Custom Fields / Secure Custom Fields (inactive)', 'uichemy' );
	}

	/**
	 * Plugin files WordPress currently has active, including network-wide ones.
	 *
	 * @return string[]
	 */
	private static function active_plugin_files() {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_map( 'strval', $plugins );
	}

	/**
	 * Whether this source has anything to import on this site.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		return (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'acf-field-group' LIMIT 1" );
	}

	// ----------------------------------------------------------------- Definitions
	// -----------------------------------------------------------------.

	/**
	 * Post-type definitions shaped for Admin_Logic::save_cpt().
	 *
	 * Carries the advanced settings too, not just the basics: save_cpt() REPLACES the stored config, so a
	 * key omitted here is written as a Uichemy default rather than left alone (see map_advanced_flag()).
	 *
	 * @return array
	 */
	public function read_post_types() {
		$out = array();
		foreach ( $this->definition_posts( 'acf-post-type' ) as $p ) {
			$cfg = $this->decode_content( $p->post_content );
			$key = $this->clean_name( isset( $cfg['post_type'] ) ? $cfg['post_type'] : $p->post_name );
			if ( '' === $key ) {
				continue;
			}
			$warnings = array();
			$labels   = isset( $cfg['labels'] ) && is_array( $cfg['labels'] ) ? $cfg['labels'] : array();
			$rewrite  = isset( $cfg['rewrite'] ) && is_array( $cfg['rewrite'] ) ? $cfg['rewrite'] : array();

			$data = array(
				'post_type_key'  => $key,
				'label'          => '' !== $p->post_title ? $p->post_title : $key,
				'singular_label' => isset( $labels['singular_name'] ) ? $labels['singular_name'] : $p->post_title,
				'public'         => ! empty( $cfg['public'] ),
				'show_ui'        => ! isset( $cfg['show_ui'] ) || ! empty( $cfg['show_ui'] ),
				'hierarchical'   => ! empty( $cfg['hierarchical'] ),
				'has_archive'    => ! empty( $cfg['has_archive'] ),
				'supports'       => isset( $cfg['supports'] ) ? (array) $cfg['supports'] : array(),
				'taxonomies'     => isset( $cfg['taxonomies'] ) ? (array) $cfg['taxonomies'] : array(),
				'menu_icon'      => isset( $cfg['menu_icon'] ) ? $cfg['menu_icon'] : '',
				'description'    => isset( $cfg['description'] ) ? $cfg['description'] : '',
				'active'         => ! isset( $cfg['active'] ) || ! empty( $cfg['active'] ),
			);

			// Advanced: visibility.
			$data['show_in_menu']        = ! isset( $cfg['show_in_menu'] ) || ! empty( $cfg['show_in_menu'] );
			$data['show_in_admin_bar']   = ! isset( $cfg['show_in_admin_bar'] ) || ! empty( $cfg['show_in_admin_bar'] );
			$data['show_in_nav_menus']   = ! isset( $cfg['show_in_nav_menus'] ) || ! empty( $cfg['show_in_nav_menus'] );
			$data['exclude_from_search'] = ! empty( $cfg['exclude_from_search'] );
			$data['publicly_queryable']  = ! isset( $cfg['publicly_queryable'] ) || ! empty( $cfg['publicly_queryable'] );
			$data['can_export']          = ! isset( $cfg['can_export'] ) || ! empty( $cfg['can_export'] );
			$data['delete_with_user']    = ! empty( $cfg['delete_with_user'] );
			if ( isset( $cfg['menu_position'] ) && '' !== $cfg['menu_position'] && null !== $cfg['menu_position'] ) {
				$data['menu_position'] = (int) $cfg['menu_position'];
			}

			// Advanced: archive slug is a separate Uichemy key; ACF nests it beside has_archive.
			if ( ! empty( $cfg['has_archive'] ) && ! empty( $cfg['has_archive_slug'] ) ) {
				$data['archive_slug'] = (string) $cfg['has_archive_slug'];
			}

			// Advanced: permalinks. ACF encodes the on/off/custom choice in permalink_rewrite, not in `slug`.
			$data = array_merge( $data, $this->map_rewrite( $rewrite, $key, 'post_type_key', $warnings ) );

			// Advanced: query var. ACF uses 'post_type_key' | 'custom_query_var' | 'none'.
			$data = array_merge( $data, $this->map_query_var( $cfg, $key, 'post_type_key' ) );

			// Advanced: REST.
			$data['show_in_rest'] = ! isset( $cfg['show_in_rest'] ) || ! empty( $cfg['show_in_rest'] );
			if ( ! empty( $cfg['rest_base'] ) ) {
				$data['rest_base'] = (string) $cfg['rest_base'];
			}
			if ( ! empty( $cfg['rest_controller_class'] ) && 'WP_REST_Posts_Controller' !== $cfg['rest_controller_class'] ) {
				$data['rest_controller_class'] = (string) $cfg['rest_controller_class'];
			}
			if ( ! empty( $cfg['rest_namespace'] ) && 'wp/v2' !== $cfg['rest_namespace'] ) {
				$warnings[] = sprintf(
					/* translators: 1: post type key, 2: REST namespace. */
					__( '"%1$s": the REST namespace "%2$s" was not carried over — UiChemy always registers under wp/v2.', 'uichemy' ),
					$key,
					(string) $cfg['rest_namespace']
				);
			}

			// Advanced: capabilities. Uichemy stores capability_type as ONE key; ACF can name the plural
			// separately, and WP only derives a plural by appending "s".
			$data['map_meta_cap'] = true;
			if ( ! empty( $cfg['rename_capabilities'] ) ) {
				$singular = isset( $cfg['singular_capability_name'] ) ? sanitize_key( $cfg['singular_capability_name'] ) : '';
				$plural   = isset( $cfg['plural_capability_name'] ) ? sanitize_key( $cfg['plural_capability_name'] ) : '';
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
			}

			// Advanced: label overrides. name/singular_name already became label/singular_label.
			$overrides = $this->map_labels( $labels, array( 'name', 'singular_name' ) );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'cpt', ! empty( $cfg['advanced_configuration'] ) );

			$out[] = array(
				'source_id' => (int) $p->ID,
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
		foreach ( $this->definition_posts( 'acf-taxonomy' ) as $p ) {
			$cfg = $this->decode_content( $p->post_content );
			$key = $this->clean_name( isset( $cfg['taxonomy'] ) ? $cfg['taxonomy'] : $p->post_name );
			if ( '' === $key ) {
				continue;
			}
			$warnings = array();
			$labels   = isset( $cfg['labels'] ) && is_array( $cfg['labels'] ) ? $cfg['labels'] : array();
			$rewrite  = isset( $cfg['rewrite'] ) && is_array( $cfg['rewrite'] ) ? $cfg['rewrite'] : array();

			$data = array(
				'taxonomy_key'   => $key,
				'label'          => '' !== $p->post_title ? $p->post_title : $key,
				'singular_label' => isset( $labels['singular_name'] ) ? $labels['singular_name'] : $p->post_title,
				'hierarchical'   => ! empty( $cfg['hierarchical'] ),
				'public'         => ! isset( $cfg['public'] ) || ! empty( $cfg['public'] ),
				'object_type'    => isset( $cfg['object_type'] ) ? (array) $cfg['object_type'] : array( 'post' ),
				'description'    => isset( $cfg['description'] ) ? $cfg['description'] : '',
				'active'         => ! isset( $cfg['active'] ) || ! empty( $cfg['active'] ),
			);

			// Advanced: visibility. Note ACF spells it show_tagcloud; Uichemy uses show_tag_cloud.
			$data['show_ui']            = ! isset( $cfg['show_ui'] ) || ! empty( $cfg['show_ui'] );
			$data['show_admin_column']  = ! empty( $cfg['show_admin_column'] );
			$data['show_tag_cloud']     = ! isset( $cfg['show_tagcloud'] ) || ! empty( $cfg['show_tagcloud'] );
			$data['show_in_nav_menus']  = ! isset( $cfg['show_in_nav_menus'] ) || ! empty( $cfg['show_in_nav_menus'] );

			// Advanced: permalinks and query var.
			$data = array_merge( $data, $this->map_rewrite( $rewrite, $key, 'taxonomy_key', $warnings ) );
			$data = array_merge( $data, $this->map_query_var( $cfg, $key, 'taxonomy_key' ) );

			// Advanced: REST.
			$data['show_in_rest'] = ! isset( $cfg['show_in_rest'] ) || ! empty( $cfg['show_in_rest'] );
			if ( ! empty( $cfg['rest_base'] ) ) {
				$data['rest_base'] = (string) $cfg['rest_base'];
			}
			if ( ! empty( $cfg['rest_controller_class'] ) && 'WP_REST_Terms_Controller' !== $cfg['rest_controller_class'] ) {
				$data['rest_controller_class'] = (string) $cfg['rest_controller_class'];
			}
			if ( ! empty( $cfg['rest_namespace'] ) && 'wp/v2' !== $cfg['rest_namespace'] ) {
				$warnings[] = sprintf(
					/* translators: 1: taxonomy key, 2: REST namespace. */
					__( '"%1$s": the REST namespace "%2$s" was not carried over — UiChemy always registers under wp/v2.', 'uichemy' ),
					$key,
					(string) $cfg['rest_namespace']
				);
			}

			// Advanced: sort. Only forwarded when ACF actually set it, so an unset value keeps
			// WordPress's own behaviour (no term_order maintenance) instead of being pinned to false.
			if ( isset( $cfg['sort'] ) && null !== $cfg['sort'] ) {
				$data['sort'] = ! empty( $cfg['sort'] );
			}

			// Advanced: capabilities. ACF's four keys are exactly this module's allowlist.
			if ( ! empty( $cfg['capabilities'] ) && is_array( $cfg['capabilities'] ) ) {
				$data['capabilities'] = $cfg['capabilities'];
			}

			// Advanced: default term, nested one level deeper in ACF than in Uichemy.
			$dt = isset( $cfg['default_term'] ) && is_array( $cfg['default_term'] ) ? $cfg['default_term'] : array();
			if ( ! empty( $dt['default_term_enabled'] ) && ! empty( $dt['default_term_name'] ) ) {
				$data['default_term_enabled'] = true;
				$data['default_term']         = array(
					'name'        => (string) $dt['default_term_name'],
					'slug'        => isset( $dt['default_term_slug'] ) ? (string) $dt['default_term_slug'] : '',
					'description' => isset( $dt['default_term_description'] ) ? (string) $dt['default_term_description'] : '',
				);
			}

			$overrides = $this->map_labels( $labels, array( 'name', 'singular_name' ) );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			// Uichemy has no equivalent for these, so say so rather than let them vanish.
			if ( isset( $cfg['show_in_quick_edit'] ) && empty( $cfg['show_in_quick_edit'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": "Show in Quick Edit" was off in the source but UiChemy has no such setting, so it stays on.', 'uichemy' ),
					$key
				);
			}
			if ( ! empty( $cfg['meta_box_cb'] ) || ( isset( $cfg['meta_box'] ) && 'default' !== $cfg['meta_box'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": a custom term meta box was configured in the source; UiChemy renders its own and the callback was not carried over.', 'uichemy' ),
					$key
				);
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'taxonomy', ! empty( $cfg['advanced_configuration'] ) );

			$out[] = array(
				'source_id' => (int) $p->ID,
				'warnings'  => $warnings,
				'data'      => $data,
			);
		}
		return $out;
	}

	/**
	 * ACF permalink settings mapped to this module's flat rewrite_* keys.
	 *
	 * ACF puts the on/off/custom choice in `permalink_rewrite`, so `slug` alone cannot be trusted: it is
	 * only meaningful under 'custom_permalink', and 'no_permalink' means rewriting is off entirely.
	 *
	 * @param array  $rewrite   ACF rewrite sub-array.
	 * @param string $key       Post type / taxonomy key.
	 * @param string $key_mode  'post_type_key' or 'taxonomy_key' — the "use the key" choice for this type.
	 * @param array  $warnings  Collected warnings, appended to by reference.
	 * @return array
	 */
	private function map_rewrite( $rewrite, $key, $key_mode, &$warnings ) {
		$is_tax = ( 'taxonomy_key' === $key_mode );
		$mode   = isset( $rewrite['permalink_rewrite'] ) ? (string) $rewrite['permalink_rewrite'] : $key_mode;
		$out    = array();

		$out[ $is_tax ? 'rewrite' : 'rewrite_enabled' ] = ( 'no_permalink' !== $mode );

		if ( 'custom_permalink' === $mode && ! empty( $rewrite['slug'] ) ) {
			$out['rewrite_slug'] = (string) $rewrite['slug'];
		}

		// with_front only changes a URL on a site whose permalink structure has a front base to prepend;
		// without one it is a no-op, so carrying it would flip Advanced Settings on for nothing.
		$has_front = self::permalink_has_front();

		if ( $is_tax ) {
			$out['rewrite_hierarchical'] = ! empty( $rewrite['rewrite_hierarchical'] );
			// Uichemy hardcodes with_front=false for taxonomies — there is no key to carry it into.
			if ( ! empty( $rewrite['with_front'] ) && $has_front ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": the source prepended the permalink front base to term URLs; UiChemy always omits it, so term URLs will differ.', 'uichemy' ),
					$key
				);
			}
			return $out;
		}

		if ( isset( $rewrite['with_front'] ) && ( $has_front || empty( $rewrite['with_front'] ) ) ) {
			$out['rewrite_with_front'] = ! empty( $rewrite['with_front'] );
		}
		if ( isset( $rewrite['pages'] ) ) {
			$out['rewrite_pages'] = ! empty( $rewrite['pages'] );
		}
		if ( isset( $rewrite['feeds'] ) ) {
			$out['rewrite_feeds'] = ! empty( $rewrite['feeds'] );
		}
		return $out;
	}


	/**
	 * ACF query-var settings mapped to this module's query_var / query_var_string pair.
	 *
	 * @param array  $cfg      Decoded ACF definition.
	 * @param string $key      Post type / taxonomy key.
	 * @param string $key_mode 'post_type_key' or 'taxonomy_key'.
	 * @return array
	 */
	private function map_query_var( $cfg, $key, $key_mode ) {
		$mode = isset( $cfg['query_var'] ) ? (string) $cfg['query_var'] : $key_mode;
		$out  = array( 'query_var' => ( 'none' !== $mode ) );
		if ( 'custom_query_var' === $mode && ! empty( $cfg['query_var_name'] ) ) {
			$out['query_var_string'] = (string) $cfg['query_var_name'];
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
		foreach ( $this->definition_posts( 'acf-field-group' ) as $g ) {
			$cfg      = $this->decode_content( $g->post_content );
			$skipped  = array();
			$warnings = array();

			// Build the key->name map FIRST: ACF conditional logic references fields by KEY, while Uichemy references
			// them by NAME, so the whole tree must be known before any rule is translated.
			$key_to_name = array();
			$this->collect_key_names( (int) $g->ID, $key_to_name );

			$fields = $this->read_fields_for_parent( (int) $g->ID, $key_to_name, $skipped, $warnings );

			$key = $this->clean_name( $g->post_name );
			if ( '' === $key ) {
				$key = 'acf_group_' . (int) $g->ID;
			}

			$out[] = array(
				'source_id' => (int) $g->ID,
				'warnings'  => $warnings,
				'skipped'   => $skipped,
				'data'      => array(
					'title'           => $g->post_title,
					'key'             => $key,
					'fields'          => $fields,
					'location_rules'  => $this->map_location( isset( $cfg['location'] ) ? $cfg['location'] : array(), $warnings ),
					'position'        => isset( $cfg['position'] ) ? $cfg['position'] : 'normal',
					'style'           => isset( $cfg['style'] ) ? $cfg['style'] : 'default',
					'label_placement' => isset( $cfg['label_placement'] ) ? $cfg['label_placement'] : 'top',
					'active'          => 'publish' === $g->post_status,
					'description'     => isset( $cfg['description'] ) ? $cfg['description'] : '',
				),
			);
		}

		// Options pages are an SCF / ACF Pro feature with no Uichemy counterpart: their values live in options_{name}
		// rows rather than on an object, so no location rule can express them.
		foreach ( $this->definition_posts( 'acf-ui-options-page' ) as $p ) {
			$out[] = array(
				'source_id' => (int) $p->ID,
				'warnings'  => array(),
				'blocked'   => true,
				'skipped'   => array(
					array(
						'field'  => $p->post_title,
						'type'   => 'options_page',
						'reason' => __( 'Options pages store their values in site options, not on posts, terms or users. UiChemy has no options location yet, so this page and its values cannot be imported.', 'uichemy' ),
					),
				),
				'data'      => array(
					'title'  => $p->post_title,
					'key'    => $this->clean_name( $p->post_name ),
					'fields' => array(),
				),
			);
		}

		return $out;
	}

	/**
	 * Fields under one parent post, recursively. Sub-fields are child posts in ACF.
	 *
	 * @param int    $parent_id   Group or parent-field post ID.
	 * @param array  $key_to_name key => name map for conditional logic.
	 * @param array  $skipped     Collected skips (by reference).
	 * @param array  $warnings    Collected warnings (by reference).
	 * @param string $prefix     Name prefix used when flattening an ACF `group` field.
	 * @return array
	 */
	private function read_fields_for_parent( $parent_id, $key_to_name, &$skipped, &$warnings, $prefix = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_excerpt, post_title, post_content, post_name FROM {$wpdb->posts}
			 WHERE post_type = 'acf-field' AND post_parent = %d AND post_status != 'trash'
			 ORDER BY menu_order ASC, ID ASC",
				$parent_id
			)
		);

		$map    = self::type_map();
		$skips  = self::skip_reasons();
		$fields = array();

		foreach ( $rows as $r ) {
			$cfg  = $this->decode_content( $r->post_content );
			$type = isset( $cfg['type'] ) ? (string) $cfg['type'] : 'text';
			$name = $this->clean_name( $r->post_excerpt );

			// Layout-only fields carry no name and store nothing.
			if ( '' === $name ) {
				$skipped[] = array(
					'field'  => $r->post_title,
					'type'   => $type,
					'reason' => isset( $skips[ $type ] ) ? $skips[ $type ] : 'Field has no name, so it stores no value.',
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

			// An ACF `group` flattens into prefixed sibling fields — which is exactly how ACF itself
			// stores it (`a_group_g_text`), so this is lossless rather than a degrade.
			if ( 'group' === $type ) {
				$sub = $this->read_fields_for_parent( (int) $r->ID, $key_to_name, $skipped, $warnings, $prefix . $name . '_' );
				if ( ! $sub && ! empty( $cfg['sub_fields'] ) && is_array( $cfg['sub_fields'] ) ) {
					// Sub-fields are not always child posts: SCF's import seam (and ACF local-JSON
					// registrations) can keep them INLINE in the parent's config. Verified live on
					// SCF 6.9.5 — without this fallback the whole group vanished from the plan.
					$sub = $this->read_inline_fields( $cfg['sub_fields'], $key_to_name, $skipped, $warnings, $prefix . $name . '_' );
				}
				if ( ! $sub ) {
					// Never let a container disappear silently — the dry run must account for it.
					$skipped[] = array(
						'field'  => $prefix . $name,
						'type'   => 'group',
						'reason' => 'Group has no readable sub-fields (neither child posts nor inline sub_fields).',
					);
				}
				$fields = array_merge( $fields, $sub );
				continue;
			}

			if ( ! isset( $map[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $name,
					'type'   => $type,
					'reason' => 'No UiChemy equivalent for this ACF type.',
				);
				continue;
			}

			$target_type = $map[ $type ];
			$field   = array(
				'name'         => $prefix . $name,
				'label'        => $r->post_title,
				'type'         => $target_type,
				'instructions' => isset( $cfg['instructions'] ) ? $cfg['instructions'] : '',
				'required'     => ! empty( $cfg['required'] ),
			);

			$this->copy_type_settings( $cfg, $type, $target_type, $field, $warnings );

			if ( ! empty( $cfg['conditional_logic'] ) && is_array( $cfg['conditional_logic'] ) ) {
				$field['conditional_logic_enabled'] = true;
				$field['conditional_logic']         = $this->map_conditional( $cfg['conditional_logic'], $key_to_name );
			}

			if ( 'repeater' === $target_type ) {
				// Uichemy nests sub-fields under `fields` (never `sub_fields`).
				$field['fields'] = $this->read_fields_for_parent( (int) $r->ID, $key_to_name, $skipped, $warnings );
				if ( ! $field['fields'] && ! empty( $cfg['sub_fields'] ) && is_array( $cfg['sub_fields'] ) ) {
					// Same inline-storage fallback as the group branch above.
					$field['fields'] = $this->read_inline_fields( $cfg['sub_fields'], $key_to_name, $skipped, $warnings );
				}
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Map fields whose definitions live INLINE in a parent's config (SCF import seam and ACF
	 * local-JSON both produce this shape) instead of as acf-field child posts.
	 *
	 * Mirrors the per-row mapping in read_fields_for_parent(); the two intentionally share the
	 * type map, skip list and warning wording so a field converts identically regardless of
	 * which storage shape it arrived in.
	 *
	 * @param array  $subs        Inline sub-field config arrays.
	 * @param array  $key_to_name key => name map for conditional logic.
	 * @param array  $skipped     Collected skips (by reference).
	 * @param array  $warnings    Collected warnings (by reference).
	 * @param string $prefix      Name prefix used when flattening an ACF `group` field.
	 * @return array
	 */
	private function read_inline_fields( $subs, $key_to_name, &$skipped, &$warnings, $prefix = '' ) {
		$map    = self::type_map();
		$skips  = self::skip_reasons();
		$fields = array();

		// Conditional logic among inline siblings references their keys, which the post-based
		// key map never saw — extend it with the inline keys first.
		foreach ( $subs as $cfg ) {
			if ( is_array( $cfg ) && ! empty( $cfg['key'] ) && ! empty( $cfg['name'] ) ) {
				$key_to_name[ (string) $cfg['key'] ] = $prefix . $this->clean_name( $cfg['name'] );
			}
		}

		foreach ( $subs as $cfg ) {
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$type  = isset( $cfg['type'] ) ? (string) $cfg['type'] : 'text';
			$name  = $this->clean_name( isset( $cfg['name'] ) ? $cfg['name'] : '' );
			$label = isset( $cfg['label'] ) && '' !== $cfg['label'] ? (string) $cfg['label'] : $name;

			if ( '' === $name ) {
				$skipped[] = array(
					'field'  => $label,
					'type'   => $type,
					'reason' => isset( $skips[ $type ] ) ? $skips[ $type ] : 'Field has no name, so it stores no value.',
				);
				continue;
			}

			if ( isset( $skips[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $prefix . $name,
					'type'   => $type,
					'reason' => $skips[ $type ],
				);
				continue;
			}

			if ( 'group' === $type ) {
				$sub = ! empty( $cfg['sub_fields'] ) && is_array( $cfg['sub_fields'] )
					? $this->read_inline_fields( $cfg['sub_fields'], $key_to_name, $skipped, $warnings, $prefix . $name . '_' )
					: array();
				if ( ! $sub ) {
					$skipped[] = array(
						'field'  => $prefix . $name,
						'type'   => 'group',
						'reason' => 'Group has no readable sub-fields (neither child posts nor inline sub_fields).',
					);
				}
				$fields = array_merge( $fields, $sub );
				continue;
			}

			if ( ! isset( $map[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $prefix . $name,
					'type'   => $type,
					'reason' => 'No UiChemy equivalent for this ACF type.',
				);
				continue;
			}

			$target_type = $map[ $type ];
			$field   = array(
				'name'         => $prefix . $name,
				'label'        => $label,
				'type'         => $target_type,
				'instructions' => isset( $cfg['instructions'] ) ? $cfg['instructions'] : '',
				'required'     => ! empty( $cfg['required'] ),
			);

			$this->copy_type_settings( $cfg, $type, $target_type, $field, $warnings );

			if ( ! empty( $cfg['conditional_logic'] ) && is_array( $cfg['conditional_logic'] ) ) {
				$field['conditional_logic_enabled'] = true;
				$field['conditional_logic']         = $this->map_conditional( $cfg['conditional_logic'], $key_to_name );
			}

			if ( 'repeater' === $target_type ) {
				$field['fields'] = ! empty( $cfg['sub_fields'] ) && is_array( $cfg['sub_fields'] )
					? $this->read_inline_fields( $cfg['sub_fields'], $key_to_name, $skipped, $warnings )
					: array();
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Per-type settings that carry over (choices, min/max, relational filters).
	 *
	 * @param array  $cfg     ACF field config.
	 * @param string $type    ACF type.
	 * @param string $target_type Uichemy type.
	 * @param array  $field   Field being built (by reference).
	 */
	private function copy_type_settings( $cfg, $type, $target_type, &$field, &$warnings = array() ) {
		$label = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( 'textarea' === $target_type && ! empty( $cfg['rows'] ) ) {
			$field['rows'] = (int) $cfg['rows'];
		}
		// ACF's return_format is a PHP date format for the pickers; UiChemy keeps one per type.
		$date_keys = array(
			'date_picker'      => 'date_return_format',
			'date_time_picker' => 'datetime_return_format',
			'time_picker'      => 'time_return_format',
		);
		if ( isset( $date_keys[ $type ] ) && ! empty( $cfg['return_format'] ) && is_string( $cfg['return_format'] ) ) {
			$field[ $date_keys[ $type ] ] = $cfg['return_format'];
		}
		if ( in_array( $target_type, array( 'image', 'file' ), true ) && ! empty( $cfg['return_format'] ) && in_array( $cfg['return_format'], array( 'array', 'url', 'id' ), true ) ) {
			$field['return_format'] = $cfg['return_format'];
		}
		if ( 'taxonomy' === $target_type ) {
			if ( isset( $cfg['save_terms'] ) ) {
				$field['taxonomy_save_terms'] = ! empty( $cfg['save_terms'] );
			}
			if ( isset( $cfg['load_terms'] ) ) {
				$field['taxonomy_load_terms'] = ! empty( $cfg['load_terms'] );
			}
		}
		// Settings with no UiChemy counterpart: say so instead of dropping them silently.
		if ( 'text' === $target_type && ! empty( $cfg['maxlength'] ) ) {
			$warnings[] = sprintf(
				/* translators: %s: field name */
				__( '"%s": the maximum length was not carried over; UiChemy text fields have no length limit.', 'uichemy' ),
				$label
			);
		}
		if ( in_array( $target_type, array( 'post_object', 'user', 'relationship' ), true ) && ! empty( $cfg['return_format'] ) && 'id' !== $cfg['return_format'] ) {
			$warnings[] = sprintf(
				/* translators: %s: field name */
				__( '"%s": the return format was not carried over; templates receive the UiChemy default.', 'uichemy' ),
				$label
			);
		}
		if ( in_array( $target_type, array( 'select', 'checkbox', 'radio' ), true ) && ! empty( $cfg['choices'] ) && is_array( $cfg['choices'] ) ) {
			// ACF and Uichemy use the same assoc value => label shape.
			$field['choices'] = $cfg['choices'];
			if ( ! empty( $cfg['multiple'] ) ) {
				$field['multiple'] = true;
			}
		}
		if ( in_array( $target_type, array( 'number', 'range' ), true ) ) {
			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				if ( isset( $cfg[ $k ] ) && '' !== $cfg[ $k ] ) {
					$field[ $k ] = $cfg[ $k ];
				}
			}
		}
		if ( isset( $cfg['default_value'] ) && '' !== $cfg['default_value'] && ! is_array( $cfg['default_value'] ) ) {
			$field['default_value'] = $cfg['default_value'];
		}
		if ( isset( $cfg['placeholder'] ) && '' !== $cfg['placeholder'] ) {
			$field['placeholder'] = $cfg['placeholder'];
		}
		if ( in_array( $target_type, array( 'post_object', 'relationship', 'page_link' ), true ) && ! empty( $cfg['post_type'] ) ) {
			$field[ $target_type . '_post_types' ] = (array) $cfg['post_type'];
		}
		if ( 'relationship' === $target_type && ! empty( $cfg['max'] ) ) {
			$field['relationship_max_posts'] = (int) $cfg['max'];
		}
		if ( 'taxonomy' === $target_type && ! empty( $cfg['taxonomy'] ) ) {
			$field['taxonomy_name'] = (string) $cfg['taxonomy'];
		}
		if ( 'repeater' === $target_type && ! empty( $cfg['max'] ) ) {
			$field['repeater_max_rows'] = (int) $cfg['max'];
		}
	}

	/**
	 * ACF location rules => Uichemy location rules.
	 *
	 * @param array $location ACF location.
	 * @param array $warnings Collected warnings (by reference).
	 * @return array
	 */
	private function map_location( $location, &$warnings ) {
		$out = array();
		foreach ( (array) $location as $group ) {
			$rules = array();
			foreach ( (array) $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
					continue;
				}
				if ( 'options_page' === $rule['param'] ) {
					$warnings[] = __( 'An options-page location rule was dropped: UiChemy has no options-page context yet.', 'uichemy' );
					continue;
				}
				$rules[] = array(
					'param'    => (string) $rule['param'],
					'operator' => isset( $rule['operator'] ) ? (string) $rule['operator'] : '==',
					'value'    => isset( $rule['value'] ) ? (string) $rule['value'] : '',
				);
			}
			if ( $rules ) {
				$out[] = $rules;
			}
		}
		if ( ! $out ) {
			$out        = array(
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
		return $out;
	}

	/**
	 * ACF conditional logic (field KEY refs) => Uichemy (field NAME refs).
	 *
	 * @param array $logic       ACF conditional_logic.
	 * @param array $key_to_name key => name map.
	 * @return array
	 */
	private function map_conditional( $logic, $key_to_name ) {
		$out = array();
		foreach ( (array) $logic as $group ) {
			$rules = array();
			foreach ( (array) $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
					continue;
				}
				$key = (string) $rule['field'];
				if ( ! isset( $key_to_name[ $key ] ) ) {
					continue; // Reference to a field that was skipped — drop the rule, not the field.
				}
				$rules[] = array(
					'field'    => $key_to_name[ $key ],
					'operator' => isset( $rule['operator'] ) ? (string) $rule['operator'] : '==',
					'value'    => isset( $rule['value'] ) ? (string) $rule['value'] : '',
				);
			}
			if ( $rules ) {
				$out[] = $rules;
			}
		}
		return $out;
	}

	/**
	 * Walk the whole field tree once to build key => name.
	 *
	 * @param int   $parent_id Parent post ID.
	 * @param array $map       Map being built (by reference).
	 */
	private function collect_key_names( $parent_id, &$map ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_excerpt FROM {$wpdb->posts}
			 WHERE post_type = 'acf-field' AND post_parent = %d AND post_status != 'trash'",
				$parent_id
			)
		);
		foreach ( $rows as $r ) {
			if ( '' !== (string) $r->post_excerpt ) {
				$map[ (string) $r->post_name ] = $this->clean_name( $r->post_excerpt );
			}
			$this->collect_key_names( (int) $r->ID, $map );
		}
	}

	// ----------------------------------------------------------------- Values
	// -----------------------------------------------------------------.

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

		if ( isset( $field['type'] ) && 'repeater' === $field['type'] ) {
			return $this->read_repeater( $field, $object_type, $object_id, $name );
		}

		$raw = $this->raw_meta( $object_type, $object_id, $name, true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			return null;
		}
		return $this->normalise_scalar( $raw, isset( $field['type'] ) ? $field['type'] : 'text', ! empty( $field['multiple'] ) );
	}

	/**
	 * Re-assemble an ACF repeater from its flattened rows.
	 *
	 * @param array  $field       Field descriptor.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $name        Source field name (already row-prefixed when nested).
	 * @return array|null
	 */
	private function read_repeater( $field, $object_type, $object_id, $name ) {
		$count = $this->raw_meta( $object_type, $object_id, $name, true );
		if ( ! is_numeric( $count ) || (int) $count < 1 ) {
			return null;
		}
		$count = (int) $count;
		$subs  = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();
		$rows  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $subs as $sub ) {
				if ( empty( $sub['name'] ) ) {
					continue;
				}
				$sub_name = $name . '_' . $i . '_' . $sub['name'];
				if ( isset( $sub['type'] ) && 'repeater' === $sub['type'] ) {
					$nested = $this->read_repeater( $sub, $object_type, $object_id, $sub_name );
					if ( null !== $nested ) {
						$row[ $sub['name'] ] = $nested;
					}
					continue;
				}
				$raw = $this->raw_meta( $object_type, $object_id, $sub_name, true );
				if ( '' === $raw || null === $raw ) {
					continue;
				}
				$row[ $sub['name'] ] = $this->normalise_scalar( $raw, isset( $sub['type'] ) ? $sub['type'] : 'text', ! empty( $sub['multiple'] ) );
			}
			if ( $row ) {
				$rows[] = $row;
			}
		}
		return $rows ? $rows : null;
	}

	/**
	 * Convert an ACF stored scalar into the shape this module's sanitizer expects.
	 *
	 * @param mixed  $raw         Stored value.
	 * @param string $target_type Target field type.
	 * @return mixed
	 */
	private function normalise_scalar( $raw, $target_type, $multiple = false ) {
		$raw = $this->decode_stored( $raw );

		// A multi-value select legitimately stores a LIST. scalarise() below returns null for any
		// array it cannot reduce to one scalar, and read_value() turns null into "skip", so a
		// two-or-more selection was dropped silently — the field group imported with
		// multiple => true while its value never arrived. This module's own sanitizer accepts the list
		// for a multi select, so hand it straight through (scalars only, keys re-indexed).
		if ( $multiple && 'select' === $target_type && is_array( $raw ) ) {
			return array_values( array_filter( $raw, 'is_scalar' ) );
		}

		switch ( $target_type ) {
			case 'date':
				// ACF: Ymd. Feed ISO so this module normalises into its own display format.
				if ( is_string( $raw ) && preg_match( '/^\d{8}$/', $raw ) ) {
					return substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 );
				}
				return $raw;

			case 'page_link':
				// ACF stores an ID; this module stores the URL.
				if ( is_numeric( $raw ) ) {
					$url = get_permalink( (int) $raw );
					return $url ? $url : '';
				}
				return $raw;

			case 'relationship':
				return array_values( array_map( 'intval', (array) $raw ) );

			case 'post_object':
			case 'user':
			case 'image':
			case 'file':
				return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : ( is_numeric( $raw ) ? (int) $raw : $raw );

			case 'taxonomy':
				return array_values( array_map( 'intval', (array) $raw ) );

			case 'true_false':
				return ! empty( $raw ) ? 1 : 0;

			default:
				return self::scalarise( $raw, $target_type );
		}
	}

	/**
	 * This module's field types that hold a single scalar. Handing any of these an array stores an array.
	 *
	 * @return string[]
	 */
	private static function scalar_types() {
		return array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'color', 'wysiwyg', 'select', 'radio', 'time' );
	}

	/**
	 * Flatten a source value that is an array into the scalar its target type expects.
	 *
	 * @param mixed  $raw         Stored value.
	 * @param string $target_type Target field type.
	 * @return mixed
	 */
	private static function scalarise( $raw, $target_type ) {
		if ( ! is_array( $raw ) || ! in_array( $target_type, self::scalar_types(), true ) ) {
			return $raw;
		}
		// Known shapes, most specific first.
		foreach ( array( 'address', 'value', 'url', 'name', 'label' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				return $raw[ $key ];
			}
		}
		// A single-element list of a scalar is unambiguous.
		if ( 1 === count( $raw ) ) {
			$only = reset( $raw );
			if ( is_scalar( $only ) ) {
				return $only;
			}
		}
		return null;
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
			$id = (int) $id;
			if ( ! $id ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 0 !== strpos( $post->post_type, 'acf-' ) ) {
				continue;
			}
			// 'acf-disabled' is ACF's own inactive status, and is what its UI shows as Disabled —
			// the same status Meta Box's migrator uses, so the two importers stay compatible.
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'acf-disabled',
				)
			);
			++$n;
		}
		return $n;
	}

	// ----------------------------------------------------------------- Internals
	// -----------------------------------------------------------------.

	/**
	 * Definition posts of one ACF post type, excluding trashed.
	 *
	 * @param string $post_type ACF definition post type.
	 * @return WP_Post[]
	 */
	private function definition_posts( $post_type ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' ORDER BY menu_order ASC, ID ASC",
				$post_type
			)
		);
	}

	/**
	 * ACF serializes config into post_content; local-JSON copies may be plain JSON.
	 *
	 * @param string $content post_content.
	 * @return array
	 */
	private function decode_content( $content ) {
		$content = (string) $content;
		if ( '' === $content ) {
			return array();
		}
		if ( is_serialized( $content ) ) {
			$v = $this->decode_stored( $content );
			return is_array( $v ) ? $v : array();
		}
		$v = json_decode( $content, true );
		return is_array( $v ) ? $v : array();
	}
}
