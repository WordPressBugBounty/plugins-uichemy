<?php
/**
 * Field registry for Content Types (CPT and Taxonomy).
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Fields
 */
class Uich_Fields_Fields {

	const TYPE_KEY          = 'key';
	const TYPE_SLUG         = 'slug';
	const TYPE_BOOL_OR_SLUG = 'bool_or_slug';
	const TYPE_TEXT         = 'text';
	const TYPE_TEXTAREA     = 'textarea';
	const TYPE_BOOL         = 'bool';
	const TYPE_INT          = 'int';
	const TYPE_ARRAY_KEY    = 'array_key';
	const TYPE_SUPPORTS     = 'supports';
	const TYPE_LABELS       = 'labels';
	const TYPE_CAPABILITIES = 'capabilities';
	const TYPE_DEFAULT_TERM = 'default_term';
	const TYPE_CLASS_NAME   = 'class_name';

	/**
	 * CPT meta keys and their sanitize type. Matches CptForm.jsx + FormModal defaultCptForm.
	 *
	 * @return array<string, string> Key => type.
	 */
	public static function get_cpt_fields() {
		return array(
			'kind'                      => self::TYPE_KEY,
			'post_type_key'             => self::TYPE_KEY,
			'label'                     => self::TYPE_TEXT,
			'singular_label'            => self::TYPE_TEXT,
			'public'                    => self::TYPE_BOOL,
			'show_ui'                   => self::TYPE_BOOL,
			'show_in_rest'              => self::TYPE_BOOL,
			'hierarchical'              => self::TYPE_BOOL,
			'has_archive'               => self::TYPE_BOOL,
			// Slug-typed: Register applies sanitize_title() anyway, so sanitize on the way in to keep stored == effective.
			'archive_slug'              => self::TYPE_SLUG,
			'rewrite_slug'              => self::TYPE_SLUG,
			'rewrite_enabled'           => self::TYPE_BOOL,
			'rewrite_with_front'        => self::TYPE_BOOL,
			'rewrite_pages'             => self::TYPE_BOOL,
			'rewrite_feeds'             => self::TYPE_BOOL,
			'query_var'                 => self::TYPE_BOOL,
			'query_var_string'          => self::TYPE_SLUG,
			'taxonomies'                => self::TYPE_ARRAY_KEY,
			'supports'                  => self::TYPE_SUPPORTS,
			'description'               => self::TYPE_TEXTAREA,
			'labels'                    => self::TYPE_LABELS,
			'show_in_menu'              => self::TYPE_BOOL_OR_SLUG,
			'show_in_admin_bar'         => self::TYPE_BOOL,
			'show_in_nav_menus'         => self::TYPE_BOOL,
			'exclude_from_search'       => self::TYPE_BOOL,
			'publicly_queryable'        => self::TYPE_BOOL,
			'can_export'                => self::TYPE_BOOL,
			'delete_with_user'          => self::TYPE_BOOL,
			'menu_position'             => self::TYPE_INT,
			'menu_icon'                 => self::TYPE_TEXT,
			'capability_type'           => self::TYPE_KEY,
			'map_meta_cap'              => self::TYPE_BOOL,
			'capabilities'              => self::TYPE_CAPABILITIES,
			'rest_base'                 => self::TYPE_SLUG,
			'rest_controller_class'     => self::TYPE_CLASS_NAME,
			'advanced_settings_enabled' => self::TYPE_BOOL,
			'active'                    => self::TYPE_BOOL,
		);
	}

