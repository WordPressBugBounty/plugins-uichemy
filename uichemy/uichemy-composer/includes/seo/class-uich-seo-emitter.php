<?php
/**
 * Emitter — resolved values → head tags (02 §5).
 *
 * `block()` is pure-ish (escaping helpers only) and is used both for the live
 * `wp_head` output and for `preview-head`, so the two are identical by
 * construction. The title goes through `pre_get_document_title` (never a second
 * `<title>`); robots go through core's `wp_robots` (never a second robots meta).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Emitter' ) ) {

	/**
	 * Head output.
	 */
	class Uich_SEO_Emitter {

		const MARK_OPEN  = '<!-- UiChemy SEO -->';
		const MARK_CLOSE = '<!-- / UiChemy SEO -->';

		/**
		 * Hook in. Decisions that need the query wait for `wp`.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp', array( __CLASS__, 'maybe_take_head' ), 20 );
			add_filter( 'template_include', array( __CLASS__, 'dedupe_title_tag' ), 1000 );
		}

		/**
		 * One <title>, always (QA §4 duplicate-tag check).
		 *
		 * On a block theme, core's locate_block_template() hooks an unconditional
		 * title tag. If a later template_include (the theme builder's full-page
		 * view, at 99) swaps in a PHP template that calls get_header(), WordPress
		 * loads the theme-compat header.php — which prints its own <title> — and the
		 * page ends up with two. When we own the head, drop the redundant hook.
		 *
		 * @param string $template Final template path.
		 * @return string
		 */
		public static function dedupe_title_tag( $template ) {
			if ( is_admin() || ! Uich_SEO_Ownership::we_emit() || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
				return $template;
			}
			if ( false !== strpos( (string) $template, 'template-canvas.php' ) ) {
				return $template;
			}
			$header = (string) locate_template( 'header.php' );
			// locate_template() falls back to wp-includes/theme-compat, whose header.php prints <title>.
			if ( has_action( 'wp_head', '_block_template_render_title_tag' ) && ( '' === $header || false !== strpos( wp_normalize_path( $header ), '/theme-compat/' ) ) ) {
				remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
			}
			return $template;
		}

		/**
		 * On a front-end request we own: take the title, canonical and robots.
		 *
		 * @return void
		 */
		public static function maybe_take_head() {
			if ( is_admin() || ! Uich_SEO_Ownership::we_emit() ) {
				return;
			}
			$r = Uich_SEO_Resolver::for_request();
			if ( ! $r || ! self::should_emit( $r ) ) {
				return;
			}

			add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 15 );
			add_filter( 'wp_title', array( __CLASS__, 'filter_wp_title' ), 15 ); // Themes without title-tag support.
			add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 20 );
			// "Hide search results from search engines" off: core adds noindex to search pages by itself.
			if ( is_search() && ! Uich_SEO_Settings::get( 'noindex_search', true ) ) {
				remove_filter( 'wp_robots', 'wp_robots_noindex_search' );
			}
			add_action( 'wp_head', array( __CLASS__, 'print_head' ), 1 );
			// Jetpack prints its own Open Graph / Twitter tags: one set per page.
			add_filter( 'jetpack_enable_open_graph', '__return_false' );

			if ( '' !== (string) $r['canonical']['value'] ) {
				remove_action( 'wp_head', 'rel_canonical' );
			}
		}

		/**
		 * Does the module print the head tags on this front-end request? (We own
		 * the head, the request resolves and its content type has SEO on.) When
		 * it does not, SEO tags in site / page head code are printed as written.
		 *
		 * @return bool
		 */
		public static function emits_for_request() {
			if ( is_admin() || ! did_action( 'wp' ) || ! Uich_SEO_Ownership::we_emit() ) {
				return false;
			}
			$r = Uich_SEO_Resolver::for_request();
			return $r && self::should_emit( $r );
		}

		/**
		 * Is this object one we handle?
		 *
		 * @param array $r Resolved.
		 * @return bool
		 */
		public static function should_emit( array $r ) {
			if ( 'post' === $r['object']['kind'] ) {
				$post = get_post( $r['object']['id'] );
				if ( ! $post || ! empty( $r['is_draft'] ) ) {
					return false;
				}
				$row = Uich_SEO_Settings::type_row( $post->post_type );
				return (bool) $row['seo'];
			}
			if ( 'term' === $r['object']['kind'] ) {
				$term = get_term( $r['object']['id'] );
				if ( $term && ! is_wp_error( $term ) ) {
					$row = Uich_SEO_Settings::taxonomy_row( $term->taxonomy );
					return (bool) $row['seo'];
				}
			}
			return true;
		}

		/**
		 * `pre_get_document_title`. WordPress prints a value returned here as is,
		 * so it is escaped here. The resolved title is plain text (entities
		 * decoded), so esc_html() encodes "Tom & Jerry" exactly once.
		 *
		 * @param string $title Incoming.
		 * @return string
		 */
		public static function filter_title( $title ) {
			$r = Uich_SEO_Resolver::for_request();
			if ( $r && empty( $r['bare'] ) && '' !== (string) $r['title']['value'] ) {
				return esc_html( trim( wp_strip_all_tags( (string) $r['title']['value'] ) ) );
			}
			return $title;
		}

		/**
		 * `wp_title` for legacy themes.
		 *
		 * @param string $title Incoming.
		 * @return string
		 */
		public static function filter_wp_title( $title ) {
			$ours = self::filter_title( '' );
			return '' !== $ours ? $ours : $title; // Already escaped by filter_title().
		}

		/**
		 * `wp_robots` — merge ours into core's single robots meta.
		 *
		 * @param array $robots Directives.
		 * @return array
		 */
		public static function filter_robots( $robots ) {
			$r = Uich_SEO_Resolver::for_request();
			if ( ! $r ) {
				return $robots;
			}
			return array_merge( (array) $robots, self::robots_directives( $r['robots'] ) );
		}

		/**
		 * Robots array → wp_robots directives.
		 *
		 * @param array $rb Resolved robots.
		 * @return array
		 */
		public static function robots_directives( array $rb ) {
			$d = array();
			if ( empty( $rb['index'] ) ) {
				$d['noindex'] = true;
			}
			if ( empty( $rb['follow'] ) ) {
				$d['nofollow'] = true;
			}
			foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $f ) {
				if ( ! empty( $rb[ $f ] ) ) {
					$d[ $f ] = true;
				}
			}
			if ( isset( $rb['max_snippet'] ) ) {
				$d['max-snippet'] = (string) (int) $rb['max_snippet'];
			}
			if ( isset( $rb['max_image_preview'] ) ) {
				$d['max-image-preview'] = (string) $rb['max_image_preview'];
			}
			return $d;
		}

		/**
		 * Robots meta content string (for previews).
		 *
		 * @param array $rb Resolved robots.
		 * @return string
		 */
		public static function robots_line( array $rb ) {
			$d   = array_merge( array( 'max-image-preview' => 'large' ), self::robots_directives( $rb ) );
			$out = array();
			foreach ( $d as $k => $v ) {
				$out[] = true === $v ? $k : $k . ':' . $v;
			}
			return implode( ', ', $out );
		}

		/**
		 * `wp_head` @1.
		 *
		 * @return void
		 */
		public static function print_head() {
			$r = Uich_SEO_Resolver::for_request();
			if ( $r ) {
				echo self::block( $r, array( 'front' => is_front_page() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside block().
			}
		}

		/**
		 * The head block.
		 *
		 * @param array $r    Resolved values.
		 * @param array $opts { front: bool } — verification tags only on the front page.
		 * @return string
		 */
		public static function block( array $r, array $opts = array() ) {
			$og      = (bool) Uich_SEO_Settings::get( 'og_enabled', true );
			$tw      = (bool) Uich_SEO_Settings::get( 'twitter_enabled', true );
			$lines   = array();
			$desc    = (string) $r['description']['value'];
			$canon   = (string) $r['canonical']['value'];
			$s_title = (string) $r['social_title']['value'];
			$s_desc  = (string) $r['social_description']['value'];
			$img     = isset( $r['share_image']['value'] ) && is_array( $r['share_image']['value'] ) ? $r['share_image']['value'] : null;

			if ( '' !== $desc ) {
				$lines[] = self::meta( 'name', 'description', $desc );
			}
			if ( '' !== $canon ) {
				$lines[] = '<link rel="canonical" href="' . esc_url( $canon ) . '" />';
			}
			if ( ! empty( $r['prev'] ) ) {
				$lines[] = '<link rel="prev" href="' . esc_url( $r['prev'] ) . '" />';
			}
			if ( ! empty( $r['next'] ) ) {
				$lines[] = '<link rel="next" href="' . esc_url( $r['next'] ) . '" />';
			}

			if ( ! empty( $opts['front'] ) ) {
				$verify = array(
					'verify_google'    => 'google-site-verification',
					'verify_bing'      => 'msvalidate.01',
					'verify_yandex'    => 'yandex-verification',
					'verify_baidu'     => 'baidu-site-verification',
					'verify_pinterest' => 'p:domain_verify',
				);
				foreach ( $verify as $key => $name ) {
					$code = trim( (string) Uich_SEO_Settings::get( $key, '' ) );
					if ( '' !== $code ) {
						$lines[] = self::meta( 'name', $name, $code );
					}
				}
			}

			if ( $og && empty( $r['bare'] ) ) {
				$lines[] = self::meta( 'property', 'og:locale', (string) $r['og_locale']['value'] );
				if ( '' !== (string) $r['og_type'] ) {
					$lines[] = self::meta( 'property', 'og:type', (string) $r['og_type'] );
				}
				if ( '' !== $s_title ) {
					$lines[] = self::meta( 'property', 'og:title', $s_title );
				}
				if ( '' !== $s_desc ) {
					$lines[] = self::meta( 'property', 'og:description', $s_desc );
				}
				if ( '' !== $canon ) {
					$lines[] = self::meta( 'property', 'og:url', $canon, true ); // og:url always follows the canonical.
				}
				if ( '' !== (string) $r['site_name'] ) {
					$lines[] = self::meta( 'property', 'og:site_name', (string) $r['site_name'] );
				}
				if ( ! empty( $r['article'] ) ) {
					$lines[] = self::meta( 'property', 'article:published_time', (string) $r['article']['published'] );
					$lines[] = self::meta( 'property', 'article:modified_time', (string) $r['article']['modified'] );
				}
				if ( $img && ! empty( $img['url'] ) ) {
					$lines[] = self::meta( 'property', 'og:image', (string) $img['url'], true );
					if ( ! empty( $img['w'] ) && ! empty( $img['h'] ) ) {
						$lines[] = self::meta( 'property', 'og:image:width', (string) (int) $img['w'] );
						$lines[] = self::meta( 'property', 'og:image:height', (string) (int) $img['h'] );
					}
					if ( ! empty( $img['alt'] ) ) {
						$lines[] = self::meta( 'property', 'og:image:alt', (string) $img['alt'] );
					}
				}
			}

			if ( $tw && empty( $r['bare'] ) ) {
				$card    = 'small' === Uich_SEO_Settings::get( 'twitter_card', 'large' ) ? 'summary' : 'summary_large_image';
				$lines[] = self::meta( 'name', 'twitter:card', $card );
				if ( '' !== $s_title ) {
					$lines[] = self::meta( 'name', 'twitter:title', $s_title );
				}
				if ( '' !== $s_desc ) {
					$lines[] = self::meta( 'name', 'twitter:description', $s_desc );
				}
				if ( $img && ! empty( $img['url'] ) ) {
					$lines[] = self::meta( 'name', 'twitter:image', (string) $img['url'], true );
				}
				$handle = trim( (string) Uich_SEO_Settings::get( 'twitter_handle', '' ) );
				if ( '' !== $handle ) {
					$lines[] = self::meta( 'name', 'twitter:site', $handle );
				}
			}

			/**
			 * Extra head lines (the schema graph registers here).
			 *
			 * @param string[] $extra Lines, already escaped.
			 * @param array    $r     Resolved values.
			 */
			$extra = (array) apply_filters( 'uichemy_seo_head_extra', array(), $r );
			$lines = array_merge( $lines, $extra );

			if ( empty( $lines ) ) {
				return '';
			}
			return self::MARK_OPEN . "\n" . implode( "\n", $lines ) . "\n" . self::MARK_CLOSE . "\n";
		}

		/**
		 * One meta tag.
		 *
		 * @param string $attr    'name' | 'property'.
		 * @param string $key     Key.
		 * @param string $content Content.
		 * @param bool   $is_url  Escape as URL.
		 * @return string
		 */
		private static function meta( $attr, $key, $content, $is_url = false ) {
			return '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . ( $is_url ? esc_url( $content ) : esc_attr( $content ) ) . '" />';
		}

		/**
		 * Full preview for a post as if it were the request (MCP `preview-head`,
		 * panel). Same block() as the live head.
		 *
		 * @param int $post_id Post id.
		 * @return array{title:string,robots:string,head:string,emits:bool,why:string}
		 */
		public static function preview_for_post( $post_id ) {
			$r = Uich_SEO_Resolver::for_post( $post_id );
			if ( ! $r ) {
				return array(
					'title'  => '',
					'robots' => '',
					'head'   => '',
					'emits'  => false,
					'why'    => 'That page does not exist.',
				);
			}
			$is_front = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post_id;
			$own      = Uich_SEO_Ownership::resolve();
			$why      = '';
			if ( 'uichemy' !== $own['owner'] ) {
				$why = Uich_SEO_Ownership::status_text();
			} elseif ( ! empty( $r['is_draft'] ) ) {
				$why = __( 'Nothing is published until this page goes live.', 'uichemy' );
			} elseif ( ! self::should_emit( $r ) ) {
				$why = __( 'SEO is switched off for this type of content.', 'uichemy' );
			}
			return array(
				'title'  => (string) $r['title']['value'],
				'robots' => self::robots_line( $r['robots'] ),
				'head'   => self::block( $r, array( 'front' => $is_front ) ),
				'emits'  => '' === $why,
				'why'    => $why,
			);
		}
	}
}
