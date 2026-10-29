<?php
/**
 * The page record — post meta `_uichemy_seo`, a rebuildable CACHE only.
 *
 * Holds what the page's markup says (`derived`, including the parsed SEO tags
 * of the page's before-</head> code under `derived.head`) and the audit
 * findings, so the front end never parses HTML. It holds no decisions: the page
 * head code is the source of truth, and `fields` is a read-only view of its
 * SEO tags, rebuilt with the rest. Delete the meta and the next save restores
 * it exactly.
 *
 * Rebuild triggers are STORAGE-LEVEL (plan §4.2): every content write — any MCP
 * action, any editor save, any path that bypasses the builder drivers — ends in
 * a write to `_elementor_data`, `_bricks_page_content*` or `post_content`. We
 * listen there, so no Composer file needs a hook added and no path is missed.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Record' ) ) {

	/**
	 * Record storage + rebuild.
	 */
	class Uich_SEO_Record {

		const META          = '_uichemy_seo';
		const VERSION       = 4; // 2: derived section_ld; page head read with the author rules. 3: meta_automatic check. 4: share_image_missing check.
		const REBUILD_EVENT = 'uich_seo_rebuild';

		/** Rebuilt inline at shutdown; the rest go to the async queue. */
		const SYNC_LIMIT = 10;

		/**
		 * Field contract (PRD §14 + §19.2).
		 */
		const FIELDS = array(
			'title',
			'description',
			'social_title',
			'social_description',
			'share_image',
			'canonical',
			'content_type',
			'index',
			'follow',
			'robots_advanced',
			'redirect_to',
			'exclude_ai',
			'schema_extra',
			'og_locale',
		);

		/**
		 * Meta keys whose write means "the page's HTML changed". `_uichemy_content`
		 * is the Composer Builder document (html + head code): without it a page
		 * edited through the builder or MCP kept printing the record derived
		 * before the edit, so a <title> added to its head code never showed.
		 */
		const WATCHED_META = array( '_elementor_data', '_uichemy_content' );

		/**
		 * Posts touched in this request.
		 *
		 * @var array<int,bool>
		 */
		private static $dirty = array();

		/**
		 * True while rebuilding, so our own writes do not re-dirty the post.
		 *
		 * @var bool
		 */
		private static $rebuilding = false;

		/**
		 * Wire the triggers.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
			add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
			add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
			add_action( 'wp_after_insert_post', array( __CLASS__, 'on_insert' ), 10, 4 );
			add_action( 'shutdown', array( __CLASS__, 'flush_dirty' ), 5 );
			add_action( self::REBUILD_EVENT, array( __CLASS__, 'run_queue' ) );
		}

		// ------------------------------------------------------------------
		// Triggers
		// ------------------------------------------------------------------

		/**
		 * Meta write listener.
		 *
		 * @param int|int[] $meta_id   Meta id(s).
		 * @param int       $object_id Post id.
		 * @param string    $key       Meta key.
		 * @return void
		 */
		public static function on_meta( $meta_id, $object_id, $key ) {
			if ( self::$rebuilding ) {
				return;
			}
			$key = (string) $key;
			if ( in_array( $key, self::WATCHED_META, true ) || 0 === strpos( $key, '_bricks_page_content' ) ) {
				self::mark_dirty( $object_id );
				return;
			}
			if ( '_wp_attachment_image_alt' === $key ) {
				foreach ( self::posts_using_attachment( $object_id ) as $pid ) {
					self::mark_dirty( $pid );
				}
			}
		}

		/**
		 * Post write listener (Gutenberg, classic, wp_update_post from any driver).
		 *
		 * @param int          $post_id     Post id.
		 * @param WP_Post      $post        Post after.
		 * @param bool         $update      Update or insert.
		 * @param WP_Post|null $post_before Post before.
		 * @return void
		 */
		public static function on_insert( $post_id, $post, $update, $post_before ) {
			if ( self::$rebuilding || ! $post instanceof WP_Post ) {
				return;
			}
			if ( $post_before instanceof WP_Post
				&& $post_before->post_content === $post->post_content
				&& $post_before->post_title === $post->post_title
				&& $post_before->post_excerpt === $post->post_excerpt
				&& $post_before->post_status === $post->post_status
				&& $post_before->post_password === $post->post_password
			) {
				return;
			}
			self::mark_dirty( $post_id );
		}

		/**
		 * Queue a post for rebuild at the end of this request.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function mark_dirty( $post_id ) {
			$post_id = absint( $post_id );
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post || ! self::is_eligible( $post ) ) {
				return;
			}
			self::$dirty[ $post_id ] = true;
		}

		/**
		 * Does this post get a record?
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function is_eligible( $post ) {
			if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
				return false;
			}
			if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
				return false;
			}
			if ( class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post->post_type ) {
				return true; // Pattern records.
			}
			if ( in_array( $post->post_type, Uich_SEO_Settings::internal_types(), true ) ) {
				return false; // Builder internals (kits, library templates) are not content.
			}
			$obj = get_post_type_object( $post->post_type );
			return $obj && ( ! empty( $obj->public ) || ! empty( $obj->publicly_queryable ) );
		}

		/**
		 * Rebuild the dirty set: a few inline, the remainder async.
		 *
		 * @return void
		 */
		public static function flush_dirty() {
			if ( empty( self::$dirty ) ) {
				return;
			}
			$ids         = array_keys( self::$dirty );
			self::$dirty = array();
			$now         = array_slice( $ids, 0, self::SYNC_LIMIT );
			$later       = array_slice( $ids, self::SYNC_LIMIT );
			foreach ( $now as $id ) {
				self::rebuild( $id );
			}
			if ( $later ) {
				self::queue( $later );
			}
		}

		/**
		 * Queue ids for an async rebuild.
		 *
		 * @param int[] $ids Post ids.
		 * @return void
		 */
		public static function queue( array $ids ) {
			$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
			foreach ( array_chunk( $ids, 25 ) as $i => $chunk ) {
				wp_schedule_single_event( time() + 5 + $i, self::REBUILD_EVENT, array( $chunk ) );
			}
		}

		/**
		 * Cron handler.
		 *
		 * @param int[] $ids Post ids.
		 * @return void
		 */
		public static function run_queue( $ids ) {
			foreach ( (array) $ids as $id ) {
				self::rebuild( (int) $id );
			}
		}

		/**
		 * Posts that reference an attachment (for alt-text changes). Bounded.
		 *
		 * @param int $attachment_id Attachment.
		 * @return int[]
		 */
		private static function posts_using_attachment( $attachment_id ) {
			global $wpdb;
			$attachment_id = absint( $attachment_id );
			$file          = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( ! $attachment_id || ! $file ) {
				return array();
			}
			$base = pathinfo( (string) $file, PATHINFO_FILENAME );
			$like = '%' . $wpdb->esc_like( $base ) . '%';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','future','private') AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s LIMIT 50", $like ) );
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_bricks_page_content_2','_bricks_page_content') AND meta_value LIKE %s LIMIT 50", $like ) ) );
			// phpcs:enable
			return array_values( array_unique( array_map( 'intval', $ids ) ) );
		}

		// ------------------------------------------------------------------
		// Read / write
		// ------------------------------------------------------------------

		/**
		 * The record, normalised. Never null.
		 *
		 * @param int $post_id Post id.
		 * @return array
		 */
		public static function get( $post_id ) {
			$raw = get_post_meta( absint( $post_id ), self::META, true );
			$raw = is_array( $raw ) ? $raw : array();
			// Not derived yet (the async rebuild is pending): read the page head code
			// now, so its SEO tags are printed on the very first request — Composer's
			// printer has already removed them from the raw head code.
			if ( empty( $raw['built_at'] ) && ! isset( $raw['derived']['head'] ) && absint( $post_id ) ) {
				$raw['derived']         = isset( $raw['derived'] ) && is_array( $raw['derived'] ) ? $raw['derived'] : array();
				$raw['derived']['head'] = Uich_SEO_Head::page_trusted( absint( $post_id ) );
			}
			return self::normalize( $raw );
		}

		/**
		 * Is there a derived cache yet?
		 *
		 * @param array $record Record.
		 * @return bool
		 */
		public static function is_built( array $record ) {
			return ! empty( $record['built_at'] );
		}

		/**
		 * Fill missing keys.
		 *
		 * @param array $r Raw.
		 * @return array
		 */
		public static function normalize( array $r ) {
			return array(
				'v'              => self::VERSION,
				'kind'           => isset( $r['kind'] ) && 'pattern' === $r['kind'] ? 'pattern' : 'instance',
				'built_at'       => isset( $r['built_at'] ) ? (int) $r['built_at'] : 0,
				'source_sig'     => isset( $r['source_sig'] ) ? (string) $r['source_sig'] : '',
				// A view of the page head code's SEO tags, never stored (see save()).
				'fields'         => Uich_SEO_Head::to_fields( isset( $r['derived']['head'] ) && is_array( $r['derived']['head'] ) ? $r['derived']['head'] : array() ),
				'derived'        => isset( $r['derived'] ) && is_array( $r['derived'] ) ? $r['derived'] : array(),
				'findings'       => isset( $r['findings'] ) && is_array( $r['findings'] ) ? $r['findings'] : array(),
				'ignored_checks' => isset( $r['ignored_checks'] ) && is_array( $r['ignored_checks'] ) ? array_values( $r['ignored_checks'] ) : array(),
				// Site-wide checks (PRD §18.6): filled only by the batched pass, cached 24h.
				'site_findings'  => isset( $r['site_findings'] ) && is_array( $r['site_findings'] ) ? $r['site_findings'] : array(),
				'site_checked'   => isset( $r['site_checked'] ) ? (int) $r['site_checked'] : 0,
			);
		}

		/**
		 * Persist a record.
		 *
		 * @param int   $post_id Post id.
		 * @param array $record  Record.
		 * @return void
		 */
		public static function save( $post_id, array $record ) {
			$record = self::normalize( $record );
			unset( $record['fields'] ); // A view of the head code, not data.
			self::$rebuilding = true;
			update_post_meta( $post_id, self::META, wp_slash( $record ) );
			self::$rebuilding = false;
		}

		/**
		 * Write page SEO values into the page's before-</head> code.
		 *
		 * Each value in $patch is either a value (written as its tag) or null / ''
		 * (the tag is removed, so the field follows the page again). `redirect_to`
		 * is not a tag: it becomes a redirect in the Redirects table.
		 *
		 * @param int    $post_id Post id.
		 * @param array  $patch   field => value.
		 * @param string $state   Unused; kept for callers of the old signature.
		 * @param string $from    Who asked (agent | user | imported), for the redirect origin.
		 * @return array|WP_Error Rebuilt record, or errors keyed by field.
		 */
		public static function set_fields( $post_id, array $patch, $state = '', $from = 'user' ) {
			unset( $state );
			$post_id = absint( $post_id );
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'uich_seo_no_post', __( 'That page does not exist.', 'uichemy' ) );
			}
			$errors = new WP_Error();
			$clean  = array();

			foreach ( $patch as $key => $value ) {
				if ( ! in_array( $key, self::FIELDS, true ) ) {
					continue;
				}
				if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
					$clean[ $key ] = null;
					continue;
				}
				$v = self::sanitize_field( $key, $value );
				if ( is_wp_error( $v ) ) {
					$errors->add( $key, $v->get_error_message() );
					continue;
				}
				$clean[ $key ] = $v;
			}
			if ( $errors->has_errors() ) {
				return $errors;
			}

			if ( array_key_exists( 'redirect_to', $clean ) ) {
				do_action( 'uichemy_seo_page_redirect', $post_id, (string) $clean['redirect_to'], sanitize_key( $from ) );
				unset( $clean['redirect_to'] );
				// The Redirects table refused it (a loop, a clash…): report it on the field.
				$redirect_error = ( class_exists( 'Uich_SEO_Redirects' ) && method_exists( 'Uich_SEO_Redirects', 'last_page_error' ) ) ? Uich_SEO_Redirects::last_page_error() : null;
				if ( is_wp_error( $redirect_error ) ) {
					$errors->add( 'redirect_to', $redirect_error->get_error_message() );
					return $errors;
				}
			}

			if ( $clean ) {
				$parsed  = Uich_SEO_Head::apply_fields( Uich_SEO_Head::page( $post_id, true ), $clean );
				$written = Uich_SEO_Head::write_page( $post_id, $parsed );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}

			$record = self::rebuild( $post_id );
			do_action( 'uichemy_seo_record_saved', $post_id, $record, $patch );
			return $record;
		}

		/**
		 * Store dismissed checks (PRD §18.7).
		 *
		 * @param int      $post_id Post id.
		 * @param string[] $ids     Check ids.
		 * @return array
		 */
		public static function set_ignored( $post_id, array $ids ) {
			$record                   = self::get( $post_id );
			$record['ignored_checks'] = array_values( array_unique( array_map( 'sanitize_key', $ids ) ) );
			self::save( absint( $post_id ), $record );
			return $record;
		}

		/**
		 * Is a URL on a different host from this site?
		 *
		 * @param string $url URL.
		 * @return bool
		 */
		private static function is_other_site( $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			return '' !== $host && preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home );
		}

		/**
		 * May the current user point a page at another website (redirect / canonical)?
		 * Administrators yes; WP-CLI without a user is a trusted system context.
		 *
		 * @return bool
		 */
		private static function may_point_elsewhere() {
			if ( defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id() ) {
				return true;
			}
			return current_user_can( 'manage_options' );
		}

		/**
		 * May a URL written in a post's head code by its author stand as that page's
		 * canonical / og:url? The same rules as sanitize_field( 'canonical' ), keyed
		 * to the author rather than the current user: an absolute http(s) link, and
		 * another website only when the author is an administrator.
		 *
		 * @param string $url       URL.
		 * @param int    $author_id Post author.
		 * @return bool
		 */
		public static function author_url_allowed( $url, $author_id ) {
			$raw = trim( (string) $url );
			$esc = esc_url_raw( $raw, array( 'http', 'https' ) );
			if ( '' === $esc || ! preg_match( '#^https?://[a-z0-9\[][^\s/?\#]*(?:[/?\#]\S*)?$#i', $raw ) || '' === (string) wp_parse_url( $raw, PHP_URL_HOST ) ) {
				return false;
			}
			return ! self::is_other_site( $esc ) || ( $author_id > 0 && user_can( (int) $author_id, 'manage_options' ) );
		}

		/**
		 * Sanitise one field value.
		 *
		 * @param string $key   Field.
		 * @param mixed  $value Raw.
		 * @return mixed|WP_Error
		 */
		public static function sanitize_field( $key, $value ) {
			// Text and on/off fields take a single value; never turn an array into "Array".
			$scalar_only = array( 'title', 'social_title', 'description', 'social_description', 'canonical', 'redirect_to', 'content_type', 'index', 'follow', 'exclude_ai', 'og_locale' );
			if ( in_array( $key, $scalar_only, true ) && ! is_scalar( $value ) ) {
				return new WP_Error( 'invalid', __( 'This field expects text or on/off, not a list.', 'uichemy' ) );
			}
			if ( 'robots_advanced' === $key && ! is_array( $value ) ) {
				return new WP_Error( 'invalid', __( 'This field expects a list of crawler options.', 'uichemy' ) );
			}
			// Page text fields render through the dynamic engine, whose functions read any post.
			// Without unfiltered_html only the documented Insert tokens are allowed.
			if ( in_array( $key, array( 'title', 'social_title', 'description', 'social_description' ), true )
				&& ! current_user_can( 'unfiltered_html' )
				&& ! Uich_SEO_Settings::page_template_safe( (string) $value )
			) {
				return new WP_Error( 'invalid', __( 'Only the values from the Insert buttons can be used here.', 'uichemy' ) );
			}
			switch ( $key ) {
				case 'title':
				case 'social_title':
					$v   = sanitize_text_field( (string) $value );
					$bad = Uich_SEO_Settings::template_error( $v );
					if ( $bad ) {
						return new WP_Error( 'invalid', $bad );
					}
					// Google shows about 60 characters; anything past 300 is a mistake.
					return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 300 ) : substr( $v, 0, 300 );

				case 'description':
				case 'social_description':
					$v = sanitize_text_field( (string) $value );
					return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 600 ) : substr( $v, 0, 600 );

				case 'canonical':
					$raw = trim( (string) $value );
					$url = esc_url_raw( $raw, array( 'http', 'https' ) );
					// esc_url_raw() silently prefixes http:// — require a real absolute URL as typed.
					// Absolute http(s) URL with a host; dotless hosts and ports (localhost:10052, intranet) are fine.
					if ( '' === $url || ! preg_match( '#^https?://[a-z0-9\[][^\s/?\#]*(?:[/?\#]\S*)?$#i', $raw ) || '' === (string) wp_parse_url( $raw, PHP_URL_HOST ) ) {
						return new WP_Error( 'invalid', __( 'Enter a full link, starting with https://', 'uichemy' ) );
					}
					if ( self::is_other_site( $url ) && ! self::may_point_elsewhere() ) {
						return new WP_Error( 'forbidden', __( 'Only an administrator can point the canonical link at another website.', 'uichemy' ) );
					}
					return $url;

				case 'redirect_to':
					$v = trim( (string) $value );
					if ( 0 === strpos( $v, '/' ) && 0 !== strpos( $v, '//' ) ) {
						$abs = esc_url_raw( home_url( $v ) );
						return '' === $abs ? '/' : wp_make_link_relative( $abs );
					}
					$url = esc_url_raw( $v, array( 'http', 'https' ) );
					if ( '' === $url ) {
						return new WP_Error( 'invalid', __( 'Enter a full link, starting with https://', 'uichemy' ) );
					}
					// Sending visitors to ANOTHER website is an administrator decision
					// (the Link Manager is admin-only); other roles may redirect within the site.
					if ( self::is_other_site( $url ) && ! self::may_point_elsewhere() ) {
						return new WP_Error( 'forbidden', __( 'Only an administrator can send this page to another website. You can still send it to a page on this site, like /new-page.', 'uichemy' ) );
					}
					return $url;

				case 'content_type':
					$v = strtolower( (string) $value );
					$aliases = array(
						'web page' => 'webpage',
						'page'     => 'webpage',
						'website'  => 'webpage',
						'webpage'  => 'webpage',
						'article'  => 'article',
						'post'     => 'article',
						'product'  => 'product',
					);
					if ( ! isset( $aliases[ $v ] ) ) {
						return new WP_Error( 'invalid', __( 'Choose a web page, an article or a product.', 'uichemy' ) );
					}
					return $aliases[ $v ];

				case 'index':
				case 'follow':
				case 'exclude_ai':
					return (bool) rest_sanitize_boolean( $value );

				case 'robots_advanced':
					$v = is_array( $value ) ? $value : array();
					// A list (`["noarchive"]`) means those flags on; it was silently
					// dropped as a map with no keys (F-04).
					if ( $v && array_values( $v ) === $v ) {
						$v = array_fill_keys( array_map( 'strval', array_filter( $v, 'is_scalar' ) ), true );
					}
					$out = array();
					foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
						if ( ! empty( $v[ $flag ] ) ) {
							$out[ $flag ] = true;
						}
					}
					if ( isset( $v['max_snippet'] ) && '' !== $v['max_snippet'] ) {
						$out['max_snippet'] = max( -1, (int) $v['max_snippet'] );
					}
					if ( isset( $v['max_image_preview'] ) && in_array( $v['max_image_preview'], array( 'none', 'standard', 'large' ), true ) ) {
						$out['max_image_preview'] = $v['max_image_preview'];
					}
					return $out;

				case 'share_image':
					if ( is_numeric( $value ) ) {
						$value = array( 'id' => (int) $value );
					}
					if ( is_string( $value ) ) {
						$value = array( 'url' => $value );
					}
					$v  = is_array( $value ) ? $value : array();
					$id = isset( $v['id'] ) ? absint( $v['id'] ) : 0;
					if ( $id && wp_attachment_is_image( $id ) ) {
						$src = wp_get_attachment_image_src( $id, 'full' );
						return array(
							'id'  => $id,
							'url' => $src ? $src[0] : '',
							'w'   => $src ? (int) $src[1] : null,
							'h'   => $src ? (int) $src[2] : null,
							'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
						);
					}
					$url = isset( $v['url'] ) ? esc_url_raw( (string) $v['url'], array( 'http', 'https' ) ) : '';
					if ( '' === $url ) {
						return new WP_Error( 'invalid', __( 'Choose an image from the media library or paste its full link.', 'uichemy' ) );
					}
					return array(
						'id'  => 0,
						'url' => $url,
						'w'   => isset( $v['w'] ) ? absint( $v['w'] ) : null,
						'h'   => isset( $v['h'] ) ? absint( $v['h'] ) : null,
					);

				case 'og_locale':
					$v = (string) $value;
					// The same list the language dropdown offers (de_DE_formal, pt_PT_ao90, ca@valencia, zh_Hans …).
					$installed = array_merge( array( 'en_US' ), (array) get_available_languages() );
					if ( ! in_array( $v, $installed, true ) && ! preg_match( '/^[a-z]{2,3}(_[A-Za-z]{2,4})?(_[a-z0-9]+)?(@[a-z]+)?$/', $v ) ) {
						return new WP_Error( 'invalid', __( 'Choose a language from the list.', 'uichemy' ) );
					}
					return $v;

				case 'schema_extra':
					// WP-CLI without --user is a trusted system context; any real user needs the cap.
					$system = defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id();
					if ( ! $system && ! current_user_can( 'unfiltered_html' ) ) {
						return new WP_Error( 'forbidden', __( 'Only administrators can add page details for Google by hand.', 'uichemy' ) );
					}
					$list = is_string( $value ) ? json_decode( $value, true ) : $value;
					if ( ! is_array( $list ) ) {
						return new WP_Error( 'invalid', __( 'This is not valid JSON. Check for missing commas, quotes or brackets.', 'uichemy' ) );
					}
					if ( isset( $list['@type'] ) ) {
						$list = array( $list );
					}
					$clean = array();
					foreach ( $list as $entity ) {
						if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
							return new WP_Error( 'invalid', __( 'Each item needs an "@type", for example "@type": "Event".', 'uichemy' ) );
						}
						// Re-encode through JSON so only data survives (02 §13).
						$clean[] = json_decode( wp_json_encode( $entity ), true );
					}
					return $clean;
			}
			return new WP_Error( 'invalid', __( 'Unknown field.', 'uichemy' ) );
		}

		// ------------------------------------------------------------------
		// Rebuild
		// ------------------------------------------------------------------

		/**
		 * Re-derive a post's record from its current HTML. Overrides are kept.
		 *
		 * @param int $post_id Post id.
		 * @return array The rebuilt record (unsaved when the post is ineligible).
		 */
		public static function rebuild( $post_id ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );
			$record  = self::get( $post_id );
			if ( ! $post || ! self::is_eligible( $post ) ) {
				return $record;
			}

			$is_template = class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post->post_type;
			$kind        = $is_template ? 'pattern' : 'instance';

			// Password-protected content is never read (QA §2 #15). Checked on the post itself:
			// post_password_required() reads the saving user's password cookie, so an editor who
			// had already unlocked the post would have its protected text derived and published.
			$protected = ! $is_template && '' !== (string) $post->post_password;

			// Derive as a logged-out visitor. The result is printed publicly (title, description,
			// JSON-LD, llms.txt), so viewer-specific output (Twig user / cart values, logged-in-only
			// shortcodes) must not be captured from whoever happened to save the page.
			$prev_user = get_current_user_id();
			wp_set_current_user( 0 );
			try {
				$sections = $protected ? array() : Uich_SEO_Sections::get( $post_id, $kind );
				$text     = ( $is_template || $protected ) ? '' : Uich_SEO_Sections::plain_text( $post_id );
			} finally {
				wp_set_current_user( $prev_user );
			}

			$context = array(
				'kind' => $kind,
				'site' => array(
					'url'  => home_url( '/' ),
					'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				),
				'text' => $text,
			);

			$derived = Uich_SEO_Deriver::derive( $sections, $context );
			// The page head code's SEO tags: the source of truth for every page field.
			$head_raw        = Uich_SEO_Head::page_raw( $post_id );
			$derived['head'] = Uich_SEO_Head::trusted( Uich_SEO_Head::parse( $head_raw ), $post_id );

			$record['kind']       = $kind;
			$record['built_at']   = time();
			$record['source_sig'] = md5( $derived['source_sig'] . '|' . md5( $head_raw ) );
			unset( $derived['source_sig'] );
			$record['derived']    = self::enrich_image( $derived );
			// Where the HTML came from (composer:* | elementor | blocks | classic): the audit
			// needs it to know when the theme, not the content, prints the main heading.
			$record['derived']['source'] = isset( $sections[0]['source'] ) ? (string) $sections[0]['source'] : '';

			/**
			 * Filter a freshly derived record before it is stored. The audit
			 * attaches its on-save findings here.
			 *
			 * @param array   $record Record.
			 * @param WP_Post $post   Post.
			 */
			$record = apply_filters( 'uichemy_seo_record', $record, $post );

			self::save( $post_id, $record );
			do_action( 'uichemy_seo_rebuilt', $post_id, $record );
			return self::normalize( $record );
		}

		/**
		 * Give a derived share image its attachment id and size, at save time, so
		 * the front end never has to look it up.
		 *
		 * @param array $derived Derived block.
		 * @return array
		 */
		private static function enrich_image( array $derived ) {
			if ( empty( $derived['share_image']['value']['url'] ) || ! empty( $derived['share_image']['twig'] ) ) {
				return $derived;
			}
			$url = (string) $derived['share_image']['value']['url'];
			$id  = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
			if ( ! $id ) {
				$id = attachment_url_to_postid( $url );
			}
			if ( $id ) {
				$src = wp_get_attachment_image_src( $id, 'full' );
				if ( $src ) {
					$derived['share_image']['value'] = array(
						'id'  => (int) $id,
						'url' => $src[0],
						'w'   => (int) $src[1],
						'h'   => (int) $src[2],
						'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					);
				}
			} elseif ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$derived['share_image']['value']['url'] = home_url( $url );
			}
			return $derived;
		}

		/**
		 * Signature of the current content without a DOM parse, for staleness
		 * checks in admin / audit context only (never on the front end).
		 *
		 * @param int $post_id Post id.
		 * @return string
		 */
		public static function current_sig( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return '';
			}
			$is_template = class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post->post_type;
			$kind        = $is_template ? 'pattern' : 'instance';
			$sig         = '';
			foreach ( Uich_SEO_Sections::get( $post_id, $kind ) as $i => $s ) {
				$sig .= ( isset( $s['uid'] ) ? $s['uid'] : $i ) . ':' . md5( $s['html'] ) . ';';
			}
			return md5( md5( $kind . '|' . $sig ) . '|' . md5( Uich_SEO_Head::page_raw( $post_id ) ) );
		}

		/**
		 * Is the stored record behind the content? Admin/audit only.
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public static function is_stale( $post_id ) {
			$raw = get_post_meta( absint( $post_id ), self::META, true );
			// Built by an older version: it lacks what this one derives (e.g. section JSON-LD).
			if ( ! is_array( $raw ) || ! isset( $raw['v'] ) || (int) $raw['v'] < self::VERSION ) {
				return true;
			}
			$record = self::get( $post_id );
			return ! self::is_built( $record ) || self::current_sig( $post_id ) !== $record['source_sig'];
		}

		/**
		 * Delete a record entirely.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function delete( $post_id ) {
			delete_post_meta( absint( $post_id ), self::META );
		}
	}
}
