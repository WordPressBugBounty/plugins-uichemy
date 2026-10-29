<?php
/**
 * Composer Builder loader — the single entry point for the new UiChemy builder.
 *
 * Everything the builder owns lives under `composer-builder/`:
 *
 *   composer-builder/includes/           this loader, the editor screen, the
 *                                        canvas and the block-editor entry points
 *   composer-builder/includes/security/  WHO may do what (Uich_CB_Policy) and
 *                                        WHAT they may store (Uich_CB_Code_Policy)
 *   composer-builder/includes/storage/   THE CORE STORAGE MODEL — post types,
 *                                        the layout AST in postmeta, the
 *                                        post_content mirror, and version
 *                                        history on WordPress revisions
 *   composer-builder/includes/rest/      the REST surface: one registrar, one
 *                                        controller per resource
 *   composer-builder/assets/             shipped JS/CSS that needs no build step
 *   composer-builder/src/                React source (compiled, NOT shipped)
 *
 * Each layer asks only the one below it. Entry points (screen, REST, canvas)
 * ask security/ whether the user may act and hand documents to storage/;
 * storage/ has one write gateway that applies the code policy, so no entry
 * point — existing or added later — can store a document any other way.
 * storage/ knows about posts and documents, not about what a template is, so
 * pages, posts, custom post types and Theme Builder templates all persist and
 * version identically, and a new content kind is a post type in
 * Uich_CB_Post_Types rather than a change to how anything is stored.
 *
 * The whole subsystem reaches the filesystem and the browser through the
 * `UICH_CB_*` constants defined here, so the folder can be relocated later by
 * changing this file alone — the same trick Uich_Composer_Loader uses for the
 * merged `uichemy-composer/` runtime.
 *
 * BUILD OUTPUT lives in `composer-builder/build/`, next to the source it came
 * from. new-dashboard/webpack.config.js exports TWO configs — the dashboard's,
 * and one for this builder — because the editor vendors its own copy of the
 * composer core and resolves `@` to it, while the dashboard resolves the same
 * `@` to its own copy. One alias cannot mean two things, so they are two
 * compilations, and two compilations cannot share an output directory because
 * wp-scripts' CleanWebpackPlugin empties it.
 *
 * Loaded from uichemy.php at plugin-file load time (not from
 * Uich_Uichemy::uich_load_dependencies(), which already runs inside
 * `plugins_loaded`) so the classes below can register their own `init` and
 * `plugins_loaded` callbacks normally.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UICH_CB_PATH' ) ) {
	define( 'UICH_CB_PATH', UICH_PATH . 'composer-builder/' );
}
if ( ! defined( 'UICH_CB_URL' ) ) {
	define( 'UICH_CB_URL', UICH_URL . 'composer-builder/' );
}
if ( ! defined( 'UICH_CB_BUILD_PATH' ) ) {
	define( 'UICH_CB_BUILD_PATH', UICH_CB_PATH . 'build/' );
}
if ( ! defined( 'UICH_CB_BUILD_URL' ) ) {
	define( 'UICH_CB_BUILD_URL', UICH_CB_URL . 'build/' );
}

/**
 * Webpack entry name for the editor bundle (editor.js / editor.css /
 * editor.asset.php inside UICH_CB_BUILD_PATH).
 */
if ( ! defined( 'UICH_CB_BUNDLE' ) ) {
	define( 'UICH_CB_BUNDLE', 'editor' );
}

/**
 * The DOM id the editor mounts into.
 *
 * `uipb-editor` is the id the VENDORED editor looks for (src/editor/index.jsx),
 * and the editor tree is not hand-edited — so this side matches it rather than
 * the other way round. Same reason the boot payload below is `UIPB_BOOT`.
 */
if ( ! defined( 'UICH_CB_MOUNT' ) ) {
	define( 'UICH_CB_MOUNT', 'uipb-editor' );
}

/**
 * `?action=` value that opens the builder.
 *
 * wp-admin/admin.php fires `admin_action_{$action}` for any request carrying an
 * `action` parameter, and wp-admin/post.php includes admin.php — so
 * `post.php?post=123&action=uich_composer_builder` hands control to
 * Uich_CB_Editor before post.php renders the block editor. This is the same
 * core hook established page builders use for their own editor screens.
 */
if ( ! defined( 'UICH_CB_ACTION' ) ) {
	define( 'UICH_CB_ACTION', 'uich_composer_builder' );
}

