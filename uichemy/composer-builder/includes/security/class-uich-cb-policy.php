<?php
/**
 * Uich_CB_Policy — who may do what with the builder.
 *
 * Every permission decision the builder makes is answered here, and every
 * entry point asks this class rather than checking capabilities itself: the
 * editor screen, each REST route, the canvas, the block-editor button. When
 * the rule for one of them changes, it changes for all of them, and a route
 * added later gets the same answer by calling the same method.
 *
 *   can_edit( $post )       open the post in the builder and save it
 *   can_publish( $post_id ) take it live
 *   can_edit_any()          use a builder surface that is not about one post
 *
 * The REST permission callbacks at the bottom are the same rules, returning
 * the WP_Error a REST client expects instead of a bool.
 *
 * What a permitted user may STORE is a separate question, answered by
 * Uich_CB_Code_Policy.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Policy' ) ) {

	/**
	 * Permission rules for the builder.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Policy {

		/**
		 * May the current user open this post in the builder, and save it?
		 *
		 * @param int|WP_Post|null $post Post or post ID.
		 * @return bool
		 */
		public static function can_edit( $post = null ) {
			$post = get_post( $post );

			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			if ( ! in_array( $post->post_type, Uich_CB_Content::storable_post_types(), true ) ) {
				return false;
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) || ! self::role_allows() ) {
				return false;
			}

			// A template renders on every page it is assigned to, so editing
			// one is a site-wide change: it needs what the Theme Builder asks
			// for to manage templates, not just the right to edit the post.
			// `edit_post` alone would let an editor (anyone with
			// edit_others_posts) rewrite the site's header.
			if ( Uich_CB_Post_Types::is_builder_type( $post->post_type ) && ! current_user_can( self::template_capability() ) ) {
				return false;
			}

			/**
			 * Filter whether a single post can be opened in the Composer Builder.
			 *
			 * @since 5.2.1
			 *
			 * @param bool    $supported Result so far.
			 * @param WP_Post $post      The post being checked.
			 */
			return (bool) apply_filters( 'uich_cb/is_supported', true, $post );
		}

		/**
		 * May the current user take this post live?
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function can_publish( $post_id ) {
			return current_user_can( 'publish_post', (int) $post_id );
		}

		/**
		 * May the current user use a builder surface that is not about one post
		 * (the section library)?
		 *
		 * @return bool
		 */
		public static function can_edit_any() {
			return current_user_can( 'edit_posts' ) && self::role_allows();
		}

		/**
		 * Does the Role Manager let the current user open the UiChemy editor?
		 *
		 * The same gate the front-end editor applies (UiChemy_Roles::can_edit()):
		 * a role set to No Access never reaches the builder. In Free the Role
		 * Manager is ignored and every `edit_posts` role passes.
		 *
		 * @return bool
		 */
		public static function role_allows() {
			return ! class_exists( 'UiChemy_Roles' ) || UiChemy_Roles::can_edit();
		}

		/**
		 * Is the current user limited to Content Only (text and images, no
		 * design, structure or code)?
		 *
		 * @return bool
		 */
		public static function content_only() {
			return class_exists( 'UiChemy_Roles' ) && UiChemy_Roles::is_content_only();
		}

		/**
		 * rest_request_before_callbacks: refuse every builder route to a role the
		 * Role Manager set to No Access, whatever the route's own callback says.
		 *
		 * @param mixed           $response Response so far.
		 * @param array           $handler  Route handler.
		 * @param WP_REST_Request $request  Request.
		 * @return mixed
		 */
		public static function rest_role_gate( $response, $handler, $request ) {
			if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
				return $response;
			}
			$prefix = '/' . Uich_CB_Rest::NS . Uich_CB_Rest::BASE . '/';
			if ( 0 !== strpos( $request->get_route(), $prefix ) || ! is_user_logged_in() || self::role_allows() ) {
				return $response;
			}
			return Uich_CB_Rest::error( 'uich_cb_role_no_access', __( 'Your role does not have access to the UiChemy editor.', 'uichemy' ), 403 );
		}

		/**
		 * The capability that editing a builder-owned post (a template) needs.
		 *
		 * @return string
		 */
		public static function template_capability() {
			/**
			 * Filter the capability required to edit a template in the builder.
			 *
			 * Defaults to the Theme Builder's own gate, so the two never
			 * disagree about who may change a template.
			 *
			 * @since 5.2.1
			 *
			 * @param string $capability Capability name.
			 */
			return (string) apply_filters( 'uich_cb/template_capability', 'manage_options' );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * REST permission callbacks
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * REST: may the current user edit the post named by the `id` URL param?
		 *
		 * Distinguishes the three ways it can fail, because the editor reports
		 * them differently: the page is gone (404), the user may not touch it
		 * (401/403), or the builder does not handle this post (400).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function rest_edit_post( $request ) {
			$post_id = absint( $request['id'] );
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return Uich_CB_Rest::error( 'uich_cb_not_found', __( 'That page no longer exists.', 'uichemy' ), 404 );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to edit this page.', 'uichemy' ), rest_authorization_required_code() );
			}

			if ( ! self::can_edit( $post ) ) {
				return Uich_CB_Rest::error( 'uich_cb_unsupported', __( 'This content type cannot be edited with the builder.', 'uichemy' ), 400 );
			}

			return true;
		}

		/**
		 * REST: may the current user use surfaces not tied to one post?
		 *
		 * @return true|WP_Error
		 */
		public static function rest_edit_any() {
			if ( ! self::can_edit_any() ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to edit content.', 'uichemy' ), rest_authorization_required_code() );
			}

			return true;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Stored builder state
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * `auth_callback` for the builder's registered meta: nobody.
		 *
		 * WordPress consults this whenever builder meta is written through a
		 * generic path — the Custom Fields box and its `add-meta` AJAX action,
		 * XML-RPC `custom_fields`, the meta REST API. None of those go through
		 * Uich_CB_Content::prepare_for_storage(), so allowing them would let
		 * anyone who can edit a post store unfiltered code in it. The builder
		 * writes its own meta directly, which this does not gate.
		 *
		 * @return bool
		 */
		public static function meta_auth() {
			return false;
		}
	}
}
