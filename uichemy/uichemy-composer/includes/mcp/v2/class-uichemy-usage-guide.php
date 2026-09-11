<?php
/**
 * UiChemy Usage Guide
 *
 * Contributes UiChemy's briefing to the UiChemy MCP v2 discovery document
 * (the `usage_guide` key returned by `uichemy/discover-abilities`), and opts
 * the `uichemy` namespace into that endpoint's ability list.
 *
 * The briefing is the text a user would otherwise have to type into every
 * session: what UiChemy is, which entry point matches which kind of request,
 * and the build order that the individual tool descriptions can only hint at.
 * Delivering it once at discovery is what lets the tool descriptions
 * themselves stay short.
 *
 * A no-op when UiChemy is not active: the filters simply never fire.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Usage_Guide' ) ) {

	/**
	 * Supplies UiChemy's namespace, usage-guide block and skill index.
	 */
	class UiChemy_Usage_Guide {

		/**
		 * Ability namespace UiChemy registers under.
		 */
		const NAMESPACE_SLUG = 'uichemy-composer';

		/**
		 * Ability that serves the briefing.
		 *
		 * The briefing used to be reachable only through UiChemy's own gateway,
		 * which returns it as the `instructions` key of `discover-abilities`. Any
		 * OTHER MCP gateway on the site — a third-party MCP plugin, core's
		 * mcp-adapter default server, anything future — serves our abilities
		 * automatically (they are registered with meta.mcp.public), but knows
		 * nothing about `uichemy_mcp_usage_guide`, so on those hosts the model got
		 * the tools and none of the sequence.
		 *
		 * Serving the same text as an ordinary ability fixes every host at once:
		 * an ability IS the portable unit, so no gateway needs UiChemy-specific
		 * code to expose it.
		 */
		const ABILITY_NAME = 'uichemy-composer/instructions';

		/**
		 * Hook onto UiChemy's gateway filters.
		 */
		public static function init() {
			add_filter( 'uichemy_mcp_discover_namespaces', array( __CLASS__, 'add_namespace' ) );
			add_filter( 'uichemy_mcp_discover_exclude', array( __CLASS__, 'add_exclusions' ) );
			add_filter( 'uichemy_mcp_usage_guide', array( __CLASS__, 'add_guide' ) );
			add_filter( 'uichemy_mcp_skills', array( __CLASS__, 'add_skills' ) );

			if ( function_exists( 'wp_register_ability' ) ) {
				// Priority 10 so UiChemy_Abilities (priority 5) has registered the
				// `uichemy-composer` category first.
				add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
			}
		}

		/**
		 * Register the briefing as an ability, so every gateway serves it.
		 */
		public static function register_ability() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY_NAME ) ) {
				return;
			}

			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => 'UiChemy Builder: Instructions',
					// Paid on every discovery call on every host, so it says only
					// what a model needs to decide to call this FIRST.
					'description'         => 'START HERE before any other uichemy-composer call. Returns this site\'s UiChemy briefing: what the UiChemy widget is and what one section means, which entry point matches the request (a Figma URL, code the user already has, or only a description), the exact two-level call envelope, the required order - read the matching skill, then uichemy-composer/describe-site, then uichemy-composer/dynamic "list-fields" before any dynamic binding, then uichemy-composer/audit before you call a build finished - and the design-system rules. The other uichemy-composer descriptions are deliberately short and do NOT teach that sequence, so a build that skips this one gets the order wrong.',
					'category'            => self::NAMESPACE_SLUG,
					'input_schema'        => array(
						'type'        => 'object',
						'description' => 'No parameters. Call it with an empty object.',
						'properties'  => array(),
						'required'    => array(),
					),
					'execute_callback'    => array( __CLASS__, 'execute' ),
					'permission_callback' => array( 'UiChemy_Abilities', 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'title'       => 'UiChemy Builder: Instructions',
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		}

		/**
		 * Serve the briefing.
		 *
		 * Same markdown, same embedded-resource shape as UiChemy_Skills::execute,
		 * so a client that can read a skill can read this without special casing.
		 *
		 * @param array $arguments Unused.
		 * @return array|WP_Error
		 */
		public static function execute( $arguments = array() ) {
			$instructions = self::instructions();

			if ( '' === $instructions ) {
				return new WP_Error(
					'uichemy_no_instructions',
					'No UiChemy instructions are registered on this site.'
				);
			}

			return array(
				'type'     => 'resource',
				'uri'      => 'uichemy://instructions',
				'mimeType' => 'text/markdown',
				'text'     => $instructions,
			);
		}

		/**
		 * The assembled briefing: every registered block, joined.
		 *
		 * The single source for both surfaces — this ability and the `instructions`
		 * key of UiChemy's own `discover-abilities`. One assembler, so a host can
		 * never be served a different briefing than the gateway is.
		 *
		 * @return string Markdown, or '' when nothing is registered.
		 */
		public static function instructions( $with_inventory = true ) {
			/**
			 * Filters the markdown blocks that make up the MCP discovery briefing.
			 *
			 * @since 5.0.2
			 *
			 * @param array<int,string> $blocks Markdown blocks, joined with a blank line.
			 */
			// Seeded EMPTY, not with add_guide(): init() has already hooked
			// add_guide() onto this filter, so passing its output in as the seed
			// would emit the briefing twice over.
			$blocks = apply_filters( 'uichemy_mcp_usage_guide', array() );

			if ( ! is_array( $blocks ) ) {
				return '';
			}

			$blocks = array_filter(
				array_map( 'trim', array_filter( $blocks, 'is_string' ) ),
				static function ( $block ) {
					return '' !== $block;
				}
			);

			$document = implode( "\n\n", $blocks );

			// Appended here rather than inside guide() on purpose: a sibling plugin
			// may serve its own briefing block in place of ours (see the Pro build),
			// and the inventory has to survive that — it describes the site, not the
			// briefing.
			if ( $with_inventory ) {
				$inventory = self::inventory_section();

				if ( '' !== $inventory ) {
					$document = ( '' === $document ) ? $inventory : $document . "\n\n" . $inventory;
				}
			}

			return $document;
		}

		/**
		 * The ability + action map, generated from the live registry.
		 *
		 * Why this travels with the briefing instead of being left to the host:
		 * gateways disagree about how much of an ability they reveal at discovery.
		 * Ours lists each ability's action names. Others copy only three keys per
		 * row — name, label, description — dropping `meta`, where `actions` lives,
		 * so on such a host a model has to call get-ability-info once per ability
		 * just to learn what actions exist. Carrying the map here makes one call
		 * answer it on every host, however thin that host's own discovery is.
		 *
		 * Generated, never hand-maintained: a renamed or added action shows up here
		 * the moment it is registered, so this cannot drift the way a written list
		 * would.
		 *
		 * @return string Markdown, or '' when nothing is registered.
		 */
		public static function inventory_section() {
			if ( ! function_exists( 'wp_get_abilities' ) ) {
				return '';
			}

			// The briefing does not list itself — whoever is reading this already
			// has it.
			$skip = self::hidden_from_own_gateway();
			$rows = array();

			foreach ( wp_get_abilities() as $ability ) {
				$name = $ability->get_name();

				if ( 0 !== strpos( (string) $name, self::NAMESPACE_SLUG . '/' ) ) {
					continue;
				}

				$meta = $ability->get_meta();

				// Same three gates the catalogues use, so this can never advertise
				// something a gateway will refuse to run.
				if ( empty( $meta['mcp']['public'] ) ) {
					continue;
				}
				if ( ( isset( $meta['mcp']['type'] ) ? $meta['mcp']['type'] : 'tool' ) !== 'tool' ) {
					continue;
				}
				if ( in_array( (string) $name, $skip, true ) ) {
					continue;
				}

				$actions = array();

				if ( ! empty( $meta['actions'] ) && is_array( $meta['actions'] ) ) {
					foreach ( $meta['actions'] as $action ) {
						if ( is_array( $action ) && isset( $action['name'] ) ) {
							$actions[] = (string) $action['name'];
						} elseif ( is_string( $action ) ) {
							$actions[] = $action;
						}
					}
				}

				$rows[ (string) $name ] = $actions;
			}

			if ( empty( $rows ) ) {
				return '';
			}

			ksort( $rows );

			$lines = array(
				'## Abilities on this site',
				'',
				'Generated from what is registered right now. Some MCP hosts show this in their own discovery and some do not - several copy only an ability\'s name, label and description - so it travels with the briefing and is correct either way.',
				'',
				'Each line is one ability and the actions it accepts. Pass one of those as "action" inside "parameters", following the envelope rules above. What this list deliberately leaves out is the parameter schema for each action: call `get-ability-info` on the ability once you have picked an action, and read the schema for that action rather than guessing parameter names.',
				'',
			);

			foreach ( $rows as $name => $actions ) {
				$lines[] = empty( $actions )
					? sprintf( '- `%s` - NOT action routed: its parameters go straight into "parameters", with no "action" and no "action_parameters".', $name )
					: sprintf( '- `%s` - %s', $name, implode( ', ', $actions ) );
			}

			return implode( "\n", $lines );
		}

		/**
		 * Opt the `uichemy` namespace into v2 discovery.
		 *
		 * @param array<int,string> $namespaces Existing namespace slugs.
		 * @return array<int,string>
		 */
		public static function add_namespace( $namespaces ) {
			if ( ! is_array( $namespaces ) ) {
				$namespaces = array();
			}

			$namespaces[] = self::NAMESPACE_SLUG;

			return $namespaces;
		}

		/**
		 * Abilities held back from UiChemy's OWN v2 surface.
		 *
		 * Two tiers, deliberately kept apart:
		 *
		 * - hidden_everywhere() is a real retirement — no gateway should offer it.
		 *   It drives meta.mcp.public, so it applies on every host.
		 * - hidden_from_own_gateway() is redundancy, not retirement. Our own
		 *   discover-abilities already returns the briefing in its `instructions`
		 *   key, so listing the ability that returns the same text would be a
		 *   second door to one room. Other gateways have no `instructions` key,
		 *   which is the entire reason that ability exists — so it stays public
		 *   there.
		 *
		 * Net: 15 abilities on our own gateway, 16 on every other MCP gateway.
		 *
		 * @param array<int,string> $excluded Existing exclusions.
		 * @return array<int,string>
		 */
		public static function add_exclusions( $excluded ) {
			if ( ! is_array( $excluded ) ) {
				$excluded = array();
			}

			return array_merge( $excluded, self::hidden_everywhere(), self::hidden_from_own_gateway() );
		}

		/**
		 * Retired from MCP on EVERY host.
		 *
		 * convert / convert-code / start-site-build are served as skills through
		 * uichemy-composer/read-skill now. They remain registered abilities so the
		 * v1 endpoint and the Figma relay keep working unchanged — both reach them
		 * through UiChemy_Composer_MCP_Server::get_tool_definitions(), which does
		 * not consult meta.mcp.public.
		 *
		 * Read by UiChemy_Abilities::is_mcp_public().
		 *
		 * @return array<int,string>
		 */
		public static function hidden_everywhere() {
			return array(
				'uichemy-composer/convert',
				'uichemy-composer/convert-code',
				'uichemy-composer/start-site-build',
			);
		}

		/**
		 * Hidden from our own catalogue only — still public, still executable.
		 *
		 * Hidden, not unserved: the briefing tells the model it can re-read itself
		 * with this ability after a compaction, and that has to work on this
		 * endpoint too. UiChemy_MCP_Server_V2 keeps it resolvable for
		 * get-ability-info and execute-ability while leaving it out of the list.
		 *
		 * @return array<int,string>
		 */
		public static function hidden_from_own_gateway() {
			return array( self::ABILITY_NAME );
		}

		/**
		 * Append UiChemy's briefing block.
		 *
		 * @param array<int,string> $blocks Existing markdown blocks.
		 * @return array<int,string>
		 */
		public static function add_guide( $blocks ) {
			if ( ! is_array( $blocks ) ) {
				$blocks = array();
			}

			$blocks[] = self::guide();

			return $blocks;
		}

		/**
		 * Advertise UiChemy's skills.
		 *
		 * @param array<int,array> $skills Existing skill records.
		 * @return array<int,array>
		 */
		public static function add_skills( $skills ) {
			if ( ! is_array( $skills ) ) {
				$skills = array();
			}

			if ( ! class_exists( 'UiChemy_Skills' ) ) {
				return $skills;
			}

			return array_merge( $skills, UiChemy_Skills::index() );
		}
		/**
		 * The briefing.
		 *
		 * The Skills section is generated from UiChemy_Skills so the list can
		 * never drift from what read-skill will actually serve.
		 *
		 * @return string Markdown.
		 */
		private static function guide() {
			$guide = <<<'GUIDE'
# UiChemy: build real WordPress pages from figma designs, code, or a brief

UiChemy is powered by the UiChemy plugin, which provides the Composer Elementor widget. The widget holds any HTML, CSS and JS, and gives the user a friendly editing interface for it directly inside the Elementor editor.

One Composer widget is meant to hold one section of a webpage, and the `uichemy/*` abilities are how you create and edit them.
	- **Conversion is meant to take place with per section code only**, because that is the only way the Composer editor can hand editability back to the user.

Apart from widget scoped code:
- UiChemy can also add, update and read HTML placed before the `</head>` and `</body>` of a single page, and site wide code through `uichemy-composer/platform`.
- Images, video and fonts go to the WordPress media library through `uichemy-composer/media`, which returns a URL to reference from your markup.
- On a WooCommerce site, the cart, checkout and account pages are STYLED, never replaced: UiChemy refuses to put a theme-builder template over them because that breaks the purchase flow. Build the page around `uichemy-composer/dynamic` (`action: "add-tag"`) tags instead - `woo-cart`, `woo-checkout`, `woo-my-account`, `woo-order-tracking`, and `woo-notices`, which any custom cart or checkout layout must include or the customer never sees an error.

## Do these two things before you build anything

1. **Read the matching skill**: call `uichemy-composer/read-skill` with `name: "<skill-name>"` - for example `{ "name": "code-to-wordpress" }`. The skill is the build process - section planning, naming, ordering, the media flow, the verification steps. Nothing below teaches you that, and a build that skips it gets the sequence wrong. Match on the user's intent using the Skills list at the end of this briefing.
2. **Call `uichemy-composer/describe-site`.** Its `platform` block decides how you build (`header_footer_system` routes header and footer work; stop if `checks.elementor_active` is false), and the rest is the real content model - post types, taxonomies, and the field `metaKey`s any dynamic binding must use. Never guess a field name.

Then, the moment you are about to write a dynamic binding, call **`uichemy-composer/dynamic` with `action: "list-fields"`**. `describe-site` carries the site's CUSTOM fields; that carries the BUILT-IN tokens - `post.*`, `product.*` (WooCommerce), `user.*`, `term.*`, `image.*`, `site.*`, `request.*`. An unknown token renders EMPTY rather than failing, so a guessed accessor produces a page that looks built and is blank, with nothing in the output to tell you.

When the site needs a content type it does not have - "case studies", "team members", "properties" - the content MODEL comes before any markup. `uichemy-composer/cpt` with `action: "describe"` reports every post type and taxonomy, the plugin that registered it, and which field groups reach it; `register-post-type`, `register-taxonomy`, `register-field-group` and `add-fields` create them through ACF or JetEngine. UiChemy never registers a content type under its own storage, so with NEITHER plugin active it refuses and names the two ways forward, and with BOTH active it refuses until you pass `provider` - ask the user which plugin they want their model in rather than choosing. Field VALUES are `uichemy-composer/custom-fields`: `list` before you bind (an unknown field name renders empty rather than failing), `get` to read, `update` to write - batched, with `dry_run` and a revert token. Read `uichemy-composer/read-skill` `{ "name": "custom-fields-and-cpt" }` first: the storage rules differ between ACF and JetEngine in four places that all fail silently.

When the ask is "what is this site missing", call **`uichemy-composer/theme-builder` with `action: "architecture"`** rather than `list`. `list` reports the templates that exist; `architecture` reports every slot the site HAS, which of them still fall through to the theme, and the exact call that fills each gap.

## How to call an ability - there are TWO levels of nesting

The gateway envelope is always `ability_name` and `parameters`. Those two key names are fixed and are the only ones `execute-ability` accepts - not `ability`, not `action_parameters`.

Most abilities are then **action routed** *inside* `parameters`: they take an `action` plus that action's own `action_parameters`. So a page call is:

```json
{ "ability_name": "uichemy-composer/page",
  "parameters": {
    "action": "append-section",
    "action_parameters": { "post_id": 123, "label": "Features", "html": "…" }
  } }
```

`action` and `action_parameters` live INSIDE `parameters`. They are never envelope keys.

A few abilities are **not** action routed - `uichemy-composer/read-skill` is one. Their parameters go straight into `parameters`, with no `action` and no `action_parameters`:

```json
{ "ability_name": "uichemy-composer/read-skill",
  "parameters": { "name": "code-to-wordpress" } }
```

Discovery lists each ability's actions, or shows none when it is not action routed. `get-ability-info` returns the exact parameter schema for the action you picked - read that instead of guessing parameter names.

Before telling the user a build is finished, run `uichemy-composer/audit`. Each finding names the ability that fixes it.

## Design system

The site design system is one plain CSS block with the id `uichemy-globals`, and it is the source of truth for global tokens. Read it with `uichemy-composer/design-system` (`action: "get"`) before you write section CSS, and reference its `var(--token)` names rather than repeating literal values.

When writing it: custom properties go in `:root {}`, one per line. Group related properties under a plain section comment naming the collection (`/* Colors */`, `/* Spacing */`, `/* Fonts */`); that comment is the only thing that classifies them. Never rename an existing token, because that breaks every `var()` referring to it. Reusable text styles go in `.text-*` classes and components in `.pr-*` classes, after `:root`. No `@layer`, no @-metadata.

Replace the whole block with `action: "set"` (send the complete merged file) or patch it with `action: "patch"` using exact find/replace.
GUIDE;

			return $guide . "\n\n" . self::skills_section();
		}

		/**
		 * Render the Skills section from the registry.
		 *
		 * Public because a sibling plugin may serve its own briefing in place of
		 * this one (see the Pro build) and still needs THIS list: it is generated
		 * from UiChemy_Skills, so a copy frozen into another document would start
		 * advertising skills that `read-skill` no longer serves. Exposing the
		 * renderer keeps one source for the list however many briefings exist.
		 *
		 * @return string Markdown, or '' when no skills are registered.
		 */
		public static function skills_section() {
			if ( ! class_exists( 'UiChemy_Skills' ) ) {
				return '';
			}

			$skills = UiChemy_Skills::index();
			if ( empty( $skills ) ) {
				return '';
			}

			$lines = array(
				'## Skills:',
				'',
				'A skill is the build PROCESS for one kind of job. Read the matching one with `'
					. UiChemy_Skills::ABILITY_NAME . '` BEFORE you plan or call any building ability - not after, and not only when you feel stuck.',
				'',
				'Call it as `' . UiChemy_Skills::ABILITY_NAME . '` with `name: "<skill-name>"`, using a name exactly as listed below. Add `part: "<part-name>"` to load one of a skill\'s extra documents once you have its main body.',
				'',
				'Match on the user\'s intent, without being asked. If more than one looks close, read the one whose description names what the user actually supplied (a design URL, their own code, or only a description). If none matches, say so rather than inventing a process.',
				'',
				'Keep this list, and any skill you have read, in memory for the whole task - including across a context compaction. If you lose it, re-read this briefing with `' . self::ABILITY_NAME . '`.',
				'',
			);

			foreach ( $skills as $skill ) {
				$lines[] = sprintf(
					'- %s: %s',
					isset( $skill['name'] ) ? (string) $skill['name'] : '',
					isset( $skill['description'] ) ? (string) $skill['description'] : ''
				);
				$lines[] = '';
			}

			return rtrim( implode( "\n", $lines ) );
		}
	}
}
