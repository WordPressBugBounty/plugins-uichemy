<?php
/**
 * Runs a Code Mode script by walking its syntax tree.
 *
 * Nothing in a script is ever handed to PHP's eval, include or a second PHP
 * process: each node is carried out here, and only the nodes the Guard allows
 * are implemented. A UiChemy call becomes one ability call through $on_call;
 * a function from the allowlist is a plain PHP call; anything else is refused.
 * So a script cannot reach a file, the network or the database however it is
 * written: those are not things this interpreter knows how to do.
 *
 * It also keeps the run inside its budget: steps, script time (the time spent
 * inside ability calls is not charged), memory, string size, nesting depth.
 * Hitting one stops the run with a limit error the script cannot catch.
 *
 * Values are ordinary PHP values, so arrays, strings and numbers behave
 * exactly as PHP's do. Variables live in an array passed by reference through
 * every call, which makes PHP's own references do the work for by-reference
 * foreach, closure `use (&$x)` and `$a = &$b`.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

use UiChemy\Deps\PhpParser\Node;
use UiChemy\Deps\PhpParser\Node\Expr;
use UiChemy\Deps\PhpParser\Node\Stmt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Interpreter' ) ) {

	/**
	 * Tree-walking interpreter for the Code Mode subset of PHP.
	 */
	class UiChemy_Code_Mode_Interpreter {

		/** Statements, loop turns and calls a run may take. */
		const MAX_STEPS = 5000000;

		/** Nested function and closure calls. */
		const MAX_DEPTH = 128;

		/**
		 * Native functions calling back into the script (array_map, usort,
		 * preg_replace_callback ...) nested inside each other. Each level is real
		 * recursion on PHP's C stack, which can be small (threads, musl), so it has
		 * its own, lower cap.
		 */
		const MAX_CALLBACK_DEPTH = 64;

		/** Bytes of echo output kept: logs go back to the model, so they stay small. */
		const MAX_LOG = 32768;

		/** Longest string a script may build or a function may return. */
		const MAX_STRING = 16777216;

		/** Most elements range() may make. */
		const MAX_RANGE = 1000000;

		/** Deepest a value may nest. PHP frees and encodes nested arrays recursively, so far deeper crashes the worker. */
		const MAX_NEST = 512;

		/** Most values one value may hold, counting every level, so shared copies count too. */
		const MAX_VALUES = 1000000;

		/**
		 * References taken into arrays or properties ($p = &$p['child']) per run.
		 * Each can step one level deeper without building anything, so this keeps a
		 * structure grown that way well short of the nesting that crashes PHP.
		 */
		const MAX_ALIASES = 4096;

		/** Longest chain of previous exceptions a script may build. PHP frees the chain recursively. */
		const MAX_PREVIOUS = 64;

		/** Warnings kept for the answer. */
		const MAX_WARNINGS = 10;

		const SIG_RETURN   = 1;
		const SIG_BREAK    = 2;
		const SIG_CONTINUE = 3;

		/** @var UiChemy_Code_Mode_Binder */
		private $binder;

		/** @var callable Answers one ability call: ( $id, $args ) => result array. */
		private $on_call;

		/** @var float Milliseconds of script time allowed. */
		private $script_ms;

		/** @var array<string,Stmt\Function_> Functions the script declares, lowercase. */
		private $functions = array();

		/** @var int Native calls that call back into the script, nested now. */
		private $callback_depth = 0;

		/** @var array<string,true> Every function the script declares anywhere, lowercase. */
		private $declarable = array();

		/** @var array{0:int,1:string} json_last_error() and its message after the script's own last json_* call. */
		private $json_state = array( 0, 'No error' );

		/** @var array{0:int,1:string} The same for preg_*. */
		private $preg_state = array( 0, 'No error' );

		/** @var string */
		private $log = '';

		/** @var int */
		private $steps = 0;

		/** @var int */
		private $depth = 0;

		/** @var int */
		private $line = 0;

		/** @var float */
		private $started = 0.0;

		/** @var float Seconds spent inside ability calls. */
		private $paused = 0.0;

		/** @var int */
		private $mem_cap = 0;

		/** @var int */
		private $mem_mb = 64;

		/** @var string */
		private $function_name = '';

		/** @var bool True while script code runs, false inside an ability call. */
		private $in_script = false;

		/** @var callable|null */
		private $previous_handler = null;

		/** @var \SplObjectStorage Script line per exception. */
		private $lines;

		/** @var array<string,array<int,true>> By-reference parameter positions per function. */
		private $by_ref = array();

		/** @var UiChemy_Code_Mode_Random The run's own seeded generator. */
		private $random;

		/** @var int Peak memory the run added, in bytes. */
		private $peak = 0;

		/** @var array<string,string> Reads of things that do not exist, "line|message" => text. */
		private $warnings = array();

		/** @var int Warnings past MAX_WARNINGS. */
		private $more_warnings = 0;

		/** @var bool Whether echo output was cut at MAX_LOG. */
		private $log_cut = false;

		/** @var \SplObjectStorage Exceptions already stripped by sanitize(). */
		private $clean;

		/** @var array<int,array<string,true>> Per call depth, the variables that are references into an array or object. */
		private $aliases = array();

		/** @var int References taken into arrays or properties so far. */
		private $alias_count = 0;

		/** @var int Longest string this run may build: MAX_STRING, or less when memory is short. */
		private $max_string = self::MAX_STRING;

		/** @var int Bytes of JSON the answer may take, 0 for no limit. */
		private $max_result = 0;

		/** @var float Milliseconds the whole run may take, ability calls included, 0 for no limit. */
		private $wall_ms = 0.0;

		/** @var array<int,array<int,string>> Per arrow function node, the variables its body reads. */
		private $arrow_vars = array();

		/** @var array<int,true> Closures on the path check_shape() is walking, so a closure that holds itself ends. */
		private $walking = array();

		/**
		 * @param UiChemy_Code_Mode_Binder $binder    Method lookup.
		 * @param callable                 $on_call   ( $id, $args ) => { ok, value|message } | { limit }.
		 * @param float                    $script_ms Script time allowed, in milliseconds.
		 * @param array                    $options   { result_bytes?: int, wall_ms?: float }.
		 */
		public function __construct( UiChemy_Code_Mode_Binder $binder, $on_call, $script_ms, array $options = array() ) {
			$this->binder     = $binder;
			$this->on_call    = $on_call;
			$this->script_ms  = (float) $script_ms;
			$this->max_result = isset( $options['result_bytes'] ) ? (int) $options['result_bytes'] : 0;
			$this->wall_ms    = isset( $options['wall_ms'] ) ? (float) $options['wall_ms'] : 0.0;
			$this->random     = new UiChemy_Code_Mode_Random( isset( $options['seed'] ) ? $options['seed'] : null );
			$this->lines      = new \SplObjectStorage();
			$this->clean      = new \SplObjectStorage();
		}

		/**
		 * Run a parsed script.
		 *
		 * @param array $stmts Syntax tree.
		 * @return array{output:mixed, logs:string, steps:int, peak_kb:int}
		 * @throws UiChemy_Code_Mode_Halt When a limit is hit or a construct is refused.
		 * @throws \Throwable             The script's own uncaught exception.
		 */
		public function run( array $stmts ) {
			$this->started = microtime( true );
			$this->setup_memory();

			// As in PHP: a function declared at the top level exists from the start; one
			// declared inside an if, a loop or another function exists once that
			// statement has run (so if (!function_exists('x')) { function x() ... } works).
			$finder = new \UiChemy\Deps\PhpParser\NodeFinder();
			foreach ( $finder->findInstanceOf( $stmts, Stmt\Function_::class ) as $function ) {
				$this->declarable[ strtolower( $function->name->toString() ) ] = true;
			}
			foreach ( $stmts as $stmt ) {
				if ( $stmt instanceof Stmt\Function_ ) {
					$this->functions[ strtolower( $stmt->name->toString() ) ] = $stmt;
				}
			}

			$this->previous_handler = set_error_handler(
				function ( $no, $message, $file = '', $line = 0 ) {
					return $this->handle_error( $no, $message, $file, $line );
				}
			);
			$this->in_script = true;

			try {
				$vars   = array();
				$signal = $this->exec_stmts( $stmts, $vars );
				$output = ( null !== $signal && self::SIG_RETURN === $signal[0] ) ? $signal[1] : null;
				// The answer is encoded next, in one step no limit can stop.
				$this->check_shape( $output );
				$this->check_answer( $output );
			} catch ( \Throwable $e ) {
				if ( ! $e instanceof UiChemy_Code_Mode_Halt && ! isset( $this->lines[ $e ] ) ) {
					$this->lines[ $e ] = $this->line;
				}
				throw $e;
			} finally {
				$this->in_script = false;
				restore_error_handler();
			}

			return array(
				'output'  => $output,
				'logs'    => $this->log,
				'steps'   => $this->steps,
				'peak_kb'  => (int) round( $this->peak / 1024 ),
				'warnings' => $this->warnings(),
			);
		}

		/**
		 * Reads of things that did not exist (an undefined variable, a missing
		 * array key or property), as "line N: message". PHP warns about these
		 * and so does the interpreter: a silent null is how a script with a
		 * typo returns a wrong answer that looks right.
		 *
		 * @return array<int,string>
		 */
		public function warnings() {
			$list = array_values( $this->warnings );
			if ( $this->more_warnings > 0 ) {
				$list[] = sprintf( '... and %d more.', $this->more_warnings );
			}
			return $list;
		}

		/**
		 * @param int    $line    Script line.
		 * @param string $message What was read.
		 */
		private function warn( $line, $message ) {
			$key = $line . '|' . $message;
			if ( isset( $this->warnings[ $key ] ) ) {
				return;
			}
			if ( count( $this->warnings ) >= self::MAX_WARNINGS ) {
				++$this->more_warnings;
				return;
			}
			$this->warnings[ $key ] = sprintf( 'line %d: %s', $line, $message );
		}

		/**
		 * The real key closest to one that is missing, when the miss looks like
		 * a typo: same letters in another case, or one or two letters off.
		 *
		 * @param mixed $key  Missing key.
		 * @param array $base The array.
		 * @return string|null
		 */
		private static function close_key( $key, array $base ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return null;
			}
			$best     = null;
			$distance = 3;
			$seen     = 0;
			foreach ( $base as $candidate => $unused ) {
				if ( ++$seen > 500 ) {
					break;
				}
				if ( ! is_string( $candidate ) ) {
					continue;
				}
				if ( 0 === strcasecmp( $candidate, $key ) ) {
					return $candidate;
				}
				if ( strlen( $key ) >= 3 ) {
					$d = levenshtein( strtolower( $key ), strtolower( $candidate ) );
					if ( $d < $distance ) {
						$distance = $d;
						$best     = $candidate;
					}
				}
			}
			return $best;
		}

		/**
		 * @param mixed $key  Missing key.
		 * @param array $base The array.
		 * @return string
		 */
		private static function missing_key( $key, array $base ) {
			$message = is_int( $key ) ? sprintf( 'Undefined array key %d', $key ) : sprintf( 'Undefined array key "%s"', $key );
			$close   = self::close_key( $key, $base );
			return null === $close ? $message : sprintf( '%s. Did you mean "%s"?', $message, $close );
		}

		/**
		 * The seed to hand back, or null when the script drew no random number.
		 *
		 * @return string|null
		 */
		public function seed_used() {
			return $this->random->seed_used();
		}

		/**
		 * Output echoed so far, for a run that stopped early.
		 *
		 * @return string
		 */
		public function logs() {
			return $this->log;
		}

		/**
		 * The script line an exception belongs to.
		 *
		 * @param \Throwable $e Exception.
		 * @return int
		 */
		public function line_of( $e ) {
			if ( $e instanceof UiChemy_Code_Mode_Halt ) {
				return $e->script_line;
			}
			return isset( $this->lines[ $e ] ) ? (int) $this->lines[ $e ] : $this->line;
		}

		/**
		 * Warnings and notices inside a script are swallowed, as a script
		 * running without a display would see them; anywhere else they go on to
		 * whatever handler was there before.
		 *
		 * @param int    $no      Level.
		 * @param string $message Message.
		 * @param string $file    File.
		 * @param int    $line    Line.
		 * @return bool
		 */
		public function handle_error( $no, $message, $file = '', $line = 0 ) {
			if ( $this->in_script ) {
				return true;
			}
			if ( is_callable( $this->previous_handler ) ) {
				return (bool) call_user_func( $this->previous_handler, $no, $message, $file, $line );
			}
			return false;
		}

		// ---------------------------------------------------------------- limits.

		/**
		 * Room for the script: at most UiChemy_Code_Mode::MEMORY_MB, and no more than half of what
		 * this request has left.
		 */
		private function setup_memory() {
			$base  = memory_get_usage();
			$limit = (string) ini_get( 'memory_limit' );
			$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $limit ) : (int) $limit;
			$room  = $bytes > 0 ? max( 0, $bytes - $base ) : PHP_INT_MAX;

			$budget = (int) min( ( class_exists( 'UiChemy_Code_Mode' ) ? UiChemy_Code_Mode::MEMORY_MB : 128 ) * 1048576, $room * 0.5 );
			$budget = max( $budget, 2 * 1048576 );

			$this->mem_cap = $base + $budget;
			$this->mem_mb  = max( 1, (int) round( $budget / 1048576 ) );
			$this->peak    = 0;
			// One value may not be larger than the room the script has, or building it is a PHP fatal.
			$this->max_string = (int) min( self::MAX_STRING, $budget );
		}

		/**
		 * Count a step; every so often check the clock and memory.
		 */
		private function tick() {
			if ( ++$this->steps > self::MAX_STEPS ) {
				$this->halt( 'limit', sprintf( 'The script ran more than %s steps. It looks like an endless loop.', number_format_i18n( self::MAX_STEPS ) ) );
			}
			if ( 0 === ( $this->steps & 255 ) ) {
				$this->check_time();
				$this->check_memory();
			}
		}

		private function check_time() {
			$elapsed = microtime( true ) - $this->started;
			if ( ( $elapsed - $this->paused ) * 1000 > $this->script_ms ) {
				$this->halt( 'limit', sprintf( 'The script used more than %d s of script time and was stopped.', (int) round( $this->script_ms / 1000 ) ) );
			}
			// Script time and ability calls together, so the run ends before PHP's max_execution_time does.
			if ( $this->wall_ms > 0 && $elapsed * 1000 > $this->wall_ms ) {
				$this->halt( 'limit', sprintf( 'The run took longer than %d s and was stopped.', (int) round( $this->wall_ms / 1000 ) ) );
			}
		}

		private function check_memory() {
			$used = memory_get_usage();
			if ( $used > $this->mem_cap ) {
				$this->halt( 'limit', sprintf( 'The script ran out of memory (%d MB).', $this->mem_mb ) );
			}
			$this->peak = max( $this->peak, $used - ( $this->mem_cap - $this->mem_mb * 1048576 ) );
		}

		/**
		 * @param int $bytes Size a result would have.
		 */
		private function check_size( $bytes ) {
			if ( $bytes > $this->max_string ) {
				$this->halt( 'limit', sprintf( 'The script would build a value over %d MB, which is not allowed.', max( 1, (int) round( $this->max_string / 1048576 ) ) ) );
			}
		}

		/**
		 * Refuse a value nested deeper than MAX_NEST or holding more than
		 * MAX_VALUES values in all.
		 *
		 * PHP shares arrays instead of copying them, so [ $a, $a ] doubles what a
		 * value holds at no cost in memory. Anything that then walks it - json_encode,
		 * count( ..., COUNT_RECURSIVE ), a comparison, the answer itself - runs out of
		 * time or memory inside one native step, where no other limit can stop it,
		 * and a value nested tens of thousands deep crashes PHP when it is freed.
		 * Checked wherever a value can grow and before anything walks it.
		 *
		 * @param mixed $value Value.
		 */
		private function check_shape( $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$count         = 0;
				$this->walking = array();
				$this->walk_shape( $value, 1, $count );
			}
		}

		/**
		 * A closure the script made holds the variables it captured, and a chain
		 * of closures each holding the one before is nested like any array, so
		 * it is walked the same way. A closure that holds itself (use (&$f)) is
		 * walked once.
		 *
		 * @param array|object $value Value.
		 * @param int          $depth How deep it sits.
		 * @param int          $count Values seen so far.
		 */
		private function walk_shape( $value, $depth, &$count ) {
			$closure = null;
			if ( is_object( $value ) ) {
				if ( $value instanceof \Throwable ) {
					return;
				}
				if ( $value instanceof \Closure ) {
					$id = spl_object_id( $value );
					if ( isset( $this->walking[ $id ] ) ) {
						return;
					}
					$statics = ( new \ReflectionFunction( $value ) )->getStaticVariables();
					$held    = isset( $statics['captured'] ) && is_array( $statics['captured'] ) ? $statics['captured'] : array();
					if ( isset( $statics['cells'] ) && $statics['cells'] instanceof \stdClass ) {
						$held = array_merge( $held, get_object_vars( $statics['cells'] ) );
					}
					if ( ! $held ) {
						return;
					}
					$this->walking[ $id ] = true;
					$closure              = $id;
					$value                = $held;
				} else {
					$value = get_object_vars( $value );
				}
			}
			if ( $depth > self::MAX_NEST ) {
				$this->halt( 'limit', sprintf( 'The script built a value nested more than %d levels deep, which is not allowed.', self::MAX_NEST ) );
			}
			foreach ( $value as $item ) {
				$this->count_value( $count );
				if ( is_array( $item ) || is_object( $item ) ) {
					$this->walk_shape( $item, $depth + 1, $count );
				}
			}
			if ( null !== $closure ) {
				unset( $this->walking[ $closure ] );
			}
		}

		/**
		 * @param int $count Values seen so far, by reference.
		 */
		private function count_value( &$count ) {
			if ( ++$count > self::MAX_VALUES ) {
				$this->halt( 'limit', sprintf( 'The script built a value holding more than %s values in all, which is not allowed. Keep or return less, e.g. with array_slice() or count().', number_format_i18n( self::MAX_VALUES ) ) );
			}
			if ( 0 === ( $count & 8191 ) ) {
				$this->check_time();
			}
		}

		/**
		 * Refuse a call that would make more than MAX_VALUES elements in one step.
		 *
		 * @param int|float $values Elements it would make.
		 */
		private function check_values( $values ) {
			if ( $values > self::MAX_VALUES ) {
				$this->halt( 'limit', sprintf( 'The script would build a value holding more than %s values in one call, which is not allowed. Work on less at a time, e.g. with substr() or array_slice().', number_format_i18n( self::MAX_VALUES ) ) );
			}
		}

		/**
		 * The answer is encoded in one step after the run. Its JSON is never
		 * shorter than its strings and keys, so when those alone are over the
		 * limit it is refused here, before a value made of one 16 MB string
		 * repeated a hundred times is turned into 1.6 GB of JSON.
		 *
		 * @param mixed $value The script's return value.
		 */
		private function check_answer( $value ) {
			if ( $this->max_result <= 0 ) {
				return;
			}
			$bytes = 0;
			$this->answer_bytes( $value, $bytes );
		}

		/**
		 * @param mixed $value Value.
		 * @param int   $bytes Fewest bytes its JSON can take, so far, by reference.
		 */
		private function answer_bytes( $value, &$bytes ) {
			if ( is_array( $value ) || ( is_object( $value ) && ! $value instanceof \Closure && ! $value instanceof \Throwable ) ) {
				$bytes += 2;
				foreach ( ( is_array( $value ) ? $value : get_object_vars( $value ) ) as $key => $item ) {
					$bytes += 1 + ( is_string( $key ) ? strlen( $key ) : 0 );
					$this->answer_bytes( $item, $bytes );
				}
			} elseif ( is_string( $value ) ) {
				$bytes += strlen( $value ) + 2;
			} else {
				$bytes += null === $value || is_bool( $value ) ? 4 : ( is_object( $value ) ? 2 : 1 );
			}
			if ( $bytes > $this->max_result ) {
				$this->halt( 'limit', sprintf( 'The script returned more than %d KB, the most allowed. Filter, count or slice inside the script and return only what you need.', (int) ( $this->max_result / 1024 ) ) );
			}
		}

		/**
		 * Most bytes json_encode() can make of a value, checked against
		 * MAX_STRING as it is counted. Each string is counted with what its
		 * escapes add, so a 16 MB string of control characters (six bytes
		 * each in JSON) or the same string repeated is refused before encoding.
		 *
		 * @param mixed $value Value (its shape already checked).
		 * @param int   $flags json_encode() flags.
		 * @return int
		 */
		private function json_bound( $value, $flags ) {
			$bytes = 0;
			$this->walk_json( $value, 1, (int) $flags, $bytes );
			return $bytes;
		}

		/**
		 * @param mixed $value Value.
		 * @param int   $depth How deep it sits.
		 * @param int   $flags json_encode() flags.
		 * @param int   $bytes Bytes so far, by reference.
		 */
		private function walk_json( $value, $depth, $flags, &$bytes ) {
			if ( is_string( $value ) ) {
				$bytes += $this->json_string_bound( $value, $flags );
			} elseif ( is_array( $value ) || ( is_object( $value ) && ! $value instanceof \Closure && ! $value instanceof \Throwable ) ) {
				// Brackets, and per item a comma, a newline and the indent JSON_PRETTY_PRINT adds.
				$indent = ( $flags & JSON_PRETTY_PRINT ) ? $depth * 4 + 2 : 0;
				$bytes += 2 + $indent;
				foreach ( ( is_array( $value ) ? $value : get_object_vars( $value ) ) as $key => $item ) {
					$bytes += 4 + $indent + $this->json_string_bound( (string) $key, $flags );
					$this->walk_json( $item, $depth + 1, $flags, $bytes );
				}
			} else {
				// Numbers (a float can take 25 bytes), true, false, null and {}.
				$bytes += 25;
			}
			$this->check_size( $bytes );
		}

		/**
		 * @param string $text  String.
		 * @param int    $flags json_encode() flags.
		 * @return int Most bytes it takes in JSON, quotes included.
		 */
		private function json_string_bound( $text, $flags ) {
			$length = strlen( $text );
			if ( $length * 6 + 2 <= 4096 ) {
				return $length * 6 + 2;
			}
			if ( $length > self::MAX_STRING ) {
				return $length + 2;
			}
			// \u00XX for control characters and, with the JSON_HEX_* flags, < > & ' and ";
			// two bytes for " \ and /; \uXXXX (up to three bytes per byte) for anything not ASCII.
			$six   = (int) preg_match_all( '/[\x00-\x1f<>&\']/', $text );
			$two   = (int) preg_match_all( '/["\\\\\/]/', $text );
			$multi = ( $flags & JSON_UNESCAPED_UNICODE ) ? 0 : (int) preg_match_all( '/[\x80-\xff]/', $text );
			return $length + 5 * ( $six + $two ) + 2 * $multi + 2;
		}

		/**
		 * Comparing two arrays (or objects) walks both, so check them first.
		 *
		 * @param mixed $left  Left value.
		 * @param mixed $right Right value.
		 */
		private function check_compare( $left, $right ) {
			if ( ( is_array( $left ) || is_object( $left ) ) && ( is_array( $right ) || is_object( $right ) ) ) {
				$this->check_shape( $left );
				$this->check_shape( $right );
			}
		}

		/**
		 * Strip what an exception carries about the server - the file and line
		 * PHP saw, the stack trace through the interpreter, absolute paths in the
		 * message - before a script can hold it. Otherwise (string) $e, (array) $e,
		 * sprintf( '%s', $e ) or strval( $e ) hand all of it back.
		 *
		 * @param \Throwable $e Exception.
		 * @return \Throwable The same exception.
		 */
		private function sanitize( \Throwable $e ) {
			for ( $each = $e, $hops = 0; null !== $each && $hops < 16; $each = $each->getPrevious(), $hops++ ) {
				if ( isset( $this->clean[ $each ] ) ) {
					break;
				}
				$this->clean[ $each ] = true;

				$base   = $each instanceof \Exception ? \Exception::class : \Error::class;
				$fields = array(
					'message' => self::strip_paths( $each->getMessage() ),
					'file'    => 'script',
					'line'    => $this->line_of( $each ),
					'trace'   => array(),
				);
				foreach ( $fields as $name => $value ) {
					$property = new \ReflectionProperty( $base, $name );
					$property->setAccessible( true );
					$property->setValue( $each, $value );
				}
			}
			return $e;
		}

		/**
		 * @param string $text Message.
		 * @return string The message without the site's absolute paths.
		 */
		private static function strip_paths( $text ) {
			$paths = array_filter( array( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' : '', defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/' : '', ABSPATH ) );
			return str_replace( $paths, '', (string) $text );
		}

		/**
		 * @param string $kind    'limit', 'refused' or 'runtime'.
		 * @param string $message Message.
		 * @throws UiChemy_Code_Mode_Halt Always.
		 */
		private function halt( $kind, $message ) {
			throw new UiChemy_Code_Mode_Halt( $kind, $message, $this->line );
		}

		// ------------------------------------------------------------ statements.

		/**
		 * @param array $stmts Statements.
		 * @param array $vars  Variables, by reference.
		 * @return array|null A control signal ( type, payload ), or null.
		 */
		private function exec_stmts( array $stmts, array &$vars ) {
			foreach ( $stmts as $stmt ) {
				$signal = $this->run_statement( $stmt, $vars );
				if ( null !== $signal ) {
					return $signal;
				}
			}
			return null;
		}

		/**
		 * @param Node  $n    Statement.
		 * @param array $vars Variables, by reference.
		 * @return array|null
		 */
		private function run_statement( Node $n, array &$vars ) {
			$this->line = $n->getStartLine();
			$this->tick();

			switch ( $n->getType() ) {
				case 'Stmt_Expression':
					$this->evaluate( $n->expr, $vars );
					return null;

				case 'Stmt_Echo':
					foreach ( $n->exprs as $expr ) {
						$this->echo_( $this->to_string( $this->evaluate( $expr, $vars ) ) );
					}
					return null;

				case 'Stmt_Return':
					return array( self::SIG_RETURN, null === $n->expr ? null : $this->evaluate( $n->expr, $vars ) );

				case 'Stmt_If':
					if ( $this->evaluate( $n->cond, $vars ) ) {
						return $this->exec_stmts( $n->stmts, $vars );
					}
					foreach ( $n->elseifs as $elseif ) {
						if ( $this->evaluate( $elseif->cond, $vars ) ) {
							return $this->exec_stmts( $elseif->stmts, $vars );
						}
					}
					return null === $n->else ? null : $this->exec_stmts( $n->else->stmts, $vars );

				case 'Stmt_While':
					while ( $this->evaluate( $n->cond, $vars ) ) {
						$this->tick();
						$signal = $this->exec_stmts( $n->stmts, $vars );
						if ( null !== $signal ) {
							if ( self::SIG_RETURN === $signal[0] || $signal[1] > 1 ) {
								return $this->outward( $signal );
							}
							if ( self::SIG_BREAK === $signal[0] ) {
								break;
							}
						}
					}
					return null;

				case 'Stmt_Do':
					do {
						$this->tick();
						$signal = $this->exec_stmts( $n->stmts, $vars );
						if ( null !== $signal ) {
							if ( self::SIG_RETURN === $signal[0] || $signal[1] > 1 ) {
								return $this->outward( $signal );
							}
							if ( self::SIG_BREAK === $signal[0] ) {
								break;
							}
						}
					} while ( $this->evaluate( $n->cond, $vars ) );
					return null;

				case 'Stmt_For':
					foreach ( $n->init as $expr ) {
						$this->evaluate( $expr, $vars );
					}
					while ( true ) {
						$go = true;
						foreach ( $n->cond as $expr ) {
							$go = (bool) $this->evaluate( $expr, $vars );
						}
						if ( ! $go ) {
							break;
						}
						$this->tick();
						$signal = $this->exec_stmts( $n->stmts, $vars );
						if ( null !== $signal ) {
							if ( self::SIG_RETURN === $signal[0] || $signal[1] > 1 ) {
								return $this->outward( $signal );
							}
							if ( self::SIG_BREAK === $signal[0] ) {
								break;
							}
						}
						foreach ( $n->loop as $expr ) {
							$this->evaluate( $expr, $vars );
						}
					}
					return null;

				case 'Stmt_Foreach':
					return $this->exec_foreach( $n, $vars );

				case 'Stmt_Switch':
					return $this->exec_switch( $n, $vars );

				case 'Stmt_Break':
					return array( self::SIG_BREAK, null === $n->num ? 1 : max( 1, (int) $this->evaluate( $n->num, $vars ) ) );

				case 'Stmt_Continue':
					return array( self::SIG_CONTINUE, null === $n->num ? 1 : max( 1, (int) $this->evaluate( $n->num, $vars ) ) );

				case 'Stmt_TryCatch':
					return $this->exec_try( $n, $vars );

				case 'Stmt_Unset':
					foreach ( $n->vars as $target ) {
						$this->unset_( $target, $vars );
					}
					return null;

				case 'Stmt_Function':
					$declared = strtolower( $n->name->toString() );
					if ( isset( $this->functions[ $declared ] ) && $this->functions[ $declared ] === $n ) {
						return null; // A top-level one, declared before the script started.
					}
					if ( isset( $this->functions[ $declared ] ) || in_array( $declared, UiChemy_Code_Mode_Allowlist::functions(), true ) ) {
						throw new \Error( sprintf( 'Cannot redeclare %s()', $n->name->toString() ) );
					}
					$this->functions[ $declared ] = $n;
					return null;

				case 'Stmt_Nop':
					return null;
			}

			$this->halt( 'refused', sprintf( 'This syntax is not supported: %s.', $n->getType() ) );
		}

		/**
		 * A break or continue that targets a loop further out: one level done.
		 *
		 * @param array $signal Signal.
		 * @return array
		 */
		private function outward( array $signal ) {
			if ( self::SIG_RETURN === $signal[0] ) {
				return $signal;
			}
			return array( $signal[0], $signal[1] - 1 );
		}

		/**
		 * @param Stmt\Foreach_ $n    Statement.
		 * @param array         $vars Variables.
		 * @return array|null
		 */
		private function exec_foreach( Stmt\Foreach_ $n, array &$vars ) {
			if ( $n->byRef ) {
				if ( ! $n->valueVar instanceof Expr\Variable || ! is_string( $n->valueVar->name ) ) {
					$this->halt( 'refused', 'A by-reference foreach needs a plain variable, e.g. foreach ($rows as &$row).' );
				}
				$subject = &$this->lvalue( $n->expr, $vars, 'ref' );
				// An array, or a stdClass (its properties). A closure or exception has nothing to iterate.
				if ( ! is_array( $subject ) && ! $subject instanceof \stdClass ) {
					return null;
				}
				$name = $n->valueVar->name;
				// The loop variable is a reference into $subject while the loop runs,
				// and is unset when it ends (PHP keeps it, and the manual says to unset
				// it): a reference left pointing into an array could be carried a level
				// deeper on every pass of an outer loop.
				$this->alias( $name, true );
				try {
					// PHP's own by-reference loop: an element added while it runs is visited,
					// one removed is skipped, and an object's keys stay strings.
					foreach ( $subject as $key => &$item ) {
						$this->tick();
						$vars[ $name ] = &$item;
						if ( null !== $n->keyVar ) {
							$this->assign_to( $n->keyVar, $key, $vars );
						}
						$signal = $this->exec_stmts( $n->stmts, $vars );
						if ( null !== $signal ) {
							if ( self::SIG_RETURN === $signal[0] || $signal[1] > 1 ) {
								return $this->outward( $signal );
							}
							if ( self::SIG_BREAK === $signal[0] ) {
								break;
							}
						}
					}
				} finally {
					unset( $vars[ $name ], $item );
					$this->alias( $name, false );
				}
				return null;
			}

			$subject = $this->evaluate( $n->expr, $vars );
			// An object is iterated by PHP itself, from outside it: its public properties,
			// with string keys ("0", not 0), as a script's foreach would see them.
			if ( ! is_array( $subject ) && ! ( is_object( $subject ) && ! $subject instanceof \Closure ) ) {
				return null;
			}

			foreach ( $subject as $key => $value ) {
				$this->tick();
				if ( null !== $n->keyVar ) {
					$this->assign_to( $n->keyVar, $key, $vars );
				}
				$this->assign_to( $n->valueVar, $value, $vars );
				$signal = $this->exec_stmts( $n->stmts, $vars );
				if ( null !== $signal ) {
					if ( self::SIG_RETURN === $signal[0] || $signal[1] > 1 ) {
						return $this->outward( $signal );
					}
					if ( self::SIG_BREAK === $signal[0] ) {
						break;
					}
				}
			}
			return null;
		}

		/**
		 * @param Stmt\Switch_ $n    Statement.
		 * @param array        $vars Variables.
		 * @return array|null
		 */
		private function exec_switch( Stmt\Switch_ $n, array &$vars ) {
			$subject = $this->evaluate( $n->cond, $vars );
			$start   = null;
			foreach ( $n->cases as $index => $case ) {
				if ( null === $case->cond ) {
					continue;
				}
				$value = $this->evaluate( $case->cond, $vars );
				$this->check_compare( $subject, $value );
				if ( $subject == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons -- switch compares loosely.
					$start = $index;
					break;
				}
			}
			if ( null === $start ) {
				foreach ( $n->cases as $index => $case ) {
					if ( null === $case->cond ) {
						$start = $index;
						break;
					}
				}
			}
			if ( null === $start ) {
				return null;
			}

			for ( $i = $start, $count = count( $n->cases ); $i < $count; $i++ ) {
				$signal = $this->exec_stmts( $n->cases[ $i ]->stmts, $vars );
				if ( null !== $signal ) {
					if ( self::SIG_RETURN === $signal[0] ) {
						return $signal;
					}
					// continue acts like break inside a switch.
					return $signal[1] > 1 ? $this->outward( $signal ) : null;
				}
			}
			return null;
		}

		/**
		 * @param Stmt\TryCatch $n    Statement.
		 * @param array         $vars Variables.
		 * @return array|null
		 */
		private function exec_try( Stmt\TryCatch $n, array &$vars ) {
			$signal  = null;
			$pending = null;

			try {
				try {
					$signal = $this->exec_stmts( $n->stmts, $vars );
				} catch ( \Throwable $e ) {
					if ( $e instanceof UiChemy_Code_Mode_Halt ) {
						throw $e;
					}
					$catch = $this->matching_catch( $n->catches, $e );
					if ( null === $catch ) {
						throw $e;
					}
					if ( ! isset( $this->lines[ $e ] ) ) {
						$this->lines[ $e ] = $this->line;
					}
					if ( null !== $catch->var ) {
						$vars[ $catch->var->name ] = $this->sanitize( $e );
					}
					$signal = $this->exec_stmts( $catch->stmts, $vars );
				}
			} catch ( \Throwable $e ) {
				if ( $e instanceof UiChemy_Code_Mode_Halt ) {
					throw $e;
				}
				$pending = $e;
			}

			if ( null !== $n->finally ) {
				$finally = $this->exec_stmts( $n->finally->stmts, $vars );
				if ( null !== $finally ) {
					return $finally;
				}
			}
			if ( null !== $pending ) {
				throw $pending;
			}
			return $signal;
		}

		/**
		 * @param array      $catches Catch clauses.
		 * @param \Throwable $e       Exception.
		 * @return Stmt\Catch_|null
		 */
		private function matching_catch( array $catches, $e ) {
			foreach ( $catches as $catch ) {
				foreach ( $catch->types as $type ) {
					$class = UiChemy_Code_Mode_Allowlist::exception_class( $type->toString() );
					if ( null !== $class && $e instanceof $class ) {
						return $catch;
					}
				}
			}
			return null;
		}

		/**
		 * @param Node  $target Variable, element or property.
		 * @param array $vars   Variables.
		 */
		private function unset_( Node $target, array &$vars ) {
			if ( $target instanceof Expr\Variable && is_string( $target->name ) ) {
				unset( $vars[ $target->name ] );
				$this->alias( $target->name, false );
				return;
			}
			if ( $target instanceof Expr\ArrayDimFetch && null !== $target->dim ) {
				// Looked up first without creating anything: unset( $a['x']['y'] ) on a
				// missing path changes nothing, as in PHP.
				$probe = $this->quiet( $target->var, $vars );
				if ( null === $probe ) {
					return;
				}
				if ( false === $probe ) {
					$this->warn( $target->getStartLine(), 'Automatic conversion of false to array is deprecated' );
					return;
				}
				if ( is_string( $probe ) ) {
					throw new \Error( 'Cannot unset string offsets' );
				}
				if ( ! is_array( $probe ) ) {
					throw new \Error( is_object( $probe ) ? sprintf( 'Cannot use object of type %s as array', get_class( $probe ) ) : 'Cannot unset offset in a non-array variable' );
				}
				$base = &$this->lvalue( $target->var, $vars );
				$key  = $this->evaluate( $target->dim, $vars );
				unset( $base[ $key ] );
				return;
			}
			if ( $target instanceof Expr\PropertyFetch ) {
				$object = $this->evaluate( $target->var, $vars );
				$name   = $this->property_name( $target, $vars );
				if ( is_object( $object ) && ! $object instanceof \Throwable ) {
					unset( $object->$name );
				}
				return;
			}
			$this->halt( 'refused', 'That unset() form is not supported.' );
		}

		/**
		 * The property $o->name, $o->{"a-b"} or $o->$p names. A computed name
		 * reaches nothing a written one could not: the same objects, read from
		 * outside them, so only their public properties.
		 *
		 * @param Expr\PropertyFetch|Expr\NullsafePropertyFetch $n    Expression.
		 * @param array                                         $vars Variables.
		 * @return string
		 */
		private function property_name( Node $n, array &$vars ) {
			if ( $n->name instanceof Node\Identifier ) {
				return $n->name->name;
			}
			$name = $this->evaluate( $n->name, $vars );
			if ( is_object( $name ) || is_array( $name ) ) {
				throw new \Error( sprintf( 'Cannot use a value of type %s as a property name', UiChemy_Code_Mode_Binder::describe( $name ) ) );
			}
			$name = (string) $name;
			if ( '' === $name ) {
				throw new \Error( 'Cannot access empty property' );
			}
			if ( "\0" === $name[0] ) {
				throw new \Error( 'Cannot access property starting with "\0"' );
			}
			return $name;
		}

		// ------------------------------------------------------------ expressions.

		/**
		 * @param Node  $n    Expression.
		 * @param array $vars Variables, by reference.
		 * @return mixed
		 */
		private function evaluate( Node $n, array &$vars ) {
			switch ( $n->getType() ) {
				case 'Scalar_String':
				case 'Scalar_Int':
				case 'Scalar_Float':
					return $n->value;

				case 'Scalar_InterpolatedString':
					$out = '';
					foreach ( $n->parts as $part ) {
						$piece = $part instanceof Node\InterpolatedStringPart ? $part->value : $this->to_string( $this->evaluate( $part, $vars ) );
						// Checked part by part: "$s$s$s..." would otherwise be built in full first.
						$this->check_size( strlen( $out ) + strlen( $piece ) );
						$out .= $piece;
					}
					return $out;

				case 'Scalar_MagicConst_Line':
					return $n->getStartLine();

				case 'Scalar_MagicConst_Function':
				case 'Scalar_MagicConst_Method':
					return $this->function_name;

				// A script declares no classes or namespaces.
				case 'Scalar_MagicConst_Class':
				case 'Scalar_MagicConst_Namespace':
					return '';

				case 'Expr_Clone':
					$original = $this->evaluate( $n->expr, $vars );
					if ( ! $original instanceof \stdClass ) {
						throw new \Error( sprintf( 'clone is only allowed on stdClass, not %s', UiChemy_Code_Mode_Binder::describe( $original ) ) );
					}
					return clone $original;

				case 'Expr_Variable':
					if ( is_string( $n->name ) && isset( $vars[ $n->name ] ) ) {
						return $vars[ $n->name ];
					}
					if ( is_string( $n->name ) && ! array_key_exists( $n->name, $vars ) ) {
						$this->warn( $n->getStartLine(), sprintf( 'Undefined variable $%s', $n->name ) );
					}
					return null;

				case 'Expr_Assign':
					$value = $this->evaluate( $n->expr, $vars );
					$this->assign_to( $n->var, $value, $vars );
					return $value;

				case 'Expr_AssignRef':
					return $this->assign_ref( $n, $vars );

				case 'Expr_AssignOp_Coalesce':
					// $x ??= $v is isset( $x ) ? $x : ( $x = $v ): read without creating
					// anything, then an ordinary assignment, with all of its checks.
					$current = $this->quiet( $n->var, $vars );
					if ( null !== $current ) {
						return $current;
					}
					$value = $this->evaluate( $n->expr, $vars );
					$this->check_shape( $value );
					$this->assign_to( $n->var, $value, $vars );
					return $value;

				case 'Expr_AssignOp_Plus':
				case 'Expr_AssignOp_Minus':
				case 'Expr_AssignOp_Mul':
				case 'Expr_AssignOp_Div':
				case 'Expr_AssignOp_Mod':
				case 'Expr_AssignOp_Pow':
				case 'Expr_AssignOp_Concat':
				case 'Expr_AssignOp_BitwiseAnd':
				case 'Expr_AssignOp_BitwiseOr':
				case 'Expr_AssignOp_BitwiseXor':
				case 'Expr_AssignOp_ShiftLeft':
				case 'Expr_AssignOp_ShiftRight':
					$right = $this->evaluate( $n->expr, $vars );
					$ref   = &$this->lvalue( $n->var, $vars, 'assign-op' );
					$ref   = $this->binary( self::assign_op( $n->getType() ), $ref, $right );
					return $ref;

				case 'Expr_BinaryOp_BooleanAnd':
				case 'Expr_BinaryOp_LogicalAnd':
					return $this->evaluate( $n->left, $vars ) && $this->evaluate( $n->right, $vars );

				case 'Expr_BinaryOp_BooleanOr':
				case 'Expr_BinaryOp_LogicalOr':
					return $this->evaluate( $n->left, $vars ) || $this->evaluate( $n->right, $vars );

				case 'Expr_BinaryOp_Coalesce':
					$left = $this->quiet( $n->left, $vars );
					return null !== $left ? $left : $this->evaluate( $n->right, $vars );

				case 'Expr_BinaryOp_Plus':
				case 'Expr_BinaryOp_Minus':
				case 'Expr_BinaryOp_Mul':
				case 'Expr_BinaryOp_Div':
				case 'Expr_BinaryOp_Mod':
				case 'Expr_BinaryOp_Pow':
				case 'Expr_BinaryOp_Concat':
				case 'Expr_BinaryOp_BitwiseAnd':
				case 'Expr_BinaryOp_BitwiseOr':
				case 'Expr_BinaryOp_BitwiseXor':
				case 'Expr_BinaryOp_ShiftLeft':
				case 'Expr_BinaryOp_ShiftRight':
				case 'Expr_BinaryOp_LogicalXor':
				case 'Expr_BinaryOp_Equal':
				case 'Expr_BinaryOp_NotEqual':
				case 'Expr_BinaryOp_Identical':
				case 'Expr_BinaryOp_NotIdentical':
				case 'Expr_BinaryOp_Smaller':
				case 'Expr_BinaryOp_SmallerOrEqual':
				case 'Expr_BinaryOp_Greater':
				case 'Expr_BinaryOp_GreaterOrEqual':
				case 'Expr_BinaryOp_Spaceship':
					return $this->binary( $n->getOperatorSigil(), $this->evaluate( $n->left, $vars ), $this->evaluate( $n->right, $vars ) );

				case 'Expr_BooleanNot':
					return ! $this->evaluate( $n->expr, $vars );

				case 'Expr_UnaryMinus':
					return -$this->evaluate( $n->expr, $vars );

				case 'Expr_UnaryPlus':
					return +$this->evaluate( $n->expr, $vars );

				case 'Expr_BitwiseNot':
					return ~$this->evaluate( $n->expr, $vars );

				case 'Expr_Ternary':
					$condition = $this->evaluate( $n->cond, $vars );
					if ( null === $n->if ) {
						return $condition ? $condition : $this->evaluate( $n->else, $vars );
					}
					return $condition ? $this->evaluate( $n->if, $vars ) : $this->evaluate( $n->else, $vars );

				case 'Expr_PreInc':
					$ref = &$this->lvalue( $n->var, $vars, 'incdec' );
					++$ref;
					return $ref;

				case 'Expr_PreDec':
					$ref = &$this->lvalue( $n->var, $vars, 'incdec' );
					--$ref;
					return $ref;

				case 'Expr_PostInc':
					$ref = &$this->lvalue( $n->var, $vars, 'incdec' );
					$old = $ref;
					++$ref;
					return $old;

				case 'Expr_PostDec':
					$ref = &$this->lvalue( $n->var, $vars, 'incdec' );
					$old = $ref;
					--$ref;
					return $old;

				case 'Expr_Isset':
					foreach ( $n->vars as $target ) {
						if ( null === $this->quiet( $target, $vars ) ) {
							return false;
						}
					}
					return true;

				case 'Expr_Empty':
					return ! $this->quiet( $n->expr, $vars );

				case 'Expr_Cast_Int':
					return (int) $this->evaluate( $n->expr, $vars );

				case 'Expr_Cast_Double':
					return (float) $this->evaluate( $n->expr, $vars );

				case 'Expr_Cast_String':
					return $this->to_string( $this->evaluate( $n->expr, $vars ) );

				case 'Expr_Cast_Bool':
					return (bool) $this->evaluate( $n->expr, $vars );

				case 'Expr_Cast_Array':
					return (array) $this->evaluate( $n->expr, $vars );

				case 'Expr_Cast_Object':
					return (object) $this->evaluate( $n->expr, $vars );

				case 'Expr_Array':
					return $this->eval_array( $n, $vars );

				case 'Expr_ArrayDimFetch':
					return $this->fetch_dim( $n, $vars );

				case 'Expr_PropertyFetch':
				case 'Expr_NullsafePropertyFetch':
					$base = $this->evaluate( $n->var, $vars );
					if ( null === $base && $n instanceof Expr\NullsafePropertyFetch ) {
						return null;
					}
					$name = $this->property_name( $n, $vars );
					if ( ! is_object( $base ) ) {
						if ( ! ( null === $base && $n instanceof Expr\NullsafePropertyFetch ) ) {
							$this->warn( $n->getStartLine(), sprintf( 'Attempt to read property "%s" on %s', $name, UiChemy_Code_Mode_Binder::describe( $base ) ) );
						}
						return null;
					}
					if ( ! property_exists( $base, $name ) ) {
						$this->warn( $n->getStartLine(), sprintf( 'Undefined property: %s::$%s', get_class( $base ), $name ) );
						return null;
					}
					return $base->$name;

				case 'Expr_MethodCall':
				case 'Expr_NullsafeMethodCall':
					return $this->call_method( $n, $vars );

				case 'Expr_FuncCall':
					return $this->call_function( $n, $vars );

				case 'Expr_StaticCall':
					return $this->call_static( $n, $vars );

				case 'Expr_New':
					return $this->make_exception( $n, $vars );

				case 'Expr_Throw':
					$this->line = $n->getStartLine();
					$thrown     = $this->evaluate( $n->expr, $vars );
					if ( ! $thrown instanceof \Throwable ) {
						throw new \Error( 'Can only throw objects' );
					}
					throw $thrown;

				case 'Expr_Closure':
				case 'Expr_ArrowFunction':
					return $this->make_closure( $n, $vars );

				case 'Expr_Match':
					return $this->eval_match( $n, $vars );

				case 'Expr_Instanceof':
					$value = $this->evaluate( $n->expr, $vars );
					$class = null;
					if ( $n->class instanceof Node\Name ) {
						$class = UiChemy_Code_Mode_Allowlist::exception_class( $n->class->toString() );
						$class = null !== $class ? $class : UiChemy_Code_Mode_Allowlist::value_class( $n->class->toString() );
					}
					return null !== $class && $value instanceof $class;

				case 'Expr_ConstFetch':
					return $this->constant_value( $n );

				case 'Expr_ClassConstFetch':
					$name = $n->class->toString();
					$class = UiChemy_Code_Mode_Allowlist::exception_class( $name );
					$class = null !== $class ? $class : UiChemy_Code_Mode_Allowlist::value_class( $name );
					if ( null === $class ) {
						// The name a script uses, not the internal namespace behind it.
						$class = $this->binder->resolve_class( $name );
					}
					if ( null === $class ) {
						$this->halt( 'refused', 'Only Name::class is allowed on a class.' );
					}
					return $class;

				case 'Expr_Print':
					$this->echo_( $this->to_string( $this->evaluate( $n->expr, $vars ) ) );
					return 1;

				case 'Expr_ErrorSuppress':
					return $this->evaluate( $n->expr, $vars );
			}

			$this->halt( 'refused', sprintf( 'This syntax is not supported: %s.', $n->getType() ) );
		}

		/**
		 * The value of an expression for isset(), empty() and ??: an undefined
		 * variable, key or property is null, never an error.
		 *
		 * @param Node  $n    Expression.
		 * @param array $vars Variables.
		 * @return mixed
		 */
		private function quiet( Node $n, array &$vars ) {
			switch ( $n->getType() ) {
				case 'Expr_Variable':
					return is_string( $n->name ) && isset( $vars[ $n->name ] ) ? $vars[ $n->name ] : null;

				case 'Expr_ArrayDimFetch':
					if ( null === $n->dim ) {
						return null;
					}
					$base = $this->quiet( $n->var, $vars );
					$key  = $this->evaluate( $n->dim, $vars );
					if ( is_array( $base ) ) {
						if ( is_array( $key ) || is_object( $key ) ) {
							return null;
						}
						if ( ! is_int( $key ) && ! is_string( $key ) ) {
							// A float, bool or null key: PHP casts it ($a[1.7] is $a[1], $a[null] is $a[""]).
							return isset( $base[ $key ] ) ? $base[ $key ] : null;
						}
						if ( ! array_key_exists( $key, $base ) ) {
							// isset() and ?? expect a miss, so only a likely typo is worth a word.
							$close = self::close_key( $key, $base );
							if ( null !== $close ) {
								$this->warn( $n->getStartLine(), sprintf( 'Key "%s" is not in this array. Did you mean "%s"?', $key, $close ) );
							}
							return null;
						}
						return $base[ $key ];
					}
					if ( is_string( $base ) ) {
						// PHP's own isset: casts float, bool and null keys, accepts " 1", misses "1.0".
						return ( is_array( $key ) || is_object( $key ) ) ? null : ( isset( $base[ $key ] ) ? $base[ $key ] : null );
					}
					if ( is_object( $base ) ) {
						throw new \Error( sprintf( 'Cannot use object of type %s as array', get_class( $base ) ) );
					}
					return null;

				case 'Expr_PropertyFetch':
				case 'Expr_NullsafePropertyFetch':
					$base = $this->quiet( $n->var, $vars );
					if ( ! is_object( $base ) ) {
						return null;
					}
					$name = $this->property_name( $n, $vars );
					return isset( $base->$name ) ? $base->$name : null;
			}
			return $this->evaluate( $n, $vars );
		}

		/**
		 * @param Expr\ArrayDimFetch $n    Expression.
		 * @param array              $vars Variables.
		 * @return mixed
		 */
		private function fetch_dim( Expr\ArrayDimFetch $n, array &$vars ) {
			$base = $this->evaluate( $n->var, $vars );
			if ( null === $n->dim ) {
				throw new \Error( 'Cannot use [] for reading' );
			}
			$key = $this->evaluate( $n->dim, $vars );

			if ( is_array( $base ) ) {
				if ( is_int( $key ) || is_string( $key ) ) {
					if ( array_key_exists( $key, $base ) ) {
						return $base[ $key ];
					}
					$this->warn( $n->getStartLine(), self::missing_key( $key, $base ) );
					return null;
				}
				// Float, bool, null: cast as PHP casts them. Array or object: PHP's own TypeError.
				return $base[ $key ];
			}
			if ( is_string( $base ) ) {
				if ( is_int( $key ) && ! isset( $base[ $key ] ) ) {
					$this->warn( $n->getStartLine(), sprintf( 'Uninitialized string offset %d', $key ) );
					return '';
				}
				return $base[ $key ];
			}
			if ( is_object( $base ) ) {
				throw new \Error( sprintf( 'Cannot use object of type %s as array', get_class( $base ) ) );
			}
			$this->warn( $n->getStartLine(), sprintf( 'Trying to access array offset on value of type %s', UiChemy_Code_Mode_Binder::describe( $base ) ) );
			return null;
		}

		/**
		 * @param Expr\Array_ $n    Expression.
		 * @param array       $vars Variables.
		 * @return array
		 */
		private function eval_array( Expr\Array_ $n, array &$vars ) {
			$out    = array();
			$nested = false;
			foreach ( $n->items as $item ) {
				if ( null === $item ) {
					continue;
				}
				if ( $item->unpack ) {
					$spread = $this->evaluate( $item->value, $vars );
					if ( ! is_array( $spread ) ) {
						throw new \Error( 'Only arrays can be unpacked' );
					}
					foreach ( $spread as $key => $value ) {
						if ( is_int( $key ) ) {
							$out[] = $value;
						} else {
							$out[ $key ] = $value;
						}
					}
					$nested = true;
					$this->check_memory();
					continue;
				}
				if ( null !== $item->key ) {
					$key         = $this->evaluate( $item->key, $vars );
					$value       = $this->evaluate( $item->value, $vars );
					$out[ $key ] = $value;
				} else {
					$value = $this->evaluate( $item->value, $vars );
					$out[] = $value;
				}
				$nested = $nested || is_array( $value ) || is_object( $value );
			}
			if ( $nested ) {
				$this->check_shape( $out );
			}
			return $out;
		}

		/**
		 * @param Expr\Match_ $n    Expression.
		 * @param array       $vars Variables.
		 * @return mixed
		 */
		private function eval_match( Expr\Match_ $n, array &$vars ) {
			$subject = $this->evaluate( $n->cond, $vars );
			$default = null;
			foreach ( $n->arms as $arm ) {
				if ( null === $arm->conds ) {
					$default = $arm;
					continue;
				}
				foreach ( $arm->conds as $cond ) {
					$value = $this->evaluate( $cond, $vars );
					$this->check_compare( $subject, $value );
					if ( $subject === $value ) {
						return $this->evaluate( $arm->body, $vars );
					}
				}
			}
			if ( null !== $default ) {
				return $this->evaluate( $default->body, $vars );
			}
			$message = sprintf( 'Unhandled match case %s', is_scalar( $subject ) ? var_export( $subject, true ) : 'of type ' . UiChemy_Code_Mode_Binder::describe( $subject ) );
			throw class_exists( 'UnhandledMatchError' ) ? new \UnhandledMatchError( $message ) : new \Error( $message );
		}

		/**
		 * @param Expr\ConstFetch $n Expression.
		 * @return mixed
		 */
		private function constant_value( Expr\ConstFetch $n ) {
			$name = UiChemy_Code_Mode_Allowlist::constant_name( $n->name );
			if ( null === $name ) {
				$this->halt( 'refused', sprintf( 'Constant %s is not allowed.', $n->name->toString() ) );
			}
			if ( in_array( $name, array( 'true', 'false', 'null' ), true ) ) {
				return 'true' === $name ? true : ( 'false' === $name ? false : null );
			}
			if ( ! defined( $name ) ) {
				throw new \Error( sprintf( 'Undefined constant "%s"', $name ) );
			}
			return constant( $name );
		}

		// ------------------------------------------------------------- operators.

		/**
		 * @param string $type Node type of an assignment operator.
		 * @return string The binary operator it applies.
		 */
		private static function assign_op( $type ) {
			static $map = array(
				'Expr_AssignOp_Plus'       => '+',
				'Expr_AssignOp_Minus'      => '-',
				'Expr_AssignOp_Mul'        => '*',
				'Expr_AssignOp_Div'        => '/',
				'Expr_AssignOp_Mod'        => '%',
				'Expr_AssignOp_Pow'        => '**',
				'Expr_AssignOp_Concat'     => '.',
				'Expr_AssignOp_BitwiseAnd' => '&',
				'Expr_AssignOp_BitwiseOr'  => '|',
				'Expr_AssignOp_BitwiseXor' => '^',
				'Expr_AssignOp_ShiftLeft'  => '<<',
				'Expr_AssignOp_ShiftRight' => '>>',
			);
			return $map[ $type ];
		}

		/**
		 * @param string $op    Operator.
		 * @param mixed  $left  Left value.
		 * @param mixed  $right Right value.
		 * @return mixed
		 */
		private function binary( $op, $left, $right ) {
			switch ( $op ) {
				case '+':
					return $left + $right;
				case '-':
					return $left - $right;
				case '*':
					return $left * $right;
				case '/':
					return $left / $right;
				case '%':
					return $left % $right;
				case '**':
					return $left ** $right;
				case '.':
					$left  = $this->to_string( $left );
					$right = $this->to_string( $right );
					$this->check_size( strlen( $left ) + strlen( $right ) );
					return $left . $right;
				case '&':
					return $left & $right;
				case '|':
					return $left | $right;
				case '^':
					return $left ^ $right;
				case '<<':
					return $left << $right;
				case '>>':
					return $left >> $right;
				case 'xor':
					return (bool) $left !== (bool) $right;
			}
			$this->check_compare( $left, $right );
			switch ( $op ) {
				case '==':
					return $left == $right; // phpcs:ignore Universal.Operators.StrictComparisons
				case '!=':
					return $left != $right; // phpcs:ignore Universal.Operators.StrictComparisons
				case '===':
					return $left === $right;
				case '!==':
					return $left !== $right;
				case '<':
					return $left < $right;
				case '<=':
					return $left <= $right;
				case '>':
					return $left > $right;
				case '>=':
					return $left >= $right;
				case '<=>':
					return $left <=> $right;
			}
			$this->halt( 'refused', sprintf( 'The operator %s is not supported.', $op ) );
		}

		/**
		 * @param mixed $value Value.
		 * @return string
		 */
		private function to_string( $value ) {
			return is_array( $value ) ? 'Array' : (string) $value;
		}

		/**
		 * @param string $text Output.
		 */
		private function echo_( $text ) {
			if ( strlen( $this->log ) < self::MAX_LOG ) {
				$this->log .= substr( $text, 0, self::MAX_LOG - strlen( $this->log ) );
			}
			if ( ! $this->log_cut && strlen( $this->log ) >= self::MAX_LOG ) {
				$this->log_cut = true;
				$this->log    .= sprintf( "\n[logs cut at %d KB: echo less, or return what you need]", self::MAX_LOG / 1024 );
			}
		}

		// ------------------------------------------------------------ assignment.

		/**
		 * A reference to the storage a variable, element or property names,
		 * created as null when it does not exist yet.
		 *
		 * @param Node  $n    Target.
		 * @param array $vars Variables.
		 * @return mixed A reference.
		 */
		private function &lvalue( Node $n, array &$vars, $use = 'array' ) {
			switch ( $n->getType() ) {
				case 'Expr_Variable':
					if ( ! is_string( $n->name ) ) {
						$this->halt( 'refused', 'Variable variables are not allowed.' );
					}
					return $vars[ $n->name ];

				case 'Expr_ArrayDimFetch':
					$base = &$this->lvalue( $n->var, $vars, 'array' );
					$this->vivify( $base, $n );
					if ( is_array( $base ) ) {
						if ( null === $n->dim ) {
							$base[] = null;
							$last   = array_key_last( $base );
							return $base[ $last ];
						}
						$key = $this->evaluate( $n->dim, $vars );
						return $base[ $key ];
					}
					if ( is_object( $base ) ) {
						throw new \Error( sprintf( 'Cannot use object of type %s as array', get_class( $base ) ) );
					}
					if ( is_string( $base ) ) {
						// PHP checks the key first: $s['name']++ is about the key, not the ++.
						if ( null !== $n->dim ) {
							$key = $this->evaluate( $n->dim, $vars );
							if ( is_array( $key ) || is_object( $key ) || ( is_string( $key ) && ! is_numeric( $key ) ) ) {
								throw new \TypeError( sprintf( 'Cannot access offset of type %s on string', is_object( $key ) ? get_class( $key ) : UiChemy_Code_Mode_Binder::describe( $key ) ) );
							}
						}
						throw new \Error( self::string_offset_message( null === $n->dim ? 'append' : $use ) );
					}
					throw new \Error( 'Cannot use a scalar value as an array' );

				case 'Expr_PropertyFetch':
					$object = $this->evaluate( $n->var, $vars );
					$name   = $this->property_name( $n, $vars );
					if ( ! is_object( $object ) ) {
						throw new \Error( 'Attempt to assign a property on ' . UiChemy_Code_Mode_Binder::describe( $object ) );
					}
					// An exception or closure carries no data of its own: a property on one
					// would be a place to hide values from the shape checks, and a chain
					// built that way crashes PHP when it is freed.
					if ( $object instanceof \Throwable || $object instanceof \Closure ) {
						throw new \Error( sprintf( 'Cannot create dynamic property %s::$%s', get_class( $object ), $name ) );
					}
					return $object->$name;
			}

			$temporary = $this->evaluate( $n, $vars );
			return $temporary;
		}

		/**
		 * @param Node  $target Variable, element, property or a [ ... ] pattern.
		 * @param mixed $value  Value.
		 * @param array $vars   Variables.
		 */
		private function assign_to( Node $target, $value, array &$vars ) {
			if ( $target instanceof Expr\Array_ || $target instanceof Expr\List_ ) {
				$this->destructure( $target, $value, $vars );
				return;
			}
			if ( ( $target instanceof Expr\ArrayDimFetch || $target instanceof Expr\PropertyFetch ) && ( is_array( $value ) || is_object( $value ) ) ) {
				$this->check_shape( $value );
			}
			// $c = $value where $c is a reference ( use (&$c), $g[0] = &$c, foreach as &$v ):
			// the value lands inside whatever else holds that reference, so it can nest
			// one level deeper on every pass of a loop, and PHP crashes freeing the chain.
			// Checked like an element write, so MAX_NEST stops it.
			if ( $target instanceof Expr\Variable && is_string( $target->name ) && ( is_array( $value ) || is_object( $value ) )
				&& array_key_exists( $target->name, $vars ) && null !== \ReflectionReference::fromArrayElement( $vars, $target->name ) ) {
				$this->check_shape( $value );
			}
			if ( $target instanceof Expr\ArrayDimFetch && null !== $target->dim ) {
				// $s[1] = 'X' on a string: PHP cannot take a reference to a string offset,
				// so the container is resolved once and written in place.
				$base = &$this->lvalue( $target->var, $vars, 'array' );
				if ( is_string( $base ) ) {
					$this->write_offset( $base, $this->evaluate( $target->dim, $vars ), $value );
					return;
				}
				$this->vivify( $base, $target );
				if ( is_array( $base ) ) {
					$base[ $this->evaluate( $target->dim, $vars ) ] = $value;
					return;
				}
				if ( is_object( $base ) ) {
					throw new \Error( sprintf( 'Cannot use object of type %s as array', get_class( $base ) ) );
				}
				throw new \Error( 'Cannot use a scalar value as an array' );
			}
			$ref = &$this->lvalue( $target, $vars );
			$ref = $value;
		}

		/**
		 * $s[$offset] = $value on a string, made by PHP itself (so its errors and
		 * warnings are PHP's), after checking how long the string would become:
		 * an offset past the end pads with spaces.
		 *
		 * @param string $text   String, by reference.
		 * @param mixed  $offset Offset.
		 * @param mixed  $value  Value.
		 */
		private function write_offset( &$text, $offset, $value ) {
			if ( is_int( $offset ) || is_float( $offset ) || is_bool( $offset ) || ( is_string( $offset ) && is_numeric( $offset ) ) ) {
				// Compared as a float: (int) of an offset such as 1e30 is undefined.
				if ( (float) $offset + 1 > $this->max_string ) {
					$this->check_size( PHP_INT_MAX );
				}
			}
			$copy            = $text;
			$copy[ $offset ] = $value;
			$text            = $copy;
		}

		/**
		 * A null or false container becomes an empty array on a write, as in
		 * PHP (false with its 8.1 deprecation). Anything else is left alone.
		 *
		 * @param mixed $base Container, by reference.
		 * @param Node  $n    Expression, for the line.
		 */
		private function vivify( &$base, Node $n ) {
			if ( false === $base ) {
				$this->warn( $n->getStartLine(), 'Automatic conversion of false to array is deprecated' );
				$base = array();
			} elseif ( null === $base ) {
				$base = array();
			}
		}

		/**
		 * PHP's message for using a string offset where it cannot be used.
		 *
		 * @param string $use 'append', 'array', 'assign-op', 'incdec' or 'ref'.
		 * @return string
		 */
		private static function string_offset_message( $use ) {
			$messages = array(
				'append'    => '[] operator not supported for strings',
				'assign-op' => 'Cannot use assign-op operators with string offsets',
				'incdec'    => 'Cannot increment/decrement string offsets',
				'ref'       => 'Cannot create references to/from string offsets',
			);
			return isset( $messages[ $use ] ) ? $messages[ $use ] : 'Cannot use string offset as an array';
		}

		/**
		 * [ $a, [ 'k' => $b ] ] = $value.
		 *
		 * @param Expr\Array_|Expr\List_ $pattern Pattern.
		 * @param mixed                  $value   Value.
		 * @param array                  $vars    Variables.
		 */
		private function destructure( $pattern, $value, array &$vars ) {
			if ( is_object( $value ) ) {
				throw new \Error( sprintf( 'Cannot use object of type %s as array', get_class( $value ) ) );
			}
			// A scalar or null gives nulls, as in PHP.
			$value = is_array( $value ) ? $value : array();
			$index = 0;
			foreach ( $pattern->items as $item ) {
				if ( null === $item ) {
					++$index;
					continue;
				}
				$key = null !== $item->key ? $this->evaluate( $item->key, $vars ) : $index++;
				$this->assign_to( $item->value, array_key_exists( $key, $value ) ? $value[ $key ] : null, $vars );
			}
		}

		/**
		 * $a = &$b, for variables and array elements.
		 *
		 * @param Expr\AssignRef $n    Expression.
		 * @param array          $vars Variables.
		 * @return mixed
		 */
		private function assign_ref( Expr\AssignRef $n, array &$vars ) {
			// A reference into an array or object (or a copy of one) can point one
			// level deeper each time, and $p = &$p['c'] in a loop nests without limit.
			// $g[0] = &$c counts too: the element and the variable then share one slot,
			// and a value written through either nests inside the other.
			$into = $n->expr instanceof Expr\ArrayDimFetch || $n->expr instanceof Expr\PropertyFetch || $n->var instanceof Expr\ArrayDimFetch
				|| ( $n->expr instanceof Expr\Variable && is_string( $n->expr->name ) && isset( $this->aliases[ $this->depth ][ $n->expr->name ] ) );
			if ( $into && ++$this->alias_count > self::MAX_ALIASES ) {
				$this->halt( 'limit', sprintf( 'The script took more than %s references into arrays, which is not allowed. Assign values back instead, e.g. $tree[\'a\'] = $node.', number_format_i18n( self::MAX_ALIASES ) ) );
			}

			$source = &$this->lvalue( $n->expr, $vars, 'ref' );

			if ( $n->var instanceof Expr\Variable && is_string( $n->var->name ) ) {
				$vars[ $n->var->name ] = &$source;
				$this->alias( $n->var->name, $into );
				return $source;
			}
			if ( $n->var instanceof Expr\ArrayDimFetch ) {
				$base = &$this->lvalue( $n->var->var, $vars, 'array' );
				$this->vivify( $base, $n );
				if ( ! is_array( $base ) ) {
					throw new \Error( is_string( $base ) ? self::string_offset_message( 'ref' ) : ( is_object( $base ) ? sprintf( 'Cannot use object of type %s as array', get_class( $base ) ) : 'Cannot use a scalar value as an array' ) );
				}
				$this->check_shape( $source );
				if ( null === $n->var->dim ) {
					$base[] = &$source;
				} else {
					$base[ $this->evaluate( $n->var->dim, $vars ) ] = &$source;
				}
				return $source;
			}
			$this->halt( 'refused', 'That reference assignment is not supported.' );
		}

		// ----------------------------------------------------------------- calls.

		/**
		 * Evaluate call arguments into positional and named values.
		 *
		 * @param array $args Argument nodes.
		 * @param array $vars Variables.
		 * @return array{0:array,1:array}
		 */
		private function eval_args( array $args, array &$vars ) {
			$positional = array();
			$named      = array();
			foreach ( $args as $arg ) {
				$value = $this->evaluate( $arg->value, $vars );
				if ( $arg->unpack ) {
					if ( ! is_array( $value ) ) {
						throw new \Error( 'Only arrays can be unpacked' );
					}
					foreach ( $value as $key => $item ) {
						if ( is_string( $key ) ) {
							$named[ $key ] = $item;
						} else {
							$positional[] = $item;
						}
					}
					continue;
				}
				if ( null !== $arg->name ) {
					$named[ $arg->name->toString() ] = $value;
				} else {
					$positional[] = $value;
				}
			}
			return array( $positional, $named );
		}

		/**
		 * @param Expr\StaticCall $n    Expression.
		 * @param array           $vars Variables.
		 * @return array
		 */
		private function call_static( Expr\StaticCall $n, array &$vars ) {
			$this->line = $n->getStartLine();
			$class      = $n->class instanceof Node\Name ? $this->binder->resolve_class( $n->class->toString() ) : null;
			if ( null === $class || ! $n->name instanceof Node\Identifier ) {
				if ( $n->class instanceof Node\Name && $n->class->isUnqualified() ) {
					$this->halt( 'refused', sprintf( 'There is no class %s. The classes are: %s.', $n->class->toString(), implode( ', ', $this->binder->class_names() ) ) );
				}
				$this->halt( 'refused', sprintf( '%s::%s() is not allowed. Only the UiChemy classes can be called.', $n->class instanceof Node\Name ? $n->class->toString() : '(dynamic)', $n->name instanceof Node\Identifier ? $n->name->toString() : '(dynamic)' ) );
			}

			$found = $this->binder->find( $class, $n->name->toString() );
			if ( null === $found ) {
				throw new \Error( sprintf( 'Call to an undefined static method %s%s::%s().', UiChemy_Code_Mode_Binder::API_NAMESPACE, $class, $n->name->toString() ) );
			}
			if ( $n->isFirstClassCallable() ) {
				$this->halt( 'refused', 'UiChemy methods cannot be turned into callables.' );
			}

			list( $positional, $named ) = $this->eval_args( $n->getArgs(), $vars );
			$this->line                 = $n->getStartLine();
			$args                       = $this->binder->bind( $found, $positional, $named );

			return $this->call_api( $found['fn']['id'], $args );
		}

		/**
		 * One ability call, through the run's $on_call.
		 *
		 * The clock stops while it runs, and warnings inside it are WordPress's
		 * to handle, not the script's.
		 *
		 * @param string $id   Call id, e.g. "page.grep".
		 * @param array  $args Ability arguments.
		 * @return array
		 */
		private function call_api( $id, array $args ) {
			$this->check_shape( $args );
			$began           = microtime( true );
			$this->in_script = false;
			try {
				$result = call_user_func( $this->on_call, $id, $args );
			} catch ( UiChemy_Code_Mode_Halt $halt ) {
				throw $halt;
			} catch ( \Throwable $error ) {
				$result = array(
					'ok'      => false,
					'message' => $error->getMessage(),
				);
			} finally {
				$this->in_script = true;
				$this->paused   += microtime( true ) - $began;
			}

			if ( isset( $result['limit'] ) ) {
				$this->halt( 'limit', (string) $result['limit'] );
			}
			if ( empty( $result['ok'] ) ) {
				throw new \RuntimeException( (string) ( isset( $result['message'] ) ? $result['message'] : 'The call failed.' ) );
			}

			// What a script sees is plain data: objects become arrays, as they did over the old bridge.
			$json  = wp_json_encode( isset( $result['value'] ) ? $result['value'] : null, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			$value = false === $json ? null : json_decode( $json, true );
			$this->check_memory();

			return is_array( $value ) ? $value : array( 'value' => $value );
		}

		/**
		 * @param Expr\FuncCall $n    Expression.
		 * @param array         $vars Variables.
		 * @return mixed
		 */
		private function call_function( Expr\FuncCall $n, array &$vars ) {
			$this->line = $n->getStartLine();

			if ( ! $n->name instanceof Node\Name ) {
				// $fn(...), (fn() => ...)(...) or "trim"(...): what is called is resolved
				// first, so it is always a closure this run made or an allowed function.
				$callee = $this->evaluate( $n->name, $vars );
				if ( is_string( $callee ) ) {
					$name = $this->callable_name( $callee );
					if ( isset( $this->functions[ $name ] ) ) {
						if ( $n->isFirstClassCallable() ) {
							return $this->declared_closure( $this->functions[ $name ] );
						}
						list( $positional, $named ) = $this->eval_args( $n->getArgs(), $vars );
						$this->line                 = $n->getStartLine();
						return $this->invoke( $this->functions[ $name ], array(), $positional, $named, $name );
					}
					if ( $n->isFirstClassCallable() ) {
						return $this->resolve_callable( $callee );
					}
					// Called as written out, so by-reference arguments (sort, preg_match) still work.
					return $this->call_native( $name, $n, $vars );
				}
				$closure = $this->resolve_callable( $callee );
				if ( $n->isFirstClassCallable() ) {
					return $closure;
				}
				list( $positional ) = $this->eval_args( $n->getArgs(), $vars );
				$this->line         = $n->getStartLine();
				return $closure( ...$positional );
			}

			$name = UiChemy_Code_Mode_Allowlist::function_name( $n->name );
			if ( null === $name ) {
				$this->halt( 'refused', sprintf( '%s() is not allowed.', $n->name->toString() ) );
			}
			if ( isset( $this->functions[ $name ] ) ) {
				if ( $n->isFirstClassCallable() ) {
					return $this->declared_closure( $this->functions[ $name ] );
				}
				list( $positional, $named ) = $this->eval_args( $n->getArgs(), $vars );
				$this->line                 = $n->getStartLine();
				return $this->invoke( $this->functions[ $name ], array(), $positional, $named, $name );
			}

			if ( ! in_array( $name, UiChemy_Code_Mode_Allowlist::functions(), true ) ) {
				if ( isset( $this->declarable[ $name ] ) ) {
					// Declared inside an if or a function that has not run (yet).
					throw new \Error( sprintf( 'Call to undefined function %s()', $n->name->toString() ) );
				}
				$this->halt( 'refused', sprintf( '%s() is not in the allowed function list.', $name ) );
			}
			if ( $n->isFirstClassCallable() ) {
				return $this->native_closure( $name );
			}

			return $this->call_native( $name, $n, $vars );
		}

		/**
		 * strtoupper(...) as a closure. Every call it makes goes through the same
		 * checks as a call written out, so array_map(str_repeat(...), ...) cannot
		 * build in one native step what str_repeat(...) written out may not.
		 * Arguments are weakly typed, as when PHP itself calls the function back.
		 *
		 * @param string $name Allowed function name.
		 * @return \Closure
		 */
		private function native_closure( $name ) {
			// A chain of callbacks calling callbacks (array_map("array_map", ...)) is
			// counted where each native call that calls back is made (MAX_CALLBACK_DEPTH).
			return function ( ...$args ) use ( $name ) {
				return $this->native_invoke( $name, $args, array(), true );
			};
		}

		/**
		 * The function a string names, lowercase, when it is one a script may
		 * call; anything else is refused. compact() needs the caller's variables,
		 * so, as in PHP, it cannot be called by a name held in a value.
		 *
		 * @param string $callee Name as given, e.g. "trim" or "\\strtoupper".
		 * @return string
		 */
		private function callable_name( $callee ) {
			if ( false !== strpos( $callee, '::' ) ) {
				$this->halt( 'refused', sprintf( '"%s" is not allowed. Only functions can be called by name; use a closure, e.g. fn($x) => ...', $callee ) );
			}
			$name = strtolower( ltrim( $callee, '\\' ) );
			if ( isset( $this->functions[ $name ] ) ) {
				return $name;
			}
			if ( 'compact' === $name ) {
				throw new \Error( 'Cannot call compact() dynamically' );
			}
			if ( '' === $name || ! in_array( $name, UiChemy_Code_Mode_Allowlist::functions(), true ) ) {
				$this->halt( 'refused', sprintf( '%s() is not in the allowed function list.', $callee ) );
			}
			return $name;
		}

		/**
		 * What a callback or $fn() may be: a closure this run made (a closure,
		 * an arrow function, name(...)), or a string naming an allowed or
		 * declared function, which becomes a closure making the same checked
		 * call. A closure from anywhere else - should a value from WordPress ever
		 * carry one - is never called.
		 *
		 * @param mixed $value Callable as the script gave it.
		 * @return \Closure
		 */
		private function resolve_callable( $value ) {
			if ( $value instanceof \Closure ) {
				if ( ( new \ReflectionFunction( $value ) )->getClosureThis() !== $this ) {
					$this->halt( 'refused', 'Only closures the script made itself can be called.' );
				}
				return $value;
			}
			if ( is_string( $value ) ) {
				$name = $this->callable_name( $value );
				return isset( $this->functions[ $name ] ) ? $this->declared_closure( $this->functions[ $name ] ) : $this->native_closure( $name );
			}
			if ( is_array( $value ) ) {
				$this->halt( 'refused', 'Array callables ([$object, "method"]) are not allowed. Use a closure, e.g. fn($x) => ...' );
			}
			throw new \Error( 'Value not callable' );
		}

		/**
		 * An allowlisted PHP function.
		 *
		 * @param string        $name Function name.
		 * @param Expr\FuncCall $n    Expression.
		 * @param array         $vars Variables.
		 * @return mixed
		 */
		private function call_native( $name, Expr\FuncCall $n, array &$vars ) {
			if ( 'compact' === $name ) {
				return $this->compact( $n, $vars );
			}
			$refs       = $this->ref_positions( $name );
			$positional = array();
			$named      = array();

			foreach ( $n->getArgs() as $arg ) {
				if ( $arg->unpack ) {
					$spread = $this->evaluate( $arg->value, $vars );
					if ( ! is_array( $spread ) ) {
						throw new \Error( 'Only arrays can be unpacked' );
					}
					foreach ( $spread as $key => $item ) {
						if ( is_string( $key ) ) {
							$named[ $key ] = $item;
						} else {
							$positional[] = $item;
						}
					}
					continue;
				}
				if ( null !== $arg->name ) {
					$named[ $arg->name->toString() ] = $this->evaluate( $arg->value, $vars );
					continue;
				}
				$index = count( $positional );
				if ( isset( $refs[ $index ] ) ) {
					$value = $arg->value;
					if ( $value instanceof Expr\Variable || $value instanceof Expr\ArrayDimFetch || $value instanceof Expr\PropertyFetch ) {
						$positional[ $index ] = &$this->lvalue( $value, $vars );
					} elseif ( $value instanceof Expr\FuncCall || $value instanceof Expr\MethodCall || $value instanceof Expr\StaticCall || $value instanceof Expr\NullsafeMethodCall ) {
						// As PHP: a call's result goes in as a temporary, with a notice.
						$positional[ $index ] = $this->evaluate( $value, $vars );
						$this->warn( $value->getStartLine(), 'Only variables should be passed by reference' );
					} else {
						$parameter = ( new \ReflectionFunction( $name ) )->getParameters()[ $index ] ?? null;
						throw new \Error( sprintf( '%s(): Argument #%d ($%s) %s be passed by reference', $name, $index + 1, null === $parameter ? 'value' : $parameter->getName(), PHP_VERSION_ID >= 80300 ? 'could not' : 'cannot' ) );
					}
				} else {
					$positional[] = $this->evaluate( $arg->value, $vars );
				}
			}

			$this->line = $n->getStartLine();

			return $this->native_invoke( $name, $positional, $named, false );
		}

		/**
		 * Check and make one call to an allowed PHP function.
		 *
		 * @param string $name       Function name.
		 * @param array  $positional Positional arguments (references where the function takes them).
		 * @param array  $named      Named arguments.
		 * @param bool   $weak       Weak typing, for a call PHP makes back into a first-class callable.
		 * @return mixed
		 */
		private function native_invoke( $name, array $positional, array $named, $weak ) {
			$refs = $this->ref_positions( $name );

			// A function that takes a callback only ever gets a closure this run made:
			// a string such as "trim" becomes a closure making the same checked call,
			// and anything else ("system", [$o, 'm'], a closure from elsewhere) is
			// refused. PHP itself never sees a name it would call.
			$callbacks = UiChemy_Code_Mode_Allowlist::callable_arguments();
			if ( isset( $callbacks[ $name ] ) ) {
				$at = $callbacks[ $name ];
				if ( array_key_exists( $at, $positional ) && null !== $positional[ $at ] ) {
					$positional[ $at ] = $this->resolve_callable( $positional[ $at ] );
				} elseif ( array_key_exists( 'callback', $named ) && null !== $named['callback'] ) {
					$named['callback'] = $this->resolve_callable( $named['callback'] );
				}
				if ( 'preg_replace_callback' === $name ) {
					$this->size_replacements( $positional, $named );
				}
			}

			if ( in_array( $name, UiChemy_Code_Mode_Allowlist::intrinsics(), true ) ) {
				return $this->intrinsic( $name, $this->argument_view( $name, $positional, $named ) );
			}

			$this->guard_native( $name, $this->argument_view( $name, $positional, $named ) );
			if ( 'json_decode' === $name ) {
				// Lowered to MAX_NEST, unless it is past PHP's own maximum, where PHP throws its ValueError.
				if ( (int) ( $positional[2] ?? 0 ) > self::MAX_NEST && (int) $positional[2] < 2147483647 ) {
					$positional[2] = self::MAX_NEST;
				}
				if ( (int) ( $named['depth'] ?? 0 ) > self::MAX_NEST && (int) $named['depth'] < 2147483647 ) {
					$named['depth'] = self::MAX_NEST;
				}
			}

			if ( 'get_debug_type' === $name && ! function_exists( 'get_debug_type' ) ) {
				return UiChemy_Code_Mode_Binder::describe( $positional[0] );
			}
			$reenters = isset( $callbacks[ $name ] );
			if ( $reenters && $this->callback_depth + 1 > self::MAX_CALLBACK_DEPTH ) {
				$this->halt( 'limit', sprintf( 'The script nested callbacks (array_map, usort ...) deeper than %d levels.', self::MAX_CALLBACK_DEPTH ) );
			}
			$this->callback_depth += $reenters ? 1 : 0;
			try {
				if ( $named ) {
					if ( $refs || PHP_VERSION_ID < 80100 ) {
						$this->halt( 'refused', sprintf( 'Named arguments for %s() need PHP 8.1 or later and no by-reference parameters. Pass them by position.', $name ) );
					}
					$result = UiChemy_Code_Mode_Native::call_named( $name, $positional, $named );
				} elseif ( $this->in_steps( $name, $positional ) ) {
					$result = $this->replace_in_steps( $name, $positional );
				} elseif ( $weak ) {
					// This file does not declare strict_types, so this call is weakly typed, as PHP's own callbacks are.
					$result = $name( ...$positional );
				} elseif ( $refs ) {
					$result = UiChemy_Code_Mode_Native::call_ref( $name, $positional );
				} else {
					$result = UiChemy_Code_Mode_Native::call( $name, $positional );
				}
			} finally {
				$this->callback_depth -= $reenters ? 1 : 0;
			}

			if ( is_string( $result ) ) {
				$this->check_size( strlen( $result ) );
			}
			// json_last_error() and preg_last_error() answer for the script's own calls,
			// never for what the interpreter or WordPress ran in between.
			if ( 'json_encode' === $name || 'json_decode' === $name ) {
				$flags = (int) ( 'json_encode' === $name ? ( $positional[1] ?? 0 ) : ( $positional[3] ?? 0 ) );
				if ( ! ( $flags & JSON_THROW_ON_ERROR ) ) {
					$this->json_state = array( json_last_error(), json_last_error_msg() );
				}
			} elseif ( 0 === strpos( $name, 'preg_' ) && 'preg_quote' !== $name && 0 !== strpos( $name, 'preg_last' ) ) {
				$this->preg_state = array( preg_last_error(), function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : '' );
			}
			// These put values into the array they are given by reference, which may be
			// shared through a reference: checked like an element write.
			if ( 'parse_str' === $name && array_key_exists( 1, $positional ) ) {
				$this->check_shape( $positional[1] );
			}
			if ( in_array( $name, array( 'array_push', 'array_unshift', 'array_splice', 'array_walk_recursive' ), true ) && array_key_exists( 0, $positional ) ) {
				$this->check_shape( $positional[0] );
			}
			// These can nest values or repeat one many times over in a single step.
			if ( in_array( $name, array( 'array_chunk', 'array_combine', 'array_fill_keys', 'array_map', 'json_decode', 'array_fill', 'array_pad', 'array_merge_recursive', 'array_replace_recursive', 'str_split', 'mb_str_split', 'str_word_count' ), true ) ) {
				$this->check_shape( $result );
			}
			$this->check_memory();
			return $result;
		}

		/**
		 * compact( 'a', [ 'b', 'c' ] ): the script's own variables by name, as
		 * PHP reads the caller's. Only the script's variables are ever in $vars.
		 *
		 * @param Expr\FuncCall $n    Expression.
		 * @param array         $vars Variables.
		 * @return array
		 */
		private function compact( Expr\FuncCall $n, array &$vars ) {
			list( $names ) = $this->eval_args( $n->getArgs(), $vars );
			$this->line    = $n->getStartLine();
			$this->check_shape( $names );
			$out = array();
			$this->compact_names( $names, $vars, $out );
			$this->check_shape( $out );
			return $out;
		}

		/**
		 * @param array $names Names, nested arrays in order (depth already checked).
		 * @param array $vars  Variables.
		 * @param array $out   Result, by reference.
		 */
		private function compact_names( array $names, array &$vars, array &$out ) {
			foreach ( $names as $each ) {
				if ( is_array( $each ) ) {
					$this->compact_names( $each, $vars, $out );
				} elseif ( ! is_string( $each ) ) {
					$this->warn( $this->line, sprintf( 'compact(): Argument must be string or array of strings, %s given', UiChemy_Code_Mode_Binder::describe( $each ) ) );
				} elseif ( array_key_exists( $each, $vars ) ) {
					$out[ $each ] = $vars[ $each ];
				} else {
					$this->warn( $this->line, sprintf( 'compact(): Undefined variable $%s', $each ) );
				}
			}
		}

		/**
		 * preg_replace_callback() builds its result inside one native step from
		 * what the callback returns, so the callback is wrapped to count those
		 * bytes as they come and stop the run before the result is too big.
		 *
		 * @param array $positional Arguments by position, callback already resolved.
		 * @param array $named      Named arguments.
		 */
		private function size_replacements( array &$positional, array &$named ) {
			$in_named = ! array_key_exists( 1, $positional ) && array_key_exists( 'callback', $named );
			$callback = $in_named ? $named['callback'] : ( array_key_exists( 1, $positional ) ? $positional[1] : null );
			if ( ! $callback instanceof \Closure ) {
				return;
			}
			$subject = array_key_exists( 2, $positional ) ? $positional[2] : ( array_key_exists( 'subject', $named ) ? $named['subject'] : '' );
			$built   = 0;
			foreach ( is_array( $subject ) ? $subject : array( $subject ) as $text ) {
				$built += is_scalar( $text ) ? strlen( (string) $text ) : 0;
			}
			$wrapped = function ( $matches ) use ( $callback, &$built ) {
				$out    = $callback( $matches );
				$built += is_scalar( $out ) ? strlen( (string) $out ) : 0;
				$this->check_size( $built );
				$this->check_memory();
				return $out;
			};
			if ( $in_named ) {
				$named['callback'] = $wrapped;
			} else {
				$positional[1] = $wrapped;
			}
		}

		/**
		 * The allowed functions carried out on the interpreter's side: compact()
		 * reads the script's variables (see call_native), and the printing ones
		 * write into the run's logs, as echo does, never into the response.
		 *
		 * @param string $name Function name.
		 * @param array  $args Arguments by position (named ones folded in).
		 * @return mixed
		 */
		private function intrinsic( $name, array $args ) {
			if ( in_array( $name, UiChemy_Code_Mode_Random::functions(), true ) ) {
				$result = $this->random->call( $name, $args );
				if ( is_string( $result ) ) {
					$this->check_size( strlen( $result ) );
				}
				return $result;
			}
			$arg = function ( $index, $default = null ) use ( $args ) {
				return array_key_exists( $index, $args ) ? $args[ $index ] : $default;
			};

			if ( 'printf' === $name ) {
				$this->check_sprintf_size( $args );
				$text = UiChemy_Code_Mode_Native::call( 'sprintf', array_values( $args ) );
				$this->echo_( $text );
				return strlen( $text );
			}
			if ( 'compact' === $name ) {
				throw new \Error( 'Cannot call compact() dynamically' );
			}
			if ( 'json_last_error' === $name || 'json_last_error_msg' === $name ) {
				return 'json_last_error' === $name ? $this->json_state[0] : $this->json_state[1];
			}
			if ( 'preg_last_error' === $name || 'preg_last_error_msg' === $name ) {
				return 'preg_last_error' === $name ? $this->preg_state[0] : $this->preg_state[1];
			}
			if ( 'filter_var' === $name ) {
				$filter = $arg( 1, FILTER_DEFAULT );
				if ( FILTER_CALLBACK === $filter ) {
					$this->halt( 'refused', 'filter_var() with FILTER_CALLBACK calls a function by name, which scripts cannot. Call the function or closure yourself.' );
				}
				$this->check_shape( $arg( 0 ) );
				$result = UiChemy_Code_Mode_Native::call( 'filter_var', array_values( $args ) );
				if ( is_string( $result ) ) {
					$this->check_size( strlen( $result ) );
				}
				return $result;
			}
			if ( 'function_exists' === $name ) {
				if ( ! is_string( $arg( 0 ) ) ) {
					throw new \TypeError( sprintf( 'function_exists(): Argument #1 ($function) must be of type string, %s given', self::value_name( $arg( 0 ) ) ) );
				}
				// Only what this script can call: never PHP's own answer, which would tell which plugins are installed.
				$asked = strtolower( ltrim( $arg( 0 ), '\\' ) );
				return isset( $this->functions[ $asked ] ) || in_array( $asked, UiChemy_Code_Mode_Allowlist::functions(), true );
			}
			if ( 'get_class' === $name || 'get_object_vars' === $name ) {
				if ( ! array_key_exists( 0, $args ) ) {
					throw new \ArgumentCountError( 'get_class' === $name ? 'get_class() without arguments must be called from within a class' : 'get_object_vars() expects exactly 1 argument, 0 given' );
				}
				$object = $args[0];
				if ( ! is_object( $object ) ) {
					throw new \TypeError( sprintf( '%s(): Argument #1 ($object) must be of type object, %s given', $name, self::value_name( $object ) ) );
				}
				if ( 'get_class' === $name ) {
					return get_class( $object );
				}
				if ( $object instanceof \Closure || $object instanceof \Throwable ) {
					return array();
				}
				// From no class scope, so only public properties, whatever the object.
				$read = \Closure::bind( static function ( $o ) { return get_object_vars( $o ); }, null, null );
				return $read( $object );
			}

			// print_r, var_export, var_dump: never a closure or exception (PHP would print
			// the interpreter that holds it), and sized before the text is made.
			$values = 'var_dump' === $name ? array_values( $args ) : array( $arg( 0 ) );
			if ( ! $values ) {
				throw new \ArgumentCountError( sprintf( '%s() expects at least 1 argument, 0 given', $name ) );
			}
			$shape = array(
				'print_r'    => array( 8, 1, 16 ),
				'var_export' => array( 2, 2, 48 ),
				'var_dump'   => array( 2, 1, 48 ),
			);
			list( $indent, $escape, $each ) = $shape[ $name ];
			$bytes                          = 0;
			foreach ( $values as $value ) {
				$this->check_shape( $value );
				$this->print_bound( $value, 1, $indent, $escape, $each, $bytes );
			}

			if ( 'var_dump' === $name ) {
				ob_start();
				try {
					foreach ( $values as $value ) {
						var_dump( $value ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- into the script's logs, not the response.
					}
				} finally {
					$text = (string) ob_get_clean();
				}
				$this->echo_( $text );
				return null;
			}

			$text = 'print_r' === $name ? print_r( $values[0], true ) : var_export( $values[0], true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- returned to the script, never printed.
			if ( $arg( 1, false ) ) {
				return $text;
			}
			$this->echo_( $text );
			return 'print_r' === $name ? true : null;
		}

		/**
		 * Most bytes print_r(), var_export() or var_dump() can make of a value,
		 * checked as it is counted. Refuses closures and exceptions anywhere in it.
		 *
		 * @param mixed $value  Value (its shape already checked).
		 * @param int   $depth  How deep it sits.
		 * @param int   $indent Indent each level adds per line.
		 * @param int   $escape Most bytes one byte of a string or key can become.
		 * @param int   $each   Bytes of markup around each item.
		 * @param int   $bytes  Bytes so far, by reference.
		 */
		private function print_bound( $value, $depth, $indent, $escape, $each, &$bytes ) {
			if ( $value instanceof \Closure || $value instanceof \Throwable ) {
				$this->halt( 'refused', 'print_r(), var_export() and var_dump() cannot print a closure or an exception. Print $e->getMessage(), or leave the closure out.' );
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$bytes += $each * 2 + $depth * $indent * 2;
				foreach ( ( is_array( $value ) ? $value : get_object_vars( $value ) ) as $key => $item ) {
					$bytes += $each + $depth * $indent + strlen( (string) $key ) * $escape;
					$this->print_bound( $item, $depth + 1, $indent, $escape, $each, $bytes );
				}
			} else {
				$bytes += ( is_string( $value ) ? strlen( $value ) * $escape : 0 ) + $each;
			}
			$this->check_size( $bytes );
		}

		/**
		 * Positions of a function's by-reference parameters.
		 *
		 * @param string $name Function name.
		 * @return array<int,true>
		 */
		private function ref_positions( $name ) {
			if ( ! isset( $this->by_ref[ $name ] ) ) {
				$positions = array();
				if ( function_exists( $name ) ) {
					foreach ( ( new \ReflectionFunction( $name ) )->getParameters() as $index => $parameter ) {
						if ( $parameter->isPassedByReference() ) {
							$positions[ $index ] = true;
						}
					}
				}
				$this->by_ref[ $name ] = $positions;
			}
			return $this->by_ref[ $name ];
		}

		/**
		 * The arguments by position, named ones included, so a guard sees
		 * str_repeat( times: ... ) the same as str_repeat( 'x', ... ).
		 *
		 * @param string $name       Function name.
		 * @param array  $positional Positional arguments.
		 * @param array  $named      Named arguments.
		 * @return array
		 */
		private function argument_view( $name, array $positional, array $named ) {
			if ( ! $named || ! function_exists( $name ) ) {
				return $positional;
			}
			foreach ( ( new \ReflectionFunction( $name ) )->getParameters() as $index => $parameter ) {
				if ( array_key_exists( $parameter->getName(), $named ) && ! array_key_exists( $index, $positional ) ) {
					$positional[ $index ] = $named[ $parameter->getName() ];
				}
			}
			return $positional;
		}

		/**
		 * Refuse a call that would build a value too big to survive: a function
		 * handed a size can make a huge string or array in one native step,
		 * before any check could see it.
		 *
		 * @param string $name       Function name.
		 * @param array  $positional Arguments.
		 */
		private function guard_native( $name, array $positional ) {
			$arg = function ( $index, $default = null ) use ( $positional ) {
				return array_key_exists( $index, $positional ) ? $positional[ $index ] : $default;
			};

			switch ( $name ) {
				case 'str_repeat':
					$this->check_size( strlen( (string) ( is_scalar( $arg( 0 ) ) ? $arg( 0 ) : '' ) ) * max( 0, (int) $arg( 1, 0 ) ) );
					break;

				// Time grows with the product of the two lengths, inside one native step.
				case 'levenshtein':
				case 'similar_text':
					$cap = 'levenshtein' === $name ? 4000000 : 1000000;
					if ( $this->text_length( $arg( 0 ) ) * $this->text_length( $arg( 1 ) ) > $cap ) {
						$this->halt( 'limit', sprintf( '%s() on strings this long would take too long: the two lengths multiplied may be at most %s. Compare shorter strings (a title, not a whole page).', $name, number_format_i18n( $cap ) ) );
					}
					break;

				case 'str_getcsv':
					if ( is_string( $arg( 0 ) ) && strlen( $arg( 0 ) ) > self::MAX_VALUES ) {
						$sep = is_string( $arg( 1 ) ) && '' !== $arg( 1 ) ? $arg( 1 ) : ',';
						$this->check_values( substr_count( $arg( 0 ), $sep ) + 1 );
					}
					break;

				case 'mb_convert_case':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 3 );
					break;

				case 'array_walk_recursive':
					$this->check_shape( $arg( 0 ) );
					break;

				case 'end':
				case 'reset':
				case 'current':
				case 'key':
				case 'next':
				case 'prev':
				case 'pos':
					if ( is_object( $arg( 0 ) ) && ! $arg( 0 ) instanceof \stdClass ) {
						$this->halt( 'refused', sprintf( '%s() takes an array (or a stdClass), not a %s.', $name, get_class( $arg( 0 ) ) ) );
					}
					break;

				case 'chunk_split':
					$length = max( 1, (int) $arg( 1, 76 ) );
					$text   = $this->text_length( $arg( 0 ) );
					$this->check_size( $text + (int) ceil( $text / $length ) * $this->text_length( $arg( 2, "\r\n" ) ) );
					break;

				case 'parse_str':
					if ( count( $positional ) < 2 ) {
						$this->halt( 'refused', 'parse_str() needs its second argument, the array to fill: parse_str($query, $result).' );
					}
					break;

				case 'random_bytes':
					$this->check_size( is_int( $arg( 0 ) ) ? $arg( 0 ) : 0 );
					break;

				case 'str_pad':
					$this->check_size( (int) $arg( 1, 0 ) );
					break;

				case 'range':
					if ( is_numeric( $arg( 0 ) ) && is_numeric( $arg( 1 ) ) ) {
						$step  = abs( (float) $arg( 2, 1 ) );
						$count = abs( (float) $arg( 1 ) - (float) $arg( 0 ) ) / ( $step > 0 ? $step : 1 ) + 1;
						if ( $count > self::MAX_RANGE ) {
							$this->halt( 'limit', sprintf( 'range() would make %s elements, which is more than the %s allowed.', number_format_i18n( (int) $count ), number_format_i18n( self::MAX_RANGE ) ) );
						}
					}
					break;

				case 'sprintf':
					$this->check_sprintf_size( $positional );
					break;

				case 'str_replace':
				case 'str_ireplace':
					// An array of searches is made one at a time, each one checked (replace_in_steps).
					if ( ! $this->in_steps( $name, $positional ) ) {
						$this->check_replace_size( $arg( 0 ), $arg( 1 ), $arg( 2 ) );
					}
					break;

				case 'preg_replace':
					if ( ! $this->in_steps( $name, $positional ) ) {
						$pattern = $arg( 0 );
						if ( is_array( $pattern ) ) {
							$pattern = $pattern ? reset( $pattern ) : '';
						}
						$with = $arg( 1 );
						if ( is_array( $with ) ) {
							$with = $with ? reset( $with ) : '';
						}
						$this->check_preg_replace_size( $pattern, $with, $arg( 2 ), (int) $arg( 3, -1 ) );
					}
					break;

				case 'implode':
					$this->check_implode_size( $arg( 0 ), $arg( 1 ) );
					break;

				case 'wordwrap':
					if ( is_string( $arg( 0 ) ) ) {
						$text   = $arg( 0 );
						$break  = $arg( 2, "\n" );
						$breaks = substr_count( $text, ' ' ) + ( $arg( 3, false ) ? intdiv( strlen( $text ), max( 1, (int) $arg( 1, 75 ) ) ) + 1 : 0 );
						$this->check_size( strlen( $text ) + $breaks * strlen( is_scalar( $break ) ? (string) $break : '' ) );
					}
					break;

				case 'number_format':
					$this->check_size( max( 0, (int) $arg( 1, 0 ) ) + 400 );
					break;

				case 'nl2br':
				case 'htmlspecialchars':
					$this->check_size( strlen( (string) ( is_scalar( $arg( 0 ) ) ? $arg( 0 ) : '' ) ) * 7 );
					break;

				case 'preg_quote':
					$this->check_size( strlen( (string) ( is_scalar( $arg( 0 ) ) ? $arg( 0 ) : '' ) ) * 2 );
					break;

				// Case mapping can make a character up to three times longer (ΐ is two bytes, its capital six).
				case 'mb_strtoupper':
				case 'mb_strtolower':
					$this->check_size( strlen( (string) ( is_scalar( $arg( 0 ) ) ? $arg( 0 ) : '' ) ) * 3 );
					break;

				// These make one element per match or separator, in one step.
				case 'explode':
					if ( is_string( $arg( 0 ) ) && '' !== $arg( 0 ) && is_string( $arg( 1 ) ) ) {
						$parts = substr_count( $arg( 1 ), $arg( 0 ) ) + 1;
						$limit = (int) $arg( 2, PHP_INT_MAX );
						$this->check_values( $limit > 0 ? min( $parts, $limit ) : $parts );
					}
					break;

				case 'preg_split':
					if ( is_string( $arg( 0 ) ) && is_string( $arg( 1 ) ) ) {
						$flags = (int) $arg( 3, 0 );
						$each  = ( ( $flags & PREG_SPLIT_DELIM_CAPTURE ) ? 1 + self::groups( $arg( 0 ) ) : 1 ) * ( ( $flags & PREG_SPLIT_OFFSET_CAPTURE ) ? 3 : 1 );
						if ( ( strlen( $arg( 1 ) ) + 2 ) * $each > self::MAX_VALUES ) {
							$parts = (int) @preg_match_all( $arg( 0 ), $arg( 1 ) ) + 1; // phpcs:ignore WordPress.PHP.NoSilencedErrors
							$limit = (int) $arg( 2, -1 );
							$this->check_values( ( $limit > 0 ? min( $parts, $limit ) : $parts ) * $each );
						}
					}
					break;

				case 'preg_match_all':
					// Only when it fills $matches.
					if ( array_key_exists( 2, $positional ) && is_string( $arg( 0 ) ) && is_string( $arg( 1 ) ) ) {
						$each = ( 1 + self::groups( $arg( 0 ) ) ) * ( ( (int) $arg( 3, 0 ) & PREG_OFFSET_CAPTURE ) ? 3 : 1 );
						if ( ( strlen( $arg( 1 ) ) + 1 ) * $each > self::MAX_VALUES ) {
							$this->check_values( (int) @preg_match_all( $arg( 0 ), $arg( 1 ) ) * $each ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						}
					}
					break;

				case 'array_merge':
					$total = 0;
					foreach ( $positional as $value ) {
						$total += is_array( $value ) ? count( $value ) : 0;
					}
					$this->check_values( $total );
					break;

				case 'json_decode':
					// Every value but the first follows a comma or opens a container.
					if ( is_string( $arg( 0 ) ) && strlen( $arg( 0 ) ) > self::MAX_VALUES ) {
						$json = $arg( 0 );
						$this->check_values( substr_count( $json, ',' ) + substr_count( $json, '[' ) + substr_count( $json, '{' ) + 1 );
					}
					break;

				case 'json_encode':
					$this->check_shape( $arg( 0 ) );
					$this->check_size( $this->json_bound( $arg( 0 ), (int) $arg( 1, 0 ) ) );
					break;

				// These walk every level of what they are given in one step.
				case 'sort':
				case 'rsort':
				case 'array_unique':
				case 'min':
				case 'max':
					foreach ( $positional as $value ) {
						$this->check_shape( $value );
					}
					break;

				case 'count':
					if ( $arg( 1, 0 ) ) {
						$this->check_shape( $arg( 0 ) );
					}
					break;

				case 'in_array':
				case 'array_search':
					if ( is_array( $arg( 0 ) ) || is_object( $arg( 0 ) ) ) {
						$this->check_shape( $arg( 0 ) );
						$this->check_shape( $arg( 1 ) );
					}
					break;

				// ---- Added with the wider allowlist. Each is sized like those above.

				case 'asort':
				case 'arsort':
				case 'natsort':
				case 'natcasesort':
					$this->check_shape( $arg( 0 ) );
					break;

				// Same length, or at most twice (a backslash before each byte).
				case 'ucwords':
				case 'lcfirst':
				case 'strrev':
				case 'addslashes':
				case 'quotemeta':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 2 );
					break;

				// "&Aacute;" is 8 bytes for a 2-byte character; "&amp;" 5 for 1.
				case 'htmlentities':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 8 );
					break;

				case 'bin2hex':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 2 );
					break;

				case 'base64_encode':
					$this->check_size( (int) ceil( $this->text_length( $arg( 0 ) ) / 3 ) * 4 + 4 );
					break;

				case 'urlencode':
				case 'rawurlencode':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 3 );
					break;

				// Each format character prints at most a time zone name or an RFC 2822 date.
				case 'date':
				case 'gmdate':
					$this->check_size( $this->text_length( $arg( 0 ) ) * 40 );
					break;

				case 'vsprintf':
					$this->check_sprintf_size( array_merge( array( $arg( 0 ) ), is_array( $arg( 1 ) ) ? array_values( $arg( 1 ) ) : array() ) );
					break;

				// strtr( $s, [ from => to ] ): at worst every byte is a match made longest.
				case 'strtr':
					if ( is_array( $arg( 1 ) ) && ! array_key_exists( 2, $positional ) ) {
						$longest = 0;
						foreach ( $arg( 1 ) as $to ) {
							$longest = max( $longest, is_scalar( $to ) ? strlen( (string) $to ) : 5 );
						}
						$this->check_size( $this->text_length( $arg( 0 ) ) * max( 1, $longest ) );
					}
					break;

				case 'substr_replace':
					$longest = 0;
					foreach ( ( is_array( $arg( 1 ) ) ? $arg( 1 ) : array( $arg( 1 ) ) ) as $with ) {
						$longest = max( $longest, $this->text_length( $with ) );
					}
					$total = 0;
					foreach ( ( is_array( $arg( 0 ) ) ? $arg( 0 ) : array( $arg( 0 ) ) ) as $text ) {
						$total += $this->text_length( $text ) + $longest;
						$this->check_size( $total );
					}
					break;

				// One element per piece (or per word, every other byte at most).
				case 'str_split':
				case 'mb_str_split':
					$this->check_values( intdiv( $this->text_length( $arg( 0 ) ), max( 1, (int) $arg( 1, 1 ) ) ) + 1 );
					break;

				case 'str_word_count':
					if ( (int) $arg( 1, 0 ) > 0 ) {
						$this->check_values( intdiv( $this->text_length( $arg( 0 ) ), 2 ) + 1 );
					}
					break;

				case 'array_fill':
					$this->check_values( max( 0, (int) $arg( 1, 0 ) ) );
					break;

				case 'array_pad':
					$this->check_values( max( is_array( $arg( 0 ) ) ? count( $arg( 0 ) ) : 0, abs( (int) $arg( 1, 0 ) ) ) );
					break;

				case 'array_push':
				case 'array_unshift':
					$this->check_values( ( is_array( $arg( 0 ) ) ? count( $arg( 0 ) ) : 0 ) + count( $positional ) - 1 );
					break;

				case 'array_replace':
					$total = 0;
					foreach ( $positional as $value ) {
						$total += is_array( $value ) ? count( $value ) : 0;
					}
					$this->check_values( $total );
					break;

				// These walk every level of every argument and can keep all of it.
				case 'array_merge_recursive':
				case 'array_replace_recursive':
					$total = 0;
					foreach ( $positional as $value ) {
						$this->check_shape( $value );
						$total += is_array( $value ) ? count( $value, COUNT_RECURSIVE ) : 0;
						$this->check_values( $total );
					}
					break;

				case 'http_build_query':
					$this->check_shape( $arg( 0 ) );
					$bytes = 0;
					$this->query_bound( $arg( 0 ), $this->text_length( $arg( 1, '' ) ), max( 1, $this->text_length( $arg( 2, '&' ) ) ), $bytes );
					break;
			}
		}

		/**
		 * @param mixed $value An argument that should be a string.
		 * @return int Its length when it is printable, else 0.
		 */
		private function text_length( $value ) {
			return is_scalar( $value ) ? strlen( (string) $value ) : 0;
		}

		/**
		 * Most bytes http_build_query() can make: every leaf repeats its whole
		 * key path, each byte of which can become %XX.
		 *
		 * @param mixed $value      Value (its shape already checked).
		 * @param int   $prefix     Bytes of the key path so far.
		 * @param int   $separator  Bytes of the separator.
		 * @param int   $bytes      Bytes so far, by reference.
		 */
		private function query_bound( $value, $prefix, $separator, &$bytes ) {
			if ( ! is_array( $value ) && ! ( is_object( $value ) && ! $value instanceof \Closure && ! $value instanceof \Throwable ) ) {
				return;
			}
			foreach ( ( is_array( $value ) ? $value : get_object_vars( $value ) ) as $key => $item ) {
				$path = $prefix + 6 + strlen( (string) $key ) * 3;
				if ( is_array( $item ) || is_object( $item ) ) {
					$this->query_bound( $item, $path, $separator, $bytes );
					continue;
				}
				$bytes += $path + 1 + $this->text_length( $item ) * 3 + $separator;
				$this->check_size( $bytes );
			}
		}

		/**
		 * How long str_replace()'s result will be, so a replacement that grows
		 * the text on every match is refused before it is built.
		 *
		 * @param mixed $search  Search.
		 * @param mixed $replace Replace.
		 * @param mixed $subject Subject.
		 */
		private function check_replace_size( $search, $replace, $subject ) {
			$subjects = is_array( $subject ) ? $subject : array( $subject );
			$searches = is_array( $search ) ? array_values( $search ) : array( $search );
			$replaces = is_array( $replace ) ? array_values( $replace ) : null;

			foreach ( $subjects as $text ) {
				if ( ! is_string( $text ) ) {
					continue;
				}
				$length = strlen( $text );
				foreach ( $searches as $index => $needle ) {
					$with = null === $replaces ? $replace : ( isset( $replaces[ $index ] ) ? $replaces[ $index ] : '' );
					if ( ! is_string( $needle ) || '' === $needle || ! is_string( $with ) || strlen( $with ) <= strlen( $needle ) ) {
						continue;
					}
					$length += substr_count( $text, $needle ) * ( strlen( $with ) - strlen( $needle ) );
				}
				$this->check_size( $length );
			}
		}

		/**
		 * How long preg_replace()'s result can be. Matches do not overlap, so
		 * what they cover adds up to at most the subject, and each $n or \n in
		 * the replacement repeats at most what its match covered.
		 *
		 * @param mixed $pattern Pattern.
		 * @param mixed $with    Replacement.
		 * @param mixed $subject Subject or subjects.
		 * @param int   $limit   Most replacements per subject, -1 for all.
		 */
		private function check_preg_replace_size( $pattern, $with, $subject, $limit ) {
			if ( ! is_string( $pattern ) || ! is_scalar( $with ) ) {
				return;
			}
			$with = (string) $with;
			$refs = (int) preg_match_all( '/\$\d{1,2}|\$\{\d{1,2}\}|\\\\\d{1,2}/', $with );
			foreach ( ( is_array( $subject ) ? $subject : array( $subject ) ) as $text ) {
				if ( ! is_string( $text ) ) {
					continue;
				}
				$length = strlen( $text );
				$grow   = max( 0, $refs - 1 ) * $length;
				// At most one match per byte and one more at the end: count them only when that could be too much.
				if ( $length + ( $length + 1 ) * strlen( $with ) + $grow <= $this->max_string ) {
					continue;
				}
				$matches = (int) @preg_match_all( $pattern, $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $limit >= 0 ) {
					$matches = min( $matches, $limit );
				}
				$this->check_size( $length + $matches * strlen( $with ) + ( $matches > 0 ? $grow : 0 ) );
			}
		}

		/**
		 * How long sprintf()'s result can be: the format, and for each
		 * conversion its width, its argument or the longest a number prints.
		 *
		 * @param array $positional Arguments.
		 */
		private function check_sprintf_size( array $positional ) {
			$format = array_key_exists( 0, $positional ) ? $positional[0] : '';
			if ( ! is_string( $format ) ) {
				return;
			}
			$longest = 0;
			$widest  = 0;
			foreach ( array_slice( $positional, 1 ) as $value ) {
				$longest = max( $longest, is_scalar( $value ) ? strlen( (string) $value ) : 5 );
				$widest  = max( $widest, is_int( $value ) ? abs( $value ) : 0 );
			}
			$total = strlen( $format );
			if ( preg_match_all( '/%(?:\d+\$)?[-+ 0]*(?:\'.)?(\*|\d*)(?:\.(?:\*|\d+))?([a-zA-Z])/', $format, $specs ) ) {
				foreach ( $specs[1] as $index => $width ) {
					// %s prints its argument; %f a float in full (over 300 digits); anything else a number.
					$conversion = $specs[2][ $index ];
					$printed    = 's' === $conversion ? $longest : ( 'f' === strtolower( $conversion ) ? 400 : 100 );
					$total     += max( '*' === $width ? $widest : (int) $width, $printed );
				}
			}
			$this->check_size( $total );
		}

		/**
		 * @param mixed $glue   Separator (or the pieces, in the one-argument form).
		 * @param mixed $pieces Pieces.
		 */
		private function check_implode_size( $glue, $pieces ) {
			if ( is_array( $glue ) && null === $pieces ) {
				$pieces = $glue;
				$glue   = '';
			}
			if ( ! is_array( $pieces ) || ! is_scalar( $glue ) ) {
				return;
			}
			$total = max( 0, count( $pieces ) - 1 ) * strlen( (string) $glue );
			$this->check_size( $total );
			foreach ( $pieces as $piece ) {
				$total += is_scalar( $piece ) ? strlen( (string) $piece ) : 5;
				$this->check_size( $total );
			}
		}

		/**
		 * @param string $pattern Regex.
		 * @return int Capturing groups it has (named ones twice), 0 when it is not valid.
		 */
		private static function groups( $pattern ) {
			$found = array();
			@preg_match_all( $pattern, '', $found ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_array( $found ) ? max( 0, count( $found ) - 1 ) : 0;
		}

		/**
		 * Is this str_replace(), str_ireplace() or preg_replace() with several
		 * searches? Those are made one search at a time, as PHP makes them, so
		 * every step is checked: a step can grow what the next one matches.
		 *
		 * @param string $name       Function name.
		 * @param array  $positional Arguments.
		 * @return bool
		 */
		private function in_steps( $name, array $positional ) {
			return in_array( $name, array( 'str_replace', 'str_ireplace', 'preg_replace' ), true )
				&& isset( $positional[0], $positional[1], $positional[2] )
				&& is_array( $positional[0] ) && count( $positional[0] ) > 1
				&& ( is_string( $positional[1] ) || is_array( $positional[1] ) )
				&& ( is_string( $positional[2] ) || is_array( $positional[2] ) );
		}

		/**
		 * str_replace( [ a, b ], [ x, y ], $s ) as PHP makes it: a with x on the
		 * whole subject, then b with y on that result. Each step is checked first.
		 *
		 * @param string $name       str_replace, str_ireplace or preg_replace.
		 * @param array  $positional Arguments; the count argument, when given, is a reference.
		 * @return mixed
		 */
		private function replace_in_steps( $name, array $positional ) {
			$searches = array_values( $positional[0] );
			$replaces = is_array( $positional[1] ) ? array_values( $positional[1] ) : null;
			$subject  = $positional[2];
			$preg     = 'preg_replace' === $name;
			if ( $preg && array_key_exists( 3, $positional ) && ! is_int( $positional[3] ) ) {
				throw new \TypeError( sprintf( 'preg_replace(): Argument #4 ($limit) must be of type int, %s given', self::value_name( $positional[3] ) ) );
			}
			$limit = $preg && isset( $positional[3] ) ? $positional[3] : -1;
			$total    = 0;

			foreach ( $searches as $index => $search ) {
				$with   = null === $replaces ? $positional[1] : ( isset( $replaces[ $index ] ) ? $replaces[ $index ] : '' );
				$search = $this->to_string( $search );
				$with   = $this->to_string( $with );
				$count  = 0;
				if ( $preg ) {
					$this->check_preg_replace_size( $search, $with, $subject, $limit );
					$subject = UiChemy_Code_Mode_Native::call_ref( $name, array( $search, $with, $subject, $limit, &$count ) );
					if ( null === $subject ) {
						break;
					}
				} else {
					$this->check_replace_size( $search, $with, $subject );
					$subject = UiChemy_Code_Mode_Native::call_ref( $name, array( $search, $with, $subject, &$count ) );
				}
				$total += $count;
				$this->check_memory();
			}

			$count_at = $preg ? 4 : 3;
			if ( array_key_exists( $count_at, $positional ) ) {
				$positional[ $count_at ] = $total;
			}
			return $subject;
		}

		/**
		 * @param Expr\MethodCall|Expr\NullsafeMethodCall $n    Expression.
		 * @param array                                   $vars Variables.
		 * @return mixed
		 */
		private function call_method( Node $n, array &$vars ) {
			$this->line = $n->getStartLine();
			$object     = $this->evaluate( $n->var, $vars );
			if ( null === $object && $n instanceof Expr\NullsafeMethodCall ) {
				return null;
			}

			$method = $n->name instanceof Node\Identifier ? strtolower( $n->name->name ) : '';
			if ( ! in_array( $method, UiChemy_Code_Mode_Allowlist::methods(), true ) ) {
				$this->halt( 'refused', UiChemy_Code_Mode_Allowlist::methods_message() );
			}
			if ( ! $object instanceof \Throwable ) {
				throw new \Error( sprintf( 'Call to a member function %s() on %s', $n->name->name, UiChemy_Code_Mode_Binder::describe( $object ) ) );
			}

			switch ( $method ) {
				case 'getmessage':
					return str_replace( ABSPATH, '', $object->getMessage() );
				case 'getcode':
					return $object->getCode();
				case 'getline':
					return isset( $this->lines[ $object ] ) ? (int) $this->lines[ $object ] : $this->line;
				// What PHP would say about the interpreter is never handed out: the script is the file, and has no frames.
				case 'getfile':
					return 'script';
				case 'gettrace':
					return array();
				case 'gettraceasstring':
					return '#0 {main}';
				default:
					return $object->getPrevious();
			}
		}

		/**
		 * new \RuntimeException(...): the only thing a script may create.
		 *
		 * @param Expr\New_ $n    Expression.
		 * @param array     $vars Variables.
		 * @return \Throwable
		 */
		private function make_exception( Expr\New_ $n, array &$vars ) {
			$this->line = $n->getStartLine();
			// new stdClass: an empty object, exactly what (object) [] makes.
			if ( $n->class instanceof Node\Name && null !== UiChemy_Code_Mode_Allowlist::value_class( $n->class->toString() ) ) {
				$this->eval_args( $n->getArgs(), $vars );
				return new \stdClass();
			}
			$class = $n->class instanceof Node\Name ? UiChemy_Code_Mode_Allowlist::exception_class( $n->class->toString() ) : null;
			if ( null === $class ) {
				$this->halt( 'refused', 'Only exceptions and stdClass can be created, e.g. new \\RuntimeException(...).' );
			}

			list( $positional, $named ) = $this->eval_args( $n->getArgs(), $vars );

			// Each exception holds the one before it, and PHP frees the chain one
			// level inside the next: a chain thousands long crashes the worker.
			$previous = array_key_exists( 'previous', $named ) ? $named['previous'] : ( isset( $positional[2] ) ? $positional[2] : null );
			for ( $hops = 1; $previous instanceof \Throwable; $previous = $previous->getPrevious() ) {
				if ( ++$hops > self::MAX_PREVIOUS ) {
					$this->halt( 'limit', sprintf( 'The script chained more than %d exceptions through $previous, which is not allowed.', self::MAX_PREVIOUS ) );
				}
			}

			if ( $named ) {
				$positional = array_merge( $positional, $named );
			}
			$exception = UiChemy_Code_Mode_Native::make( $class, $positional );

			$this->lines[ $exception ] = $this->line;
			return $this->sanitize( $exception );
		}

		// ------------------------------------------------- functions and closures.

		/**
		 * @param Expr\Closure|Expr\ArrowFunction $n    Expression.
		 * @param array                           $vars Variables.
		 * @return \Closure
		 */
		private function make_closure( Node $n, array &$vars ) {
			$captured = array();
			$cells    = null;
			if ( $n instanceof Expr\ArrowFunction ) {
				// By value, and only the variables its body reads, as PHP does. Copying
				// every variable would make $f = fn() => ... in a loop capture the $f
				// before it, a chain as long as the loop that crashes PHP when freed.
				foreach ( $this->arrow_reads( $n ) as $name ) {
					if ( array_key_exists( $name, $vars ) ) {
						$captured[ $name ] = $vars[ $name ];
					}
				}
			} else {
				foreach ( $n->uses as $use ) {
					$name = $use->var->name;
					if ( $use->byRef ) {
						// Each by-reference capture is a shared slot, counted like $a = &$b[...].
						if ( ++$this->alias_count > self::MAX_ALIASES ) {
							$this->halt( 'limit', sprintf( 'The script took more than %s references, which is not allowed. Pass values in and return them instead.', number_format_i18n( self::MAX_ALIASES ) ) );
						}
						// Kept on an object, not in the array: once the scope that made the
						// closure ends, the reference is held only here, and a reference held
						// only by an array is turned back into a value whenever the array is
						// copied (so a counter made in a function would restart at every call).
						if ( null === $cells ) {
							$cells = new \stdClass();
						}
						if ( ! array_key_exists( $name, $vars ) ) {
							$vars[ $name ] = null;
						}
						$cells->$name = &$vars[ $name ];
					} else {
						$captured[ $name ] = isset( $vars[ $name ] ) ? $vars[ $name ] : null;
					}
				}
			}

			// A closure holding another closure is a level of nesting like any other.
			foreach ( array_merge( $captured, null === $cells ? array() : get_object_vars( $cells ) ) as $value ) {
				if ( is_object( $value ) || is_array( $value ) ) {
					$this->check_shape( $captured );
					if ( null !== $cells ) {
						$this->check_shape( $cells );
					}
					break;
				}
			}

			return function () use ( $n, $captured, $cells ) {
				$locals = $captured;
				$shared = array();
				if ( null !== $cells ) {
					foreach ( array_keys( get_object_vars( $cells ) ) as $name ) {
						$locals[ $name ] = &$cells->$name;
						$shared[]        = $name;
					}
				}
				return $this->invoke( $n, $locals, func_get_args(), array(), '{closure}', $shared );
			};
		}

		/**
		 * The variables an arrow function's body reads, nested functions included.
		 *
		 * @param Expr\ArrowFunction $n Arrow function.
		 * @return array<int,string>
		 */
		private function arrow_reads( Expr\ArrowFunction $n ) {
			$id = spl_object_id( $n );
			if ( ! isset( $this->arrow_vars[ $id ] ) ) {
				$names = array();
				foreach ( ( new \UiChemy\Deps\PhpParser\NodeFinder() )->findInstanceOf( $n->expr, Expr\Variable::class ) as $variable ) {
					if ( is_string( $variable->name ) ) {
						$names[ $variable->name ] = true;
					}
				}
				$this->arrow_vars[ $id ] = array_keys( $names );
			}
			return $this->arrow_vars[ $id ];
		}

		/**
		 * A function the script declared, as a closure ( for name(...) ).
		 *
		 * @param Stmt\Function_ $function Declaration.
		 * @return \Closure
		 */
		private function declared_closure( Stmt\Function_ $function ) {
			return function () use ( $function ) {
				return $this->invoke( $function, array(), func_get_args(), array(), $function->name->toString() );
			};
		}

		/**
		 * Call a function, closure or arrow function the script defined.
		 *
		 * @param Node   $fn         Function_, Closure or ArrowFunction.
		 * @param array  $locals     Variables it starts with (a closure's captured ones).
		 * @param array  $positional Positional arguments.
		 * @param array  $named      Named arguments.
		 * @param string $name       Name for messages.
		 * @return mixed
		 */
		private function invoke( Node $fn, array $locals, array $positional, array $named, $name, array $shared = array() ) {
			if ( ++$this->depth > self::MAX_DEPTH ) {
				--$this->depth;
				$this->halt( 'limit', sprintf( 'The script nested calls deeper than %d levels.', self::MAX_DEPTH ) );
			}
			foreach ( $shared as $alias ) {
				$this->aliases[ $this->depth ][ $alias ] = true;
			}
			$outer               = $this->function_name;
			$this->function_name = $name;
			try {
				$this->tick();
				$this->bind_params( $fn->params, $positional, $named, $locals, $name );

				if ( $fn instanceof Expr\ArrowFunction ) {
					return $this->check_return( $fn, $this->evaluate( $fn->expr, $locals ), true, $name );
				}
				$signal   = $this->exec_stmts( $fn->stmts, $locals );
				$returned = null !== $signal && self::SIG_RETURN === $signal[0];
				return $this->check_return( $fn, $returned ? $signal[1] : null, $returned, $name );
			} finally {
				unset( $this->aliases[ $this->depth ] );
				--$this->depth;
				$this->function_name = $outer;
			}
		}

		/**
		 * How a TypeError names a value: PHP 8.3 says "true"/"false" where 8.2 and
		 * earlier say "bool", so the message is the one this PHP would give.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function value_name( $value ) {
			if ( is_bool( $value ) && PHP_VERSION_ID >= 80300 ) {
				return $value ? 'true' : 'false';
			}
			return UiChemy_Code_Mode_Binder::describe( $value );
		}

		/**
		 * A declared return type, enforced as PHP does under strict_types (an int
		 * where float is declared becomes a float). Checked as the call ends, so a
		 * try/catch inside the function itself does not see the TypeError.
		 *
		 * @param Node   $fn       Function_, Closure or ArrowFunction.
		 * @param mixed  $value    What it returned.
		 * @param bool   $returned Whether a return statement gave it.
		 * @param string $name     Name for messages.
		 * @return mixed The value, coerced.
		 */
		private function check_return( Node $fn, $value, $returned, $name ) {
			$type = isset( $fn->returnType ) ? $fn->returnType : null;
			if ( null === $type ) {
				return $value;
			}
			$declared = strtolower( $this->type_name( $type ) );
			if ( 'void' === $declared ) {
				return null;
			}
			if ( 'never' === $declared ) {
				throw new \TypeError( sprintf( '%s(): never-returning function must not implicitly return', $name ) );
			}
			list( $ok, $coerced ) = $this->accepts( $type, $value );
			if ( ! $ok ) {
				throw new \TypeError( sprintf( '%s(): Return value must be of type %s, %s returned', $name, $this->type_name( $type ), $returned ? self::value_name( $value ) : 'none' ) );
			}
			return $coerced;
		}

		/**
		 * Note whether a variable of the current call is a reference into an
		 * array or object.
		 *
		 * @param string $name  Variable name.
		 * @param bool   $alias Whether it is.
		 */
		private function alias( $name, $alias ) {
			if ( $alias ) {
				$this->aliases[ $this->depth ][ $name ] = true;
			} else {
				unset( $this->aliases[ $this->depth ][ $name ] );
			}
		}

		/**
		 * @param array  $params     Parameter nodes.
		 * @param array  $positional Positional arguments.
		 * @param array  $named      Named arguments.
		 * @param array  $locals     Variables, by reference.
		 * @param string $name       Function name for messages.
		 */
		private function bind_params( array $params, array $positional, array $named, array &$locals, $name ) {
			foreach ( $params as $index => $param ) {
				$variable = $param->var->name;

				// The Guard refuses these; the interpreter refuses them too rather than quietly passing by value.
				if ( $param->byRef ) {
					$this->halt( 'refused', 'Reference parameters (&$x) are not supported. Return the new value, or change an array in place with foreach ($array as &$item).' );
				}

				if ( $param->variadic ) {
					$rest = array_slice( $positional, $index );
					if ( null !== $param->type ) {
						foreach ( $rest as $offset => $each ) {
							list( $ok, $rest[ $offset ] ) = $this->accepts( $param->type, $each );
							if ( ! $ok ) {
								throw new \TypeError( sprintf( '%s(): Argument #%d must be of type %s, %s given', $name, $index + $offset + 1, $this->type_name( $param->type ), self::value_name( $each ) ) );
							}
						}
					}
					$locals[ $variable ] = $rest;
					return;
				}

				if ( array_key_exists( $index, $positional ) ) {
					$value = $positional[ $index ];
				} elseif ( array_key_exists( $variable, $named ) ) {
					$value = $named[ $variable ];
				} elseif ( null !== $param->default ) {
					$locals[ $variable ] = $this->evaluate( $param->default, $locals );
					continue;
				} else {
					$required = 0;
					foreach ( $params as $each ) {
						$required += null === $each->default && ! $each->variadic ? 1 : 0;
					}
					throw new \ArgumentCountError( sprintf( 'Too few arguments to function %s(), %d passed and %d expected', $name, count( $positional ) + count( $named ), $required ) );
				}

				if ( null !== $param->type ) {
					list( $ok, $value ) = $this->accepts( $param->type, $value );
					// int $x = null is implicitly nullable (deprecated in 8.4, still accepted).
					if ( ! $ok && null === $value && $param->default instanceof Expr\ConstFetch && 'null' === strtolower( $param->default->name->toString() ) ) {
						$ok = true;
					}
					if ( ! $ok ) {
						throw new \TypeError( sprintf( '%s(): Argument #%d ($%s) must be of type %s, %s given', $name, $index + 1, $variable, $this->type_name( $param->type ), self::value_name( $value ) ) );
					}
				}
				$locals[ $variable ] = $value;
			}
		}

		/**
		 * Does a value fit a declared parameter type (strict, as the script is)?
		 *
		 * @param Node  $type  Type node.
		 * @param mixed $value Value.
		 * @return array{0:bool,1:mixed} Whether it fits, and the value (an int given for float becomes a float).
		 */
		private function accepts( Node $type, $value ) {
			if ( $type instanceof Node\NullableType ) {
				return null === $value ? array( true, $value ) : $this->accepts( $type->type, $value );
			}
			if ( $type instanceof Node\UnionType ) {
				// An exact match first: float|int given 5 keeps the int, as PHP does.
				$coerced = null;
				foreach ( $type->types as $one ) {
					$result = $this->accepts( $one, $value );
					if ( $result[0] && $result[1] === $value ) {
						return $result;
					}
					if ( $result[0] && null === $coerced ) {
						$coerced = $result;
					}
				}
				return null !== $coerced ? $coerced : array( false, $value );
			}
			if ( $type instanceof Node\IntersectionType ) {
				return array( true, $value );
			}

			$name = strtolower( $type instanceof Node\Identifier ? $type->name : $type->toString() );
			switch ( $name ) {
				case 'int':
					return array( is_int( $value ), $value );
				case 'float':
					return is_int( $value ) ? array( true, (float) $value ) : array( is_float( $value ), $value );
				case 'string':
					return array( is_string( $value ), $value );
				case 'bool':
					return array( is_bool( $value ), $value );
				case 'false':
					return array( false === $value, $value );
				case 'true':
					return array( true === $value, $value );
				case 'null':
					return array( null === $value, $value );
				case 'array':
				case 'iterable':
					return array( is_array( $value ), $value );
				case 'object':
					return array( is_object( $value ), $value );
				case 'callable':
					// A closure, or the name of a function the script may call ("trim").
					if ( is_string( $value ) ) {
						$called = strtolower( ltrim( $value, '\\' ) );
						return array( isset( $this->functions[ $called ] ) || ( 'compact' !== $called && in_array( $called, UiChemy_Code_Mode_Allowlist::functions(), true ) ), $value );
					}
					return array( $value instanceof \Closure, $value );
				case 'mixed':
					return array( true, $value );
				case 'closure':
					return array( $value instanceof \Closure, $value );
				case 'stdclass':
					return array( $value instanceof \stdClass, $value );
			}
			$class = UiChemy_Code_Mode_Allowlist::exception_class( $name );
			return array( null !== $class && $value instanceof $class, $value );
		}

		/**
		 * @param Node $type Type node.
		 * @return string
		 */
		private function type_name( Node $type ) {
			if ( $type instanceof Node\NullableType ) {
				return '?' . $this->type_name( $type->type );
			}
			if ( $type instanceof Node\UnionType ) {
				return implode( '|', array_map( array( $this, 'type_name' ), $type->types ) );
			}
			return $type instanceof Node\Identifier ? $type->name : $type->toString();
		}
	}
}