if ( ! function_exists( 'uich_cb_builder_enabled' ) ) {
	/**
	 * Whether people may open the UiChemy Builder (Settings > Page builders).
	 *
	 * Off hides every "Edit with UiChemy" entry point and closes the editor
	 * route. Only editing stops: pages and templates already built keep
	 * rendering exactly as they are, because the renderer does not ask this.
	 * Missing (never saved) means on.
	 *
	 * @return bool
	 */
	function uich_cb_builder_enabled() {
		$opts = get_option( 'uichemy_settings', array() );
		$on   = ! is_array( $opts ) || ! array_key_exists( 'enable_builder', $opts ) ? true : ! empty( $opts['enable_builder'] );

		/**
		 * Filter whether the UiChemy Builder can be opened.
		 *
		 * @param bool $on Whether it is enabled.
		 */
		return (bool) apply_filters( 'uich_cb/builder/enabled', $on );
	}
}

if ( ! class_exists( 'Uich_CB_Loader' ) ) {

	/**
	 * Requires and boots every Composer Builder module.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Loader {

		/**
		 * Singleton instance.
		 *
		 * @var Uich_CB_Loader
		 */
		private static $instance;

		/**
		 * Initiator.
		 *
		 * @return Uich_CB_Loader
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Require the modules and hand each one its hooks.
		 */
		private function __construct() {
			// security/ — who may do what (Uich_CB_Policy) and what a user may
			// store (Uich_CB_Code_Policy). First, because every module below asks
			// it: storage's single write gateway, the REST routes, the editor
			// screen and the canvas.
			require_once UICH_CB_PATH . 'includes/security/class-uich-cb-policy.php';
			require_once UICH_CB_PATH . 'includes/security/class-uich-cb-code-policy.php';

			// storage/ — the core model. Post-type agnostic: it stores a layout
			// against a post ID and asks nothing about what kind of post it is.
			// Which template renders where is the Theme Builder's job
			// (UiChemy_Template_*); a template's layout is stored here like a
			// page's.
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-post-types.php';
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-wpcontent.php';
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-content.php';
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-revisions.php';
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-render.php';
			require_once UICH_CB_PATH . 'includes/storage/class-uich-cb-code-files.php';

			// rest/ — one registrar and one controller per resource. A new
			// resource is a class implementing Uich_CB_Rest_Controller, listed
			// in Uich_CB_Rest::controllers().
			require_once UICH_CB_PATH . 'includes/rest/interface-uich-cb-rest-controller.php';
			require_once UICH_CB_PATH . 'includes/rest/class-uich-cb-rest.php';
			require_once UICH_CB_PATH . 'includes/rest/class-uich-cb-rest-page.php';
			require_once UICH_CB_PATH . 'includes/rest/class-uich-cb-rest-history.php';
			require_once UICH_CB_PATH . 'includes/rest/class-uich-cb-rest-code-files.php';
			require_once UICH_CB_PATH . 'includes/rest/class-uich-cb-rest-cms.php';

			// library/ — the starter sections the Add Section dialog offers,
			// fed to the library endpoint through its filter.
			require_once UICH_CB_PATH . 'includes/library/class-uich-cb-library.php';

			require_once UICH_CB_PATH . 'includes/class-uich-cb-enqueue.php';
			require_once UICH_CB_PATH . 'includes/class-uich-cb-editor.php';
			require_once UICH_CB_PATH . 'includes/class-uich-cb-gutenberg.php';
			require_once UICH_CB_PATH . 'includes/class-uich-cb-canvas.php';
			require_once UICH_CB_PATH . 'includes/class-uich-cb-page-settings.php';
			require_once UICH_CB_PATH . 'includes/class-uich-cb-visibility.php';

			// Post types FIRST (they register on `init` at 5): the meta
			// registration below runs at 20 and asks which types exist.
			Uich_CB_Post_Types::boot();
			Uich_CB_Content::boot();
			// Revisions only adds filters, but it must be in place before any
			// save can call wp_save_post_revision() — a revision written before
			// `wp_post_revision_meta_keys` is filtered carries no builder state.
			Uich_CB_Revisions::boot();
			Uich_CB_Editor::boot();
			Uich_CB_Gutenberg::boot();
			// Render before Canvas: the canvas view is the front end, and the
			// front end is what Uich_CB_Render hooks.
			Uich_CB_Render::boot();
			Uich_CB_Canvas::boot();
			Uich_CB_Page_Settings::boot();
			// Per-post visibility rules (set in the CMS), applied to front-end queries.
			Uich_CB_Visibility::boot();
			Uich_CB_Rest::boot();
			Uich_CB_Library::boot();

			/**
			 * Fires once the Composer Builder has registered its hooks.
			 *
			 * The extension point for anything that wants to add to the builder
			 * without editing this file.
			 *
			 * @since 5.2.1
			 */
			do_action( 'uich_cb/loaded' );
		}
	}

	Uich_CB_Loader::get_instance();
}
