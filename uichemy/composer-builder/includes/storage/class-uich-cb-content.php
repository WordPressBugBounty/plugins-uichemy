<?php
/**
 * Uich_CB_Content — THE CORE STORAGE MODEL.
 *
 * One rule: a builder document is structured JSON (an AST) stored in
 * `wp_postmeta` against an ordinary WordPress post. Nothing else. That single
 * rule is what makes the model modular — pages, posts, custom post types,
 * templates and any builder-owned type added later all store their layout the
 * same way, so a new content kind needs a post type (see Uich_CB_Post_Types) and
 * nothing here changes.
 *
 * DELIBERATELY POST-TYPE AGNOSTIC. Every method takes a post ID and asks nothing
 * about what kind of post it is: a Theme Builder template's layout is stored by
 * exactly the same call a page's layout is. (Which template renders where is
 * the Theme Builder's job — UiChemy_Template_* — not this class's.)
 *
 * WRITES have one door. prepare_for_storage() normalises a document and applies
 * the code policy; write_to() is the only method that writes the meta. Save and
 * autosave both go through the pair.
 *
 * THE DOCUMENT SHAPE (schema 2). One object, two halves:
 *
 *   {
 *     "settings": { … },              page-level builder settings
 *     "content":  {
 *       "html": "", "css": "", "js": "",
 *       "before_head_end_html": "",   printed just before </head>
 *       "before_body_end_html": ""    printed just before </body>
 *     }
 *   }
 *
 * `content` is the page as plain strings — the markup, its styles, its
 * behaviour, and the page's own code for the end of <head> and of <body> —
 * which is the form the front end actually needs and the form a human can
 * read straight out of the database. `settings` is everything about the page
 * that is not the page itself. CONTENT_FIELDS below is the list, and the one
 * place a new content field is added.
 *
 * Schema 1 stored `{version, sections:[{id, settings:{raw_html, raw_css,
 * raw_js}}]}` and kept page settings in a meta row of their own. normalize()
 * still accepts that shape and folds it into this one, so a document written
 * before this change reads back correctly and is rewritten on its next save.
 *
 * Storage (all post meta, all private, all prefixed `_uichemy_`):
 *
 *   _uichemy_cb_enabled           '1' once the post has been opened in the
 *                                 builder — the switch a front-end render
 *                                 path reads.
 *   _uichemy_content              the whole document above, as JSON. Settings
 *                                 live INSIDE it, so one row is the complete
 *                                 builder state.
 *   _uichemy_cb_revision_version  schema version of the document, so a later
 *                                 format change can migrate rather than guess.
 *
 * These were `_uich_cb_enabled` / `_uich_cb_document` / `_uich_cb_version`
 * before the builder moved onto the plugin's `_uichemy_` prefix. Existing rows
 * are renamed by scripts/uich-cb-migrate-meta-keys.php; nothing reads the old
 * names any more.
 *
 * ALL THREE ARE REVISIONED — registered with `revisions_enabled` here and with
 * the `wp_post_revision_meta_keys` filter in Uich_CB_Revisions, so a revision
 * captures the complete builder state and not just the post row.
 *
 * `_uich_cb_settings` was schema 1's separate settings row. It is no longer
 * written; the constant survives so an existing row can still be read once by
 * normalize() and deleted by delete_cb_content(). It is deliberately NOT renamed
 * with the other three: it is the name of OLD data, and renaming it would stop
 * the one read that exists to find that data.
 *
 * WHY JSON IN ONE META ROW rather than a row per node: a layout is read and
 * written whole, never queried by node, so a single row is one cache-primed
 * lookup instead of hundreds — and core copies meta to a revision key by key,
 * so a row-per-node design would multiply every revision by the node count.
 * It is JSON rather than a serialized PHP array because a string survives
 * export/import, WP-CLI and database search-replace intact, where a serialized
 * array is corrupted by any byte-length change.
 *
 * `post_content` is kept as the rendered/semantic mirror — see Uich_CB_WPContent.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Content' ) ) {

	/**
	 * Per-post builder storage.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Content {

		/**
		 * Meta key: post has been opened in the Composer Builder.
		 */
		/** Save sequence is transport metadata, never part of the content schema/history. */
		const META_SAVE_REV = '_uichemy_cb_save_rev';
		private static $save_locks = array();

		/** Serialize all canonical readers/writers, including first saves (no meta row yet).
		 * MySQL advisory locks work across PHP workers and persistent object caches.
		 * Never fall back to an unlocked read/check/write if the DB refuses a lock.
		 */
		private static function with_save_lock( $post_id, $callback ) {
			global $wpdb;
			$key = 'uich_cb_' . md5( $wpdb->postmeta . ':' . (int) $post_id );
			if ( ! empty( self::$save_locks[ $key ] ) ) {
				return $callback();
			}
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
				return new WP_Error( 'uich_cb_save_busy', __( 'This page is being saved. Try again shortly.', 'uichemy' ), array( 'status' => 503 ) );
			}
			self::$save_locks[ $key ] = true;
			wp_cache_delete( (int) $post_id, 'post_meta' );
			try {
				return $callback();
			} finally {
				unset( self::$save_locks[ $key ] );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			}
		}

		const META_ENABLED = '_uichemy_cb_enabled';

		/**
		 * Meta key: the builder layout (JSON AST).
		 */
		const META_CONTENT = '_uichemy_content';

		/**
		 * Meta key: page-level builder settings (JSON).
		 */
		const META_SETTINGS = '_uich_cb_settings';

		/**
		 * Meta key: schema version of META_CONTENT.
		 */
		const META_VERSION = '_uichemy_cb_revision_version';

		/**
		 * The content fields, and the editor section setting each maps to.
		 *
		 * The editor edits a section's settings; storage keeps `content`. This
		 * map is the whole translation between the two, used in both
		 * directions (normalize/flatten on the way in, to_editor_sections on
		 * the way out). A new content field is one line here — plus its rule
		 * in Uich_CB_Code_Policy — and every reader and writer picks it up.
		 *
		 * `page_custom_code_head` / `_footer` are what the editor's Code pane
		 * writes in its "Page" scope.
		 */
		const CONTENT_FIELDS = array(
			'html'                 => 'raw_html',
			'css'                  => 'raw_css',
			'js'                   => 'raw_js',
			'before_head_end_html' => 'page_custom_code_head',
			'before_body_end_html' => 'page_custom_code_footer',
		);

		/**
		 * Content fields that belong to the PAGE, not to a section.
		 *
		 * The editor's Page scope writes the same value onto every section, so
		 * flattening takes one copy (the first non-empty) instead of joining
		 * them — joining would print the page's head code once per section.
		 */
		const PAGE_LEVEL_FIELDS = array( 'before_head_end_html', 'before_body_end_html' );

		/**
		 * Current document schema version.
		 */
		const SCHEMA_VERSION = 2;

		/**
		 * Post types the builder never offers itself for, whatever their
		 * registration says.
		 *
		 * `uichemy_template` is the Theme Builder CPT, and it is ALSO where the
		 * Composer Builder keeps its templates (Uich_CB_Post_Types::TEMPLATE).
		 * It is excluded here, from the CONTENT types, only — so the row action
		 * and the post-type lists never treat a template as a page. The
		 * block-editor button lets templates back in on its own terms
		 * (Uich_CB_Gutenberg::is_theme_template). It is still a storable type:
		 * storable_post_types() adds the builder's own types back, so a template
		 * can carry a builder document and its revisions.
		 *
		 * @var string[]
		 */
		private static $excluded_post_types = array(
			'attachment',
			'uichemy_template',
			'elementor_library',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
		);

		/**
		 * Every meta key that makes up the builder state of a post.
		 *
		 * The single list Uich_CB_Revisions revisions, so "the complete builder
		 * state is saved with each revision" stays true by construction — a key
		 * added here is revisioned without touching the revision layer.
		 *
		 * @return string[]
		 */
		public static function meta_keys() {
			// META_SETTINGS is deliberately absent: page settings live inside
			// META_CONTENT, which is revisioned, so listing the legacy row here
			// would revision a copy that is never written.
			return array(
				self::META_CONTENT,
				self::META_VERSION,
				self::META_ENABLED,
			);
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			// After Uich_CB_Post_Types::register() at priority 5, so the
			// builder-owned types exist when the meta is attached to them.
			add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
			// The WordPress Importer moves posts, not the URLs inside a builder
			// document (B-02).
			add_action( 'import_end', array( __CLASS__, 'remap_imported_urls' ) );
			// Third-party page caches keep serving the old page otherwise (J2-05).
			add_action( 'uich_cb/flush_caches', array( __CLASS__, 'purge_page_cache' ) );
		}

		/**
		 * Purge page caches after a builder save: the whole site for a template
		 * (a header or footer is on every page), the one post otherwise.
		 *
		 * @param int $post_id Post saved.
		 * @return void
		 */
		public static function purge_page_cache( $post_id ) {
			$post_id = (int) $post_id;
			if ( class_exists( 'Uich_CB_Post_Types' ) && Uich_CB_Post_Types::is_builder_type( get_post_type( $post_id ) ) ) {
				if ( function_exists( 'uich_purge_page_cache_all' ) ) {
					uich_purge_page_cache_all( $post_id, 'template' );
				}
				return;
			}
			if ( function_exists( 'uich_purge_page_cache_for_post' ) ) {
				uich_purge_page_cache_for_post( $post_id );
			}
		}

		/**
		 * After a WXR import: point every builder document at this site.
		 *
		 * The importer rewrites attachment URLs in post_content (its url_remap),
		 * but the builder document is meta it copies as-is, and the next render
		 * puts the old domain back into the page. Here the same remap, plus the
		 * export's own site URL to this one, is applied to each imported
		 * document — written both plain and JSON-escaped, since documents saved
		 * before B-04 stored `\/` — and the post is regenerated.
		 *
		 * @return void
		 */
		public static function remap_imported_urls() {
			global $wp_import;

			if ( ! is_object( $wp_import ) || empty( $wp_import->processed_posts ) ) {
				return;
			}

			$map = array();
			if ( ! empty( $wp_import->url_remap ) && is_array( $wp_import->url_remap ) ) {
				$map = $wp_import->url_remap;
			}
			$old_base = isset( $wp_import->base_url ) ? untrailingslashit( (string) $wp_import->base_url ) : '';
			$new_base = untrailingslashit( home_url() );
			if ( '' !== $old_base && $old_base !== $new_base ) {
				$map[ $old_base ] = $new_base;
			}
			if ( ! $map ) {
				return;
			}
			// Longest first, so a full attachment URL is replaced before its domain.
			uksort(
				$map,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$pairs = array();
			foreach ( $map as $from => $to ) {
				$pairs[ $from ]                             = $to;
				$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
			}

			foreach ( array_unique( array_map( 'intval', (array) $wp_import->processed_posts ) ) as $post_id ) {
				$raw = get_post_meta( $post_id, self::META_CONTENT, true );
				if ( ! is_string( $raw ) || '' === $raw ) {
					continue;
				}
				$next = strtr( $raw, $pairs );
				if ( $next === $raw ) {
					continue;
				}
				update_metadata( 'post', $post_id, self::META_CONTENT, wp_slash( $next ) );
				if ( class_exists( 'Uich_CB_WPContent' ) ) {
					Uich_CB_WPContent::regenerate( $post_id );
				}
			}
		}

		/**
		 * Register the builder meta on every post type that can carry a document.
		 *
		 * `revisions_enabled` is the declarative half of version history:
		 * wp_post_revision_meta_keys() collects every registered key carrying it.
		 * Uich_CB_Revisions adds the same keys through the filter as well, which
		 * is not redundant — see the note there.
		 *
		 * `show_in_rest` is false and `auth_callback` refuses everyone on
		 * purpose: the document is saved only through the builder's own REST
		 * routes and prepare_for_storage(), never as a raw meta field that
		 * anything with edit access — the Custom Fields box, XML-RPC, the
		 * posts endpoint — could write unfiltered code into.
		 *
		 * @return void
		 */
		public static function register_meta() {
			// Nobody through the generic meta APIs — see Uich_CB_Policy::meta_auth().
			$auth = array( 'Uich_CB_Policy', 'meta_auth' );

			$types = array(
				self::META_CONTENT => 'string',
				self::META_SETTINGS => 'string',
				self::META_VERSION  => 'integer',
				self::META_ENABLED  => 'string',
			);

			foreach ( self::storable_post_types() as $post_type ) {
				foreach ( self::meta_keys() as $key ) {
					$type = isset( $types[ $key ] ) ? $types[ $key ] : 'string';

					register_post_meta(
						$post_type,
						$key,
						array(
							'type'              => $type,
							'single'            => true,
							'default'           => ( 'integer' === $type ) ? 0 : '',
							'show_in_rest'      => false,
							// Core refuses (with a notice on every request) revisioned
							// meta on a type without revisions, e.g. WooCommerce's (P0-02).
							'revisions_enabled' => post_type_supports( $post_type, 'revisions' ),
							'auth_callback'     => $auth,
						)
					);
				}
			}
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Post types
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * CONTENT post types the builder offers itself on.
		 *
		 * Every public, UI-visible post type that supports the editor — pages and
		 * posts on a stock install, plus any CPT a site registers the same way.
		 * Computed rather than hard-coded so a site's own CPTs work without a code
		 * change, and filtered so a site can narrow or widen it.
		 *
		 * Drives the USER-FACING entry points (the block-editor button, the row
		 * action). Excludes the builder's own post types — a template is not
		 * something you "switch to the builder" from Gutenberg.
		 *
		 * @return string[]
		 */
		public static function supported_post_types() {
			$types = get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				),
				'names'
			);

			$types = array_filter(
				(array) $types,
				static function ( $post_type ) {
					if ( in_array( $post_type, self::$excluded_post_types, true ) ) {
						return false;
					}
					if ( Uich_CB_Post_Types::is_builder_type( $post_type ) ) {
						return false;
					}
					return post_type_supports( $post_type, 'editor' );
				}
			);

			/**
			 * Filter the CONTENT post types the Composer Builder can edit.
			 *
			 * @since 5.2.1
			 *
			 * @param string[] $types Post type names.
			 */
			$types = (array) apply_filters( 'uich_cb/supported_post_types', array_values( $types ) );

			return array_values( array_unique( array_map( 'strval', $types ) ) );
		}

		/**
		 * EVERY post type that can carry a builder document.
		 *
		 * The content types above PLUS the builder's own types (templates, and
		 * whatever Uich_CB_Post_Types grows later). This is the set the storage
		 * meta is registered against, the set the builder screen and REST layer
		 * accept, and the set Uich_CB_Revisions revisions.
		 *
		 * @return string[]
		 */
		public static function storable_post_types() {
			return array_values(
				array_unique(
					array_merge( self::supported_post_types(), Uich_CB_Post_Types::slugs() )
				)
			);
		}

		/**
		 * Can this post be OPENED IN THE BUILDER by the current user?
		 *
		 * The rule itself is Uich_CB_Policy::can_edit(); this stays as the name
		 * the rest of the plugin (and any extension) already calls.
		 *
		 * @param int|WP_Post|null $post Post or post ID.
		 * @return bool
		 */
		public static function is_supported( $post = null ) {
			return Uich_CB_Policy::can_edit( $post );
		}

		/**
		 * Is this a CONTENT post the block-editor button should appear on?
		 *
		 * Narrower than is_supported(): builder-owned types are excluded.
		 *
		 * @param int|WP_Post|null $post Post or post ID.
		 * @return bool
		 */
		public static function is_content_post( $post = null ) {
			$post = get_post( $post );

			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			return in_array( $post->post_type, self::supported_post_types(), true )
				&& self::is_supported( $post );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * The document
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Has this post ever been opened in the builder?
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function is_enabled( $post_id ) {
			return '1' === (string) get_post_meta( (int) $post_id, self::META_ENABLED, true );
		}

		/**
		 * Mark a post as built with (or no longer built with) the builder.
		 *
		 * @param int  $post_id Post ID.
		 * @param bool $enabled Whether the builder owns this post.
		 * @return void
		 */
		public static function set_enabled( $post_id, $enabled = true ) {
			$post_id = (int) $post_id;

			if ( $enabled ) {
				$first = '1' !== get_post_meta( $post_id, self::META_ENABLED, true ) && 0 === self::get_version( $post_id );
				update_post_meta( $post_id, self::META_ENABLED, '1' );
				if ( $first ) {
					self::default_layout( $post_id );
				}
				return;
			}

			delete_post_meta( $post_id, self::META_ENABLED );
		}

		/**
		 * A page new to the builder starts on the Canvas layout — only its own
		 * sections, no theme header, footer or title — which is what a page built
		 * here is drawn as. Only when nothing is chosen yet: a layout set before
		 * (Full width, a theme template) stays, and Page settings › Layout
		 * changes it ("Standard" is the theme's own).
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		private static function default_layout( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
				return;
			}
			// Pages only: a post keeps its theme layout — title, categories,
			// featured image, author, comments — which Canvas removed (C-01).
			if ( 'page' !== $post->post_type ) {
				return;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_wp_page_template', true ) ) {
				return;
			}

			/**
			 * Filter the layout a page new to the builder starts on ('' for the
			 * theme's own).
			 *
			 * @param string  $template Page template slug.
			 * @param WP_Post $post     The page.
			 */
			$template = (string) apply_filters( 'uich_cb/default_page_template', class_exists( 'Uich_CB_Page_Settings' ) ? Uich_CB_Page_Settings::TPL_CANVAS : 'uichemy-canvas', $post );
			if ( '' !== $template ) {
				update_post_meta( $post_id, '_wp_page_template', $template );
			}
		}

		/**
		 * The schema version a post's stored document was written with.
		 *
		 * 0 means "no document yet".
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		public static function get_version( $post_id ) {
			return (int) get_post_meta( (int) $post_id, self::META_VERSION, true );
		}

		/**
		 * Decode one of our JSON meta values.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $key     Meta key.
		 * @return array
		 */
		private static function get_json( $post_id, $key ) {
			$raw = get_post_meta( (int) $post_id, $key, true );

			if ( ! is_string( $raw ) || '' === $raw ) {
				return array();
			}

			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		/* ─────────────────────────────────────────────────────────────────
		 * The document shape
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * A document with nothing in it.
		 *
		 * Every key is present and every content field is a string, so callers
		 * can read `$doc['content']['html']` without a single isset() — which is
		 * the whole point of normalising on the way in and out.
		 *
		 * @return array
		 */
		public static function empty_cb_content() {
			return array(
				'settings' => array(),
				'content'  => array_fill_keys( array_keys( self::CONTENT_FIELDS ), '' ),
			);
		}

		/**
		 * Coerce anything into the canonical `{settings, content{…CONTENT_FIELDS}}`.
		 *
		 * Three inputs have to work, because all three reach this method:
		 *
		 *   1. The canonical shape — passed through, with missing keys filled in.
		 *   2. Schema 1's `{version, sections:[{settings:{raw_html,…}}]}` — the
		 *      sections are concatenated in order into the three content fields.
		 *      A page authored before this change therefore keeps its markup
		 *      instead of reading back empty.
		 *   3. Anything else (null, a string, a broken decode) — an empty
		 *      document, never a partial one.
		 *
		 * Flattening is one-way ON PURPOSE: this shape has no section list, so
		 * the boundaries between sections are not preserved. That is the shape
		 * that was asked for; nothing downstream can put them back.
		 *
		 * @param mixed $document Raw document.
		 * @return array Canonical document.
		 */
		public static function normalize( $document ) {
			$empty = self::empty_cb_content();

			if ( ! is_array( $document ) || empty( $document ) ) {
				return $empty;
			}

			// Schema 1 — fold the section list down into the three fields.
			if ( ! isset( $document['content'] ) && isset( $document['sections'] ) && is_array( $document['sections'] ) ) {
				$document = self::flatten_sections( $document );
			}

			$settings = ( isset( $document['settings'] ) && is_array( $document['settings'] ) )
				? $document['settings']
				: array();

			$content = ( isset( $document['content'] ) && is_array( $document['content'] ) )
				? $document['content']
				: array();

			// Every field present and a string; anything not in CONTENT_FIELDS
			// is dropped, so storage never carries keys nothing reads.
			$clean = array();
			foreach ( array_keys( self::CONTENT_FIELDS ) as $field ) {
				$clean[ $field ] = ( isset( $content[ $field ] ) && is_scalar( $content[ $field ] ) ) ? (string) $content[ $field ] : '';
			}

			return array(
				'settings' => $settings,
				'content'  => $clean,
			);
		}

		/**
		 * Schema 1 → schema 2: concatenate the section list.
		 *
		 * Sections are joined in document order with a blank line between them,
		 * so the result reads like the page it came from rather than one run-on
		 * string.
		 *
		 * @param array $document Schema 1 document.
		 * @return array Canonical-ish document (normalize() finishes the job).
		 */
		private static function flatten_sections( array $document ) {
			$parts = array_fill_keys( array_keys( self::CONTENT_FIELDS ), array() );
			// The root class of the section each JS part came from, by part.
			$js_roots = array();

			foreach ( (array) $document['sections'] as $section ) {
				if ( ! is_array( $section ) || ! isset( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
					continue;
				}

				foreach ( self::CONTENT_FIELDS as $field => $key ) {
					$value = isset( $section['settings'][ $key ] ) && is_scalar( $section['settings'][ $key ] )
						? (string) $section['settings'][ $key ]
						: '';

					if ( '' !== trim( $value ) ) {
						$parts[ $field ][] = $value;
						if ( 'js' === $field ) {
							$js_roots[] = self::root_class( isset( $section['settings'][ self::CONTENT_FIELDS['html'] ] ) ? (string) $section['settings'][ self::CONTENT_FIELDS['html'] ] : '' );
						}
					}
				}
			}

			// Each section's JS keeps a scope of its own once they are joined.
			// They used to be concatenated bare, so two sections declaring the
			// same `const` / `let` / `class` — every Composer section declares
			// `const uichemy_controller_bridge` — made the whole joined script a
			// SyntaxError, and NO section's JS ran (a page imported from
			// Elementor lost all of its animation). A block scopes exactly those
			// declarations and nothing else: `var` and functions behave as
			// before, and UiChemy_Motion::compile() still finds the first
			// declaration. One section needs no block, and a part already
			// wrapped by an earlier save is not wrapped again.
			if ( count( $parts['js'] ) > 1 ) {
				$parts['js'] = array_map( array( __CLASS__, 'scope_js' ), $parts['js'], $js_roots );
			}

			$content = array();
			foreach ( $parts as $field => $values ) {
				$content[ $field ] = in_array( $field, self::PAGE_LEVEL_FIELDS, true )
					? (string) reset( $values )
					: implode( "\n\n", $values );
			}

			return array(
				'settings' => ( isset( $document['settings'] ) && is_array( $document['settings'] ) ) ? $document['settings'] : array(),
				'content'  => $content,
			);
		}

		/** Marks a section's JS that flatten_sections() has put in a block of its own. */
		const JS_SCOPE_MARK = '/* uich-cb:section */';

		/**
		 * One section's JS in a block of its own, so its `const` / `let` /
		 * `class` declarations cannot collide with another section's once the
		 * page's JS is joined into one script (see flatten_sections()).
		 *
		 * The block's first line names the section it belongs to — `/* uich-cb:root
		 * <class> *\/`, a class of the section's root element — so the editor's
		 * Animation panel can give each section its own controls (lib/motion.js
		 * motionScopeFor). Same bytes as the editor's scopeJs().
		 *
		 * @param string $js   A section's JS.
		 * @param string $root A class of the section's root element, or ''.
		 * @return string
		 */
		private static function scope_js( $js, $root = '' ) {
			$js = (string) $js;
			if ( 0 === strpos( ltrim( $js ), self::JS_SCOPE_MARK ) ) {
				return $js;
			}
			$root = (string) $root;
			$mark = ( '' !== $root && preg_match( '/^[A-Za-z_][\w-]*$/', $root ) ) ? "/* uich-cb:root {$root} */\n" : '';
			return self::JS_SCOPE_MARK . "{\n" . $mark . $js . "\n}";
		}

		/**
		 * The class a section's root element is known by: its first `uichemy-…`
		 * class (every Composer section's root carries one), else its first class.
		 *
		 * @param string $html A section's HTML.
		 * @return string The class, or '' when the root has none.
		 */
		private static function root_class( $html ) {
			if ( ! preg_match( '/<[a-zA-Z][\w-]*\b[^>]*?\sclass\s*=\s*(["\'])(.*?)\1/s', (string) $html, $m ) ) {
				return '';
			}
			$classes = preg_split( '/\s+/', trim( $m[2] ) );
			foreach ( $classes as $class ) {
				if ( 0 === strpos( $class, 'uichemy-' ) ) {
					return $class;
				}
			}
			return isset( $classes[0] ) ? (string) $classes[0] : '';
		}

		/**
		 * A stored document's `content` as the editor's section list.
		 *
		 * Split out of to_editor_cb_content() because the preview path renders a
		 * document it already HAS — the autosave's — rather than one to read
		 * from a post. Both go through here, so a preview and the live page can
		 * never disagree about how storage becomes sections.
		 *
		 * @param array $document A normalized stored document.
		 * @return array<int,array> Editor sections.
		 */
		public static function to_editor_sections( $document ) {
			$document = self::normalize( is_array( $document ) ? $document : array() );
			$content  = $document['content'];

			// No section at all for an untouched page: the editor shows its own
			// empty state, which is friendlier than one empty section.
			if ( '' === implode( '', $content ) ) {
				return array();
			}

			$settings = array();
			foreach ( self::CONTENT_FIELDS as $field => $key ) {
				$settings[ $key ] = $content[ $field ];
			}

			return array(
				array(
					'id'       => 'uich-cb-page',
					'settings' => $settings,
				),
			);
		}

		/**
		 * The document in the shape the VENDORED editor speaks.
		 *
		 * The editor under composer-builder/src/editor is not hand-edited, and it
		 * works in `{version, rev, sections:[{id, settings:{raw_html,…}}]}`.
		 * Storage is `{settings, content}`. This is the one place that bridges
		 * the two, so neither side has to know about the other.
		 *
		 * The whole page becomes ONE section, because that is what this shape
		 * can say — it has no section boundaries to restore. Editing and saving
		 * from the editor therefore round-trips the page as a single section.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public static function to_editor_cb_content( $post_id ) {
			return self::with_save_lock( $post_id, static function () use ( $post_id ) {
				return self::editor_content_unlocked( $post_id );
			} );
		}

		private static function editor_content_unlocked( $post_id ) {
			$document = self::get_cb_content( $post_id );
			$sections = self::to_editor_sections( $document );

			return array(
				'version'  => self::SCHEMA_VERSION,
				'rev'      => (int) get_post_meta( $post_id, self::META_SAVE_REV, true ),
				'settings' => (object) $document['settings'],
				'sections' => $sections,
			);
		}

		/**
		 * The document ready to hand to JSON.
		 *
		 * `settings` is an associative array in PHP, and an EMPTY associative
		 * array encodes as `[]`, not `{}` — so a page with no settings would
		 * reach JavaScript with `document.settings` as an array. Anything doing
		 * `settings.layout` then reads undefined off an Array, and anything
		 * doing `Object.keys()` still works, which is exactly the kind of bug
		 * that survives review. Casting to stdClass pins it to an object at
		 * every size.
		 *
		 * json_decode(…, true) turns it back into an array on the way in, so
		 * this only affects the encoded form.
		 *
		 * @param array $document Canonical document.
		 * @return array
		 */
		public static function for_json( array $document ) {
			$document['settings'] = (object) ( isset( $document['settings'] ) ? (array) $document['settings'] : array() );

			return $document;
		}

		/**
		 * Write an already-prepared document onto a row: the post itself, or
		 * one of its autosave / revision rows.
		 *
		 * The ONLY place the document meta is written. Callers prepare the
		 * document first (prepare_for_storage) — this does not, because the
		 * policy baseline is the post, which it cannot know from an autosave
		 * row's ID alone.
		 *
		 * update_metadata(), not update_post_meta(): the post-meta helpers
		 * resolve a revision ID to its parent and would write onto the live
		 * post instead of the row asked for.
		 *
		 * @param int   $target_id Row to write to.
		 * @param array $document  Normalised, policy-checked document.
		 * @param bool  $bump_rev  Advance the save token (see save_cb_content()'s
		 *                         `keep_rev`).
		 * @return bool
		 */
		public static function write_to( $target_id, array $document, $bump_rev = true ) {
			$result = self::with_save_lock( $target_id, static function () use ( $target_id, $document, $bump_rev ) {
				return self::write_unlocked( $target_id, $document, $bump_rev );
			} );
			return ! is_wp_error( $result ) && $result;
		}

		private static function write_unlocked( $target_id, array $document, $bump_rev = true ) {
			// Slashes and non-ASCII unescaped, so a URL is stored as written and
			// `wp search-replace` / a migration plugin can find it (B-04).
			$json = wp_json_encode( self::for_json( $document ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( false === $json ) {
				return false;
			}

			// Advance before content: a later write failure may cause a harmless
			// conflict, but must never leave changed content with an old token.
			$rev = (int) get_post_meta( $target_id, self::META_SAVE_REV, true );
			if ( $bump_rev && ! update_metadata( 'post', (int) $target_id, self::META_SAVE_REV, $rev + 1 ) ) {
				return false;
			}
			$written = update_metadata( 'post', (int) $target_id, self::META_CONTENT, wp_slash( $json ) );
			if ( false === $written && get_post_meta( $target_id, self::META_CONTENT, true ) !== $json ) {
				return false;
			}

			return true;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Reading
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * The stored document, decoded and normalised.
		 *
		 * ALWAYS the canonical shape — a post that has never been opened in the
		 * builder reads back as an empty document, not as an empty array.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public static function get_cb_content( $post_id ) {
			$stored = self::get_json( $post_id, self::META_CONTENT );

			// Schema 1 kept settings in a row of their own. Fold a leftover one
			// in on read so an upgraded page does not lose them; the next save
			// writes them inside the document and delete_cb_content() removes the
			// row for good.
			if ( ! isset( $stored['settings'] ) ) {
				$legacy = self::get_json( $post_id, self::META_SETTINGS );
				if ( ! empty( $legacy ) ) {
					$stored['settings'] = $legacy;
				}
			}

			return self::normalize( $stored );
		}

		/**
		 * Just the page-level settings.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public static function get_cb_settings( $post_id ) {
			$document = self::get_cb_content( $post_id );

			return $document['settings'];
		}

		/**
		 * Just the content triplet.
		 *
		 * @param int $post_id Post ID.
		 * @return array{html:string,css:string,js:string}
		 */
		public static function get_cb_content_fields( $post_id ) {
			$document = self::get_cb_content( $post_id );

			return $document['content'];
		}

		/**
		 * Replace the page-level settings, leaving the content untouched.
		 *
		 * A read-modify-write now that settings live inside the document rather
		 * than in a row of their own.
		 *
		 * @param int   $post_id  Post ID.
		 * @param array $settings Settings.
		 * @return bool
		 */
		public static function save_cb_settings( $post_id, $settings ) {
			if ( ! is_array( $settings ) ) {
				return false;
			}

			$result = self::with_save_lock( $post_id, static function () use ( $post_id, $settings ) {
				$document             = self::get_cb_content( $post_id );
				$document['settings'] = $settings;
				return self::write_to( $post_id, self::prepare_for_storage( $post_id, $document ) );
			} );
			return ! is_wp_error( $result ) && $result;
		}

		/**
		 * The one door a document goes through on its way into storage.
		 *
		 * Normalises it to the canonical shape and applies the code policy
		 * (Uich_CB_Code_Policy) for the user saving it. Every writer — save,
		 * autosave, anything added later — calls this, so what the policy
		 * allows is decided here and nowhere else.
		 *
		 * The baseline for "unchanged" is the post's LIVE document, never an
		 * autosave: an autosave is this user's own unsaved work, so treating it
		 * as trusted would let a second autosave launder the first.
		 *
		 * @param int   $post_id  Post the document belongs to (not an autosave row).
		 * @param array $document Incoming document, any accepted shape.
		 * @return array Normalised document, safe to store.
		 */
		public static function prepare_for_storage( $post_id, $document ) {
			$document = self::normalize( is_array( $document ) ? $document : array() );
			$stored   = self::get_cb_content( (int) $post_id );

			$document['content'] = Uich_CB_Code_Policy::apply( $document['content'], $stored['content'] );

			return $document;
		}

		/**
		 * Persist a builder document.
		 *
		 * Writes the AST, mirrors the rendered HTML into `post_content` when a
		 * renderer is available, and — unless told otherwise — asks WordPress to
		 * snapshot the result as a revision.
		 *
		 * @param int   $post_id  Post ID.
		 * @param array $document Builder document (AST).
		 * @param array $args     {
		 *     Optional.
		 *
		 *     @type bool  $revision Create a revision after saving. Default true.
		 *                           FALSE is the autosave path, which stores its
		 *                           own snapshot instead — see Uich_CB_Revisions.
		 *     @type array $settings Page-level settings to store in the same pass,
		 *                           so one revision captures both.
		 * }
		 * @return bool True when the document was written.
		 */
		public static function save_cb_content( $post_id, $document, $args = array() ) {
			$result = self::with_save_lock( $post_id, static function () use ( $post_id, $document, $args ) {
				if ( array_key_exists( 'expected_rev', $args ) && (int) $args['expected_rev'] !== (int) get_post_meta( $post_id, self::META_SAVE_REV, true ) ) {
					return new WP_Error( 'uich_cb_conflict', __( 'A newer version is saved. Your changes have not been written.', 'uichemy' ), array( 'status' => 409, 'document' => self::editor_content_unlocked( $post_id ) ) );
				}
				$result = self::save_unlocked( $post_id, $document, $args );
				return $result && ! empty( $args['return_document'] ) ? self::editor_content_unlocked( $post_id ) : $result;
			} );
			// Legacy internal callers expect a bool, not a truthy WP_Error.
			return is_wp_error( $result ) && ! array_key_exists( 'expected_rev', $args ) ? false : $result;
		}

		private static function save_unlocked( $post_id, $document, $args ) {
			$post_id = (int) $post_id;

			if ( ! is_array( $document ) || ! $post_id || ! get_post( $post_id ) ) {
				return false;
			}

			$args = wp_parse_args(
				$args,
				array(
					'revision' => true,
					'settings' => null,
				)
			);

			// Normalised BEFORE the write, so what lands in the database is
			// always the canonical shape no matter what the caller passed —
			// including the editor's schema-1 section list.
			$document = self::normalize( $document );

			// The settings argument wins over anything carried in the document,
			// and is folded in rather than written to a row of its own: one row
			// is the whole document, so one revision captures both halves.
			if ( is_array( $args['settings'] ) ) {
				$document['settings'] = $args['settings'];
			}

			$document = self::prepare_for_storage( $post_id, $document );

			if ( ! self::write_to( $post_id, $document, empty( $args['keep_rev'] ) ) ) {
				return false;
			}

			update_post_meta( $post_id, self::META_VERSION, self::SCHEMA_VERSION );
			self::set_enabled( $post_id, true );

			// The semantic mirror + any derived caches. Its wp_update_post() fires
			// core's post_updated revision hook; a save that asked for no revision
			// (the editor's autosave of its own draft) must not get one that way,
			// or every autosave pushed a real revision out of the history.
			$core_revision = ! $args['revision'] && has_action( 'post_updated', 'wp_save_post_revision' );
			if ( $core_revision ) {
				remove_action( 'post_updated', 'wp_save_post_revision', 10 );
			}
			try {
				Uich_CB_WPContent::regenerate( $post_id, $document );
			} finally {
				if ( $core_revision ) {
					add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
				}
			}

			/**
			 * Fires after a builder document has been stored.
			 *
			 * @since 5.2.1
			 *
			 * @param int   $post_id  Post that was saved.
			 * @param array $document The stored document.
			 */
			do_action( 'uich_cb/cb_content_saved', $post_id, $document );

			// LAST, so the revision captures the meta, the regenerated
			// post_content and the settings together rather than a half-written
			// state. Core compares our revisioned meta against the previous
			// revision, so a save that changed nothing does not create one.
			if ( $args['revision'] ) {
				Uich_CB_Revisions::snapshot( $post_id );
			}

			return true;
		}

		/**
		 * Remove a post's builder document and flags.
		 *
		 * `post_content` is left alone: it is the readable fallback, and deleting
		 * it would empty the page rather than just detach the builder.
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function delete_cb_content( $post_id ) {
			$post_id = (int) $post_id;

			delete_post_meta( $post_id, self::META_CONTENT );
			delete_post_meta( $post_id, self::META_SETTINGS );
			delete_post_meta( $post_id, self::META_VERSION );
			self::set_enabled( $post_id, false );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * URLs
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Nonce action guarding the builder screen for one post.
		 *
		 * Per-post so a nonce minted for one page cannot open another.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function nonce_action( $post_id ) {
			return 'uich_cb_edit_' . (int) $post_id;
		}

		/**
		 * URL that opens the builder for a post.
		 *
		 * Goes through post.php (not admin.php) so the request carries the same
		 * post context WordPress gives the block editor; wp-admin/admin.php,
		 * which post.php includes, is what fires our `admin_action_` hook.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function edit_url( $post_id ) {
			$post_id = (int) $post_id;

			return add_query_arg(
				array(
					'post'     => $post_id,
					'action'   => UICH_CB_ACTION,
					'_wpnonce' => wp_create_nonce( self::nonce_action( $post_id ) ),
				),
				admin_url( 'post.php' )
			);
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Untitled new posts
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Is this WordPress's placeholder title for a post nobody has named?
		 *
		 * wp_insert_post() gives every auto-draft the title "Auto Draft" (in the
		 * site's language). The block editor hides it and never sends a title it
		 * was not given, so a page opened straight in the builder keeps it — and
		 * publishing then derives the slug from it, putting the page at
		 * /auto-draft/.
		 *
		 * @param string $title Post title.
		 * @return bool
		 */
		public static function is_placeholder_title( $title ) {
			$title = (string) $title;

			return 'Auto Draft' === $title || __( 'Auto Draft' ) === $title; // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string, matched as core translates it.
		}

		/**
		 * Is this slug one WordPress derived from the placeholder title?
		 *
		 * `auto-draft`, or `auto-draft-2` … once the first is taken.
		 *
		 * @param string $slug Post slug.
		 * @return bool
		 */
		private static function is_placeholder_slug( $slug ) {
			return 1 === preg_match( '/^auto-draft(-\d+)?$/', (string) $slug );
		}

		/**
		 * The title an untitled post gets when it is opened in the builder.
		 *
		 * `uichemy-page #123`: a name that lists the page as a UiChemy page in
		 * the posts list and stays unique by carrying the post ID.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function default_title( $post_id ) {
			return 'uichemy-page #' . (int) $post_id;
		}

		/**
		 * Does this post still need a name — empty, or WordPress's placeholder?
		 *
		 * @param string $title Post title.
		 * @return bool
		 */
		private static function is_untitled( $title ) {
			return '' === trim( (string) $title ) || self::is_placeholder_title( $title );
		}

		/**
		 * Make a post opened in the builder for the first time a real draft.
		 *
		 * A post fresh from "Add New" is an `auto-draft`: invisible in the posts
		 * list and deleted by WordPress after seven days. Opening it in the
		 * builder is a decision to build it, so leaving the builder without
		 * publishing has to leave a draft to come back to. The block-editor
		 * button saves before it navigates, which usually does this already;
		 * this is the server making sure, whichever way the builder was reached.
		 *
		 * An untitled post (empty, or WordPress's "Auto Draft" placeholder) is
		 * named default_title() at the same time, whatever its status: there is
		 * no author-chosen title to overwrite, and it keeps the draft from
		 * listing as "(no title)" or publishing at /auto-draft/.
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function claim_as_draft( $post_id ) {
			$post = get_post( (int) $post_id );

			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$postarr = array();

			if ( 'auto-draft' === $post->post_status ) {
				$postarr['post_status'] = 'draft';
			}

			if ( self::is_untitled( $post->post_title ) ) {
				$postarr['post_title'] = self::default_title( $post->ID );
			}

			if ( empty( $postarr ) ) {
				return;
			}

			$postarr['ID'] = $post->ID;

			// No revision for this. It changes the status and nothing a revision
			// records, and a revision of an empty page is a row in the history
			// that restores to nothing. wp_save_post_revision() returns on this
			// check before it creates or prunes anything, so the post's existing
			// revisions (it has none yet) are not touched.
			add_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
			wp_update_post( $postarr );
			remove_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
		}

		/**
		 * Post fields that replace the placeholder title and slug on publish.
		 *
		 * For a post that still carries them when it goes live — one opened in
		 * the builder before claim_as_draft() existed, or reached some other way.
		 * An empty post_name makes wp_insert_post() build the slug again, from
		 * the new default_title(), e.g. `uichemy-page-123`.
		 *
		 * @param WP_Post $post Post being published.
		 * @return array Fields to merge into the wp_update_post() call.
		 */
		public static function placeholder_fix( WP_Post $post ) {
			$fields = array();

			if ( self::is_untitled( $post->post_title ) ) {
				$fields['post_title'] = self::default_title( $post->ID );
			}

			// Only a slug derived from the placeholder, and only when the post
			// is not live yet: renaming a published page's URL is the author's
			// call, not a side effect of pressing Publish again.
			if ( 'publish' !== $post->post_status && ( '' === $post->post_name || self::is_placeholder_slug( $post->post_name ) ) ) {
				$fields['post_name'] = '';
			}

			return $fields;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Content made elsewhere
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Does this post have content the builder has never seen — a block
		 * editor, Classic editor, MCP or Elementor page opened here for the
		 * first time? Opening it claims the post, and from then on the front end
		 * shows the builder document, so that content must be adopted (or the
		 * user must decide) before the claim, never lost to it.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function has_foreign_content( $post_id ) {
			$post_id = (int) $post_id;
			if ( self::is_enabled( $post_id ) || 0 !== self::get_version( $post_id ) ) {
				return false;
			}
			return '' !== trim( (string) get_post_field( 'post_content', $post_id ) );
		}

		/**
		 * Was this builder page's post_content changed outside the builder (the
		 * block editor, Classic, REST) since the builder last wrote it? Those
		 * edits are not shown on the site, and the next builder save replaces
		 * them, so the builder says so before it opens (U-03).
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function edited_elsewhere( $post_id ) {
			$post_id = (int) $post_id;
			if ( ! self::is_enabled( $post_id ) || ! class_exists( 'Uich_CB_WPContent' ) ) {
				return false;
			}
			$hash = (string) get_post_meta( $post_id, Uich_CB_WPContent::META_MIRROR_HASH, true );
			return '' !== $hash && md5( (string) get_post_field( 'post_content', $post_id, 'raw' ) ) !== $hash;
		}

		/**
		 * Take a post's existing content into the builder as one HTML section.
		 *
		 * Blocks are rendered (do_blocks()) and classic content gets its
		 * paragraphs (wpautop()), so the section shows what the page showed.
		 * The original post_content is saved as a revision first, so the page
		 * as it was can always be restored from its history. A page that
		 * arrives with content keeps the theme layout it was designed for: the
		 * Canvas default is only for pages started in the builder.
		 *
		 * @param int $post_id Post ID.
		 * @return bool Whether a document was stored.
		 */
		public static function adopt_post_content( $post_id ) {
			$post_id = (int) $post_id;
			if ( ! self::has_foreign_content( $post_id ) ) {
				return false;
			}

			if ( post_type_supports( get_post_type( $post_id ), 'revisions' ) ) {
				wp_save_post_revision( $post_id );
			}

			$raw  = (string) get_post_field( 'post_content', $post_id );
			$html = has_blocks( $raw ) ? do_blocks( $raw ) : wpautop( $raw );

			$document                    = self::empty_cb_content();
			$document['content']['html'] = '<section class="uich-cb-imported">' . "\n" . trim( $html ) . "\n" . '</section>';

			return true === self::save_cb_content( $post_id, $document );
		}

		/**
		 * URL back to Theme Builder for templates, or the post's WordPress edit screen.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function exit_url( $post_id ) {
			if ( Uich_CB_Post_Types::TEMPLATE === get_post_type( $post_id ) ) {
				return admin_url( 'admin.php?page=uichemy#/theme-builder' );
			}

			return add_query_arg(
				array(
					'post'   => (int) $post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
		}
	}
}
