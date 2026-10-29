<?php
/**
 * Uich_CB_Canvas — the page, on the front end, with the editor's bridge in it.
 *
 * The editor's middle column is an iframe pointed at the REAL page with
 * `?uipb_canvas=1` on it. That is the whole design: what you edit is the page a
 * visitor gets, rendered by the same renderer, styled by the same theme — not a
 * reconstruction of it. The only difference this class makes is the bridge
 * script, which reports geometry, hover and selection back to the editor and
 * applies section patches without a reload.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Canvas' ) ) {

	/**
	 * Serves the canvas view of a page.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Canvas {

		/**
		 * Query var that turns a page into the canvas.
		 */
		const QUERY_VAR = 'uipb_canvas';

		/**
		 * Script handle for the bridge.
		 */
		const HANDLE = 'uich-cb-canvas';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_enqueue' ) );
			add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
			add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
			add_filter( 'edit_post_link', array( __CLASS__, 'hide_edit_link' ) );
			add_action( 'wp_footer', array( __CLASS__, 'print_page_template' ), 5 );
		}

		/**
		 * Name the full-page Theme Builder template this canvas rendered with
		 * (single, product, archive…), for the top bar's template list.
		 *
		 * The header and footer are already marked where they render
		 * (UiChemy_Locations, `data-uipb-location`); the template drawing the
		 * page itself has no wrapper of its own, so it is named here, in an
		 * inert <template> the canvas reads on load (canvas.js, READY). Only on
		 * the canvas, and only for someone who may edit that template.
		 *
		 * @return void
		 */
		public static function print_page_template() {
			if ( ! self::is_canvas() || ! class_exists( 'UiChemy_Template_Render' ) || ! class_exists( 'UiChemy_Template_CPT' ) ) {
				return;
			}

			$id = (int) UiChemy_Template_Render::get_current_render_id();

			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
				return;
			}

			$type = (string) get_post_meta( $id, UiChemy_Template_CPT::META_TYPE, true );

			printf(
				'<template data-uipb-page-template="%1$s" data-uipb-template="%2$d" data-uipb-title="%3$s" data-uipb-editor="%4$s" data-uipb-edit="%5$s"></template>',
				esc_attr( '' !== $type ? $type : 'single' ),
				(int) $id,
				esc_attr( get_the_title( $id ) ),
				esc_attr( UiChemy_Template_CPT::editor_for( $id ) ),
				esc_url( UiChemy_Template_CPT::edit_url_for( $id ) )
			);
		}

		/**
		 * Is this request the canvas?
		 *
		 * @return bool
		 */
		public static function is_canvas() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch; the capability check is in maybe_enqueue().
			return isset( $_GET[ self::QUERY_VAR ] ) && '1' === (string) $_GET[ self::QUERY_VAR ];
		}

		/**
		 * The canvas URL for a post.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function url( $post_id ) {
			return add_query_arg( self::QUERY_VAR, '1', (string) get_permalink( (int) $post_id ) );
		}

		/**
		 * Load the bridge, for an editor only.
		 *
		 * The capability check is the point: the canvas is the page itself, so
		 * without it any visitor could append the query var and be served an
		 * editing bridge. It gives them nothing on its own — every write goes
		 * through REST, which checks again — but it is still a script nobody
		 * else has any reason to receive.
		 *
		 * @return void
		 */
		public static function maybe_enqueue() {
			if ( ! self::is_canvas() || ! is_singular() ) {
				return;
			}

			$post_id = get_queried_object_id();

			// The same rule as the editor that frames it, so a canvas is never
			// served for a post the builder itself would refuse.
			if ( ! $post_id || ! Uich_CB_Policy::can_edit( $post_id ) ) {
				return;
			}

			$js    = UICH_CB_BUILD_PATH . 'canvas.js';
			$asset = UICH_CB_BUILD_PATH . 'canvas.asset.php';

			if ( ! file_exists( $js ) ) {
				return;
			}

			$meta = file_exists( $asset ) ? include $asset : array();
			$deps = ( isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ) ? $meta['dependencies'] : array();
			$ver  = isset( $meta['version'] ) ? (string) $meta['version'] : (string) filemtime( $js );

			wp_enqueue_script( self::HANDLE, UICH_CB_BUILD_URL . 'canvas.js', $deps, $ver, true );

			// The composer's canvas design system, for the Composer widget's handle
			// bar and typography controls the editor draws into this page. Tailwind
			// without preflight, every rule scoped to `.uich-tw`, so it cannot restyle
			// the page being edited — the file Elementor's preview gets for the same
			// bar. Absent only in an unbuilt checkout; the bar then renders unstyled.
			$ui_css = UICH_CB_BUILD_PATH . 'canvas-ui.css';
			if ( file_exists( $ui_css ) ) {
				wp_enqueue_style( self::HANDLE . '-ui', UICH_CB_BUILD_URL . 'canvas-ui.css', array(), (string) filemtime( $ui_css ) );
			}
		}

		/**
		 * Mark the document so a theme (and our own CSS) can tell.
		 *
		 * @param array $classes Body classes.
		 * @return array
		 */
		public static function body_class( $classes ) {
			if ( self::is_canvas() ) {
				$classes[] = 'uipb-canvas';
			}

			return $classes;
		}

		/**
		 * No admin bar on the canvas — it is 32px of someone else's chrome
		 * inside an iframe that is measuring positions.
		 *
		 * @param bool $show Whether to show the admin bar.
		 * @return bool
		 */
		public static function hide_admin_bar( $show ) {
			return self::is_canvas() ? false : $show;
		}

		/**
		 * No theme "Edit" link on the canvas either, for the same reason as the
		 * admin bar, and one more: followed inside the iframe it opens the
		 * WordPress editor nested inside the builder.
		 *
		 * @param string $link The edit link markup.
		 * @return string
		 */
		public static function hide_edit_link( $link ) {
			return self::is_canvas() ? '' : $link;
		}
	}
}
