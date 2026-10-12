<?php
/**
 * Shared admin logic for Custom Content Types (used by REST API; replaces admin-ajax).
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Admin_Logic
 */
class Uich_Fields_Admin_Logic {

	/**
	 * Max rows returned per post type in the location-choices lists. When a query hits this cap the
	 * response is flagged `truncated` so the UI can prompt to refine instead of silently omitting.
	 */
	const LOCATION_CHOICES_LIMIT = 100;

	/**
	 * Max terms per taxonomy in the Post Object "filter by taxonomy" payload. Hitting this cap
	 * flags the response `truncated` rather than silently dropping the rest.
	 */
	const POST_OBJECT_TERMS_LIMIT = 500;

	/**
	 * Sanitize single content type meta array (all fields from fields registry).
	 *
	 * @param array  $data Raw decoded data.
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return array Sanitized array for update_post_meta.
	 */
	public static function sanitize_meta( $data, $kind ) {
		$is_cpt = ( 'cpt' === $kind );
		$out    = array( 'kind' => $is_cpt ? 'cpt' : 'taxonomy' );

		if ( $is_cpt ) {
			$fields   = Uich_Fields_Fields::get_cpt_fields();
			$defaults = Uich_Fields_Fields::get_cpt_defaults();
			foreach ( $fields as $key => $type ) {
				if ( 'kind' === $key ) {
					continue;
				}
				if ( array_key_exists( $key, $data ) ) {
					$out[ $key ] = Uich_Fields_Fields::sanitize_value( $data[ $key ], $type, 'cpt' );
				} elseif ( array_key_exists( $key, $defaults ) ) {
					$out[ $key ] = $defaults[ $key ];
				}
			}
			if ( isset( $out['singular_label'] ) && '' === (string) $out['singular_label'] && isset( $out['label'] ) ) {
				$out['singular_label'] = $out['label'];
			}
			if ( empty( $out['taxonomies'] ) ) {
				$out['taxonomies'] = array();
			}
			// Deliberately NOT normalised to array(): whether the caller sent `supports` at all is meaningful.
			if ( array_key_exists( 'supports', $data ) && ! isset( $out['supports'] ) ) {
				$out['supports'] = array();
			}
		} else {
			$fields   = Uich_Fields_Fields::get_taxonomy_fields();
			$defaults = Uich_Fields_Fields::get_taxonomy_defaults();
			foreach ( $fields as $key => $type ) {
				if ( 'kind' === $key ) {
					continue;
				}
				if ( array_key_exists( $key, $data ) ) {
					$out[ $key ] = Uich_Fields_Fields::sanitize_value( $data[ $key ], $type, 'taxonomy' );
				} elseif ( array_key_exists( $key, $defaults ) ) {
					$out[ $key ] = $defaults[ $key ];
				}
			}
			if ( isset( $out['singular_label'] ) && '' === (string) $out['singular_label'] && isset( $out['label'] ) ) {
				$out['singular_label'] = $out['label'];
			}
			if ( empty( $out['object_type'] ) ) {
				$out['object_type'] = array( 'post' );
			}
		}

		return $out;
	}

	/**
	 * Location Rules select options (Post, Page, Taxonomy, etc.).
	 *
	 * @param string $param Rule parameter key.
	 * @return array{ items: array, groups?: array }
	 */
	public static function get_location_choices( $param ) {
		$param     = sanitize_key( (string) $param );
		$items     = array();
		$groups    = array();
		$truncated = false;

		switch ( $param ) {
			case 'post':
				// All posts from all public post types, grouped by post type label.
				$post_types = get_post_types(
					array(
						'public' => true,
					),
					'objects'
				);
				// Exclude built-in 'page' and 'attachment' post types.
				unset( $post_types['page'], $post_types['attachment'] );

				foreach ( $post_types as $type_obj ) {
					$hierarchical = is_post_type_hierarchical( $type_obj->name );
					$args         = array(
						'post_type'              => $type_obj->name,
						'post_status'            => 'any',
						'posts_per_page'         => self::LOCATION_CHOICES_LIMIT,
						'order'                  => 'ASC',
						'orderby'                => $hierarchical ? 'menu_order title' : 'title',
						// Perf: this is a label/id list — skip found-rows and satellite cache priming.
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					);
					$query        = get_posts( $args );

					if ( empty( $query ) ) {
						continue;
					}
					if ( count( $query ) >= self::LOCATION_CHOICES_LIMIT ) {
						$truncated = true;
					}

					// Depth from the fetched set only (0 extra queries) instead of get_post_ancestors() per row.
					$parents = array();
					if ( $hierarchical ) {
						foreach ( $query as $p ) {
							$parents[ (int) $p->ID ] = (int) $p->post_parent;
						}
					}

					$group_options = array();
					foreach ( $query as $post ) {
						$label = get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'uichemy' );
						if ( $hierarchical ) {
							$depth = self::compute_depth_from_map( (int) $post->ID, $parents );
							if ( $depth > 0 ) {
								$label = str_repeat( '- ', $depth ) . $label;
							}
						}
						$option          = array(
							'value' => (string) $post->ID,
							'label' => $label,
						);
						$items[]         = $option;
						$group_options[] = $option;
					}

					// The loop above can append nothing — every post in $query may be filtered out by the per-post checks
					// inside it — and an option group with no options renders as an empty heading in the select.
					if ( ! empty( $group_options ) ) {
						$groups[] = array(
							'label'   => $type_obj->labels->name ? $type_obj->labels->name : $type_obj->name,
							'options' => $group_options,
						);
					}
				}
				break;

			case 'page':
				$query = get_posts(
					array(
						'post_type'              => 'page',
						'post_status'            => 'any',
						'posts_per_page'         => self::LOCATION_CHOICES_LIMIT,
						'orderby'                => 'menu_order title',
						'order'                  => 'ASC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				if ( count( $query ) >= self::LOCATION_CHOICES_LIMIT ) {
					$truncated = true;
				}
				$parents = array();
				foreach ( $query as $p ) {
					$parents[ (int) $p->ID ] = (int) $p->post_parent;
				}
				foreach ( $query as $post ) {
					$label = get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'uichemy' );
					$depth = self::compute_depth_from_map( (int) $post->ID, $parents );
					if ( $depth > 0 ) {
						$label = str_repeat( '- ', $depth ) . $label;
					}
					$items[] = array(
						'value' => (string) $post->ID,
						'label' => $label,
					);
				}
				break;

			case 'post_template':
			case 'page_template':
				// get_page_templates() is an admin-only function (wp-admin/includes/theme.php) and is undefined in REST
				// context.
				$tpl_post_type = ( 'post_template' === $param ) ? 'post' : 'page';
				$theme         = wp_get_theme();
				$templates     = $theme ? $theme->get_page_templates( null, $tpl_post_type ) : array();
				$items[]       = array(
					'value' => 'default',
					'label' => __( 'Default Template', 'uichemy' ),
				);
				foreach ( $templates as $template_file => $template_name ) {
					$items[] = array(
						'value' => $template_file,
						'label' => $template_name,
					);
				}
				break;

			case 'post_status':
				$stati = get_post_stati( array( 'internal' => false ), 'objects' );
				foreach ( $stati as $key => $obj ) {
					$items[] = array(
						'value' => $key,
						'label' => isset( $obj->label ) ? $obj->label : $key,
					);
				}
				$internal = get_post_stati( array( 'internal' => true ), 'objects' );
				foreach ( $internal as $key => $obj ) {
					$items[] = array(
						'value' => $key,
						'label' => isset( $obj->label ) ? $obj->label : $key,
					);
				}
				break;

			case 'post_format':
				$items[] = array(
					'value' => 'standard',
					'label' => _x( 'Standard', 'Post format', 'uichemy' ),
				);
				foreach ( get_post_format_strings() as $slug => $label ) {
					if ( 'standard' !== $slug ) {
						$items[] = array(
							'value' => $slug,
							'label' => $label,
						);
					}
				}
				break;

			case 'post_category':
				$terms = get_terms(
					array(
						'taxonomy'   => 'category',
						'hide_empty' => false,
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						// Prefix child terms with "- " based on depth in hierarchy.
						$depth = 0;
						if ( isset( $term->term_id ) ) {
							$ancestors = get_ancestors( $term->term_id, 'category', 'taxonomy' );
							$depth     = is_array( $ancestors ) ? count( $ancestors ) : 0;
						}
						$label = $term->name;
						if ( $depth > 0 ) {
							$label = str_repeat( '- ', (int) $depth ) . $label;
						}
						$items[] = array(
							'value' => (string) $term->term_id,
							'label' => $label,
						);
					}
				}
				break;

			case 'post_taxonomy':
				// All public taxonomy terms grouped by taxonomy (for Post Taxonomy location rules).
				$taxonomies = get_taxonomies(
					array(
						'public' => true,
					),
					'objects'
				);
				$groups     = array();

				foreach ( $taxonomies as $tax ) {
					$terms = get_terms(
						array(
							'taxonomy'   => $tax->name,
							'hide_empty' => false,
							'orderby'    => 'name',
							'order'      => 'ASC',
						)
					);
					if ( is_wp_error( $terms ) || empty( $terms ) ) {
						continue;
					}

					$group_options = array();
					foreach ( $terms as $term ) {
						// Value encodes both taxonomy and term for precise matching.
						$value = $tax->name . ':' . $term->term_id;
						$label = $term->name;
						// For hierarchical taxonomies, prefix child terms with "- " per depth.
						if ( is_taxonomy_hierarchical( $tax->name ) ) {
							$ancestors = get_ancestors( $term->term_id, $tax->name, 'taxonomy' );
							$depth     = is_array( $ancestors ) ? count( $ancestors ) : 0;
							if ( $depth > 0 ) {
								$label = str_repeat( '- ', (int) $depth ) . $label;
							}
						}
						$items[]         = array(
							'value' => $value,
							'label' => $label,
						);
						$group_options[] = array(
							'value' => $value,
							'label' => $label,
						);
					}

					if ( ! empty( $group_options ) ) {
						$groups[] = array(
							'label'   => $tax->labels->name ? $tax->labels->name : $tax->name,
							'options' => $group_options,
						);
					}
				}
				break;
			case 'post_type':
				// Post types a field group can appear on: publicly viewable, or carrying an admin menu entry of their
				// own.
				$pt_objects = get_post_types( array( 'show_ui' => true ), 'objects' );
				foreach ( $pt_objects as $type_obj ) {
					if ( empty( $type_obj->public ) && empty( $type_obj->show_in_menu ) ) {
						continue;
					}
					$items[] = array(
						'value' => $type_obj->name,
						'label' => $type_obj->labels->name ? $type_obj->labels->name : $type_obj->name,
					);
				}
				break;

			case 'taxonomy':
				$taxonomies = get_taxonomies(
					array(
						'public' => true,
					),
					'objects'
				);
				$items[]    = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				foreach ( $taxonomies as $tax ) {
					$items[] = array(
						'value' => $tax->name,
						'label' => $tax->labels->name ? $tax->labels->name : $tax->name,
					);
				}
				break;

			case 'page_type':
				$items = array(
					array(
						'value' => 'front_page',
						'label' => __( 'Front Page', 'uichemy' ),
					),
					array(
						'value' => 'posts_page',
						'label' => __( 'Posts Page', 'uichemy' ),
					),
					array(
						'value' => 'top_level',
						'label' => __( 'Top Level Page (no parent)', 'uichemy' ),
					),
					array(
						'value' => 'parent',
						'label' => __( 'Parent Page (has children)', 'uichemy' ),
					),
					array(
						'value' => 'child',
						'label' => __( 'Child Page (has parent)', 'uichemy' ),
					),
				);
				break;

			case 'page_parent':
				$pages   = get_pages(
					array(
						'sort_column' => 'menu_order,post_title',
						'number'      => 200,
					)
				);
				$items[] = array(
					'value' => '0',
					'label' => __( '— No parent —', 'uichemy' ),
				);
				foreach ( $pages as $page ) {
					$prefix  = str_repeat( '— ', (int) count( get_post_ancestors( $page->ID ) ) );
					$items[] = array(
						'value' => (string) $page->ID,
						'label' => $prefix . ( get_the_title( $page->ID ) ? get_the_title( $page->ID ) : __( '(no title)', 'uichemy' ) ),
					);
				}
				break;

			case 'user_role':
			case 'current_user_role':
				// Read into a local rather than assigning to the global: wp_roles() returns that very
				// instance and populates it, so this is the same object without overwriting a core global.
				global $wp_roles;
				$roles_registry = isset( $wp_roles ) ? $wp_roles : wp_roles();
				foreach ( $roles_registry->roles as $role_key => $role ) {
					$items[] = array(
						'value' => $role_key,
						'label' => $role['name'],
					);
				}
				break;

			case 'current_user':
				$items = array(
					array(
						'value' => 'logged_in',
						'label' => __( 'Logged in', 'uichemy' ),
					),
					array(
						'value' => 'viewing_front',
						'label' => __( 'Viewing front end', 'uichemy' ),
					),
					array(
						'value' => 'viewing_back',
						'label' => __( 'Viewing back end', 'uichemy' ),
					),
				);
				break;

			case 'user_form':
				$items = array(
					array(
						'value' => 'all',
						'label' => __( 'All', 'uichemy' ),
					),
					array(
						'value' => 'add',
						'label' => __( 'Add', 'uichemy' ),
					),
					array(
						'value' => 'edit',
						'label' => __( 'Edit', 'uichemy' ),
					),
					array(
						'value' => 'add_edit',
						'label' => __( 'Add / Edit', 'uichemy' ),
					),
					array(
						'value' => 'register',
						'label' => __( 'Register', 'uichemy' ),
					),
				);
				break;

			case 'attachment':
				$items[] = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				$items[] = array(
					'value' => 'image',
					'label' => __( 'All image formats', 'uichemy' ),
				);
				$mimes   = get_allowed_mime_types();
				$seen    = array();
				foreach ( $mimes as $ext => $mime ) {
					if ( preg_match( '#^image/#', $mime ) && ! isset( $seen[ $mime ] ) ) {
						$seen[ $mime ] = true;
						$items[]       = array(
							'value' => $mime,
							'label' => $ext . ' (' . $mime . ')',
						);
					}
				}
				break;

			case 'comment':
				$items[] = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				$pt      = get_post_types( array( 'public' => true ), 'names' );
				foreach ( $pt as $p ) {
					$obj     = get_post_type_object( $p );
					$items[] = array(
						'value' => $p,
						'label' => $obj && isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $p,
					);
				}
				break;

			case 'widget':
				global $wp_registered_sidebars;
				$items[] = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				if ( ! empty( $wp_registered_sidebars ) && is_array( $wp_registered_sidebars ) ) {
					foreach ( $wp_registered_sidebars as $id => $sidebar ) {
						$items[] = array(
							'value' => $id,
							'label' => isset( $sidebar['name'] ) ? $sidebar['name'] : $id,
						);
					}
				}
				break;

			case 'nav_menu':
				$items[]   = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				$locations = get_registered_nav_menus();
				if ( ! empty( $locations ) ) {
					foreach ( $locations as $loc => $name ) {
						$items[] = array(
							'value' => 'location_' . $loc,
							'label' => $name,
						);
					}
				}
				$menus = wp_get_nav_menus();
				if ( ! empty( $menus ) && ! is_wp_error( $menus ) ) {
					foreach ( $menus as $menu ) {
						$items[] = array(
							'value' => (string) $menu->term_id,
							'label' => $menu->name,
						);
					}
				}
				break;

			case 'nav_menu_item':
				$items[] = array(
					'value' => 'all',
					'label' => __( 'All', 'uichemy' ),
				);
				$menus   = wp_get_nav_menus();
				if ( ! empty( $menus ) && ! is_wp_error( $menus ) ) {
					foreach ( $menus as $menu ) {
						$items[] = array(
							'value' => (string) $menu->term_id,
							'label' => $menu->name,
						);
					}
				}
				break;

			default:
				$items = array();
				break;
		}

