<?php
/**
 * Base class for third-party field-plugin import sources (ACF, Meta Box, Pods).
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_Source
 */
abstract class Uich_Fields_Import_Source {

	/**
	 * Field types Uichemy actually offers.
	 *
	 * @return string[]
	 */
	public static function supported_types() {
		return array(
			'text',
			'textarea',
			'number',
			'range',
			'email',
			'url',
			'select',
			'checkbox',
			'radio',
			'true_false',
			'wysiwyg',
			'image',
			'file',
			'repeater',
			'link',
			'post_object',
			'page_link',
			'relationship',
			'taxonomy',
			'user',
			'date',
			'time',
			'datetime',
			'color',
		);
	}

	/**
	 * Machine slug ('acf' | 'metabox' | 'pods').
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Human label for the UI and reports.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether this source has anything to import on this site. Checked before the source is even
	 * offered, so an absent plugin never produces a notice.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Post-type definitions, already shaped for Admin_Logic::save_cpt().
	 *
	 * @return array<int, array{ data: array, source_id: int, warnings: string[] }>
	 */
	abstract public function read_post_types();

	/**
	 * Taxonomy definitions, already shaped for Admin_Logic::save_taxonomy().
	 *
	 * @return array<int, array{ data: array, source_id: int, warnings: string[] }>
	 */
	abstract public function read_taxonomies();

	/**
	 * Field groups, already shaped for the field-groups REST controller.
	 *
	 * @return array<int, array{ data: array, source_id: int, warnings: string[], skipped: array }>
	 */
	abstract public function read_groups();

	/**
	 * Read one field's stored value from the SOURCE plugin's storage.
	 *
	 * @param array  $field       Source field descriptor (from read_groups()).
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return mixed|null
	 */
	abstract public function read_value( $field, $object_type, $object_id );

	/**
	 * Mark the source's own definitions inactive after a successful import, so both plugins can stay
	 * installed without rendering the same fields twice. Never deletes: rollback depends on them.
	 *
	 * @param array $source_ids Definition post IDs that were migrated.
	 * @return int Number disabled.
	 */
	abstract public function disable_definitions( $source_ids );

	/**
	 * Put the source's own definitions back, undoing disable_definitions().
	 *
	 * Post-based sources leave this alone: rollback re-publishes their definition posts directly.
	 * A source whose definitions are table rows or option keys has no such path and must override.
	 *
	 * @param array $source_ids Definition ids to re-enable.
	 * @return int Number re-enabled.
	 */
	public function enable_definitions( $source_ids ) {
		unset( $source_ids );
		return 0;
	}

	/**
	 * Whether this source's definition ids are WordPress post ids.
	 *
	 * The importer writes a reverse-link post meta on each source id and rollback may re-publish it.
	 * A source whose definitions are custom-table rows or option keys returns false, or those two
	 * operations land on whatever post happens to hold the same number.
	 *
	 * @return bool
	 */
	public function definitions_are_posts() {
		return true;
	}

	// --------------------------------------------------------------------- Shared helpers
	// ---------------------------------------------------------------------.

