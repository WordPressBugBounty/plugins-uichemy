<?php
/**
 * Code Mode IR -> the PHP the model reads: signatures (search), definitions
 * (search with an exact name) and the index, typed with PHP's own type system.
 *
 * One class per ability, one static method per action. Parameters keep the
 * schema's snake_case names so the model calls them with named arguments, and
 * a misspelled name is an error.
 *
 *   JSON Schema                    native (runtime)      PHPDoc (PHPStan)
 *   string/integer/number/boolean  string/int/float/bool same
 *   enum: [...]                    string                'a'|'b'
 *   type: [integer,string,null]    int|string|null       same
 *   array of T                     array                 list<T>
 *   object with properties         array                 array{k: T, j?: U}
 *   not required                   ?T $x = null          T|null
 *
 * The interpreter binds calls to the same IR (UiChemy_Code_Mode_Binder), so
 * what the model reads here is exactly what a script can call.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Php_Emitter' ) ) {

	/**
	 * Writes the UiChemy API as PHP for the model to read.
	 */
	class UiChemy_Code_Mode_Php_Emitter {

		const WRAP = 100;

		/**
		 * Classes and methods as declarations, with their docblocks.
		 *
		 * @param array<int,array> $modules IR modules.
		 * @return string
		 */
		public static function emit( array $modules ) {
			$out = array();
			foreach ( $modules as $module ) {
				$out[] = self::emit_module( $module );
			}
			return implode( "\n", $out );
		}

		/**
		 * One ability as a final class.
		 *
		 * @param array $module IR module.
		 * @return string
		 */
		public static function emit_module( array $module ) {
			$out = "/**\n * " . self::doc_line( $module['label'] ) . "\n";
			if ( ! empty( $module['about'] ) ) {
				$out .= " *\n";
				foreach ( self::wrap( $module['about'] ) as $line ) {
					$out .= ' * ' . $line . "\n";
				}
			}
			$out .= " */\n";
			$out .= 'final class ' . $module['module'] . " {\n";

			foreach ( $module['fns'] as $fn ) {
				$out .= "\n" . self::emit_fn( $fn );
			}

			return $out . "}\n";
		}

		/**
		 * One action as a static method.
		 *
		 * @param array $fn IR function.
		 * @return string
		 */
		private static function emit_fn( array $fn ) {
			$params = self::ordered_params( $fn );

			$doc       = array();
			$signature = array();

			foreach ( self::wrap( $fn['doc'] ) as $line ) {
				$doc[] = $line;
			}
			if ( 'write' === $fn['kind'] ) {
				$doc[] = '';
				$doc[] = 'WRITE: changes the site. Run the script once with `execute` and dry_run: true to preview it.';
			} elseif ( 'delete' === $fn['kind'] ) {
				$doc[] = '';
				$doc[] = 'DELETE: permanent. Runs only with `execute` allow_delete: true, after the user has agreed to what it removes.';
			} elseif ( 'refused' === $fn['kind'] ) {
				$doc[] = '';
				$doc[] = 'REFUSED: ' . UiChemy_Code_Mode_Dispatcher::refusal( $fn['id'] );
			}
			if ( $params ) {
				$doc[] = '';
			}

			foreach ( $params as $param ) {
				$name        = UiChemy_Code_Mode_Binder::identifier( $param['name'] );
				$types       = self::types( $param['schema'] );
				$phpdoc      = $param['required'] ? $types['doc'] : self::nullable_doc( $types['doc'] );
				$signature[] = self::param_signature( $param, false );

				$description = trim( preg_replace( '/\s+/', ' ', $param['description'] ) . self::range( $param['schema'] ) );
				$doc[]       = rtrim( '@param ' . $phpdoc . ' $' . $name . ' ' . self::doc_line( $description ) );
				foreach ( self::nested_docs( $param['schema'], $name ) as $line ) {
					$doc[] = '       ' . $line;
				}
			}
			$doc[] = '@return array<string, mixed>';

			$out = "\t/**\n";
			foreach ( $doc as $line ) {
				$out .= '' === $line ? "\t *\n" : "\t * " . $line . "\n";
			}
			$out .= "\t */\n";
			$out .= "\tpublic static function " . $fn['fn'] . '(' . ( $signature ? "\n\t\t" . implode( ",\n\t\t", $signature ) . "\n\t" : '' ) . "): array;\n";

			return $out;
		}

		/**
		 * One method as a single line, with PHPDoc types so allowed values show:
		 * Page::grep(string $pattern, 'all'|'html'|'css'|'js'|null $field = null, ...): array
		 *
		 * @param array $module IR module.
		 * @param array $fn     IR function.
		 * @return string
		 */
		public static function signature( array $module, array $fn ) {
			$parts = array();
			foreach ( self::ordered_params( $fn ) as $param ) {
				$parts[] = self::param_signature( $param, true );
			}
			return $module['module'] . '::' . $fn['fn'] . '(' . implode( ', ', $parts ) . '): array';
		}

		/**
		 * PHP wants required parameters before optional ones (the interpreter
		 * binds positional arguments in the same order).
		 *
		 * @param array $fn IR function.
		 * @return array<int,array>
		 */
		private static function ordered_params( array $fn ) {
			return UiChemy_Code_Mode_Binder::ordered( $fn['params'] );
		}

		/**
		 * One parameter as it appears in a signature.
		 *
		 * @param array $param   IR parameter.
		 * @param bool  $phpdoc  PHPDoc types (literal unions, shapes) instead of native ones.
		 * @return string
		 */
		private static function param_signature( array $param, $phpdoc ) {
			$types = self::types( $param['schema'] );
			$name  = '$' . UiChemy_Code_Mode_Binder::identifier( $param['name'] );

			if ( $param['required'] ) {
				return ( $phpdoc ? $types['doc'] : $types['native'] ) . ' ' . $name;
			}

			$type = $phpdoc ? self::nullable_doc( $types['doc'] ) : self::nullable( $types['native'] );
			return $type . ' ' . $name . ' = null';
		}

		/**
		 * A schema's native type and its PHPDoc type.
		 *
		 * @param array $schema Property schema.
		 * @return array{native:string, doc:string}
		 */
		public static function types( array $schema ) {
			if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && $schema['enum'] ) {
				$literals = array();
				$natives  = array();
				foreach ( $schema['enum'] as $value ) {
					if ( is_string( $value ) ) {
						$literals[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
						$natives[]  = 'string';
					} elseif ( is_int( $value ) ) {
						$literals[] = (string) $value;
						$natives[]  = 'int';
					} elseif ( is_bool( $value ) ) {
						$literals[] = $value ? 'true' : 'false';
						$natives[]  = 'bool';
					} elseif ( null === $value ) {
						$literals[] = 'null';
						$natives[]  = 'null';
					}
				}
				return array(
					'native' => self::union( $natives ),
					'doc'    => implode( '|', array_unique( $literals ) ),
				);
			}

			$type = $schema['type'] ?? null;

			if ( is_array( $type ) ) {
				$natives = array();
				$docs    = array();
				foreach ( $type as $one ) {
					$single    = self::types( array_merge( $schema, array( 'type' => $one ) ) );
					$natives[] = $single['native'];
					$docs[]    = $single['doc'];
				}
				return array(
					'native' => self::union( $natives ),
					'doc'    => implode( '|', array_unique( $docs ) ),
				);
			}

			switch ( $type ) {
				case 'string':
					return array( 'native' => 'string', 'doc' => 'string' );
				case 'integer':
					return array( 'native' => 'int', 'doc' => 'int' );
				case 'number':
					return array( 'native' => 'float', 'doc' => 'float' );
				case 'boolean':
					return array( 'native' => 'bool', 'doc' => 'bool' );
				case 'null':
					return array( 'native' => 'null', 'doc' => 'null' );
				case 'array':
					$items = isset( $schema['items'] ) && is_array( $schema['items'] ) ? self::types( $schema['items'] ) : array( 'doc' => 'mixed' );
					return array( 'native' => 'array', 'doc' => 'list<' . $items['doc'] . '>' );
				case 'object':
					return array( 'native' => 'array', 'doc' => self::shape( $schema ) );
			}

			return array( 'native' => 'mixed', 'doc' => 'mixed' );
		}

		/**
		 * An object schema as a PHPStan array shape, or array<string, mixed>.
		 *
		 * @param array $schema Object schema.
		 * @return string
		 */
		private static function shape( array $schema ) {
			if ( empty( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
				return 'array<string, mixed>';
			}

			$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
			$keys     = array();
			foreach ( $schema['properties'] as $key => $property ) {
				$key    = (string) $key;
				$label  = preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key ) ? $key : "'" . str_replace( "'", "\\'", $key ) . "'";
				$keys[] = $label . ( in_array( $key, $required, true ) ? '' : '?' ) . ': ' . self::types( is_array( $property ) ? $property : array() )['doc'];
			}

			// An open shape: abilities accept keys their schema does not list.
			return 'array{' . implode( ', ', $keys ) . ', ...}';
		}

		/**
		 * Native union, collapsing to mixed where PHP cannot express it.
		 *
		 * @param array<int,string> $natives Native types.
		 * @return string
		 */
		private static function union( array $natives ) {
			$natives = array_values( array_unique( $natives ) );
			if ( in_array( 'mixed', $natives, true ) || ! $natives ) {
				return 'mixed';
			}
			if ( array( 'null' ) === $natives ) {
				return 'null';
			}
			return implode( '|', $natives );
		}

		/**
		 * @param string $native Native type.
		 * @return string
		 */
		private static function nullable( $native ) {
			if ( 'mixed' === $native || 'null' === $native ) {
				return $native;
			}
			if ( false !== strpos( $native, '|' ) ) {
				return in_array( 'null', explode( '|', $native ), true ) ? $native : $native . '|null';
			}
			return '?' . $native;
		}

		/**
		 * @param string $doc PHPDoc type.
		 * @return string
		 */
		private static function nullable_doc( $doc ) {
			if ( 'mixed' === $doc || preg_match( '/(^|\|)null($|\|)/', $doc ) ) {
				return $doc;
			}
			return $doc . '|null';
		}

		/**
		 * Text that is safe inside a docblock.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private static function doc_line( $text ) {
			// A docblock cannot hold "*/". A CSS comment example becomes words, never a
			// broken "* /" a model would copy into real CSS.
			$text = preg_replace( '#/\*\s*([^*]*?)\s*\*/#', 'a "$1" comment', (string) $text );
			return str_replace( '*/', '* /', (string) $text );
		}

		/**
		 * " (1 to 200)" for a schema with a numeric range or length limit, else ''.
		 *
		 * @param array $schema Schema.
		 * @return string
		 */
		private static function range( array $schema ) {
			$min = $schema['minimum'] ?? ( $schema['minLength'] ?? null );
			$max = $schema['maximum'] ?? ( $schema['maxLength'] ?? null );
			if ( null === $min && null === $max ) {
				return '';
			}
			$what = isset( $schema['minLength'] ) || isset( $schema['maxLength'] ) ? ' characters' : '';
			if ( null !== $min && null !== $max ) {
				return sprintf( ' (%s to %s%s)', $min, $max, $what );
			}
			return null !== $min ? sprintf( ' (at least %s%s)', $min, $what ) : sprintf( ' (at most %s%s)', $max, $what );
		}

		/**
		 * One line per described key of an array shape, so rules on a field
		 * descriptor ("choices: required for select") reach the model too:
		 *   - $fields[].choices: ...
		 *
		 * @param array  $schema Schema.
		 * @param string $path   Where it sits.
		 * @param int    $depth  Depth.
		 * @return array<int,string>
		 */
		private static function nested_docs( array $schema, $path, $depth = 0 ) {
			if ( $depth > 2 ) {
				return array();
			}
			$lines = array();
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				return self::nested_docs( $schema['items'], $path . '[]', $depth + 1 );
			}
			if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
				return array();
			}
			foreach ( $schema['properties'] as $key => $property ) {
				if ( ! is_array( $property ) ) {
					continue;
				}
				$description = trim( preg_replace( '/\s+/', ' ', (string) ( $property['description'] ?? '' ) ) . self::range( $property ) );
				if ( '' !== $description ) {
					$lines[] = '- $' . $path . '.' . $key . ': ' . self::doc_line( $description );
				}
				foreach ( self::nested_docs( $property, $path . '.' . $key, $depth + 1 ) as $line ) {
					$lines[] = $line;
				}
			}
			return $lines;
		}

		/**
		 * Wrap a description into docblock lines.
		 *
		 * @param string $text Description.
		 * @return array<int,string>
		 */
		private static function wrap( $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', self::doc_line( $text ) ) );
			return '' === $text ? array() : explode( "\n", wordwrap( $text, self::WRAP ) );
		}

		/**
		 * The compact index search returns when asked for nothing in
		 * particular: every class and method signature, no docs.
		 *
		 * @param array<int,array> $modules IR modules.
		 * @return array<int,array{class:string, ability:string, label:string, methods:array<int,string>}>
		 */
		public static function index( array $modules ) {
			$index = array();

			foreach ( $modules as $module ) {
				$methods = array();
				foreach ( $module['fns'] as $fn ) {
					$params = array();
					foreach ( $fn['params'] as $param ) {
						$params[] = ( $param['required'] ? '' : '?' ) . UiChemy_Code_Mode_Binder::identifier( $param['name'] );
					}
					$methods[] = $fn['fn'] . '(' . implode( ', ', $params ) . ')' . ( 'read' === $fn['kind'] ? '' : ' [' . $fn['kind'] . ']' );
				}
				$index[] = array(
					'class'   => $module['module'],
					'ability' => $module['slug'],
					'label'   => $module['label'],
					'methods' => $methods,
				);
			}

			return $index;
		}
	}
}
