<?php
/**
 * Uich_CB_Page_Settings — the settings that belong to a page, not a section.
 *
 *   Template        the theme's own, UiChemy Full width, UiChemy Canvas, or
 *                   any other template the theme offers
 *   Hide title      drop the theme's page heading on this page
 *   Featured image  the post thumbnail
 *
 * The two templates are registered here rather than borrowed: without them
 * "full width" meant whatever the active theme (or Elementor, when it happens
 * to be active) chose to ship, and a page set to Elementor Canvas breaks the
 * day Elementor is deactivated. They ride on `_wp_page_template`, the same meta
 * the block and classic editors write, so switching in WordPress does what you
 * expect.
 *
 *   uichemy-full-width   the theme's header and footer, no content container
 *                        and no title: sections reach the edges
 *   uichemy-canvas       a blank document: no theme header, footer or title
 *                        (UiChemy's Theme Builder header / footer still show)
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Page_Settings' ) ) {

	/**
	 * Page templates, hide title and featured image.
	 *
	 * @since 5.2.5
	 */
	final class Uich_CB_Page_Settings {

		/** Template slug: theme header and footer, full-width content. */
		const TPL_FULL_WIDTH = 'uichemy-full-width';

		/** Template slug: blank document. */
		const TPL_CANVAS = 'uichemy-canvas';

		/** Post meta: '1' when the theme's page title is hidden. */
		const META_HIDE_TITLE = '_uich_cb_hide_title';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function boot() {
			add_filter( 'theme_templates', array( __CLASS__, 'register_templates' ), 10, 4 );
			// After themes (10) so a theme's own template_include does not win
			// over a template the page explicitly chose.
			add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
			add_action( 'wp_head', array( __CLASS__, 'print_hide_title_css' ) );
			add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
			// Before the screen reads the settings (its boot payload).
			add_action( 'uich_cb/editor/init', array( __CLASS__, 'default_template_layout' ) );
		}

		/**
		 * A Theme Builder template opens on the Canvas layout.
		 *
		 * A header, footer or single template is a piece of a page, not a page:
		 * on the theme's Standard layout the builder showed it inside the
		 * theme's single-post page (its title, post navigation, author box).
		 * Applied when the builder opens one whose layout was never chosen, so
		 * it covers templates made anywhere (Theme Builder, Layers' Add header,
		 * the block editor's Edit with UiChemy); a layout someone picked,
		 * Standard included (stored as 'default'), is left alone.
		 *
		 * @param int $post_id Post the builder is opening.
		 * @return void
		 */
		public static function default_template_layout( $post_id ) {
			$post_id = (int) $post_id;
			if ( Uich_CB_Post_Types::TEMPLATE !== get_post_type( $post_id ) ) {
				return;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_wp_page_template', true ) ) {
				return;
			}
			update_post_meta( $post_id, '_wp_page_template', self::TPL_CANVAS );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Templates
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * The templates this plugin adds, slug => label.
		 *
		 * @return array
		 */
		public static function own_templates() {
			return array(
				self::TPL_FULL_WIDTH => __( 'UiChemy Full width', 'uichemy' ),
				self::TPL_CANVAS     => __( 'UiChemy Canvas', 'uichemy' ),
			);
		}

		/**
		 * Offer our templates on every post type the builder edits: the content
		 * types, and the Theme Builder's templates (headers, footers…), which
		 * supported_post_types() leaves out as a builder-owned type. Without
		 * them a template's Layout offered Canvas and Full width, and saving
		 * either failed with "That page template does not exist".
		 *
		 * @param array         $templates Templates the theme offers.
		 * @param WP_Theme      $theme     Theme.
		 * @param WP_Post|null  $post      Post being edited, if any.
		 * @param string        $post_type Post type.
		 * @return array
		 */
		public static function register_templates( $templates, $theme, $post, $post_type ) {
			if ( Uich_CB_Post_Types::TEMPLATE !== $post_type && ! in_array( $post_type, Uich_CB_Content::supported_post_types(), true ) ) {
				return $templates;
			}
			return array_merge( (array) $templates, self::own_templates() );
		}

		/**
		 * Serve our template files for a page that chose one.
		 *
		 * @param string $template Template WordPress resolved.
		 * @return string
		 */
		public static function template_include( $template ) {
			if ( ! is_singular() ) {
				return $template;
			}

			$slug = get_page_template_slug( get_queried_object_id() );
			$file = array(
				self::TPL_FULL_WIDTH => 'full-width.php',
				self::TPL_CANVAS     => 'canvas.php',
			);

			if ( isset( $file[ $slug ] ) && is_readable( UICH_CB_PATH . 'templates/' . $file[ $slug ] ) ) {
				return UICH_CB_PATH . 'templates/' . $file[ $slug ];
			}
			return $template;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Hide title
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Is the theme's title hidden on this post?
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function hides_title( $post_id ) {
			return '1' === (string) get_post_meta( (int) $post_id, self::META_HIDE_TITLE, true );
		}

		/**
		 * Hide the theme's page heading with CSS.
		 *
		 * CSS rather than filtering the_title: the_title also feeds menus,
		 * widgets, the document title and other posts' listings, and blanking
		 * it there is how a "hide title" switch empties a nav menu. Our own
		 * templates print no title, so this is only needed for the theme's.
		 *
		 * @return void
		 */
		public static function print_hide_title_css() {
			if ( ! is_singular() || ! self::hides_title( get_queried_object_id() ) ) {
				return;
			}

			/**
			 * Filter the selector for the theme's page title.
			 *
			 * Covers the common markup: `h1.entry-title` (most classic themes,
			 * Hello Elementor), `.page-header` wrappers, and the block themes'
			 * Post Title block.
			 *
			 * @param string $selector CSS selector.
			 */
			$selector = (string) apply_filters(
				'uich_cb/hide_title_selector',
				'.page-header .entry-title, h1.entry-title, .entry-header .entry-title, h1.wp-block-post-title, .wp-block-post-title'
			);

			printf( "<style id=\"uich-cb-hide-title\">%s{display:none !important;}</style>\n", wp_strip_all_tags( $selector ) );
		}

		/**
		 * Mark the body so themes and sections can react too.
		 *
		 * @param array $classes Body classes.
		 * @return array
		 */
		public static function body_class( $classes ) {
			if ( is_singular() && self::hides_title( get_queried_object_id() ) ) {
				$classes[] = 'uich-hide-title';
			}
			return $classes;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Read / write
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Does this post have a featured image slot at all?
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function supports_thumbnail( $post_id ) {
			return post_type_supports( get_post_type( $post_id ), 'thumbnail' ) && current_theme_supports( 'post-thumbnails' );
		}

		/**
		 * Every template this page can use, in the order the editor lists them.
		 *
		 * @param int $post_id Post ID.
		 * @return array List of {value, label, own}.
		 */
		public static function template_choices( $post_id ) {
			$all  = wp_get_theme()->get_page_templates( get_post( $post_id ) );
			$own  = self::own_templates();
			$list = array(
				array(
					'value' => '',
					'label' => __( 'Theme default', 'uichemy' ),
					'own'   => false,
				),
			);
			foreach ( $own as $slug => $label ) {
				$list[] = array(
					'value' => $slug,
					'label' => $label,
					'own'   => true,
				);
			}
			foreach ( $all as $slug => $label ) {
				if ( isset( $own[ $slug ] ) ) {
					continue;
				}
				$list[] = array(
					'value' => (string) $slug,
					'label' => (string) $label,
					'own'   => false,
				);
			}
			return $list;
		}

		/**
		 * The page's settings, in the shape the editor reads.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public static function get( $post_id ) {
			$post_id  = (int) $post_id;
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			$image    = null;

			if ( $thumb_id ) {
				$src   = wp_get_attachment_image_src( $thumb_id, 'medium' );
				$image = array(
					'id'  => $thumb_id,
					'url' => $src ? (string) $src[0] : (string) wp_get_attachment_url( $thumb_id ),
					'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
				);
			}

			$template = (string) get_page_template_slug( $post_id );

			return array(
				'template'          => 'default' === $template ? '' : $template,
				'templates'         => self::template_choices( $post_id ),
				'hideTitle'         => self::hides_title( $post_id ),
				'featuredImage'     => $image,
				'supportsThumbnail' => self::supports_thumbnail( $post_id ),
			);
		}

		/**
		 * Apply a partial update. Keys left out are not touched.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $body    Any of: template, hideTitle, featuredImageId.
		 * @return array|WP_Error The settings after the update.
		 */
		public static function update( $post_id, $body ) {
			$post_id = (int) $post_id;
			$body    = is_array( $body ) ? $body : array();

			if ( array_key_exists( 'template', $body ) ) {
				$template = sanitize_text_field( (string) $body['template'] );
				if ( '' !== $template ) {
					$available = wp_get_theme()->get_page_templates( get_post( $post_id ) );
					if ( ! isset( $available[ $template ] ) ) {
						return new WP_Error( 'uich_cb_bad_template', __( 'That page template does not exist.', 'uichemy' ), array( 'status' => 400 ) );
					}
				}
				update_post_meta( $post_id, '_wp_page_template', '' === $template ? 'default' : $template );
			}

			if ( array_key_exists( 'hideTitle', $body ) ) {
				if ( rest_sanitize_boolean( $body['hideTitle'] ) ) {
					update_post_meta( $post_id, self::META_HIDE_TITLE, '1' );
				} else {
					delete_post_meta( $post_id, self::META_HIDE_TITLE );
				}
			}

			if ( array_key_exists( 'featuredImageId', $body ) ) {
				$image_id = absint( $body['featuredImageId'] );
				if ( 0 === $image_id ) {
					delete_post_thumbnail( $post_id );
				} else {
					if ( ! self::supports_thumbnail( $post_id ) ) {
						return new WP_Error( 'uich_cb_no_thumbnail', __( 'This page type has no featured image.', 'uichemy' ), array( 'status' => 400 ) );
					}
					if ( ! wp_attachment_is_image( $image_id ) || ! current_user_can( 'read_post', $image_id ) ) {
						return new WP_Error( 'uich_cb_bad_image', __( 'That is not an image you can use.', 'uichemy' ), array( 'status' => 400 ) );
					}
					set_post_thumbnail( $post_id, $image_id );
				}
			}

			return self::get( $post_id );
		}
	}
}