	/**
	 * Taxonomy meta keys and their sanitize type. Matches TaxonomyForm.jsx + FormModal defaultTaxForm.
	 *
	 * @return array<string, string> Key => type.
	 */
	public static function get_taxonomy_fields() {
		return array(
			'kind'                      => self::TYPE_KEY,
			'taxonomy_key'              => self::TYPE_KEY,
			'label'                     => self::TYPE_TEXT,
			'singular_label'            => self::TYPE_TEXT,
			'hierarchical'              => self::TYPE_BOOL,
			'object_type'               => self::TYPE_ARRAY_KEY,
			'show_in_rest'              => self::TYPE_BOOL,
			'rewrite_slug'              => self::TYPE_SLUG,
			'rewrite'                   => self::TYPE_BOOL,
			'rewrite_hierarchical'      => self::TYPE_BOOL,
			'public'                    => self::TYPE_BOOL,
			'show_ui'                   => self::TYPE_BOOL,
			'show_admin_column'         => self::TYPE_BOOL,
			'show_tag_cloud'            => self::TYPE_BOOL,
			'show_in_nav_menus'         => self::TYPE_BOOL,
			'query_var'                 => self::TYPE_BOOL,
			// Slug-typed to match the CPT branch: sanitize_tax_args() applies sanitize_title() when
			// building the register args, so storing the raw text drifts from what actually serves.
			'query_var_string'          => self::TYPE_SLUG,
			'labels'                    => self::TYPE_LABELS,
			'capabilities'              => self::TYPE_CAPABILITIES,
			'rest_base'                 => self::TYPE_SLUG,
			'rest_controller_class'     => self::TYPE_CLASS_NAME,
			'active'                    => self::TYPE_BOOL,
			'sort'                      => self::TYPE_BOOL,
			'default_term_enabled'      => self::TYPE_BOOL,
			'default_term'              => self::TYPE_DEFAULT_TERM,
			'description'               => self::TYPE_TEXTAREA,
			'advanced_settings_enabled' => self::TYPE_BOOL,
		);
	}

