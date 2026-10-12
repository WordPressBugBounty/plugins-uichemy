<?php
/**
 * Conditionally register Custom Post Types and Taxonomies from uich_content_types posts.
 * Optimized: minimal DB load, caching, secure validation. Only runs when needed.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Register
 */
class Uich_Fields_Register {

	const CACHE_GROUP   = 'uich_content_types';
	const CACHE_KEY_CPT = 'uich_registered_cpt';
	const CACHE_KEY_TAX = 'uich_registered_tax';

	/**
	 * Transient holding the fully-sanitized register_post_type / register_taxonomy args, so register_all() on
	 * every front-end init doesn't re-sanitize every definition from raw meta.
	 */
	const REGISTERED_ARGS_TRANSIENT = 'uich_ct_registered_args_v1';
	const REGISTERED_ARGS_TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * One-shot option flag: set when a definition is saved/deleted, consumed on the next init
	 * (after register_all) to flush rewrite rules so new CPT/taxonomy URLs work immediately.
	 */
	const FLUSH_FLAG = 'uich_ct_flush_rewrite';

	/**
	 * One-shot flag for the private-taxonomy REST exposure migration.
	 */
	const REST_EXPOSURE_MIGRATED       = 'uich_ct_tax_rest_exposure_migrated';
	const CPT_CAPABILITY_OVERRIDE_KEYS = array(
		'edit_post',
		'read_post',
		'delete_post',
		'edit_posts',
		'edit_others_posts',
		'publish_posts',
		'read_private_posts',
	);

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_Register|null
	 */
	private static $instance = null;

	/**
	 * Registration collisions detected in register_all(): [ 'cpt'|'taxonomy' => [ key => true ] ].
	 *
	 * @var array
	 */
	private $collisions = array();

	/**
	 * Taxonomies whose configured default term could not be created in register_one_taxonomy():
	 * [ taxonomy key => configured term name ]. Surfaced by render_default_term_notice().
	 *
	 * @var array<string, string>
	 */
	private $default_term_failures = array();

