<?php
/**
 * The page-builder driver registry.
 *
 * One place that knows which builders exist. Adding a fourth builder means
 * writing one driver class and registering it through the `uichemy/builders`
 * filter — no change to the abilities, the router, the shared op layer or the
 * Composer manager.
 *
 * Drivers live in the FREE plugin on purpose, so a free-only site gets all
 * three builders; Pro consumes this registry and ships no driver of its own.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Builder_Registry' ) ) {

	/**
	 * Resolves builder slugs to driver instances.
	 */
	class UiChemy_Builder_Registry {

		/**
		 * Detection order for owns_post().
		 *
		 * Elementor is probed FIRST and Gutenberg LAST, and the order is load
		 * bearing: a Bricks-themed site can still hold Elementor pages, and
		 * every post has a post_content, so a Gutenberg-first probe would claim
		 * everything.
		 */
		const DETECT_ORDER = array( 'elementor', 'bricks', 'gutenberg' );

		/**
		 * Instantiated drivers, keyed by slug.
		 *
		 * @var array<string,UiChemy_Builder_Driver>|null
		 */
		private static $drivers = null;

		/**
		 * Build the driver map once per request.
		 *
		 * @return array<string,UiChemy_Builder_Driver>
		 */
		public static function all() {
			if ( is_array( self::$drivers ) ) {
				return self::$drivers;
			}

			$drivers = array();
			foreach ( array(
				'elementor' => 'UiChemy_Elementor_Driver',
				'bricks'    => 'UiChemy_Bricks_Driver',
				'gutenberg' => 'UiChemy_Gutenberg_Driver',
			) as $slug => $class ) {
				if ( class_exists( $class ) ) {
					$drivers[ $slug ] = new $class();
				}
			}

			/**
			 * Filter the registered page-builder drivers.
			 *
			 * Every value must implement UiChemy_Builder_Driver; anything else
			 * is dropped rather than allowed to fatal deep inside a write.
			 *
			 * @param array<string,UiChemy_Builder_Driver> $drivers Keyed by slug.
			 */
			$drivers = apply_filters( 'uichemy/builders', $drivers );

			self::$drivers = array();
			if ( is_array( $drivers ) ) {
				foreach ( $drivers as $slug => $driver ) {
					if ( $driver instanceof UiChemy_Builder_Driver ) {
						self::$drivers[ sanitize_key( (string) $slug ) ] = $driver;
					}
				}
			}

			return self::$drivers;
		}

		/**
		 * One driver by slug, or null.
		 *
		 * @param string $slug Builder slug.
		 * @return UiChemy_Builder_Driver|null
		 */
		public static function get( $slug ) {
			$all  = self::all();
			$slug = sanitize_key( (string) $slug );

			return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
		}

		/**
		 * Slugs of every registered driver.
		 *
		 * @return string[]
		 */
		public static function slugs() {
			return array_keys( self::all() );
		}

		/**
		 * Drivers that can actually be driven here AND are not switched off in
		 * Settings. Both halves matter: `is_available()` is about the builder
		 * being installed, `uichemy_composer_enabled()` about the site owner
		 * having asked us not to touch it.
		 *
		 * @return array<string,UiChemy_Builder_Driver>
		 */
		public static function available() {
			$out = array();
			foreach ( self::all() as $slug => $driver ) {
				if ( ! $driver->is_available() ) {
					continue;
				}
				if ( function_exists( 'uichemy_composer_enabled' ) && ! uichemy_composer_enabled( $slug ) ) {
					continue;
				}
				$out[ $slug ] = $driver;
			}

			return $out;
		}

		/**
		 * Whether a builder is registered, installed and enabled.
		 *
		 * @param string $slug Builder slug.
		 * @return bool
		 */
		public static function is_available( $slug ) {
			$available = self::available();
			return isset( $available[ sanitize_key( (string) $slug ) ] );
		}

		/**
		 * Which builder owns an existing post's content, or '' when nothing
		 * claims it (an empty post that any builder may adopt).
		 *
		 * Probed in DETECT_ORDER, and only across registered drivers — never a
		 * hardcoded meta check, so a fourth builder participates for free.
		 *
		 * @param int $post_id Post id.
		 * @return string Builder slug, or ''.
		 */
		public static function detect_post_builder( $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return '';
			}

			$all = self::all();
			foreach ( self::DETECT_ORDER as $slug ) {
				if ( isset( $all[ $slug ] ) && $all[ $slug ]->owns_post( $post_id ) ) {
					return $slug;
				}
			}
			foreach ( $all as $slug => $driver ) {
				if ( ! in_array( $slug, self::DETECT_ORDER, true ) && $driver->owns_post( $post_id ) ) {
					return $slug;
				}
			}

			return '';
		}

		/**
		 * Test seam — forget the memoized drivers.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$drivers = null;
		}
	}
}
