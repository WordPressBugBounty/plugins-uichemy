<?php
/**
 * Resolver — head code + page content + site settings → the final values for one URL.
 *
 * Binds to the queried object (D6). Precedence for every value:
 *   1. the page's before-</head> code (its SEO tags, Twig allowed),
 *   2. the theme-builder template's before-</head> code (Twig against the post),
 *   3. what the page's content says (H1, first paragraph, first image…),
 *   4. the site's before-</head> code (fallback tags; its <title> is the template),
 *   5. WordPress.
 * Never parses HTML on the front end: it reads the record cache, which holds the
 * parsed page head, and the memoised site head.
 *
 * The same code path serves the live request (`for_request()`) and the admin /
 * MCP preview (`for_post()`), which is what makes `preview-head` match the
 * rendered head byte for byte.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Resolver' ) ) {

	/**
	 * Value resolution.
	 */
	class Uich_SEO_Resolver {

		/**
		 * Per-request memo for the live request.
		 *
		 * @var array|null
		 */
		private static $request = null;

		/**
		 * Resolve the current request.
		 *
		 * @return array|null Null when nothing should be emitted (e.g. admin, feeds).
		 */
		public static function for_request() {
			if ( null !== self::$request ) {
				return self::$request ?: null;
			}
			self::$request = false;

			if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
				return null;
			}

			$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

			if ( is_front_page() && is_home() ) {
				$out = self::for_home( $paged );
			} elseif ( is_singular() ) {
				$out = self::for_post( get_queried_object_id(), array( 'live' => true, 'paged' => $paged ) );
			} elseif ( is_home() ) {
				$page_id = (int) get_option( 'page_for_posts' );
				$out     = $page_id ? self::for_post( $page_id, array( 'live' => true, 'paged' => $paged, 'is_posts_page' => true ) ) : self::for_home( $paged );
			} elseif ( is_category() || is_tag() || is_tax() ) {
				$out = self::for_term( get_queried_object(), $paged );
			} elseif ( is_author() ) {
				$out = self::for_author( get_queried_object(), $paged );
			} elseif ( is_post_type_archive() ) {
				$out = self::for_post_type_archive( get_query_var( 'post_type' ), $paged );
			} elseif ( is_search() ) {
				$out = self::bare( 'search', __( 'Search results', 'uichemy' ), Uich_SEO_Settings::get( 'noindex_search', true ) ? 'search' : '' );
			} elseif ( is_404() ) {
				$out = self::bare( '404', '', '404' );
			} elseif ( is_date() ) {
				$out = self::bare( 'date', '', Uich_SEO_Settings::get( 'noindex_date', true ) ? 'date-archive' : '' );
			} else {
				return null;
			}

			// Page 2, 3… of a list or a multi-page post must not share page 1's <title>
			// (WordPress core appends "Page N" the same way).
			if ( $out && $paged > 1 && empty( $out['bare'] ) && ! empty( $out['title']['value'] ) ) {
				/* translators: %s: page number. */
				$out['title']['value'] .= ' ' . Uich_SEO_Settings::get( 'title_separator', '|' ) . ' ' . sprintf( __( 'Page %s', 'uichemy' ), $paged );
			}

			if ( $out && ( is_archive() || is_home() ) ) {
				$out['prev'] = $paged > 1 ? get_pagenum_link( $paged - 1 ) : '';
				global $wp_query;
				$max         = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 1;
				$out['next'] = $paged < $max ? get_pagenum_link( $paged + 1 ) : '';
			}

			self::$request = $out ? $out : false;
			return $out;
		}

		/**
		 * Forget the request memo (tests, preview).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$request = null;
		}

		/**
		 * Resolve a post, page, product or any singular object.
		 *
		 * @param int   $post_id Post id.
		 * @param array $opts    { live: bool, paged: int, is_posts_page: bool, skip_redirect: bool }.
		 * @return array|null
		 */
		public static function for_post( $post_id, array $opts = array() ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return null;
			}
			$live     = ! empty( $opts['live'] );
			$paged    = isset( $opts['paged'] ) ? max( 1, (int) $opts['paged'] ) : 1;
			$is_front = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;
			$row      = Uich_SEO_Settings::type_row( $post->post_type );
			$record   = Uich_SEO_Record::get( $post->ID );
			$fields   = $record['fields'];
			$derived  = $record['derived'];
			// A password-protected post shows nothing of its text: ignore values derived from its
			// content (H1 title, first paragraph, first image, declared entities), including a
			// record built before the password was added.
			if ( '' !== (string) $post->post_password && is_array( $derived ) ) {
				foreach ( array( 'title', 'description', 'share_image', 'content_type' ) as $k ) {
					unset( $derived[ $k ] );
				}
				$derived['entities'] = array();
			}

			// Front end with no record yet: the page head code's tags are read live
			// (Uich_SEO_Record::get()), the rest falls back, and the derive is queued (02 §4).
			if ( $live && ! Uich_SEO_Record::is_built( $record ) ) {
				self::queue_once( $post->ID );
			}

			$pattern = self::pattern_for( $post, $live );

			$out = self::skeleton( 'post', $post->ID );

			// ---- Title (PRD §14, §19) ----
			$pfields = $pattern && ! empty( $pattern['fields'] ) ? $pattern['fields'] : array();
			if ( self::has_field( $fields, 'title' ) ) {
				$out['title'] = self::val( self::render_page( $fields['title']['value'], $post, array( 'title' => Uich_SEO_Html::title( get_the_title( $post ) ) ) ), self::field_from( $fields['title'] ) );
			} elseif ( self::has_field( $pfields, 'title' ) && '' !== self::render_page( $pfields['title']['value'], $post ) ) {
				$out['title'] = self::val( self::render_page( $pfields['title']['value'], $post, array( 'title' => Uich_SEO_Html::title( get_the_title( $post ) ) ) ), 'template-code' );
			} else {
				$core = '';
				$from = '';
				if ( $pattern && ! empty( $pattern['title']['twig'] ) ) {
					$core = self::render( $pattern['title']['value'], $post );
					$from = 'template';
				}
				if ( '' === $core && ! empty( $derived['title']['value'] ) ) {
					$core = (string) $derived['title']['value'];
					$from = 'h1';
				}
				if ( '' === $core ) {
					// get_the_title() prefixes "Protected: " on password-protected posts; a search
					// result title should read like the post's own title.
					$core = '' !== (string) $post->post_password
						? Uich_SEO_Html::title( (string) apply_filters( 'the_title', $post->post_title, $post->ID ) )
						: Uich_SEO_Html::title( get_the_title( $post ) );
					$from = 'post-title';
				}
				$out['title']         = self::val( self::with_affix( $core, $post ), $from );
				$out['title']['core'] = $core;
				if ( 'h1' === $from ) {
					$out['title']['path']    = isset( $derived['title']['path'] ) ? $derived['title']['path'] : null;
					$out['title']['section'] = isset( $derived['title']['section'] ) ? $derived['title']['section'] : null;
				}
			}

			// ---- Description ----
			$desc = null;
			if ( self::has_field( $fields, 'description' ) ) {
				$desc = self::val( self::render_page( $fields['description']['value'], $post ), self::field_from( $fields['description'] ) );
			} elseif ( self::has_field( $pfields, 'description' ) && '' !== self::render_page( $pfields['description']['value'], $post ) ) {
				$desc = self::val( Uich_SEO_Deriver::clip( self::render_page( $pfields['description']['value'], $post ), Uich_SEO_Deriver::DESC_MAX ), 'template-code' );
			} else {
				if ( $pattern && ! empty( $pattern['description']['twig'] ) ) {
					$v = Uich_SEO_Deriver::clip( self::render( $pattern['description']['value'], $post ), Uich_SEO_Deriver::DESC_MAX );
					if ( '' !== $v ) {
						$desc = self::val( $v, 'template' );
					}
				}
				if ( ! $desc && '' !== trim( (string) $post->post_excerpt ) ) {
					$desc = self::val( Uich_SEO_Deriver::clip( Uich_SEO_Html::plain( $post->post_excerpt ), Uich_SEO_Deriver::DESC_MAX ), 'excerpt' );
				}
				if ( ! $desc && ! empty( $derived['description']['value'] ) ) {
					$desc            = self::val( (string) $derived['description']['value'], 'first-paragraph' );
					$desc['path']    = isset( $derived['description']['path'] ) ? $derived['description']['path'] : null;
					$desc['section'] = isset( $derived['description']['section'] ) ? $derived['description']['section'] : null;
				}
				if ( ! $desc && ! Uich_SEO_Record::is_built( $record ) ) {
					// Not derived yet (async queue pending): a cheap text fallback, no DOM parse.
					$plain = Uich_SEO_Html::plain( excerpt_remove_blocks( strip_shortcodes( (string) $post->post_content ) ) );
					if ( Uich_SEO_Html::words( $plain ) >= Uich_SEO_Deriver::MIN_DESC_WORDS ) {
						$desc = self::val( Uich_SEO_Deriver::clip( $plain, Uich_SEO_Deriver::DESC_MAX ), 'content' );
					}
				}
				// The site head code's description is the fallback for every page that
				// says nothing of its own (the owner put it there on purpose; the audit
				// flags the duplicates it causes). Without one, only the front page
				// falls back to the tagline.
				if ( ! $desc ) {
					$site_desc = $is_front ? self::site_description() : trim( (string) Uich_SEO_Settings::get( 'home_description', '' ) );
					if ( '' !== $site_desc ) {
						$desc = self::val( self::render( $site_desc, $post ), 'site' );
					}
				}
			}
			// A password-protected post shows nothing of its text to anyone without the
			// password: no description from the excerpt or the body (WordPress hides
			// the excerpt of protected posts for the same reason). A description the
			// owner typed on purpose is still used.
			if ( '' !== (string) $post->post_password && ( ! $desc || ! in_array( $desc['from'], array( 'page', 'template-code', 'site' ), true ) ) ) {
				$desc = null;
			}
			$out['description'] = $desc ? $desc : self::val( '', '' );

			// ---- Social title / description (PRD §19) ----
			$out['social_title'] = self::has_field( $fields, 'social_title' )
				? self::val( self::render_page( $fields['social_title']['value'], $post ), self::field_from( $fields['social_title'] ) )
				: self::val( isset( $out['title']['core'] ) ? $out['title']['core'] : $out['title']['value'], 'title' );
			$out['social_description'] = self::has_field( $fields, 'social_description' )
				? self::val( self::render_page( $fields['social_description']['value'], $post ), self::field_from( $fields['social_description'] ) )
				: self::val( $out['description']['value'], 'description' );

			// ---- Share image: page → featured → template → first content image → site default ----
			$img = null;
			if ( self::has_field( $fields, 'share_image' ) ) {
				$img = self::val( $fields['share_image']['value'], self::field_from( $fields['share_image'] ) );
			} elseif ( has_post_thumbnail( $post ) ) {
				$img = self::val( self::attachment_image( get_post_thumbnail_id( $post ) ), 'featured' );
			}
			if ( ! $img && self::has_field( $pfields, 'share_image' ) && ! empty( $pfields['share_image']['value']['url'] ) ) {
				$url = esc_url_raw( self::render_page( $pfields['share_image']['value']['url'], $post ) );
				if ( '' !== $url ) {
					$img = self::val( array_merge( (array) $pfields['share_image']['value'], array( 'url' => $url ) ), 'template-code' );
				}
			}
			if ( ! $img && $pattern && ! empty( $pattern['share_image']['twig'] ) ) {
				$url = esc_url_raw( self::render( $pattern['share_image']['value']['url'], $post ) );
				if ( '' !== $url ) {
					$img = self::val( array( 'id' => 0, 'url' => $url, 'w' => null, 'h' => null ), 'template' );
				}
			}
			if ( ! $img && ! empty( $derived['share_image']['value']['url'] ) ) {
				$img            = self::val( $derived['share_image']['value'], 'first-image' );
				$img['path']    = isset( $derived['share_image']['path'] ) ? $derived['share_image']['path'] : null;
				$img['section'] = isset( $derived['share_image']['section'] ) ? $derived['share_image']['section'] : null;
			}
			if ( ! $img ) {
				$img = self::site_image();
			}
			$out['share_image'] = $img ? $img : self::val( null, '' );

			// ---- Canonical ----
			if ( self::has_field( $fields, 'canonical' ) ) {
				$out['canonical'] = self::val( $fields['canonical']['value'], self::field_from( $fields['canonical'] ) );
			} else {
				$url = $is_front ? home_url( '/' ) : get_permalink( $post );
				if ( $paged > 1 && $url ) {
					$url = self::paged_url( $url, $paged, ! empty( $opts['is_posts_page'] ) );
				}
				$out['canonical'] = self::val( (string) $url, 'permalink' );
			}

			// ---- Content type → og:type + schema @type (PRD §19.3) ----
			if ( self::has_field( $fields, 'content_type' ) ) {
				$type = self::val( $fields['content_type']['value'], self::field_from( $fields['content_type'] ) );
			} elseif ( ! empty( $derived['content_type']['value'] ) ) {
				$type = self::val( $derived['content_type']['value'], 'markup' );
			} else {
				$pt   = in_array( $row['page_type'], array( 'webpage', 'article', 'product' ), true ) ? $row['page_type'] : 'webpage';
				$type = self::val( $pt, 'content-type' );
			}
			if ( $is_front && 'article' === $type['value'] && ! self::has_field( $fields, 'content_type' ) ) {
				$type = self::val( 'webpage', 'front-page' );
			}
			$out['content_type'] = $type;
			$out['og_type']      = self::og_type( $type['value'] );

			// ---- Robots ----
			$out['robots'] = self::robots_for_post( $post, $fields, $row, $paged );

			// ---- Locale ----
			$out['og_locale'] = self::has_field( $fields, 'og_locale' )
				? self::val( $fields['og_locale']['value'], 'page' )
				: self::site_locale();

			// ---- Article times ----
			if ( 'article' === $type['value'] ) {
				$out['article'] = array(
					'published' => get_post_time( 'c', true, $post ),
					'modified'  => get_post_modified_time( 'c', true, $post ),
					'author'    => get_the_author_meta( 'display_name', $post->post_author ),
				);
			}

			$out['exclude_ai']  = self::has_field( $fields, 'exclude_ai' ) ? (bool) $fields['exclude_ai']['value'] : false;
			// Redirects live only in the Redirects table. A live request for a
			// redirected page never gets this far, so only previews look it up.
			$out['redirect_to'] = ( $live || ! empty( $opts['skip_redirect'] ) || ! class_exists( 'Uich_SEO_Redirects' ) ) ? '' : Uich_SEO_Redirects::page_target( $post->ID );
			$out['template_id'] = $pattern ? (int) $pattern['template_id'] : 0;
			$out['is_draft']    = ! in_array( $post->post_status, array( 'publish', 'private' ), true );

			return self::finalize( $out, $post );
		}

		/**
		 * Resolve a post against a record that is not (or not yet) the stored one —
		 * used by the audit while saving, and by the panel to show inherited values.
		 *
		 * @param int   $post_id Post id.
		 * @param array $record  Record.
		 * @return array|null
		 */
		public static function for_post_with_record( $post_id, array $record ) {
			$swap = function ( $value, $object_id, $meta_key ) use ( $post_id, $record ) {
				if ( (int) $object_id === (int) $post_id && Uich_SEO_Record::META === $meta_key ) {
					return array( $record ); // get_metadata() unwraps [0] for single reads.
				}
				return $value;
			};
			add_filter( 'get_post_metadata', $swap, 1, 3 );
			$r = self::for_post( $post_id );
			remove_filter( 'get_post_metadata', $swap, 1 );
			return $r;
		}

		/**
		 * Front page showing latest posts — no queried post (QA §2 #11).
		 *
		 * @param int $paged Page.
		 * @return array
		 */
		public static function for_home( $paged = 1 ) {
			$out   = self::skeleton( 'home', 0 );
			$name = Uich_SEO_Settings::site_name();
			$tag  = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
			// SureRank's default: "%site_name% - %tagline%", with the site separator.
			$sep          = (string) Uich_SEO_Settings::get( 'title_separator', '|' );
			// Intentionally not title_template: that is for page titles; the home title is "name | tagline".
			$out['title'] = self::val( '' !== $tag ? $name . ' ' . $sep . ' ' . $tag : $name, 'site' );
			$out['description']        = self::val( self::site_description(), 'site' );
			$out['social_title']       = self::val( $out['title']['value'], 'title' );
			$out['social_description'] = self::val( $out['description']['value'], 'description' );
			$img                       = self::site_image();
			$out['share_image']        = $img ? $img : self::val( null, '' );
			$out['canonical']          = self::val( $paged > 1 ? self::paged_url( home_url( '/' ), $paged, true ) : home_url( '/' ), 'permalink' );
			$out['content_type']       = self::val( 'webpage', 'front-page' );
			$out['og_type']            = 'website';
			$out['robots']             = self::robots( true, true, array(), $paged > 1 && Uich_SEO_Settings::get( 'noindex_paginated', false ) ? 'paginated' : '' );
			$out['og_locale']          = self::site_locale();
			return self::finalize( $out, null );
		}

		/**
		 * Category / tag / custom taxonomy archive.
		 *
		 * @param WP_Term $term  Term.
		 * @param int     $paged Page.
		 * @return array|null
		 */
		public static function for_term( $term, $paged = 1 ) {
			if ( ! $term instanceof WP_Term ) {
				return null;
			}
			$row  = Uich_SEO_Settings::taxonomy_row( $term->taxonomy );
			$out  = self::skeleton( 'term', $term->term_id );
			$name = wp_specialchars_decode( $term->name, ENT_QUOTES );
			$out['title']              = self::val( self::with_affix( $name, null ), 'term' );
			$out['title']['core']      = $name;
			$desc                      = Uich_SEO_Deriver::clip( Uich_SEO_Html::squish( wp_strip_all_tags( term_description( $term ) ) ), Uich_SEO_Deriver::DESC_MAX );
			$out['description']        = self::val( $desc, '' === $desc ? '' : 'term' );
			$out['social_title']       = self::val( $name, 'title' );
			$out['social_description'] = self::val( $desc, 'description' );
			$img                       = self::site_image();
			$out['share_image']        = $img ? $img : self::val( null, '' );
			$link                      = get_term_link( $term );
			$link                      = is_wp_error( $link ) ? '' : $link;
			$out['canonical']          = self::val( $paged > 1 ? self::paged_url( $link, $paged, true ) : $link, 'permalink' );
			$out['content_type']       = self::val( 'webpage', 'archive' );
			$out['og_type']            = 'website';
			$reason                    = ! $row['index'] ? 'taxonomy' : ( $paged > 1 && Uich_SEO_Settings::get( 'noindex_paginated', false ) ? 'paginated' : '' );
			$out['robots']             = self::robots( true, true, array(), $reason );
			$out['og_locale']          = self::site_locale();
			return self::finalize( $out, null );
		}

		/**
		 * Author archive.
		 *
		 * @param WP_User $user  User.
		 * @param int     $paged Page.
		 * @return array|null
		 */
		public static function for_author( $user, $paged = 1 ) {
			if ( ! $user instanceof WP_User ) {
				return null;
			}
			$out                       = self::skeleton( 'author', $user->ID );
			$name                      = $user->display_name;
			$out['title']              = self::val( self::with_affix( $name, null ), 'author' );
			$out['title']['core']      = $name;
			$desc                      = Uich_SEO_Deriver::clip( Uich_SEO_Html::squish( wp_strip_all_tags( get_the_author_meta( 'description', $user->ID ) ) ), Uich_SEO_Deriver::DESC_MAX );
			$out['description']        = self::val( $desc, '' === $desc ? '' : 'author' );
			$out['social_title']       = self::val( $name, 'title' );
			$out['social_description'] = self::val( $desc, 'description' );
			$img                       = self::site_image();
			$out['share_image']        = $img ? $img : self::val( null, '' );
			$link                      = get_author_posts_url( $user->ID );
			$out['canonical']          = self::val( $paged > 1 ? self::paged_url( $link, $paged, true ) : $link, 'permalink' );
			$out['content_type']       = self::val( 'webpage', 'archive' );
			$out['og_type']            = 'profile';
			$out['robots']             = self::robots( true, true, array(), Uich_SEO_Settings::get( 'noindex_author', true ) ? 'author-archive' : '' );
			$out['og_locale']          = self::site_locale();
			return self::finalize( $out, null );
		}

		/**
		 * Post type archive.
		 *
		 * @param string|array $post_type Post type.
		 * @param int          $paged     Page.
		 * @return array|null
		 */
		public static function for_post_type_archive( $post_type, $paged = 1 ) {
			$post_type = is_array( $post_type ) ? reset( $post_type ) : (string) $post_type;
			$obj       = get_post_type_object( $post_type );
			if ( ! $obj ) {
				return null;
			}
			$row                       = Uich_SEO_Settings::type_row( $post_type );
			$out                       = self::skeleton( 'archive', 0 );
			$name                      = $obj->labels->name;
			$out['title']              = self::val( self::with_affix( $name, null ), 'archive' );
			$out['title']['core']      = $name;
			$desc                      = Uich_SEO_Deriver::clip( Uich_SEO_Html::squish( wp_strip_all_tags( (string) $obj->description ) ), Uich_SEO_Deriver::DESC_MAX );
			$out['description']        = self::val( $desc, '' === $desc ? '' : 'archive' );
			$out['social_title']       = self::val( $name, 'title' );
			$out['social_description'] = self::val( $desc, 'description' );
			$img                       = self::site_image();
			$out['share_image']        = $img ? $img : self::val( null, '' );
			$link                      = (string) get_post_type_archive_link( $post_type );
			$out['canonical']          = self::val( $paged > 1 ? self::paged_url( $link, $paged, true ) : $link, 'permalink' );
			$out['content_type']       = self::val( 'webpage', 'archive' );
			$out['og_type']            = 'website';
			$out['robots']             = self::robots( true, true, array(), ! $row['index'] ? 'content-type' : '' );
			$out['og_locale']          = self::site_locale();
			return self::finalize( $out, null );
		}

		/**
		 * Search / 404 / date: robots only; the title stays WordPress's own.
		 *
		 * @param string $kind   Kind.
		 * @param string $title  Title ('' = let WordPress decide).
		 * @param string $reason Noindex reason or ''.
		 * @return array
		 */
		private static function bare( $kind, $title, $reason ) {
			$out                       = self::skeleton( $kind, 0 );
			$out['title']              = self::val( '', '' );
			$out['description']        = self::val( '', '' );
			$out['social_title']       = self::val( '', '' );
			$out['social_description'] = self::val( '', '' );
			$out['share_image']        = self::val( null, '' );
			$out['canonical']          = self::val( '', '' );
			$out['content_type']       = self::val( 'webpage', $kind );
			$out['og_type']            = '';
			$out['robots']             = self::robots( true, true, array(), $reason );
			$out['og_locale']          = self::site_locale();
			$out['bare']               = true;
			return self::finalize( $out, null );
		}

		// ------------------------------------------------------------------
		// Pieces
		// ------------------------------------------------------------------

		/**
		 * Empty result.
		 *
		 * @param string $kind Kind.
		 * @param int    $id   Object id.
		 * @return array
		 */
		private static function skeleton( $kind, $id ) {
			return array(
				'object'    => array(
					'kind' => $kind,
					'id'   => (int) $id,
				),
				'site_name' => Uich_SEO_Settings::share_site_name(),
				'prev'      => '',
				'next'      => '',
				'article'   => null,
				'bare'      => false,
			);
		}

		/**
		 * Value + provenance.
		 *
		 * @param mixed  $value Value.
		 * @param string $from  Token.
		 * @return array
		 */
		private static function val( $value, $from ) {
			return array(
				'value' => $value,
				'from'  => $from,
			);
		}

		/**
		 * Does the record carry an override for a field?
		 *
		 * @param array  $fields Fields.
		 * @param string $key    Key.
		 * @return bool
		 */
		private static function has_field( array $fields, $key ) {
			return isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && array_key_exists( 'value', $fields[ $key ] );
		}

		/**
		 * Provenance for an override.
		 *
		 * @param array $field Field entry.
		 * @return string
		 */
		private static function field_from( array $field ) {
			return isset( $field['from'] ) && '' !== (string) $field['from'] ? (string) $field['from'] : 'page';
		}

		/**
		 * Render a PAGE value (SEO panel field or page head code) for a post.
		 *
		 * Page values come from whoever edits the post. When its author lacks unfiltered_html
		 * and the value uses anything beyond the documented tokens (a stored value from before
		 * save-time validation, or written through page head code), it is not evaluated:
		 * the engine's functions would otherwise read other posts' private content.
		 *
		 * @param string       $tpl   Template.
		 * @param WP_Post|null $post  Post.
		 * @param array        $extra Extra context.
		 * @return string
		 */
		public static function render_page( $tpl, $post, array $extra = array() ) {
			if ( $post instanceof WP_Post && ! Uich_SEO_Settings::page_template_safe( $tpl ) && ! Uich_SEO_Head::author_trusted( $post->ID ) ) {
				// Rendered as plain text: the braces are dropped, nothing is evaluated.
				$tpl = preg_replace( '/\{\{.*?(\}\}|$)|\{%.*?(%\}|$)|\{#.*?(#\}|$)/s', '', (string) $tpl );
			}
			return self::render( $tpl, $post, $extra );
		}

		/**
		 * Render a Twig SEO template (site, template or trusted page value).
		 *
		 * @param string       $tpl   Template.
		 * @param WP_Post|null $post  Post.
		 * @param array        $extra Extra context.
		 * @return string
		 */
		public static function render( $tpl, $post, array $extra = array() ) {
			$tpl = (string) $tpl;
			if ( '' === $tpl ) {
				return '';
			}
			if ( class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( $tpl ) ) {
				$ctx = array_merge( array( 'site' => Uich_SEO_Settings::twig_site() ), $extra );
				if ( $post instanceof WP_Post ) {
					Uich_Dynamic::boot(); // post_provider() does not load its own class.
					$ctx['post'] = Uich_Dynamic::post_provider( $post );
				}
				$tpl = (string) Uich_Dynamic::render_html( $tpl, $ctx );
			}
			// A template Twig could not render must never leak its braces into a title.
			$tpl = preg_replace( '/\{\{.*?(\}\}|$)|\{%.*?(%\}|$)|\}\}|%\}/s', '', (string) $tpl );
			// Plain text out: Twig escapes what it prints ("&" → "&amp;"), and every
			// output path escapes again, so decode here or titles print "&amp;amp;".
			return Uich_SEO_Html::squish( html_entity_decode( wp_strip_all_tags( $tpl ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		/**
		 * Apply the site title template (Site → Titles: "{{ title }} {{ site.separator }}
		 * {{ site.name }}"), with {{ title }} = the page's own title. Never doubles
		 * the brand when the page title already ends with the site name.
		 *
		 * @param string       $core Title core.
		 * @param WP_Post|null $post Post.
		 * @return string
		 */
		private static function with_affix( $core, $post ) {
			$tpl = trim( (string) Uich_SEO_Settings::get( 'title_template', '' ) );
			if ( '' === $tpl ) {
				// No site <title> in the site head code: the built-in template.
				$tpl = (string) Uich_SEO_Settings::defaults()['title_template'];
			} elseif ( ! preg_match( '/\{\{\s*title\s*(?:\|[^}]*)?\}\}/', $tpl ) ) {
				// {{ title|upper }} still places the title; only a template with no
				// title token at all gets it prepended.
				$tpl = '{{ title }} ' . $tpl;
			}
			$name = (string) Uich_SEO_Settings::site_name();
			if ( '' !== $core && '' !== $name && preg_match( '/site\.(name|title)/', $tpl ) && substr( $core, -strlen( $name ) ) === $name ) {
				return $core;
			}
			$out = self::render( $tpl, $post, array( 'title' => $core ) );
			// Tidy a separator left dangling by an empty value ("About | " / " | Brand").
			$sep = preg_quote( (string) Uich_SEO_Settings::get( 'title_separator', '|' ), '/' );
			$out = trim( (string) preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $out ) );
			return '' === $out ? $core : $out;
		}

		/**
		 * Pattern record of the template governing a post (D4/D5).
		 *
		 * @param WP_Post $post Post.
		 * @param bool    $live Live request.
		 * @return array|null { title, description, share_image, template_id }.
		 */
		private static function pattern_for( $post, $live ) {
			if ( ! class_exists( 'UiChemy_Template_Resolver' ) ) {
				return null;
			}
			$tpl_id = $live ? self::live_template_id( $post ) : Uich_SEO_Sections::template_for_post( $post->ID );
			if ( ! $tpl_id ) {
				return null;
			}
			$rec = Uich_SEO_Record::get( $tpl_id );
			if ( 'pattern' !== $rec['kind'] || ! Uich_SEO_Record::is_built( $rec ) ) {
				return null;
			}
			$d = $rec['derived'];
			return array(
				// The template's own head tags (Twig, rendered against each post).
				'fields'      => $rec['fields'],
				'title'       => isset( $d['title'] ) ? $d['title'] : null,
				'description' => isset( $d['description'] ) ? $d['description'] : null,
				'share_image' => isset( $d['share_image'] ) ? $d['share_image'] : null,
				'template_id' => $tpl_id,
			);
		}

		/**
		 * Template for the live queried post (resolver is memoised per request).
		 *
		 * @param WP_Post $post Post.
		 * @return int
		 */
		private static function live_template_id( $post ) {
			if ( 'product' === $post->post_type && class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::is_valid_type( 'single_product' ) ) {
				$id = (int) UiChemy_Template_Resolver::resolve( 'single_product' );
				if ( $id ) {
					return $id;
				}
			}
			return (int) UiChemy_Template_Resolver::resolve( 'single' );
		}

		/**
		 * Robots for a post.
		 *
		 * @param WP_Post $post   Post.
		 * @param array   $fields Overrides.
		 * @param array   $row    Content-type row.
		 * @param int     $paged  Page.
		 * @return array
		 */
		private static function robots_for_post( $post, array $fields, array $row, $paged ) {
			// The site head code's robots meta is the default under the page's own.
			$site   = Uich_SEO_Head::robots_from( (string) Uich_SEO_Head::site()['robots'] );
			$index  = self::has_field( $fields, 'index' ) ? (bool) $fields['index']['value'] : ( (bool) $row['index'] && false !== $site['index'] );
			$follow = self::has_field( $fields, 'follow' ) ? (bool) $fields['follow']['value'] : ( false !== $site['follow'] );
			$adv    = self::has_field( $fields, 'robots_advanced' ) ? (array) $fields['robots_advanced']['value'] : $site['advanced'];
			$reason = '';

			if ( ! empty( $post->post_password ) ) {
				$reason = 'password';
			} elseif ( 'publish' !== $post->post_status ) {
				$reason = 'not-public';
			} elseif ( self::is_woo_private_page( $post->ID ) ) {
				$reason = 'woocommerce';
			} elseif ( ! $index ) {
				$reason = self::has_field( $fields, 'index' ) ? 'page' : ( false === $site['index'] ? 'site' : 'content-type' );
			} elseif ( $paged > 1 && Uich_SEO_Settings::get( 'noindex_paginated', false ) ) {
				$reason = 'paginated';
			}
			return self::robots( true, $follow, $adv, $reason );
		}

		/**
		 * Build a robots array.
		 *
		 * @param bool   $index  Index.
		 * @param bool   $follow Follow.
		 * @param array  $adv    Advanced flags.
		 * @param string $reason Forced-noindex reason, '' for none.
		 * @return array
		 */
		private static function robots( $index, $follow, array $adv, $reason ) {
			$r = array(
				'index'  => $index && '' === $reason,
				'follow' => (bool) $follow,
				'reason' => (string) $reason,
			);
			return array_merge( $r, $adv );
		}

		/**
		 * Cart / checkout / account pages are noindex by default (QA §2 #9).
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		private static function is_woo_private_page( $post_id ) {
			if ( ! function_exists( 'wc_get_page_id' ) ) {
				return false;
			}
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				if ( (int) wc_get_page_id( $p ) === (int) $post_id ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * og:type for a content type.
		 *
		 * @param string $type Content type.
		 * @return string
		 */
		public static function og_type( $type ) {
			$map = array(
				'webpage' => 'website',
				'article' => 'article',
				'product' => 'product',
			);
			return isset( $map[ $type ] ) ? $map[ $type ] : 'website';
		}

		/**
		 * An attachment as a share image.
		 *
		 * @param int $id Attachment id.
		 * @return array|null
		 */
		private static function attachment_image( $id ) {
			$id  = absint( $id );
			$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
			if ( ! $src ) {
				return null;
			}
			return array(
				'id'  => $id,
				'url' => $src[0],
				'w'   => (int) $src[1],
				'h'   => (int) $src[2],
				'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}

		/**
		 * Site default share image.
		 *
		 * @return array|null
		 */
		private static function site_image() {
			$img = Uich_SEO_Head::image_from( Uich_SEO_Head::site() );
			return $img ? self::val( $img, 'site' ) : null;
		}

		/**
		 * Site description for the front page.
		 *
		 * @return string
		 */
		private static function site_description() {
			foreach ( array( self::render( (string) Uich_SEO_Settings::get( 'home_description', '' ), null ), Uich_SEO_Settings::get( 'entity_description', '' ), get_bloginfo( 'description' ) ) as $c ) {
				$c = trim( (string) $c );
				if ( '' !== $c ) {
					return Uich_SEO_Html::squish( wp_specialchars_decode( $c, ENT_QUOTES ) );
				}
			}
			return '';
		}

		/**
		 * Site locale (PRD §19: site value → get_locale()).
		 *
		 * @return array
		 */
		private static function site_locale() {
			$l = trim( (string) Uich_SEO_Settings::get( 'og_locale', '' ) );
			return '' !== $l ? self::val( $l, 'site' ) : self::val( get_locale(), 'wordpress' );
		}

		/**
		 * Page N of a URL.
		 *
		 * @param string $url     Base.
		 * @param int    $paged   Page.
		 * @param bool   $archive Archive-style (/page/N/) or post-style (/N/).
		 * @return string
		 */
		private static function paged_url( $url, $paged, $archive ) {
			if ( $paged < 2 || '' === $url ) {
				return $url;
			}
			global $wp_rewrite;
			if ( ! $wp_rewrite || ! $wp_rewrite->using_permalinks() || false !== strpos( $url, '?' ) ) {
				return add_query_arg( $archive ? 'paged' : 'page', $paged, $url );
			}
			return trailingslashit( $url ) . ( $archive ? user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' ) : user_trailingslashit( (string) $paged, 'single_paged' ) );
		}

		/**
		 * Front-end, no record yet: queue one async derive (at most every 10 min).
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		private static function queue_once( $post_id ) {
			$key = 'uich_seo_q_' . $post_id;
			if ( get_transient( $key ) ) {
				return;
			}
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
			Uich_SEO_Record::queue( array( $post_id ) );
		}

		/**
		 * Public filters on every emitted value (02 §15).
		 *
		 * @param array        $out  Resolved.
		 * @param WP_Post|null $post Post.
		 * @return array
		 */
		private static function finalize( array $out, $post ) {
			// Sharing title fallback: a social title that only follows the page title
			// takes the site head's og:title, rendered with {{ title }} = the page's own
			// title. The search title is never touched.
			if ( isset( $out['social_title'], $out['title'] ) && 'title' === $out['social_title']['from'] ) {
				$site_title_tpl = trim( (string) Uich_SEO_Settings::get( 'default_social_title', '' ) );
				if ( '' !== $site_title_tpl ) {
					$core = isset( $out['title']['core'] ) ? (string) $out['title']['core'] : (string) $out['title']['value'];
					$v    = self::render( $site_title_tpl, $post, array( 'title' => $core ) );
					if ( '' !== $v ) {
						$out['social_title'] = self::val( $v, 'site-social' );
					}
				}
			}
			// Sharing fallback: a social description that only follows the search
			// description, when that is empty or merely the site-wide default, takes the
			// site head's og:description instead. Search text is never touched.
			if ( isset( $out['social_description'], $out['description'] ) && 'description' === $out['social_description']['from']
				&& ( '' === (string) $out['description']['value'] || 'site' === $out['description']['from'] ) ) {
				$site_social = trim( (string) Uich_SEO_Settings::get( 'default_social_description', '' ) );
				if ( '' !== $site_social ) {
					$out['social_description'] = self::val( self::render( $site_social, $post ), 'site-social' );
				}
			}
			// "Discourage search engines" (Settings → Reading): WordPress adds
			// noindex, nofollow to every page; report it the same way.
			if ( isset( $out['robots'] ) && is_array( $out['robots'] ) && ! Uich_SEO_Settings::site_public() ) {
				$out['robots']['index']  = false;
				$out['robots']['follow'] = false;
				$out['robots']['reason'] = 'site-hidden';
			}
			$out['title']['value']       = (string) apply_filters( 'uichemy_seo_title', (string) $out['title']['value'], $out, $post );
			$out['description']['value'] = (string) apply_filters( 'uichemy_seo_description', (string) $out['description']['value'], $out, $post );
			$out['robots']               = (array) apply_filters( 'uichemy_seo_robots', $out['robots'], $out, $post );
			/**
			 * Filter the whole resolved head model.
			 *
			 * @param array        $out  Resolved values.
			 * @param WP_Post|null $post Post, when the object is singular.
			 */
			return (array) apply_filters( 'uichemy_seo_resolved', $out, $post );
		}
	}
}
