<?php
/**
 * Uich_CB_WPContent — the `post_content` mirror and the derived-output pipeline.
 *
 * The builder's source of truth is the AST in postmeta (Uich_CB_Content).
 * `post_content` is the RENDERED / SEMANTIC HTML mirror of it, and it is kept
 * populated on purpose rather than left empty:
 *
 *   - WordPress search, excerpts and `the_content` filters read post_content.
 *     A builder that leaves it blank makes every page invisible to site search
 *     and gives RSS, oEmbed and AMP nothing to show.
 *   - Deactivating the plugin must not blank the site. With the HTML mirrored
 *     here the page still renders as plain content — degraded, but readable.
 *   - Revisions diff post_content, so the version history stays legible to a
 *     human reading it in the revision browser.
 *
 * regenerate() is also what a RESTORE calls. Restoring a revision puts the old
 * AST back; the HTML, CSS and any caches derived from it are stale the instant
 * that happens, so they are rebuilt from the restored AST rather than trusted.
 *
 * NOTHING HERE RENDERS THE AST YET. The renderer arrives with the editor's
 * server side; this class owns the read/write contract around it so the storage
 * model is complete and a renderer can be dropped in behind
 * `uich_cb/render_cb_content` without touching a caller.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_WPContent' ) ) {

	/**
	 * Renders a builder document and keeps the derived output in step with it.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_WPContent {

		/**
		 * Post meta: md5 of the post_content the builder last wrote.
		 */
		const META_MIRROR_HASH = '_uich_cb_mirror_hash';

		/**
		 * Posts currently being regenerated, by ID.
		 *
		 * regenerate() writes post_content with wp_update_post(), which fires
		 * `post_updated` → wp_save_post_revision_on_insert() → and, on the
		 * restore path, could re-enter this method through our own hooks. The
		 * guard makes the write happen once per post per request.
		 *
		 * @var array<int,bool>
		 */
		private static $regenerating = array();

		/**
		 * Get a post's stored HTML (raw `post_content`, unfiltered).
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function get( $post_id ) {
			$post = get_post( (int) $post_id );

			return $post instanceof WP_Post ? (string) $post->post_content : '';
		}

		/**
		 * Write a post's `post_content`.
		 *
		 * Deliberately a plain wp_update_post() rather than a direct $wpdb write:
		 * the point of storing content natively is that every hook a post save
		 * normally fires still fires — revisions, `save_post`, cache
		 * invalidation, sitemap and search-index integrations.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $html    HTML to store.
		 * @return bool True when the post was updated.
		 */
		public static function save( $post_id, $html ) {
			$post_id = (int) $post_id;

			if ( ! $post_id || ! get_post( $post_id ) ) {
				return false;
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					// wp_slash() because wp_update_post() unslashes what it is
					// given; without it every backslash in the markup is eaten.
					'post_content' => wp_slash( (string) $html ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return false;
			}

			// What the builder last wrote, as stored (after any kses), so an
			// edit made elsewhere afterwards can be noticed before the next
			// builder save replaces it (Uich_CB_Content::edited_elsewhere()).
			update_post_meta( $post_id, self::META_MIRROR_HASH, md5( (string) get_post_field( 'post_content', $post_id, 'raw' ) ) );

			return true;
		}

		/**
		 * Rebuild everything derived from a post's builder document.
		 *
		 * Called after every save and after every revision restore, so the HTML,
		 * the CSS and any caches can never describe a layout the AST no longer
		 * holds.
		 *
		 * @param int        $post_id  Post ID.
		 * @param array|null $document The AST. Read from storage when omitted —
		 *                             which is the restore path, where the meta
		 *                             has just been put back by core.
		 * @return void
		 */
		public static function regenerate( $post_id, $document = null ) {
			$post_id = (int) $post_id;

			if ( ! $post_id || isset( self::$regenerating[ $post_id ] ) ) {
				return;
			}

			self::$regenerating[ $post_id ] = true;

			try {
				if ( null === $document ) {
					$document = Uich_CB_Content::get_cb_content( $post_id );
				}

				$html = self::render( $document, $post_id );

				// '' means "no renderer installed", NOT "this page is empty".
				// Writing it would wipe content the user still has - unless the
				// document really is empty, in which case the old mirror would
				// keep deleted text in search results.
				$empty_doc = isset( $document['content']['html'] ) && '' === trim( (string) $document['content']['html'] )
					&& class_exists( 'Uich_CB_Render' ) && Uich_CB_Render::renderer_available();
				if ( '' !== $html || ( $empty_doc && '' !== trim( self::get( $post_id ) ) ) ) {
					self::save( $post_id, $html );
				}

				/**
				 * Rebuild any output derived from the document besides post_content.
				 *
				 * Where the CSS file / critical-CSS / asset-manifest builders hook
				 * in. Fires for saves AND restores, which is the point: a restore
				 * has to leave the derived output describing the restored AST.
				 *
				 * @since 5.2.1
				 *
				 * @param int   $post_id  Post whose output is being rebuilt.
				 * @param array $document The AST it is being rebuilt from.
				 */
				do_action( 'uich_cb/regenerate', $post_id, (array) $document );

				self::flush_caches( $post_id );
			} finally {
				unset( self::$regenerating[ $post_id ] );
			}
		}

		/**
		 * Drop the caches that describe a post's builder output.
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function flush_caches( $post_id ) {
			$post_id = (int) $post_id;

			// Core's own post + meta caches. wp_update_post() already does this
			// when the content changed, but a restore or a meta-only save may
			// not have touched the post row at all.
			clean_post_cache( $post_id );

			/**
			 * Flush caches derived from a builder document.
			 *
			 * The hook for a page-cache purge, a CDN invalidation or a
			 * transient the builder keeps.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id Post whose caches should be dropped.
			 */
			do_action( 'uich_cb/flush_caches', $post_id );
		}

		/**
		 * Render a builder document to HTML.
		 *
		 * The seam the real renderer plugs into. Until it exists this returns
		 * whatever a listener supplies, and an empty string otherwise — callers
		 * must treat '' as "nothing to mirror" and leave post_content alone.
		 *
		 * @param array $document The builder AST.
		 * @param int   $post_id  Post the document belongs to.
		 * @return string HTML, or '' when no renderer is available.
		 */
		public static function render( $document, $post_id = 0 ) {
			/**
			 * Filter the HTML rendered from a builder document.
			 *
			 * @since 5.2.1
			 *
			 * @param string $html     Rendered HTML. '' until a renderer exists.
			 * @param array  $document The builder AST.
			 * @param int    $post_id  Post the document belongs to.
			 */
			$html = apply_filters( 'uich_cb/render_cb_content', '', (array) $document, (int) $post_id );

			return is_string( $html ) ? $html : '';
		}

		/**
		 * Is a renderer available on this site?
		 *
		 * @return bool
		 */
		public static function has_renderer() {
			return (bool) has_filter( 'uich_cb/render_cb_content' );
		}
	}
}
