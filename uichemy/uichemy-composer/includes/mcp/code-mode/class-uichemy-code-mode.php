<?php
/**
 * Code Mode: the model writes one PHP script that calls many UiChemy
 * abilities (the same abilities and actions v2 serves), and only its final
 * value comes back.
 *
 * Works with no setup: scripts run in this request, in the interpreter the
 * plugin bundles (engine/). Nothing is needed in wp-config.php. Two optional
 * overrides:
 *
 *   define( 'UICHEMY_CODE_MODE', false );          // switch Code Mode (v3) off
 *   define( 'UICHEMY_CODE_MODE_WRITES', false );   // read-only: scripts cannot change the site
 *
 * Served by UiChemy_MCP_Server_V3 as three tools: get_instructions,
 * search and execute.
 * Scripts can change the site unless UICHEMY_CODE_MODE_WRITES is false; deletes
 * are refused either way. UiChemy_Code_Mode_Dispatcher is the gate.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-uichemy-code-mode-text.php';
require_once __DIR__ . '/class-uichemy-code-mode-catalog.php';
require_once __DIR__ . '/engine/class-uichemy-code-mode-binder.php';
require_once __DIR__ . '/engine/class-uichemy-code-mode-inline-runner.php';
require_once __DIR__ . '/class-uichemy-code-mode-php-emitter.php';
require_once __DIR__ . '/class-uichemy-code-mode-dispatcher.php';
require_once __DIR__ . '/class-uichemy-code-mode-undo.php';

if ( ! class_exists( 'UiChemy_Code_Mode' ) ) {

	/**
	 * Gate, run loop and API reference for Code Mode.
	 */
	class UiChemy_Code_Mode {

		/** Calls one run may make: reads and writes alike (deletes also have their own cap). */
		const MAX_CALLS = 200;

		/**
		 * Wall time for one run. Under nginx's default 60 s upstream timeout with room
		 * to answer: a run the server cuts off loses its answer, though its writes
		 * went through. PHP's own limit is raised to fit where the host allows it,
		 * else the run shrinks to fit (wall_ms()).
		 */
		const WALL_MS = 45000;

		/** Time the script itself may run (time inside ability calls is not counted). */
		const SCRIPT_MS = 30000;

		/** Memory a script may use, at most (and never more than half of what is left). */
		const MEMORY_MB = 128;

		/** Bytes of JSON a script may return: the answer goes into the model's context. */
		const MAX_RESULT = 102400;

		/** Option holding, per user, what a run PHP stopped had changed. */
		const INTERRUPTED_OPTION = 'uichemy_code_mode_interrupted_';

		/**
		 * Is Code Mode switched on? Yes, unless the site owner switched it off:
		 * Settings > AI clients > Code Mode (uichemy_settings[enable_code_mode],
		 * on when absent), or UICHEMY_CODE_MODE false in wp-config.php, which wins.
		 *
		 * Off, the v3 and v1 URLs serve the v2 tools instead (see
		 * UiChemy_MCP_Server_V3::register_mcp_server), so a client already set up
		 * with the v3 URL keeps working.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			if ( defined( 'UICHEMY_CODE_MODE' ) && in_array( UICHEMY_CODE_MODE, array( false, 0, '0', '', 'off' ), true ) ) {
				return false;
			}
			$settings = get_option( 'uichemy_settings', array() );
			return ! ( is_array( $settings ) && array_key_exists( 'enable_code_mode', $settings ) && empty( $settings['enable_code_mode'] ) );
		}

		/**
		 * The interpreter, or why it cannot run here.
		 *
		 * @return UiChemy_Code_Mode_Inline_Runner|WP_Error
		 */
		public static function runner() {
			if ( ! UiChemy_Code_Mode_Inline_Runner::tokenizer_available() ) {
				return new WP_Error( 'uichemy_code_mode_config', 'Code Mode needs PHP\'s tokenizer extension, which is turned off on this server. Ask the host to enable it.' );
			}
			if ( ! UiChemy_Code_Mode_Inline_Runner::available() ) {
				return new WP_Error( 'uichemy_code_mode_config', 'Code Mode needs the PHP parser that ships in the plugin (vendor-prefixed/nikic/php-parser). Reinstall UiChemy from a complete package.' );
			}
			return new UiChemy_Code_Mode_Inline_Runner();
		}

		/**
		 * get_instructions: how to use this server, then what UiChemy is.
		 *
		 * The v3 rules come first, so a model that stops reading early still
		 * knows how to write a script. The UiChemy part is a short briefing
		 * written in v3 names (Page::getCode(), not uichemy-composer/page
		 * get-code): v2's full briefing is about 22 KB, most of it detail the
		 * skills already carry. Its live parts are reused, not copied: the
		 * switched-off features, the builder mode and the skills list.
		 *
		 * @return array{usage_guide:string, instructions:string}
		 */
		public static function instructions( $section = '' ) {
			$section = trim( (string) $section );
			if ( '' !== $section ) {
				return self::full_section( $section );
			}
			return array(
				'usage_guide'  => self::how_to(),
				'instructions' => UiChemy_Code_Mode_Catalog::plain( self::about() ),
			);
		}

		/** Sections the briefing gives in short, with the whole text one call away. */
		const ON_DEMAND = array( 'Code files', 'Memory', 'Store' );

		/**
		 * One briefing section in full: get_instructions(section: "Memory").
		 *
		 * @param string $section Section title, any case.
		 * @return array{section:string, text:string}|array{error:string, sections:array<int,string>}
		 */
		private static function full_section( $section ) {
			foreach ( self::ON_DEMAND as $title ) {
				if ( 0 !== strcasecmp( $title, $section ) ) {
					continue;
				}
				$text = '';
				if ( 'Memory' === $title ) {
					$context = class_exists( 'UiChemy_MCP_Context' ) ? self::v3_actions( self::v3_names( UiChemy_MCP_Context::briefing_section() ), 'Memory' ) : '';
					$text    = self::markdown_section( $context, 'Memory' );
				} else {
					$parts = self::v2_parts();
					if ( ! empty( $parts[ $title ] ) ) {
						$body  = self::v3_names( $parts[ $title ] );
						$owner = preg_match( '/`([A-Z][A-Za-z0-9]*)(?:::[a-zA-Z0-9]+\([^`]*\))?`/', $body, $m ) ? $m[1] : '';
						$text  = '## ' . $title . "\n\n" . trim( self::v3_actions( $body, $owner ) );
					}
				}
				if ( '' !== $text ) {
					return array(
						'section' => $title,
						'text'    => UiChemy_Code_Mode_Catalog::plain( $text ),
					);
				}
			}
			return array(
				'error'    => sprintf( 'No section "%s" on this site.', $section ),
				'sections' => array_values(
					array_filter(
						self::ON_DEMAND,
						static function ( $title ) {
							return 'Store' !== $title || class_exists( 'WooCommerce' );
						}
					)
				),
			);
		}

		/**
		 * One "## " section of a markdown text, up to the next "## " heading.
		 *
		 * @param string $markdown Markdown.
		 * @param string $title    Section title.
		 * @return string
		 */
		private static function markdown_section( $markdown, $title ) {
			return preg_match( '/^## ' . preg_quote( $title, '/' ) . '\s*$.*?(?=^## |\z)/ms', (string) $markdown, $m ) ? trim( $m[0] ) : '';
		}

		/**
		 * What UiChemy is and the rules a build must not miss, in v3 names.
		 *
		 * @return string Markdown.
		 */
		private static function about() {
			$composer = class_exists( 'UiChemy_Builder_Context' )
				&& method_exists( 'UiChemy_Builder_Context', 'is_composer_mode' )
				&& UiChemy_Builder_Context::is_composer_mode();

			$pages = $composer
				? array(
					'- Each page is ONE document: html, css, js, head_code and body_end_code. Create the whole page with one Page::create() call: plan the regions, then send the page once. Only a page too large for one call is finished with Page::patchCode(): one call per field, several regions at a time, anchored on code that stays (in html, </main>).',
					'- To add a block, put its markup into html. Build skills give their Composer Builder version where a step differs: they plan the page as regions of the ONE document.',
					'- Page::create() with post_id converts an existing page; a page another builder owns needs confirm: true, so ask the user first.',
					'- Before every edit, call Page::getCode() and pass its hash as expected_hash to Page::setCode() or Page::patchCode(). patchCode for small find/replace edits, setCode to replace whole parts.',
					'- Publish, rename or set the page template with Page::update().',
					'- Header, footer and single templates are documents too: create them with ThemeBuilder::create(), then edit them by post_id with Post::getCode(), Post::setCode() and Post::patchCode().',
					'- Animation: ONE uichemy_controller_bridge declaration per page, first in its js.',
					'- Without unfiltered_html, js is not stored and html is filtered: every write reports this under "stripped".',
				)
				: array(
					'- Each section of a page is one Composer widget holding its own HTML, CSS and JS. Build and edit section by section: that is what keeps the page editable for the user.',
				);

			$summary = self::what_uichemy_has();

			$lines = array_merge(
				array(
					'' !== $summary ? $summary : 'This WordPress site is powered by the UiChemy plugin, which builds real WordPress pages from a Figma design, existing code or a short brief, and keeps them editable for the user.',
					'',
					'## Before you build',
					'1. Read the skill for the job: ReadSkill::call(name: "..."). It is the build process (planning, order, media, checks). Skills are listed at the end.',
					'2. Call DescribeSite::schema(). Its platform block names the page builder every write lands in; if ready is false, stop and read builders for the reason. The rest is the real content model: post types, taxonomies and the field metaKeys a binding must use. Never guess a field name.',
					'3. When you are done, run Audit::run() and fix what it finds before you tell the user it is finished. Each finding names the call that fixes it.',
					'',
					$composer ? '## Composer Builder mode' : '## How pages are built',
				),
				$pages,
				array(
					'- Code before </head> and </body>: per page, or site-wide with Platform.',
					'- Images, video and fonts: upload with Media, then use the URL it returns. The site\'s own font families: CustomFont::requestUpload(), then CustomFont::add() (required, so the font shows in the pickers).',
					'- Header, footer and single templates: ThemeBuilder::create(). For "what is this site missing", call ThemeBuilder::architecture(), not list(): it reports every slot the site has, which still fall back to the theme, and the call that fills each gap. An order_received template must include the woo-thankyou tag, or WooCommerce\'s thank-you hook (gateway notices, conversion pixels) never fires.',
					'- Images, video and fonts in your markup must be a real media-library URL: never a data: or blob: URI or a local path.',
					'',
					'## Mistakes that fail silently',
					'- Dynamic data uses Twig tokens (post.*, product.*, user.*, term.*, image.*, site.*, request.*). An unknown token renders EMPTY with no error, so get the real ones from Dynamic::listFields() first.',
					'- Design system: one CSS block (#uichemy-globals) of global tokens. Read it with DesignSystem::get() before page CSS and use its var(--token) names, not literal values. When writing it: custom properties in :root {}, one per line, grouped under a plain section comment (Colors, Spacing, Fonts) - that comment is the only thing that classifies them; .text-* type styles and .pr-* components after :root; no @layer, no @-metadata. Never rename a token (every var() using it breaks). DesignSystem::set() takes the complete merged file; DesignSystem::patch() is exact find/replace.',
					'- A new content type (post type, taxonomy, fields) comes before any markup: use Cpt and CustomFields. If more than one provider (UiChemy Custom Fields, ACF, JetEngine) is active, ask the user which one to use. A post type or taxonomy registered through UiChemy Custom Fields or JetEngine exists from the NEXT request: add its field groups, terms, loops and template conditions in a new execute call, not later in the same script.',
					'- WooCommerce: cart, checkout and account pages are styled, never replaced; build them around the woo-* tags from Dynamic. A single_product template must include woo-add-to-cart (without it the page cannot take an order) and woo-reviews (Woo\'s review list and submit form).',
					'- SEO tags (title, meta description, canonical, Open Graph, JSON-LD) go in the page\'s head code. Read the improve-seo skill before any SEO work.',
				)
			);

			$off = class_exists( 'UiChemy_Usage_Guide' ) ? UiChemy_Usage_Guide::switched_off_section() : '';
			if ( '' !== $off ) {
				$lines[] = '';
				$lines[] = $off;
			}

			$sections = self::v2_sections();
			// The Store section says the WooCommerce rules in full, and without
			// WooCommerce there is nothing to style: either way, drop the short version.
			if ( false !== strpos( $sections, '## Store' ) || ! class_exists( 'WooCommerce' ) ) {
				$lines = array_values(
					array_filter(
						$lines,
						static function ( $line ) {
							return 0 !== strpos( (string) $line, '- WooCommerce: cart, checkout and account pages' );
						}
					)
				);
			}
			if ( '' !== $sections ) {
				$lines[] = '';
				$lines[] = $sections;
			}

			$skills = class_exists( 'UiChemy_Skills' ) ? UiChemy_Skills::index() : array();
			if ( $skills ) {
				$lines[] = '';
				$lines[] = '## Skills';
				$lines[] = 'A skill is the build PROCESS for one kind of job. Read the matching one with ReadSkill::call(name: "<name>") BEFORE you plan or build (part: "<part>" for one of its extra parts), matching on the user\'s intent without being asked; if several fit, pick the one that names what the user supplied; if none does, say so. Keep this list and the skills you read across a context compaction (get_instructions again if you lose them). Skills name abilities the v2 way: uichemy-composer/page action "patch-code" is Page::patchCode() here. Read it when:';
				foreach ( $skills as $skill ) {
					$lines[] = sprintf( '- %s: %s', (string) ( $skill['name'] ?? '' ), self::skill_trigger( (string) ( $skill['description'] ?? '' ) ) );
				}
			}

			$classes = self::classes_section();
			if ( '' !== $classes ) {
				$lines[] = '';
				$lines[] = $classes;
			}

			// The owner's instructions, the AI's memory and design.md (Settings > MCP)
			// go LAST, as on v2: the site's own instructions are the final word.
			$context = class_exists( 'UiChemy_MCP_Context' ) ? self::short_memory( self::v3_actions( self::v3_names( UiChemy_MCP_Context::briefing_section() ), 'Memory' ) ) : '';
			if ( '' !== trim( $context ) ) {
				$lines[] = '';
				$lines[] = $context;
			}

			return implode( "\n", $lines );
		}

		/**
		 * What UiChemy has on THIS site, from v2's own briefing (its intro and the
		 * "Apart from ..." list), so the two endpoints never describe a different
		 * product: the builder mode (one document in the Composer Builder, one
		 * widget per section in Elementor, Bricks or Gutenberg), Pro's additions
		 * (Module Studio, code files) and the store all come from the same blocks.
		 *
		 * Ability names become v3 class names; a very long item keeps its first
		 * two sentences (the matching skill has the rest).
		 *
		 * @return string Markdown, or '' when v2's briefing is not loaded.
		 */
		private static function what_uichemy_has() {
			$parts = self::v2_parts();
			$intro = trim( (string) preg_replace( '/^# [^\n]*\n/', '', ltrim( $parts[''] ?? '' ) ) );
			if ( '' === $intro ) {
				return '';
			}

			$out = array();
			foreach ( explode( "\n", $intro ) as $line ) {
				if ( strlen( $line ) > 400 && preg_match( '/^(\s*[-*]\s+)(.*)$/s', $line, $m ) ) {
					$sentences = preg_split( '/(?<=[.!?])\s+(?=[A-Z`*])/', $m[2], 3 );
					$line      = $m[1] . implode( ' ', array_slice( $sentences, 0, 2 ) );
				}
				$out[] = $line;
			}
			return self::v3_actions( self::v3_names( implode( "\n", $out ) ) );
		}

		/**
		 * When to read a skill: its "Read this when ..." sentence, which is what a
		 * model matches on; the skill itself says the rest.
		 *
		 * @param string $description Skill description.
		 * @return string
		 */
		private static function skill_trigger( $description ) {
			$description = trim( (string) preg_replace( '/\s+/', ' ', $description ) );
			if ( preg_match( '/\bRead this (when|before)\s+(.+)$/i', $description, $m ) ) {
				return ( 'before' === strtolower( $m[1] ) ? 'before ' : '' ) . rtrim( $m[2], '.' ) . '.';
			}
			return self::first_sentence( $description );
		}

		/**
		 * The memory section with its rules in short; the index of memories (the
		 * part that is about THIS site) stays whole.
		 *
		 * @param string $context Owner's instructions, memory and design.md.
		 * @return string
		 */
		private static function short_memory( $context ) {
			$full = self::markdown_section( $context, 'Memory' );
			if ( '' === $full ) {
				return $context;
			}
			$index = strpos( $full, '### Index' );
			$short = "## Memory\n\nMemories are notes earlier AI sessions saved on this site, one fact each, with a name, a one-line description and a type (user, feedback, project or reference). Check that what a memory names still exists before you rely on it. Save with `Memory::saveMemory()` what is worth keeping across sessions (who the user is, guidance they gave, project decisions, where things live); update a memory that already covers it rather than adding a duplicate; never save secrets or what the site already records; say so in a few words when you save. Before your first save, read the full rules: `get_instructions` with section: \"Memory\"."
				. ( false !== $index ? "\n\n" . trim( substr( $full, $index ) ) : '' );
			return str_replace( $full, $short, $context );
		}

		/**
		 * The v2 sections v3 serves as they are (in v3 names): the ones the
		 * summary points to ("See Store below") and that no v3 rule replaces.
		 * Each appears only when v2 has it on this site (Store with WooCommerce,
		 * Module Studio and Code files with Pro).
		 *
		 * The rest of v2 is left out on purpose: the call envelope (v3 has
		 * scripts), "Do these two things" and the design system (v3's own rules
		 * say them), and the Skills and ability lists (v3 lists both itself).
		 *
		 * @return string Markdown.
		 */
		private static function v2_sections() {
			$keep  = array( 'Store', 'Code files', 'Module Studio', 'PHP error log' );
			$parts = self::v2_parts();
			$out   = array();
			foreach ( $keep as $title ) {
				if ( empty( $parts[ $title ] ) ) {
					continue;
				}
				// The section's own class is the first it names (Store, CodeFile ...).
				$body  = self::v3_names( $parts[ $title ] );
				$owner = preg_match( '/`([A-Z][A-Za-z0-9]*)(?:::[a-zA-Z0-9]+\([^`]*\))?`/', $body, $m ) ? $m[1] : '';
				$text = trim( self::v3_actions( $body, $owner ) );
				if ( 'Store' === $title && ! class_exists( 'WooCommerce' ) ) {
					$text = 'WooCommerce is not active on this site, so every `Store` method only answers that. If the user wants a shop, WooCommerce has to be installed and activated first; then call `get_instructions` again for the store rules.';
				} elseif ( 'Code files' === $title ) {
					$text = '`CodeFile` keeps standalone `.css` and `.js` files, served from `/wp-content/uichemy-composer/`. Section CSS and JS stay inline in the section by default; a file is for code several sections or pages share, or that already comes as one file. A file nothing references does nothing. Before you write or upload one, read the full rules: `get_instructions` with section: "Code files".';
				}
				$out[] = '## ' . $title . "\n\n" . $text;
			}
			return implode( "\n\n", $out );
		}

		/**
		 * v2's briefing for this site, cut at its "## " headings: '' is what comes
		 * before the first one.
		 *
		 * @return array<string,string> Title => body.
		 */
		private static function v2_parts() {
			if ( ! class_exists( 'UiChemy_Usage_Guide' ) ) {
				return array();
			}
			$guide = (string) UiChemy_Usage_Guide::instructions( false );
			$parts = array();
			foreach ( preg_split( '/^## /m', $guide ) as $index => $chunk ) {
				if ( 0 === $index ) {
					$parts[''] = $chunk;
					continue;
				}
				$eol                              = strpos( $chunk, "\n" );
				$title                            = trim( false === $eol ? $chunk : substr( $chunk, 0, $eol ) );
				$parts[ rtrim( $title, ':' ) ] = false === $eol ? '' : substr( $chunk, $eol + 1 );
			}
			return $parts;
		}

		/**
		 * Every UiChemy class and its methods, generated from the catalog, so a
		 * model knows what exists before its first search.
		 *
		 * @return string Markdown.
		 */
		private static function classes_section() {
			$lines = array();
			foreach ( UiChemy_Code_Mode_Catalog::modules() as $module ) {
				$fns = array();
				foreach ( $module['fns'] as $fn ) {
					$fns[] = $fn['fn'] . ( 'delete' === $fn['kind'] ? '*' : '' );
				}
				$lines[] = sprintf( '- %s: %s', $module['module'], implode( ', ', $fns ) );
			}
			if ( ! $lines ) {
				return '';
			}
			sort( $lines );
			return "## UiChemy classes on this site\n`search` a name (\"Page::grep\") for its parameters. * deletes.\n" . implode( "\n", $lines );
		}

		/**
		 * v2 ability names in v3 names (see UiChemy_Code_Mode_Text::names()).
		 *
		 * @param string $text Markdown written for v2.
		 * @return string
		 */
		public static function v3_names( $text ) {
			return UiChemy_Code_Mode_Text::names( (string) $text, self::class_methods() );
		}

		/**
		 * Action names left bare after v3_names() (see UiChemy_Code_Mode_Text::actions()).
		 *
		 * @param string $text  Markdown already through v3_names().
		 * @param string $owner The section's own class, '' for none.
		 * @return string
		 */
		private static function v3_actions( $text, $owner = '' ) {
			return UiChemy_Code_Mode_Text::actions( (string) $text, $owner, self::class_methods() );
		}

		/**
		 * @return array<string,array> UiChemy_Code_Mode_Text::map() of the catalog.
		 */
		private static function class_methods() {
			return UiChemy_Code_Mode_Text::map( UiChemy_Code_Mode_Catalog::modules() );
		}

		/**
		 * search: find the methods that do something, by keyword.
		 *
		 * Every term has to match somewhere (class, method, action, parameter
		 * names, or description); a name match ranks above a description match.
		 * If no method matches every term, methods matching any term are
		 * returned instead. An empty query returns the whole index.
		 *
		 * Each result carries the full one-line signature, which is often all a
		 * model needs to write the script. When that is not enough, the model
		 * searches the exact name ("Page::grep") and gets the method's full
		 * definition: what each parameter does and its allowed values.
		 *
		 * @param string $query Keywords, e.g. "seo audit page", or exact names.
		 * @param int    $limit Most results to return.
		 * @return array
		 */
		public static function search( $query, $limit = 16 ) {
			$names = self::exact_names( $query );
			// "design.md" looks like Class.method; when no name is real, it is keywords.
			if ( $names && ! self::any_known( $names ) ) {
				$names = array();
			}
			if ( $names ) {
				return array_merge(
					array( 'query' => trim( (string) $query ) ),
					self::definitions( $names ),
					array( 'next' => 'Write the script and run it with `execute`.' )
				);
			}

			$modules = UiChemy_Code_Mode_Catalog::modules();
			$raw     = trim( (string) $query );
			$query   = strtolower( $raw );
			$limit   = max( 1, min( 50, (int) $limit ) );

			if ( '' === $query ) {
				return array(
					'query'   => '',
					'classes' => UiChemy_Code_Mode_Php_Emitter::index( $modules ),
					'next'    => 'Search with keywords, e.g. "list pages", for full signatures. Search an exact name, e.g. "Page::grep", for its parameter details.',
				);
			}

			$tokens = self::query_tokens( $raw );

			// Keywords: the words that say something, crudely stemmed, each with the
			// words the docs use for it and the near spellings a typo stands for.
			$terms = array();
			foreach ( $tokens as $token ) {
				if ( ( strlen( $token ) < 3 && ! ctype_digit( $token ) ) || in_array( $token, self::SEARCH_STOP, true ) ) {
					continue;
				}
				$terms[] = self::stem( $token );
			}
			if ( ! $terms ) {
				$terms = array_map( array( __CLASS__, 'stem' ), $tokens );
			}
			if ( preg_match( '/\bids?\b/', $query ) ) {
				$terms[] = 'id';
			}
			$terms = array_values( array_unique( $terms ) );

			// One entry per method: its fields as word lists, strongest first.
			$entries = array();
			$vocab   = array();
			foreach ( $modules as $position => $module ) {
				$about = self::words( $module['about'] ?? '' );
				$class = self::words( $module['module'] . ' ' . $module['slug'] . ' ' . self::plain_label( $module['label'] ) );
				foreach ( $module['fns'] as $order => $fn ) {
					$param_text = '';
					foreach ( $fn['params'] as $param ) {
						$param_text .= ' ' . $param['description'] . ' ' . implode( ' ', array_filter( (array) ( $param['schema']['enum'] ?? ( $param['schema']['items']['enum'] ?? array() ) ), 'is_string' ) );
					}
					$summary = self::first_sentence( $fn['doc'] );
					$fields  = array(
						6 => self::words( $fn['fn'] . ' ' . $fn['action'] ),
						4 => $class,
						3 => self::words( implode( ' ', wp_list_pluck( $fn['params'], 'name' ) ) ),
						2 => self::words( $summary ),
						1 => array_merge( self::words( $fn['doc'] ), self::words( $param_text ), $about ),
					);
					foreach ( $fields as $words ) {
						foreach ( $words as $word ) {
							$vocab[ $word ] = true;
						}
					}
					$entries[] = array(
						'module' => $module,
						'fn'     => $fn,
						'order'  => $position * 1000 + $order,
						'fields' => $fields,
					);
				}
			}

			// A method named as one word ("updatePermalinks", "listredirects") is what
			// the query wants, whatever class its parts happen to name.
			$named        = self::named_methods( $raw, $modules );
			$intent_query = $raw;
			foreach ( $named['words'] as $word ) {
				$intent_query = (string) preg_replace( '/\b' . preg_quote( $word, '/' ) . '\b/', ' ', $intent_query );
			}
			$intents = self::class_intents( self::query_tokens( $intent_query ), $modules, $vocab );
			// "load the seo skill": the skill is what is asked for.
			if ( isset( $intents['ReadSkill'] ) ) {
				$intents = array( 'ReadSkill' => $intents['ReadSkill'] ) + $intents;
			}
			// In a sentence ("which pages have no meta title"), "page" or "post" is
			// usually where the job happens, not what it is about: the other
			// classes it names go first. A list of names ("Page Media") keeps its order.
			if ( count( $intents ) > 1 && count( $tokens ) >= 4 ) {
				$generic = array();
				foreach ( array( 'Page', 'Post' ) as $class ) {
					if ( isset( $intents[ $class ] ) && in_array( self::stem( strtolower( implode( ' ', $intents[ $class ] ) ) ), array( 'page', 'post' ), true ) ) {
						$generic[ $class ] = $intents[ $class ];
						unset( $intents[ $class ] );
					}
				}
				$intents = $intents ? $intents + $generic : $generic;
			}

			// Store answers "WooCommerce is not active" for every call on a site without
			// it, so it only takes room in the results when the query asks for a shop.
			// "product" alone is often a page about one ("product page design"):
			// it unlocks Store only next to a word about selling one.
			if ( isset( $intents['Store'] ) && ! class_exists( 'WooCommerce' ) ) {
				$named_by = array_map( array( __CLASS__, 'stem' ), array_map( 'strtolower', $intents['Store'] ) );
				if ( array( 'product' ) === array_values( array_unique( $named_by ) ) && array_intersect( array_map( array( __CLASS__, 'stem' ), $tokens ), array( 'page', 'design', 'section', 'layout', 'template', 'card', 'gallery', 'image', 'photo' ) ) ) {
					unset( $intents['Store'] );
				}
			}
			$store_named = (bool) array_filter( array_keys( $named['methods'] ), static fn( $k ) => 0 === strpos( $k, 'Store::' ) );
			if ( ! class_exists( 'WooCommerce' ) && ! isset( $intents['Store'] ) && ! $store_named ) {
				$entries = array_values(
					array_filter(
						$entries,
						static function ( $entry ) {
							return 'Store' !== $entry['module']['module'];
						}
					)
				);
			}

			// Per term: the words it matches as typed (with its near spellings when it
			// is not a word the docs use), and its synonyms, which count half: the
			// word someone wrote is the better guess at what they want.
			$groups   = array();
			$synonyms = array();
			foreach ( $terms as $term ) {
				$direct = array_merge( array( $term ), self::SEARCH_VARIANTS[ $term ] ?? array() );
				if ( ! self::known_prefix( $term, $vocab ) ) {
					$direct = array_merge( $direct, self::near_words( $term, array_keys( $vocab ) ) );
				}
				$groups[ $term ]   = array_values( array_unique( $direct ) );
				$synonyms[ $term ] = array_values( array_diff( self::SEARCH_SYNONYMS[ $term ] ?? array(), $direct ) );
			}

			// A term found in few methods says more than one found in most of them.
			$weight_of = array();
			foreach ( $groups as $term => $alternatives ) {
				$df  = 0;
				$any = array_merge( $alternatives, $synonyms[ $term ] );
				foreach ( $entries as $entry ) {
					foreach ( $entry['fields'] as $words ) {
						if ( self::has_any_word( $words, $any ) ) {
							++$df;
							break;
						}
					}
				}
				$weight_of[ $term ] = $df ? 1 + log( max( 1, count( $entries ) ) / $df ) : 0;
			}

			// The words that named a class are already used to pick its methods; the
			// rest of the query ranks them.
			$intent_words = array();
			foreach ( $intents as $class => $words ) {
				foreach ( $words as $word ) {
					$intent_words[ self::stem( $word ) ] = true;
				}
			}
			$rest = array_values( array_diff( array_keys( $groups ), array_keys( $intent_words ) ) );

			$wants_delete = (bool) array_intersect( array_map( array( __CLASS__, 'stem' ), $tokens ), self::DELETE_WORDS );
			$hits         = array();
			foreach ( $entries as $entry ) {
				$score    = 0.0;
				$matched  = 0;
				$rest_hit = 0;
				$rest_sum = 0.0;
				$contrib  = array();
				foreach ( $groups as $term => $alternatives ) {
					$best = 0.0;
					foreach ( $entry['fields'] as $weight => $words ) {
						if ( self::has_any_word( $words, $alternatives ) ) {
							$best = max( $best, (float) $weight );
						} elseif ( $synonyms[ $term ] && self::has_any_word( $words, $synonyms[ $term ] ) ) {
							$best = max( $best, $weight * 0.5 );
						}
					}
					if ( $best > 0 ) {
						$score            += $best * $weight_of[ $term ];
						$contrib[ $term ]  = $best * $weight_of[ $term ];
						++$matched;
						if ( in_array( $term, $rest, true ) ) {
							++$rest_hit;
							$rest_sum += $best * $weight_of[ $term ];
						}
					}
				}
				$class = $entry['module']['module'];
				$key   = $class . '::' . $entry['fn']['fn'];
				if ( ! $matched && ! isset( $intents[ $class ] ) && ! isset( $named['methods'][ $key ] ) ) {
					continue;
				}
				// A method named exactly what the query says ("list" -> list(), not
				// listRedirects()) is the likelier one of a tie.
				if ( isset( $groups[ self::stem( strtolower( $entry['fn']['fn'] ) ) ] ) ) {
					$score += 1.0;
					if ( in_array( self::stem( strtolower( $entry['fn']['fn'] ) ), $rest, true ) ) {
						$rest_sum += 1.0;
					}
				}
				// Deleting is never the guess: without a delete verb in the query, a
				// deleting method ranks below the rest.
				// And when the query does ask to delete, the deleting method is the one.
				$factor = 'delete' === $entry['fn']['kind'] ? ( $wants_delete ? 1.5 : 0.3 ) : 1.0;
				if ( 1.0 !== $factor ) {
					$score    *= $factor;
					$rest_sum *= $factor;
					foreach ( $contrib as $t => $v ) {
						$contrib[ $t ] = $v * $factor;
					}
				}
				// Covering more of the query counts, but a word in the method's name
				// still outweighs several words met somewhere in a long description.
				$coverage = count( $groups ) ? $matched / count( $groups ) : 0;
				$hits[]   = array(
					'class'    => $class,
					'key'      => $key,
					'named'    => isset( $named['methods'][ $key ] ) ? $named['methods'][ $key ] : null,
					'contrib'  => $contrib,
					'order'    => $entry['order'],
					'all'      => count( $groups ) === $matched,
					'score'    => $score * ( 0.5 + 0.5 * $coverage ) + ( count( $groups ) === $matched ? 1.0 : 0.0 ),
					'rest'     => $rest ? $rest_sum * ( 0.5 + 0.5 * $rest_hit / count( $rest ) ) : 0.0,
					'call'     => UiChemy_Code_Mode_Php_Emitter::signature( $entry['module'], $entry['fn'] ),
					'summary'  => self::first_sentence( $entry['fn']['doc'] ),
					'kind'     => $entry['fn']['kind'],
					'delete_penalised' => ! $wants_delete,
				);
			}

			// Methods the query names come first, closest spelling first.
			$first = array_values( array_filter( $hits, static fn( $h ) => null !== $h['named'] ) );
			usort(
				$first,
				static function ( $a, $b ) {
					return array( $a['named'], $a['order'] ) <=> array( $b['named'], $b['order'] );
				}
			);
			$others = array_values( array_filter( $hits, static fn( $h ) => null === $h['named'] ) );
			$ranked = array_merge( $first, self::rank_hits( $others, $intents, $rest, $modules, $limit - count( $first ) ) );
			$all    = count( array_filter( $hits, static fn( $h ) => $h['all'] ) );

			$results = array();
			foreach ( array_slice( $ranked, 0, $limit ) as $hit ) {
				$results[] = array(
					'call'    => $hit['call'],
					'summary' => $hit['summary'],
					'kind'    => $hit['kind'],
				);
			}

			return array(
				'query'     => $query,
				'matched'   => $all ? ( count( $hits ) > $all ? 'all terms first, then some' : 'all terms' ) : ( $hits ? 'any term' : 'nothing' ),
				'total'     => count( $hits ),
				'all_terms' => $all,
				'results'   => $results,
				'next'      => 'If these signatures are enough, write the script and run it with `execute`. For a method\'s parameter details and allowed values, search its exact name, e.g. "Page::grep".',
			);
		}

		/** Words a query uses that say nothing about which method it wants. */
		const SEARCH_STOP = array( 'the', 'and', 'for', 'with', 'from', 'into', 'this', 'that', 'what', 'which', 'how', 'are', 'was', 'its', 'all', 'any', 'can', 'use', 'using', 'used', 'via', 'per', 'one', 'out', 'site', 'uichemy', 'want', 'need', 'some', 'thing', 'before', 'after', 'there', 'their', 'have', 'has', 'should', 'would', 'will', 'please', 'way', 'my', 'our', 'your', 'about', 'like', 'just', 'also', 'then', 'them', 'does', 'did', 'not', 'own' );

		/**
		 * Words people use that the docs do not: each also matches what the docs say.
		 * Keys and values are stems (see stem()).
		 */
		const SEARCH_SYNONYMS = array(
			'id'          => array( 'entiti', 'resolve' ),
			'feedback'    => array( 'report', 'request', 'bug' ),
			'issue'       => array( 'bug', 'report', 'problem' ),
			'broken'      => array( 'error', 'log', 'audit', 'finding' ),
			'crash'       => array( 'error', 'log', 'safe' ),
			'fatal'       => array( 'error', 'log', 'safe' ),
			'white'       => array( 'error', 'safe' ),
			'working'     => array( 'error', 'log' ),
			'ready'       => array( 'audit', 'readiness' ),
			'finish'      => array( 'audit', 'readiness' ),
			'problem'     => array( 'audit', 'finding' ),
			'grid'        => array( 'loop', 'listing' ),
			'latest'      => array( 'loop', 'recent' ),
			'recent'      => array( 'loop' ),
			'listing'     => array( 'loop' ),
			'print'       => array( 'token', 'bind' ),
			'output'      => array( 'token', 'bind' ),
			'text'        => array( 'grep', 'pattern', 'html', 'section' ),
			'turn'        => array( 'activ', 'enabl', 'disabl', 'toggle' ),
			'activate'    => array( 'activ', 'toggle' ),
			'reactivate'  => array( 'activ', 'toggle' ),
			'enable'      => array( 'activ', 'toggle' ),
			'disable'     => array( 'activ', 'toggle' ),
			'switch'      => array( 'activ', 'toggle' ),
			'acf'         => array( 'field' ),
			'entry'       => array( 'submission' ),
			'response'    => array( 'submission' ),
			'lead'        => array( 'submission' ),
			'enquiry'     => array( 'submission' ),
			'inquiry'     => array( 'submission' ),
			'builder'     => array( 'describe', 'schema' ),
			'template'    => array( 'theme' ),
			'snippet'     => array( 'module' ),
			'stylesheet'  => array( 'css' ),
			'style'       => array( 'css', 'design' ),
			'styling'     => array( 'css', 'design' ),
			'favicon'     => array( 'icon' ),
			'speed'       => array( 'performance', 'optimi' ),
			'fast'        => array( 'performance' ),
			'slow'        => array( 'performance' ),
			'add'         => array( 'create', 'insert', 'append' ),
			'make'        => array( 'create' ),
			'new'         => array( 'create' ),
			'build'       => array( 'create' ),
			'insert'      => array( 'create', 'append' ),
			'edit'        => array( 'update', 'patch', 'set' ),
			'change'      => array( 'update', 'patch', 'set' ),
			'modify'      => array( 'update', 'patch', 'set' ),
			'rename'      => array( 'update', 'title' ),
			'replace'     => array( 'patch', 'update' ),
			'swap'        => array( 'patch', 'update' ),
			'fix'         => array( 'patch', 'update', 'audit' ),
			'remove'      => array( 'delete' ),
			'delete'      => array( 'trash' ),
			'erase'       => array( 'delete' ),
			'trash'       => array( 'delete' ),
			'find'        => array( 'search', 'grep', 'list', 'find' ),
			'lookup'      => array( 'search', 'find', 'get' ),
			'look'        => array( 'search', 'find', 'get' ),
			'locate'      => array( 'search', 'find', 'grep' ),
			'show'        => array( 'list', 'read', 'preview' ),
			'view'        => array( 'get', 'list', 'read' ),
			'see'         => array( 'get', 'list', 'read' ),
			'read'        => array( 'get', 'read' ),
			'fetch'       => array( 'get', 'read' ),
			'heading'     => array( 'html', 'section', 'title' ),
			'headline'    => array( 'html', 'section', 'title' ),
			'button'      => array( 'html', 'section', 'link' ),
			'paragraph'   => array( 'html', 'section' ),
			'hero'        => array( 'html', 'section' ),
			'banner'      => array( 'html', 'section' ),
			'cta'         => array( 'html', 'section', 'link' ),
			'content'     => array( 'html', 'section', 'code' ),
			'copy'        => array( 'html', 'grep', 'duplicat' ),
			'layout'      => array( 'section', 'structure', 'html' ),
			'landing'     => array( 'page' ),
			'block'       => array( 'section' ),
			'element'     => array( 'section' ),
			'image'       => array( 'media', 'imag' ),
			'photo'       => array( 'media', 'imag' ),
			'picture'     => array( 'media', 'imag' ),
			'img'         => array( 'media', 'imag' ),
			'alt'         => array( 'alt', 'imag' ),
			'colour'      => array( 'color' ),
			'color'       => array( 'color', 'design' ),
			'palette'     => array( 'color', 'design' ),
			'font'        => array( 'font', 'typography', 'design' ),
			'typography'  => array( 'font', 'typography' ),
			'php'         => array( 'php', 'module', 'code' ),
			'javascript'  => array( 'js', 'script' ),
			'script'      => array( 'js', 'script', 'code' ),
			'publish'     => array( 'status', 'update' ),
			'draft'       => array( 'status' ),
			'schedule'    => array( 'date', 'status' ),
			'shop'        => array( 'store', 'product' ),
			'woocommerce' => array( 'store', 'product' ),
			'menu'        => array( 'menu', 'navigation', 'header' ),
			'nav'         => array( 'menu', 'navigation', 'header' ),
			'navigation'  => array( 'menu', 'navigation', 'header' ),
			'meta'        => array( 'meta', 'seo' ),
			'title'       => array( 'title', 'seo' ),
			'undo'        => array( 'revert', 'restore' ),
			'remember'    => array( 'save', 'memory' ),
			'note'        => array( 'save', 'memory' ),
			'forget'      => array( 'delete', 'memory' ),
			'homepage'    => array( 'front', 'home', 'setting' ),
			'home'        => array( 'front', 'setting' ),
			'appear'      => array( 'grep', 'text', 'section' ),
			'contain'     => array( 'grep', 'find' ),
			'mention'     => array( 'grep', 'find' ),
			'where'       => array( 'grep', 'text' ),
			'only'        => array( 'condition' ),
			'every'       => array( 'condition' ),
			'everywhere'  => array( 'condition' ),
			'display'     => array( 'condition', 'get' ),
			'exclude'     => array( 'condition' ),
			'featured'    => array( 'thumbnail', 'featured' ),
			'thumbnail'   => array( 'thumbnail', 'featured' ),
			'css'         => array( 'css', 'code' ),
			'custom'      => array( 'code', 'custom' ),
			'analytic'    => array( 'code', 'head' ),
			'tracking'    => array( 'code', 'head' ),
			'pixel'       => array( 'code', 'head' ),
			'price'       => array( 'pricing', 'price' ),
			'discount'    => array( 'coupon' ),
			'revert'      => array( 'revert', 'restore' ),
			'restore'     => array( 'revert', 'restore' ),
			'log'         => array( 'log', 'error' ),
			'debug'       => array( 'error', 'log' ),
		);

		/** Spellings of the same word: each counts as the word itself. */
		const SEARCH_VARIANTS = array(
			'price'    => array( 'pricing' ),
			'pricing'  => array( 'price' ),
			'optimise' => array( 'optimiz', 'optimis' ),
			'optimize' => array( 'optimiz', 'optimis' ),
			'colour'   => array( 'color' ),
			'color'    => array( 'colour' ),
			'categor'  => array( 'category', 'categori' ),
			'category' => array( 'categori' ),
			'tag'      => array( 'term' ),
		);

		/** Verbs that ask for a delete: without one, deleting methods rank last. */
		const DELETE_WORDS = array( 'delete', 'remove', 'erase', 'trash', 'drop', 'clear', 'purge', 'uninstall', 'destroy', 'wipe', 'forget', 'rid' );

		/** A slight lead for the classes most queries are about, when none is named. */
		const CLASS_PRIOR = array(
			'Page'     => 1.2,
			'Post'     => 1.1,
			'Template' => 1.05,
		);

		/**
		 * Other names a class goes by, as people would write them. Its own name,
		 * slug and label are added in class_intents(); these are the rest.
		 */
		const CLASS_ALIASES = array(
			'Module'       => array( 'module studio', 'studio', 'snippet', 'code snippet', 'php snippet', 'custom code', 'code module' ),
			'Seo'          => array( 'search engine', 'sitemap', 'redirect', 'robots', 'open graph', 'structured data', 'schema markup', 'faq schema', 'article schema', 'schema', 'json ld', 'jsonld', 'meta title', 'meta description', 'meta tag', 'canonical', 'permalink', '404', '404s', 'not found page', 'noindex', 'no index', 'indexing', 'crawl', 'crawler', 'block search engine', 'google search' ),
			'Store'        => array( 'shop', 'woocommerce', 'woo', 'product', 'ecommerce', 'cart', 'checkout', 'coupon', 'discount', 'shipping', 'payment', 'catalog', 'price', 'product price', 'sku', 'stock', 'inventory' ),
			'CustomFields' => array( 'acf', 'custom field', 'meta field', 'field group' ),
			'Cpt'          => array( 'post type', 'custom post type', 'taxonomy', 'content model', 'content type', 'category', 'tag', 'term', 'list of tag' ),
			'ThemeBuilder' => array( 'theme builder', 'template', 'header', 'footer', '404 template', '404 page template', 'archive template', 'missing template' ),
			'Template'     => array( 'header', 'footer' ),
			'Media'        => array( 'image', 'photo', 'picture', 'media library', 'attachment' ),
			'DesignSystem' => array( 'design system', 'design token', 'css variable', 'global style', 'palette', 'color', 'colour', 'brand color', 'theme color', 'typography', 'heading font', 'font of heading', 'font size', 'font family', 'token', 'dark mode', 'globally', 'global css', 'button style' ),
			'Platform'     => array( 'site setting', 'site code', 'tagline', 'front page setting', 'homepage setting', 'site wide', 'sitewide', 'analytic', 'google analytic', 'tracking code', 'tracking', 'pixel', 'gtag', 'ga', 'ga4', 'ga script', 'google tag', 'tag manager', 'gtm', 'meta pixel', 'hotjar', 'menu', 'footer menu', 'header menu', 'nav menu', 'navigation menu', 'whole site', 'entire site', 'site head', 'set the homepage', 'set homepage', 'change homepage', 'front page' ),
			'Performance'  => array( 'speed', 'lazy load', 'page speed', 'webp', 'avif', 'compress', 'compress image', 'optimise image', 'optimize image', 'convert image', 'image optimisation', 'image optimization', 'restore original image', 'original image', 'unoptimised', 'lighthouse', 'minify', 'minification', 'defer', 'code placement', 'css to footer', 'js to footer', 'critical css', 'section css' ),
			'Forms'        => array( 'form', 'contact form', 'submission', 'form entry', 'lead', 'enquiry' ),
			'ErrorLog'     => array( 'error log', 'php error', 'debug log', 'fatal error', 'critical error', 'white screen', 'site broken', 'site crash', 'crash', 'not working' ),
			'Memory'       => array( 'memory', 'remember', 'design md', 'note for later' ),
			'Audit'        => array( 'launch', 'go live', 'ready to launch', 'pre launch', 'checklist', 'site audit' ),
			'UiChemyTeam'  => array( 'bug report', 'feature request', 'report bug', 'request feature', 'uichemy team', 'feedback', 'submit feedback', 'report a bug' ),
			'ReadSkill'    => array( 'skill', 'read skill' ),
			'CodeFile'     => array( 'code file', 'theme file', 'wasm' ),
			'CustomFont'   => array( 'custom font', 'font', 'webfont', 'font file', 'upload font', 'brand font', 'google font', 'woff', 'woff2', 'ttf', 'otf' ),
			'Dynamic'      => array( 'dynamic', 'twig', 'loop', 'dynamic tag', 'bind', 'bind field', 'listing', 'grid', 'latest post', 'post grid', 'post listing', 'query loop' ),
			'DescribeSite' => array( 'describe site', 'site overview', 'site schema', 'plugin', 'installed plugin', 'what is on' ),
			'Page'         => array( 'landing page' ),
			'Post'         => array( 'blog', 'article', 'blog post' ),
		);

		/**
		 * The query's words in order: lowercase, camelCase split ("ModuleStudio" is
		 * "module studio"), nothing but letters and digits.
		 *
		 * @param string $raw Query as typed.
		 * @return array<int,string>
		 */
		private static function query_tokens( $raw ) {
			$raw = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', (string) $raw );
			$raw = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1 $2', (string) $raw );
			return preg_split( '/[^a-z0-9]+/', strtolower( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
		}

		/**
		 * Crude stem: "pages" and "page" compare equal.
		 *
		 * @param string $word Lowercase word.
		 * @return string
		 */
		private static function stem( $word ) {
			if ( strlen( $word ) <= 3 ) {
				return $word;
			}
			if ( preg_match( '/ies$/', $word ) ) {
				return substr( $word, 0, -3 ) . 'y';
			}
			if ( preg_match( '/(ss|us|is)$/', $word ) ) {
				return $word;
			}
			return (string) preg_replace( '/s$/', '', $word );
		}

		/**
		 * A class label without the product prefix ("UiChemy Builder: Module Studio").
		 *
		 * @param string $label Label.
		 * @return string
		 */
		private static function plain_label( $label ) {
			return (string) preg_replace( '/^UiChemy(?: Builder| Team)?:\s*/i', '', (string) $label );
		}

		/**
		 * Methods the query names as one word, in any case: "updatePermalinks",
		 * "listredirects", or one letter off for a long name ("updatePermalink").
		 *
		 * @param string $raw     Query as typed.
		 * @param array  $modules Catalog modules.
		 * @return array{methods:array<string,int>, words:array<int,string>} "Class::fn" => edits, and the words that named them.
		 */
		private static function named_methods( $raw, array $modules ) {
			$methods = array();
			$words   = array();
			foreach ( preg_split( '/[^A-Za-z0-9_]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
				$lower = strtolower( $word );
				// Only a word that could be a name: long, and not an everyday word.
				if ( strlen( $lower ) < 6 || ! preg_match( '/[a-z][A-Z]|^[a-z]+$/', $word ) ) {
					continue;
				}
				foreach ( $modules as $module ) {
					foreach ( $module['fns'] as $fn ) {
						$name = strtolower( (string) $fn['fn'] );
						// A plain word is a name only when the method's name has more than
						// one part (listRedirects), so "update" is not update().
						if ( ! preg_match( '/[a-z][A-Z]/', (string) $fn['fn'] ) && ! preg_match( '/[a-z][A-Z]/', $word ) ) {
							continue;
						}
						$distance = $name === $lower ? 0 : ( strlen( $lower ) >= 9 ? self::edit_distance( $lower, $name ) : 9 );
						if ( $distance <= 1 ) {
							$key             = $module['module'] . '::' . $fn['fn'];
							$methods[ $key ] = min( $distance, $methods[ $key ] ?? 9 );
							$words[]         = $word;
						}
					}
				}
			}
			return array(
				'methods' => $methods,
				'words'   => array_values( array_unique( $words ) ),
			);
		}

		/**
		 * The classes the query names, in the order it names them, each with the
		 * words that named it: "Module SEO" is Module then Seo, and "ModuleStudio",
		 * "module studio", "studio" or "moduel" are all Module. Longer names win
		 * ("theme builder" is ThemeBuilder, not a theme and a builder).
		 *
		 * @param array<int,string>  $tokens  Query words.
		 * @param array              $modules Catalog modules.
		 * @param array<string,bool> $vocab   Words the docs use: a real word is never read as a typo.
		 * @return array<string,array<int,string>> Class => words.
		 */
		private static function class_intents( array $tokens, array $modules, array $vocab ) {
			$names     = array();
			$own_names = array();
			foreach ( $modules as $module ) {
				$class = $module['module'];
				foreach ( array( implode( ' ', self::query_tokens( $class ) ), str_replace( '-', ' ', (string) $module['slug'] ) ) as $own ) {
					$own_names[ implode( ' ', array_map( array( __CLASS__, 'stem' ), preg_split( '/\s+/', trim( $own ), -1, PREG_SPLIT_NO_EMPTY ) ) ) ][] = $class;
				}
				$phrases = array_merge(
					array(
						implode( ' ', self::query_tokens( $class ) ),
						str_replace( '-', ' ', (string) $module['slug'] ),
						implode( ' ', self::query_tokens( self::plain_label( $module['label'] ) ) ),
					),
					self::CLASS_ALIASES[ $class ] ?? array()
				);
				foreach ( $phrases as $phrase ) {
					$words = array_map( array( __CLASS__, 'stem' ), preg_split( '/\s+/', trim( $phrase ), -1, PREG_SPLIT_NO_EMPTY ) );
					if ( ! $words ) {
						continue;
					}
					$names[ implode( ' ', $words ) ][] = $class;
					// "modulestudio" and "themebuilder", typed as one word.
					if ( count( $words ) > 1 ) {
						$names[ implode( '', $words ) ][] = $class;
					}
				}
			}

			$stems   = array_map( array( __CLASS__, 'stem' ), $tokens );
			$intents = array();
			$count   = count( $stems );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $len = min( 3, $count - $i ); $len >= 1; $len-- ) {
					$phrase  = implode( ' ', array_slice( $stems, $i, $len ) );
					$classes = $names[ $phrase ] ?? null;
					// One word, one letter off a one-word name ("moduel", "templat").
					if ( null === $classes && 1 === $len && strlen( $phrase ) >= 5 && ! self::known_prefix( $phrase, $vocab ) ) {
						foreach ( $names as $name => $named ) {
							if ( false === strpos( $name, ' ' ) && self::edit_distance( $phrase, $name ) <= 1 ) {
								$classes = $named;
								break;
							}
						}
					}
					if ( null === $classes ) {
						continue;
					}
					// "template" names Template; ThemeBuilder only goes by it too.
					$classes = array_values( array_unique( $classes ) );
					if ( count( $classes ) > 1 && isset( $own_names[ $phrase ] ) ) {
						$classes = array_values( array_intersect( $classes, $own_names[ $phrase ] ) );
					}
					foreach ( $classes as $class ) {
						if ( ! isset( $intents[ $class ] ) ) {
							$intents[ $class ] = array_slice( $tokens, $i, $len );
						}
					}
					$i += $len - 1;
					break;
				}
			}
			return $intents;
		}

		/**
		 * Order the hits: methods of the classes the query names come first, in the
		 * order it names them, best match first and otherwise in the class's own
		 * order; methods elsewhere that match the rest of the query follow.
		 *
		 * @param array             $hits    Hits.
		 * @param array             $intents Class => words.
		 * @param array<int,string> $rest    Query terms that name no class.
		 * @param array             $modules Catalog modules (for each class's own name).
		 * @param int               $limit   Results wanted.
		 * @return array
		 */
		private static function rank_hits( array $hits, array $intents, array $rest, array $modules, $limit ) {
			$limit = max( 1, (int) $limit );
			$by_score = static function ( $a, $b ) {
				return array( $b['score'], $a['order'] ) <=> array( $a['score'], $b['order'] );
			};
			if ( ! $intents ) {
				// With no class named, pages and posts are where most content lives.
				foreach ( $hits as $i => $hit ) {
					$hits[ $i ]['score'] *= self::CLASS_PRIOR[ $hit['class'] ] ?? 1.0;
				}
				usort( $hits, $by_score );
				return $hits;
			}

			// Inside a class, every word but the named classes' own names ranks its
			// methods: "redirects" picks Seo, then listRedirects() over auditPageSeo().
			$own = array();
			foreach ( $modules as $module ) {
				if ( isset( $intents[ $module['module'] ] ) ) {
					$own['*'] = ( $own['*'] ?? array() ) + array_flip(
						array_map(
							array( __CLASS__, 'stem' ),
							array_merge(
								self::query_tokens( $module['module'] ),
								self::query_tokens( (string) $module['slug'] ),
								self::query_tokens( self::plain_label( $module['label'] ) )
							)
						)
					);
				}
			}
			$named = array();
			$other = array();
			foreach ( $hits as $hit ) {
				if ( isset( $intents[ $hit['class'] ] ) ) {
					$hit['inside'] = 0.0;
					// "pages" in "header only on blog pages" named Page; it says nothing
					// about which Template method is wanted.
					foreach ( $hit['contrib'] as $term => $value ) {
						if ( ! isset( $own['*'][ $term ] ) ) {
							$hit['inside'] += $value;
						}
					}
					$hit['last']              = 'delete' === $hit['kind'] && $hit['delete_penalised'] ? 1 : 0;
					$named[ $hit['class'] ][] = $hit;
				} elseif ( ! $rest || $hit['rest'] > 0 ) {
					$other[] = $hit;
				}
			}
			usort( $other, $by_score );

			$in_class = static function ( $a, $b ) {
				return array( $a['last'], $b['inside'], $a['order'] ) <=> array( $b['last'], $a['inside'], $b['order'] );
			};
			// A class's methods that match the rest of the query come first; with
			// nothing else to go on, the class keeps its own order (its first
			// method is where to start); the ones that match nothing wait at the end.
			$lists = array();
			$tails = array();
			foreach ( array_keys( $intents ) as $class ) {
				$list = $named[ $class ] ?? array();
				usort( $list, $in_class );
				$any  = array_filter( $list, static fn( $h ) => $h['inside'] > 0 && ! $h['last'] );
				if ( $any ) {
					$tails[ $class ] = array_values( array_filter( $list, static fn( $h ) => $h['inside'] <= 0 || $h['last'] ) );
					$list            = array_values( $any );
				} else {
					$tails[ $class ] = array_values( array_filter( $list, static fn( $h ) => $h['last'] ) );
					$list            = array_values( array_filter( $list, static fn( $h ) => ! $h['last'] ) );
				}
				$lists[ $class ] = $list;
			}

			// Several classes share the room in the order the query names them, so
			// "Module SEO" shows both and Module first.
			$ordered = array();
			$share   = max( 1, (int) ceil( $limit / max( 1, count( $lists ) ) ) );
			foreach ( $lists as $list ) {
				$ordered = array_merge( $ordered, array_slice( $list, 0, $share ) );
			}
			foreach ( $lists as $list ) {
				$ordered = array_merge( $ordered, array_slice( $list, $share ) );
			}
			$ordered = array_merge( $ordered, $other );
			foreach ( $tails as $tail ) {
				$ordered = array_merge( $ordered, $tail );
			}
			return $ordered;
		}

		/**
		 * Does any known word start with the term (or the term with it)?
		 *
		 * @param string             $term  Term.
		 * @param array<string,bool> $vocab Known words.
		 * @return bool
		 */
		private static function known_prefix( $term, array $vocab ) {
			if ( isset( $vocab[ $term ] ) ) {
				return true;
			}
			foreach ( $vocab as $word => $unused ) {
				if ( 0 === strpos( (string) $word, $term ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Known words a typo may stand for: one edit away, two for long words.
		 *
		 * @param string            $term  Term (not a known word).
		 * @param array<int,string> $vocab Known words.
		 * @return array<int,string>
		 */
		private static function near_words( $term, array $vocab ) {
			$length = strlen( $term );
			if ( $length < 4 ) {
				return array();
			}
			$max  = $length >= 8 ? 2 : 1;
			$near = array();
			foreach ( $vocab as $word ) {
				$word = (string) $word;
				if ( abs( strlen( $word ) - $length ) > $max || strlen( $word ) < 4 ) {
					continue;
				}
				$distance = self::edit_distance( $term, $word );
				if ( $distance <= $max ) {
					$near[ $word ] = $distance;
				}
			}
			asort( $near );
			return array_slice( array_keys( $near ), 0, 5 );
		}

		/**
		 * Edits between two words, a swap of neighbours counting as one ("moduel").
		 *
		 * @param string $a Word.
		 * @param string $b Word.
		 * @return int
		 */
		private static function edit_distance( $a, $b ) {
			$la = strlen( $a );
			$lb = strlen( $b );
			if ( abs( $la - $lb ) > 2 ) {
				return 3;
			}
			$d = array();
			for ( $i = 0; $i <= $la; $i++ ) {
				$d[ $i ][0] = $i;
			}
			for ( $j = 0; $j <= $lb; $j++ ) {
				$d[0][ $j ] = $j;
			}
			for ( $i = 1; $i <= $la; $i++ ) {
				for ( $j = 1; $j <= $lb; $j++ ) {
					$cost       = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
					$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
					if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
						$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
					}
				}
			}
			return $d[ $la ][ $lb ];
		}

		/**
		 * Is at least one of these names a real class or method?
		 *
		 * @param array<int,string> $names Names.
		 * @return bool
		 */
		private static function any_known( array $names ) {
			$binder = self::binder();
			foreach ( $names as $name ) {
				$parts = preg_split( '/::|\./', $name, 2 );
				$class = $binder->resolve_class( $parts[0] );
				if ( null !== $class && ( ! isset( $parts[1] ) || null !== $binder->find( $class, $parts[1] ) ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Lowercase words of a text, camelCase and kebab-case split, crudely stemmed.
		 *
		 * @param string $text Text.
		 * @return array<int,string>
		 */
		private static function words( $text ) {
			$text  = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', (string) $text );
			$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
			return array_values( array_unique( array_map( array( __CLASS__, 'stem' ), $words ) ) );
		}

		/**
		 * Does a word start with the term ("form" finds "forms", not "platform")?
		 *
		 * @param array<int,string> $words Words.
		 * @param string            $term  Term.
		 * @return bool
		 */
		private static function has_any_word( array $words, array $terms ) {
			foreach ( $terms as $term ) {
				if ( self::has_word( $words, $term ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param array<int,string> $words Words.
		 * @param string            $term  Term.
		 * @return bool
		 */
		private static function has_word( array $words, $term ) {
			foreach ( $words as $word ) {
				if ( 0 === strpos( $word, $term ) || ( strlen( $word ) >= 3 && 0 === strpos( $term, $word ) && strlen( $term ) - strlen( $word ) <= 2 ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * The method names in a query made only of exact names: "Page::grep",
		 * "page.grep" or "Page::grep, Seo::auditPageSeo". Anything else is a
		 * keyword search, so this returns nothing.
		 *
		 * @param string $query Search query.
		 * @return array<int,string>
		 */
		private static function exact_names( $query ) {
			$tokens = preg_split( '/[\s,]+/', trim( (string) $query ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $tokens as $token ) {
				if ( ! preg_match( '/^[A-Za-z][\w-]*(::|\.)[A-Za-z][\w-]*\(?\)?$/', $token ) ) {
					return array();
				}
			}
			return array_map(
				static function ( $token ) {
					return rtrim( $token, '()' );
				},
				$tokens
			);
		}

		/**
		 * The typed PHP definitions for whole classes or single methods; search
		 * serves these for exact names, and wp-run.php for any.
		 *
		 * "Page" returns the whole class, "Page::grep" (or "page.grep") only that
		 * method, which keeps the answer small. Unknown names come back with the
		 * closest real ones.
		 *
		 * @param array<int,string> $names "Class", "Class::method", ability slugs or full names.
		 * @return array
		 */
		public static function definitions( array $names ) {
			$names  = array_values( array_filter( array_map( 'trim', array_map( 'strval', $names ) ) ) );
			$binder = self::binder();

			if ( ! $names ) {
				return array(
					'error'   => 'Pass one or more names, e.g. "Page::grep" or "Seo".',
					'classes' => $binder->class_names(),
				);
			}

			$wanted  = array(); // Class => null for the whole class, or the methods asked for.
			$unknown = array();
			foreach ( $names as $name ) {
				$parts = preg_split( '/::|\./', $name, 2 );
				$class = $binder->resolve_class( $parts[0] );

				if ( null === $class ) {
					$unknown[] = array(
						'name'         => $name,
						'did_you_mean' => $binder->closest_classes( $parts[0] ),
					);
					continue;
				}
				if ( ! isset( $parts[1] ) || '' === trim( $parts[1] ) ) {
					$wanted[ $class ] = null;
					continue;
				}

				$found = $binder->find( $class, $parts[1] );
				if ( null === $found ) {
					$unknown[] = array(
						'name'         => $name,
						'did_you_mean' => $binder->closest_methods( $class, $parts[1] ),
					);
					continue;
				}
				if ( ! array_key_exists( $class, $wanted ) || null !== $wanted[ $class ] ) {
					$wanted[ $class ][] = $found['fn'];
				}
			}

			$modules = array();
			foreach ( $wanted as $class => $fns ) {
				$module = $binder->module( $class );
				if ( null !== $fns ) {
					$module['fns'] = array_values( array_unique( $fns, SORT_REGULAR ) );
				}
				$modules[] = $module;
			}

			$definitions = array(
				'definitions' => $modules ? UiChemy_Code_Mode_Php_Emitter::emit( $modules ) : '',
			);
			if ( $unknown ) {
				$definitions['unknown'] = $unknown;
			}

			return $definitions;
		}

		/**
		 * Help for a failed script: the real signature of every UiChemy method
		 * the error names, or the closest real names when it names one that does
		 * not exist. Saves the model a round trip to search.
		 *
		 * @param string $message Error message.
		 * @return array{signatures?:array<int,string>, did_you_mean?:array<int,string>}
		 */
		public static function help_for( $message ) {
			if ( ! preg_match_all( '/(UiChemy\\\\(?:Api|Script)\\\\)?\b([A-Z]\w*)::(\w+)\(/', (string) $message, $matches, PREG_SET_ORDER ) ) {
				return array();
			}

			$binder = self::binder();
			$help   = array();
			foreach ( $matches as $match ) {
				$class = $binder->resolve_class( $match[2] );
				if ( null === $class ) {
					// Only a name in our namespace is a misspelt class; Exception::getMessage() is not.
					if ( '' !== $match[1] ) {
						foreach ( $binder->closest_classes( $match[2] ) as $suggestion ) {
							$help['did_you_mean'][] = $suggestion;
						}
					}
					continue;
				}
				$found = $binder->find( $class, $match[3] );
				if ( null === $found ) {
					foreach ( $binder->closest_methods( $class, $match[3] ) as $signature ) {
						$help['did_you_mean'][] = $signature;
					}
					continue;
				}
				$help['signatures'][] = UiChemy_Code_Mode_Php_Emitter::signature( $found['module'], $found['fn'] );
			}

			foreach ( $help as $key => $list ) {
				$help[ $key ] = array_values( array_unique( $list ) );
			}

			return $help;
		}

		/**
		 * Method lookup over the current catalog.
		 *
		 * @return UiChemy_Code_Mode_Binder
		 */
		private static function binder() {
			return new UiChemy_Code_Mode_Binder( UiChemy_Code_Mode_Catalog::modules() );
		}

		/**
		 * A skill's first sentence, cut at a comma or a space under 140
		 * characters rather than mid-word with an ellipsis.
		 *
		 * @param string $text Description.
		 * @return string
		 */
		private static function short_description( $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( preg_match( '/^(.+?[.!?])(\s|$)/', $text, $m ) ) {
				$text = $m[1];
			}
			if ( strlen( $text ) <= 140 ) {
				return $text;
			}

			$cut   = substr( $text, 0, 140 );
			$comma = strrpos( $cut, ',' );
			$cut   = false !== $comma && $comma > 60 ? substr( $cut, 0, $comma ) : substr( $cut, 0, (int) strrpos( $cut, ' ' ) );

			// Never leave an open bracket or backtick behind, or end on a joining word.
			if ( 1 === substr_count( $cut, '`' ) % 2 ) {
				$cut = substr( $cut, 0, (int) strrpos( $cut, '`' ) );
			}
			if ( substr_count( $cut, '(' ) > substr_count( $cut, ')' ) ) {
				$cut = substr( $cut, 0, (int) strrpos( $cut, '(' ) );
			}
			do {
				$before = $cut;
				$cut    = preg_replace( '/[\s,:;-]+(?:the|a|an|of|to|and|or|in|on|at|as|by|for|with|from|into|that|is|are)?$/i', '', rtrim( $cut ) );
			} while ( $cut !== $before );

			return rtrim( $cut, " ,:;-(" ) . '.';
		}

		/**
		 * @param string $text Description.
		 * @return string The first sentence, at most 200 characters.
		 */
		private static function first_sentence( $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			// "e.g." and "i.e." do not end a sentence.
			if ( preg_match( '/^(.+?(?<!\be\.g|\bi\.e|\betc)[.!?])(\s|$)/', $text, $m ) ) {
				$text = $m[1];
			}
			return strlen( $text ) > 200 ? substr( $text, 0, 197 ) . '...' : $text;
		}

		/**
		 * execute: check, run and answer every call, within the budgets.
		 *
		 * Writes the script made are listed under `writes`, so a model (and the
		 * person reading along) can see exactly what changed - including when the
		 * script failed halfway, after some writes had already gone through.
		 *
		 * Pages and posts the script changed are snapshotted first; the answer's
		 * `undo` carries the token that puts them back (UiChemy_Code_Mode_Undo).
		 *
		 * @param string $code         Script body.
		 * @param bool   $dry_run      Answer every write with what it would do instead of running it.
		 * @param bool   $allow_delete Let delete actions run (at most 5).
		 * @param string|null $seed    Seed for rand(), shuffle() ...; null pairs a real run with the dry run before it (see dry_seed()).
		 * @return array { result, logs, calls, writes, usage, undo?, error?, dry_run?, seed? }
		 */
		public static function run( $code, $dry_run = false, $allow_delete = false, $seed = null ) {
			$started = microtime( true );

			$runner = self::runner();
			if ( is_wp_error( $runner ) ) {
				return self::response( null, '', array(), array(), array(), array( 'kind' => 'runtime', 'message' => $runner->get_error_message(), 'line' => 0 ) );
			}

			$wall       = self::wall_ms();
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				// What WordPress gives its admin screens (WP_MAX_MEMORY_LIMIT), not the front end's.
				wp_raise_memory_limit( 'admin' );
			}
			$previous   = self::take_interrupted();
			if ( null === $seed && ! $dry_run ) {
				$seed = self::take_dry_seed( (string) $code );
			}
			$modules    = UiChemy_Code_Mode_Catalog::modules();
			$undo       = $dry_run ? null : new UiChemy_Code_Mode_Undo();
			$dispatcher = new UiChemy_Code_Mode_Dispatcher( $dry_run, $allow_delete, $undo );
			$calls      = array();
			$writes     = array();
			$refusals   = array();
			$wp_ms      = 0.0;

			// Should PHP itself stop this request (a fatal error the limits did not
			// foresee), keep what the run changed and its undo, and hand them to the
			// next execute: the answer that would have listed them is never sent.
			$state  = (object) array(
				'running' => true,
				'undo'    => $undo,
			);
			$rescue = static function () use ( $state, &$writes ) {
				if ( ! $state->running ) {
					return;
				}
				$state->running = false;
				$error          = error_get_last();
				$fatal          = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
				if ( ! $writes || ! is_array( $error ) || ! ( (int) $error['type'] & $fatal ) ) {
					return;
				}
				self::remember_interrupted( $writes, null === $state->undo ? null : $state->undo->save() );
			};
			register_shutdown_function( $rescue );
			// WordPress's fatal error handler ends the request before later shutdown functions run.
			$on_fatal = static function ( $message ) use ( $state, $rescue ) {
				$running = $state->running;
				$rescue();
				return $running ? $message . "\n" . 'A UiChemy Code Mode script was running. What it changed is listed under previous_run in the answer to the next execute call.' : $message;
			};
			add_filter( 'wp_php_error_message', $on_fatal );

			// One ability call: the budgets, the dispatch and the records.
			$handle = function ( $fn, $args ) use ( &$calls, &$writes, &$refusals, &$wp_ms, $dispatcher, $started, $wall ) {
				if ( count( $calls ) >= self::MAX_CALLS ) {
					return array( 'limit' => sprintf( 'The script made more than %d ability calls. Narrow it down, e.g. with per_page or max_results.', self::MAX_CALLS ) );
				}
				if ( ( microtime( true ) - $started ) * 1000 > $wall ) {
					return array( 'limit' => sprintf( 'The run took longer than %d s and was stopped.', (int) round( $wall / 1000 ) ) );
				}

				$call_started = microtime( true );
				$result       = $dispatcher->dispatch( $fn, $args );
				$ms           = ( microtime( true ) - $call_started ) * 1000;
				$wp_ms       += $ms;

				if ( ! empty( $result['refused'] ) ) {
					$refusals[] = $result['message'];
				}

				$calls[] = array(
					'fn'    => $fn,
					'ok'    => ! empty( $result['ok'] ),
					'ms'    => round( $ms, 2 ),
					'bytes' => strlen( (string) wp_json_encode( $result['ok'] ? ( $result['value'] ?? null ) : ( $result['message'] ?? '' ) ) ),
				);

				if ( ! empty( $result['write'] ) ) {
					$record = array(
						'fn'   => $fn,
						'args' => self::summarise_args( $args ),
						'ok'   => ! empty( $result['ok'] ) && empty( $result['reported_failure'] ),
					);
					if ( empty( $result['ok'] ) || ! empty( $result['reported_failure'] ) ) {
						$record['error'] = (string) ( $result['message'] ?? 'It answered that part of it failed: read its result.' );
					}
					$writes[] = $record;
				}

				return $result;
			};

			$limits = array(
				'script_ms'    => min( self::SCRIPT_MS, $wall ),
				'result_bytes' => self::MAX_RESULT,
				// Ability calls stop at WALL_MS; the script itself a little after, inside max_execution_time.
				'wall_ms'      => $wall + 3000,
				'seed'         => $seed,
			);
			$step   = $runner->run( (string) $code, $modules, $limits, $handle );

			$state->running = false;
			$state->undo    = null;
			remove_filter( 'wp_php_error_message', $on_fatal );

			$total_ms = ( microtime( true ) - $started ) * 1000;
			$usage    = array_merge(
				array(
					'calls'     => count( $calls ),
					'writes'    => count( $writes ),
					'total_ms'  => round( $total_ms, 2 ),
					'runner_ms' => round( $total_ms - $wp_ms, 2 ),
					'wp_ms'     => round( $wp_ms, 2 ),
				),
				isset( $step['usage'] ) && is_array( $step['usage'] ) ? $step['usage'] : array(),
				$runner->usage()
			);

			if ( $dry_run && ! empty( $step['seed'] ) ) {
				self::keep_dry_seed( (string) $code, $step['seed'] );
			}

			// Saved whether or not the script finished: a failed run can still have changed things.
			$undo = null === $undo ? null : $undo->save();

			if ( 'done' === ( $step['type'] ?? '' ) ) {
				$bytes = strlen( (string) wp_json_encode( $step['output'] ?? null ) );
				if ( $bytes > self::MAX_RESULT ) {
					$step = array(
						'type'     => 'error',
						'kind'     => 'limit',
						'message'  => sprintf( 'The script returned %d KB, more than the %d KB allowed. Filter, count or slice inside the script and return only what you need.', (int) ceil( $bytes / 1024 ), self::MAX_RESULT / 1024 ),
						'line'     => 0,
						'logs'     => $step['logs'] ?? '',
						'warnings' => $step['warnings'] ?? array(),
					);
				}
			}

			if ( 'done' === ( $step['type'] ?? '' ) ) {
				return self::finish( self::response( $step['output'] ?? null, (string) ( $step['logs'] ?? '' ), $calls, $writes, $usage, null, $dry_run ), $step, $undo, $previous );
			}

			$error = array(
				'kind'    => (string) ( $step['kind'] ?? 'runtime' ),
				'message' => (string) ( $step['message'] ?? 'The script failed.' ),
				'line'    => (int) ( $step['line'] ?? 0 ),
			);
			// An uncaught refusal is a refusal, not a crash.
			if ( 'runtime' === $error['kind'] && in_array( $error['message'], $refusals, true ) ) {
				$error['kind'] = 'refused';
			}
			if ( ! empty( $step['errors'] ) && is_array( $step['errors'] ) && count( $step['errors'] ) > 1 ) {
				$error['all'] = $step['errors'];
			}
			$help = self::help_for( $error['message'] . ' ' . implode( ' ', array_column( $error['all'] ?? array(), 'message' ) ) );
			if ( $help ) {
				$error['help'] = $help;
			}
			// The model writes Page::grep(), not UiChemy\Api\Page::grep(): say it the same way
			// (only now, since help_for() reads the namespace to tell our classes apart).
			$error['message'] = str_replace( UiChemy_Code_Mode_Binder::API_NAMESPACE, '', $error['message'] );
			if ( isset( $error['all'] ) ) {
				foreach ( $error['all'] as $i => $one ) {
					$error['all'][ $i ]['message'] = str_replace( UiChemy_Code_Mode_Binder::API_NAMESPACE, '', (string) ( $one['message'] ?? '' ) );
				}
			}

			return self::finish( self::response( null, (string) ( $step['logs'] ?? '' ), $calls, $writes, $usage, $error, $dry_run ), $step, $undo, $previous );
		}

		/** Transient prefix for the seeds dry runs drew with. */
		const DRY_SEED_PREFIX = 'uich_cm_dry_seed_';

		/** How long a dry run's seed waits for its real run. */
		const DRY_SEED_TTL = HOUR_IN_SECONDS;

		/**
		 * A dry run drew random numbers: keep its seed, so the real run after it
		 * draws the same ones and writes what the dry run showed. Nothing for the
		 * model to carry: the run is paired by user and MCP session.
		 *
		 * @param string $code Script.
		 * @param string $seed Seed it drew with.
		 */
		private static function keep_dry_seed( $code, $seed ) {
			$key   = self::dry_seed_key();
			$seeds = get_transient( $key );
			$seeds = is_array( $seeds ) ? $seeds : array();
			unset( $seeds[ md5( $code ) ] );
			$seeds[ md5( $code ) ] = $seed;
			// The latest few; the last one is the latest dry run.
			set_transient( $key, array_slice( $seeds, -5, null, true ), self::DRY_SEED_TTL );
		}

		/**
		 * The seed for a real run: its own dry run's (same script), else the
		 * latest dry run's (the script was edited a little in between), used once.
		 * Null when there is none, and the run draws fresh numbers.
		 *
		 * A real run never reuses a seed another real run had, so two runs never
		 * make the same uniqid() by accident.
		 *
		 * @param string $code Script.
		 * @return string|null
		 */
		private static function take_dry_seed( $code ) {
			$key   = self::dry_seed_key();
			$seeds = get_transient( $key );
			if ( ! is_array( $seeds ) || ! $seeds ) {
				return null;
			}
			$hash = md5( $code );
			if ( ! isset( $seeds[ $hash ] ) ) {
				$keys = array_keys( $seeds );
				$hash = end( $keys );
			}
			$seed = $seeds[ $hash ];
			unset( $seeds[ $hash ] );
			if ( $seeds ) {
				set_transient( $key, $seeds, self::DRY_SEED_TTL );
			} else {
				delete_transient( $key );
			}
			return is_string( $seed ) ? $seed : null;
		}

		/**
		 * @return string This user's (and MCP session's) dry-run seeds.
		 */
		private static function dry_seed_key() {
			$who = class_exists( 'UiChemy_MCP_Server_V3' ) ? UiChemy_MCP_Server_V3::session_key() : (string) get_current_user_id();
			return self::DRY_SEED_PREFIX . md5( $who );
		}

		/**
		 * Wall time this run may take: WALL_MS, with PHP's max_execution_time raised
		 * to fit where the host allows it. Where it does not, the run shrinks to what
		 * is left of the request, less a few seconds to answer.
		 *
		 * @param bool $raise Raise PHP's limit (false: only report what a run would get).
		 * @return int Milliseconds.
		 */
		private static function wall_ms( $raise = true ) {
			$want     = self::WALL_MS;
			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) {
				if ( $raise ) {
					@set_time_limit( (int) ceil( $want / 1000 ) + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions -- the run needs its time; hosts that forbid it fall back below.
				}
				return $want;
			}
			$max = (int) ini_get( 'max_execution_time' );
			if ( $max <= 0 ) {
				return $want;
			}
			$elapsed = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
			return (int) max( 5000, min( $want, ( $max - $elapsed - 5 ) * 1000 ) );
		}

		/**
		 * Keep the writes (and undo) of a run PHP stopped with a fatal error.
		 *
		 * @param array      $writes What the run wrote.
		 * @param array|null $undo   What UiChemy_Code_Mode_Undo::save() returned.
		 */
		private static function remember_interrupted( array $writes, $undo ) {
			update_option(
				self::INTERRUPTED_OPTION . get_current_user_id(),
				array(
					'time'   => time(),
					'writes' => $writes,
					'undo'   => $undo,
				),
				false
			);
		}

		/**
		 * What the user's last run had changed when PHP stopped it, once.
		 *
		 * @return array|null { message, writes, undo? }
		 */
		private static function take_interrupted() {
			$option = self::INTERRUPTED_OPTION . get_current_user_id();
			$data   = get_option( $option );
			if ( false === $data ) {
				return null;
			}
			delete_option( $option );
			if ( ! is_array( $data ) || empty( $data['writes'] ) || time() - (int) ( $data['time'] ?? 0 ) > DAY_IN_SECONDS ) {
				return null;
			}

			$previous = array(
				'message' => 'Your previous run was stopped by a server error after it made these writes, so its answer never came back. Tell the user, and check these pages before going on.',
				'writes'  => $data['writes'],
			);
			if ( ! empty( $data['undo']['token'] ) ) {
				$previous['message'] .= ' execute with undo: "<token>" puts them back.';
				$previous['undo']     = $data['undo'];
			}
			return $previous;
		}

		/**
		 * A write's arguments, short enough to list: long strings (whole html
		 * documents) are cut to their length.
		 *
		 * @param array $args Call arguments.
		 * @return array
		 */
		private static function summarise_args( $args ) {
			$summary = array();
			foreach ( (array) $args as $key => $value ) {
				if ( is_string( $value ) && strlen( $value ) > 120 ) {
					$value = sprintf( '(%d characters)', strlen( $value ) );
				} elseif ( is_array( $value ) ) {
					$value = sprintf( '(%d items)', count( $value ) );
				}
				$summary[ $key ] = $value;
			}
			return $summary;
		}

		/**
		 * The run-script answer a model gets: the result and only what helps it
		 * act on the result. The per-call timings and check details in `calls`
		 * and `usage` stay in run() for the bench and tests.
		 *
		 * @param array $run What run() returned.
		 * @return array { previous_run?, result?, warnings?, logs?, writes?, undo?, dry_run?, error?, stats }
		 */
		public static function for_model( array $run ) {
			$answer = array();
			if ( ! empty( $run['previous_run'] ) ) {
				$answer['previous_run'] = $run['previous_run'];
			}
			if ( ! isset( $run['error'] ) ) {
				$answer['result'] = $run['result'];
			}
			if ( ! empty( $run['warnings'] ) ) {
				$answer['warnings'] = $run['warnings'];
			}
			if ( '' !== (string) ( $run['logs'] ?? '' ) ) {
				$answer['logs'] = $run['logs'];
			}
			if ( ! empty( $run['writes'] ) ) {
				$answer['writes'] = $run['writes'];
			}
			if ( ! empty( $run['undo'] ) ) {
				$answer['undo'] = $run['undo'];
			}
			if ( ! empty( $run['dry_run'] ) ) {
				$answer['dry_run'] = true;
			}
			if ( isset( $run['error'] ) ) {
				$answer['error'] = $run['error'];
			}
			$answer['stats'] = array(
				'calls'  => (int) ( $run['usage']['calls'] ?? 0 ),
				'writes' => (int) ( $run['usage']['writes'] ?? 0 ),
				'ms'     => (int) round( (float) ( $run['usage']['total_ms'] ?? 0 ) ),
			);
			return $answer;
		}

		/**
		 * Add the run's warnings (reads of things that did not exist), its undo
		 * and what an earlier run stopped by PHP had changed to a response.
		 *
		 * @param array      $response What response() built.
		 * @param array      $step     The runner's last step.
		 * @param array|null $undo     What UiChemy_Code_Mode_Undo::save() returned.
		 * @param array|null $previous What take_interrupted() returned.
		 * @return array
		 */
		private static function finish( array $response, array $step, $undo, $previous = null ) {
			if ( ! empty( $step['warnings'] ) ) {
				$response['warnings'] = $step['warnings'];
			}
			if ( ! empty( $step['seed'] ) ) {
				$response['seed'] = $step['seed'];
			}
			if ( ! empty( $undo ) ) {
				$response['undo'] = $undo;
			}
			if ( ! empty( $previous ) ) {
				$response['previous_run'] = $previous;
			}
			return $response;
		}

		/**
		 * execute with undo: put back the pages and posts a run changed.
		 *
		 * @param string $token The run's undo.token.
		 * @return array { undone, skipped } or { error }.
		 */
		public static function undo( $token ) {
			$result = UiChemy_Code_Mode_Undo::restore( $token );
			if ( is_wp_error( $result ) ) {
				return array(
					'error' => array(
						'kind'    => 'runtime',
						'message' => $result->get_error_message(),
					),
				);
			}
			return $result;
		}

		/**
		 * @param mixed      $result Script's return value.
		 * @param string     $logs   Captured echo/print output.
		 * @param array      $calls   Per-call records.
		 * @param array      $writes  The writes the script made.
		 * @param array      $usage   Timings and sizes.
		 * @param array|null $error   { kind, message, line }.
		 * @param bool       $dry_run Whether writes were only previewed.
		 * @return array
		 */
		private static function response( $result, $logs, array $calls, array $writes, array $usage, $error = null, $dry_run = false ) {
			$response = array(
				'result' => $result,
				'logs'   => $logs,
				'calls'  => $calls,
				'writes' => $writes,
				'usage'  => $usage,
			);
			if ( $dry_run ) {
				$response['dry_run'] = true;
			}
			if ( null !== $error ) {
				$response['error'] = $error;
			}
			return $response;
		}

		/**
		 * The rules a script is written to: the first thing get_instructions returns.
		 *
		 * @return string
		 */
		public static function how_to() {
			$writes = UiChemy_Code_Mode_Dispatcher::writes_enabled()
				? '- Methods marked WRITE change the site (they count toward the call limit). Run with dry_run: true first to see what would change: reads run for real, each write is checked and answers { dry_run, would_call, with, preview? } instead of its result (so a later call that needs a new post_id cannot be previewed). Every write is listed under "writes".'
					. "\n" . '- After a run that changed pages or posts, the answer has undo.token: execute with undo: "<token>" (and no code) puts them back, within 24 hours. undo.not_covered lists changes it cannot undo (anything without a post_id, and deletes).'
					. "\n" . ( UiChemy_Code_Mode_Dispatcher::deletes_enabled()
						? sprintf( '- Methods marked DELETE are permanent. They run only when execute has allow_delete: true, at most %d per run. First show the user exactly what will be deleted (a dry_run previews deletes without allow_delete) and get their OK. A call that answers with a confirm_token (or confirm) is confirmed in a new execute call, never in the same script.', UiChemy_Code_Mode_Dispatcher::MAX_DELETES )
						: '- Deletes are switched off on this site. Methods marked REFUSED throw.' )
				: '- Read-only: writes are switched off on this site. Methods marked REFUSED throw.';

			return implode(
				"\n",
				array(
					'# How to use',
					'',
					'Write ONE PHP script that calls as many UiChemy methods as it needs. Only what it returns comes back, so filter and count inside the script.',
					'',
					'## Steps',
					'1. `search` what you want to do, e.g. "seo audit page". Each result is a ready-to-use signature.',
					'2. Need what a parameter means or its allowed values? `search` the exact name, e.g. "Page::grep".',
					'3. Run the script with `execute`. On an error, read error.line, error.message and error.help, fix it and run again.',
					'',
					'## Example',
					'```php',
					'$pages = Page::list(per_page: 100)[\'pages\'] ?? [];',
					'$report = [];',
					'foreach ($pages as $page) {',
					'    $audit = Seo::auditPageSeo(post_id: (int) $page[\'id\']);',
					'    $report[$page[\'title\']] = count($audit[\'findings\'] ?? []);',
					'}',
					'return $report;',
					'```',
					'',
					'## Rules',
					'- Script body only: no <?php, namespace or use lines. Every UiChemy class is already imported.',
					'- NAMED arguments only, as in the example. A wrong argument type is an error before that call is made.',
					'- End with `return <value>;`. Anything you echo comes back as "logs".',
					'- "warnings" in the answer means the script read a variable, key or property that does not exist (a typo, or a wrong guess about the data). Fix the script before you trust or report the result.',
					'- A failed or refused call throws \RuntimeException; catch it if the script should carry on.',
					'- Allowed: plain PHP, closures (also in variables: $f($x), array_map($f, ...), array_map("trim", ...)), named functions, string, array, math, date, hash, url and json functions, randomness, new stdClass, exceptions, print_r/var_dump (into logs).',
					'- rand, mt_rand, shuffle, array_rand, uniqid ... in the real run after a dry run draw the same values the dry run did, so it writes what the dry run showed. random_int and random_bytes are never repeated: use them for secrets.',
					'- Not allowed: files, network, env, eval, include, classes, new (except exceptions and stdClass), [$object, "method"] callables, WordPress functions (use the UiChemy classes).',
					$writes,
					'- Memory::deleteMemory() is a DELETE too: tidying memory (merging, removing stale ones) needs allow_delete and the user\'s OK, at most 5 per run.',
					'- Never deactivate or update the UiChemy plugin, or revoke the Application Password you are connected with: that cuts this connection. Tell the user instead.',
					'- UiChemy is in beta. When a UiChemy method is wrong, fails unclearly or is missing something the user needs, draft a report: UiChemyTeam::reportBug(...) or UiChemyTeam::requestFeature(...). It sends nothing. Show the user the report and what it adds, ask, and only on their yes call UiChemyTeam::send(confirm_token: ...) in a new execute call.',
					sprintf( '- Limits: %d calls, %d s in total, %d s of script time, %d MB. The answer may be at most %d KB, so filter, count or slice inside the script.', self::MAX_CALLS, (int) round( self::wall_ms( false ) / 1000 ), (int) round( min( self::SCRIPT_MS, self::wall_ms( false ) ) / 1000 ), self::MEMORY_MB, self::MAX_RESULT / 1024 ),
				)
			);
		}
	}
}
