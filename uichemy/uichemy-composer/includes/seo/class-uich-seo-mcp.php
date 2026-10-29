<?php
/**
 * MCP — the `uichemy-composer/seo` ability (02 §12).
 *
 * Page SEO lives as tags in head code, so this ability never writes a page's
 * title or meta: an agent edits those tags with uichemy-composer/page
 * (set-page-code / update-page-code) or, site-wide, uichemy-composer/platform
 * (update-site-code). This ability reads and audits what those tags produce,
 * and owns what has no tag: site switches, schema templates, redirects,
 * crawling and permalinks.
 *
 * Zero Composer edits: Composer's registrar skips a slug that is already
 * registered (`wp_has_ability`), so registering the same slug one priority
 * earlier hands it to this module.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_MCP' ) ) {

	/**
	 * SEO ability.
	 */
	class Uich_SEO_MCP {

		const SLUG = 'uichemy-composer/seo';

		/** Permalink structures offered (postname is the SEO recommendation). */
		const PERMALINKS = array(
			'postname'  => '/%postname%/',
			'category'  => '/%category%/%postname%/',
			'day'       => '/%year%/%monthnum%/%day%/%postname%/',
			'month'     => '/%year%/%monthnum%/%postname%/',
			'numeric'   => '/archives/%post_id%',
			'plain'     => '',
		);

		/** How to change a page's SEO: shown wherever an agent needs it. */
		const HOW_TO_EDIT = 'SEO values are real tags in head code, so edit them there. This page: uichemy-composer/page update-page-code (or set-page-code, which replaces the whole block) with page_before_head holding the tags — <title>, <meta name="description">, <link rel="canonical">, <meta name="robots" content="noindex">, <meta property="og:title|og:description|og:image|og:type|og:locale">, <meta name="twitter:*">, <script type="application/ld+json">. Site-wide fallbacks: uichemy-composer/platform update-site-code, or update-site-settings here. A tag on the page wins over the page content, which wins over the site tag. The site <title> is the title template: {{ title }} marks where the page title goes. Remove a page tag to let that value follow the page content again.';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 4 );
		}

		/**
		 * Actions and their parameter schemas.
		 *
		 * @return array
		 */
		public static function actions() {
			$post_id   = array( 'type' => 'integer', 'description' => 'Post, page or product ID.' );
			$tpl_props = array(
				'name'       => array( 'type' => 'string', 'description' => 'A name people will recognise, e.g. "Recipes".' ),
				'type'       => array( 'type' => 'string', 'description' => 'schema.org type of the WHOLE post: ' . implode( ', ', call_user_func_array( 'array_merge', array_values( Uich_SEO_Schema::CATALOGUE ) ) ) . '. Section-level types (' . implode( ', ', Uich_SEO_Schema::SECTION_TYPES ) . ') are refused: put them in JSON-LD next to that content - the section\'s JSON-LD, or on a Composer Builder page a <script type="application/ld+json"> in its head_code.' ),
				'conditions' => array( 'type' => 'object', 'description' => 'Where it applies, the theme builder\'s rule shape: {rules: [{match: "include"|"exclude", type: "entire"|"all_singulars"|"front_page"|"blog"|"post_type"|"taxonomy"|"singular"|"author", value?, taxonomy?, term?}]}. E.g. {rules: [{match: "include", type: "post_type", value: "recipe"}]}.' ),
				'template'   => array( 'type' => 'string', 'description' => 'JSON with Twig values from each post: {{ post.title }}, {{ post.url }}, {{ post.excerpt }}, {{ post.thumbnail.src }}, {{ post.meta(\'<key>\') }} (dot syntax post.meta.key renders empty), {{ post.date(\'c\') }} / {{ post.modified(\'c\') }} for ISO dates (plain {{ post.date }} is not ISO), {{ site.title }}, {{ site.url }}.' ),
				'active'     => array( 'type' => 'boolean', 'description' => 'Publish it now. Google\'s required properties are checked first.' ),
			);
			$dry_run   = array( 'type' => 'boolean', 'description' => 'true previews what would happen and writes nothing.' );
			$redirect  = array(
				'source'   => array( 'type' => 'string', 'description' => 'The old address, as a path: /old-page. With is_regex, a pattern: ^/blog/(.*)$' ),
				'target'   => array( 'type' => 'string', 'description' => 'Where to send people: /new-page or a full https:// link. Empty for 410 / 451. With is_regex, $1… refer to the pattern\'s groups.' ),
				'type'     => array( 'type' => 'integer', 'enum' => array( 301, 302, 307, 410, 451 ), 'description' => '301 moved for good (default; passes ranking), 302/307 temporary, 410 gone for good, 451 removed for legal reasons.' ),
				'is_regex' => array( 'type' => 'boolean' ),
				'status'   => array( 'type' => 'boolean', 'description' => 'false switches it off without deleting it.' ),
			);

			return array(
				// ---------- Page ----------
				array(
					'name'                     => 'audit-page-seo',
					'description'              => 'Start here for one page. Every SEO value the page publishes, where each comes from (this page\'s head code, its template\'s head code, the page content, the site head code, or WordPress), the exact tags printed, the SEO tags currently in its head code, and findings that each name the call that fixes them. Read-only.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array( 'post_id' => $post_id ),
						'required'   => array( 'post_id' ),
					),
				),
				array(
					'name'                     => 'set-image-alt',
					'description'              => 'Write image alt text into the page markup (and optionally the media library), or mark an image decorative (alt=""). Never guess: describe what the image shows, or mark it decorative. Take section + path from audit-page-seo findings.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'      => $post_id,
							'images'       => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'section'    => array( 'type' => 'string', 'description' => 'The "section" from the finding. Optional on a Composer Builder page, which is one document.' ),
										'path'       => array( 'type' => 'string', 'description' => 'Node path from the finding, e.g. 0.1.3.' ),
										'alt'        => array( 'type' => 'string', 'description' => 'What the image shows. Omit when decorative.' ),
										'decorative' => array( 'type' => 'boolean' ),
									),
									'required'   => array( 'path' ),
								),
							),
							'update_media' => array( 'type' => 'boolean', 'description' => 'Also save the alt text on the media library item. Default false.' ),
						),
						'required'   => array( 'post_id', 'images' ),
					),
				),
				array(
					'name'                     => 'preview-page-schema',
					'description'              => 'The structured data (JSON-LD @graph) one page publishes, with where each entity comes from (automatic, markup, site head code, page head code, a schema template) and any entity left out because Google\'s required properties are missing. JSON-LD inside a section prints there and is not part of this graph; on a Composer Builder page, JSON-LD in head_code is. Read-only.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array( 'post_id' => $post_id ),
						'required'   => array( 'post_id' ),
					),
				),

				// ---------- Site ----------
				array(
					'name'                     => 'list-site-settings',
					'description'              => 'Every site SEO setting, grouped, and where it lives: "head_code" keys are tags in the site\'s before-</head> code (title template, social fallbacks, verification codes, the site Organization/Person); the rest are switches with no tag (content types, sitemap, robots.txt, AI formats, schema, redirects). Read-only.',
					'action_parameters_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				),
				array(
					'name'                     => 'update-site-settings',
					'description'              => 'Change some site SEO settings; only the keys you pass change. Head-code keys rewrite their tag in the site head code. Keys — titles: site_title and site_tagline (WordPress\'s Site Title and Tagline, Settings → General: {{ site.title }} / {{ site.tagline }} in page titles and the WebSite schema), title_template (Twig: "{{ title }} {{ site.separator }} {{ site.title }}"), title_separator, home_description. Social fallbacks: default_share_image (attachment id or https link; the og:image of any page with none), default_social_title (og:title template for link previews of pages without their own og:title; {{ title }} = the page title, e.g. "{{ title }} — Brand"; never used in search), default_social_description (og:description for link previews of pages whose own text gives none; never used in search), site_name_override (the name on link previews, og:site_name, only when it should differ from the site title; never used in page titles), og_locale, twitter_handle, twitter_card (large|small), og_enabled, twitter_enabled. Identity: entity_type (organization|person), entity_name, entity_logo, entity_description, entity_email, entity_phone, social_profiles (https links). Verification: verify_google|bing|yandex|baidu|pinterest. Archives: noindex_author, noindex_date, noindex_search, noindex_paginated, redirect_attachments. Content types: types / taxonomies ({slug: {seo, index, sitemap, llms, markdown, page_type}}). AEO: llms_enabled, llms_limit, llms_intro, llms_extra, md_enabled, md_template. Schema: schema_enabled, schema_auto. Crawling: blog_public (WordPress \'Search engine visibility\': false = discourage search engines, which noindexes every page and drops the sitemap; set true when the site goes live, and ask the user first), robots_mode (wordpress|custom), robots_content, sitemap_sync. Redirects: redirects_enabled, auto_redirect, record_404. Advanced: head_owner (auto|uichemy|external|off), module_enabled.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array( 'values' => array( 'type' => 'object' ) ),
						'required'   => array( 'values' ),
					),
				),
				array(
					'name'                     => 'audit-site-seo',
					'description'              => 'The site-wide SEO inbox: problems across every published page, worst first (duplicate titles and descriptions, noindex pages still in the sitemap, broken links, missing titles…), each with the pages it affects, plus counts by severity. Work it top to bottom with audit-page-seo on each page. Read-only.',
					'action_parameters_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				),
				array(
					'name'                     => 'get-crawl-status',
					'description'              => 'How search engines can crawl the site: robots.txt (and whether it blocks everything), whether search engines are discouraged in WordPress, the sitemap URL and whether it actually resolves, how many pages are kept out of it, llms.txt, and the permalink structure with an SEO recommendation. Read-only.',
					'action_parameters_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				),
				array(
					'name'                     => 'update-permalinks',
					'description'              => 'Change WordPress\'s permalink structure. SITE-WIDE and hard to undo: it rewrites the URL of every post, page and archive, flushes the rewrite rules, and breaks every external link, bookmark and sitemap entry that WordPress does not redirect. "postname" (/%postname%/) is the SEO recommendation: short, readable, keyword-bearing URLs. WordPress sends most old addresses to the new ones, but check list-404s afterwards and add redirects for anything left. Ask the user before changing it on a live site. Pass dry_run: true first to preview before/after and how many published items move, writing nothing.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'structure' => array( 'type' => 'string', 'description' => 'One of ' . implode( ', ', array_keys( self::PERMALINKS ) ) . ', or a custom structure containing %postname%.' ),
							'dry_run'   => $dry_run,
						),
						'required'   => array( 'structure' ),
					),
				),

				// ---------- Schema templates ----------
				array(
					'name'                     => 'list-schema-templates',
					'description'              => 'Schema templates: one JSON-LD pattern filled from each matching post\'s fields (e.g. Recipe for every recipe post). Includes ready-made presets to start from. Read-only.',
					'action_parameters_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				),
				array(
					'name'                     => 'add-schema-template',
					'description'              => 'Create a schema template for a type that describes a whole post. Not for site identity (Site Settings) or section-level types like FAQPage / HowTo / Review (those go in JSON-LD next to that content - the section\'s JSON-LD, or a Composer Builder page\'s head_code).',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => $tpl_props,
						'required'   => array( 'type', 'conditions', 'template' ),
					),
				),
				array(
					'name'                     => 'update-schema-template',
					'description'              => 'Change a schema template; pass the fields to change plus its id.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $tpl_props ),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'                     => 'delete-schema-template',
					'description'              => 'Delete a schema template for good (permanent, no trash). Pass dry_run: true to preview what would be deleted.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => array( 'type' => 'integer' ), 'dry_run' => $dry_run ),
						'required'   => array( 'id' ),
					),
				),

				// ---------- Redirects ----------
				array(
					'name'                     => 'list-redirects',
					'description'              => 'Redirects, newest first, with hits and origin (manual, slug-change, trash, page, agent, import, 404). Read-only.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'search'   => array( 'type' => 'string' ),
							'origin'   => array( 'type' => 'string' ),
							'page'     => array( 'type' => 'integer' ),
							'per_page' => array( 'type' => 'integer', 'maximum' => 200 ),
						),
					),
				),
				array(
					'name'                     => 'add-redirect',
					'description'              => 'Send an old address somewhere else. Use it when a page is replaced, renamed or removed, and for every real address in list-404s, so visitors and rankings are not lost.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => $redirect,
						'required'   => array( 'source' ),
					),
				),
				array(
					'name'                     => 'update-redirect',
					'description'              => 'Change a redirect by id; pass the fields to change.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $redirect ),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'                     => 'delete-redirect',
					'description'              => 'Delete a redirect for good (permanent). Pass dry_run: true to preview what would be deleted.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => array( 'type' => 'integer' ), 'dry_run' => $dry_run ),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'                     => 'list-404s',
					'description'              => 'Missing pages (404s) visitors and crawlers hit, most frequent first, with referrers. Redirect each real one with add-redirect. Read-only.',
					'action_parameters_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				),
			);
		}

		/**
		 * Register the ability.
		 *
		 * @return void
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'UiChemy_Abilities' ) ) {
				return;
			}
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::SLUG ) ) {
				return;
			}

			$actions  = self::actions();
			$names    = wp_list_pluck( $actions, 'name' );
			$preamble = method_exists( 'UiChemy_Abilities', 'contract' ) ? UiChemy_Abilities::contract() : '';

			// The text below names the section-mode page calls (update-page-code,
			// page_before_head). In Composer Builder mode those do not exist, so
			// rewrite them to the document actions, as every other ability does.
			$schema_description = $preamble . ' Read the improve-seo skill (uichemy-composer/read-skill) before an SEO pass. ' . self::HOW_TO_EDIT . ' Fix markup problems (alt text, heading order, link rel) in the page markup with uichemy-composer/page. When another SEO plugin owns the head, UiChemy prints no SEO tags; the tags stay in the code and come back if it is removed. No keyword scoring and no sitemap of its own: WordPress serves /wp-sitemap.xml and UiChemy keeps it in step.';
			if ( class_exists( 'UiChemy_Builder_Context' ) ) {
				$actions            = UiChemy_Builder_Context::for_mode_deep( $actions );
				$schema_description = UiChemy_Builder_Context::for_mode( $schema_description );
			}

			wp_register_ability(
				self::SLUG,
				array(
					'label'               => 'UiChemy Builder: SEO',
					'description'         => 'SEO for pages and the site: audit a page or the whole site, see where every value comes from, and manage site settings, schema templates, redirects, crawling and permalinks. Page titles and meta are tags in head code, edited with uichemy-composer/page.',
					'category'            => UiChemy_Abilities::CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'            => array(
								'type' => 'string',
								'enum' => array_values( $names ),
							),
							'action_parameters' => array( 'type' => 'object' ),
						),
						'required'   => array( 'action' ),
					),
					'execute_callback'    => array( __CLASS__, 'execute' ),
					'permission_callback' => array( 'UiChemy_Abilities', 'permission_check' ),
					'meta'                => array(
						'show_in_rest'       => true,
						'mcp'                => array( 'public' => true ),
						'annotations'        => array(
							'title'       => 'UiChemy Builder: SEO',
							// Mixed read + write ability that can delete and change every URL: report the worst case.
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => false,
						),
						'actions'            => $actions,
						'schema_description' => $schema_description,
					),
				)
			);
		}

		/**
		 * Execute.
		 *
		 * @param array $input { action, action_parameters }.
		 * @return array|WP_Error
		 */
		public static function execute( $input ) {
			$input  = is_array( $input ) ? $input : array();
			$action = isset( $input['action'] ) ? strtolower( (string) $input['action'] ) : '';
			$params = isset( $input['action_parameters'] ) && is_array( $input['action_parameters'] ) ? $input['action_parameters'] : array();
			// Flat keys are accepted too, like the Composer router.
			foreach ( $input as $k => $v ) {
				if ( ! in_array( $k, array( 'action', 'action_parameters' ), true ) && ! array_key_exists( $k, $params ) ) {
					$params[ $k ] = $v;
				}
			}

			$result = self::readable( self::route( str_replace( '_', '-', $action ), $action, $params ) );
			// Fix hints name section-mode calls; in Composer Builder mode - or for
			// a post that is itself a Composer Builder document - they are the
			// document actions instead.
			$cb_post = ! empty( $params['post_id'] ) && Uich_SEO_Head::is_cb_document( (int) $params['post_id'] );
			return ( ! is_wp_error( $result ) && class_exists( 'UiChemy_Builder_Context' ) )
				? UiChemy_Builder_Context::for_mode_deep( $result, $cb_post )
				: $result;
		}

		/**
		 * Fold per-field messages into a WP_Error's message, so an agent sees why.
		 *
		 * @param mixed $res Result.
		 * @return mixed
		 */
		private static function readable( $res ) {
			if ( ! is_wp_error( $res ) ) {
				return $res;
			}
			$data = $res->get_error_data();
			if ( is_array( $data ) && ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
				$parts = array();
				foreach ( $data['fields'] as $field => $msg ) {
					$parts[] = $field . ': ' . ( is_array( $msg ) ? implode( ' ', $msg ) : $msg );
				}
				return new WP_Error( $res->get_error_code(), $res->get_error_message() . ' ' . implode( ' ', $parts ), $data );
			}
			if ( count( $res->get_error_codes() ) > 1 ) {
				$parts = array();
				foreach ( $res->get_error_codes() as $code ) {
					$parts[] = $code . ': ' . $res->get_error_message( $code );
				}
				return new WP_Error( 'uich_seo_invalid', implode( ' ', $parts ) );
			}
			return $res;
		}

		/**
		 * Dispatch one action.
		 *
		 * @param string $name   Normalised action name.
		 * @param string $action As given.
		 * @param array  $params Params.
		 * @return array|WP_Error
		 */
		private static function route( $name, $action, array $params ) {
			switch ( $name ) {
				case 'audit-page-seo':
					return self::audit_page( $params );
				case 'set-image-alt':
					return self::fix_alt( $params );
				case 'preview-page-schema':
					return self::preview_schema( $params );
				case 'list-site-settings':
					return self::list_settings();
				case 'update-site-settings':
					return self::update_settings( $params );
				case 'audit-site-seo':
					return self::audit_site();
				case 'get-crawl-status':
					return self::crawl_status();
				case 'update-permalinks':
					return self::update_permalinks( $params );
				case 'list-schema-templates':
					return self::list_templates();
				case 'add-schema-template':
					unset( $params['id'] );
					return self::save_template( $params );
				case 'update-schema-template':
					return self::save_template( $params, true );
				case 'delete-schema-template':
					return self::delete_template( $params );
				case 'list-redirects':
					return Uich_SEO_Redirects::query( $params );
				case 'add-redirect':
					unset( $params['id'] );
					return self::save_redirect( $params );
				case 'update-redirect':
					return self::save_redirect( $params, true );
				case 'delete-redirect':
					return self::delete_redirect( $params );
				case 'list-404s':
					return array(
						'rows' => Uich_SEO_Redirects::not_found(),
						'hint' => 'Redirect each real address with add-redirect {source: "<url path>", target: "/the-best-replacement"}. Ignore bot noise (wp-login probes, .env, random strings).',
					);
			}
			return new WP_Error( 'uich_seo_bad_action', 'Unknown action "' . $action . '". Valid: ' . implode( ', ', wp_list_pluck( self::actions(), 'name' ) ) . '.' );
		}

		/**
		 * Validate post_id.
		 *
		 * @param array $p Params.
		 * @return WP_Post|WP_Error
		 */
		private static function post( array $p ) {
			$id   = isset( $p['post_id'] ) ? absint( $p['post_id'] ) : 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post ) {
				return new WP_Error( 'uich_seo_no_post', 'post_id is required and must be an existing post.', array( 'status' => 400 ) );
			}
			return $post;
		}

		/**
		 * Map a provenance token to plain words (PRD §13.1).
		 *
		 * @param string $from Token.
		 * @return string
		 */
		public static function from_label( $from ) {
			$map = array(
				'page'            => 'This page\'s head code',
				'template-code'   => 'The template\'s head code',
				'site'            => 'The site head code',
				'site-social'     => 'The site head code (sharing fallback)',
				'h1'              => 'From the main heading',
				'first-paragraph' => 'From the first paragraph',
				'template'        => 'From the single post template',
				'excerpt'         => 'From the excerpt',
				'featured'        => 'From the featured image',
				'first-image'     => 'From the first image on the page',
				'post-title'      => 'From the page title',
				'permalink'       => 'The page address',
				'title'           => 'Same as the search title',
				'description'     => 'Same as the search description',
				'markup'          => 'From the page content',
				'content-type'    => 'From the content type setting',
				'wordpress'       => 'From the WordPress language',
				'front-page'      => 'Front page',
				'content'         => 'From the page text',
			);
			return isset( $map[ $from ] ) ? $map[ $from ] : (string) $from;
		}

		/**
		 * Where a provenance token lives, for an agent deciding what to edit.
		 *
		 * @param string $from Token.
		 * @return string page_head | template_head | site_head | content | wordpress
		 */
		private static function lives_in( $from ) {
			if ( 'page' === $from ) {
				return 'page_head';
			}
			if ( 'template-code' === $from ) {
				return 'template_head';
			}
			if ( 'site' === $from || 'site-social' === $from ) {
				return 'site_head';
			}
			if ( in_array( $from, array( 'h1', 'first-paragraph', 'template', 'excerpt', 'featured', 'first-image', 'markup', 'content', 'title', 'description' ), true ) ) {
				return 'content';
			}
			return 'wordpress';
		}

		/**
		 * audit-page-seo.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function audit_page( array $p ) {
			$post = self::post( $p );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( Uich_SEO_Record::is_stale( $post->ID ) ) {
				Uich_SEO_Record::rebuild( $post->ID );
			}
			$record  = Uich_SEO_Record::get( $post->ID );
			$r       = Uich_SEO_Resolver::for_post( $post->ID );
			$preview = Uich_SEO_Emitter::preview_for_post( $post->ID );
			$own     = Uich_SEO_Ownership::resolve();
			$finds   = Uich_SEO_Audit::stored( $post->ID );

			$values = array();
			foreach ( array( 'title', 'description', 'social_title', 'social_description', 'share_image', 'canonical', 'content_type', 'og_locale' ) as $k ) {
				if ( ! isset( $r[ $k ] ) ) {
					continue;
				}
				$row = array(
					'value'    => $r[ $k ]['value'],
					'from'     => self::from_label( $r[ $k ]['from'] ),
					'lives_in' => self::lives_in( (string) $r[ $k ]['from'] ),
				);
				if ( ! empty( $r[ $k ]['path'] ) ) {
					$row['node'] = array(
						'section' => isset( $r[ $k ]['section'] ) ? $r[ $k ]['section'] : null,
						'path'    => $r[ $k ]['path'],
					);
				}
				$values[ $k ] = $row;
			}
			$values['robots'] = array(
				'value'    => Uich_SEO_Emitter::robots_line( $r['robots'] ),
				'lives_in' => 'site-hidden' === $r['robots']['reason'] ? 'wordpress_reading_setting' : ( ( isset( $record['fields']['index'] ) || isset( $record['fields']['follow'] ) ) ? 'page_head' : ( 'site' === $r['robots']['reason'] ? 'site_head' : 'settings' ) ),
				'reason'   => (string) $r['robots']['reason'],
			);
			$redirect = (string) $r['redirect_to'];

			$page_tags = Uich_SEO_Head::build( isset( $record['derived']['head'] ) ? (array) $record['derived']['head'] : array() );
			$writable  = Uich_SEO_Head::has_sections( $post->ID );
			$d         = $record['derived'];

			if ( ! $writable ) {
				$start = 'This page is not built with UiChemy, so it has no page head code yet. Its values are worked out from its content and the site head code. To set its own SEO tags, build it with UiChemy, then use uichemy-composer/page update-page-code.';
			} elseif ( '' === $page_tags ) {
				$start = 'This page has no SEO tags of its own yet: every value below is worked out from its content or the site head code. To get started, add the tags this page needs (at least a <title> and <meta name="description"> written for search) to its before-</head> code with uichemy-composer/page update-page-code, and set the site-wide fallbacks (title template, default og:image) once with update-site-settings.';
			} else {
				$start = 'Edit or remove the tags in "page_head_tags" with uichemy-composer/page update-page-code / set-page-code to change these values.';
			}

			return array(
				'post_id'        => $post->ID,
				'url'            => get_permalink( $post->ID ),
				'get_started'    => $start,
				'search_engines' => Uich_SEO_Settings::site_public()
					? 'allowed'
					: 'DISCOURAGED: WordPress asks search engines not to index this site (Settings → Reading), so no page can appear in search. Ask the user whether the site is live; if so, update-site-settings {values: {blog_public: true}}.',
				'how_to_edit'    => self::HOW_TO_EDIT,
				'ownership'      => array(
					'owner'      => $own['owner'],
					'competitor' => $own['competitor'] ? $own['competitor']['label'] : null,
					'status'     => Uich_SEO_Ownership::status_text(),
				),
				'values'         => $values,
				'redirect'       => '' !== $redirect ? array(
					'to'   => $redirect,
					'note' => 'This address is redirected (Redirects table), so visitors never see this page.',
				) : null,
				'page_head_tags' => $page_tags,
				'site_head_tags' => Uich_SEO_Head::build( Uich_SEO_Head::site() ),
				'template'       => ! empty( $r['template_id'] ) ? array(
					'id'    => (int) $r['template_id'],
					'title' => get_the_title( $r['template_id'] ),
				) : null,
				'page'           => array(
					'word_count' => isset( $d['word_count'] ) ? (int) $d['word_count'] : 0,
					'headings'   => isset( $d['headings'] ) ? count( $d['headings'] ) : 0,
					'images'     => isset( $d['images'] ) ? count( $d['images'] ) : 0,
					'links'      => isset( $d['links'] ) ? count( $d['links'] ) : 0,
					'structure'  => isset( $d['structure'] ) ? $d['structure'] : array(),
				),
				'printed_head'   => $preview,
				'findings'       => self::with_fixes( $post->ID, $finds ),
				'siblings'       => self::siblings( $post ),
			);
		}

		/**
		 * Findings with the call that fixes each.
		 *
		 * @param int   $post_id Post id.
		 * @param array $finds   Findings.
		 * @return array
		 */
		private static function with_fixes( $post_id, array $finds ) {
			$fix = array(
				'heading'    => 'Change the heading tag in the section HTML: uichemy-composer/page patch-section-code.',
				'image'      => 'uichemy-composer/seo set-image-alt {post_id: ' . $post_id . ', images: [{section, path, alt | decorative}]}.',
				'attributes' => 'Add the attribute in the section HTML: uichemy-composer/page patch-section-code.',
				'link'       => 'Edit the <a> in the section HTML: uichemy-composer/page patch-section-code.',
				'code'       => 'Edit the section HTML (uichemy-composer/page patch-section-code), or the page head code (update-page-code).',
				'panel'      => 'Add or edit the tag in this page\'s before-</head> code: uichemy-composer/page update-page-code.',
				'settings'   => 'uichemy-composer/seo update-site-settings.',
			);
			$out = array();
			foreach ( $finds as $f ) {
				$out[] = array(
					'check'    => $f['check'],
					'severity' => $f['severity'],
					'issue'    => $f['message'],
					'node'     => $f['node_path'] ? array(
						'section' => $f['section'],
						'path'    => $f['node_path'],
					) : null,
					'fix'      => isset( $fix[ $f['fix_target'] ] ) ? $fix[ $f['fix_target'] ] : '',
				);
			}
			return $out;
		}

		/**
		 * Up to 20 published siblings' titles and descriptions, so a new one is distinct.
		 *
		 * @param WP_Post $post Post.
		 * @return array
		 */
		private static function siblings( $post ) {
			$out = array();
			$ids = get_posts(
				array(
					'post_type'      => $post->post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'post__not_in'   => array( $post->ID ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$sr = Uich_SEO_Resolver::for_post( $id );
				if ( $sr ) {
					$out[] = array(
						'post_id'     => (int) $id,
						'title'       => (string) $sr['title']['value'],
						'description' => (string) $sr['description']['value'],
					);
				}
			}
			return $out;
		}

		/**
		 * preview-page-schema.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function preview_schema( array $p ) {
			$post = self::post( $p );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$o = Uich_SEO_Schema::for_post( $post->ID );
			return array(
				'post_id'  => $post->ID,
				'enabled'  => Uich_SEO_Schema::enabled(),
				'graph'    => $o['graph'],
				// source: auto | markup (data-uich-schema) | site / page (head code JSON-LD) | template.
				'sources'  => isset( $o['entities'] ) ? (array) $o['entities'] : array(),
				'left_out' => $o['errors'],
				'test_url' => 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) get_permalink( $post->ID ) ),
			);
		}

		/**
		 * list-site-settings.
		 *
		 * @return array
		 */
		public static function list_settings() {
			$all    = Uich_SEO_Settings::all();
			$groups = array(
				'titles'       => array( 'site_title', 'site_tagline', 'title_template', 'title_separator', 'home_description' ),
				'social'       => array( 'default_share_image', 'default_share_url', 'default_social_title', 'default_social_description', 'site_name_override', 'og_locale', 'twitter_handle', 'twitter_card', 'og_enabled', 'twitter_enabled' ),
				'identity'     => array( 'entity_type', 'entity_name', 'entity_logo', 'entity_logo_url', 'entity_description', 'entity_email', 'entity_phone', 'social_profiles' ),
				'verification' => array( 'verify_google', 'verify_bing', 'verify_yandex', 'verify_baidu', 'verify_pinterest' ),
				'archives'     => array( 'noindex_author', 'noindex_date', 'noindex_search', 'noindex_paginated', 'redirect_attachments' ),
				'sitemap'      => array( 'blog_public', 'types', 'taxonomies', 'sitemap_sync', 'robots_mode', 'robots_content' ),
				'aeo'          => array( 'llms_enabled', 'llms_limit', 'llms_intro', 'llms_extra', 'md_enabled', 'md_template' ),
				'schema'       => array( 'schema_enabled', 'schema_auto' ),
				'redirects'    => array( 'redirects_enabled', 'auto_redirect', 'record_404' ),
				'advanced'     => array( 'head_owner', 'module_enabled' ),
			);
			$out = array();
			foreach ( $groups as $g => $keys ) {
				foreach ( $keys as $k ) {
					if ( array_key_exists( $k, $all ) ) {
						$out[ $g ][ $k ] = $all[ $k ];
					}
				}
			}
			return array(
				'settings'       => $out,
				'head_code_keys' => Uich_SEO_Head::SITE_KEYS,
				'site_head_tags' => Uich_SEO_Head::build( Uich_SEO_Head::site() ),
				'ownership'      => Uich_SEO_Ownership::status_text(),
			);
		}

		/**
		 * update-site-settings.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function update_settings( array $p ) {
			$values = isset( $p['values'] ) && is_array( $p['values'] ) ? $p['values'] : array();
			if ( ! $values ) {
				return new WP_Error( 'uich_seo_nothing', 'Pass "values": {key: value}. See list-site-settings for the keys.' );
			}
			$unknown = array_diff( array_keys( $values ), array_keys( Uich_SEO_Settings::defaults() ) );
			$saved   = Uich_SEO_Settings::update( $values );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			Uich_SEO_Ownership::flush();
			$out = self::list_settings();
			if ( $unknown ) {
				$out['ignored_keys'] = array_values( $unknown );
			}
			$out['updated'] = array_values( array_diff( array_keys( $values ), $unknown ) );
			return $out;
		}

		/**
		 * audit-site-seo.
		 *
		 * @return array
		 */
		public static function audit_site() {
			$res  = Uich_SEO_Rest::overview();
			$data = $res instanceof WP_REST_Response ? $res->get_data() : (array) $res;
			$data['how_to_work_it'] = 'Fix blockers first, then warnings. For each page: audit-page-seo, then edit its head code or its markup as each finding says. Duplicate titles/descriptions need a distinct <title> / <meta name="description"> in each page\'s head code.';
			return $data;
		}

		/**
		 * get-crawl-status.
		 *
		 * @return array
		 */
		public static function crawl_status() {
			$res  = Uich_SEO_Rest::crawling();
			$data = $res instanceof WP_REST_Response ? $res->get_data() : (array) $res;
			$cur  = (string) get_option( 'permalink_structure' );
			$key  = array_search( $cur, self::PERMALINKS, true );

			$data['permalinks'] = array(
				'structure'   => $cur,
				'preset'      => false === $key ? 'custom' : $key,
				'recommended' => self::PERMALINKS['postname'],
				'ok'          => false !== strpos( $cur, '%postname%' ),
				'advice'      => false !== strpos( $cur, '%postname%' )
					? ( '/%postname%/' === $cur ? 'Post-name permalinks: the SEO recommendation.' : 'Readable, but dates or categories make URLs longer and change when posts are re-filed. /%postname%/ is shorter.' )
					: 'Change to post-name permalinks (update-permalinks {structure: "postname"}) — ' . ( '' === $cur ? 'plain ?p=123 URLs carry no words for search engines or people, and the sitemap rewrite rules need pretty permalinks.' : 'numeric URLs carry no words for search engines or people.' ),
			);
			return $data;
		}

		/**
		 * update-permalinks.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function update_permalinks( array $p ) {
			$in        = isset( $p['structure'] ) ? trim( (string) $p['structure'] ) : '';
			$dry       = ! empty( $p['dry_run'] );
			$structure = isset( self::PERMALINKS[ $in ] ) ? self::PERMALINKS[ $in ] : $in;
			if ( '' !== $structure && false === strpos( $structure, '%postname%' ) && false === strpos( $structure, '%post_id%' ) ) {
				return new WP_Error( 'uich_seo_bad_permalink', 'A permalink structure needs %postname% (or %post_id%). Use "postname" for /%postname%/.' );
			}
			if ( '' !== $structure ) {
				$structure = '/' . trim( preg_replace( '#[^a-z0-9%_/\-.]#i', '', $structure ), '/' ) . ( '/' === substr( $structure, -1 ) ? '/' : '' );
			}
			$before    = (string) get_option( 'permalink_structure' );
			$published = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
			if ( $dry ) {
				return array(
					'dry_run'    => true,
					'before'     => $before,
					'after'      => $structure,
					'urls_moving' => $before === $structure ? 0 : $published,
					'message'    => 'Preview only, nothing was changed. Run again without dry_run to apply.',
				);
			}
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( $structure );
			flush_rewrite_rules( false );
			return array(
				'before'  => $before,
				'after'   => (string) get_option( 'permalink_structure' ),
				'message' => $before === $structure
					? 'Unchanged.'
					: ( $published ? 'Changed. Every URL moved: WordPress redirects most old addresses itself; check list-404s over the next days and add-redirect for anything it misses. Update links you control (menus, social profiles) to the new URLs.' : 'Changed.' ),
			);
		}

		/**
		 * list-schema-templates.
		 *
		 * @return array
		 */
		public static function list_templates() {
			$list = array();
			foreach ( Uich_SEO_Schema::templates() as $t ) {
				$t['pages']     = Uich_SEO_Schema::count_matches( $t['conditions'] );
				$t['applies']   = Uich_SEO_Schema::rules_labels( $t['conditions'] );
				$t['retired']   = Uich_SEO_Schema::is_section_type( $t['type'] );
				$list[]         = $t;
			}
			return array(
				'templates' => $list,
				'presets'   => array_map(
					function ( $p ) {
						return array(
							'type'     => $p['type'],
							'name'     => $p['name'],
							'desc'     => $p['desc'],
							'template' => $p['template'],
						);
					},
					Uich_SEO_Schema::presets()
				),
				'note'      => 'Templates marked "retired" are section-level types and no longer print; move them into JSON-LD next to that content (the section\'s JSON-LD, or a Composer Builder page\'s head_code).',
			);
		}

		/**
		 * add / update-schema-template.
		 *
		 * @param array $p      Params.
		 * @param bool  $update Update an existing one.
		 * @return array|WP_Error
		 */
		private static function save_template( array $p, $update = false ) {
			if ( $update ) {
				$cur = ! empty( $p['id'] ) ? Uich_SEO_Schema::template( (int) $p['id'] ) : null;
				if ( ! $cur ) {
					return new WP_Error( 'uich_seo_no_template', 'No schema template with that id. See list-schema-templates.' );
				}
				$p = array_merge( $cur, $p );
			}
			if ( isset( $p['template'] ) && is_array( $p['template'] ) ) {
				$p['template'] = (string) wp_json_encode( $p['template'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			return Uich_SEO_Schema::save_template( $p, ! empty( $p['active'] ) );
		}

		/**
		 * delete-schema-template.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		private static function delete_template( array $p ) {
			$id = isset( $p['id'] ) ? (int) $p['id'] : 0;
			if ( ! $id || ! Uich_SEO_Schema::template( $id ) ) {
				return new WP_Error( 'uich_seo_no_template', 'No schema template with that id. See list-schema-templates.' );
			}
			if ( ! empty( $p['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_delete' => $id, 'template' => Uich_SEO_Schema::template( $id ) );
			}
			wp_delete_post( $id, true );
			Uich_SEO_Schema::flush();
			return array( 'deleted' => $id );
		}

		/**
		 * add / update-redirect.
		 *
		 * @param array $p      Params.
		 * @param bool  $update Update an existing one.
		 * @return array|WP_Error
		 */
		private static function save_redirect( array $p, $update = false ) {
			if ( $update ) {
				$cur = ! empty( $p['id'] ) ? Uich_SEO_Redirects::get( (int) $p['id'] ) : null;
				if ( ! $cur ) {
					return new WP_Error( 'uich_seo_no_redirect', 'No redirect with that id. See list-redirects.' );
				}
				$p = array_merge( $cur, $p );
			}
			return Uich_SEO_Redirects::save( $p, 'agent' );
		}

		/**
		 * delete-redirect.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		private static function delete_redirect( array $p ) {
			$id = isset( $p['id'] ) ? (int) $p['id'] : 0;
			if ( ! $id || ! Uich_SEO_Redirects::get( $id ) ) {
				return new WP_Error( 'uich_seo_no_redirect', 'No redirect with that id. See list-redirects.' );
			}
			if ( ! empty( $p['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_delete' => $id, 'redirect' => Uich_SEO_Redirects::get( $id ) );
			}
			Uich_SEO_Redirects::delete( $id );
			return array( 'deleted' => $id );
		}

		/**
		 * set-image-alt — lane-1 write-back into the section markup (01 §2, 02 §14).
		 * Explicit only: nothing calls this automatically.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function fix_alt( array $p ) {
			$post = self::post( $p );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$images = isset( $p['images'] ) && is_array( $p['images'] ) ? $p['images'] : array();
			if ( ! $images || ! class_exists( 'UiChemy_Builder_Registry' ) ) {
				return new WP_Error( 'uich_seo_nothing', 'Pass images: [{section, path, alt | decorative}] from audit-page-seo findings.' );
			}
			// A Composer Builder document is one "section" (uid uich-cb-page, the
			// same uid its findings carry) whose html is the document's own. It
			// has no driver, so without this branch the write was refused with
			// advice to use the block editor.
			$cb_doc = Uich_SEO_Head::is_cb_document( $post->ID ) ? Uich_CB_Content::get_cb_content( $post->ID ) : null;
			$driver = null;
			if ( $cb_doc ) {
				$sections = array(
					array(
						'uid'      => 'uich-cb-page',
						'settings' => array( 'raw_html' => isset( $cb_doc['content']['html'] ) ? (string) $cb_doc['content']['html'] : '' ),
					),
				);
			} else {
				$slug   = UiChemy_Builder_Registry::detect_post_builder( $post->ID );
				$driver = $slug ? UiChemy_Builder_Registry::get( $slug ) : null;
				if ( ! $driver || ! $driver->is_available() ) {
					return new WP_Error( 'uich_seo_not_composer', 'This page is not built with UiChemy (Composer sections or the Composer Builder). Edit the image in the block editor instead.' );
				}
				$sections = $driver->load_sections( $post->ID, 'content' );
				if ( is_wp_error( $sections ) ) {
					return $sections;
				}
			}
			$record  = Uich_SEO_Record::get( $post->ID );
			$derived = isset( $record['derived']['images'] ) ? (array) $record['derived']['images'] : array();
			$done    = array();
			$changed = false;

			foreach ( $images as $req ) {
				$uid  = isset( $req['section'] ) ? (string) $req['section'] : '';
				if ( '' === $uid && $cb_doc ) {
					$uid = 'uich-cb-page';
				}
				$path = isset( $req['path'] ) ? (string) $req['path'] : '';
				$deco = ! empty( $req['decorative'] );
				$alt  = $deco ? '' : sanitize_text_field( isset( $req['alt'] ) ? (string) $req['alt'] : '' );
				if ( ! $deco && '' === $alt ) {
					$done[] = array( 'section' => $uid, 'path' => $path, 'ok' => false, 'why' => 'Give alt text, or mark the image decorative.' );
					continue;
				}
				// Which image of this section is it? Derived images are in document order.
				$nth = -1;
				$src = '';
				$k   = 0;
				foreach ( $derived as $img ) {
					if ( (string) $img['section'] !== $uid ) {
						continue;
					}
					if ( (string) $img['path'] === $path ) {
						$nth = $k;
						$src = (string) $img['src'];
						break;
					}
					++$k;
				}
				$si = null;
				foreach ( $sections as $i => $sec ) {
					if ( (string) $sec['uid'] === $uid ) {
						$si = $i;
						break;
					}
				}
				if ( $nth < 0 || null === $si ) {
					$done[] = array( 'section' => $uid, 'path' => $path, 'ok' => false, 'why' => 'Image not found. Run audit-page-seo again for fresh paths.' );
					continue;
				}
				$html = (string) $sections[ $si ]['settings']['raw_html'];
				$i    = 0;
				$new  = preg_replace_callback(
					'/<img\b[^>]*>/i',
					function ( $m ) use ( &$i, $nth, $alt, $deco ) {
						if ( $i++ !== $nth ) {
							return $m[0];
						}
						$tag = $m[0];
						$tag = preg_replace( '/\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag );
						$tag = preg_replace( '/\sdata-uich-decorative(\s*=\s*("[^"]*"|\'[^\']*\'))?/i', '', $tag );
						$add = ' alt="' . esc_attr( $alt ) . '"' . ( $deco ? ' data-uich-decorative' : '' );
						return preg_replace( '#\s*(/?)>$#', $add . '$1>', $tag, 1 );
					},
					$html
				);
				if ( $new !== $html ) {
					$sections[ $si ]['settings']['raw_html'] = $new;
					$changed = true;
				}
				$media = false;
				if ( ! empty( $p['update_media'] ) && ! $deco && '' !== $src ) {
					$aid = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $src ) );
					if ( $aid ) {
						update_post_meta( $aid, '_wp_attachment_image_alt', $alt );
						$media = true;
					}
				}
				$done[] = array( 'section' => $uid, 'path' => $path, 'ok' => true, 'alt' => $alt, 'decorative' => $deco, 'media_updated' => $media );
			}
			if ( $changed && $cb_doc ) {
				$cb_doc['content']['html'] = (string) $sections[0]['settings']['raw_html'];
				$ok                        = Uich_CB_Content::save_cb_content( $post->ID, $cb_doc );
				if ( ! $ok || is_wp_error( $ok ) ) {
					return is_wp_error( $ok ) ? $ok : new WP_Error( 'uich_seo_save_failed', 'The page could not be saved.' );
				}
				Uich_SEO_Record::rebuild( $post->ID );
			} elseif ( $changed ) {
				$ok = $driver->save_sections( $post->ID, $sections, 'content' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( method_exists( $driver, 'after_write' ) ) {
					$driver->after_write( $post->ID );
				}
				Uich_SEO_Record::rebuild( $post->ID );
			}
			return array( 'post_id' => $post->ID, 'images' => $done );
		}

		/**
		 * ignore.
		 *
		 * @param array $p Params.
		 * @return array|WP_Error
		 */
		public static function ignore( array $p ) {
			$post = self::post( $p );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$check  = sanitize_key( isset( $p['check'] ) ? (string) $p['check'] : '' );
			$slugs  = wp_list_pluck( Uich_SEO_Audit::catalogue(), 0 );
			if ( ! in_array( $check, $slugs, true ) ) {
				return new WP_Error( 'uich_seo_bad_check', 'Unknown check. Use a "check" value from audit-page-seo findings.', array( 'status' => 400 ) );
			}
			$rec     = Uich_SEO_Record::get( $post->ID );
			$ignored = (array) $rec['ignored_checks'];
			if ( ! isset( $p['ignore'] ) || rest_sanitize_boolean( $p['ignore'] ) ) {
				$ignored[] = $check;
			} else {
				$ignored = array_diff( $ignored, array( $check ) );
			}
			Uich_SEO_Record::set_ignored( $post->ID, $ignored );
			Uich_SEO_Record::rebuild( $post->ID );
			return array(
				'post_id' => $post->ID,
				'ignored' => array_values( Uich_SEO_Record::get( $post->ID )['ignored_checks'] ),
			);
		}
	}
}
