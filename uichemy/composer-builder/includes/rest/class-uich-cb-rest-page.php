<?php
/**
 * Uich_CB_Rest_Page — the page the editor is working on.
 *
 *   GET  …/page/<id>             load the document
 *   PUT  …/page/<id>             save it
 *   POST …/page/<id>/render      sections → html, without saving
 *   POST …/page/<id>/status      draft ⇄ publish
 *   POST …/page/<id>/template    theme template ⇄ full-width canvas
 *   POST …/page/<id>/title       rename the page
 *   GET  …/page/<id>/settings    template, hide title, featured image
 *   POST …/page/<id>/settings    change any of them (partial)
 *   GET  …/library               starter sections
 *
 * The editor speaks `{version, rev, sections:[{id, settings}]}`; storage keeps
 * `{content:{html,css,js}, settings}`. Uich_CB_Content owns that translation
 * (to_editor_cb_content / save_cb_content), so no route here reimplements it.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Rest_Page' ) ) {

	/**
	 * REST routes for the page being edited.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Rest_Page implements Uich_CB_Rest_Controller {

		/**
		 * Register the routes.
		 *
		 * @return void
		 */
		public static function register() {
			$edit = array( 'Uich_CB_Policy', 'rest_edit_post' );
			$args = Uich_CB_Rest::id_arg();

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)' ),
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_page' ),
						'permission_callback' => $edit,
						'args'                => $args,
					),
					array(
						// EDITABLE covers PUT, which is what the client sends.
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'save_page' ),
						'permission_callback' => $edit,
						'args'                => $args,
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/render' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'render' ),
					'permission_callback' => $edit,
					'args'                => $args,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/status' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_status' ),
					'permission_callback' => $edit,
					'args'                => $args,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/template' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_template' ),
					'permission_callback' => $edit,
					'args'                => $args,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/title' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_title' ),
					'permission_callback' => $edit,
					'args'                => $args,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/settings' ),
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_settings' ),
						'permission_callback' => $edit,
						'args'                => $args,
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'set_settings' ),
						'permission_callback' => $edit,
						'args'                => $args,
					),
				)
			);

			// Moving a page or template between sites (B-01).
			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/page/(?P<id>\d+)/export' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'export_page' ),
					'permission_callback' => $edit,
					'args'                => $args,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/import' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'import_page' ),
					'permission_callback' => array( 'Uich_CB_Policy', 'rest_edit_any' ),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/library' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'library' ),
					'permission_callback' => array( 'Uich_CB_Policy', 'rest_edit_any' ),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/pages' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'pages' ),
					'permission_callback' => array( 'Uich_CB_Policy', 'rest_edit_any' ),
				)
			);
		}

		/* ─────────────────────────────────────────────────────────────────
		 * The document
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * GET the document, in the editor's shape.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function get_page( $request ) {
			return rest_ensure_response( Uich_CB_Content::to_editor_cb_content( absint( $request['id'] ) ) );
		}

		/**
		 * PUT the document back.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_page( $request ) {
			$post_id = absint( $request['id'] );
			$doc     = $request->get_json_params();

			// Keep this user's edit lock fresh while they save (A-04).
			Uich_CB_Editor::refresh_lock( $post_id );

			if ( ! is_array( $doc ) || ! isset( $doc['sections'] ) ) {
				return Uich_CB_Rest::error( 'uich_cb_bad_document', __( 'Expected a document with a sections list.', 'uichemy' ), 400 );
			}

			// Missing/invalid tokens must not opt a caller out of concurrency safety.
			if ( ! isset( $doc['rev'] ) || ! is_int( $doc['rev'] ) || $doc['rev'] < 0 ) {
				return Uich_CB_Rest::error( 'uich_cb_bad_revision', __( 'Reload this page before saving.', 'uichemy' ), 400 );
			}
			$expected_rev = $doc['rev'];
			$doc = Uich_CB_Rest::read_cb_content( $doc, $request );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}

			$settings = ( isset( $doc['settings'] ) && is_array( $doc['settings'] ) ) ? $doc['settings'] : null;

			$saved = Uich_CB_Content::save_cb_content( $post_id, $doc, array( 'settings' => $settings, 'expected_rev' => $expected_rev, 'return_document' => true ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			if ( ! $saved ) {
				return Uich_CB_Rest::error( 'uich_cb_save_failed', __( 'The builder document could not be saved.', 'uichemy' ), 500 );
			}

			// The saved document, re-read — so the client adopts what the server
			// stored (after the code policy) rather than what it sent.
			return rest_ensure_response( $saved );
		}

		/**
		 * A page as a portable file: its document, title, type and the URLs of
		 * the images it uses, so another site can import it (B-01).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function export_page( $request ) {
			$post_id  = absint( $request['id'] );
			$post     = get_post( $post_id );
			$document = Uich_CB_Content::get_cb_content( $post_id );

			return rest_ensure_response(
				array(
					'format'   => 'uichemy-composer-page',
					'version'  => 1,
					'site'     => home_url(),
					'title'    => $post ? $post->post_title : '',
					'type'     => $post ? $post->post_type : 'page',
					'template' => (string) get_post_meta( $post_id, '_wp_page_template', true ),
					'document' => $document,
					// A theme-builder template is its document PLUS where it applies;
					// without these an import was a template of no type that could
					// never render anywhere.
					'theme'    => self::theme_template_meta( $post ),
				)
			);
		}

		/**
		 * Type, target and display conditions of a theme-builder template, or null.
		 *
		 * @param WP_Post|null $post Post.
		 * @return array|null
		 */
		private static function theme_template_meta( $post ) {
			if ( ! $post instanceof WP_Post || ! class_exists( 'UiChemy_Template_CPT' ) || UiChemy_Template_CPT::POST_TYPE !== $post->post_type ) {
				return null;
			}
			$conditions = get_post_meta( $post->ID, UiChemy_Template_CPT::META_CONDITIONS, true );
			return array(
				'type'       => (string) get_post_meta( $post->ID, UiChemy_Template_CPT::META_TYPE, true ),
				'target'     => (string) get_post_meta( $post->ID, UiChemy_Template_CPT::META_TARGET, true ),
				'conditions' => is_array( $conditions ) ? $conditions : array(),
			);
		}

		/**
		 * Create a new draft from an exported page.
		 *
		 * The document goes through the same storage door as every save
		 * (Uich_CB_Content::save_cb_content → Uich_CB_Code_Policy), so an
		 * import can store nothing the user could not have typed. Image URLs
		 * that point at the exporting site are kept as written; the editor's
		 * image import (upload_images) or a search-replace moves them.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function import_page( $request ) {
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || 'uichemy-composer-page' !== ( isset( $body['format'] ) ? $body['format'] : '' ) || empty( $body['document'] ) || ! is_array( $body['document'] ) ) {
				return Uich_CB_Rest::error( 'uich_cb_bad_import', __( 'That is not a UiChemy page export.', 'uichemy' ), 400 );
			}

			$type = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : 'page';
			if ( ! in_array( $type, Uich_CB_Content::storable_post_types(), true ) ) {
				$type = 'page';
			}
			$type_object = get_post_type_object( $type );
			if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to create this content.', 'uichemy' ), rest_authorization_required_code() );
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'draft',
					'post_title'  => isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			if ( ! Uich_CB_Content::save_cb_content( $post_id, $body['document'] ) ) {
				wp_delete_post( $post_id, true );
				return Uich_CB_Rest::error( 'uich_cb_import_failed', __( 'The page could not be imported.', 'uichemy' ), 500 );
			}
			$template = isset( $body['template'] ) ? sanitize_text_field( (string) $body['template'] ) : '';
			if ( '' !== $template ) {
				update_post_meta( $post_id, '_wp_page_template', $template );
			}
			// A theme-builder template keeps its type and placement, imported
			// switched OFF: it must not take over this site's header or 404 until
			// someone turns it on in the Theme Builder.
			$theme = isset( $body['theme'] ) && is_array( $body['theme'] ) ? $body['theme'] : null;
			if ( $theme && class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $type ) {
				$tpl_type = isset( $theme['type'] ) ? sanitize_key( (string) $theme['type'] ) : '';
				if ( UiChemy_Template_CPT::is_valid_type( $tpl_type ) ) {
					update_post_meta( $post_id, UiChemy_Template_CPT::META_TYPE, $tpl_type );
					if ( class_exists( 'UiChemy_Template_Store' ) ) {
						update_post_meta( $post_id, UiChemy_Template_CPT::META_TARGET, UiChemy_Template_Store::sanitize_target( isset( $theme['target'] ) ? $theme['target'] : '' ) );
						update_post_meta( $post_id, UiChemy_Template_CPT::META_CONDITIONS, UiChemy_Template_Store::sanitize_conditions( isset( $theme['conditions'] ) ? $theme['conditions'] : array() ) );
					}
					update_post_meta( $post_id, UiChemy_Template_CPT::META_STATUS, 'inactive' );
				}
			}

			return rest_ensure_response(
				array(
					'id'      => (int) $post_id,
					'editUrl' => Uich_CB_Content::edit_url( $post_id ),
				)
			);
		}

		/**
		 * Render sections WITHOUT saving them.
		 *
		 * Takes sections from the body rather than storage, because the caller
		 * is usually previewing something not yet accepted — an AI proposal, or
		 * a keystroke that has not been committed. The HTML goes back to the
		 * same user's canvas and is stored nowhere.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function render( $request ) {
			if ( ! Uich_CB_Render::renderer_available() ) {
				return Uich_CB_Rest::error( 'uich_cb_no_renderer', __( 'The Composer renderer is unavailable.', 'uichemy' ), 409 );
			}

			$body     = $request->get_json_params();
			$sections = ( isset( $body['sections'] ) && is_array( $body['sections'] ) ) ? $body['sections'] : array();
			$post_id  = absint( $request['id'] );

			// With the edited post in scope, so dynamic tokens preview with their
			// real values - as they do on the canvas once it reloads. Trust is
			// judged by the edited post (a template), not the post it previews on.
			$html = Uich_CB_Render::with_post_context(
				$post_id,
				static function () use ( $sections, $post_id ) {
					$out = array();

					foreach ( $sections as $section ) {
						if ( ! is_array( $section ) || empty( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
							continue;
						}

						$uid = isset( $section['id'] ) ? sanitize_key( $section['id'] ) : '';

						if ( '' === $uid ) {
							continue;
						}

						// Editor mode, and the same wrapper the front end uses — a canvas
						// that wraps sections differently from the visitor's page is a
						// canvas that lies about spacing.
						$out[ $uid ] = Uich_CB_Render::render_section( $uid, $section['settings'], true, true, $post_id );
					}

					return $out;
				}
			);

			return rest_ensure_response( array( 'html' => $html ) );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Publishing
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Move the post between draft and publish.
		 *
		 * Deliberately does NOT write the document. The editor saves before it
		 * calls this; this only changes who can see the page.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function set_status( $request ) {
			$post_id = absint( $request['id'] );
			$body    = $request->get_json_params();
			$status  = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : '';

			if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
				return Uich_CB_Rest::error( 'uich_cb_bad_status', __( 'A page can only be set to draft or publish.', 'uichemy' ), 400 );
			}

			if ( 'publish' === $status && ! Uich_CB_Policy::can_publish( $post_id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_cannot_publish', __( 'You are not allowed to publish this page.', 'uichemy' ), rest_authorization_required_code() );
			}

			// A Duplicate Post "Rewrite & Republish" copy is republished onto its
			// original by that plugin. Published here, it stayed a hidden copy and
			// the original never changed (J2-09).
			if ( 'publish' === $status && get_post_meta( $post_id, '_dp_is_rewrite_republish_copy', true ) ) {
				return Uich_CB_Rest::error( 'uich_cb_rewrite_copy', __( 'This is a Rewrite & Republish copy. Save it here, then use Republish in the block editor so Duplicate Post updates the original page.', 'uichemy' ), 409 );
			}

			$postarr = array(
				'ID'          => $post_id,
				'post_status' => $status,
			);

			// Going live with WordPress's "Auto Draft" placeholder would publish
			// the page under that title, at /auto-draft/.
			$post = get_post( $post_id );
			if ( 'publish' === $status && $post instanceof WP_Post ) {
				$postarr = array_merge( $postarr, Uich_CB_Content::placeholder_fix( $post ) );
			}

			$result = wp_update_post( $postarr, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( self::publish_state( $post_id ) );
		}

		/**
		 * The publish payload, in the shape the editor's top bar reads.
		 *
		 * The boot payload (Uich_CB_Enqueue) sends the same thing from this same
		 * method, so the bar reads one shape at boot and after publishing.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public static function publish_state( $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			return array(
				'status'      => $post instanceof WP_Post ? $post->post_status : '',
				'canPublish'  => Uich_CB_Policy::can_publish( $post_id ),
				'link'        => (string) get_permalink( $post_id ),
				'previewLink' => (string) get_preview_post_link( $post_id ),
			);
		}

		/**
		 * Switch between the theme's template and the full-width canvas.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function set_template( $request ) {
			$post_id  = absint( $request['id'] );
			$body     = $request->get_json_params();
			$template = isset( $body['template'] ) ? sanitize_text_field( (string) $body['template'] ) : '';

			// '' is the theme's own template. Anything else has to be a template
			// the theme actually offers, or the page would 500 on the front end
			// looking for a file that is not there.
			if ( '' !== $template ) {
				$available = wp_get_theme()->get_page_templates( get_post( $post_id ) );

				if ( ! isset( $available[ $template ] ) ) {
					return Uich_CB_Rest::error( 'uich_cb_bad_template', __( 'That page template does not exist in the active theme.', 'uichemy' ), 400 );
				}
			}

			$updated = update_post_meta( $post_id, '_wp_page_template', '' === $template ? 'default' : $template );

			if ( false === $updated && get_page_template_slug( $post_id ) !== $template ) {
				return Uich_CB_Rest::error( 'uich_cb_template_failed', __( 'The page template could not be changed.', 'uichemy' ), 500 );
			}

			return rest_ensure_response( array( 'template' => (string) get_page_template_slug( $post_id ) ) );
		}

		/**
		 * Rename the page — the top bar's double-click on the page name.
		 *
		 * Only the title. The slug is left alone: renaming a page is not a
		 * request to move its URL, and a draft gets its slug from the title
		 * when it is published anyway (see placeholder_fix()).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function set_title( $request ) {
			$post_id = absint( $request['id'] );
			$body    = $request->get_json_params();
			$title   = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';

			if ( '' === $title ) {
				return Uich_CB_Rest::error( 'uich_cb_empty_title', __( 'A page needs a name.', 'uichemy' ), 400 );
			}

			$result = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( array( 'title' => (string) get_post_field( 'post_title', $post_id, 'raw' ) ) );
		}

		/**
		 * GET the page settings (Uich_CB_Page_Settings::get()).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function get_settings( $request ) {
			return rest_ensure_response( Uich_CB_Page_Settings::get( absint( $request['id'] ) ) );
		}

		/**
		 * POST a partial change to the page settings.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function set_settings( $request ) {
			$result = Uich_CB_Page_Settings::update( absint( $request['id'] ), $request->get_json_params() );
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Library
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Starter sections for the Add Section dialog.
		 *
		 * Empty until a library ships. It answers 200 with an empty list rather
		 * than 404 on purpose: the dialog's Library tab handles "nothing here"
		 * gracefully, and a 404 would surface as an error toast on every open.
		 *
		 * @return WP_REST_Response
		 */
		public static function library() {
			/**
			 * Filter the starter sections offered in the Add Section dialog.
			 *
			 * Each entry: { id, name, category, settings, preview }.
			 *
			 * @since 5.2.1
			 *
			 * @param array $sections Library sections.
			 */
			$sections = (array) apply_filters( 'uich_cb/library_sections', array() );

			return rest_ensure_response( array( 'sections' => array_values( $sections ) ) );
		}

		/**
		 * GET /pages — the posts built with the builder, for the editor's page
		 * switcher (its Pages / Templates / Posts tabs).
		 *
		 * Only posts the builder has opened (META_ENABLED), only ones the
		 * current user may edit, and only pages, posts and Theme Builder
		 * templates: a custom post type's items (a CPT's "doctors"…) are
		 * content managed in the CMS, not pages to switch the editor to, and
		 * listing them there read as a bug. Each carries its builder edit URL,
		 * which holds a per-post nonce and so can only be made here, and its
		 * `kind` (page | post | template); a template also its `templateType`
		 * (Header, Footer…). Most recently edited first, capped at 100.
		 *
		 * @return WP_REST_Response
		 */
		public static function pages() {
			$types = array_values( array_intersect( array( 'page', 'post' ), Uich_CB_Content::supported_post_types() ) );
			if ( post_type_exists( Uich_CB_Post_Types::TEMPLATE ) ) {
				$types[] = Uich_CB_Post_Types::TEMPLATE;
			}
			if ( ! $types ) {
				return rest_ensure_response( array( 'pages' => array() ) );
			}

			$query = new WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'         => 100,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One flag key, capped at 100 rows, editor-only route.
					'meta_query'             => array(
						array(
							'key'   => Uich_CB_Content::META_ENABLED,
							'value' => '1',
						),
					),
				)
			);

			$pages = array();
			foreach ( $query->posts as $post ) {
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				$type     = get_post_type_object( $post->post_type );
				$template = Uich_CB_Post_Types::TEMPLATE === $post->post_type;
				$entry    = array(
					'id'      => (int) $post->ID,
					'title'   => '' !== trim( (string) $post->post_title ) ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : __( '(no title)', 'uichemy' ),
					'type'    => $type && isset( $type->labels->singular_name ) ? $type->labels->singular_name : $post->post_type,
					'kind'    => $template ? 'template' : $post->post_type,
					'status'  => $post->post_status,
					'editUrl' => Uich_CB_Content::edit_url( $post->ID ),
				);
				if ( $template ) {
					// header, footer, single, archive, error_404… as a label.
					$meta_key              = class_exists( 'UiChemy_Template_CPT' ) ? UiChemy_Template_CPT::META_TYPE : '_uichemy_tpl_type';
					$slug                  = (string) get_post_meta( $post->ID, $meta_key, true );
					$entry['templateType'] = '' !== $slug ? ucwords( str_replace( array( '_', '-' ), ' ', 'error_404' === $slug ? '404 page' : $slug ) ) : __( 'Template', 'uichemy' );
				}
				$pages[] = $entry;
			}

			return rest_ensure_response( array( 'pages' => $pages ) );
		}
	}
}