	/**
	 * Keys this module registered in register_all(), so deactivation unregisters only its own.
	 *
	 * @var array{cpt: string[], tax: string[]}
	 */
	private $registered_keys = array(
		'cpt' => array(),
		'tax' => array(),
	);

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_Register
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_all' ), 15 );
		// Runs once, in admin only (a single cheap option read on other requests).
		add_action( 'admin_init', array( $this, 'maybe_migrate_private_taxonomy_rest_exposure' ) );
		// Surface registration collisions (silent drops) to admins instead of failing quietly.
		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_collision_notice' ) );
			add_action( 'admin_notices', array( $this, 'render_default_term_notice' ) );
		}
		// Flush rewrite rules once on the next init after register_all (priority 16), so new permalinks resolve immediately.
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 16 );
		add_action( 'save_post_' . Uich_Fields_CPT::POST_TYPE, array( $this, 'request_rewrite_flush' ), 10, 0 );
		add_action( 'deleted_post', array( $this, 'maybe_request_flush_for_post' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'maybe_request_flush_for_post' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'maybe_request_flush_for_post' ), 10, 1 );
		// F-04's before_delete_post handler is gone.
		// Every trigger above is a post hook, and definitions are written to JSON files now, so an
		// import that creates a post type never asked for a flush: its permalinks 301 to the home
		// page until someone opens Settings > Permalinks. These are the module's own definition
		// actions, which every write path already fires.
		foreach ( array( 'uich/definition_saved', 'uich/definition_changed' ) as $uich_def_hook ) {
			add_action( $uich_def_hook, array( $this, 'maybe_request_flush_for_kind' ), 10, 2 );
		}
	}

	/**
	 * One-time repair of taxonomy definitions saved while `show_in_rest` defaulted to a hardcoded true.
	 */
	public function maybe_migrate_private_taxonomy_rest_exposure() {
		if ( uich_fields_flag( 'tax_rest_exposure', self::REST_EXPOSURE_MIGRATED ) ) {
			return;
		}
		// Set the flag before doing the work: a fatal midway must not leave this retrying on every
		// admin request. The repair is idempotent, so a partial run is safe to leave as-is.
		uich_fields_flag_set( 'tax_rest_exposure', 1 );

		$meta_key = Uich_Fields_CPT::get_meta_key();
		$repo     = Uich_Fields_Definitions_Repository::get_instance();
		$changed  = false;

		foreach ( $repo->get_rows( array( 'include_trash' => true ) ) as $row ) {
			if ( 'taxonomy' !== $row['kind'] ) {
				continue;
			}
			$meta = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			if ( ! array_key_exists( 'show_in_rest', $meta ) || ! $meta['show_in_rest'] ) {
				continue;
			}
			// Absent `public` means public (that is the default), so only an explicit false qualifies.
			if ( ! array_key_exists( 'public', $meta ) || $meta['public'] ) {
				continue;
			}
			unset( $meta['show_in_rest'] );
			$row_id = (int) $row['id'];
			// Definitions are JSON files now and their ids are derived (100000+), not post ids:
			// update_post_meta() on such an id wrote this meta onto whatever unrelated post
			// happened to have it. Write each row back to where it actually lives.
			$found = class_exists( 'Uich_Fields_Model_Writer' ) ? Uich_Fields_Model_Writer::lookup( $row_id ) : null;
			if ( $found ) {
				if ( empty( $found['trashed'] ) ) {
					Uich_Fields_Model_Writer::save( $found['kind'], $meta, $row_id );
					$changed = true;
				}
			} elseif ( Uich_Fields_CPT::POST_TYPE === get_post_type( $row_id ) ) {
				update_post_meta( $row_id, $meta_key, $meta );
				$changed = true;
			}
		}

		if ( $changed ) {
			$repo->flush();
		}
	}

	/**
	 * Whether a query_var / rewrite slug collides with a reserved WordPress query var.
	 *
	 * @param string $value Sanitized slug / query var.
	 * @return bool
	 */
	private static function is_reserved_var( $value ) {
		$value = (string) $value;
		if ( '' === $value || ! class_exists( 'Uich_Fields_Admin_Logic' ) ) {
			return false;
		}
		return in_array( $value, Uich_Fields_Admin_Logic::reserved_keys(), true );
	}

	/**
	 * Ask for a rewrite flush when a post type or taxonomy definition changes.
	 *
	 * Field groups are skipped: they register no rewrite rules, and flushing on every group save
	 * would rebuild the whole rule set for nothing.
	 *
	 * @param int    $post_id Unused; the action's signature.
	 * @param string $kind    'cpt', 'taxonomy' or 'group_fields'.
	 * @return void
	 */
	public function maybe_request_flush_for_kind( $post_id, $kind = '' ) {
		unset( $post_id );
		if ( 'cpt' === $kind || 'taxonomy' === $kind || 'tax' === $kind ) {
			$this->request_rewrite_flush();
		}
	}

	/**
	 * Mark that rewrite rules need flushing on the next request. Cheap option write; the actual
	 * (expensive) flush is deferred to the next init, after the new type is registered.
	 */
	public function request_rewrite_flush() {
		// autoload ON: this flag is read on EVERY init (maybe_flush_rewrite_rules at priority 16), so it is a
		// hot-path option and must ride along in wp_load_alloptions() rather than cost its own query.
		update_option( self::FLUSH_FLAG, 1, true );
	}

	/**
	 * Request a flush only when the affected post is one of our definition posts.
	 *
	 * @param int $post_id Post ID.
	 */
	public function maybe_request_flush_for_post( $post_id ) {
		if ( Uich_Fields_CPT::POST_TYPE === get_post_type( $post_id ) ) {
			$this->request_rewrite_flush();
		}
	}

	/**
	 * Consume the one-shot flag and flush rewrite rules. Runs on init at priority 16, i.e. after
	 * register_all (15) has registered every CPT/taxonomy, so the regenerated rules include them.
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( self::FLUSH_FLAG ) ) {
			// Reset to 0 rather than delete: a missing option isn't in alloptions, costing a SELECT on every request.
			update_option( self::FLUSH_FLAG, 0, true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Flush cache when a content type post is saved or deleted.
	 *
	 * @param int     $post_id Post ID (optional).
	 * @param WP_Post $post    Post object (optional).
	 * @param bool    $update  Whether this is an existing post update (optional).
	 */
	public function flush_cache( $post_id = 0, $post = null, $update = false ) {
		Uich_Fields_Definitions_Repository::get_instance()->flush();
	}

	/**
	 * Get all published content type definitions (CPT and Taxonomy).
	 *
	 * @return array{ cpt: array, tax: array }
	 */
	private function get_definitions() {
		// Serve the already-sanitized args from cache so init doesn't re-sanitize every definition (labels, caps,
		// rewrite, REST) from raw meta on every front-end request.
		$cached = get_transient( self::REGISTERED_ARGS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['cpt'], $cached['tax'] ) ) {
			return $cached;
		}

		$cpt_list = array();
		$tax_list = array();

		foreach ( Uich_Fields_Definitions_Repository::get_instance()->get_rows() as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$raw = $row['meta'];
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$kind = isset( $raw['kind'] ) ? sanitize_key( $raw['kind'] ) : '';
			if ( 'cpt' === $kind ) {
				$args = $this->sanitize_cpt_args( $raw );
				if ( ! empty( $args ) ) {
					$cpt_list[ $row['id'] ] = $args;
				}
			} elseif ( 'taxonomy' === $kind ) {
				$args = $this->sanitize_tax_args( $raw );
				if ( ! empty( $args ) ) {
					$tax_list[ $row['id'] ] = $args;
				}
			}
		}

		$out = array(
			'cpt' => $cpt_list,
			'tax' => $tax_list,
		);
		set_transient( self::REGISTERED_ARGS_TRANSIENT, $out, self::REGISTERED_ARGS_TTL );
		return $out;
	}

	/**
	 * Sanitize and validate CPT args from meta.
	 *
	 * @param array $raw Raw meta array.
	 * @return array|null Sanitized args for register_post_type or null if invalid.
	 */
	private function sanitize_cpt_args( $raw ) {
		$key = isset( $raw['post_type_key'] ) ? sanitize_key( $raw['post_type_key'] ) : '';
		if ( empty( $key ) || strlen( $key ) > 20 ) {
			return null;
		}
		// Reserved query vars / terms / built-ins — never register these (they corrupt core
		// URL parsing when injected into public_query_vars). Shared with the save-time gate.
		if ( class_exists( 'Uich_Fields_Admin_Logic' ) && in_array( $key, Uich_Fields_Admin_Logic::reserved_keys(), true ) ) {
			return null;
		}

		$advanced_enabled = array_key_exists( 'advanced_settings_enabled', $raw ) ? (bool) $raw['advanced_settings_enabled'] : true;

		$label    = isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : $key;
		$singular = isset( $raw['singular_label'] ) ? sanitize_text_field( $raw['singular_label'] ) : $label;
		$public   = ! empty( $raw['public'] );
		$show_ui  = isset( $raw['show_ui'] ) ? (bool) $raw['show_ui'] : true;
		// A private CPT must not be exposed in the REST API by default — only follow $public
		// unless the admin explicitly opted in/out via the stored show_in_rest key.
		$show_in_rest = isset( $raw['show_in_rest'] ) ? (bool) $raw['show_in_rest'] : $public;
		$hierarchical = ! empty( $raw['hierarchical'] );
		$has_archive  = ! empty( $raw['has_archive'] );
		// Custom archive slug (optional). Empty => archive uses the post type key when enabled.
		$archive_slug = isset( $raw['archive_slug'] ) ? sanitize_title( $raw['archive_slug'] ) : '';
		$active       = ! isset( $raw['active'] ) || (bool) $raw['active'];
		$description  = isset( $raw['description'] ) ? sanitize_textarea_field( $raw['description'] ) : '';

		// Taxonomies stored in meta are already sanitized slugs by Ajax layer, but normalize just in case.
		$taxonomies = array();
		if ( ! empty( $raw['taxonomies'] ) ) {
			if ( is_array( $raw['taxonomies'] ) ) {
				$taxonomies = $raw['taxonomies'];
			} else {
				$taxonomies = explode( ',', (string) $raw['taxonomies'] );
			}
			$taxonomies = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						array_map( 'trim', $taxonomies )
					)
				)
			);
		}

		// Supports come in as a flat list of enabled features.
		$supports = null;
		if ( array_key_exists( 'supports', $raw ) && is_array( $raw['supports'] ) ) {
			$supports = array_values(
				array_filter(
					array_map( 'sanitize_key', $raw['supports'] )
				)
			);
		}
		// An empty array is a deliberate choice (no supports, or custom-only via filter), so it is preserved as-is.

		// Labels overrides (subset of register_post_type labels).
		$labels_overrides = array();
		if ( ! empty( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
			foreach ( $raw['labels'] as $label_key => $label_value ) {
				$label_value = (string) $label_value;
				if ( '' === trim( $label_value ) ) {
					continue;
				}
				$labels_overrides[ sanitize_key( $label_key ) ] = sanitize_text_field( $label_value );
			}
		}

		// Visibility & admin UI flags.
		$show_in_menu      = isset( $raw['show_in_menu'] )
			? ( is_string( $raw['show_in_menu'] ) && '' !== $raw['show_in_menu'] ? $raw['show_in_menu'] : (bool) $raw['show_in_menu'] )
			: $show_ui;
		$show_in_admin_bar = isset( $raw['show_in_admin_bar'] ) ? (bool) $raw['show_in_admin_bar'] : true;
		$show_in_nav_menus = isset( $raw['show_in_nav_menus'] ) ? (bool) $raw['show_in_nav_menus'] : true;
		// Derive from $public when the admin never set it, matching register_post_type()'s own default of
		// exclude_from_search = !
		$exclude_from_search = isset( $raw['exclude_from_search'] ) ? (bool) $raw['exclude_from_search'] : ! $public;
		$publicly_queryable  = isset( $raw['publicly_queryable'] ) ? (bool) $raw['publicly_queryable'] : $public;
		$can_export          = isset( $raw['can_export'] ) ? (bool) $raw['can_export'] : true;
		$delete_with_user    = ! empty( $raw['delete_with_user'] );

		$menu_position = isset( $raw['menu_position'] ) && '' !== $raw['menu_position'] ? (int) $raw['menu_position'] : null;
		// Fall back to a default dashicon when the icon is absent OR an empty string (sanitize_meta always sets
		// the key, so an empty value is the common case) — otherwise the menu item shows no icon at all.
		$menu_icon = ( isset( $raw['menu_icon'] ) && '' !== trim( (string) $raw['menu_icon'] ) ) ? sanitize_text_field( $raw['menu_icon'] ) : 'dashicons-admin-post';

		// Rewrite & query options.
		$rewrite_enabled = isset( $raw['rewrite_enabled'] ) ? (bool) $raw['rewrite_enabled'] : true;
		$rewrite_slug    = isset( $raw['rewrite_slug'] ) ? sanitize_title( $raw['rewrite_slug'] ) : $key;
		if ( '' === $rewrite_slug || self::is_reserved_var( $rewrite_slug ) ) {
			$rewrite_slug = $key;
		}

		$rewrite_with_front = isset( $raw['rewrite_with_front'] ) ? (bool) $raw['rewrite_with_front'] : false;
		$rewrite_pages      = isset( $raw['rewrite_pages'] ) ? (bool) $raw['rewrite_pages'] : true;
		$rewrite_feeds      = isset( $raw['rewrite_feeds'] ) ? (bool) $raw['rewrite_feeds'] : true;

		$query_var        = isset( $raw['query_var'] ) ? (bool) $raw['query_var'] : true;
		$query_var_string = isset( $raw['query_var_string'] ) ? sanitize_title( $raw['query_var_string'] ) : '';
		// Never register a reserved query var (e.g.
		if ( $query_var && '' !== $query_var_string && ! self::is_reserved_var( $query_var_string ) ) {
			$query_var = $query_var_string;
		}

		// REST options.
		$rest_base             = isset( $raw['rest_base'] ) ? sanitize_title( $raw['rest_base'] ) : '';
		$rest_controller_class = isset( $raw['rest_controller_class'] ) ? sanitize_text_field( $raw['rest_controller_class'] ) : '';

		// Capability options.
		$capability_type = isset( $raw['capability_type'] ) ? sanitize_key( $raw['capability_type'] ) : 'post';
		if ( '' === $capability_type ) {
			$capability_type = 'post';
		}
		$map_meta_cap = isset( $raw['map_meta_cap'] ) ? (bool) $raw['map_meta_cap'] : true;

		$capabilities = array();
		if ( $map_meta_cap && ! empty( $raw['capabilities'] ) && is_array( $raw['capabilities'] ) ) {
			// Iterate the fixed key allowlist, not the raw payload, so this matches the save-side TYPE_CAPABILITIES sanitizer.
			$allowed        = class_exists( 'Uich_Fields_Fields' ) ? Uich_Fields_Fields::get_cpt_capability_keys() : array();
			$unsafe_targets = class_exists( 'Uich_Fields_Fields' ) ? Uich_Fields_Fields::unsafe_capability_targets() : array( 'read', 'exist', 'level_0' );
			foreach ( $allowed as $cap_key ) {
				if ( ! isset( $raw['capabilities'][ $cap_key ] ) || '' === trim( (string) $raw['capabilities'][ $cap_key ] ) ) {
					continue;
				}
				$cap_value = sanitize_key( (string) $raw['capabilities'][ $cap_key ] );
				// Skip empty and broadly-held targets (e.g. 'read') so a stale/malicious mapping
				// stored before this guard can't grant the capability to every logged-in user.
				if ( '' === $cap_value || in_array( $cap_value, $unsafe_targets, true ) ) {
					continue;
				}
				// Also skip a meta cap stored before that guard existed which targets a core primitive
				// cap: registering it would lock the whole site out (see reserved_meta_cap_targets()).
				// Dropping it here lets an already-affected site recover on the next request.
				if ( class_exists( 'Uich_Fields_Fields' ) && Uich_Fields_Fields::is_refused_capability_target( $cap_key, $cap_value ) ) {
					continue;
				}
				$capabilities[ $cap_key ] = $cap_value;
			}
		}
		if ( $map_meta_cap ) {
			foreach ( self::CPT_CAPABILITY_OVERRIDE_KEYS as $cap_key ) {
				if ( isset( $capabilities[ $cap_key ] ) && '' !== trim( (string) $capabilities[ $cap_key ] ) ) {
					continue;
				}
				$capabilities[ $cap_key ] = $this->get_default_cpt_capability_value( $cap_key, $capability_type );
			}
		}

		// Skip inactive CPTs entirely.
		if ( ! $active ) {
			return null;
		}

		return array(
			'key'                       => $key,
			'label'                     => $label,
			'singular_label'            => $singular,
			'public'                    => $public,
			'show_ui'                   => $show_ui,
			'show_in_rest'              => $show_in_rest,
			'hierarchical'              => $hierarchical,
			'has_archive'               => $has_archive,
			'archive_slug'              => $archive_slug,
			'description'               => $description,
			'taxonomies'                => $taxonomies,
			'supports'                  => $supports,
			'labels_overrides'          => $labels_overrides,
			'show_in_menu'              => $show_in_menu,
			'show_in_admin_bar'         => $show_in_admin_bar,
			'show_in_nav_menus'         => $show_in_nav_menus,
			'exclude_from_search'       => $exclude_from_search,
			'publicly_queryable'        => $publicly_queryable,
			'can_export'                => $can_export,
			'delete_with_user'          => $delete_with_user,
			'menu_position'             => $menu_position,
			'menu_icon'                 => $menu_icon,
			'rewrite_enabled'           => $rewrite_enabled,
			'rewrite_slug'              => $rewrite_slug,
			'rewrite_with_front'        => $rewrite_with_front,
			'rewrite_pages'             => $rewrite_pages,
			'rewrite_feeds'             => $rewrite_feeds,
			'query_var'                 => $query_var,
			'rest_base'                 => $rest_base,
			'rest_controller_class'     => $rest_controller_class,
			'capability_type'           => $capability_type,
			'map_meta_cap'              => $map_meta_cap,
			'capabilities'              => $capabilities,
			// Honor the "Advanced Settings" toggle: when off, register_one_cpt() uses the
			// minimal-args branch (WordPress defaults for rewrite/REST/archive/etc.).
			'advanced_settings_enabled' => $advanced_enabled,
		);
	}

	/**
	 * Sanitize and validate taxonomy args from meta. Uses all keys from Uich_Fields_Fields.
	 *
	 * @param array $raw Raw meta array.
	 * @return array|null Sanitized args for register_taxonomy or null if invalid.
	 */
	private function sanitize_tax_args( $raw ) {
		$key = isset( $raw['taxonomy_key'] ) ? sanitize_key( $raw['taxonomy_key'] ) : '';
		if ( empty( $key ) || strlen( $key ) > 32 ) {
			return null;
		}
		// Reserved query vars / terms / built-ins — never register these. Shared with the save-time gate.
		if ( class_exists( 'Uich_Fields_Admin_Logic' ) && in_array( $key, Uich_Fields_Admin_Logic::reserved_keys(), true ) ) {
			return null;
		}

		$advanced_enabled = array_key_exists( 'advanced_settings_enabled', $raw ) ? (bool) $raw['advanced_settings_enabled'] : true;

		$label        = isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : $key;
		$singular     = isset( $raw['singular_label'] ) ? sanitize_text_field( $raw['singular_label'] ) : $label;
		$hierarchical = ! empty( $raw['hierarchical'] );
		$object_type  = isset( $raw['object_type'] ) && is_array( $raw['object_type'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw['object_type'] ) ) ) )
			: array( 'post' );
		if ( empty( $object_type ) ) {
			$object_type = array( 'post' );
		}

		$rewrite_slug = isset( $raw['rewrite_slug'] ) ? sanitize_title( $raw['rewrite_slug'] ) : $key;
		if ( '' === $rewrite_slug || self::is_reserved_var( $rewrite_slug ) ) {
			$rewrite_slug = $key;
		}
		$rewrite              = isset( $raw['rewrite'] ) ? (bool) $raw['rewrite'] : true;
		$rewrite_hierarchical = ! empty( $raw['rewrite_hierarchical'] );
		$public               = isset( $raw['public'] ) ? (bool) $raw['public'] : true;
		// A private taxonomy must not be exposed in the REST API by default — only follow $public unless the
		// admin explicitly opted in/out via the stored show_in_rest key.
		$show_in_rest      = isset( $raw['show_in_rest'] ) ? (bool) $raw['show_in_rest'] : $public;
		$show_ui           = isset( $raw['show_ui'] ) ? (bool) $raw['show_ui'] : true;
		$show_admin_column = isset( $raw['show_admin_column'] ) ? (bool) $raw['show_admin_column'] : true;
		$show_tag_cloud    = isset( $raw['show_tag_cloud'] ) ? (bool) $raw['show_tag_cloud'] : true;
		$show_in_nav_menus = isset( $raw['show_in_nav_menus'] ) ? (bool) $raw['show_in_nav_menus'] : true;
		$query_var         = isset( $raw['query_var'] ) ? (bool) $raw['query_var'] : true;
		$query_var_string  = isset( $raw['query_var_string'] ) ? sanitize_title( $raw['query_var_string'] ) : '';
		// Never register a reserved query var (e.g. cat/tag/name/author) — it corrupts core URL parsing.
		if ( self::is_reserved_var( $query_var_string ) ) {
			$query_var_string = '';
		}
		$rest_base             = isset( $raw['rest_base'] ) ? sanitize_title( $raw['rest_base'] ) : '';
		$rest_controller_class = isset( $raw['rest_controller_class'] ) ? sanitize_text_field( $raw['rest_controller_class'] ) : '';
		$active                = ! isset( $raw['active'] ) || (bool) $raw['active'];
		// null when absent, so the arg can be omitted and core's own default applies.
		$sort                 = array_key_exists( 'sort', $raw ) ? (bool) $raw['sort'] : null;
		$description          = isset( $raw['description'] ) ? sanitize_textarea_field( $raw['description'] ) : '';
		$default_term_enabled = ! empty( $raw['default_term_enabled'] );
		$default_term         = array(
			'name'        => '',
			'slug'        => '',
			'description' => '',
		);
		if ( $default_term_enabled && ! empty( $raw['default_term'] ) && is_array( $raw['default_term'] ) ) {
			if ( isset( $raw['default_term']['name'] ) && '' !== trim( (string) $raw['default_term']['name'] ) ) {
				$default_term['name']        = sanitize_text_field( $raw['default_term']['name'] );
				$default_term['slug']        = isset( $raw['default_term']['slug'] ) ? sanitize_title( $raw['default_term']['slug'] ) : sanitize_title( $default_term['name'] );
				$default_term['description'] = isset( $raw['default_term']['description'] ) ? sanitize_textarea_field( $raw['default_term']['description'] ) : '';
			}
		}
		if ( '' === $default_term['name'] ) {
			$default_term = null;
		}

		$labels_overrides = array();
		if ( ! empty( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
			foreach ( $raw['labels'] as $label_key => $label_value ) {
				$label_value = (string) $label_value;
				if ( '' !== trim( $label_value ) ) {
					$labels_overrides[ sanitize_key( $label_key ) ] = sanitize_text_field( $label_value );
				}
			}
		}

		$capabilities = array();
		if ( ! empty( $raw['capabilities'] ) && is_array( $raw['capabilities'] ) ) {
			$allowed        = Uich_Fields_Fields::get_taxonomy_capability_keys();
			$unsafe_targets = Uich_Fields_Fields::unsafe_capability_targets();
			foreach ( $allowed as $cap_key ) {
				if ( isset( $raw['capabilities'][ $cap_key ] ) && '' !== trim( (string) $raw['capabilities'][ $cap_key ] ) ) {
					$cap_value = sanitize_key( (string) $raw['capabilities'][ $cap_key ] );
					// Reject broadly-held targets so a stale mapping can't grant term caps to everyone.
					if ( '' === $cap_value || in_array( $cap_value, $unsafe_targets, true ) ) {
						continue;
					}
					$capabilities[ $cap_key ] = $cap_value;
				}
			}
		}

		if ( ! $active ) {
			return null;
		}

		return array(
			'key'                       => $key,
			'label'                     => $label,
			'singular_label'            => $singular,
			'hierarchical'              => $hierarchical,
			'object_type'               => $object_type,
			'show_in_rest'              => $show_in_rest,
			'rewrite_slug'              => $rewrite_slug,
			'rewrite'                   => $rewrite,
			'rewrite_hierarchical'      => $rewrite_hierarchical,
			'public'                    => $public,
			'show_ui'                   => $show_ui,
			'show_admin_column'         => $show_admin_column,
			'show_tag_cloud'            => $show_tag_cloud,
			'show_in_nav_menus'         => $show_in_nav_menus,
			// The custom string only names the query var — it must not resurrect one the admin turned off.
			'query_var'                 => $query_var ? ( '' !== $query_var_string ? $query_var_string : true ) : false,
			'labels_overrides'          => $labels_overrides,
			'capabilities'              => $capabilities,
			'rest_base'                 => $rest_base,
			'rest_controller_class'     => $rest_controller_class,
			'show_in_rest_stored'       => isset( $raw['show_in_rest'] ),
			// May be null — register_one_taxonomy() only forwards it when it is a real bool.
			'sort'                      => $sort,
			'description'               => $description,
			'default_term'              => $default_term,
			// Honor the "Advanced Settings" toggle: when off, register_one_taxonomy() uses the
			// minimal-args branch (WordPress defaults for rewrite/REST/etc.).
			'advanced_settings_enabled' => $advanced_enabled,
		);
	}

	/**
	 * Register all CPTs and taxonomies from definitions.
	 */
	public function register_all() {
		$def = $this->get_definitions();

		// Taxonomies first: register_post_type() attaches them via register_taxonomy_for_object_type(), which no-ops if absent.
		$registered_tax = array();
		foreach ( $def['tax'] as $args ) {
			if ( empty( $args ) ) {
				continue;
			}
			$key = $args['key'];
			if ( taxonomy_exists( $key ) ) {
				// Either a third party owns the key or a sibling definition claimed it this pass; surface both.
				$this->collisions['taxonomy'][ $key ] = true;
				continue;
			}
			$this->register_one_taxonomy( $args );
			$registered_tax[ $key ]        = true;
			$this->registered_keys['tax'][] = $key;
		}

		$registered_cpt = array();
		foreach ( $def['cpt'] as $args ) {
			if ( empty( $args ) ) {
				continue;
			}
			$key = $args['key'];
			if ( post_type_exists( $key ) ) {
				// Existed before this arg registered => owned by core / another plugin (earlier init priority) when
				// !isset(registered_cpt), OR a sibling Uichemy definition already claimed the key this pass.
				$this->collisions['cpt'][ $key ] = true;
				continue;
			}
			$this->register_one_cpt( $args );
			$registered_cpt[ $key ]        = true;
			$this->registered_keys['cpt'][] = $key;
		}
	}

	/**
	 * Unregister the post types and taxonomies this module registered, then rebuild the rewrite
	 * rules, so their URLs 404 once the plugin is deactivated. A key owned by core or another
	 * plugin was never recorded, so it is never touched.
	 *
	 * @return void
	 */
	public function unregister_all() {
		foreach ( $this->registered_keys['cpt'] as $key ) {
			if ( post_type_exists( $key ) ) {
				unregister_post_type( $key );
			}
		}
		foreach ( $this->registered_keys['tax'] as $key ) {
			if ( taxonomy_exists( $key ) ) {
				unregister_taxonomy( $key );
			}
		}
		if ( $this->registered_keys['cpt'] || $this->registered_keys['tax'] ) {
			$this->registered_keys = array(
				'cpt' => array(),
				'tax' => array(),
			);
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Admin notice listing definitions that couldn't register because their key is already owned by WordPress
	 * core or another plugin that registered at an earlier init priority.
	 */
	public function render_collision_notice() {
		if ( empty( $this->collisions ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$keys = array();
		foreach ( $this->collisions as $by_kind ) {
			foreach ( array_keys( (array) $by_kind ) as $k ) {
				$keys[] = $k;
			}
		}
		if ( empty( $keys ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Uichemy Content Types:', 'uichemy' ),
			sprintf(
				/* translators: %s: comma-separated list of colliding keys */
				esc_html__( 'These keys are already registered by WordPress or another plugin, so their definitions were not registered: %s. Rename them to a unique key.', 'uichemy' ),
				esc_html( implode( ', ', array_unique( $keys ) ) )
			)
		);
	}

	/**
	 * Admin notice for taxonomies whose configured default term could not be created.
	 * WordPress discards the wp_insert_term() error inside register_taxonomy().
	 */
	public function render_default_term_notice() {
		if ( empty( $this->default_term_failures ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$parts = array();
		foreach ( $this->default_term_failures as $tax_key => $term_name ) {
			$parts[] = '' !== $term_name ? $tax_key . ' (“' . $term_name . '”)' : $tax_key;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Uichemy Content Types:', 'uichemy' ),
			sprintf(
				/* translators: %s: comma-separated list of taxonomy keys and default term names */
				esc_html__( 'The default term could not be created for: %s. The term name may already be taken by another term, or its slug may collide with an existing one. Change the default term name or slug.', 'uichemy' ),
				esc_html( implode( ', ', $parts ) )
			)
		);
	}

	/**
	 * Register a single CPT with optimized rewrite and REST support.
	 *
	 * @param array $args Sanitized args from sanitize_cpt_args.
	 */
	private function register_one_cpt( $args ) {
		$key         = $args['key'];
		$label       = $args['label'];
		$singular    = $args['singular_label'];
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$taxonomies  = isset( $args['taxonomies'] ) && is_array( $args['taxonomies'] ) ? $args['taxonomies'] : array();
		// isset() is the load-bearing part: a null/absent supports key means the definition never expressed a
		// preference, so fall back to WordPress's own default (title + editor) rather than inventing a wider set.
		$supports = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array( 'title', 'editor' );
		$advanced = isset( $args['advanced_settings_enabled'] ) ? (bool) $args['advanced_settings_enabled'] : true;

		// A hierarchical CPT needs 'page-attributes' or WP shows no parent selector, leaving no UI path to set a parent.
		if ( ! empty( $args['hierarchical'] ) && ! in_array( 'page-attributes', $supports, true ) ) {
			$supports[] = 'page-attributes';
		}

		// register_post_type() treats an empty ARRAY as "unspecified" and hands back title+editor; only boolean
		// false means "no features".
		$supports_arg = empty( $supports ) ? false : $supports;

		$labels = array(
			'name'                  => $label,
			'singular_name'         => $singular,
			'add_new'               => __( 'Add New', 'uichemy' ),
			/* translators: %s: the content type name */
			'add_new_item'          => sprintf( __( 'Add New %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'edit_item'             => sprintf( __( 'Edit %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'new_item'              => sprintf( __( 'New %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'view_item'             => sprintf( __( 'View %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'view_items'            => sprintf( __( 'View %s', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'search_items'          => sprintf( __( 'Search %s', 'uichemy' ), $label ),
			'not_found'             => __( 'Nothing found', 'uichemy' ),
			'not_found_in_trash'    => __( 'Nothing found in Trash', 'uichemy' ),
			/* translators: %s: the content type name */
			'parent_item'           => sprintf( __( 'Parent %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'all_items'             => sprintf( __( 'All %s', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'archives'              => sprintf( __( '%s Archives', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'attributes'            => sprintf( __( '%s Attributes', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'insert_into_item'      => sprintf( __( 'Insert into %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'uichemy' ), $singular ),
			'featured_image'        => __( 'Featured image', 'uichemy' ),
			'set_featured_image'    => __( 'Set featured image', 'uichemy' ),
			'remove_featured_image' => __( 'Remove featured image', 'uichemy' ),
			'use_featured_image'    => __( 'Use as featured image', 'uichemy' ),
			/* translators: %s: the content type name */
			'items_list'            => sprintf( __( '%s list', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'items_list_navigation' => sprintf( __( '%s list navigation', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'filter_items_list'     => sprintf( __( 'Filter %s list', 'uichemy' ), $label ),
			'menu_name'             => $label,
		);

		if ( $advanced && ! empty( $args['labels_overrides'] ) && is_array( $args['labels_overrides'] ) ) {
			$labels = array_merge( $labels, $args['labels_overrides'] );
		}

		if ( $advanced ) {
			// Build rewrite arg.
			if ( isset( $args['rewrite_enabled'] ) && false === $args['rewrite_enabled'] ) {
				$rewrite = false;
			} else {
				$rewrite = array(
					'slug'       => $args['rewrite_slug'],
					'with_front' => isset( $args['rewrite_with_front'] ) ? (bool) $args['rewrite_with_front'] : false,
					'pages'      => isset( $args['rewrite_pages'] ) ? (bool) $args['rewrite_pages'] : true,
					'feeds'      => isset( $args['rewrite_feeds'] ) ? (bool) $args['rewrite_feeds'] : true,
				);
			}

			$query_var = true;
			if ( array_key_exists( 'query_var', $args ) ) {
				$query_var = $args['query_var'];
			}

			$cpt_args = array(
				'labels'              => $labels,
				'description'         => $description,
				'public'              => (bool) $args['public'],
				'show_ui'             => (bool) $args['show_ui'],
				'show_in_menu'        => isset( $args['show_in_menu'] ) ? $args['show_in_menu'] : (bool) $args['show_ui'],
				'show_in_admin_bar'   => isset( $args['show_in_admin_bar'] ) ? (bool) $args['show_in_admin_bar'] : true,
				'show_in_nav_menus'   => isset( $args['show_in_nav_menus'] ) ? (bool) $args['show_in_nav_menus'] : true,
				'show_in_rest'        => (bool) $args['show_in_rest'],
				'hierarchical'        => (bool) $args['hierarchical'],
				// Pass the custom archive slug when set (WordPress accepts a string here); otherwise
				// the boolean toggle (true = archive at the post-type key, false = no archive).
				'has_archive'         => ( ! empty( $args['has_archive'] ) && ! empty( $args['archive_slug'] ) ) ? $args['archive_slug'] : (bool) $args['has_archive'],
				'exclude_from_search' => ! empty( $args['exclude_from_search'] ),
				'publicly_queryable'  => isset( $args['publicly_queryable'] ) ? (bool) $args['publicly_queryable'] : (bool) $args['public'],
				'can_export'          => isset( $args['can_export'] ) ? (bool) $args['can_export'] : true,
				'delete_with_user'    => ! empty( $args['delete_with_user'] ),
				'rewrite'             => $rewrite,
				'query_var'           => $query_var,
				'capability_type'     => ( isset( $args['capability_type'] ) && '' !== $args['capability_type'] ) ? $args['capability_type'] : 'post',
				'map_meta_cap'        => isset( $args['map_meta_cap'] ) ? (bool) $args['map_meta_cap'] : true,
				'supports'            => $supports_arg,
				'taxonomies'          => $taxonomies,
				'menu_position'       => isset( $args['menu_position'] ) ? $args['menu_position'] : null,
				'menu_icon'           => ( isset( $args['menu_icon'] ) && '' !== trim( (string) $args['menu_icon'] ) ) ? $args['menu_icon'] : 'dashicons-admin-post',
			);
			if ( ! empty( $cpt_args['map_meta_cap'] ) && isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) && ! empty( $args['capabilities'] ) ) {
				$cpt_args['capabilities'] = $args['capabilities'];
			}
			if ( ! empty( $args['show_in_rest'] ) ) {
				$cpt_args['rest_base'] = isset( $args['rest_base'] ) ? $args['rest_base'] : '';
				// Only forward a REST controller class that actually exists and extends the core controller.
				$controller = isset( $args['rest_controller_class'] ) ? trim( (string) $args['rest_controller_class'] ) : '';
				if ( '' !== $controller && class_exists( $controller ) && is_subclass_of( $controller, 'WP_REST_Controller' ) ) {
					$cpt_args['rest_controller_class'] = $controller;
				}
			}
		} else {
			// Minimal args: let WordPress handle advanced options from sensible defaults.
			$cpt_args = array(
				'labels'       => $labels,
				'description'  => $description,
				'public'       => (bool) $args['public'],
				// WordPress defaults show_in_rest to false; the editor says it follows Public, so pass it.
				'show_in_rest' => (bool) $args['show_in_rest'],
				'hierarchical' => (bool) $args['hierarchical'],
				'supports'     => $supports_arg,
				'taxonomies'   => $taxonomies,
			);
		}

		/**
		 * Filter the register_post_type() args for a Uichemy-defined CPT before registration.
		 *
		 * @param array  $cpt_args Args passed to register_post_type().
		 * @param string $key      Post type key.
		 * @param array  $args      Sanitized definition args.
		 */
		$cpt_args = apply_filters( 'uich/register_post_type_args', $cpt_args, $key, $args );

		$object = register_post_type( $key, $cpt_args );

		/**
		 * Fires after a Uichemy-defined CPT is registered — extension point for integrations.
		 *
		 * @param string                $key    Post type key.
		 * @param WP_Post_Type|WP_Error $object Result of register_post_type().
		 * @param array                 $args   Sanitized definition args.
		 */
		do_action( 'uich/registered_post_type', $key, $object, $args );
	}

	/**
	 * Register a single taxonomy with full args from sanitize_tax_args (labels, visibility, rewrite, capabilities, REST).
	 *
	 * @param array $args Sanitized args from sanitize_tax_args.
	 */
	private function register_one_taxonomy( $args ) {
		$key      = $args['key'];
		$label    = $args['label'];
		$singular = $args['singular_label'];
		$advanced = isset( $args['advanced_settings_enabled'] ) ? (bool) $args['advanced_settings_enabled'] : true;

		$labels = array(
			'name'                       => $label,
			'singular_name'              => $singular,
			/* translators: %s: the content type name */
			'search_items'               => sprintf( __( 'Search %s', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'all_items'                  => sprintf( __( 'All %s', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'parent_item'                => $args['hierarchical'] ? sprintf( __( 'Parent %s', 'uichemy' ), $singular ) : null,
			/* translators: %s: the content type name */
			'parent_item_colon'          => $args['hierarchical'] ? sprintf( __( 'Parent %s:', 'uichemy' ), $singular ) : null,
			/* translators: %s: the content type name */
			'edit_item'                  => sprintf( __( 'Edit %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'update_item'                => sprintf( __( 'Update %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'add_new_item'               => sprintf( __( 'Add New %s', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'new_item_name'              => sprintf( __( 'New %s Name', 'uichemy' ), $singular ),
			/* translators: %s: the content type name */
			'view_item'                  => sprintf( __( 'View %s', 'uichemy' ), $singular ),
			'separate_items_with_commas' => __( 'Separate items with commas', 'uichemy' ),
			'add_or_remove_items'        => __( 'Add or remove items', 'uichemy' ),
			'choose_from_most_used'      => __( 'Choose from the most used items', 'uichemy' ),
			'popular_items'              => __( 'Popular Items', 'uichemy' ),
			/* translators: %s: the content type name */
			'not_found'                  => sprintf( __( 'No %s found.', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'no_terms'                   => sprintf( __( 'No %s', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'items_list'                 => sprintf( __( '%s list', 'uichemy' ), $label ),
			/* translators: %s: the content type name */
			'items_list_navigation'      => sprintf( __( '%s list navigation', 'uichemy' ), $label ),
			'menu_name'                  => $label,
		);
		if ( $advanced && ! empty( $args['labels_overrides'] ) && is_array( $args['labels_overrides'] ) ) {
			$labels = array_merge( $labels, $args['labels_overrides'] );
		}

		// The configured default term, deliberately kept OUT of $params once the term already exists —
		// see the note at the default_term block below. Declared here so it is defined in both branches.
		$default_term = null;

		if ( $advanced ) {
			$rewrite = ( isset( $args['rewrite'] ) && false === $args['rewrite'] )
				? false
				: array(
					'slug'         => $args['rewrite_slug'],
					'with_front'   => false,
					'hierarchical' => ! empty( $args['rewrite_hierarchical'] ),
				);

			$query_var = isset( $args['query_var'] ) ? $args['query_var'] : true;

			$params = array(
				'labels'            => $labels,
				'description'       => isset( $args['description'] ) ? $args['description'] : '',
				'hierarchical'      => (bool) $args['hierarchical'],
				'public'            => (bool) $args['public'],
				'show_ui'           => (bool) $args['show_ui'],
				'show_admin_column' => (bool) $args['show_admin_column'],
				// WordPress reads show_tagcloud (no underscore) and falls back to show_ui when it is unset,
				// so the misspelled key silently disabled this setting entirely.
				'show_tagcloud'     => (bool) $args['show_tag_cloud'],
				'show_in_nav_menus' => (bool) $args['show_in_nav_menus'],
				'show_in_rest'      => (bool) $args['show_in_rest'],
				'rewrite'           => $rewrite,
				'query_var'         => $query_var,
			);
			// Forward `sort` only when explicitly chosen; core's default is null, not true.
			if ( isset( $args['sort'] ) ) {
				$params['sort'] = (bool) $args['sort'];
			}
			// Passing default_term makes core run term_exists() on every registration. Once the term
			// exists, omit the arg and set the property after registering instead.
			if ( ! empty( $args['default_term'] ) && is_array( $args['default_term'] ) && '' !== trim( (string) $args['default_term']['name'] ) ) {
				$default_term = $args['default_term'];
				if ( ! (int) get_option( 'default_term_' . $key, 0 ) ) {
					// Not created yet — let core create it, this once.
					$params['default_term'] = $default_term;
				}
			}
			if ( ! empty( $args['capabilities'] ) ) {
				$params['capabilities'] = $args['capabilities'];
			}
			if ( ! empty( $args['show_in_rest'] ) ) {
				if ( isset( $args['rest_base'] ) && '' !== $args['rest_base'] ) {
					$params['rest_base'] = $args['rest_base'];
				}
				// Only forward a real WP_REST_Controller subclass; matches the CPT branch.
				$controller = isset( $args['rest_controller_class'] ) ? trim( (string) $args['rest_controller_class'] ) : '';
				if ( '' !== $controller && class_exists( $controller ) && is_subclass_of( $controller, 'WP_REST_Controller' ) ) {
					$params['rest_controller_class'] = $controller;
				}
			}
		} else {
			// Minimal args when advanced settings are disabled.
			$params = array(
				'labels'       => $labels,
				'description'  => isset( $args['description'] ) ? $args['description'] : '',
				'hierarchical' => (bool) $args['hierarchical'],
				'public'       => (bool) $args['public'],
			);
			// Only a stored value: a taxonomy saved before REST followed Public keeps WordPress's default (off).
			if ( ! empty( $args['show_in_rest_stored'] ) ) {
				$params['show_in_rest'] = (bool) $args['show_in_rest'];
			}
		}

		/**
		 * Filter the register_taxonomy() args for a Uichemy-defined taxonomy before registration.
		 *
		 * @param array  $params Args passed to register_taxonomy().
		 * @param string $key    Taxonomy key.
		 * @param array  $args   Sanitized definition args.
		 */
		$params = apply_filters( 'uich/register_taxonomy_args', $params, $key, $args );

		$object = register_taxonomy( $key, $args['object_type'], $params );

		// Property must still be set, or core stops auto-assigning and delete-protecting the term.
		if ( null !== $default_term && ! isset( $params['default_term'] ) ) {
			$tax_object = ( $object instanceof WP_Taxonomy ) ? $object : get_taxonomy( $key );
			if ( $tax_object instanceof WP_Taxonomy ) {
				$tax_object->default_term = $default_term;
			}
		}

		// Core discards wp_insert_term() errors, so detect a missing default term and notify.
		if ( ! is_wp_error( $object ) && isset( $params['default_term'] ) && ! get_option( 'default_term_' . $key ) ) {
			$this->default_term_failures[ $key ] = isset( $params['default_term']['name'] )
				? (string) $params['default_term']['name']
				: '';
		}

		/**
		 * Fires after a Uichemy-defined taxonomy is registered — extension point for integrations.
		 *
		 * @param string                $key    Taxonomy key.
		 * @param WP_Taxonomy|WP_Error  $object Result of register_taxonomy().
		 * @param array                 $args   Sanitized definition args.
		 */
		do_action( 'uich/registered_taxonomy', $key, $object, $args );
	}

	/**
	 * Default CPT capability value based on capability_type.
	 *
	 * @param string $cap_key         Capability key.
	 * @param string $capability_type Either 'post' or 'page'.
	 * @return string
	 */
	private function get_default_cpt_capability_value( $cap_key, $capability_type ) {
		$target = ( 'page' === $capability_type ) ? 'page' : 'post';
		if ( preg_match( '/_posts$/', $cap_key ) ) {
			return preg_replace( '/_posts$/', '_' . $target . 's', $cap_key );
		}
		return preg_replace( '/_post$/', '_' . $target, $cap_key );
	}
}
