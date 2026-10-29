<?php
/**
 * Head code — the source of truth for SEO tags.
 *
 * Every storable SEO value lives as a real tag in the page's or the site's
 * before-</head> code (Composer page code / site code). Nothing is kept in a
 * second store: this class reads those tags, writes them, and strips them from
 * Composer's raw output so the SEO module can print each one exactly once.
 *
 * Precedence: page code → the page's content (derived) → site code → WordPress.
 * The site `<title>` is the title template: `{{ title }}` marks where the page
 * title goes.
 *
 * Tags it manages (anything else in head code is left alone and printed as is):
 *   <title>, meta description / robots / twitter:* / verification codes /
 *   uichemy:ai, og:* properties, link rel=canonical, and JSON-LD scripts.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Head' ) ) {

	/**
	 * Head-code SEO tags.
	 */
	class Uich_SEO_Head {

		/** Marker meta for "keep this page out of llms.txt and Markdown". */
		const AI_META = 'uichemy:ai';

		/**
		 * Post meta holding the SEO tags of a post with no Composer sections (a
		 * block-editor or classic post), which has no page code to keep them in.
		 */
		const PAGE_META = '_uichemy_seo_head';

		/** meta[name] values this module owns. */
		const META_NAMES = array(
			'description',
			'robots',
			'twitter:card',
			'twitter:site',
			'twitter:title',
			'twitter:description',
			'twitter:image',
			'twitter:image:alt',
			self::AI_META,
		);

		/** Verification meta names → settings key. */
		const VERIFY = array(
			'google-site-verification' => 'verify_google',
			'msvalidate.01'            => 'verify_bing',
			'yandex-verification'      => 'verify_yandex',
			'baidu-site-verification'  => 'verify_baidu',
			'p:domain_verify'          => 'verify_pinterest',
		);

		/** meta[property] values this module owns. */
		const META_PROPS = array(
			'og:title',
			'og:description',
			'og:image',
			'og:image:secure_url',
			'og:image:alt',
			'og:image:width',
			'og:image:height',
			'og:type',
			'og:locale',
			'og:site_name',
			'og:url',
		);

		/** Site settings keys that are stored as site head tags, not in the option. */
		const SITE_KEYS = array(
			'title_template',
			'home_description',
			'default_social_description',
			'default_social_title',
			'default_share_image',
			'site_name_override',
			'og_locale',
			'twitter_handle',
			'twitter_card',
			'verify_google',
			'verify_bing',
			'verify_yandex',
			'verify_baidu',
			'verify_pinterest',
			'entity_type',
			'entity_name',
			'entity_logo',
			'entity_description',
			'entity_email',
			'entity_phone',
			'social_profiles',
		);

		/**
		 * Parsed site head, per request.
		 *
		 * @var array|null
		 */
		private static $site = null;

		/**
		 * Live page-head parses, per request (admin / MCP; the front end reads the cache).
		 *
		 * @var array<int,array>
		 */
		private static $pages = array();

		/**
		 * Live page-head parses with the author rules applied, per request.
		 *
		 * @var array<int,array>
		 */
		private static $trusted = array();

		// ------------------------------------------------------------------
		// Parse
		// ------------------------------------------------------------------

		/**
		 * An empty parse.
		 *
		 * @return array
		 */
		public static function blank() {
			return array(
				'title'       => null,
				'description' => null,
				'canonical'   => null,
				'robots'      => null,
				'og'          => array(),
				'twitter'     => array(),
				'verify'      => array(),
				'image_id'    => 0,
				'ai_exclude'  => false,
				'ldjson'      => array(),
				'ld_errors'   => 0,
			);
		}

		/**
		 * Pull the managed SEO tags out of head code.
		 *
		 * @param string $html Head code.
		 * @return array{0:array,1:string} [ parsed, the code with those tags removed ].
		 */
		public static function extract( $html ) {
			$html   = (string) $html;
			$parsed = self::blank();
			if ( '' === trim( $html ) || ! preg_match( '/<(title|meta|link|script)\b/i', $html ) ) {
				return array( $parsed, $html );
			}

			// Comments, styles and non-JSON-LD scripts are matched first and handed back
			// untouched, so a tag inside them (a commented-out <title>, a "<meta" string
			// in inline JS) is never mistaken for a real one.
			// An unclosed <title> is taken too: left in, it would swallow the rest of the head.
			$re   = '#<!--.*?-->|<style\b[^>]*>.*?</style\s*>|<script\b[^>]*>.*?</script\s*>|<title\b[^>]*>(?:.*?</title\s*>|)|<meta\b[^>]*>|<link\b[^>]*>#is';
			$rest = preg_replace_callback(
				$re,
				function ( $m ) use ( &$parsed ) {
					return self::take( $m[0], $parsed ) ? '' : $m[0];
				},
				$html
			);
			$rest = trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) $rest ) );
			return array( $parsed, $rest );
		}

		/**
		 * Parse only.
		 *
		 * @param string $html Head code.
		 * @return array
		 */
		public static function parse( $html ) {
			$r = self::extract( $html );
			return $r[0];
		}

		/**
		 * Head code with the managed tags removed (Composer's printer uses this).
		 *
		 * @param string $html Head code.
		 * @return string
		 */
		public static function strip( $html ) {
			$r = self::extract( $html );
			return $r[1];
		}

		/**
		 * Record one tag into $parsed when it is ours.
		 *
		 * @param string $tag    Full tag (element for title/script).
		 * @param array  $parsed Parsed, by reference.
		 * @return bool Taken (remove it from the raw output).
		 */
		private static function take( $tag, array &$parsed ) {
			$lower = strtolower( substr( $tag, 0, 8 ) );
			if ( 0 === strpos( $lower, '<!--' ) || 0 === strpos( $lower, '<style' ) ) {
				return false;
			}

			if ( 0 === strpos( $lower, '<title' ) ) {
				$text = self::text( preg_replace( '#^<title\b[^>]*>|</title\s*>$#i', '', $tag ) );
				if ( '' !== $text ) {
					$parsed['title'] = $text;
				}
				return true;
			}

			$attrs = self::attrs( $tag );

			if ( 0 === strpos( $lower, '<script' ) ) {
				if ( 'application/ld+json' !== strtolower( trim( isset( $attrs['type'] ) ? $attrs['type'] : '' ) ) ) {
					return false;
				}
				$json = trim( (string) preg_replace( '#^<script\b[^>]*>|</script\s*>$#i', '', $tag ) );
				$data = json_decode( $json, true );
				if ( ! is_array( $data ) ) {
					++$parsed['ld_errors'];
					return true; // Invalid JSON-LD is worse than none: never print it.
				}
				foreach ( self::entities( $data ) as $entity ) {
					$parsed['ldjson'][] = $entity;
				}
				return true;
			}

			if ( 0 === strpos( $lower, '<link' ) ) {
				$rel = strtolower( trim( isset( $attrs['rel'] ) ? $attrs['rel'] : '' ) );
				if ( 'canonical' === $rel && isset( $attrs['href'] ) ) {
					$parsed['canonical'] = trim( $attrs['href'] );
					return true;
				}
				return false;
			}

			// <meta>.
			$content = isset( $attrs['content'] ) ? trim( $attrs['content'] ) : '';
			$name    = strtolower( trim( isset( $attrs['name'] ) ? $attrs['name'] : '' ) );
			$prop    = strtolower( trim( isset( $attrs['property'] ) ? $attrs['property'] : '' ) );

			if ( '' !== $prop && in_array( $prop, self::META_PROPS, true ) ) {
				if ( 'og:image' === $prop && ! empty( $attrs['data-id'] ) ) {
					$parsed['image_id'] = absint( $attrs['data-id'] );
				}
				if ( ! isset( $parsed['og'][ $prop ] ) ) { // First one wins, like a crawler.
					$parsed['og'][ $prop ] = $content;
				}
				return true;
			}
			if ( '' === $name ) {
				return false;
			}
			if ( isset( self::VERIFY[ $name ] ) ) {
				$parsed['verify'][ self::VERIFY[ $name ] ] = $content;
				return true;
			}
			if ( ! in_array( $name, self::META_NAMES, true ) ) {
				return false;
			}
			if ( 'description' === $name ) {
				$parsed['description'] = $content;
			} elseif ( 'robots' === $name ) {
				$parsed['robots'] = null === $parsed['robots'] ? $content : $parsed['robots'] . ', ' . $content;
			} elseif ( self::AI_META === $name ) {
				$parsed['ai_exclude'] = false !== strpos( strtolower( $content ), 'exclude' );
			} elseif ( ! isset( $parsed['twitter'][ $name ] ) ) {
				$parsed['twitter'][ $name ] = $content;
			}
			return true;
		}

		/**
		 * Attributes of one opening tag, decoded.
		 *
		 * @param string $tag Tag.
		 * @return array<string,string>
		 */
		private static function attrs( $tag ) {
			$out = array();
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$p = new WP_HTML_Tag_Processor( $tag );
				if ( $p->next_tag() ) {
					foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $n ) {
						$v                      = $p->get_attribute( $n );
						$out[ strtolower( $n ) ] = true === $v ? '' : (string) $v;
					}
				}
				return $out;
			}
			if ( preg_match_all( '/([a-zA-Z_:.\-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $a ) {
					$v                           = isset( $a[5] ) && '' !== $a[5] ? $a[5] : ( isset( $a[4] ) && '' !== $a[4] ? $a[4] : $a[3] );
					$out[ strtolower( $a[1] ) ] = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			return $out;
		}

		/**
		 * Decoded plain text.
		 *
		 * @param string $s Raw.
		 * @return string
		 */
		private static function text( $s ) {
			$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		}

		/**
		 * JSON-LD data → a flat list of entities (a @graph is unwrapped).
		 *
		 * @param array $data Decoded JSON.
		 * @return array[]
		 */
		private static function entities( array $data ) {
			if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
				$data = $data['@graph'];
			} elseif ( isset( $data['@type'] ) ) {
				$data = array( $data );
			}
			$out = array();
			foreach ( $data as $e ) {
				if ( is_array( $e ) && ! empty( $e['@type'] ) ) {
					unset( $e['@context'] );
					$out[] = $e;
				}
			}
			return $out;
		}

		// ------------------------------------------------------------------
		// Parsed ⇄ page fields
		// ------------------------------------------------------------------

		/**
		 * A parse as page fields: field => { value, from: 'page' }. Only what the
		 * head code actually sets appears; absent fields follow the page.
		 *
		 * @param array $p Parsed.
		 * @return array
		 */
		public static function to_fields( array $p ) {
			$p   = array_merge( self::blank(), $p );
			$out = array();
			$set = function ( $k, $v ) use ( &$out ) {
				$out[ $k ] = array(
					'value' => $v,
					'from'  => 'page',
				);
			};

			if ( null !== $p['title'] && '' !== $p['title'] ) {
				$set( 'title', $p['title'] );
			}
			if ( null !== $p['description'] && '' !== $p['description'] ) {
				$set( 'description', $p['description'] );
			}
			if ( null !== $p['canonical'] && '' !== $p['canonical'] ) {
				$set( 'canonical', $p['canonical'] );
			}
			if ( null !== $p['robots'] ) {
				$r = self::robots_from( $p['robots'] );
				if ( null !== $r['index'] ) {
					$set( 'index', $r['index'] );
				}
				if ( null !== $r['follow'] ) {
					$set( 'follow', $r['follow'] );
				}
				if ( $r['advanced'] ) {
					$set( 'robots_advanced', $r['advanced'] );
				}
			}
			$og = $p['og'];
			$tw = $p['twitter'];
			$st = ! empty( $og['og:title'] ) ? $og['og:title'] : ( ! empty( $tw['twitter:title'] ) ? $tw['twitter:title'] : '' );
			if ( '' !== $st ) {
				$set( 'social_title', $st );
			}
			$sd = ! empty( $og['og:description'] ) ? $og['og:description'] : ( ! empty( $tw['twitter:description'] ) ? $tw['twitter:description'] : '' );
			if ( '' !== $sd ) {
				$set( 'social_description', $sd );
			}
			$img = self::image_from( $p );
			if ( $img ) {
				$set( 'share_image', $img );
			}
			if ( ! empty( $og['og:type'] ) ) {
				$map = array(
					'website' => 'webpage',
					'article' => 'article',
					'product' => 'product',
				);
				$t   = strtolower( $og['og:type'] );
				$set( 'content_type', isset( $map[ $t ] ) ? $map[ $t ] : 'webpage' );
			}
			if ( ! empty( $og['og:locale'] ) ) {
				$set( 'og_locale', $og['og:locale'] );
			}
			if ( $p['ai_exclude'] ) {
				$set( 'exclude_ai', true );
			}
			if ( $p['ldjson'] ) {
				$set( 'schema_extra', array_values( $p['ldjson'] ) );
			}
			return $out;
		}

		/**
		 * The share image a parse names, with size when it is a media item.
		 *
		 * @param array $p Parsed.
		 * @return array|null
		 */
		public static function image_from( array $p ) {
			$og  = isset( $p['og'] ) ? (array) $p['og'] : array();
			$tw  = isset( $p['twitter'] ) ? (array) $p['twitter'] : array();
			$url = ! empty( $og['og:image'] ) ? $og['og:image'] : ( ! empty( $tw['twitter:image'] ) ? $tw['twitter:image'] : '' );
			if ( '' === $url ) {
				return null;
			}
			$id = ! empty( $p['image_id'] ) ? absint( $p['image_id'] ) : 0;
			if ( $id && wp_attachment_is_image( $id ) ) {
				$src = wp_get_attachment_image_src( $id, 'full' );
				if ( $src ) {
					return array(
						'id'  => $id,
						'url' => $src[0],
						'w'   => (int) $src[1],
						'h'   => (int) $src[2],
						'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					);
				}
			}
			$alt = ! empty( $og['og:image:alt'] ) ? $og['og:image:alt'] : ( ! empty( $tw['twitter:image:alt'] ) ? $tw['twitter:image:alt'] : '' );
			return array(
				'id'  => 0,
				'url' => 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ? home_url( $url ) : $url,
				'w'   => ! empty( $og['og:image:width'] ) ? absint( $og['og:image:width'] ) : null,
				'h'   => ! empty( $og['og:image:height'] ) ? absint( $og['og:image:height'] ) : null,
				'alt' => $alt,
			);
		}

		/**
		 * robots content → index / follow / advanced flags.
		 *
		 * @param string $content robots content.
		 * @return array{index:?bool,follow:?bool,advanced:array}
		 */
		public static function robots_from( $content ) {
			$out = array(
				'index'    => null,
				'follow'   => null,
				'advanced' => array(),
			);
			foreach ( preg_split( '/\s*,\s*/', strtolower( (string) $content ) ) as $d ) {
				$d = trim( $d );
				if ( 'noindex' === $d || 'none' === $d ) {
					$out['index'] = false;
				}
				if ( 'nofollow' === $d || 'none' === $d ) {
					$out['follow'] = false;
				}
				if ( 'index' === $d ) {
					$out['index'] = true;
				}
				if ( 'follow' === $d ) {
					$out['follow'] = true;
				}
				if ( in_array( $d, array( 'noarchive', 'nosnippet', 'noimageindex' ), true ) ) {
					$out['advanced'][ $d ] = true;
				}
				if ( preg_match( '/^max-snippet\s*:\s*(-?\d+)$/', $d, $m ) ) {
					$out['advanced']['max_snippet'] = max( -1, (int) $m[1] );
				}
				if ( preg_match( '/^max-image-preview\s*:\s*(none|standard|large)$/', $d, $m ) ) {
					$out['advanced']['max_image_preview'] = $m[1];
				}
			}
			return $out;
		}

		/**
		 * Apply a page-field patch to a parse. A null / '' value removes the tag,
		 * so the field follows the page again.
		 *
		 * @param array $p     Parsed.
		 * @param array $patch field => sanitised value | null.
		 * @return array
		 */
		public static function apply_fields( array $p, array $patch ) {
			$p = array_merge( self::blank(), $p );
			foreach ( $patch as $k => $v ) {
				$clear = null === $v || ( is_string( $v ) && '' === trim( $v ) );
				switch ( $k ) {
					case 'title':
						$p['title'] = $clear ? null : (string) $v;
						break;
					case 'description':
						$p['description'] = $clear ? null : (string) $v;
						break;
					case 'canonical':
						$p['canonical'] = $clear ? null : (string) $v;
						break;
					case 'social_title':
						unset( $p['og']['og:title'], $p['twitter']['twitter:title'] );
						if ( ! $clear ) {
							$p['og']['og:title'] = (string) $v;
						}
						break;
					case 'social_description':
						unset( $p['og']['og:description'], $p['twitter']['twitter:description'] );
						if ( ! $clear ) {
							$p['og']['og:description'] = (string) $v;
						}
						break;
					case 'share_image':
						unset( $p['og']['og:image'], $p['og']['og:image:secure_url'], $p['og']['og:image:width'], $p['og']['og:image:height'], $p['og']['og:image:alt'], $p['twitter']['twitter:image'], $p['twitter']['twitter:image:alt'] );
						$p['image_id'] = 0;
						if ( ! $clear && is_array( $v ) && ! empty( $v['url'] ) ) {
							$p['og']['og:image'] = (string) $v['url'];
							$p['image_id']       = ! empty( $v['id'] ) ? absint( $v['id'] ) : 0;
							if ( ! $p['image_id'] ) {
								if ( ! empty( $v['w'] ) ) {
									$p['og']['og:image:width'] = (string) absint( $v['w'] );
								}
								if ( ! empty( $v['h'] ) ) {
									$p['og']['og:image:height'] = (string) absint( $v['h'] );
								}
							}
						}
						break;
					case 'content_type':
						unset( $p['og']['og:type'] );
						if ( ! $clear ) {
							$map                  = array(
								'webpage' => 'website',
								'article' => 'article',
								'product' => 'product',
							);
							$p['og']['og:type'] = isset( $map[ $v ] ) ? $map[ $v ] : 'website';
						}
						break;
					case 'og_locale':
						unset( $p['og']['og:locale'] );
						if ( ! $clear ) {
							$p['og']['og:locale'] = (string) $v;
						}
						break;
					case 'exclude_ai':
						$p['ai_exclude'] = ! $clear && (bool) $v;
						break;
					case 'schema_extra':
						$p['ldjson'] = $clear ? array() : array_values( (array) $v );
						break;
					case 'index':
					case 'follow':
					case 'robots_advanced':
						$r = self::robots_from( (string) $p['robots'] );
						if ( 'robots_advanced' === $k ) {
							$r['advanced'] = $clear ? array() : (array) $v;
						} else {
							$r[ $k ] = $clear ? null : (bool) $v;
						}
						$p['robots'] = self::robots_line( $r );
						break;
				}
			}
			return $p;
		}

		/**
		 * index / follow / advanced → robots content ('' when nothing is set).
		 *
		 * @param array $r { index, follow, advanced }.
		 * @return string|null
		 */
		private static function robots_line( array $r ) {
			$d = array();
			if ( false === $r['index'] ) {
				$d[] = 'noindex';
			}
			if ( false === $r['follow'] ) {
				$d[] = 'nofollow';
			}
			foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $f ) {
				if ( ! empty( $r['advanced'][ $f ] ) ) {
					$d[] = $f;
				}
			}
			if ( isset( $r['advanced']['max_snippet'] ) ) {
				$d[] = 'max-snippet:' . (int) $r['advanced']['max_snippet'];
			}
			if ( ! empty( $r['advanced']['max_image_preview'] ) ) {
				$d[] = 'max-image-preview:' . $r['advanced']['max_image_preview'];
			}
			return $d ? implode( ', ', $d ) : null;
		}

		/**
		 * Three-way merge of SEO tags, one tag at a time: a tag `mine` changed from
		 * `base` takes `mine`; an untouched one takes `theirs` (stored now).
		 *
		 * @param array $base   Parse the editor started from.
		 * @param array $mine   Parse the editor sent.
		 * @param array $theirs Parse stored now.
		 * @return array
		 */
		public static function merge3( array $base, array $mine, array $theirs ) {
			$base   = array_merge( self::blank(), $base );
			$mine   = array_merge( self::blank(), $mine );
			$theirs = array_merge( self::blank(), $theirs );
			$pick   = function ( $b, $m, $t ) {
				return $m === $b ? $t : $m;
			};
			$out = self::blank();
			foreach ( array( 'title', 'description', 'canonical', 'robots', 'ai_exclude', 'ldjson' ) as $k ) {
				$out[ $k ] = $pick( $base[ $k ], $mine[ $k ], $theirs[ $k ] );
			}
			// The image id belongs to og:image: move them together.
			$img_b           = array( isset( $base['og']['og:image'] ) ? $base['og']['og:image'] : null, $base['image_id'] );
			$img_m           = array( isset( $mine['og']['og:image'] ) ? $mine['og']['og:image'] : null, $mine['image_id'] );
			$out['image_id'] = $img_m === $img_b ? $theirs['image_id'] : $mine['image_id'];
			foreach ( array( 'og', 'twitter', 'verify' ) as $map ) {
				$keys = array_unique( array_merge( array_keys( $base[ $map ] ), array_keys( $mine[ $map ] ), array_keys( $theirs[ $map ] ) ) );
				foreach ( $keys as $k ) {
					$v = $pick(
						isset( $base[ $map ][ $k ] ) ? $base[ $map ][ $k ] : null,
						isset( $mine[ $map ][ $k ] ) ? $mine[ $map ][ $k ] : null,
						isset( $theirs[ $map ][ $k ] ) ? $theirs[ $map ][ $k ] : null
					);
					if ( null !== $v ) {
						$out[ $map ][ $k ] = $v;
					}
				}
			}
			return $out;
		}

		/**
		 * A parse back to tags, in a stable order.
		 *
		 * @param array $p Parsed.
		 * @return string
		 */
		public static function build( array $p ) {
			$p     = array_merge( self::blank(), $p );
			$lines = array();
			$meta  = function ( $attr, $key, $value, $extra = '' ) {
				return '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( (string) $value ) . '"' . $extra . ' />';
			};
			if ( null !== $p['title'] && '' !== $p['title'] ) {
				$lines[] = '<title>' . esc_html( $p['title'] ) . '</title>';
			}
			if ( null !== $p['description'] && '' !== $p['description'] ) {
				$lines[] = $meta( 'name', 'description', $p['description'] );
			}
			if ( null !== $p['canonical'] && '' !== $p['canonical'] ) {
				$lines[] = '<link rel="canonical" href="' . esc_url( $p['canonical'] ) . '" />';
			}
			if ( null !== $p['robots'] && '' !== $p['robots'] ) {
				$lines[] = $meta( 'name', 'robots', $p['robots'] );
			}
			foreach ( self::META_PROPS as $prop ) {
				if ( isset( $p['og'][ $prop ] ) && '' !== (string) $p['og'][ $prop ] ) {
					$extra   = ( 'og:image' === $prop && ! empty( $p['image_id'] ) ) ? ' data-id="' . absint( $p['image_id'] ) . '"' : '';
					$lines[] = $meta( 'property', $prop, $p['og'][ $prop ], $extra );
				}
			}
			foreach ( $p['twitter'] as $name => $value ) {
				if ( '' !== (string) $value ) {
					$lines[] = $meta( 'name', $name, $value );
				}
			}
			$names = array_flip( self::VERIFY );
			foreach ( $p['verify'] as $key => $value ) {
				if ( '' !== (string) $value && isset( $names[ $key ] ) ) {
					$lines[] = $meta( 'name', $names[ $key ], $value );
				}
			}
			if ( $p['ai_exclude'] ) {
				$lines[] = $meta( 'name', self::AI_META, 'exclude' );
			}
			foreach ( $p['ldjson'] as $entity ) {
				$lines[] = '<script type="application/ld+json">' . wp_json_encode(
					array_merge( array( '@context' => 'https://schema.org' ), (array) $entity ),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT
				) . '</script>';
			}
			return implode( "\n", $lines );
		}

		/**
		 * Rewrite head code with a new parse: SEO tags first, everything else after.
		 *
		 * @param string $html   Current head code.
		 * @param array  $parsed New parse.
		 * @return string
		 */
		public static function compose( $html, array $parsed ) {
			list( , $rest ) = self::extract( $html );
			$tags           = self::build( $parsed );
			return trim( $tags . ( '' !== $tags && '' !== $rest ? "\n\n" : '' ) . $rest );
		}

		// ------------------------------------------------------------------
		// Page head code
		// ------------------------------------------------------------------

		/**
		 * The builder driver that owns a post, or null.
		 *
		 * @param int $post_id Post id.
		 * @return object|null
		 */
		private static function driver( $post_id ) {
			if ( ! class_exists( 'UiChemy_Builder_Registry' ) ) {
				return null;
			}
			$slug   = UiChemy_Builder_Registry::detect_post_builder( $post_id );
			$driver = $slug ? UiChemy_Builder_Registry::get( $slug ) : null;
			return ( $driver && $driver->is_available() ) ? $driver : null;
		}

		/**
		 * A post's raw before-</head> page code ('' when it has none).
		 *
		 * @param int $post_id Post id.
		 * @return string
		 */
		public static function page_raw( $post_id ) {
			$post_id = absint( $post_id );
			// A Composer Builder page keeps its head code in its own document, which
			// no builder driver reads; without this its SEO tags printed raw next to
			// the module's own (F-01).
			if ( class_exists( 'Uich_CB_Content' ) && Uich_CB_Content::is_enabled( $post_id ) ) {
				$document = Uich_CB_Content::get_cb_content( $post_id );
				$head     = isset( $document['content']['before_head_end_html'] ) ? (string) $document['content']['before_head_end_html'] : '';
				if ( '' !== trim( $head ) ) {
					return $head;
				}
			}
			$driver  = self::driver( $post_id );
			$sections = $driver ? $driver->load_sections( $post_id, 'content' ) : array();
			if ( ! is_wp_error( $sections ) ) {
				// Every section carries the same copy (Section_Ops::sync_page_code); the
				// first non-empty one is authoritative, matching the front-end printer.
				foreach ( (array) $sections as $s ) {
					$head = isset( $s['settings']['page_custom_code_head'] ) ? (string) $s['settings']['page_custom_code_head'] : '';
					if ( '' !== trim( $head ) ) {
						return $head;
					}
				}
			}
			// No page code: a block-editor / classic post keeps its SEO tags in post meta.
			return (string) get_post_meta( $post_id, self::PAGE_META, true );
		}

		/**
		 * Does the post have Composer sections (so its SEO tags live in page code)?
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public static function has_sections( $post_id ) {
			// A Composer Builder document has no sections, but its head code is
			// the page's own before-</head> code all the same.
			if ( self::is_cb_document( $post_id ) ) {
				return true;
			}
			$driver = self::driver( $post_id );
			if ( ! $driver ) {
				return false;
			}
			$sections = $driver->load_sections( absint( $post_id ), 'content' );
			return ! is_wp_error( $sections ) && ! empty( $sections );
		}

		/**
		 * A post's parsed page head, read live (admin / MCP / rebuild).
		 *
		 * @param int  $post_id Post id.
		 * @param bool $fresh   Skip the request memo.
		 * @return array
		 */
		public static function page( $post_id, $fresh = false ) {
			$post_id = absint( $post_id );
			if ( $fresh || ! isset( self::$pages[ $post_id ] ) ) {
				self::$pages[ $post_id ] = self::parse( self::page_raw( $post_id ) );
				unset( self::$trusted[ $post_id ] );
			}
			return self::$pages[ $post_id ];
		}

		/**
		 * A post's parsed page head as the front end may use it (see trusted()),
		 * read live and memoised per request.
		 *
		 * @param int $post_id Post id.
		 * @return array
		 */
		public static function page_trusted( $post_id ) {
			$post_id = absint( $post_id );
			if ( ! isset( self::$trusted[ $post_id ] ) ) {
				self::$trusted[ $post_id ] = self::trusted( self::page( $post_id ), $post_id );
			}
			return self::$trusted[ $post_id ];
		}

		/**
		 * May the post's author print raw code? Mirrors Composer's page-code printer,
		 * which passes the page code of an author without `unfiltered_html` through
		 * wp_kses_post().
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public static function author_trusted( $post_id ) {
			// Author and last editor (Uich_Dynamic::author_trusted()).
			if ( absint( $post_id ) && class_exists( 'Uich_Dynamic' ) ) {
				return Uich_Dynamic::author_trusted( absint( $post_id ) );
			}
			$author_id = (int) get_post_field( 'post_author', absint( $post_id ) );
			return $author_id > 0 && user_can( $author_id, 'unfiltered_html' );
		}

		/**
		 * Drop from a page-head parse what its author could not have published:
		 * for an author without `unfiltered_html`, JSON-LD is ignored (Composer
		 * would never print their <script>), and canonical / og:url follow the
		 * same rules as a canonical typed in the SEO panel.
		 *
		 * @param array $parsed  Parsed page head.
		 * @param int   $post_id Post id.
		 * @return array
		 */
		public static function trusted( array $parsed, $post_id ) {
			$parsed = array_merge( self::blank(), $parsed );
			if ( self::author_trusted( $post_id ) ) {
				return $parsed;
			}
			$author_id        = (int) get_post_field( 'post_author', absint( $post_id ) );
			$parsed['ldjson'] = array();
			if ( null !== $parsed['canonical'] && '' !== $parsed['canonical'] && ! Uich_SEO_Record::author_url_allowed( $parsed['canonical'], $author_id ) ) {
				$parsed['canonical'] = null;
			}
			if ( isset( $parsed['og']['og:url'] ) && '' !== $parsed['og']['og:url'] && ! Uich_SEO_Record::author_url_allowed( $parsed['og']['og:url'], $author_id ) ) {
				unset( $parsed['og']['og:url'] );
			}
			return $parsed;
		}

		/**
		 * Can this post hold its own SEO tags?
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public static function page_writable( $post_id ) {
			// With sections the tags go in page code; without, in post meta (PAGE_META).
			return (bool) get_post( absint( $post_id ) );
		}

		/**
		 * Replace a post's SEO tags with a new parse; the rest of its head code is kept.
		 *
		 * @param int   $post_id Post id.
		 * @param array $parsed  New parse.
		 * @return true|WP_Error
		 */
		public static function write_page( $post_id, array $parsed ) {
			$post_id = absint( $post_id );
			if ( self::is_cb_document( $post_id ) ) {
				return self::write_cb_page( $post_id, $parsed );
			}
			$driver   = self::driver( $post_id );
			$sections = ( $driver && class_exists( 'UiChemy_Section_Ops' ) ) ? $driver->load_sections( $post_id, 'content' ) : array();
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}
			$stored = (string) get_post_meta( $post_id, self::PAGE_META, true );
			if ( empty( $sections ) ) {
				// A block-editor / classic post: no page code, so the tags live in post meta.
				$next = self::compose( $stored, $parsed );
				if ( $next !== $stored ) {
					if ( '' === trim( $next ) ) {
						delete_post_meta( $post_id, self::PAGE_META );
					} else {
						update_post_meta( $post_id, self::PAGE_META, wp_slash( $next ) );
					}
				}
				unset( self::$pages[ $post_id ], self::$trusted[ $post_id ] );
				return true;
			}
			$current = '';
			foreach ( $sections as $s ) {
				if ( '' !== trim( isset( $s['settings']['page_custom_code_head'] ) ? (string) $s['settings']['page_custom_code_head'] : '' ) ) {
					$current = (string) $s['settings']['page_custom_code_head'];
					break;
				}
			}
			// Sections added to a post that had meta-stored tags: carry them into page code.
			$from_meta = '' === trim( $current ) && '' !== trim( $stored );
			if ( $from_meta ) {
				$current = $stored;
			}
			$next = self::compose( $current, $parsed );
			if ( $next === $current && ! $from_meta ) {
				return true;
			}
			delete_post_meta( $post_id, self::PAGE_META );
			UiChemy_Section_Ops::replace_page_code( $sections, $next, null );
			// The builder kses-filters code fields for users without unfiltered_html, which
			// stripped every <meta> we write: an Author's description and social tags were
			// "saved" and silently lost. What is saved here is the stored sections (already
			// filtered when they were saved) plus tags built by compose() from validated,
			// escaped values — no markup from this user — so lift the filter for this save
			// only. It also stops this save re-filtering an administrator's page code.
			$lift  = static function ( $caps, $cap ) {
				return 'unfiltered_html' === $cap ? array( 'exist' ) : $caps;
			};
			add_filter( 'map_meta_cap', $lift, PHP_INT_MAX, 2 );
			try {
				$saved = $driver->save_sections( $post_id, $sections, 'content' );
			} finally {
				remove_filter( 'map_meta_cap', $lift, PHP_INT_MAX );
			}
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			if ( method_exists( $driver, 'after_write' ) ) {
				$driver->after_write( $post_id );
			}
			if ( '' !== $stored ) {
				delete_post_meta( $post_id, self::PAGE_META );
			}
			unset( self::$pages[ $post_id ], self::$trusted[ $post_id ] );
			return true;
		}

		/**
		 * Is the post a Composer Builder document (its head code lives in the
		 * document, not in sections or post meta)?
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public static function is_cb_document( $post_id ) {
			return class_exists( 'Uich_CB_Content' ) && Uich_CB_Content::is_enabled( absint( $post_id ) );
		}

		/**
		 * write_page() for a Composer Builder document: the tags go into the
		 * document's head code, which is what page_raw() reads back. Tags stored
		 * in post meta before the page became a document are carried across.
		 *
		 * @param int   $post_id Post id.
		 * @param array $parsed  New parse.
		 * @return true|WP_Error
		 */
		private static function write_cb_page( $post_id, array $parsed ) {
			$document = Uich_CB_Content::get_cb_content( $post_id );
			$current  = isset( $document['content']['before_head_end_html'] ) ? (string) $document['content']['before_head_end_html'] : '';
			$stored   = (string) get_post_meta( $post_id, self::PAGE_META, true );
			if ( '' !== trim( $stored ) ) {
				$current = '' === trim( $current ) ? $stored : $current . "\n" . $stored;
			}
			$next = self::compose( $current, $parsed );
			if ( $next !== $current || '' !== $stored ) {
				$document['content']['before_head_end_html'] = $next;
				// Same reasoning as the section path below: compose() only adds
				// escaped tags, so the save must not kses away an Author's <meta>.
				$lift = static function ( $caps, $cap ) {
					return 'unfiltered_html' === $cap ? array( 'exist' ) : $caps;
				};
				add_filter( 'map_meta_cap', $lift, PHP_INT_MAX, 2 );
				try {
					$saved = Uich_CB_Content::save_cb_content( $post_id, $document );
				} finally {
					remove_filter( 'map_meta_cap', $lift, PHP_INT_MAX );
				}
				if ( ! $saved || is_wp_error( $saved ) ) {
					return is_wp_error( $saved ) ? $saved : new WP_Error( 'uich_seo_save_failed', 'The page head code could not be saved.' );
				}
				delete_post_meta( $post_id, self::PAGE_META );
			}
			unset( self::$pages[ $post_id ], self::$trusted[ $post_id ] );
			return true;
		}

		// ------------------------------------------------------------------
		// Site head code
		// ------------------------------------------------------------------

		/**
		 * Raw site head code.
		 *
		 * @return string
		 */
		public static function site_raw() {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return '';
			}
			$code = UiChemy_Composer_Manager::get_site_custom_code_option();
			return (string) $code['head'];
		}

		/**
		 * Parsed site head (memoised per request and in the object cache).
		 *
		 * @return array
		 */
		public static function site() {
			if ( null !== self::$site ) {
				return self::$site;
			}
			$raw = self::site_raw();
			$key = 'site_' . md5( $raw );
			$hit = wp_cache_get( $key, 'uich_seo_head' );
			if ( is_array( $hit ) ) {
				self::$site = $hit;
				return $hit;
			}
			self::$site = self::parse( $raw );
			wp_cache_set( $key, self::$site, 'uich_seo_head', HOUR_IN_SECONDS );
			return self::$site;
		}

		/**
		 * Forget request memos (tests, after writes).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$site    = null;
			self::$pages   = array();
			self::$trusted = array();
		}

		/**
		 * Replace the site's SEO tags with a new parse; other site head code is kept.
		 *
		 * The tags are built here from validated values (never user markup), so they
		 * are stored directly rather than through the site-code sanitiser, which would
		 * strip <meta>/<script> for an administrator without `unfiltered_html`.
		 *
		 * @param array $parsed New parse.
		 * @return true
		 */
		public static function write_site( array $parsed ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return true;
			}
			$code = UiChemy_Composer_Manager::get_site_custom_code_option();
			$next = self::compose( $code['head'], $parsed );
			if ( $next !== $code['head'] ) {
				update_option(
					UiChemy_Composer_Manager::SITE_CUSTOM_CODE_OPTION,
					array(
						'head'   => $next,
						'footer' => $code['footer'],
					),
					false
				);
			}
			self::flush();
			return true;
		}

		/**
		 * Site SEO tags as settings values (the keys in SITE_KEYS).
		 *
		 * @return array
		 */
		public static function site_settings() {
			$key = 'settings_' . md5( self::site_raw() );
			$hit = wp_cache_get( $key, 'uich_seo_head' );
			if ( is_array( $hit ) ) {
				return $hit;
			}
			$out = self::site_settings_uncached();
			wp_cache_set( $key, $out, 'uich_seo_head', HOUR_IN_SECONDS );
			return $out;
		}

		/**
		 * site_settings() without the cache.
		 *
		 * @return array
		 */
		private static function site_settings_uncached() {
			$p   = self::site();
			$og  = $p['og'];
			$tw  = $p['twitter'];
			$out = array(
				// '' = no site <title> yet: the resolver then uses the default template,
				// and the dashboard shows that default as a placeholder, not a saved value.
				'title_template'      => null !== $p['title'] ? (string) $p['title'] : '',
				'home_description'    => (string) $p['description'],
				// Sharing-only fallbacks: the site head's og:title (a template: {{ title }}
				// is each page's own title) and og:description.
				'default_social_title'       => isset( $og['og:title'] ) ? (string) $og['og:title'] : ( isset( $tw['twitter:title'] ) ? (string) $tw['twitter:title'] : '' ),
				'default_social_description' => isset( $og['og:description'] ) ? (string) $og['og:description'] : ( isset( $tw['twitter:description'] ) ? (string) $tw['twitter:description'] : '' ),
				'default_share_image' => (int) $p['image_id'],
				'default_share_url'   => isset( $og['og:image'] ) ? (string) $og['og:image'] : '',
				'site_name_override'  => isset( $og['og:site_name'] ) ? (string) $og['og:site_name'] : '',
				'og_locale'           => isset( $og['og:locale'] ) ? (string) $og['og:locale'] : '',
				'twitter_handle'      => isset( $tw['twitter:site'] ) ? (string) $tw['twitter:site'] : '',
				'twitter_card'        => ( isset( $tw['twitter:card'] ) && 'summary' === $tw['twitter:card'] ) ? 'small' : 'large',
			);
			foreach ( self::VERIFY as $key ) {
				$out[ $key ] = isset( $p['verify'][ $key ] ) ? (string) $p['verify'][ $key ] : '';
			}
			$e                         = self::site_entity( $p );
			$out['entity_type']        = $e && 'Person' === self::type_of( $e ) ? 'person' : 'organization';
			$out['entity_name']        = $e && isset( $e['name'] ) ? (string) $e['name'] : '';
			$out['entity_description'] = $e && isset( $e['description'] ) ? (string) $e['description'] : '';
			$out['entity_email']       = $e && isset( $e['email'] ) ? (string) $e['email'] : '';
			$out['entity_phone']       = $e && isset( $e['telephone'] ) ? (string) $e['telephone'] : '';
			$out['social_profiles']    = $e && isset( $e['sameAs'] ) ? array_values( (array) $e['sameAs'] ) : array();
			$logo                      = $e ? self::logo_of( $e ) : '';
			$out['entity_logo']        = $logo ? (int) attachment_url_to_postid( $logo ) : 0;
			$out['entity_logo_url']    = $logo;
			return $out;
		}

		/**
		 * The site entity (Organization / Person) in site JSON-LD, or null.
		 *
		 * @param array|null $p Parsed (default: the site head).
		 * @return array|null
		 */
		public static function site_entity( $p = null ) {
			$p = null === $p ? self::site() : $p;
			foreach ( (array) $p['ldjson'] as $e ) {
				if ( in_array( self::type_of( $e ), array( 'Organization', 'Person', 'LocalBusiness', 'Corporation', 'NGO', 'EducationalOrganization' ), true ) ) {
					return $e;
				}
			}
			return null;
		}

		/**
		 * Site JSON-LD entities other than the site entity (e.g. a WebSite SearchAction).
		 *
		 * @return array[]
		 */
		public static function site_extra_entities() {
			$entity = self::site_entity();
			$out    = array();
			foreach ( (array) self::site()['ldjson'] as $e ) {
				if ( $e !== $entity ) {
					$out[] = $e;
				}
			}
			return $out;
		}

		/**
		 * First @type of an entity.
		 *
		 * @param array $e Entity.
		 * @return string
		 */
		public static function type_of( array $e ) {
			$t = isset( $e['@type'] ) ? $e['@type'] : '';
			return is_array( $t ) ? (string) reset( $t ) : (string) $t;
		}

		/**
		 * Logo URL of an entity ('' for none).
		 *
		 * @param array $e Entity.
		 * @return string
		 */
		private static function logo_of( array $e ) {
			$l = isset( $e['logo'] ) ? $e['logo'] : ( isset( $e['image'] ) ? $e['image'] : '' );
			if ( is_array( $l ) ) {
				$l = isset( $l['url'] ) ? $l['url'] : ( isset( $l['contentUrl'] ) ? $l['contentUrl'] : '' );
			}
			return is_string( $l ) ? $l : '';
		}

		/**
		 * Apply a (sanitised) site-settings patch to the site head.
		 *
		 * @param array $patch SITE_KEYS subset.
		 * @return true
		 */
		public static function update_site( array $patch ) {
			$p = self::site();
			foreach ( $patch as $k => $v ) {
				$clear = null === $v || '' === $v || array() === $v || ( 'default_share_image' === $k && ! $v );
				switch ( $k ) {
					case 'title_template':
						$p['title'] = $clear ? null : (string) $v;
						break;
					case 'home_description':
						$p['description'] = $clear ? null : (string) $v;
						break;
					case 'default_social_title':
						$p = self::apply_fields( $p, array( 'social_title' => $clear ? null : (string) $v ) );
						break;
					case 'default_social_description':
						$p = self::apply_fields( $p, array( 'social_description' => $clear ? null : (string) $v ) );
						break;
					case 'default_share_image':
						$p = self::apply_fields( $p, array( 'share_image' => $clear ? null : self::image_value( $v ) ) );
						break;
					case 'site_name_override':
						unset( $p['og']['og:site_name'] );
						if ( ! $clear ) {
							$p['og']['og:site_name'] = (string) $v;
						}
						break;
					case 'og_locale':
						$p = self::apply_fields( $p, array( 'og_locale' => $clear ? null : (string) $v ) );
						break;
					case 'twitter_handle':
						unset( $p['twitter']['twitter:site'] );
						if ( ! $clear ) {
							$p['twitter']['twitter:site'] = (string) $v;
						}
						break;
					case 'twitter_card':
						$p['twitter']['twitter:card'] = 'small' === $v ? 'summary' : 'summary_large_image';
						break;
					default:
						if ( in_array( $k, self::VERIFY, true ) ) {
							unset( $p['verify'][ $k ] );
							if ( ! $clear ) {
								$p['verify'][ $k ] = (string) $v;
							}
						}
				}
			}
			$entity_keys = array_intersect_key( $patch, array_flip( array( 'entity_type', 'entity_name', 'entity_logo', 'entity_description', 'entity_email', 'entity_phone', 'social_profiles' ) ) );
			if ( $entity_keys ) {
				$p = self::with_entity( $p, $entity_keys );
			}
			return self::write_site( $p );
		}

		/**
		 * Share-image value from an attachment id or URL.
		 *
		 * @param int|string $v Id or URL.
		 * @return array|null
		 */
		private static function image_value( $v ) {
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$src = wp_get_attachment_image_src( (int) $v, 'full' );
				return $src ? array( 'id' => (int) $v, 'url' => $src[0] ) : null;
			}
			$url = esc_url_raw( (string) $v, array( 'http', 'https' ) );
			return '' === $url ? null : array( 'id' => 0, 'url' => $url );
		}

		/**
		 * Merge entity settings into the site Organization / Person JSON-LD,
		 * keeping every property the settings do not manage.
		 *
		 * @param array $p     Parsed site head.
		 * @param array $patch Entity keys.
		 * @return array
		 */
		private static function with_entity( array $p, array $patch ) {
			$current = self::site_entity( $p );
			$e       = $current ? $current : array( '@type' => 'Organization' );
			if ( isset( $patch['entity_type'] ) ) {
				$e['@type'] = 'person' === $patch['entity_type'] ? 'Person' : ( 'Person' === self::type_of( $e ) ? 'Organization' : $e['@type'] );
			}
			$map = array(
				'entity_name'        => 'name',
				'entity_description' => 'description',
				'entity_email'       => 'email',
				'entity_phone'       => 'telephone',
				'social_profiles'    => 'sameAs',
			);
			foreach ( $map as $key => $prop ) {
				if ( array_key_exists( $key, $patch ) ) {
					if ( '' === $patch[ $key ] || array() === $patch[ $key ] || null === $patch[ $key ] ) {
						unset( $e[ $prop ] );
					} else {
						$e[ $prop ] = $patch[ $key ];
					}
				}
			}
			if ( array_key_exists( 'entity_logo', $patch ) ) {
				$img = $patch['entity_logo'] ? self::image_value( $patch['entity_logo'] ) : null;
				$key = 'Person' === self::type_of( $e ) ? 'image' : 'logo';
				unset( $e['logo'], $e['image'] );
				if ( $img ) {
					$e[ $key ] = $img['url'];
				}
			}
			// A cleared Name stays empty (the entity then follows the site title when printed),
			// not frozen to today's site title.
			if ( isset( $e['name'] ) && '' === trim( (string) $e['name'] ) ) {
				unset( $e['name'] );
			}
			$e['url'] = isset( $e['url'] ) ? $e['url'] : home_url( '/' );

			// Nothing but the defaults (an Organization named after the site, at the
			// home URL): the tag says nothing the automatic one does not, so the site
			// head code does not carry it.
			$is_default = 'Organization' === $e['@type']
				&& array() === array_diff( array_keys( $e ), array( '@type', '@context', 'name', 'url' ) )
				&& ( ! isset( $e['name'] ) || wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) === $e['name'] )
				&& untrailingslashit( (string) $e['url'] ) === untrailingslashit( home_url( '/' ) );

			$list = array();
			$done = false;
			foreach ( (array) $p['ldjson'] as $x ) {
				if ( ! $done && $current && $x === $current ) {
					if ( ! $is_default ) {
						$list[] = $e;
					}
					$done = true;
					continue;
				}
				$list[] = $x;
			}
			if ( ! $done && ! $is_default ) {
				array_unshift( $list, $e );
			}
			$p['ldjson'] = $list;
			return $p;
		}
	}
}
