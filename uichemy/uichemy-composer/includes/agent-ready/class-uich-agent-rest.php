<?php
/**
 * Agent Ready admin REST routes for the dashboard screen.
 *
 * All routes are admin-only (manage_options): they change configuration, not
 * executable code. Nothing here is reachable by visitors; the public surface
 * is the site MCP, which has its own server and allowlist.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Rest' ) ) {

	/**
	 * REST controller.
	 */
	class Uich_Agent_Rest {

		/**
		 * Namespace.
		 */
		const NS = 'uichemy/v1';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static $limited = false;

		/**
		 * Hook routes.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		}

		/**
		 * Admin-only permission.
		 *
		 * @return bool
		 */
		public static function can_manage() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Register routes.
		 *
		 * @return void
		 */
		public static function routes() {
			if ( self::$limited ) {
				// Module off: read-only, admin-only routes.
				register_rest_route(
					self::NS,
					'/agent-ready/status',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_status' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					)
				);
				register_rest_route(
					self::NS,
					'/agent-ready/settings',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_settings' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					)
				);
				// Dashboard tabs still call /action; answer quietly (no console 404) and run nothing.
				register_rest_route(
					self::NS,
					'/agent-ready/action',
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'disabled_action' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					)
				);
				return;
			}
			register_rest_route(
				self::NS,
				'/agent-ready/settings',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_settings' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'save_settings' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
						'args'                => array(
							'settings' => array(
								'type'     => 'object',
								'required' => true,
							),
						),
					),
				)
			);
			register_rest_route(
				self::NS,
				'/agent-ready/settings/reset',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'reset_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);
			// Letting a plugin ability write data is a person's decision: this route is
			// the only place it can be switched on (dashboard, cookie + REST nonce), and
			// the AI-facing ability cannot reach it.
			register_rest_route(
				self::NS,
				'/agent-ready/tools/(?P<name>[a-z][a-z0-9_]{2,47})/allow-write',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'allow_write' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'allow' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				)
			);
			// The dashboard tabs (profile, tools, forms, inbox, audit, logs) run the same
			// actions as the ability, through the same dispatcher and checks.
			register_rest_route(
				self::NS,
				'/agent-ready/action',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'run_action' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[a-z][a-z0-9\-]{1,40}$',
						),
						'params' => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				)
			);
			register_rest_route(
				self::NS,
				'/agent-ready/status',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);
		}

		/**
		 * GET settings.
		 *
		 * @return WP_REST_Response
		 */
		public static function get_settings() {
			return rest_ensure_response(
				array(
					'settings' => Uich_Agent_Settings::get(),
					'defaults' => Uich_Agent_Settings::defaults(),
				)
			);
		}

		/**
		 * POST settings (partial update).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_settings( WP_REST_Request $request ) {
			$patch = $request->get_param( 'settings' );
			if ( ! is_array( $patch ) ) {
				return new WP_Error( 'uich_agent_bad_settings', 'settings must be an object.', array( 'status' => 400 ) );
			}
			// A browser session with the REST nonce is a person; Application Password
			// clients (how AI tools connect) cannot change the write switch.
			$human = (bool) wp_verify_nonce( (string) $request->get_header( 'x_wp_nonce' ), 'wp_rest' );
			$saved = Uich_Agent_Settings::update( $patch, $human );
			if ( is_wp_error( $saved ) ) {
				$saved->add_data( array( 'status' => 500 ) );
				return $saved;
			}
			return rest_ensure_response( array( 'settings' => $saved ) );
		}

		/**
		 * POST reset.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function reset_settings() {
			$saved = Uich_Agent_Settings::reset();
			if ( is_wp_error( $saved ) ) {
				$saved->add_data( array( 'status' => 500 ) );
				return $saved;
			}
			return rest_ensure_response( array( 'settings' => $saved ) );
		}

		/**
		 * POST allow-write: a person lets (or stops) an ability-bridge tool change data.
		 * The tool is switched off by the change and must be re-enabled.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function allow_write( WP_REST_Request $request ) {
			// Only a real browser session with the REST nonce counts as a person here;
			// Application Password calls (how AI clients connect) are refused.
			if ( ! wp_verify_nonce( (string) $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
				return new WP_Error( 'uich_agent_human_only', 'This change can only be made by a person on the Agent Ready screen.', array( 'status' => 403 ) );
			}
			$name = (string) $request->get_param( 'name' );
			$defs = Uich_Agent_Tools::definitions();
			if ( ! isset( $defs[ $name ] ) || 'ability' !== ( isset( $defs[ $name ]['type'] ) ? $defs[ $name ]['type'] : '' ) ) {
				return new WP_Error( 'uich_agent_no_tool', 'No ability tool with that name.', array( 'status' => 404 ) );
			}
			$def                          = $defs[ $name ];
			$def['config']['allow_write'] = (bool) $request->get_param( 'allow' );
			Uich_Agent_Tools::$human      = true;
			$saved                        = Uich_Agent_Tools::save( $def );
			Uich_Agent_Tools::$human      = false;
			if ( is_wp_error( $saved ) ) {
				$saved->add_data( array( 'status' => 400 ) );
				return $saved;
			}
			return rest_ensure_response( array( 'tool' => $saved ) );
		}

		/**
		 * POST action: run one Agent Ready action for the dashboard.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function run_action( WP_REST_Request $request ) {
			$params = $request->get_param( 'params' );
			$result = Uich_Agent_Ability::execute(
				array(
					'action'            => (string) $request->get_param( 'action' ),
					'action_parameters' => is_array( $params ) ? $params : array(),
				)
			);
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				if ( ! is_array( $data ) || empty( $data['status'] ) ) {
					$result->add_data( array( 'status' => 400 ) );
				}
				return $result;
			}
			return rest_ensure_response( $result );
		}

		/**
		 * Action reply while the module is off. Runs nothing.
		 *
		 * @return WP_REST_Response
		 */
		public static function disabled_action() {
			return rest_ensure_response(
				array(
					'disabled' => true,
					'enabled'  => false,
					'message'  => __( 'Agent Ready is turned off. Turn it on to use this.', 'uichemy' ),
				)
			);
		}

		/**
		 * GET status.
		 *
		 * @return WP_REST_Response
		 */
		public static function get_status() {
			return rest_ensure_response( Uich_Agent_Status::get() );
		}
	}
}
