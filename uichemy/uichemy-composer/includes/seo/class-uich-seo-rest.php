<?php
/**
 * REST for the SEO panel and the dashboard screens — namespace `uichemy/v1`, base `seo`.
 *
 * One contract for every surface (D16): the panel in Gutenberg, the classic
 * metabox, the Elementor modal and the dashboard all read and write here.
 *
 *   GET  seo/post/{id}          panel payload
 *   POST seo/post/{id}          { fields: { key: value|null } }   (null = reset)
 *   POST seo/post/{id}/ignore   { check, ignore }
 *   GET  seo/settings           site settings + content-type rows + ownership
 *   POST seo/settings           { values }
 *   POST seo/settings/reset
 *   GET  seo/overview           status tiles + findings rollup
 *   POST seo/rebuild            { offset } → one batch of the site-wide rebuild
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Rest' ) ) {

	/**
	 * REST routes.
	 */
	class Uich_SEO_Rest {

		const NS = 'uichemy/v1';

		/** Rebuild batch size. */
		const BATCH = 20;

		/** Pages the overview reads; ids only, so large sites stay one fast request. */
		const OVERVIEW_MAX = 5000;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		}

		/**
		 * Register routes.
		 *
		 * @return void
		 */
		public static function routes() {
			$can_edit = function ( WP_REST_Request $r ) {
				// A missing post is a 404, not a misleading 403 from edit_post on an unknown id.
				if ( ! get_post( (int) $r['id'] ) ) {
					return new WP_Error( 'uich_seo_not_found', __( 'Page not found.', 'uichemy' ), array( 'status' => 404 ) );
				}
				return current_user_can( 'edit_post', (int) $r['id'] );
			};
			$admin = function () {
				return current_user_can( 'manage_options' );
			};

			register_rest_route( self::NS, '/seo/post/(?P<id>\d+)', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_post' ),
					'permission_callback' => $can_edit,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_post' ),
					'permission_callback' => $can_edit,
				),
			) );
			register_rest_route( self::NS, '/seo/post/(?P<id>\d+)/ignore', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ignore' ),
				'permission_callback' => $can_edit,
			) );
			register_rest_route( self::NS, '/seo/settings', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => $admin,
				),
			) );
			register_rest_route( self::NS, '/seo/settings/reset', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reset_settings' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/overview', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/crawling', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'crawling' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/redirects', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'redirects_list' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'redirects_save' ),
					'permission_callback' => $admin,
				),
			) );
			register_rest_route( self::NS, '/seo/redirects/(?P<rid>\d+)', array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'redirects_delete' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/redirects/import', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'redirects_import' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/redirects/export', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'redirects_export' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/404s', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'nf_list' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/404s/(?P<nid>\d+)/ignore', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'nf_ignore' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/schema', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'schema_list' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'schema_save' ),
					'permission_callback' => $admin,
				),
			) );
			register_rest_route( self::NS, '/seo/schema/(?P<sid>\d+)', array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'schema_delete' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/schema/auto', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'schema_auto_save' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/schema/preview', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'schema_preview' ),
				'permission_callback' => $admin,
			) );
			register_rest_route( self::NS, '/seo/rebuild', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rebuild' ),
				'permission_callback' => $admin,
			) );
		}

		// ------------------------------------------------------------------
		// Page panel
		// ------------------------------------------------------------------

		/**
		 * GET panel payload.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function get_post( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			if ( ! get_post( $id ) ) {
				return new WP_Error( 'uich_seo_no_post', __( 'That page does not exist.', 'uichemy' ), array( 'status' => 404 ) );
			}
			if ( Uich_SEO_Record::is_stale( $id ) ) {
				Uich_SEO_Record::rebuild( $id );
			}
			return rest_ensure_response( self::payload( $id ) );
		}

		/**
		 * POST field overrides.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_post( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$fields = $req->get_param( 'fields' );
			if ( ! is_array( $fields ) ) {
				return new WP_Error( 'uich_seo_bad_request', __( 'Nothing to save.', 'uichemy' ), array( 'status' => 400 ) );
			}
			$saved = Uich_SEO_Record::set_fields( $id, $fields, '', 'user' );
			if ( is_wp_error( $saved ) ) {
				$errors = array();
				foreach ( $saved->get_error_codes() as $code ) {
					$errors[ $code ] = $saved->get_error_message( $code );
				}
				return new WP_Error( 'uich_seo_invalid', __( 'Some values could not be saved.', 'uichemy' ), array( 'status' => 400, 'fields' => $errors ) );
			}
			$data = self::payload( $id );
			// The page head code as now stored, so an open builder can copy it into its
			// in-memory sections and its next save does not put the old tags back.
			$data['head_code']['code'] = Uich_SEO_Head::page_raw( $id );
			return rest_ensure_response( $data );
		}

		/**
		 * POST ignore / stop ignoring a finding on this page.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function ignore( WP_REST_Request $req ) {
			$res = Uich_SEO_MCP::ignore(
				array(
					'post_id' => (int) $req['id'],
					'check'   => (string) $req->get_param( 'check' ),
					'ignore'  => $req->get_param( 'ignore' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return rest_ensure_response( self::payload( (int) $req['id'] ) );
		}

		/**
		 * Everything the panel draws.
		 *
		 * @param int $id Post id.
		 * @return array
		 */
		public static function payload( $id ) {
			$post   = get_post( $id );
			$record = Uich_SEO_Record::get( $id );
			$r      = Uich_SEO_Resolver::for_post( $id );

			// What each field would be with no override — the grey placeholder (PRD §19.4).
			$bare                    = $record;
			$bare['derived']['head'] = array(); // As if the page head code set nothing.
			$inh            = Uich_SEO_Resolver::for_post_with_record( $id, $bare );

			$fields = array();
			foreach ( Uich_SEO_Record::FIELDS as $k ) {
				$f            = isset( $record['fields'][ $k ] ) ? $record['fields'][ $k ] : null;
				// 'page' = a tag in this page's before-</head> code; 'derived' = follows the page.
				$fields[ $k ] = array(
					'value' => $f ? $f['value'] : null,
					'state' => $f ? 'page' : 'derived',
				);
			}
			if ( class_exists( 'Uich_SEO_Redirects' ) ) {
				$to                    = Uich_SEO_Redirects::page_target( $id );
				$fields['redirect_to'] = array(
					'value' => '' !== $to ? $to : null,
					'state' => '' !== $to ? 'page' : 'derived',
				);
			}

			$inherited = array();
			foreach ( array( 'title', 'description', 'social_title', 'social_description', 'share_image', 'canonical', 'content_type', 'og_locale' ) as $k ) {
				if ( ! isset( $inh[ $k ] ) ) {
					continue;
				}
				$inherited[ $k ] = array(
					'value' => $inh[ $k ]['value'],
					'from'  => $inh[ $k ]['from'],
					'label' => Uich_SEO_MCP::from_label( $inh[ $k ]['from'] ),
					'node'  => ! empty( $inh[ $k ]['path'] ) ? array(
						'section' => isset( $inh[ $k ]['section'] ) ? $inh[ $k ]['section'] : null,
						'path'    => $inh[ $k ]['path'],
					) : null,
				);
			}

			$own      = Uich_SEO_Ownership::resolve();
			$row      = Uich_SEO_Settings::type_row( $post->post_type );
			$findings = self::locate( self::with_images( (array) $record['findings'], $record ), $id );
			$active   = array_values( array_filter( $findings, function ( $f ) {
				return empty( $f['ignored'] );
			} ) );
			$ignored  = array_values( array_filter( $findings, function ( $f ) {
				return ! empty( $f['ignored'] );
			} ) );

			$canon = (string) $r['canonical']['value'];
			$crumb = self::crumb( '' !== $canon ? $canon : (string) get_permalink( $id ) );
			$obj   = get_post_type_object( $post->post_type );

			return array(
				// For the panel's preview of {{ title }} / {{ site.name }} / {{ site.separator }}.
				'site'      => array(
					'name'      => Uich_SEO_Settings::site_name(),
					'separator' => (string) Uich_SEO_Settings::get( 'title_separator', '|' ),
					'tagline'   => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				),
				'post'      => array(
					'id'         => $id,
					'title'      => get_the_title( $id ),
					'type'       => $post->post_type,
					'type_label' => $obj ? $obj->labels->singular_name : $post->post_type,
					'status'     => $post->post_status,
					'permalink'  => get_permalink( $id ),
					'is_draft'   => ! in_array( $post->post_status, array( 'publish', 'private' ), true ),
				),
				'type_enabled' => (bool) $row['seo'],
				'ownership'    => array(
					'owner'          => $own['owner'],
					'competitor'     => $own['competitor'] ? $own['competitor']['label'] : null,
					'module_enabled' => Uich_SEO::output_enabled(),
				),
				'fields'       => $fields,
				'inherited'    => $inherited,
				'robots'       => array(
					'index'       => ! empty( $r['robots']['index'] ),
					'follow'      => ! empty( $r['robots']['follow'] ),
					'reason'      => (string) $r['robots']['reason'],
					'default'     => (bool) $row['index'],
				),
				'head_code'    => array(
					// Where these values live: the page's before-</head> code.
					'writable' => Uich_SEO_Head::page_writable( $id ),
				),
				'preview'      => array(
					'crumb'       => $crumb,
					'title'       => (string) $r['title']['value'],
					'description' => (string) $r['description']['value'],
					'social'      => array(
						'title'       => (string) $r['social_title']['value'],
						'description' => (string) $r['social_description']['value'],
						'image'       => isset( $r['share_image']['value']['url'] ) ? (string) $r['share_image']['value']['url'] : '',
						'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
						'type'        => (string) $r['content_type']['value'],
					),
					'head'        => Uich_SEO_Emitter::preview_for_post( $id ),
				),
				'schema'       => self::post_schema( $id, $record ),
				'findings'     => $active,
				'ignored'      => $ignored,
				'locales'      => self::locales(),
				'built_at'     => $record['built_at'],
			);
		}

		/**
		 * What this page publishes as structured data (PRD §13.4).
		 *
		 * @param int   $id     Post id.
		 * @param array $record Record.
		 * @return array
		 */
		private static function post_schema( $id, array $record ) {
			if ( ! class_exists( 'Uich_SEO_Schema' ) ) {
				return array( 'entities' => array(), 'errors' => array() );
			}
			$o       = Uich_SEO_Schema::for_post( $id );
			$by_type = array();
			foreach ( $o['graph'] as $e ) {
				$by_type[] = array(
					'type' => Uich_SEO_Head::type_of( $e ),
					'json' => wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				);
			}
			$page_entities = array();
			foreach ( isset( $record['fields']['schema_extra']['value'] ) ? (array) $record['fields']['schema_extra']['value'] : array() as $e ) {
				$page_entities[] = wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			$starters = array();
			foreach ( Uich_SEO_Schema::CATALOGUE as $names ) {
				foreach ( $names as $n ) {
					// Page entities hold literal values: no Twig on this level.
					$starters[ $n ] = preg_replace( '/\{\{[^}]*\}\}/', '', Uich_SEO_Schema::starter( $n ) );
				}
			}
			$errors = array();
			foreach ( $o['errors'] as $er ) {
				$er['json'] = isset( $er['entity'] ) ? wp_json_encode( $er['entity'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
				unset( $er['entity'] );
				$errors[] = $er;
			}
			return array(
				'can_edit_json'  => current_user_can( 'unfiltered_html' ),
				'page_entities'  => $page_entities,
				'starters'       => $starters,
				'enabled'        => Uich_SEO_Schema::enabled(),
				'entities'       => $by_type,
				'errors'         => $errors,
				'faq_candidates' => count( isset( $record['derived']['faq_candidates'] ) ? (array) $record['derived']['faq_candidates'] : array() ),
				// Section-level types are added in the section's JSON-LD (Schema, in the UiChemy editor).
				'section_types'  => Uich_SEO_Schema::SECTION_TYPES,
				'has_faq'        => (bool) array_filter( $by_type, function ( $e ) {
					return 'FAQPage' === $e['type'];
				} ),
				'test_url'       => 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) get_permalink( $id ) ),
			);
		}

		// ---- Crawling ----

		/**
		 * GET robots.txt preview + sitemap status.
		 *
		 * @return WP_REST_Response
		 */
		/**
		 * Will /wp-sitemap.xml resolve? With pretty permalinks the sitemap needs its
		 * rewrite rule; when the rules are stale (a common state after a migration)
		 * WordPress answers 404 even though sitemaps are "on". No HTTP request.
		 *
		 * @return bool
		 */
		private static function sitemap_reachable() {
			global $wp_rewrite;
			if ( ! $wp_rewrite || ! $wp_rewrite->using_permalinks() ) {
				return true; // Plain permalinks: served from ?sitemap=index, no rule needed.
			}
			$rules = get_option( 'rewrite_rules' );
			return is_array( $rules ) && isset( $rules['^wp-sitemap\.xml$'] );
		}

		public static function crawling() {
			$rules = (string) Uich_SEO_Settings::get( 'robots_content', '' );
			return rest_ensure_response(
				array(
					'core'          => Uich_SEO_Crawl::core_robots(),
					'robots_url'    => home_url( '/robots.txt' ),
					'blocks_all'    => Uich_SEO_Crawl::blocks_everything( $rules ),
					// A real robots.txt in the site root is served by the web server before WordPress runs: the rules above never reach crawlers.
					'physical_file' => file_exists( trailingslashit( ABSPATH ) . 'robots.txt' ),
					'sitemap_url'   => function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : '',
					'sitemaps_on'   => function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled(),
					// Enabled is not the same as reachable: stale rewrite rules make /wp-sitemap.xml a 404.
					'sitemap_reachable' => self::sitemap_reachable(),
					'excluded'      => count( Uich_SEO_Crawl::excluded_ids() ),
					'blog_public'   => (bool) get_option( 'blog_public' ),
					'llms_url'      => home_url( '/llms.txt' ),
					'md_example'    => self::md_example(),
					'owner'         => Uich_SEO_Ownership::resolve()['owner'],
				)
			);
		}

		/**
		 * An example .md URL (PRD §10.2 status line).
		 *
		 * @return string
		 */
		private static function md_example() {
			$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( ! $ids ) {
				$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			}
			return $ids ? Uich_SEO_AI::md_url( $ids[0] ) : home_url( '/about.md' );
		}

		// ---- Redirects ----

		/**
		 * GET redirects.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function redirects_list( WP_REST_Request $req ) {
			return rest_ensure_response( Uich_SEO_Redirects::query( $req->get_params() ) );
		}

		/**
		 * POST create / update (dry_run → validation only).
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function redirects_save( WP_REST_Request $req ) {
			$in = (array) $req->get_json_params();
			if ( ! empty( $in['dry_run'] ) ) {
				$v = Uich_SEO_Redirects::validate( $in );
				return rest_ensure_response( array( 'errors' => $v['errors'], 'warnings' => $v['warnings'] ) );
			}
			$origin = ! empty( $in['from_404'] ) ? '404' : 'manual';
			return rest_ensure_response( Uich_SEO_Redirects::save( $in, $origin ) );
		}

		/**
		 * DELETE.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function redirects_delete( WP_REST_Request $req ) {
			return rest_ensure_response( array( 'deleted' => Uich_SEO_Redirects::delete( (int) $req['rid'] ) ) );
		}

		/**
		 * POST CSV import { csv, dry_run }.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function redirects_import( WP_REST_Request $req ) {
			$in = (array) $req->get_json_params();
			return rest_ensure_response( Uich_SEO_Redirects::import_csv( isset( $in['csv'] ) ? (string) $in['csv'] : '', ! empty( $in['dry_run'] ) ) );
		}

		/**
		 * GET CSV export.
		 *
		 * @return WP_REST_Response
		 */
		public static function redirects_export() {
			return rest_ensure_response( array( 'csv' => Uich_SEO_Redirects::export_csv(), 'filename' => 'uichemy-redirects-' . gmdate( 'Y-m-d' ) . '.csv' ) );
		}

		/**
		 * GET 404 list.
		 *
		 * @return WP_REST_Response
		 */
		public static function nf_list() {
			return rest_ensure_response( array( 'rows' => Uich_SEO_Redirects::not_found(), 'cap' => (int) apply_filters( 'uichemy_seo_404_cap', Uich_SEO_Redirects::CAP_404 ) ) );
		}

		/**
		 * POST ignore a 404 row.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function nf_ignore( WP_REST_Request $req ) {
			Uich_SEO_Redirects::ignore_404( (int) $req['nid'] );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// ---- Schema templates ----

		/**
		 * GET templates + catalogue + automatic entities.
		 *
		 * @return WP_REST_Response
		 */
		public static function schema_list() {
			$list = array();
			foreach ( Uich_SEO_Schema::templates() as $t ) {
				$t['pages']   = Uich_SEO_Schema::count_matches( $t['conditions'] );
				$t['applies'] = self::applies_text( $t['conditions'] );
				$t['rules_labels'] = Uich_SEO_Schema::rules_labels( $t['conditions'] );
				$list[]       = $t;
			}
			$types = array();
			foreach ( Uich_SEO_Settings::content_types( 'objects' ) as $pt ) {
				if ( 'attachment' !== $pt->name ) {
					$types[] = array( 'value' => $pt->name, 'label' => $pt->labels->name );
				}
			}
			$starters = array();
			foreach ( Uich_SEO_Schema::CATALOGUE as $group => $names ) {
				foreach ( $names as $n ) {
					$starters[ $n ] = Uich_SEO_Schema::starter( $n );
				}
			}
			$sample = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'modified' ) );
			return rest_ensure_response(
				array(
					'templates'  => $list,
					'catalogue'  => Uich_SEO_Schema::CATALOGUE,
					'starters'   => $starters,
					'presets'    => Uich_SEO_Schema::presets(),
					'post_types' => $types,
					'enabled'    => Uich_SEO_Schema::enabled(),
					'auto'       => Uich_SEO_Schema::auto_list(),
					'can_edit_json' => current_user_can( 'unfiltered_html' ),
					'sample_url' => $sample ? get_permalink( $sample[0] ) : home_url( '/' ),
				)
			);
		}

		/**
		 * "All products", "Posts and 2 more pages" (PRD §8.1 Applies to).
		 *
		 * @param array $c Conditions.
		 * @return string
		 */
		private static function applies_text( array $c ) {
			$inc = array();
			$exc = array();
			foreach ( Uich_SEO_Schema::rules_labels( $c ) as $r ) {
				if ( 'exclude' === $r['match'] ) {
					$exc[] = $r['label'];
				} else {
					$inc[] = $r['label'];
				}
			}
			if ( ! $inc ) {
				return __( 'Nowhere yet', 'uichemy' );
			}
			$text = implode( ', ', $inc );
			if ( $exc ) {
				/* translators: %s: excluded places. */
				$text .= ' ' . sprintf( __( '(not %s)', 'uichemy' ), implode( ', ', $exc ) );
			}
			return $text;
		}

		/**
		 * POST one automatic item { key, off?, extra? (JSON string) }. Checks the
		 * result on a sample page first, so a change can never stop Google
		 * getting a required property.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function schema_auto_save( WP_REST_Request $req ) {
			$in  = (array) $req->get_json_params();
			$key = isset( $in['key'] ) ? sanitize_key( $in['key'] ) : '';
			if ( ! in_array( $key, Uich_SEO_Schema::AUTO, true ) ) {
				return new WP_Error( 'uich_seo_bad_key', __( 'Unknown item.', 'uichemy' ), array( 'status' => 400 ) );
			}
			$cfg = Uich_SEO_Schema::auto_cfg();
			$row = isset( $cfg[ $key ] ) ? $cfg[ $key ] : array( 'off' => false, 'extra' => array() );
			if ( array_key_exists( 'off', $in ) ) {
				$row['off'] = ! empty( $in['off'] );
			}
			if ( array_key_exists( 'extra', $in ) ) {
				$row['extra'] = (string) $in['extra'];
			}
			$errors = new WP_Error();
			$clean  = Uich_SEO_Schema::sanitize_auto( array( $key => $row ) + $cfg, $errors );
			if ( $errors->has_errors() ) {
				return new WP_Error( 'uich_seo_invalid', $errors->get_error_message(), array( 'status' => 400, 'fields' => array( 'extra' => $errors->get_error_message() ) ) );
			}
			if ( empty( $clean[ $key ]['off'] ) ) {
				foreach ( Uich_SEO_Schema::auto_list( $clean ) as $item ) {
					if ( $item['key'] === $key && $item['missing'] ) {
						/* translators: %s: property name. */
						$msg = sprintf( __( 'Google needs "%s" here. With this change it would be missing, so nothing was saved.', 'uichemy' ), $item['missing'] );
						return new WP_Error( 'uich_seo_invalid', $msg, array( 'status' => 400, 'fields' => array( 'extra' => $msg ) ) );
					}
				}
			}
			$saved = Uich_SEO_Settings::update( array( 'schema_auto' => $clean ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return rest_ensure_response( array( 'auto' => Uich_SEO_Schema::auto_list() ) );
		}

		/**
		 * POST save template { …, activate }.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function schema_save( WP_REST_Request $req ) {
			$in = (array) $req->get_json_params();
			return rest_ensure_response( Uich_SEO_Schema::save_template( $in, ! empty( $in['activate'] ) ) );
		}

		/**
		 * DELETE template.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function schema_delete( WP_REST_Request $req ) {
			$t = Uich_SEO_Schema::template( (int) $req['sid'] );
			if ( $t ) {
				wp_delete_post( (int) $req['sid'], true );
				Uich_SEO_Schema::flush();
			}
			return rest_ensure_response( array( 'deleted' => (bool) $t ) );
		}

		/**
		 * POST live preview { template, type, sample_id | sample_url }.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function schema_preview( WP_REST_Request $req ) {
			$in   = (array) $req->get_json_params();
			$post = null;
			if ( ! empty( $in['sample_id'] ) ) {
				$post = get_post( (int) $in['sample_id'] );
			} elseif ( ! empty( $in['sample_url'] ) ) {
				$post = get_post( url_to_postid( (string) $in['sample_url'] ) );
			}
			if ( ! $post ) {
				$post = Uich_SEO_Schema::sample_post( isset( $in['conditions'] ) ? (array) $in['conditions'] : array() );
			}
			if ( ! $post ) {
				return rest_ensure_response( array( 'json' => '', 'error' => __( 'Choose a sample page to preview.', 'uichemy' ), 'sample' => null, 'rules_labels' => isset( $in['conditions'] ) ? Uich_SEO_Schema::rules_labels( (array) $in['conditions'] ) : array(), 'pages' => 0 ) );
			}
			$r = Uich_SEO_Schema::render_template(
				array(
					'id'       => 0,
					'type'     => isset( $in['type'] ) ? (string) $in['type'] : '',
					'template' => isset( $in['template'] ) ? (string) $in['template'] : '',
				),
				$post
			);
			$missing = is_array( $r['entity'] ) ? Uich_SEO_Schema::validate( Uich_SEO_Schema::normalize( $r['entity'] ) ) : '';
			return rest_ensure_response(
				array(
					'json'    => is_array( $r['entity'] ) ? wp_json_encode( Uich_SEO_Schema::normalize( $r['entity'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '',
					'error'   => $r['error'],
					'missing' => $missing,
					'sample'  => array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'url' => get_permalink( $post ) ),
					'rules_labels' => isset( $in['conditions'] ) ? Uich_SEO_Schema::rules_labels( (array) $in['conditions'] ) : array(),
					'pages'   => isset( $in['conditions'] ) ? Uich_SEO_Schema::count_matches( (array) $in['conditions'] ) : 0,
				)
			);
		}

		/**
		 * Give each finding about an image (alt text, size, loading…) the image's
		 * address (`image`), found by its section and node path among the page's
		 * images, so the panel can show a
		 * thumbnail and point at the image in the editor. Read at request time, so
		 * findings stored before this existed get it too.
		 *
		 * @param array $findings Findings.
		 * @param array $record   Page record.
		 * @return array
		 */
		private static function with_images( array $findings, array $record ) {
			$by_node = array();
			foreach ( isset( $record['derived']['images'] ) ? (array) $record['derived']['images'] : array() as $img ) {
				if ( ! empty( $img['src'] ) && isset( $img['path'] ) ) {
					$by_node[ ( isset( $img['section'] ) ? $img['section'] : '' ) . '|' . $img['path'] ] = (string) $img['src'];
				}
			}
			foreach ( $findings as $i => $f ) {
				if ( ! is_array( $f ) || empty( $f['node_path'] ) ) {
					continue;
				}
				$key = ( isset( $f['section'] ) ? $f['section'] : '' ) . '|' . $f['node_path'];
				if ( isset( $by_node[ $key ] ) ) {
					$findings[ $i ]['image'] = $by_node[ $key ];
				}
			}
			return $findings;
		}

		/**
		 * Tell each finding exactly where it is fixed (`where`), so a person or an
		 * AI can go straight there:
		 *   file     'section' (a Composer section's HTML), 'head' (this page's
		 *            before-</head> code) or 'settings'
		 *   section  section uid, for 'section'
		 *   line     1-based line in that code, when one line is the place
		 *   lines    "1-57" for a finding about a whole section
		 *   code     the text on that line, trimmed
		 *   add      a tag to add, when the place is a tag that does not exist yet
		 * Lines are counted in the stored code as the Composer code editor shows it
		 * (Twig tags blanked with the same number of line breaks).
		 *
		 * @param array $findings Findings.
		 * @param int   $post_id  Post id.
		 * @return array
		 */
		private static function locate( array $findings, $post_id ) {
			if ( ! $findings ) {
				return $findings;
			}
			$raw = array();
			foreach ( Uich_SEO_Sections::get( $post_id, 'pattern' ) as $s ) {
				if ( isset( $s['uid'], $s['html'] ) && 0 === strpos( (string) ( isset( $s['source'] ) ? $s['source'] : '' ), 'composer:' ) ) {
					$raw[ (string) $s['uid'] ] = (string) $s['html'];
				}
			}
			$head  = $raw ? (string) Uich_SEO_Head::page_raw( $post_id ) : '';
			$roots = array();

			foreach ( $findings as $i => $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$uid   = isset( $f['section'] ) ? (string) $f['section'] : '';
				$where = null;

				if ( '' !== $uid && isset( $raw[ $uid ] ) ) {
					$where = array(
						'file'    => 'section',
						'section' => $uid,
					);
					$html  = $raw[ $uid ];
					$line  = 0;
					if ( ! empty( $f['node_path'] ) ) {
						if ( ! isset( $roots[ $uid ] ) ) {
							list( , $roots[ $uid ] ) = Uich_SEO_Html::fragment( self::blank_twig( $html ) );
						}
						$line = self::node_line( $roots[ $uid ], (string) $f['node_path'] );
					} elseif ( 'schema_invalid' === $f['check'] ) {
						// The n-th JSON-LD block of the section.
						$line = self::nth_line( $html, '/<script\b[^>]*application\/ld\+json[^>]*>/i', isset( $f['index'] ) ? (int) $f['index'] : 0 );
					}
					if ( $line ) {
						$where['line'] = $line;
					} else {
						$where['lines'] = '1-' . ( substr_count( $html, "\n" ) + 1 );
					}
				} elseif ( isset( $f['fix_target'] ) && 'settings' === $f['fix_target'] ) {
					$where = array( 'file' => 'settings' );
				} elseif ( $raw ) {
					$where = self::head_place( $f, $head );
					if ( ! $where ) {
						// About the page as a whole (little text, no links…): every section.
						$where = array(
							'file'     => 'section',
							'sections' => array_map(
								function ( $u ) use ( $raw ) {
									return $u . ' (lines 1-' . ( substr_count( $raw[ $u ], "\n" ) + 1 ) . ')';
								},
								array_keys( $raw )
							),
						);
					}
				}
				if ( $where ) {
					if ( ! empty( $where['line'] ) && empty( $where['code'] ) ) {
						$src           = 'head' === $where['file'] ? $head : $raw[ $where['section'] ];
						$text          = explode( "\n", $src );
						$where['code'] = isset( $text[ $where['line'] - 1 ] ) ? mb_substr( trim( $text[ $where['line'] - 1 ] ), 0, 200 ) : '';
					}
					$findings[ $i ]['where'] = $where;
					if ( ! empty( $where['line'] ) && 'section' === $where['file'] ) {
						$findings[ $i ]['line'] = $where['line'];
					}
				}
			}
			return $findings;
		}

		/**
		 * The place in this page's head code for a finding about a head tag: the
		 * line of the tag when it is there, else the tag to add. Null when the
		 * finding is not about a head tag.
		 *
		 * @param array  $f    Finding.
		 * @param string $head Page head code.
		 * @return array|null
		 */
		private static function head_place( array $f, $head ) {
			$check = (string) $f['check'];
			$tags  = array(
				'title'       => array( '/<title\b/i', '<title>Your page title</title>' ),
				'description' => array( '/<meta\b[^>]*name=["\']description["\']/i', '<meta name="description" content="One or two sentences about this page (70-160 characters).">' ),
				'image'       => array( '/<meta\b[^>]*property=["\']og:image["\']/i', '<meta property="og:image" content="https://example.com/wp-content/uploads/share.jpg">' ),
				'canonical'   => array( '/<link\b[^>]*rel=["\']canonical["\']/i', '' ),
				'ld'          => array( '/<script\b[^>]*application\/ld\+json[^>]*>/i', '' ),
			);
			switch ( $check ) {
				case 'no_title':
				case 'title_long':
				case 'title_short':
				case 'title_duplicate':
					$want = array( 'title' );
					break;
				case 'no_description':
				case 'description_length':
				case 'description_duplicate':
					$want = array( 'description' );
					break;
				case 'meta_automatic':
					$want = array( 'title', 'description' );
					break;
				case 'share_image_missing':
				case 'share_image_small':
					$want = array( 'image' );
					break;
				case 'canonical_foreign':
					$want = array( 'canonical' );
					break;
				case 'schema_invalid':
				case 'head_json_invalid':
					$want = array( 'ld' );
					break;
				default:
					return null;
			}
			$where = array( 'file' => 'head' );
			$add   = array();
			foreach ( $want as $k ) {
				$n    = ( 'ld' === $k && isset( $f['index'] ) ) ? (int) $f['index'] : 0;
				$line = self::nth_line( $head, $tags[ $k ][0], $n );
				if ( $line && empty( $where['line'] ) ) {
					$where['line'] = $line;
				} elseif ( ! $line && '' !== $tags[ $k ][1] ) {
					$add[] = $tags[ $k ][1];
				}
			}
			if ( $add ) {
				$where['add'] = implode( "\n", $add );
			}
			return $where;
		}

		/**
		 * 1-based line of the n-th match of a pattern, or 0.
		 *
		 * @param string $text    Text.
		 * @param string $pattern Regex.
		 * @param int    $n       Which match (0-based).
		 * @return int
		 */
		private static function nth_line( $text, $pattern, $n = 0 ) {
			if ( '' === (string) $text || ! preg_match_all( $pattern, (string) $text, $m, PREG_OFFSET_CAPTURE ) || ! isset( $m[0][ $n ] ) ) {
				return 0;
			}
			return substr_count( substr( (string) $text, 0, $m[0][ $n ][1] ), "\n" ) + 1;
		}

		/**
		 * Line of the element at a Composer node path ("0.2.1": 0 is the root,
		 * then the n-th counted child at each level), or 0.
		 *
		 * @param DOMElement $root Root.
		 * @param string     $path Path.
		 * @return int
		 */
		private static function node_line( $root, $path ) {
			$node = $root;
			foreach ( array_slice( explode( '.', $path ), 1 ) as $n ) {
				$kids = Uich_SEO_Html::path_children( $node );
				$node = isset( $kids[ (int) $n ] ) ? $kids[ (int) $n ] : null;
				if ( ! $node ) {
					return 0;
				}
			}
			return max( 0, (int) $node->getLineNo() );
		}

		/**
		 * Blank Twig comments and tags, keeping their line breaks, so a parse of
		 * the result reports the same line numbers as the original.
		 *
		 * @param string $html HTML.
		 * @return string
		 */
		private static function blank_twig( $html ) {
			return (string) preg_replace_callback(
				'/\{#.*?#\}|\{%-?.*?-?%\}/s',
				function ( $m ) {
					return str_repeat( "\n", substr_count( $m[0], "\n" ) );
				},
				(string) $html
			);
		}

		/**
		 * "example.com › services › cnc" (PRD §13.1).
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private static function crumb( $url ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$bits = '' === $path ? array() : explode( '/', $path );
			return implode( ' › ', array_merge( array( $host ), array_map( 'urldecode', $bits ) ) );
		}

		/**
		 * Native language names, from WordPress's own cached translation list when it
		 * has one. Never a remote request here; the result (also a miss) is cached for an hour.
		 *
		 * @return array
		 */
		private static function locale_names() {
			$cached = get_transient( 'uich_seo_locale_names' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$core  = get_site_transient( 'available_translations' );
			$names = is_array( $core ) ? $core : array();
			set_transient( 'uich_seo_locale_names', $names, HOUR_IN_SECONDS );
			return $names;
		}

		/**
		 * Installed locales for the language selects.
		 *
		 * @return array<int,array{value:string,label:string}>
		 */
		private static function locales() {
			$out = array(
				array(
					'value' => 'en_US',
					'label' => 'English (United States)',
				),
			);
			$names = self::locale_names();
			foreach ( get_available_languages() as $code ) {
				$out[] = array(
					'value' => $code,
					'label' => isset( $names[ $code ]['native_name'] ) ? $names[ $code ]['native_name'] : $code,
				);
			}
			return $out;
		}

		// ------------------------------------------------------------------
		// Site settings
		// ------------------------------------------------------------------

		/**
		 * GET settings.
		 *
		 * @return WP_REST_Response
		 */
		public static function get_settings() {
			return rest_ensure_response( self::settings_payload() );
		}

		/**
		 * POST settings.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_settings( WP_REST_Request $req ) {
			$values = $req->get_param( 'values' );
			if ( ! is_array( $values ) ) {
				return new WP_Error( 'uich_seo_bad_request', __( 'Nothing to save.', 'uichemy' ), array( 'status' => 400 ) );
			}
			$saved = Uich_SEO_Settings::update( $values );
			if ( is_wp_error( $saved ) ) {
				$errors = array();
				foreach ( $saved->get_error_codes() as $code ) {
					$errors[ $code ] = $saved->get_error_message( $code );
				}
				return new WP_Error( 'uich_seo_invalid', __( 'Some values could not be saved.', 'uichemy' ), array( 'status' => 400, 'fields' => $errors ) );
			}
			Uich_SEO_Ownership::flush();
			return rest_ensure_response( self::settings_payload() );
		}

		/**
		 * POST reset (PRD §12.2).
		 *
		 * @return WP_REST_Response
		 */
		public static function reset_settings() {
			Uich_SEO_Settings::reset();
			Uich_SEO_Ownership::flush();
			return rest_ensure_response( self::settings_payload() );
		}

		/**
		 * Settings + the content-type matrix rows + live ownership status.
		 *
		 * @return array
		 */
		private static function settings_payload() {
			$types = array();
			foreach ( Uich_SEO_Settings::content_types( 'objects' ) as $pt ) {
				if ( 'attachment' === $pt->name ) {
					continue;
				}
				$types[] = array_merge(
					array(
						'slug'  => $pt->name,
						'label' => $pt->labels->name,
					),
					Uich_SEO_Settings::type_row( $pt->name )
				);
			}
			$taxes = array();
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tx ) {
				if ( 'post_format' === $tx->name ) {
					continue;
				}
				$taxes[] = array_merge(
					array(
						'slug'  => $tx->name,
						'label' => $tx->labels->name,
					),
					Uich_SEO_Settings::taxonomy_row( $tx->name )
				);
			}

			$settings = Uich_SEO_Settings::all();
			$media    = array();
			foreach ( array( 'entity_logo' => 'entity_logo_url', 'default_share_image' => 'default_share_url' ) as $k => $url_key ) {
				$id          = (int) $settings[ $k ];
				$media[ $k ] = $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : ( isset( $settings[ $url_key ] ) ? (string) $settings[ $url_key ] : '' );
			}
			$own = Uich_SEO_Ownership::resolve();

			return array(
				'settings'   => $settings,
				'defaults'   => Uich_SEO_Settings::defaults(),
				// Keys stored as tags in the site's before-</head> code (the rest are switches).
				'head_keys'  => Uich_SEO_Head::SITE_KEYS,
				'head_code'  => Uich_SEO_Head::build( Uich_SEO_Head::site() ),
				'media'      => $media,
				'types'      => $types,
				'taxonomies' => $taxes,
				'locales'    => self::locales(),
				'site'       => array(
					'name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'tagline' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
					'locale'  => get_locale(),
					'sitemap' => get_sitemap_url( 'index' ),
				),
				'ownership'  => array(
					'owner'      => $own['owner'],
					'mode'       => $own['mode'],
					'competitor' => $own['competitor'] ? $own['competitor']['label'] : null,
					'conflict'   => $own['conflict'],
					'status'     => Uich_SEO_Ownership::status_text(),
				),
			);
		}

		// ------------------------------------------------------------------
		// Overview + rebuild
		// ------------------------------------------------------------------

		/**
		 * Published, SEO-enabled post types.
		 *
		 * @return string[]
		 */
		private static function seo_types() {
			$out = array();
			foreach ( Uich_SEO_Settings::content_types() as $pt ) {
				if ( 'attachment' === $pt ) {
					continue;
				}
				$row = Uich_SEO_Settings::type_row( $pt );
				if ( $row['seo'] ) {
					$out[] = $pt;
				}
			}
			return $out;
		}

		/**
		 * GET overview (PRD §5). Rolls up stored findings; capped so the screen
		 * stays one fast request on a large site.
		 *
		 * @return WP_REST_Response
		 */
		public static function overview() {
			// Ids only are cheap; the per-page work below is chunked and cache-primed.
			$ids = get_posts(
				array(
					'post_type'      => self::seo_types(),
					'post_status'    => 'publish',
					'posts_per_page' => self::OVERVIEW_MAX,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'modified',
				)
			);

			$with_seo = 0;
			$checks   = array();
			$titles   = array();
			$descs    = array();
			$unbuilt  = 0;
			foreach ( $ids as $i => $id ) {
				// One query per chunk for posts and meta instead of two per page.
				if ( 0 === $i % 200 ) {
					$chunk = array_slice( $ids, $i, 200 );
					_prime_post_caches( $chunk, true, true );
				}
				$record = Uich_SEO_Record::get( $id );
				if ( ! Uich_SEO_Record::is_built( $record ) ) {
					++$unbuilt;
				}
				// The roll-up never reads the redirect, so skip its two lookups per page.
				$r = Uich_SEO_Resolver::for_post( $id, array( 'skip_redirect' => true ) );
				if ( ! $r ) {
					continue;
				}
				$t = (string) $r['title']['value'];
				$d = (string) $r['description']['value'];
				if ( '' !== $t && '' !== $d ) {
					++$with_seo;
				}
				if ( '' !== $t ) {
					$titles[ strtolower( $t ) ][] = $id;
				}
				if ( '' !== $d ) {
					$descs[ strtolower( $d ) ][] = $id;
				}
				$ignored = array_flip( (array) $record['ignored_checks'] );
				foreach ( array_merge( (array) $record['findings'], (array) $record['site_findings'] ) as $f ) {
					if ( ! empty( $f['ignored'] ) ) {
						continue;
					}
					$key = $f['check'];
					if ( ! isset( $checks[ $key ] ) ) {
						$checks[ $key ] = array(
							'check'    => $key,
							'check_id' => $f['check_id'],
							'severity' => $f['severity'],
							'pages'    => array(),
							'count'    => 0,
						);
					}
					++$checks[ $key ]['count'];
					$checks[ $key ]['pages'][ $id ] = true;
				}
			}

			// Site-wide duplicate checks (PRD §18.5 #38/#39).
			foreach ( array( 'title_duplicate' => $titles, 'description_duplicate' => $descs ) as $key => $map ) {
				$pages = array();
				foreach ( $map as $group ) {
					if ( count( $group ) > 1 ) {
						foreach ( $group as $pid ) {
							$pages[ $pid ] = true;
						}
					}
				}
				if ( $pages ) {
					$checks[ $key ] = array(
						'check'    => $key,
						'check_id' => 'title_duplicate' === $key ? 38 : 39,
						'severity' => 'warning',
						'pages'    => $pages,
						'count'    => count( $pages ),
					);
				}
			}

			$rows = array();
			foreach ( $checks as $c ) {
				$pages  = array_keys( $c['pages'] );
				$rows[] = array(
					'check'    => $c['check'],
					'severity' => $c['severity'],
					'text'     => self::rollup_text( $c['check'], $c['count'], count( $pages ) ),
					'count'    => $c['count'],
					'pages'    => array_map(
						function ( $pid ) {
							return array(
								'id'    => $pid,
								'title' => get_the_title( $pid ),
								'edit'  => get_edit_post_link( $pid, 'raw' ),
								'view'  => get_permalink( $pid ),
							);
						},
						array_slice( $pages, 0, 50 )
					),
				);
			}
			$rank = array(
				'blocker' => 0,
				'warning' => 1,
				'notice'  => 2,
			);
			usort( $rows, function ( $a, $b ) use ( $rank ) {
				return ( $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ] ) ?: ( $b['count'] <=> $a['count'] );
			} );

			if ( ! Uich_SEO_Settings::site_public() ) {
				array_unshift( $rows, array(
					'check'    => 'site_hidden',
					'severity' => 'blocker',
					'text'     => Uich_SEO_Audit::site_hidden_text(),
					'count'    => 0,
					'pages'    => array(),
					'action'   => 'sitemap',
				) );
			}

			$own = Uich_SEO_Ownership::resolve();
			if ( $own['competitor'] ) {
				array_unshift( $rows, array(
					'check'    => 'competitor',
					'severity' => $own['conflict'] ? 'blocker' : 'notice',
					/* translators: %s: plugin. */
					'text'     => sprintf( __( '%s is installed, so it handles SEO and this plugin stays out of the way. Your settings here are kept and will be used if you switch.', 'uichemy' ), $own['competitor']['label'] ),
					'count'    => 0,
					'pages'    => array(),
					'action'   => 'advanced',
				) );
			}

			// Distinct pages, not page-and-finding pairs: a page with three warnings counts once.
			$attention_pages = array();
			foreach ( $checks as $c ) {
				if ( 'notice' !== $c['severity'] ) {
					$attention_pages += $c['pages'];
				}
			}
			$attention = count( $attention_pages );

			if ( ! $own['competitor'] && 'off' === $own['owner'] ) {
				$owner_label = __( 'Nobody', 'uichemy' );
			} elseif ( $own['competitor'] ) {
				$owner_label = $own['competitor']['label'];
			} elseif ( 'external' === $own['owner'] ) {
				$owner_label = __( 'Another plugin', 'uichemy' );
			} else {
				$owner_label = __( 'This plugin', 'uichemy' );
			}

			return rest_ensure_response(
				array(
					'tiles'    => array(
						'owner'     => $owner_label,
						'with_seo'  => $with_seo,
						'total'     => count( $ids ),
						'attention' => $attention,
						'redirects' => class_exists( 'Uich_SEO_Redirects' ) ? Uich_SEO_Redirects::active_count() : null,
					),
					'findings' => $rows,
					'unbuilt'  => $unbuilt,
					'capped'   => count( $ids ) >= self::OVERVIEW_MAX,
				)
			);
		}

		/**
		 * PRD §5.2 rollup copy.
		 *
		 * @param string $check Check slug.
		 * @param int    $count Occurrences.
		 * @param int    $pages Pages affected.
		 * @return string
		 */
		private static function rollup_text( $check, $count, $pages ) {
			switch ( $check ) {
				case 'no_h1':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page has no main heading (H1).', '%d pages have no main heading (H1).', $pages, 'uichemy' ), $pages );
				case 'heading_skip':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page skips a heading size, for example from H2 straight to H4.', '%d pages skip a heading size, for example from H2 straight to H4.', $pages, 'uichemy' ), $pages );
				case 'alt_missing':
					/* translators: %d: images. */
					return sprintf( _n( '%d image has no description for people who cannot see it (alt text).', '%d images have no description for people who cannot see them (alt text).', $count, 'uichemy' ), $count );
				case 'no_description':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page has no description, and none could be found on the page.', '%d pages have no description, and none could be found on the page.', $pages, 'uichemy' ), $pages );
				case 'title_duplicate':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page shares a title with another page.', '%d pages share a title with another page.', $pages, 'uichemy' ), $pages );
				case 'description_duplicate':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page shares a description with another page.', '%d pages share a description with another page.', $pages, 'uichemy' ), $pages );
				case 'schema_invalid':
					/* translators: %d: pages. */
					return sprintf( _n( '%d page has details for Google that are incomplete, so they were left out.', '%d pages have details for Google that are incomplete, so they were left out.', $pages, 'uichemy' ), $pages );
			}
			// Plain-language lines for every other check: "{n} pages …".
			$lines = array(
				'multiple_h1'              => /* translators: %d: number of pages. */ _n( '%d page has more than one main heading (H1).', '%d pages have more than one main heading (H1).', $pages, 'uichemy' ),
				'empty_h1'                 => /* translators: %d: number of pages. */ _n( '%d page has an empty main heading.', '%d pages have an empty main heading.', $pages, 'uichemy' ),
				'first_heading_not_h1'     => /* translators: %d: number of pages. */ _n( '%d page does not start with a main heading (H1).', '%d pages do not start with a main heading (H1).', $pages, 'uichemy' ),
				'pseudo_heading'           => /* translators: %d: number of pages. */ _n( '%d page has text that looks like a heading but is not set as one.', '%d pages have text that looks like a heading but is not set as one.', $pages, 'uichemy' ),
				'no_main'                  => /* translators: %d: number of pages. */ _n( '%d page does not mark where its main content is.', '%d pages do not mark where their main content is.', $pages, 'uichemy' ),
				'thin_content'             => /* translators: %d: number of pages. */ _n( '%d page has very little text.', '%d pages have very little text.', $pages, 'uichemy' ),
				'alt_empty'                => /* translators: %d: number of pages. */ _n( '%d page has images with an empty description.', '%d pages have images with an empty description.', $pages, 'uichemy' ),
				'alt_filename'             => /* translators: %d: number of pages. */ _n( '%d page has images described only by a file name.', '%d pages have images described only by a file name.', $pages, 'uichemy' ),
				'alt_long'                 => /* translators: %d: number of pages. */ _n( '%d page has image descriptions that are too long.', '%d pages have image descriptions that are too long.', $pages, 'uichemy' ),
				'img_no_dimensions'        => /* translators: %d: number of pages. */ _n( '%d page has images without a width and height, so the page jumps while loading.', '%d pages have images without a width and height, so the page jumps while loading.', $pages, 'uichemy' ),
				'img_no_loading'           => /* translators: %d: number of pages. */ _n( '%d page has images that could load later to make the page faster.', '%d pages have images that could load later to make the page faster.', $pages, 'uichemy' ),
				'img_external'             => /* translators: %d: number of pages. */ _n( '%d page uses images from other websites.', '%d pages use images from other websites.', $pages, 'uichemy' ),
				'blank_no_noopener'        => /* translators: %d: number of pages. */ _n( '%d page has links that open a new tab without a safety setting.', '%d pages have links that open a new tab without a safety setting.', $pages, 'uichemy' ),
				'link_no_text'             => /* translators: %d: number of pages. */ _n( '%d page has links with no text.', '%d pages have links with no text.', $pages, 'uichemy' ),
				'link_generic_text'        => /* translators: %d: number of pages. */ _n( '%d page has links like "click here" that do not say where they go.', '%d pages have links like "click here" that do not say where they go.', $pages, 'uichemy' ),
				'no_internal_links'        => /* translators: %d: number of pages. */ _n( '%d page does not link to any other page on your site.', '%d pages do not link to any other page on your site.', $pages, 'uichemy' ),
				'internal_nofollow'        => /* translators: %d: number of pages. */ _n( '%d page tells search engines not to follow links to your own pages.', '%d pages tell search engines not to follow links to your own pages.', $pages, 'uichemy' ),
				'iframe_no_title'          => /* translators: %d: number of pages. */ _n( '%d page has embedded items (like videos or maps) without a title.', '%d pages have embedded items (like videos or maps) without a title.', $pages, 'uichemy' ),
				'time_no_datetime'         => /* translators: %d: number of pages. */ _n( '%d page has dates that search engines cannot read.', '%d pages have dates that search engines cannot read.', $pages, 'uichemy' ),
				'table_no_header'          => /* translators: %d: number of pages. */ _n( '%d page has tables without a header row.', '%d pages have tables without a header row.', $pages, 'uichemy' ),
				'nav_unlabelled'           => /* translators: %d: number of pages. */ _n( '%d page has menus without names.', '%d pages have menus without names.', $pages, 'uichemy' ),
				'faq_not_published'        => /* translators: %d: number of pages. */ _n( '%d page has questions and answers that could show as an FAQ in Google.', '%d pages have questions and answers that could show as an FAQ in Google.', $pages, 'uichemy' ),
				'breadcrumb_not_published' => /* translators: %d: number of pages. */ _n( '%d page has a "Home > Section > Page" trail that Google is not told about.', '%d pages have a "Home > Section > Page" trail that Google is not told about.', $pages, 'uichemy' ),
				'product_no_price'         => /* translators: %d: number of pages. */ _n( '%d page shows a product without a price.', '%d pages show a product without a price.', $pages, 'uichemy' ),
				'no_title'                 => /* translators: %d: number of pages. */ _n( '%d page has no title.', '%d pages have no title.', $pages, 'uichemy' ),
				'title_long'               => /* translators: %d: number of pages. */ _n( '%d page has a title that Google will cut short.', '%d pages have a title that Google will cut short.', $pages, 'uichemy' ),
				'title_short'              => /* translators: %d: number of pages. */ _n( '%d page has a very short title.', '%d pages have a very short title.', $pages, 'uichemy' ),
				'description_length'       => /* translators: %d: number of pages. */ _n( '%d page has a description that is too short or too long.', '%d pages have a description that is too short or too long.', $pages, 'uichemy' ),
				'canonical_foreign'        => /* translators: %d: number of pages. */ _n( '%d page says it belongs to another website (preferred address).', '%d pages say they belong to another website (preferred address).', $pages, 'uichemy' ),
				'no_lang'                  => /* translators: %d: number of pages. */ _n( '%d page does not say which language it is in.', '%d pages do not say which language they are in.', $pages, 'uichemy' ),
				'img_large_file'           => /* translators: %d: number of pages. */ _n( '%d page has very large image files that slow it down.', '%d pages have very large image files that slow them down.', $pages, 'uichemy' ),
				'link_broken'              => /* translators: %d: number of pages. */ _n( '%d page links to pages that do not exist.', '%d pages link to pages that do not exist.', $pages, 'uichemy' ),
				'link_redirects'           => /* translators: %d: number of pages. */ _n( '%d page links to old addresses that now redirect.', '%d pages link to old addresses that now redirect.', $pages, 'uichemy' ),
				'noindex_in_sitemap'       => /* translators: %d: number of pages. */ _n( '%d page is hidden from search but still listed in the sitemap.', '%d pages are hidden from search but still listed in the sitemap.', $pages, 'uichemy' ),
				'ai_excluded_in_llms'      => /* translators: %d: number of pages. */ _n( '%d page is hidden from AI tools but still listed in the AI file.', '%d pages are hidden from AI tools but still listed in the AI file.', $pages, 'uichemy' ),
				'share_image_small'        => /* translators: %d: number of pages. */ _n( '%d page has a sharing image that is too small.', '%d pages have a sharing image that is too small.', $pages, 'uichemy' ),
				'share_image_missing'      => /* translators: %d: number of pages. */ _n( '%d page has no sharing image.', '%d pages have no sharing image.', $pages, 'uichemy' ),
				'meta_automatic'           => /* translators: %d: number of pages. */ _n( '%d page uses a title or description filled in automatically.', '%d pages use a title or description filled in automatically.', $pages, 'uichemy' ),
			);
			if ( isset( $lines[ $check ] ) ) {
				return sprintf( $lines[ $check ], $pages );
			}
			/* translators: %d: pages. */
			return sprintf( _n( '%d page needs attention.', '%d pages need attention.', $pages, 'uichemy' ), $pages );
		}

		/**
		 * POST one batch of the site-wide rebuild (PRD §12.2). Idempotent; the
		 * screen calls it again with the returned offset until done.
		 *
		 * @param WP_REST_Request $req Request.
		 * @return WP_REST_Response
		 */
		public static function rebuild( WP_REST_Request $req ) {
			$offset = max( 0, (int) $req->get_param( 'offset' ) );
			// Rate limit (02 §13): one batch at a time per site.
			if ( get_transient( 'uich_seo_rebuild_busy' ) ) {
				return new WP_Error( 'uich_seo_busy', __( 'A refresh is already running. Wait a moment and try again.', 'uichemy' ), array( 'status' => 429 ) );
			}
			set_transient( 'uich_seo_rebuild_busy', 1, 60 );
			// Bounded work per request: stop after ~8 s and hand back the offset to continue from.
			$deadline = microtime( true ) + 8;
			$partial  = false;
			$handled  = 0;
			$types  = self::seo_types();
			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				$types[] = UiChemy_Template_CPT::POST_TYPE; // Pattern records.
			}
			$q      = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
					'posts_per_page' => self::BATCH,
					'offset'         => $offset,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $q->posts as $id ) {
				if ( $handled && microtime( true ) > $deadline ) {
					$partial = true;
					break;
				}
				Uich_SEO_Record::rebuild( (int) $id );
				if ( 'publish' === get_post_status( $id ) && class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE !== get_post_type( $id ) ) {
					// false = out of time mid-page: leave it for the next call (statuses are cached).
					if ( false === Uich_SEO_Audit::site_checks( (int) $id, true, $deadline ) ) {
						$partial = true;
						if ( $handled ) {
							break;
						}
					}
				}
				++$handled;
			}
			delete_transient( 'uich_seo_rebuild_busy' );
			$done = $offset + $handled;
			return rest_ensure_response(
				array(
					'done'   => $done,
					'total'  => (int) $q->found_posts,
					'next'   => $done < (int) $q->found_posts ? $done : null,
					'partial' => $partial,
				)
			);
		}
	}
}
