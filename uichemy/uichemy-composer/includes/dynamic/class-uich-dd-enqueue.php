<?php
/**
 * Uich_DD_Enqueue — loads the visual dynamic-data builder into the Elementor editor and
 * injects "+ Data / + Loop / + Condition" buttons into the Composer/Atom popup header.
 *
 * The builders (UichDD.ui) produce Twig and hand it to UichDD.bridge.insert(), which drops it
 * into the focused code editor.
 *
 * The front end gets `uich-dd-public.css` and ONLY when a runtime that emits its
 * classes is on the page. The picker's own stylesheet is editor furniture, and
 * loading it everywhere put ~7 KiB on pages with no form and no loop.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_DD_Enqueue' ) ) {
	class Uich_DD_Enqueue {

		public static function init() {
			add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'editor_assets' ), 999 );
			// Registered, not enqueued. A public page gets the stylesheet only when
			// something on it renders one of its classes — see enqueue_public().
			// Priority 100 so that when it IS enqueued it still prints after the
			// active theme's reset, which is what lets its rules win a tie.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_public' ), 100 );
		}

		/** Version a file by its mtime so edits always bust the browser cache (dev-safe). */
		private static function ver( $rel ) {
			$path  = UICHEMY_PATH . $rel;
			$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
			return UICHEMY_VERSION . '.' . $mtime;
		}

		/**
		 * Register the public stylesheet. Nothing is queued here.
		 *
		 * @return void
		 */
		public static function register_public() {
			wp_register_style(
				'uich-dd-public',
				UICHEMY_URL . 'assets/css/uich-dd-public.css',
				array(),
				self::ver( 'assets/css/uich-dd-public.css' )
			);
		}

		/**
		 * Queue the public stylesheet, for a page that actually renders one of its
		 * classes: a managed form's message, or the loop runtime's skeleton,
		 * pagination and load-more.
		 *
		 * Callers are the two runtimes themselves rather than a page-wide guess,
		 * because only they know whether their markup reached the output. Safe to
		 * call repeatedly — wp_enqueue_style de-dupes by handle — and safe to call
		 * during rendering, since styles can still be queued while the footer has
		 * not printed.
		 *
		 * @return void
		 */
		public static function enqueue_public() {
			if ( ! defined( 'UICHEMY_URL' ) ) {
				return;
			}
			if ( ! wp_style_is( 'uich-dd-public', 'registered' ) ) {
				self::register_public();
			}
			wp_enqueue_style( 'uich-dd-public' );
		}

		/**
		 * Enqueue the visual dynamic-data picker library (UichDD.ui) + the REST
		 * endpoints it needs. Shared by the Elementor editor and the front-end
		 * composer ("Pick UiChemy"): both render the inspector's "Insert dynamic
		 * value" connector and the Loop/Condition/Form construct tabs, which call
		 * window.UichDD.ui.openValue(). Without it that button silently no-ops.
		 * Safe to call more than once — wp_enqueue_script de-dupes by handle.
		 *
		 * @return void
		 */
		public static function enqueue_picker() {
			// The picker previews forms and loops too, so it needs both halves.
			self::enqueue_public();
			wp_enqueue_style( 'uich-dd', UICHEMY_URL . 'assets/css/uich-dd.css', array( 'uich-dd-public' ), self::ver( 'assets/css/uich-dd.css' ) );

			wp_enqueue_script( 'uich-dd-schema', UICHEMY_URL . 'assets/js/uich-dd-schema.js', array(), self::ver( 'assets/js/uich-dd-schema.js' ), true );
			wp_enqueue_script( 'uich-dd-compile', UICHEMY_URL . 'assets/js/uich-dd-compile.js', array(), self::ver( 'assets/js/uich-dd-compile.js' ), true );
			wp_enqueue_script( 'uich-dd-picker', UICHEMY_URL . 'assets/js/uich-uichemy-dd-picker.js', array( 'uich-dd-schema', 'uich-dd-compile' ), self::ver( 'assets/js/uich-uichemy-dd-picker.js' ), true );

			// Endpoint the editor uses to server-render Twig for an accurate live preview.
			wp_localize_script(
				'uich-dd-picker',
				'uichAtomPreview',
				array(
					'url'   => esc_url_raw( rest_url( 'uichemy/v1/atom-preview' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			);

			// Endpoint the picker uses to load live ACF/Woo/CPT field introspection.
			// `entitiesUrl` powers the Loop tab's visual post/term pickers (Only-these-IDs / Category).
			// `integrations` reports which optional plugins are active so the pickers can
			// hide sources that depend on them (e.g. the WooCommerce Products source /
			// product loop when Woo is inactive). Mirrors the gating in Uich_Dynamic_Tags.
			wp_localize_script(
				'uich-dd-picker',
				'uichAtomFields',
				array(
					'url'          => esc_url_raw( rest_url( 'uichemy/v1/atom-fields' ) ),
					'entitiesUrl'  => esc_url_raw( rest_url( 'uichemy/v1/atom-entities' ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					// Free ships five dynamic tags; the picker badges/locks the rest.
					// Read by UichDD.isPro() / fieldIsPro() in uich-dd-schema.js. The real
					// enforcement is uichemy_dynamic_field_allowed() at render time.
					'isPro'        => uichemy_is_pro(),
					'proUrl'       => uichemy_upgrade_url( 'dynamic-tags' ),
					'integrations' => array(
						'woo' => class_exists( 'WooCommerce' ),
						'acf' => function_exists( 'get_field' ) && function_exists( 'acf_get_field_groups' ),
						'jet' => function_exists( 'jet_engine' ),
					),
				)
			);

			// Endpoint the Form tab uses to save/load sensitive config (email/webhook) server-side.
			wp_localize_script(
				'uich-dd-picker',
				'uichAtomFormCfg',
				array(
					'url'   => esc_url_raw( rest_url( 'uichemy/v1/atom-form-config' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		public static function editor_assets() {
			self::enqueue_picker();

			// NOTE: The "+ Dynamic" dropdown (the Composer panel header menu with
			// Dynamic value / Loop start / Loop end / Condition start / Condition end
			// / Form start / Form end) was REMOVED per request. It is no longer
			// injected into the panel on any builder (Gutenberg / Bricks / Elementor /
			// front-end). The per-field "insert dynamic value" pickers beside
			// individual inputs (the database-icon buttons, driven by the same
			// window.UichDD API) are a separate mechanism and remain available.
			//
			// To restore the dropdown, re-add a `wp_add_inline_script( 'uich-dd-picker',
			// $boot )` here whose $boot IIFE builds a `.uich-dd-dropdown` from
			// window.UichDD.MENU and appends it to `.uich-composer-host .panel-tabs
			// .tab-tools`, routing each item through window.UichDD.open( item.mode ).
		}
	}
}
