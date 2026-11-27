<?php
/**
 * Discovery: how agents find the agent layer.
 *
 * - robots.txt: a Content-Signal line (search, ai-input, ai-train), an llms.txt
 *   pointer, and Disallow rules only for AI crawlers the owner blocks. Nothing
 *   here ever adds an Allow rule, so a site's own blocks are never overridden.
 * - /.well-known/api-catalog (RFC 9727 linkset) and /.well-known/ai-catalog.json
 *   (draft) point to the site MCP, its server card, llms.txt and agent pages.
 * - {mcp path}/server-card: the MCP server card.
 * - Link headers on every HTML page: llms.txt and the API catalog.
 * - Shortcodes usable in any builder: [uichemy_agent_switch],
 *   [uichemy_ask_ai] and [uichemy_agent_connect].
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Discovery' ) ) {

	/**
	 * Discovery files, headers and shortcodes.
	 */
	class Uich_Agent_Discovery {

		/**
		 * Option holding the Connect-your-AI page ID.
		 */
		const CONNECT_OPTION = 'uichemy_agent_ready_connect_page';

		/**
		 * AI crawlers the owner can allow or block, by group.
		 *
		 * @return array<string,string[]>
		 */
		public static function bots() {
			return array(
				'training' => array( 'GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent', 'Bytespider', 'cohere-ai', 'AI2Bot' ),
				'search'   => array( 'OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'DuckAssistBot' ),
				'user'     => array( 'ChatGPT-User', 'Claude-User', 'Perplexity-User', 'MistralAI-User' ),
			);
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		/**
		 * The discovery file matched when the request started.
		 *
		 * @var string
		 */
		private static $which = '';

		public static function init() {
			add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
			add_shortcode( 'uichemy_agent_switch', array( __CLASS__, 'sc_switch' ) );
			add_shortcode( 'uichemy_ask_ai', array( __CLASS__, 'sc_ask_ai' ) );
			add_shortcode( 'uichemy_agent_connect', array( __CLASS__, 'sc_connect' ) );
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );

			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}
			self::$which = self::requested();
			if ( self::$which ) {
				add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 0 );
				// SKILL.md must not be taken as an agent page.
				return 'skill-md' === self::$which ? true : null;
			}
			add_action( 'template_redirect', array( __CLASS__, 'link_headers' ), 2 );
		}

		/**
		 * Real files in the web root that the web server sends before WordPress
		 * runs, so Agent Ready's version of that address is never seen.
		 *
		 * @return array<string,string> Address => what it overrides.
		 */
		public static function overrides() {
			$files = array(
				'llms.txt'                         => 'llms.txt',
				'llms-full.txt'                    => 'llms-full.txt',
				'robots.txt'                       => 'the Agent Ready rules in robots.txt',
				'.well-known/api-catalog'          => 'the API catalog',
				'.well-known/ai-catalog.json'      => 'the AI catalog',
				'.well-known/mcp/server-card.json' => 'the MCP server card',
				'.well-known/agent-skills/index.json' => 'the agent skills index',
				'index.md'                         => 'the home page agent file',
			);
			$roots = array_unique( array( trailingslashit( ABSPATH ), trailingslashit( self::home_dir() ) ) );
			$out   = array();
			foreach ( $files as $rel => $what ) {
				foreach ( $roots as $root ) {
					if ( '/' !== $root && is_file( $root . $rel ) ) {
						$out[ '/' . $rel ] = $what;
					}
				}
			}
			return $out;
		}

		/**
		 * The site's main folder (differs from ABSPATH when WordPress is in a sub-folder).
		 *
		 * @return string
		 */
		private static function home_dir() {
			if ( ! function_exists( 'get_home_path' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return function_exists( 'get_home_path' ) ? (string) get_home_path() : ABSPATH;
		}

		/**
		 * Which discovery file this request is for: 'api-catalog', 'ai-catalog', 'server-card' or ''.
		 *
		 * @return string
		 */
		private static function requested() {
			$path = untrailingslashit( Uich_Agent_Urls::request_path() );
			$home = Uich_Agent_Urls::home_path();
			$d    = Uich_Agent_Settings::value( 'discovery' );
			if ( $path === $home . '.well-known/api-catalog' && $d['api_catalog'] ) {
				return 'api-catalog';
			}
			if ( $path === $home . '.well-known/ai-catalog.json' && $d['ai_catalog'] ) {
				return 'ai-catalog';
			}
			$mcp_on = $d['server_card'] && Uich_Agent_MCP::public_server();
			if ( $path === $home . '.well-known/mcp/server-card.json' && $mcp_on ) {
				return 'server-card';
			}
			if ( $path === $home . '.well-known/agent-skills/index.json' && $d['ai_catalog'] ) {
				return 'skills-index';
			}
			if ( $path === $home . '.well-known/agent-skills/use-this-site/SKILL.md' && $d['ai_catalog'] ) {
				return 'skill-md';
			}
			if ( $path === $home . Uich_Agent_Settings::value( 'mcp.path', 'mcp' ) . '/server-card' && $d['server_card'] && Uich_Agent_MCP::public_server() ) {
				return 'server-card';
			}
			return '';
		}

		/**
		 * Serve a discovery file and stop.
		 *
		 * @return void
		 */
		public static function serve() {
			$which = self::$which ? self::$which : self::requested();
			if ( ! Uich_Agent_Limiter::allow( 'cached' ) ) {
				Uich_Agent_Limiter::refuse( 'json' );
			}
			$type = 'application/json';
			switch ( $which ) {
				case 'api-catalog':
					$body = self::api_catalog();
					$type = 'application/linkset+json';
					break;
				case 'ai-catalog':
					$body = self::ai_catalog();
					break;
				case 'skills-index':
					$body = self::skills_index();
					break;
				case 'skill-md':
					status_header( 200 );
					header( 'Content-Type: text/markdown; charset=utf-8' );
					header( 'Access-Control-Allow-Origin: *' );
					header( 'Cache-Control: public, max-age=3600' );
					header( 'X-Content-Type-Options: nosniff' );
					echo self::skill_md(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown, not HTML.
					exit;
				default:
					$body = self::server_card();
			}
			status_header( 200 );
			header( 'Content-Type: ' . $type . '; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'X-Content-Type-Options: nosniff' );
			echo wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}

		/**
		 * RFC 9727 API catalog (linkset).
		 *
		 * @return array
		 */
		public static function api_catalog() {
			$set = array(
				'anchor'      => home_url( '/' ),
				'service-doc' => array(
					array(
						'href'  => home_url( '/llms.txt' ),
						'type'  => 'text/plain',
						'title' => 'Guide for AI agents (llms.txt)',
					),
				),
			);
			if ( Uich_Agent_MCP::public_server() ) {
				$set['service-desc'] = array(
					array(
						'href'  => Uich_Agent_MCP::url() . '/server-card',
						'type'  => 'application/json',
						'title' => 'Site MCP server card',
					),
					array(
						'href'  => home_url( '/.well-known/mcp/server-card.json' ),
						'type'  => 'application/json',
						'title' => 'Site MCP server card (well-known)',
					),
				);
				$set['service-meta'] = array(
					array(
						'href'  => Uich_Agent_MCP::url(),
						'title' => 'Site MCP endpoint (Streamable HTTP, no login)',
					),
				);
			}
			return array( 'linkset' => array( $set ) );
		}

		/**
		 * AI catalog (draft format): this site's agent-facing resources.
		 *
		 * @return array
		 */
		public static function ai_catalog() {
			$name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$items = array(
				array(
					'type'        => 'llms-txt',
					'name'        => 'Guide for AI agents',
					'url'         => home_url( '/llms.txt' ),
					'description' => 'What this site offers and how agents can use it.',
				),
			);
			if ( Uich_Agent_Settings::value( 'agent_pages.md_urls', true ) ) {
				$items[] = array(
					'type'        => 'markdown',
					'name'        => 'Agent pages',
					'url'         => home_url( '/index.md' ),
					'description' => 'Every public page as Markdown: add .md to any page address.',
				);
			}
			if ( Uich_Agent_MCP::public_server() ) {
				$items[] = array(
					'type'        => 'mcp-server',
					'name'        => $name . ' site MCP',
					'url'         => Uich_Agent_MCP::url(),
					'card'        => Uich_Agent_MCP::url() . '/server-card',
					'transport'   => 'streamable-http',
					'auth'        => 'none',
					'description' => 'Public, read-only tools for this site\'s own information.',
				);
			}
			// Checkers read "entries" with a displayName each; "resources" stays for older readers.
			$entries = array();
			foreach ( $items as $item ) {
				$entries[] = array_merge( array( 'displayName' => $item['name'] ), $item );
			}
			$entries[] = array(
				'displayName' => 'Agent skills',
				'name'        => 'Agent skills',
				'type'        => 'agent-skills',
				'url'         => home_url( '/.well-known/agent-skills/index.json' ),
				'description' => 'How an agent should use this site.',
			);
			return array(
				'specVersion' => '0.1',
				'version'     => '0.1',
				'displayName' => $name,
				'publisher'   => array(
					'name' => $name,
					'url'  => home_url( '/' ),
				),
				'entries'     => $entries,
				'resources'   => $items,
			);
		}

		/**
		 * MCP server card (draft): where and how to connect. Tools are listed
		 * through tools/list, not here.
		 *
		 * @return array
		 */
		public static function server_card() {
			$name = (string) Uich_Agent_Settings::value( 'mcp.name', '' );
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			return array(
				'name'             => '' !== $name ? $name : $site,
				'description'      => sprintf( 'Official MCP server of %s. Read the site\'s own published information.', $site ),
				'version'          => Uich_Agent_Ready::VERSION,
				'protocolVersions' => Uich_Agent_MCP::VERSIONS,
				'remotes'          => array(
					array(
						'type' => 'streamable-http',
						'url'  => Uich_Agent_MCP::url(),
					),
				),
				'authentication'   => array( 'required' => false ),
				'tools'            => self::card_tools(),
				'websiteUrl'       => home_url( '/' ),
				'documentation'    => home_url( '/llms.txt' ),
			);
		}

		/**
		 * Tools for the server card: name and description, as tools/list gives.
		 *
		 * @return array
		 */
		private static function card_tools() {
			$out = array();
			foreach ( Uich_Agent_Tools::enabled() as $tool ) {
				$out[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
				);
			}
			return $out;
		}

		/**
		 * Agent skills discovery index.
		 *
		 * @return array
		 */
		public static function skills_index() {
			$md = self::skill_md();
			return array(
				'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
				'skills'  => array(
					array(
						'name'        => 'use-this-site',
						'type'        => 'skill-md',
						'description' => 'How to read and use ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' as an AI agent.',
						'url'         => home_url( '/.well-known/agent-skills/use-this-site/SKILL.md' ),
						'digest'      => 'sha256:' . hash( 'sha256', $md ),
					),
				),
			);
		}

		/**
		 * The site's one public skill: where to read and which tools to call.
		 *
		 * @return string
		 */
		public static function skill_md() {
			$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$lines = array(
				'---',
				'name: use-this-site',
				'description: How to read and use ' . str_replace( array( "\n", ':' ), ' ', $site ) . ' as an AI agent.',
				'---',
				'',
				'# Using ' . $site,
				'',
				'- Start with the guide: ' . home_url( '/llms.txt' ),
			);
			if ( Uich_Agent_Settings::value( 'agent_pages.md_urls', true ) ) {
				$lines[] = '- Read any page as Markdown: add .md to its address, starting with ' . home_url( '/index.md' );
			}
			if ( Uich_Agent_MCP::public_server() ) {
				$lines[] = '- Call tools on the site MCP server (no login, Streamable HTTP): ' . Uich_Agent_MCP::url();
				foreach ( self::card_tools() as $tool ) {
					$lines[] = '  - `' . $tool['name'] . '`: ' . $tool['description'];
				}
			} elseif ( Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
				$lines[] = '- An AI that runs in the visitor\'s browser finds this site\'s tools on every page (WebMCP). The guide above lists them.';
			}
			$lines[] = '';
			$lines[] = 'Only published, public pages are available. Do not guess addresses: use llms.txt' . ( Uich_Agent_MCP::public_server() ? ' or search_site' : '' ) . '.';
			return implode( "\n", $lines ) . "\n";
		}

		/**
		 * Append the Agent Ready block to robots.txt.
		 *
		 * @param string $output Robots text.
		 * @param bool   $public Whether the site is public.
		 * @return string
		 */
		public static function robots( $output, $public ) {
			if ( ! $public ) {
				return $output;
			}
			$d     = Uich_Agent_Settings::value( 'discovery' );
			$cs    = $d['content_signal'];
			$block = "\n# Agent Ready (UiChemy)\n";
			$block .= "User-agent: *\n";
			$block .= 'Content-Signal: search=' . ( $cs['search'] ? 'yes' : 'no' ) . ', ai-input=' . ( $cs['ai_input'] ? 'yes' : 'no' ) . ', ai-train=' . ( $cs['ai_train'] ? 'yes' : 'no' ) . "\n";
			$block .= 'LLMs-Txt: ' . home_url( '/llms.txt' ) . "\n";

			$blocked = array();
			foreach ( self::bots() as $group ) {
				foreach ( $group as $bot ) {
					$rule = isset( $d['bots']['rules'][ $bot ] ) ? $d['bots']['rules'][ $bot ] : $d['bots']['default'];
					if ( 'block' === $rule ) {
						$blocked[] = $bot;
					}
				}
			}
			foreach ( $d['bots']['rules'] as $bot => $rule ) {
				if ( 'block' === $rule && ! in_array( $bot, $blocked, true ) ) {
					$blocked[] = $bot;
				}
			}
			foreach ( $blocked as $bot ) {
				$block .= "\nUser-agent: " . $bot . "\nDisallow: /\n";
			}
			return rtrim( (string) $output ) . "\n" . $block;
		}

		/**
		 * Link headers on HTML pages.
		 *
		 * @return void
		 */
		public static function link_headers() {
			if ( headers_sent() || ! Uich_Agent_Settings::value( 'discovery.link_headers', true ) || ( class_exists( 'Uich_Agent_Pages' ) && Uich_Agent_Pages::is_agent_request() ) ) {
				return;
			}
			header( 'Link: <' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"; type="text/plain"', false );
			if ( Uich_Agent_Settings::value( 'discovery.api_catalog', true ) ) {
				header( 'Link: <' . esc_url_raw( home_url( '/.well-known/api-catalog' ) ) . '>; rel="api-catalog"', false );
			}
		}

		/* -------------------------------------------------------------
		 * Shortcodes
		 * ----------------------------------------------------------- */

		/**
		 * [uichemy_agent_switch human="Human" agent="Agent"]: links to this page and its agent file.
		 *
		 * @param array $atts Attributes.
		 * @return string
		 */
		public static function sc_switch( $atts ) {
			$atts  = shortcode_atts(
				array(
					'human' => __( 'Human', 'uichemy' ),
					'agent' => __( 'Agent', 'uichemy' ),
				),
				$atts,
				'uichemy_agent_switch'
			);
			$here  = home_url( Uich_Agent_Urls::request_path() );
			$agent = Uich_Agent_Urls::md_url( $here );
			return sprintf(
				'<nav class="uich-agent-switch" aria-label="%1$s"><a href="%2$s" aria-current="page">%3$s</a> <a href="%4$s" rel="alternate" type="text/markdown">%5$s</a></nav>',
				esc_attr__( 'Page format', 'uichemy' ),
				esc_url( $here ),
				esc_html( $atts['human'] ),
				esc_url( $agent ),
				esc_html( $atts['agent'] )
			);
		}

		/**
		 * [uichemy_ask_ai]: open the visitor's assistant with this site's official address in the prompt.
		 *
		 * @param array $atts Attributes.
		 * @return string
		 */
		public static function sc_ask_ai( $atts ) {
			$atts   = shortcode_atts( array( 'label' => __( 'Ask AI about us', 'uichemy' ) ), $atts, 'uichemy_ask_ai' );
			$s      = Uich_Agent_Settings::value( 'discovery.ask_ai' );
			$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$prompt = '' !== $s['prompt'] ? $s['prompt'] : sprintf( 'Tell me about %1$s. Use their official site: %2$s (guide for AI agents: %3$s).', $site, home_url( '/' ), home_url( '/llms.txt' ) );
			$q      = rawurlencode( $prompt );
			$urls   = array(
				'chatgpt'    => array( 'ChatGPT', 'https://chatgpt.com/?q=' . $q ),
				'claude'     => array( 'Claude', 'https://claude.ai/new?q=' . $q ),
				'perplexity' => array( 'Perplexity', 'https://www.perplexity.ai/search?q=' . $q ),
				'grok'       => array( 'Grok', 'https://grok.com/?q=' . $q ),
				'gemini'     => array( 'Gemini', 'https://gemini.google.com/app?q=' . $q ),
			);
			$links  = array();
			foreach ( $s['assistants'] as $key ) {
				if ( isset( $urls[ $key ] ) ) {
					$links[] = sprintf( '<a href="%1$s" target="_blank" rel="noopener nofollow">%2$s</a>', esc_url( $urls[ $key ][1] ), esc_html( $urls[ $key ][0] ) );
				}
			}
			return sprintf(
				'<div class="uich-ask-ai"><span class="uich-ask-ai__label">%1$s</span> %2$s <button type="button" class="uich-ask-ai__copy" data-prompt="%3$s" title="%3$s" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.prompt)">%4$s</button></div>',
				esc_html( $atts['label'] ),
				implode( ' ', $links ),
				esc_attr( $prompt ),
				esc_html__( 'Copy prompt', 'uichemy' )
			);
		}

		/**
		 * [uichemy_agent_connect]: how to connect an AI assistant to this site.
		 *
		 * @return string
		 */
		public static function sc_connect() {
			$mcp = class_exists( 'Uich_Agent_MCP' ) ? Uich_Agent_MCP::url() : '';
			$out = '<div class="uich-agent-connect">';
			if ( $mcp && Uich_Agent_MCP::public_server() ) {
				$out .= '<p>' . esc_html__( 'This site has its own MCP server, so AI assistants can read its information directly. No login is needed.', 'uichemy' ) . '</p>';
				$out .= '<p><strong>' . esc_html__( 'Server address:', 'uichemy' ) . '</strong> <code>' . esc_html( $mcp ) . '</code></p>';
				$out .= '<ul>';
				$out .= '<li><strong>Claude:</strong> ' . esc_html__( 'Settings > Connectors > Add custom connector, paste the address, choose no sign-in.', 'uichemy' ) . '</li>';
				$out .= '<li><strong>ChatGPT:</strong> ' . esc_html__( 'Settings > Apps & Connectors > Create (developer mode), paste the address, no authentication.', 'uichemy' ) . '</li>';
				$out .= '<li><strong>Cursor / other MCP clients:</strong> ' . esc_html__( 'add a remote (Streamable HTTP) server with this address.', 'uichemy' ) . '</li>';
				$out .= '</ul>';
			}
			$out .= '<p>' . sprintf(
				/* translators: %s: llms.txt address */
				esc_html__( 'Guide for AI agents: %s', 'uichemy' ),
				'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '">' . esc_html( home_url( '/llms.txt' ) ) . '</a>'
			) . '</p>';
			return $out . '</div>';
		}

		/* -------------------------------------------------------------
		 * Ability actions
		 * ----------------------------------------------------------- */

		/**
		 * Add discovery actions.
		 *
		 * @param array $actions Actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['preview-discovery']    = array(
				'description' => 'Show the discovery files agents receive: the Agent Ready robots.txt block, the API catalog, the AI catalog and the MCP server card. Change them with update-settings (discovery and mcp sections).',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_preview' ),
			);
			$actions['create-connect-page']  = array(
				'description' => 'Create (or re-publish) a public "Connect your AI" page that explains how to add this site\'s MCP server to ChatGPT, Claude and other assistants. It is linked from llms.txt.',
				'params'      => array(
					'title' => array(
						'type'        => 'string',
						'description' => 'Page title. Default "Connect your AI".',
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'act_connect_page' ),
			);
			return $actions;
		}

		/**
		 * Action: preview-discovery.
		 *
		 * @return array
		 */
		public static function act_preview() {
			return array(
				'robots_block' => self::robots( '', true ),
				'api_catalog'  => self::api_catalog(),
				'ai_catalog'   => self::ai_catalog(),
				'server_card'  => self::server_card(),
				'urls'         => array(
					'api_catalog' => home_url( '/.well-known/api-catalog' ),
					'ai_catalog'  => home_url( '/.well-known/ai-catalog.json' ),
					'server_card' => Uich_Agent_MCP::url() . '/server-card',
					'robots'      => home_url( '/robots.txt' ),
				),
				'note'         => '/' !== Uich_Agent_Urls::home_path() ? 'WordPress runs in a sub-folder, so the /.well-known files are served under it, not at the domain root.' : '',
			);
		}

		/**
		 * Action: create-connect-page.
		 *
		 * @param array $params { title? }.
		 * @return array|WP_Error
		 */
		public static function act_connect_page( array $params ) {
			if ( ! current_user_can( 'publish_pages' ) ) {
				return new WP_Error( 'uich_agent_cap', 'You cannot publish pages.' );
			}
			$title = isset( $params['title'] ) && '' !== trim( (string) $params['title'] ) ? sanitize_text_field( $params['title'] ) : __( 'Connect your AI', 'uichemy' );
			$id    = (int) get_option( self::CONNECT_OPTION, 0 );
			$post  = $id ? get_post( $id ) : null;
			$data  = array(
				'post_title'   => $title,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:shortcode -->[uichemy_agent_connect]<!-- /wp:shortcode -->',
			);
			if ( $post && 'page' === $post->post_type ) {
				$data['ID'] = $post->ID;
				$id         = wp_update_post( $data, true );
			} else {
				$data['post_name'] = 'connect-your-ai';
				$id                = wp_insert_post( $data, true );
			}
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_option( self::CONNECT_OPTION, (int) $id, false );
			Uich_Agent_Pages::bump();
			return array(
				'page_id' => (int) $id,
				'url'     => get_permalink( $id ),
			);
		}
	}
}

