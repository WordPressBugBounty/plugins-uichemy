<?php
/**
 * Turn a fully rendered HTML page into clean Markdown for AI agents.
 *
 * Works on the final HTML a logged-out visitor receives, so it covers every
 * builder, template, archive and language without knowing how the page was
 * made. Only the main content is kept:
 *
 * - The content root is <main> (or role="main"); without one, <body> minus the
 *   site header, footer and navigation. Theme-builder header and footer
 *   templates render as <header> and <footer>, so they drop out too.
 * - Scripts, styles, forms internals, embeds, comments sections, HTML comments,
 *   hidden elements (hidden, aria-hidden, display:none, visibility:hidden) and
 *   invisible Unicode are removed. Hidden demo text left in a theme therefore
 *   never reaches an agent, and neither do instructions planted in hidden text.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Convert' ) ) {

	/**
	 * HTML to Markdown.
	 */
	class Uich_Agent_Convert {

		/**
		 * Elements removed with everything inside them.
		 *
		 * @var string[]
		 */
		private static $drop = array( 'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'video', 'audio', 'source', 'track', 'map', 'head', 'link', 'meta', 'select', 'textarea', 'input', 'button', 'dialog' );

		/**
		 * Base URL used to make relative links absolute.
		 *
		 * @var string
		 */
		private $base = '';

		/**
		 * Whether to keep images (as alt text with link).
		 *
		 * @var bool
		 */
		private $images = true;

		/**
		 * Facts read from the document head.
		 *
		 * @var array
		 */
		private $meta = array();

		/**
		 * Convert a page.
		 *
		 * @param string $html    Full HTML document.
		 * @param string $base    Page URL (for relative links).
		 * @param array  $options { images: bool }.
		 * @return array{markdown:string,title:string,lang:string,canonical:string,description:string,noindex:bool}
		 */
		public static function page( $html, $base, array $options = array() ) {
			$self         = new self();
			$self->base   = (string) $base;
			$self->images = ! isset( $options['images'] ) || $options['images'];
			return $self->run( (string) $html );
		}

		/**
		 * Plain text of an HTML fragment (post content, excerpts, product
		 * descriptions) with hidden elements and invisible characters removed,
		 * trimmed to a number of words. Use it for every text a tool returns.
		 *
		 * @param string $html  HTML fragment.
		 * @param int    $words Word limit.
		 * @return string
		 */
		public static function plain_text( $html, $words = 40 ) {
			$html = strip_shortcodes( (string) $html );
			if ( '' === trim( $html ) ) {
				return '';
			}
			$self   = new self();
			$result = $self->run( '<!doctype html><html><body><main>' . $html . '</main></body></html>' );
			// Images carry no text for a plain-text summary: drop ![alt](url) whole.
			$text   = preg_replace( '/!\[[^\]]*\]\([^)]*\)/u', ' ', html_entity_decode( $result['markdown'], ENT_QUOTES, 'UTF-8' ) );
			$text   = preg_replace( '/[\\\\#*_`>|\[\]]+|\(http[^)]*\)/u', ' ', (string) $text );
			// Drop list markers, table rules and fences left from the Markdown pass.
			$text = preg_replace( array( '/(^|\s)-{3,}(?=\s|$)/u', '/(^|\s)(?:-|\d+\.)\s+/u', '/~{3,}|`{3,}\w*/u' ), ' ', (string) $text );
			$text = preg_replace( '/\s+([.,;:!?])/u', '$1', preg_replace( '/\s+/u', ' ', (string) $text ) );
			return wp_trim_words( trim( (string) $text ), max( 1, (int) $words ) );
		}

		/**
		 * Do the conversion.
		 *
		 * @param string $html HTML.
		 * @return array
		 */
		private function run( $html ) {
			$empty = array(
				'markdown'    => '',
				'title'       => '',
				'lang'        => '',
				'canonical'   => '',
				'description' => '',
				'noindex'     => false,
			);
			if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
				return $empty;
			}

			$doc      = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			// Force UTF-8: DOMDocument assumes ISO-8859-1 without a declaration.
			$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			$this->read_head( $doc );

			$xpath = new DOMXPath( $doc );
			$this->strip( $doc, $xpath );

			$root = $this->content_root( $doc, $xpath );
			if ( ! $root ) {
				return array_merge( $empty, $this->meta );
			}

			$markdown = $this->children( $root, 0 );
			$markdown = $this->tidy( $markdown );

			return array_merge( $empty, $this->meta, array( 'markdown' => $markdown ) );
		}

		/**
		 * Title, language, canonical, description and robots from the document.
		 *
		 * @param DOMDocument $doc Document.
		 * @return void
		 */
		private function read_head( DOMDocument $doc ) {
			$html = $doc->getElementsByTagName( 'html' )->item( 0 );
			$this->meta['lang'] = $html instanceof DOMElement ? substr( preg_replace( '/[^A-Za-z0-9\-]/', '', $html->getAttribute( 'lang' ) ), 0, 20 ) : '';

			$title = $doc->getElementsByTagName( 'title' )->item( 0 );
			$this->meta['title'] = $title ? $this->clean_text( $title->textContent ) : '';

			$this->meta['canonical']   = '';
			$this->meta['description'] = '';
			$this->meta['noindex']     = false;

			foreach ( $doc->getElementsByTagName( 'link' ) as $link ) {
				if ( 'canonical' === strtolower( $link->getAttribute( 'rel' ) ) ) {
					$this->meta['canonical'] = esc_url_raw( $link->getAttribute( 'href' ) );
				}
			}
			foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
				$name = strtolower( $meta->getAttribute( 'name' ) );
				if ( 'description' === $name ) {
					$this->meta['description'] = $this->clean_text( $meta->getAttribute( 'content' ) );
				} elseif ( 'robots' === $name && false !== stripos( $meta->getAttribute( 'content' ), 'noindex' ) ) {
					$this->meta['noindex'] = true;
				}
			}
		}

		/**
		 * Remove everything that must never reach an agent.
		 *
		 * @param DOMDocument $doc   Document.
		 * @param DOMXPath    $xpath XPath.
		 * @return void
		 */
		private function strip( DOMDocument $doc, DOMXPath $xpath ) {
			$remove = array();

			foreach ( self::$drop as $tag ) {
				foreach ( $doc->getElementsByTagName( $tag ) as $node ) {
					$remove[] = $node;
				}
			}

			// HTML comments.
			foreach ( $xpath->query( '//comment()' ) as $node ) {
				$remove[] = $node;
			}

			// Hidden elements. The screen-reader-text pattern is kept on purpose: it is
			// real text meant for non-visual readers.
			$hidden = '//*[@hidden or @aria-hidden="true" or contains(translate(@style," ",""),"display:none") or contains(translate(@style," ",""),"visibility:hidden")]';
			foreach ( $xpath->query( $hidden ) as $node ) {
				$remove[] = $node;
			}

			// Comment and review sections: user-generated, unmoderated text is a prompt
			// injection route and not the site's own information.
			// Covers classic themes, block themes (wp-block-comments) and WooCommerce reviews.
			$classes = array( 'comments-area', 'comment-respond', 'comment-form', 'commentlist', 'comment-list', 'wp-block-comments', 'wp-block-comment-template', 'wp-block-post-comments', 'wp-block-post-comments-form', 'woocommerce-Reviews', 'review' );
			$tests   = array( '@id="comments"', '@id="respond"', '@id="reviews"' );
			foreach ( $classes as $class ) {
				$tests[] = 'contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")';
			}
			// A single comment carries "comment" plus a depth-N class in every core theme.
			$tests[] = '(contains(concat(" ",normalize-space(@class)," ")," comment ") and contains(@class,"depth-"))';
			foreach ( $xpath->query( '//*[' . implode( ' or ', $tests ) . ']' ) as $node ) {
				$remove[] = $node;
			}

			// The admin bar and skip links.
			foreach ( $xpath->query( '//*[@id="wpadminbar"] | //a[contains(concat(" ",normalize-space(@class)," ")," skip-link ")]' ) as $node ) {
				$remove[] = $node;
			}

			foreach ( $remove as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		/**
		 * Pick the element whose content is the page's own information.
		 *
		 * @param DOMDocument $doc   Document.
		 * @param DOMXPath    $xpath XPath.
		 * @return DOMNode|null
		 */
		private function content_root( DOMDocument $doc, DOMXPath $xpath ) {
			$main = $xpath->query( '//main | //*[@role="main"]' );
			if ( $main && $main->length > 0 ) {
				// The largest main wins when a theme nests or repeats it.
				$best = null;
				$size = -1;
				foreach ( $main as $node ) {
					$len = strlen( $node->textContent );
					if ( $len > $size ) {
						$best = $node;
						$size = $len;
					}
				}
				return $best;
			}

			$body = $doc->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) {
				return null;
			}

			// No <main>: drop the site chrome that sits directly in the page frame.
			$chrome = $xpath->query( '//body//header[not(ancestor::article)] | //body//footer[not(ancestor::article)] | //body//nav | //*[@role="banner" or @role="contentinfo" or @role="navigation"]' );
			$remove = array();
			foreach ( $chrome as $node ) {
				$remove[] = $node;
			}
			foreach ( $remove as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
			return $body;
		}

		/**
		 * Convert all children of a node.
		 *
		 * @param DOMNode $node  Node.
		 * @param int     $depth List depth.
		 * @return string
		 */
		private function children( DOMNode $node, $depth ) {
			$out = '';
			foreach ( $node->childNodes as $child ) {
				$out .= $this->node( $child, $depth );
			}
			return $out;
		}

		/**
		 * Convert one node.
		 *
		 * @param DOMNode $node  Node.
		 * @param int     $depth List depth.
		 * @return string
		 */
		private function node( DOMNode $node, $depth ) {
			if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
				return $this->escape_inline( preg_replace( '/\s+/u', ' ', $this->strip_invisible( $node->textContent ) ) );
			}
			if ( ! $node instanceof DOMElement ) {
				return '';
			}

			$tag = strtolower( $node->tagName );
			switch ( $tag ) {
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					$text = $this->inline( $node );
					return '' === $text ? '' : "\n\n" . str_repeat( '#', (int) $tag[1] ) . ' ' . $text . "\n\n";

				case 'p':
					$text = trim( $this->children( $node, $depth ) );
					return '' === $text ? '' : "\n\n" . $text . "\n\n";

				case 'br':
					return "  \n";

				case 'hr':
					return "\n\n---\n\n";

				case 'strong':
				case 'b':
					$text = trim( $this->children( $node, $depth ) );
					return '' === $text ? '' : '**' . $text . '**';

				case 'em':
				case 'i':
					$text = trim( $this->children( $node, $depth ) );
					return '' === $text ? '' : '*' . $text . '*';

				case 'code':
					if ( $node->parentNode && 'pre' === strtolower( $node->parentNode->nodeName ) ) {
						return $node->textContent;
					}
					$text = $this->strip_invisible( $node->textContent );
					return '' === trim( $text ) ? '' : '`' . str_replace( '`', '\\`', $text ) . '`';

				case 'pre':
					return $this->pre( $node );

				case 'a':
					return $this->link( $node, $depth );

				case 'img':
					return $this->image( $node );

				case 'ul':
				case 'ol':
					return $this->a_list( $node, $depth );

				case 'blockquote':
					$text = trim( $this->tidy( $this->children( $node, $depth ) ) );
					return '' === $text ? '' : "\n\n" . preg_replace( '/^/m', '> ', $text ) . "\n\n";

				case 'table':
					return $this->table( $node );

				case 'dl':
					return $this->definition_list( $node );

				case 'figure':
					return "\n\n" . trim( $this->children( $node, $depth ) ) . "\n\n";

				case 'figcaption':
					$text = trim( $this->children( $node, $depth ) );
					return '' === $text ? '' : "\n\n*" . $text . "*\n\n";

				case 'details':
					return "\n\n" . trim( $this->children( $node, $depth ) ) . "\n\n";

				case 'summary':
					$text = trim( $this->inline( $node ) );
					return '' === $text ? '' : "\n\n**" . $text . "**\n\n";

				case 'form':
					return $this->form( $node );

				case 'label':
				case 'option':
					return '';

				case 'div':
				case 'section':
				case 'article':
				case 'header':
				case 'footer':
				case 'aside':
				case 'main':
				case 'address':
				case 'li':
					return "\n\n" . $this->children( $node, $depth ) . "\n\n";

				default:
					return $this->children( $node, $depth );
			}
		}

		/**
		 * Inline text of a node, single line.
		 *
		 * @param DOMNode $node Node.
		 * @return string
		 */
		private function inline( DOMNode $node ) {
			return trim( preg_replace( '/\s+/u', ' ', $this->children( $node, 0 ) ) );
		}

		/**
		 * A link, made absolute; plain text when it goes nowhere useful.
		 *
		 * @param DOMElement $node  Anchor.
		 * @param int        $depth List depth.
		 * @return string
		 */
		private function link( DOMElement $node, $depth ) {
			$text = trim( preg_replace( '/\s+/u', ' ', $this->children( $node, $depth ) ) );
			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(javascript|data|vbscript):#i', $href ) ) {
				return $text;
			}
			$href = $this->absolute( $href );
			if ( '' === $href ) {
				return $text;
			}
			if ( '' === $text ) {
				$text = $node->getAttribute( 'aria-label' ) ? $this->clean_text( $node->getAttribute( 'aria-label' ) ) : $href;
			}
			return '[' . str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $text ) . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $href ) . ')';
		}

		/**
		 * An image as alt text (with its address), or nothing when it has no alt.
		 *
		 * Decorative images (empty alt) are skipped: they carry no information.
		 *
		 * @param DOMElement $node Image.
		 * @return string
		 */
		private function image( DOMElement $node ) {
			if ( ! $this->images ) {
				return '';
			}
			$alt = $this->clean_text( $node->getAttribute( 'alt' ) );
			if ( '' === $alt ) {
				return '';
			}
			$src = $this->absolute( $node->getAttribute( 'src' ) );
			return '' === $src ? $alt : '![' . str_replace( array( '[', ']' ), '', $alt ) . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $src ) . ')';
		}

		/**
		 * A preformatted block as a fenced code block, with language when the class says so.
		 *
		 * @param DOMElement $node Pre.
		 * @return string
		 */
		private function pre( DOMElement $node ) {
			$lang  = '';
			$code  = $node->getElementsByTagName( 'code' )->item( 0 );
			$class = ( $code instanceof DOMElement ? $code->getAttribute( 'class' ) : '' ) . ' ' . $node->getAttribute( 'class' );
			if ( preg_match( '/(?:language|lang)-([a-z0-9+#\-]+)/i', $class, $m ) ) {
				$lang = strtolower( $m[1] );
			}
			$text  = rtrim( $this->strip_invisible( $node->textContent ) );
			$fence = false !== strpos( $text, '```' ) ? '~~~~' : '```';
			return "\n\n" . $fence . $lang . "\n" . $text . "\n" . $fence . "\n\n";
		}

		/**
		 * A list, nested lists indented.
		 *
		 * @param DOMElement $node  List.
		 * @param int        $depth Depth.
		 * @return string
		 */
		private function a_list( DOMElement $node, $depth ) {
			$ordered = 'ol' === strtolower( $node->tagName );
			$i       = 1;
			$lines   = '';
			foreach ( $node->childNodes as $item ) {
				if ( ! $item instanceof DOMElement || 'li' !== strtolower( $item->tagName ) ) {
					continue;
				}
				$body   = '';
				$nested = '';
				foreach ( $item->childNodes as $child ) {
					if ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), array( 'ul', 'ol' ), true ) ) {
						$nested .= $this->a_list( $child, $depth + 1 );
					} else {
						$body .= $this->node( $child, $depth + 1 );
					}
				}
				// Headings inside a list item (card grids, post lists) read as plain text.
				$body = trim( preg_replace( '/(^|\s)#{1,6}\s+/u', '$1', preg_replace( '/\s+/u', ' ', $body ) ) );
				if ( '' === $body && '' === $nested ) {
					continue;
				}
				$marker = $ordered ? $i . '. ' : '- ';
				$lines .= str_repeat( '  ', $depth ) . $marker . $body . "\n" . $nested;
				++$i;
			}
			if ( '' === $lines ) {
				return '';
			}
			return 0 === $depth ? "\n\n" . $lines . "\n" : $lines;
		}

		/**
		 * A table as a GitHub-style table; layout tables (one column) become paragraphs.
		 *
		 * @param DOMElement $node Table.
		 * @return string
		 */
		private function table( DOMElement $node ) {
			$rows = array();
			foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
				$cells = array();
				foreach ( $tr->childNodes as $cell ) {
					if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
						$cells[] = str_replace( '|', '\\|', trim( preg_replace( '/\s+/u', ' ', $this->children( $cell, 0 ) ) ) );
					}
				}
				if ( $cells ) {
					$rows[] = $cells;
				}
			}
			if ( ! $rows ) {
				return '';
			}
			$cols = max( array_map( 'count', $rows ) );
			if ( 1 === $cols ) {
				return "\n\n" . implode( "\n\n", array_map( 'reset', $rows ) ) . "\n\n";
			}
			$out = '';
			foreach ( $rows as $i => $cells ) {
				$cells = array_pad( $cells, $cols, '' );
				$out  .= '| ' . implode( ' | ', $cells ) . " |\n";
				if ( 0 === $i ) {
					$out .= '|' . str_repeat( ' --- |', $cols ) . "\n";
				}
			}
			return "\n\n" . $out . "\n";
		}

		/**
		 * A definition list as bold terms with their descriptions.
		 *
		 * @param DOMElement $node Dl.
		 * @return string
		 */
		private function definition_list( DOMElement $node ) {
			$out = '';
			foreach ( $node->childNodes as $child ) {
				if ( ! $child instanceof DOMElement ) {
					continue;
				}
				$text = trim( preg_replace( '/\s+/u', ' ', $this->children( $child, 0 ) ) );
				if ( '' === $text ) {
					continue;
				}
				$out .= 'dt' === strtolower( $child->tagName ) ? "\n**" . $text . "**\n" : ': ' . $text . "\n";
			}
			return '' === $out ? '' : "\n\n" . trim( $out ) . "\n\n";
		}

		/**
		 * A form as a short description of what it asks for. Agents fill forms
		 * through the site MCP or WebMCP, not by reading markup.
		 *
		 * @param DOMElement $node Form.
		 * @return string
		 */
		private function form( DOMElement $node ) {
			$fields = array();
			foreach ( $node->getElementsByTagName( 'label' ) as $label ) {
				$text = $this->clean_text( $label->textContent );
				if ( '' !== $text && count( $fields ) < 20 ) {
					$fields[] = rtrim( $text, ' *:' );
				}
			}
			$headings = trim( $this->children( $node, 0 ) );
			$out      = '' === $headings ? '' : $headings;
			if ( $fields ) {
				$out .= "\n\n*Form fields: " . implode( ', ', array_unique( $fields ) ) . '.*';
			}
			return '' === trim( $out ) ? '' : "\n\n" . trim( $out ) . "\n\n";
		}

		/**
		 * Make a URL absolute against the page URL; '' when unusable.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private function absolute( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '';
			}
			if ( preg_match( '#^(https?:)?//#i', $url ) ) {
				$url = 0 === strpos( $url, '//' ) ? ( is_ssl() ? 'https:' : 'http:' ) . $url : $url;
				return esc_url_raw( $url, array( 'http', 'https' ) );
			}
			if ( preg_match( '#^(mailto|tel):#i', $url ) ) {
				return esc_url_raw( $url, array( 'mailto', 'tel' ) );
			}
			if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
				return '';
			}
			$base = wp_parse_url( $this->base ? $this->base : home_url( '/' ) );
			if ( empty( $base['host'] ) ) {
				return '';
			}
			$origin = ( isset( $base['scheme'] ) ? $base['scheme'] : 'https' ) . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
			if ( '/' === $url[0] ) {
				return esc_url_raw( $origin . $url );
			}
			$path = isset( $base['path'] ) ? $base['path'] : '/';
			$dir  = substr( $path, 0, strrpos( $path, '/' ) + 1 );
			return esc_url_raw( $origin . $dir . $url );
		}

		/**
		 * Escape characters that would start Markdown syntax mid-text.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private function escape_inline( $text ) {
			return str_replace( array( '\\', '*', '_', '`', '<', '>' ), array( '\\\\', '\\*', '\\_', '\\`', '&lt;', '&gt;' ), $text );
		}

		/**
		 * Plain single-line text, invisible characters removed.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private function clean_text( $text ) {
			return trim( preg_replace( '/\s+/u', ' ', $this->strip_invisible( (string) $text ) ) );
		}

		/**
		 * Remove zero-width, bidi-control and other invisible characters that can
		 * hide text from people while an agent still reads it.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private function strip_invisible( $text ) {
			$clean = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{00AD}\x{E0000}-\x{E007F}]/u', '', (string) $text );
			return null === $clean ? '' : str_replace( "\xC2\xA0", ' ', $clean );
		}

		/**
		 * Collapse blank lines and trailing spaces.
		 *
		 * @param string $md Markdown.
		 * @return string
		 */
		private function tidy( $md ) {
			// Fenced code keeps its exact whitespace; everything else is collapsed.
			$parts = preg_split( "/(\n(?:```|~~~~)[^\n]*\n.*?\n(?:```|~~~~)\n)/s", $md, -1, PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $i => $part ) {
				if ( 1 === $i % 2 ) {
					continue;
				}
				$part        = preg_replace( "/[ \t]+\n/", "\n", $part );
				$part        = preg_replace( "/\n[ \t]+(?=[^\s\-\d])/", "\n", $part );
				$parts[ $i ] = preg_replace( "/\n{3,}/", "\n\n", $part );
			}
			$md = preg_replace( "/\n{3,}/", "\n\n", implode( '', $parts ) );
			return trim( $md ) . "\n";
		}
	}
}
