<?php
/**
 * Uich_CB_Code_Policy — what a user may store as a page's HTML, CSS and JS.
 *
 * A builder document is code that the front end prints as-is: the HTML into
 * the page, the CSS into a <style>, the JavaScript into a <script>, and the
 * page's head / body-end code at the end of <head> and of <body>. Whoever can
 * save a document can therefore run script in the browser of everyone who
 * views the page — including an administrator previewing a contributor's
 * draft. WordPress already has a name for "may publish unfiltered markup":
 * the `unfiltered_html` capability (administrators and editors on a single
 * site; nobody on multisite except super admins; nobody when
 * DISALLOW_UNFILTERED_HTML is set). This class applies it to builder
 * documents, the same rule core applies to post content.
 *
 *   may author code   everything is stored as sent
 *   may not           HTML, and the head / body-end code
 *                          → wp_kses_post() (core's post-content allowlist)
 *                     CSS  → kept as styling, stripped of anything that could
 *                            leave the <style> element or pull code in
 *                     JS   → not stored
 *                     any field without a rule below → not stored, so a
 *                            content field added later is closed until it is
 *                            given one
 *
 * A field the user did NOT change is kept as it is stored. Opening an
 * administrator's page to fix a typo must not wipe the script or markup the
 * administrator put there, and a value identical to the stored one adds
 * nothing the user could not already cause. Only what they changed is theirs,
 * so only that is filtered.
 *
 * Separately, a site can switch stored CSS/JS off altogether through
 * `uichemy/composer/allow_custom_code` — the switch the rest of the plugin
 * already honours for Composer code — and that applies to everyone.
 *
 * Applied in ONE place: Uich_CB_Content::prepare_for_storage(), which every
 * write of a document goes through (save, autosave). A route added later
 * cannot forget it, because it has no other way to store a document.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Code_Policy' ) ) {

	/**
	 * Filters document content by the saving user's capability.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Code_Policy {

		/**
		 * How each content field is filtered for a user without
		 * `unfiltered_html`: 'markup' (kses), 'css' (clean_css) or 'script'
		 * (dropped). Keys are Uich_CB_Content::CONTENT_FIELDS.
		 */
		const RULES = array(
			'html'                 => 'markup',
			'css'                  => 'css',
			'js'                   => 'script',
			'before_head_end_html' => 'markup',
			'before_body_end_html' => 'markup',
		);

		/**
		 * Fields the site's custom-code switch turns off.
		 */
		const SWITCHABLE = array( 'css', 'js' );

		/**
		 * May the current user store HTML, CSS and JavaScript unfiltered?
		 *
		 * @return bool
		 */
		public static function can_author_code() {
			/**
			 * Filter whether the current user may store unfiltered builder code.
			 *
			 * Narrowing is safe; widening it past `unfiltered_html` hands that
			 * user script execution on every page they can edit.
			 *
			 * @since 5.2.1
			 *
			 * @param bool $can Whether the user holds `unfiltered_html`.
			 */
			return (bool) apply_filters( 'uich_cb/can_author_code', current_user_can( 'unfiltered_html' ) );
		}

		/**
		 * Is stored CSS/JS allowed on this site at all?
		 *
		 * @return bool
		 */
		public static function custom_code_allowed() {
			return function_exists( 'uichemy_custom_code_allowed' ) ? (bool) uichemy_custom_code_allowed() : true;
		}

		/**
		 * The content the current user may store for a post.
		 *
		 * @param array $content Incoming content (normalised: every
		 *                       Uich_CB_Content::CONTENT_FIELDS key present).
		 * @param array $stored  What the post stores now — the baseline for
		 *                       "unchanged".
		 * @return array Content safe to store, same keys.
		 */
		public static function apply( array $content, array $stored ) {
			$out = array();
			foreach ( array_keys( Uich_CB_Content::CONTENT_FIELDS ) as $field ) {
				$out[ $field ] = isset( $content[ $field ] ) ? (string) $content[ $field ] : '';
			}

			// Site switch first, and for everyone: an "unchanged" CSS/JS field
			// must not keep code the site has turned off.
			if ( ! self::custom_code_allowed() ) {
				foreach ( self::SWITCHABLE as $field ) {
					$out[ $field ] = '';
				}
			}

			// Role Manager "Content Only": text and images, nothing else.
			if ( class_exists( 'Uich_CB_Policy' ) && Uich_CB_Policy::content_only() ) {
				$out = self::content_only( $out, $stored );
			}

			if ( self::can_author_code() ) {
				return $out;
			}

			foreach ( $out as $field => $value ) {
				if ( isset( $stored[ $field ] ) && (string) $stored[ $field ] === $value ) {
					continue;
				}

				$out[ $field ] = self::filter( $field, $value );
			}

			return $out;
		}

		/**
		 * Inline tags a text edit may add or remove (bold, a link, a line break).
		 */
		const INLINE_TAGS = array( 'A', 'ABBR', 'B', 'BR', 'CITE', 'CODE', 'EM', 'I', 'MARK', 'S', 'SMALL', 'STRONG', 'SUB', 'SUP', 'U' );

		/**
		 * Attributes that are content, not design: an image's source and text,
		 * a link's target.
		 */
		const CONTENT_ATTRIBUTES = array( 'alt', 'href', 'rel', 'sizes', 'src', 'srcset', 'target', 'title' );

		/**
		 * What a Content Only user may store: the page's text and images.
		 *
		 * CSS, JS and the head / body-end code stay as stored. The HTML is taken
		 * only when its structure is the stored one — the same elements in the
		 * same order with the same classes, styles and other attributes — so
		 * text, image sources and alt text can change but a section, a class or
		 * an inline style cannot. Anything else keeps the stored HTML.
		 *
		 * @param array $out    Incoming content.
		 * @param array $stored Stored content.
		 * @return array
		 */
		private static function content_only( array $out, array $stored ) {
			foreach ( array_keys( $out ) as $field ) {
				if ( 'html' !== $field ) {
					$out[ $field ] = isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
				}
			}

			$html = isset( $stored['html'] ) ? (string) $stored['html'] : '';
			if ( $out['html'] !== $html && self::structure( $out['html'] ) !== self::structure( $html ) ) {
				$out['html'] = $html;
			}

			return $out;
		}

		/**
		 * An HTML string's structure: every element with its attributes minus
		 * the content ones. Plain inline text markup (a bare <strong>, a link,
		 * a <br>) is left out, so a text edit may add or remove it; an inline
		 * tag carrying a class, style or other design attribute counts.
		 *
		 * @param string $html HTML.
		 * @return string[]
		 */
		private static function structure( $html ) {
			$tags = new WP_HTML_Tag_Processor( (string) $html );
			$out  = array();

			while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
				$name   = $tags->get_tag();
				$names  = $tags->is_tag_closer() ? array() : (array) $tags->get_attribute_names_with_prefix( '' );
				$design = array();
				foreach ( $names as $attr ) {
					if ( ! in_array( $attr, self::CONTENT_ATTRIBUTES, true ) ) {
						$design[ $attr ] = $tags->get_attribute( $attr );
					}
				}
				if ( ! $design && in_array( $name, self::INLINE_TAGS, true ) ) {
					continue;
				}
				ksort( $design );
				$out[] = ( $tags->is_tag_closer() ? '/' : '' ) . $name . ( $design ? wp_json_encode( $design ) : '' );
			}

			return $out;
		}

		/**
		 * One field's value, as a user without `unfiltered_html` may store it.
		 *
		 * @param string $field Content field.
		 * @param string $value Incoming value.
		 * @return string
		 */
		private static function filter( $field, $value ) {
			$rule = isset( self::RULES[ $field ] ) ? self::RULES[ $field ] : '';

			switch ( $rule ) {
				case 'markup':
					return wp_kses_post( $value );
				case 'css':
					return self::clean_css( $value );
				default:
					// 'script', and any field nobody has written a rule for.
					return '';
			}
		}

		/**
		 * CSS that can only ever style.
		 *
		 * It is printed inside a <style> element, so the one way out of it is
		 * markup — `</style>` and whatever follows. `<` has no meaning in CSS
		 * outside a quoted string, so removing every one of them closes that
		 * without touching a real rule (`>`, the child combinator, stays).
		 * Beyond that: `@import` loads a stylesheet nobody reviewed, and the
		 * old script-in-CSS hooks (`expression()`, `behavior`, `-moz-binding`,
		 * `javascript:` URLs) are neutralised for the browsers that still read
		 * them.
		 *
		 * @param string $css Stylesheet text.
		 * @return string
		 */
		public static function clean_css( $css ) {
			$css = str_replace( array( "\0", '<' ), '', (string) $css );

			// Drop whole @import statements, up to their semicolon or the end.
			$css = preg_replace( '/@import\b[^;]*;?/i', '', $css );

			// Script hooks: replace the keyword itself, so the declaration no
			// longer parses, rather than guessing where the rule it sits in
			// ends. Replacing (not prefixing) keeps a second pass a no-op.
			$css = preg_replace( '/(?<![\w-])(expression|behavior|-moz-binding)\s*(\(|:)/i', 'blocked$2', (string) $css );
			$css = preg_replace( '/javascript\s*:/i', 'blocked:', (string) $css );

			return (string) $css;
		}
	}
}
