<?php
/**
 * Uich_CB_Revisions — server-side version history, on WordPress's own revisions.
 *
 * THE BUILDER HAS TWO HISTORY LAYERS, and they are not alternatives:
 *
 *   1. UNDO / REDO — client-side, in-session, per keystroke. It lives in the
 *      editor (src/editor/host/history.js) and never reaches the server. It is
 *      for the edit you just made, and it dies with the tab.
 *   2. REVISIONS — server-side, persistent, per save. This class. It is for
 *      "put yesterday's version back", it survives the tab, the browser and the
 *      user, and it is what another editor sees.
 *
 * Nothing here is a reimplementation of version history. It is core's revision
 * system with the builder's meta attached, which is the only way the rest of
 * WordPress keeps working: the revision browser, `wp_revisions_to_keep()`,
 * WP-CLI, exports and any plugin that reads revisions all understand these rows
 * because they are ordinary revision posts.
 *
 * THE ONE THING THAT MAKES IT WORK is `wp_post_revision_meta_keys`. A revision
 * copies the post ROW; post meta is only carried across for keys on that list.
 * Without it a restore would put back the old title and post_content and leave
 * today's AST in place — the page would silently keep the current layout while
 * claiming to be the old version.
 *
 * AUTOSAVE IS SEPARATE, deliberately. An autosave is one row per author that is
 * overwritten in place, so a chatty editor cannot push real revisions out of the
 * `wp_revisions_to_keep()` window. Core's own autosave meta handler
 * (wp_autosave_post_revisioned_meta_fields) reads `$_POST`, which a REST-driven
 * builder never populates, so this class writes the meta onto the autosave row
 * itself rather than relying on it.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Revisions' ) ) {

	/**
	 * Builder state in WordPress revisions.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Revisions {

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			add_filter( 'wp_post_revision_meta_keys', array( __CLASS__, 'revisioned_meta_keys' ), 10, 2 );

			// Core restores the revisioned meta itself (wp_restore_post_revision_meta,
			// priority 10). This runs after, so the AST is already back when the
			// derived output is rebuilt from it.
			add_action( 'wp_restore_post_revision', array( __CLASS__, 'on_restore' ), 20, 2 );
		}

		/**
		 * Add the builder's meta keys to the revisioned set.
		 *
		 * NOT redundant with `revisions_enabled` in Uich_CB_Content::register_meta().
		 * That registration happens once, on `init` at priority 20, against the
		 * post types that existed at that moment. A CPT registered later — plugins
		 * commonly register on `init` at the default priority 10 but themes and
		 * some integrations go later — would never have the meta registered, and
		 * its revisions would silently carry no builder state. This filter is
		 * evaluated at revision time, when the post type is known for certain.
		 *
		 * @param array  $keys      Meta keys already marked revisioned.
		 * @param string $post_type The post type being revisioned.
		 * @return array
		 */
		public static function revisioned_meta_keys( $keys, $post_type ) {
			$keys = is_array( $keys ) ? $keys : array();

			if ( ! in_array( (string) $post_type, Uich_CB_Content::storable_post_types(), true ) ) {
				return $keys;
			}

			return array_values( array_unique( array_merge( $keys, Uich_CB_Content::meta_keys() ) ) );
		}

		/**
		 * Does this post type keep revisions of builder state?
		 *
		 * Both halves matter: the post type has to be one we store documents on,
		 * AND it has to support revisions at all — wp_save_post_revision() is a
		 * no-op otherwise, so promising history for such a type would be a lie.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function is_supported( $post_id ) {
			$post = get_post( (int) $post_id );

			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			return in_array( $post->post_type, Uich_CB_Content::storable_post_types(), true )
				&& post_type_supports( $post->post_type, 'revisions' )
				&& wp_revisions_enabled( $post );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Creating
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Snapshot the post's current state as a revision.
		 *
		 * Called after a real save. wp_save_post_revision() decides whether one
		 * is warranted: core compares the revisioned meta against the previous
		 * revision (wp_check_revisioned_meta_fields_have_changed), so a save that
		 * changed nothing does not add a row, and a save that changed ONLY the
		 * AST — no title, no content — still does. That second case is why the
		 * meta keys have to be registered before this is ever called.
		 *
		 * @param int $post_id Post ID.
		 * @return int Revision ID, or 0 when none was created.
		 */
		public static function snapshot( $post_id ) {
			$post_id = (int) $post_id;

			if ( ! self::is_supported( $post_id ) ) {
				return 0;
			}

			$revision_id = wp_save_post_revision( $post_id );

			return is_numeric( $revision_id ) ? (int) $revision_id : 0;
		}

		/**
		 * Store the builder state as this author's AUTOSAVE.
		 *
		 * One row per author, overwritten every time, so autosaving does not
		 * consume the revision budget. Returns the autosave's post ID.
		 *
		 * The meta is written here rather than left to core: core's
		 * `wp_creating_autosave` handler reads the incoming values out of
		 * `$_POST`, and the builder autosaves over REST with a JSON body, so that
		 * handler sees nothing and the autosave would carry no builder state.
		 *
		 * @param int   $post_id  Post ID.
		 * @param array $document Builder document (AST) to autosave.
		 * @param array $settings Optional page-level settings to autosave with it.
		 * @return int|WP_Error Autosave post ID, or WP_Error.
		 */
		public static function autosave( $post_id, $document, $settings = null ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'uich_cb_no_post', __( 'That page no longer exists.', 'uichemy' ) );
			}

			if ( ! Uich_CB_Policy::can_edit( $post ) ) {
				return new WP_Error( 'uich_cb_forbidden', __( 'You are not allowed to edit this page.', 'uichemy' ) );
			}

			// wp_create_post_autosave() and wp_get_post_autosave() are admin-side
			// functions; a REST request has not loaded that file.
			if ( ! function_exists( 'wp_create_post_autosave' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$author_id = get_current_user_id();

			/*
			 * An autosave of one's OWN draft is not a separate row in WordPress:
			 * core updates the post itself, because there is no published version
			 * to protect. Mirroring that keeps "restore the autosave" meaning the
			 * same thing here as everywhere else in WordPress.
			 */
			$is_own_draft = in_array( $post->post_status, array( 'draft', 'auto-draft' ), true )
				&& (int) $post->post_author === $author_id;

			if ( $is_own_draft ) {
				Uich_CB_Content::save_cb_content(
					$post_id,
					$document,
					array(
						'revision' => false,
						'settings' => $settings,
						// The editor's own unsaved work, written where core
						// writes it: not a change made "somewhere else", so the
						// save token stays and the Publish that follows a
						// Preview does not conflict with it (A-01).
						'keep_rev' => true,
					)
				);

				return $post_id;
			}

			$autosave = wp_get_post_autosave( $post_id, $author_id );

			if ( $autosave ) {
				$autosave_id = (int) $autosave->ID;
				// Touch the row so its timestamp reflects this autosave; the
				// builder state itself lives in the meta written below.
				wp_update_post(
					array(
						'ID'            => $autosave_id,
						'post_modified' => current_time( 'mysql' ),
					)
				);
			} else {
				$created = wp_create_post_autosave(
					array(
						'post_ID'      => $post_id,
						'post_type'    => $post->post_type,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
					)
				);

				if ( is_wp_error( $created ) ) {
					return $created;
				}

				$autosave_id = (int) $created;
			}

			if ( ! $autosave_id ) {
				return new WP_Error( 'uich_cb_autosave_failed', __( 'The autosave could not be stored.', 'uichemy' ) );
			}

			self::write_meta( $autosave_id, $post_id, $document, $settings );

			/**
			 * Fires after builder state has been autosaved.
			 *
			 * @since 5.2.1
			 *
			 * @param int   $autosave_id The autosave post ID.
			 * @param int   $post_id     The post it belongs to.
			 * @param array $document    The autosaved document.
			 */
			do_action( 'uich_cb/autosaved', $autosave_id, $post_id, (array) $document );

			return $autosave_id;
		}

		/**
		 * Write builder meta onto a revision or autosave row.
		 *
		 * update_metadata() rather than update_post_meta(): the latter resolves a
		 * revision ID to its PARENT post (see its $post_id handling), so writing
		 * the autosave's state with it would overwrite the live page instead —
		 * exactly the data loss autosave exists to prevent. Core's own
		 * wp_save_revisioned_meta_fields() uses the same low-level call for the
		 * same reason.
		 *
		 * @param int        $target_id Revision / autosave post ID.
		 * @param int        $post_id   The post it belongs to — the policy baseline.
		 * @param array      $document  Builder document.
		 * @param array|null $settings  Page-level settings, or null to skip.
		 * @return void
		 */
		private static function write_meta( $target_id, $post_id, $document, $settings = null ) {
			$target_id = (int) $target_id;

			// Through the storage gateway, exactly as a real save stores it:
			// normalised (the editor sends the section-shaped document; storage
			// keeps `content: {html,css,js}`) and filtered by the code policy.
			// An autosave is rendered on preview, so it is as much a way onto
			// the page as a save is. The baseline is the parent post, not this
			// row — see Uich_CB_Content::prepare_for_storage().
			//
			// Settings passed separately are folded in BEFORE the one write:
			// schema 2 keeps them inside the document.
			$document = is_array( $document ) ? $document : array();
			if ( is_array( $settings ) ) {
				$document             = Uich_CB_Content::normalize( $document );
				$document['settings'] = $settings;
			}

			Uich_CB_Content::write_to( $target_id, Uich_CB_Content::prepare_for_storage( (int) $post_id, $document ) );

			update_metadata( 'post', $target_id, Uich_CB_Content::META_VERSION, Uich_CB_Content::SCHEMA_VERSION );
			update_metadata( 'post', $target_id, Uich_CB_Content::META_ENABLED, '1' );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Reading
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * The revision history for a post, newest first.
		 *
		 * Autosaves are excluded — they are a scratch row, not a version someone
		 * chose to keep. get_autosave() returns that one separately.
		 *
		 * @param int $post_id Post ID.
		 * @param int $offset  First revision to describe (newest is 0).
		 * @param int $limit   How many to describe; 0 for all of them.
		 * @return array<int,array> One entry per revision.
		 */
		public static function get_revisions( $post_id, $offset = 0, $limit = 0 ) {
			$posts = self::revision_posts( $post_id );
			if ( $limit > 0 || $offset > 0 ) {
				$posts = array_slice( $posts, max( 0, (int) $offset ), $limit > 0 ? (int) $limit : null );
			}
			// Described only once sliced: a row reads the revision's meta and
			// the author's avatar, which a long history should not pay for in
			// full just to show its first few.
			return array_map( array( __CLASS__, 'describe' ), $posts );
		}

		/**
		 * How many revisions get_revisions() can return, for paging.
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		public static function count_revisions( $post_id ) {
			return count( self::revision_posts( $post_id ) );
		}

		/**
		 * The post's revisions, newest first, without its autosaves.
		 *
		 * @param int $post_id Post ID.
		 * @return WP_Post[]
		 */
		private static function revision_posts( $post_id ) {
			$post_id = (int) $post_id;

			if ( ! self::is_supported( $post_id ) ) {
				return array();
			}

			$revisions = wp_get_post_revisions(
				$post_id,
				array(
					'order'   => 'DESC',
					'orderby' => 'date ID',
				)
			);

			$out = array();

			foreach ( $revisions as $revision ) {
				// wp_is_post_autosave() returns the parent ID (truthy) for an
				// autosave row and false for a real revision.
				if ( wp_is_post_autosave( $revision ) ) {
					continue;
				}

				$out[] = $revision;
			}

			return $out;
		}

		/**
		 * This author's autosave for a post, if there is one.
		 *
		 * @param int $post_id Post ID.
		 * @return array|null
		 */
		public static function get_autosave( $post_id ) {
			if ( ! function_exists( 'wp_get_post_autosave' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$autosave = wp_get_post_autosave( (int) $post_id, get_current_user_id() );

			return $autosave ? self::describe( $autosave ) : null;
		}

		/**
		 * Describe one revision row for a client.
		 *
		 * @param WP_Post $revision Revision post.
		 * @return array
		 */
		private static function describe( WP_Post $revision ) {
			$author = (int) $revision->post_author;

			return array(
				'id'        => (int) $revision->ID,
				'parent'    => (int) $revision->post_parent,
				'date'      => mysql_to_rfc3339( $revision->post_modified_gmt ) . 'Z',
				'autosave'  => (bool) wp_is_post_autosave( $revision ),
				'author'    => array(
					'id'     => $author,
					'name'   => (string) get_the_author_meta( 'display_name', $author ),
					// For the History panel's version rows, as Elementor's
					// Revisions list shows who saved each one. `404`, not
					// Gravatar's grey silhouette, for a user with no avatar:
					// the panel then shows their initial on a colour instead.
					'avatar' => (string) get_avatar_url( $author, array( 'size' => 64, 'default' => '404' ) ),
				),
				// Whether this revision actually carries builder state. A post
				// that predates the builder has revisions with no AST, and
				// restoring one would blank the layout — a client can warn.
				'hasDocument' => '' !== (string) get_metadata( 'post', $revision->ID, Uich_CB_Content::META_CONTENT, true ),
			);
		}

		/**
		 * The builder document stored on a revision.
		 *
		 * get_metadata() rather than get_post_meta(), for the same reason
		 * write_meta() uses update_metadata: post-meta helpers resolve a revision
		 * to its parent, so get_post_meta() here would return the LIVE document
		 * and every revision would look identical to the current state.
		 *
		 * @param int $revision_id Revision post ID.
		 * @return array
		 */
		public static function get_revision_cb_content( $revision_id ) {
			$raw = get_metadata( 'post', (int) $revision_id, Uich_CB_Content::META_CONTENT, true );

			if ( ! is_string( $raw ) || '' === $raw ) {
				return array();
			}

			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * The document a PREVIEW of this post should render.
		 *
		 * The editor's Preview writes the work-in-progress to this author's
		 * autosave rather than to the post, so that looking at a page cannot
		 * change what the public sees — the whole point of a preview. This is
		 * the read side of that: on a preview request the renderer asks for the
		 * autosave's document and falls back to the stored one.
		 *
		 * Per author, deliberately. Two editors previewing the same page each
		 * see their own draft, which is what WordPress does for post_content
		 * and the only answer that is not "whoever previewed last wins".
		 *
		 * @param int $post_id Post ID.
		 * @return array|null The autosaved document, or null when there is none.
		 */
		public static function get_preview_cb_content( $post_id ) {
			$post_id = (int) $post_id;

			if ( ! $post_id || ! is_user_logged_in() || ! current_user_can( 'edit_post', $post_id ) ) {
				return null;
			}

			if ( ! function_exists( 'wp_get_post_autosave' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );

			if ( ! $autosave ) {
				return null;
			}

			// An autosave older than the post is work that has since been
			// saved over — Publish does not delete it. Showing it would roll the
			// preview back to before the save; WordPress's own "newer autosave"
			// check draws the same line.
			$post = get_post( $post_id );
			if ( $post && mysql2date( 'U', $autosave->post_modified_gmt, false ) < mysql2date( 'U', $post->post_modified_gmt, false ) ) {
				return null;
			}

			$document = self::get_revision_cb_content( $autosave->ID );

			return empty( $document ) ? null : $document;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Restoring
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Restore a revision onto its parent post.
		 *
		 * Thin on purpose: wp_restore_post_revision() is what puts the post row
		 * and the revisioned meta back, and doing it any other way would skip the
		 * hooks every other plugin listens to. on_restore() below then rebuilds
		 * what is derived from the AST.
		 *
		 * @param int $revision_id Revision post ID.
		 * @return int|WP_Error Parent post ID, or WP_Error.
		 */
		public static function restore( $revision_id ) {
			// Assigned first, never inlined as `wp_get_post_revision( (int) $x )`:
			// core declares the parameter BY REFERENCE, and PHP 8 raises a fatal
			// for an expression passed to one.
			$revision_id = (int) $revision_id;
			$revision    = wp_get_post_revision( $revision_id );

			if ( ! $revision ) {
				return new WP_Error(
					'uich_cb_no_revision',
					__( 'That revision no longer exists.', 'uichemy' ),
					array( 'status' => 404 )
				);
			}

			$post_id = (int) $revision->post_parent;

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'uich_cb_forbidden',
					__( 'You are not allowed to edit this page.', 'uichemy' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			$restored = wp_restore_post_revision( $revision->ID );

			if ( ! $restored ) {
				return new WP_Error(
					'uich_cb_restore_failed',
					__( 'The revision could not be restored.', 'uichemy' ),
					array( 'status' => 500 )
				);
			}

			return $post_id;
		}

		/**
		 * After core has restored a revision: rebuild everything derived from it.
		 *
		 * The AST is back in postmeta by now (core's wp_restore_post_revision_meta
		 * runs on this same hook at priority 10). The HTML, the CSS and any caches
		 * still describe the layout that was there a moment ago, so they are
		 * regenerated from the restored document rather than left to rot.
		 *
		 * @param int $post_id     Post that was restored onto.
		 * @param int $revision_id Revision it was restored from.
		 * @return void
		 */
		public static function on_restore( $post_id, $revision_id ) {
			$post_id = (int) $post_id;

			if ( ! in_array( get_post_type( $post_id ), Uich_CB_Content::storable_post_types(), true ) ) {
				return;
			}

			Uich_CB_WPContent::regenerate( $post_id );

			/**
			 * Fires after a builder revision has been restored and its derived
			 * output rebuilt.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id     Post that was restored onto.
			 * @param int $revision_id Revision it was restored from.
			 */
			do_action( 'uich_cb/revision_restored', $post_id, (int) $revision_id );
		}
	}
}
