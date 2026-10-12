<?php
/**
 * Inherit SEO from imported HTML (D10, 02 §7).
 *
 * A page built in Lovable, v0, Bolt or captured from a live site arrives with a
 * full document. Its title, description, social text and JSON-LD are kept with
 * `imported` provenance. The safety rail is mandatory: the source site's
 * canonical, og:url and every absolute URL on the source domain are dropped or
 * rewritten, and each one is reported — never silently.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Import' ) ) {

	/**
	 * HTML import.
	 */
	class Uich_SEO_Import {

		/**
		 * Read SEO from a full HTML document.
		 *
		 * @param string $full_html   Document.
		 * @param string $source_url  Where it came from (optional; detected otherwise).
		 * @param array  $opts        { image_imported: bool } — keep og:image only when
		 *                            the caller imported it into the media library.
		 * @return array{fields:array,dropped:array,lang:string,source_host:string}
		 */
		public static function from_html( $full_html, $source_url = '', array $opts = array() ) {
			$doc     = Uich_SEO_Html::document( (string) $full_html );
			$xp      = new DOMXPath( $doc );
			$fields  = array();
			$dropped = array();
			$meta    = function ( $attr, $name ) use ( $xp ) {
				$n = $xp->query( '//meta[@' . $attr . '="' . $name . '"]/@content' )->item( 0 );
				return $n ? Uich_SEO_Html::squish( $n->nodeValue ) : '';
			};

			$canonical = '';
			$cn        = $xp->query( '//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href' )->item( 0 );
			if ( $cn ) {
				$canonical = trim( $cn->nodeValue );
			}
			$og_url = $meta( 'property', 'og:url' );

			$source_host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
			if ( '' === $source_host ) {
				$source_host = strtolower( (string) wp_parse_url( $canonical ? $canonical : $og_url, PHP_URL_HOST ) );
			}
			$own_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

			// ---- Kept ----
			$title_node = $xp->query( '//head/title' )->item( 0 );
			$title      = $title_node ? Uich_SEO_Html::squish( $title_node->textContent ) : '';
			if ( '' !== $title ) {
				$fields['title'] = $title;
			}
			$desc = $meta( 'name', 'description' );
			if ( '' !== $desc ) {
				$fields['description'] = $desc;
			}
			$og_title = $meta( 'property', 'og:title' );
			$og_desc  = $meta( 'property', 'og:description' );
			if ( '' === $title && '' !== $og_title ) {
				$fields['title'] = $og_title;
			} elseif ( '' !== $og_title && $og_title !== $title ) {
				$fields['social_title'] = $og_title;
			}
			if ( '' === $desc && '' !== $og_desc ) {
				$fields['description'] = $og_desc;
			} elseif ( '' !== $og_desc && $og_desc !== $desc ) {
				$fields['social_description'] = $og_desc;
			}

			$og_image = $meta( 'property', 'og:image' );
			if ( '' !== $og_image ) {
				if ( ! empty( $opts['image_imported'] ) && ! empty( $opts['image_url'] ) ) {
					$fields['share_image'] = array( 'url' => (string) $opts['image_url'], 'id' => isset( $opts['image_id'] ) ? (int) $opts['image_id'] : 0 );
				} else {
					$dropped[] = array(
						'what'  => 'og:image',
						'value' => $og_image,
						'why'   => __( 'The sharing image still points to the original website. Upload the image to your media library and set it as the sharing image.', 'uichemy' ),
					);
				}
			}

			$robots = strtolower( $meta( 'name', 'robots' ) );
			if ( false !== strpos( $robots, 'noindex' ) ) {
				$dropped[] = array(
					'what'  => 'robots',
					'value' => $robots,
					'why'   => __( 'The original page was hidden from search. That was a setting of the old website, so it was not copied. Hide this page in the SEO panel if you want the same.', 'uichemy' ),
				);
			}

			// ---- Dropped, always ----
			if ( '' !== $canonical ) {
				$dropped[] = array(
					'what'  => 'canonical',
					'value' => $canonical,
					'why'   => __( 'The preferred address pointed to the original website. Keeping it would tell search engines your page belongs to that site, so it was removed.', 'uichemy' ),
				);
			}
			if ( '' !== $og_url ) {
				$dropped[] = array(
					'what'  => 'og:url',
					'value' => $og_url,
					'why'   => __( 'The sharing address pointed to the original website. This page\'s own address is used instead.', 'uichemy' ),
				);
			}

			// ---- JSON-LD: keep, rewrite source-domain URLs, drop foreign @ids ----
			$entities = array();
			foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
				$data = json_decode( trim( $s->textContent ), true );
				if ( ! is_array( $data ) ) {
					$dropped[] = array(
						'what'  => 'json-ld',
						'value' => Uich_SEO_Deriver::clip( trim( $s->textContent ), 80 ),
						'why'   => __( 'This block of page details was not valid JSON, so it was left out.', 'uichemy' ),
					);
					continue;
				}
				$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
				foreach ( $list as $e ) {
					if ( ! is_array( $e ) || empty( $e['@type'] ) ) {
						continue;
					}
					// Site-level entities belong to the new site's own settings.
					if ( in_array( $e['@type'], array( 'WebSite', 'Organization', 'WebPage', 'BreadcrumbList' ), true ) ) {
						$dropped[] = array(
							'what'  => 'json-ld',
							'value' => is_string( $e['@type'] ) ? $e['@type'] : 'entity',
							'why'   => __( 'This describes the original website. This is built from your own site settings instead.', 'uichemy' ),
						);
						continue;
					}
					unset( $e['@context'] );
					$entities[] = self::rewrite_urls( $e, $source_host, $own_host, $dropped );
				}
			}
			if ( $entities ) {
				$fields['schema_extra'] = $entities;
			}

			// ---- Body links / images on the source domain (reported, the HTML import rewrites them) ----
			if ( '' !== $source_host && $source_host !== $own_host ) {
				$n = 0;
				foreach ( $xp->query( '//body//a[@href] | //body//img[@src]' ) as $el ) {
					$u = $el->hasAttribute( 'href' ) ? $el->getAttribute( 'href' ) : $el->getAttribute( 'src' );
					if ( strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) === $source_host ) {
						++$n;
					}
				}
				if ( $n ) {
					$dropped[] = array(
						'what'  => 'links',
						'value' => (string) $n,
						/* translators: %s: domain. */
						'why'   => sprintf( __( 'The page has %1$d links or images that still point to %2$s. Change them to your own pages and media library.', 'uichemy' ), $n, $source_host ),
					);
				}
			}

			$lang = (string) $doc->documentElement->getAttribute( 'lang' );
			return array(
				'fields'      => $fields,
				'dropped'     => $dropped,
				'lang'        => $lang,
				'source_host' => $source_host,
			);
		}

		/**
		 * Rewrite absolute URLs on the source domain to this site; drop foreign @id.
		 *
		 * @param mixed  $v       Value.
		 * @param string $from    Source host.
		 * @param string $to      Own host.
		 * @param array  $dropped Report (by reference).
		 * @param string $key     Current key.
		 * @return mixed
		 */
		private static function rewrite_urls( $v, $from, $to, array &$dropped, $key = '' ) {
			if ( is_array( $v ) ) {
				// Drop a foreign @id first: rewriting it would point it at this site's home page.
				if ( isset( $v['@id'] ) && is_string( $v['@id'] ) && '' !== $from && false !== stripos( $v['@id'], $from ) ) {
					unset( $v['@id'] ); // Re-minted by the schema builder against this page.
				}
				foreach ( $v as $k => $item ) {
					$clean = self::rewrite_urls( $item, $from, $to, $dropped, (string) $k );
					if ( null === $clean ) {
						unset( $v[ $k ] );
					} else {
						$v[ $k ] = $clean;
					}
				}
				return $v;
			}
			if ( is_string( $v ) && self::unsafe_scheme( $v ) ) {
				$dropped[] = array(
					'what'  => 'json-ld value',
					'value' => Uich_SEO_Deriver::clip( $v, 80 ),
					'why'   => __( 'Only web addresses starting with http or https are copied, so this value was left out.', 'uichemy' ),
				);
				return null;
			}
			if ( is_string( $v ) && '' !== $from && preg_match( '#^https?://' . preg_quote( $from, '#' ) . '(?=[/:?\#]|$)#i', $v ) ) {
				$path = (string) wp_parse_url( $v, PHP_URL_PATH ) . ( wp_parse_url( $v, PHP_URL_QUERY ) ? '?' . wp_parse_url( $v, PHP_URL_QUERY ) : '' );
				if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i', $path ) ) {
					$dropped[] = array(
						'what'  => 'json-ld image',
						'value' => $v,
						'why'   => __( 'An image in the page details still points to the original website. Upload it and update the page details.', 'uichemy' ),
					);
					return $v;
				}
				return home_url( '' === $path ? '/' : $path );
			}
			return $v;
		}

		/**
		 * Does a value start with a script-like scheme (javascript:, data:, vbscript:)?
		 *
		 * @param string $v Value.
		 * @return bool
		 */
		private static function unsafe_scheme( $v ) {
			return (bool) preg_match( '/^s*(?:javascript|vbscript|data)s*:/i', (string) $v );
		}

		/**
		 * Remove another SEO plugin's variables (%%title%%, %title%) from a text field.
		 *
		 * @param string $v Value.
		 * @return string
		 */
		private static function strip_foreign_vars( $v ) {
			$v = preg_replace( '/%%[a-z_]{2,40}%%|%[a-z_]{3,40}%/i', '', $v );
			return trim( preg_replace( '/s{2,}/', ' ', (string) $v ) );
		}

		/**
		 * Import and apply to a post: values are stored with `imported` state.
		 *
		 * @param int    $post_id    Post id.
		 * @param string $full_html  Document.
		 * @param string $source_url Source.
		 * @param array  $opts       Options (see from_html), plus overwrite: bool to replace values the page already has.
		 * @return array|WP_Error Summary.
		 */
		public static function apply( $post_id, $full_html, $source_url = '', array $opts = array() ) {
			$r = self::from_html( $full_html, $source_url, $opts );
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'uich_seo_no_post', __( 'That page does not exist.', 'uichemy' ) );
			}
			$kept = array();
			$text = array( 'title', 'description', 'social_title', 'social_description' );
			foreach ( $text as $key ) {
				if ( ! isset( $r['fields'][ $key ] ) ) {
					continue;
				}
				$val = (string) $r['fields'][ $key ];
				if ( self::unsafe_scheme( $val ) ) {
					$val = '';
				} else {
					$val = self::strip_foreign_vars( $val );
				}
				if ( $val !== $r['fields'][ $key ] ) {
					$r['dropped'][] = array(
						'what'  => $key,
						'value' => Uich_SEO_Deriver::clip( (string) $r['fields'][ $key ], 80 ),
						'why'   => '' === $val ? __( 'This text only held variables from another SEO plugin or a script address, so it was left out.', 'uichemy' ) : __( 'Variables from another SEO plugin were removed from this text.', 'uichemy' ),
					);
				}
				if ( '' === $val ) {
					unset( $r['fields'][ $key ] );
				} else {
					$r['fields'][ $key ] = $val;
				}
			}
			// Keep what the editor already wrote unless the caller asks to overwrite.
			if ( empty( $opts['overwrite'] ) && $r['fields'] ) {
				$existing = Uich_SEO_Record::get( $post_id );
				foreach ( array_keys( $r['fields'] ) as $key ) {
					if ( isset( $existing['fields'][ $key ] ) && '' !== $existing['fields'][ $key ] && array() !== $existing['fields'][ $key ] ) {
						$r['dropped'][] = array(
							'what'  => $key,
							'value' => is_string( $r['fields'][ $key ] ) ? Uich_SEO_Deriver::clip( $r['fields'][ $key ], 80 ) : $key,
							'why'   => __( 'This page already has its own value, so the imported one was not used.', 'uichemy' ),
						);
						unset( $r['fields'][ $key ] );
					}
				}
			}
			if ( $r['fields'] ) {
				$saved = Uich_SEO_Record::set_fields( $post_id, $r['fields'], 'imported', 'imported' );
				// One refused field must not cost the rest: report it and save the others.
				for ( $try = 0; is_wp_error( $saved ) && $try < 6; $try++ ) {
					$bad = array_values( array_intersect( $saved->get_error_codes(), array_keys( $r['fields'] ) ) );
					if ( ! $bad || ( 1 === count( $bad ) && 'schema_extra' === $bad[0] ) ) {
						break;
					}
					foreach ( $bad as $key ) {
						$r['dropped'][] = array(
							'what'  => $key,
							'value' => is_string( $r['fields'][ $key ] ) ? Uich_SEO_Deriver::clip( $r['fields'][ $key ], 80 ) : $key,
							'why'   => $saved->get_error_message( $key ),
						);
						unset( $r['fields'][ $key ] );
					}
					$saved = $r['fields'] ? Uich_SEO_Record::set_fields( $post_id, $r['fields'], 'imported', 'imported' ) : array();
				}
				if ( is_wp_error( $saved ) ) {
					// Structured data needs unfiltered_html; keep the rest rather than failing.
					if ( isset( $r['fields']['schema_extra'] ) ) {
						$r['dropped'][] = array(
							'what'  => 'json-ld',
							'value' => count( $r['fields']['schema_extra'] ) . ' entities',
							'why'   => __( 'Only administrators can add page details for Google by hand, so these were not copied.', 'uichemy' ),
						);
						unset( $r['fields']['schema_extra'] );
						$saved = Uich_SEO_Record::set_fields( $post_id, $r['fields'], 'imported', 'imported' );
					}
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
				}
				$kept = array_keys( $r['fields'] );
				Uich_SEO_Record::rebuild( $post_id );
			}
			return array(
				'post_id'     => (int) $post_id,
				'kept'        => $kept,
				'dropped'     => $r['dropped'],
				'lang'        => $r['lang'],
				'source_host' => $r['source_host'],
			);
		}
	}
}
