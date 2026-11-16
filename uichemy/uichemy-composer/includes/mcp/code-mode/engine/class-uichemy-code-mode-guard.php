<?php
/**
 * Static allowlist over a script's syntax tree, run before anything executes.
 *
 * Everything not explicitly allowed is refused: it is an allowlist of names,
 * not a blocklist of dangerous ones. This gives the model every problem with
 * its script at once, with the line it is on. The interpreter enforces the
 * same rules again as it runs, so the Guard is the helpful layer and the
 * interpreter is the boundary.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

use UiChemy\Deps\PhpParser\Node;
use UiChemy\Deps\PhpParser\Node\Expr;
use UiChemy\Deps\PhpParser\Node\Name;
use UiChemy\Deps\PhpParser\Node\Stmt;
use UiChemy\Deps\PhpParser\NodeVisitorAbstract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Guard' ) ) {

	/**
	 * Collects every violation in a script.
	 */
	class UiChemy_Code_Mode_Guard extends NodeVisitorAbstract {

		const SUPERGLOBALS = array( 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'argv', 'argc', 'http_response_header' );

		/** Deepest nesting of syntax a script may have. */
		const MAX_DEPTH = 300;

		/** @var UiChemy_Code_Mode_Binder */
		private $binder;

		/** @var array<string,true> */
		private $functions;

		/** @var array<string,true> Functions the script declares itself, lowercase. */
		private $declared = array();

		/** @var array<int,array{line:int,message:string}> */
		private $violations = array();

		/** @var int */
		private $depth = 0;

		/** @var bool */
		private $too_deep = false;

		/**
		 * @param UiChemy_Code_Mode_Binder $binder The classes a script may call.
		 */
		public function __construct( UiChemy_Code_Mode_Binder $binder ) {
			$this->binder    = $binder;
			$this->functions = array_fill_keys( UiChemy_Code_Mode_Allowlist::functions(), true );
		}

		/**
		 * @return array<int,array{line:int,message:string}>
		 */
		public function violations() {
			return $this->violations;
		}

		/**
		 * Collect the script's own function declarations first, so calls to
		 * them are allowed wherever they appear.
		 *
		 * @param array $nodes Syntax tree.
		 * @return null
		 */
		public function beforeTraverse( array $nodes ) {
			$finder = new \UiChemy\Deps\PhpParser\NodeFinder();
			foreach ( $finder->findInstanceOf( $nodes, Stmt\Function_::class ) as $function ) {
				$this->declared[ strtolower( $function->name->toString() ) ] = true;
			}
			// A top-level function exists from the start, so a second one with the same
			// name, or one named like a function PHP already has, is PHP's "Cannot redeclare".
			// (Inside an if, either may be fine: the interpreter decides when it runs.)
			$top = array();
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof Stmt\Function_ ) {
					continue;
				}
				$name = strtolower( $node->name->toString() );
				if ( isset( $top[ $name ] ) || isset( $this->functions[ $name ] ) ) {
					$this->refuse( $node, sprintf( 'Cannot redeclare %s(). Give the function another name.', $node->name->toString() ) );
				}
				$top[ $name ] = true;
			}
			return null;
		}

		/**
		 * @param Node $node Node.
		 * @return null
		 */
		public function enterNode( Node $node ) {
			++$this->depth;
			if ( $this->depth > self::MAX_DEPTH && ! $this->too_deep ) {
				$this->too_deep = true;
				$this->refuse( $node, 'The script is nested too deeply.' );
			}
			$this->check( $node );
			return null;
		}

		/**
		 * @param Node $node Node.
		 * @return null
		 */
		public function leaveNode( Node $node ) {
			--$this->depth;
			return null;
		}

		/**
		 * @param Node   $node    Node.
		 * @param string $message Message.
		 */
		private function refuse( Node $node, $message ) {
			$this->violations[] = array(
				'line'    => $node->getStartLine(),
				'message' => $message,
			);
		}

		/**
		 * @param Node $node Node.
		 */
		private function check( Node $node ) {
			$banned = array(
				Expr\Include_::class            => 'include/require is not allowed.',
				Expr\Eval_::class               => 'eval is not allowed.',
				Expr\ShellExec::class           => 'Backtick shell execution is not allowed.',
				Expr\Exit_::class               => 'exit/die is not allowed. Return a value instead.',
				Expr\Yield_::class              => 'yield is not allowed.',
				Expr\YieldFrom::class           => 'yield from is not allowed.',
				Expr\StaticPropertyFetch::class => 'Static properties are not allowed.',
				Stmt\Goto_::class               => 'goto is not allowed.',
				Stmt\Label::class               => 'Labels are not allowed.',
				Stmt\Global_::class             => 'global is not allowed.',
				Stmt\Static_::class             => 'static variables are not supported. Use a normal variable.',
				Stmt\Const_::class              => 'const declarations are not supported. Use a variable.',
				Stmt\HaltCompiler::class        => '__halt_compiler is not allowed.',
				Stmt\Declare_::class            => 'declare is not needed; remove it.',
				Stmt\Class_::class              => 'Declaring classes is not allowed.',
				Stmt\Interface_::class          => 'Declaring interfaces is not allowed.',
				Stmt\Trait_::class              => 'Declaring traits is not allowed.',
				Stmt\Enum_::class               => 'Declaring enums is not allowed.',
				Stmt\Namespace_::class          => 'Namespaces are not needed; remove it.',
				Stmt\Use_::class                => 'Every UiChemy class is already available; remove the use statement.',
				Stmt\GroupUse::class            => 'Every UiChemy class is already available; remove the use statement.',
				Stmt\InlineHTML::class          => 'Inline HTML outside <?php is not allowed.',
			);
			foreach ( $banned as $class => $message ) {
				if ( $node instanceof $class ) {
					$this->refuse( $node, $message );
					return;
				}
			}

			if ( $node instanceof Node\Param ) {
				if ( $node->byRef ) {
					$this->refuse( $node, 'Reference parameters (&$x) are not supported. Return the new value, or change an array in place with foreach ($array as &$item).' );
				}
				return;
			}

			if ( $node instanceof Node\ArrayItem ) {
				if ( $node->byRef ) {
					$this->refuse( $node, 'References inside arrays are not supported.' );
				}
				return;
			}

			if ( $node instanceof Expr\Variable ) {
				if ( ! is_string( $node->name ) ) {
					$this->refuse( $node, 'Variable variables are not allowed.' );
				} elseif ( in_array( $node->name, self::SUPERGLOBALS, true ) ) {
					$this->refuse( $node, sprintf( '$%s is not available.', $node->name ) );
				}
				return;
			}

			if ( $node instanceof Expr\FuncCall ) {
				$this->check_function_call( $node );
				return;
			}

			if ( $node instanceof Expr\StaticCall ) {
				$this->check_static_call( $node );
				return;
			}

			if ( $node instanceof Expr\New_ ) {
				$class = $node->class instanceof Name ? $node->class->toString() : null;
				if ( null === $class || ( null === UiChemy_Code_Mode_Allowlist::exception_class( $class ) && null === UiChemy_Code_Mode_Allowlist::value_class( $class ) ) ) {
					$this->refuse( $node, sprintf( 'new %s is not allowed. Only exceptions and stdClass can be created, e.g. new \\RuntimeException(...).', null === $class ? '(dynamic)' : $class ) );
				}
				return;
			}

			if ( $node instanceof Expr\ClassConstFetch ) {
				$class = $node->class instanceof Name ? $node->class->toString() : null;
				if ( null === $class || ! $this->is_known_class( $class ) || ! $node->name instanceof Node\Identifier || 'class' !== strtolower( $node->name->toString() ) ) {
					$this->refuse( $node, 'Only Name::class is allowed on a class, and only for UiChemy classes and exceptions.' );
				}
				return;
			}

			if ( $node instanceof Expr\Instanceof_ ) {
				$class = $node->class instanceof Name ? $node->class->toString() : null;
				if ( null === $class || ( null === UiChemy_Code_Mode_Allowlist::exception_class( $class ) && null === UiChemy_Code_Mode_Allowlist::value_class( $class ) ) ) {
					$this->refuse( $node, 'instanceof is only allowed against exception classes and stdClass.' );
				}
				return;
			}

			if ( $node instanceof Stmt\Catch_ ) {
				foreach ( $node->types as $type ) {
					if ( null === UiChemy_Code_Mode_Allowlist::exception_class( $type->toString() ) ) {
						$this->refuse( $node, sprintf( 'catch (%s) is not allowed. Catch \\Throwable, \\RuntimeException or another built-in exception.', $type->toString() ) );
					}
				}
				return;
			}

			if ( $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall ) {
				if ( ! $node->name instanceof Node\Identifier || ! in_array( strtolower( $node->name->toString() ), UiChemy_Code_Mode_Allowlist::methods(), true ) ) {
					$this->refuse( $node, UiChemy_Code_Mode_Allowlist::methods_message() );
				}
				return;
			}

			if ( $node instanceof Expr\ConstFetch ) {
				$this->check_constant( $node );
				return;
			}

			// __FILE__, __DIR__ (paths) and __TRAIT__ / __PROPERTY__ stay out.
			if ( $node instanceof Node\Scalar\MagicConst && ! $node instanceof Node\Scalar\MagicConst\Line && ! $node instanceof Node\Scalar\MagicConst\Function_
				&& ! $node instanceof Node\Scalar\MagicConst\Method && ! $node instanceof Node\Scalar\MagicConst\Class_ && ! $node instanceof Node\Scalar\MagicConst\Namespace_ ) {
				$this->refuse( $node, 'Only __LINE__, __FUNCTION__, __METHOD__, __CLASS__ and __NAMESPACE__ are allowed.' );
			}
		}

		/**
		 * @param Expr\StaticCall $node Node.
		 */
		private function check_static_call( Expr\StaticCall $node ) {
			$class = $node->class instanceof Name ? $node->class->toString() : null;

			if ( null === $class || ! $node->name instanceof Node\Identifier ) {
				$this->refuse( $node, 'Dynamic static calls are not allowed. Call a UiChemy class by name, e.g. Page::grep(...).' );
				return;
			}

			$canonical = $this->binder->resolve_class( $class );
			if ( null !== $canonical ) {
				return;
			}

			if ( $node->class->isUnqualified() ) {
				// An unqualified name that is not one of ours: almost always a misspelt class.
				$this->refuse( $node, sprintf( 'There is no class %s. The classes are: %s.', $class, implode( ', ', $this->binder->class_names() ) ) );
				return;
			}

			$this->refuse( $node, sprintf( '%s::%s() is not allowed. Only the UiChemy classes can be called.', $class, $node->name->toString() ) );
		}

		/**
		 * @param Expr\FuncCall $node Node.
		 */
		private function check_function_call( Expr\FuncCall $node ) {
			if ( ! $node->name instanceof Name ) {
				// $fn(...), (fn() => ...)(...), "trim"(...): the interpreter calls only a
				// closure this run made or an allowed function, so only what is plainly
				// wrong is refused here.
				$this->check_callable_value( $node, $node->name, 'call' );
				return;
			}

			$name = UiChemy_Code_Mode_Allowlist::function_name( $node->name );
			if ( null === $name ) {
				$this->refuse( $node, sprintf( '%s() is not allowed.', $node->name->toString() ) );
				return;
			}
			if ( isset( $this->declared[ $name ] ) ) {
				return;
			}
			if ( ! isset( $this->functions[ $name ] ) ) {
				$this->refuse( $node, sprintf( '%s() is not in the allowed function list.', $name ) );
				return;
			}

			$callable = UiChemy_Code_Mode_Allowlist::callable_arguments();
			if ( ! isset( $callable[ $name ] ) || $node->isFirstClassCallable() ) {
				return;
			}

			foreach ( $node->getArgs() as $index => $arg ) {
				if ( $arg->unpack ) {
					$this->refuse( $node, sprintf( 'Spreading arguments into %s() is not allowed.', $name ) );
					return;
				}
				$is_callback = null !== $arg->name ? 'callback' === $arg->name->toString() : $index === $callable[ $name ];
				if ( ! $is_callback ) {
					continue;
				}
				$this->check_callable_value( $node, $arg->value, $name );
			}
		}

		/**
		 * What can be called: a closure, an arrow function, name(...), null (for
		 * array_map), a variable or other expression (checked when it runs), or a
		 * string naming an allowed or declared function. Refused here: a string
		 * naming anything else, and array or 'Class::method' callables.
		 *
		 * @param Node   $node  The call, for the line.
		 * @param Node   $value What is called, or passed as the callback.
		 * @param string $where 'call', or the function the callback is passed to.
		 */
		private function check_callable_value( Node $node, Node $value, $where ) {
			$message = 'call' === $where ? '%s' : sprintf( 'The callback passed to %s(): %%s', $where );
			if ( $value instanceof Expr\Array_ ) {
				$this->refuse( $node, sprintf( $message, 'Array callables ([$object, "method"]) are not allowed. Use a closure, e.g. fn($x) => ...' ) );
				return;
			}
			if ( ! $value instanceof Node\Scalar\String_ ) {
				return;
			}
			$called = strtolower( ltrim( $value->value, '\\' ) );
			if ( isset( $this->declared[ $called ] ) ) {
				return;
			}
			if ( 'compact' === $called ) {
				$this->refuse( $node, sprintf( $message, 'compact() cannot be called dynamically. Call it by name.' ) );
				return;
			}
			if ( ! isset( $this->functions[ $called ] ) ) {
				$this->refuse( $node, sprintf( $message, sprintf( '%s() is not in the allowed function list.', $value->value ) ) );
			}
		}

		/**
		 * @param Expr\ConstFetch $node Node.
		 */
		private function check_constant( Expr\ConstFetch $node ) {
			if ( null === UiChemy_Code_Mode_Allowlist::constant_name( $node->name ) ) {
				$this->refuse( $node, sprintf( 'Constant %s is not allowed.', $node->name->toString() ) );
			}
		}

		/**
		 * @param string $class Class name as written.
		 * @return bool
		 */
		private function is_known_class( $class ) {
			return null !== UiChemy_Code_Mode_Allowlist::exception_class( $class ) || null !== UiChemy_Code_Mode_Allowlist::value_class( $class ) || null !== $this->binder->resolve_class( $class );
		}
	}
}
