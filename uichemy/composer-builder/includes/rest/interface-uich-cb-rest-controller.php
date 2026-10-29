<?php
/**
 * Uich_CB_Rest_Controller — the contract every builder REST controller keeps.
 *
 * A controller owns one resource's routes and registers them in register().
 * Uich_CB_Rest calls it; nothing else does. Adding a resource is a class that
 * implements this and one line in Uich_CB_Rest::controllers() (or the
 * `uich_cb/rest_controllers` filter) — no existing controller changes.
 *
 * Permission callbacks come from Uich_CB_Policy, never from the controller, so
 * a new route cannot quietly carry a looser rule than the ones beside it.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Uich_CB_Rest_Controller' ) ) {

	/**
	 * A group of builder REST routes.
	 *
	 * @since 5.2.1
	 */
	interface Uich_CB_Rest_Controller {

		/**
		 * Register this controller's routes. Called on `rest_api_init`.
		 *
		 * @return void
		 */
		public static function register();
	}
}
