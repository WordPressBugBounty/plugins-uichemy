<?php
/**
 * v2 wording in v3 names.
 *
 * The abilities, their messages and the skills are written for v2, where a
 * call is an ability plus an action:
 *
 *   uichemy-composer/page (action="patch-code", post_id=12)   ->   Page::patchCode(post_id: 12)
 *   `uichemy-composer/memory` `read-memory`                    ->   `Memory::readMemory()`
 *   call "get-code" again        (said by a Page method)       ->   call `Page::getCode()` again
 *   get-ability-info                                           ->   search ("Class::method")
 *
 * v3 serves the same text, so it is rewritten on the way out: method docs (the
 * catalog), call results and errors (the dispatcher), skills (read-skill) and
 * the briefing. v2 is never touched.
 *
 * An action name is only rewritten when it is a real method: of the class
 * the text belongs to (its owner), or of a class named before it in the same
 * paragraph. Anything else is left as written.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Text' ) ) {

	/**
	 * Rewrites v2 call names into v3 ones.
	 */
	class UiChemy_Code_Mode_Text {

		/**
		 * Keys of a result whose strings are hints for the model, where even a
		 * bare kebab name ("get-code / patch-code") is an action. Elsewhere only
		 * the unmistakable forms are rewritten, so data (a title, a slug) is safe.
		 */
		const HINT_KEYS = array( 'fix', 'hint', 'hints', 'message', 'messages', 'note', 'notes', 'next', 'next_step', 'next_steps', 'edit_with', 'how_to_edit', 'how_to_work_it', 'get_started', 'instructions', 'usage', 'usage_example', 'warning', 'warnings', 'ask_user', 'reason', 'help', 'tip', 'tips', 'error', 'gaps', 'blockers', 'todo', 'notice' );

		/** Keys that hold content (code, values), never rewritten. */
		const CONTENT_KEYS = array( 'html', 'css', 'js', 'head_code', 'body_end_code', 'content', 'code', 'value', 'values', 'formatted', 'raw', 'source', 'markup', 'twig', 'item_html', 'body', 'design_md', 'old_text', 'new_text', 'find', 'replace', 'diff', 'lines', 'line', 'match', 'matches', 'title', 'name', 'slug', 'label', 'description', 'url', 'path', 'filename' );

		/** Longest string of a result that is rewritten (messages are short; data can be long). */
		const MAX_RESULT_STRING = 4000;

		/**
		 * Class name => { slug, actions: action => method } for the served classes.
		 *
		 * @param array $modules IR modules.
		 * @return array<string,array>
		 */
		public static function map( array $modules ) {
			$map = array();
			foreach ( $modules as $module ) {
				$actions = array();
				foreach ( $module['fns'] as $fn ) {
					$actions[ '' === $fn['action'] ? 'call' : $fn['action'] ] = $fn['fn'];
				}
				$map[ $module['module'] ] = array(
					'slug'    => $module['slug'],
					'actions' => $actions,
				);
			}
			return $map;
		}

		/**
		 * Rewrite v2 call names in free text.
		 *
		 * @param string $text  Text written for v2.
		 * @param string $owner Class the text belongs to ('' for none).
		 * @param array  $map   From map().
		 * @param bool   $bare  Also rewrite bare kebab names of known actions (hint text only).
		 * @return string
		 */
		public static function to_v3( $text, $owner, array $map, $bare = false ) {
			$text = (string) $text;
			if ( '' === $text ) {
				return $text;
			}
			$text = self::names( $text, $map );
			return self::tidy( self::actions( $text, $owner, $map, $bare ) );
		}

		/**
		 * A call that sat inside a longer code span ("`uichemy-composer/x (action=\"y\") { ... }`")
		 * comes out with a doubled backtick: keep the one span.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public static function tidy( $text ) {
			return (string) preg_replace( '/``([A-Z][A-Za-z0-9]*(?:::[a-zA-Z0-9]+\([^`]*\))?)`/', '`$1', (string) $text );
		}

		/**
		 * Ability names, with or without an action, as classes and methods.
		 *
		 * @param string $text Text.
		 * @param array  $map  From map().
		 * @return string
		 */
		public static function names( $text, array $map ) {
			if ( false === strpos( $text, '/' ) && false === strpos( $text, 'get-ability-info' ) && false === strpos( $text, 'abilit' ) ) {
				return $text;
			}
			$by_slug = array();
			foreach ( $map as $class => $info ) {
				$by_slug[ $info['slug'] ] = $class;
			}
			$prefix = preg_quote( UiChemy_MCP_Server_V2::NAMESPACE_SLUG . '/', '/' );
			$slug   = '([a-z0-9-]+)';
			$method = static function ( $class, $action ) use ( $map ) {
				return $map[ $class ]['actions'][ $action ] ?? lcfirst( UiChemy_Code_Mode_Catalog::studly( $action ) );
			};

			// uichemy-composer/x (action="y", k=v) / `uichemy-composer/x` (`action: "y"`) / ... with `action: "y"`.
			$text = preg_replace_callback(
				'/(\*{0,2})(`(?=' . $prefix . '[a-z0-9-]+`))?(?<![\w\/.-])' . $prefix . $slug . '(?(2)`)(?:\s*\(\s*`?action\s*[=:]\s*\\\\?"([a-z0-9-]+)\\\\?"`?\s*(?:,\s*([^()]*))?\)|\s+with\s+`action:\s*"([a-z0-9-]+)"`)\1/',
				static function ( $m ) use ( $by_slug, $method ) {
					if ( ! isset( $by_slug[ $m[3] ] ) ) {
						return $m[0];
					}
					$class  = $by_slug[ $m[3] ];
					$action = '' !== $m[4] ? $m[4] : $m[6];
					return $m[1] . '`' . $class . '::' . $method( $class, $action ) . '(' . self::args( $m[5] ?? '' ) . ')`' . $m[1];
				},
				$text
			);
			// `uichemy-composer/memory` `read-memory`, and uichemy-composer/x action "y".
			$text = preg_replace_callback(
				'/(`(?=' . $prefix . '[a-z0-9-]+`))?(?<![\w\/.-])' . $prefix . $slug . '(?(1)`)\s+(?:action\s+)?[`"]([a-z][a-z0-9-]*)[`"]/',
				static function ( $m ) use ( $by_slug, $map, $method ) {
					if ( ! isset( $by_slug[ $m[2] ] ) || ! isset( $map[ $by_slug[ $m[2] ] ]['actions'][ $m[3] ] ) ) {
						return $m[0];
					}
					return '`' . $by_slug[ $m[2] ] . '::' . $method( $by_slug[ $m[2] ], $m[3] ) . '()`';
				},
				$text
			);
			// v2's briefing is v3's get_instructions.
			$text = preg_replace( '/`?(?<![\w\/.-])' . $prefix . 'instructions`?/', '`get_instructions`', $text );
			// The name alone.
			$text = preg_replace_callback(
				'/(`(?=' . $prefix . '[a-z0-9-]+`))?(?<![\w\/.-])' . $prefix . $slug . '(?(1)`)(?![\w\/-])/',
				static function ( $m ) use ( $by_slug ) {
					return isset( $by_slug[ $m[2] ] ) ? '`' . $by_slug[ $m[2] ] . '`' : $m[0];
				},
				$text
			);
			// "custom-fields/update": slug/action shorthand.
			$text = preg_replace_callback(
				'/(?<![\w\/.-])([a-z][a-z0-9-]*)\/([a-z][a-z0-9-]*)(?![\w\/-])/',
				static function ( $m ) use ( $by_slug, $map ) {
					$class = $by_slug[ $m[1] ] ?? null;
					return null !== $class && isset( $map[ $class ]['actions'][ $m[2] ] ) ? '`' . $class . '::' . $map[ $class ]['actions'][ $m[2] ] . '()`' : $m[0];
				},
				$text
			);
			return str_replace(
				array( '`get-ability-info`', 'get-ability-info', 'is not this ability', 'through this ability', 'the abilities above', 'this ability', 'action routed' ),
				array( '`search` ("Class::method")', '`search` ("Class::method")', 'is not this class', 'through this class', 'the classes above', 'this class', 'a class of methods' ),
				$text
			);
		}

		/**
		 * Action names left after names(): `action="list"`, `seed-catalog`,
		 * "get-code", `read {since: "mark"}`, as the method they mean - of the
		 * owner when it has it, else of the class named last before it in the
		 * same paragraph. A name no such class has is left as it is.
		 *
		 * @param string $text  Text.
		 * @param string $owner Owner class or ''.
		 * @param array  $map   From map().
		 * @param bool   $bare  Also unquoted kebab names (a-b) of those classes.
		 * @return string
		 */
		public static function actions( $text, $owner, array $map, $bare = false ) {
			$paras = explode( "\n\n", (string) $text );
			foreach ( $paras as $i => $para ) {
				$named   = array();
				$pattern = '`([A-Z][A-Za-z0-9]*)(?:::[a-zA-Z0-9]+\([^`]*\))?`'                       // a class: remembered
					. '|`?action\s*[=:]\s*\\\\?"?([a-z][a-z0-9-]*)(\s*\([^)"]*\))?\\\\?"?`?'           // action="x", action=x
					. '|[`"]([a-z][a-z0-9-]*)(?:\s*\{([^}`"]*(?:"[^"]*"[^}`"]*)*)\})?[`"]'             // `x`, "x", `x {a: 1}`
					. ( $bare ? '|(?<![\w\/.#$-])([a-z][a-z0-9]*(?:-[a-z0-9]+)+)(?![\w\/(-])(?!\.\w)' : '' ); // get-code
				$paras[ $i ] = preg_replace_callback(
					'/' . $pattern . '/',
					static function ( $m ) use ( &$named, $owner, $map ) {
						if ( '' !== ( $m[1] ?? '' ) ) {
							if ( isset( $map[ $m[1] ] ) ) {
								array_unshift( $named, $m[1] );
							}
							return $m[0];
						}
						$action = '';
						$args   = '';
						$suffix = '';
						if ( '' !== ( $m[2] ?? '' ) ) {
							$action = $m[2];
							$suffix = trim( $m[3] ?? '' );
						} elseif ( '' !== ( $m[4] ?? '' ) ) {
							$action = $m[4];
							$args   = self::args( $m[5] ?? '' );
						} else {
							$action = $m[6] ?? '';
						}
						foreach ( array_unique( array_filter( array_merge( array( $owner ), $named ) ) ) as $class ) {
							if ( isset( $map[ $class ]['actions'][ $action ] ) ) {
								return '`' . $class . '::' . $map[ $class ]['actions'][ $action ] . '(' . $args . ')`' . ( '' !== $suffix ? ' ' . $suffix : '' );
							}
						}
						return $m[0];
					},
					$para
				);
				// "`Platform`, `Platform::x()`" and "`Performance`'s `Performance::x()`" say it twice.
				$paras[ $i ] = preg_replace( '/`([A-Z][A-Za-z0-9]*)`(?:,|\'s)\s+`\1::/', '`$1::', $paras[ $i ] );
			}
			return implode( "\n\n", $paras );
		}

		/**
		 * A call result with its messages rewritten: only strings under HINT_KEYS.
		 * Everything else (code, values, titles, alt text) is left exactly as stored;
		 * read-skill's text is rewritten whole by the dispatcher.
		 *
		 * @param mixed  $value Result.
		 * @param string $owner Class that answered.
		 * @param array  $map   From map().
		 * @param string $key   Key the value sits under.
		 * @param int    $depth Depth.
		 * @return mixed
		 */
		public static function result( $value, $owner, array $map, $key = '', $depth = 0 ) {
			if ( is_string( $value ) ) {
				// Only the strings that talk to the model (fix, message, note ...) are
				// rewritten. Everything else is data a script may write back, and must
				// come back exactly as stored.
				if ( ! in_array( $key, self::HINT_KEYS, true ) || strlen( $value ) > self::MAX_RESULT_STRING ) {
					return $value;
				}
				return self::to_v3( $value, $owner, $map, true );
			}
			if ( ! is_array( $value ) || $depth > 8 || in_array( $key, self::CONTENT_KEYS, true ) ) {
				return $value;
			}
			$hint = in_array( $key, self::HINT_KEYS, true );
			foreach ( $value as $k => $item ) {
				// A list under a hint key (warnings, gaps) is hints too.
				$value[ $k ] = self::result( $item, $owner, $map, is_int( $k ) && $hint ? $key : (string) $k, $depth + 1 );
			}
			return $value;
		}

		/**
		 * "k=v, k2=v2" or "a: 1, b: \"x\"" or "\"a\": 1" as named arguments "k: v, k2: v2".
		 *
		 * @param string $args Raw argument text.
		 * @return string
		 */
		private static function args( $args ) {
			$args = trim( (string) $args );
			if ( '' === $args ) {
				return '';
			}
			$args = preg_replace( '/"([a-z_][a-z0-9_]*)"\s*:/i', '$1:', $args );
			return (string) preg_replace( '/(^|,\s*)([a-z_][a-z0-9_]*)\s*=\s*/i', '$1$2: ', $args );
		}
	}
}
