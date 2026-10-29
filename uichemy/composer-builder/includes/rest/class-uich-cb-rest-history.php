<?php
/**
 * Uich_CB_Rest_History — a page's saved versions and its autosave.
 *
 *   POST …/document/<id>/autosave                    { document, settings? }
 *   GET  …/document/<id>/revisions                   list them
 *   GET  …/document/<id>/revisions/<rev>             read one, without restoring
 *   POST …/document/<id>/revisions/<rev>/restore     put one back
 *
 * SAVE vs AUTOSAVE is the distinction the history model rests on. A save (the
 * page controller's PUT) is a version the user meant to keep, so it snapshots
 * a revision; an autosave is the preview copy, one row per author, overwritten
 * each time and never counted against `wp_revisions_to_keep()`. See
 * Uich_CB_Revisions.
 *
 * READING an old version and RESTORING it are separate routes on purpose: the
 * editor loads a version as unsaved work and Publish decides whether it goes
 * live, so looking at history can never change the page.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Rest_History' ) ) {

	/**
	 * REST routes for autosave and revisions.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Rest_History implements Uich_CB_Rest_Controller {

		/**
		 * Register the routes.
		 *
		 * @return void
		 */
		public static function register() {
			$edit = array( 'Uich_CB_Policy', 'rest_edit_post' );

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/document/(?P<id>\d+)/autosave' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'autosave' ),
					'permission_callback' => $edit,
					'args'                => array_merge(
						Uich_CB_Rest::id_arg(),
						array(
							'document' => array(
								'type'        => 'object',
								'required'    => true,
								'description' => __( 'The builder document to store.', 'uichemy' ),
							),
							'settings' => array(
								'type'        => 'object',
								'required'    => false,
								'description' => __( 'Page-level builder settings to store with it.', 'uichemy' ),
							),
						)
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/document/(?P<id>\d+)/revisions' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_revisions' ),
					'permission_callback' => $edit,
					'args'                => array_merge(
						Uich_CB_Rest::id_arg(),
						array(
							// Paging for the History panel's list, which loads more as
							// it is scrolled. Without per_page every revision comes back,
							// as it always has.
							'page'     => array(
								'type'    => 'integer',
								'minimum' => 1,
								'default' => 1,
							),
							'per_page' => array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 100,
								'default' => 0,
							),
						)
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/document/(?P<id>\d+)/revisions/(?P<revision>\d+)' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_revision' ),
					'permission_callback' => $edit,
					'args'                => array_merge( Uich_CB_Rest::id_arg(), Uich_CB_Rest::revision_arg( __( 'Revision ID to read.', 'uichemy' ) ) ),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/document/(?P<id>\d+)/revisions/(?P<revision>\d+)/restore' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'restore_revision' ),
					'permission_callback' => $edit,
					'args'                => array_merge( Uich_CB_Rest::id_arg(), Uich_CB_Rest::revision_arg( __( 'Revision ID to restore.', 'uichemy' ) ) ),
				)
			);
		}

		/**
		 * POST a document as this author's autosave.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function autosave( $request ) {
			$post_id  = absint( $request['id'] );
			$document = Uich_CB_Rest::read_cb_content( $request->get_param( 'document' ), $request );

			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$settings = $request->get_param( 'settings' );
			$result   = Uich_CB_Revisions::autosave( $post_id, $document, is_array( $settings ) ? $settings : null );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response(
				array(
					'id'       => $post_id,
					// Equal to `id` when the post was the author's own draft:
					// WordPress autosaves those in place rather than making a
					// separate row, and this mirrors that.
					'autosave' => (int) $result,
					'saved'    => true,
				)
			);
		}

		/**
		 * GET the revision history for a post.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function list_revisions( $request ) {
			$post_id  = absint( $request['id'] );
			$per_page = absint( $request['per_page'] );
			$page     = max( 1, absint( $request['page'] ) );
			$offset   = $per_page ? ( $page - 1 ) * $per_page : 0;
			$total    = Uich_CB_Revisions::count_revisions( $post_id );

			return rest_ensure_response(
				array(
					'id'        => $post_id,
					'supported' => Uich_CB_Revisions::is_supported( $post_id ),
					'revisions' => Uich_CB_Revisions::get_revisions( $post_id, $offset, $per_page ),
					'total'     => $total,
					'hasMore'   => $per_page ? $offset + $per_page < $total : false,
					// The autosave belongs at the top of the list: first page only.
					'autosave'  => 1 === $page ? Uich_CB_Revisions::get_autosave( $post_id ) : null,
				)
			);
		}

		/**
		 * GET one revision's document, in the editor's shape. Reads only.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function get_revision( $request ) {
			$revision = Uich_CB_Rest::owned_revision( $request );

			if ( is_wp_error( $revision ) ) {
				return $revision;
			}

			$document = Uich_CB_Revisions::get_revision_cb_content( $revision->ID );

			return rest_ensure_response(
				array(
					'id'       => absint( $request['id'] ),
					'revision' => (int) $revision->ID,
					'document' => array(
						'version'  => Uich_CB_Content::SCHEMA_VERSION,
						'rev'      => 0,
						'settings' => (object) ( isset( $document['settings'] ) ? $document['settings'] : array() ),
						'sections' => Uich_CB_Content::to_editor_sections( $document ),
					),
				)
			);
		}

		/**
		 * POST to restore a revision onto its post.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function restore_revision( $request ) {
			$revision = Uich_CB_Rest::owned_revision( $request );

			if ( is_wp_error( $revision ) ) {
				return $revision;
			}

			$restored = Uich_CB_Revisions::restore( $revision->ID );

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}

			$post_id = absint( $request['id'] );

			return rest_ensure_response(
				array(
					'id'       => $post_id,
					'restored' => (int) $revision->ID,
					'document' => (object) Uich_CB_Content::for_json( Uich_CB_Content::get_cb_content( $post_id ) ),
					'settings' => (object) Uich_CB_Content::get_cb_settings( $post_id ),
				)
			);
		}
	}
}
