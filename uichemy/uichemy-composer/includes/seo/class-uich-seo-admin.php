<?php
/**
 * Admin surfaces (D16): the dashboard SEO screen's config (reached from the SEO tab, no admin menu item) and
 * the page panel in every editor — Gutenberg sidebar, classic metabox and an
 * Elementor modal. All from host APIs; no Composer file is touched.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Admin' ) ) {

	/**
	 * Admin wiring.
	 */
	class Uich_SEO_Admin {

		/** Dashboard bundle handle (Uich_ND_Enqueue). */
		const DASH_HANDLE = 'uich-nd-script';

		/** Editor panel bundle handle. */
		const EDITOR_HANDLE = 'uichemy-seo-editor';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'dashboard_config' ), 20 );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor' ) );
			add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'classic_editor' ) );
			add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'elementor_editor' ) );
		}

		/**
		 * REST config for the dashboard's SEO screen.
		 *
		 * @param string $hook Hook suffix.
		 * @return void
		 */
		public static function dashboard_config( $hook ) {
			if ( 'toplevel_page_uichemy' !== $hook || ! wp_script_is( self::DASH_HANDLE, 'registered' ) ) {
				return;
			}
			wp_enqueue_media();
			wp_localize_script( self::DASH_HANDLE, 'uichemySeo', self::config( 0, 'dashboard' ) );
		}

		/**
		 * Shared config object.
		 *
		 * @param int    $post_id Post id (0 on the dashboard).
		 * @param string $context dashboard | gutenberg | metabox | elementor.
		 * @return array
		 */
		private static function config( $post_id, $context ) {
			return array(
				'root'         => esc_url_raw( rest_url( Uich_SEO_Rest::NS . '/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'postId'       => (int) $post_id,
				'context'      => $context,
				'dashboardUrl' => admin_url( 'admin.php?page=uichemy#/seo' ),
				'canSettings'  => current_user_can( 'manage_options' ),
			);
		}

		/**
		 * Enqueue the editor panel bundle.
		 *
		 * @param int    $post_id Post id.
		 * @param string $context Context.
		 * @return bool
		 */
		private static function enqueue_editor( $post_id, $context ) {
			if ( ! defined( 'UICHEMY_BUILD_PATH' ) || ! defined( 'UICHEMY_BUILD_URL' ) ) {
				return false;
			}
			$asset_file = UICHEMY_BUILD_PATH . 'seo-editor.asset.php';
			$js_file    = UICHEMY_BUILD_PATH . 'seo-editor.js';
			if ( ! file_exists( $asset_file ) || ! file_exists( $js_file ) ) {
				return false;
			}
			$asset = include $asset_file;
			$deps  = isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array();
			$ver   = isset( $asset['version'] ) ? $asset['version'] : filemtime( $js_file );

			// The block-editor-only packages are not available in the classic or Elementor screens.
			if ( 'gutenberg' === $context ) {
				// The panel reads wp.plugins / wp.editor at runtime; make sure they load first.
				$deps = array_values( array_unique( array_merge( $deps, array( 'wp-plugins', 'wp-editor', 'wp-edit-post' ) ) ) );
			} else {
				$deps = array_values( array_diff( $deps, array( 'wp-edit-post', 'wp-editor', 'wp-plugins', 'wp-block-editor', 'wp-blocks' ) ) );
			}

			wp_enqueue_script( self::EDITOR_HANDLE, UICHEMY_BUILD_URL . 'seo-editor.js', $deps, $ver, true );
			if ( file_exists( UICHEMY_BUILD_PATH . 'seo-editor.css' ) ) {
				wp_enqueue_style( self::EDITOR_HANDLE, UICHEMY_BUILD_URL . 'seo-editor.css', array(), $ver );
			}
			wp_localize_script( self::EDITOR_HANDLE, 'uichemySeo', self::config( $post_id, $context ) );
			wp_set_script_translations( self::EDITOR_HANDLE, 'uichemy' );
			return true;
		}

		/**
		 * Is the panel shown for this post (PRD §7 "SEO fields", §13.6)?
		 *
		 * @param WP_Post|null $post Post.
		 * @return bool
		 */
		private static function panel_for( $post ) {
			if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				return false;
			}
			if ( class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post->post_type ) {
				return false;
			}
			$obj = get_post_type_object( $post->post_type );
			if ( ! $obj || empty( $obj->public ) ) {
				return false;
			}
			$row = Uich_SEO_Settings::type_row( $post->post_type );
			return (bool) $row['seo'];
		}

		/**
		 * Gutenberg: PluginSidebar.
		 *
		 * @return void
		 */
		public static function block_editor() {
			$post = get_post();
			if ( ! self::panel_for( $post ) ) {
				return;
			}
			self::enqueue_editor( $post->ID, 'gutenberg' );
		}

		/**
		 * Classic editor / any post type without the block editor: metabox.
		 *
		 * @param string  $post_type Post type.
		 * @param WP_Post $post      Post.
		 * @return void
		 */
		public static function metabox( $post_type, $post ) {
			if ( ! self::panel_for( $post ) || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
				return;
			}
			add_meta_box(
				'uichemy-seo',
				__( 'SEO', 'uichemy' ),
				function ( $p ) {
					echo '<div id="uichemy-seo-metabox-root" class="uc-content" data-post="' . (int) $p->ID . '"></div>';
				},
				$post_type,
				'normal',
				'high'
			);
		}

		/**
		 * Classic editor assets.
		 *
		 * @param string $hook Hook suffix.
		 * @return void
		 */
		public static function classic_editor( $hook ) {
			if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
				return;
			}
			$post = get_post();
			if ( ! self::panel_for( $post ) || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
				return;
			}
			wp_enqueue_media();
			self::enqueue_editor( $post->ID, 'metabox' );
		}

		/**
		 * Bricks builder (runs on the front end, `?bricks=run`): the same SEO
		 * button and side panel as Elementor. Best effort: the Bricks integration
		 * is marked unverified in Composer.
		 *
		 * @return void
		 */
		public static function bricks_editor() {
			if ( ! function_exists( 'bricks_is_builder_main' ) || ! bricks_is_builder_main() ) {
				return;
			}
			$post_id = (int) get_the_ID();
			if ( ! $post_id && isset( $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
				$post_id = absint( $_GET['p'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			}
			if ( ! self::panel_for( get_post( $post_id ) ) ) {
				return;
			}
			wp_enqueue_media();
			self::enqueue_editor( $post_id, 'bricks' );
		}

		/**
		 * Elementor editor: an SEO button that opens the panel in a modal — the
		 * same injection pattern SureRank uses; Elementor's own panel is untouched.
		 *
		 * @return void
		 */
		public static function elementor_editor() {
			$post_id = 0;
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) ) {
				$post_id = (int) \Elementor\Plugin::$instance->editor->get_post_id();
			}
			if ( ! $post_id && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
				$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			}
			if ( ! self::panel_for( get_post( $post_id ) ) ) {
				return;
			}
			wp_enqueue_media();
			self::enqueue_editor( $post_id, 'elementor' );
		}
	}
}
