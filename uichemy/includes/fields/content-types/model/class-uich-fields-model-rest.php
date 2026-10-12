<?php
/**
 * REST surface for the file-first model.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_REST
 */
class Uich_Fields_Model_REST {

	const NAMESPACE = UICH_FIELDS_REST_NS;

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_Model_REST|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_Model_REST
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
		register_rest_route(
			self::NAMESPACE,
			'/model/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/model/repair',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_repair' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'dry_run' => array(
							'description'       => __( 'List the renames that would be made without making them.', 'uichemy' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * What the file store currently holds, and any problems with it.
	 *
	 * Definitions are file-first: the repository's rows are built FROM these files, so there is no
	 * second copy to diff against. (The old `differences` list compared the files with themselves.)
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$writable = Uich_Fields_Model_Store::is_writable();
		$scan     = Uich_Fields_Model_Validator::validate_scan( Uich_Fields_Model_Loader::scan() );

		$counts = array();
		foreach ( $scan['definitions'] as $kind => $defs ) {
			$counts[ $kind ] = count( $defs );
		}

		return rest_ensure_response(
			array(
				'writable'    => (bool) $writable,
				'directory'   => self::relative_dir(),
				'counts'      => $counts,
				'problems'    => array_values( $scan['problems'] ),
				'repairable'  => array_values( isset( $scan['repairs'] ) ? $scan['repairs'] : array() ),
				'last_error'  => Uich_Fields_Model_Sync::last_error(),
			)
		);
	}

	/**
	 * Rename files whose filename no longer matches the key stored inside them.
	 *
	 * @param WP_REST_Request|null $request Request; carries `dry_run`.
	 * @return WP_REST_Response
	 */
	public function run_repair( $request = null ) {
		$scan    = Uich_Fields_Model_Loader::scan();
		$repairs = isset( $scan['repairs'] ) ? $scan['repairs'] : array();

		// The repair ability defaults to a preview and sends dry_run; this route used to ignore it and
		// rename files anyway.
		if ( $request instanceof WP_REST_Request && $request->get_param( 'dry_run' ) ) {
			$planned = array();
			foreach ( (array) $repairs as $repair ) {
				if ( is_array( $repair ) && isset( $repair['type'] ) && 'rename_file' === $repair['type'] ) {
					$planned[] = array(
						'from' => isset( $repair['from'] ) ? basename( (string) $repair['from'] ) : '',
						'to'   => isset( $repair['to'] ) && is_string( $repair['to'] ) ? basename( $repair['to'] ) : '',
					);
				}
			}
			return rest_ensure_response(
				array(
					'applied' => 0,
					'planned' => $planned,
					'failed'  => array(),
				)
			);
		}

		$result = Uich_Fields_Model_Loader::repair( $repairs );
		Uich_Fields_Model_Cache::flush();
		return rest_ensure_response( $result );
	}

	/**
	 * The model directory as a path relative to wp-content, for display.
	 *
	 * @return string
	 */
	private static function relative_dir() {
		$dir = Uich_Fields_Model_Paths::site_dir();
		$wp  = wp_normalize_path( WP_CONTENT_DIR );
		return 0 === strpos( $dir, $wp ) ? ltrim( substr( $dir, strlen( $wp ) ), '/' ) : basename( $dir );
	}
}
