<?php
/**
 * The one place a UiChemy class or method is looked up: the interpreter, the
 * API checker, search's definitions and the error help all go through it.
 *
 * It finds a method in the IR (by class name or ability slug, by method or
 * action name: "describe-site" is DescribeSite, "get-code" is getCode),
 * matches named and positional arguments to its parameters, and checks their
 * types with strict_types semantics.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Binder' ) ) {

	/**
	 * Method lookup, argument binding and type checks over the IR.
	 */
	class UiChemy_Code_Mode_Binder {

		const API_NAMESPACE = 'UiChemy\\Api\\';

		/** @var array<string,array> Class key => module. */
		private $classes = array();

		/** @var array<string,array> "class key::method key" => { module, fn, params }. */
		private $methods = array();

		/**
		 * @param array $modules IR modules from UiChemy_Code_Mode_Catalog::modules().
		 */
		public function __construct( array $modules ) {
			foreach ( $modules as $module ) {
				$class                   = self::key( $module['module'] );
				$this->classes[ $class ] = $module;
				foreach ( $module['fns'] as $fn ) {
					$params = array();
					foreach ( self::ordered( $fn['params'] ) as $param ) {
						$params[] = array(
							'name'     => $param['name'],
							'ident'    => self::identifier( $param['name'] ),
							'required' => (bool) $param['required'],
							'schema'   => $param['schema'],
						);
					}
					$this->methods[ $class . '::' . self::key( $fn['fn'] ) ] = array(
						'module' => $module,
						'fn'     => $fn,
						'params' => $params,
					);
				}
			}
		}

		/**
		 * @return array<int,string> The class names scripts may call.
		 */
		public function class_names() {
			return array_values( wp_list_pluck( $this->classes, 'module' ) );
		}

		/**
		 * The class a name written in a script refers to, or null.
		 *
		 * Accepts Page, \Page, UiChemy\Api\Page, the ability slug "page" or
		 * "uichemy-composer/page", in any case.
		 *
		 * @param string $name Name as written.
		 * @return string|null Canonical class name.
		 */
		public function resolve_class( $name ) {
			$name = ltrim( (string) $name, '\\' );
			foreach ( array( self::API_NAMESPACE, 'UiChemy\\Script\\', UiChemy_MCP_Server_V2::NAMESPACE_SLUG . '/' ) as $prefix ) {
				if ( 0 === stripos( $name, $prefix ) ) {
					$name = substr( $name, strlen( $prefix ) );
					break;
				}
			}
			$key = self::key( $name );
			return isset( $this->classes[ $key ] ) ? $this->classes[ $key ]['module'] : null;
		}

		/**
		 * @param string $class  Canonical class name.
		 * @param string $method Method or action name, any case.
		 * @return array|null { module, fn, params }.
		 */
		public function find( $class, $method ) {
			$key = self::key( $class ) . '::' . self::key( $method );
			return isset( $this->methods[ $key ] ) ? $this->methods[ $key ] : null;
		}

		/**
		 * @param string $class Canonical class name.
		 * @return array|null The IR module.
		 */
		public function module( $class ) {
			$key = self::key( $class );
			return isset( $this->classes[ $key ] ) ? $this->classes[ $key ] : null;
		}

		/**
		 * The class names closest to one that does not exist.
		 *
		 * @param string $name What the script or model wrote.
		 * @return array<int,string>
		 */
		public function closest_classes( $name ) {
			return self::closest( $name, $this->class_names() );
		}

		/**
		 * The methods of a class closest to one that does not exist, as signatures.
		 *
		 * @param string $class Canonical class name.
		 * @param string $name  What the script or model wrote.
		 * @return array<int,string>
		 */
		public function closest_methods( $class, $name ) {
			$module = $this->module( $class );
			if ( null === $module ) {
				return array();
			}
			$signatures = array();
			foreach ( $module['fns'] as $fn ) {
				$signatures[ $fn['fn'] ] = UiChemy_Code_Mode_Php_Emitter::signature( $module, $fn );
			}
			$out = array();
			foreach ( self::closest( $name, array_keys( $signatures ) ) as $method ) {
				$out[] = $signatures[ $method ];
			}
			return $out;
		}

		/**
		 * The candidates closest to $needle: containment first, then edit distance.
		 *
		 * @param string            $needle     What was asked for.
		 * @param array<int,string> $candidates Real names.
		 * @param int               $count      How many to return.
		 * @return array<int,string>
		 */
		private static function closest( $needle, array $candidates, $count = 3 ) {
			$needle = self::key( $needle );
			$scored = array();
			foreach ( $candidates as $candidate ) {
				$plain = self::key( $candidate );
				$score = levenshtein( $needle, $plain );
				if ( '' !== $needle && ( false !== strpos( $plain, $needle ) || false !== strpos( $needle, $plain ) ) ) {
					$score -= 100;
				}
				$scored[ (string) $candidate ] = $score;
			}
			asort( $scored );
			return array_slice( array_keys( $scored ), 0, $count );
		}

		/**
		 * Lookup key: lowercase letters and digits only, so DescribeSite,
		 * describe-site and describesite are one name.
		 *
		 * @param string $name Name.
		 * @return string
		 */
		private static function key( $name ) {
			return strtolower( (string) preg_replace( '/[^a-zA-Z0-9]/', '', (string) $name ) );
		}

		/**
		 * "UiChemy\Api\Page::grep".
		 *
		 * @param array $found Result of find().
		 * @return string
		 */
		public static function label( array $found ) {
			return self::API_NAMESPACE . $found['module']['module'] . '::' . $found['fn']['fn'];
		}

		/**
		 * Match a call's arguments to the method's parameters.
		 *
		 * @param array $found      Result of find().
		 * @param array $positional Positional values, in order.
		 * @param array $named      Named values, by parameter name.
		 * @return array The ability arguments, by the ability's own parameter names, nulls dropped.
		 * @throws \Error|\TypeError|\ArgumentCountError When the call does not fit the method.
		 */
		public function bind( array $found, array $positional, array $named ) {
			$label  = self::label( $found );
			$params = $found['params'];
			$values = array();

			if ( count( $positional ) > count( $params ) ) {
				throw new \ArgumentCountError( sprintf( 'Static method %s() invoked with %d parameters, at most %d allowed.', $label, count( $positional ), count( $params ) ) );
			}
			foreach ( array_values( $positional ) as $index => $value ) {
				$values[ $params[ $index ]['ident'] ] = $value;
			}

			$by_ident = array();
			foreach ( $params as $param ) {
				$by_ident[ $param['ident'] ] = $param;
			}
			foreach ( $named as $name => $value ) {
				if ( ! isset( $by_ident[ $name ] ) ) {
					throw new \Error( sprintf( 'Unknown named parameter $%s in static method %s().', $name, $label ) );
				}
				if ( array_key_exists( $name, $values ) ) {
					throw new \Error( sprintf( 'Named parameter $%s overwrites a previous argument in static method %s().', $name, $label ) );
				}
				$values[ $name ] = $value;
			}

			$args = array();
			foreach ( $params as $param ) {
				$value = array_key_exists( $param['ident'], $values ) ? $values[ $param['ident'] ] : null;
				if ( null === $value ) {
					if ( $param['required'] ) {
						throw new \ArgumentCountError( sprintf( 'Static method %s() is missing required parameter $%s.', $label, $param['ident'] ) );
					}
					continue;
				}
				$problem = $this->type_problem( $param, $value );
				if ( null !== $problem ) {
					throw new \TypeError( sprintf( 'Parameter $%s of static method %s() %s', $param['ident'], $label, $problem ) );
				}
				$args[ $param['name'] ] = $value;
			}

			return $args;
		}

		/**
		 * "expects int, string given." when the value does not fit the parameter, else null.
		 *
		 * @param array $param Bound parameter.
		 * @param mixed $value Value.
		 * @return string|null
		 */
		public function type_problem( array $param, $value ) {
			if ( ! self::matches( $param['schema'], $value ) ) {
				$expected = UiChemy_Code_Mode_Php_Emitter::types( $param['schema'] );
				// As PHP words it ("string given"); the value itself only for an enum, where it is the point.
				$enum = ! empty( $param['schema']['enum'] );
				return sprintf( 'expects %s, %s given.', $expected['doc'], $enum ? self::given( $value ) : self::describe( $value ) );
			}
			// The top level fits: now what is inside it (list items, shape keys, ranges).
			$inner = self::inner_problem( $param['schema'], $value, '$' . $param['ident'], 0 );
			return null === $inner ? null : 'is not valid: ' . $inner;
		}

		/**
		 * What is wrong inside a value whose top-level type is right, or null:
		 * list items, enums inside them, the keys an object shape requires, and
		 * numeric or length ranges. Lenient where the handlers are: unknown keys
		 * pass, and a map is accepted where a list of plain values is declared.
		 *
		 * @param array  $schema Schema.
		 * @param mixed  $value  Value.
		 * @param string $path   Where it sits, e.g. $edits[0].
		 * @param int    $depth  Depth.
		 * @return string|null
		 */
		private static function inner_problem( array $schema, $value, $path, $depth ) {
			if ( $depth > 6 ) {
				return null;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				if ( isset( $schema['minimum'] ) && is_numeric( $schema['minimum'] ) && $value < $schema['minimum'] ) {
					return sprintf( '%s must be at least %s, %s given.', $path, $schema['minimum'], $value );
				}
				if ( isset( $schema['maximum'] ) && is_numeric( $schema['maximum'] ) && $value > $schema['maximum'] ) {
					return sprintf( '%s must be at most %s, %s given.', $path, $schema['maximum'], $value );
				}
				return null;
			}
			if ( is_string( $value ) ) {
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
				if ( isset( $schema['minLength'] ) && is_numeric( $schema['minLength'] ) && $length < $schema['minLength'] ) {
					return sprintf( '%s must be at least %d characters, %d given.', $path, $schema['minLength'], $length );
				}
				if ( isset( $schema['maxLength'] ) && is_numeric( $schema['maxLength'] ) && $length > $schema['maxLength'] ) {
					return sprintf( '%s must be at most %d characters, %d given.', $path, $schema['maxLength'], $length );
				}
				return null;
			}
			if ( ! is_array( $value ) ) {
				return null;
			}

			$types   = (array) ( $schema['type'] ?? array() );
			$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			$items   = isset( $schema['items'] ) && is_array( $schema['items'] ) ? $schema['items'] : null;

			if ( null !== $items && in_array( 'array', $types, true ) && ( $is_list || ! in_array( 'object', $types, true ) ) ) {
				$shaped = isset( $items['properties'] ) || isset( $items['required'] );
				if ( ! $is_list ) {
					// A list of shapes (edits, fields, images) given one shape, or keys: say so.
					if ( $shaped ) {
						return sprintf( '%s must be a list ([...]) of items, not a single keyed array.', $path );
					}
					$walk = $value;
				} else {
					$walk = $value;
				}
				if ( isset( $schema['minItems'] ) && is_numeric( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
					return sprintf( '%s needs at least %d item(s), %d given.', $path, $schema['minItems'], count( $value ) );
				}
				if ( isset( $schema['maxItems'] ) && is_numeric( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
					return sprintf( '%s takes at most %d item(s), %d given.', $path, $schema['maxItems'], count( $value ) );
				}
				foreach ( $walk as $index => $item ) {
					$where = $path . '[' . ( is_int( $index ) ? $index : var_export( $index, true ) ) . ']';
					if ( ! self::matches( $items, $item ) ) {
						$expected = UiChemy_Code_Mode_Php_Emitter::types( $items );
						return sprintf( '%s expects %s, %s given.', $where, $expected['doc'], self::given( $item ) );
					}
					$inner = self::inner_problem( $items, $item, $where, $depth + 1 );
					if ( null !== $inner ) {
						return $inner;
					}
				}
				return null;
			}

			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ! $is_list || ( isset( $schema['required'] ) && is_array( $schema['required'] ) && ! $is_list ) ) {
				foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
					if ( is_string( $key ) && ( ! array_key_exists( $key, $value ) || null === $value[ $key ] ) ) {
						return sprintf( '%s is missing "%s"%s.', $path, $key, self::keys_hint( $schema ) );
					}
				}
				foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $property ) {
					if ( ! is_array( $property ) || ! array_key_exists( $key, $value ) || null === $value[ $key ] ) {
						continue;
					}
					$where = $path . '.' . $key;
					if ( ! self::matches( $property, $value[ $key ] ) ) {
						$expected = UiChemy_Code_Mode_Php_Emitter::types( $property );
						return sprintf( '%s expects %s, %s given.', $where, $expected['doc'], self::given( $value[ $key ] ) );
					}
					$inner = self::inner_problem( $property, $value[ $key ], $where, $depth + 1 );
					if ( null !== $inner ) {
						return $inner;
					}
				}
			}
			return null;
		}

		/**
		 * " (its keys: find, replace, all)" for an object shape.
		 *
		 * @param array $schema Object schema.
		 * @return string
		 */
		private static function keys_hint( array $schema ) {
			$keys = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
			return $keys ? ' (its keys: ' . implode( ', ', array_slice( $keys, 0, 12 ) ) . ')' : '';
		}

		/**
		 * The value for an error: a short scalar as written ("'trash'", "300"), else its type.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function given( $value ) {
			if ( is_string( $value ) && strlen( $value ) <= 40 ) {
				return var_export( $value, true );
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				return (string) $value;
			}
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}
			return self::describe( $value );
		}

		/**
		 * @param array $schema Property schema.
		 * @param mixed $value  Value.
		 * @return bool
		 */
		private static function matches( array $schema, $value ) {
			if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && $schema['enum'] ) {
				return in_array( $value, $schema['enum'], true );
			}

			$type = isset( $schema['type'] ) ? $schema['type'] : null;
			if ( null === $type ) {
				return true;
			}
			foreach ( (array) $type as $one ) {
				switch ( $one ) {
					case 'string':
						$ok = is_string( $value );
						break;
					case 'integer':
						$ok = is_int( $value );
						break;
					case 'number':
						$ok = is_int( $value ) || is_float( $value );
						break;
					case 'boolean':
						$ok = is_bool( $value );
						break;
					case 'null':
						$ok = null === $value;
						break;
					case 'array':
					case 'object':
						$ok = is_array( $value );
						break;
					default:
						$ok = true;
				}
				if ( $ok ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * The type name PHP 8 prints in a type error.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		public static function describe( $value ) {
			if ( null === $value ) {
				return 'null';
			}
			if ( is_bool( $value ) ) {
				return 'bool';
			}
			if ( is_int( $value ) ) {
				return 'int';
			}
			if ( is_float( $value ) ) {
				return 'float';
			}
			if ( is_string( $value ) ) {
				return 'string';
			}
			if ( is_array( $value ) ) {
				return 'array';
			}
			return is_object( $value ) ? get_class( $value ) : gettype( $value );
		}

		/**
		 * Required parameters first, in their own order, then the optional ones.
		 *
		 * A stable partition: usort() is not stable before PHP 8.0.
		 *
		 * @param array $params IR parameters.
		 * @return array
		 */
		public static function ordered( array $params ) {
			$required = array();
			$optional = array();
			foreach ( $params as $param ) {
				if ( ! empty( $param['required'] ) ) {
					$required[] = $param;
				} else {
					$optional[] = $param;
				}
			}
			return array_merge( $required, $optional );
		}

		/**
		 * The PHP identifier a parameter name becomes ("max-results" -> "max_results").
		 *
		 * @param string $name Parameter name.
		 * @return string
		 */
		public static function identifier( $name ) {
			$name = preg_replace( '/[^a-zA-Z0-9_]/', '_', (string) $name );
			return preg_match( '/^[0-9]/', $name ) ? '_' . $name : $name;
		}
	}
}
