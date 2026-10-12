<?php
/**
 * REST API for Field Groups (Group Fields).
 * Endpoints: GET/POST /{namespace}/field-groups, GET/PUT/DELETE /field-groups/{id}
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_REST_Field_Groups
 */
class Uich_Fields_REST_Field_Groups {

	const NAMESPACE = UICH_FIELDS_REST_NS;

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_REST_Field_Groups|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_REST_Field_Groups
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers REST routes.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/field-groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'active_only' => array(
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_item_schema_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'validate_callback' => function ( $param ) {
									return is_numeric( $param ) && (int) $param > 0;
								},
							),
						),
						$this->get_item_schema_args()
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'      => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Report what the delete would do without trashing anything.', 'uichemy' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Check permission for REST requests.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function check_permission( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get schema args for create/update.
	 *
	 * @return array
	 */
	private function get_item_schema_args() {
		return array(
			'data'    => array(
				'required'          => false,
				'type'              => 'object',
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? $value : array();
				},
			),
			'dry_run' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
		);
	}

	/**
	 * Get all field groups.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
		// Defaults to unfiltered (all statuses) since this is the admin management screen's own data source — it
		// must be able to see and re-enable a disabled/draft group.
		$active_only = (bool) $request->get_param( 'active_only' );

		$items = array();
		foreach ( $groups['group_fields'] as $row ) {
			if ( $active_only ) {
				$active = ! isset( $row['meta']['active'] ) || (bool) $row['meta']['active'];
				if ( 'publish' !== $row['status'] || ! $active ) {
					continue;
				}
			}
			$title   = ! empty( $row['meta']['title'] ) ? $row['meta']['title'] : $row['title'];
			$items[] = array(
				'id'          => $row['id'],
				'title'       => $title,
				'kind'        => 'group_fields',
				'last_update' => $row['modified'],
				'status'      => $row['status'],
				'data'        => $row['meta'],
			);
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Refuse a malformed group key instead of quietly rewriting it.
	 *
	 * @param array $data Raw, unsanitized request data.
	 * @return WP_Error|null WP_Error when the submitted key is malformed, null when it is fine or absent.
	 */
	private function assert_submitted_key_well_formed( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['key'] ) ) {
			return null; // Absent is fine — finalize_group_meta() generates one.
		}
		$raw = is_string( $data['key'] ) ? trim( $data['key'] ) : '';
		if ( '' === $raw ) {
			return null; // Blank is also delegated to finalize_group_meta().
		}

		// sanitize_key() would quietly turn `../escape` into `escape` and `has space` into
		// `hasspace`, so the caller got back an id for a definition it never named. Validate what
		// was actually submitted; validate_key() already carries the charset and length rules.
		$valid = Uich_Fields_Model_Paths::validate_key( 'group_fields', $raw );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Resolve a definition id to its stored group meta.
	 *
	 * @param int $id Definition post ID.
	 * @return array|WP_Error
	 */
	private function model_group( $id ) {
		$found = Uich_Fields_Model_Writer::lookup( $id );
		if ( ! $found || 'group_fields' !== $found['kind'] ) {
			return new WP_Error( 'rest_not_found', __( 'Field group not found.', 'uichemy' ), array( 'status' => 404 ) );
		}
		$model = Uich_Fields_Model_Cache::get();
		if ( isset( $model['definitions']['group_fields'][ $found['key'] ] ) ) {
			return $model['definitions']['group_fields'][ $found['key'] ];
		}
		$trash = Uich_Fields_Model_Rows::read_trash();
		if ( isset( $trash['group_fields'][ $found['key'] ] ) ) {
			return $trash['group_fields'][ $found['key'] ];
		}
		return new WP_Error( 'rest_not_found', __( 'Field group not found.', 'uichemy' ), array( 'status' => 404 ) );
	}

	/**
	 * Get one field group.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id   = (int) $request['id'];
		$meta = $this->model_group( $id );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		return rest_ensure_response( $this->format_row( $id, $meta ) );
	}

	/**
	 * Create field group.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$key_error = $this->assert_submitted_key_well_formed( $data );
		if ( is_wp_error( $key_error ) ) {
			return $key_error;
		}

		$meta = $this->sanitize_meta( $data );

		// Before finalize/dry-run, so a preview reports the refusal the real save would make.
		$tier_error = uich_fields_assert_group_tier( $meta );
		if ( is_wp_error( $tier_error ) ) {
			return $tier_error;
		}

		$title = ! empty( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : __( 'Field Group', 'uichemy' );

		// finalize_group_meta() runs FIRST and is not optional: it is what guarantees a non-blank,
		// collision-free group key, and the key is the filename.
		$renamed = array();
		$meta    = Uich_Fields_Admin_Logic::finalize_group_meta( $meta, 0, $renamed );

		// The dry run stops on the line before the first write, so the key check, sanitisation and
		// key finalisation have all run against this exact payload.
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) ) {
			return rest_ensure_response( self::dry_run_response( 0, $meta, 'create' ) );
		}

		$saved_id = Uich_Fields_Model_Writer::save( 'group_fields', $meta, 0, $title );
		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}
		self::announce_saved( (int) $saved_id, $meta );

		return rest_ensure_response( self::with_warnings( $this->format_row( (int) $saved_id, $meta ), $meta, $renamed ) );
	}

	/**
	 * Update field group.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id       = (int) $request['id'];
		$existing = $this->model_group( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$meta    = $existing;
		$renamed = array();
		$data    = $request->get_param( 'data' );
		if ( is_array( $data ) ) {
			$key_error = $this->assert_submitted_key_well_formed( $data );
			if ( is_wp_error( $key_error ) ) {
				return $key_error;
			}
			// MB-14: this PUT is the admin UI's only save path, so the rename-lock guard must run
			// here. It blocks a group-key or field-name rename that would orphan saved values.
			$meta = $this->sanitize_meta( array_merge( $meta, $data ) );

			// `$existing` is the pre-merge definition, so a repeater already saved on this group is
			// recognised as stored and the group stays editable after a downgrade.
			$tier_error = uich_fields_assert_group_tier( $meta, $existing );
			if ( is_wp_error( $tier_error ) ) {
				return $tier_error;
			}

			$lock_error = Uich_Fields_Admin_Logic::assert_group_keys_not_renamed_with_data( $id, $meta );
			if ( is_wp_error( $lock_error ) ) {
				return $lock_error;
			}

			$meta  = Uich_Fields_Admin_Logic::finalize_group_meta( $meta, $id, $renamed );
			$title = ! empty( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : '';

			// Stops on the line before the first write, so the well-formed key check, sanitisation,
			// the rename lock and key finalisation have all run against this exact payload. Stopping
			// any higher would miss the rename lock, which is the check most worth previewing.
			if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) ) {
				return rest_ensure_response( self::dry_run_response( $id, $meta, 'update' ) );
			}

			$saved_id = Uich_Fields_Model_Writer::save( 'group_fields', $meta, $id, $title );
			if ( is_wp_error( $saved_id ) ) {
				return $saved_id;
			}
			$id = (int) $saved_id;
			self::announce_saved( $id, $meta );
			return rest_ensure_response( self::with_warnings( $this->format_row( $id, $meta ), $meta, $renamed ) );
		}

		return rest_ensure_response( $this->format_row( $id, $meta ) );
	}

	/**
	 * Attach non-blocking save warnings (a field name another active group also defines) to a response row.
	 *
	 * @param array    $row     Row from format_row().
	 * @param array    $meta    Saved group definition.
	 * @param string[] $renamed Field names finalize_group_meta() changed to store them (a duplicate, or characters it dropped).
	 * @return array
	 */
	private static function with_warnings( $row, $meta, $renamed = array() ) {
		$warnings = function_exists( 'uich_field_name_collision_warnings' ) ? uich_field_name_collision_warnings( $meta ) : array();
		$warnings = array_merge( $renamed, $warnings );
		if ( ! empty( $warnings ) ) {
			$row['warnings'] = $warnings;
		}
		return $row;
	}

	/**
	 * The body a dry run returns instead of saving.
	 *
	 * Reports the key and field count that WOULD be stored, taken from the finalised meta, so the
	 * caller sees the key finalisation actually chose — not the one it submitted.
	 *
	 * @param int    $id    Definition id, 0 on create.
	 * @param array  $meta  Finalised group meta.
	 * @param string $would 'create' or 'update'.
	 * @return array
	 */
	private static function dry_run_response( $id, $meta, $would ) {
		return array(
			'success' => true,
			'dry_run' => true,
			'id'      => (int) $id,
			'would'   => $would,
			'kind'    => 'group_fields',
			'key'     => isset( $meta['key'] ) ? (string) $meta['key'] : '',
			'title'   => isset( $meta['title'] ) ? (string) $meta['title'] : '',
			'fields'  => ( isset( $meta['fields'] ) && is_array( $meta['fields'] ) ) ? count( $meta['fields'] ) : 0,
			'message' => __( 'Dry run: every check passed and nothing was saved. Re-send without dry_run to apply it.', 'uichemy' ),
		);
	}

	/**
	 * Announce a saved group so cache layers can invalidate.
	 *
	 * This controller is the only writer of field groups, and it wrote them silently: every
	 * invalidation listener in the module keys off uich/definition_saved, which only Admin_Logic
	 * fired (for post types and taxonomies). A group save therefore left the definitions
	 * repository, the abilities cache and the page cache all serving the pre-save state until
	 * something unrelated flushed them - toggling a group active and seeing no change was the
	 * visible symptom.
	 *
	 * @param int   $id   Definition id.
	 * @param array $meta Saved group meta.
	 * @return void
	 */
	private static function announce_saved( $id, $meta ) {
		if ( ! isset( $meta['kind'] ) ) {
			$meta['kind'] = 'group_fields';
		}
		/** This action is documented in class-uich-fields-admin-logic.php */
		do_action( 'uich/definition_saved', (int) $id, 'group_fields', $meta );
	}

	/**
	 * Delete field group (trash).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$found = Uich_Fields_Model_Writer::lookup( $id );
		if ( ! $found || 'group_fields' !== $found['kind'] ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Field group not found.', 'uichemy' ),
				array( 'status' => 404 )
			);
		}

		// Stops below the lookup and the kind check, so a preview of a bad id still reports the 404.
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'dry_run' => true,
					'would'   => 'trash',
					'id'      => $id,
					'key'     => isset( $found['key'] ) ? $found['key'] : '',
				)
			);
		}

		$trashed = Uich_Fields_Model_Writer::trash( $id );
		if ( is_wp_error( $trashed ) ) {
			return $trashed;
		}

		// Listeners (page-cache purge, repository and read caches) key off this; no other path fired it.
		do_action( 'uich/definition_deleted', $id, 'group_fields', $found );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Sanitize field group meta.
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	private function sanitize_meta( $data ) {
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
	 * Format item for response.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $meta Meta data.
	 * @return array
	 */
	/**
	 * The same payload as format_item(), for a group that has no post behind it.
	 *
	 * @param int    $id     Derived definition id.
	 * @param array  $meta   Group definition.
	 * @param string $status publish | trash.
	 * @return array
	 */
	private function format_row( $id, $meta, $status = 'publish' ) {
		$key   = isset( $meta['key'] ) ? (string) $meta['key'] : '';
		$path  = Uich_Fields_Model_Paths::file_for_key( 'group_fields', $key );
		$mtime = ( is_string( $path ) && file_exists( $path ) ) ? (int) filemtime( $path ) : time();

		return array(
			'id'          => (int) $id,
			'title'       => ! empty( $meta['title'] ) ? $meta['title'] : $key,
			'kind'        => 'group_fields',
			'last_update' => gmdate( 'Y-m-d H:i:s', $mtime ),
			'status'      => $status,
			'data'        => is_array( $meta ) ? $meta : array(),
		);
	}
}
