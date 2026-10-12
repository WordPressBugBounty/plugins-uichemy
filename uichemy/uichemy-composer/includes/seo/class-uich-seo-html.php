<?php
/**
 * Hardened HTML parsing for the SEO module.
 *
 * Every DOM pass the SEO engine makes goes through here, so the libxml flags
 * are decided once: LIBXML_NONET (never fetch over the network) and never
 * LIBXML_NOENT (never substitute entities) — the pair that closes XXE.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Html' ) ) {

	/**
	 * DOM helpers. Pure: no WordPress state.
	 */
	class Uich_SEO_Html {

		/** Id of the wrapper the fragment is parsed into. */
		const ROOT_ID = 'uich-seo-root';

		/**
		 * Number of documents parsed in this request. The front end must leave
		 * this at zero — the test harness asserts it (PRD §16.2).
		 *
		 * @var int
		 */
		public static $parse_count = 0;

		/**
		 * Tags whose children never count as content and are skipped by the
		 * Composer node path (mirrors SKIP_TAGS in composer-layer-tree.jsx).
		 */
		const PATH_SKIP = array( 'script', 'style', 'template', 'noscript', 'meta', 'link', 'head' );

		/**
		 * Parse an HTML fragment into a DOM whose content sits inside one root div.
		 *
		 * @param string $html Fragment.
		 * @return array{0:DOMDocument,1:DOMElement}
		 */
		public static function fragment( $html ) {
			$doc  = self::load(
				'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="' . self::ROOT_ID . '">'
				. (string) $html
				. '</div></body></html>'
			);
			$root = $doc->getElementById( self::ROOT_ID );
			if ( ! $root ) {
				$root = $doc->createElement( 'div' );
				$doc->appendChild( $root );
			}
			return array( $doc, $root );
		}

		/**
		 * Parse a full HTML document (head included), for import.
		 *
		 * @param string $html Document.
		 * @return DOMDocument
		 */
		public static function document( $html ) {
			$html = (string) $html;
			// Make sure libxml reads it as UTF-8 even without a charset meta.
			if ( ! preg_match( '/<meta[^>]+charset/i', $html ) ) {
				$html = preg_replace( '/<head([^>]*)>/i', '<head$1><meta charset="utf-8">', $html, 1, $count );
				if ( ! $count ) {
					$html = '<meta charset="utf-8">' . $html;
				}
			}
			return self::load( $html );
		}

		/**
		 * The one place libxml is called.
		 *
		 * @param string $html Markup.
		 * @return DOMDocument
		 */
		private static function load( $html ) {
			++self::$parse_count;

			$doc       = new DOMDocument( '1.0', 'UTF-8' );
			$prev      = libxml_use_internal_errors( true );
			$prev_load = null;
			if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
				$prev_load = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions -- PHP < 8 only.
			}

			$doc->loadHTML( $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT );

			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			if ( null !== $prev_load ) {
				libxml_disable_entity_loader( $prev_load ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions -- PHP < 8 only.
			}

			return $doc;
		}

		/**
		 * Element children that count for the Composer node path.
		 *
		 * @param DOMNode $node Parent.
		 * @return DOMElement[]
		 */
		public static function path_children( $node ) {
			$out = array();
			if ( ! $node || ! $node->hasChildNodes() ) {
				return $out;
			}
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && ! in_array( strtolower( $child->nodeName ), self::PATH_SKIP, true ) ) {
					$out[] = $child;
				}
			}
			return $out;
		}

		/**
		 * Composer node path ("0.2.1") of an element relative to a root, in the
		 * format resolveNodeByPath() in composer-layer-tree.jsx reads.
		 *
		 * @param DOMElement $el   Element.
		 * @param DOMElement $root Root (the section wrapper).
		 * @return string|null
		 */
		public static function node_path( $el, $root ) {
			$chain = array();
			$node  = $el;
			while ( $node && ! $node->isSameNode( $root ) ) {
				$parent = $node->parentNode;
				if ( ! $parent ) {
					return null;
				}
				$idx = 0;
				$hit = false;
				foreach ( self::path_children( $parent ) as $i => $kid ) {
					if ( $kid->isSameNode( $node ) ) {
						$idx = $i;
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					return null;
				}
				array_unshift( $chain, $idx );
				$node = $parent;
			}
			if ( ! $node ) {
				return null;
			}
			return implode( '.', array_merge( array( 0 ), $chain ) );
		}

		/**
		 * Visible text of a node, whitespace collapsed, skipping script/style.
		 *
		 * @param DOMNode $node Node.
		 * @return string
		 */
		public static function text( $node ) {
			if ( ! $node ) {
				return '';
			}
			if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
				return (string) $node->nodeValue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				return '';
			}
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, array( 'script', 'style', 'template', 'noscript', 'svg' ), true ) ) {
				return '';
			}
			$buf = '';
			foreach ( $node->childNodes as $child ) {
				$buf .= self::text( $child );
				if ( XML_ELEMENT_NODE === $child->nodeType && self::is_block( $child->nodeName ) ) {
					$buf .= ' ';
				}
			}
			return $buf;
		}

		/**
		 * Readable plain text from HTML, without a DOM parse (safe on the front end):
		 * drops style/script/noscript/template blocks with their contents, puts a
		 * space where every block element ends so words never glue together
		 * ("Big DayFish"), removes leftover [shortcode] tokens WordPress did not
		 * recognise, then strips tags and collapses whitespace.
		 *
		 * @param string $html HTML.
		 * @return string
		 */
		public static function plain( $html ) {
			$s = (string) $html;
			$s = preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1\s*>#is', ' ', $s );
			$s = preg_replace( '#<!--.*?-->#s', ' ', $s );
			$s = preg_replace( '#<(br|hr)\b[^>]*>#i', ' ', $s );
			$s = preg_replace( '#</(p|div|li|h[1-6]|td|th|tr|dt|dd|blockquote|pre|section|article|header|footer|figcaption|figure|ul|ol|table|summary|details|nav|aside|main|address)\s*>#i', '$0 ', $s );
			$s = self::strip_shortcodes( $s );
			$s = preg_replace( '#<[^>]*>#', '', (string) $s );
			return self::squish( $s );
		}

		/**
		 * A post title as plain text: tags removed, entities (`&#8220;`, `&amp;`) decoded.
		 * For places that are not HTML: structured data, llms.txt, Markdown.
		 *
		 * @param string $title Title as stored or filtered.
		 * @return string
		 */
		public static function title( $title ) {
			return self::squish( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		/**
		 * Remove any [name …] / [/name] token, registered or not.
		 *
		 * @param string $s Text.
		 * @return string
		 */
		public static function strip_shortcodes( $s ) {
			return (string) preg_replace( '#\[/?[a-z][a-z0-9_-]*(?:\s[^\[\]]*)?/?\]#i', ' ', (string) $s );
		}

		/**
		 * Collapse whitespace and trim.
		 *
		 * @param string $s Text.
		 * @return string
		 */
		public static function squish( $s ) {
			$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s );
			return trim( (string) $s );
		}

		/**
		 * Word count that handles any script (counts whitespace-separated tokens
		 * containing a letter or digit).
		 *
		 * @param string $text Text.
		 * @return int
		 */
		public static function words( $text ) {
			$text = self::squish( $text );
			if ( '' === $text ) {
				return 0;
			}
			return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text );
		}

		/**
		 * Is this a block-level tag (adds a word break between siblings)?
		 *
		 * @param string $tag Tag name.
		 * @return bool
		 */
		public static function is_block( $tag ) {
			return in_array(
				strtolower( (string) $tag ),
				array( 'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'td', 'th', 'tr', 'ul', 'details', 'summary' ),
				true
			);
		}

		/**
		 * Does the element sit inside any of the given ancestor tags (stopping at root)?
		 *
		 * @param DOMElement $el   Element.
		 * @param string[]   $tags Lowercase tag names.
		 * @param DOMNode    $root Stop node.
		 * @return bool
		 */
		public static function has_ancestor( $el, array $tags, $root = null ) {
			$node = $el->parentNode;
			while ( $node && XML_ELEMENT_NODE === $node->nodeType ) {
				if ( $root && $node->isSameNode( $root ) ) {
					return false;
				}
				if ( in_array( strtolower( $node->nodeName ), $tags, true ) ) {
					return true;
				}
				$node = $node->parentNode;
			}
			return false;
		}

		/**
		 * Remove Twig control tags ({% … %}) and comments ({# … #}) but keep
		 * output expressions ({{ … }}), so the DOM reflects the template's shape.
		 *
		 * @param string $html Template.
		 * @return string
		 */
		public static function strip_twig_control( $html ) {
			$html = preg_replace( '/\{#.*?#\}/s', '', (string) $html );
			return (string) preg_replace( '/\{%-?.*?-?%\}/s', '', $html );
		}

		/**
		 * Does a string contain a Twig output or control token?
		 *
		 * @param string $s String.
		 * @return bool
		 */
		public static function has_twig( $s ) {
			return false !== strpos( (string) $s, '{{' ) || false !== strpos( (string) $s, '{%' );
		}
	}
}