	/**
	 * Objects a group's location rules point at, as [ object_type => [ ids ] ].
	 *
	 * @param array $location_rules Uichemy-shaped location rules.
	 * @param int   $limit          Max objects per type (0 = no limit).
	 * @return array<string, int[]>
	 */
	protected function objects_for_location( $location_rules, $limit = 0 ) {
		$out = array(
			'post'    => array(),
			'term'    => array(),
			'user'    => array(),
			'comment' => array(),
		);

		$targets     = $this->location_targets( $location_rules );
		$post_types  = $targets['post_types'];
		$taxonomies  = $targets['taxonomies'];
		$has_user    = $targets['user'];
		$has_comment = $targets['comment'];

		if ( $post_types ) {
			$out['post'] = get_posts(
				array(
					'post_type'        => $post_types,
					'post_status'      => 'any',
					'numberposts'      => $limit > 0 ? $limit : -1,
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);
		}
		if ( $taxonomies ) {
			$terms       = get_terms(
				array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$out['term'] = is_wp_error( $terms ) ? array() : $terms;
		}
		if ( $has_user ) {
			$out['user'] = get_users(
				array(
					'fields' => 'ID',
					'number' => $limit > 0 ? $limit : -1,
				)
			);
		}
		if ( $has_comment ) {
			$out['comment'] = get_comments(
				array(
					'fields' => 'ids',
					'number' => $limit > 0 ? $limit : 0,
				)
			);
		}

		return array_map(
			'array_values',
			array_map(
				function ( $ids ) {
					return array_map( 'intval', (array) $ids );
				},
				$out
			)
		);
	}

	/**
	 * Object types a group's location rules point at, without running any query.
	 *
	 * @param array $location_rules Uichemy-shaped location rules.
	 * @return array{post_types:string[], taxonomies:string[], user:bool, comment:bool}
	 */
	protected function location_targets( $location_rules ) {
		$post_types  = array();
		$taxonomies  = array();
		$has_user    = false;
		$has_comment = false;

		foreach ( (array) $location_rules as $group ) {
			foreach ( (array) $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
					continue;
				}
				$param = (string) $rule['param'];
				$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
				if ( 'post_type' === $param && '' !== $value ) {
					$post_types[] = $value;
				} elseif ( 'taxonomy' === $param && '' !== $value ) {
					$taxonomies[] = $value;
				} elseif ( 0 === strpos( $param, 'user' ) || 'current_user' === $param ) {
					$has_user = true;
				} elseif ( 'comment' === $param ) {
					$has_comment = true;
				}
			}
		}

		return array(
			'post_types' => array_values( array_unique( array_filter( $post_types ) ) ),
			'taxonomies' => array_values( array_unique( array_filter( $taxonomies ) ) ),
			'user'       => $has_user,
			'comment'    => $has_comment,
		);
	}

	/**
	 * One page of object IDs of a single type, for the batched importer.
	 *
	 * @param array  $location_rules Uichemy-shaped location rules.
	 * @param string $object_type    post|term|user|comment.
	 * @param int    $offset         Objects to skip.
	 * @param int    $limit          Page size.
	 * @return int[]
	 */
	public function objects_page( $location_rules, $object_type, $offset, $limit ) {
		$targets = $this->location_targets( $location_rules );
		$offset  = max( 0, (int) $offset );
		$limit   = max( 1, (int) $limit );

		switch ( $object_type ) {
			case 'post':
				if ( ! $targets['post_types'] ) {
					return array();
				}
				return array_map(
					'intval',
					(array) get_posts(
						array(
							'post_type'        => $targets['post_types'],
							'post_status'      => 'any',
							'numberposts'      => $limit,
							'offset'           => $offset,
							'orderby'          => 'ID',
							'order'            => 'ASC',
							'fields'           => 'ids',
							'suppress_filters' => false,
						)
					)
				);

			case 'term':
				if ( ! $targets['taxonomies'] ) {
					return array();
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $targets['taxonomies'],
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => $limit,
						'offset'     => $offset,
						'orderby'    => 'term_id',
						'order'      => 'ASC',
					)
				);
				return is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms );

			case 'user':
				if ( ! $targets['user'] ) {
					return array();
				}
				return array_map(
					'intval',
					(array) get_users(
						array(
							'fields'  => 'ID',
							'number'  => $limit,
							'offset'  => $offset,
							'orderby' => 'ID',
							'order'   => 'ASC',
						)
					)
				);

			case 'comment':
				if ( ! $targets['comment'] ) {
					return array();
				}
				return array_map(
					'intval',
					(array) get_comments(
						array(
							'fields'  => 'ids',
							'number'  => $limit,
							'offset'  => $offset,
							'orderby' => 'comment_ID',
							'order'   => 'ASC',
						)
					)
				);
		}

