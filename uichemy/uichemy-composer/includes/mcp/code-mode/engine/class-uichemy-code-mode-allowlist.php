<?php
/**
 * What a Code Mode script may name: the single source of truth for the Guard
 * (which refuses everything else before a script runs) and the interpreter
 * (which only implements what is listed here).
 *
 * Every function here is pure: it reads only its arguments (and, for the date
 * functions, the clock and the default time zone) and changes nothing but what
 * it returns or the by-reference argument it is given. Where one can build a
 * large value in one step, the interpreter sizes the call first (guard_native).
 *
 * Randomness: rand, mt_rand, shuffle, str_shuffle, array_rand, uniqid and
 * mt_srand/srand draw from the run's own seeded generator
 * (UiChemy_Code_Mode_Random), so a dry run and the real run given its seed make
 * the same writes, and PHP's global generator is never touched. random_int and
 * random_bytes are PHP's own CSPRNG, unseeded, so what they make stays secret.
 *
 * Deliberately absent:
 * - anything that can reach a file, socket, process, environment variable,
 *   ini setting, the output buffer or global state (setlocale,
 *   date_default_timezone_set, extract, one-argument parse_str);
 * - anything that calls a name it is given (call_user_func, the u*diff family
 *   with their trailing callbacks, iterator_apply) - callbacks reach PHP only
 *   through callable_arguments(), where the interpreter resolves them;
 * - unserialize (object injection);
 * - WordPress functions: most run filters, which is third-party code.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Allowlist' ) ) {

	/**
	 * Allowed functions, exception classes, methods and constants.
	 */
	class UiChemy_Code_Mode_Allowlist {

		/**
		 * Functions a script may call.
		 *
		 * @return array<int,string>
		 */
		public static function functions() {
			return array(
				// Strings.
				'strlen', 'str_contains', 'str_starts_with', 'str_ends_with', 'str_replace', 'str_ireplace',
				'substr', 'substr_count', 'strpos', 'stripos', 'strtolower', 'strtoupper', 'ucfirst', 'trim',
				'ltrim', 'rtrim', 'explode', 'implode', 'sprintf', 'str_pad', 'str_repeat', 'nl2br', 'wordwrap',
				'strip_tags', 'html_entity_decode', 'htmlspecialchars', 'mb_strlen', 'mb_substr',
				'mb_strtolower', 'mb_strtoupper',
				'ucwords', 'lcfirst', 'strrev', 'str_word_count', 'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp',
				'strnatcmp', 'strnatcasecmp', 'substr_compare', 'strrpos', 'strripos', 'strstr', 'stristr', 'strrchr',
				'strtr', 'substr_replace', 'str_split', 'mb_str_split', 'mb_strpos', 'mb_stripos', 'mb_strrpos',
				'mb_substr_count', 'vsprintf', 'addslashes', 'stripslashes', 'quotemeta', 'htmlentities',
				'htmlspecialchars_decode',
				// Encoding and hashing.
				'md5', 'sha1', 'crc32', 'hash', 'hash_hmac', 'base64_encode', 'base64_decode', 'bin2hex', 'hex2bin',
				'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode', 'http_build_query', 'parse_url',
				// Regex. PCRE's own backtrack and JIT limits stop a runaway pattern.
				'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_quote',
				// Arrays.
				'count', 'array_map', 'array_filter', 'array_values', 'array_keys', 'array_slice', 'array_splice',
				'array_merge', 'array_unique', 'array_column', 'array_combine', 'array_flip', 'array_fill_keys',
				'array_key_exists', 'array_key_first', 'array_key_last', 'array_search', 'array_sum',
				'array_reduce', 'array_reverse', 'array_chunk', 'array_count_values', 'in_array', 'range', 'sort',
				'rsort', 'usort', 'uasort', 'ksort', 'krsort', 'min', 'max',
				'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_diff', 'array_diff_key',
				'array_diff_assoc', 'array_intersect', 'array_intersect_key', 'array_intersect_assoc', 'array_fill',
				'array_pad', 'array_replace', 'array_replace_recursive', 'array_merge_recursive', 'array_product',
				'array_is_list', 'array_change_key_case', 'key_exists', 'array_walk', 'asort', 'arsort', 'uksort',
				'natsort', 'natcasesort', 'compact',
				// Math and types.
				'abs', 'round', 'floor', 'ceil', 'intdiv', 'fmod', 'number_format', 'intval', 'floatval', 'strval',
				'boolval', 'is_int', 'is_string', 'is_array', 'is_bool', 'is_float', 'is_numeric', 'is_null',
				'gettype', 'get_debug_type',
				'is_object', 'is_scalar', 'is_iterable', 'is_countable', 'is_nan', 'is_finite', 'is_infinite',
				'sqrt', 'pow', 'exp', 'log', 'log10', 'pi', 'fdiv', 'hypot', 'sin', 'cos', 'tan', 'asin', 'acos',
				'atan', 'atan2', 'deg2rad', 'rad2deg', 'base_convert', 'bindec', 'decbin', 'dechex', 'hexdec',
				'octdec', 'decoct',
				'get_class', 'get_object_vars', 'chr', 'ord', 'function_exists',
				'json_last_error', 'json_last_error_msg', 'preg_last_error', 'preg_last_error_msg',
				'ctype_alnum', 'ctype_alpha', 'ctype_digit', 'ctype_lower', 'ctype_upper', 'ctype_space',
				'ctype_punct', 'ctype_xdigit',
				// Date and time: the clock and the default time zone are read, never set.
				'time', 'microtime', 'date', 'gmdate', 'mktime', 'gmmktime', 'strtotime', 'checkdate',
				'date_default_timezone_get',
				// JSON.
				'json_encode', 'json_decode',
				// Randomness. The seeded ones are carried out by UiChemy_Code_Mode_Random.
				'rand', 'mt_rand', 'mt_srand', 'srand', 'shuffle', 'str_shuffle', 'array_rand', 'uniqid',
				'random_int', 'random_bytes', 'mt_getrandmax', 'getrandmax',
				// What a script needs to do itself what a one-file grep or a filename
				// filter used to need a tool call for. levenshtein and similar_text are
				// sized first (their time grows with the product of the lengths);
				// filter_var refuses FILTER_CALLBACK, which would call a name.
				'preg_grep', 'fnmatch', 'str_getcsv', 'mb_strimwidth', 'mb_convert_case', 'levenshtein',
				'similar_text', 'array_walk_recursive', 'filter_var',
				// Common in model-written PHP: end(explode(...)), reset($list), sizeof, join.
				// The pointer functions take arrays and stdClass only (an exception's or a
				// closure's internals stay out of reach); parse_str only with its result array.
				'end', 'reset', 'current', 'key', 'next', 'prev', 'pos', 'sizeof', 'join', 'chunk_split', 'parse_str',
				// Output. These print into the run's "logs" as echo does, never to the response
				// itself, and refuse closures and exceptions (they would print the interpreter).
				'printf', 'print_r', 'var_export', 'var_dump',
			);
		}

		/**
		 * Allowed functions PHP runs on the interpreter's side (they need the
		 * script's variables or its log), never handed to PHP by name.
		 *
		 * @return array<int,string>
		 */
		public static function intrinsics() {
			return array( 'compact', 'printf', 'print_r', 'var_export', 'var_dump', 'get_class', 'get_object_vars', 'function_exists', 'json_last_error', 'json_last_error_msg', 'preg_last_error', 'preg_last_error_msg', 'filter_var', 'rand', 'mt_rand', 'mt_srand', 'srand', 'shuffle', 'str_shuffle', 'array_rand', 'uniqid' );
		}

		/**
		 * Allowlisted functions that take a callable, and the argument index it sits at.
		 *
		 * The interpreter resolves what is passed there before PHP sees it: a
		 * closure this run made, or the name of an allowed or script-declared
		 * function (which becomes a closure making the same checked call). Any
		 * other string, an array callable or a closure from elsewhere is refused,
		 * so a callback can never name a method or an unlisted function.
		 *
		 * @return array<string,int>
		 */
		public static function callable_arguments() {
			return array(
				'array_map'             => 0,
				'array_filter'          => 1,
				'array_reduce'          => 1,
				'usort'                 => 1,
				'uasort'                => 1,
				'uksort'                => 1,
				'array_walk'            => 1,
				'array_walk_recursive'  => 1,
				'preg_replace_callback' => 1,
			);
		}

		/**
		 * Classes a script may create with new, besides exceptions. stdClass
		 * holds only what is put in it, as (object) [] does.
		 *
		 * @return array<int,string>
		 */
		public static function value_classes() {
			return array( 'stdClass' );
		}

		/**
		 * Classes a script may name in catch, instanceof and new.
		 *
		 * @return array<int,string>
		 */
		public static function exception_classes() {
			return array(
				'Throwable', 'Exception', 'Error', 'RuntimeException', 'LogicException', 'InvalidArgumentException',
				'UnexpectedValueException', 'DomainException', 'RangeException', 'LengthException',
				'OutOfBoundsException', 'OutOfRangeException', 'TypeError', 'ValueError', 'ArgumentCountError',
				'ArithmeticError', 'DivisionByZeroError', 'JsonException', 'UnhandledMatchError',
				'BadFunctionCallException', 'BadMethodCallException', 'OverflowException', 'UnderflowException',
			);
		}

		/**
		 * Methods a script may call on an object it holds (lowercase).
		 *
		 * @return array<int,string>
		 */
		public static function methods() {
			return array( 'getmessage', 'getcode', 'getline', 'getprevious', 'getfile', 'gettrace', 'gettraceasstring' );
		}

		/**
		 * @return string The refusal for any other method call.
		 */
		public static function methods_message() {
			return 'Only getMessage(), getCode(), getLine(), getPrevious(), getFile(), getTrace() and getTraceAsString() can be called, and only on an exception.';
		}

		/**
		 * Global constants a script may read: exact names, then prefixes.
		 *
		 * @return array{names: array<int,string>, prefixes: array<int,string>}
		 */
		public static function constants() {
			return array(
				'names'    => array(
					'true', 'false', 'null', 'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_INT_SIZE', 'PHP_FLOAT_EPSILON',
					'PHP_FLOAT_MAX', 'PHP_FLOAT_MIN', 'PHP_FLOAT_DIG', 'NAN', 'INF', 'PHP_VERSION', 'PHP_MAJOR_VERSION',
					'PHP_MINOR_VERSION', 'PHP_RELEASE_VERSION', 'PHP_VERSION_ID', 'M_PI', 'M_E', 'M_PI_2', 'M_PI_4',
					'M_1_PI', 'M_2_PI', 'M_SQRT2', 'M_SQRT1_2', 'M_LN2', 'M_LN10', 'M_LOG2E', 'M_LOG10E',
					'COUNT_RECURSIVE', 'COUNT_NORMAL', 'CASE_LOWER', 'CASE_UPPER',
				),
				'prefixes' => array( 'JSON_', 'SORT_', 'ARRAY_FILTER_', 'PREG_', 'ENT_', 'STR_PAD_', 'PHP_URL_', 'PHP_ROUND_', 'PHP_QUERY_', 'DATE_', 'MB_CASE_', 'E_', 'FILTER_', 'FNM_' ),
			);
		}

		/**
		 * Is this a constant PHP itself defines? A prefix such as JSON_ or DATE_
		 * would otherwise also match a constant a plugin defined, which may hold
		 * a secret (a JSON_API_KEY, say).
		 *
		 * @param string $name Constant name.
		 * @return bool
		 */
		private static function is_php_constant( $name ) {
			static $user = null;
			if ( null === $user ) {
				$all  = get_defined_constants( true );
				$user = isset( $all['user'] ) ? $all['user'] : array();
			}
			// Read again for a name not seen yet: a plugin can define one later in the request.
			if ( ! array_key_exists( $name, $user ) && defined( $name ) ) {
				$all = get_defined_constants( true );
				if ( isset( $all['user'] ) && array_key_exists( $name, $all['user'] ) ) {
					$user = $all['user'];
				}
			}
			return ! array_key_exists( $name, $user );
		}

		/**
		 * The global function a call names, lowercase, or null when the name
		 * carries a namespace (Foo\bar()). Read by the Guard and the interpreter.
		 *
		 * @param \UiChemy\Deps\PhpParser\Node\Name $name Name as written.
		 * @return string|null
		 */
		public static function function_name( $name ) {
			if ( $name->isFullyQualified() ) {
				return 1 === count( $name->getParts() ) ? strtolower( $name->toString() ) : null;
			}
			return $name->isUnqualified() ? strtolower( $name->toString() ) : null;
		}

		/**
		 * The constant a name reads, when it is allowed ("true", "false" and
		 * "null" lowercase), or null. Read by the Guard and the interpreter.
		 *
		 * @param \UiChemy\Deps\PhpParser\Node\Name $name Name as written.
		 * @return string|null
		 */
		public static function constant_name( $name ) {
			if ( ! $name->isUnqualified() && ! ( $name->isFullyQualified() && 1 === count( $name->getParts() ) ) ) {
				return null;
			}
			$constant = $name->getLast();
			if ( in_array( strtolower( $constant ), array( 'true', 'false', 'null' ), true ) ) {
				return strtolower( $constant );
			}
			$allowed = self::constants();
			if ( in_array( $constant, $allowed['names'], true ) ) {
				return $constant;
			}
			foreach ( $allowed['prefixes'] as $prefix ) {
				if ( 0 === strpos( $constant, $prefix ) ) {
					return self::is_php_constant( $constant ) ? $constant : null;
				}
			}
			return null;
		}

		/**
		 * The canonical spelling of an allowed exception class, or null.
		 *
		 * @param string $name Class name as written.
		 * @return string|null
		 */
		public static function exception_class( $name ) {
			static $map = null;
			if ( null === $map ) {
				$map = array();
				foreach ( self::exception_classes() as $class ) {
					$map[ strtolower( $class ) ] = $class;
				}
			}
			$key = strtolower( ltrim( (string) $name, '\\' ) );
			return isset( $map[ $key ] ) ? $map[ $key ] : null;
		}

		/**
		 * The canonical spelling of an allowed value class (stdClass), or null.
		 *
		 * @param string $name Class name as written.
		 * @return string|null
		 */
		public static function value_class( $name ) {
			$key = strtolower( ltrim( (string) $name, '\\' ) );
			foreach ( self::value_classes() as $class ) {
				if ( strtolower( $class ) === $key ) {
					return $class;
				}
			}
			return null;
		}
	}
}
