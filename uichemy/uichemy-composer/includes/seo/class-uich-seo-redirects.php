<?php
/**
 * Redirects + minimal 404 capture (D12, D19, 02 §10, PRD §9).
 *
 * PHP redirects on `template_redirect` — never .htaccess or nginx config.
 * Automatic redirects on slug change, trash and untrash (the half that prevents
 * 404s), `origin = agent` when an MCP / AI call caused it.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Redirects' ) ) {

	/**
	 * Redirect manager.
	 */
	class Uich_SEO_Redirects {

		const DB_VERSION = '1';
		const DB_OPTION  = 'uichemy_seo_db_ver';
		const TYPES      = array( 301, 302, 307, 410, 451 );
		const ORIGINS    = array( 'manual', 'slug-change', 'trash', 'import', 'agent', '404', 'page' );

		/** 404 rows kept (LRU). */
		const CAP_404 = 500;

		/** @var bool Flushes are deferred (CSV import). */
		private static $bulk = false;

		/** @var bool A flush was asked for while deferred. */
		private static $bulk_dirty = false;

		// CSV import limits: each row runs several queries.
		const IMPORT_MAX_ROWS  = 500;
		const IMPORT_MAX_BYTES = 1048576;

		/** Regex rows evaluated per request, at most. */
		const REGEX_CAP = 200;

		/**
		 * Permalinks captured before an update, keyed by post id.
		 *
		 * @var array<int,string>
		 */
		private static $before = array();

		/**
		 * Set when this request was answered 410 / 451 on purpose, so the 404
		 * capture does not log an address that is gone deliberately.
		 *
		 * @var bool
		 */
		private static $gone = false;

		/**
		 * Why the last "Redirect this URL" page field was not saved (null when it was).
		 *
		 * @var WP_Error|null
		 */
		private static $page_error = null;

		/** Core addresses WordPress answers before `template_redirect`: a redirect there never fires. */
		const CORE_PATHS = '#^/(wp-admin|wp-includes|wp-content|wp-json)(/|$)|^/(wp-login|wp-signup|wp-activate|wp-cron|wp-trackback|wp-comments-post|wp-links-opml|wp-mail|xmlrpc)\.php#i';

		/**
		 * Table names.
		 *
		 * @return array{redirects:string,nf:string}
		 */
		public static function tables() {
			global $wpdb;
			return array(
				'redirects' => $wpdb->prefix . 'uichemy_redirects',
				'nf'        => $wpdb->prefix . 'uichemy_404s',
			);
		}

		/**
		 * Include this plugin's per-site tables when a network site is deleted, so
		 * visitor referrers in the 404 log do not outlive the site.
		 *
		 * @param string[] $tables  Tables WordPress will drop.
		 * @param int      $site_id Deleted site id.
		 * @return string[]
		 */
		public static function drop_site_tables( $tables, $site_id ) {
			global $wpdb;
			$prefix = $wpdb->get_blog_prefix( (int) $site_id );
			foreach ( array( 'uichemy_redirects', 'uichemy_404s', 'uich_submissions', 'uich_submission_values' ) as $name ) {
				$tables[ $prefix . $name ] = $prefix . $name;
			}
			return $tables;
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_site_tables' ), 10, 2 );
			add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
			add_action( 'template_redirect', array( __CLASS__, 'catch_request' ), 0 );
			add_action( 'template_redirect', array( __CLASS__, 'capture_404' ), 99 );

			// Watcher.
			add_action( 'pre_post_update', array( __CLASS__, 'remember_permalink' ), 10, 1 );
			add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
			add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ), 10, 1 );
			add_action( 'untrashed_post', array( __CLASS__, 'on_untrash' ), 10, 1 );
			// Trash → Published through an update (REST / MCP) never fires untrashed_post.
			add_action( 'transition_post_status', array( __CLASS__, 'on_leave_trash' ), 10, 3 );
			// A newly published post that takes an address back from an automatic redirect.
			add_action( 'wp_after_insert_post', array( __CLASS__, 'claim_path' ), 20, 2 );
			// The page's own "Redirect this URL" row follows its address (publish, slug change).
			add_action( 'wp_after_insert_post', array( __CLASS__, 'rekey_page_row' ), 21, 1 );

			// The page panel's "Redirect this URL" field → a table row.
			add_action( 'uichemy_seo_page_redirect', array( __CLASS__, 'sync_page_field' ), 10, 2 );
		}

		// ------------------------------------------------------------------
		// Schema
		// ------------------------------------------------------------------

		/**
		 * Create / upgrade the tables when the stored version differs.
		 *
		 * @return void
		 */
		public static function maybe_install() {
			if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
				return;
			}
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t       = self::tables();
			$charset = $wpdb->get_charset_collate();

			dbDelta(
				"CREATE TABLE {$t['redirects']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(255) NOT NULL DEFAULT '',
  target text NOT NULL,
  type smallint(3) unsigned NOT NULL DEFAULT 301,
  is_regex tinyint(1) unsigned NOT NULL DEFAULT 0,
  status tinyint(1) unsigned NOT NULL DEFAULT 1,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  origin varchar(20) NOT NULL DEFAULT 'manual',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY source (source(191)),
  KEY is_regex (is_regex,status),
  KEY post_id (post_id)
) $charset;"
			);
			dbDelta(
				"CREATE TABLE {$t['nf']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  last_seen datetime NOT NULL,
  referrer text NULL,
  ignored tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY url (url(191)),
  KEY last_seen (last_seen)
) $charset;"
			);
			update_option( self::DB_OPTION, self::DB_VERSION, true );
		}

		// ------------------------------------------------------------------
		// URLs
		// ------------------------------------------------------------------

		/**
		 * Normalise a URL or path to the stored source form: a path relative to
		 * the site root, leading slash, no trailing slash (except "/"), no host,
		 * query string kept only when the user typed one.
		 *
		 * @param string $url URL or path.
		 * @return string
		 */
		public static function normalize( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '';
			}
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( preg_match( '#^(https?:)?//#i', $url ) ) {
				$parts = wp_parse_url( $url );
				$url   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			}
			$url = '/' . ltrim( $url, '/' );
			// Strip a subdirectory install's base path.
			if ( '/' !== $home_path && 0 === strpos( $url, rtrim( $home_path, '/' ) . '/' ) ) {
				$url = substr( $url, strlen( rtrim( $home_path, '/' ) ) );
			}
			list( $path ) = array_pad( explode( '?', $url, 2 ), 1, '' );
			$query        = strpos( $url, '?' ) !== false ? substr( $url, strpos( $url, '?' ) ) : '';
			$path         = '/' === $path ? '/' : untrailingslashit( $path );
			return rawurldecode( $path ) . $query;
		}

		/**
		 * A target as a site-relative path, exactly as WordPress links to it —
		 * trailing slash and query kept, so the redirect lands in one hop.
		 *
		 * @param string $url URL or path.
		 * @return string
		 */
		public static function target_path( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '';
			}
			if ( preg_match( '#^(https?:)?//#i', $url ) ) {
				$parts = wp_parse_url( $url );
				$url   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			}
			$url       = '/' . ltrim( $url, '/' );
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '/' !== $home_path && 0 === strpos( $url, rtrim( $home_path, '/' ) . '/' ) ) {
				$url = substr( $url, strlen( rtrim( $home_path, '/' ) ) );
			}
			return $url;
		}

		/**
		 * Absolute target for an external or internal target.
		 *
		 * @param string $target Target.
		 * @return string
		 */
		public static function absolute( $target ) {
			$target = trim( (string) $target );
			if ( '' === $target || preg_match( '#^https?://#i', $target ) ) {
				return $target;
			}
			return home_url( '/' . ltrim( $target, '/' ) );
		}

		/**
		 * The current request path, normalised.
		 *
		 * @return array{0:string,1:string} [ path, path+query ]
		 */
		private static function request() {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised below.
			$full = self::normalize( (string) $uri );
			$path = strtok( $full, '?' );
			return array( false === $path ? '/' : $path, $full );
		}

		// ------------------------------------------------------------------
		// Front end
		// ------------------------------------------------------------------

		/**
		 * Is the feature on?
		 *
		 * @return bool
		 */
		private static function active() {
			return Uich_SEO::output_enabled() && (bool) Uich_SEO_Settings::get( 'redirects_enabled', true );
		}

		/**
		 * `template_redirect` @0: exact match, then capped regex, then attachments.
		 *
		 * @return void
		 */
		public static function catch_request() {
			if ( is_admin() || ! self::active() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			list( $path, $full ) = self::request();
			$row                 = self::match( $path, $full );
			// An automatic row never hides a live page that now lives at this address, and
			// neither does another post's "Redirect this URL" row: a post may share a slug
			// with a page (slugs are unique per post type only), and its own redirect must
			// not take over that page's address.
			if ( $row && isset( $row->origin ) && is_singular() ) {
				$live = get_queried_object();
				if ( $live instanceof WP_Post && 'publish' === $live->post_status && self::normalize( get_permalink( $live ) ) === $path ) {
					if ( self::is_auto_row( $row ) ) {
						self::claim_path( $live->ID, $live );
						$row = null;
					} elseif ( 'page' === (string) $row->origin && (int) $row->post_id !== (int) $live->ID ) {
						$row = null;
					}
				}
			}
			if ( $row ) {
				self::serve( $row, $full );
				return;
			}

			// PRD §6.4 "Send attachment pages to the post".
			if ( is_attachment() && Uich_SEO_Settings::get( 'redirect_attachments', true ) ) {
				$att    = get_queried_object();
				$parent = $att && $att->post_parent ? get_post( $att->post_parent ) : null;
				$to     = ( $parent && 'publish' === $parent->post_status ) ? get_permalink( $parent ) : wp_get_attachment_url( $att ? $att->ID : 0 );
				if ( $to ) {
					wp_safe_redirect( $to, 301, 'UiChemy SEO' );
					exit;
				}
			}
		}

		/**
		 * Find the redirect for a request.
		 *
		 * @param string $path Path.
		 * @param string $full Path + query.
		 * @return object|null
		 */
		public static function match( $path, $full = '' ) {
			global $wpdb;
			if ( ! self::has_rows() ) {
				return null;
			}
			$t = self::tables();
			$candidates = array_values( array_unique( array_filter( array( $full, $path ) ) ) );
			foreach ( $candidates as $c ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND status = 1 LIMIT 1", $c ) );
				if ( $row ) {
					return $row;
				}
			}
			// Regex rows run against the visitor's path on every request. A pattern with nested
			// quantifiers plus a long crafted URL can backtrack for a long time, so very long
			// paths are not tested and the backtracking budget is kept small while matching
			// (URL patterns never come near it).
			if ( strlen( (string) $path ) > 2048 ) {
				return null;
			}
			$prev_limit = ini_get( 'pcre.backtrack_limit' );
			@ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet -- restored below.
			try {
				foreach ( self::regex_rows() as $row ) {
					$re = self::regex( $row->source );
					if ( $re && @preg_match( $re, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validated on save; defensive here.
						$row         = clone $row;
						$row->target = self::expand_target( $re, (string) $row->target, $path );
						return $row;
					}
				}
			} finally {
				if ( false !== $prev_limit ) {
					@ini_set( 'pcre.backtrack_limit', $prev_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
				}
			}
			return null;
		}

		/**
		 * A pattern row's target for a path: the whole target, with $1 / ${1} / \\1
		 * filled in from the match. (preg_replace() on the path would only swap the
		 * matched part and keep the rest, turning `old` + `/new/` into `/new/-page`.)
		 *
		 * @param string $re     Delimited pattern.
		 * @param string $target Stored target.
		 * @param string $path   Request path.
		 * @return string
		 */
		private static function expand_target( $re, $target, $path ) {
			$m = array();
			if ( ! @preg_match( $re, $path, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validated on save.
				return $target;
			}
			return (string) preg_replace_callback(
				'/\\$\{(\d+)\}|\\$(\d+)|\\\\(\d+)/',
				function ( $x ) use ( $m ) {
					$n = (int) ( '' !== $x[1] ? $x[1] : ( isset( $x[2] ) && '' !== $x[2] ? $x[2] : $x[3] ) );
					return isset( $m[ $n ] ) ? $m[ $n ] : '';
				},
				$target
			);
		}

		/**
		 * Where a post's own address is redirected to ('' when it is not).
		 *
		 * @param int $post_id Post id.
		 * @return string
		 */
		public static function page_target( $post_id ) {
			if ( ! self::has_rows() ) {
				return '';
			}
			// The page's own row is found by post id: its address may have changed since.
			$own = self::page_row( $post_id );
			if ( $own && (int) $own->status ) {
				return (string) $own->target;
			}
			$url  = get_permalink( $post_id );
			$from = $url ? self::normalize( $url ) : '';
			if ( '' === $from || '/' === $from ) {
				return '';
			}
			$row = self::match( $from );
			return ( $row && empty( $row->is_regex ) ) ? (string) $row->target : '';
		}

		/**
		 * Does the table have any active row? Cached so a site with no redirects
		 * pays nothing on a 404.
		 *
		 * @return bool
		 */
		private static function has_rows() {
			$n = get_transient( 'uich_seo_redir_n' );
			if ( false === $n ) {
				global $wpdb;
				$t = self::tables();
				$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['redirects']} WHERE status = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				set_transient( 'uich_seo_redir_n', $n, DAY_IN_SECONDS );
			}
			return (int) $n > 0;
		}

		/**
		 * Active regex rows, capped and cached.
		 *
		 * @return object[]
		 */
		private static function regex_rows() {
			$rows = get_transient( 'uich_seo_redir_re' );
			if ( false === $rows ) {
				global $wpdb;
				$t    = self::tables();
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, source, target, type, is_regex FROM {$t['redirects']} WHERE is_regex = 1 AND status = 1 ORDER BY id ASC LIMIT %d", self::REGEX_CAP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				set_transient( 'uich_seo_redir_re', $rows, DAY_IN_SECONDS );
			}
			return (array) $rows;
		}

		/**
		 * Forget the caches after a write.
		 *
		 * @return void
		 */
		public static function flush_cache() {
			// A bulk import flushes once at the end instead of after every row.
			if ( self::$bulk ) {
				self::$bulk_dirty = true;
				return;
			}
			delete_transient( 'uich_seo_redir_n' );
			delete_transient( 'uich_seo_redir_re' );
			delete_transient( 'uich_seo_redir_src' );
			do_action( 'uichemy_seo_redirects_changed' );
		}

		/**
		 * A stored regex source → a delimited pattern.
		 *
		 * @param string $source Source.
		 * @return string|null
		 */
		public static function regex( $source ) {
			$re = '#' . str_replace( '#', '\#', (string) $source ) . '#i';
			return false === @preg_match( $re, '' ) ? null : $re; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validity probe.
		}

		/**
		 * Send the response for a matched row.
		 *
		 * @param object $row  Row.
		 * @param string $full Request path + query, normalised.
		 * @return void
		 */
		private static function serve( $row, $full ) {
			global $wpdb;
			$t = self::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['redirects']} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row->id ) );

			$type = (int) $row->type;
			if ( in_array( $type, array( 410, 451 ), true ) ) {
				global $wp_query;
				self::$gone = true; // Not a 404 to log: the address is gone on purpose.
				$wp_query->set_404();
				status_header( $type );
				nocache_headers();
				return; // WordPress renders the 404 template with our status.
			}
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its query is carried, and wp_redirect() sanitises the Location.
			$query = false !== strpos( $uri, '?' ) ? (string) substr( $uri, strpos( $uri, '?' ) + 1 ) : '';
			$to    = self::destination( $row, $query );
			// Hop guard: never answer with a redirect that leads back here (ERR_TOO_MANY_REDIRECTS).
			if ( '' === $to || self::loops( $to, $full ) ) {
				return;
			}
			// wp_redirect, not wp_safe_redirect: a redirect to another domain is a deliberate admin choice.
			wp_redirect( $to, $type, 'UiChemy SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		/**
		 * The absolute URL a matched row sends a request to, the request's query
		 * string carried over (so ?utm_… tracking survives the redirect). When the
		 * row's source itself named a query, that query was part of the match and
		 * is not carried.
		 *
		 * @param object $row   Matched row (regex rows: target already substituted).
		 * @param string $query Incoming query string, without "?".
		 * @return string '' when the row has no target.
		 */
		private static function destination( $row, $query ) {
			$to = self::absolute( $row->target );
			if ( '' === $to ) {
				return '';
			}
			if ( empty( $row->is_regex ) && false !== strpos( (string) $row->source, '?' ) ) {
				return $to;
			}
			return self::carry_query( $to, $query );
		}

		/**
		 * Append an incoming query string to a target. Parameters the target sets
		 * itself win; the rest are added as they arrived (encoding untouched).
		 *
		 * @param string $to    Target URL.
		 * @param string $query Query string, without "?".
		 * @return string
		 */
		private static function carry_query( $to, $query ) {
			$query = trim( (string) $query, '&' );
			if ( '' === $query ) {
				return $to;
			}
			$hash = '';
			$at   = strpos( $to, '#' );
			if ( false !== $at ) {
				$hash = substr( $to, $at );
				$to   = substr( $to, 0, $at );
			}
			$have = array();
			$own  = (string) wp_parse_url( $to, PHP_URL_QUERY );
			if ( '' !== $own ) {
				wp_parse_str( $own, $have );
			}
			$add = array();
			foreach ( explode( '&', $query ) as $pair ) {
				$name = preg_replace( '/\[.*$/', '', urldecode( (string) strstr( $pair . '=', '=', true ) ) );
				if ( '' === $name || isset( $have[ $name ] ) ) {
					continue;
				}
				$add[] = $pair;
			}
			if ( ! $add ) {
				return $to . $hash;
			}
			$glue = false === strpos( $to, '?' ) ? '?' : ( in_array( substr( $to, -1 ), array( '?', '&' ), true ) ? '' : '&' );
			return $to . $glue . implode( '&', $add ) . $hash;
		}

		/**
		 * Would sending the visitor to $to bring them back here? Follows the
		 * redirect rules on this site (as serve() would answer each hop) and
		 * reports a loop, or a chain too long to be anything else.
		 *
		 * @param string $to   Absolute target about to be sent.
		 * @param string $full The current request, normalised path + query.
		 * @return bool
		 */
		private static function loops( $to, $full ) {
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$seen = array( $full => true );
			for ( $hop = 0; $hop < 10; $hop++ ) {
				$host = wp_parse_url( $to, PHP_URL_HOST );
				if ( $host && strtolower( (string) $host ) !== $home ) {
					return false; // Leaves the site.
				}
				$next = self::normalize( $to );
				if ( isset( $seen[ $next ] ) ) {
					return true;
				}
				$seen[ $next ] = true;
				$path          = strtok( $next, '?' );
				$row           = self::match( false === $path ? '/' : $path, $next );
				if ( ! $row || in_array( (int) $row->type, array( 410, 451 ), true ) ) {
					return false;
				}
				$to = self::destination( $row, (string) wp_parse_url( $to, PHP_URL_QUERY ) );
				if ( '' === $to ) {
					return false;
				}
			}
			return true; // Ten hops and counting: treat it as a loop.
		}

		/**
		 * `template_redirect` @99: remember 404s (minimal capture, row-capped).
		 *
		 * @return void
		 */
		public static function capture_404() {
			if ( self::$gone || ! is_404() || ! self::active() || ! Uich_SEO_Settings::get( 'record_404', true ) ) {
				return;
			}
			list( $path ) = self::request(); // Query strings are never logged.
			if ( '' === $path || strlen( $path ) > 255 || preg_match( '#^/(wp-admin|wp-json|wp-content|wp-includes)/#', $path ) ) {
				return;
			}
			// Files and probes are not pages anyone should redirect: skip them.
			if ( preg_match( '#\.(ico|png|jpe?g|gif|svg|webp|avif|bmp|css|js|mjs|map|json|woff2?|ttf|otf|eot|php\d?|phtml|asp|aspx|cgi|env|ini|log|sql|bak|zip|gz|tar|rar|txt|xml)$#i', $path ) ) {
				return;
			}
			global $wpdb;
			$t   = self::tables();
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$affected = $wpdb->query( $wpdb->prepare( "INSERT INTO {$t['nf']} (url, hits, last_seen, referrer) VALUES (%s, 1, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) = '', referrer, VALUES(referrer))", $path, $now, $ref ) );
			if ( 1 === (int) $affected ) {
				self::trim_404s();
			}
		}

		/**
		 * LRU eviction: keep the most recently seen CAP rows.
		 *
		 * @return void
		 */
		public static function trim_404s() {
			global $wpdb;
			$t   = self::tables();
			$cap = (int) apply_filters( 'uichemy_seo_404_cap', self::CAP_404 );
			$n   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['nf']}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $n <= $cap ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['nf']} ORDER BY last_seen ASC, id ASC LIMIT %d", $n - $cap ) );
			if ( $ids ) {
				$ids = implode( ',', array_map( 'intval', $ids ) );
				$wpdb->query( "DELETE FROM {$t['nf']} WHERE id IN ($ids)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		// ------------------------------------------------------------------
		// Watcher (slug change / trash / untrash)
		// ------------------------------------------------------------------

		/**
		 * Who caused this change? An AI / MCP request counts as the agent.
		 *
		 * @param string $fallback Origin otherwise.
		 * @return string
		 */
		private static function origin_for( $fallback ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST && preg_match( '#/(mcp|wp-abilities|abilities|uichemy/v2)#i', $uri ) )
				|| did_action( 'uichemy_mcp_request' ) || apply_filters( 'uichemy_seo_is_agent_request', false ) ) {
				return 'agent';
			}
			return $fallback;
		}

		/**
		 * Is this post's address worth watching?
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		private static function watchable( $post ) {
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post ) ) {
				return false;
			}
			$obj = get_post_type_object( $post->post_type );
			return $obj && ! empty( $obj->public ) && 'attachment' !== $post->post_type
				&& ! ( class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post->post_type );
		}

		/**
		 * `pre_post_update` — capture the old permalink.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function remember_permalink( $post_id ) {
			$post = get_post( $post_id );
			if ( self::watchable( $post ) ) {
				self::$before[ $post_id ] = self::normalize( get_permalink( $post ) );
			}
		}

		/**
		 * `post_updated` — slug or parent changed → redirect old → new.
		 *
		 * @param int     $post_id Post id.
		 * @param WP_Post $after   After.
		 * @param WP_Post $before  Before.
		 * @return void
		 */
		public static function on_post_updated( $post_id, $after, $before ) {
			if ( ! Uich_SEO_Settings::get( 'auto_redirect', true ) || ! self::watchable( $before ) || ! self::watchable( $after ) ) {
				return;
			}
			$old = isset( self::$before[ $post_id ] ) ? self::$before[ $post_id ] : self::normalize( get_permalink( $before ) );
			$new = self::target_path( get_permalink( $after ) );
			if ( '' === $old || $old === self::normalize( $new ) || '/' === $old ) {
				return;
			}
			// The old address still belongs to another live post (this post only shadowed its
			// slug): leave its redirects alone, or renaming would re-point them here.
			if ( ! self::served_by_other_post( $old, $post_id ) ) {
				self::auto( $old, $new, 'slug-change', $post_id );
				self::repoint( $old, $new );
			}
			self::rekey_page_row( $post_id );
		}

		/**
		 * `wp_trash_post` — the URL is going away.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function on_trash( $post_id ) {
			$post = get_post( $post_id );
			if ( ! Uich_SEO_Settings::get( 'auto_redirect', true ) || ! self::watchable( $post ) ) {
				return;
			}
			$old = self::normalize( get_permalink( $post ) );
			if ( '/' === $old ) {
				return;
			}
			// The page already sends its address somewhere ("Redirect this URL"): that stays.
			$own = self::page_row( $post_id );
			if ( $own && $own->source === $old ) {
				return;
			}
			// Point to the closest living parent: the parent page, else the type archive.
			$to = '';
			if ( $post->post_parent && 'publish' === get_post_status( $post->post_parent ) ) {
				$to = self::target_path( get_permalink( $post->post_parent ) );
			} elseif ( get_post_type_archive_link( $post->post_type ) ) {
				$to = self::target_path( get_post_type_archive_link( $post->post_type ) );
			} elseif ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {
				$to = self::target_path( get_permalink( (int) get_option( 'page_for_posts' ) ) );
			}
			if ( '' !== $to && self::normalize( $to ) !== $old ) {
				self::auto( $old, $to, 'trash', $post_id );
			} else {
				self::auto( $old, '', 'trash', $post_id, 410 );
			}
		}

		/**
		 * `transition_post_status` — a post left the trash by any route.
		 *
		 * @param string  $new  New status.
		 * @param string  $old  Old status.
		 * @param WP_Post $post Post.
		 * @return void
		 */
		public static function on_leave_trash( $new, $old, $post ) {
			if ( 'trash' === $old && 'trash' !== $new && $post instanceof WP_Post ) {
				self::on_untrash( $post->ID );
			}
		}

		/**
		 * `untrashed_post` — the page is back; remove what trashing created.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function on_untrash( $post_id ) {
			global $wpdb;
			$t = self::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['redirects']} WHERE post_id = %d AND origin = 'trash'", (int) $post_id ) );
			self::flush_cache();
		}

		/** Origins of rows UiChemy wrote by itself (as opposed to ones a person added). */
		const AUTO_ORIGINS = array( 'slug-change', 'trash', 'agent' );

		/**
		 * Did UiChemy write this row by itself? 'agent' covers two kinds of row: automatic
		 * ones written while an agent request saved a post (they carry that post_id), and
		 * redirects an admin deliberately added through MCP (post_id 0). Only the first kind
		 * may be cleared when a page claims the address.
		 *
		 * @param object $row Redirect row.
		 * @return bool
		 */
		private static function is_auto_row( $row ) {
			$origin = isset( $row->origin ) ? (string) $row->origin : '';
			if ( 'slug-change' === $origin || 'trash' === $origin ) {
				return true;
			}
			return 'agent' === $origin && ! empty( $row->post_id );
		}

		/** SQL condition matching is_auto_row(). */
		const AUTO_ROW_SQL = "( origin IN ('slug-change','trash') OR ( origin = 'agent' AND post_id > 0 ) )";

		/**
		 * Is this address served by a DIFFERENT published post right now?
		 *
		 * @param string $path    Normalised path.
		 * @param int    $post_id The post acting on the address.
		 * @return bool
		 */
		private static function served_by_other_post( $path, $post_id ) {
			if ( '' === (string) $path ) {
				return false;
			}
			$pid = (int) url_to_postid( home_url( $path ) );
			if ( $pid > 0 ) {
				return $pid !== (int) $post_id && 'publish' === get_post_status( $pid );
			}
			// url_to_postid() can come back empty (translation plugins filter it, and it does
			// not try every post type when slugs collide), so also look for another published
			// post whose permalink IS this path.
			$slug = basename( untrailingslashit( strtok( (string) $path, '?' ) ) );
			if ( '' === $slug ) {
				return false;
			}
			$ids = get_posts(
				array(
					'name'             => $slug,
					'post_type'        => array_values( get_post_types( array( 'public' => true ) ) ),
					'post_status'      => 'publish',
					'post__not_in'     => array( (int) $post_id ),
					'posts_per_page'   => 5,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( self::normalize( get_permalink( $id ) ) === self::normalize( $path ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * A published post owns its address: drop automatic redirects from it that
		 * another (renamed or trashed) post left behind. Without this, a new page
		 * that re-uses an old address is answered with that old 410 / 301.
		 * Redirects a person added by hand are left alone.
		 *
		 * @param int     $post_id Post id.
		 * @param WP_Post $post    Post.
		 * @return void
		 */
		public static function claim_path( $post_id, $post ) {
			if ( ! self::watchable( $post ) ) {
				return;
			}
			$path = self::normalize( get_permalink( $post ) );
			if ( '' === $path || '/' === $path ) {
				return;
			}
			global $wpdb;
			$t    = self::tables();
			$auto = self::AUTO_ROW_SQL;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND {$auto} AND post_id <> %d", $path, (int) $post_id ) );
			if ( $n ) {
				self::flush_cache();
			}
		}

		/**
		 * Write an automatic redirect, replacing any earlier one for the source and
		 * removing a redirect that would now shadow the page's new address.
		 *
		 * @param string $from    Old path.
		 * @param string $to      New path.
		 * @param string $origin  Origin.
		 * @param int    $post_id Post id.
		 * @param int    $type    Status.
		 * @return void
		 */
		private static function auto( $from, $to, $origin, $post_id, $type = 301 ) {
			global $wpdb;
			$t = self::tables();
			// Someone who cannot manage redirects (an Author renaming or trashing their own
			// post) only ever replaces rows UiChemy wrote by itself; a redirect an admin added
			// at either address stays, and wins.
			$admin = current_user_can( 'manage_options' );
			$guard = $admin ? '' : ' AND ' . self::AUTO_ROW_SQL;
			// The post's own "Redirect this URL" row is never dropped here: it follows
			// the post to its new address (rekey_page_row()).
			if ( '' !== $to ) {
				// The page now lives at $to: a redirect FROM $to would hide it.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND NOT ( origin = 'page' AND post_id = %d ){$guard}", self::normalize( $to ), (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND NOT ( origin = 'page' AND post_id = %d ){$guard}", $from, (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $admin ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND NOT ( origin = 'page' AND post_id = %d ) LIMIT 1", $from, (int) $post_id ) );
				if ( $kept ) {
					self::flush_cache();
					return;
				}
			}
			$wpdb->insert(
				$t['redirects'],
				array(
					'source'     => $from,
					'target'     => $to,
					'type'       => $type,
					'is_regex'   => 0,
					'status'     => 1,
					'created_at' => current_time( 'mysql', true ),
					'created_by' => get_current_user_id(),
					'origin'     => self::origin_for( $origin ),
					'post_id'    => (int) $post_id,
				)
			);
			self::flush_cache();
		}

		/**
		 * Collapse chains: anything that pointed at $old now points at $new.
		 *
		 * @param string $old Old path.
		 * @param string $new New path.
		 * @return void
		 */
		private static function repoint( $old, $new ) {
			global $wpdb;
			$t = self::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['redirects']} SET target = %s WHERE target IN (%s, %s, %s, %s) AND is_regex = 0 AND source <> %s", $new, $old, trailingslashit( $old ), home_url( $old ), home_url( trailingslashit( $old ) ), self::normalize( $new ) ) );
			self::flush_cache();
		}

		/**
		 * "Send this page somewhere else" ↔ one table row (origin 'page'). The
		 * Redirects table is the only home of a redirect; '' removes it.
		 *
		 * The row belongs to the post (found by post id, not by address), so it
		 * follows the post when its address changes — a draft's /?p=123 becomes
		 * the real permalink on publish (rekey_page_row()). It goes through
		 * validate() like any other redirect, and never replaces a redirect a
		 * person (or an import) already set for the same address. Why a value was
		 * refused is kept for last_page_error().
		 *
		 * @param int    $post_id Post id.
		 * @param string $to      Target ('' to remove).
		 * @return true|WP_Error
		 */
		public static function sync_page_field( $post_id, $to ) {
			global $wpdb;
			$t                = self::tables();
			$post_id          = (int) $post_id;
			$own              = self::page_row( $post_id );
			$to               = trim( (string) $to );
			self::$page_error = null;

			if ( '' === $to ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['redirects']} WHERE post_id = %d AND origin = 'page'", $post_id ) );
				self::flush_cache();
				return true;
			}

			$url  = get_permalink( $post_id );
			$from = $url ? self::normalize( $url ) : '';
			if ( '' === $from || '/' === $from ) {
				self::$page_error = new WP_Error( 'uich_seo_redirect_invalid', __( 'The home page address cannot be redirected from here. Add the redirect in SEO → Redirects instead.', 'uichemy' ), array( 'status' => 400, 'fields' => array( 'redirect_to' => __( 'The home page address cannot be redirected from here.', 'uichemy' ) ) ) );
				return self::$page_error;
			}

			// The post may share its slug with a page (slugs are unique per post type only), so
			// its permalink can be another post's live address. Redirecting that address would
			// take the other page over; only an admin may do that, from SEO → Redirects.
			if ( self::served_by_other_post( $from, $post_id ) && ! current_user_can( 'manage_options' ) ) {
				/* translators: %s: url. */
				$msg              = sprintf( __( '%s is the address of another page, so it cannot be redirected from this post.', 'uichemy' ), $from );
				self::$page_error = new WP_Error( 'uich_seo_redirect_invalid', $msg, array( 'status' => 400, 'fields' => array( 'redirect_to' => $msg ) ) );
				return self::$page_error;
			}

			$type = $own && in_array( (int) $own->type, array( 301, 302, 307 ), true ) ? (int) $own->type : 301;
			$v    = self::validate(
				array(
					'id'       => $own ? (int) $own->id : 0,
					'source'   => $from,
					'target'   => $to,
					'type'     => $type,
					'is_regex' => 0,
					'status'   => 1,
				)
			);
			if ( $v['errors'] ) {
				$fields = array_filter( $v['errors'], 'is_string' );
				self::$page_error = new WP_Error( 'uich_seo_redirect_invalid', implode( ' ', $fields ), array( 'status' => 400, 'fields' => array( 'redirect_to' => implode( ' ', $fields ) ) ) );
				return self::$page_error;
			}

			$data = array(
				'source'   => $v['clean']['source'],
				'target'   => $v['clean']['target'],
				'type'     => $v['clean']['type'],
				'is_regex' => 0,
				'status'   => 1,
			);
			if ( $own ) {
				$wpdb->update( $t['redirects'], $data, array( 'id' => (int) $own->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$t['redirects'],
					array_merge(
						$data,
						array(
							'created_at' => current_time( 'mysql', true ),
							'created_by' => get_current_user_id(),
							'origin'     => 'page', // Always 'page': it is how the row is found again.
							'post_id'    => $post_id,
						)
					)
				);
			}
			self::forget_404( $data['source'] );
			self::flush_cache();
			return true;
		}

		/**
		 * Why the last page-field redirect was refused (null when it was saved).
		 *
		 * @return WP_Error|null
		 */
		public static function last_page_error() {
			return self::$page_error;
		}

		/**
		 * A post's own "Redirect this URL" row.
		 *
		 * @param int $post_id Post id.
		 * @return object|null
		 */
		private static function page_row( $post_id ) {
			global $wpdb;
			$t = self::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['redirects']} WHERE post_id = %d AND origin = 'page' ORDER BY id DESC LIMIT 1", (int) $post_id ) );
		}

		/**
		 * Move a post's own row to the post's current address (publish, slug or
		 * parent change). Left where it is when another redirect already owns the
		 * new address — that one was set by a person and is not replaced.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function rekey_page_row( $post_id ) {
			// Only a published post has a lasting address; a trashed one keeps the row
			// at the address it had, so that address goes on redirecting.
			if ( wp_is_post_revision( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
				return;
			}
			$own = self::page_row( $post_id );
			if ( ! $own ) {
				return;
			}
			$url  = get_permalink( $post_id );
			$from = $url ? self::normalize( $url ) : '';
			if ( '' === $from || '/' === $from || $from === (string) $own->source ) {
				return;
			}
			// Never move the row onto another live post's address (see sync_page_field()).
			if ( self::served_by_other_post( $from, $post_id ) && ! current_user_can( 'manage_options' ) ) {
				return;
			}
			global $wpdb;
			$t = self::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND id <> %d LIMIT 1", $from, (int) $own->id ) );
			if ( $taken ) {
				return;
			}
			$wpdb->update( $t['redirects'], array( 'source' => $from ), array( 'id' => (int) $own->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::flush_cache();
		}

		// ------------------------------------------------------------------
		// CRUD (REST / MCP)
		// ------------------------------------------------------------------

		/**
		 * Validate a redirect (PRD §9.2 copy).
		 *
		 * @param array $in Input { id?, source, target, type, is_regex }.
		 * @return array{clean:array,errors:array,warnings:array}
		 */
		public static function validate( array $in ) {
			global $wpdb;
			$t        = self::tables();
			$errors   = array();
			$warnings = array();
			$id       = isset( $in['id'] ) ? (int) $in['id'] : 0;
			$is_regex = ! empty( $in['is_regex'] );
			$type     = isset( $in['type'] ) ? (int) $in['type'] : 301;
			$type     = in_array( $type, self::TYPES, true ) ? $type : 301;
			$raw_src  = isset( $in['source'] ) ? trim( (string) $in['source'] ) : '';
			$raw_tgt  = isset( $in['target'] ) ? trim( (string) $in['target'] ) : '';
			// A line break would split the Location header; markup has no place in an address.
			$ctl_err  = preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $raw_src . $raw_tgt ) ? __( 'Remove line breaks and control characters from the address.', 'uichemy' ) : '';
			$raw_src  = trim( wp_strip_all_tags( $raw_src ) );
			$raw_tgt  = trim( wp_strip_all_tags( $raw_tgt ) );
			$source   = $is_regex ? $raw_src : self::normalize( $raw_src );
			$target   = $raw_tgt;
			$bad_scheme = '' !== $target && preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $target ) && ! preg_match( '#^https?://#i', $target );
			if ( in_array( $type, array( 410, 451 ), true ) ) {
				$target = '';
			} elseif ( $bad_scheme ) {
				$target = '';
			} elseif ( '' !== $target && ! preg_match( '#^https?://#i', $target ) ) {
				$target = self::target_path( $target );
			} elseif ( '' !== $target ) {
				$target = esc_url_raw( $target );
				// A full URL on this site is stored as a path, so it survives a domain move.
				if ( wp_parse_url( $target, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
					$target = self::target_path( $target );
				}
			}

			if ( $id && ! self::get( $id ) ) {
				$errors['id'] = __( 'This redirect no longer exists.', 'uichemy' );
			}
			if ( '' !== $ctl_err ) {
				$errors['source'] = $ctl_err;
			} elseif ( strlen( $source ) > 255 ) {
				// The column is varchar(255): a longer value would be cut or refused by MySQL.
				$errors['source'] = __( 'The old address can be at most 255 characters.', 'uichemy' );
			}
			if ( strlen( $target ) > 2048 ) {
				$errors['target'] = __( 'The new address can be at most 2048 characters.', 'uichemy' );
			}
			if ( isset( $errors['source'] ) ) {
				// Already reported.
			} elseif ( '' === $source ) {
				$errors['source'] = __( 'Enter the old address.', 'uichemy' );
			} elseif ( ! $is_regex && self::is_core_path( $source ) ) {
				$errors['source'] = __( 'This is a WordPress system address (admin, login or REST API). A redirect there would never run.', 'uichemy' );
			}
			if ( $bad_scheme && ! in_array( $type, array( 410, 451 ), true ) ) {
				$errors['target'] = __( 'Enter a web address starting with https://, or a path like /new-page.', 'uichemy' );
			} elseif ( ! in_array( $type, array( 410, 451 ), true ) && '' === $target ) {
				$errors['target'] = __( 'Enter the new address.', 'uichemy' );
			}
			if ( $is_regex && '' !== $source && null === self::regex( $source ) ) {
				$err = error_get_last();
				/* translators: %s: error. */
				$errors['source'] = sprintf( __( 'This pattern does not work: %s', 'uichemy' ), $err && isset( $err['message'] ) ? preg_replace( '/^preg_match\(\): /', '', $err['message'] ) : __( 'check the brackets and special characters', 'uichemy' ) );
			}
			if ( ! $is_regex && '' !== $source && '' !== $target && self::normalize( self::absolute( $target ) ) === $source && ! preg_match( '#^https?://#i', $target ) ) {
				$errors['target'] = __( 'The old and new address are the same.', 'uichemy' );
			}
			if ( '' !== $source && ! isset( $errors['source'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dup = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['redirects']} WHERE source = %s AND is_regex = %d AND id <> %d LIMIT 1", $source, $is_regex ? 1 : 0, $id ) );
				if ( $dup ) {
					$errors['source']    = __( 'This old address already has a redirect.', 'uichemy' );
					$errors['duplicate'] = $dup;
				}
			}
			// Loop detection: follow exact redirects from the target.
			if ( ! $is_regex && '' !== $target && empty( $errors ) && ! preg_match( '#^https?://#i', $target ) ) {
				$seen  = array( $source => true );
				$hop   = self::normalize( $target );
				$steps = 1;
				while ( $steps < 20 ) {
					if ( isset( $seen[ $hop ] ) ) {
						/* translators: %d: steps. */
						$errors['target'] = sprintf( __( 'This would send visitors in a circle back to the same page after %d steps.', 'uichemy' ), $steps );
						break;
					}
					$seen[ $hop ] = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$next = $wpdb->get_var( $wpdb->prepare( "SELECT target FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND status = 1 AND id <> %d LIMIT 1", $hop, $id ) );
					if ( null === $next || '' === $next || preg_match( '#^https?://#i', $next ) ) {
						break;
					}
					$hop = self::normalize( (string) $next );
					++$steps;
				}
			}
			// A chain: the target itself redirects again, so visitors (and crawlers) take two hops.
			if ( ! $is_regex && '' !== $target && empty( $errors ) && ! preg_match( '#^https?://#i', $target ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$onward = $wpdb->get_var( $wpdb->prepare( "SELECT target FROM {$t['redirects']} WHERE source = %s AND is_regex = 0 AND status = 1 AND id <> %d LIMIT 1", self::normalize( $target ), $id ) );
				if ( null !== $onward && '' !== $onward ) {
					/* translators: 1: target, 2: where the target goes. */
					$warnings['target'] = sprintf( __( '%1$s already redirects to %2$s. Point this redirect straight to %2$s so visitors and search engines make one hop, not two.', 'uichemy' ), $target, $onward );
				}
			}
			// A pattern whose target it matches again sends visitors round in a circle.
			// Back-references are probed with a placeholder segment.
			if ( $is_regex && '' !== $target && empty( $errors ) && ! preg_match( '#^https?://#i', $target ) ) {
				$re    = self::regex( $source );
				$probe = strtok( self::normalize( preg_replace( '/\$\{?\d+\}?|\\\\\d+/', 'x', $target ) ), '?' );
				if ( $re && false !== $probe && @preg_match( $re, $probe ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validated above.
					$errors['target'] = __( 'The pattern also matches this new address, so visitors would be sent in a circle. Make the pattern more specific (for example, start it with ^ and end it with $).', 'uichemy' );
				}
			}
			if ( ! $is_regex && '' !== $source && empty( $errors ) ) {
				$pid = url_to_postid( home_url( $source ) );
				if ( $pid && 'publish' === get_post_status( $pid ) ) {
					/* translators: %s: url. */
					$warnings['source'] = sprintf( __( '%s is a working page right now. If you redirect it, visitors will not be able to reach that page any more.', 'uichemy' ), $source );
				}
			}

			return array(
				'clean'    => array(
					'source'   => $source,
					'target'   => $target,
					'type'     => $type,
					'is_regex' => $is_regex ? 1 : 0,
					'status'   => isset( $in['status'] ) ? ( rest_sanitize_boolean( $in['status'] ) ? 1 : 0 ) : 1,
				),
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		/**
		 * Is a path one WordPress answers before `template_redirect` (admin, login,
		 * REST API, core files)? Redirects there never fire.
		 *
		 * @param string $path Normalised path.
		 * @return bool
		 */
		public static function is_core_path( $path ) {
			$path   = strtok( (string) $path, '?' );
			$rest   = '/' . trim( (string) rest_get_url_prefix(), '/' );
			$path   = false === $path ? '' : $path;
			$is_api = '/' !== $rest && ( $path === $rest || 0 === strpos( $path, $rest . '/' ) );
			return $is_api || (bool) preg_match( self::CORE_PATHS, $path );
		}

		/**
		 * Create or update.
		 *
		 * @param array  $in     Input.
		 * @param string $origin Origin for new rows.
		 * @return array|WP_Error Row.
		 */
		public static function save( array $in, $origin = 'manual' ) {
			global $wpdb;
			// Ten identical saves at once would each pass the "already has a redirect" check
			// before any row exists. A named MySQL lock (released with the connection) lines them up.
			$got = (int) $wpdb->get_var( "SELECT GET_LOCK('uichemy_seo_redirect_save', 5)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			try {
				return self::save_locked( $in, $origin );
			} finally {
				if ( $got ) {
					$wpdb->get_var( "SELECT RELEASE_LOCK('uichemy_seo_redirect_save')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}

		/**
		 * The validate-and-write half of save().
		 *
		 * @param array  $in     Input.
		 * @param string $origin Origin for new rows.
		 * @return array|WP_Error
		 */
		private static function save_locked( array $in, $origin ) {
			global $wpdb;
			$t = self::tables();
			$v = self::validate( $in );
			if ( isset( $v['errors']['id'] ) ) {
				return new WP_Error( 'uich_seo_redirect_not_found', $v['errors']['id'], array( 'status' => 404 ) );
			}
			if ( $v['errors'] ) {
				return new WP_Error( 'uich_seo_redirect_invalid', __( 'This redirect could not be saved.', 'uichemy' ), array( 'status' => 400, 'fields' => $v['errors'] ) );
			}
			$id = isset( $in['id'] ) ? (int) $in['id'] : 0;
			if ( $id && ! self::get( $id ) ) {
				return new WP_Error( 'uich_seo_redirect_missing', __( 'This redirect no longer exists. Reload the list and try again.', 'uichemy' ), array( 'status' => 404 ) );
			}
			if ( $id ) {
				if ( false === $wpdb->update( $t['redirects'], $v['clean'], array( 'id' => $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return new WP_Error( 'uich_seo_redirect_failed', __( 'The redirect could not be saved.', 'uichemy' ), array( 'status' => 500 ) );
				}
			} else {
				$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$t['redirects'],
					array_merge(
						$v['clean'],
						array(
							'created_at' => current_time( 'mysql', true ),
							'created_by' => get_current_user_id(),
							'origin'     => in_array( $origin, self::ORIGINS, true ) ? $origin : 'manual',
						)
					)
				);
				$id = (int) $wpdb->insert_id;
				if ( ! $ok || ! $id ) {
					return new WP_Error( 'uich_seo_redirect_failed', __( 'The redirect could not be saved.', 'uichemy' ), array( 'status' => 500 ) );
				}
			}
			if ( ! $v['clean']['is_regex'] ) {
				self::forget_404( $v['clean']['source'] );
			}
			self::flush_cache();
			$row             = self::get( $id );
			$row['warnings'] = $v['warnings'];
			return $row;
		}

		/**
		 * One row as an array.
		 *
		 * @param int $id Id.
		 * @return array|null
		 */
		public static function get( $id ) {
			global $wpdb;
			$t   = self::tables();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['redirects']} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $row ? self::shape( $row ) : null;
		}

		/**
		 * Row shape for the UI.
		 *
		 * @param array $r Raw row.
		 * @return array
		 */
		private static function shape( array $r ) {
			return array(
				'id'         => (int) $r['id'],
				'source'     => (string) $r['source'],
				'target'     => (string) $r['target'],
				'type'       => (int) $r['type'],
				'is_regex'   => (bool) $r['is_regex'],
				'status'     => (bool) $r['status'],
				'hits'       => (int) $r['hits'],
				'last_hit'   => $r['last_hit'] ? gmdate( 'c', strtotime( $r['last_hit'] . ' UTC' ) ) : null,
				'created_at' => gmdate( 'c', strtotime( $r['created_at'] . ' UTC' ) ),
				'origin'     => (string) $r['origin'],
				'post_id'    => (int) $r['post_id'],
			);
		}

		/**
		 * Delete.
		 *
		 * @param int $id Id.
		 * @return bool
		 */
		public static function delete( $id ) {
			global $wpdb;
			$t  = self::tables();
			$ok = (bool) $wpdb->delete( $t['redirects'], array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::flush_cache();
			return $ok;
		}

		/**
		 * List with search / filters / paging.
		 *
		 * @param array $q { search, type, origin, page, per_page }.
		 * @return array{rows:array,total:int,active:int}
		 */
		public static function query( array $q = array() ) {
			global $wpdb;
			$t     = self::tables();
			$where = array( '1=1' );
			$args  = array();
			if ( ! empty( $q['search'] ) ) {
				$like    = '%' . $wpdb->esc_like( (string) $q['search'] ) . '%';
				$where[] = '(source LIKE %s OR target LIKE %s)';
				$args[]  = $like;
				$args[]  = $like;
			}
			if ( ! empty( $q['type'] ) ) {
				$where[] = 'type = %d';
				$args[]  = (int) $q['type'];
			}
			if ( ! empty( $q['origin'] ) ) {
				$where[] = 'origin = %s';
				$args[]  = (string) $q['origin'];
			}
			$per  = max( 1, min( 200, isset( $q['per_page'] ) ? (int) $q['per_page'] : 50 ) );
			$page = max( 1, isset( $q['page'] ) ? (int) $q['page'] : 1 );
			$w    = implode( ' AND ', $where );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t['redirects']} WHERE $w", $args ) : "SELECT COUNT(*) FROM {$t['redirects']} WHERE $w" );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['redirects']} WHERE $w ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
			$act   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['redirects']} WHERE status = 1" );
			// phpcs:enable
			return array(
				'rows'   => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
				'total'  => $total,
				'active' => $act,
			);
		}

		/**
		 * Active count for the Overview tile.
		 *
		 * @return int
		 */
		public static function active_count() {
			global $wpdb;
			$t = self::tables();
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['redirects']} WHERE status = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		/**
		 * Is a path redirected (for the sitemap)?
		 *
		 * @return string[] Exact active sources.
		 */
		public static function exact_sources() {
			$list = get_transient( 'uich_seo_redir_src' );
			if ( false === $list ) {
				global $wpdb;
				$t    = self::tables();
				$list = (array) $wpdb->get_col( "SELECT source FROM {$t['redirects']} WHERE status = 1 AND is_regex = 0 LIMIT 5000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				set_transient( 'uich_seo_redir_src', $list, HOUR_IN_SECONDS );
			}
			return $list;
		}

		// ------------------------------------------------------------------
		// CSV
		// ------------------------------------------------------------------

		/**
		 * Export CSV.
		 *
		 * @return string
		 */
		public static function export_csv() {
			global $wpdb;
			$t    = self::tables();
			$rows = $wpdb->get_results( "SELECT source, target, type, is_regex FROM {$t['redirects']} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fputcsv( $fh, array( 'source', 'target', 'type', 'regex' ) );
			foreach ( (array) $rows as $r ) {
				// A cell starting = + - @ tab or CR runs as a formula in a spreadsheet: prefix an apostrophe.
				$guard = function ( $cell ) {
					return preg_match( '/^[=+\-@\t\r]/', (string) $cell ) ? "'" . $cell : $cell;
				};
				fputcsv( $fh, array( $guard( $r['source'] ), $guard( $r['target'] ), $r['type'], $r['is_regex'] ) );
			}
			rewind( $fh );
			$csv = stream_get_contents( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return (string) $csv;
		}

		/**
		 * Import CSV — our own export, Yoast (origin,target,type,format) and Rank
		 * Math (source,destination,type) column names are recognised.
		 *
		 * @param string $csv     CSV text.
		 * @param bool   $dry_run Report only.
		 * @return array{added:int,skipped:array,total:int}|WP_Error
		 */
		public static function import_csv( $csv, $dry_run = false ) {
			// Each row costs several queries: cap the file so one request stays short.
			if ( strlen( (string) $csv ) > self::IMPORT_MAX_BYTES ) {
				return new WP_Error( 'uich_seo_import_too_big', __( 'This file is larger than 1 MB. Split it into smaller files.', 'uichemy' ), array( 'status' => 413 ) );
			}
			$lines   = preg_split( '/\r\n|\r|\n/', trim( (string) $csv ) );
			if ( count( $lines ) - 1 > self::IMPORT_MAX_ROWS ) {
				/* translators: %d: max rows. */
				return new WP_Error( 'uich_seo_import_too_big', sprintf( __( 'A file can have at most %d rows. Split it into smaller files.', 'uichemy' ), self::IMPORT_MAX_ROWS ), array( 'status' => 413 ) );
			}
			$header  = array_map( 'strtolower', array_map( 'trim', str_getcsv( (string) array_shift( $lines ) ) ) );
			$col     = function ( array $names ) use ( $header ) {
				foreach ( $names as $n ) {
					$i = array_search( $n, $header, true );
					if ( false !== $i ) {
						return $i;
					}
				}
				return null;
			};
			$c_src   = $col( array( 'source', 'origin', 'from', 'old', 'old url', 'source url' ) );
			$c_tgt   = $col( array( 'target', 'destination', 'to', 'new', 'new url', 'url' ) );
			$c_type  = $col( array( 'type', 'code', 'status', 'redirect type' ) );
			$c_re    = $col( array( 'regex', 'is_regex', 'format' ) );
			$added   = 0;
			$skipped = array();
			if ( null === $c_src ) {
				// No recognised header: treat the first line as data, columns source,target,type.
				array_unshift( $lines, implode( ',', $header ) );
				list( $c_src, $c_tgt, $c_type, $c_re ) = array( 0, 1, 2, 3 );
			}
			// One transaction for the batch: a commit (disk sync) per row is what made 500 rows slow.
			global $wpdb;
			if ( ! $dry_run ) {
				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			foreach ( $lines as $n => $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$cells = array_map(
					function ( $c ) {
						// Undo the export's formula guard so a re-import round-trips.
						return preg_match( "/^'[=+\-@\t\r]/", (string) $c ) ? substr( $c, 1 ) : $c;
					},
					str_getcsv( $line )
				);
				$in    = array(
					'source'   => isset( $cells[ $c_src ] ) ? $cells[ $c_src ] : '',
					'target'   => null !== $c_tgt && isset( $cells[ $c_tgt ] ) ? $cells[ $c_tgt ] : '',
					'type'     => null !== $c_type && isset( $cells[ $c_type ] ) ? (int) $cells[ $c_type ] : 301,
					'is_regex' => null !== $c_re && isset( $cells[ $c_re ] ) && in_array( strtolower( trim( $cells[ $c_re ] ) ), array( '1', 'regex', 'true', 'yes' ), true ),
				);
				$v = self::validate( $in );
				if ( $v['errors'] ) {
					$skipped[] = array(
						'line'   => $n + 2,
						'source' => $in['source'],
						'why'    => implode( ' ', array_filter( $v['errors'], 'is_string' ) ),
					);
					continue;
				}
				if ( ! $dry_run ) {
					self::$bulk = true;
					self::save( $in, 'import' );
					self::$bulk = false;
				}
				++$added;
			}
			if ( ! $dry_run ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( self::$bulk_dirty ) {
				self::$bulk_dirty = false;
				self::flush_cache();
			}
			return array(
				'added'   => $added,
				'skipped' => $skipped,
				'total'   => $added + count( $skipped ),
			);
		}

		// ------------------------------------------------------------------
		// 404 list
		// ------------------------------------------------------------------

		/**
		 * The 404 list.
		 *
		 * @return array
		 */
		public static function not_found() {
			global $wpdb;
			$t    = self::tables();
			$rows = $wpdb->get_results( "SELECT id, url, hits, last_seen, referrer FROM {$t['nf']} WHERE ignored = 0 ORDER BY last_seen DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array_map(
				function ( $r ) {
					return array(
						'id'        => (int) $r['id'],
						'url'       => (string) $r['url'],
						'hits'      => (int) $r['hits'],
						'last_seen' => gmdate( 'c', strtotime( $r['last_seen'] . ' UTC' ) ),
						'referrer'  => (string) $r['referrer'],
					);
				},
				(array) $rows
			);
		}

		/**
		 * Ignore a 404 row (kept, hidden; still subject to the cap).
		 *
		 * @param int $id Id.
		 * @return void
		 */
		public static function ignore_404( $id ) {
			global $wpdb;
			$t = self::tables();
			$wpdb->update( $t['nf'], array( 'ignored' => 1 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		/**
		 * Remove 404 rows for a path once it redirects.
		 *
		 * @param string $path Path.
		 * @return void
		 */
		public static function forget_404( $path ) {
			global $wpdb;
			$t = self::tables();
			$wpdb->delete( $t['nf'], array( 'url' => self::normalize( $path ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
