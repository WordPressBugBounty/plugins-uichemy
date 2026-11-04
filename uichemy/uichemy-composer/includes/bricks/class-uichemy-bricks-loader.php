<?php
/**
 * UiChemy — Bricks Builder integration bootstrap.
 *
 * UNVERIFIED INTEGRATION — no Bricks core install was available to test
 * against; see includes/bricks/class-uichemy-bricks-composer.php's header
 * comment for exactly what's verified vs. best-effort here.
 *
 * Safe to require unconditionally: this file never references any
 * \Bricks\* symbol at top level, only inside callbacks gated by
 * class_exists() and hooked late enough (`init`, priority 11) for Bricks —
 * a THEME, which finishes loading after plugins do — to actually be ready.
 * Mirrors the verified pattern from a real third-party Bricks addon.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the UiChemy Composer element with Bricks. No-ops when Bricks
 * isn't the active theme. Priority 11 matches the reference addon's own
 * registration timing — after Bricks' own core elements register on `init`.
 */
add_action(
	'init',
	function () {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}
		// One switch per builder (Settings). Not registering is what actually keeps
		// the element out of Bricks' panel.
		if ( ! uichemy_composer_enabled( 'bricks' ) ) {
			return;
		}
		\Bricks\Elements::register_element( UICHEMY_PATH . 'includes/bricks/class-uichemy-bricks-composer.php' );
	},
	11
);

// Lets the Bricks builder open UiChemy header/footer templates built with Bricks (not in Bricks > Settings post types).
add_filter(
	'bricks/builder/supported_post_types',
	function ( $post_types, $current_post_type ) {
		if ( ! class_exists( 'UiChemy_Template_CPT' ) || UiChemy_Template_CPT::POST_TYPE !== $current_post_type ) {
			return $post_types;
		}
		$post_id = (int) get_the_ID();
		if ( $post_id && 'bricks' === UiChemy_Template_CPT::editor_for( $post_id ) ) {
			$post_types   = is_array( $post_types ) ? $post_types : array();
			$post_types[] = $current_post_type;
		}
		return $post_types;
	},
	10,
	2
);

// Panel-asset enqueueing (bricks_is_builder() gated) lives in
// UiChemy_Composer_Enqueue::enqueue_bricks_editor_script(), hooked on
// `get_footer` alongside the Elementor/Gutenberg equivalents for consistency.
