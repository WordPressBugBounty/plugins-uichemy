<?php
/**
 * REST surface for the third-party importer.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_REST
 */
class Uich_Fields_Import_REST {

	const NAMESPACE = UICH_FIELDS_REST_NS;

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_Import_REST|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_Import_REST
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
	 * Same gate as the rest of the module.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$source_arg = array(
			'source' => array(
				'description'       => __( 'Import source slug.', 'uichemy' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/sources',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sources' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/scan',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'scan' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $source_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$source_arg,
						array(
							'disable_source' => array(
								'description' => __( 'Disable the source plugin definitions after a clean import.', 'uichemy' ),
								'type'        => 'boolean',
								'default'     => false,
							),
							'import_values'  => array(
								'description' => __( 'Copy field values as well as definitions.', 'uichemy' ),
								'type'        => 'boolean',
								'default'     => true,
							),
							'key_map'        => array(
								'description' => __( 'Accepted key renames from the dry run, as { cpt: {old:new}, taxonomy: {old:new} }.', 'uichemy' ),
								'type'        => 'object',
								'default'     => array(),
							),
							'confirm'        => array(
								'description' => __( 'Acknowledge that the dry run was reviewed.', 'uichemy' ),
								'type'        => 'boolean',
								'default'     => false,
							),
						)
					),
				),
			)
		);

		// Batched run. /run does everything in one request and is fine for a small site; these
		// three split it so no single request grows with the number of objects.
		register_rest_route(
			self::NAMESPACE,
			'/import/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$source_arg,
						array(
							'disable_source' => array(
								'description' => __( 'Disable the source plugin definitions after a clean import.', 'uichemy' ),
								'type'        => 'boolean',
								'default'     => false,
							),
							'key_map'        => array(
								'description' => __( 'Accepted key renames from the dry run, as { cpt: {old:new}, taxonomy: {old:new} }.', 'uichemy' ),
								'type'        => 'object',
								'default'     => array(),
							),
							'confirm'        => array(
								'description' => __( 'Acknowledge that the dry run was reviewed.', 'uichemy' ),
								'type'        => 'boolean',
								'default'     => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/step',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'step' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$source_arg,
						array(
							'limit' => array(
								'description'       => __( 'Objects to process in this request.', 'uichemy' ),
								'type'              => 'integer',
								'default'           => 0,
								'sanitize_callback' => 'absint',
								'validate_callback' => static function ( $value ) {
									// Capped: the point of stepping is bounded work per request, so a
									// caller must not be able to ask for an unbounded page.
									return '' === $value || ( (int) $value >= 0 && (int) $value <= 200 );
								},
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/job',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'job' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $source_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $source_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/retry',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$source_arg,
						array(
							'force' => array(
								'description'       => 'Retry items the backoff or the retryable flag would otherwise hold back.',
								'type'              => 'boolean',
								'sanitize_callback' => 'rest_sanitize_boolean',
							),
							'limit' => array(
								'description'       => 'Items to attempt in this request.',
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
								'validate_callback' => function ( $v ) {
									return '' === $v || ( is_numeric( $v ) && (int) $v >= 0 && (int) $v <= 500 );
								},
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/cleanup',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cleanup' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $source_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/rollback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rollback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $source_arg,
				),
			)
		);
	}

	/**
	 * GET /import/sources
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_sources( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- required by the REST callback signature.
		return rest_ensure_response(
			array(
				'sources' => Uich_Fields_Import_Manager::detect(),
				// Finished migrations that still hold backups or failed items, so Undo / Retry survive leaving the result screen.
				'results' => Uich_Fields_Import_Manager::open_results(),
			)
		);
	}

	/**
	 * GET /import/scan
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan( $request ) {
		$out = Uich_Fields_Import_Manager::scan( (string) $request->get_param( 'source' ) );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /import/run Refuses without `confirm`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( $request ) {
		if ( ! $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'uich_import_confirm_required',
				__( 'Run the scan first and pass confirm=true to import. The scan reports what will be created, degraded or skipped.', 'uichemy' ),
				array(
					'status'                => 409,
					'requires_confirmation' => true,
				)
			);
		}
		$out = Uich_Fields_Import_Manager::run(
			(string) $request->get_param( 'source' ),
			array(
				'disable_source' => (bool) $request->get_param( 'disable_source' ),
				'import_values'  => (bool) $request->get_param( 'import_values' ),
				'key_map'        => (array) $request->get_param( 'key_map' ),
			)
		);
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /import/start Creates the definitions and returns a job the caller steps through.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( $request ) {
		if ( ! $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'uich_import_confirm_required',
				__( 'Run the scan first and pass confirm=true to import. The scan reports what will be created, degraded or skipped.', 'uichemy' ),
				array(
					'status'                => 409,
					'requires_confirmation' => true,
				)
			);
		}
		$out = Uich_Fields_Import_Manager::start_job(
			(string) $request->get_param( 'source' ),
			array(
				'disable_source' => (bool) $request->get_param( 'disable_source' ),
				'key_map'        => (array) $request->get_param( 'key_map' ),
			)
		);
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /import/step
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function step( $request ) {
		$out = Uich_Fields_Import_Manager::step_job(
			(string) $request->get_param( 'source' ),
			(int) $request->get_param( 'limit' )
		);
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * GET /import/job
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function job( $request ) {
		$job = Uich_Fields_Import_Manager::job_status( (string) $request->get_param( 'source' ) );
		return rest_ensure_response( array( 'job' => $job ) );
	}

	/**
	 * POST /import/retry — re-attempt failed items only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry( $request ) {
		$out = Uich_Fields_Import_Manager::retry_failed(
			(string) $request->get_param( 'source' ),
			array(
				'force' => (bool) $request->get_param( 'force' ),
				'limit' => (int) $request->get_param( 'limit' ),
			)
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /import/job — abandon a part-finished run.
	 *
	 * Does not undo anything already written; that is what /import/rollback is for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function cancel( $request ) {
		$ok = Uich_Fields_Import_Manager::cancel_job( (string) $request->get_param( 'source' ) );
		return rest_ensure_response( array( 'cancelled' => (bool) $ok ) );
	}

	/**
	 * POST /import/cleanup
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function cleanup( $request ) {
		$n = Uich_Fields_Import_Manager::cleanup_backups( (string) $request->get_param( 'source' ) );
		return rest_ensure_response(
			array(
				'removed' => $n,
				'message' => sprintf(
				/* translators: %d: number of backup rows removed */
					_n( '%d backup row removed.', '%d backup rows removed.', $n, 'uichemy' ),
					$n
				),
			)
		);
	}

	/**
	 * POST /import/rollback
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rollback( $request ) {
		$out = Uich_Fields_Import_Manager::rollback( (string) $request->get_param( 'source' ) );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}
}
