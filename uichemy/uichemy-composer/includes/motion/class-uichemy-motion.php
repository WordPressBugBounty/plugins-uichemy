<?php
/**
 * UiChemy_Motion — motion variables for a section's authored JavaScript.
 *
 * A section animates with ordinary GSAP written in its `raw_js`. There is no
 * fixed animation schema, so the full GSAP API stays reachable. The one contract
 * on top is that any value a designer might want to tune is declared as a motion
 * variable instead of being typed inline, which is what lets the Composer build a
 * control panel out of the JS itself:
 *
 *     const uichemy_controller_bridge = {
 *       "rise": { "value": 60, "unit": "px", "target": ".hero" }
 *     };
 *     gsap.from( '.hero', { y: uichemy_controller_bridge.rise } );
 *
 * Both halves are ORDINARY JavaScript — the declaration is a real statement and
 * the read is a real property access. That is the whole trick: the stored source
 * is valid JS at rest, so the Code tab's formatter, the editor's highlighting and
 * any linter all work on it. (It used to be a `/* @uichemy-motion … *\/` comment,
 * which was inert but also invisible to every one of those tools. A
 * template-style `{{ … }}` token was tried before that and was worse still: not
 * valid JS at all, and the formatter shredded one into loose braces.)
 *
 * The object is required to be JSON on purpose. PHP reads it with json_decode(),
 * the editor panel with JSON.parse(), and the panel writes a changed value back
 * with json_encode() — so the three sides can never drift the way a hand-rolled
 * mini-syntax and two separate parsers would. It also means neither side has to
 * ship a JavaScript parser to read the other's text.
 *
 * compile() performs no surgery on the author's code at all: it replaces the
 * declaration statement with `var uichemy_controller_bridge = { … }` carrying the
 * current values, and leaves every other line alone. Nothing in the body is
 * rewritten, so nothing in the body can break.
 *
 * Values therefore reach the browser as JSON DATA, never as code. A value cannot
 * widen what the JS does no matter what it contains, which is what makes it safe
 * to let the panel (Pro) edit values for an author who could not have written the
 * JS themselves.
 *
 * Builder-agnostic: Elementor, Gutenberg and Bricks all run a section's raw_js,
 * and each one calls compile() on the way out.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Motion' ) ) {

	class UiChemy_Motion {

		/**
		 * Opens the declaration: `const uichemy_controller_bridge = {`.
		 *
		 * Only the opener is a regex. Where the object ENDS is found by walking
		 * the braces (see find_declaration), because a regex cannot balance them
		 * and a label may legally contain a `}`.
		 */
		const DECL_RE = '#\b(?:const|let|var)\s+uichemy_controller_bridge\s*=\s*\{#';

		/**
		 * A variable read in the author's code: `uichemy_controller_bridge.name`. Used only to
		 * report which variables the code references — the code itself is never
		 * rewritten.
		 */
		const READ_RE = '#\buichemy_controller_bridge\.([a-z][a-z0-9_]*)\b#';

		/**
		 * The identifier the preamble binds. Namespaced on purpose: `motion` is a
		 * name the page may already want — Motion One and Framer Motion both
		 * publish a global called `motion` — and a bare `var motion` preamble
		 * shadowed it for the whole section. `uichemy_controller_bridge` is reserved inside a
		 * section's JS; an author variable of that name would be shadowed.
		 */
		const VAR_NAME = 'uichemy_controller_bridge';

		/**
		 * Whether this JS carries anything for us to do. Cheap string checks, so
		 * the common case (a section with no animation) costs almost nothing.
		 *
		 * @param string $js Author JavaScript.
		 * @return bool
		 */
		public static function has_motion( $js ) {
			if ( ! is_string( $js ) || '' === $js ) {
				return false;
			}
			if ( false === strpos( $js, self::VAR_NAME ) ) {
				return false;
			}
			if ( 1 === preg_match( self::DECL_RE, $js ) ) {
				return true;
			}
			// Code that reads the variable with no declaration still needs the
			// preamble: without it the name is undefined and the read throws a
			// ReferenceError that kills the whole script. With an empty preamble the
			// read is merely `undefined`, which GSAP treats as "not set".
			return 1 === preg_match( self::READ_RE, $js );
		}

		/**
		 * Locate the declaration statement.
		 *
		 * Returns `array{ start:int, end:int, json:string }`, or null when there
		 * is none. `json` is the `{ … }` source, which the contract requires to be
		 * JSON — that is what lets PHP and the JS panel read the identical text
		 * without either side shipping a JavaScript parser.
		 *
		 * @param string $js Author JavaScript.
		 * @return array{start:int,end:int,json:string}|null
		 */
		private static function find_declaration( $js ) {
			if ( ! preg_match( self::DECL_RE, $js, $m, PREG_OFFSET_CAPTURE ) ) {
				return null;
			}
			$start = (int) $m[0][1];
			$open  = strpos( $js, '{', $start );
			if ( false === $open ) {
				return null;
			}

			$depth  = 0;
			$in_str = false;
			$quote  = '';
			$esc    = false;
			$len    = strlen( $js );

			for ( $i = $open; $i < $len; $i++ ) {
				$c = $js[ $i ];

				if ( $in_str ) {
					if ( $esc ) {
						$esc = false;
						continue;
					}
					if ( '\\' === $c ) {
						$esc = true;
						continue;
					}
					if ( $c === $quote ) {
						$in_str = false;
					}
					continue;
				}

				if ( '"' === $c || "'" === $c ) {
					$in_str = true;
					$quote  = $c;
					continue;
				}
				if ( '{' === $c ) {
					$depth++;
					continue;
				}
				if ( '}' !== $c ) {
					continue;
				}
				$depth--;
				if ( 0 !== $depth ) {
					continue;
				}

				// Swallow the trailing `;` and its newline, so replacing the
				// declaration never leaves a stray semicolon on its own line.
				$end = $i + 1;
				while ( $end < $len && ( ';' === $js[ $end ] || ' ' === $js[ $end ] || "\t" === $js[ $end ] ) ) {
					$end++;
				}
				if ( $end < $len && "\n" === $js[ $end ] ) {
					$end++;
				}

				return array(
					'start' => $start,
					'end'   => $end,
					'json'  => substr( $js, $open, $i - $open + 1 ),
				);
			}

			// Unbalanced braces — report "no declaration" rather than guess where
			// it ended and risk cutting the author's code in half.
			return null;
		}

		/**
		 * The declaration, decoded.
		 *
		 * A malformed or absent block yields an empty array rather than an error:
		 * the animation must still run (it just gets no tunable values), because a
		 * broken comment should never take a live section down.
		 *
		 * @param string $js Author JavaScript.
		 * @return array<string,array> name => spec ( value + optional metadata ).
		 */
		public static function parse( $js ) {
			if ( ! is_string( $js ) || '' === $js ) {
				return array();
			}
			$decl = self::find_declaration( $js );
			if ( null === $decl ) {
				return array();
			}

			$decoded = json_decode( $decl['json'], true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}

			$out = array();
			foreach ( $decoded as $name => $spec ) {
				// A name has to be usable as a JS property AND as a token, so hold
				// both to the same rule the skill documents.
				if ( ! is_string( $name ) || ! preg_match( '#^[a-z][a-z0-9_]*$#', $name ) ) {
					continue;
				}
				// Shorthand: `"rise": 60` means `"rise": { "value": 60 }`. Not in the
				// authoring contract, but accepting it costs nothing and an AI that
				// abbreviates still produces a working section.
				if ( ! is_array( $spec ) ) {
					$out[ $name ] = array( 'value' => $spec );
					continue;
				}
				if ( ! array_key_exists( 'value', $spec ) ) {
					continue;
				}
				$out[ $name ] = $spec;
			}

			return $out;
		}

		/**
		 * Just the values, ready to be encoded into the preamble.
		 *
		 * @param string $js Author JavaScript.
		 * @return array<string,mixed> name => value.
		 */
		public static function values( $js ) {
			$out = array();
			foreach ( self::parse( $js ) as $name => $spec ) {
				$out[ $name ] = $spec['value'];
			}
			return $out;
		}

		/**
		 * Source JS → runnable JS.
		 *
		 * Drops the declaration comment and prepends the values as a preamble. The
		 * author's code passes through verbatim — it already reads values as
		 * `uichemy_controller_bridge.name`, so there is nothing to rewrite and nothing that can be
		 * mangled. JS with no motion block is returned untouched, so a section that
		 * does not animate carries no cost at all.
		 *
		 * A `uichemy_controller_bridge.name` with no declaration reads a missing property, i.e.
		 * `undefined` — which GSAP treats as "property not set". So a half-written
		 * block degrades to the un-animated property instead of throwing.
		 *
		 * @param string $js Author JavaScript.
		 * @return string Runnable JavaScript.
		 */
		public static function compile( $js ) {
			if ( ! is_string( $js ) || ! self::has_motion( $js ) ) {
				return (string) $js;
			}

			$values = self::values( $js );

			$json = wp_json_encode( (object) $values );
			if ( false === $json ) {
				$json = '{}';
			}

			// `var`, not `const`: it runs inside a Function body (see the builders'
			// JS wrappers) so it never reaches window either way, but `var` is
			// hoisted — a read that somehow sits above the declaration then reads
			// `undefined` instead of throwing on the temporal dead zone.
			$preamble = 'var ' . self::VAR_NAME . ' = ' . $json . ";\n";

			$decl = self::find_declaration( $js );
			if ( null === $decl ) {
				// Reads with no declaration: bind an empty object in front of the
				// code so the read cannot throw a ReferenceError.
				return $preamble . ltrim( $js, "\n" );
			}

			// Replace the declaration IN PLACE. The author's metadata — labels,
			// min, max, help — is the stored source's business, not the browser's,
			// and on a page with many animated sections those bytes add up.
			return substr( $js, 0, $decl['start'] ) . $preamble . substr( $js, $decl['end'] );
		}
	}
}
