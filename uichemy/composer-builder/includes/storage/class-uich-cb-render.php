<?php
/**
 * Uich_CB_Render — turn a builder document into HTML.
 *
 * ONE RENDERER, and it is not this class. The markup comes from
 * UiChemy_Composer_Renderer, the same method the Composer widget and the
 * Gutenberg block render through, because a builder that assembles its own HTML
 * is a builder whose canvas quietly stops matching the visitor's page. This
 * class only decides WHEN to render and what to wrap the result in.
 *
 * Three callers, one method:
 *   - the front end, through `the_content` (what a visitor sees)
 *   - the canvas iframe, which is the front end with a bridge script
 *   - REST /render, which renders sections the editor has not saved yet
 *
 * The wrapper matters as much as the markup. `.uipb-page` and `[data-uipb-sec]`
 * are what the canvas bridge (src/editor/canvas.js) selects on to report
 * geometry, hover and selection — render without them and the canvas loads but
 * nothing in it can be clicked.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Render' ) ) {

	/**
	 * Renders builder documents on the front end.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Render {

		/**
		 * Whether render_section() resolves Twig tokens. True only while
		 * render_page() runs - the visitor-facing render.
		 *
		 * @var bool
		 */
		private static $resolve_dynamic = false;

		/**
		 * Page scripts deferred to the footer, after the libraries they use.
		 *
		 * Keyed by a hash of the code rather than the section id: every document
		 * renders as the one section `uich-cb-page`, so a header template and the
		 * page it sits on share that id.
		 *
		 * @var array<string,string>
		 */
		private static $footer_scripts = array();

		/**
		 * Posts render_page() is rendering right now, keyed by id.
		 *
		 * A document's own Twig can ask for itself: `{{ post.content }}` runs
		 * `the_content`, and `{{ post.excerpt }}` does too when the excerpt is
		 * empty. With that post in the loop, maybe_render() answered by
		 * rendering the same document again, which asked again — until PHP ran
		 * out of memory. The canvas of a single template hits it every time,
		 * because there the post in scope IS the template.
		 *
		 * @var array<int,true>
		 */
		private static $rendering = array();

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			// Priority 20: late, so a theme or another builder that legitimately
			// owns the content has already had its say.
			add_filter( 'the_content', array( __CLASS__, 'maybe_render' ), 20 );

			// The storage layer asks for HTML whenever a document is saved or a
			// revision restored; answering here is what keeps post_content and
			// the AST describing the same page. See Uich_CB_WPContent.
			add_filter( 'uich_cb/render_cb_content', array( __CLASS__, 'render_cb_content' ), 10, 3 );

			// The page's own head / body-end code. Late, so it lands just before
			// </head> and </body> — after the theme's and other plugins' output,
			// which is what "before head end" means to the person who wrote it.
			add_action( 'wp_head', array( __CLASS__, 'print_head_css' ), self::CSS_PRIORITY );
			add_action( 'wp_head', array( __CLASS__, 'print_head_code' ), self::END_PRIORITY );
			add_action( 'wp_footer', array( __CLASS__, 'print_body_code' ), self::END_PRIORITY );
		}

		/**
		 * Hook priority for the head / body-end code.
		 */
		const END_PRIORITY = 9999;

		/**
		 * Hook priority for the sections' CSS in <head>: after the enqueued
		 * stylesheets (wp_print_styles, 8), so the page still wins a tie with
		 * the theme as it did inline, and before Additional CSS (101).
		 */
		const CSS_PRIORITY = 99;

		/**
		 * Sections whose CSS print_head_css() already put in <head>, by post
		 * and section id (every document's one section is `uich-cb-page`).
		 *
		 * render_section() leaves their inline <style> out, so the CSS is on the
		 * page once, before the first paint, not after each section's markup.
		 *
		 * @var array<int,array<string,true>>
		 */
		private static $css_in_head = array();

		/**
		 * The head a block theme's own pages get, for UiChemy's full-document
		 * views (Canvas, Full width, Theme Builder full page).
		 *
		 * Core prints a block theme's <title> from locate_block_template(),
		 * which is skipped when a plugin view replaces the template: block
		 * themes declare no `title-tag` support, so the page had no <title> at
		 * all. Called before wp_head(); the same two hooks core adds. Nothing
		 * changes on a classic theme, whose own title-tag support stands.
		 *
		 * @param bool $viewport Also print the viewport meta tag (for a view
		 *                       that does not print its own).
		 * @return void
		 */
		public static function block_theme_head( $viewport = false ) {
			if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
				return;
			}
			if ( ! has_action( 'wp_head', '_block_template_render_title_tag' ) ) {
				remove_action( 'wp_head', '_wp_render_title_tag', 1 );
				add_action( 'wp_head', '_block_template_render_title_tag', 1 );
			}
			if ( $viewport && ! has_action( 'wp_head', '_block_template_viewport_meta_tag' ) ) {
				add_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
			}
		}

		/**
		 * Is the Composer renderer available?
		 *
		 * It ships with this plugin (uichemy-composer/), but the merged runtime
		 * stands down when a standalone UiChemy is active — see
		 * Uich_Composer_Loader — so it is not unconditional.
		 *
		 * @return bool
		 */
		public static function renderer_available() {
			return class_exists( 'UiChemy_Composer_Renderer' );
		}

		/**
		 * Replace post content with the builder document, when there is one.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function maybe_render( $content ) {
			if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
				return $content;
			}

			$post_id = (int) get_the_ID();

			// The document asking for its own content, from inside its own
			// render (see $rendering). A page cannot contain itself, so the
			// answer is nothing — not $content, which is the document's own
			// post_content mirror with its Twig tokens still raw.
			if ( isset( self::$rendering[ $post_id ] ) ) {
				return '';
			}

			// Inside another document's render: its `{{ post.content }}` asking
			// for a different post's content — a single template on its canvas,
			// previewing against a real post (see preview_post_id()). That post
			// is embedded, not edited: no canvas wrapper, no bridge markers.
			$nested = ! empty( self::$rendering );

			// The editor's canvas always gets the page wrapper, even for a page
			// with nothing in it yet. The canvas bridge puts sections INTO
			// `.uipb-page`; without one, the first section added to a new page had
			// nowhere to go and the canvas stayed blank until the editor reloaded.
			$is_canvas = ! $nested && $post_id && class_exists( 'Uich_CB_Canvas' ) && Uich_CB_Canvas::is_canvas()
				&& class_exists( 'Uich_CB_Policy' ) && Uich_CB_Policy::can_edit( $post_id );

			if ( ! $post_id || ( ! $is_canvas && ! Uich_CB_Content::is_enabled( $post_id ) ) ) {
				return $content;
			}

			// A password-protected post arrives here as WordPress's password
			// form, not its content. Rendering the document in its place would
			// show the page to anyone without the password.
			if ( post_password_required( $post_id ) ) {
				return $content;
			}

			if ( ! self::renderer_available() ) {
				// Loud for an editor, silent for a visitor: a blank page with no
				// explanation is the worst of both.
				return current_user_can( 'edit_post', $post_id )
					? '<!-- UiChemy Builder: the Composer renderer is unavailable, so this page cannot render. -->' . $content
					: $content;
			}

			$html = self::render_page( $post_id, ! $nested );

			if ( '' === $html && $is_canvas ) {
				return '<div class="uipb-page" data-uipb-page="' . esc_attr( (string) (int) $post_id ) . '"></div>';
			}

			if ( '' === $html ) {
				return $content;
			}

			return $is_canvas ? $html : self::content_filters( $html );
		}

		/**
		 * What WordPress does to post content, done to the builder's output.
		 *
		 * The document replaces the_content at priority 20, after core's own
		 * filters have run on post_content, so shortcodes printed as text
		 * (C-03), embed URLs stayed links (C-04), images had no srcset, sizes
		 * or lazy loading (C-07) and <!--nextpage--> showed every page at once
		 * (C-05). Not on the canvas: the editor edits the markup as written.
		 *
		 * @param string $html Rendered document.
		 * @return string
		 */
		private static function content_filters( $html ) {
			/**
			 * Filter whether the builder's output gets core's content filters
			 * (embeds, shortcodes, image attributes, page breaks).
			 *
			 * @param bool $run Default true.
			 */
			if ( ! apply_filters( 'uich_cb/content_filters', true ) ) {
				return $html;
			}

			// <!--nextpage-->: only the current page's part, as setup_postdata() would.
			if ( false !== strpos( $html, '<!--nextpage-->' ) ) {
				$parts = explode( '<!--nextpage-->', $html );
				$page  = max( 1, (int) get_query_var( 'page' ) );
				$html  = isset( $parts[ $page - 1 ] ) ? $parts[ $page - 1 ] : end( $parts );
			}

			if ( isset( $GLOBALS['wp_embed'] ) && $GLOBALS['wp_embed'] instanceof WP_Embed ) {
				$html = $GLOBALS['wp_embed']->run_shortcode( $html );
				$html = $GLOBALS['wp_embed']->autoembed( $html );
			}
			$html = do_shortcode( $html );

			if ( function_exists( 'wp_filter_content_tags' ) ) {
				$html = wp_filter_content_tags( $html, 'the_content' );
			}

			return $html;
		}

		/**
		 * Is this request previewing work on THIS post that is not live yet?
		 *
		 * Only the post the request is showing. `is_preview()` is about the
		 * queried post, and WordPress also sets it whenever an editor opens a
		 * draft — so without the id check a Theme Builder header embedded in
		 * any draft page (its canvas included) rendered the header's autosave
		 * instead of the header as published.
		 *
		 * @param int $post_id Post being rendered.
		 * @return bool
		 */
		private static function is_preview_request( $post_id ) {
			$post_id = (int) $post_id;

			return is_preview()
				&& (int) get_queried_object_id() === $post_id
				&& current_user_can( 'edit_post', $post_id );
		}

		/**
		 * The whole page, wrapped for the canvas bridge.
		 *
		 * `$markers` false drops the bridge's hooks (`.uipb-page`,
		 * `data-uipb-sec`) for a document rendered INSIDE another one — a Theme
		 * Builder header or footer. On a canvas those would be picked up as the
		 * page being edited: the bridge takes the first `.uipb-page` it finds,
		 * and the header comes first.
		 *
		 * @param int  $post_id Post ID.
		 * @param bool $markers Whether to emit the canvas-bridge markers.
		 * @return string
		 */
		public static function render_page( $post_id, $markers = true ) {
			$post_id = (int) $post_id;

			// Already rendering this post further up the stack: stop here, for
			// every caller (the_content, a Theme Builder header or footer).
			if ( isset( self::$rendering[ $post_id ] ) ) {
				return '';
			}

			$render = static function () use ( $post_id, $markers ) {
				// The one path that renders for a visitor, with the real post in
				// scope - so the one path where Twig tokens are resolved.
				$previous              = self::$resolve_dynamic;
				self::$resolve_dynamic = true;

				self::$rendering[ $post_id ] = true;

				try {
					$document = self::page_cb_content( $post_id );
					return self::render_sections(
						Uich_CB_Content::to_editor_sections( $document ),
						$post_id,
						false,
						$markers,
						$document['settings']
					);
				} finally {
					unset( self::$rendering[ $post_id ] );
					self::$resolve_dynamic = $previous;
				}
			};

			// The document is its own post in scope — a Theme Builder single
			// template opened on its canvas. Its `{{ post.* }}` tokens are
			// written for the post it will be shown on, not for the template, so
			// render them against the post it previews with instead.
			if ( (int) get_the_ID() === $post_id && self::preview_post_id( $post_id ) !== $post_id ) {
				return self::with_post_context( $post_id, $render );
			}

			return $render();
		}

		/**
		 * The post a document's dynamic tokens preview against in the editor.
		 *
		 * Normally the document itself: a page's `{{ post.title }}` is its own
		 * title. Not for a Theme Builder single template — it IS a post, but
		 * `post` in it means the attorney, article or product it will be shown
		 * on, so on its own canvas every `{{ post.* }}` was empty or showed the
		 * template's name. The Theme Builder knows which post a template is for
		 * and answers through the filter; the builder only asks.
		 *
		 * @param int $document_id Builder document (post) ID.
		 * @return int Post ID to put in scope; the document when nothing better.
		 */
		public static function preview_post_id( $document_id ) {
			$document_id = (int) $document_id;

			/**
			 * Filter the post a builder document's dynamic tokens preview against.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id     Post ID to put in scope. Defaults to the document.
			 * @param int $document_id Builder document ID.
			 */
			$post_id = (int) apply_filters( 'uich_cb/preview_post_id', $document_id, $document_id );

			return ( $post_id && get_post( $post_id ) instanceof WP_Post ) ? $post_id : $document_id;
		}

		/**
		 * The document a request should show for a post: normally the stored
		 * one, and on a preview the AUTOSAVE if there is one.
		 *
		 * Without the preview half the editor had no way to look at unsaved
		 * work: its Preview saved the document to the live post first, so the
		 * act of previewing pushed the changes to every visitor. Publishing is
		 * the only thing that should do that.
		 *
		 * One method for the page body and its head / body-end code, so a
		 * preview never shows new markup with old head code.
		 *
		 * @param int $post_id Post ID.
		 * @return array Normalised document.
		 */
		public static function page_cb_content( $post_id ) {
			$post_id = (int) $post_id;

			if ( self::is_preview_request( $post_id ) ) {
				$preview = Uich_CB_Revisions::get_preview_cb_content( $post_id );

				if ( null !== $preview ) {
					return Uich_CB_Content::normalize( $preview );
				}
			}

			return Uich_CB_Content::get_cb_content( $post_id );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Head / body-end code
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * The builder page this front-end request is showing, or 0.
		 *
		 * Same gates as the page body (maybe_render): a singular builder page,
		 * and not a password-protected one the visitor has not unlocked — its
		 * code is part of the page, so it stays behind the password too.
		 *
		 * @return int
		 */
		private static function current_page_id() {
			if ( is_admin() || ! is_singular() ) {
				return 0;
			}

			$post_id = (int) get_queried_object_id();

			if ( ! $post_id || ! Uich_CB_Content::is_enabled( $post_id ) || post_password_required( $post_id ) ) {
				return 0;
			}

			return $post_id;
		}

		/**
		 * Print the sections' CSS in <head>, for a visitor's page.
		 *
		 * The page and every builder document on it (a Theme Builder header or
		 * footer — page_code_sources()), each section's CSS scoped exactly as the
		 * renderer scopes it inline. Not on the editor's canvas: there sections
		 * are added and restyled live, and their CSS goes with them.
		 *
		 * @return void
		 */
		public static function print_head_css() {
			if ( is_admin() || ! self::renderer_available() || ! method_exists( 'UiChemy_Composer_Renderer', 'scope_css_for_scope' ) ) {
				return;
			}

			if ( class_exists( 'Uich_CB_Canvas' ) && Uich_CB_Canvas::is_canvas() ) {
				return;
			}

			foreach ( self::page_code_sources() as $post_id ) {
				$sections = Uich_CB_Content::to_editor_sections( self::page_cb_content( $post_id ) );

				foreach ( (array) $sections as $section ) {
					$uid = is_array( $section ) && isset( $section['id'] ) ? sanitize_key( $section['id'] ) : '';
					$css = '' !== $uid && isset( $section['settings']['raw_css'] ) ? (string) $section['settings']['raw_css'] : '';

					if ( '' === trim( $css ) || isset( self::$css_in_head[ $post_id ][ $uid ] ) ) {
						continue;
					}

					$scoped = UiChemy_Composer_Renderer::scope_css_for_scope( $css, '.uichemy-composer-' . $uid );

					if ( '' === trim( (string) $scoped ) ) {
						continue;
					}

					self::$css_in_head[ $post_id ][ $uid ] = true;
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder CSS, scoped by the shared Composer renderer as it prints it inline.
					echo "\n" . '<style id="uich-cb-css-' . esc_attr( $post_id . '-' . $uid ) . '">' . $scoped . '</style>';
				}
			}
		}

		/**
		 * Print the page's code for the end of <head>.
		 *
		 * @return void
		 */
		public static function print_head_code() {
			self::print_page_code( 'before_head_end_html' );
		}

		/**
		 * Print the page's code for the end of <body>.
		 *
		 * @return void
		 */
		public static function print_body_code() {
			self::print_page_code( 'before_body_end_html' );
		}

		/**
		 * Every builder post whose head / body-end code belongs on this request.
		 *
		 * The page being viewed, plus whatever else renders on it — a Theme
		 * Builder header, footer or full-page template built in the builder.
		 * The builder does not decide which templates render where (the Theme
		 * Builder does), so those arrive through the filter; this class only
		 * keeps the ones that are builder documents and prints them in order.
		 *
		 * Order: the filtered sources first, the page LAST — the page is the
		 * most specific thing on it, so its code gets the final word (a later
		 * <style> wins a tie, a later script sees what the earlier ones set).
		 *
		 * @return int[]
		 */
		public static function page_code_sources() {
			$page = self::current_page_id();

			/**
			 * Filter the posts whose head / body-end code prints on this request,
			 * besides the page itself.
			 *
			 * @since 5.2.1
			 *
			 * @param int[] $post_ids Post IDs, in print order.
			 * @param int   $page_id  The builder page being viewed, or 0.
			 */
			$sources = (array) apply_filters( 'uich_cb/page_code_sources', array(), $page );

			if ( $page ) {
				$sources[] = $page;
			}

			$out = array();
			foreach ( $sources as $id ) {
				$id = (int) $id;
				// Once each, first position kept; builder documents only.
				if ( $id && ! in_array( $id, $out, true ) && Uich_CB_Content::is_enabled( $id ) ) {
					$out[] = $id;
				}
			}

			return $out;
		}

		/**
		 * Print one of the end-of-element code fields, for every source.
		 *
		 * Printed as stored. It was filtered on the way IN, for the user who
		 * saved it (Uich_CB_Code_Policy): code from someone allowed to author
		 * it is theirs to run, and nobody else's reaches storage unfiltered.
		 *
		 * @param string $field Content field.
		 * @return void
		 */
		private static function print_page_code( $field ) {
			if ( is_admin() ) {
				return;
			}

			foreach ( self::page_code_sources() as $post_id ) {
				$document = self::page_cb_content( $post_id );
				$code     = isset( $document['content'][ $field ] ) ? (string) $document['content'][ $field ] : '';

				// The same filter every other Composer page-code printer applies:
				// the SEO module takes its tags out, because it prints them itself
				// (once, rendered) or leaves the head to Yoast / Rank Math (F-01,
				// F-03).
				/** This filter is documented in uichemy-composer/includes/admin/class-uichemy-composer-manager.php */
				$code = (string) apply_filters( 'uichemy_custom_code_output', $code, 'page', 'before_head_end_html' === $field ? 'head' : 'body_end' );

				if ( '' === trim( $code ) ) {
					continue;
				}

				// Head code is a template like the page body: its {{ }} tokens are
				// rendered for the page being viewed, with the trust of the
				// document they belong to (F-02).
				if ( class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( $code ) ) {
					$code = (string) Uich_Dynamic::render_authored( $code, array(), (int) $post_id );
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Policy-checked at storage; see the docblock.
				echo "\n" . $code . "\n";
			}
		}

		/**
		 * Answer Uich_CB_WPContent's render request.
		 *
		 * @param string $html     HTML so far ('' from the default).
		 * @param array  $document The builder AST.
		 * @param int    $post_id  Post the document belongs to.
		 * @return string
		 */
		public static function render_cb_content( $html, $document, $post_id ) {
			if ( ! self::renderer_available() ) {
				return $html;
			}

			// to_editor_sections() is the one place that knows how the stored
			// shape maps to renderable sections, so go through it rather than
			// reading `content` here and having two answers to that question.
			$document = Uich_CB_Content::normalize( $document );
			$sections = Uich_CB_Content::to_editor_sections( $document );

			if ( empty( $sections ) ) {
				return $html;
			}

			return self::render_sections( $sections, (int) $post_id, false, true, $document['settings'] );
		}

		/**
		 * Page-only styles; each CSS value stays inside its own declaration.
		 *
		 * @param array $settings Page document settings.
		 * @return string Safe inline styles, or empty for the theme defaults.
		 */
		public static function appearance_style( $settings ) {
			$appearance = isset( $settings['appearance'] ) && is_array( $settings['appearance'] ) ? $settings['appearance'] : array();
			$styles     = array();
			$background = isset( $appearance['background'] ) && is_string( $appearance['background'] ) ? trim( $appearance['background'] ) : '';
			if ( '' !== $background && strlen( $background ) <= 250 && ! preg_match( '/[;{}<>]/', $background ) ) {
				$background_style = safecss_filter_attr( 'background-color:' . $background );
				if ( '' !== $background_style ) {
					$styles[] = $background_style;
				}
			}
			$width = isset( $appearance['contentWidth'] ) ? $appearance['contentWidth'] : '';
			if ( 'narrow' === $width ) {
				$styles[] = 'width:100%;max-width:960px;margin-inline:auto';
			} elseif ( 'full' === $width ) {
				$styles[] = 'width:100%;max-width:none';
			}
			return implode( ';', array_filter( $styles ) );
		}

		/**
		 * Render a list of editor sections into the page wrapper.
		 *
		 * @param array $sections  Editor-shaped sections ({id, settings}).
		 * @param int   $post_id   Post they belong to.
		 * @param bool  $is_editor Editor mode — see render_section().
		 * @param bool  $markers   Canvas-bridge markers — see render_page().
		 * @param array $settings  Page-level appearance, from the same document.
		 * @return string
		 */
		public static function render_sections( $sections, $post_id = 0, $is_editor = false, $markers = true, $settings = array() ) {
			$appearance = self::appearance_style( $settings );
			$style_attr = '' !== $appearance ? ' style="' . esc_attr( $appearance ) . '"' : '';
			if ( ! is_array( $sections ) || empty( $sections ) ) {
				if ( $markers && '' !== $style_attr ) {
					return '<div class="uipb-page" data-uipb-page="' . esc_attr( (string) (int) $post_id ) . '"' . $style_attr . '></div>';
				}
				return '';
			}

			$out = '';

			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) || empty( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
					continue;
				}

				$uid = isset( $section['id'] ) ? sanitize_key( $section['id'] ) : '';

				if ( '' === $uid ) {
					continue;
				}

				$out .= self::render_section( $uid, $section['settings'], $is_editor, $markers, $post_id );
			}

			if ( '' === $out ) {
				return '';
			}

			if ( ! $markers ) {
				return '<div class="uipb-embed" data-uipb-embed="' . esc_attr( (string) (int) $post_id ) . '"' . $style_attr . '>' . $out . '</div>';
			}

			return '<div class="uipb-page" data-uipb-page="' . esc_attr( (string) (int) $post_id ) . '"' . $style_attr . '>' . $out . '</div>';
		}

		/**
		 * One section, wrapped exactly as the canvas bridge expects.
		 *
		 * `$is_editor` is passed straight through to the Composer renderer, and
		 * the difference is real: in editor mode it ships the section's CSS as a
		 * script that appends a <style> to document.head, which is right for a
		 * live canvas and useless anywhere scripts do not run.
		 *
		 * @param string $uid       Section id.
		 * @param array  $settings  Composer settings map (raw_html / raw_css / raw_js …).
		 * @param bool   $is_editor Editor mode.
		 * @param bool   $markers   Canvas-bridge marker — see render_page().
		 * @param int    $post_id   Post the section belongs to (0 when unknown).
		 * @return string
		 */
		public static function render_section( $uid, $settings, $is_editor = false, $markers = true, $post_id = 0 ) {
			if ( ! self::renderer_available() ) {
				return '';
			}

			// Layer-path stamps for the editor (the canvas, and the live /render
			// patches), put on raw_html BEFORE Twig runs — see stamp_layer_paths().
			$is_canvas = class_exists( 'Uich_CB_Canvas' ) && Uich_CB_Canvas::is_canvas();
			$stamped   = false;
			if ( $markers && ( $is_canvas || $is_editor ) && ! empty( $settings['raw_html'] ) ) {
				$with_stamps = self::stamp_layer_paths( (string) $settings['raw_html'] );
				if ( '' !== $with_stamps ) {
					$settings['raw_html'] = $with_stamps;
					$stamped              = true;
				}
			}

			// Dynamic data: run the Twig engine over the markup first, so
			// {{ values }}, {% for %} loops and {% if %} conditions resolve before
			// the renderer scopes the result - the same order the Gutenberg block,
			// the Bricks element and the Elementor widget use. Only where a post
			// is in scope: render_page() (the visitor's page and the canvas) and
			// with_post_context() (the editor's live /render). The post_content
			// mirror runs with neither, so it keeps the tokens instead of freezing
			// an empty value into the post.
			if ( self::$resolve_dynamic && isset( $settings['raw_html'] ) && class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( (string) $settings['raw_html'] ) ) {
				// The editor shows an empty loop's card as designed (Uich_Twig::$preview_empty_loops).
				$preview_loops = class_exists( 'Uich_Twig' ) && $markers && ( $is_canvas || $is_editor );
				if ( $preview_loops ) {
					Uich_Twig::$preview_empty_loops = true;
				}
				try {
					// Same trust rule as the block, Bricks and the Elementor widget:
					// the full engine only when the document's author holds
					// unfiltered_html, otherwise the untrusted render.
					$settings['raw_html'] = Uich_Dynamic::render_authored( (string) $settings['raw_html'], array( 'widget_id' => $uid ), (int) $post_id );
				} finally {
					if ( $preview_loops ) {
						Uich_Twig::$preview_empty_loops = false;
					}
				}

				// Position-indexed slots only line up with static HTML; a loop or
				// condition changes the node count, so they would clobber it.
				foreach ( array_keys( $settings ) as $key ) {
					if ( 0 === strpos( (string) $key, 'slot_' ) ) {
						unset( $settings[ $key ] );
					}
				}
			}

			// The post_content mirror (no post in scope, not the editor): the
			// tokens are taken out rather than frozen. Search results, feeds,
			// excerpts and the blog index read post_content, and printed them
			// raw — `{{ post.title }}` in an RSS description (C-06).
			if ( ! self::$resolve_dynamic && ! $is_editor && isset( $settings['raw_html'] ) && class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( (string) $settings['raw_html'] ) ) {
				$settings['raw_html'] = (string) preg_replace( '/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', '', (string) $settings['raw_html'] );
			}

			// GSAP and three.js load on demand, as they do for the Elementor
			// widget - but the renderer prints a document's js inline, where it
			// would run before a footer library exists. So when the js uses one,
			// the library is enqueued and the js moves to the footer after it.
			if ( self::$resolve_dynamic && ! $is_editor && ! empty( $settings['raw_js'] ) && self::defer_script_with_libraries( (string) $settings['raw_js'] ) ) {
				unset( $settings['raw_js'] );
			}

			// Its CSS is already in <head> (print_head_css()): not again inline.
			if ( self::$resolve_dynamic && ! $is_editor && ! $is_canvas && isset( self::$css_in_head[ (int) $post_id ][ $uid ] ) ) {
				unset( $settings['raw_css'] );
			}

			$scope = 'uichemy-composer-' . $uid;
			// A form inside a template is configured on the template, not on the
			// page it shows up on.
			$prev_cfg = UiChemy_Composer_Renderer::$form_config_post;
			if ( $post_id && class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === get_post_type( (int) $post_id ) ) {
				UiChemy_Composer_Renderer::$form_config_post = (int) $post_id;
			}
			try {
				$html = UiChemy_Composer_Renderer::render_html( $settings, '.' . $scope, $uid, (bool) $is_editor );
			} finally {
				UiChemy_Composer_Renderer::$form_config_post = $prev_cfg;
			}

			// The scope class stays either way: the section's CSS is written
			// against it.
			if ( ! $markers ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder output from the shared Composer renderer, as the widget and block emit it.
				return '<div class="' . esc_attr( $scope ) . '">' . $html . '</div>';
			}

			if ( $is_canvas && ! $stamped ) {
				// Markup stamp_layer_paths() could not read: stamped in the browser
				// instead, by counting the rendered elements. Ahead of the section's own scripts (the renderer prints them after
				// the markup), so the stamps are on before any of them runs.
				$stamp = self::layer_path_stamp_script();
				$html  = preg_match( '#(?:\s*<script\b[^>]*>.*?</script>)+\s*$#is', $html, $m, PREG_OFFSET_CAPTURE )
					? substr( $html, 0, $m[0][1] ) . $stamp . substr( $html, $m[0][1] )
					: $html . $stamp;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Builder output from the shared Composer renderer, as the widget and block emit it.
			return '<div class="uipb-sec ' . esc_attr( $scope ) . '" data-uipb-sec="' . esc_attr( $uid ) . '">' . $html . '</div>';
		}

		/**
		 * Stamp every element of raw_html with its layer path (`data-uich-path`),
		 * counted over raw_html itself, before Twig runs.
		 *
		 * The Layers tree and the Composer address an element by its place in
		 * raw_html, where `{% for %}` / `{% if %}` are text and the looped
		 * element is one element. Counted after Twig, as the browser stamper
		 * does, a loop that renders no copy — or six — shifts every sibling
		 * after it: the looped block's path landed on the next block, so
		 * picking it in Layers selected something else. Stamped here, every
		 * copy carries its source element's path and nothing after it moves.
		 *
		 * The same walk as composer-stamps.js stampTree() and the layer
		 * parser: skip SCRIPT/STYLE/TEMPLATE/NOSCRIPT/META/LINK/HEAD, do not
		 * descend into `<svg>` / `img[data-as="svg"]`. Only attributes are
		 * added; the markup is otherwise returned byte for byte.
		 *
		 * Also marks what the canvas rings in purple, `data-uich-mark`: "loop"
		 * on the element a `{% for %}` repeats, "dynamic" on one printing a
		 * `{{ value }}` in its own text or an attribute. Like the path, it is
		 * canvas-only and stripped on the way back into raw_html (stripStamps).
		 *
		 * '' when the markup is not one this can read the way the layer parser
		 * does — the HTML API gave up on it, or it carries `<uichemy-*>` tags,
		 * which the parser closes itself — and the browser stamps it instead.
		 *
		 * @param string $html raw_html.
		 * @return string Stamped raw_html, or ''.
		 */
		private static function stamp_layer_paths( $html ) {
			if ( ! class_exists( 'WP_HTML_Processor' ) || false !== stripos( $html, '<uichemy-' ) ) {
				return '';
			}

			$processor = WP_HTML_Processor::create_fragment( $html );
			if ( ! $processor ) {
				return '';
			}

			$skip     = array( 'SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'META', 'LINK', 'HEAD' );
			$counters = array();
			$base     = null;
			// The next element at a depth follows a `{% for %}`: the loop's own.
			$after_for = array();
			// Paths whose own text prints a {{ value }}, found after the opener.
			$dynamic   = array();

			while ( $processor->next_token() ) {
				$crumbs = $processor->get_breadcrumbs();

				if ( '#text' === $processor->get_token_type() ) {
					if ( null === $base ) {
						continue;
					}
					$text  = (string) $processor->get_modifiable_text();
					$depth = count( $crumbs ) - 1 - $base; // The text's parent element.
					if ( false !== strpos( $text, '{%' ) && preg_match( '/\{%-?\s*for\s/', $text ) ) {
						$after_for[ $depth + 1 ] = true;
					}
					if ( $depth >= 1 && false !== strpos( $text, '{{' ) && isset( $counters[ $depth ] ) ) {
						$dynamic[ '0.' . implode( '.', array_slice( $counters, 0, $depth, true ) ) ] = true;
					}
					continue;
				}

				if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
					continue;
				}

				if ( null === $base ) {
					// The fragment's own context (HTML > BODY) comes first.
					$base = count( $crumbs ) - 1;
				}
				$depth = count( $crumbs ) - $base;
				if ( $depth < 1 ) {
					continue;
				}

				// Inside a skipped tag or an <svg>: not a layer.
				$ancestors = array_map( 'strtoupper', array_slice( $crumbs, $base, $depth - 1 ) );
				if ( array_intersect( $ancestors, $skip ) || in_array( 'SVG', $ancestors, true ) ) {
					continue;
				}
				$tag = strtoupper( (string) $processor->get_tag() );
				if ( in_array( $tag, $skip, true ) ) {
					continue;
				}

				$counters[ $depth ] = isset( $counters[ $depth ] ) ? $counters[ $depth ] + 1 : 0;
				$counters           = array_slice( $counters, 0, $depth, true );
				$after_for          = array_filter(
					$after_for,
					static function ( $d ) use ( $depth ) {
						return $d <= $depth;
					},
					ARRAY_FILTER_USE_KEY
				);
				$processor->set_attribute( 'data-uich-path', '0.' . implode( '.', $counters ) );

				// What the canvas rings in purple (composer-box-overlay.js): the
				// element a loop repeats, or one printing dynamic data in an
				// attribute (its own text is the second pass, below).
				$kind = '';
				if ( ! empty( $after_for[ $depth ] ) ) {
					$kind = 'loop';
					unset( $after_for[ $depth ] );
				} else {
					foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
						if ( 'data-uich-path' !== $name && false !== strpos( (string) $processor->get_attribute( $name ), '{{' ) ) {
							$kind = 'dynamic';
							break;
						}
					}
				}
				if ( '' !== $kind ) {
					$processor->set_attribute( 'data-uich-mark', $kind );
				}
			}

			if ( null !== $processor->get_last_error() ) {
				return '';
			}

			$out = $processor->get_updated_html();

			// Second pass: elements whose own text prints a value, by the path
			// the first pass gave them. A loop keeps its own mark.
			if ( $dynamic ) {
				$tags = new WP_HTML_Tag_Processor( $out );
				while ( $tags->next_tag() ) {
					$path = $tags->get_attribute( 'data-uich-path' );
					if ( is_string( $path ) && isset( $dynamic[ $path ] ) && null === $tags->get_attribute( 'data-uich-mark' ) ) {
						$tags->set_attribute( 'data-uich-mark', 'dynamic' );
					}
				}
				$out = $tags->get_updated_html();
			}

			return $out;
		}

		/**
		 * Inline script that stamps a section's elements with their layer paths.
		 *
		 * The Composer panel finds the element behind a click, and the live node
		 * behind a text or attribute edit, by `data-uich-path` — the element's
		 * position in raw_html. Counting live children instead breaks as soon as
		 * the page's own JS runs: split text, pin spacers and cloned marquee items
		 * all shift the count. Without stamps every content edit also has to
		 * repaint the section, which re-runs its script (and on a page with one
		 * shared runtime, re-boots all of it) for a one-word change.
		 *
		 * Printed inside the section wrapper, straight after its markup, so it runs
		 * while the parser is there — before any footer or deferred script has
		 * touched the markup. Canvas only: visitors have no panel to serve. A patch
		 * from the editor (POST /render) is stamped by canvas.js instead.
		 *
		 * The same walk as the Elementor widget's layer_path_stamp_script() and
		 * parseHtmlToLayers(): skip SCRIPT/STYLE/TEMPLATE/NOSCRIPT/META/LINK/HEAD,
		 * do not descend into `<svg>` / `img[data-as="svg"]`, step through the link
		 * wrapper and skip the form honeypot fields.
		 *
		 * @return string
		 */
		private static function layer_path_stamp_script() {
			return '<script>(function(s){try{var r=s&&s.parentNode;if(!r)return;'
				. 'var K={SCRIPT:1,STYLE:1,TEMPLATE:1,NOSCRIPT:1,META:1,LINK:1,HEAD:1};'
				. 'var w=function(n,b,k){var c=n.firstChild;for(;c;c=c.nextSibling){'
				. 'if(c.nodeType!==1||K[c.tagName])continue;'
				. 'if(c.hasAttribute("data-uich-link-wrap")){w(c,b,k);continue;}'
				. 'if((c.tagName==="INPUT"&&(c.name||"").indexOf("_uich_")===0)||(c.classList&&c.classList.contains("uich-hp")))continue;'
				. 'var p=b+"."+(k.i++);c.setAttribute("data-uich-path",p);'
				. 'var t=c.tagName.toUpperCase();if(t==="SVG"||(t==="IMG"&&c.getAttribute("data-as")==="svg")){'
				. 'var q=c.querySelectorAll("[data-uich-path]");for(var j=0;j<q.length;j++)q[j].removeAttribute("data-uich-path");continue;}w(c,p,{i:0});}};'
				. 'w(r,"0",{i:0});}catch(e){}})(document.currentScript);</script>';
		}

		/**
		 * Run a render with a post in scope, so Twig tokens resolve against it.
		 *
		 * For the editor's live preview (POST /page/{id}/render): a REST request
		 * has no global post, so without this `{{ post.title }}` would preview
		 * empty - or, with dynamic resolution off, as the raw token - until the
		 * canvas reloads. Elementor, Gutenberg and Bricks preview the resolved
		 * value; this makes the Composer Builder do the same.
		 *
		 * @param int      $post_id  Post the document belongs to.
		 * @param callable $callback Renders and returns the result.
		 * @return mixed What the callback returns.
		 */
		public static function with_post_context( $post_id, callable $callback ) {
			// The post the document previews against — for a single template,
			// a real post of its type rather than the template (preview_post_id()).
			$post = get_post( self::preview_post_id( (int) $post_id ) );

			$previous_flag = self::$resolve_dynamic;
			$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

			if ( $post instanceof WP_Post ) {
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped to this render and restored below.
				setup_postdata( $post );
				self::$resolve_dynamic = true;
			}

			try {
				return $callback();
			} finally {
				self::$resolve_dynamic = $previous_flag;
				$GLOBALS['post']       = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the value saved above.
				if ( $previous_post instanceof WP_Post ) {
					setup_postdata( $previous_post );
				}
			}
		}

		/**
		 * Enqueue the libraries a document's js uses, and queue the js to print
		 * after them.
		 *
		 * The same detection the Elementor widget uses: `gsap` / `ScrollTrigger`
		 * loads GSAP core and ScrollTrigger, plus MotionPathPlugin / DrawSVGPlugin
		 * when the js uses them;
		 * `THREE` loads UiChemy's three.js bundle. Nothing else is touched, so a
		 * page whose js needs neither renders exactly as before.
		 *
		 * @param string $js The document's raw js.
		 * @return bool True when the js was queued for the footer.
		 */
		private static function defer_script_with_libraries( $js ) {
			$gsap  = 1 === preg_match( '/\bgsap\b|\bScrollTrigger\b/', $js );
			$three = 1 === preg_match( '/\bTHREE\b/', $js );

			if ( ! $gsap && ! $three ) {
				return false;
			}

			$ver = defined( 'UICHEMY_VERSION' ) ? UICHEMY_VERSION : false;

			if ( $gsap && defined( 'UICHEMY_URL' ) ) {
				$base = UICHEMY_URL . 'assets/js/vendor/gsap/';
				wp_enqueue_script( 'uichemy-gsap-core', $base . 'gsap.min.js', array(), $ver, true );
				wp_enqueue_script( 'uichemy-gsap-scrolltrigger', $base . 'ScrollTrigger.min.js', array( 'uichemy-gsap-core' ), $ver, true );
				// The two plugins only for a page that uses them: loaded on every
				// animated page, they were two more scripts to fetch and parse for
				// nothing. A tween names them by their property (`motionPath`,
				// `drawSVG`) or the code registers them by class.
				if ( 1 === preg_match( '/\bmotionPath\b|\bMotionPathPlugin\b/', $js ) ) {
					wp_enqueue_script( 'uichemy-gsap-motionpath', $base . 'MotionPathPlugin.min.js', array( 'uichemy-gsap-core' ), $ver, true );
				}
				if ( 1 === preg_match( '/\bdrawSVG\b|\bDrawSVGPlugin\b/', $js ) ) {
					wp_enqueue_script( 'uichemy-gsap-drawsvg', $base . 'DrawSVGPlugin.min.js', array( 'uichemy-gsap-core' ), $ver, true );
				}
			}

			if ( $three && defined( 'UICHEMY_BUILD_URL' ) ) {
				$path = defined( 'UICHEMY_BUILD_PATH' ) ? UICHEMY_BUILD_PATH . 'three.js' : '';
				wp_enqueue_script( 'uichemy-three', UICHEMY_BUILD_URL . 'three.js', array(), ( $path && file_exists( $path ) ) ? filemtime( $path ) : $ver, true );
			}

			$code = self::compile_sections( UiChemy_Composer_Renderer::decode_code_entities( $js ) );

			self::$footer_scripts[ md5( $code ) ] = $code;

			if ( ! has_action( 'wp_footer', array( __CLASS__, 'print_footer_scripts' ) ) ) {
				// After wp_print_footer_scripts (priority 20), where the libraries print.
				add_action( 'wp_footer', array( __CLASS__, 'print_footer_scripts' ), 99 );
			}

			return true;
		}

		/**
		 * Compile the motion variables of every section in the page's JS.
		 *
		 * A page imported from several Composer sections holds each section's JS
		 * in a block of its own (Uich_CB_Content::JS_SCOPE_MARK), and each block
		 * declares its own `uichemy_controller_bridge`. UiChemy_Motion::compile()
		 * replaces only the FIRST declaration, so every later section kept its
		 * `{ value, label, … }` specs and read objects where it expected values —
		 * a `pin: { value: true }` made ScrollTrigger throw and stopped that
		 * section's animation. Compiled one block at a time, each section gets its
		 * own values, as it does under Elementor. compile_motion() does that
		 * itself now, so the canvas repaint (render_html) gets it too: it used
		 * to compile the first block only, and every later section's animation
		 * broke on the first edit.
		 *
		 * @param string $js The page's JS.
		 * @return string Runnable JS.
		 */
		private static function compile_sections( $js ) {
			return UiChemy_Composer_Renderer::compile_motion( (string) $js );
		}

		/**
		 * Print the deferred document scripts.
		 *
		 * @return void
		 */
		public static function print_footer_scripts() {
			// On the editor's canvas the page's script is left for canvas.js to
			// run (host/canvas-motion.js createScriptRunner): a run it starts is
			// one the next repaint can undo — its GSAP context reverted, its
			// `const`s in their own block. Run here, the first repaint could not
			// take its matchMedia set-ups back, and reran into "already declared".
			// Only where canvas.js is actually on the page to run it: the query
			// flag alone is a URL anyone can type.
			$open = ( class_exists( 'Uich_CB_Canvas' ) && Uich_CB_Canvas::is_canvas() && wp_script_is( Uich_CB_Canvas::HANDLE, 'done' ) )
				? '<script type="text/plain" data-uich-cb-run>'
				: '<script>';
			foreach ( self::$footer_scripts as $code ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The document's own js, stored through the code policy; the widget prints it the same way.
				echo $open . $code . '</script>' . "\n";
			}

			self::$footer_scripts = array();
		}
	}
}
