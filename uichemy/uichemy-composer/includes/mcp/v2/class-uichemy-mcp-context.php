<?php
/**
 * UiChemy MCP Context and Memory
 *
 * Two things every AI session on the site is given, set under Settings > MCP:
 *
 * - CONTEXT, in two layers, written once instead of typed into every chat:
 *   SITE context (brand voice, audience, rules - one text, shared by every
 *   admin) and ADMIN context (one per admin, stored on their user: how that
 *   person likes to work). Both are merged at the end of the MCP briefing as
 *   "Site Owner's instructions & context"; a session sees the site context plus the
 *   context of the admin it runs as.
 * - MEMORY: what the AI learns across sessions. Always on. Each memory is one
 *   record - a kebab-case name, a one-line description, a type (user, feedback,
 *   project or reference) and a body. The briefing carries only an index of
 *   them, one line each; the agent reads a memory in full with read-memory.
 *
 * - DESIGN.MD: one design document per site, recording its globals: colours,
 *   typography, buttons, radius and shadows, spacing and layout, shared
 *   components. Empty on a fresh site; the AI should create it with the first
 *   page it builds and follow it afterwards (except for a design the user brings
 *   to import as it is), and asks the user before changing it when a site-wide
 *   decision changes. The values live in #uichemy-globals, where the user can
 *   edit them. The briefing closes with those rules and a pointer to
 *   read-design-md, not the document itself. The AI writes it
 *   (uichemy-composer/memory read-design-md / write-design-md, and a
 *   design-system set / patch reminds it to); owners can add or edit it in
 *   Settings > MCP too.
 *
 * Memory is also served and writable through its own ability,
 * uichemy-composer/memory (read-memory / save-memory / delete-memory), so a
 * user can say "remember that we never use stock photos" in the chat. The
 * context is the owner's: it is read from the briefing and edited only in
 * Settings > MCP, never through the ability.
 *
 * Memory is bounded: a description and a body are capped, and so is the number
 * of memories. Near the cap the AI is asked to consolidate (merge duplicates,
 * fix stale facts, delete what is wrong), which keeps the meaning. At the cap
 * the least recently updated memory moves to a short "Earlier" digest that
 * keeps only its description, so a save never fails for lack of room.
 *
 * Credits:
 * - The memory format is adapted from the memory system of Claude Code by
 *   Anthropic (https://code.claude.com/docs/en/memory): the four memory types
 *   (user, feedback, project, reference), the "Why:" / "How to apply:" lines on
 *   feedback and project memories, and a one-line-per-memory index the agent
 *   reads first. The code and the rest of the wording here are UiChemy's own.
 * - The design.md idea - one design document per site that every AI build
 *   follows - is inspired by Google Stitch (https://stitch.withgoogle.com).
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_MCP_Context' ) ) {

	/**
	 * Stores the owner's context and the AI's memory, and renders both into the briefing.
	 */
	class UiChemy_MCP_Context {

		/**
		 * Site context, shared by every admin. A plain string.
		 */
		const CONTEXT_OPTION = 'uichemy_mcp_context';

		/**
		 * Admin context: user meta on each admin, a plain string.
		 */
		const USER_CONTEXT_META = 'uichemy_mcp_user_context';

		/**
		 * The two context layers, as the `scope` Settings > MCP saves each one under.
		 */
		const SCOPES = array( 'site', 'user' );

		/**
		 * The site's design.md: { markdown: string, updated: int }.
		 */
		const DESIGN_MD_OPTION = 'uichemy_design_md';

		/**
		 * The ability that serves memory and context to MCP clients.
		 */
		const ABILITY_NAME = 'uichemy-composer/memory';

		/**
		 * Memory: { memories: list<record>, digest: string }, where a
		 * record is { name, description, type, content, created, updated }.
		 */
		const MEMORY_OPTION = 'uichemy_mcp_memory';

		/**
		 * Longest context per layer, in characters. It is paid on every session,
		 * so each is kept to roughly a page of text.
		 */
		const CONTEXT_MAX = 6000;

		/**
		 * The four kinds of memory.
		 */
		const TYPES = array( 'user', 'feedback', 'project', 'reference' );

		/**
		 * Longest memory name (a kebab-case slug).
		 */
		const NAME_MAX = 60;

		/**
		 * Longest description: one line, read to decide whether a memory is relevant.
		 */
		const DESCRIPTION_MAX = 150;

		/**
		 * Longest memory body.
		 */
		const CONTENT_MAX = 1500;

		/**
		 * Most memories kept before the least recently updated moves to the digest.
		 */
		const MEMORY_MAX = 50;

		/**
		 * Longest digest of moved-out memories, in characters. The oldest lines go first.
		 */
		const DIGEST_MAX = 1500;

		/**
		 * Share of MEMORY_MAX at which the AI is asked to consolidate.
		 */
		const COMPACT_AT = 0.8;

		/**
		 * Hook the dashboard ajax routes and the ability.
		 */
		public static function init() {
			add_action( 'uichemy/admin/ajax', array( __CLASS__, 'ajax' ) );

			if ( function_exists( 'wp_register_ability' ) ) {
				// Priority 10 so UiChemy_Abilities (priority 5) has registered the
				// `uichemy-composer` category first.
				add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
			}
		}

		/* ── Context ──────────────────────────────────────────────────────── */

		/**
		 * One context layer: the site's, or the current admin's own.
		 *
		 * @param string $scope 'site' or 'user'.
		 * @return string
		 */
		public static function get_context( $scope = 'site' ) {
			if ( 'user' === $scope ) {
				$user_id = get_current_user_id();
				$context = $user_id ? get_user_meta( $user_id, self::USER_CONTEXT_META, true ) : '';
			} else {
				$context = get_option( self::CONTEXT_OPTION, '' );
			}

			return is_string( $context ) ? $context : '';
		}

		/**
		 * Replace one context layer. An empty string clears it.
		 *
		 * @param string $text  New context.
		 * @param string $scope 'site' or 'user' (the current admin).
		 * @return string|WP_Error The stored context.
		 */
		public static function set_context( $text, $scope = 'site' ) {
			if ( ! in_array( $scope, self::SCOPES, true ) ) {
				return self::scope_error();
			}

			$text = self::clean_text( $text );

			if ( self::length( $text ) > self::CONTEXT_MAX ) {
				return new WP_Error(
					'uich_mcp_context_too_long',
					sprintf( 'Context is limited to %d characters; this is %d. Shorten it and send it again.', self::CONTEXT_MAX, self::length( $text ) )
				);
			}

			if ( 'user' === $scope ) {
				$user_id = get_current_user_id();
				if ( ! $user_id ) {
					return new WP_Error( 'uich_mcp_error', 'Admin context belongs to a signed-in admin, and this session has none. Use scope "site".' );
				}
				if ( '' === $text ) {
					delete_user_meta( $user_id, self::USER_CONTEXT_META );
				} else {
					update_user_meta( $user_id, self::USER_CONTEXT_META, $text );
				}
			} else {
				update_option( self::CONTEXT_OPTION, $text, false );
			}

			return $text;
		}

		/**
		 * The refusal for a missing or unknown context scope.
		 *
		 * @return WP_Error
		 */
		private static function scope_error() {
			return new WP_Error( 'uich_mcp_error', 'Pass "scope": "site" for context every admin\'s sessions follow (brand, audience, rules for the site), or "user" for this admin\'s own context (how they like to work with you).' );
		}

		/* ── Memory ───────────────────────────────────────────────────────── */

		/**
		 * The whole memory store, with defaults filled in, newest update last.
		 *
		 * @return array{memories:array<int,array>, digest:string}
		 */
		public static function get_memory() {
			$stored = get_option( self::MEMORY_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$memories = array();
			if ( ! empty( $stored['memories'] ) && is_array( $stored['memories'] ) ) {
				foreach ( $stored['memories'] as $record ) {
					if ( is_array( $record ) && ! empty( $record['name'] ) ) {
						$memories[ (string) $record['name'] ] = array(
							'name'        => (string) $record['name'],
							'description' => isset( $record['description'] ) ? (string) $record['description'] : '',
							'type'        => isset( $record['type'] ) && in_array( $record['type'], self::TYPES, true ) ? $record['type'] : 'project',
							'content'     => isset( $record['content'] ) ? (string) $record['content'] : '',
							'created'     => isset( $record['created'] ) ? (int) $record['created'] : 0,
							'updated'     => isset( $record['updated'] ) ? (int) $record['updated'] : 0,
						);
					}
				}
			}

			uasort(
				$memories,
				static function ( $a, $b ) {
					return $a['updated'] - $b['updated'];
				}
			);

			return array(
				'memories' => array_values( $memories ),
				'digest'   => isset( $stored['digest'] ) ? (string) $stored['digest'] : '',
			);
		}

		/**
		 * Create a memory, or update the one with the same name. On an update any
		 * field left out keeps its value, so a type or description can be changed
		 * alone.
		 *
		 * The owner edits through here too (Settings > MCP), with `$by_owner`:
		 * `rename_from` then renames a memory in place.
		 *
		 * @param array $fields   { name, description?, type?, content?, rename_from? }
		 * @param bool  $by_owner Whether the site owner is saving from the dashboard.
		 * @return array|WP_Error { memory, created, moved_out, consolidate_suggested, usage }
		 */
		public static function save_memory_record( $fields, $by_owner = false ) {
			$memory = self::get_memory();

			$fields = is_array( $fields ) ? $fields : array();
			$name   = self::slug( isset( $fields['name'] ) ? $fields['name'] : '' );

			if ( '' === $name ) {
				return new WP_Error( 'uich_mcp_error', 'Pass "name": lowercase words joined by dashes that label this memory, like "brand-voice". Sending a name that already exists updates that memory.' );
			}

			$index = self::find( $memory['memories'], $name );
			$from  = $by_owner && isset( $fields['rename_from'] ) ? self::slug( $fields['rename_from'] ) : '';

			// A rename edits the memory under its old name and frees that name.
			if ( '' !== $from && $from !== $name ) {
				if ( null !== $index ) {
					return new WP_Error( 'uich_mcp_memory_name_taken', sprintf( 'A memory named "%s" already exists. Pick another name.', $name ) );
				}
				$index = self::find( $memory['memories'], $from );
			}

			$existing = null === $index ? null : $memory['memories'][ $index ];

			$record = array(
				'name'        => $name,
				'description' => isset( $fields['description'] ) ? self::clean_line( $fields['description'] ) : ( $existing ? $existing['description'] : '' ),
				'type'        => isset( $fields['type'] ) ? strtolower( trim( (string) $fields['type'] ) ) : ( $existing ? $existing['type'] : '' ),
				'content'     => isset( $fields['content'] ) ? self::clean_text( $fields['content'] ) : ( $existing ? $existing['content'] : '' ),
				'created'     => $existing ? $existing['created'] : time(),
				'updated'     => time(),
			);

			if ( ! in_array( $record['type'], self::TYPES, true ) ) {
				return new WP_Error( 'uich_mcp_error', 'Pass "type": one of user, feedback, project, reference.' );
			}
			if ( '' === $record['description'] ) {
				return new WP_Error( 'uich_mcp_error', 'Pass "description": one line saying what this memory is about, used later to decide whether it is relevant.' );
			}
			if ( '' === $record['content'] ) {
				return new WP_Error( 'uich_mcp_error', 'Pass "content": the memory itself. For feedback and project memories add a "Why:" line and a "How to apply:" line.' );
			}
			if ( self::length( $record['description'] ) > self::DESCRIPTION_MAX ) {
				return new WP_Error( 'uich_mcp_memory_too_long', sprintf( 'A description is one line of at most %d characters; this is %d.', self::DESCRIPTION_MAX, self::length( $record['description'] ) ) );
			}
			if ( self::length( $record['content'] ) > self::CONTENT_MAX ) {
				return new WP_Error( 'uich_mcp_memory_too_long', sprintf( 'A memory body is at most %d characters; this is %d. Keep one fact per memory and split anything bigger.', self::CONTENT_MAX, self::length( $record['content'] ) ) );
			}

			if ( null !== $index ) {
				array_splice( $memory['memories'], $index, 1 );
			}
			$memory['memories'][] = $record;

			$moved  = array();
			$memory = self::move_out( $memory, $moved );
			$memory = self::save_memory( $memory );

			return array(
				'memory'                => $record,
				'created'               => null === $existing,
				'moved_out'             => $moved,
				'consolidate_suggested' => self::near_limit( $memory ),
				'usage'                 => self::usage( $memory ),
			);
		}

		/**
		 * Read memories in full.
		 *
		 * @param array $names Memory names; empty reads every one.
		 * @return array { memories, missing }
		 */
		public static function read_memory( $names ) {
			$memory = self::get_memory();

			$names = array_filter( array_map( array( __CLASS__, 'slug' ), (array) $names ) );
			if ( ! $names ) {
				return array(
					'memories' => $memory['memories'],
					'missing'  => array(),
				);
			}

			$found   = array();
			$missing = array();
			foreach ( $names as $name ) {
				$index = self::find( $memory['memories'], $name );
				if ( null === $index ) {
					$missing[] = $name;
				} else {
					$found[] = $memory['memories'][ $index ];
				}
			}

			return array(
				'memories' => $found,
				'missing'  => $missing,
			);
		}

		/**
		 * Remove one memory by name.
		 *
		 * @param string $name Memory name.
		 * @return array The memory store.
		 */
		public static function delete_memory( $name ) {
			$memory = self::get_memory();
			$index  = self::find( $memory['memories'], self::slug( $name ) );

			if ( null !== $index ) {
				array_splice( $memory['memories'], $index, 1 );
			}

			return self::save_memory( $memory );
		}

		/**
		 * Forget everything. The on/off switch is kept.
		 *
		 * @return array The memory store.
		 */
		public static function clear_memory() {
			$memory             = self::get_memory();
			$memory['memories'] = array();
			$memory['digest']   = '';

			return self::save_memory( $memory );
		}

		/**
		 * How full memory is, for the dashboard meter and the AI.
		 *
		 * @param array|null $memory Memory store, or null for the stored one.
		 * @return array{memories:int, max:int, percent:int}
		 */
		public static function usage( $memory = null ) {
			$memory = null === $memory ? self::get_memory() : $memory;
			$count  = count( $memory['memories'] );

			return array(
				'memories' => $count,
				'max'      => self::MEMORY_MAX,
				'percent'  => (int) min( 100, round( 100 * $count / self::MEMORY_MAX ) ),
			);
		}

		/**
		 * Move the least recently updated memories to the digest until the store
		 * is within MEMORY_MAX. Only the description survives the move, so this is
		 * the hard floor: the AI is asked to consolidate well before it is reached.
		 *
		 * @param array $memory Memory store, newest update last.
		 * @param array $moved  Receives the names that were moved out.
		 * @return array
		 */
		private static function move_out( array $memory, array &$moved ) {
			$lines = array();

			while ( count( $memory['memories'] ) > self::MEMORY_MAX ) {
				$oldest  = array_shift( $memory['memories'] );
				$moved[] = $oldest['name'];
				$lines[] = '- ' . $oldest['name'] . ': ' . $oldest['description'];
			}

			if ( $lines ) {
				$digest = trim( $memory['digest'] . "\n" . implode( "\n", $lines ) );
				while ( self::length( $digest ) > self::DIGEST_MAX && false !== strpos( $digest, "\n" ) ) {
					$digest = substr( $digest, strpos( $digest, "\n" ) + 1 );
				}
				$memory['digest'] = self::cut( $digest, self::DIGEST_MAX );
			}

			return $memory;
		}

		/**
		 * Whether the AI should consolidate memory now.
		 *
		 * @param array $memory Memory store.
		 * @return bool
		 */
		private static function near_limit( array $memory ) {
			return count( $memory['memories'] ) >= self::MEMORY_MAX * self::COMPACT_AT || '' !== $memory['digest'];
		}

		/**
		 * Persist the memory store. Not autoloaded: it is read on MCP calls and
		 * the dashboard, never on a front-end page.
		 *
		 * @param array $memory Memory store.
		 * @return array
		 */
		private static function save_memory( array $memory ) {
			$memory = array(
				'memories' => array_values( $memory['memories'] ),
				'digest'   => (string) $memory['digest'],
			);

			update_option( self::MEMORY_OPTION, $memory, false );

			return $memory;
		}

		/**
		 * Position of a memory by name.
		 *
		 * @param array  $memories Records.
		 * @param string $name     Memory name.
		 * @return int|null
		 */
		private static function find( array $memories, $name ) {
			foreach ( $memories as $i => $record ) {
				if ( $record['name'] === $name ) {
					return $i;
				}
			}

			return null;
		}

		/* ── design.md ─────────────────────────────────────────────────────── */

		/**
		 * The site's design.md.
		 *
		 * @return array{markdown:string, updated:int} An empty markdown string while none exists.
		 */
		public static function get_design_md() {
			$stored = get_option( self::DESIGN_MD_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			return array(
				'markdown' => isset( $stored['markdown'] ) ? (string) $stored['markdown'] : '',
				'updated'  => isset( $stored['updated'] ) ? (int) $stored['updated'] : 0,
			);
		}

		/**
		 * Replace the site's design.md. Stored as written - it is Markdown and may
		 * quote HTML or CSS - and only ever printed escaped.
		 *
		 * @param mixed $markdown The complete document.
		 * @return array|WP_Error The stored design.md.
		 */
		public static function set_design_md( $markdown ) {
			$markdown = is_string( $markdown ) ? wp_check_invalid_utf8( $markdown ) : '';
			$markdown = trim( str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $markdown ) );

			if ( '' === $markdown ) {
				return new WP_Error( 'uich_mcp_error', 'Pass "design_md": the complete design.md. It cannot be emptied.' );
			}

			$doc = array(
				'markdown' => $markdown,
				'updated'  => time(),
			);
			update_option( self::DESIGN_MD_OPTION, $doc, false );

			return $doc;
		}

		/**
		 * The design.md actions of uichemy-composer/memory.
		 *
		 * @return array<int,array>
		 */
		public static function design_md_actions() {
			return array(
				array(
					'name'                     => 'read-design-md',
					'description'              => 'Read the site\'s design.md - the global design decisions everything built here follows. The briefing does not include it: read it once per session before you write HTML, CSS or JS (and again after a context compaction). "exists" is false on a site that has none yet, and the answer then carries the template to fill.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => new stdClass(),
						'required'   => array(),
					),
				),
				array(
					'name'                     => 'write-design-md',
					'description'              => 'Create or REPLACE the site\'s design.md - its global design decisions (colours, typography, buttons, radius and shadows, spacing and layout, shared components) - with the complete document. Create it the first time you build a page here (read-design-md returns the template while none exists). Once it exists, ask the user before changing it, and only for a site-wide decision such as a colour, a type style, a button style or a border radius; not for a change on one page. Keep the values themselves in #uichemy-globals (uichemy-composer/design-system). Always send the whole file, starting from the current one.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'design_md' => array(
								'type'        => 'string',
								'description' => 'The complete design.md, in Markdown.',
							),
						),
						'required'   => array( 'design_md' ),
					),
				),
			);
		}

		/**
		 * Run a design.md action of uichemy-composer/memory.
		 *
		 * @param string $action Action, underscored.
		 * @param array  $params Action parameters.
		 * @return array|WP_Error|null Null when the action is not one of these.
		 */
		public static function execute_design_md( $action, $params ) {
			if ( 'read_design_md' === $action ) {
				$doc = self::get_design_md();
				$out = array(
					'success'   => true,
					'exists'    => '' !== $doc['markdown'],
					'design_md' => $doc['markdown'],
					'chars'     => self::length( $doc['markdown'] ),
				);

				// No design.md yet: hand over how to write one, so creating it
				// needs no separate skill.
				if ( '' === $doc['markdown'] ) {
					$out['template'] = self::design_md_template();
				}

				return $out;
			}

			if ( 'write_design_md' === $action ) {
				$doc = self::set_design_md( isset( $params['design_md'] ) ? $params['design_md'] : '' );

				return is_wp_error( $doc ) ? $doc : array(
					'success' => true,
					'chars'   => self::length( $doc['markdown'] ),
				);
			}

			return null;
		}

		/**
		 * How to write a design.md and the headings to fill, returned by
		 * read-design-md while the site has none.
		 *
		 * @return string Markdown.
		 */
		private static function design_md_template() {
			return <<<'TEMPLATE'
Write the site's design.md: the record of its globals that every later page follows. Fill every heading with concrete values (token names, numbers, rules), then save the whole file with `uichemy-composer/memory` `write-design-md`.

Where the values come from:
- A supplied design (Figma, code, a screenshot) or existing pages: record exactly what is there; read the tokens with `uichemy-composer/design-system` `get`. Do not invent or "improve".
- Designing from a brief: the user's words first, then the site context and memory, then what the site already has. Decide three tone words from the subject (not "modern, clean, sleek") and one signature idea only this site has. Do not reuse a look from another project. Avoid the generated look unless asked: hard-coded values instead of #uichemy-globals tokens, a default template stack, every section centred, gradient-heavy or glassy surfaces, the same radius and shadow on every box, emoji as icons, entrance animation on everything, placeholder copy or made-up figures.

Make sure every token named exists in the design system (`get`, then `set` or `patch`) before saving.

```markdown

TEMPLATE
				. self::design_md_skeleton() . "\n```";
		}

		/**
		 * The empty design.md: its headings with placeholder rows. Inside the
		 * template above, and what Settings > MCP starts from when the owner adds one.
		 *
		 * @return string Markdown.
		 */
		private static function design_md_skeleton() {
			return <<<'SKELETON'
# <Site name> - design.md

## Colours
| Token | Value | Used for |
|---|---|---|
| --color-bg | #... | page background |
| --color-ink | #... | body text |
| --color-accent | #... | primary buttons and key highlights |
Contrast: body text at least 4.5:1, large text and UI at least 3:1.

## Typography
- Families and weights: ...
| Role | Class | Size (mobile -> desktop) | Weight | Line height | Letter spacing |
|---|---|---|---|---|---|
| H1 | .text-h1 | clamp(...) | ... | ... | ... |
| Body | .text-body | ... | ... | ... | ... |

## Buttons
| Variant | Background | Text | Border | Radius | Padding | Hover | Focus | Disabled |
|---|---|---|---|---|---|---|---|---|
| Primary | ... | ... | ... | var(--radius-button) | ... | ... | ... | ... |
| Secondary | ... | ... | ... | ... | ... | ... | ... | ... |

## Radius and shadows
- Radius tokens, borders, shadows: one system for every element.

## Spacing and layout
- Content width, grid, spacing scale, section padding and rhythm.

## Components
- Cards, forms, navigation, footer: the rules each follows.

## Imagery and icons
## Motion
## Voice
## Direction
- Tone words, reference world, signature idea (leave out for a supplied design if it shows none).
SKELETON;
		}

		/**
		 * The briefing's design.md section, the last block of the briefing: while it
		 * exists, the should-follow rules that send the agent to read-design-md (the
		 * document itself stays out of the briefing); otherwise the should-create
		 * instruction for the first page built. Both point the agent at the
		 * #uichemy-globals block, where the values live and the user edits them.
		 *
		 * @return string Markdown.
		 */
		private static function design_md_section() {
			$doc = self::get_design_md();
			$ds  = '`' . self::ABILITY_NAME . '` `write-design-md`';
			// Where the values themselves live: the #uichemy-globals block, which the
			// user sees and edits in the UiChemy Builder's Globals panel.
			$globals = "Keep the values in the site's global CSS, the `#uichemy-globals` block (`uichemy-composer/design-system`: `get` first, then `patch` or `set`): colours, fonts, spacing and radius as CSS variables, and repeated styles as design-system classes (`.text-*` for type, `.pr-*` for layout). Use those variables and classes in section CSS instead of hard-coded values. The user can see and change them in the UiChemy Builder's Globals panel, and every page follows.";

			if ( '' === $doc['markdown'] ) {
				return "## Site design (design.md) - should create\n\n"
					. "This site has no design.md yet. When you build a page, a template or a whole site here - from a brief, a Figma design or code - you should also create design.md in the same task: call `" . self::ABILITY_NAME . "` `read-design-md` - with no design.md yet it returns the template and how to fill it - then save it with " . $ds . ". It records the site's globals: colours, typography, buttons, border radius and shadows, spacing and layout. Small edits that build nothing new do not need it.\n\n"
					. $globals;
			}

			// A pointer, not the document: design.md can be long, and the briefing is
			// paid on every session. The agent reads it once with read-design-md.
			return "## Site design (design.md) - should follow\n\n"
				. "This site has a design.md: its global design decisions - colours, typography, buttons, border radius and shadows, spacing and layout, shared components. It is not included here.\n\n"
				. "- Before you write HTML, CSS or JS for anything a visitor sees, read it with `" . self::ABILITY_NAME . "` `read-design-md` (once per session, and again after a context compaction) and build from it: its colours, type styles, button styles, radius and spacing. Only what the user asks in this chat overrides it.\n"
				. '- ' . $globals . "\n"
				. "- When the user brings their own design to import as it is - an HTML file, code or a page already designed - keep it exactly as supplied. Do not restyle it to match design.md.\n"
				. "- Ask the user before you change design.md. When a site-wide decision changes - a colour, a font or type size, a button style, a border radius (say 30px to 20px), spacing, the layout grid, a shared component - say what you would update and wait for a yes. Then change the `#uichemy-globals` values and design.md together, sending the whole file with " . $ds . ".\n"
				. "- Do not change it for something that stays on one page or one section.";
		}

		/**
		 * The nudge added to a design-system write: the tokens just changed, so
		 * design.md may need to follow (or be created).
		 *
		 * @return string
		 */
		public static function design_md_reminder() {
			$doc = self::get_design_md();

			return '' === $doc['markdown']
				? 'This site has no design.md yet. If you are building pages, you should create it in this task (' . self::ABILITY_NAME . ' read-design-md gives the template, then write-design-md).'
				: 'The design system changed. If this is a site-wide decision (a colour, type style, button style, radius, spacing), design.md should match it: ask the user, then update it with ' . self::ABILITY_NAME . ' write-design-md (whole file).';
		}

		/* ── Briefing ─────────────────────────────────────────────────────── */

		/**
		 * The block appended to the end of the MCP briefing: the context, then
		 * memory, then design.md last.
		 *
		 * @return string Markdown, or '' when there is nothing to add.
		 */
		public static function briefing_section() {
			$parts = array();
			$site  = trim( self::get_context( 'site' ) );
			$admin = trim( self::get_context( 'user' ) );

			if ( '' !== $site || '' !== $admin ) {
				$user = wp_get_current_user();
				$who  = ( $user && $user->exists() ) ? $user->display_name : '';

				$lines = array(
					"## Site Owner's instructions & context",
					'',
					'Follow these on every request, over the general guidance above; only what the user asks in this chat outranks them. **Site** applies to everyone on this site, **Admin** only to the admin this session runs as. If they conflict, Site wins for what ends up on the site (content, design, brand) and Admin wins for how you work with this admin (language, tone, how much you explain). You cannot edit either; to change them, point the user to UiChemy > Settings > MCP.',
				);

				if ( '' !== $site ) {
					array_push( $lines, '', '### Site instructions & context', '', $site );
				}
				if ( '' !== $admin ) {
					array_push( $lines, '', '' !== $who ? '### Admin (' . $who . ') instructions & context' : '### Admin instructions & context', '', $admin );
				}

				$parts[] = implode( "\n", $lines );
			}

			// Memory is always on.
			$parts[] = self::memory_section( self::get_memory() );

			// Last, so it is the final word of the briefing: the site's design.md
			// (follow it) or, while there is none, the instruction to create it.
			$parts[] = self::design_md_section();

			return implode( "\n\n", $parts );
		}

		/**
		 * The Memory section: how to keep memory, the index,
		 * one line per memory (bodies are read on demand with read-memory).
		 *
		 * @param array $memory Memory store.
		 * @return string Markdown.
		 */
		private static function memory_section( array $memory ) {
			$guide = <<<'GUIDE'
## Memory

This site keeps a persistent memory across AI sessions: one memory per fact, each with a short kebab-case `name`, a one-line `description`, a `type` and a body. Memories are background context written by earlier sessions and reflect what was true when written: if one names a page, field, setting or file, check it still exists before you rely on it.

Types:
- `user` - facts about the person you are working with: their job, how technical they are, how they like to be helped.
- `feedback` - guidance the user has given on how to work here, both corrections and approaches they confirmed; include why.
- `project` - what is in progress here and why: goals, deadlines, decisions and limits you cannot read from the site itself. Write every date as a full date.
- `reference` - where something lives outside this site: a link, a dashboard, a shared folder, a document, an account named without its password.

Save with `uichemy-composer/memory` `save-memory`: `name`, `description` (used later to judge relevance), `type` and `content`. For `feedback` and `project`, write the fact, then a **Why:** line and a **How to apply:** line. Link related memories by writing `[[their-name]]`. Before saving, look for a memory below that already covers it and save under THAT name to update it rather than adding a duplicate. Delete a memory that turns out to be wrong with `delete-memory`. Do not save what the site already records (pages, fields, settings, code), what only matters to this task, or secrets: passwords, keys, tokens, personal data. When you save one, say so in a few words.

When a save answers `consolidate_suggested: true`, tidy memory before you carry on: merge duplicates into one (save the merged memory, delete the others), fix stale facts, delete what is no longer true. At the limit the least recently updated memory is moved to "Earlier" automatically, and only its description is kept.
GUIDE;

			// Index only: one line per memory. The bodies stay out of the briefing to
			// keep it short; the agent reads the ones it needs with read-memory.
			$lines = array(
				$guide,
				'',
				'### Index',
				'',
				'One line per memory; the full memory is not included here. When a description fits the task, read that memory with `' . self::ABILITY_NAME . '` `read-memory` (`names`) before you rely on it.',
				'',
			);

			if ( $memory['memories'] ) {
				foreach ( $memory['memories'] as $record ) {
					$lines[] = sprintf( '- `%s` (%s) - %s', $record['name'], $record['type'], $record['description'] );
				}
			} else {
				$lines[] = '(no memories yet)';
			}

			if ( '' !== $memory['digest'] ) {
				$lines[] = '';
				$lines[] = '### Earlier (moved out at the limit, descriptions only)';
				$lines[] = '';
				$lines[] = $memory['digest'];
			}

			return implode( "\n", $lines );
		}

		/* ── MCP ability (uichemy-composer/memory) ─────────────────────────── */

		/**
		 * Register uichemy-composer/memory: memory and the site's design.md, one door
		 * with five actions. The context is not here: the owner edits it in Settings.
		 */
		public static function register_ability() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY_NAME ) ) {
				return;
			}

			$actions  = self::ability_actions();
			$contract = class_exists( 'UiChemy_Abilities' ) ? UiChemy_Abilities::contract() . ' ' : '';

			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => 'UiChemy Builder: Memory',
					'description'         => 'What AI sessions on this site remember: the persistent memory (facts saved across sessions) and the site\'s design.md (its global design decisions).',
					'category'            => class_exists( 'UiChemy_Abilities' ) ? UiChemy_Abilities::CATEGORY : 'uichemy-composer',
					'input_schema'        => array(
						'type'        => 'object',
						'description' => $contract . 'MEMORY (one named, typed memory per fact): "read-memory" reads memories in full, "save-memory" creates one or updates the one with the same name, "delete-memory" removes one that is wrong, stale or merged. DESIGN.MD: "read-design-md" reads the site\'s design.md, "write-design-md" creates or replaces it (whole file) - create it with the first page you build; once it exists, ask the user before changing it. The memory index and the site owner\'s instructions & context are at the end of the briefing (the owner edits them in Settings > MCP); design.md is read with read-design-md.',
						'properties'  => array(
							'action'            => array(
								'type'        => 'string',
								'enum'        => wp_list_pluck( $actions, 'name' ),
								'description' => 'Which operation to run.',
							),
							'action_parameters' => array(
								'type'        => 'object',
								'description' => 'The parameters for this action - check the "actions" key in meta for the accurate schema.',
							),
						),
						'required'    => array( 'action' ),
					),
					'execute_callback'    => array( __CLASS__, 'execute_ability' ),
					'permission_callback' => class_exists( 'UiChemy_Abilities' ) ? array( 'UiChemy_Abilities', 'permission_check' ) : static function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'title'       => 'UiChemy Builder: Memory',
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'actions'      => $actions,
					),
				)
			);
		}

		/**
		 * The five action specs of uichemy-composer/memory.
		 *
		 * @return array<int,array>
		 */
		private static function ability_actions() {
			$actions = array(
				array(
					'name'                     => 'read-memory',
					'description'              => 'Read memories in full by name. The briefing lists only a one-line index: read a memory here before you rely on it.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'names' => array(
								'type'        => 'array',
								'description' => 'Names from the Memory index. Omit to read every memory.',
								'items'       => array( 'type' => 'string' ),
							),
						),
						'required'   => array(),
					),
				),
				array(
					'name'                     => 'save-memory',
					'description'              => 'Create a memory, or update the one with the same name (fields left out keep their value). One fact per memory. Check the Memory index in the briefing first and reuse a name rather than duplicating. When the answer says consolidate_suggested: true, merge and prune memory before you carry on.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array(
								'type'        => 'string',
								'description' => 'Lowercase words joined by dashes, like "button-style". At most ' . self::NAME_MAX . ' characters.',
							),
							'description' => array(
								'type'        => 'string',
								'description' => 'One line saying what it is about, used later to judge relevance. At most ' . self::DESCRIPTION_MAX . ' characters.',
							),
							'type'        => array(
								'type'        => 'string',
								'enum'        => self::TYPES,
								'description' => 'user: facts about the person you work with. feedback: a working rule they set, with its reason. project: work in progress, goals and decisions the site cannot show. reference: where something lives outside the site.',
							),
							'content'     => array(
								'type'        => 'string',
								'description' => 'The memory. For feedback and project: the fact, then a "Why:" line and a "How to apply:" line. Link related memories as [[their-name]]. At most ' . self::CONTENT_MAX . ' characters.',
							),
						),
						'required'   => array( 'name' ),
					),
				),
				array(
					'name'                     => 'delete-memory',
					'description'              => 'Delete a memory that is wrong, stale, or merged into another. Use it as well when the user wants something removed from memory.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'The memory\'s name.',
							),
						),
						'required'   => array( 'name' ),
					),
				),
			);

			// The site's design.md: read it, and write the whole file.
			return array_merge( $actions, self::design_md_actions() );
		}

		/**
		 * Execute uichemy-composer/memory: { action, action_parameters }, with the
		 * parameters also accepted flat beside `action`, like every other ability.
		 *
		 * @param array $arguments Ability input.
		 * @return array|WP_Error
		 */
		public static function execute_ability( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$action    = isset( $arguments['action'] ) ? sanitize_key( str_replace( '-', '_', (string) $arguments['action'] ) ) : '';
			$params    = isset( $arguments['action_parameters'] ) && is_array( $arguments['action_parameters'] ) ? $arguments['action_parameters'] : array();
			$flat      = $arguments;
			unset( $flat['action'], $flat['action_parameters'] );
			$params = array_merge( $flat, $params );

			$design = self::execute_design_md( $action, $params );
			if ( null !== $design ) {
				return $design;
			}

			switch ( $action ) {
				case 'save_memory':
					$result = self::save_memory_record( $params );
					return is_wp_error( $result ) ? $result : array_merge( array( 'success' => true ), $result );

				case 'read_memory':
					$names  = isset( $params['names'] ) ? (array) $params['names'] : ( isset( $params['name'] ) ? array( $params['name'] ) : array() );
					$result = self::read_memory( $names );
					return is_wp_error( $result ) ? $result : array_merge( array( 'success' => true ), $result );

				case 'delete_memory':
					$name = self::slug( isset( $params['name'] ) ? $params['name'] : '' );
					if ( '' === $name || null === self::find( self::get_memory()['memories'], $name ) ) {
						return new WP_Error( 'uich_mcp_error', 'No memory named "' . $name . '". Names are listed in the Memory index of the briefing.' );
					}
					$memory = self::delete_memory( $name );
					return array(
						'success' => true,
						'deleted' => $name,
						'usage'   => self::usage( $memory ),
					);
			}

			$valid = 'read-memory, save-memory, delete-memory, read-design-md, write-design-md';

			return new WP_Error(
				'uich_mcp_error',
				'' === $action ? 'Missing "action". Valid: ' . $valid . '.' : 'Unknown action "' . $action . '". Valid: ' . $valid . '.'
			);
		}

		/* ── Dashboard ────────────────────────────────────────────────────── */

		/**
		 * What Settings > MCP boots with.
		 *
		 * @return array
		 */
		public static function dashboard_payload() {
			$memory = self::get_memory();

			return array(
				'context'     => self::get_context( 'site' ),
				// The signed-in admin's own layer; every admin sees only theirs.
				'userContext' => self::get_context( 'user' ),
				'contextMax'  => self::CONTEXT_MAX,
				// The memory editor's field limits.
				'memoryLimits' => array(
					'name'        => self::NAME_MAX,
					'description' => self::DESCRIPTION_MAX,
					'content'     => self::CONTENT_MAX,
				),
				// Null while none exists; the dashboard then shows an empty box.
				'designMd'    => self::design_md_payload(),
				'memory'     => array(
					'memories' => $memory['memories'],
					'digest'   => $memory['digest'],
					'usage'    => self::usage( $memory ),
				),
			);
		}

		/**
		 * design.md for the dashboard, or null while the site has none.
		 *
		 * @return array{markdown:string, updated:int}|null
		 */
		private static function design_md_payload() {
			$doc = self::get_design_md();

			return '' === $doc['markdown'] ? null : $doc;
		}

		/**
		 * The dashboard's `mcp_*` ajax types, on the shared `uichemy_dashboard`
		 * endpoint (nonce and manage_options are checked before this runs).
		 *
		 * @param string $type Ajax type.
		 */
		public static function ajax( $type ) {
			if ( 0 !== strpos( (string) $type, 'mcp_' ) ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by UiChemy_Admin_Menu::ajax() before this action fires.
			switch ( $type ) {
				case 'mcp_save_context':
					$text    = isset( $_POST['context'] ) ? (string) wp_unslash( $_POST['context'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_text() sanitizes.
					$scope   = isset( $_POST['scope'] ) && 'user' === $_POST['scope'] ? 'user' : 'site';
					$context = self::set_context( $text, $scope );
					if ( is_wp_error( $context ) ) {
						wp_send_json_error( array( 'message' => $context->get_error_message() ), 400 );
					}
					break;

				case 'mcp_save_memory':
					$fields = isset( $_POST['memory'] ) ? json_decode( wp_unslash( $_POST['memory'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_memory_record() sanitizes each field.
					$result = self::save_memory_record( is_array( $fields ) ? $fields : array(), true );
					if ( is_wp_error( $result ) ) {
						wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
					}
					break;

				case 'mcp_save_design_md':
					$doc = self::set_design_md( isset( $_POST['design_md'] ) ? (string) wp_unslash( $_POST['design_md'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Markdown stored as written and only printed escaped, like the AI's write-design-md.
					if ( is_wp_error( $doc ) ) {
						wp_send_json_error( array( 'message' => $doc->get_error_message() ), 400 );
					}
					break;

				case 'mcp_delete_memory':
					self::delete_memory( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
					break;

				case 'mcp_clear_memory':
					self::clear_memory();
					break;

				default:
					return;
			}
			// phpcs:enable

			wp_send_json_success( self::dashboard_payload() );
		}

		/* ── Text helpers ─────────────────────────────────────────────────── */

		/**
		 * Multi-line plain text: tags stripped, line endings unified, blank runs
		 * collapsed to one blank line.
		 *
		 * @param mixed $text Input.
		 * @return string
		 */
		private static function clean_text( $text ) {
			$text = sanitize_textarea_field( is_scalar( $text ) ? (string) $text : '' );
			$text = preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $text ) );

			return trim( (string) $text );
		}

		/**
		 * One line of plain text.
		 *
		 * @param mixed $text Input.
		 * @return string
		 */
		private static function clean_line( $text ) {
			return trim( (string) preg_replace( '/\s+/', ' ', self::clean_text( $text ) ) );
		}

		/**
		 * A memory name: lower-case kebab-case, at most NAME_MAX characters.
		 *
		 * @param mixed $name Input.
		 * @return string
		 */
		private static function slug( $name ) {
			$slug = sanitize_title( is_scalar( $name ) ? (string) $name : '' );

			return trim( substr( $slug, 0, self::NAME_MAX ), '-' );
		}

		/**
		 * Character length, multibyte-safe.
		 *
		 * @param string $text Input.
		 * @return int
		 */
		private static function length( $text ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
		}

		/**
		 * The first $max characters, multibyte-safe.
		 *
		 * @param string $text Input.
		 * @param int    $max  Length.
		 * @return string
		 */
		private static function cut( $text, $max ) {
			return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $max ) : substr( (string) $text, 0, $max );
		}
	}
}
