<?php
/**
 * The markup audit (PRD §18). Every message is PRD copy, verbatim.
 *
 * Page checks run on save, from the derived record (no extra parse), and are
 * stored with the record. Site-wide checks (duplicates, sitemap, links that 404,
 * file sizes) need the whole site or I/O and run only in the batched pass.
 *
 * Each finding: { check_id, check, severity, message, fix_target, post_id, node_path, section }.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Audit' ) ) {

	/**
	 * Audit checks.
	 */
	class Uich_SEO_Audit {

		/**
		 * Check numbers kept so the PRD numbering stays stable, but never reported:
		 * #9 (WordPress always declares the page language) and #44 (pages kept away
		 * from AI tools are filtered out of llms.txt automatically).
		 */
		const RESERVED = array( 9, 44 );

		/** Generic anchor text (PRD §18.3 #20). */
		const GENERIC_ANCHORS = array( 'click here', 'here', 'read more', 'more', 'learn more', 'this', 'link', 'this link', 'details' );

		/**
		 * Catalogue: id => [ slug, severity, fix_target, scope ].
		 *
		 * @return array
		 */
		public static function catalogue() {
			return array(
				1  => array( 'no_h1', 'warning', 'heading', 'page' ),
				2  => array( 'multiple_h1', 'notice', 'heading', 'page' ),
				3  => array( 'empty_h1', 'warning', 'heading', 'page' ),
				4  => array( 'first_heading_not_h1', 'notice', 'heading', 'page' ),
				5  => array( 'heading_skip', 'notice', 'heading', 'page' ),
				6  => array( 'pseudo_heading', 'notice', 'heading', 'page' ),
				7  => array( 'no_main', 'notice', 'code', 'page' ),
				8  => array( 'thin_content', 'warning', 'code', 'page' ),
				9  => array( 'no_lang', 'notice', 'settings', 'site' ),
				10 => array( 'alt_missing', 'warning', 'image', 'page' ),
				11 => array( 'alt_empty', 'notice', 'image', 'page' ),
				12 => array( 'alt_filename', 'warning', 'image', 'page' ),
				13 => array( 'alt_long', 'notice', 'image', 'page' ),
				14 => array( 'img_no_dimensions', 'warning', 'attributes', 'page' ),
				15 => array( 'img_no_loading', 'notice', 'attributes', 'page' ),
				16 => array( 'img_large_file', 'warning', 'image', 'site' ),
				17 => array( 'img_external', 'notice', 'image', 'page' ),
				18 => array( 'blank_no_noopener', 'warning', 'link', 'page' ),
				19 => array( 'link_no_text', 'warning', 'attributes', 'page' ),
				20 => array( 'link_generic_text', 'notice', 'link', 'page' ),
				21 => array( 'link_broken', 'warning', 'link', 'site' ),
				22 => array( 'no_internal_links', 'notice', 'code', 'page' ),
				23 => array( 'internal_nofollow', 'notice', 'link', 'page' ),
				24 => array( 'link_redirects', 'notice', 'link', 'site' ),
				25 => array( 'iframe_no_title', 'warning', 'attributes', 'page' ),
				26 => array( 'time_no_datetime', 'notice', 'attributes', 'page' ),
				27 => array( 'table_no_header', 'notice', 'code', 'page' ),
				28 => array( 'nav_unlabelled', 'notice', 'attributes', 'page' ),
				29 => array( 'faq_not_published', 'notice', 'code', 'page' ),
				30 => array( 'breadcrumb_not_published', 'notice', 'panel', 'page' ),
				31 => array( 'product_no_price', 'warning', 'panel', 'page' ),
				32 => array( 'schema_invalid', 'warning', 'panel', 'page' ),
				33 => array( 'no_title', 'warning', 'panel', 'page' ),
				34 => array( 'no_description', 'warning', 'panel', 'page' ),
				35 => array( 'title_long', 'notice', 'panel', 'page' ),
				36 => array( 'title_short', 'notice', 'panel', 'page' ),
				37 => array( 'description_length', 'notice', 'panel', 'page' ),
				38 => array( 'title_duplicate', 'warning', 'panel', 'site' ),
				39 => array( 'description_duplicate', 'warning', 'panel', 'site' ),
				40 => array( 'canonical_foreign', 'blocker', 'panel', 'page' ),
				41 => array( 'noindex_in_sitemap', 'blocker', 'settings', 'site' ),
				42 => array( 'share_image_small', 'notice', 'panel', 'page' ),
				44 => array( 'ai_excluded_in_llms', 'notice', 'settings', 'site' ),
				45 => array( 'head_json_invalid', 'warning', 'code', 'page' ),
				46 => array( 'site_hidden', 'blocker', 'settings', 'site' ),
				47 => array( 'meta_automatic', 'notice', 'panel', 'page' ),
				48 => array( 'share_image_missing', 'notice', 'panel', 'page' ),
			);
		}

		/**
		 * Hook in: findings are attached as the record is rebuilt, and SEO checks
		 * join the existing `audit` ability through its own filters.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_seo_record', array( __CLASS__, 'attach_findings' ), 10, 2 );
			add_filter( 'uichemy_audit_findings', array( __CLASS__, 'into_composer_audit' ), 10, 2 );
			add_filter( 'uichemy_audit_checks', array( __CLASS__, 'into_composer_checks' ) );
		}

		/**
		 * `uichemy_seo_record` — compute on-save findings.
		 *
		 * @param array   $record Record.
		 * @param WP_Post $post   Post.
		 * @return array
		 */
		public static function attach_findings( $record, $post ) {
			if ( 'pattern' === $record['kind'] ) {
				$record['findings'] = array();
				return $record;
			}
			// Resolve against the record being saved (not yet in the database).
			$record['findings'] = self::page( $post->ID, $record );
			return $record;
		}

		/**
		 * Page checks.
		 *
		 * @param int        $post_id Post id.
		 * @param array|null $record  Record (defaults to stored).
		 * @return array Findings.
		 */
		public static function page( $post_id, $record = null ) {
			$record = is_array( $record ) ? Uich_SEO_Record::normalize( $record ) : Uich_SEO_Record::get( $post_id );
			$d      = $record['derived'];
			$f      = array();
			$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			$headings = isset( $d['headings'] ) ? (array) $d['headings'] : array();
			$h1s      = array_values( array_filter( $headings, function ( $h ) {
				return 1 === (int) $h['level'];
			} ) );

			// ---- 18.1 Document and headings ----
			if ( empty( $h1s ) && ! self::h1_outside_content( $post_id, $d ) ) {
				$f[] = self::make( 1, __( 'This page has no main heading (H1).', 'uichemy' ), $post_id );
			} else {
				if ( count( $h1s ) > 1 ) {
					/* translators: %d: count. */
					$f[] = self::make( 2, sprintf( __( 'This page has %d main headings (H1). Usually a page should have just one.', 'uichemy' ), count( $h1s ) ), $post_id, $h1s[1] );
				}
				foreach ( $h1s as $h ) {
					if ( '' === trim( (string) $h['text'] ) ) {
						$f[] = self::make( 3, __( 'The main heading is empty.', 'uichemy' ), $post_id, $h );
						break;
					}
				}
			}
			if ( ! empty( $headings ) && 1 !== (int) $headings[0]['level'] ) {
				/* translators: %s: tag. */
				$f[] = self::make( 4, sprintf( __( 'This page starts with an %s heading instead of a main heading (H1).', 'uichemy' ), 'H' . (int) $headings[0]['level'] ), $post_id, $headings[0] );
			}
			$prev = 0;
			foreach ( $headings as $h ) {
				$lvl = (int) $h['level'];
				if ( $prev && $lvl > $prev + 1 ) {
					/* translators: 1: from tag, 2: to tag. */
					$f[] = self::make( 5, sprintf( __( 'The headings jump from %1$s to %2$s. Search engines and screen readers expect headings in order, without skipping a size.', 'uichemy' ), 'h' . $prev, 'h' . $lvl ), $post_id, $h );
				}
				$prev = $lvl;
			}
			foreach ( isset( $d['pseudo_headings'] ) ? (array) $d['pseudo_headings'] : array() as $p ) {
				/* translators: %s: tag. */
				$f[] = self::make( 6, sprintf( __( 'This text looks like a heading but is not set as one (it is a %s). Change it to a heading so search engines see it as one.', 'uichemy' ), $p['tag'] ), $post_id, $p );
			}
			if ( isset( $d['signals']['has_main'] ) && ! $d['signals']['has_main'] && ! self::theme_has_main() ) {
				$f[] = self::make( 7, __( 'This page does not mark where its main content is (a <main> area).', 'uichemy' ), $post_id );
			}
			$words = isset( $d['word_count'] ) ? (int) $d['word_count'] : 0;
			if ( $words < 150 ) {
				/* translators: %d: words. */
				$f[] = self::make( 8, sprintf( __( 'This page has only %d words. Pages with very little text rarely show up well in search.', 'uichemy' ), $words ), $post_id );
			}

			// ---- 18.2 Images ----
			$below_fold = false;
			foreach ( isset( $d['images'] ) ? (array) $d['images'] : array() as $i => $img ) {
				if ( null === $img['alt'] ) {
					$f[] = self::make( 10, __( 'This image has no description for people who cannot see it (alt text).', 'uichemy' ), $post_id, $img );
				} elseif ( '' === $img['alt'] ) {
					if ( empty( $img['decorative'] ) ) {
						$f[] = self::make( 11, __( 'This image has an empty description. That is fine for purely decorative images; otherwise describe what it shows.', 'uichemy' ), $post_id, $img );
					}
				} else {
					if ( preg_match( '/^(img|image|dsc|photo|screenshot|untitled)[\s_-]*\d*$/i', $img['alt'] ) || preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $img['alt'] ) ) {
						/* translators: %s: alt. */
						$f[] = self::make( 12, sprintf( __( 'The image description is just a file name: "%s". Describe what the image shows instead.', 'uichemy' ), $img['alt'] ), $post_id, $img );
					}
					$len = function_exists( 'mb_strlen' ) ? mb_strlen( $img['alt'] ) : strlen( $img['alt'] );
					if ( $len > 125 ) {
						/* translators: %d: characters. */
						$f[] = self::make( 13, sprintf( __( 'The image description is %d characters long. Try to keep it under about 125.', 'uichemy' ), $len ), $post_id, $img );
					}
				}
				if ( ( null === $img['w'] || null === $img['h'] ) && empty( $img['wp_image'] ) ) {
					$f[] = self::make( 14, __( 'This image has no width and height set, so the page jumps around while it loads.', 'uichemy' ), $post_id, $img );
				}
				// Without layout, "below the fold" = after the first two content images.
				if ( $i >= 2 && empty( $img['in_chrome'] ) ) {
					$below_fold = true;
				}
				if ( $below_fold && '' === (string) $img['loading'] ) {
					$f[] = self::make( 15, __( 'This image loads straight away. If it is further down the page, set it to load later (loading="lazy") so the page opens faster.', 'uichemy' ), $post_id, $img );
				}
				if ( ! empty( $img['external'] ) ) {
					$domain = (string) wp_parse_url( $img['src'], PHP_URL_HOST );
					/* translators: %s: domain. */
					$f[] = self::make( 17, sprintf( __( 'This image comes from another website (%s). Upload it to your own media library instead.', 'uichemy' ), $domain ), $post_id, $img );
				}
			}

			// ---- 18.3 Links ----
			$internal = 0;
			foreach ( isset( $d['links'] ) ? (array) $d['links'] : array() as $l ) {
				$rel = ' ' . $l['rel'] . ' ';
				if ( '_blank' === $l['target'] && false === strpos( $rel, ' noopener ' ) && false === strpos( $rel, ' noreferrer ' ) ) {
					$f[] = self::make( 18, __( 'This link opens in a new tab without a safety setting (rel="noopener"). Add it to protect your visitors.', 'uichemy' ), $post_id, $l );
				}
				if ( '' === $l['text'] && '' === trim( (string) $l['aria_label'] ) ) {
					$f[] = self::make( 19, __( 'This link has no text, so screen readers cannot tell people where it goes.', 'uichemy' ), $post_id, $l );
				} elseif ( in_array( strtolower( trim( $l['text'], " \t.!:…→»" ) ), self::GENERIC_ANCHORS, true ) ) {
					/* translators: %s: link text. */
					$f[] = self::make( 20, sprintf( __( '"%s" does not tell visitors where the link goes. Use words that describe the page it opens.', 'uichemy' ), $l['text'] ), $post_id, $l );
				}
				if ( $l['internal'] ) {
					++$internal;
					if ( false !== strpos( $rel, ' nofollow ' ) ) {
						$f[] = self::make( 23, __( 'This link to one of your own pages tells search engines not to follow it (nofollow). Remove that so they can find the page.', 'uichemy' ), $post_id, $l );
					}
				}
			}
			if ( 0 === $internal ) {
				$f[] = self::make( 22, __( 'This page does not link to any other page on your site. A few links help visitors and search engines.', 'uichemy' ), $post_id );
			}

			// ---- 18.4 Semantics ----
			$sig = isset( $d['signals'] ) ? (array) $d['signals'] : array();
			foreach ( isset( $sig['iframes_no_title'] ) ? $sig['iframes_no_title'] : array() as $n ) {
				$f[] = self::make( 25, __( 'This embedded item (like a video or map) has no title for screen readers.', 'uichemy' ), $post_id, $n );
			}
			foreach ( isset( $sig['time_no_datetime'] ) ? $sig['time_no_datetime'] : array() as $n ) {
				$f[] = self::make( 26, __( 'This date is written only for people, so search engines cannot read it. Add a datetime attribute with the date in a standard format.', 'uichemy' ), $post_id, $n );
			}
			foreach ( isset( $sig['tables_no_header'] ) ? $sig['tables_no_header'] : array() as $n ) {
				$f[] = self::make( 27, __( 'This table has no header row or caption, so it is hard to understand for screen readers.', 'uichemy' ), $post_id, $n );
			}
			$navs = isset( $sig['navs'] ) ? (array) $sig['navs'] : array();
			if ( count( $navs ) > 1 ) {
				$labels = array_map( function ( $n ) {
					return strtolower( (string) $n['label'] );
				}, $navs );
				if ( in_array( '', $labels, true ) || count( array_unique( $labels ) ) < count( $labels ) ) {
					/* translators: %d: count. */
					$f[] = self::make( 28, sprintf( __( 'This page has %d menus without names, so screen readers cannot tell them apart. Give each one a label.', 'uichemy' ), count( $navs ) ), $post_id, $navs[0] );
				}
			}
			$published = self::published_types( $record );
			foreach ( isset( $d['faq_candidates'] ) ? (array) $d['faq_candidates'] : array() as $g ) {
				if ( empty( $g['declared'] ) && ! isset( $published['FAQPage'] ) ) {
					$f[] = self::make( 29, __( 'This looks like a list of questions and answers. Add FAQPage JSON-LD to that section (Schema, in the editor) so it can appear in Google.', 'uichemy' ), $post_id, $g );
				}
			}
			if ( ! empty( $d['breadcrumbs'] ) && ! isset( $published['BreadcrumbList'] ) && ! apply_filters( 'uichemy_seo_breadcrumbs_published', false, $post_id ) ) {
				$f[] = self::make( 30, __( 'This page has a "Home > Section > Page" trail, but Google is not being told about it.', 'uichemy' ), $post_id, $d['breadcrumbs'][0] );
			}
			if ( ! empty( $sig['product_markup'] ) && empty( $sig['price_found'] ) && 'product' !== get_post_type( $post_id ) ) {
				$f[] = self::make( 31, __( 'This product has no price, so Google cannot show it as a product.', 'uichemy' ), $post_id );
			}
			foreach ( (array) apply_filters( 'uichemy_seo_schema_errors', array(), $post_id, $record ) as $err ) {
				$extra = array_intersect_key( $err, array_flip( array( 'source', 'index', 'properties' ) ) );
				if ( isset( $err['source'] ) && 'section' === $err['source'] ) {
					// Section JSON-LD is printed with the section; it is reported, not suppressed.
					$node = array(
						'path'    => isset( $err['node_path'] ) ? $err['node_path'] : null,
						'section' => isset( $err['section'] ) ? $err['section'] : null,
					);
					if ( 'json' === $err['property'] ) {
						$msg = __( 'A JSON-LD block in a section is not valid JSON, so Google ignores it. Check it for missing commas, quotes or brackets.', 'uichemy' );
					} else {
						/* translators: 1: type, 2: property list. */
						$msg = sprintf( __( 'The %1$s JSON-LD in a section has empty details Google needs: %2$s. Fill them in (Schema, in the editor) or remove the block.', 'uichemy' ), $err['type'], '"' . implode( '", "', isset( $err['properties'] ) ? (array) $err['properties'] : array( $err['property'] ) ) . '"' );
					}
					$f[] = self::make( 32, $msg, $post_id, $node, $extra );
					continue;
				}
				/* translators: 1: type, 2: property. */
				$f[] = self::make( 32, sprintf( __( '%1$s details were not added to this page because "%2$s" is missing.', 'uichemy' ), $err['type'], $err['property'] ), $post_id, null, $extra );
			}

			// ---- 18.5 Page-level metadata (on resolved values) ----
			$r = Uich_SEO_Resolver::for_post_with_record( $post_id, $record );
			if ( $r ) {
				$title = (string) $r['title']['value'];
				$desc  = (string) $r['description']['value'];
				if ( '' === trim( $title ) ) {
					$f[] = self::make( 33, __( 'This page has no title and none could be worked out from the page.', 'uichemy' ), $post_id );
				} else {
					$tl = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
					if ( $tl > 60 ) {
						/* translators: %d: characters. */
						$f[] = self::make( 35, sprintf( __( 'The title is %d characters long, so Google will cut it short. Try to keep it under 60.', 'uichemy' ), $tl ), $post_id );
					} elseif ( $tl < 15 ) {
						/* translators: %d: characters. */
						$f[] = self::make( 36, sprintf( __( 'The title is very short, only %d characters. A few more words help people know what the page is about.', 'uichemy' ), $tl ), $post_id );
					}
				}
				if ( '' === trim( $desc ) ) {
					$f[] = self::make( 34, __( 'This page has no description and none could be worked out from the page.', 'uichemy' ), $post_id );
				} else {
					$dl = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
					if ( $dl < 70 || $dl > 160 ) {
						/* translators: %d: characters. */
						$f[] = self::make( 37, sprintf( __( 'The description is %d characters long. Between 70 and 160 shows in full in Google.', 'uichemy' ), $dl ), $post_id );
					}
				}
				// Worked out from the page (main heading, first paragraph, page name or
				// the site-wide fallback) rather than written for search: a tip, since a
				// hand-written title and description usually get more clicks. Not when
				// one is missing (#33 / #34 already say so).
				$auto_title = '' !== trim( $title ) && in_array( (string) $r['title']['from'], array( 'h1', 'post-title' ), true );
				$auto_desc  = '' !== trim( $desc ) && in_array( (string) $r['description']['from'], array( 'first-paragraph', 'content', 'site' ), true );
				if ( $auto_title && $auto_desc ) {
					$f[] = self::make( 47, __( 'The title and description are filled in automatically from the page. Write your own in "Title and description" so the result in Google reads better and gets more clicks.', 'uichemy' ), $post_id );
				} elseif ( $auto_title ) {
					$f[] = self::make( 47, __( 'The title is filled in automatically from the page. Write your own in "Title and description" so the result in Google reads better and gets more clicks.', 'uichemy' ), $post_id );
				} elseif ( $auto_desc ) {
					$f[] = self::make( 47, __( 'The description is filled in automatically from the page. Write your own in "Title and description" so the result in Google reads better and gets more clicks.', 'uichemy' ), $post_id );
				}
				$canon = (string) $r['canonical']['value'];
				if ( '' !== $canon && Uich_SEO_Deriver::is_external( $canon, $host ) ) {
					$domain = (string) wp_parse_url( $canon, PHP_URL_HOST );
					/* translators: %s: domain. */
					$f[] = self::make( 40, sprintf( __( 'The preferred address points to %s, another website. This tells search engines this page belongs to someone else.', 'uichemy' ), $domain ), $post_id );
				}
				$img = isset( $r['share_image']['value'] ) && is_array( $r['share_image']['value'] ) ? $r['share_image']['value'] : null;
				// No picture from the page, the template or the site default: shared
				// links show as a bare text card.
				if ( ! $img || empty( $img['url'] ) ) {
					$f[] = self::make( 48, __( 'This page has no sharing image, so links to it look empty when shared on WhatsApp, LinkedIn, Facebook or X. Choose one in "Social preview", or set a default sharing image for the whole site.', 'uichemy' ), $post_id );
				}
				if ( $img && ! empty( $img['w'] ) && ! empty( $img['h'] ) && ( $img['w'] < 600 || $img['h'] < 315 ) ) {
					/* translators: 1: width, 2: height. */
					$f[] = self::make( 42, sprintf( __( 'The sharing image is %1$d by %2$d pixels. It will look small or cut off when the link is shared. 1200 by 630 works best.', 'uichemy' ), $img['w'], $img['h'] ), $post_id );
				}
			}
			// JSON-LD in the page head code that is not valid JSON is never printed.
			if ( ! empty( $d['head']['ld_errors'] ) ) {
				$f[] = self::make( 45, __( "A JSON-LD block in this page's head code is not valid JSON, so it is left out. Check it for missing commas, quotes or brackets.", 'uichemy' ), $post_id );
			}

			// Ignored per page (PRD §18.7) — kept, but flagged so counts skip them.
			$ignored = array_flip( (array) $record['ignored_checks'] );
			foreach ( $f as &$row ) {
				$row['ignored'] = isset( $ignored[ $row['check'] ] ) || isset( $ignored[ (string) $row['check_id'] ] );
			}
			unset( $row );

			return $f;
		}

		/**
		 * Is the page's main heading printed outside the content we read?
		 * True when a theme-builder template supplies an H1 for this post, or when
		 * the post is plain WordPress content (blocks / classic), where the theme
		 * prints the title as the H1. Composer pages build the whole page, so for
		 * them a missing H1 is real.
		 *
		 * @param int   $post_id Post id.
		 * @param array $d       Derived block.
		 * @return bool
		 */
		private static function h1_outside_content( $post_id, array $d ) {
			$source = isset( $d['source'] ) ? (string) $d['source'] : '';
			$tpl    = Uich_SEO_Sections::template_for_post( $post_id );
			if ( $tpl ) {
				$prec = Uich_SEO_Record::get( $tpl );
				foreach ( isset( $prec['derived']['headings'] ) ? (array) $prec['derived']['headings'] : array() as $h ) {
					if ( 1 === (int) $h['level'] ) {
						return true;
					}
				}
				return false; // The template owns the page and has no H1: report it.
			}
			$theme_title = in_array( $source, array( 'blocks', 'classic' ), true );
			// A Composer Builder document builds its own H1 only on the UiChemy
			// Canvas / Full width layouts, which print no title. On the theme's
			// own layout ("Standard", and every post by default) the theme prints
			// the title above it, so the H1 is outside the content like any
			// block-editor post - otherwise those pages reported a missing H1.
			if ( 'composer:cb' === $source ) {
				$layout      = (string) get_post_meta( $post_id, '_wp_page_template', true );
				$theme_title = ! in_array( $layout, array( 'uichemy-canvas', 'uichemy-full-width' ), true );
			}
			/**
			 * Does the theme print this post's title as the H1?
			 *
			 * @param bool   $theme_title Default guess.
			 * @param int    $post_id     Post id.
			 * @param string $source      Content source.
			 */
			return (bool) apply_filters( 'uichemy_seo_theme_prints_title', $theme_title, $post_id, $source );
		}

		/**
		 * Schema types this page will publish (filled by the schema module).
		 *
		 * @param array $record Record.
		 * @return array<string,bool>
		 */
		private static function published_types( array $record ) {
			$types = array();
			foreach ( isset( $record['derived']['entities'] ) ? (array) $record['derived']['entities'] : array() as $e ) {
				if ( isset( $e['entity']['@type'] ) ) {
					$types[ Uich_SEO_Head::type_of( $e['entity'] ) ] = true;
				}
			}
			foreach ( isset( $record['fields']['schema_extra']['value'] ) ? (array) $record['fields']['schema_extra']['value'] : array() as $e ) {
				if ( isset( $e['@type'] ) ) {
					$types[ Uich_SEO_Head::type_of( $e ) ] = true;
				}
			}
			// JSON-LD written into the sections (e.g. an FAQPage next to its accordion).
			foreach ( isset( $record['derived']['section_ld'] ) ? (array) $record['derived']['section_ld'] : array() as $e ) {
				if ( isset( $e['entity']['@type'] ) && is_string( $e['entity']['@type'] ) ) {
					$types[ $e['entity']['@type'] ] = true;
				}
			}
			return (array) apply_filters( 'uichemy_seo_published_types', $types, $record );
		}

		/**
		 * Does the theme wrap content in <main>? Composer sections usually sit
		 * inside the theme's <main>, so a missing <main> in the section HTML is not
		 * a finding by itself. Block themes and most classic themes have one.
		 *
		 * @return bool
		 */
		private static function theme_has_main() {
			return (bool) apply_filters( 'uichemy_seo_theme_has_main', wp_is_block_theme() || current_theme_supports( 'html5' ) );
		}

		/**
		 * Findings that depend on site state, not on the page: worked out when read,
		 * never stored, so they appear and clear the moment the setting changes.
		 *
		 * @param int $post_id Post id.
		 * @return array
		 */
		public static function live( $post_id ) {
			$out = array();
			if ( ! Uich_SEO_Settings::site_public() ) {
				$out[] = self::make( 46, self::site_hidden_text(), $post_id );
			}
			return $out;
		}

		/**
		 * Copy for the hidden-site finding.
		 *
		 * @return string
		 */
		public static function site_hidden_text() {
			return __( 'WordPress is set to discourage search engines from indexing this site (Settings → Reading → Search engine visibility). Every page gets noindex, nofollow and there is no sitemap, so nothing can appear in search. Turn it off when the site goes live.', 'uichemy' );
		}

		/**
		 * One finding.
		 *
		 * @param int        $id      Check id.
		 * @param string     $message Message.
		 * @param int        $post_id Post id.
		 * @param array|null $node    Row carrying path/section.
		 * @param array      $extra   Extra keys.
		 * @return array
		 */
		private static function make( $id, $message, $post_id, $node = null, array $extra = array() ) {
			$cat = self::catalogue();
			$c   = $cat[ $id ];
			return array_merge(
				array(
					'check_id'   => $id,
					'check'      => $c[0],
					'severity'   => $c[1],
					'message'    => $message,
					'fix_target' => $c[2],
					'post_id'    => (int) $post_id,
					'node_path'  => is_array( $node ) && isset( $node['path'] ) ? $node['path'] : null,
					'section'    => is_array( $node ) && isset( $node['section'] ) ? $node['section'] : null,
				),
				$extra
			);
		}

		/**
		 * Site-wide checks for one page (PRD §18.6): #16 large image files,
		 * #21 internal links that 404, #24 internal links through a redirect,
		 * #41 hidden from search but still in the sitemap. They need file or HTTP
		 * lookups, so they run only in the batched pass, never on save or on a
		 * front-end request, and the result is kept for 24 hours.
		 *
		 * (#9 and #44 cannot occur by construction: WordPress always declares the
		 * page language, and pages kept away from AI tools are filtered out of
		 * llms.txt automatically.)
		 *
		 * @param int  $post_id Post id.
		 * @param bool $force   Ignore the 24h cache.
		 * @param float $deadline microtime() after which link checks stop (0 = no limit).
		 * @return array|false Findings, or false when the deadline cut the checks short (nothing saved).
		 */
		public static function site_checks( $post_id, $force = false, $deadline = 0.0 ) {
			$record = Uich_SEO_Record::get( $post_id );
			if ( ! $force && $record['site_checked'] && $record['site_checked'] > time() - DAY_IN_SECONDS ) {
				return $record['site_findings'];
			}
			$d     = $record['derived'];
			$f     = array();
			$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$links = 0;

			foreach ( isset( $d['images'] ) ? (array) $d['images'] : array() as $img ) {
				if ( ! empty( $img['external'] ) || '' === (string) $img['src'] || Uich_SEO_Html::has_twig( $img['src'] ) ) {
					continue;
				}
				$src  = 0 === strpos( $img['src'], '/' ) && 0 !== strpos( $img['src'], '//' ) ? home_url( $img['src'] ) : $img['src'];
				$aid  = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $src ) );
				$file = $aid ? get_attached_file( $aid ) : '';
				if ( $file && file_exists( $file ) ) {
					// The size actually served: the sized file if the src names one.
					if ( preg_match( '/-\d+x\d+\.[a-z0-9]+$/i', $src ) ) {
						$sized = trailingslashit( dirname( $file ) ) . basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
						if ( file_exists( $sized ) ) {
							$file = $sized;
						}
					}
					$bytes = (int) filesize( $file );
					if ( $bytes > 300 * 1024 ) {
						/* translators: %s: size. */
						$f[] = self::make( 16, sprintf( __( 'This image file is %s, which slows the page down. Try a smaller or compressed version.', 'uichemy' ), size_format( $bytes ) ), $post_id, $img );
					}
				}
			}

			foreach ( isset( $d['links'] ) ? (array) $d['links'] : array() as $l ) {
				if ( empty( $l['internal'] ) || Uich_SEO_Html::has_twig( $l['href'] ) || $links >= 30 ) {
					continue;
				}
				$href = $l['href'];
				if ( 0 === strpos( $href, '?' ) || preg_match( '#^(\.\./|\./)#', $href ) ) {
					continue;
				}
				$abs = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href;
				if ( (string) wp_parse_url( $abs, PHP_URL_HOST ) !== $host ) {
					continue;
				}
				// Same host is not enough: a link written into content as host:6379 would make the
				// server probe its own ports. Only the site's own scheme family and port are checked.
				if ( ! in_array( strtolower( (string) wp_parse_url( $abs, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true )
					|| (int) wp_parse_url( $abs, PHP_URL_PORT ) !== (int) wp_parse_url( home_url(), PHP_URL_PORT ) ) {
					continue;
				}
				// Out of time: save nothing so the page is re-checked; cached statuses make that cheap.
				if ( $deadline && microtime( true ) > $deadline ) {
					return false;
				}
				++$links;
				$status = self::link_status( $abs );
				if ( 404 === $status || 410 === $status ) {
					/* translators: %s: url. */
					$f[] = self::make( 21, sprintf( __( 'This link points to %s, which does not exist.', 'uichemy' ), $href ), $post_id, $l );
				} elseif ( in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
					$to = self::link_target( $abs );
					/* translators: %s: target. */
					$f[] = self::make( 24, sprintf( __( 'This link goes through a redirect. Point it straight at %s instead.', 'uichemy' ), $to ? $to : __( 'the new address', 'uichemy' ) ), $post_id, $l );
				}
			}

			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$r   = Uich_SEO_Resolver::for_post( $post_id );
				$row = Uich_SEO_Settings::type_row( $post->post_type );
				$in_sitemap = $row['sitemap'] && $row['index'] && ( ! Uich_SEO_Settings::get( 'sitemap_sync', true ) || ! Uich_SEO_Ownership::we_emit() );
				if ( $r && empty( $r['robots']['index'] ) && 'content-type' !== $r['robots']['reason'] && $in_sitemap && empty( $post->post_password ) ) {
					$f[] = self::make( 41, __( 'This page is hidden from search but still listed in the sitemap.', 'uichemy' ), $post_id );
				}
			}

			$ignored = array_flip( (array) $record['ignored_checks'] );
			foreach ( $f as &$row_f ) {
				$row_f['ignored'] = isset( $ignored[ $row_f['check'] ] ) || isset( $ignored[ (string) $row_f['check_id'] ] );
			}
			unset( $row_f );

			$record['site_findings'] = $f;
			$record['site_checked']  = time();
			Uich_SEO_Record::save( $post_id, $record );
			return $f;
		}

		/**
		 * HTTP status of an internal URL, without following redirects. Cached 24h.
		 *
		 * @param string $url URL.
		 * @return int 0 when unknown.
		 */
		private static function link_status( $url ) {
			$key    = 'uich_seo_ls_' . md5( $url );
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return (int) $cached;
			}
			// Cheap answers first: a known post or an active redirect needs no request.
			// A redirect row answers with its own status; 410 / 451 mean the page is gone
			// (451 is reported as 410, the "does not exist" finding).
			$known    = url_to_postid( $url );
			$redirect = ( $known || ! class_exists( 'Uich_SEO_Redirects' ) ) ? null : Uich_SEO_Redirects::match( Uich_SEO_Redirects::normalize( $url ) );
			if ( $known ) {
				$status = 200;
			} elseif ( $redirect ) {
				$status = 451 === (int) $redirect->type ? 410 : (int) $redirect->type;
			} else {
				$res    = wp_remote_head( $url, array( 'timeout' => 2, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
				$status = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				if ( 405 === $status ) {
					$res    = wp_remote_get( $url, array( 'timeout' => 2, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'limit_response_size' => 1024 ) );
					$status = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				}
			}
			// A lookup that failed (timeout, DNS) is retried soon instead of hiding a broken link for a day.
			set_transient( $key, $status, $status ? DAY_IN_SECONDS : 2 * MINUTE_IN_SECONDS );
			return $status;
		}

		/**
		 * Where a redirecting internal URL goes.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private static function link_target( $url ) {
			if ( class_exists( 'Uich_SEO_Redirects' ) ) {
				$row = Uich_SEO_Redirects::match( Uich_SEO_Redirects::normalize( $url ) );
				if ( $row && '' !== (string) $row->target ) {
					return (string) $row->target;
				}
			}
			$res = wp_remote_head( $url, array( 'timeout' => 2, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
			return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' );
		}

		/**
		 * Findings for a page, from the stored record, ignored ones removed.
		 *
		 * @param int  $post_id         Post id.
		 * @param bool $include_ignored Keep ignored rows.
		 * @return array
		 */
		public static function stored( $post_id, $include_ignored = false ) {
			$record = Uich_SEO_Record::get( $post_id );
			$rows   = array_merge( self::live( $post_id ), (array) $record['findings'], (array) $record['site_findings'] );
			if ( $include_ignored ) {
				return $rows;
			}
			return array_values( array_filter( $rows, function ( $r ) {
				return empty( $r['ignored'] );
			} ) );
		}

		/**
		 * `uichemy_audit_findings` — add SEO findings to the Composer audit.
		 *
		 * @param array $findings Existing findings.
		 * @param array $params   Audit params (post_id optional).
		 * @return array
		 */
		public static function into_composer_audit( $findings, $params = array() ) {
			$findings = is_array( $findings ) ? $findings : array();
			$own      = Uich_SEO_Ownership::resolve();
			if ( $own['conflict'] ) {
				$findings[] = array(
					'severity' => 'blocker',
					'check'    => 'seo_head_conflict',
					/* translators: %s: plugin. */
					'message'  => sprintf( __( 'This plugin is set to handle SEO, but %s is also installed. It has paused so your pages do not get two sets of SEO settings.', 'uichemy' ), $own['competitor']['label'] ),
					'fix'      => 'uichemy-composer/seo (settings: head_owner)',
				);
			}
			$post_id = is_array( $params ) && ! empty( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			if ( $post_id ) {
				foreach ( self::stored( $post_id ) as $row ) {
					$findings[] = array(
						'severity' => $row['severity'],
						'check'    => 'seo_' . $row['check'],
						'message'  => $row['message'],
						// audit-page-seo is the action that explains this finding and names its fix.
						'fix'      => 'uichemy-composer/seo (action="audit-page-seo", post_id=' . $post_id . ')' . ( $row['node_path'] ? ' (node ' . $row['node_path'] . ')' : '' ),
					);
				}
			}
			return $findings;
		}

		/**
		 * `uichemy_audit_checks` — list ours.
		 *
		 * @param array $checks Existing.
		 * @return array
		 */
		public static function into_composer_checks( $checks ) {
			$checks = is_array( $checks ) ? $checks : array();
			foreach ( self::catalogue() as $id => $c ) {
				if ( in_array( $id, self::RESERVED, true ) ) {
					continue;
				}
				$checks[] = array(
					'check'    => 'seo_' . $c[0],
					'scope'    => $c[3],
					'severity' => $c[1],
				);
			}
			return $checks;
		}
	}
}
