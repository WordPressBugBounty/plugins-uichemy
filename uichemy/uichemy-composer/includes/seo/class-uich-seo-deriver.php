<?php
/**
 * The deriver — HTML + context in, derived SEO data out.
 *
 * PURE (D14): no WordPress calls, no globals, no Composer classes. Everything
 * it needs arrives in $sections and $context; everything it learns is returned.
 * That is what lets it be unit-tested without booting WordPress.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Deriver' ) ) {

	/**
	 * Derivation engine.
	 */
	class Uich_SEO_Deriver {

		/** Paragraphs shorter than this are not "meaningful" for a description. */
		const MIN_DESC_WORDS = 12;

		/** Description length cap, in characters (Google shows ~155–160). */
		const DESC_MAX = 158;

		/** Tags whose content never feeds title/description/share image. */
		const CHROME_TAGS = array( 'nav', 'header', 'footer', 'form', 'button', 'aside', 'label', 'select', 'figcaption', 'blockquote' );

		/** Default list property per container type, for data-uich-schema-item. */
		const ITEM_PROPS = array(
			'FAQPage'        => 'mainEntity',
			'HowTo'          => 'step',
			'ItemList'       => 'itemListElement',
			'BreadcrumbList' => 'itemListElement',
			'Recipe'         => 'recipeInstructions',
		);

		/**
		 * Derive.
		 *
		 * @param array $sections List of [ 'html' => string, 'uid' => string ] in document order.
		 * @param array $context  {
		 *     @type string $kind 'instance' | 'pattern'.
		 *     @type array  $post { title, excerpt, type }.
		 *     @type array  $site { url, host }.
		 * }
		 * @return array Derived block of the record.
		 */
		public static function derive( array $sections, array $context = array() ) {
			$kind    = isset( $context['kind'] ) && 'pattern' === $context['kind'] ? 'pattern' : 'instance';
			$post    = isset( $context['post'] ) && is_array( $context['post'] ) ? $context['post'] : array();
			$site    = isset( $context['site'] ) && is_array( $context['site'] ) ? $context['site'] : array();
			$host    = isset( $site['host'] ) ? strtolower( (string) $site['host'] ) : '';

			$out = array(
				'headings'        => array(),
				'images'          => array(),
				'links'           => array(),
				'pseudo_headings' => array(),
				'faq_candidates'  => array(),
				'breadcrumbs'     => array(),
				'entities'        => array(),
				// JSON-LD scripts written into the sections (Composer prints them as is).
				'section_ld'      => array(),
				'signals'         => array(
					'has_main'         => false,
					'navs'             => array(),
					'iframes_no_title' => array(),
					'time_no_datetime' => array(),
					'tables_no_header' => array(),
					'product_markup'   => false,
					'price_found'      => false,
				),
				'word_count'      => 0,
				'structure'       => array(),
			);

			$paragraphs  = array(); // [ text, words, path, section, twig ]
			$first_h1    = null;
			$first_img   = null;
			$words_total = 0;
			$words_struct = 0;
			$words_emph  = 0;
			$blocks      = 0;
			$internal    = 0;
			$sig         = '';

			foreach ( array_values( $sections ) as $s_index => $section ) {
				$html = isset( $section['html'] ) ? (string) $section['html'] : '';
				$uid  = isset( $section['uid'] ) ? (string) $section['uid'] : (string) $s_index;
				$sig .= $uid . ':' . md5( $html ) . ';';
				if ( '' === trim( $html ) ) {
					continue;
				}
				if ( 'pattern' === $kind ) {
					$html = Uich_SEO_Html::strip_twig_control( $html );
				}

				list( , $root ) = Uich_SEO_Html::fragment( $html );

				$all = $root->getElementsByTagName( '*' );
				// Snapshot: the live list must not be iterated while reading attributes of nested nodes.
				$nodes = array();
				foreach ( $all as $n ) {
					$nodes[] = $n;
				}

				foreach ( $nodes as $el ) {
					$tag = strtolower( $el->nodeName );

					switch ( $tag ) {
						case 'h1':
						case 'h2':
						case 'h3':
						case 'h4':
						case 'h5':
						case 'h6':
							$text = Uich_SEO_Html::squish( Uich_SEO_Html::text( $el ) );
							$row  = array(
								'level'   => (int) substr( $tag, 1 ),
								'text'    => $text,
								'path'    => Uich_SEO_Html::node_path( $el, $root ),
								'section' => $uid,
							);
							$out['headings'][] = $row;
							if ( 1 === $row['level'] && null === $first_h1 && '' !== $text ) {
								$first_h1 = $row;
							}
							break;

						case 'img':
							$img = self::image_row( $el, $root, $uid, $host );
							$out['images'][] = $img;
							if ( null === $first_img && self::usable_share_image( $img, $el, $root, $kind ) ) {
								$first_img = $img;
							}
							break;

						case 'a':
							$link = self::link_row( $el, $root, $uid, $host );
							if ( null !== $link ) {
								$out['links'][] = $link;
								if ( $link['internal'] ) {
									++$internal;
								}
							}
							break;

						case 'p':
							$text  = Uich_SEO_Html::squish( Uich_SEO_Html::strip_shortcodes( Uich_SEO_Html::text( $el ) ) );
							$words = Uich_SEO_Html::words( $text );
							if ( $words > 0 ) {
								++$blocks;
								if ( ! Uich_SEO_Html::has_ancestor( $el, self::CHROME_TAGS, $root ) ) {
									$paragraphs[] = array(
										'text'    => $text,
										'words'   => $words,
										'path'    => Uich_SEO_Html::node_path( $el, $root ),
										'section' => $uid,
										'twig'    => Uich_SEO_Html::has_twig( $text ),
									);
								}
							}
							break;

						case 'li':
						case 'td':
						case 'th':
						case 'dt':
						case 'dd':
							// Count only leaf-ish structured blocks so nested lists are not double counted.
							if ( ! Uich_SEO_Html::has_ancestor( $el, array( 'li', 'td', 'th', 'dd' ), $root ) ) {
								$w = Uich_SEO_Html::words( Uich_SEO_Html::text( $el ) );
								$words_struct += $w;
								if ( $w > 0 ) {
									++$blocks;
								}
							}
							break;

						case 'strong':
						case 'b':
						case 'em':
						case 'mark':
							if ( ! Uich_SEO_Html::has_ancestor( $el, array( 'strong', 'b', 'em', 'mark' ), $root ) ) {
								$words_emph += Uich_SEO_Html::words( Uich_SEO_Html::text( $el ) );
							}
							break;

						case 'main':
							$out['signals']['has_main'] = true;
							break;

						case 'nav':
							$label = trim( (string) $el->getAttribute( 'aria-label' ) );
							if ( '' === $label && $el->hasAttribute( 'aria-labelledby' ) ) {
								$label = '#' . $el->getAttribute( 'aria-labelledby' );
							}
							$out['signals']['navs'][] = array(
								'label'   => $label,
								'path'    => Uich_SEO_Html::node_path( $el, $root ),
								'section' => $uid,
							);
							if ( self::is_breadcrumb( $el ) ) {
								$crumbs = self::breadcrumb_items( $el, $host );
								if ( count( $crumbs ) >= 2 ) {
									$out['breadcrumbs'][] = array(
										'items'   => $crumbs,
										'path'    => Uich_SEO_Html::node_path( $el, $root ),
										'section' => $uid,
									);
								}
							}
							break;

						case 'iframe':
							if ( '' === trim( (string) $el->getAttribute( 'title' ) ) ) {
								$out['signals']['iframes_no_title'][] = array(
									'path'    => Uich_SEO_Html::node_path( $el, $root ),
									'section' => $uid,
								);
							}
							break;

						case 'time':
							if ( '' === trim( (string) $el->getAttribute( 'datetime' ) ) ) {
								$out['signals']['time_no_datetime'][] = array(
									'path'    => Uich_SEO_Html::node_path( $el, $root ),
									'section' => $uid,
								);
							}
							break;

						case 'table':
							if ( 0 === $el->getElementsByTagName( 'th' )->length && 0 === $el->getElementsByTagName( 'caption' )->length ) {
								$out['signals']['tables_no_header'][] = array(
									'path'    => Uich_SEO_Html::node_path( $el, $root ),
									'section' => $uid,
								);
							}
							break;

						case 'details':
							// Collected per parent below.
							break;

						case 'script':
							if ( 'application/ld+json' === strtolower( trim( (string) $el->getAttribute( 'type' ) ) ) ) {
								$data = json_decode( trim( (string) $el->textContent ), true );
								$path = Uich_SEO_Html::node_path( $el, $root );
								$row  = array(
									// Paths skip <script>: point at the element that holds it.
									'path'    => null !== $path ? $path : ( $el->parentNode && ! $el->parentNode->isSameNode( $root ) ? Uich_SEO_Html::node_path( $el->parentNode, $root ) : null ),
									'section' => $uid,
								);
								if ( ! is_array( $data ) ) {
									$out['section_ld'][] = array_merge( $row, array( 'entity' => null ) );
									break;
								}
								if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
									$data = $data['@graph'];
								} elseif ( isset( $data['@type'] ) ) {
									$data = array( $data );
								}
								foreach ( $data as $e ) {
									if ( is_array( $e ) && ! empty( $e['@type'] ) ) {
										unset( $e['@context'] );
										$out['section_ld'][] = array_merge( $row, array( 'entity' => $e ) );
									}
								}
							}
							break;
					}

					// Elements that look like headings but are not (PRD §18.1 check 6).
					if ( in_array( $tag, array( 'div', 'span', 'p' ), true ) ) {
						$pseudo = self::pseudo_heading( $el, $root, $uid );
						if ( $pseudo ) {
							$out['pseudo_headings'][] = $pseudo;
						}
					}

					// Product signals (PRD §18.4 check 31).
					$schema_attr = (string) $el->getAttribute( 'data-uich-schema' );
					if ( 'Product' === $schema_attr || preg_match( '/\bproduct\b/i', (string) $el->getAttribute( 'class' ) ) ) {
						$out['signals']['product_markup'] = true;
					}
					if ( 'price' === $el->getAttribute( 'data-uich-prop' ) || 'price' === $el->getAttribute( 'itemprop' ) || preg_match( '/\bprice\b/i', (string) $el->getAttribute( 'class' ) ) ) {
						$out['signals']['price_found'] = true;
					}

					// Tier 2: top-level declared entities.
					if ( '' !== $schema_attr && ! self::inside_declared_entity( $el, $root ) && '' === (string) $el->getAttribute( 'data-uich-prop' ) ) {
						$entity = self::declared_entity( $el, $host );
						if ( $entity ) {
							$out['entities'][] = array(
								'entity'  => $entity,
								'path'    => Uich_SEO_Html::node_path( $el, $root ),
								'section' => $uid,
								'source'  => 'markup',
							);
						}
					}
				}

				// FAQ candidates: accordions (PRD §18.4 check 29).
				foreach ( self::faq_groups( $root, $uid ) as $group ) {
					$out['faq_candidates'][] = $group;
				}

				$words_total += Uich_SEO_Html::words( Uich_SEO_Html::text( $root ) );
			}

			// Text metrics prefer the plain-text rendition when the caller has one (08 §1.4).
			if ( ! empty( $context['text'] ) ) {
				$plain = Uich_SEO_Html::words( (string) $context['text'] );
				if ( $plain > $words_total ) {
					$words_total = $plain;
				}
			}
			$out['word_count'] = $words_total;
			$out['source_sig'] = md5( $kind . '|' . $sig );

			// ---- Lane 2 candidates (values the resolver may use) ----

			$out['title']       = self::title_candidate( $first_h1, $kind );
			$out['description'] = self::description_candidate( $paragraphs, $kind );
			$out['share_image'] = $first_img
				? array(
					'value' => array(
						'url' => $first_img['src'],
						'w'   => $first_img['w'],
						'h'   => $first_img['h'],
					),
					'from'    => 'first-image',
					'path'    => $first_img['path'],
					'section' => $first_img['section'],
					'twig'    => Uich_SEO_Html::has_twig( $first_img['src'] ),
				)
				: null;
			$out['content_type'] = $out['signals']['product_markup'] && $out['signals']['price_found']
				? array( 'value' => 'product', 'from' => 'markup' )
				: null;

			// ---- Lane 3: structure (GEO signals, 02 §9). Computed now, surfaced later. ----

			$levels = array();
			foreach ( $out['headings'] as $h ) {
				$levels[ $h['level'] ] = true;
			}
			$para_words = array_sum( array_column( $paragraphs, 'words' ) );
			$out['structure'] = array(
				'heading_depth'       => count( $levels ),
				'link_density'        => $blocks ? round( $internal / $blocks, 3 ) : 0,
				'avg_paragraph_words' => $paragraphs ? (int) round( $para_words / count( $paragraphs ) ) : 0,
				'structured_ratio'    => $words_total ? round( $words_struct / $words_total, 3 ) : 0,
				'emphasis_ratio'      => $words_total ? round( $words_emph / $words_total, 3 ) : 0,
			);

			return $out;
		}

		/**
		 * Title candidate from the first h1.
		 *
		 * @param array|null $h1   Heading row.
		 * @param string     $kind Record kind.
		 * @return array|null
		 */
		private static function title_candidate( $h1, $kind ) {
			if ( ! $h1 ) {
				return null;
			}
			$twig = Uich_SEO_Html::has_twig( $h1['text'] );
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $h1['text'] ) : strlen( $h1['text'] );
			// A hero line of one word or a paragraph-length heading is not a title.
			if ( ! $twig && ( $len < 3 || $len > 120 ) ) {
				return null;
			}
			if ( 'instance' === $kind && $twig ) {
				return null; // Unresolved Twig on an instance means the token rendered to nothing useful.
			}
			return array(
				'value'   => $h1['text'],
				'from'    => 'h1',
				'path'    => $h1['path'],
				'section' => $h1['section'],
				'twig'    => $twig,
			);
		}

		/**
		 * Description candidate: first meaningful paragraph. The excerpt and the
		 * site default sit either side of this in the chain; the resolver owns
		 * the chain because both need WordPress.
		 *
		 * Pattern records: only a paragraph carrying a Twig token qualifies — a
		 * literal paragraph in a template is the same on every post, which is
		 * exactly the 50-identical-descriptions failure (QA fixture 3).
		 *
		 * @param array  $paragraphs Paragraph rows.
		 * @param string $kind       Record kind.
		 * @return array|null
		 */
		private static function description_candidate( array $paragraphs, $kind ) {
			foreach ( $paragraphs as $p ) {
				if ( 'pattern' === $kind ) {
					if ( $p['twig'] ) {
						return array(
							'value'   => $p['text'],
							'from'    => 'template',
							'path'    => $p['path'],
							'section' => $p['section'],
							'twig'    => true,
						);
					}
					continue;
				}
				if ( $p['twig'] || $p['words'] < self::MIN_DESC_WORDS ) {
					continue;
				}
				return array(
					'value'   => self::clip( $p['text'], self::DESC_MAX ),
					'from'    => 'first-paragraph',
					'path'    => $p['path'],
					'section' => $p['section'],
					'twig'    => false,
				);
			}
			return null;
		}

		/**
		 * Cut a string on a word boundary.
		 *
		 * @param string $s   Text.
		 * @param int    $max Max characters.
		 * @return string
		 */
		public static function clip( $s, $max ) {
			$s = trim( (string) $s );
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
			if ( $len <= $max ) {
				return $s;
			}
			$cut = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max - 1 ) : substr( $s, 0, $max - 1 );
			$sp  = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
			if ( false !== $sp && $sp > $max * 0.6 ) {
				$cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $sp ) : substr( $cut, 0, $sp );
			}
			// /u: a byte-wise rtrim() mask would strip the tail bytes of CJK, Cyrillic and emoji.
			return preg_replace( '/[\s,;:\-–—]+$/u', '', $cut ) . '…';
		}

		/**
		 * Image row.
		 *
		 * @param DOMElement $el   Image.
		 * @param DOMElement $root Section root.
		 * @param string     $uid  Section uid.
		 * @param string     $host Site host.
		 * @return array
		 */
		private static function image_row( $el, $root, $uid, $host ) {
			$src = trim( (string) $el->getAttribute( 'src' ) );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				$alt_src = trim( (string) ( $el->getAttribute( 'data-src' ) ?: $el->getAttribute( 'data-lazy-src' ) ) );
				if ( '' !== $alt_src ) {
					$src = $alt_src;
				}
			}
			$has_alt    = $el->hasAttribute( 'alt' );
			$alt        = $has_alt ? Uich_SEO_Html::squish( $el->getAttribute( 'alt' ) ) : null;
			$decorative = ( 'presentation' === strtolower( (string) $el->getAttribute( 'role' ) ) )
				|| ( 'none' === strtolower( (string) $el->getAttribute( 'role' ) ) )
				|| ( 'true' === strtolower( (string) $el->getAttribute( 'aria-hidden' ) ) )
				|| $el->hasAttribute( 'data-uich-decorative' );

			return array(
				'src'        => $src,
				'alt'        => $alt,
				'decorative' => $decorative,
				'w'          => self::dim( $el->getAttribute( 'width' ) ),
				'h'          => self::dim( $el->getAttribute( 'height' ) ),
				'loading'    => strtolower( (string) $el->getAttribute( 'loading' ) ),
				'external'   => self::is_external( $src, $host ),
				'in_chrome'  => Uich_SEO_Html::has_ancestor( $el, array( 'nav', 'header', 'footer' ), $root ),
				// A media-library image: WordPress adds width/height to it when the page renders.
				'wp_image'   => (bool) preg_match( '/(^|\s)wp-image-\d+(\s|$)/', (string) $el->getAttribute( 'class' ) ) || false !== strpos( $src, '/wp-content/uploads/' ),
				'path'       => Uich_SEO_Html::node_path( $el, $root ),
				'section'    => $uid,
			);
		}

		/**
		 * Can this image be the page's share image?
		 *
		 * @param array      $img  Image row.
		 * @param DOMElement $el   Image element.
		 * @param DOMElement $root Root.
		 * @param string     $kind Record kind.
		 * @return bool
		 */
		private static function usable_share_image( array $img, $el, $root, $kind ) {
			if ( '' === $img['src'] || $img['in_chrome'] || $img['decorative'] ) {
				return false;
			}
			if ( Uich_SEO_Html::has_twig( $img['src'] ) ) {
				return 'pattern' === $kind;
			}
			if ( preg_match( '/\.svg(\?|#|$)/i', $img['src'] ) ) {
				return false;
			}
			// Known-small images (icons, avatars) are not share images; unknown size is allowed.
			if ( ( null !== $img['w'] && $img['w'] < 200 ) || ( null !== $img['h'] && $img['h'] < 100 ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Link row, or null for non-navigational anchors.
		 *
		 * @param DOMElement $el   Anchor.
		 * @param DOMElement $root Root.
		 * @param string     $uid  Section uid.
		 * @param string     $host Site host.
		 * @return array|null
		 */
		private static function link_row( $el, $root, $uid, $host ) {
			if ( ! $el->hasAttribute( 'href' ) ) {
				return null;
			}
			$href = trim( (string) $el->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '/^(javascript|mailto|tel|sms):/i', $href ) ) {
				return null;
			}
			$text = Uich_SEO_Html::squish( Uich_SEO_Html::text( $el ) );
			if ( '' === $text ) {
				// An image link is named by its alt.
				foreach ( $el->getElementsByTagName( 'img' ) as $img ) {
					$text = Uich_SEO_Html::squish( $img->getAttribute( 'alt' ) );
					if ( '' !== $text ) {
						break;
					}
				}
			}
			$rel = strtolower( Uich_SEO_Html::squish( $el->getAttribute( 'rel' ) ) );
			return array(
				'href'       => $href,
				'text'       => $text,
				'aria_label' => Uich_SEO_Html::squish( $el->getAttribute( 'aria-label' ) . ' ' . $el->getAttribute( 'title' ) ),
				'internal'   => ! self::is_external( $href, $host ),
				'rel'        => $rel,
				'target'     => strtolower( (string) $el->getAttribute( 'target' ) ),
				'path'       => Uich_SEO_Html::node_path( $el, $root ),
				'section'    => $uid,
			);
		}

		/**
		 * Is a URL on another host? Relative URLs and Twig URLs count as internal.
		 *
		 * @param string $url  URL.
		 * @param string $host Site host.
		 * @return bool
		 */
		public static function is_external( $url, $host ) {
			$url = trim( (string) $url );
			if ( '' === $url || Uich_SEO_Html::has_twig( $url ) ) {
				return false;
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return false;
			}
			$h = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
			if ( '' === $host ) {
				return true;
			}
			$strip = function ( $x ) {
				return preg_replace( '/^www\./', '', $x );
			};
			return $strip( $h ) !== $strip( $host );
		}

		/**
		 * Parse a width/height attribute.
		 *
		 * @param string $v Value.
		 * @return int|null
		 */
		private static function dim( $v ) {
			$v = trim( (string) $v );
			if ( '' === $v || ! preg_match( '/^(\d+)(px)?$/', $v, $m ) ) {
				return null;
			}
			return (int) $m[1];
		}

		/**
		 * Is a nav a breadcrumb trail?
		 *
		 * @param DOMElement $nav Nav.
		 * @return bool
		 */
		private static function is_breadcrumb( $nav ) {
			$hay = strtolower( $nav->getAttribute( 'aria-label' ) . ' ' . $nav->getAttribute( 'class' ) . ' ' . $nav->getAttribute( 'id' ) );
			return false !== strpos( $hay, 'breadcrumb' );
		}

		/**
		 * Breadcrumb trail items.
		 *
		 * @param DOMElement $nav  Nav.
		 * @param string     $host Site host.
		 * @return array<int,array{name:string,url:string}>
		 */
		private static function breadcrumb_items( $nav, $host ) {
			$items = array();
			$lis   = $nav->getElementsByTagName( 'li' );
			if ( $lis->length ) {
				foreach ( $lis as $li ) {
					$name = Uich_SEO_Html::squish( Uich_SEO_Html::text( $li ) );
					$a    = $li->getElementsByTagName( 'a' )->item( 0 );
					if ( '' !== $name ) {
						$items[] = array(
							'name' => $name,
							'url'  => $a ? trim( (string) $a->getAttribute( 'href' ) ) : '',
						);
					}
				}
				return $items;
			}
			foreach ( $nav->getElementsByTagName( 'a' ) as $a ) {
				$name = Uich_SEO_Html::squish( Uich_SEO_Html::text( $a ) );
				if ( '' !== $name ) {
					$items[] = array(
						'name' => $name,
						'url'  => trim( (string) $a->getAttribute( 'href' ) ),
					);
				}
			}
			return $items;
		}

		/**
		 * A block that looks like a heading but is not one (PRD §18.1 check 6).
		 *
		 * The spec's rule is "computed font size ≥ 1.5× body"; a save-time pass has
		 * no layout engine, so this uses the signals markup does carry: an inline
		 * font-size of 24px / 1.5em / 1.5rem or more, or a heading-ish class, on a
		 * short text-only element with no heading ancestor.
		 *
		 * @param DOMElement $el   Element.
		 * @param DOMElement $root Root.
		 * @param string     $uid  Section uid.
		 * @return array|null
		 */
		private static function pseudo_heading( $el, $root, $uid ) {
			foreach ( $el->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && ! in_array( strtolower( $c->nodeName ), array( 'br', 'strong', 'b', 'em', 'span' ), true ) ) {
					return null;
				}
			}
			$text = Uich_SEO_Html::squish( Uich_SEO_Html::text( $el ) );
			$w    = Uich_SEO_Html::words( $text );
			if ( $w < 1 || $w > 12 ) {
				return null;
			}
			$big   = false;
			$style = (string) $el->getAttribute( 'style' );
			if ( preg_match( '/font-size\s*:\s*([\d.]+)\s*(px|em|rem)/i', $style, $m ) ) {
				$n    = (float) $m[1];
				$unit = strtolower( $m[2] );
				$big  = ( 'px' === $unit && $n >= 24 ) || ( 'px' !== $unit && $n >= 1.5 );
			}
			if ( ! $big && ! preg_match( '/(^|[\s_-])(heading|headline|title|h[1-3])($|[\s_-])/i', (string) $el->getAttribute( 'class' ) ) ) {
				return null;
			}
			if ( Uich_SEO_Html::has_ancestor( $el, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'button', 'nav', 'label' ), $root ) ) {
				return null;
			}
			return array(
				'tag'     => strtolower( $el->nodeName ),
				'text'    => $text,
				'path'    => Uich_SEO_Html::node_path( $el, $root ),
				'section' => $uid,
			);
		}

		/**
		 * Accordion groups that read as FAQs: three or more siblings, each a short
		 * question-ish head plus a collapsible body (PRD §18.4 check 29).
		 *
		 * @param DOMElement $root Root.
		 * @param string     $uid  Section uid.
		 * @return array
		 */
		private static function faq_groups( $root, $uid ) {
			$groups  = array();
			$parents = array();

			// <details><summary> is the unambiguous form.
			foreach ( $root->getElementsByTagName( 'details' ) as $d ) {
				$key = spl_object_hash( $d->parentNode );
				$parents[ $key ]['node']    = $d->parentNode;
				$parents[ $key ]['items'][] = $d;
			}
			foreach ( $parents as $p ) {
				if ( count( $p['items'] ) < 3 ) {
					continue;
				}
				$qa = array();
				foreach ( $p['items'] as $d ) {
					$summary = $d->getElementsByTagName( 'summary' )->item( 0 );
					if ( ! $summary ) {
						continue;
					}
					$q   = Uich_SEO_Html::squish( Uich_SEO_Html::text( $summary ) );
					$all = Uich_SEO_Html::squish( Uich_SEO_Html::text( $d ) );
					$a   = trim( (string) preg_replace( '/^' . preg_quote( $q, '/' ) . '/u', '', $all ) );
					if ( '' !== $q && '' !== $a ) {
						$qa[] = array( 'q' => $q, 'a' => $a );
					}
				}
				if ( count( $qa ) >= 3 ) {
					$groups[] = array(
						'items'    => $qa,
						'path'     => Uich_SEO_Html::node_path( $p['node'], $root ),
						'section'  => $uid,
						'declared' => self::inside_declared_entity( $p['node'], $root ) || '' !== (string) $p['node']->getAttribute( 'data-uich-schema' ),
					);
				}
			}

			// ARIA accordions: a trigger with aria-expanded / aria-controls followed by its panel.
			$trig_parents = array();
			$xp           = new DOMXPath( $root->ownerDocument );
			foreach ( $xp->query( './/*[@aria-expanded]', $root ) as $t ) {
				$item = $t;
				// The repeating unit is the trigger's nearest ancestor that also contains the panel.
				while ( $item->parentNode && ! $item->parentNode->isSameNode( $root ) && 1 === count( Uich_SEO_Html::path_children( $item->parentNode ) ) ) {
					$item = $item->parentNode;
				}
				if ( ! $item->parentNode ) {
					continue;
				}
				$unit = $item->parentNode;
				$host = $unit->parentNode;
				if ( ! $host ) {
					continue;
				}
				$key = spl_object_hash( $host );
				$trig_parents[ $key ]['node']                          = $host;
				$trig_parents[ $key ]['units'][ spl_object_hash( $unit ) ] = array( $unit, $t );
			}
			foreach ( $trig_parents as $p ) {
				if ( count( $p['units'] ) < 3 ) {
					continue;
				}
				$qa = array();
				foreach ( $p['units'] as $pair ) {
					list( $unit, $t ) = $pair;
					$q   = Uich_SEO_Html::squish( Uich_SEO_Html::text( $t ) );
					$all = Uich_SEO_Html::squish( Uich_SEO_Html::text( $unit ) );
					$a   = trim( (string) preg_replace( '/^' . preg_quote( $q, '/' ) . '/u', '', $all ) );
					if ( '' !== $q && '' !== $a && Uich_SEO_Html::words( $q ) <= 25 ) {
						$qa[] = array( 'q' => $q, 'a' => $a );
					}
				}
				if ( count( $qa ) >= 3 ) {
					$groups[] = array(
						'items'    => $qa,
						'path'     => Uich_SEO_Html::node_path( $p['node'], $root ),
						'section'  => $uid,
						'declared' => self::inside_declared_entity( $p['node'], $root ),
					);
				}
			}

			return $groups;
		}

		/**
		 * Is the element inside (or itself) a data-uich-schema container?
		 *
		 * @param DOMElement $el   Element.
		 * @param DOMElement $root Root.
		 * @return bool
		 */
		private static function inside_declared_entity( $el, $root ) {
			$node = $el->parentNode;
			while ( $node && XML_ELEMENT_NODE === $node->nodeType && ! $node->isSameNode( $root ) ) {
				if ( $node->hasAttribute( 'data-uich-schema' ) ) {
					return true;
				}
				$node = $node->parentNode;
			}
			return false;
		}

		/**
		 * Read a tier-2 declared entity (D8):
		 *
		 *   data-uich-schema="Type"          an entity
		 *   data-uich-prop="name"            a property of the nearest entity
		 *   data-uich-value="literal"        overrides the element's own value
		 *   data-uich-schema-item="Type"     a child entity appended to the
		 *                                    parent's list property (FAQPage →
		 *                                    mainEntity, HowTo → step, …)
		 *
		 * A nested data-uich-schema that also carries data-uich-prop becomes that
		 * property's value (an object, or a list when repeated).
		 *
		 * @param DOMElement $el   Entity root.
		 * @param string     $host Site host.
		 * @return array|null
		 */
		private static function declared_entity( $el, $host ) {
			$type = trim( (string) ( $el->getAttribute( 'data-uich-schema' ) ?: $el->getAttribute( 'data-uich-schema-item' ) ) );
			if ( ! preg_match( '/^[A-Z][A-Za-z]{1,60}$/', $type ) ) {
				return null;
			}
			$entity = array( '@type' => $type );
			self::collect_props( $el, $entity, $host, $type );
			return $entity;
		}

		/**
		 * Walk descendants, filling props until another entity boundary.
		 *
		 * @param DOMElement $parent Container.
		 * @param array      $entity Entity being filled (by reference).
		 * @param string     $host   Site host.
		 * @param string     $type   Entity type.
		 * @return void
		 */
		private static function collect_props( $parent, array &$entity, $host, $type ) {
			foreach ( $parent->childNodes as $c ) {
				if ( XML_ELEMENT_NODE !== $c->nodeType ) {
					continue;
				}
				$prop    = trim( (string) $c->getAttribute( 'data-uich-prop' ) );
				$is_ent  = $c->hasAttribute( 'data-uich-schema' );
				$is_item = $c->hasAttribute( 'data-uich-schema-item' );

				if ( $is_item ) {
					$child = self::declared_entity( $c, $host );
					if ( $child ) {
						$list = '' !== $prop ? $prop : ( isset( self::ITEM_PROPS[ $type ] ) ? self::ITEM_PROPS[ $type ] : 'itemListElement' );
						$entity[ $list ][] = $child;
					}
					continue;
				}
				if ( $is_ent ) {
					$child = self::declared_entity( $c, $host );
					if ( $child && '' !== $prop ) {
						self::add_prop( $entity, $prop, $child );
					}
					continue; // A nested entity without a prop is its own top-level entity.
				}
				if ( '' !== $prop && preg_match( '/^[A-Za-z][A-Za-z0-9]{0,60}$/', $prop ) ) {
					self::add_prop( $entity, $prop, self::prop_value( $c ) );
				}
				self::collect_props( $c, $entity, $host, $type );
			}
		}

		/**
		 * Add a property, turning repeats into a list.
		 *
		 * @param array  $entity Entity.
		 * @param string $prop   Property.
		 * @param mixed  $value  Value.
		 * @return void
		 */
		private static function add_prop( array &$entity, $prop, $value ) {
			if ( '' === $value || null === $value ) {
				return;
			}
			if ( ! isset( $entity[ $prop ] ) ) {
				$entity[ $prop ] = $value;
				return;
			}
			if ( ! is_array( $entity[ $prop ] ) || isset( $entity[ $prop ]['@type'] ) ) {
				$entity[ $prop ] = array( $entity[ $prop ] );
			}
			$entity[ $prop ][] = $value;
		}

		/**
		 * The value an element contributes to a property.
		 *
		 * @param DOMElement $el Element.
		 * @return string
		 */
		private static function prop_value( $el ) {
			if ( $el->hasAttribute( 'data-uich-value' ) ) {
				return trim( (string) $el->getAttribute( 'data-uich-value' ) );
			}
			$tag = strtolower( $el->nodeName );
			if ( 'meta' === $tag ) {
				return trim( (string) $el->getAttribute( 'content' ) );
			}
			if ( in_array( $tag, array( 'img', 'source', 'video', 'audio', 'iframe' ), true ) ) {
				return trim( (string) $el->getAttribute( 'src' ) );
			}
			if ( in_array( $tag, array( 'a', 'link' ), true ) && $el->hasAttribute( 'href' ) && '' === Uich_SEO_Html::squish( Uich_SEO_Html::text( $el ) ) ) {
				return trim( (string) $el->getAttribute( 'href' ) );
			}
			if ( 'time' === $tag && $el->hasAttribute( 'datetime' ) ) {
				return trim( (string) $el->getAttribute( 'datetime' ) );
			}
			return Uich_SEO_Html::squish( Uich_SEO_Html::text( $el ) );
		}
	}
}
