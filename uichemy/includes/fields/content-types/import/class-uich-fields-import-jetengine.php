<?php
/**
 * JetEngine (Crocoblock) import source.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_JetEngine
 */
class Uich_Fields_Import_JetEngine extends Uich_Fields_Import_Source {

	/** Where JetEngine keeps its meta boxes. Read through the runtime object; written here directly. */
	const BOXES_OPTION = 'jet_engine_meta_boxes';

	/**
	 * JetEngine field type => Uichemy type.
	 *
	 * @return array<string, string>
	 */
	public static function type_map() {
		return array(
			'text'           => 'text',
			'textarea'       => 'textarea',
			'number'         => 'number',
			'wysiwyg'        => 'wysiwyg',
			'select'         => 'select',
			'radio'          => 'radio',
			'checkbox'       => 'checkbox',
			'date'           => 'date',
			'time'           => 'time',
			'datetime-local' => 'datetime',
			'colorpicker'    => 'color',
			'media'          => 'image',
			'repeater'       => 'repeater',
			// Unverified against a live install — see the provenance note in the file header.
			'switcher'       => 'true_false',
			'iconpicker'     => 'text',
		);
	}

	/**
	 * Types deliberately not imported, with the reason shown in the scan report.
	 *
	 * @return array<string, string>
	 */
	public static function skip_reasons() {
		return array(
			'gallery' => __( 'No UiChemy gallery type yet. Interim: a repeater of image fields.', 'uichemy' ),
			'html'    => __( 'Layout-only field, stores no value.', 'uichemy' ),
			// JetEngine's Relations module keeps its own tables and is not meta at all.
			'posts'   => __( 'JetEngine Relations are stored in their own tables, not as meta. Out of scope for this importer.', 'uichemy' ),
		);
	}

	/**
	 * Field `object_type` values that are layout only and store nothing.
	 *
	 * @return string[]
	 */
	private static function layout_object_types() {
		return array( 'tab', 'accordion', 'endpoint' );
	}

	/**
	 * Machine slug for this source.
	 *
	 * @return string
	 */
	public function slug() {
		return 'jetengine';
	}

	/**
	 * Human label for the UI and reports.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'JetEngine', 'uichemy' );
	}

	/**
	 * Whether this source has anything to import on this site.
	 *
	 * @return bool
	 */
	public function is_available() {
		// Any JetEngine data at all, not just meta boxes: gating on groups hid the whole source on a
		// site whose JetEngine content is post types and taxonomies. Read the same way the importer
		// does — through the tables too — so rows left behind by a deactivated module still count.
		return (bool) $this->groups_raw()
			|| (bool) get_option( self::BOXES_OPTION, array() )
			|| (bool) $this->module_rows( 'cpt', 'jet_post_types' )
			|| (bool) $this->module_rows( 'taxonomies', 'jet_taxonomies' );
	}

	/**
	 * Source-level notes for the dry run, naming what this adapter cannot carry.
	 *
	 * Shown even when the plan is otherwise empty, so a JetEngine user sees why rather than a blank
	 * "nothing to import".
	 *
	 * @return string[]
	 */
	public function scan_notes() {
		// Keyed off data, not the plugin being active, so the note matches what the importer will read.
		if ( ! $this->is_available() ) {
			return array();
		}
		return array( self::not_imported_notice() );
	}

