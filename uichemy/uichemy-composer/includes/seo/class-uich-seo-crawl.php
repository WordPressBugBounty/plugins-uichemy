<?php
/**
 * Crawling (PRD §11): robots.txt through the `robots_txt` filter (never a file on
 * disk), and keeping WordPress's own sitemap honest (S12, S13). We do not build a
 * sitemap.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Crawl' ) ) {

	/**
	 * robots.txt + sitemap sync.
	 */
	class Uich_SEO_Crawl {

		/** Option holding post ids excluded from the sitemap (noindex or redirected). */
		const EXCLUDED = 'uichemy_seo_sitemap_excluded';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
			add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'sitemap_post_types' ), 20 );
			add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'sitemap_taxonomies' ), 20 );
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_providers' ), 20, 2 );
			add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_query' ), 20, 2 );
			add_action( 'uichemy_seo_rebuilt', array( __CLASS__, 'track' ), 10, 1 );
			add_action( 'uichemy_seo_record_saved', array( __CLASS__, 'track' ), 10, 1 );
			add_action( 'uichemy_seo_redirects_changed', array( __CLASS__, 'forget_redirect_cache' ) );
		}

		/**
		 * Do we touch crawling output at all?
		 *
		 * @return bool
		 */
		private static function on() {
			return Uich_SEO_Ownership::we_emit();
		}

		/**
		 * Is the sitemap sync on?
		 *
		 * @return bool
		 */
		private static function sync() {
			return self::on() && (bool) Uich_SEO_Settings::get( 'sitemap_sync', true );
		}

		// ------------------------------------------------------------------
		// robots.txt
		// ------------------------------------------------------------------

		/**
		 * `robots_txt`.
		 *
		 * @param string $output Core output.
		 * @param bool   $public Blog public.
		 * @return string
		 */
		public static function robots_txt( $output, $public ) {
			if ( ! self::on() || 'custom' !== Uich_SEO_Settings::get( 'robots_mode', 'wordpress' ) || ! $public ) {
				return $output;
			}
			$custom = trim( (string) Uich_SEO_Settings::get( 'robots_content', '' ) );
			if ( '' === $custom ) {
				return $output;
			}
			$custom = str_replace( "\r\n", "\n", $custom ) . "\n";
			// Keep the sitemap discoverable unless the rules already name one.
			$sitemap = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : '';
			if ( $sitemap && false === stripos( $custom, 'sitemap:' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
				$custom .= "\nSitemap: " . esc_url( $sitemap ) . "\n";
			}
			return $custom;
		}

		/**
		 * What WordPress (and other plugins) produce without our rules — the
		 * read-only block above the editor (PRD §11.1).
		 *
		 * @return string
		 */
		public static function core_robots() {
			remove_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99 );
			$public = (bool) get_option( 'blog_public' );
			$out    = "User-agent: *\n";
			// Core keeps crawlers out of wp-admin whether or not the site is public (a hidden
			// site is noindexed by meta tag, and robots.txt stays open so crawlers can read it).
			$site_url = wp_parse_url( site_url() );
			$path     = ! empty( $site_url['path'] ) ? $site_url['path'] : '';
			$out     .= "Disallow: $path/wp-admin/\n";
			$out     .= "Allow: $path/wp-admin/admin-ajax.php\n";
			$out = (string) apply_filters( 'robots_txt', $out, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
			return $out;
		}

		/**
		 * PRD §11.1 warning: rules that block the whole site for every crawler.
		 *
		 * @param string $rules Rules.
		 * @return bool
		 */
		public static function blocks_everything( $rules ) {
			$agent = '';
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $rules ) as $line ) {
				$line = trim( preg_replace( '/#.*$/', '', $line ) );
				if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
					$agent = trim( $m[1] );
				} elseif ( '*' === $agent && preg_match( '#^disallow:\s*/\s*$#i', $line ) ) {
					return true;
				}
			}
			return false;
		}

		// ------------------------------------------------------------------
		// Sitemap sync
		// ------------------------------------------------------------------

		/**
		 * Remove post types switched out of the sitemap, set to noindex by default,
		 * and the theme-builder template CPT (R6.2).
		 *
		 * @param array $types Types.
		 * @return array
		 */
		public static function sitemap_post_types( $types ) {
			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				unset( $types[ UiChemy_Template_CPT::POST_TYPE ] );
			}
			if ( ! self::sync() ) {
				return $types;
			}
			foreach ( array_keys( $types ) as $pt ) {
				$row = Uich_SEO_Settings::type_row( $pt );
				if ( ! $row['sitemap'] || ! $row['index'] ) {
					unset( $types[ $pt ] );
				}
			}
			return $types;
		}

		/**
		 * Taxonomies out of the sitemap.
		 *
		 * @param array $taxes Taxonomies.
		 * @return array
		 */
		public static function sitemap_taxonomies( $taxes ) {
			if ( ! self::sync() ) {
				return $taxes;
			}
			foreach ( array_keys( $taxes ) as $tx ) {
				$row = Uich_SEO_Settings::taxonomy_row( $tx );
				if ( ! $row['sitemap'] || ! $row['index'] ) {
					unset( $taxes[ $tx ] );
				}
			}
			return $taxes;
		}

		/**
		 * Drop the users sitemap when author archives are noindexed.
		 *
		 * @param WP_Sitemaps_Provider|false $provider Provider.
		 * @param string                     $name     Name.
		 * @return WP_Sitemaps_Provider|false
		 */
		public static function sitemap_providers( $provider, $name ) {
			if ( 'users' === $name && self::sync() && Uich_SEO_Settings::get( 'noindex_author', true ) ) {
				return false;
			}
			return $provider;
		}

		/**
		 * Exclude noindexed and redirected posts (PRD §18.5 #41).
		 *
		 * @param array  $args      Query args.
		 * @param string $post_type Post type.
		 * @return array
		 */
		public static function sitemap_query( $args, $post_type ) {
			if ( ! self::sync() ) {
				return $args;
			}
			$ids = self::excluded_ids();
			if ( $ids ) {
				$args['post__not_in'] = array_values( array_unique( array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ids ) ) );
			}
			$args['has_password'] = false;
			return $args;
		}

		/**
		 * Excluded ids: tracked noindex posts + posts whose address now redirects.
		 *
		 * @return int[]
		 */
		public static function excluded_ids() {
			$ids = array_map( 'intval', (array) get_option( self::EXCLUDED, array() ) );
			$red = get_transient( 'uich_seo_redir_posts' );
			if ( false === $red ) {
				$red = array();
				if ( class_exists( 'Uich_SEO_Redirects' ) ) {
					foreach ( array_slice( Uich_SEO_Redirects::exact_sources(), 0, 2000 ) as $src ) {
						$pid = url_to_postid( home_url( $src ) );
						if ( $pid ) {
							$red[] = $pid;
						}
					}
				}
				set_transient( 'uich_seo_redir_posts', $red, HOUR_IN_SECONDS );
			}
			return array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $red ) ) ) );
		}

		/**
		 * Keep the excluded list (a cache of noindex pages, rebuilt from each page's
		 * head code on save) in step with a post's record.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public static function track( $post_id ) {
			$post_id = (int) $post_id;
			$record  = Uich_SEO_Record::get( $post_id );
			$f       = $record['fields'];
			// Redirected pages are covered by excluded_ids() from the Redirects table.
			$out = isset( $f['index']['value'] ) && false === (bool) $f['index']['value'];
			$list = array_map( 'intval', (array) get_option( self::EXCLUDED, array() ) );
			$has  = in_array( $post_id, $list, true );
			if ( $out && ! $has ) {
				$list[] = $post_id;
				update_option( self::EXCLUDED, array_values( $list ), false );
			} elseif ( ! $out && $has ) {
				update_option( self::EXCLUDED, array_values( array_diff( $list, array( $post_id ) ) ), false );
			}
		}

		/**
		 * Redirects changed → recompute the redirected-post set lazily.
		 *
		 * @return void
		 */
		public static function forget_redirect_cache() {
			delete_transient( 'uich_seo_redir_posts' );
		}
	}
}
