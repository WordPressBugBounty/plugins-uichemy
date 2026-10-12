<?php
/**
 * UiChemy_I18n_Composer_Text — the visible text of a Composer widget as translatable strings.
 *
 * A Composer widget keeps its markup in `raw_html`, so WPML's Advanced Translation Editor has
 * no field to offer a translator. This class is the one place that knows how to:
 *
 *   extract()  list every visible text (text nodes + title / alt / placeholder / aria-label)
 *              of a piece of markup, keyed by a hash of its own wording;
 *   apply()    swap those texts for translations held in the widget's `uich_i18n` map.
 *
 * WPML reads through extract() (UiChemy_WPML_Composer_Strings) and the renderer writes through
 * apply(), so the two can never disagree about what counts as "a string".
 *
 * The map lives on the TRANSLATED copy of the page, not on the original:
 *
 *     settings.uich_i18n = [ <key> => [ 'index' => <key>, 'text' => <translation> ], ... ]
 *
 * and is keyed by the ORIGINAL wording (hash of the normalised text). Editing the original
 * wording therefore yields a new string; a stale translation is never applied to new text.
 *
 * @package UiChemy
 * @subpackage UiChemy/i18n
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_I18n_Composer_Text' ) ) {

	/**
	 * Extract / apply visible Composer text.
	 */
	class UiChemy_I18n_Composer_Text {

		/**
		 * Widget setting holding the translation map.
		 */
		const SETTING = 'uich_i18n';

		/**
		 * Attributes whose value is visible (or announced) text.
		 *
		 * @var string[]
		 */
		const ATTRIBUTES = array( 'title', 'alt', 'placeholder', 'aria-label' );

		/**
		 * Elements whose content is code or markup, never copy.
		 *
		 * @var string[]
		 */
		const SKIP_TAGS = array( 'style', 'script', 'noscript', 'template', 'svg', 'textarea', 'code', 'pre' );

		/**
		 * Collapse whitespace so wording that differs only by line breaks is one string.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		public static function normalize( $text ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		}

		/**
		 * Stable key for a normalised wording.
		 *
		 * @param string $normalized Output of normalize().
		 * @return string 16 hex characters.
		 */
		public static function key( $normalized ) {
			return substr( md5( (string) $normalized ), 0, 16 );
		}

		/**
		 * Should this wording be offered to a translator?
		 *
		 * @param string $normalized Normalised text.
		 * @return bool
		 */
		private static function wanted( $normalized ) {
			if ( class_exists( 'UiChemy_I18n_Strings' ) ) {
				return UiChemy_I18n_Strings::is_translatable( $normalized );
			}
			return '' !== $normalized && (bool) preg_match( '/\p{L}/u', $normalized );
		}

		/**
		 * Every visible text in a piece of markup.
		 *
		 * @param string $html Widget `raw_html`.
		 * @return array<string,array{text:string,kind:string}> key => text + kind ('text' | 'attr').
		 */
		public static function extract( $html ) {
			$found = array();
			$dom   = self::parse( $html );
			if ( ! $dom ) {
				return $found;
			}

			self::walk(
				$dom,
				static function ( $kind, $value ) use ( &$found ) {
					$text = UiChemy_I18n_Composer_Text::normalize( $value );
					if ( UiChemy_I18n_Composer_Text::is_wanted( $text ) ) {
						$key = UiChemy_I18n_Composer_Text::key( $text );
						// First sighting wins; a text that is both body copy and an alt stays "text".
						if ( ! isset( $found[ $key ] ) ) {
							$found[ $key ] = array(
								'text' => $text,
								'kind' => $kind,
							);
						}
					}
					return null; // Read-only pass.
				}
			);

			return $found;
		}

		/**
		 * Replace visible texts with their translations, in place.
		 *
		 * @param \DOMNode                 $root Parsed widget markup.
		 * @param array<string,mixed>|string $map  The `uich_i18n` setting (array, or its JSON).
		 * @return int Number of texts replaced.
		 */
		public static function apply( $root, $map ) {
			if ( is_string( $map ) ) {
				$decoded = json_decode( $map, true );
				$map     = is_array( $decoded ) ? $decoded : array();
			}
			if ( ! $root instanceof \DOMNode || ! is_array( $map ) || empty( $map ) ) {
				return 0;
			}

			$count = 0;
			self::walk(
				$root,
				static function ( $kind, $value ) use ( $map, &$count ) {
					$text = UiChemy_I18n_Composer_Text::normalize( $value );
					if ( ! UiChemy_I18n_Composer_Text::is_wanted( $text ) ) {
						return null;
					}
					$key = UiChemy_I18n_Composer_Text::key( $text );
					if ( ! isset( $map[ $key ] ) ) {
						return null;
					}
					$entry       = $map[ $key ];
					$translation = is_array( $entry ) ? ( isset( $entry['text'] ) ? (string) $entry['text'] : '' ) : (string) $entry;
					if ( '' === trim( $translation ) ) {
						return null; // Untranslated: keep the original wording.
					}
					// Surrounding whitespace belongs to the layout, not to the string.
					if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', $value, $m ) ) {
						return null;
					}
					++$count;
					return $m[1] . $translation . $m[3];
				}
			);

			return $count;
		}

		/**
		 * Public wrapper so the closures above (which are static) can reach the private rule.
		 *
		 * @param string $normalized Normalised text.
		 * @return bool
		 */
		public static function is_wanted( $normalized ) {
			return self::wanted( $normalized );
		}

		/**
		 * Parse markup the way the renderer does.
		 *
		 * @param string $html Markup.
		 * @return \DOMDocument|null
		 */
		private static function parse( $html ) {
			if ( ! is_string( $html ) || '' === trim( $html ) ) {
				return null;
			}
			$dom = new \DOMDocument();
			$prev = libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return $dom;
		}

		/**
		 * Visit every visible text and translatable attribute.
		 *
		 * The callback gets ( 'text'|'attr', current value ) and returns the new value, or null
		 * to leave it alone.
		 *
		 * @param \DOMNode $node     Node whose children are visited.
		 * @param callable $callback Visitor.
		 * @return void
		 */
		private static function walk( $node, $callback ) {
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$new = $callback( 'text', $child->data );
					if ( null !== $new && $new !== $child->data ) {
						$child->data = $new;
					}
				} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
					$tag = strtolower( $child->nodeName );
					if ( in_array( $tag, self::SKIP_TAGS, true ) || 0 === strncmp( $tag, 'uichemy-', 8 ) || 0 === strncmp( $tag, 'uichemy:', 8 ) ) {
						continue;
					}
					if ( $child instanceof \DOMElement ) {
						foreach ( self::ATTRIBUTES as $attr ) {
							if ( $child->hasAttribute( $attr ) ) {
								$value = $child->getAttribute( $attr );
								$new   = $callback( 'attr', $value );
								if ( null !== $new && $new !== $value ) {
									$child->setAttribute( $attr, $new );
								}
							}
						}
					}
					self::walk( $child, $callback );
				}
			}
		}
	}
}
