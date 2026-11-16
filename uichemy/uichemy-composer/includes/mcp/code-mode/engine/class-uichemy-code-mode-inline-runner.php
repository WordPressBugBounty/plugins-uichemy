<?php
/**
 * Runs a Code Mode script inside this request with the bundled interpreter.
 * No eval, no second process, no PHP CLI, no server setup.
 *
 *   1. parse     (nikic/php-parser)            -> kind "syntax"
 *   2. Guard     (allowlist over the tree)     -> kind "refused"
 *   3. API check (calls against the methods)   -> kind "type"
 *   4. interpret (UiChemy_Code_Mode_Interpreter)
 *
 * It returns one step array, { type: done } or { type: error }, and each
 * ability call a script makes is answered by the $on_call callback the run
 * loop in UiChemy_Code_Mode passes in (budgets, the dispatcher, the records).
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

use UiChemy\Deps\PhpParser\NodeTraverser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Inline_Runner' ) ) {

	/**
	 * Checks and runs a script in-process.
	 */
	class UiChemy_Code_Mode_Inline_Runner {

		/** Longest script accepted, in bytes. */
		const MAX_CODE = 102400;

		/**
		 * Most tokens of code (strings count as one) a script may have. The
		 * parser keeps about 800 bytes per token, so 100 KB of dense code would
		 * take some 80 MB to parse; this keeps it near 16 MB.
		 */
		const MAX_TOKENS = 20000;

		/** @var string The script being run, for the development refusal log. */
		private static $script = '';

		/**
		 * Is the bundled parser there? (It is built into vendor-prefixed/.)
		 *
		 * @return bool
		 */
		public static function available() {
			return self::tokenizer_available() && class_exists( '\UiChemy\Deps\PhpParser\ParserFactory' );
		}

		/**
		 * Does PHP have its tokenizer extension? The parser's lexer needs it,
		 * and a host can build PHP without it; parsing would then fail.
		 *
		 * @return bool
		 */
		public static function tokenizer_available() {
			return function_exists( 'token_get_all' );
		}

		/**
		 * Transport-specific measurements for the usage block.
		 *
		 * @return array<string,mixed>
		 */
		public function usage() {
			return array( 'transport' => 'inline' );
		}

		/**
		 * Check and run a script.
		 *
		 * @param string   $code    Script body.
		 * @param array    $modules IR modules.
		 * @param array    $limits  { script_ms:int }.
		 * @param callable $on_call ( $id, $args ) => result array of the dispatcher, or { limit }.
		 * @return array Step: { type: done, output, logs, usage } or { type: error, kind, message, line, ... }.
		 */
		public function run( $code, array $modules, array $limits, $on_call ) {
			self::load();

			$code         = (string) $code;
			$usage        = array();
			self::$script = $code;

			if ( strlen( $code ) > self::MAX_CODE ) {
				return self::error( 'limit', sprintf( 'The script is longer than %d KB. Split it into smaller scripts.', self::MAX_CODE / 1024 ), 0, $usage );
			}

			$binder = new UiChemy_Code_Mode_Binder( $modules );

			// 1. Parse.
			$began  = microtime( true );
			$source = self::assemble( $code );
			if ( self::count_tokens( $source ) > self::MAX_TOKENS ) {
				return self::error( 'limit', sprintf( 'The script has more than %s tokens of code. Split it into smaller scripts; long text in a string counts as one token.', number_format_i18n( self::MAX_TOKENS ) ), 0, $usage );
			}
			$parser = ( new \UiChemy\Deps\PhpParser\ParserFactory() )->createForNewestSupportedVersion();
			try {
				$stmts = $parser->parse( $source );
			} catch ( \UiChemy\Deps\PhpParser\Error $e ) {
				$usage['parse_ms'] = self::ms( $began );
				return self::error( 'syntax', $e->getRawMessage(), max( 0, $e->getStartLine() ), $usage );
			}
			$stmts             = null === $stmts ? array() : $stmts;
			$usage['parse_ms'] = self::ms( $began );

			// Too deep for the Guard? Checked without recursion: every later walk of
			// the tree recurses, and so does PHP when it frees one tens of thousands deep.
			$deepest = self::deeper_than( $stmts, UiChemy_Code_Mode_Guard::MAX_DEPTH );
			if ( null !== $deepest ) {
				$line = $deepest->getStartLine();
				self::dismantle( $stmts );
				return self::error( 'refused', 'The script is nested too deeply.', $line, $usage );
			}

			// 2. Guard.
			$began     = microtime( true );
			$guard     = new UiChemy_Code_Mode_Guard( $binder );
			$traverser = new NodeTraverser();
			$traverser->addVisitor( $guard );
			$traverser->traverse( $stmts );
			$usage['guard_ms'] = self::ms( $began );
			if ( $guard->violations() ) {
				$violations = $guard->violations();
				return self::error( 'refused', $violations[0]['message'], $violations[0]['line'], $usage, $violations );
			}

			// 3. API calls against the methods they name.
			$began     = microtime( true );
			$checker   = new UiChemy_Code_Mode_Api_Checker( $binder );
			$traverser = new NodeTraverser();
			$traverser->addVisitor( $checker );
			$traverser->traverse( $stmts );
			$usage['check_ms'] = self::ms( $began );
			if ( $checker->errors() ) {
				$errors = $checker->errors();
				return self::error( 'type', $errors[0]['message'], $errors[0]['line'], $usage, $errors );
			}

			// 4. Run.
			$interpreter = new UiChemy_Code_Mode_Interpreter(
				$binder,
				$on_call,
				isset( $limits['script_ms'] ) ? $limits['script_ms'] : 10000,
				array(
					'result_bytes' => isset( $limits['result_bytes'] ) ? (int) $limits['result_bytes'] : 0,
					'wall_ms'      => isset( $limits['wall_ms'] ) ? (float) $limits['wall_ms'] : 0.0,
					'seed'         => isset( $limits['seed'] ) ? $limits['seed'] : null,
				)
			);
			$began       = microtime( true );
			try {
				$result = $interpreter->run( $stmts );
			} catch ( UiChemy_Code_Mode_Halt $halt ) {
				$usage['run_ms'] = self::ms( $began );
				return self::error( $halt->kind, $halt->getMessage(), $halt->script_line, $usage, array(), $interpreter->logs(), $interpreter->warnings() );
			} catch ( \Throwable $e ) {
				$usage['run_ms'] = self::ms( $began );
				$message         = str_replace( ABSPATH, '', $e->getMessage() );
				return self::error( self::kind_of( $message ), $message, $interpreter->line_of( $e ), $usage, array(), $interpreter->logs(), $interpreter->warnings() );
			}

			$usage['run_ms']  = self::ms( $began );
			$usage['steps']   = $result['steps'];
			$usage['peak_kb'] = $result['peak_kb'];

			return array(
				'type'   => 'done',
				'output' => self::as_data( $result['output'] ),
				'logs'     => $result['logs'],
				'warnings' => $result['warnings'],
				'seed'     => $interpreter->seed_used(),
				'usage'    => $usage,
			);
		}

		/**
		 * Load the engine. Kept out of the plugin's normal load: the Guard and
		 * the API checker extend the parser's visitor, so they can only be
		 * declared once the parser is there.
		 */
		private static function load() {
			$dir = __DIR__ . '/';
			foreach ( array( 'allowlist', 'halt', 'native', 'random', 'binder', 'guard', 'api-checker', 'interpreter' ) as $file ) {
				require_once $dir . 'class-uichemy-code-mode-' . $file . '.php';
			}
		}

		/**
		 * Tokens of code, leaving out whitespace and comments.
		 *
		 * @param string $source Script as the parser sees it.
		 * @return int 0 when PHP has no tokenizer (the parser then cannot run either).
		 */
		private static function count_tokens( $source ) {
			// Every token takes at least a byte: a short script needs no count.
			if ( strlen( $source ) <= self::MAX_TOKENS ) {
				return 0;
			}
			if ( class_exists( 'PhpToken' ) ) {
				$tokens = \PhpToken::tokenize( $source );
			} elseif ( function_exists( 'token_get_all' ) ) {
				$tokens = token_get_all( $source );
			} else {
				return 0;
			}
			$count = 0;
			foreach ( $tokens as $token ) {
				$id = is_array( $token ) ? $token[0] : ( is_object( $token ) ? $token->id : 0 );
				if ( T_WHITESPACE !== $id && T_COMMENT !== $id && T_DOC_COMMENT !== $id ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * The first node nested deeper than $limit, found with a stack instead of
		 * recursion, or null.
		 *
		 * @param array $stmts Syntax tree.
		 * @param int   $limit Deepest allowed.
		 * @return \UiChemy\Deps\PhpParser\Node|null
		 */
		private static function deeper_than( array $stmts, $limit ) {
			$stack = array();
			foreach ( $stmts as $stmt ) {
				$stack[] = array( $stmt, 1 );
			}
			while ( $stack ) {
				list( $node, $depth ) = array_pop( $stack );
				if ( ! $node instanceof \UiChemy\Deps\PhpParser\Node ) {
					continue;
				}
				if ( $depth > $limit ) {
					return $node;
				}
				foreach ( self::children( $node ) as $child ) {
					$stack[] = array( $child, $depth + 1 );
				}
			}
			return null;
		}

		/**
		 * Take a tree apart node by node, so PHP frees each one on its own
		 * instead of recursing through every level at once.
		 *
		 * @param array $stmts Syntax tree.
		 */
		private static function dismantle( array $stmts ) {
			$all   = array();
			$stack = $stmts;
			while ( $stack ) {
				$node = array_pop( $stack );
				if ( ! $node instanceof \UiChemy\Deps\PhpParser\Node ) {
					continue;
				}
				$all[] = $node;
				foreach ( self::children( $node ) as $child ) {
					$stack[] = $child;
				}
			}
			foreach ( $all as $node ) {
				foreach ( $node->getSubNodeNames() as $name ) {
					unset( $node->$name );
				}
			}
		}

		/**
		 * @param \UiChemy\Deps\PhpParser\Node $node Node.
		 * @return array<int,mixed> Its child nodes (and anything in its sub-node arrays).
		 */
		private static function children( $node ) {
			$children = array();
			foreach ( $node->getSubNodeNames() as $name ) {
				$sub = isset( $node->$name ) ? $node->$name : null;
				if ( is_array( $sub ) ) {
					foreach ( $sub as $item ) {
						$children[] = $item;
					}
				} elseif ( null !== $sub ) {
					$children[] = $sub;
				}
			}
			return $children;
		}

		/**
		 * The script as the parser sees it. A leading <?php, declare, namespace
		 * or use line (a model adds them out of habit) is blanked, not removed,
		 * so every line number stays the model's own.
		 *
		 * @param string $code Script body.
		 * @return string
		 */
		private static function assemble( $code ) {
			$blank = static function ( $match ) {
				return str_repeat( "\n", substr_count( $match[0], "\n" ) );
			};
			$code  = (string) preg_replace_callback( '/\A\s*<\?php\b/', $blank, $code );
			$code  = (string) preg_replace_callback(
				'/\A(?:\s*(?:declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;|namespace\s+UiChemy\\\\Script\s*;|use\s+UiChemy\\\\Api\\\\[^;]*;))+/i',
				$blank,
				$code
			);
			return '<?php ' . $code . "\n";
		}

		/**
		 * A script's return value as plain data: what json_encode makes of it.
		 *
		 * @param mixed $value Return value.
		 * @return mixed
		 */
		private static function as_data( $value ) {
			$json = wp_json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			return false === $json ? null : json_decode( $json, true );
		}

		/**
		 * A wrong UiChemy call is a "type" error, whether it is caught before
		 * the run or as the call is made; anything else a script throws is "runtime".
		 *
		 * @param string $message Exception message.
		 * @return string
		 */
		private static function kind_of( $message ) {
			$pattern = '/^(Parameter \$\w+ of static method UiChemy\\\\Api\\\\|Call to an undefined static method UiChemy\\\\Api\\\\|Unknown named parameter \$\w+ in static method UiChemy\\\\Api\\\\|Static method UiChemy\\\\Api\\\\|Named parameter \$\w+ overwrites)/';
			return preg_match( $pattern, $message ) ? 'type' : 'runtime';
		}

		/**
		 * @param string $kind    Error kind.
		 * @param string $message Message.
		 * @param int    $line    Script line.
		 * @param array  $usage   Timings so far.
		 * @param array  $errors  Every error found, when there is more than one.
		 * @param string $logs     Output echoed before the error.
		 * @param array  $warnings Reads of things that did not exist, before the error.
		 * @return array
		 */
		private static function error( $kind, $message, $line, array $usage, array $errors = array(), $logs = '', array $warnings = array() ) {
			if ( 'refused' === $kind ) {
				self::log_refusal( $message, (int) $line );
			}
			return array(
				'type'    => 'error',
				'kind'    => $kind,
				'message' => $message,
				'line'    => (int) $line,
				'errors'  => $errors ? $errors : array(
					array(
						'line'    => (int) $line,
						'message' => $message,
					),
				),
				'logs'     => $logs,
				'warnings' => $warnings,
				'usage'    => $usage,
			);
		}

		/**
		 * Development only: write each refusal, with the line of the script it
		 * names, to the PHP error log, so what models actually write can decide
		 * what the allowlist takes next. Off unless both WP_DEBUG and
		 * UICHEMY_CODE_MODE_LOG_REFUSALS are true in wp-config.php; it never
		 * leaves the server, and a site that has not opted in logs nothing.
		 *
		 * @param string $message Refusal.
		 * @param int    $line    Script line, 0 when none.
		 */
		private static function log_refusal( $message, $line ) {
			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'UICHEMY_CODE_MODE_LOG_REFUSALS' ) && true === UICHEMY_CODE_MODE_LOG_REFUSALS ) ) {
				return;
			}
			$lines = explode( "\n", self::$script );
			$text  = $line > 0 && isset( $lines[ $line - 1 ] ) ? substr( trim( $lines[ $line - 1 ] ), 0, 300 ) : '';
			error_log( sprintf( '[UiChemy Code Mode] refused (line %d): %s | %s', $line, $message, $text ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- development opt-in only, see above.
		}

		/**
		 * @param float $began microtime( true ) when it started.
		 * @return float Milliseconds since.
		 */
		private static function ms( $began ) {
			return round( ( microtime( true ) - $began ) * 1000, 2 );
		}
	}
}
