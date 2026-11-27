<?php
/**
 * Agent pages: a clean Markdown file for every public URL.
 *
 * Three ways in, one file out:
 *   /menu/?view=agent        301 to /menu.md (parameter name and value are settings)
 *   /menu.md                 the agent file
 *   /menu/ + Accept: text/markdown   the agent file at the normal URL (Vary: Accept)
 *
 * How a file is made: WordPress renders the real page for a logged-out visitor,
 * through its own routing, so templates, archives, WooCommerce and language
 * prefixes all work with no special cases. The HTML is captured and converted
 * (Uich_Agent_Convert), then cached on disk until content changes. Cached files
 * are served before the theme loads.
 *
 * Agent files never compete with the real page in search: every response sends
 * a canonical Link header to the HTML page, and agent files are never listed in
 * the XML sitemap.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Pages' ) ) {

	/**
	 * Agent page routing, rendering, caching.
	 */
	class Uich_Agent_Pages {

		/**
		 * Option holding the cache generation (bumped when content changes).
		 */
		const GEN_OPTION = 'uichemy_agent_ready_gen';

		/**
		 * Post meta: the agent file as the owner wrote it (replaces the generated one).
		 */
		const META_MD = '_uichemy_agent_markdown';

		/**
		 * Post meta: hide this page from agents.
		 */
		const META_HIDE = '_uichemy_agent_hide';

		/**
		 * Whether this request is producing an agent file.
		 *
		 * @var bool
		 */
		private static $active = false;

		/**
		 * The page path being rendered (after removing .md).
		 *
		 * @var string
		 */
		private static $page_path = '';

		/**
		 * Whether the cache generation was already bumped in this request.
		 *
		 * @var bool
		 */
		private static $bumped = false;

		/**
		 * Whether the request came in as /something.md (a distinct, cacheable URL).
		 *
		 * @var bool
		 */
		private static $via_md = false;

		/**
		 * Hook in. Called from the module bootstrap on plugins_loaded.
		 *
		 * @return void
		 */
		public static function init() {
			// Content changes clear agent files (all of them: one cheap generation bump).
			foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'edited_term', 'delete_term', 'created_term', 'wp_update_nav_menu', 'switch_theme', 'customize_save_after', 'uichemy_agent_ready_settings_updated', 'uichemy/theme_builder/changed', 'uichemy/builder/after_write', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'on_change' ), 10, 3 );
			}
			add_action( 'update_option_blogname', array( __CLASS__, 'bump' ) );
			add_action( 'update_option_blogdescription', array( __CLASS__, 'bump' ) );
			add_action( 'uichemy_agent_ready_cache_cleanup', array( __CLASS__, 'cleanup' ) );
			add_action( 'uichemy_agent_ready_warm', array( __CLASS__, 'warm' ) );
			if ( ! wp_next_scheduled( 'uichemy_agent_ready_cache_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'uichemy_agent_ready_cache_cleanup' );
			}

			if ( self::skip_request() ) {
				return;
			}

			$settings = Uich_Agent_Settings::value( 'agent_pages' );
			// Switched off on the Agent Ready screen: no agent pages are served.
			if ( isset( $settings['enabled'] ) && ! $settings['enabled'] ) {
				return;
			}
			$path     = Uich_Agent_Urls::request_path();

			// 1. ?view=agent: redirect to the .md file (or serve in place when .md URLs are off).
			$name  = $settings['param_name'];
			$value = $settings['param_value'];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public view switch.
			if ( isset( $_GET[ $name ] ) && $value === sanitize_key( wp_unslash( $_GET[ $name ] ) ) ) {
				// ?p=ID / ?page_id=ID: the path is just "/", so resolve the post first.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public view switch.
				$id = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : ( isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0 );
				if ( $id && ! did_action( 'init' ) ) {
					// Permalinks are not ready this early: resolve once WordPress is.
					add_action( 'init', array( __CLASS__, 'init' ), 0 );
					return;
				}
				if ( $id ) {
					$post = get_post( $id );
					if ( ! $post || ! Uich_Agent_Access::visible( $post ) ) {
						self::send_headers( 404 );
						echo "# Not available\n\nThere is no agent page for this address.\n";
						exit;
					}
					$path = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
				}
				if ( $settings['md_urls'] ) {
					wp_safe_redirect( home_url( ltrim( substr( Uich_Agent_Urls::md_path( $path ), strlen( Uich_Agent_Urls::home_path() ) ), '/' ) ), 301 );
					exit;
				}
				self::start( $path );
				return;
			}

			// 2. /something.md.
			if ( $settings['md_urls'] ) {
				$page = Uich_Agent_Urls::page_path( $path );
				if ( false !== $page ) {
					self::$via_md = true;
					self::rewrite_request( $page );
					self::start( $page );
					return;
				}
			}

			// 3. Accept: text/markdown on a normal URL.
			if ( $settings['accept_negotiation'] && Uich_Agent_Urls::prefers_markdown() ) {
				self::start( $path );
				return;
			}

			// Normal HTML page: advertise the agent file.
			add_action( 'template_redirect', array( __CLASS__, 'html_headers' ), 1 );
			add_action( 'wp_head', array( __CLASS__, 'head_link' ), 2 );
		}

		/**
		 * Requests that never produce or advertise an agent file.
		 *
		 * @return bool
		 */
		private static function skip_request() {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
				return true;
			}
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
				return true;
			}
			$path = Uich_Agent_Urls::request_path();
			return (bool) preg_match( '#/(wp-admin|wp-json|wp-content|wp-includes|wp-login|xmlrpc)(/|\.md$|\.php|$)#i', $path );
		}

		/**
		 * Point WordPress at the page path so its own routing resolves it.
		 *
		 * The multilingual module reads the language prefix from the first path
		 * segment, which is unchanged, so /fr/menu.md renders the French copy.
		 *
		 * @param string $page Page path.
		 * @return void
		 */
		private static function rewrite_request( $page ) {
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- rebuilt from the parsed path.
			$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
			$_SERVER['REQUEST_URI'] = $page . ( '' === $query ? '' : '?' . $query );
		}

		/**
		 * Enter agent mode for this request.
		 *
		 * @param string $page Page path.
		 * @return void
		 */
		private static function start( $page ) {
			self::$active    = true;
			self::$page_path = $page;

			// From here the page is rendered as HTML and converted by us. Other plugins that
			// answer Markdown requests themselves (Elementor's agents module serves any
			// `Accept: text/markdown` request at template_redirect) must not take it over
			// before our capture runs, so the rest of the request asks for HTML.
			$_SERVER['HTTP_ACCEPT'] = 'text/html';

			// Fast path: a cached file is served before the theme loads. Not while a
			// membership plugin is active: its rules are checked on every render.
			$cached = Uich_Agent_Access::membership_active() ? false : self::cache_file( $page );
			if ( $cached && is_file( $cached ) ) {
				if ( Uich_Agent_Limiter::allow( 'cached' ) ) {
					self::send_headers( 200 );
					readfile( $cached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- local cache file.
					exit;
				}
				Uich_Agent_Limiter::refuse();
			}

			if ( ! Uich_Agent_Limiter::allow( 'uncached' ) ) {
				Uich_Agent_Limiter::refuse();
			}

			// Render exactly what a logged-out visitor sees: no login, no unlocked
			// password pages, nothing personal. These cookies are dropped for the
			// whole request, so a page one visitor unlocked can never be rendered,
			// cached and then served to everyone.
			// Every cookie goes, not only login ones: currency, language, geo or A/B
			// cookies also change the render, and the result is cached by address only.
			$_COOKIE = array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleared, not read.
			// Hidden and password-protected posts never appear in agent archives.
			add_action( 'pre_get_posts', array( __CLASS__, 'agent_query' ) );
			add_filter( 'determine_current_user', '__return_zero', PHP_INT_MAX );
			wp_set_current_user( 0 );
			add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Other page caches must not store this response as HTML.
			}

			// Keep redirects inside the agent view.
			add_filter( 'redirect_canonical', array( __CLASS__, 'agent_redirect' ), PHP_INT_MAX );
			add_filter( 'wp_redirect', array( __CLASS__, 'agent_redirect' ), PHP_INT_MAX );

			add_action( 'template_redirect', array( __CLASS__, 'begin_capture' ), PHP_INT_MAX );
		}

		/**
		 * Agent requests: queries never return hidden or password-protected posts.
		 *
		 * @param WP_Query $query Query.
		 * @return void
		 */
		public static function agent_query( $query ) {
			if ( ! $query instanceof WP_Query ) {
				return;
			}
			// Nest under AND: merging into a query that already uses relation OR would
			// turn the hide clauses into alternatives.
			$own  = (array) $query->get( 'meta_query' );
			$meta = array_merge( array( 'relation' => 'AND' ), Uich_Agent_Access::meta_query() );
			if ( $own ) {
				$meta[] = $own;
			}
			$query->set( 'meta_query', $meta );
			$query->set( 'has_password', false );
		}

		/**
		 * Rewrite a same-site redirect target to its agent file.
		 *
		 * @param string|false $location Target.
		 * @return string|false
		 */
		public static function agent_redirect( $location ) {
			if ( ! is_string( $location ) || '' === $location ) {
				return $location;
			}
			if ( is_404() ) {
				return false; // A missing page stays a Markdown 404, never the home page.
			}
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( wp_parse_url( $location, PHP_URL_HOST ) && wp_parse_url( $location, PHP_URL_HOST ) !== $host ) {
				// Never send an agent off-site from an agent URL.
				return home_url( '/index.md' );
			}
			$path = (string) wp_parse_url( $location, PHP_URL_PATH );
			if ( preg_match( '#\.md$#', $path ) ) {
				return $location;
			}
			return Uich_Agent_Urls::md_url( $location );
		}

		/**
		 * Decide eligibility, then capture the rendered page.
		 *
		 * @return void
		 */
		public static function begin_capture() {
			$reason = self::ineligible();
			if ( $reason ) {
				self::send_headers( 404 );
				echo '# Not available' . "\n\n" . 'There is no agent page for this address (' . esc_html( $reason ) . ').' . "\n";
				exit;
			}
			self::send_headers( 200 );
			ob_start( array( __CLASS__, 'finish' ) );
		}

		/**
		 * Why the current request has no agent file, or '' when it has one.
		 *
		 * @return string
		 */
		private static function ineligible() {
			$s = Uich_Agent_Settings::value( 'agent_pages' );

			if ( is_404() ) {
				return 'not found';
			}
			if ( is_search() || is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() || is_date() || is_attachment() ) {
				return 'not a content page';
			}
			if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
				return 'personal page';
			}

			foreach ( $s['exclude_patterns'] as $pattern ) {
				if ( function_exists( 'fnmatch' ) ? fnmatch( $pattern, self::$page_path ) : $pattern === self::$page_path ) {
					return 'excluded';
				}
			}

			if ( is_singular() ) {
				$post = get_queried_object();
				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
					return 'not public';
				}
				if ( get_post_meta( $post->ID, self::META_HIDE, true ) || Uich_Agent_Access::seo_excluded( $post ) ) {
					return 'hidden from agents';
				}
				if ( $s['post_types'] && ! in_array( $post->post_type, $s['post_types'], true ) && ! is_front_page() ) {
					return 'post type not included';
				}
				if ( Uich_Agent_Access::restricted( $post ) ) {
					return 'members only';
				}
				return '';
			}

			if ( is_author() ) {
				return $s['archives']['author'] ? '' : 'author archives are off';
			}
			if ( is_category() || is_tag() || is_tax() ) {
				return $s['archives']['taxonomy'] ? '' : 'taxonomy archives are off';
			}
			if ( is_post_type_archive() || is_home() ) {
				return $s['archives']['post_type'] || is_front_page() ? '' : 'archives are off';
			}
			return is_front_page() ? '' : 'not a content page';
		}

		/**
		 * Output-buffer callback: convert the rendered page and cache it.
		 *
		 * @param string $html Rendered HTML.
		 * @return string Markdown.
		 */
		public static function finish( $html ) {
			$page_url = home_url( ltrim( substr( self::$page_path, strlen( Uich_Agent_Urls::home_path() ) ), '/' ) );
			$images   = (bool) Uich_Agent_Settings::value( 'agent_pages.content.image_alt', true );
			$result   = Uich_Agent_Convert::page( $html, $page_url, array( 'images' => $images ) );

			if ( $result['noindex'] && ! Uich_Agent_Settings::value( 'agent_pages.include_noindex', false ) ) {
				status_header( 404 );
				return "# Not available\n\nThis page is not listed for search or agents.\n";
			}

			$markdown = self::document( $result, $page_url );

			$file = self::cache_file( self::$page_path );
			if ( $file && '' !== trim( $result['markdown'] ) ) {
				self::write_cache( $file, $markdown );
			}

			/**
			 * Fires after an agent file is built (for logs and warm-up).
			 *
			 * @param string $page_url Page URL.
			 * @param int    $bytes    Size of the agent file.
			 */
			do_action( 'uichemy_agent_ready_page_built', $page_url, strlen( $markdown ) );

			return $markdown;
		}

		/**
		 * Wrap converted content with front matter and the agent footer.
		 *
		 * @param array  $result   Converter result.
		 * @param string $page_url Page URL.
		 * @return string
		 */
		private static function document( array $result, $page_url ) {
			$s         = Uich_Agent_Settings::value( 'agent_pages.content' );
			$canonical = $result['canonical'] ? $result['canonical'] : $page_url;
			$title     = $result['title'];
			$queried   = get_queried_object();
			$post      = $queried instanceof WP_Post ? $queried : null;
			if ( ! $post && is_front_page() && get_option( 'page_on_front' ) ) {
				$post = get_post( (int) get_option( 'page_on_front' ) );
			}
			// Untitled post: the converter falls back to the site name.
			if ( $post && '' === trim( $post->post_title ) ) {
				$slug  = trim( (string) wp_parse_url( $page_url, PHP_URL_PATH ), '/' );
				$slug  = $slug ? basename( $slug ) : '';
				$title = ( $slug && ! is_numeric( $slug ) ) ? ucfirst( str_replace( '-', ' ', $slug ) ) : __( 'Untitled', 'uichemy' );
			}

			// The owner edited this page's agent file: serve it exactly as written.
			if ( $post ) {
				$own = (string) get_post_meta( $post->ID, self::META_MD, true );
				if ( '' !== trim( $own ) ) {
					return rtrim( $own ) . "\n";
				}
			}

			$out = '';
			if ( $s['front_matter'] ) {
				$fm = array(
					'title' => $title,
					'url'   => $canonical,
				);
				if ( $result['lang'] ) {
					$fm['lang'] = $result['lang'];
				}
				// The page's meta description is deliberately not copied: it can be
				// derived from hidden text that the converter removed from the body.
				$desc = self::description( $post );
				if ( '' !== $desc ) {
					$fm['description'] = $desc;
				}
				if ( $post ) {
					$fm['updated'] = gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) );
				} elseif ( is_front_page() || is_home() ) {
					$fm['updated'] = gmdate( 'c', strtotime( get_lastpostmodified( 'gmt' ) . ' UTC' ) );
				}
				$out .= "---\n";
				foreach ( $fm as $key => $value ) {
					$out .= $key . ': ' . wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
				}
				$out .= "---\n\n";
			}

			$out .= '> Site guide for AI agents: ' . home_url( '/llms.txt' ) . "\n\n";

			$body = $result['markdown'];
			if ( '' !== $title && ! preg_match( '/^#\s/m', $body ) ) {
				$out .= '# ' . $title . "\n\n";
			}
			$out .= $body;


			/**
			 * Filter the closing lines of every agent file (site MCP, contact links).
			 *
			 * @param string[] $lines    Markdown lines.
			 * @param string   $page_url Page URL.
			 */
			$footer = apply_filters( 'uichemy_agent_ready_page_footer', array(), $page_url );
			if ( $s['profile_footer'] && $footer ) {
				$out .= "\n---\n\n" . implode( "\n", array_map( 'strval', $footer ) ) . "\n";
			}

			return $out;
		}

		/**
		 * Short description for the front matter: SEO description, then excerpt.
		 *
		 * @param WP_Post|null $post Post.
		 * @return string
		 */
		private static function description( $post ) {
			$desc = '';
			if ( $post ) {
				if ( class_exists( 'Uich_SEO_Record' ) ) {
					$record = Uich_SEO_Record::get( $post->ID );
					$desc   = isset( $record['fields']['description']['value'] ) && is_string( $record['fields']['description']['value'] ) ? $record['fields']['description']['value'] : '';
				}
				foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description' ) as $key ) {
					if ( '' === trim( $desc ) ) {
						$desc = (string) get_post_meta( $post->ID, $key, true );
					}
				}
				// SEO plugins may hold variables like %%sep%%: drop them.
				$desc = preg_replace( '/%%[^%]+%%|%[a-z_]+%/i', '', $desc );
				if ( '' === trim( $desc ) && '' !== trim( $post->post_excerpt ) ) {
					$desc = $post->post_excerpt;
				}
			} elseif ( is_front_page() || is_home() ) {
				$desc = get_bloginfo( 'description' );
			}
			return Uich_Agent_Convert::plain_text( (string) $desc, 60 );
		}

		/**
		 * Log one agent page read.
		 *
		 * @param int $status HTTP status.
		 * @return void
		 */
		private static function log_read( $status ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prefix check only.
			// The cache warm-up requests itself: not an agent read.
			if ( 0 === stripos( $ua, 'WordPress/' ) || ! class_exists( 'Uich_Agent_Log' ) ) {
				return;
			}
			Uich_Agent_Log::add(
				'page',
				array(
					'path'   => (string) self::$page_path,
					'ok'     => $status < 400,
					'status' => (int) $status,
				)
			);
		}

		/**
		 * Headers for an agent file response.
		 *
		 * @param int $status HTTP status.
		 * @return void
		 */
		private static function send_headers( $status ) {
			if ( headers_sent() ) {
				return;
			}
			status_header( $status );
			self::log_read( $status );
			$page_url = home_url( ltrim( substr( self::$page_path, strlen( Uich_Agent_Urls::home_path() ) ), '/' ) );
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'Vary: Accept' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Link: <' . esc_url_raw( $page_url ) . '>; rel="canonical"', false );
			// A Markdown answer at the normal page URL is private: some CDNs ignore
			// Vary, and must never hand it to a browser. The .md URL is its own address.
			header( 'Cache-Control: ' . ( 200 !== $status ? 'no-store' : ( self::$via_md ? 'public, max-age=300' : 'private, max-age=300' ) ) );
		}

		/**
		 * On a normal HTML page: Vary on Accept and a Link header to the agent file.
		 *
		 * @return void
		 */
		public static function html_headers() {
			if ( headers_sent() || ! self::advertise() ) {
				return;
			}
			if ( Uich_Agent_Settings::value( 'agent_pages.accept_negotiation', true ) ) {
				header( 'Vary: Accept', false );
			}
			if ( Uich_Agent_Settings::value( 'discovery.link_headers', true ) && Uich_Agent_Settings::value( 'agent_pages.md_urls', true ) ) {
				header( 'Link: <' . esc_url_raw( Uich_Agent_Urls::md_url( home_url( Uich_Agent_Urls::request_path() ) ) ) . '>; rel="alternate"; type="text/markdown"', false );
			}
		}

		/**
		 * On a normal HTML page: <link rel="alternate" type="text/markdown">.
		 *
		 * @return void
		 */
		public static function head_link() {
			if ( ! self::advertise() || ! Uich_Agent_Settings::value( 'discovery.head_links', true ) || ! Uich_Agent_Settings::value( 'agent_pages.md_urls', true ) ) {
				return;
			}
			printf( '<link rel="alternate" type="text/markdown" href="%s" title="%s" />' . "\n", esc_url( Uich_Agent_Urls::md_url( home_url( Uich_Agent_Urls::request_path() ) ) ), esc_attr__( 'This page for AI agents (Markdown)', 'uichemy' ) );
		}

		/**
		 * Whether the current HTML page has an agent file worth pointing to.
		 *
		 * @return bool
		 */
		private static function advertise() {
			self::$page_path = Uich_Agent_Urls::request_path();
			return '' === self::ineligible();
		}

		/* -------------------------------------------------------------
		 * Cache
		 * ----------------------------------------------------------- */

		/**
		 * Cache file for a page path in the current generation.
		 *
		 * @param string $page Page path.
		 * @return string|false
		 */
		private static function cache_file( $page ) {
			// A query string can change what the page shows (search, filters, paging
			// by parameter), so such requests are never read from or written to cache.
			// The view parameter itself is not a reason: it redirects before this.
			$query = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only counted.
			unset( $query[ Uich_Agent_Settings::value( 'agent_pages.param_name', 'view' ) ] );
			if ( ! empty( $query ) ) {
				return false;
			}
			return self::cache_path( $page );
		}

		/**
		 * Cache file path for a page path, without looking at the current request.
		 *
		 * @param string $page Page path.
		 * @return string|false
		 */
		private static function cache_path( $page ) {
			$root = Uich_Agent_Store::cache_root();
			if ( is_wp_error( $root ) ) {
				return false;
			}
			$gen  = (int) get_option( self::GEN_OPTION, 1 );
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$hash = sha1( ( is_ssl() ? 'https' : 'http' ) . '|' . $host . '|' . $page );
			return $root . 'pages/g' . $gen . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.md';
		}

		/**
		 * The saved agent file of a page, read straight from the cache (no web request).
		 *
		 * The dashboard uses this to show what AI reads without loading the page again.
		 * Returns false when nothing is saved yet (first visit after a change); the
		 * caller then fetches the page the normal way.
		 *
		 * @param string $md_url Agent file address, e.g. https://site/about.md.
		 * @return string|false
		 */
		public static function cached_markdown( $md_url ) {
			if ( Uich_Agent_Access::membership_active() ) {
				return false;
			}
			$page = Uich_Agent_Urls::page_path( (string) wp_parse_url( (string) $md_url, PHP_URL_PATH ) );
			if ( false === $page ) {
				return false;
			}
			$file = self::cache_path( $page );
			if ( ! $file || ! is_file( $file ) ) {
				return false;
			}
			$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file.
			return is_string( $body ) ? $body : false;
		}

		/**
		 * Write a cache file atomically.
		 *
		 * @param string $file Path.
		 * @param string $body Contents.
		 * @return void
		 */
		private static function write_cache( $file, $body ) {
			$dir = dirname( $file );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return;
			}
			$tmp = $file . '.tmp-' . wp_generate_password( 6, false, false );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache write.
			if ( false !== file_put_contents( $tmp, $body, LOCK_EX ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace.
				@rename( $tmp, $file );
			}
		}

		/**
		 * Content changed: start a new cache generation (once per request).
		 *
		 * @param mixed $a First hook argument.
		 * @param mixed $b Second hook argument.
		 * @param mixed $c Third hook argument.
		 * @return void
		 */
		public static function on_change( $a = null, $b = null, $c = null ) {
			// Meta hooks fire for every meta write; only these keys change agent files.
			// The first write of a key fires added_post_meta, removal deleted_post_meta.
			if ( in_array( current_filter(), array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ), true ) ) {
				if ( ! in_array( $c, array( self::META_HIDE, self::META_MD, '_uichemy_seo', '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots', '_members_access_role', 'um_content_restriction', '_is_preview', '_visibility' ), true ) ) {
					return;
				}
				if ( '_uichemy_seo' === $c ) {
					Uich_Agent_Access::sync_seo_hide( (int) $b );
				}
			}
			// Private types (the requests inbox, templates) never appear in agent files,
			// so saving them must not throw the whole cache away.
			if ( 'save_post' === current_filter() && $b instanceof WP_Post && ( wp_is_post_revision( $b ) || wp_is_post_autosave( $b ) || 'auto-draft' === $b->post_status || ! is_post_type_viewable( $b->post_type ) ) ) {
				return;
			}
			self::bump();
		}

		/**
		 * Start a new cache generation.
		 *
		 * @return void
		 */
		public static function bump() {
			if ( self::$bumped ) {
				return;
			}
			self::$bumped = true;
			update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 1 ) + 1, true );

			// Rebuild the most important files a minute later, so the first agent after
			// an edit does not pay for the render. Several edits in a row share one run.
			if ( Uich_Agent_Settings::value( 'agent_pages.warm.enabled', true ) && ! wp_next_scheduled( 'uichemy_agent_ready_warm' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'uichemy_agent_ready_warm' );
			}
		}

		/**
		 * Warm the cache: front page, llms key pages, then the newest public content of any type.
		 *
		 * Uses non-blocking loopback requests, paced by the speed setting, so a
		 * shared host is never flooded.
		 *
		 * @return void
		 */
		public static function warm() {
			$counts = array(
				'gentle' => 10,
				'normal' => 30,
				'fast'   => 80,
			);
			$speed  = (string) Uich_Agent_Settings::value( 'agent_pages.warm.speed', 'gentle' );
			$limit  = isset( $counts[ $speed ] ) ? $counts[ $speed ] : 10;

			$urls = array( home_url( '/' ) );
			foreach ( (array) Uich_Agent_Settings::value( 'llms.key_pages', array() ) as $id ) {
				$url = get_permalink( $id );
				if ( $url ) {
					$urls[] = $url;
				}
			}
			$recent = get_posts(
				array(
					// Every public type the Pages step lists, so custom types (doctors, products…) are warmed too.
					'post_type'        => array_values( array_diff( get_post_types( array( 'public' => true ) ), Uich_Agent_Tools::BLOCKED_TYPES ) ),
					'post_status'      => 'publish',
					'numberposts'      => $limit,
					'orderby'          => 'modified',
					'fields'           => 'ids',
					'has_password'     => false,
					'suppress_filters' => false,
				)
			);
			foreach ( $recent as $id ) {
				$urls[] = get_permalink( $id );
			}

			foreach ( array_slice( array_unique( array_filter( $urls ) ), 0, $limit ) as $url ) {
				wp_remote_get(
					Uich_Agent_Urls::md_url( $url ),
					array(
						'blocking'  => false,
						'timeout'   => 1,
						'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
						'headers'   => array( 'Accept' => 'text/markdown' ),
					)
				);
				if ( 'fast' !== $speed ) {
					usleep( 200000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pace loopback requests.
				}
			}
		}

		/**
		 * Daily: delete cache generations older than the current one.
		 *
		 * @return void
		 */
		public static function cleanup() {
			Uich_Agent_Limiter::prune();
			$root = Uich_Agent_Store::cache_root();
			if ( is_wp_error( $root ) || ! is_dir( $root . 'pages' ) ) {
				return;
			}
			$current = 'g' . (int) get_option( self::GEN_OPTION, 1 );
			foreach ( (array) glob( $root . 'pages/g*', GLOB_ONLYDIR ) as $dir ) {
				if ( basename( $dir ) !== $current ) {
					self::remove_dir( $dir );
				}
			}
		}

		/**
		 * Remove a cache directory tree.
		 *
		 * @param string $dir Directory.
		 * @return void
		 */
		private static function remove_dir( $dir ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		/**
		 * Whether this request is producing an agent file.
		 *
		 * @return bool
		 */
		public static function is_agent_request() {
			return self::$active;
		}
	}
}
