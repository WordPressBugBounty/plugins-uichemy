<?php
/**
 * The few places the interpreter hands a call to PHP itself.
 *
 * This file declares strict_types on purpose, and nothing else lives here:
 * strict typing applies to calls made FROM a file, so a script that calls
 * strlen(5) gets the same TypeError it always did, while the rest of the
 * plugin keeps its own typing rules.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Native' ) ) {

	/**
	 * Strictly typed calls into PHP's own functions and exception classes.
	 */
	class UiChemy_Code_Mode_Native {

		/**
		 * @param string $name Function name (already checked against the allowlist).
		 * @param array  $args Positional arguments.
		 * @return mixed
		 */
		public static function call( $name, array $args ) {
			return $name( ...$args );
		}

		/**
		 * Positional and named arguments (PHP 8.1 or later).
		 *
		 * @param string $name  Function name.
		 * @param array  $args  Positional arguments.
		 * @param array  $named Named arguments.
		 * @return mixed
		 */
		public static function call_named( $name, array $args, array $named ) {
			return $name( ...$args, ...$named );
		}

		/**
		 * A function with by-reference parameters (sort, preg_match ...): the
		 * references inside $args are kept. Weak typing, as call_user_func_array is.
		 *
		 * @param string $name Function name.
		 * @param array  $args Arguments, some of them references.
		 * @return mixed
		 */
		public static function call_ref( $name, array $args ) {
			return call_user_func_array( $name, $args );
		}

		/**
		 * @param string $class Exception class, already checked against the allowlist.
		 * @param array  $args  Constructor arguments.
		 * @return \Throwable
		 */
		public static function make( $class, array $args ) {
			return new $class( ...$args );
		}
	}
}
