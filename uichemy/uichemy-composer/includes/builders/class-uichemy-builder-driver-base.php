<?php
/**
 * Shared behaviour for every page-builder driver.
 *
 * Holds only what is genuinely the same for all builders: identity, the
 * capability table, and the conservative defaults for the hooks most builders
 * do not need. Everything storage-shaped stays abstract, because that is the
 * one thing the three builders really do differently.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Builder_Driver_Base' ) ) {

	/**
	 * Base implementation of UiChemy_Builder_Driver.
	 */
	abstract class UiChemy_Builder_Driver_Base implements UiChemy_Builder_Driver {

		/**
		 * Builder slug.
		 *
		 * @var string
		 */
		protected $slug = '';

		/**
		 * Human label.
		 *
		 * @var string
		 */
		protected $label = '';

		/**
		 * Capabilities this builder has. Anything absent is false, so a new
		 * capability defaults to "unsupported" for every existing driver rather
		 * than being silently claimed by all of them.
		 *
		 * @var string[]
		 */
		protected $caps = array();

		/**
		 * {@inheritDoc}
		 */
		public function slug() {
			return $this->slug;
		}

		/**
		 * {@inheritDoc}
		 */
		public function label() {
			return $this->label;
		}

		/**
		 * {@inheritDoc}
		 */
		public function supports( $cap ) {
			return in_array( (string) $cap, $this->caps, true );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: a post is unclaimed when NO registered builder owns it.
		 */
		public function is_unclaimed( $post_id ) {
			return '' === UiChemy_Builder_Registry::detect_post_builder( $post_id );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: the builder slug. Overridden only where the Template Store's
		 * `editor` vocabulary differs from ours.
		 */
		public function template_editor_slug() {
			return $this->slug;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: nothing to invalidate.
		 */
		public function after_write( $post_id ) {
			unset( $post_id );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: just the WordPress editor.
		 */
		public function edit_links( $post_id ) {
			$post_id = absint( $post_id );

			return array(
				'edit_link'    => (string) get_edit_post_link( $post_id, 'internal' ),
				'preview_link' => (string) get_permalink( $post_id ),
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: no narrowing, which is safe (it over-selects rather than
		 * under-selects) but slow. Every real driver overrides this.
		 */
		public function grep_query_args( array $args ) {
			return $args;
		}

		/**
		 * Create the WordPress post itself, without any builder meta.
		 *
		 * Shared because the post row is identical for all three; the drivers
		 * differ only in what they stamp on it afterwards.
		 *
		 * @param array $attrs { post_title, post_type, post_status }.
		 * @return int|WP_Error
		 */
		protected function insert_post( array $attrs ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => isset( $attrs['post_title'] ) ? sanitize_text_field( (string) $attrs['post_title'] ) : '',
					'post_type'    => isset( $attrs['post_type'] ) ? sanitize_key( (string) $attrs['post_type'] ) : 'page',
					'post_status'  => isset( $attrs['post_status'] ) ? sanitize_key( (string) $attrs['post_status'] ) : 'draft',
					'post_content' => isset( $attrs['post_content'] ) ? (string) $attrs['post_content'] : '',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return (int) $post_id;
		}

		/**
		 * Guard used by every load/save: the post must exist.
		 *
		 * @param int $post_id Post id.
		 * @return WP_Post|WP_Error
		 */
		protected function require_post( $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return new WP_Error( 'uich_invalid_post_id', 'Invalid post_id.' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'uich_post_not_found', 'Post not found.' );
			}

			return $post;
		}

		/**
		 * Refuse an area this builder does not keep separately.
		 *
		 * Elementor and Gutenberg store one stream per post, so asking them for
		 * a 'header' area is a caller bug worth naming rather than silently
		 * answering with the body.
		 *
		 * @param string $area Requested area.
		 * @return true|WP_Error
		 */
		protected function require_content_area( $area ) {
			if ( 'content' === UiChemy_Section::area( $area ) ) {
				return true;
			}

			return new WP_Error(
				'uich_area_unsupported',
				sprintf(
					'%s stores one content stream per post, so there is no separate "%s" area. Build the header or footer as a theme-builder template instead.',
					$this->label,
					sanitize_key( (string) $area )
				)
			);
		}
	}
}
