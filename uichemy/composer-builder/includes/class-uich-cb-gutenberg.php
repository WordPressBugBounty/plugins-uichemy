<?php
/**
 * Uich_CB_Gutenberg — the "Edit with UiChemy" entry points: the block editor
 * button, the list-table row action and the front-end admin bar link.
 *
 * Decides WHETHER the button belongs on the current screen; the button itself is
 * drawn by assets/js/uich-cb-gutenberg.js and enqueued by Uich_CB_Enqueue.
 *
 * Gating here rather than in JavaScript is deliberate: skipping the enqueue is
 * what actually keeps the button off unsupported screens. Hiding it client-side
 * would still ship the script and the localized post data to every editor load.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Gutenberg' ) ) {

	/**
	 * Block-editor integration.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Gutenberg {

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			// Settings > Page builders > UiChemy Builder off: no way in from
			// anywhere (block editor, list tables, admin bar, Classic editor).
			if ( function_exists( 'uich_cb_builder_enabled' ) && ! uich_cb_builder_enabled() ) {
				return;
			}

			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );

			// Row action on Pages / Posts list tables, so the builder is also
			// reachable without opening the block editor first — the same place
			// WordPress puts "Edit | Quick Edit | Trash | View".
			add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );

			// Front-end admin bar, next to WordPress's own "Edit Page". Priority
			// 81 because core adds that node at 80 (wp_admin_bar_edit_menu) and
			// the bar renders nodes in the order they were added.
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 81 );

			// The Classic editor has no block-editor button to start from (J2-08).
			add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'classic_button' ) );
		}

		/**
		 * "Edit with UiChemy" in the Classic editor's Publish box.
		 *
		 * @param WP_Post $post Post being edited.
		 * @return void
		 */
		public static function classic_button( $post ) {
			if ( ! $post instanceof WP_Post || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
				return;
			}
			if ( function_exists( 'uich_brand_hidden_from_current_user' ) && uich_brand_hidden_from_current_user() ) {
				return;
			}
			if ( 'auto-draft' === $post->post_status || ! Uich_CB_Content::is_content_post( $post ) || ! Uich_CB_Policy::can_edit( $post ) ) {
				return;
			}
			printf(
				'<div class="misc-pub-section"><a class="button" href="%1$s">%2$s</a></div>',
				esc_url( Uich_CB_Content::edit_url( $post->ID ) ),
				/* translators: %s: product name, e.g. "UiChemy". */
				esc_html( sprintf( __( 'Edit with %s', 'uichemy' ), function_exists( 'uich_brand_name' ) ? uich_brand_name() : 'UiChemy' ) )
			);
		}

		/**
		 * Load the block-editor integration on supported post edit screens.
		 *
		 * @return void
		 */
		public static function enqueue() {
			// White Label's "hide from other admins" hides every UiChemy surface
			// from everyone but the owner; the editor button is one of them.
			if ( function_exists( 'uich_brand_hidden_from_current_user' ) && uich_brand_hidden_from_current_user() ) {
				return;
			}

			$post = get_post();

			if ( ! $post instanceof WP_Post ) {
				return;
			}

			// A content post (the post type, the capability and any site-level
			// opt-out in one call), or a Theme Builder template — see
			// is_theme_template().
			if ( ! Uich_CB_Content::is_content_post( $post ) && ! self::is_theme_template( $post ) ) {
				return;
			}

			// The Site Editor and the widget screens also fire
			// `enqueue_block_editor_assets` but have no post being edited in the
			// sense this button means, so require a real post edit screen.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' !== $screen->base ) {
				return;
			}

			Uich_CB_Enqueue::block_editor_assets( $post->ID );
		}

		/**
		 * Is this a Theme Builder template the builder can open?
		 *
		 * A new header, footer or single template opens in the block editor
		 * (UiChemy_Theme_Builder_Admin::handle_create), and this button is how
		 * its author moves it to the builder — the same choice a page gets.
		 * is_content_post() leaves templates out, so they are let back in here,
		 * for this button only: the row action stays content-only, and the
		 * template list is not a WordPress list table anyway.
		 *
		 * @param WP_Post $post Post being edited.
		 * @return bool
		 */
		private static function is_theme_template( $post ) {
			return Uich_CB_Post_Types::TEMPLATE === $post->post_type
				&& Uich_CB_Policy::can_edit( $post );
		}

		/**
		 * Add "Edit with UiChemy" to a post's row actions.
		 *
		 * @param array   $actions Existing row actions.
		 * @param WP_Post $post    The row's post.
		 * @return array
		 */
		public static function row_action( $actions, $post ) {
			if ( ! is_array( $actions ) || ! $post instanceof WP_Post ) {
				return $actions;
			}

			if ( function_exists( 'uich_brand_hidden_from_current_user' ) && uich_brand_hidden_from_current_user() ) {
				return $actions;
			}

			if ( ! Uich_CB_Content::is_content_post( $post ) ) {
				return $actions;
			}

			/*
			 * ONLY for posts that are actually built with UiChemy.
			 *
			 * The list table is not where a page is chosen to be a UiChemy page —
			 * the block editor's "Edit with UiChemy" button is. Offering the
			 * builder on every row put a UiChemy link under every page on the
			 * site, including ones edited entirely in Gutenberg or Elementor,
			 * which is noise in a menu WordPress keeps deliberately short.
			 *
			 * Here the row action is a SHORTCUT BACK: it appears once a page has
			 * been opened in the builder (which is what sets the flag — see
			 * Uich_CB_Editor::handle_request), so the pages that are UiChemy
			 * pages can be reopened without going through Gutenberg first.
			 */
			if ( ! Uich_CB_Content::is_enabled( $post->ID ) ) {
				return $actions;
			}

			$actions['uich_cb_edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( Uich_CB_Content::edit_url( $post->ID ) ),
				/* translators: %s: product name, e.g. "UiChemy". */
				esc_html( sprintf( __( 'Edit with %s', 'uichemy' ), uich_brand_name() ) )
			);

			return $actions;
		}

		/**
		 * Add "Edit with UiChemy" to the front-end admin bar.
		 *
		 * Same rule as the row action: only when something on the page is
		 * actually built with UiChemy, so a Gutenberg or Elementor page does not
		 * grow a link into a builder that has nothing to show for it.
		 *
		 * Where it goes, most specific first:
		 *  - a Theme Builder template's own URL → that template;
		 *  - a single view laid out by a Composer Builder "single" template →
		 *    that template, because it is what the visitor is looking at. When
		 *    the post is a UiChemy page too, both are offered as sub-items;
		 *  - a UiChemy page → the page.
		 *
		 * The canvas iframe never reaches here — Uich_CB_Canvas turns the admin
		 * bar off for it — so this is the visitor view and the preview only.
		 *
		 * @param WP_Admin_Bar $bar Admin bar instance.
		 * @return void
		 */
		public static function admin_bar_node( $bar ) {
			if ( is_admin() || ! is_admin_bar_showing() || ! is_singular() ) {
				return;
			}

			if ( function_exists( 'uich_brand_hidden_from_current_user' ) && uich_brand_hidden_from_current_user() ) {
				return;
			}

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post ) {
				return;
			}

			/* translators: %s: product name, e.g. "UiChemy". */
			$title = esc_html( sprintf( __( 'Edit with %s', 'uichemy' ), uich_brand_name() ) );

			// The brand's logo in front of it (the white-label logo when one is
			// set). Sized inline: the admin bar resets every element's box.
			$logo = function_exists( 'uich_brand_button_logo_url' ) ? uich_brand_button_logo_url() : '';
			if ( '' !== $logo ) {
				$title = '<img src="' . esc_url( $logo ) . '" alt="" width="16" height="16" style="display:inline-block;width:16px;height:16px;margin:0 6px 0 0;vertical-align:-3px;" />' . $title;
			}

			if ( self::is_theme_template( $post ) ) {
				if ( Uich_CB_Content::is_enabled( $post->ID ) ) {
					$bar->add_node(
						array(
							'id'    => 'uich-cb-edit',
							'title' => $title,
							'href'  => Uich_CB_Content::edit_url( $post->ID ),
						)
					);
				}
				return;
			}

			$template_id = self::single_template_id();
			$page_id     = Uich_CB_Content::is_content_post( $post ) && Uich_CB_Content::is_enabled( $post->ID ) ? $post->ID : 0;

			if ( ! $template_id && ! $page_id ) {
				return;
			}

			$bar->add_node(
				array(
					'id'    => 'uich-cb-edit',
					'title' => $title,
					'href'  => Uich_CB_Content::edit_url( $template_id ? $template_id : $page_id ),
				)
			);

			// Both are UiChemy-built: the template is the default, and each is
			// one click away from the dropdown.
			if ( $template_id && $page_id ) {
				$bar->add_node(
					array(
						'parent' => 'uich-cb-edit',
						'id'     => 'uich-cb-edit-template',
						'title'  => esc_html__( 'Edit Single Template', 'uichemy' ),
						'href'   => Uich_CB_Content::edit_url( $template_id ),
					)
				);
				$bar->add_node(
					array(
						'parent' => 'uich-cb-edit',
						'id'     => 'uich-cb-edit-page',
						/* translators: %s: post type singular name, e.g. "Page". */
						'title'  => esc_html( sprintf( __( 'Edit This %s', 'uichemy' ), get_post_type_object( $post->post_type )->labels->singular_name ) ),
						'href'   => Uich_CB_Content::edit_url( $page_id ),
					)
				);
			}
		}

		/**
		 * The Composer Builder "single" template laying out this request, if any.
		 *
		 * Read from the render rather than re-resolved: template_include has
		 * already run by the time the admin bar is built, so this is the
		 * template that actually rendered, not one that merely matches.
		 *
		 * @return int Template post ID, or 0.
		 */
		private static function single_template_id() {
			if ( ! class_exists( 'UiChemy_Template_Render' ) || ! class_exists( 'UiChemy_Template_CPT' ) ) {
				return 0;
			}

			$id = UiChemy_Template_Render::get_current_render_id();

			if ( ! $id || 'single' !== get_post_meta( $id, UiChemy_Template_CPT::META_TYPE, true ) ) {
				return 0;
			}

			if ( 'composer' !== UiChemy_Template_CPT::editor_for( $id ) || ! Uich_CB_Policy::can_edit( $id ) ) {
				return 0;
			}

			return $id;
		}
	}
}
