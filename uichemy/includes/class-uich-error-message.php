<?php
/**
 * Plain-text PHP error message for UiChemy's own requests.
 *
 * When PHP stops with a fatal error, WordPress answers with its "critical error"
 * message, which is HTML ("<p>There has been a critical error…</p><p><a …>").
 * For a REST or AJAX request that HTML goes into the JSON reply, and the UiChemy
 * screens that show the reply print the tags as text. For UiChemy's own REST
 * routes and AJAX actions the message is replaced with plain text that says
 * what to do. Every other request keeps WordPress's message.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uich_is_own_request' ) ) {
	/**
	 * Is this a REST request to a UiChemy route, or a UiChemy AJAX action?
	 *
	 * Reads the raw request because it runs from the fatal-error handler, after
	 * WordPress may have stopped part-way through routing.
	 *
	 * @return bool
	 */
	function uich_is_own_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only routing check; nothing is changed.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( preg_match( '#^/?uichemy#i', $route ) || preg_match( '#/' . preg_quote( rest_get_url_prefix(), '#' ) . '/uichemy#i', $uri ) ) {
			return true;
		}

		if ( wp_doing_ajax() ) {
			$action = '';
			if ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) {
				$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
			}
			return (bool) preg_match( '/^(uich|uichemy|posimyth_uich)/', $action );
		}
		// phpcs:enable

		return false;
	}
}

add_filter(
	'wp_php_error_message',
	static function ( $message ) {
		if ( ! uich_is_own_request() ) {
			return $message;
		}
		$text = 'WordPress hit a PHP error while doing this. Check the PHP error log (or turn on WP_DEBUG_LOG and look in wp-content/debug.log) for the cause, then try again.';
		return function_exists( '__' ) ? __( 'WordPress hit a PHP error while doing this. Check the PHP error log (or turn on WP_DEBUG_LOG and look in wp-content/debug.log) for the cause, then try again.', 'uichemy' ) : $text;
	}
);
