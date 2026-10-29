<?php
/**
 * get_content_sections() — the host adapter (02 §3).
 *
 * Reads a post's content-scope HTML from whichever builder owns it, through the
 * existing driver layer (read-only — nothing here writes). Order of resort:
 * Composer sections (Elementor / Gutenberg / Bricks drivers) → Elementor's own
 * render for Elementor pages built without Composer → plain post_content.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Sections' ) ) {

	/**
	 * Section reader.
	 */
	class Uich_SEO_Sections {

		/**
		 * Guards against re-entry: rendering Twig or Elementor content can fire
		 * hooks that would otherwise ask us to read the same post again.
		 *
		 * @var array<int,bool>
		 */
		private static $reading = array();

		/**
		 * Content sections of a post, in document order.
		 *
		 * @param int    $post_id Post id.
		 * @param string $kind    'instance' resolves Twig against this post;
		 *                        'pattern' (templates) keeps it.
		 * @return array<int,array{html:string,uid:string,source:string,order:int}>
		 */
		public static function get( $post_id, $kind = 'instance' ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );
			if ( ! $post || isset( self::$reading[ $post_id ] ) ) {
				return array();
			}
			self::$reading[ $post_id ] = true;

			try {
				$sections = self::from_cb_document( $post, $kind );
				if ( empty( $sections ) ) {
					$sections = self::from_composer( $post, $kind );
				}
				if ( empty( $sections ) ) {
					$sections = self::from_elementor_render( $post );
				}
				if ( empty( $sections ) ) {
					$sections = self::from_post_content( $post );
				}
			} catch ( \Throwable $e ) {
				$sections = self::from_post_content( $post );
			}

			unset( self::$reading[ $post_id ] );
			return $sections;
		}

		/**
		 * A Composer Builder document: its own html, as the page prints it.
		 *
		 * The document has no builder driver, so it used to be read from the
		 * post_content mirror through wpautop() - which pushes <p> tags into an
		 * inline <script>, and the script's text then became the page's meta
		 * description.
		 *
		 * @param WP_Post $post Post.
		 * @param string  $kind Kind.
		 * @return array
		 */
		private static function from_cb_document( $post, $kind ) {
			if ( ! class_exists( 'Uich_CB_Content' ) || ! Uich_CB_Content::is_enabled( $post->ID ) ) {
				return array();
			}
			$fields = Uich_CB_Content::get_cb_content_fields( $post->ID );
			$html   = isset( $fields['html'] ) ? (string) $fields['html'] : '';
			if ( '' === trim( $html ) ) {
				return array();
			}
			$uid = 'uich-cb-page';
			if ( 'instance' === $kind ) {
				$html = self::render_twig( $html, $post, $uid );
			}
			return array(
				array(
					'html'   => $html,
					'uid'    => $uid,
					'source' => 'composer:cb',
					'order'  => 0,
				),
			);
		}

		/**
		 * Composer sections through the builder driver layer.
		 *
		 * @param WP_Post $post Post.
		 * @param string  $kind Kind.
		 * @return array
		 */
		private static function from_composer( $post, $kind ) {
			if ( ! class_exists( 'UiChemy_Builder_Registry' ) ) {
				return array();
			}
			$slug = UiChemy_Builder_Registry::detect_post_builder( $post->ID );
			if ( '' === $slug ) {
				return array();
			}
			$driver = UiChemy_Builder_Registry::get( $slug );
			if ( ! $driver || ! $driver->is_available() ) {
				return array();
			}
			$loaded = $driver->load_sections( $post->ID, 'content' );
			if ( is_wp_error( $loaded ) || empty( $loaded ) ) {
				return array();
			}

			$out = array();
			foreach ( array_values( $loaded ) as $i => $section ) {
				// "Page-only" header/footer sections are content widgets labelled as
				// such (mcp v1 tools). Site headers live in templates and never reach here.
				$label = isset( $section['label'] ) ? strtolower( trim( (string) $section['label'] ) ) : '';
				if ( in_array( $label, array( 'header', 'footer', 'site header', 'site footer' ), true ) ) {
					continue;
				}
				$html = isset( $section['settings']['raw_html'] ) ? (string) $section['settings']['raw_html'] : '';
				if ( '' === trim( $html ) ) {
					continue;
				}
				$uid = (string) $section['uid'];
				if ( 'instance' === $kind ) {
					$html = self::render_twig( $html, $post, $uid );
				}
				$out[] = array(
					'html'   => $html,
					'uid'    => $uid,
					'source' => 'composer:' . $slug,
					'order'  => $i,
				);
			}
			return $out;
		}

		/**
		 * Elementor page built without Composer widgets: Elementor's own render.
		 *
		 * @param WP_Post $post Post.
		 * @return array
		 */
		private static function from_elementor_render( $post ) {
			if ( 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true )
				|| ! class_exists( '\Elementor\Plugin' )
				|| empty( \Elementor\Plugin::$instance->frontend )
			) {
				return array();
			}
			$html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content( $post->ID, false );
			// get_builder_content() prints nothing, but strip the inline CSS it returns.
			$html = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				return array();
			}
			return array(
				array(
					'html'   => $html,
					'uid'    => 'elementor',
					'source' => 'elementor',
					'order'  => 0,
				),
			);
		}

		/**
		 * Classic / plain Gutenberg content. Blocks are rendered, but the
		 * `the_content` filter stack is not run — third-party filters would add
		 * share buttons, related posts and ads to what we read as "the page".
		 *
		 * @param WP_Post $post Post.
		 * @return array
		 */
		private static function from_post_content( $post ) {
			$raw = (string) $post->post_content;
			if ( '' === trim( $raw ) ) {
				return array();
			}
			$html = has_blocks( $raw ) ? do_blocks( $raw ) : wpautop( $raw );
			$html = do_shortcode( shortcode_unautop( $html ) );
			return array(
				array(
					'html'   => $html,
					'uid'    => 'content',
					'source' => has_blocks( $raw ) ? 'blocks' : 'classic',
					'order'  => 0,
				),
			);
		}

		/**
		 * Resolve Twig in a section against this post (instance records, D4).
		 *
		 * @param string  $html Section HTML.
		 * @param WP_Post $post Post.
		 * @param string  $uid  Section uid.
		 * @return string
		 */
		private static function render_twig( $html, $post, $uid ) {
			if ( ! class_exists( 'Uich_Dynamic' ) || ! Uich_Dynamic::has_dynamic( $html ) ) {
				return $html;
			}
			Uich_Dynamic::boot(); // post_provider() does not load its own class.
			// The derived title / description are printed publicly, so the section's Twig
			// runs with the trust of the post's author (Uich_Dynamic::render_authored()).
			return (string) Uich_Dynamic::render_authored(
				$html,
				array(
					'post'      => Uich_Dynamic::post_provider( $post ),
					'site'      => Uich_SEO_Settings::twig_site(),
					'widget_id' => $uid,
				),
				(int) $post->ID
			);
		}

		/**
		 * Plain text of the post (builder R6.1 writes one into post_content).
		 *
		 * @param int $post_id Post id.
		 * @return string
		 */
		public static function plain_text( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return '';
			}
			return Uich_SEO_Html::plain( strip_shortcodes( (string) $post->post_content ) );
		}

		/**
		 * Which theme-builder single template governs a post, outside its own
		 * request. Runs the real resolver against a temporary main query so the
		 * answer is exactly what rendering would pick (admin / MCP only).
		 *
		 * @param int $post_id Post id.
		 * @return int Template id, or 0.
		 */
		public static function template_for_post( $post_id ) {
			$target = get_post( $post_id );
			if ( ! $target || ! class_exists( 'UiChemy_Template_Resolver' ) || ! class_exists( 'UiChemy_Template_CPT' ) ) {
				return 0;
			}
			if ( UiChemy_Template_CPT::POST_TYPE === $target->post_type ) {
				return 0;
			}

			// On the front end, for the queried post, the live resolver is authoritative.
			if ( ! is_admin() && did_action( 'wp' ) && is_singular() && (int) get_queried_object_id() === (int) $target->ID ) {
				return self::resolve_single_type( $target );
			}

			global $wp_query, $wp_the_query, $post;
			$saved = array( $wp_query, $wp_the_query, $post );

			$args = array(
				'p'                => $post_id,
				'post_type'        => get_post_type( $post_id ),
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			);
			if ( 'page' === get_post_type( $post_id ) ) {
				unset( $args['p'] );
				$args['page_id'] = $post_id;
			}
			$q             = new WP_Query( $args );
			$wp_query      = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			$wp_the_query  = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			if ( $q->have_posts() ) {
				$q->the_post();
			}

			UiChemy_Template_Resolver::flush_cache();
			$id = self::resolve_single_type( get_post( $post_id ) );
			UiChemy_Template_Resolver::flush_cache();

			list( $wp_query, $wp_the_query, $post ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
			if ( $post instanceof WP_Post ) {
				setup_postdata( $post );
			}

			return (int) $id;
		}

		/**
		 * single_product for WooCommerce products, falling back to single.
		 *
		 * @param WP_Post $post Post.
		 * @return int
		 */
		private static function resolve_single_type( $post ) {
			$id = 0;
			if ( $post && 'product' === $post->post_type && UiChemy_Template_CPT::is_valid_type( 'single_product' ) ) {
				$id = (int) UiChemy_Template_Resolver::resolve( 'single_product' );
			}
			if ( ! $id ) {
				$id = (int) UiChemy_Template_Resolver::resolve( 'single' );
			}
			return $id;
		}
	}
}