	/**
	 * JetEngine's raw field-group array, or an empty array when it cannot be reached.
	 *
	 * @return array
	 */
	private function groups_raw() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return array();
		}
		$je = jet_engine();
		if ( ! is_object( $je ) || ! isset( $je->meta_boxes ) || ! is_object( $je->meta_boxes ) ) {
			return array();
		}
		if ( ! isset( $je->meta_boxes->data ) || ! is_object( $je->meta_boxes->data ) ) {
			return array();
		}

		// ->raw is JetEngine's LAZY cache: it stays empty until something calls get_raw(), which is
		// what actually loads the option. Reading the property directly made the scan report "nothing
		// to migrate" — and the run import zero groups, with no error — on any request where nothing
		// else had primed it yet (reproduced: same fixtures, written:0 one request and written:12 the
		// next). get_raw() returns the same array and primes the cache, so ask for it properly.
		$raw = method_exists( $je->meta_boxes->data, 'get_raw' )
			? $je->meta_boxes->data->get_raw()
			: ( isset( $je->meta_boxes->data->raw ) ? $je->meta_boxes->data->raw : null );

		// Last resort: the module can be switched off while its option still holds definitions, and
		// reporting nothing to migrate there would lose data silently.
		if ( ! is_array( $raw ) || ! $raw ) {
			$opt = get_option( 'jet_engine_meta_boxes', array() );
			$raw = is_array( $opt ) ? $opt : array();
		}

		return is_array( $raw ) ? $raw : array();
	}

	// ----------------------------------------------------------------- Definitions
	// -----------------------------------------------------------------.

	/**
	 * This source numbers its definitions from its own tables and option keys, not from wp_posts.
	 *
	 * @return bool
	 */
	public function definitions_are_posts() {
		return false;
	}

	/**
	 * Rows from one JetEngine definition module.
	 *
	 * Prefers the module's own reader; falls back to the table because a site can switch the module
	 * off while its rows remain, and reporting "nothing to migrate" there would be a lie.
	 *
	 * @param string $component jet_engine() property holding the module.
	 * @param string $table     Table name without the prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function module_rows( $component, $table ) {
		$je = function_exists( 'jet_engine' ) ? jet_engine() : null;
		if ( is_object( $je ) && isset( $je->$component ) && is_object( $je->$component )
			&& isset( $je->$component->data ) && is_object( $je->$component->data )
			&& method_exists( $je->$component->data, 'get_items' ) ) {
			$rows = $je->$component->data->get_items();
			if ( is_array( $rows ) && $rows ) {
				return array_map( array( $this, 'to_array' ), $rows );
			}
		}

		global $wpdb;
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table with no WordPress API; a scan must see current rows, so caching would report a stale plan.
		if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, never user input, and an identifier cannot be bound by prepare().
		$rows = $wpdb->get_results( "SELECT * FROM `{$name}` ORDER BY id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One JetEngine row as an array, whichever shape its reader handed back.
	 *
	 * @param mixed $row Row.
	 * @return array<string, mixed>
	 */
	private function to_array( $row ) {
		if ( is_object( $row ) ) {
			return get_object_vars( $row );
		}
		return is_array( $row ) ? $row : array();
	}

	/**
	 * A stored value decoded, with object instantiation refused.
	 *
	 * Drop-in for maybe_unserialize(), which allows any class and so turns a crafted postmeta row
	 * into a gadget chain. JetEngine only ever stores arrays and scalars here, so refusing objects
	 * cannot change a legitimate result.
	 *
	 * @param mixed $raw Stored value.
	 * @return mixed
	 */
	private function decode_value( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}
		$trimmed = trim( $raw );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes false makes this a pure array/scalar decode; a malformed row must fall back to the raw string rather than emit a notice.
		$decoded = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
		if ( false === $decoded && 'b:0;' !== $trimmed ) {
			return $raw;
		}
		return $decoded;
	}

	/**
	 * A serialized JetEngine column decoded to an array.
	 *
	 * Decoded with allowed_classes false rather than JetEngine's own 'stdClass': a definition is
	 * plain data, and an importer that instantiates a class named by a third-party row is an
	 * object-injection sink.
	 *
	 * @param mixed $value Stored column value.
	 * @return array
	 */
	private function decode_column( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( ! is_serialized( $value ) ) {
			return array();
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- JetEngine stores these columns serialized; allowed_classes false makes this a pure array decode, and a malformed row must skip that definition rather than emit a notice into the REST response.
		$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Post-type definitions shaped for Admin_Logic::save_cpt().
	 *
	 * @return array
	 */
	public function read_post_types() {
		$out = array();

		foreach ( $this->module_rows( 'cpt', 'jet_post_types' ) as $row ) {
			$key = $this->clean_name( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' === $key ) {
				continue;
			}
			// 'built-in' rows are JetEngine's edits to post/page, not new types. Importing one would
			// try to create a definition owning a reserved core key.
			$status = isset( $row['status'] ) ? (string) $row['status'] : 'publish';
			if ( 'built-in' === $status ) {
				continue;
			}

			$args     = $this->decode_column( isset( $row['args'] ) ? $row['args'] : array() );
			$labels   = $this->decode_column( isset( $row['labels'] ) ? $row['labels'] : array() );
			$warnings = array();

			$public  = ! empty( $args['public'] );
			$show_ui = isset( $args['show_ui'] ) ? ! empty( $args['show_ui'] ) : $public;
			$name    = isset( $labels['name'] ) && '' !== $labels['name'] ? (string) $labels['name'] : $key;

			$data = array(
				'post_type_key'  => $key,
				'label'          => $name,
				'singular_label' => isset( $labels['singular_name'] ) && '' !== $labels['singular_name'] ? (string) $labels['singular_name'] : $name,
				'public'         => $public,
				'hierarchical'   => ! empty( $args['hierarchical'] ),
				'show_ui'        => $show_ui,
				'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
			);

			$data['show_in_menu']        = isset( $args['show_in_menu'] ) ? ! empty( $args['show_in_menu'] ) : $show_ui;
			$data['show_in_admin_bar']   = isset( $args['show_in_admin_bar'] ) ? ! empty( $args['show_in_admin_bar'] ) : $data['show_in_menu'];
			$data['show_in_nav_menus']   = isset( $args['show_in_nav_menus'] ) ? ! empty( $args['show_in_nav_menus'] ) : $public;
			$data['show_in_rest']        = isset( $args['show_in_rest'] ) ? ! empty( $args['show_in_rest'] ) : $public;
			$data['publicly_queryable']  = isset( $args['publicly_queryable'] ) ? ! empty( $args['publicly_queryable'] ) : $public;
			$data['exclude_from_search'] = isset( $args['exclude_from_search'] ) ? ! empty( $args['exclude_from_search'] ) : ! $public;
			$data['map_meta_cap']        = ! isset( $args['map_meta_cap'] ) || ! empty( $args['map_meta_cap'] );
			$data['query_var']           = ! isset( $args['query_var'] ) || ! empty( $args['query_var'] );
			$data['has_archive']         = ! empty( $args['has_archive'] );
			$data['supports']            = isset( $args['supports'] ) ? array_values( (array) $args['supports'] ) : array();
			$data['taxonomies']          = array();

			if ( isset( $args['menu_position'] ) && '' !== $args['menu_position'] && null !== $args['menu_position'] ) {
				$data['menu_position'] = (int) $args['menu_position'];
			}
			if ( ! empty( $args['menu_icon'] ) ) {
				$data['menu_icon'] = (string) $args['menu_icon'];
			}
			if ( ! empty( $args['capability_type'] ) ) {
				$data['capability_type'] = $this->clean_name( $args['capability_type'] );
			}

			$data = array_merge( $data, $this->map_wp_rewrite( $this->rewrite_args( $args, $key, false ), $key, 'cpt' ) );

			$overrides = $this->map_labels( $labels );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			if ( 'publish' !== $status ) {
				$warnings[] = sprintf(
					/* translators: %s: post type or taxonomy key. */
					__( '"%s" was not published in JetEngine. It is imported as active anyway, because disabling the source is how this importer hands a type over — carrying the status across would switch the imported type off on the next run.', 'uichemy' ),
					$key
				);
			}
			$this->warn_unmapped_cpt( $args, $key, $warnings );

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'cpt' );

			$out[] = array(
				// Not a post id, and namespaced so it cannot equal a taxonomy row or a meta box —
				// see stable_id() and definitions_are_posts().
				'source_id' => $this->stable_id( 'pt:' . $key ),
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

		foreach ( $this->module_rows( 'taxonomies', 'jet_taxonomies' ) as $row ) {
			$key = $this->clean_name( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' === $key ) {
				continue;
			}
			$status = isset( $row['status'] ) ? (string) $row['status'] : 'publish';
			if ( 'built-in' === $status ) {
				continue;
			}

			$args     = $this->decode_column( isset( $row['args'] ) ? $row['args'] : array() );
			$labels   = $this->decode_column( isset( $row['labels'] ) ? $row['labels'] : array() );
			$objects  = $this->decode_column( isset( $row['object_type'] ) ? $row['object_type'] : array() );
			$warnings = array();

			$public  = ! empty( $args['public'] );
			$show_ui = isset( $args['show_ui'] ) ? ! empty( $args['show_ui'] ) : $public;
			$name    = isset( $labels['name'] ) && '' !== $labels['name'] ? (string) $labels['name'] : $key;

			$targets = array();
			foreach ( (array) $objects as $pt ) {
				$pt = is_array( $pt ) ? '' : $this->clean_name( $pt );
				if ( '' !== $pt ) {
					$targets[] = $pt;
				}
			}

			$data = array(
				'taxonomy_key'   => $key,
				'label'          => $name,
				'singular_label' => isset( $labels['singular_name'] ) && '' !== $labels['singular_name'] ? (string) $labels['singular_name'] : $name,
				'public'         => $public,
				'hierarchical'   => ! empty( $args['hierarchical'] ),
				'show_ui'        => $show_ui,
				'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
				'object_type'    => $targets ? $targets : array( 'post' ),
			);

			$data['show_in_nav_menus'] = isset( $args['show_in_nav_menus'] ) ? ! empty( $args['show_in_nav_menus'] ) : $public;
			$data['show_in_rest']      = isset( $args['show_in_rest'] ) ? ! empty( $args['show_in_rest'] ) : $public;

			$query_var = isset( $args['query_var'] ) ? $args['query_var'] : '';
			if ( is_string( $query_var ) && '' !== $query_var ) {
				$data['query_var'] = true;
				if ( $query_var !== $key ) {
					$data['query_var_string'] = $query_var;
				}
			} else {
				$data['query_var'] = ! empty( $query_var );
			}

			$data = array_merge( $data, $this->map_wp_rewrite( $this->rewrite_args( $args, $key, true ), $key, 'taxonomy' ) );

			$overrides = $this->map_labels( $labels );
			if ( ! empty( $overrides ) ) {
				$data['labels'] = $overrides;
			}

			if ( 'publish' !== $status ) {
				$warnings[] = sprintf(
					/* translators: %s: post type or taxonomy key. */
					__( '"%s" was not published in JetEngine. It is imported as active anyway, because disabling the source is how this importer hands a type over — carrying the status across would switch the imported type off on the next run.', 'uichemy' ),
					$key
				);
			}
			if ( ! $targets ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy key. */
					__( '"%s": the source did not name a post type, so the taxonomy was attached to Posts. Check its location before relying on it.', 'uichemy' ),
					$key
				);
			}

			$data['advanced_settings_enabled'] = $this->map_advanced_flag( $data, 'taxonomy' );

			$out[] = array(
				// Not a post id, and namespaced — see stable_id() and definitions_are_posts().
				'source_id' => $this->stable_id( 'tax:' . $key ),
				'warnings'  => $warnings,
				'data'      => $data,
			);
		}

		return $out;
	}

	/**
	 * JetEngine keeps rewrite settings as flat sibling args; map_wp_rewrite() takes WordPress's own
	 * nested shape, so rebuild that instead of duplicating the mapping.
	 *
	 * @param array  $args   Decoded JetEngine args.
	 * @param string $key    Post type / taxonomy key.
	 * @param bool   $is_tax Whether this is a taxonomy.
	 * @return array|bool
	 */
	private function rewrite_args( $args, $key, $is_tax ) {
		if ( isset( $args['rewrite'] ) && empty( $args['rewrite'] ) ) {
			return false;
		}
		$out = array();
		if ( ! empty( $args['rewrite_slug'] ) && $args['rewrite_slug'] !== $key ) {
			$out['slug'] = (string) $args['rewrite_slug'];
		}
		if ( isset( $args['with_front'] ) ) {
			$out['with_front'] = ! empty( $args['with_front'] );
		}
		if ( $is_tax && isset( $args['rewrite_hierarchical'] ) ) {
			$out['hierarchical'] = ! empty( $args['rewrite_hierarchical'] );
		}
		return $out ? $out : true;
	}

	/**
	 * Warn about JetEngine post-type settings that have no Uichemy equivalent.
	 *
	 * @param array  $args     Decoded args.
	 * @param string $key      Post type key.
	 * @param array  $warnings Collected warnings (by reference).
	 */
	private function warn_unmapped_cpt( $args, $key, &$warnings ) {
		if ( ! empty( $args['custom_storage'] ) ) {
			$warnings[] = sprintf(
				/* translators: %s: post type key. */
				__( '"%s" stored its posts in a JetEngine custom table. UiChemy registers a normal post type, so those rows stay in the JetEngine table and will not appear until they are moved into wp_posts.', 'uichemy' ),
				$key
			);
		}
		$features = array(
			'admin_columns' => __( 'admin columns', 'uichemy' ),
			'admin_filters' => __( 'admin filters', 'uichemy' ),
		);
		foreach ( $features as $arg => $label ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: post type key, 2: the JetEngine feature name. */
					__( '"%1$s": custom %2$s were configured in JetEngine and have no UiChemy equivalent, so they were not carried over.', 'uichemy' ),
					$key,
					$label
				);
			}
		}
	}

	/**
	 * A stable positive id for one source definition, derived from a namespaced token.
	 *
	 * Ids must be unique across EVERY origin, not just within one kind: JetEngine keys meta boxes
	 * with strings like 'meta-1' (a plain cast makes them all 0) and numbers post-type and taxonomy
	 * rows from 1 each, while the importer collects migrated ids into one flat list. Raw ids would
	 * make a post type, a taxonomy and a meta box the same number, and disable/rollback would then
	 * act on whichever one it found first.
	 *
	 * @param string $token Namespaced token, e.g. 'mb:meta-1' or 'pt:je_property'.
	 * @return int
	 */
	private function stable_id( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return 0;
		}
		// Folded to stay inside a 32-bit signed int on every platform, and never 0 — the manager
		// reads 0 as "this item has no source record".
		return ( (int) sprintf( '%u', crc32( $token ) ) % 2000000000 ) + 1;
	}

	/**
	 * Field groups shaped for the field-groups REST controller.
	 *
	 * Covers both places JetEngine keeps fields: its meta boxes, and the meta_fields attached
	 * directly to a post type or taxonomy. The second kind used to be dropped without even a skip
	 * entry, so a migration silently lost them.
	 *
	 * @return array
	 */
	public function read_groups() {
		$out   = array();
		$index = 0;

		foreach ( $this->groups_raw() as $group ) {
			++$index;
			if ( ! is_array( $group ) ) {
				continue;
			}
			$args   = isset( $group['args'] ) && is_array( $group['args'] ) ? $group['args'] : array();
			$title  = isset( $args['name'] ) && '' !== $args['name'] ? (string) $args['name'] : sprintf( 'JetEngine group %d', $index );
			$raw_id = isset( $args['id'] ) ? $args['id'] : ( isset( $group['id'] ) ? $group['id'] : $index );

			$skipped  = array();
			$warnings = array();
			$fields   = $this->map_fields(
				isset( $group['meta_fields'] ) ? $group['meta_fields'] : array(),
				$skipped,
				$warnings
			);

			$key = $this->clean_name( $title );
			if ( '' === $key ) {
				$key = 'jet_group_' . $this->stable_id( 'mb:' . $raw_id );
			}

			$warnings[] = self::not_imported_notice();

			$out[] = array(
				// JetEngine group ids are not post ids — see definitions_are_posts().
				'source_id' => $this->stable_id( 'mb:' . $raw_id ),
				'warnings'  => $warnings,
				'skipped'   => $skipped,
				'data'      => array(
					'title'           => $title,
					'key'             => $key,
					'fields'          => $fields,
					'location_rules'  => $this->map_location( $args ),
					'position'        => 'normal',
					'style'           => 'default',
					'label_placement' => 'top',
					'active'          => true,
					'description'     => '',
				),
			);
		}

		$out = array_merge(
			$out,
			$this->attached_groups( 'cpt', 'jet_post_types', 'allowed_post_type', 'ptf' ),
			$this->attached_groups( 'taxonomies', 'jet_taxonomies', 'taxonomy', 'taxf' )
		);

		return $out;
	}

	/**
	 * Groups built from the meta_fields stored on a post type or taxonomy row.
	 *
	 * @param string $component    jet_engine() property holding the module.
	 * @param string $table        Table name without the prefix.
	 * @param string $location_arg map_location() key the owning object belongs under.
	 * @param string $ns           Id namespace, distinct from the owning definition's own.
	 * @return array
	 */
	private function attached_groups( $component, $table, $location_arg, $ns ) {
		$out = array();

		foreach ( $this->module_rows( $component, $table ) as $row ) {
			$slug = $this->clean_name( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' === $slug ) {
				continue;
			}
			$raw_fields = $this->decode_column( isset( $row['meta_fields'] ) ? $row['meta_fields'] : array() );
			if ( ! $raw_fields ) {
				continue;
			}

			$skipped  = array();
			$warnings = array();
			$fields   = $this->map_fields( $raw_fields, $skipped, $warnings );
			if ( ! $fields && ! $skipped ) {
				continue;
			}

			$labels = $this->decode_column( isset( $row['labels'] ) ? $row['labels'] : array() );
			$name   = isset( $labels['name'] ) && '' !== $labels['name'] ? (string) $labels['name'] : $slug;

			$warnings[] = sprintf(
				/* translators: %s: the post type or taxonomy label. */
				__( 'These fields were attached directly to "%s" in JetEngine rather than to a meta box. UiChemy keeps fields in groups, so they were imported as one.', 'uichemy' ),
				$name
			);
			$warnings[] = self::not_imported_notice();

			$out[] = array(
				// Namespaced: these share the group bucket with the meta boxes, and both are
				// numbered from 1 in their own storage.
				'source_id' => $this->stable_id( $ns . ':' . $slug ),
				'warnings'  => $warnings,
				'skipped'   => $skipped,
				'data'      => array(
					'title'           => sprintf(
						/* translators: %s: the post type or taxonomy label. */
						__( '%s Fields', 'uichemy' ),
						$name
					),
					'key'             => $this->clean_name( $slug . '_fields' ),
					'fields'          => $fields,
					'location_rules'  => $this->map_location( array( $location_arg => array( $slug ) ) ),
					'position'        => 'normal',
					'style'           => 'default',
					'label_placement' => 'top',
					'active'          => true,
					'description'     => '',
				),
			);
		}

		return $out;
	}

	/**
	 * What this adapter still does not carry across.
	 *
	 * @return string
	 */
	private static function not_imported_notice() {
		return __( 'JetEngine Relations, Options Pages, Glossaries and Listings are separate modules and are not imported. Post types, taxonomies, meta boxes and their values do come across.', 'uichemy' );
	}

	/**
	 * Turn JetEngine's meta_fields list into Uichemy field definitions.
	 *
	 * @param array $meta_fields JetEngine fields.
	 * @param array $skipped     Collected skips (by reference).
	 * @param array $warnings    Collected warnings (by reference).
	 * @return array
	 */
	private function map_fields( $meta_fields, &$skipped, &$warnings ) {
		$map    = self::type_map();
		$skips  = self::skip_reasons();
		$layout = self::layout_object_types();
		$fields = array();

		foreach ( (array) $meta_fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$type  = isset( $f['type'] ) ? (string) $f['type'] : '';
			$obj   = isset( $f['object_type'] ) ? (string) $f['object_type'] : '';
			$name  = $this->clean_name( isset( $f['name'] ) ? $f['name'] : '' );
			$label = isset( $f['title'] ) && '' !== $f['title'] ? (string) $f['title'] : $name;

			// Layout-only containers store nothing; dropping them silently is correct.
			if ( in_array( $obj, $layout, true ) ) {
				continue;
			}
			if ( '' === $name ) {
				$skipped[] = array(
					'field'  => $label,
					'type'   => $type,
					'reason' => __( 'Field has no name, so it stores no value.', 'uichemy' ),
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
			if ( ! isset( $map[ $type ] ) ) {
				$skipped[] = array(
					'field'  => $name,
					'type'   => $type,
					'reason' => __( 'No UiChemy equivalent for this JetEngine type.', 'uichemy' ),
				);
				continue;
			}

			$target_type = $map[ $type ];
			$field   = array(
				'name'         => $name,
				'label'        => $label,
				'type'         => $target_type,
				'instructions' => isset( $f['description'] ) ? (string) $f['description'] : '',
				'required'     => ! empty( $f['is_required'] ),
			);

			if ( $target_type !== $type ) {
				$warnings[] = sprintf(
					/* translators: 1: field name, 2: source type, 3: target type */
					__( '"%1$s": %2$s has no exact UiChemy equivalent and was imported as %3$s.', 'uichemy' ),
					$name,
					$type,
					$target_type
				);
			}

			$this->copy_choices( $f, $target_type, $field );

			if ( 'repeater' === $target_type ) {
				// JetEngine names the sub-field list `repeater-fields`; Uichemy nests under `fields`
				// (and ACF uses `sub_fields`) — three plugins, three key names.
				$subs            = isset( $f['repeater-fields'] ) ? $f['repeater-fields'] : array();
				$field['fields'] = $this->map_fields( $subs, $skipped, $warnings );
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Copy select/radio/checkbox options across.
	 *
	 * @param array  $f       Source field.
	 * @param string $target_type Target Uichemy type.
	 * @param array  $field   Uichemy field being built (by reference).
	 */
	private function copy_choices( $f, $target_type, &$field ) {
		if ( ! in_array( $target_type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			return;
		}
		$options = isset( $f['options'] ) ? $f['options'] : array();
		if ( ! is_array( $options ) ) {
			return;
		}
		$choices = array();
		foreach ( $options as $k => $opt ) {
			if ( ! is_array( $opt ) ) {
				// Flat map: the array key IS the stored value.
				$value = (string) $k;
				$label = (string) $opt;
			} elseif ( isset( $opt['key'] ) ) {
				// key/value: the key is stored, the value is shown.
				$value = (string) $opt['key'];
				$label = isset( $opt['value'] ) && '' !== $opt['value'] ? (string) $opt['value'] : $value;
			} elseif ( isset( $opt['value'] ) ) {
				// value/label: the value is stored, the label is shown.
				$value = (string) $opt['value'];
				$label = isset( $opt['label'] ) && '' !== $opt['label'] ? (string) $opt['label'] : $value;
			} else {
				continue;
			}
			if ( '' !== $value ) {
				$choices[ $value ] = $label;
			}
		}
		if ( $choices ) {
			$field['choices'] = $choices;
		}
	}

	/**
	 * Uichemy location rules from a JetEngine group's args.
	 *
	 * @param array $args Group args.
	 * @return array
	 */
	private function map_location( $args ) {
		$rules = array();

		foreach ( array( 'allowed_post_type', 'post_type', 'allowed_post_types' ) as $key ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $args[ $key ] as $pt ) {
				$pt = $this->clean_name( $pt );
				if ( '' !== $pt ) {
					$rules[] = array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => $pt,
						),
					);
				}
			}
		}

		foreach ( array( 'allowed_taxonomies', 'taxonomy' ) as $key ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $args[ $key ] as $tax ) {
				$tax = $this->clean_name( $tax );
				if ( '' !== $tax ) {
					$rules[] = array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => $tax,
						),
					);
				}
			}
		}

		return $rules;
	}

	// ----------------------------------------------------------------- Values
	// -----------------------------------------------------------------.

	/**
	 * Read one field's stored value from JetEngine's storage.
	 *
	 * @param array  $field       Source field descriptor.
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return mixed|null Null when the source holds nothing.
	 */
	public function read_value( $field, $object_type, $object_id ) {
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( '' === $name ) {
			return null;
		}

		$raw = $this->raw_meta( $object_type, $object_id, $name, true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			return null;
		}

		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( 'repeater' === $type ) {
			return $this->decode_repeater( $raw, isset( $field['fields'] ) ? $field['fields'] : array() );
		}

		return $this->normalise( $raw, $type );
	}

	/**
	 * Decode a JetEngine repeater into this module's row array.
	 *
	 * @param mixed $raw  Stored value.
	 * @param array $subs Uichemy sub-field definitions.
	 * @return array|null
	 */
	private function decode_repeater( $raw, $subs ) {
		$rows = null;

		if ( is_array( $raw ) ) {
			$rows = $raw;
		} elseif ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$rows = $decoded;
			} else {
				$maybe = $this->decode_value( $raw );
				$rows  = is_array( $maybe ) ? $maybe : null;
			}
		}

		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( (array) $subs as $sub ) {
				if ( empty( $sub['name'] ) ) {
					continue;
				}
				$sub_name = $sub['name'];
				if ( ! array_key_exists( $sub_name, $row ) ) {
					continue;
				}
				$clean[ $sub_name ] = $this->normalise( $row[ $sub_name ], isset( $sub['type'] ) ? $sub['type'] : 'text' );
			}
			if ( $clean ) {
				$out[] = $clean;
			}
		}

		return $out ? $out : null;
	}

	/**
	 * Convert a JetEngine stored value into the shape this module's sanitizer expects.
	 *
	 * @param mixed  $raw     Stored value.
	 * @param string $target_type Target Uichemy type.
	 * @return mixed
	 */
	private function normalise( $raw, $target_type ) {
		$raw = $this->decode_value( $raw );

		switch ( $target_type ) {
			case 'image':
			case 'file':
				return $this->normalise_media( $raw );

			case 'true_false':
				// filter_var, not (bool)/empty(): JetEngine persists these through form posts, so an
				// unchecked switcher arrives as the STRING 'false' — which every truthiness check in
				// PHP calls true. Verified against a live JetEngine install.
				return filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;

			case 'checkbox':
				// JetEngine stores a checked map { value: 'true', other: 'false' }; Uichemy stores the
				// list of checked values. The flags are STRINGS ('true'/'false'), so empty()/truthiness
				// would count every unchecked option as checked — filter_var handles both the string
				// spellings and real booleans.
				if ( is_array( $raw ) ) {
					$on = array();
					foreach ( $raw as $k => $v ) {
						if ( is_int( $k ) ) {
							// Plain value list (no flags): every entry is a checked value.
							if ( '' !== (string) $v ) {
								$on[] = (string) $v;
							}
							continue;
						}
						if ( filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ) {
							$on[] = (string) $k;
						}
					}
					return array_values( array_unique( $on ) );
				}
				return array( (string) $raw );

			case 'datetime':
			case 'date':
				// JetEngine's date fields can hold a unix timestamp; Uichemy wants a date string.
				if ( is_numeric( $raw ) && (int) $raw > 100000 ) {
					return gmdate( 'datetime' === $target_type ? 'Y-m-d H:i:s' : 'Y-m-d', (int) $raw );
				}
				return $raw;

			default:
				return is_array( $raw ) ? null : $raw;
		}
	}

	/**
	 * JetEngine media fields store an attachment ID, an array carrying `id`, or a bare URL.
	 * Uichemy stores an attachment ID, so a URL must be resolved back to one.
	 *
	 * @param mixed $raw Stored value.
	 * @return int|null
	 */
	private function normalise_media( $raw ) {
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		if ( is_array( $raw ) ) {
			if ( isset( $raw['id'] ) && is_numeric( $raw['id'] ) ) {
				return (int) $raw['id'];
			}
			if ( isset( $raw['url'] ) && is_string( $raw['url'] ) ) {
				$raw = $raw['url'];
			} else {
				return null;
			}
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$id = attachment_url_to_postid( $raw );
			return $id ? (int) $id : null;
		}
		return null;
	}

	// ----------------------------------------------------------------- Teardown
	// -----------------------------------------------------------------.

	/**
	 * Every source definition this adapter can report, indexed by the id it reports it under.
	 *
	 * Disable and enable receive the flat list of migrated ids, so the only way back to the record
	 * is to recompute the same namespaced ids the read_* methods handed out.
	 *
	 * @return array<int, array{kind:string, slug:string}>
	 */
	private function definition_index() {
		$index = array();

		foreach ( $this->module_rows( 'cpt', 'jet_post_types' ) as $row ) {
			$slug = $this->clean_name( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' !== $slug ) {
				$index[ $this->stable_id( 'pt:' . $slug ) ] = array(
					'kind' => 'cpt',
					'slug' => $slug,
				);
			}
		}
		foreach ( $this->module_rows( 'taxonomies', 'jet_taxonomies' ) as $row ) {
			$slug = $this->clean_name( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' !== $slug ) {
				$index[ $this->stable_id( 'tax:' . $slug ) ] = array(
					'kind' => 'taxonomy',
					'slug' => $slug,
				);
			}
		}
		foreach ( $this->groups_raw() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$args   = isset( $group['args'] ) && is_array( $group['args'] ) ? $group['args'] : array();
			$raw_id = isset( $args['id'] ) ? $args['id'] : ( isset( $group['id'] ) ? $group['id'] : '' );
			if ( '' !== (string) $raw_id ) {
				$index[ $this->stable_id( 'mb:' . $raw_id ) ] = array(
					'kind' => 'meta_box',
					'slug' => (string) $raw_id,
				);
			}
		}

		return $index;
	}

	/**
	 * Switch the source's own definitions off (or back on) so both plugins do not register the same
	 * post type, taxonomy or metabox at once.
	 *
	 * Never deletes: rollback puts these back, and a deleted JetEngine row could not be restored.
	 *
	 * @param array $source_ids Ids reported by read_post_types/read_taxonomies/read_groups.
	 * @param bool  $enable     True to re-enable, false to disable.
	 * @return int Number of records changed.
	 */
	private function set_definitions_enabled( $source_ids, $enable ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}
		$wanted = array_flip( array_map( 'intval', (array) $source_ids ) );
		$index  = $this->definition_index();
		$status = $enable ? 'publish' : 'draft';
		$boxes  = null;
		$done   = 0;

		global $wpdb;
		foreach ( $index as $id => $record ) {
			if ( ! isset( $wanted[ $id ] ) ) {
				continue;
			}
			if ( 'meta_box' === $record['kind'] ) {
				if ( null === $boxes ) {
					$boxes = get_option( self::BOXES_OPTION, array() );
					$boxes = is_array( $boxes ) ? $boxes : array();
				}
				foreach ( $boxes as $box_key => $box ) {
					$box_id = isset( $box['args']['id'] ) ? $box['args']['id'] : ( isset( $box['id'] ) ? $box['id'] : $box_key );
					if ( (string) $box_id !== $record['slug'] ) {
						continue;
					}
					$boxes[ $box_key ]['args']['active'] = (bool) $enable;
					++$done;
				}
				continue;
			}

			$table = 'cpt' === $record['kind'] ? $wpdb->prefix . 'jet_post_types' : $wpdb->prefix . 'jet_taxonomies';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table with no WordPress API; the write must hit the row the source reads.
			$done += (int) $wpdb->update( $table, array( 'status' => $status ), array( 'slug' => $record['slug'] ), array( '%s' ), array( '%s' ) );
		}

		if ( null !== $boxes ) {
			update_option( self::BOXES_OPTION, $boxes, false );
		}
		if ( $done ) {
			// JetEngine caches both modules in the request that registered them; the next request
			// re-reads the rows, but a same-request scan would otherwise still see the old state.
			$this->flush_source_cache();
		}

		return $done;
	}

	/**
	 * Mark the source's own definitions inactive after a successful import.
	 *
	 * @param array $source_ids Definition ids that were migrated.
	 * @return int Number disabled.
	 */
	public function disable_definitions( $source_ids ) {
		return $this->set_definitions_enabled( $source_ids, false );
	}

	/**
	 * Put the source's definitions back, for rollback.
	 *
	 * @param array $source_ids Definition ids to re-enable.
	 * @return int Number re-enabled.
	 */
	public function enable_definitions( $source_ids ) {
		return $this->set_definitions_enabled( $source_ids, true );
	}

	/**
	 * Drop JetEngine's in-memory copies of the rows this adapter just changed.
	 */
	private function flush_source_cache() {
		$je = function_exists( 'jet_engine' ) ? jet_engine() : null;
		if ( ! is_object( $je ) ) {
			return;
		}
		foreach ( array( 'cpt', 'taxonomies', 'meta_boxes' ) as $component ) {
			if ( isset( $je->$component ) && is_object( $je->$component )
				&& isset( $je->$component->data ) && is_object( $je->$component->data ) ) {
				$data = $je->$component->data;
				if ( property_exists( $data, 'raw' ) ) {
					$data->raw = null;
				}
			}
		}
	}
}
