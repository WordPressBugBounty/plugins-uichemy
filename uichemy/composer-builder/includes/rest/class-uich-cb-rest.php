<?php
/**
 * Uich_CB_Rest — the builder's REST surface: one namespace, one registrar.
 *
 * Every route lives under `uichemy/v1/composer-builder/` and is registered by a
 * controller class, one per resource:
 *
 *   Uich_CB_Rest_Page      the page the editor is working on
 *     GET  page/<id>                         load the document
 *     PUT  page/<id>                         save it (snapshots a revision)
 *     POST page/<id>/render                  sections → html, without saving
 *     POST page/<id>/status                  draft ⇄ publish
 *     POST page/<id>/template                theme template ⇄ full-width canvas
 *     GET  library                           starter sections
 *
 *   Uich_CB_Rest_History   the page's saved versions and its autosave
 *     POST document/<id>/autosave             store the preview copy
 *     GET  document/<id>/revisions            list versions
 *     GET  document/<id>/revisions/<rev>      read one, without restoring
 *     POST document/<id>/revisions/<rev>/restore  put one back
 *
 *   Uich_CB_Rest_Cms       the CMS workspace (top bar › CMS: posts and custom
 *                          post types built with UiChemy); the rest is wp/v2
 *     GET  cms                               content types and their taxonomies
 *     POST cms/posts                         a new UiChemy draft, by title
 *     GET  cms/posts/<id>                    an item with its custom field groups
 *     POST cms/posts/<id>                    save its title and fields
 *     POST cms/posts/<id>/duplicate          copy a post as a draft
 *     POST cms/posts/<id>/restore            take it out of the trash
 *     GET  cms/visibility?ids=               who may see each post (Uich_CB_Visibility)
 *     POST cms/posts/<id>/visibility         set it
 *
 * The paths are the editor client's (src/editor/host/page-client.js and
 * shell/history/revisions-client.js) and do not change here.
 *
 * This class owns only what every controller shares — the namespace, the `id`
 * argument, the error shape, reading a document out of a request. Permissions
 * are Uich_CB_Policy's; what may be stored is Uich_CB_Code_Policy's, applied by
 * the storage gateway every write goes through.
 *
 * `uichemy/v1` is the namespace the rest of the plugin already publishes under;
 * the `composer-builder/` prefix keeps these routes from colliding with it.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Rest' ) ) {

	/**
	 * Registers the builder's REST controllers and holds what they share.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Rest {

		/**
		 * REST namespace, shared with the rest of the plugin.
		 */
		const NS = 'uichemy/v1';

		/**
		 * Route prefix that keeps the builder's routes to itself.
		 */
		const BASE = '/composer-builder';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
			// One Role Manager gate for every builder route (Uich_CB_Policy::rest_role_gate()).
			add_filter( 'rest_request_before_callbacks', array( 'Uich_CB_Policy', 'rest_role_gate' ), 10, 3 );
			// Before `init`: the CMS gives REST support to custom post types
			// registered without it, as they register.
			Uich_CB_Rest_Cms::boot();
		}

		/**
		 * The controllers whose routes make up the builder's REST surface.
		 *
		 * @return string[] Class names implementing Uich_CB_Rest_Controller.
		 */
		public static function controllers() {
			/**
			 * Filter the builder's REST controllers.
			 *
			 * Each entry is a class name implementing Uich_CB_Rest_Controller.
			 *
			 * @since 5.2.1
			 *
			 * @param string[] $controllers Controller class names.
			 */
			return (array) apply_filters(
				'uich_cb/rest_controllers',
				array( 'Uich_CB_Rest_Page', 'Uich_CB_Rest_History', 'Uich_CB_Rest_Code_Files', 'Uich_CB_Rest_Cms' )
			);
		}

		/**
		 * Register every controller's routes.
		 *
		 * @return void
		 */
		public static function register_routes() {
			foreach ( self::controllers() as $controller ) {
				if ( is_string( $controller ) && is_subclass_of( $controller, 'Uich_CB_Rest_Controller' ) ) {
					$controller::register();
				}
			}
		}

		/**
		 * A route path under the builder's prefix.
		 *
		 * @param string $path Path after the prefix, e.g. '/page/(?P<id>\d+)'.
		 * @return string
		 */
		public static function route( $path ) {
			return self::BASE . $path;
		}

		/**
		 * The `id` URL argument every per-post route takes.
		 *
		 * @return array
		 */
		public static function id_arg() {
			return array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'description'       => __( 'Post ID.', 'uichemy' ),
				),
			);
		}

		/**
		 * The `revision` URL argument.
		 *
		 * @param string $description What the route does with it.
		 * @return array
		 */
		public static function revision_arg( $description ) {
			return array(
				'revision' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'description'       => $description,
				),
			);
		}

		/**
		 * A REST error in the builder's shape.
		 *
		 * @param string $code    Error code.
		 * @param string $message Human-readable message.
		 * @param int    $status  HTTP status.
		 * @return WP_Error
		 */
		public static function error( $code, $message, $status ) {
			return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
		}

		/**
		 * A document handed in by a request, after the extension filter.
		 *
		 * Only the shape is checked here. What the document may CONTAIN is
		 * decided on the way into storage (Uich_CB_Content::prepare_for_storage),
		 * which no write can skip.
		 *
		 * @param mixed           $document Decoded document from the request.
		 * @param WP_REST_Request $request  The request carrying it.
		 * @return array|WP_Error
		 */
		public static function read_cb_content( $document, $request ) {
			if ( ! is_array( $document ) ) {
				return self::error( 'uich_cb_invalid_document', __( 'The builder document must be an object.', 'uichemy' ), 400 );
			}

			/**
			 * Filter a builder document on its way into storage.
			 *
			 * Runs for every save and autosave, before the storage gateway.
			 *
			 * @since 5.2.1
			 *
			 * @param array           $document Incoming document.
			 * @param WP_REST_Request $request  The request carrying it.
			 */
			return (array) apply_filters( 'uich_cb/save_cb_content', $document, $request );
		}

		/**
		 * The revision named in the URL, provided it belongs to the post in it.
		 *
		 * `edit_post` was checked against the post in the URL; reading or
		 * restoring any other post's revision through it would be checking the
		 * wrong permission.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_Post|WP_Error
		 */
		public static function owned_revision( $request ) {
			// A variable: wp_get_post_revision() takes its argument by reference,
			// and an expression there raised a notice on every read (A-07).
			$revision_id = absint( $request['revision'] );
			$revision    = wp_get_post_revision( $revision_id );

			if ( ! $revision || (int) $revision->post_parent !== absint( $request['id'] ) ) {
				return self::error( 'uich_cb_no_revision', __( 'That revision does not belong to this page.', 'uichemy' ), 404 );
			}

			return $revision;
		}
	}
}