	/**
	 * Allowed capability keys for CPT (register_post_type capabilities).
	 *
	 * @return array<string>
	 */
	public static function get_cpt_capability_keys() {
		return array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'publish_posts',
			'read_private_posts',
		);
	}

	/**
	 * Allowed capability keys for taxonomy (register_taxonomy capabilities).
	 *
	 * @return array<string>
	 */
	public static function get_taxonomy_capability_keys() {
		return array(
			'manage_terms',
			'edit_terms',
			'delete_terms',
			'assign_terms',
		);
	}

	/**
	 * Capabilities that must NOT be used as the target of a content-type capability mapping, because
	 * low-privilege / all-logged-in users hold them.
	 *
	 * @return string[] Lowercase capability slugs.
	 */
	public static function unsafe_capability_targets() {
		$unsafe = array( 'read', 'exist', 'level_0' );
		/**
		 * Filter the list of capabilities that cannot be used as a content-type capability target.
		 *
		 * @param string[] $unsafe Capability slugs to reject.
		 */
		$unsafe = apply_filters( 'uich_fields_unsafe_capability_targets', $unsafe );
		return array_map( 'sanitize_key', (array) $unsafe );
	}

	/**
	 * Capabilities a META capability (edit_post / read_post / delete_post) must never map to.
	 *
	 * register_post_type() records each meta-cap value in a global map, and map_meta_cap() then
	 * treats every check of that value as the meta cap. Mapping edit_post to manage_options turns
	 * every current_user_can( 'manage_options' ) into edit_post without a post ID, which returns
	 * do_not_allow for everyone, super admins included: the site locks itself out. So a meta cap
	 * may only map to a capability of its own (edit_book…), never to a core primitive capability.
	 * Primitive keys (edit_posts, publish_posts…) may still map to these: "only admins can edit
	 * books" is edit_posts => manage_options, which is safe.
	 *
	 * @return string[] Lowercase capability slugs.
	 */
	public static function reserved_meta_cap_targets() {
		$caps = array(
			'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files',
			'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import',
			'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
			'edit_pages', 'read', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages',
			'delete_others_pages', 'delete_published_pages', 'delete_posts', 'delete_others_posts',
			'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts',
			'delete_private_pages', 'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users',
			'unfiltered_upload', 'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins',
			'update_themes', 'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users',
			'edit_theme_options', 'delete_themes', 'export', 'manage_network', 'manage_sites',
			'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options',
			'upgrade_network', 'setup_network', 'create_sites', 'delete_sites', 'delete_site', 'add_users',
			'update_languages', 'install_languages', 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms',
			'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8',
			'level_9', 'level_10', 'exist', 'do_not_allow',
		);
		/**
		 * Filter the capabilities a content type's edit_post / read_post / delete_post may not map to.
		 * Add another plugin's primitive capabilities here (manage_woocommerce…) to protect them too.
		 *
		 * @param string[] $caps Capability slugs.
		 */
		$caps = apply_filters( 'uich_fields_reserved_meta_cap_targets', $caps );
		return array_map( 'sanitize_key', (array) $caps );
	}

	/**
	 * Whether mapping capability key $cap_key to $cap_value is refused.
	 *
	 * @param string $cap_key   Content-type capability key (edit_post, edit_posts…).
	 * @param string $cap_value Target capability.
	 * @return bool
	 */
	public static function is_refused_capability_target( $cap_key, $cap_value ) {
		if ( '' === $cap_value || in_array( $cap_value, self::unsafe_capability_targets(), true ) ) {
			return true;
		}
		if ( in_array( $cap_key, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
			return in_array( $cap_value, self::reserved_meta_cap_targets(), true );
		}
		return false;
	}

	/**
	 * Default values for CPT meta when key is missing (e.g. create).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_cpt_defaults() {
		return array(
			'public'            => true,
			'show_ui'           => true,
			// No 'show_in_rest' default on purpose (MB-35): when the key is absent the register builder derives it
			// from $public (build_cpt_args), so a private CPT is NOT REST-exposed by default.
			'rewrite_enabled'   => true,
			'rewrite_pages'     => true,
			'rewrite_feeds'     => true,
			'query_var'         => true,
			'show_in_menu'      => true,
			'show_in_admin_bar' => true,
			'show_in_nav_menus' => true,
			// No 'publicly_queryable' / 'exclude_from_search' default, as with show_in_rest: it would bypass the $public fallback.
			'can_export'        => true,
			'map_meta_cap'      => true,
			'active'            => true,
			'taxonomies'        => array(),
			// No 'supports' default, same reasoning as show_in_rest / publicly_queryable above.
			'labels'            => array(),
			'capabilities'      => array(),
			'archive_slug'      => '',
		);
	}

	/**
	 * Default values for taxonomy meta when key is missing.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_taxonomy_defaults() {
		return array(
			// No 'show_in_rest' default (MB-35): absent lets the builder derive it from $public, so a
			// private taxonomy is not REST-exposed.
			'rewrite'              => true,
			'public'               => true,
			'show_ui'              => true,
			'show_admin_column'    => true,
			'show_tag_cloud'       => true,
			'show_in_nav_menus'    => true,
			'query_var'            => true,
			'active'               => true,
			'object_type'          => array( 'post' ),
			'labels'               => array(),
			'capabilities'         => array(),
			// No 'sort' default: core defaults it to null, so absent means no term_order upkeep.
			'default_term_enabled' => false,
			'default_term'         => array(
				'name'        => '',
				'slug'        => '',
				'description' => '',
			),
			'description'          => '',
		);
	}

	/**
	 * String values that mean "false".
	 *
	 * @return string[] Lowercase falsy tokens.
	 */
	private static function falsy_strings() {
		return array( '', '0', 'false', 'off', 'no' );
	}

	/**
	 * Whether a string looks like a boolean rather than a real slug value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function is_bool_like( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return in_array( strtolower( trim( $value ) ), array( '1', '0', 'true', 'false', 'on', 'off', 'yes', 'no' ), true );
	}

	/**
	 * Normalise any scalar to bool, honouring the string forms of false.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), self::falsy_strings(), true );
		}
		return ! empty( $value );
	}

	/**
	 * Sanitize a single value by type.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  One of TYPE_* constants.
	 * @param string $context 'cpt' or 'taxonomy' for capabilities keys.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_value( $value, $type, $context = 'cpt' ) {
		switch ( $type ) {
			case self::TYPE_KEY:
				return is_string( $value ) ? sanitize_key( $value ) : '';
			case self::TYPE_SLUG:
				// WP applies sanitize_title() when building rewrite rules regardless, so sanitize here to keep stored == effective.
				return is_scalar( $value ) ? sanitize_title( wp_unslash( (string) $value ) ) : '';
			case self::TYPE_TEXT:
				// REST JSON is never slashed: wp_unslash() here ate typed backslashes. is_scalar, not isset: an array reaching
				// sanitize_text_field() raises a warning that can corrupt a REST response.
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			case self::TYPE_TEXTAREA:
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			case self::TYPE_CLASS_NAME:
				// A class name may be namespaced and those separators matter, so this must not use TYPE_TEXT's wp_unslash().
				return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
			case self::TYPE_BOOL:
				return self::to_bool( $value );
			case self::TYPE_BOOL_OR_SLUG:
				// register_post_type()'s show_in_menu accepts a parent menu slug string in addition to bool.
				if ( is_string( $value ) && '' !== trim( $value ) && ! self::is_bool_like( $value )
					&& preg_match( '#^[A-Za-z0-9_.\-]+(\?[A-Za-z0-9_\-=&%\[\]]*)?$#', trim( $value ) )
				) {
					return sanitize_text_field( wp_unslash( trim( $value ) ) );
				}
				return self::to_bool( $value );
			case self::TYPE_INT:
				// Non-numeric input is treated as absent rather than cast to 0, which would pin the
				// post type to the very top of the admin menu.
				return isset( $value ) && '' !== $value && is_numeric( $value ) ? (int) $value : '';
			case self::TYPE_ARRAY_KEY:
				if ( empty( $value ) ) {
					return array();
				}
				$arr = is_array( $value ) ? $value : explode( ',', (string) $value );
				// array_unique: a duplicate would otherwise persist and render twice in the form.
				return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', $arr ) ) ) ) );
			case self::TYPE_SUPPORTS:
				// WP core "supports" slugs are hyphenated (custom-fields, page-attributes, post-formats), but the admin
				// UI keys them with underscores.
				$support_aliases = array(
					'custom_fields'   => 'custom-fields',
					'page_attributes' => 'page-attributes',
					'post_formats'    => 'post-formats',
				);
				$out             = array();
				if ( ! empty( $value ) && is_array( $value ) ) {
					$cnt             = count( $value );
					$is_indexed_list = ( 0 === $cnt ) || ( array_keys( $value ) === range( 0, $cnt - 1 ) );
					if ( $is_indexed_list ) {
						// List of feature slugs (e.g. from register_post_type or legacy payloads).
						foreach ( $value as $item ) {
							if ( is_string( $item ) && '' !== trim( $item ) ) {
								$key = sanitize_key( $item );
								$key = isset( $support_aliases[ $key ] ) ? $support_aliases[ $key ] : $key;
								if ( '' !== $key && ! ctype_digit( (string) $key ) ) {
									$out[] = $key;
								}
							}
						}
					} else {
						// Map slug => enabled (CptForm / REST body shape).
						foreach ( $value as $feature => $enabled ) {
							if ( $enabled ) {
								$key   = sanitize_key( (string) $feature );
								$out[] = isset( $support_aliases[ $key ] ) ? $support_aliases[ $key ] : $key;
							}
						}
					}
					$out = array_values( array_unique( array_filter( $out ) ) );
				}
				return $out;
			case self::TYPE_LABELS:
				$out = array();
				if ( ! empty( $value ) && is_array( $value ) ) {
					foreach ( $value as $k => $v ) {
						// Skip nested arrays/objects: casting one to string raises an
						// "Array to string conversion" warning inside the REST request.
						if ( ! is_scalar( $v ) ) {
							continue;
						}
						$v = (string) $v;
						if ( '' !== trim( $v ) ) {
							$out[ sanitize_key( $k ) ] = sanitize_text_field( $v );
						}
					}
				}
				return $out;
			case self::TYPE_CAPABILITIES:
				$allowed = 'taxonomy' === $context ? self::get_taxonomy_capability_keys() : self::get_cpt_capability_keys();
				$unsafe  = self::unsafe_capability_targets();
				$out     = array();
				if ( ! empty( $value ) && is_array( $value ) ) {
					foreach ( $allowed as $cap_key ) {
						if ( isset( $value[ $cap_key ] ) && '' !== trim( (string) $value[ $cap_key ] ) ) {
							// The VALUE is the primitive capability this content-type cap maps to.
							$cap = sanitize_key( wp_unslash( (string) $value[ $cap_key ] ) );
							if ( '' === $cap || in_array( $cap, $unsafe, true ) ) {
								continue;
							}
							// A meta cap mapped to a core primitive cap hijacks that cap site-wide.
							if ( 'taxonomy' !== $context && self::is_refused_capability_target( $cap_key, $cap ) ) {
								continue;
							}
							$out[ $cap_key ] = $cap;
						}
					}
				}
				return $out;
			case self::TYPE_DEFAULT_TERM:
				$out = array(
					'name'        => '',
					'slug'        => '',
					'description' => '',
				);
				if ( ! empty( $value ) && is_array( $value ) ) {
					if ( isset( $value['name'] ) ) {
						$out['name'] = sanitize_text_field( $value['name'] );
					}
					if ( isset( $value['slug'] ) ) {
						$out['slug'] = sanitize_title( wp_unslash( $value['slug'] ) );
					}
					if ( isset( $value['description'] ) ) {
						$out['description'] = sanitize_textarea_field( $value['description'] );
					}
				}
				return $out;
			default:
				return $value;
		}
	}
}
