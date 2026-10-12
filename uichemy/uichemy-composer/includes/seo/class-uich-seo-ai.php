<?php
/**
 * AI formats (PRD §10): llms.txt and a Markdown version of each page.
 *
 * Served by matching the request path on `parse_request` — no rewrite rules, so
 * nothing to flush and it works on any permalink structure. Markdown is built
 * from the page's own section markup (08 §7: "where we actually win"), cached
 * per post and refreshed when the record's source signature changes.
 *
 * Honest note kept in the UI: AI crawlers rarely request llms.txt today.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_AI' ) ) {

	/**
	 * llms.txt + Markdown.
	 */
	class Uich_SEO_AI {

		const MD_META = '_uichemy_seo_md';

		/** Bump when from_html() changes, so cached Markdown is rebuilt. */
		const MD_VER = 2;

		/** Transient holding the generated llms.txt. */
		const LLMS_CACHE = 'uichemy_seo_llms_txt';

		/**
		 * Per-request resolve memo.
		 *
		 * @var array<int, array|null>
		 */
		private static $resolved = array();

		/** Default Markdown template (PRD §10.2). */
		const MD_TEMPLATE = "---\ntitle: {{ title }}\ndescription: {{ description }}\nurl: {{ url }}\nupdated: {{ date }}\n---\n\n# {{ title }}\n\n{{ content }}\n";

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'parse_request', array( __CLASS__, 'serve' ), 1 );
			// llms.txt is cached; anything that can change a listed title, description or URL
			// drops the cache so the next request rebuilds it.
			foreach ( array( 'uichemy_seo_rebuilt', 'transition_post_status', 'deleted_post', 'update_option_' . Uich_SEO_Settings::OPTION, 'add_option_' . Uich_SEO_Settings::OPTION ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'flush_llms_cache' ) );
			}
			add_filter( 'uichemy_seo_head_extra', array( __CLASS__, 'head_link' ), 20, 2 );
		}

		/**
		 * Request path relative to the site root.
		 *
		 * @return string
		 */
		private static function path() {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
				$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
			}
			return '/' . ltrim( $path, '/' );
		}

		/**
		 * Is output allowed at all?
		 *
		 * @return bool
		 */
		private static function on() {
			return Uich_SEO::output_enabled();
		}

		/**
		 * `parse_request` @1.
		 *
		 * @return void
		 */
		public static function serve() {
			// A site that asks search engines to stay away does not invite AI crawlers either.
			if ( is_admin() || ! self::on() || ! Uich_SEO_Settings::site_public() ) {
				return;
			}
			$path = self::path();
			if ( ( '/llms.txt' === $path && Uich_SEO_Settings::get( 'llms_enabled', false ) )
				|| ( preg_match( '#\.md$#i', $path ) && Uich_SEO_Settings::get( 'md_enabled', false ) ) ) {
				// These files are the PUBLIC view of the site, cached and shared by every
				// visitor: build them as a logged-out visitor, never as whoever asked first (an
				// admin's request would otherwise cache Twig user / cart values and logged-in-only
				// content for everyone). send() exits; when nothing is sent the user is restored.
				$prev_user = get_current_user_id();
				wp_set_current_user( 0 );
			}
			if ( '/llms.txt' === $path && Uich_SEO_Settings::get( 'llms_enabled', false ) ) {
				self::send( self::llms_txt_cached(), 'text/plain' );
			}
			if ( Uich_SEO_Settings::get( 'md_enabled', false ) && preg_match( '#\.md$#i', $path ) && '/llms.txt' !== $path ) {
				$base = substr( $path, 0, -3 );
				$id   = '' === trim( $base, '/' ) || '/index' === $base
					? (int) get_option( 'page_on_front' )
					: url_to_postid( home_url( user_trailingslashit( $base ) ) );
				if ( $id && self::markdown_allowed( $id ) ) {
					self::send( self::markdown_for( $id ), 'text/markdown' );
				}
			}
			if ( isset( $prev_user ) ) {
				wp_set_current_user( $prev_user ); // Not one of our files after all: carry on as before.
			}
		}

		/**
		 * Write a text response and stop.
		 *
		 * @param string $body Body.
		 * @param string $type MIME.
		 * @return void
		 */
		private static function send( $body, $type ) {
			status_header( 200 );
			header( 'Content-Type: ' . $type . '; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' ); // The HTML page is the canonical copy.
			header( 'X-Content-Type-Options: nosniff' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text / markdown response.
			exit;
		}

		/**
		 * Item eligible for AI output?
		 *
		 * @param int $id Post id.
		 * @return bool
		 */
		private static function eligible( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
				return false;
			}
			if ( self::publicly_restricted( $post ) ) {
				return false;
			}
			$r = self::resolved( $id );
			return $r && ! empty( $r['robots']['index'] ) && empty( $r['exclude_ai'] ) && '' === (string) $r['redirect_to'];
		}

		/**
		 * Is the post members-only for a logged-out visitor?
		 *
		 * Membership plugins restrict content on `the_content` or at `template_redirect`, and
		 * neither runs for these files (they are served on parse_request). Without this check a
		 * restricted post could be read in full at /slug.md. Evaluated for the current user, which
		 * serve() has set to logged-out.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		private static function publicly_restricted( $post ) {
			$restricted = false;
			if ( function_exists( 'pmpro_has_membership_access' ) && ! pmpro_has_membership_access( $post->ID ) ) {
				$restricted = true; // Paid Memberships Pro.
			} elseif ( class_exists( 'MeprRule' ) && method_exists( 'MeprRule', 'is_locked' ) && MeprRule::is_locked( $post ) ) {
				$restricted = true; // MemberPress.
			} elseif ( function_exists( 'rcp_user_can_access' ) && ! rcp_user_can_access( get_current_user_id(), $post->ID ) ) {
				$restricted = true; // Restrict Content (Pro).
			} elseif ( function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted( $post->ID ) ) {
				$restricted = true; // WooCommerce Memberships.
			}
			/**
			 * Whether a post must stay out of llms.txt and its .md file (members-only content).
			 *
			 * @param bool    $restricted Detected restriction.
			 * @param WP_Post $post       Post.
			 */
			return (bool) apply_filters( 'uichemy_seo_ai_post_restricted', $restricted, $post );
		}

		/**
		 * Markdown allowed for this post's type?
		 *
		 * @param int $id Post id.
		 * @return bool
		 */
		private static function markdown_allowed( $id ) {
			$row = Uich_SEO_Settings::type_row( (string) get_post_type( $id ) );
			return $row['seo'] && $row['markdown'] && self::eligible( $id );
		}

		/**
		 * `<link rel="alternate" type="text/markdown">` on pages that have one.
		 *
		 * @param string[] $lines Lines.
		 * @param array    $r     Resolved.
		 * @return string[]
		 */
		public static function head_link( $lines, $r ) {
			if ( ! Uich_SEO_Settings::get( 'md_enabled', false ) || 'post' !== $r['object']['kind'] || ! empty( $r['is_draft'] ) ) {
				return $lines;
			}
			$row = Uich_SEO_Settings::type_row( (string) get_post_type( $r['object']['id'] ) );
			if ( ! $row['markdown'] || empty( $r['robots']['index'] ) || ! empty( $r['exclude_ai'] ) ) {
				return $lines;
			}
			$lines[] = '<link rel="alternate" type="text/markdown" href="' . esc_url( self::md_url( $r['object']['id'] ) ) . '" />';
			return $lines;
		}

		/**
		 * The .md address of a post.
		 *
		 * @param int $id Post id.
		 * @return string
		 */
		public static function md_url( $id ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $id ) {
				return home_url( '/index.md' );
			}
			return untrailingslashit( get_permalink( $id ) ) . '.md';
		}

		// ------------------------------------------------------------------
		// llms.txt
		// ------------------------------------------------------------------

		/**
		 * Build llms.txt.
		 *
		 * @return string
		 */
		public static function llms_txt_cached() {
			$cached = get_transient( self::LLMS_CACHE );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
			$body = self::llms_txt();
			set_transient( self::LLMS_CACHE, $body, 6 * HOUR_IN_SECONDS );
			return $body;
		}

		/** Drop the cached llms.txt. */
		public static function flush_llms_cache() {
			delete_transient( self::LLMS_CACHE );
		}

		/**
		 * One resolve per post per request (llms.txt lists up to 500 posts per type, and each
		 * post was resolved twice: once to check eligibility, once to print it).
		 *
		 * @param int $id Post id.
		 * @return array|null
		 */
		private static function resolved( $id ) {
			$id = (int) $id;
			if ( ! array_key_exists( $id, self::$resolved ) ) {
				self::$resolved[ $id ] = Uich_SEO_Resolver::for_post( $id );
			}
			return self::$resolved[ $id ];
		}

		/**
		 * llms.txt body (uncached; see llms_txt_cached()).
		 *
		 * @return string
		 */
		public static function llms_txt() {
			$name  = Uich_SEO_Settings::site_name();
			$intro = trim( (string) Uich_SEO_Settings::get( 'llms_intro', '' ) );
			$intro = '' !== $intro ? Uich_SEO_Resolver::render( $intro, null ) : trim( (string) ( Uich_SEO_Settings::get( 'entity_description', '' ) ?: get_bloginfo( 'description' ) ) );
			$limit = max( 1, min( 500, (int) Uich_SEO_Settings::get( 'llms_limit', 100 ) ) );

			$out = '# ' . $name . "\n\n";
			if ( '' !== $intro ) {
				$out .= '> ' . str_replace( "\n", "\n> ", $intro ) . "\n\n";
			}

			foreach ( Uich_SEO_Settings::content_types( 'objects' ) as $pt ) {
				if ( 'attachment' === $pt->name ) {
					continue;
				}
				$row = Uich_SEO_Settings::type_row( $pt->name );
				if ( ! $row['seo'] || ! $row['llms'] ) {
					continue;
				}
				// The limit counts listed pages, so keep paging past ineligible ones
				// (noindex, excluded, redirected…) until it is reached or posts run out.
				$ids  = array();
				$page = 1;
				do {
					$batch = get_posts(
						array(
							'post_type'      => $pt->name,
							'post_status'    => 'publish',
							'posts_per_page' => $limit,
							'paged'          => $page++,
							'orderby'        => 'modified',
							'order'          => 'DESC',
							'fields'         => 'ids',
							'has_password'   => false,
							'no_found_rows'  => true,
						)
					);
					foreach ( $batch as $id ) {
						if ( count( $ids ) < $limit && self::eligible( $id ) ) {
							$ids[] = $id;
						}
					}
				} while ( count( $batch ) === $limit && count( $ids ) < $limit && $page <= 20 );
				$items = '';
				foreach ( $ids as $id ) {
					$r     = self::resolved( $id );
					$title = isset( $r['title']['core'] ) ? $r['title']['core'] : get_the_title( $id );
					$url   = $row['markdown'] && Uich_SEO_Settings::get( 'md_enabled', false ) ? self::md_url( $id ) : get_permalink( $id );
					$desc  = (string) $r['description']['value'];
					$desc  = Uich_SEO_Html::title( $desc );
					$items .= '- [' . self::md_escape( Uich_SEO_Html::title( $title ) ) . '](' . esc_url_raw( $url ) . ')' . ( '' !== $desc ? ': ' . $desc : '' ) . "\n";
				}
				if ( '' !== $items ) {
					$out .= '## ' . $pt->labels->name . "\n\n" . $items . "\n";
				}
			}

			$extra = trim( (string) Uich_SEO_Settings::get( 'llms_extra', '' ) );
			if ( '' !== $extra ) {
				$out .= $extra . "\n";
			}
			return (string) apply_filters( 'uichemy_seo_llms_txt', $out );
		}

		// ------------------------------------------------------------------
		// Markdown
		// ------------------------------------------------------------------

		/**
		 * Markdown for a post, from cache when the content has not changed.
		 *
		 * @param int $id Post id.
		 * @return string
		 */
		public static function markdown_for( $id ) {
			$record = Uich_SEO_Record::get( $id );
			$sig    = $record['source_sig'];
			$cache  = get_post_meta( $id, self::MD_META, true );
			if ( is_array( $cache ) && isset( $cache['sig'], $cache['md'] ) && $sig && $cache['sig'] === $sig && isset( $cache['v'] ) && self::MD_VER === (int) $cache['v'] ) {
				$body = (string) $cache['md'];
			} else {
				$html = '';
				foreach ( Uich_SEO_Sections::get( $id, 'instance' ) as $s ) {
					$html .= $s['html'] . "\n";
				}
				$body = self::from_html( $html );
				// No signature yet (the post has not been derived): nothing to key a cache on, so
				// do not write one on every anonymous request.
				if ( $sig ) {
					update_post_meta( $id, self::MD_META, array( 'sig' => $sig, 'md' => $body, 'v' => self::MD_VER ) );
				}
			}

			$r    = self::resolved( $id );
			$tpl  = trim( (string) Uich_SEO_Settings::get( 'md_template', '' ) );
			$tpl  = '' !== $tpl ? $tpl : self::MD_TEMPLATE;
			$vals = array(
				'title'       => Uich_SEO_Html::title( isset( $r['title']['core'] ) ? $r['title']['core'] : get_the_title( $id ) ),
				'description' => Uich_SEO_Html::title( (string) $r['description']['value'] ),
				'url'         => get_permalink( $id ),
				'date'        => get_post_modified_time( 'Y-m-d', true, $id ),
				'site.title'  => Uich_SEO_Settings::site_name(),
				'site.name'   => Uich_SEO_Settings::site_name(),
				'content'     => $body,
			);
			// The template opens with "# {{ title }}": drop the body's first H1 when it
			// is the same title (wherever it sits, so stray text above it cannot keep it).
			if ( false !== strpos( $tpl, '# {{ title }}' ) ) {
				$vals['content'] = (string) preg_replace( '/(^|\n)[ \t]*#[ \t]+' . preg_quote( trim( (string) $vals['title'] ), '/' ) . '[ \t]*#*[ \t]*(\n+|$)/u', '$1', (string) $vals['content'], 1 );
			}
			// A value alone on a front-matter line (`title: {{ title }}`) is written as a quoted
			// YAML string, so ": " or " #" in a title cannot break the header.
			$yaml_val = function ( $v ) {
				return (string) wp_json_encode( (string) $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			};
			if ( preg_match( '/\A---\R(.*?)\R---(?:\R|\z)/s', $tpl, $fm, PREG_OFFSET_CAPTURE ) ) {
				$head = preg_replace_callback(
					'/^([A-Za-z_][\w.-]*:[ \t]*)\{\{\s*([a-z_.]+)\s*\}\}[ \t]*$/mi',
					function ( $m ) use ( $vals, $yaml_val ) {
						return $m[1] . ( array_key_exists( $m[2], $vals ) ? $yaml_val( $vals[ $m[2] ] ) : '""' );
					},
					$fm[0][0]
				);
				$tpl = $head . substr( $tpl, strlen( $fm[0][0] ) );
			}
			// Simple {{ key }} substitution: Markdown must not pass through the HTML auto-escaper.
			$out = preg_replace_callback(
				'/\{\{\s*([a-z_.]+)\s*\}\}/i',
				function ( $m ) use ( $vals ) {
					return array_key_exists( $m[1], $vals ) ? $vals[ $m[1] ] : '';
				},
				$tpl
			);
			$out = preg_replace( "/\n{3,}/", "\n\n", (string) $out );
			return (string) apply_filters( 'uichemy_seo_markdown', trim( $out ) . "\n", $id );
		}

		/**
		 * Escape a Markdown link label.
		 *
		 * @param string $s Text.
		 * @return string
		 */
		private static function md_escape( $s ) {
			return str_replace( array( '[', ']' ), array( '\[', '\]' ), (string) $s );
		}

		/**
		 * HTML → Markdown. Headings, paragraphs, lists, links, images, emphasis,
		 * code, quotes, tables and rules; everything else contributes its text.
		 *
		 * @param string $html HTML.
		 * @return string
		 */
		public static function from_html( $html ) {
			if ( '' === trim( (string) $html ) ) {
				return '';
			}
			list( , $root ) = Uich_SEO_Html::fragment( $html );
			$md             = self::block( $root );
			$md             = preg_replace( "/[ \t]+\n/", "\n", $md );
			$md             = preg_replace( "/\n{3,}/", "\n\n", $md );
			return trim( (string) $md );
		}

		/**
		 * Block-level walk.
		 *
		 * @param DOMNode $node Node.
		 * @return string
		 */
		private static function block( $node ) {
			$out = '';
			foreach ( $node->childNodes as $c ) {
				if ( XML_TEXT_NODE === $c->nodeType ) {
					$t = Uich_SEO_Html::squish( $c->nodeValue );
					if ( '' !== $t ) {
						$out .= $t . "\n\n";
					}
					continue;
				}
				if ( XML_ELEMENT_NODE !== $c->nodeType ) {
					continue;
				}
				$tag = strtolower( $c->nodeName );
				switch ( $tag ) {
					case 'script':
					case 'style':
					case 'noscript':
					case 'template':
					case 'svg':
					case 'form':
					case 'button':
					case 'iframe':
					case 'nav':
					case 'title': // Head tags pasted into section HTML are not content.
					case 'meta':
					case 'link':
					case 'head':
						break;
					case 'h1':
					case 'h2':
					case 'h3':
					case 'h4':
					case 'h5':
					case 'h6':
						$t = trim( self::inline( $c ) );
						if ( '' !== $t ) {
							$out .= str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . $t . "\n\n";
						}
						break;
					case 'p':
					case 'figcaption':
					case 'summary':
						$t = trim( self::inline( $c ) );
						if ( '' !== $t ) {
							$out .= $t . "\n\n";
						}
						break;
					case 'ul':
					case 'ol':
						$out .= self::lists( $c, 0 ) . "\n";
						break;
					case 'blockquote':
						$inner = trim( self::block( $c ) );
						if ( '' !== $inner ) {
							$out .= '> ' . str_replace( "\n", "\n> ", $inner ) . "\n\n";
						}
						break;
					case 'pre':
						$out .= "```\n" . rtrim( $c->textContent ) . "\n```\n\n";
						break;
					case 'hr':
						$out .= "---\n\n";
						break;
					case 'img':
						$out .= self::inline_el( $c ) . "\n\n";
						break;
					case 'table':
						$out .= self::table( $c ) . "\n\n";
						break;
					default:
						// Containers: if they hold only inline content, treat as a paragraph.
						if ( self::only_inline( $c ) ) {
							$t = trim( self::inline( $c ) );
							if ( '' !== $t ) {
								$out .= $t . "\n\n";
							}
						} else {
							$out .= self::block( $c );
						}
				}
			}
			return $out;
		}

		/**
		 * Does an element contain only inline content?
		 *
		 * @param DOMElement $el Element.
		 * @return bool
		 */
		private static function only_inline( $el ) {
			foreach ( $el->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && ( Uich_SEO_Html::is_block( $c->nodeName ) || in_array( strtolower( $c->nodeName ), array( 'table', 'img', 'figure', 'section', 'pre' ), true ) ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Inline content of an element.
		 *
		 * @param DOMNode $node Node.
		 * @return string
		 */
		private static function inline( $node ) {
			$out = '';
			foreach ( $node->childNodes as $c ) {
				if ( XML_TEXT_NODE === $c->nodeType ) {
					$out .= preg_replace( '/\s+/u', ' ', html_entity_decode( $c->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				} elseif ( XML_ELEMENT_NODE === $c->nodeType ) {
					$out .= self::inline_el( $c );
				}
			}
			return $out;
		}

		/**
		 * One inline element.
		 *
		 * @param DOMElement $c Element.
		 * @return string
		 */
		private static function inline_el( $c ) {
			$tag = strtolower( $c->nodeName );
			switch ( $tag ) {
				case 'strong':
				case 'b':
					$t = trim( self::inline( $c ) );
					return '' === $t ? '' : '**' . $t . '**';
				case 'em':
				case 'i':
					$t = trim( self::inline( $c ) );
					return '' === $t ? '' : '*' . $t . '*';
				case 'code':
					return '`' . $c->textContent . '`';
				case 'br':
					return "  \n";
				case 'a':
					$t    = trim( self::inline( $c ) );
					$href = trim( (string) $c->getAttribute( 'href' ) );
					if ( '' === $href || '#' === $href[0] || preg_match( '/^javascript:/i', $href ) ) {
						return $t;
					}
					if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
						$href = home_url( $href );
					}
					return '' === $t ? '' : '[' . self::md_escape( $t ) . '](' . $href . ')';
				case 'img':
					$src = trim( (string) $c->getAttribute( 'src' ) );
					$alt = Uich_SEO_Html::squish( $c->getAttribute( 'alt' ) );
					if ( '' === $src || 0 === strpos( $src, 'data:' ) || '' === $alt ) {
						return ''; // Decorative or alt-less images carry no meaning for a reader.
					}
					return '![' . self::md_escape( $alt ) . '](' . $src . ')';
				case 'script':
				case 'style':
				case 'svg':
				case 'button':
				case 'title':
				case 'meta':
				case 'link':
				case 'head':
					return '';
				default:
					return self::inline( $c );
			}
		}

		/**
		 * Lists, nested.
		 *
		 * @param DOMElement $list  List.
		 * @param int        $depth Depth.
		 * @return string
		 */
		private static function lists( $list, $depth ) {
			$out = '';
			$n   = 1;
			$ord = 'ol' === strtolower( $list->nodeName );
			foreach ( $list->childNodes as $li ) {
				if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) {
					continue;
				}
				$text = '';
				$sub  = '';
				foreach ( $li->childNodes as $c ) {
					if ( XML_ELEMENT_NODE === $c->nodeType && in_array( strtolower( $c->nodeName ), array( 'ul', 'ol' ), true ) ) {
						$sub .= self::lists( $c, $depth + 1 );
					} elseif ( XML_ELEMENT_NODE === $c->nodeType ) {
						$text .= self::inline_el( $c ) . ( Uich_SEO_Html::is_block( $c->nodeName ) ? ' ' : '' );
					} elseif ( XML_TEXT_NODE === $c->nodeType ) {
						$text .= preg_replace( '/\s+/u', ' ', $c->nodeValue );
					}
				}
				$out .= str_repeat( '  ', $depth ) . ( $ord ? $n++ . '. ' : '- ' ) . trim( $text ) . "\n" . $sub;
			}
			return $out;
		}

		/**
		 * Tables → pipe tables.
		 *
		 * @param DOMElement $table Table.
		 * @return string
		 */
		private static function table( $table ) {
			$rows = array();
			foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
				$cells = array();
				foreach ( $tr->childNodes as $cell ) {
					if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
						$cells[] = str_replace( '|', '\|', trim( self::inline( $cell ) ) );
					}
				}
				if ( $cells ) {
					$rows[] = $cells;
				}
			}
			if ( ! $rows ) {
				return '';
			}
			$w   = max( array_map( 'count', $rows ) );
			$out = '';
			foreach ( $rows as $i => $cells ) {
				$cells = array_pad( $cells, $w, '' );
				$out  .= '| ' . implode( ' | ', $cells ) . " |\n";
				if ( 0 === $i ) {
					$out .= '|' . str_repeat( ' --- |', $w ) . "\n";
				}
			}
			return rtrim( $out );
		}
	}
}
