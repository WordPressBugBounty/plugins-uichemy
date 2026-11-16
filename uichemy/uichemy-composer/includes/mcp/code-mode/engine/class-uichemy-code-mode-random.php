<?php
/**
 * Code Mode randomness: rand(), mt_rand(), shuffle() and friends, repeatable.
 *
 * A dry run must write what the real run will write. With PHP's own generator
 * it cannot: every run draws different numbers. So these functions draw from
 * a generator of the run's own, seeded with 128 random bits. The answer
 * carries the seed (only when the script drew a number), and passing it back
 * as execute's `seed` gives the real run the same numbers, in the same order,
 * as the dry run.
 *
 * The stream is HMAC-SHA256 over a counter, keyed with the seed: unpredictable
 * to anyone without the seed. The seed is in the answer, though, so anyone
 * who can read the answer can recompute every number. That is fine for these
 * functions (PHP's own mt_rand() is predictable too), and it is why
 * random_int() and random_bytes() are NOT routed here: they stay PHP's own
 * CSPRNG, unseeded, so a token or password a script makes with them is secret.
 *
 * It never touches PHP's global generator: mt_srand() and srand() reseed this
 * generator only, so a script cannot change what WordPress or another plugin
 * draws from mt_rand() in the same request.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Random' ) ) {

	/**
	 * A run's own seeded generator and the functions that draw from it.
	 */
	class UiChemy_Code_Mode_Random {

		/** What getrandmax() and mt_getrandmax() answer, as in PHP. */
		const RAND_MAX = 2147483647;

		/** @var string 16 bytes. */
		private $key;

		/** @var string The seed as given or made, 32 hex characters. */
		private $seed;

		/** @var int Blocks drawn so far. */
		private $counter = 0;

		/** @var string Unused bytes of the last block. */
		private $buffer = '';

		/** @var bool Has the script drawn anything? */
		private $used = false;

		/**
		 * @param string|null $seed 32 hex characters from an earlier answer, or null for a fresh one.
		 */
		public function __construct( $seed = null ) {
			$this->reseed( self::valid_seed( $seed ) ? strtolower( $seed ) : bin2hex( random_bytes( 16 ) ) );
		}

		/**
		 * Is this a seed execute accepts?
		 *
		 * @param mixed $seed Value.
		 * @return bool
		 */
		public static function valid_seed( $seed ) {
			return is_string( $seed ) && 1 === preg_match( '/^[0-9a-fA-F]{32}$/', $seed );
		}

		/**
		 * The functions carried out here.
		 *
		 * @return array<int,string>
		 */
		public static function functions() {
			return array( 'rand', 'mt_rand', 'mt_srand', 'srand', 'shuffle', 'str_shuffle', 'array_rand', 'uniqid' );
		}

		/**
		 * The seed to send back, or null when the script drew nothing.
		 *
		 * @return string|null
		 */
		public function seed_used() {
			return $this->used ? $this->seed : null;
		}

		/**
		 * Carry out one of functions().
		 *
		 * @param string $name Function name.
		 * @param array  $args Arguments by position (a reference at 0 for shuffle).
		 * @return mixed
		 */
		public function call( $name, array &$args ) {
			$count = count( $args );
			switch ( $name ) {
				case 'rand':
				case 'mt_rand':
					if ( 0 === $count ) {
						$this->used = true;
						return $this->between( 0, self::RAND_MAX );
					}
					self::arity( $name, $count, 2, 2 );
					$min = self::int_arg( $name, $args[0], 1, 'min' );
					$max = self::int_arg( $name, $args[1], 2, 'max' );
					if ( $max < $min ) {
						// rand() takes the bounds either way round; mt_rand() does not.
						if ( 'mt_rand' === $name ) {
							throw self::value_error( 'mt_rand(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)' );
						}
						list( $min, $max ) = array( $max, $min );
					}
					$this->used = true;
					return $this->between( $min, $max );

				case 'mt_srand':
				case 'srand':
					self::arity( $name, $count, 0, 2 );
					$this->used = true;
					if ( 0 === $count || null === $args[0] ) {
						$this->reseed( bin2hex( random_bytes( 16 ) ) );
						return null;
					}
					$seed = self::int_arg( $name, $args[0], 1, 'seed' );
					if ( $count > 1 ) {
						self::int_arg( $name, $args[1], 2, 'mode' );
					}
					// The same seed gives the same numbers, as in PHP (not PHP's numbers).
					$this->reseed( substr( hash( 'sha256', 'uichemy-srand:' . $seed ), 0, 32 ), false );
					return null;

				case 'shuffle':
					self::arity( $name, $count, 1, 1 );
					if ( ! is_array( $args[0] ) ) {
						throw new \TypeError( sprintf( 'shuffle(): Argument #1 ($array) must be of type array, %s given', self::type_name( $args[0] ) ) );
					}
					$this->used = true;
					$args[0]    = $this->shuffled( array_values( $args[0] ) );
					return true;

				case 'str_shuffle':
					self::arity( $name, $count, 1, 1 );
					if ( ! is_string( $args[0] ) ) {
						throw new \TypeError( sprintf( 'str_shuffle(): Argument #1 ($string) must be of type string, %s given', self::type_name( $args[0] ) ) );
					}
					$this->used = true;
					return implode( '', $this->shuffled( str_split( $args[0] ) ) );

				case 'array_rand':
					self::arity( $name, $count, 1, 2 );
					if ( ! is_array( $args[0] ) ) {
						throw new \TypeError( sprintf( 'array_rand(): Argument #1 ($array) must be of type array, %s given', self::type_name( $args[0] ) ) );
					}
					$num = $count > 1 ? self::int_arg( $name, $args[1], 2, 'num' ) : 1;
					if ( ! $args[0] ) {
						throw self::value_error( 'array_rand(): Argument #1 ($array) cannot be empty' );
					}
					$keys = array_keys( $args[0] );
					if ( $num < 1 || $num > count( $keys ) ) {
						throw self::value_error( 'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)' );
					}
					$this->used = true;
					if ( 1 === $num ) {
						return $keys[ $this->between( 0, count( $keys ) - 1 ) ];
					}
					// As PHP: the chosen keys, in the array's own order.
					$chosen = array_slice( $this->shuffled( range( 0, count( $keys ) - 1 ) ), 0, $num );
					sort( $chosen );
					$out = array();
					foreach ( $chosen as $index ) {
						$out[] = $keys[ $index ];
					}
					return $out;

				case 'uniqid':
					self::arity( $name, $count, 0, 2 );
					$prefix = $count > 0 ? $args[0] : '';
					if ( ! is_string( $prefix ) ) {
						throw new \TypeError( sprintf( 'uniqid(): Argument #1 ($prefix) must be of type string, %s given', self::type_name( $prefix ) ) );
					}
					$more = $count > 1 ? $args[1] : false;
					if ( ! is_bool( $more ) ) {
						throw new \TypeError( sprintf( 'uniqid(): Argument #2 ($more_entropy) must be of type bool, %s given', self::type_name( $more ) ) );
					}
					$this->used = true;
					// PHP's shape: 13 hex digits (8 of seconds, 5 of microseconds), then
					// with more_entropy one digit, a dot and 8 more. Drawn from the seed,
					// so the dry run and the real run make the same ids.
					$id = bin2hex( $this->bytes( 7 ) );
					$id = substr( $id, 0, 13 );
					if ( $more ) {
						$id .= sprintf( '%d.%08d', $this->between( 0, 9 ), $this->between( 0, 99999999 ) );
					}
					return $prefix . $id;
			}

			throw new \Error( sprintf( 'Call to undefined function %s()', $name ) );
		}

		/**
		 * Start the stream over from a seed.
		 *
		 * @param string $seed   32 hex characters.
		 * @param bool   $report Is this the seed the answer hands back?
		 */
		private function reseed( $seed, $report = true ) {
			if ( $report || null === $this->seed ) {
				$this->seed = $seed;
			}
			$this->key     = hex2bin( $seed );
			$this->counter = 0;
			$this->buffer  = '';
		}

		/**
		 * The next bytes of the stream.
		 *
		 * @param int $length How many.
		 * @return string
		 */
		private function bytes( $length ) {
			while ( strlen( $this->buffer ) < $length ) {
				$this->buffer .= hash_hmac( 'sha256', pack( 'J', $this->counter++ ), $this->key, true );
			}
			$out          = substr( $this->buffer, 0, $length );
			$this->buffer = (string) substr( $this->buffer, $length );
			return $out;
		}

		/**
		 * A uniform integer in [min, max], without modulo bias.
		 *
		 * @param int $min Lowest.
		 * @param int $max Highest (>= min).
		 * @return int
		 */
		private function between( $min, $max ) {
			if ( $min === $max ) {
				return $min;
			}
			$span = $max - $min;
			if ( ! is_int( $span ) || PHP_INT_MAX === $span ) {
				// The range is wider than PHP_INT_MAX: draw 64 bits until one lands in it.
				do {
					$value = unpack( 'J', $this->bytes( 8 ) )[1];
				} while ( $value < $min || $value > $max );
				return $value;
			}
			$span += 1;
			// The largest multiple of span that fits in 63 bits; above it, draw again.
			$limit = PHP_INT_MAX - ( ( PHP_INT_MAX % $span ) + 1 ) % $span;
			do {
				$value = unpack( 'J', $this->bytes( 8 ) )[1] & PHP_INT_MAX;
			} while ( $value > $limit );
			return $min + $value % $span;
		}

		/**
		 * Fisher-Yates.
		 *
		 * @param array $items A list.
		 * @return array
		 */
		private function shuffled( array $items ) {
			for ( $i = count( $items ) - 1; $i > 0; $i-- ) {
				$j           = $this->between( 0, $i );
				$swap        = $items[ $i ];
				$items[ $i ] = $items[ $j ];
				$items[ $j ] = $swap;
			}
			return $items;
		}

		/**
		 * PHP's errors for a wrong number of arguments.
		 *
		 * @param string $name  Function.
		 * @param int    $count Arguments given.
		 * @param int    $min   Fewest.
		 * @param int    $max   Most.
		 */
		private static function arity( $name, $count, $min, $max ) {
			if ( $count >= $min && $count <= $max ) {
				return;
			}
			if ( ( 'rand' === $name || 'mt_rand' === $name ) && 1 === $count ) {
				throw new \ArgumentCountError( sprintf( '%s() expects exactly 2 arguments, 1 given', $name ) );
			}
			if ( $count < $min ) {
				throw new \ArgumentCountError( sprintf( '%s() expects %s %d argument%s, %d given', $name, $min === $max ? 'exactly' : 'at least', $min, 1 === $min ? '' : 's', $count ) );
			}
			throw new \ArgumentCountError( sprintf( '%s() expects %s %d argument%s, %d given', $name, $min === $max ? 'exactly' : 'at most', $max, 1 === $max ? '' : 's', $count ) );
		}

		/**
		 * An int argument, under strict_types as scripts run.
		 *
		 * @param string $name     Function.
		 * @param mixed  $value    Value.
		 * @param int    $position 1-based.
		 * @param string $param    Parameter name.
		 * @return int
		 */
		private static function int_arg( $name, $value, $position, $param ) {
			if ( ! is_int( $value ) ) {
				throw new \TypeError( sprintf( '%s(): Argument #%d ($%s) must be of type int, %s given', $name, $position, $param, self::type_name( $value ) ) );
			}
			return $value;
		}

		/**
		 * PHP 8's ValueError; PHP 7.4 has none, so a plain Error there.
		 *
		 * @param string $message Message.
		 * @return \Error
		 */
		private static function value_error( $message ) {
			return class_exists( 'ValueError' ) ? new \ValueError( $message ) : new \Error( $message );
		}

		/**
		 * The type name PHP puts in a TypeError ("false given" from PHP 8.3).
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function type_name( $value ) {
			if ( is_bool( $value ) && PHP_VERSION_ID >= 80300 ) {
				return $value ? 'true' : 'false';
			}
			return UiChemy_Code_Mode_Binder::describe( $value );
		}
	}
}
