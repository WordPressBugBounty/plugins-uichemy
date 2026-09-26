<?php
/**
 * UiChemy_RunWith — server side of the "Run with" dependency setting.
 *
 * Two jobs, both small:
 *
 *   1. Ship the runtime, and only on a page that has a binding.
 *   2. Build the inert carrier tag for a dependency bound to an element.
 *
 * Everything else — when the element comes into view, what is built then —
 * lives in assets/js/uichemy-runwith.js. The binding is stored on the
 * dependency itself as `runWith`, so there is no server-side state to resolve
 * and nothing here needs to know which builder produced the markup.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_RunWith' ) ) {

	class UiChemy_RunWith {

		const HANDLE = 'uichemy-runwith';

		/** Marker a carrier carries when a dependency is bound to an element. */
		const ATTR = 'data-uich-run-with';

		/**
		 * Does this rendered output need the runtime?
		 *
		 * @param string $html Rendered markup.
		 * @return bool
		 */
		public static function output_needs_runtime( $html ) {
			return false !== strpos( (string) $html, self::ATTR );
		}

		/**
		 * Enqueue the runtime. Idempotent, and safe to call during rendering: the
		 * handle is registered in the footer, which has not printed yet when a
		 * widget renders inside the_content.
		 *
		 * @return void
		 */
		public static function enqueue_runtime() {
			if ( ! defined( 'UICHEMY_URL' ) || wp_script_is( self::HANDLE, 'enqueued' ) ) {
				return;
			}

			$rel  = 'assets/js/uichemy-runwith.js';
			$path = defined( 'UICHEMY_PATH' ) ? UICHEMY_PATH . $rel : '';
			// mtime in the version so an edit is never served from a stale cache
			// during development; the plugin version alone changes too rarely.
			$ver = ( $path && file_exists( $path ) )
				? ( defined( 'UICHEMY_VERSION' ) ? UICHEMY_VERSION . '.' . filemtime( $path ) : filemtime( $path ) )
				: ( defined( 'UICHEMY_VERSION' ) ? UICHEMY_VERSION : false );

			wp_enqueue_script( self::HANDLE, UICHEMY_URL . $rel, array(), $ver, true );
		}

		/**
		 * The inert carrier for a dependency bound to an element.
		 *
		 * A real <link> or <script src> is fetched by the browser the moment it is
		 * parsed, whatever happens afterwards — so the only way not to pay for a
		 * bound asset is not to emit it. `type="uich/deferred"` is not a script
		 * type any engine executes, so the carrier costs a parse and nothing else,
		 * and the runtime builds the real tag when the element comes into view.
		 *
		 * One carrier shape for both styles and scripts, so the runtime has one
		 * thing to look for and one place to decide what to build.
		 *
		 * @param string $run_with Selector of the element this asset waits for.
		 * @param string $kind     'script' or 'style'.
		 * @param string $url      Already-escaped asset URL.
		 * @param array  $attrs    Attribute keywords (defer, async, module, all, print).
		 * @return string
		 */
		public static function carrier_tag( $run_with, $kind, $url, $attrs = array() ) {
			return '<script type="uich/deferred"'
				. ' ' . self::ATTR . '="' . esc_attr( $run_with ) . '"'
				. ' data-uich-kind="' . ( 'style' === $kind ? 'style' : 'script' ) . '"'
				. ' data-uich-src="' . $url . '"'
				. ' data-uich-attrs="' . esc_attr( implode( ' ', array_map( 'strval', (array) $attrs ) ) ) . '"'
				. '></script>';
		}
	}
}
