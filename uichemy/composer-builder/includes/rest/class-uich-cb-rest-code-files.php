<?php
/**
 * Uich_CB_Rest_Code_Files — the uploaded code files the Code tab shows.
 *
 *   POST /code-files/read   { paths: [...] }                    read files
 *   POST /code-files/write  { path, contents, expected_hash? }  save one file
 *
 * Reading takes the list rather than a page id: the editor knows which files
 * the page references from the code it is holding, including edits not saved
 * yet, so it asks for exactly those. Reading needs `edit_posts` (the files are
 * public URLs anyway); writing needs `unfiltered_html`, the same right the
 * site-wide code needs, because one file can be loaded on every page.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Rest_Code_Files' ) ) {

	/**
	 * Code-file routes.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Rest_Code_Files implements Uich_CB_Rest_Controller {

		/**
		 * Register the routes.
		 *
		 * @return void
		 */
		public static function register() {
			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/code-files/read' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'read' ),
					'permission_callback' => array( 'Uich_CB_Policy', 'rest_edit_any' ),
					'args'                => array(
						'paths' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/code-files/write' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'write' ),
					'permission_callback' => array( __CLASS__, 'can_write' ),
					'args'                => array(
						'path'          => array(
							'type'     => 'string',
							'required' => true,
						),
						'contents'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'expected_hash' => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				)
			);
		}

		/**
		 * Write permission: the site-wide-code right.
		 *
		 * @return true|WP_Error
		 */
		public static function can_write() {
			if ( Uich_CB_Code_Files::can_write() ) {
				return true;
			}

			return new WP_Error(
				'uich_cb_code_file_forbidden',
				__( 'Only an administrator can change the site\'s code files.', 'uichemy' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * POST /code-files/read.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function read( $request ) {
			$paths = $request->get_param( 'paths' );

			return rest_ensure_response(
				array(
					'files'    => Uich_CB_Code_Files::read_many( is_array( $paths ) ? $paths : array() ),
					'canWrite' => Uich_CB_Code_Files::can_write(),
				)
			);
		}

		/**
		 * POST /code-files/write.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function write( $request ) {
			$result = Uich_CB_Code_Files::write(
				(string) $request->get_param( 'path' ),
				(string) $request->get_param( 'contents' ),
				(string) $request->get_param( 'expected_hash' )
			);

			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}
	}
}
