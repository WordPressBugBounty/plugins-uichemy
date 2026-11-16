<?php
/**
 * Checks every UiChemy call in a script against the method it names, before
 * anything runs: does the method exist, are the named arguments real, is a
 * required one missing, and does a literal argument have the right type.
 *
 * Arguments that are not literals are checked as the call happens. A wrong
 * literal is the common slip, and catching it here means nothing has run yet.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

use UiChemy\Deps\PhpParser\Node;
use UiChemy\Deps\PhpParser\Node\Expr;
use UiChemy\Deps\PhpParser\Node\Name;
use UiChemy\Deps\PhpParser\NodeVisitorAbstract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Api_Checker' ) ) {

	/**
	 * Collects type errors in the calls a script makes.
	 */
	class UiChemy_Code_Mode_Api_Checker extends NodeVisitorAbstract {

		/** @var UiChemy_Code_Mode_Binder */
		private $binder;

		/** @var array<int,array{line:int,message:string}> */
		private $errors = array();

		/**
		 * @param UiChemy_Code_Mode_Binder $binder Method lookup.
		 */
		public function __construct( UiChemy_Code_Mode_Binder $binder ) {
			$this->binder = $binder;
		}

		/**
		 * @return array<int,array{line:int,message:string}>
		 */
		public function errors() {
			return $this->errors;
		}

		/**
		 * @param Node $node Node.
		 * @return null
		 */
		public function enterNode( Node $node ) {
			if ( ! $node instanceof Expr\StaticCall || ! $node->class instanceof Name || ! $node->name instanceof Node\Identifier ) {
				return null;
			}
			$class = $this->binder->resolve_class( $node->class->toString() );
			if ( null === $class ) {
				return null;
			}

			$line  = $node->getStartLine();
			$found = $this->binder->find( $class, $node->name->toString() );
			if ( null === $found ) {
				$this->fail( $line, sprintf( 'Call to an undefined static method %s%s::%s().', UiChemy_Code_Mode_Binder::API_NAMESPACE, $class, $node->name->toString() ) );
				return null;
			}
			if ( $node->isFirstClassCallable() ) {
				return null;
			}

			$label      = UiChemy_Code_Mode_Binder::label( $found );
			$params     = $found['params'];
			$by_ident   = array();
			foreach ( $params as $param ) {
				$by_ident[ $param['ident'] ] = $param;
			}

			$supplied = array(); // ident => array( known, value ).
			$spread   = false;
			$index    = 0;
			foreach ( $node->getArgs() as $arg ) {
				if ( $arg->unpack ) {
					$spread = true;
					continue;
				}
				if ( null !== $arg->name ) {
					$name = $arg->name->toString();
					if ( ! isset( $by_ident[ $name ] ) ) {
						$this->fail( $line, sprintf( 'Unknown named parameter $%s in static method %s().', $name, $label ) );
						continue;
					}
					$supplied[ $name ] = self::literal( $arg->value );
					continue;
				}
				if ( ! isset( $params[ $index ] ) ) {
					$this->fail( $line, sprintf( 'Static method %s() invoked with more than %d parameters.', $label, count( $params ) ) );
					break;
				}
				$supplied[ $params[ $index ]['ident'] ] = self::literal( $arg->value );
				++$index;
			}

			foreach ( $params as $param ) {
				$ident = $param['ident'];
				if ( ! isset( $supplied[ $ident ] ) ) {
					if ( $param['required'] && ! $spread ) {
						$this->fail( $line, sprintf( 'Static method %s() is missing required parameter $%s.', $label, $ident ) );
					}
					continue;
				}
				list( $known, $value ) = $supplied[ $ident ];
				if ( ! $known || null === $value ) {
					continue;
				}
				$problem = $this->binder->type_problem( $param, $value );
				if ( null !== $problem ) {
					$this->fail( $line, sprintf( 'Parameter $%s of static method %s() %s', $ident, $label, $problem ) );
				}
			}

			return null;
		}

		/**
		 * @param int    $line    Line.
		 * @param string $message Message.
		 */
		private function fail( $line, $message ) {
			$this->errors[] = array(
				'line'    => (int) $line,
				'message' => $message,
			);
		}

		/**
		 * The value of an argument written as a literal.
		 *
		 * @param Expr $expr Argument expression.
		 * @return array{0:bool,1:mixed} Whether the value is known, and the value.
		 */
		private static function literal( $expr ) {
			if ( $expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_ ) {
				return array( true, $expr->value );
			}
			if ( $expr instanceof Expr\ConstFetch ) {
				$name = strtolower( $expr->name->toString() );
				if ( 'true' === $name ) {
					return array( true, true );
				}
				if ( 'false' === $name ) {
					return array( true, false );
				}
				if ( 'null' === $name ) {
					return array( true, null );
				}
			}
			if ( $expr instanceof Expr\UnaryMinus ) {
				list( $known, $value ) = self::literal( $expr->expr );
				if ( $known && ( is_int( $value ) || is_float( $value ) ) ) {
					return array( true, -$value );
				}
			}
			if ( $expr instanceof Expr\Array_ ) {
				return array( true, array() );
			}
			return array( false, null );
		}
	}
}