		$response = array(
			'items' => $items,
		);

		// $groups is initialised as an array at the top of this function, so isset()/is_array()
		// could never be false — emptiness is the only thing worth testing.
		if ( ! empty( $groups ) ) {
			$response['groups'] = $groups;
		}

		// Signal when the list was capped so the UI can show "showing first N — refine your search"
		// instead of silently omitting choices.
		if ( $truncated ) {
			$response['truncated'] = true;
			$response['limit']     = self::LOCATION_CHOICES_LIMIT;
		}

		return $response;
	}

	/**
	 * Depth of a post within a parent map (id => parent_id) built from an already-fetched set —
	 * avoids a get_post_ancestors() query per row. Ancestors outside the fetched set stop the walk.
	 *
	 * @param int   $id      Post ID.
	 * @param int[] $parents Map of post ID => parent ID.
	 * @return int
	 */
	private static function compute_depth_from_map( $id, $parents ) {
		$depth = 0;
		$seen  = array();
		$pid   = isset( $parents[ $id ] ) ? (int) $parents[ $id ] : 0;
		while ( $pid > 0 && isset( $parents[ $pid ] ) && ! isset( $seen[ $pid ] ) ) {
			$seen[ $pid ] = true;
			++$depth;
			$pid = (int) $parents[ $pid ];
		}
		return $depth;
	}

	/**
	 * Whether a CPT/taxonomy key is already used by another definition (uniqueness guard).
	 *
	 * @param string $kind            'cpt' or 'taxonomy'.
	 * @param string $key             The post_type_key / taxonomy_key to check.
	 * @param int    $exclude_post_id Definition post being edited (excluded from the check).
	 * @param bool   $include_trash   Also treat trashed definitions as owning the key.
	 * @return bool
	 */
	public static function key_in_use( $kind, $key, $exclude_post_id = 0, $include_trash = false ) {
		return '' !== self::definition_title_for_key( $kind, $key, $exclude_post_id, $include_trash );
	}

	/**
	 * Title of the definition that owns a key, or '' when the key is free. Used both for the
	 * uniqueness guard and to name the offender in user-facing messages.
	 *
	 * @param string $kind            'cpt' or 'taxonomy'.
	 * @param string $key             The post_type_key / taxonomy_key to look up.
	 * @param int    $exclude_post_id Definition post to ignore (the one being edited).
	 * @param bool   $include_trash   Include trashed definitions in the scan.
	 * @param bool   $trashed_only    Only match trashed definitions (implies $include_trash).
	 * @return string
	 */
	public static function definition_title_for_key( $kind, $key, $exclude_post_id = 0, $include_trash = false, $trashed_only = false ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		$meta_field      = ( 'cpt' === $kind ) ? 'post_type_key' : 'taxonomy_key';
		$exclude_post_id = absint( $exclude_post_id );

		// Always include_trash: that is the repository's uncached path, and this scan backs the uniqueness guard.
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows( array( 'include_trash' => true ) ) as $row ) {
			if ( $kind !== $row['kind'] || (int) $row['id'] === $exclude_post_id ) {
				continue;
			}
			$is_trashed = ( 'trash' === $row['status'] );
			if ( $trashed_only && ! $is_trashed ) {
				continue;
			}
			if ( ! $trashed_only && ! $include_trash && $is_trashed ) {
				continue;
			}
			$meta = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( isset( $meta[ $meta_field ] ) && (string) $meta[ $meta_field ] === $key ) {
				$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
				return '' !== $title ? $title : $key;
			}
		}
		return '';
	}

	/**
	 * REST bases owned by WordPress core's own /wp/v2 routes. A definition claiming one of these
	 * shadows a core endpoint, so it is rejected at save time.
	 *
	 * @return string[]
	 */
	public static function reserved_rest_bases() {
		return array(
			'posts',
			'pages',
			'media',
			'blocks',
			'templates',
			'template-parts',
			'navigation',
			'global-styles',
			'font-families',
			'font-faces',
			'menu-items',
			'menus',
			'menu-locations',
			'categories',
			'tags',
			'types',
			'statuses',
			'taxonomies',
			'users',
			'comments',
			'settings',
			'themes',
			'plugins',
			'search',
			'block-types',
			'block-patterns',
			'pattern-directory',
			'widgets',
			'widget-types',
			'sidebars',
			'blocks-renderer',
			'oembed',
		);
	}

	/**
	 * The URL slug a definition effectively occupies: its explicit rewrite_slug, or its type key, which is
	 * what WordPress falls back to when no slug is set.
	 *
	 * @param array  $meta Definition meta.
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return string
	 */
	private static function effective_rewrite_slug( $meta, $kind ) {
		$rewrite_on = ( 'cpt' === $kind )
			? ( ! isset( $meta['rewrite_enabled'] ) || (bool) $meta['rewrite_enabled'] )
			: ( ! isset( $meta['rewrite'] ) || (bool) $meta['rewrite'] );
		if ( ! $rewrite_on ) {
			return '';
		}
		if ( ! empty( $meta['rewrite_slug'] ) ) {
			return sanitize_title( (string) $meta['rewrite_slug'] );
		}
		$key_field = ( 'cpt' === $kind ) ? 'post_type_key' : 'taxonomy_key';
		return isset( $meta[ $key_field ] ) ? sanitize_key( (string) $meta[ $key_field ] ) : '';
	}

	/**
	 * The REST base a definition effectively occupies: its explicit rest_base, or its type key.
	 * Returns '' when the definition is not REST-exposed.
	 *
	 * @param array  $meta Definition meta.
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return string
	 */
	private static function effective_rest_base( $meta, $kind ) {
		// Absent key => derived from `public` in both register builders.
		$in_rest = isset( $meta['show_in_rest'] )
			? (bool) $meta['show_in_rest']
			: ( ! isset( $meta['public'] ) || (bool) $meta['public'] );
		if ( ! $in_rest ) {
			return '';
		}
		if ( ! empty( $meta['rest_base'] ) ) {
			return sanitize_title( (string) $meta['rest_base'] );
		}
		$key_field = ( 'cpt' === $kind ) ? 'post_type_key' : 'taxonomy_key';
		return isset( $meta[ $key_field ] ) ? sanitize_key( (string) $meta[ $key_field ] ) : '';
	}

	/**
	 * The other definition already occupying a rewrite slug or REST base, or '' when it is free.
	 *
	 * @param string $what            'rewrite_slug' or 'rest_base'.
	 * @param string $value           The slug / base being claimed.
	 * @param int    $exclude_post_id Definition being saved.
	 * @return string Conflicting definition's label/key, or '' if none.
	 */
	private static function conflicting_definition( $what, $value, $exclude_post_id = 0 ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$exclude_post_id = absint( $exclude_post_id );

		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows() as $row ) {
			if ( (int) $row['id'] === $exclude_post_id ) {
				continue;
			}
			$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
			if ( 'cpt' !== $kind && 'taxonomy' !== $kind ) {
				continue;
			}
			$meta  = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			$other = ( 'rest_base' === $what )
				? self::effective_rest_base( $meta, $kind )
				: self::effective_rewrite_slug( $meta, $kind );
			if ( '' !== $other && $other === $value ) {
				$key_field = ( 'cpt' === $kind ) ? 'post_type_key' : 'taxonomy_key';
				if ( ! empty( $meta['label'] ) ) {
					return (string) $meta['label'];
				}
				return isset( $meta[ $key_field ] ) ? (string) $meta[ $key_field ] : $kind;
			}
		}
		return '';
	}

	/**
	 * Reject a rewrite slug or REST base already claimed by another definition (or, for a REST base, by a core
	 * /wp/v2 route).
	 *
	 * @param array  $meta            Sanitized definition meta.
	 * @param string $kind            'cpt' or 'taxonomy'.
	 * @param int    $exclude_post_id Definition being saved.
	 * @return WP_Error|null
	 */
	private static function check_slug_conflicts( $meta, $kind, $exclude_post_id = 0 ) {
		// Only an explicitly-set value is rejected.
		if ( ! empty( $meta['rewrite_slug'] ) ) {
			$slug  = self::effective_rewrite_slug( $meta, $kind );
			$owner = self::conflicting_definition( 'rewrite_slug', $slug, $exclude_post_id );
			if ( '' !== $owner ) {
				return new WP_Error(
					'uich_ct_slug_conflict',
					sprintf(
						/* translators: 1: rewrite slug, 2: name of the conflicting content type */
						__( 'The permalink slug "%1$s" is already used by "%2$s". Two content types cannot share a slug — one of them would stop resolving.', 'uichemy' ),
						$slug,
						$owner
					),
					array( 'status' => 400 )
				);
			}
		}

		if ( ! empty( $meta['rest_base'] ) ) {
			$base = self::effective_rest_base( $meta, $kind );
			if ( '' !== $base && in_array( $base, self::reserved_rest_bases(), true ) ) {
				return new WP_Error(
					'uich_ct_reserved_rest_base',
					sprintf(
						/* translators: %s: the REST base */
						__( '"%s" is a REST base used by WordPress core and cannot be used.', 'uichemy' ),
						$base
					),
					array( 'status' => 400 )
				);
			}
			$owner = self::conflicting_definition( 'rest_base', $base, $exclude_post_id );
			if ( '' !== $owner ) {
				return new WP_Error(
					'uich_ct_rest_base_conflict',
					sprintf(
						/* translators: 1: REST base, 2: name of the conflicting content type */
						__( 'The REST base "%1$s" is already used by "%2$s". Two content types cannot share a REST base.', 'uichemy' ),
						$base,
						$owner
					),
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}

	/**
	 * Count content stored under a post type / taxonomy key, independent of whether the type is currently
	 * registered (so it works for a key about to be renamed or deleted).
	 *
	 * @param string $kind          'cpt' or 'taxonomy'.
	 * @param string $key           The post_type_key / taxonomy_key.
	 * @param bool   $include_trash Count trashed posts as well (CPT only).
	 * @return int
	 */
	public static function content_count_for_key( $kind, $key, $include_trash = false ) {
		global $wpdb;
		$key = (string) $key;
		if ( '' === $key ) {
			return 0;
		}
		if ( 'cpt' === $kind ) {
			if ( $include_trash ) {
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft'",
						$key
					)
				);
			}
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft' )",
					$key
				)
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$key
			)
		);
	}

	/**
	 * The stored key of an existing definition, or '' if none.
	 *
	 * @param int    $post_id   Definition post ID.
	 * @param string $meta_field 'post_type_key' or 'taxonomy_key'.
	 * @return string
	 */
	/**
	 * A definition's currently-stored meta, whichever storage it lives in.
	 *
	 * @param int $id Definition id.
	 * @return array Empty array when the definition cannot be resolved.
	 */
	private static function definition_meta( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return array();
		}
		$meta = self::model_definition_meta( $id );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * The key a definition is currently stored under.
	 *
	 * @param int    $post_id    Definition id.
	 * @param string $meta_field post_type_key | taxonomy_key | key.
	 * @return string
	 */
	private static function existing_definition_key( $post_id, $meta_field ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return '';
		}
		$meta = self::definition_meta( $post_id );
		return ( ! empty( $meta[ $meta_field ] ) ) ? (string) $meta[ $meta_field ] : '';
	}

	/**
	 * Find an existing definition's post ID by its key, for import upsert. Returns 0 when the
	 * key is empty or no match exists (so the caller creates a new definition).
	 *
	 * @param string $kind 'cpt' | 'taxonomy' | 'group_fields'.
	 * @param string $key  post_type_key / taxonomy_key / group key.
	 * @return int
	 */
	public static function find_definition_id_by_key( $kind, $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return 0;
		}
		$meta_field = ( 'cpt' === $kind ) ? 'post_type_key' : ( ( 'taxonomy' === $kind ) ? 'taxonomy_key' : 'key' );
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows() as $row ) {
			if ( $kind !== $row['kind'] ) {
				continue;
			}
			$meta = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( isset( $meta[ $meta_field ] ) && (string) $meta[ $meta_field ] === $key ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/**
	 * Batch import definitions with key-based upsert: an item whose key already exists updates the existing
	 * definition (latest wins); otherwise a new one is created.
	 *
	 * @param array    $items          Exported list rows: [ { kind, data, title? }, … ].
	 * @param bool     $dry_run        Classify every item (new / update / invalid) and write nothing.
	 * @param int|null $schema_version Schema version the file was exported with, when it says.
	 * @return array{created:int, updated:int, skipped:int, failed:int, results:array, errors:array, warnings:array}|WP_Error
	 */
	public static function import_definitions( $items, $dry_run = false, $schema_version = null ) {
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'uich_ct_import_invalid', __( 'Invalid import payload.', 'uichemy' ), array( 'status' => 400 ) );
		}

		$created  = 0;
		$updated  = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();
		$results  = array();
		$warnings = array();
		$new      = 0;
		$update   = 0;
		$invalid  = 0;

		// A file from a newer plugin may carry settings this one ignores; say so instead of importing it silently.
		$supported = Uich_Fields_Model_Paths::SCHEMA_VERSION;
		if ( null !== $schema_version && (int) $schema_version > $supported ) {
			$warnings[] = sprintf(
				/* translators: 1: schema version of the file, 2: schema version this plugin understands */
				__( 'This file uses schema version %1$d but this version of UiChemy understands version %2$d. Settings added by the newer version are ignored. Update the plugin first for a complete import.', 'uichemy' ),
				(int) $schema_version,
				$supported
			);
		}

		// Per-item processing continues past failures so one bad row never aborts the batch and leaves an
		// unreported partial import.
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				++$skipped;
				$message   = __( 'Skipped an invalid entry.', 'uichemy' );
				$errors[]  = array(
					'index'   => (int) $index,
					'message' => $message,
				);
				$results[] = array(
					'index'   => (int) $index,
					'kind'    => '',
					'key'     => '',
					'title'   => '',
					'status'  => 'skipped',
					'message' => $message,
				);
				continue;
			}

			$raw_kind = isset( $item['kind'] ) ? (string) $item['kind'] : 'cpt';
			$kind     = ( 'taxonomy' === $raw_kind ) ? 'taxonomy' : ( ( 'group_fields' === $raw_kind ) ? 'group_fields' : 'cpt' );
			// A kind this plugin does not know is not a post type: coercing it to one made the preview say "New"
			// for an item the import then failed.
			$unknown_kind = ! in_array( $raw_kind, array( 'cpt', 'taxonomy', 'group_fields' ), true );
			$data         = ( isset( $item['data'] ) && is_array( $item['data'] ) ) ? $item['data'] : array();
			$title    = isset( $item['title'] ) ? (string) $item['title'] : '';

			// Mirror the client's title → label/title fallback for list-row exports.
			if ( 'group_fields' === $kind && '' !== $title && '' === trim( (string) ( isset( $data['title'] ) ? $data['title'] : '' ) ) ) {
				$data['title'] = $title;
			}
			if ( ( 'cpt' === $kind || 'taxonomy' === $kind ) && '' !== $title && '' === trim( (string) ( isset( $data['label'] ) ? $data['label'] : '' ) ) ) {
				$data['label'] = $title;
			}

			$key_field = ( 'cpt' === $kind ) ? 'post_type_key' : ( ( 'taxonomy' === $kind ) ? 'taxonomy_key' : 'key' );
			$key       = isset( $data[ $key_field ] ) ? (string) $data[ $key_field ] : '';
			$existing  = self::find_definition_id_by_key( $kind, $key );

			// A human-readable label for the summary, falling back through the available fields.
			$label = '' !== $title
				? $title
				: ( '' !== trim( (string) ( isset( $data['title'] ) ? $data['title'] : '' ) )
					? (string) $data['title']
					: ( '' !== trim( (string) ( isset( $data['label'] ) ? $data['label'] : '' ) )
						? (string) $data['label']
						: $key ) );

			// The checks every item gets, in the preview and on write alike: kind, size, field types.
			$problem = self::import_item_problem( $raw_kind, $unknown_kind, $kind, $data );

			// $confirm = true: an import is one explicit, whole-file action the admin already committed to, and
			// stopping mid-batch on a deactivation prompt would leave a partial import with no way to answer it.
			if ( $problem ) {
				$res = $problem;
			} elseif ( 'taxonomy' === $kind ) {
				$res = self::save_taxonomy( $existing, $data, true, (bool) $dry_run );
			} elseif ( 'group_fields' === $kind ) {
				$res = $dry_run ? self::preview_field_group( $existing, $data ) : self::save_field_group( $existing, $data );
			} else {
				$res = self::save_cpt( $existing, $data, true, (bool) $dry_run );
			}

			if ( $dry_run ) {
				// Preview: classify only. Nothing above wrote anything.
				if ( is_wp_error( $res ) ) {
					++$invalid;
					$message = $res->get_error_message();
					$status  = 'invalid';
				} elseif ( $existing ) {
					++$update;
					$message = '';
					$status  = 'update';
				} else {
					++$new;
					$message = '';
					$status  = 'new';
				}
			} elseif ( is_wp_error( $res ) ) {
				++$failed;
				$message  = $res->get_error_message();
				$errors[] = array(
					'index'   => (int) $index,
					'message' => $message,
				);
				$status   = 'failed';
			} elseif ( $existing ) {
				++$updated;
				$message = '';
				$status  = 'updated';
			} else {
				++$created;
				$message = '';
				$status  = 'created';
			}

			$results[] = array(
				'index'   => (int) $index,
				'kind'    => $kind,
				'key'     => $key,
				'title'   => $label,
				'status'  => $status,
				'message' => $message,
			);
		}

		$out = array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'results'  => $results,
			// Retained for backward compatibility with existing clients.
			'errors'   => $errors,
			'warnings' => $warnings,
		);
		if ( $dry_run ) {
			$out['dry_run'] = true;
			$out['new']     = $new;
			$out['update']  = $update;
			$out['invalid'] = $invalid;
		}
		return $out;
	}

	/**
	 * Problems an import item has whatever the mode: an unknown kind, a definition too big to store,
	 * or a field of a type this plugin does not have.
	 *
	 * @param string $raw_kind     Kind as written in the file.
	 * @param bool   $unknown_kind Whether that kind is not one this plugin has.
	 * @param string $kind         cpt | taxonomy | group_fields.
	 * @param array  $data         Definition data.
	 * @return WP_Error|null
	 */
	private static function import_item_problem( $raw_kind, $unknown_kind, $kind, $data ) {
		if ( $unknown_kind ) {
			return new WP_Error(
				'uich_ct_import_kind',
				sprintf(
					/* translators: %s: the kind written in the file */
					__( 'Unknown kind "%s". Expected cpt, taxonomy or group_fields.', 'uichemy' ),
					$raw_kind
				),
				array( 'status' => 400 )
			);
		}

		$bytes = Uich_Fields_Model_Store::encoded_size( $data );
		if ( $bytes > Uich_Fields_Model_Store::MAX_BYTES ) {
			return Uich_Fields_Model_Store::too_large_error( $bytes );
		}

		if ( 'group_fields' === $kind && function_exists( 'uich_fields_creatable_field_types' ) ) {
			// The two types the picker no longer offers still load from older exports.
			$known   = array_merge( uich_fields_creatable_field_types(), array( 'password', 'gallery' ) );
			$unknown = array();
			self::collect_unknown_field_types( isset( $data['fields'] ) ? $data['fields'] : array(), $known, $unknown );
			if ( $unknown ) {
				return new WP_Error(
					'uich_ct_import_field_type',
					sprintf(
						/* translators: %s: comma-separated list of field types */
						_n( 'Unknown field type: %s.', 'Unknown field types: %s.', count( $unknown ), 'uichemy' ),
						implode( ', ', $unknown )
					),
					array( 'status' => 400 )
				);
			}
		}
		return null;
	}

	/**
	 * Field types a group uses that are not in $known, including inside repeaters.
	 *
	 * @param mixed    $fields  Field list.
	 * @param string[] $known   Accepted type slugs.
	 * @param string[] $unknown Collected unknown types (by reference).
	 * @return void
	 */
	private static function collect_unknown_field_types( $fields, $known, &$unknown ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? (string) $field['type'] : '';
			if ( '' !== $type && ! in_array( $type, $known, true ) && ! in_array( $type, $unknown, true ) ) {
				$unknown[] = $type;
			}
			if ( ! empty( $field['fields'] ) ) {
				self::collect_unknown_field_types( $field['fields'], $known, $unknown );
			}
		}
	}

	/**
	 * Every check save_field_group() runs before it writes, run on its own so an import preview can
	 * say what would be refused.
	 *
	 * @param int   $post_id Existing group id, or 0.
	 * @param array $data    Raw group data.
	 * @return true|WP_Error
	 */
	private static function preview_field_group( $post_id, $data ) {
		$meta   = self::sanitize_group_fields_meta( is_array( $data ) ? $data : array() );
		$stored = $post_id ? self::definition_meta( $post_id ) : array();

		$tier_error = uich_fields_assert_group_tier( $meta, is_array( $stored ) ? $stored : array() );
		if ( is_wp_error( $tier_error ) ) {
			return $tier_error;
		}
		if ( $post_id ) {
			$lock_error = self::assert_group_keys_not_renamed_with_data( $post_id, $meta );
			if ( is_wp_error( $lock_error ) ) {
				return $lock_error;
			}
		}
		// The key the save would settle on has to be a usable filename and meta-key segment.
		$final = self::finalize_group_meta( $meta, $post_id );
		return Uich_Fields_Model_Paths::validate_key( 'group_fields', isset( $final['key'] ) ? (string) $final['key'] : '' );
	}

	/**
	 * Guard against editing a definition through the wrong endpoint.
	 *
	 * @param int    $post_id       Target definition post ID (0 = new, always allowed).
	 * @param string $expected_kind 'cpt' | 'taxonomy' | 'group_fields'.
	 * @return WP_Error|null WP_Error on mismatch, otherwise null.
	 */
	private static function assert_definition_kind( $post_id, $expected_kind ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return null;
		}
		$meta = self::definition_meta( $post_id );
		$kind = ( ! empty( $meta['kind'] ) ) ? (string) $meta['kind'] : '';
		if ( '' !== $kind && $kind !== $expected_kind ) {
			return new WP_Error(
				'uich_ct_kind_mismatch',
				__( 'This item is a different content type and cannot be edited from here.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * Whether a key is reserved by WordPress (or by this plugin) and so cannot be used.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @param string $key  The post_type_key / taxonomy_key to check.
	 * @return bool
	 */
	private static function is_reserved_key( $kind, $key ) {
		return in_array( (string) $key, self::reserved_keys(), true );
	}

	/**
	 * WordPress reserved query vars / terms + core object types + FSE block types + this plugin's internal
	 * types.
	 *
	 * @return string[]
	 */
	public static function reserved_keys() {
		return array_merge( self::reserved_object_type_keys(), self::reserved_query_vars() );
	}

	/**
	 * Keys that name a real WordPress (or Uichemy) post type / taxonomy.
	 *
	 * @return string[]
	 */
	public static function reserved_object_type_keys() {
		return array(
			// Core post types and taxonomies.
			'post',
			'page',
			'attachment',
			'revision',
			'category',
			'post_tag',
			'post_format',
			'link_category',
			'nav_menu',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'page_template',
			// Full-Site-Editing / block types.
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
			'wp_font_family',
			'wp_font_face',
			// Core taxonomies that back the FSE/pattern system. These are only registered on some
			// requests, so taxonomy_exists() is not a reliable guard for them — block by name.
			'wp_theme',
			'wp_template_part_area',
			'wp_pattern_category',
			// This plugin's internal types.
			'uich_builder',
			'uich_content_types',
		);
	}

	/**
	 * WordPress public query variables.
	 *
	 * @return string[]
	 */
	public static function reserved_query_vars() {
		return array(
			'attachment_id',
			'author',
			'author_name',
			'calendar',
			'cat',
			'category__and',
			'category__in',
			'category__not_in',
			'category_name',
			'comments_per_page',
			'comments_popup',
			'cpage',
			'date',
			'day',
			'embed',
			'error',
			'exact',
			'feed',
			'fields',
			'hour',
			'm',
			'minute',
			'monthnum',
			'more',
			'name',
			'nopaging',
			'offset',
			'order',
			'orderby',
			'p',
			'page_id',
			'paged',
			'pagename',
			'pb',
			'perm',
			'post__in',
			'post__not_in',
			'post_mime_type',
			'post_status',
			'post_type',
			'posts',
			'posts_per_archive_page',
			'posts_per_page',
			'preview',
			'robots',
			'rest_route',
			's',
			'search',
			'second',
			'sentence',
			'showposts',
			'static',
			'status',
			'subpost',
			'subpost_id',
			'tag',
			'tag__and',
			'tag__in',
			'tag__not_in',
			'tag_id',
			'tag_slug__and',
			'tag_slug__in',
			'taxonomy',
			'tb',
			'term',
			'terms',
			'theme',
			'title',
			'type',
			'types',
			'w',
			'withcomments',
			'withoutcomments',
			'year',
		);
	}

	/**
	 * A usable `uich_`-prefixed replacement for a rejected key, or '' when even that would not pass.
	 *
	 * Public because the importer offers this as a one-click fix in its dry run; both callers must
	 * derive the same suggestion or the plan would propose a key the save then rejects.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @param string $key  The rejected key.
	 * @return string Suggested key, or '' when none is available.
	 */
	public static function suggest_available_key( $kind, $key ) {
		$key        = (string) $key;
		$max_len    = ( 'cpt' === $kind ) ? 20 : 32;
		$suggestion = 'uich_' . $key;

		if ( strlen( $suggestion ) > $max_len
			|| in_array( $suggestion, self::reserved_keys(), true )
			|| in_array( $suggestion, self::reserved_object_type_keys(), true )
			|| self::key_in_use( $kind, $suggestion, 0, true )
			|| ( 'cpt' === $kind ? post_type_exists( $suggestion ) : taxonomy_exists( $suggestion ) )
		) {
			return '';
		}
		return $suggestion;
	}

	/**
	 * Whether a key is refused outright because WordPress or this plugin reserves it.
	 *
	 * @param string $key Candidate key.
	 * @return string 'object_type', 'query_var', or '' when the key is not reserved.
	 */
	public static function reserved_key_reason( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::reserved_object_type_keys(), true ) ) {
			return 'object_type';
		}
		if ( in_array( $key, self::reserved_keys(), true ) ) {
			return 'query_var';
		}
		return '';
	}

	/**
	 * Build the save-time error for a reserved key, naming the actual reason and offering a usable
	 * alternative.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @param string $key  The rejected key.
	 * @return WP_Error
	 */
	private static function reserved_key_error( $kind, $key ) {
		$key        = (string) $key;
		$is_type    = in_array( $key, self::reserved_object_type_keys(), true );
		$suggestion = self::suggest_available_key( $kind, $key );

		if ( $is_type ) {
			$message = ( 'cpt' === $kind )
				/* translators: %s: reserved post type key */
				? sprintf( __( '"%s" is already a WordPress post type or taxonomy, so it cannot be used as a post type key.', 'uichemy' ), $key )
				/* translators: %s: reserved taxonomy key */
				: sprintf( __( '"%s" is already a WordPress post type or taxonomy, so it cannot be used as a taxonomy key.', 'uichemy' ), $key );
		} else {
			$message = ( 'cpt' === $kind )
				/* translators: %s: reserved query variable used as a post type key */
				? sprintf( __( '"%s" is a reserved WordPress query variable. Using it as a post type key would break URL handling across the whole site.', 'uichemy' ), $key )
				/* translators: %s: reserved query variable used as a taxonomy key */
				: sprintf( __( '"%s" is a reserved WordPress query variable. Using it as a taxonomy key would break URL handling across the whole site.', 'uichemy' ), $key );
		}

		if ( '' !== $suggestion ) {
			/* translators: %s: suggested alternative key, e.g. uich_status */
			$message .= ' ' . sprintf( __( 'Try "%s" instead.', 'uichemy' ), $suggestion );
		}

		return new WP_Error(
			'uich_ct_reserved',
			$message,
			array(
				'status'     => 400,
				'key'        => $key,
				'reason'     => $is_type ? 'object_type' : 'query_var',
				'suggestion' => $suggestion,
			)
		);
	}

	/**
	 * Keys of published Uichemy definitions of a kind (cpt/taxonomy). Used so cross-references to a
	 * type this plugin owns but that isn't registered yet (registration ordering, MB-42) still validate.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return string[]
	 */
	private static function pending_definition_keys( $kind ) {
		$field = ( 'cpt' === $kind ) ? 'post_type_key' : 'taxonomy_key';
		$keys  = array();
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows() as $row ) {
			if ( $kind !== $row['kind'] || 'publish' !== $row['status'] ) {
				continue;
			}
			$m = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( ! empty( $m[ $field ] ) ) {
				$keys[] = (string) $m[ $field ];
			}
		}
		return $keys;
	}

	/**
	 * Whether a post type is registered OR pending as a Uichemy CPT definition.
	 *
	 * @param string $pt Post type key.
	 * @return bool
	 */
	private static function known_or_pending_post_type( $pt ) {
		$pt = (string) $pt;
		if ( '' === $pt ) {
			return false;
		}
		return post_type_exists( $pt ) || in_array( $pt, self::pending_definition_keys( 'cpt' ), true );
	}

	/**
	 * Whether a taxonomy is registered OR pending as a Uichemy taxonomy definition.
	 *
	 * @param string $tax Taxonomy key.
	 * @return bool
	 */
	private static function known_or_pending_taxonomy( $tax ) {
		$tax = (string) $tax;
		if ( '' === $tax ) {
			return false;
		}
		return taxonomy_exists( $tax ) || in_array( $tax, self::pending_definition_keys( 'taxonomy' ), true );
	}

	/**
	 * Reject a query_var / rewrite slug that collides with a reserved WordPress query var.
	 *
	 * @param array $meta Sanitized definition meta.
	 * @return WP_Error|null WP_Error if a reserved var is used, otherwise null.
	 */
	private static function check_reserved_vars( $meta ) {
		foreach ( array( 'query_var_string', 'rewrite_slug' ) as $vk ) {
			if ( empty( $meta[ $vk ] ) ) {
				continue;
			}
			if ( in_array( sanitize_title( (string) $meta[ $vk ] ), self::reserved_keys(), true ) ) {
				return new WP_Error(
					'uich_ct_reserved_var',
					sprintf(
						/* translators: 1: the reserved value, 2: the field it was used in */
						__( '"%1$s" is a reserved WordPress query variable and cannot be used for %2$s.', 'uichemy' ),
						$meta[ $vk ],
						str_replace( '_', ' ', $vk )
					),
					array( 'status' => 400 )
				);
			}
		}
		return null;
	}

	/**
	 * Grant a definition's custom capabilities to the administrator role.
	 *
	 * @param array $meta Sanitized definition meta.
	 */
	private static function grant_custom_caps_to_admin( $meta ) {
		if ( empty( $meta['capabilities'] ) || ! is_array( $meta['capabilities'] ) ) {
			return;
		}
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		$non_grantable = self::non_grantable_capabilities();
		$granted       = self::granted_caps();
		foreach ( $meta['capabilities'] as $cap ) {
			// sanitize_key to match registration; a looser normalizer would grant a different cap.
			$cap = sanitize_key( (string) $cap );
			if ( '' === $cap || $admin->has_cap( $cap ) ) {
				continue;
			}
			// Never auto-grant a privileged core/network capability onto the administrator role from stored
			// definition data.
			if ( in_array( $cap, $non_grantable, true ) ) {
				continue;
			}
			$admin->add_cap( $cap );
			$granted[ $cap ] = true;
		}
		update_option( self::GRANTED_CAPS_OPTION, $granted, false );
	}

	/**
	 * Option holding the capabilities this module itself added to the administrator role.
	 *
	 * Revocation only ever removes caps recorded here: a definition may map to a capability the
	 * administrator already had (manage_options, edit_pages…), and stripping that on delete would
	 * lock every administrator out of the site.
	 */
	const GRANTED_CAPS_OPTION = 'uich_fields_granted_admin_caps';

	/**
	 * Capabilities this module added to the administrator role, as a set.
	 *
	 * @return array<string, true>
	 */
	private static function granted_caps() {
		$granted = get_option( self::GRANTED_CAPS_OPTION, array() );
		return is_array( $granted ) ? $granted : array();
	}

	/**
	 * Capabilities that must never be auto-granted to the administrator role when registering a content type.
	 *
	 * @return string[] Lowercase capability slugs.
	 */
	private static function non_grantable_capabilities() {
		$caps = array(
			'manage_options',
			'edit_users',
			'create_users',
			'delete_users',
			'list_users',
			'promote_users',
			'remove_users',
			'add_users',
			'unfiltered_html',
			'unfiltered_upload',
			'edit_files',
			'edit_plugins',
			'edit_themes',
			'install_plugins',
			'activate_plugins',
			'update_plugins',
			'delete_plugins',
			'install_themes',
			'switch_themes',
			'update_core',
			'manage_network',
			'manage_sites',
			'manage_network_users',
			'manage_network_plugins',
			'manage_network_themes',
			'manage_network_options',
			'setup_network',
			// Network caps core reads straight from the role (network/site-new.php checks only
			// create_sites), so granting one would hand a non-super-admin network powers.
			'create_sites',
			'delete_sites',
			'delete_site',
			'upgrade_network',
			'update_themes',
			'delete_themes',
			'edit_theme_options',
			'update_languages',
			'install_languages',
			'export',
			'import',
		);
		/**
		 * Filter the capabilities that can never be auto-granted to the administrator role
		 * during content-type registration.
		 *
		 * @param string[] $caps Capability slugs to refuse.
		 */
		$caps = apply_filters( 'uich_fields_non_grantable_capabilities', $caps );
		return array_map( 'sanitize_key', (array) $caps );
	}

	/**
	 * Revoke a deleted definition's custom capabilities from the administrator role.
	 * Only caps this module granted (see GRANTED_CAPS_OPTION) are ever removed; standard WP caps
	 * and caps still used by another definition are skipped.
	 *
	 * @param array $meta            Meta of the definition being removed.
	 * @param int   $exclude_post_id Id of the definition being removed. Its own capabilities are
	 *                               still readable at this point, so they are excluded from the
	 *                               in-use check rather than counted as another definition's.
	 */
	public static function revoke_custom_caps_for_definition( $meta, $exclude_post_id = 0 ) {
		if ( empty( $meta['capabilities'] ) || ! is_array( $meta['capabilities'] ) ) {
			return;
		}
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		$exclude_post_id = absint( $exclude_post_id );

		// Standard WP caps that must never be stripped from the administrator.
		$core_caps = array(
			'read',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_private_posts',
			'delete_published_posts',
			'delete_others_posts',
			'edit_post',
			'read_post',
			'delete_post',
			'manage_categories',
			'manage_terms',
			'edit_terms',
			'delete_terms',
			'assign_terms',
			'upload_files',
		);

		// Caps still referenced by any OTHER definition, in ANY status.
		$still_used = array();
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows( array( 'include_trash' => true ) ) as $row ) {
			if ( (int) $row['id'] === $exclude_post_id ) {
				continue;
			}
			$m = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( ! empty( $m['capabilities'] ) && is_array( $m['capabilities'] ) ) {
				foreach ( $m['capabilities'] as $c ) {
					$still_used[ (string) $c ] = true;
				}
			}
		}

		$granted       = self::granted_caps();
		$non_grantable = self::non_grantable_capabilities();
		$changed       = false;
		foreach ( $meta['capabilities'] as $cap ) {
			// sanitize_key to match grant_custom_caps_to_admin(), so a cap that was granted is
			// findable here and actually gets revoked.
			$cap = sanitize_key( (string) $cap );
			if ( '' === $cap || in_array( $cap, $core_caps, true ) || in_array( $cap, $non_grantable, true ) || isset( $still_used[ $cap ] ) ) {
				continue;
			}
			// Never remove a capability the administrator had before this module touched the role.
			if ( empty( $granted[ $cap ] ) ) {
				continue;
			}
			if ( $admin->has_cap( $cap ) ) {
				$admin->remove_cap( $cap );
			}
			unset( $granted[ $cap ] );
			$changed = true;
		}
		if ( $changed ) {
			update_option( self::GRANTED_CAPS_OPTION, $granted, false );
		}
	}

	/**
	 * WordPress core post-type "supports" feature slugs. Used to reject junk/typo values at save
	 * time instead of silently storing a slug that register_post_type() will ignore forever.
	 *
	 * @return string[]
	 */
	public static function known_supports_slugs() {
		return array(
			'title',
			'editor',
			'author',
			'thumbnail',
			'excerpt',
			'trackbacks',
			'custom-fields',
			'comments',
			'revisions',
			'page-attributes',
			'post-formats',
		);
	}

	/**
	 * Validate a CPT supports list against known WP core slugs, plus this plugin's other CPT keys.
	 * One Uichemy CPT may legitimately declare another.
	 *
	 * @param array $supports List of supports slugs.
	 * @return WP_Error|null WP_Error listing invalid slugs, otherwise null.
	 */
	private static function validate_supports( $supports ) {
		// Custom "supports" values are allowed (the CPT builder has an "Add Custom" affordance, and
		// register_post_type() accepts arbitrary feature strings via add_post_type_support()).

		/**
		 * Filters the validation of a post type's `supports` list.
		 *
		 * @since 5.2.6
		 *
		 * @param WP_Error|null $error    Null to accept, WP_Error to refuse the save.
		 * @param array         $supports Sanitized supports slugs.
		 */
		$error = apply_filters( 'uich/validate_cpt_supports', null, (array) $supports );

		return ( $error instanceof WP_Error ) ? $error : null;
	}

	/**
	 * Flag supports slugs that look like misspelled core features, without blocking the save.
	 *
	 * @param array $supports Sanitized supports list.
	 * @return string[] Warning messages, empty when nothing looks wrong.
	 */
	private static function supports_typo_warnings( $supports ) {
		if ( empty( $supports ) || ! is_array( $supports ) ) {
			return array();
		}
		$core     = self::known_supports_slugs();
		$warnings = array();

		foreach ( $supports as $slug ) {
			$slug = (string) $slug;
			// Short slugs produce too many false positives at a distance of 2.
			if ( '' === $slug || in_array( $slug, $core, true ) || strlen( $slug ) < 4 ) {
				continue;
			}
			$closest  = '';
			$distance = PHP_INT_MAX;
			foreach ( $core as $candidate ) {
				$d = levenshtein( $slug, $candidate );
				if ( $d < $distance ) {
					$distance = $d;
					$closest  = $candidate;
				}
			}
			if ( $distance > 0 && $distance <= 2 ) {
				$warnings[] = sprintf(
					/* translators: 1: the supports slug entered, 2: the core slug it resembles */
					__( 'Saved, but the feature "%1$s" is not a WordPress feature and looks like a typo of "%2$s". It was kept as a custom feature — rename it if that was not intended.', 'uichemy' ),
					$slug,
					$closest
				);
			}
		}

		return $warnings;
	}

	/**
	 * Whether a string is a syntactically valid (optionally namespaced) PHP class name.
	 *
	 * @param string $value Candidate class name.
	 * @return bool
	 */
	public static function is_valid_class_name( $value ) {
		return (bool) preg_match(
			'#^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$#',
			(string) $value
		);
	}

	/**
	 * Check a REST controller class name at save time.
	 *
	 * @param string $class Stored class name.
	 * @return WP_Error|string|null WP_Error when malformed, a warning string when unusable, else null.
	 */
	private static function check_rest_controller_class( $class ) {
		$class = trim( (string) $class );
		if ( '' === $class ) {
			return null;
		}

		if ( ! self::is_valid_class_name( $class ) ) {
			return new WP_Error(
				'uich_ct_invalid_rest_controller',
				sprintf(
					/* translators: %s: the value entered as a REST controller class */
					__( '"%s" is not a valid PHP class name, so it cannot be used as a REST controller class.', 'uichemy' ),
					$class
				),
				array( 'status' => 400 )
			);
		}

		$resolved = ltrim( $class, '\\' );
		if ( ! class_exists( $resolved ) ) {
			return sprintf(
				/* translators: %s: the REST controller class name that could not be found */
				__( 'Saved, but the REST controller class "%s" could not be found, so WordPress\'s default controller is used instead. Check the spelling, or make sure the plugin that defines it is active.', 'uichemy' ),
				$class
			);
		}
		if ( ! is_subclass_of( $resolved, 'WP_REST_Controller' ) ) {
			return sprintf(
				/* translators: %s: the REST controller class name that is not a WP_REST_Controller */
				__( 'Saved, but the REST controller class "%s" does not extend WP_REST_Controller, so WordPress\'s default controller is used instead.', 'uichemy' ),
				$class
			);
		}

		return null;
	}

	/**
	 * Validate a menu_icon: dashicons-*, a base64 SVG data URI, or an http(s) URL.
	 * Anything else is rejected, since register_post_type() embeds this value unescaped.
	 *
	 * @param string $value menu_icon raw value.
	 * @return bool
	 */
	private static function is_valid_menu_icon( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return true;
		}
		if ( 0 === strpos( $value, 'dashicons-' ) ) {
			return true;
		}
		if ( preg_match( '#^data:image/svg\+xml;base64,[a-zA-Z0-9+/]+=*$#', $value ) ) {
			return true;
		}
		if ( wp_http_validate_url( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Create or update a definition post and its meta as a single unit.
	 * On create, the meta write is verified and the post rolled back if it did not land.
	 *
	 * @param int   $post_id   Existing definition post ID, or 0 to create.
	 * @param array $post_args wp_insert_post()/wp_update_post() args (without 'ID' for create).
	 * @param array $meta      Sanitized meta to store under Uich_Fields_CPT::get_meta_key().
	 * @return int|WP_Error Post ID on success.
	 */
	private static function persist_definition_post( $post_id, $post_args, $meta ) {
		$kind = ( isset( $meta['kind'] ) && 'taxonomy' === $meta['kind'] ) ? 'tax' : 'cpt';

		// The title travels as a separate argument because it is the definition's display name and a
		// file, unlike a post, has no title of its own to fall back on.
		return Uich_Fields_Model_Writer::save(
			$kind,
			$meta,
			absint( $post_id ),
			isset( $post_args['post_title'] ) ? (string) $post_args['post_title'] : ''
		);
	}

	/**
	 * Create or update CPT definition.
	 *
	 * @param int   $post_id Post ID or 0 for new.
	 * @param array $data    Raw meta payload.
	 * @param bool  $confirm Caller has acknowledged a destructive change (deactivating a post type
	 *                       that still has posts).
	 * @param bool  $dry_run Run every check and report, but persist nothing.
	 * @return array|WP_Error Success: { id, message }.
	 */
	public static function save_cpt( $post_id, $data, $confirm = false, $dry_run = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$kind_error = self::assert_definition_kind( $post_id, 'cpt' );
		if ( is_wp_error( $kind_error ) ) {
			return $kind_error;
		}

		$meta = self::sanitize_meta( $data, 'cpt' );

		// Drop taxonomy associations to taxonomies that neither exist nor are pending Uichemy taxonomies, so a
		// stale reference can't be silently kept as a broken association.
		$controller_check = self::check_rest_controller_class(
			isset( $meta['rest_controller_class'] ) ? $meta['rest_controller_class'] : ''
		);
		if ( is_wp_error( $controller_check ) ) {
			return $controller_check;
		}

		$dropped_taxonomies = array();
		if ( isset( $meta['taxonomies'] ) && is_array( $meta['taxonomies'] ) ) {
			$requested          = $meta['taxonomies'];
			$meta['taxonomies'] = array_values( array_filter( $requested, array( __CLASS__, 'known_or_pending_taxonomy' ) ) );
			$dropped_taxonomies = array_values( array_diff( $requested, $meta['taxonomies'] ) );
		}

		if ( empty( $meta['post_type_key'] ) || empty( $meta['label'] ) ) {
			return new WP_Error(
				'uich_ct_invalid',
				__( 'Post type key and label are required.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		$supports_error = self::validate_supports( isset( $meta['supports'] ) ? $meta['supports'] : array() );
		if ( is_wp_error( $supports_error ) ) {
			return $supports_error;
		}

		if ( ! empty( $meta['menu_icon'] ) && ! self::is_valid_menu_icon( $meta['menu_icon'] ) ) {
			return new WP_Error(
				'uich_ct_invalid_menu_icon',
				__( 'Menu icon must be a dashicons- class, an SVG data URI, or an http(s) URL.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		// register_post_type() requires a key of 1–20 characters; a longer key is
		// stored fine but silently never registers. Reject it at save time.
		if ( strlen( (string) $meta['post_type_key'] ) > 20 ) {
			return new WP_Error(
				'uich_ct_key_too_long',
				__( 'Post type key must be 20 characters or fewer.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		$post_id = absint( $post_id );
		$title   = $meta['label'];
		$old_key = $post_id ? self::existing_definition_key( $post_id, 'post_type_key' ) : '';

		if ( self::is_reserved_key( 'cpt', $meta['post_type_key'] ) ) {
			return self::reserved_key_error( 'cpt', $meta['post_type_key'] );
		}

		// A trashed definition still owns its key: letting a new one take it meant trashing the new
		// one later had to overwrite the older trashed file. The definition's own current key is
		// exempt so a site already holding such a pair can still edit the live one.
		if ( self::key_in_use( 'cpt', $meta['post_type_key'], $post_id, $meta['post_type_key'] !== $old_key ) ) {
			return new WP_Error(
				'uich_ct_duplicate',
				__( 'A post type with this key already exists (check the Trash too).', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		// Third-party collision: a key already registered by core or another plugin.
		if ( $meta['post_type_key'] !== $old_key && post_type_exists( $meta['post_type_key'] )
			&& ! self::is_source_owned_key( 'cpt', $meta['post_type_key'] ) ) {
			return new WP_Error(
				'uich_ct_exists',
				sprintf(
					/* translators: %s: post type key */
					__( 'The post type key "%s" is already registered by WordPress or another plugin.', 'uichemy' ),
					$meta['post_type_key']
				),
				array( 'status' => 400 )
			);
		}

		// query_var / rewrite slug must not collide with a reserved WP query var.
		$reserved_var_error = self::check_reserved_vars( $meta );
		if ( is_wp_error( $reserved_var_error ) ) {
			return $reserved_var_error;
		}

		// Permalink slug / REST base must not already be claimed by another content type.
		$slug_conflict = self::check_slug_conflicts( $meta, 'cpt', $post_id );
		if ( is_wp_error( $slug_conflict ) ) {
			return $slug_conflict;
		}

		// Deactivating unregisters the post type, leaving its posts invisible everywhere — the same
		// damage the rename lock below and the delete guard both protect against.
		if ( $post_id && '' !== $old_key ) {
			$stored_meta = self::definition_meta( $post_id );
			$was_active  = ! $stored_meta || ! isset( $stored_meta['active'] ) || (bool) $stored_meta['active'];
			$now_active  = ! isset( $meta['active'] ) || (bool) $meta['active'];
			if ( $was_active && ! $now_active && ! $confirm ) {
				$count = self::content_count_for_key( 'cpt', $old_key );
				if ( $count > 0 ) {
					return new WP_Error(
						'uich_ct_deactivate_confirm_required',
						sprintf(
							/* translators: 1: number of posts, 2: post type key */
							_n(
								'Deactivating this post type will hide %1$d post stored under "%2$s". The post stays in the database but becomes inaccessible while the post type is inactive. Confirm to proceed.',
								'Deactivating this post type will hide %1$d posts stored under "%2$s". The posts stay in the database but become inaccessible while the post type is inactive. Confirm to proceed.',
								$count,
								'uichemy'
							),
							$count,
							$old_key
						),
						array(
							'status'                => 409,
							'requires_confirmation' => true,
							'content_count'         => $count,
							'kind'                  => 'cpt',
						)
					);
				}
			}
		}

		// Lock the key once content exists: renaming would deregister the old post type and
		// orphan its posts (rows under an unregistered type, invisible everywhere).
		if ( $post_id ) {
			// include_trash: trashed posts count here.
			$count = ( '' !== $old_key && $old_key !== $meta['post_type_key'] )
				? self::content_count_for_key( 'cpt', $old_key, true )
				: 0;
			if ( $count > 0 ) {
				return new WP_Error(
					'uich_ct_key_locked',
					sprintf(
						/* translators: 1: number of posts, 2: current post type key */
						_n(
							'The post type key cannot be changed: %1$d post (including trashed posts) is stored under "%2$s". Renaming it would strand that content under a post type that no longer exists.',
							'The post type key cannot be changed: %1$d posts (including trashed posts) are stored under "%2$s". Renaming it would strand that content under a post type that no longer exists.',
							$count,
							'uichemy'
						),
						$count,
						$old_key
					),
					array(
						'status'        => 400,
						'key'           => $old_key,
						'content_count' => $count,
					)
				);
			}
		}

		if ( $post_id ) {
			// The id names a definition in the model. There is no post to look up.
			$found = Uich_Fields_Model_Writer::lookup( $post_id );
			if ( ! $found ) {
				return new WP_Error(
					'uich_ct_invalid_post',
					__( 'Invalid post.', 'uichemy' ),
					array( 'status' => 400 )
				);
			}
		}

		// The dry run stops HERE, immediately before the first write, so every check above has
		// already run against this exact payload — capability, kind, sanitisation, required keys,
		// the key-length and reserved-key rules and the rename lock. A preview that stopped any
		// earlier would report a pass the real save then refuses, which is worse than no preview.
		if ( $dry_run ) {
			return array(
				'success' => true,
				'dry_run' => true,
				'id'      => (int) $post_id,
				'would'   => $post_id ? 'update' : 'create',
				'kind'    => 'cpt',
				'key'     => isset( $meta['post_type_key'] ) ? (string) $meta['post_type_key'] : '',
				'dropped_taxonomies' => $dropped_taxonomies,
				'message' => __( 'Dry run: every check passed and nothing was saved. Re-send without dry_run to apply it.', 'uichemy' ),
			);
		}

		// On update, omit post_status so wp_update_post() leaves a deliberately trashed definition trashed.
		$post_args = $post_id
			? array( 'post_title' => $title )
			: array(
				'post_type'   => Uich_Fields_CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			);

		$result = self::persist_definition_post( $post_id, $post_args, $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = $result;

		// Guarantee the administrator can always reach a type that uses custom capabilities.
		self::grant_custom_caps_to_admin( $meta );

		if ( class_exists( 'Uich_Fields_Register' ) ) {
			Uich_Fields_Register::get_instance()->flush_cache();
		}

		/**
		 * Fires after a CPT or taxonomy definition is saved.
		 *
		 * @param int    $post_id Definition post ID.
		 * @param string $kind    'cpt' or 'taxonomy'.
		 * @param array  $meta    Sanitized definition meta.
		 */
		do_action( 'uich/definition_saved', $post_id, isset( $meta['kind'] ) ? $meta['kind'] : '', $meta );

		$out = array(
			'id'      => $post_id,
			'message' => __( 'Saved.', 'uichemy' ),
		);

		// Report configuration that was stored but will NOT take effect, instead of accepting it silently.
		$warnings = array();
		if ( empty( $meta['map_meta_cap'] ) && ! empty( $meta['capabilities'] ) ) {
			$warnings[] = __( 'Saved, but the custom capability mappings are not being applied — they require Map Meta Cap to be on. Turn Map Meta Cap on for them to take effect.', 'uichemy' );
		}
		if ( is_string( $controller_check ) && '' !== $controller_check ) {
			$warnings[] = $controller_check;
		}
		// An empty supports list is now honoured literally (register_post_type() gets false rather than an empty
		// array, which core would have turned back into title+editor).
		if ( array_key_exists( 'supports', $meta ) && is_array( $meta['supports'] ) && empty( $meta['supports'] ) ) {
			$warnings[] = __( 'Saved with no features enabled, so this post type has no title, editor or other editing fields. Enable at least Title under Supports if you want to edit its content in the admin.', 'uichemy' );
		}
		if ( ! empty( $meta['supports'] ) ) {
			$warnings = array_merge( $warnings, self::supports_typo_warnings( $meta['supports'] ) );
		}
		// A trashed definition no longer blocks this key (F-03), but the admin should know it exists:
		// restoring it later will be refused and it will come back as a draft instead.
		$trashed_owner = self::definition_title_for_key( 'cpt', $meta['post_type_key'], $post_id, true, true );
		if ( '' !== $trashed_owner ) {
			$warnings[] = sprintf(
				/* translators: 1: post type key, 2: name of the trashed content type holding it */
				__( 'A deleted post type "%2$s" also uses the key "%1$s". This one takes over the key; restoring the deleted one will keep it inactive instead.', 'uichemy' ),
				$meta['post_type_key'],
				$trashed_owner
			);
		}
		if ( ! empty( $dropped_taxonomies ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated list of taxonomy keys that were not attached. */
				_n(
					'Saved, but the taxonomy "%s" was not attached because no taxonomy with that key exists.',
					'Saved, but these taxonomies were not attached because no taxonomy with those keys exists: %s.',
					count( $dropped_taxonomies ),
					'uichemy'
				),
				implode( ', ', $dropped_taxonomies )
			);
		}
		if ( ! empty( $warnings ) ) {
			$out['warnings'] = $warnings;
		}

		return $out;
	}

	/**
	 * Create or update Taxonomy definition.
	 *
	 * @param int   $post_id Post ID or 0 for new.
	 * @param array $data    Raw meta payload.
	 * @param bool  $confirm Caller has acknowledged a destructive change (deactivating a taxonomy
	 *                       that still has terms).
	 * @return array|WP_Error Success: { id, message, warnings }.
	 */
	public static function save_taxonomy( $post_id, $data, $confirm = false, $dry_run = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$kind_error = self::assert_definition_kind( $post_id, 'taxonomy' );
		if ( is_wp_error( $kind_error ) ) {
			return $kind_error;
		}

		$meta = self::sanitize_meta( $data, 'taxonomy' );

		// A new taxonomy follows Public for REST, as a post type does; an update keeps what is stored,
		// so taxonomies saved before this stay as they are.
		if ( ! absint( $post_id ) && ! array_key_exists( 'show_in_rest', $meta ) ) {
			$meta['show_in_rest'] = ! isset( $meta['public'] ) || (bool) $meta['public'];
		}

		if ( empty( $meta['taxonomy_key'] ) || empty( $meta['label'] ) ) {
			return new WP_Error(
				'uich_ct_invalid',
				__( 'Taxonomy key and label are required.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		// Reject binding to post types that neither exist nor are pending Uichemy CPTs — otherwise
		// the taxonomy attaches to a phantom object type (orphan association).
		$object_types = ( isset( $meta['object_type'] ) && is_array( $meta['object_type'] ) ) ? $meta['object_type'] : array();
		$unknown_ot   = array();
		foreach ( $object_types as $ot ) {
			if ( ! self::known_or_pending_post_type( $ot ) ) {
				$unknown_ot[] = (string) $ot;
			}
		}
		if ( ! empty( $unknown_ot ) ) {
			return new WP_Error(
				'uich_ct_unknown_object_type',
				sprintf(
					/* translators: %s: comma-separated list of unknown post type keys */
					__( 'Unknown post type(s) for this taxonomy: %s', 'uichemy' ),
					implode( ', ', $unknown_ot )
				),
				array( 'status' => 400 )
			);
		}

		// register_taxonomy() requires a key of 1–32 characters; a longer key is
		// stored fine but silently never registers. Reject it at save time.
		if ( strlen( (string) $meta['taxonomy_key'] ) > 32 ) {
			return new WP_Error(
				'uich_ct_key_too_long',
				__( 'Taxonomy key must be 32 characters or fewer.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		// An enabled but unnamed default term is discarded downstream, so reject it here instead of failing silently.
		if ( ! empty( $meta['default_term_enabled'] ) ) {
			$default_term_name = ( isset( $meta['default_term']['name'] ) ) ? trim( (string) $meta['default_term']['name'] ) : '';
			if ( '' === $default_term_name ) {
				return new WP_Error(
					'uich_ct_default_term_invalid',
					__( 'Enter a name for the default term, or switch the default term off.', 'uichemy' ),
					array( 'status' => 400 )
				);
			}
		}

		$post_id = absint( $post_id );
		$title   = $meta['label'];
		$old_key = $post_id ? self::existing_definition_key( $post_id, 'taxonomy_key' ) : '';

		if ( self::is_reserved_key( 'taxonomy', $meta['taxonomy_key'] ) ) {
			return self::reserved_key_error( 'taxonomy', $meta['taxonomy_key'] );
		}

		// A trashed definition still owns its key: letting a new one take it meant trashing the new
		// one later had to overwrite the older trashed file. The definition's own current key is
		// exempt so a site already holding such a pair can still edit the live one.
		if ( self::key_in_use( 'taxonomy', $meta['taxonomy_key'], $post_id, $meta['taxonomy_key'] !== $old_key ) ) {
			return new WP_Error(
				'uich_ct_duplicate',
				__( 'A taxonomy with this key already exists (check the Trash too).', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		// Third-party collision: a key already registered by core or another plugin.
		if ( $meta['taxonomy_key'] !== $old_key && taxonomy_exists( $meta['taxonomy_key'] )
			&& ! self::is_source_owned_key( 'taxonomy', $meta['taxonomy_key'] ) ) {
			return new WP_Error(
				'uich_ct_exists',
				sprintf(
					/* translators: %s: taxonomy key */
					__( 'The taxonomy key "%s" is already registered by WordPress or another plugin.', 'uichemy' ),
					$meta['taxonomy_key']
				),
				array( 'status' => 400 )
			);
		}

		// query_var / rewrite slug must not collide with a reserved WP query var.
		$reserved_var_error = self::check_reserved_vars( $meta );
		if ( is_wp_error( $reserved_var_error ) ) {
			return $reserved_var_error;
		}

		// Permalink slug / REST base must not already be claimed by another content type.
		$slug_conflict = self::check_slug_conflicts( $meta, 'taxonomy', $post_id );
		if ( is_wp_error( $slug_conflict ) ) {
			return $slug_conflict;
		}

		// Deactivating unregisters the taxonomy, which leaves every existing term unreachable ("Invalid
		// taxonomy") — the same damage the rename lock below and the delete guard both protect against.
		if ( $post_id && '' !== $old_key ) {
			$stored_meta = self::definition_meta( $post_id );
			$was_active  = ! $stored_meta || ! isset( $stored_meta['active'] ) || (bool) $stored_meta['active'];
			$now_active  = ! isset( $meta['active'] ) || (bool) $meta['active'];
			if ( $was_active && ! $now_active && ! $confirm ) {
				$count = self::content_count_for_key( 'taxonomy', $old_key );
				if ( $count > 0 ) {
					return new WP_Error(
						'uich_ct_deactivate_confirm_required',
						sprintf(
							/* translators: 1: number of terms, 2: taxonomy key */
							_n(
								'Deactivating this taxonomy will hide %1$d term stored under "%2$s". The term stays in the database but becomes inaccessible while the taxonomy is inactive. Confirm to proceed.',
								'Deactivating this taxonomy will hide %1$d terms stored under "%2$s". The terms stay in the database but become inaccessible while the taxonomy is inactive. Confirm to proceed.',
								$count,
								'uichemy'
							),
							$count,
							$old_key
						),
						array(
							'status'                => 409,
							'requires_confirmation' => true,
							'content_count'         => $count,
							'kind'                  => 'taxonomy',
						)
					);
				}
			}
		}

		// Lock the key once terms exist: renaming deregisters the old taxonomy and leaves its
		// wp_term_taxonomy rows orphaned (get_term() then returns "Invalid taxonomy").
		if ( $post_id ) {
			// Count once and reuse — same double-query issue as the CPT rename lock (F-21).
			$count = ( '' !== $old_key && $old_key !== $meta['taxonomy_key'] )
				? self::content_count_for_key( 'taxonomy', $old_key )
				: 0;
			if ( $count > 0 ) {
				return new WP_Error(
					'uich_ct_key_locked',
					sprintf(
						/* translators: 1: number of terms, 2: current taxonomy key */
						_n(
							'The taxonomy key cannot be changed: %1$d term uses the key "%2$s". Renaming it would orphan that content.',
							'The taxonomy key cannot be changed: %1$d terms use the key "%2$s". Renaming it would orphan that content.',
							$count,
							'uichemy'
						),
						$count,
						$old_key
					),
					array( 'status' => 400 )
				);
			}
		}

		if ( $post_id ) {
			// The id names a definition in the model. There is no post to look up.
			$found = Uich_Fields_Model_Writer::lookup( $post_id );
			if ( ! $found ) {
				return new WP_Error(
					'uich_ct_invalid_post',
					__( 'Invalid post.', 'uichemy' ),
					array( 'status' => 400 )
				);
			}
		}

		// The dry run stops HERE, immediately before the first write, so every check above has
		// already run against this exact payload — capability, kind, sanitisation, required keys,
		// the key-length and reserved-key rules and the rename lock. A preview that stopped any
		// earlier would report a pass the real save then refuses, which is worse than no preview.
		if ( $dry_run ) {
			return array(
				'success' => true,
				'dry_run' => true,
				'id'      => (int) $post_id,
				'would'   => $post_id ? 'update' : 'create',
				'kind'    => 'taxonomy',
				'key'     => isset( $meta['taxonomy_key'] ) ? (string) $meta['taxonomy_key'] : '',
				'message' => __( 'Dry run: every check passed and nothing was saved. Re-send without dry_run to apply it.', 'uichemy' ),
			);
		}

		// On update, omit post_status so wp_update_post() leaves a deliberately trashed definition trashed.
		$post_args = $post_id
			? array( 'post_title' => $title )
			: array(
				'post_type'   => Uich_Fields_CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			);

		$result = self::persist_definition_post( $post_id, $post_args, $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = $result;

		// Guarantee the administrator can always reach a type that uses custom capabilities.
		self::grant_custom_caps_to_admin( $meta );

		// Keep the existing default term in step with the definition (rename in place instead of
		// stranding an orphan; apply a slug/description core ignored when it adopted a same-name term).
		$warnings = self::sync_default_term( (string) $meta['taxonomy_key'], $meta );

		if ( class_exists( 'Uich_Fields_Register' ) ) {
			Uich_Fields_Register::get_instance()->flush_cache();
		}

		/**
		 * Fires after a CPT or taxonomy definition is saved.
		 *
		 * @param int    $post_id Definition post ID.
		 * @param string $kind    'cpt' or 'taxonomy'.
		 * @param array  $meta    Sanitized definition meta.
		 */
		do_action( 'uich/definition_saved', $post_id, isset( $meta['kind'] ) ? $meta['kind'] : '', $meta );

		return array(
			'id'       => $post_id,
			'message'  => __( 'Saved.', 'uichemy' ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Reconcile a taxonomy's existing default term with its definition.
	 *
	 * @param string $taxonomy_key Taxonomy key.
	 * @param array  $meta         Sanitized definition meta.
	 * @return string[] Human-readable warnings (empty on success or when there is nothing to do).
	 */
	private static function sync_default_term( $taxonomy_key, $meta ) {
		$warnings = array();

		if ( '' === $taxonomy_key || empty( $meta['default_term_enabled'] ) || empty( $meta['default_term']['name'] ) ) {
			return $warnings;
		}

		// No option yet => the term has never been created. register_taxonomy() will create it on the
		// next init with exactly the values just saved, so there is nothing to reconcile.
		$term_id = (int) get_option( 'default_term_' . $taxonomy_key, 0 );
		if ( ! $term_id ) {
			return $warnings;
		}

		$term = get_term( $term_id, $taxonomy_key );
		if ( ! $term || is_wp_error( $term ) ) {
			return $warnings;
		}

		$wanted = array();
		$name   = (string) $meta['default_term']['name'];
		if ( (string) $term->name !== $name ) {
			$wanted['name'] = $name;
		}
		if ( ! empty( $meta['default_term']['slug'] ) && (string) $term->slug !== (string) $meta['default_term']['slug'] ) {
			$wanted['slug'] = (string) $meta['default_term']['slug'];
		}
		$description = isset( $meta['default_term']['description'] ) ? (string) $meta['default_term']['description'] : '';
		if ( (string) $term->description !== $description ) {
			$wanted['description'] = $description;
		}

		if ( empty( $wanted ) ) {
			return $warnings;
		}

		$updated = wp_update_term( $term_id, $taxonomy_key, $wanted );
		if ( is_wp_error( $updated ) ) {
			$warnings[] = sprintf(
				/* translators: %s: the underlying WordPress error message */
				__( 'The default term could not be updated: %s', 'uichemy' ),
				$updated->get_error_message()
			);
		}

		return $warnings;
	}

	/**
	 * Create or update Field Group (legacy ajax parity; prefer REST /field-groups when possible).
	 *
	 * @param int   $post_id Post ID or 0 for new.
	 * @param array $data    Raw meta payload.
	 * @return array|WP_Error Success: { id, message }.
	 */
	public static function save_field_group( $post_id, $data ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$kind_error = self::assert_definition_kind( $post_id, 'group_fields' );
		if ( is_wp_error( $kind_error ) ) {
			return $kind_error;
		}

		$meta  = self::sanitize_group_fields_meta( $data );
		$title = ! empty( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : __( 'Field Group', 'uichemy' );

		$post_id = absint( $post_id );

		// The same Free/Pro checks the REST controller runs. The JSON import (import_definitions())
		// and uich_import_fields() both reach this path, and skipping them here let an import store
		// Pro field types, Pro location rules and conditional logic on a Free build.
		$stored     = $post_id ? self::definition_meta( $post_id ) : array();
		$tier_error = uich_fields_assert_group_tier( $meta, is_array( $stored ) ? $stored : array() );
		if ( is_wp_error( $tier_error ) ) {
			return $tier_error;
		}

		// Run the rename lock before the key is finalised, as the REST controller does: the importer reaches this path too.
		if ( $post_id ) {
			$lock_error = self::assert_group_keys_not_renamed_with_data( $post_id, $meta );
			if ( is_wp_error( $lock_error ) ) {
				return $lock_error;
			}
		}

		$meta  = self::finalize_group_meta( $meta, $post_id );
		$saved = Uich_Fields_Model_Writer::save( 'group_fields', $meta, $post_id, $title );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		self::after_definition_change( 'group_fields' );

		/** This action is documented in class-uich-fields-admin-logic.php (save_cpt). */
		do_action( 'uich/definition_saved', (int) $saved, 'group_fields', $meta );

		return array(
			'id'      => (int) $saved,
			'message' => __( 'Saved.', 'uichemy' ),
		);
	}

	/**
	 * Finalize a field group's meta so its value meta keys can't collide: guarantee a non-blank, unique group
	 * key and unique top-level field names.
	 *
	 * @param array      $meta     Sanitized group meta.
	 * @param int        $post_id  This group's post ID (used for the stable fallback key + self-exclusion).
	 * @param array|null $warnings Receives a message for every field name that was changed to be stored.
	 * @return array
	 */
	public static function finalize_group_meta( $meta, $post_id, &$warnings = null ) {
		$post_id  = absint( $post_id );
		$warnings = array();
		if ( ! is_array( $meta ) ) {
			return $meta;
		}

		// 1. Non-blank, unique group key.
		$key = isset( $meta['key'] ) ? self::normalize_group_key( $meta['key'] ) : '';
		if ( '' === $key ) {
			// A NEW group has no id to derive a stable key from; `group_0` told nobody anything, so use its title.
			$key = $post_id ? 'group_' . $post_id : self::group_key_from_title( isset( $meta['title'] ) ? $meta['title'] : '' );
		}
		if ( self::group_key_in_use( $key, $post_id ) ) {
			$key = self::unique_group_key( $key, $post_id );
		}
		$meta['key'] = $key;

		// 2. Unique top-level field names within this group.
		if ( isset( $meta['fields'] ) && is_array( $meta['fields'] ) ) {
			$seen    = array();
			$renames = array();
			foreach ( $meta['fields'] as $i => $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				unset( $meta['fields'][ $i ]['_isNew'] ); // #2: strip the client-only new-field flag before persisting.
				if ( empty( $f['name'] ) ) {
					continue;
				}
				// Guarantee a stable per-field `key`.
				if ( empty( $meta['fields'][ $i ]['key'] ) ) {
					$meta['fields'][ $i ]['key'] = 'field_' . substr( md5( $post_id . '|' . $f['name'] . '|' . $i ), 0, 13 );
				}
				$orig = (string) $f['name'];
				// Store the name in exactly the form the value meta key is built from, so two names
				// that would build the same key ("My_Field" / "my__field") are deduplicated below
				// instead of silently sharing one value.
				$name = function_exists( 'uich_normalize_field_name' ) ? uich_normalize_field_name( $orig ) : sanitize_key( $orig );
				if ( '' === $name ) {
					$name = 'field_' . ( $i + 1 );
				}
				if ( $name !== $orig ) {
					$meta['fields'][ $i ]['name'] = $name;
					$renames[ $orig ]             = $name;
					$warnings[]                   = sprintf(
						/* translators: 1: the field name as sent, 2: the field name as stored */
						__( 'The field name “%1$s” was saved as “%2$s”: names use lowercase letters, numbers, dashes and underscores.', 'uichemy' ),
						$orig,
						$name
					);
				}
				if ( isset( $seen[ $name ] ) ) {
					$base = $name;
					$n    = 2;
					while ( isset( $seen[ $base . '_' . $n ] ) ) {
						++$n;
					}
					$name                         = $base . '_' . $n;
					$meta['fields'][ $i ]['name'] = $name;
					$renames[ $orig ]             = $name;
					$warnings[]                   = sprintf(
						/* translators: 1: the field name that was already used, 2: the name the later field was saved as */
						__( 'Another field already used the name “%1$s”, so a later field was saved as “%2$s”.', 'uichemy' ),
						$base,
						$name
					);
				}
				$seen[ $name ] = true;
			}
			// #8: remap conditional_logic to an auto-suffixed name, but only if no surviving field still owns the original.
			if ( ! empty( $renames ) ) {
				foreach ( $renames as $old => $new ) {
					if ( isset( $seen[ $old ] ) ) {
						continue;
					}
					foreach ( $meta['fields'] as $i => $f ) {
						if ( ! is_array( $f ) || empty( $f['conditional_logic'] ) || ! is_array( $f['conditional_logic'] ) ) {
							continue;
						}
						$meta['fields'][ $i ]['conditional_logic'] = self::remap_conditional_logic_field( $f['conditional_logic'], $old, $new );
					}
				}
			}
		}

		return $meta;
	}

	/**
	 * A group key derived from its title ("[IM] Partial" => im_partial), for a group submitted without one.
	 *
	 * @param mixed $title Group title.
	 * @return string Never empty.
	 */
	public static function group_key_from_title( $title ) {
		$key = self::normalize_group_key( str_replace( '-', '_', sanitize_title( is_scalar( $title ) ? (string) $title : '' ) ) );
		$key = rtrim( substr( $key, 0, 56 ), '_' );
		return '' !== $key ? $key : 'group';
	}

	/**
	 * Normalise a submitted group key: sanitize_key(), runs of underscores collapsed, and no leading
	 * `_`/`-` or trailing `_` — the forms Uich_Fields_Model_Paths::validate_key() refuses because the
	 * key is the first segment of every value meta key (`{prefix}{group}__{field}`).
	 *
	 * @param mixed $key Raw key.
	 * @return string '' when nothing usable remains.
	 */
	public static function normalize_group_key( $key ) {
		$key = sanitize_key( is_scalar( $key ) ? (string) $key : '' );
		$key = (string) preg_replace( '/_{2,}/', '_', $key );
		$key = ltrim( $key, '_-' );
		return rtrim( $key, '_' );
	}

	/**
	 * Rewrite every conditional-logic rule that references field name $old to $new, in both the flat
	 * ([{rule}]) and nested ([[{rule}]]) rule-group shapes.
	 *
	 * @param array  $logic Conditional-logic structure.
	 * @param string $old   Old field name.
	 * @param string $new   New field name.
	 * @return array
	 */
	private static function remap_conditional_logic_field( $logic, $old, $new ) {
		if ( ! is_array( $logic ) ) {
			return $logic;
		}
		foreach ( $logic as $k => $item ) {
			if ( is_array( $item ) && isset( $item['field'] ) ) {
				if ( (string) $item['field'] === (string) $old ) {
					$logic[ $k ]['field'] = $new;
				}
			} elseif ( is_array( $item ) ) {
				$logic[ $k ] = self::remap_conditional_logic_field( $item, $old, $new );
			}
		}
		return $logic;
	}

	/**
	 * A group key nothing else holds, derived from the one that collided.
	 *
	 * @param string $key             The colliding key.
	 * @param int    $exclude_post_id Group being saved (excluded from the collision check).
	 * @return string
	 */
	private static function unique_group_key( $key, $exclude_post_id ) {
		// The old form appended $post_id, but create_item() passes 0 for every NEW group, so every
		// collision resolved to the same `{key}_0` — two groups could share a key and with it their
		// `_uichemy_gf_{key}__*` value meta, letting one group read the other's values (BUG-003).
		$max = 64;
		if ( class_exists( 'Uich_Fields_Model_Paths' ) ) {
			$caps = Uich_Fields_Model_Paths::key_max_lengths();
			$max  = isset( $caps['group_fields'] ) ? (int) $caps['group_fields'] : 64;
		}

		for ( $n = 2; $n <= 200; $n++ ) {
			$suffix    = '_' . $n;
			$candidate = rtrim( substr( $key, 0, max( 1, $max - strlen( $suffix ) ) ), '_-' ) . $suffix;
			if ( ! self::group_key_in_use( $candidate, $exclude_post_id ) ) {
				return $candidate;
			}
		}

		// 200 collisions is not a real site, but returning the key we know is taken would reintroduce
		// the sharing this method exists to prevent, so fall back to something that cannot collide.
		return rtrim( substr( $key, 0, max( 1, $max - 13 ) ), '_-' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 );
	}

	/**
	 * Whether another group definition already holds this key.
	 *
	 * @param string $key             Candidate key.
	 * @param int    $exclude_post_id Definition to ignore (the one being saved).
	 * @return bool
	 */
	private static function group_key_in_use( $key, $exclude_post_id ) {
		$key             = (string) $key;
		$exclude_post_id = absint( $exclude_post_id );
		if ( '' === $key ) {
			return false;
		}
		// include_trash: a trashed group's key stays reserved so a new group can't claim the same
		// key and share its _uichemy_gf_{key}__* value meta (MB-15).
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows( array( 'include_trash' => true ) ) as $row ) {
			if ( 'group_fields' !== $row['kind'] || (int) $row['id'] === $exclude_post_id ) {
				continue;
			}
			$m = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( isset( $m['key'] ) && (string) $m['key'] === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * MB-14 guard.
	 *
	 * @param int   $post_id  Existing group post ID.
	 * @param array $incoming Sanitized (pre-finalize) incoming group meta.
	 * @return WP_Error|null WP_Error when a locked rename is attempted, null otherwise.
	 */
	public static function assert_group_keys_not_renamed_with_data( $post_id, $incoming ) {
		$post_id = absint( $post_id );

		// File-first has no post meta; reading it returned '' and the guard below read that as nothing stored.
		$stored = self::definition_meta( $post_id );

		if ( ! $stored || ! is_array( $incoming ) ) {
			return null;
		}

		// Resolve old + new group key the same way finalize_group_meta() does (blank -> group_{id}).
		$old_key = isset( $stored['key'] ) ? trim( (string) $stored['key'] ) : '';
		if ( '' === $old_key ) {
			return null; // No stable old key: nothing could have been saved under it.
		}
		$new_key = isset( $incoming['key'] ) ? self::normalize_group_key( $incoming['key'] ) : '';
		if ( '' === $new_key ) {
			$new_key = 'group_' . $post_id;
		}

		// Group-key rename orphans every field's values at once.
		if ( $old_key !== $new_key && self::group_value_data_exists( $old_key ) ) {
			return new WP_Error(
				'uich_ct_group_key_locked',
				sprintf(
					/* translators: %s: the locked group key. */
					__( 'The group key "%s" cannot be changed because field values have already been saved under it. Renaming it would orphan that data. Keep the existing key, or remove the saved values first.', 'uichemy' ),
					$old_key
				),
				array( 'status' => 400 )
			);
		}

		// Field-name rename: match fields by their stable "key" and compare names.
		$stored_fields   = ( isset( $stored['fields'] ) && is_array( $stored['fields'] ) ) ? array_values( $stored['fields'] ) : array();
		$incoming_fields = ( isset( $incoming['fields'] ) && is_array( $incoming['fields'] ) ) ? array_values( $incoming['fields'] ) : array();
		// Positional matching is only trustworthy when nothing was added or removed — otherwise a
		// deletion shifts every later field and would read as a rename.
		$allow_positional = ( count( $stored_fields ) === count( $incoming_fields ) );

		$old_names = array();
		foreach ( $stored_fields as $idx => $f ) {
			if ( ! is_array( $f ) || ! isset( $f['name'] ) ) {
				continue;
			}
			if ( empty( $f['key'] ) && ! $allow_positional ) {
				continue;
			}
			$ident               = ! empty( $f['key'] ) ? (string) $f['key'] : '#index:' . $idx;
			$old_names[ $ident ] = (string) $f['name'];
		}
		if ( ! empty( $old_names ) ) {
			foreach ( $incoming_fields as $idx => $f ) {
				if ( ! is_array( $f ) || ! isset( $f['name'] ) ) {
					continue;
				}
				if ( empty( $f['key'] ) && ! $allow_positional ) {
					continue;
				}
				$fk = ! empty( $f['key'] ) ? (string) $f['key'] : '#index:' . $idx;
				// Compared in stored form: finalize_group_meta() normalises the name before it is saved.
				$new_name = function_exists( 'uich_normalize_field_name' ) ? uich_normalize_field_name( $f['name'] ) : (string) $f['name'];
				if ( isset( $old_names[ $fk ] )
					&& $old_names[ $fk ] !== $new_name
					&& self::group_value_data_exists( $old_key, $old_names[ $fk ] ) ) {
					return new WP_Error(
						'uich_ct_field_name_locked',
						sprintf(
							/* translators: %s: the locked field name. */
							__( 'The field name "%s" cannot be changed because values have already been saved under it. Renaming it would orphan that data. Keep the existing name, or remove the saved values first.', 'uichemy' ),
							$old_names[ $fk ]
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether any saved field value exists under a group key (optionally a specific field name), across every
	 * object type a group can attach to: post/user/term/comment meta + options.
	 *
	 * @param string      $group_key  Group key (sanitize_key applied here).
	 * @param string|null $field_name Optional specific field name; null = any field in the group.
	 * @return bool
	 */
	private static function group_value_data_exists( $group_key, $field_name = null ) {
		global $wpdb;
		$targets = self::group_value_targets( $group_key );
		if ( ! $targets ) {
			return false;
		}
		$fn = ( null === $field_name ) ? null : ( function_exists( 'uich_normalize_field_name' ) ? uich_normalize_field_name( $field_name ) : sanitize_key( (string) $field_name ) );

		foreach ( $targets as $t ) {
			list( $table, $col, $prefix ) = $t;
			if ( null === $fn ) {
				$like  = $wpdb->esc_like( $prefix ) . '%';
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} = %s", $prefix . $fn ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $count > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where a group's stored values live: [ table, column, key prefix incl. the trailing "{group}__" ].
	 * Built from the same helpers the read/write paths use, so no caller can look under a different key.
	 *
	 * @param string $group_key Group key.
	 * @return array[] Empty when the key is blank or the field API is not loaded.
	 */
	private static function group_value_targets( $group_key ) {
		global $wpdb;
		$gk = sanitize_key( (string) $group_key );
		if ( '' === $gk || ! function_exists( 'uich_get_meta_key_prefix' ) || ! function_exists( 'uich_meta_key_group_prefix' ) ) {
			return array();
		}
		$group_part = uich_meta_key_group_prefix( $gk );
		return array(
			array( $wpdb->postmeta, 'meta_key', uich_get_meta_key_prefix( 'post' ) . $group_part ),
			array( $wpdb->usermeta, 'meta_key', uich_get_meta_key_prefix( 'user' ) . $group_part ),
			array( $wpdb->termmeta, 'meta_key', uich_get_meta_key_prefix( 'term' ) . $group_part ),
			array( $wpdb->commentmeta, 'meta_key', uich_get_meta_key_prefix( 'comment' ) . $group_part ),
			array( $wpdb->options, 'option_name', uich_get_meta_key_prefix( 'option' ) . $group_part ),
		);
	}

	/**
	 * Number of stored value rows (all object types) under a group key.
	 *
	 * @param string $group_key Group key.
	 * @return int
	 */
	private static function group_value_count( $group_key ) {
		global $wpdb;
		$total = 0;
		foreach ( self::group_value_targets( $group_key ) as $t ) {
			list( $table, $col, $prefix ) = $t;
			$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return $total;
	}

	/**
	 * Delete every stored value row under a group key.
	 *
	 * @param string $group_key Group key.
	 * @return void
	 */
	private static function delete_group_values( $group_key ) {
		global $wpdb;
		foreach ( self::group_value_targets( $group_key ) as $t ) {
			list( $table, $col, $prefix ) = $t;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		// Direct deletes bypass the meta and options caches, which would keep serving the removed values.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			foreach ( array( 'post_meta', 'term_meta', 'user_meta', 'comment_meta', 'options', 'notoptions' ) as $cache_group ) {
				wp_cache_flush_group( $cache_group );
			}
		} else {
			wp_cache_flush();
		}
	}

	/**
	 * Sanitize field group meta.
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	public static function sanitize_group_fields_meta( $data ) {
		$out = array( 'kind' => 'group_fields' );

		$fields   = Uich_Fields_Field_Groups_Fields::get_fields();
		$defaults = Uich_Fields_Field_Groups_Fields::get_defaults();

		foreach ( $fields as $key => $type ) {
			if ( 'kind' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = Uich_Fields_Field_Groups_Fields::sanitize_value( $data[ $key ], $type );
			} elseif ( array_key_exists( $key, $defaults ) ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}

		if ( isset( $out['location_rules'] ) ) {
			$out['location_rules'] = Uich_Fields_Field_Groups_Fields::dedupe_location_rules( $out['location_rules'] );
		}

		return $out;
	}

	/**
	 * Trash a content-type definition post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $confirm Whether the caller has confirmed hiding any content stored under this
	 *                      type. When false and the CPT/taxonomy still has content, the delete is
	 *                      refused with a confirmation-required error instead of silently orphaning.
	 * @return array|WP_Error Success: { message }.
	 */
	/**
	 * Keys a migration is currently importing, and the source they belong to.
	 *
	 * @var array<string, string> "kind|key" => source slug.
	 */
	private static $importing_keys = array();

	/**
	 * Tell the collision guard that these keys belong to the plugin being migrated from.
	 *
	 * @param string   $slug Source slug being imported from.
	 * @param string   $kind cpt | taxonomy.
	 * @param string[] $keys Keys read from that source.
	 * @return void
	 */
	public static function begin_source_import( $slug, $kind, $keys ) {
		foreach ( (array) $keys as $key ) {
			$key = (string) $key;
			if ( '' !== $key ) {
				self::$importing_keys[ $kind . '|' . $key ] = (string) $slug;
			}
		}
	}

	/**
	 * Clear the exemption. Always called, including when an import fails part way.
	 *
	 * @return void
	 */
	public static function end_source_import() {
		self::$importing_keys = array();
	}

	/**
	 * Whether a registered key is one the current migration is importing from its own source.
	 *
	 * @param string $kind cpt | taxonomy.
	 * @param string $key  Key being saved.
	 * @return bool
	 */
	private static function is_source_owned_key( $kind, $key ) {
		return isset( self::$importing_keys[ $kind . '|' . $key ] );
	}

	/**
	 * Refuse to hide existing content without an explicit confirmation.
	 *
	 * @param array $def_meta Definition meta.
	 * @param bool  $confirm  Whether the caller has confirmed.
	 * @return true|WP_Error
	 */
	/**
	 * A definition's meta, addressed by its derived id, when definitions live in files.
	 *
	 * @param int $id Definition id.
	 * @return array|WP_Error
	 */
	private static function model_definition_meta( $id ) {
		$found = Uich_Fields_Model_Writer::lookup( $id );
		if ( ! $found ) {
			return new WP_Error( 'uich_ct_invalid_post', __( 'Invalid item.', 'uichemy' ), array( 'status' => 400 ) );
		}

		// Read the copy this id NAMES. Falling back from live to trash would return the wrong
		// definition whenever both exist on one key, which is a state the module allows.
		if ( ! empty( $found['trashed'] ) ) {
			$trash = Uich_Fields_Model_Rows::read_trash();
			if ( isset( $trash[ $found['kind'] ][ $found['key'] ] ) ) {
				return $trash[ $found['kind'] ][ $found['key'] ];
			}
		} else {
			$model = Uich_Fields_Model_Cache::get();
			if ( isset( $model['definitions'][ $found['kind'] ][ $found['key'] ] ) ) {
				return $model['definitions'][ $found['kind'] ][ $found['key'] ];
			}
		}

		return new WP_Error( 'uich_ct_invalid_post', __( 'Invalid item.', 'uichemy' ), array( 'status' => 400 ) );
	}

	/**
	 * Refuse to hide existing content without an explicit confirmation.
	 *
	 * @param array $def_meta Definition meta.
	 * @param bool  $confirm  Whether the caller has already confirmed.
	 * @return true|WP_Error
	 */
	private static function content_impact_guard( $def_meta, $confirm ) {
		$guard_kind = ( is_array( $def_meta ) && ! empty( $def_meta['kind'] ) ) ? $def_meta['kind'] : '';
		if ( ! $confirm && ( 'cpt' === $guard_kind || 'taxonomy' === $guard_kind ) ) {
			$key_field = 'cpt' === $guard_kind ? 'post_type_key' : 'taxonomy_key';
			$key       = ( is_array( $def_meta ) && isset( $def_meta[ $key_field ] ) ) ? (string) $def_meta[ $key_field ] : '';
			$count     = self::content_count_for_key( $guard_kind, $key );
			if ( $count > 0 ) {
				// Whole sentences per kind: a separately translated noun cannot be inflected correctly in many languages.
				$message = 'cpt' === $guard_kind
					? sprintf(
						/* translators: %d: number of posts that use this post type. */
						_n(
							'This will hide %d post that uses this type. The content stays in the database but becomes inaccessible once the type is removed. Confirm to proceed.',
							'This will hide %d posts that use this type. The content stays in the database but becomes inaccessible once the type is removed. Confirm to proceed.',
							$count,
							'uichemy'
						),
						$count
					)
					: sprintf(
						/* translators: %d: number of terms that use this taxonomy. */
						_n(
							'This will hide %d term that uses this type. The content stays in the database but becomes inaccessible once the type is removed. Confirm to proceed.',
							'This will hide %d terms that use this type. The content stays in the database but becomes inaccessible once the type is removed. Confirm to proceed.',
							$count,
							'uichemy'
						),
						$count
					);
				return new WP_Error(
					'uich_ct_delete_confirm_required',
					$message,
					array(
						'status'                => 409,
						'requires_confirmation' => true,
						'content_count'         => $count,
						'kind'                  => $guard_kind,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Flush the caches a definition change invalidates, and fire the module's own action.
	 *
	 * @param string $kind cpt | taxonomy | group_fields.
	 * @return void
	 */
	private static function after_definition_change( $kind, $event = '', $post_id = 0, $meta = array() ) {
		if ( class_exists( 'Uich_Fields_Register' ) ) {
			Uich_Fields_Register::get_instance()->flush_cache();
		}

		// The specific event (deleted | deleted_permanently | restored): the page-cache purge and the
		// repository, render and read caches listen for these, and nothing else fired them.
		if ( '' !== $event ) {
			/**
			 * Fires after a definition is trashed (definition_deleted), restored (definition_restored) or
			 * removed for good (definition_deleted_permanently).
			 *
			 * @param int    $post_id Definition id the call addressed.
			 * @param string $kind    'cpt', 'taxonomy' or 'group_fields'.
			 * @param array  $meta    The definition as it was.
			 */
			do_action( 'uich/definition_' . $event, (int) $post_id, (string) $kind, is_array( $meta ) ? $meta : array() );
		}

		/**
		 * Fires after a definition is trashed, restored or deleted.
		 *
		 * @param int    $post_id Always 0; kept so listeners can share a callback with uich/definition_saved.
		 * @param string $kind    'cpt', 'taxonomy' or 'group_fields'.
		 */
		do_action( 'uich/definition_changed', 0, (string) $kind );
	}

	/**
	 * Move a definition to the trash.
	 *
	 * @param int  $post_id Definition id.
	 * @param bool $confirm Whether the caller has confirmed hiding any existing content.
	 * @return array|WP_Error
	 */
	public static function delete_definition( $post_id, $confirm = false, $dry_run = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		$def_meta = self::model_definition_meta( $post_id );
		if ( is_wp_error( $def_meta ) ) {
			return $def_meta;
		}

		// Trashing a definition unregisters its post type or taxonomy, which makes every item using
		// it invisible. Refuse to do that silently.
		$guard = self::content_impact_guard( $def_meta, $confirm );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// The dry run stops HERE, immediately before the first write, so the capability check and the
		// content-impact guard have both run against this exact id — including the refusal that asks
		// for confirm when the type still holds content.
		if ( $dry_run ) {
			return array(
				'success' => true,
				'dry_run' => true,
				'would'   => 'trash',
				'id'      => (int) $post_id,
				'kind'    => isset( $def_meta['kind'] ) ? $def_meta['kind'] : '',
			);
		}

		// Capabilities are deliberately NOT revoked here.
		$moved = Uich_Fields_Model_Writer::trash( $post_id );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		self::after_definition_change( isset( $def_meta['kind'] ) ? $def_meta['kind'] : '', 'deleted', $post_id, $def_meta );

		return array(
			'id'      => (int) $post_id,
			'message' => __( 'Moved to Trash.', 'uichemy' ),
		);
	}

	/**
	 * Restore a trashed definition.
	 *
	 * @param int $post_id Definition post ID.
	 * @return array|WP_Error
	 */
	public static function restore_definition( $post_id, $dry_run = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		$found = Uich_Fields_Model_Writer::lookup( $post_id );
		if ( ! $found ) {
			return new WP_Error( 'uich_ct_invalid_post', __( 'Invalid item.', 'uichemy' ), array( 'status' => 400 ) );
		}

		if ( empty( $found['trashed'] ) ) {
			return new WP_Error(
				'uich_ct_not_trashed',
				__( 'This item is not in the Trash.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}

		$live = Uich_Fields_Model_Paths::file_for_key( $found['kind'], $found['key'] );

		// MB-09: while this definition sat in the trash, another one may have claimed its key — the duplicate
		// check on save only scans live definitions.
		if ( ! is_wp_error( $live ) && file_exists( $live ) ) {
			return new WP_Error(
				'uich_ct_key_conflict',
				sprintf(
					/* translators: %s: the definition key that is already in use. */
					__( 'This item cannot be restored because the key "%s" is now used by another definition. Rename or remove that one first, then restore this item.', 'uichemy' ),
					$found['key']
				),
				array(
					'status' => 409,
					'key'    => $found['key'],
				)
			);
		}

		// Stops here so the preview has already run the two refusals that matter: not-in-trash, and a
		// key another definition claimed while this one sat in the Trash.
		if ( $dry_run ) {
			return array(
				'success' => true,
				'dry_run' => true,
				'would'   => 'restore',
				'id'      => (int) $post_id,
				'kind'    => $found['kind'],
				'key'     => $found['key'],
			);
		}

		$restored = Uich_Fields_Model_Writer::untrash( $post_id );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		self::after_definition_change( 'cpt' === $found['kind'] ? 'cpt' : ( 'tax' === $found['kind'] ? 'taxonomy' : 'group_fields' ), 'restored', $post_id );

		return array(
			'id'      => (int) $post_id,
			'message' => __( 'Restored.', 'uichemy' ),
		);
	}

	/**
	 * Permanently delete a definition.
	 *
	 * @param int  $post_id       Definition post ID.
	 * @param bool $dry_run       Report what would happen without deleting.
	 * @param bool $confirm       Caller accepts that stored values of a deleted field group stay in the database.
	 *                            Defaults to true so internal callers (import, scaffold rollback) are not gated.
	 * @param bool $delete_values Also delete the stored values of a deleted field group.
	 * @return array|WP_Error
	 */
	public static function delete_definition_permanently( $post_id, $dry_run = false, $confirm = true, $delete_values = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'uich_ct_forbidden',
				__( 'You do not have permission to do this.', 'uichemy' ),
				array( 'status' => 403 )
			);
		}

		$def_meta = self::model_definition_meta( $post_id );
		if ( is_wp_error( $def_meta ) ) {
			return $def_meta;
		}

		// A group's values are keyed by its key and outlive the definition; refuse to orphan them silently.
		// Skipped when a live group still owns the same key (the values are not orphaned then).
		$group_key   = '';
		$value_count = 0;
		$found       = Uich_Fields_Model_Writer::lookup( $post_id );
		if ( $found && isset( $def_meta['kind'], $def_meta['key'] ) && 'group_fields' === $def_meta['kind'] ) {
			$model = Uich_Fields_Model_Cache::get();
			$owned = ! empty( $found['trashed'] ) && isset( $model['definitions']['group_fields'][ $found['key'] ] );
			if ( ! $owned ) {
				$group_key   = (string) $def_meta['key'];
				$value_count = self::group_value_count( $group_key );
			}
		}
		if ( $value_count > 0 && ! $confirm && ! $delete_values ) {
			return new WP_Error(
				'uich_ct_group_values_confirm_required',
				sprintf(
					/* translators: %d: number of stored value rows. */
					_n(
						'This field group still has %d stored value. Deleting the group keeps it in the database, unreachable. Confirm to keep the values, or choose to delete them too.',
						'This field group still has %d stored values. Deleting the group keeps them in the database, unreachable. Confirm to keep the values, or choose to delete them too.',
						$value_count,
						'uichemy'
					),
					$value_count
				),
				array(
					'status'                => 409,
					'requires_confirmation' => true,
					'value_count'           => $value_count,
					'can_delete_values'     => true,
					'kind'                  => 'group_fields',
				)
			);
		}

		// Stops ABOVE the capability revoke, not just above the file delete: revoking is itself a
		// write, and a preview that performed it would strip caps for a delete that never happened.
		if ( $dry_run ) {
			$preview = array(
				'success'   => true,
				'dry_run'   => true,
				'would'     => 'delete permanently',
				'id'        => (int) $post_id,
				'kind'      => isset( $def_meta['kind'] ) ? $def_meta['kind'] : '',
				'permanent' => true,
			);
			if ( $value_count > 0 ) {
				$preview['value_count']         = $value_count;
				$preview['would_delete_values'] = (bool) $delete_values;
			}
			return $preview;
		}

		// There is no post, so before_delete_post never fires — and that hook is what used to revoke a
		// definition's custom capabilities from the administrator role.
		self::revoke_custom_caps_for_definition( $def_meta, (int) $post_id );

		$done = Uich_Fields_Model_Writer::delete( $post_id );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		// After the definition is gone, so a failed delete never leaves a group without its values.
		if ( $delete_values && $value_count > 0 ) {
			self::delete_group_values( $group_key );
		}

		self::after_definition_change( isset( $def_meta['kind'] ) ? $def_meta['kind'] : '', 'deleted_permanently', $post_id, $def_meta );

		$result = array(
			'id'      => (int) $post_id,
			'message' => __( 'Deleted permanently.', 'uichemy' ),
		);
		if ( $value_count > 0 ) {
			$result['value_count']    = $value_count;
			$result['values_deleted'] = (bool) $delete_values;
		}
		return $result;
	}

	/**
	 * Trashed definitions, newest first, for the Trash view.
	 *
	 * @return array<int, array>
	 */
	public static function get_trashed_definitions() {
		$out = array();
		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows( array( 'include_trash' => true ) ) as $row ) {
			if ( 'trash' !== $row['status'] ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Flat list of taxonomy terms for Post Object field "Filter by Taxonomy" (Select2).
	 *
	 * @return array{ results: array }
	 */
	public static function get_post_object_terms_payload() {
		$results   = array();
		$truncated = false;
		$taxes     = get_taxonomies( array( 'show_ui' => true ), 'objects' );

		foreach ( $taxes as $tax => $tax_obj ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => self::POST_OBJECT_TERMS_LIMIT,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			if ( count( $terms ) >= self::POST_OBJECT_TERMS_LIMIT ) {
				$truncated = true;
			}
			$tax_label = isset( $tax_obj->labels->singular_name ) ? $tax_obj->labels->singular_name : $tax;
			foreach ( $terms as $term ) {
				$results[] = array(
					'id'   => $tax . ':' . (int) $term->term_id,
					'text' => $tax_label . ': ' . $term->name,
				);
			}
		}

		$payload = array( 'results' => $results );
		if ( $truncated ) {
			$payload['truncated'] = true;
			$payload['limit']     = self::POST_OBJECT_TERMS_LIMIT;
		}
		return $payload;
	}
}
