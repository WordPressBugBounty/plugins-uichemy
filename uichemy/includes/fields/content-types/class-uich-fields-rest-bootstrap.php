<?php
/**
 * REST: content types list, bootstrap, CPT/taxonomy saves, deletes, location choices, post-object terms.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_REST_Bootstrap
 */
class Uich_Fields_REST_Bootstrap {

	const NAMESPACE = UICH_FIELDS_REST_NS;

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_REST_Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_REST_Bootstrap
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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/list',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_list' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bootstrap',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bootstrap' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		$cpt_tax_args = array(
			'id'      => array(
				'description'       => __( 'Existing definition post ID, or 0 to create.', 'uichemy' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				// Validate BEFORE absint() sanitizes.
				'validate_callback' => function ( $value, $request, $key ) {
					if ( ! is_numeric( $value ) || (int) $value < 0 ) {
						return new WP_Error(
							'rest_invalid_param',
							sprintf(
								/* translators: %s: the request parameter name */
								__( '%s must be zero (to create) or a positive definition ID.', 'uichemy' ),
								$key
							),
							array( 'status' => 400 )
						);
					}
					return true;
				},
				'sanitize_callback' => 'absint',
			),
			'data'    => array(
				'description'       => __( 'Field values object.', 'uichemy' ),
				'type'              => 'object',
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? $value : array();
				},
			),
			// Acknowledges a destructive change the save would otherwise refuse — currently
			// deactivating a type that still has content (409 + requires_confirmation).
			'confirm' => array(
				'description' => __( 'Proceed with a change that hides existing content.', 'uichemy' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/cpt',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_cpt' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $cpt_tax_args,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/taxonomy',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_taxonomy' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $cpt_tax_args,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/definitions/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_definition' ),
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_definition' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
					),
				),
			)
		);

		// Trash management. GET lists what is recoverable; POST .../restore puts one back;
		// DELETE .../permanent is the irreversible counterpart to the trashing DELETE above.
		register_rest_route(
			self::NAMESPACE,
			'/trash',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_trash' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		$id_arg = array(
			'id' => array(
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/definitions/(?P<id>[\d]+)/restore',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_definition' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/definitions/(?P<id>[\d]+)/permanent',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_definition_permanently' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_definitions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'dry_run'        => array(
							'description'       => __( 'Classify every item as new, update or invalid and write nothing.', 'uichemy' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'schema_version' => array(
							'description' => __( 'Schema version the file was exported with.', 'uichemy' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/location-choices',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_location_choices' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'param' => array(
							'description'       => __( 'Location rule parameter key.', 'uichemy' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/post-object-terms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_object_terms' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check (matches Ajax handlers).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_permission( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /list — full definitions list (CPT, taxonomy, group_fields).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_list( $request ) {
		$items = array();
		foreach ( $this->iterate_definitions() as $row ) {
			$items[] = $this->format_list_row( $row );
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * GET /bootstrap — one query; splits CPT/tax vs field groups (matches App.jsx merge).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_bootstrap( $request ) {
		$cpt_tax      = array();
		$field_groups = array();
		foreach ( $this->iterate_definitions() as $row ) {
			if ( 'group_fields' === $row['kind'] ) {
				$field_groups[] = $this->format_field_group_row( $row );
			} else {
				$cpt_tax[] = $this->format_list_row( $row );
			}
		}
		return rest_ensure_response(
			array(
				'cpt_tax_items'     => $cpt_tax,
				'field_group_items' => $field_groups,
				'model_problems'    => $this->model_problems(),
			)
		);
	}

	/**
	 * Definition files that were not loaded (invalid JSON, duplicate keys, ...), for the dashboard banner.
	 *
	 * Without this the definition just disappears from the list. The two "changed outside the admin"
	 * drift notices are left out: they are informational and hide nothing.
	 *
	 * @return array<int, array{type:string,kind:string,key:string,file:string,message:string}>
	 */
	private function model_problems() {
		$model = Uich_Fields_Model_Cache::get();
		$out   = array();
		foreach ( isset( $model['problems'] ) && is_array( $model['problems'] ) ? $model['problems'] : array() as $problem ) {
			$type = isset( $problem['type'] ) ? (string) $problem['type'] : '';
			if ( in_array( $type, array( 'file_changed_outside_ui', 'file_removed_outside_ui' ), true ) ) {
				continue;
			}
			$out[] = array(
				'type'    => $type,
				'kind'    => isset( $problem['kind'] ) ? (string) $problem['kind'] : '',
				'key'     => isset( $problem['key'] ) ? (string) $problem['key'] : '',
				'file'    => isset( $problem['file'] ) ? (string) $problem['file'] : '',
				'message' => isset( $problem['message'] ) ? (string) $problem['message'] : '',
			);
		}
		return $out;
	}

	/**
	 * Shared definition rows from the repository (one cached, meta-primed query).
	 *
	 * @return array<int, array{id: int, title: string, status: string, modified: string, meta: array, kind: string}>
	 */
	private function iterate_definitions() {
		return Uich_Fields_Definitions_Repository::get_instance()->get_rows();
	}

	/**
	 * Row shape for ajax_list / CPT / taxonomy table.
	 *
	 * @param array $row Repository row.
	 * @return array
	 */
	private function format_list_row( $row ) {
		return array(
			'id'          => $row['id'],
			'title'       => $row['title'],
			'kind'        => $row['kind'],
			'last_update' => $row['modified'],
			'status'      => $row['status'],
			'data'        => $row['meta'],
		);
	}

	/**
	 * Row shape aligned with Uich_Fields_REST_Field_Groups::format_item.
	 *
	 * @param array $row Repository row.
	 * @return array
	 */
	private function format_field_group_row( $row ) {
		$title = $row['title'];
		if ( ! empty( $row['meta']['title'] ) ) {
			$title = $row['meta']['title'];
		}
		return array(
			'id'          => $row['id'],
			'title'       => $title,
			'kind'        => 'group_fields',
			'last_update' => $row['modified'],
			'status'      => $row['status'],
			'field_count' => self::count_group_fields( $row['meta'] ),
			'data'        => $row['meta'],
		);
	}

	/**
	 * How many fields a group defines.
	 *
	 * @param array $meta Group definition meta.
	 * @return int
	 */
	private static function count_group_fields( $meta ) {
		if ( ! is_array( $meta ) || empty( $meta['fields'] ) || ! is_array( $meta['fields'] ) ) {
			return 0;
		}
		return count( $meta['fields'] );
	}

	/**
	 * Normalize JSON/body "data" param to array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function get_data_param_as_array( $request ) {
		$data = $request->get_param( 'data' );
		if ( is_array( $data ) ) {
			return $data;
		}
		$json = $request->get_json_params();
		if ( is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] ) ) {
			return $json['data'];
		}
		return array();
	}

	/**
	 * POST /cpt
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_cpt( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$data    = $this->get_data_param_as_array( $request );
		$confirm = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
		$out     = Uich_Fields_Admin_Logic::save_cpt( $id, $data, $confirm, $dry_run );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /taxonomy
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_taxonomy( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$data    = $this->get_data_param_as_array( $request );
		$confirm = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
		$out     = Uich_Fields_Admin_Logic::save_taxonomy( $id, $data, $confirm, $dry_run );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /definitions/{id} — single definition (CPT, taxonomy or field group),
	 * shaped by its kind so callers get the same row format as /list and /bootstrap.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_definition( $request ) {
		$id = (int) $request['id'];
		foreach ( $this->iterate_definitions() as $row ) {
			if ( (int) $row['id'] === $id ) {
				$out = 'group_fields' === $row['kind']
					? $this->format_field_group_row( $row )
					: $this->format_list_row( $row );

				// Content impact — lets the delete-confirm UI warn "this will hide N items".
				if ( 'cpt' === $row['kind'] || 'taxonomy' === $row['kind'] ) {
					$meta                 = is_array( $row['meta'] ) ? $row['meta'] : array();
					$key_field            = 'cpt' === $row['kind'] ? 'post_type_key' : 'taxonomy_key';
					$key                  = isset( $meta[ $key_field ] ) ? (string) $meta[ $key_field ] : '';
					$out['content_count'] = Uich_Fields_Admin_Logic::content_count_for_key( $row['kind'], $key );
				}

				return rest_ensure_response( $out );
			}
		}
		return new WP_Error(
			'rest_not_found',
			__( 'Definition not found.', 'uichemy' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * DELETE /definitions/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_definition( $request ) {
		$id      = (int) $request['id'];
		$confirm = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
		$out     = Uich_Fields_Admin_Logic::delete_definition( $id, $confirm, $dry_run );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /trash — definitions currently in the Trash, in the same row shape as /list so the
	 * existing table can render them without a second format.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_trash( $request ) {
		$items = array();
		foreach ( Uich_Fields_Admin_Logic::get_trashed_definitions() as $row ) {
			$items[] = ( 'group_fields' === $row['kind'] )
				? $this->format_field_group_row( $row )
				: $this->format_list_row( $row );
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * POST /definitions/{id}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_definition( $request ) {
		$out = Uich_Fields_Admin_Logic::restore_definition(
			(int) $request['id'],
			rest_sanitize_boolean( $request->get_param( 'dry_run' ) )
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /definitions/{id}/permanent
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_definition_permanently( $request ) {
		// `confirm` keeps a deleted group's stored values; `delete_values` removes them. Neither set on a
		// group that still has values is a 409 (the module's own callers use the PHP default instead).
		$out = Uich_Fields_Admin_Logic::delete_definition_permanently(
			(int) $request['id'],
			rest_sanitize_boolean( $request->get_param( 'dry_run' ) ),
			rest_sanitize_boolean( $request->get_param( 'confirm' ) ),
			rest_sanitize_boolean( $request->get_param( 'delete_values' ) )
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /import — batch import with key-based upsert (created/updated counts + per-item errors).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_definitions( $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			$json  = $request->get_json_params();
			$items = ( is_array( $json ) && isset( $json['items'] ) && is_array( $json['items'] ) ) ? $json['items'] : array();
		}
		$schema = $request->get_param( 'schema_version' );
		if ( null === $schema ) {
			$json   = $request->get_json_params();
			$schema = ( is_array( $json ) && isset( $json['schema_version'] ) ) ? $json['schema_version'] : null;
		}
		$out = Uich_Fields_Admin_Logic::import_definitions(
			$items,
			rest_sanitize_boolean( $request->get_param( 'dry_run' ) ),
			is_numeric( $schema ) ? (int) $schema : null
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /location-choices?param=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_location_choices( $request ) {
		$param = (string) $request->get_param( 'param' );
		$out   = Uich_Fields_Admin_Logic::get_location_choices( $param );
		return rest_ensure_response( $out );
	}

	/**
	 * GET /post-object-terms
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_post_object_terms( $request ) {
		return rest_ensure_response( Uich_Fields_Admin_Logic::get_post_object_terms_payload() );
	}
}
