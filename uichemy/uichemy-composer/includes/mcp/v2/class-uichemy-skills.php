<?php
/**
 * UiChemy Skills
 *
 * A skill is a process document: the multi-step playbook an AI has to follow
 * to do a job properly. UiChemy has always had these, but they were only
 * reachable by calling one of three
 * tools that did nothing except read a hardcoded file off disk. That made the
 * process documents look like tools in every catalogue, and left content such
 * as the scope-decision rules unreachable because no tool pointed at them.
 *
 * This registry addresses skills by name instead. A skill is declared here as a
 * plain array (name, description, keywords, and the markdown files that make up
 * its body) and is read through the `uichemy-composer/read-skill` ability.
 *
 * Long form skills declare additional parts. `read-skill` returns the main body
 * by default and one named part on request, so a large pipeline can be pulled in
 * stages rather than in one 38 KB response. Whichever is read, the response names
 * the parts still outstanding, and the discovery index names them too, so the
 * model knows before and after reading that the main body is not the whole skill.
 *
 * That navigation metadata travels beside the markdown, never inside it, so an
 * author edits exactly one copy of every document.
 *
 * The documents live in includes/mcp/skills/, named for the skill they serve,
 * authored in v2 vocabulary - ability names, action names, and the
 * `platform.`-nested shape that describe-site actually returns.
 *
 * The definitions stay a PHP array on purpose. Discovery has to list every
 * skill on every call, and an array costs nothing to read, whereas parsing
 * front matter out of a directory scan costs a file read per skill. Bodies live
 * in markdown files and are only touched when a skill is actually read. When
 * user-authored skills arrive later, they can be merged in through the
 * `uichemy_skills` filter in exactly this shape.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Skills' ) ) {

	/**
	 * Declares UiChemy's skills and serves their markdown.
	 */
	class UiChemy_Skills {

		/**
		 * Ability name used to read a skill.
		 */
		const ABILITY_NAME = 'uichemy-composer/read-skill';

		/**
		 * Cached, filtered skill definitions.
		 *
		 * @var array<string,array>|null
		 */
		private static $cache = null;

		/**
		 * Register the reader ability.
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			// Priority 10 so UiChemy_Abilities (priority 5) has registered the
			// `uichemy` category first.
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
		}

		// ============================================================
		// DEFINITIONS
		// ============================================================

		/**
		 * The skills UiChemy ships.
		 *
		 * `files` is the main body, concatenated in order. `additional_parts` are
		 * named follow-up documents the model can request once it has the main body.
		 * Paths are relative to includes/mcp/.
		 *
		 * @return array<string,array>
		 */
		private static function definitions() {
			return array(
				'figma-to-wordpress'      => array(
					'description' => 'Convert a Figma design into WordPress pages built from Composer widgets, section by section. Read this when the user gives a Figma or design URL and wants it turned into a site, page or section.',
					'description_composer' => 'Convert a Figma design into WordPress pages built with the Composer Builder: each page one document of HTML, CSS and JS, planned as regions. Read this when the user gives a Figma or design URL and wants it turned into a site or page.',
					'keywords'    => array( 'figma', 'design', 'mockup', 'convert', 'design to code', 'redesign' ),
					'files'       => array(
						'skills/figma-to-wordpress/overview.md',
						'skills/figma-to-wordpress/structure.md',
						'skills/figma-to-wordpress/tokens.md',
					),
					'additional_parts' => array(
						'globals-sync'    => array(
							'description' => 'Sync and look up the site design tokens before generating any section.',
							'files'       => array( 'skills/figma-to-wordpress/globals-sync.md' ),
						),
						'generate'        => array(
							'description' => 'Generate section code and upload it, including the media flow.',
							'files'       => array(
								'skills/figma-to-wordpress/generate.md',
								'skills/figma-to-wordpress/upload.md',
							),
						),
						'scope-decision'  => array(
							'description' => 'Decide whether a link, style, script or meta tag belongs at site level or page level. Read before emitting any of those tags.',
							'files'       => array( 'skills/shared/scope-decision.md' ),
						),
						'animation'       => array(
							'description' => 'Write a section\'s GSAP animation so its values become designer-editable controls, via the `uichemy_controller_bridge` block in section-level js. Read before writing ANY animation.',
							'files'       => array( 'skills/motion-and-animation.md' ),
						),
						'webgl'           => array(
							'description' => 'Build a WebGL / three.js section: mounting through the context owner, post-processing, and driving a shader from GSAP. Read before writing ANY 3D or shader code.',
							'files'       => array( 'skills/webgl-and-3d.md' ),
						),
						'improve-performance'      => array(
							'description' => 'Performance rules for generated sections (hero label, image preloads, fonts, no jQuery/Elementor dependencies), plus image optimisation and Google Fonts loading. Read before generating the first section.',
							'files'       => array( 'skills/improve-performance.md' ),
						),
						'appendix'        => array(
							'description' => 'Reference appendix for the conversion pipeline.',
							'files'       => array( 'skills/figma-to-wordpress/appendix.md' ),
						),
					),
				),
				'code-to-wordpress'       => array(
					'description' => 'Turn existing front-end code (pasted HTML/CSS/JS, a file, or a local project folder) into a WordPress page, reproducing it faithfully rather than redesigning it. Read this when the user supplies code and no Figma URL.',
					'keywords'    => array( 'html', 'css', 'code', 'import', 'static site', 'convert code', 'project folder' ),
					'files'       => array( 'skills/code-to-wordpress.md' ),
					'additional_parts' => array(
						'scope-decision' => array(
							'description' => 'Decide whether a link, style, script or meta tag belongs at site level or page level.',
							'files'       => array( 'skills/shared/scope-decision.md' ),
						),
						'animation'      => array(
							'description' => 'Write a section\'s GSAP animation so its values become designer-editable controls, via the `uichemy_controller_bridge` block in section-level js. Read before writing ANY animation.',
							'files'       => array( 'skills/motion-and-animation.md' ),
						),
						'webgl'          => array(
							'description' => 'Build a WebGL / three.js section: mounting through the context owner, post-processing, and driving a shader from GSAP. Read before writing ANY 3D or shader code.',
							'files'       => array( 'skills/webgl-and-3d.md' ),
						),
						'improve-performance'     => array(
							'description' => 'Performance rules for generated sections (hero label, image preloads, fonts, no jQuery/Elementor dependencies), plus image optimisation and Google Fonts loading. Read before generating the first section.',
							'files'       => array( 'skills/improve-performance.md' ),
						),
					),
				),
				'builder-to-composer'     => array(
					'description' => 'Move anything built with any other page builder (or theme, or custom code) into UiChemy Composer, same to same: on the same site, or from a source site A to a target site B. You act as the middleman AI between the two sites through whatever connectors you have: read the source (read-only), write the target. Covers pages, builder templates, dynamic loops and field bindings, custom post types, taxonomies, custom fields, media, menus and site settings, with an inventory checklist so nothing is missed. The source builder is never installed on the target. Read this when the user asks to convert, migrate, move or rebuild pages or a site in Composer, whatever builder they came from.',
					'keywords'    => array( 'migrate', 'migration', 'move site', 'site to site', 'convert page', 'convert to composer', 'rebuild in composer', 'page builder', 'builder to composer', 'divi', 'elementor', 'bricks', 'oxygen', 'beaver builder', 'brizy', 'wpbakery', 'acf', 'custom fields', 'dynamic' ),
					'files'       => array( 'skills/builder-to-composer.md' ),
				),
				'brief-to-wordpress'      => array(
					'description' => 'Invent and build a complete site or landing page from a one-line brief, with no design and no code supplied. Read this when the user asks for a website, homepage, landing page or section and gives nothing but a description.',
					'description_composer' => 'Invent and build a complete site or landing page from a one-line brief, with no design and no code supplied. Read this when the user asks for a website, homepage or landing page and gives nothing but a description.',
					'keywords'    => array( 'brief', 'landing page', 'website', 'homepage', 'build me', 'design a', 'from scratch' ),
					'files'       => array( 'skills/brief-to-wordpress.md' ),
					'additional_parts' => array(
						'scope-decision' => array(
							'description' => 'Decide whether a link, style, script or meta tag belongs at site level or page level.',
							'files'       => array( 'skills/shared/scope-decision.md' ),
						),
						'animation'      => array(
							'description' => 'Write a section\'s GSAP animation so its values become designer-editable controls, via the `uichemy_controller_bridge` block in section-level js. Read before writing ANY animation.',
							'files'       => array( 'skills/motion-and-animation.md' ),
						),
						'webgl'          => array(
							'description' => 'Build a WebGL / three.js section: mounting through the context owner, post-processing, and driving a shader from GSAP. Read before writing ANY 3D or shader code.',
							'files'       => array( 'skills/webgl-and-3d.md' ),
						),
						'improve-performance'     => array(
							'description' => 'Performance rules for generated sections (hero label, image preloads, fonts, no jQuery/Elementor dependencies), plus image optimisation and Google Fonts loading. Read before generating the first section.',
							'files'       => array( 'skills/improve-performance.md' ),
						),
					),
				),
				'motion-and-animation'    => array(
					'description' => 'Write a section\'s animation as GSAP whose values become editable controls in the Composer\'s Animation panel (the `uichemy_controller_bridge` block, in section-level js). Read this before writing ANY animation - on its own, or as the `animation` part of a build skill.',
					'description_composer' => 'Write a page\'s animation as GSAP whose values become editable controls in the Animation panel (ONE `uichemy_controller_bridge` block at the top of the page\'s js). Read this before writing ANY animation - on its own, or as the `animation` part of a build skill.',
					'keywords'    => array( 'animation', 'animate', 'gsap', 'scroll', 'scrolltrigger', 'motion', 'parallax', 'stagger', 'reveal', 'fade in', 'transition', 'ease',
						'controller bridge', 'controllers bridge', 'uichemy_controller_bridge' ),
					'files'       => array( 'skills/motion-and-animation.md' ),
					'additional_parts' => array(),
				),
				'webgl-and-3d'            => array(
					'description' => 'Build a section with WebGL / three.js: a shader background, a 3D object, a scroll-driven scene or a loaded model. three.js ships with UiChemy and loads on demand. Read this before writing ANY 3D or shader code - on its own, or as the `webgl` part of a build skill.',
					'description_composer' => 'Build a WebGL / three.js scene in a page: a shader background, a 3D object, a scroll-driven scene or a loaded model. three.js ships with UiChemy and loads on demand. Read this before writing ANY 3D or shader code - on its own, or as the `webgl` part of a build skill.',
					'keywords'    => array( 'webgl', 'three', 'threejs', 'three.js', '3d', 'shader', 'glsl', 'canvas', 'bloom', 'postprocessing', 'gltf', 'glb', 'mesh', 'particles' ),
					'files'       => array( 'skills/webgl-and-3d.md' ),
					'additional_parts' => array(
						'animation' => array(
							'description' => 'The `uichemy_controller_bridge` contract, which a WebGL section follows unchanged in its section-level js - a uniform is declared like any other value.',
							'files'       => array( 'skills/motion-and-animation.md' ),
						),
					),
				),
				'dynamic-loops-and-forms' => array(
					// The document exists and is complete; this entry described it as
					// unwritten long after it was written, so every client was told to
					// skip a working skill. The description is generated from nothing —
					// it is hand-maintained — so it needs updating whenever the file
					// gains or loses a section.
					'description' => 'Build a section that is not static: print LIVE site data with Twig tokens and validated {% for %} listings, or collect input with a managed <form data-atom-form="key">. Read this when the user wants a post/product/term/user listing, a page bound to custom fields, or a working contact or signup form.',
					'description_composer' => 'Build a page that is not static: print LIVE site data with Twig tokens and validated {% for %} listings, or collect input with a managed <form data-atom-form="key">. Read this when the user wants a post/product/term/user listing, a page bound to custom fields, or a working contact or signup form.',
					'keywords'    => array( 'loop', 'query', 'posts', 'form', 'submission', 'dynamic' ),
					'files'       => array( 'skills/dynamic-loops-and-forms.md' ),
					'additional_parts' => array(
						'custom-fields-and-cpt' => array(
							'description' => 'Create the content model itself, and read and write field values. Read before any register-post-type / add-fields / custom-fields call.',
							'files'       => array( 'skills/custom-fields-and-cpt.md' ),
						),
					),
				),
				'improve-seo'             => array(
					// Hidden while Settings > Features > SEO is off: the ability it
					// teaches is not registered then.
					'feature'     => 'seo',
					'description' => 'Improve how the site does in search and AI answers: how UiChemy SEO works (SEO values are tags in page/site head code, and their precedence), finalising site settings (title template, social fallbacks, identity, permalinks), auditing and fixing every page (one H1, text in the HTML), silo site structure and orphan pages, schema (templates vs page vs section JSON-LD), redirects for missing pages, trust signals (author bios, generic AI copy), AEO, and what to hand over to the user (Search Console, backlinks). Read this when the user asks about SEO, rankings, Google not showing the site, meta titles or descriptions, Open Graph / share images, schema or structured data, sitemaps, redirects, 404s or permalinks.',
					'keywords'    => array( 'seo', 'search', 'google', 'ranking', 'meta title', 'meta description', 'open graph', 'og image', 'share image', 'schema', 'structured data', 'json-ld', 'faq schema', 'sitemap', 'robots', 'redirect', '404', 'permalink', 'canonical', 'noindex', 'silo', 'aeo', 'llms.txt', 'h1', 'orphan pages', 'internal links', 'breadcrumbs', 'alt text', 'author bio', 'e-e-a-t', 'search console', 'backlinks', 'indexing', 'not indexed' ),
					'files'       => array( 'skills/improve-seo.md' ),
					'additional_parts' => array(
						'scope-decision' => array(
							'description' => 'Decide whether a tag belongs at site level or page level.',
							'files'       => array( 'skills/shared/scope-decision.md' ),
						),
					),
				),
				'custom-fields-and-cpt'   => array(
					// Deliberately its own skill as well as a part of the dynamic
					// one. The two jobs arrive from opposite directions - "build a
					// listing of case studies" starts at the loop, "add a price
					// field to products" starts at the model - and a model that
					// enters at the second should not have to read about forms to
					// find the delegation rule.
					'description' => 'Create and configure a WordPress content model - custom post types, taxonomies, field groups and fields - through UiChemy Custom Fields (built in), ACF or JetEngine, and read and write the field values on posts, terms, users and options pages. Read this when the user asks for a custom post type, a custom field, UiChemy Custom Fields, ACF, JetEngine, or wants field values filled in or read back.',
					'keywords'    => array( 'cpt', 'custom post type', 'custom field', 'custom fields', 'uichemy custom fields', 'uichemy fields', 'post type', 'acf', 'advanced custom fields', 'jetengine', 'jet engine', 'taxonomy', 'field group', 'meta', 'meta box', 'repeater', 'relation', 'content model', 'schema' ),
					'files'       => array( 'skills/custom-fields-and-cpt.md' ),
					'additional_parts' => array(),
				),
				'improve-performance'              => array(
					'description' => 'Make UiChemy pages fast: the Performance settings (Fast Load), image optimisation (WebP / AVIF) and how Google Fonts load (self-hosted, swap, off), the rules for building fast sections, and the Lighthouse tests to run. Read this when the user asks about performance, page speed, Lighthouse, optimising images or fonts, or when generating a page.',
					'description_composer' => 'Make UiChemy pages fast: the Performance settings (Fast Load), image optimisation (WebP / AVIF) and how Google Fonts load (self-hosted, swap, off), the rules for building fast pages, and the Lighthouse tests to run. Read this when the user asks about performance, page speed, Lighthouse, optimising images or fonts, or when generating a page.',
					'keywords'    => array( 'page speed', 'pagespeed', 'lighthouse', 'web-vitals', 'performance', 'fast load', 'speed', 'font', 'fonts', 'fcp', 'lcp', 'tbt', 'csp', 'image optimisation', 'image optimization', 'optimise images', 'compress images', 'webp', 'avif', 'google fonts', 'self-host fonts', 'self hosted fonts', 'display swap', 'gdpr fonts' ),
					'files'       => array( 'skills/improve-performance.md' ),
					'additional_parts' => array(
						'scope-decision' => array(
							'description' => 'Decide whether a link, style, script or meta tag (for example Google Fonts) belongs at site level or page level.',
							'files'       => array( 'skills/shared/scope-decision.md' ),
						),
						'animation'      => array(
							'description' => 'The `uichemy_controller_bridge` animation contract - read before changing any section animation.',
							'files'       => array( 'skills/motion-and-animation.md' ),
						),
					),
				),
			);
		}

		/**
		 * All skills, after the contribution filter.
		 *
		 * @return array<string,array> Keyed by skill name.
		 */
		public static function all() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}

			/**
			 * Filters the registered UiChemy skills.
			 *
			 * Keys are skill names; each value takes the same shape as the
			 * built-in definitions (description, keywords, files, additional_parts).
			 * An add-on that ships its own markdown sets `base` (on the skill or on a
			 * part) to the folder its `files` are relative to.
			 *
			 * @since 5.1.0
			 *
			 * @param array<string,array> $skills Skill definitions.
			 */
			$skills = apply_filters( 'uichemy_skills', self::definitions() );

			// A skill for a switched-off feature teaches abilities that are not loaded.
			if ( is_array( $skills ) && function_exists( 'uich_feature_enabled' ) ) {
				foreach ( $skills as $slug => $skill ) {
					if ( ! empty( $skill['feature'] ) && ! uich_feature_enabled( $skill['feature'] ) ) {
						unset( $skills[ $slug ] );
					}
				}
			}

			self::$cache = is_array( $skills ) ? $skills : array();

			return self::$cache;
		}

		/**
		 * The skill index: what discovery advertises.
		 *
		 * @return array<int,array{name:string,keywords:array,description:string}>
		 */
		public static function index() {
			$index = array();

			foreach ( self::all() as $name => $skill ) {
				// A skill whose job reads differently on a Composer Builder site (no
				// sections) carries a `description_composer` for that mode.
				$description = isset( $skill['description'] ) ? (string) $skill['description'] : '';
				if ( isset( $skill['description_composer'] ) && self::composer_mode() ) {
					$description = (string) $skill['description_composer'];
				}

				$record = array(
					'name'        => (string) $name,
					'description' => $description,
					'keywords'    => isset( $skill['keywords'] ) && is_array( $skill['keywords'] )
						? array_values( $skill['keywords'] )
						: array(),
				);

				// Names only. Their descriptions ride in the body header, where
				// they are actionable, but the names have to be visible here:
				// otherwise a staged skill looks self-contained at discovery and
				// the model can finish reading the main body believing it has the
				// whole process.
				$additional = isset( $skill['additional_parts'] ) && is_array( $skill['additional_parts'] )
					? $skill['additional_parts']
					: array();
				if ( ! empty( $additional ) ) {
					$record['additional_parts'] = array_keys( $additional );
				}

				$index[] = $record;
			}

			return $index;
		}

		// ============================================================
		// ABILITY
		// ============================================================

		/**
		 * Register `uichemy-composer/read-skill`.
		 */
		public static function register_ability() {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY_NAME ) ) {
				return;
			}

			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => 'UiChemy Builder: Read Skill',
					'description'         => 'Load a UiChemy skill: the step-by-step process to follow for a job. Call it as name: "<skill-name>". Read the skill that matches the user request before starting work. Skill names and what each covers are listed in the instructions.',
					'category'            => 'uichemy-composer',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'       => array(
								'type'        => 'string',
								'description' => 'Skill name, e.g. "figma-to-wordpress". Listed in the discovery instructions.',
							),
							'skill_name' => array(
								'type'        => 'string',
								'description' => 'Accepted alias for "name". Prefer "name".',
							),
							'part'       => array(
								'type'        => 'string',
								'description' => 'Optional. Load one of the skill\'s additional parts instead of its main body. The names are listed in the skills index and at the top of the main body.',
							),
						),
						// `name` is deliberately NOT in `required`: the briefing tells the
						// model to read a skill before anything else, so this is often its
						// first call and "skill_name" is the natural guess. Schema-level
						// validation would reject that guess before the handler could
						// accept it, costing a round trip to learn one parameter name.
						// The handler errors clearly when neither key is supplied.
						'required'   => array(),
					),
					'execute_callback'    => array( __CLASS__, 'execute' ),
					'permission_callback' => array( 'UiChemy_Abilities', 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'title'       => 'UiChemy Builder: Read Skill',
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		}

		/**
		 * Read a skill's main body, or one of its additional parts.
		 *
		 * @param array $arguments Ability input.
		 * @return array|WP_Error Markdown resource, or an error.
		 */
		public static function execute( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();

			// Unwrap an action_parameters envelope. This ability is not action routed,
			// but almost every sibling is, and the briefing that sends a model here
			// teaches that shape — so arriving wrapped is the predictable mistake, not
			// a malformed call. Cheaper to accept than to spend a round trip refusing.
			if ( isset( $arguments['action_parameters'] ) && is_array( $arguments['action_parameters'] ) ) {
				$arguments = array_merge( $arguments, $arguments['action_parameters'] );
				unset( $arguments['action_parameters'] );
			}

			// "skill_name" is accepted alongside "name" — see the schema comment.
			$name = '';
			foreach ( array( 'name', 'skill_name' ) as $key ) {
				if ( isset( $arguments[ $key ] ) && '' !== trim( (string) $arguments[ $key ] ) ) {
					$name = trim( (string) $arguments[ $key ] );
					break;
				}
			}
			$part = isset( $arguments['part'] ) ? trim( (string) $arguments['part'] ) : '';

			// Renamed skill / part: answer the old name so a client that cached it
			// still gets the document instead of an "unknown skill" error.
			$renamed = array( 'improve-page-speed' => 'improve-performance' );
			if ( isset( $renamed[ $name ] ) ) {
				$name = $renamed[ $name ];
			}
			if ( isset( $renamed[ $part ] ) ) {
				$part = $renamed[ $part ];
			}

			$skills = self::all();

			if ( '' === $name ) {
				return new WP_Error(
					'uichemy_missing_skill_name',
					sprintf(
						'Which skill? Pass name: "<skill-name>". Available skills: %s.',
						implode( ', ', array_keys( $skills ) )
					)
				);
			}

			if ( ! isset( $skills[ $name ] ) ) {
				return new WP_Error(
					'uichemy_unknown_skill',
					sprintf(
						'Unknown skill "%s". Available skills: %s.',
						$name,
						implode( ', ', array_keys( $skills ) )
					)
				);
			}

			$skill = $skills[ $name ];
			$parts = isset( $skill['additional_parts'] ) && is_array( $skill['additional_parts'] )
				? $skill['additional_parts']
				: array();

			if ( '' !== $part ) {
				if ( ! isset( $parts[ $part ] ) ) {
					return new WP_Error(
						'uichemy_unknown_skill_part',
						sprintf(
							'Skill "%s" has no additional part "%s". Available: %s.',
							$name,
							$part,
							$parts ? implode( ', ', array_keys( $parts ) ) : 'none'
						)
					);
				}

				$files = isset( $parts[ $part ]['files'] ) ? $parts[ $part ]['files'] : array();
				$base  = isset( $parts[ $part ]['base'] ) ? $parts[ $part ]['base'] : ( isset( $skill['base'] ) ? $skill['base'] : '' );
				$uri   = 'uichemy://skill/' . $name . '/' . $part;
			} else {
				$files = isset( $skill['files'] ) ? $skill['files'] : array();
				$base  = isset( $skill['base'] ) ? $skill['base'] : '';
				$uri   = 'uichemy://skill/' . $name;
			}

			$body = self::read_files( $files, $base );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$body = self::for_builder_mode( $body );

			// Nothing but the document, as an embedded text resource. Navigation
			// metadata does not ride along; `additional_parts` lives in the
			// discovery index.
			return array(
				'type'     => 'resource',
				'uri'      => $uri,
				'mimeType' => 'text/markdown',
				'text'     => $body,
			);
		}

		/**
		 * Is the Composer Builder the selected builder for this request?
		 *
		 * @return bool
		 */
		private static function composer_mode() {
			return class_exists( 'UiChemy_Builder_Context' ) && UiChemy_Builder_Context::is_composer_mode();
		}

		/**
		 * Fit a skill to the selected builder mode.
		 *
		 * 1. Mode blocks. A skill writes a step that differs between the two page
		 *    models side by side, each version wrapped in a block:
		 *
		 *        <!-- mode:sections -->
		 *        ...the step on Elementor / Bricks / block-editor sections...
		 *        <!-- /mode -->
		 *        <!-- mode:composer -->
		 *        ...the step on a Composer Builder page (one document)...
		 *        <!-- /mode -->
		 *
		 *    The block for the other mode is dropped, so each mode reads one
		 *    native version and both are edited together in one file.
		 *
		 * 2. Composer Builder mode only: a skill with no "Composer Builder"
		 *    part of its own (improve-seo, custom-fields-and-cpt, ...) names
		 *    update-page-code, page_before_head and the section actions, every
		 *    one of them refused in this mode, so its remaining section-mode text
		 *    is rewritten to the document actions and a short note says why.
		 *    Composer blocks are already native and are never rewritten.
		 *
		 * @param string $body Skill markdown.
		 * @return string
		 */
		private static function for_builder_mode( $body ) {
			$composer = self::composer_mode();

			// Decide on the text OUTSIDE the mode blocks: a Composer block alone
			// does not make the rest of a section-mode skill native.
			$native = false !== strpos( self::strip_mode_blocks( $body ), 'Composer Builder' );

			$kept = array();
			$body = preg_replace_callback(
				'/<!-- mode:(composer|sections) -->\R?(.*?)<!-- \/mode -->\R?/s',
				static function ( $m ) use ( $composer, &$kept ) {
					$wanted = $composer ? 'composer' : 'sections';
					if ( $m[1] !== $wanted ) {
						return '';
					}
					if ( ! $composer ) {
						return $m[2];
					}
					// Shield native Composer text from the rewrite below.
					$token          = '@@uich-mode-block-' . count( $kept ) . '@@';
					$kept[ $token ] = $m[2];
					return $token;
				},
				$body
			);

			if ( $composer && ! $native ) {
				$note = "> **Composer Builder site.** Every page here is ONE document - `html`, `css`, `js`, `head_code` (before `</head>`), `body_end_code` (before `</body>`) - holding the whole page; where the text below says \"section\", read the page. `uichemy-composer/page` / `post` offer `get-code`, `set-code` and `patch-code`: a page's own head tags go in `head_code` (read it with `get-code`, change it with `patch-code` `field: \"head_code\"`). The calls below are already written that way.\n\n";
				$body = $note . UiChemy_Builder_Context::for_mode( $body );
			}

			return $kept ? strtr( $body, $kept ) : $body;
		}

		/**
		 * The body with every mode block removed.
		 *
		 * @param string $body Skill markdown.
		 * @return string
		 */
		private static function strip_mode_blocks( $body ) {
			return (string) preg_replace( '/<!-- mode:(composer|sections) -->.*?<!-- \/mode -->/s', '', $body );
		}

		/**
		 * Concatenate a skill's markdown files.
		 *
		 * @param array<int,string> $files Paths relative to $base.
		 * @param string            $base  Folder the paths are relative to. Empty: this
		 *                                 plugin's includes/mcp/. A skill an add-on
		 *                                 registers through `uichemy_skills` names its own
		 *                                 folder here (`base` on the skill or the part).
		 * @return string|WP_Error
		 */
		private static function read_files( $files, $base = '' ) {
			if ( ! is_array( $files ) || empty( $files ) ) {
				return '';
			}

			// The documents live in includes/mcp/, one level above this v2 folder, and
			// are shared with v1 — so anchor on the plugin path rather than __FILE__.
			$base = '' !== (string) $base ? trailingslashit( (string) $base ) : UICHEMY_PATH . 'includes/mcp/';
			$parts = array();

			foreach ( $files as $relative ) {
				$path = $base . ltrim( (string) $relative, '/' );

				// Keep reads inside the declared folder.
				$real = realpath( $path );
				if ( false === $real || 0 !== strpos( $real, realpath( $base ) ) ) {
					continue;
				}

				if ( ! is_readable( $real ) ) {
					continue;
				}

				$chunk = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset.
				if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
					$parts[] = $chunk;
				}
			}

			if ( empty( $parts ) ) {
				return new WP_Error( 'uichemy_empty_skill', 'This skill has no readable content yet.' );
			}

			return implode( "\n\n", $parts );
		}


	}
}