		return array();
	}

	/**
	 * How many objects of one type a group applies to.
	 *
	 * @param array  $location_rules Uichemy-shaped location rules.
	 * @param string $object_type    post|term|user|comment.
	 * @return int
	 */
	public function objects_count( $location_rules, $object_type ) {
		$targets = $this->location_targets( $location_rules );

		switch ( $object_type ) {
			case 'post':
				if ( ! $targets['post_types'] ) {
					return 0;
				}
				$q = new WP_Query(
					array(
						'post_type'              => $targets['post_types'],
						'post_status'            => 'any',
						'posts_per_page'         => 1,
						'fields'                 => 'ids',
						'no_found_rows'          => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				return (int) $q->found_posts;

			case 'term':
				if ( ! $targets['taxonomies'] ) {
					return 0;
				}
				$n = wp_count_terms(
					array(
						'taxonomy'   => $targets['taxonomies'],
						'hide_empty' => false,
					)
				);
				return is_wp_error( $n ) ? 0 : (int) $n;

			case 'user':
				if ( ! $targets['user'] ) {
					return 0;
				}
				$uq = new WP_User_Query(
					array(
						'number' => 1,
						'fields' => 'ID',
					)
				);
				return (int) $uq->get_total();

			case 'comment':
				if ( ! $targets['comment'] ) {
					return 0;
				}
				return (int) get_comments( array( 'count' => true ) );
		}

		return 0;
	}

	/**
	 * A stored value decoded, with object instantiation refused.
	 *
	 * Use instead of maybe_unserialize() on anything read from another plugin's tables. Meta APIs
	 * already unserialize once, so a second decode only ever meets strings a user typed: a
	 * Contributor can store a serialized object string as a field value, and maybe_unserialize()
	 * would instantiate it during an admin's import (PHP object injection). Field data is only
	 * ever arrays and scalars, so refusing objects cannot change a legitimate result.
	 *
	 * @param mixed $raw Stored value.
	 * @return mixed
	 */
	protected function decode_stored( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}
		$trimmed = trim( $raw );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes false makes this a pure array/scalar decode; a malformed row falls back to the raw string.
		$decoded = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
		if ( false === $decoded && 'b:0;' !== $trimmed ) {
			return $raw;
		}
		return $decoded;
	}

	/**
	 * Read a raw meta value for any object type, bypassing the source plugin's own API.
	 * get_field() / pods()->field() return display-shaped data, not what is stored.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @param string $key         Meta key.
	 * @param bool   $single      Single value or all rows.
	 * @return mixed
	 */
	protected function raw_meta( $object_type, $object_id, $key, $single = true ) {
		switch ( $object_type ) {
			case 'term':
				return get_term_meta( (int) $object_id, $key, $single );
			case 'user':
				return get_user_meta( (int) $object_id, $key, $single );
			case 'comment':
				return get_comment_meta( (int) $object_id, $key, $single );
			default:
				return get_post_meta( (int) $object_id, $key, $single );
		}
	}

	/**
	 * Normalise a field name to something Uichemy will accept as a meta-key segment.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	protected function clean_name( $name ) {
		return sanitize_key( (string) $name );
	}

	/**
	 * Source-level notes for the dry run. Override to explain what this source cannot carry.
	 *
	 * @return string[]
	 */
	public function scan_notes() {
		return array();
	}

	// ----------------------------------------------------------------- Advanced settings
	// -----------------------------------------------------------------.

	/**
	 * Advanced keys whose value is only ever APPLIED when Advanced Settings is on.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return string[]
	 */
	protected function advanced_keys( $kind ) {
		if ( 'taxonomy' === $kind ) {
			return array(
				'show_ui', 'show_admin_column', 'show_tag_cloud', 'show_in_nav_menus', 'show_in_rest',
				'rewrite', 'rewrite_slug', 'rewrite_hierarchical', 'query_var', 'query_var_string',
				'rest_base', 'rest_controller_class', 'sort', 'capabilities', 'default_term_enabled',
				'default_term', 'labels',
			);
		}
		return array(
			'show_in_menu', 'show_in_admin_bar', 'show_in_nav_menus', 'exclude_from_search',
			'publicly_queryable', 'can_export', 'delete_with_user', 'menu_position', 'archive_slug',
			'rewrite_enabled', 'rewrite_slug', 'rewrite_with_front', 'rewrite_pages', 'rewrite_feeds',
			'query_var', 'query_var_string', 'show_in_rest', 'rest_base', 'rest_controller_class',
			'capability_type', 'map_meta_cap', 'capabilities', 'labels',
		);
	}

	/**
	 * The value Uichemy would actually register for each advanced key if the key were absent.
	 *
	 * Not the same as the stored defaults: several keys have no stored default and are DERIVED at register
	 * time (show_in_rest and publicly_queryable from `public`, rewrite_slug from the key), so comparing
	 * against the defaults array alone reports a matching value as a difference.
	 *
	 * @param array  $data Mapped Uichemy definition data.
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @return array
	 */
	protected function effective_defaults( $data, $kind ) {
		$public = ! empty( $data['public'] );

		if ( 'taxonomy' === $kind ) {
			$key = isset( $data['taxonomy_key'] ) ? $data['taxonomy_key'] : '';
			return array_merge(
				Uich_Fields_Fields::get_taxonomy_defaults(),
				array(
					'show_in_rest'          => $public,
					'rewrite_slug'          => $key,
					'query_var_string'      => '',
					'rest_base'             => '',
					'rest_controller_class' => '',
				)
			);
		}

		$key = isset( $data['post_type_key'] ) ? $data['post_type_key'] : '';
		return array_merge(
			Uich_Fields_Fields::get_cpt_defaults(),
			array(
				'show_in_rest'          => $public,
				'publicly_queryable'    => $public,
				'show_in_menu'          => ! empty( $data['show_ui'] ),
				'rewrite_slug'          => $key,
				'rewrite_with_front'    => false,
				'archive_slug'          => '',
				'query_var_string'      => '',
				'rest_base'             => '',
				'rest_controller_class' => '',
				'capability_type'       => 'post',
				'exclude_from_search'   => false,
				'delete_with_user'      => false,
			)
		);
	}

	/**
	 * Whether the imported definition needs Advanced Settings switched on.
	 *
	 * register_one_cpt()/register_one_taxonomy() only APPLY the advanced args when this flag is true, so
	 * carrying advanced values with the flag off stores them and registers none of them.
	 *
	 * @param array  $data           Mapped Uichemy definition data.
	 * @param string $kind           'cpt' or 'taxonomy'.
	 * @param bool   $source_advanced Whether the source itself marked the definition as advanced.
	 * @return bool
	 */
	protected function map_advanced_flag( $data, $kind, $source_advanced = false ) {
		if ( $source_advanced ) {
			return true;
		}
		$effective = $this->effective_defaults( $data, $kind );
		foreach ( $this->advanced_keys( $kind ) as $k ) {
			if ( ! array_key_exists( $k, $data ) || ! array_key_exists( $k, $effective ) ) {
				continue;
			}
			// Loose compare: JSON round trips turn booleans into 0/1 and ints into strings.
			if ( $data[ $k ] != $effective[ $k ] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- see above.
				return true;
			}
		}
		return false;
	}

	/**
	 * Label overrides, minus the ones that already became label / singular_label.
	 *
	 * @param array    $labels Source labels array.
	 * @param string[] $skip   Label keys to drop.
	 * @return array
	 */
	protected function map_labels( $labels, $skip = array( 'name', 'singular_name' ) ) {
		$out = array();
		foreach ( (array) $labels as $lk => $lv ) {
			if ( in_array( $lk, $skip, true ) || ! is_scalar( $lv ) || '' === trim( (string) $lv ) ) {
				continue;
			}
			$out[ $lk ] = (string) $lv;
		}
		return $out;
	}

	/**
	 * Whether this site's permalink structure has a front base before the post name.
	 *
	 * with_front only changes a URL when there is a front base to prepend; without one it is a no-op,
	 * so carrying it would switch Advanced Settings on for no visible difference.
	 *
	 * @return bool
	 */
	protected static function permalink_has_front() {
		$structure = ltrim( (string) get_option( 'permalink_structure' ), '/' );
		return ( '' !== $structure && 0 !== strpos( $structure, '%' ) );
	}

	/**
	 * A source's nested/boolean `rewrite` setting mapped to this module's flat rewrite_* keys.
	 *
	 * Accepts the shape WordPress itself takes — false, true, or an array of slug/with_front/pages/feeds
	 * (plus `hierarchical` for taxonomies) — which is what Pods and Meta Box both store.
	 *
	 * @param mixed  $rewrite Source rewrite value.
	 * @param string $key     Post type / taxonomy key.
	 * @param string $kind    'cpt' or 'taxonomy'.
	 * @return array
	 */
	protected function map_wp_rewrite( $rewrite, $key, $kind ) {
		$is_tax  = ( 'taxonomy' === $kind );
		$enabled = ( false !== $rewrite && 0 !== $rewrite && '0' !== $rewrite );
		$out     = array( $is_tax ? 'rewrite' : 'rewrite_enabled' => $enabled );

		if ( ! is_array( $rewrite ) ) {
			return $out;
		}
		if ( ! empty( $rewrite['slug'] ) && $rewrite['slug'] !== $key ) {
			$out['rewrite_slug'] = (string) $rewrite['slug'];
		}
		if ( $is_tax ) {
			if ( isset( $rewrite['hierarchical'] ) ) {
				$out['rewrite_hierarchical'] = ! empty( $rewrite['hierarchical'] );
			}
			return $out;
		}
		if ( isset( $rewrite['with_front'] ) && ( self::permalink_has_front() || empty( $rewrite['with_front'] ) ) ) {
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
}
