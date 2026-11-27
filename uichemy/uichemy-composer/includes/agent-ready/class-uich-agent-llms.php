<?php
/**
 * llms.txt for AI agents: /llms.txt, /llms/{section}.txt and /llms-full.txt.
 *
 * Follows the llms.txt format (llmstxt.org): an H1 with the site name, a short
 * blockquote summary, detail text, then H2 sections of "- [name](url): note"
 * links, with an "Optional" section agents may skip. It is written for action,
 * not marketing: the first section says what an agent can do here (the site
 * MCP and its tools, agent pages), then the key pages, then complete lists.
 *
 * Modes:
 * - generated: built from the site, rebuilt when content changes.
 * - claude / manual: the text saved in the private folder (llms.txt) is served
 *   as written and never overwritten by the generator.
 *
 * When Agent Ready is on it answers these URLs; the SEO module's older llms.txt
 * never sees the request.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Llms' ) ) {

	/**
	 * llms.txt files.
	 */
	class Uich_Agent_Llms {

		/**
		 * Stored file for claude/manual mode.
		 */
		const FILE = 'llms.txt';

		/**
		 * Most items listed in one section file.
		 */
		const SECTION_MAX = 2000;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );

			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}
			// Switched off on the Agent Ready screen: llms.txt is not served.
			if ( ! Uich_Agent_Settings::value( 'llms.enabled', true ) ) {
				return;
			}
			$file = self::requested();
			if ( $file ) {
				add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 0 );
			}
		}

		/**
		 * Which llms file this request asks for: 'main', 'full', 'section:{type}' or ''.
		 *
		 * @return string
		 */
		private static function requested() {
			$path = Uich_Agent_Urls::request_path();
			$home = Uich_Agent_Urls::home_path();
			if ( $path === $home . 'llms.txt' ) {
				return 'main';
			}
			if ( $path === $home . 'llms-full.txt' ) {
				return Uich_Agent_Settings::value( 'llms.full', true ) ? 'full' : '';
			}
			if ( preg_match( '#^' . preg_quote( $home, '#' ) . 'llms/([a-z0-9_\-]{1,40})\.txt$#', $path, $m ) ) {
				return Uich_Agent_Settings::value( 'llms.sections', true ) ? 'section:' . $m[1] : '';
			}
			return '';
		}

		/**
		 * Serve the requested file and stop.
		 *
		 * @return void
		 */
		public static function serve() {
			$which = self::requested();
			if ( ! Uich_Agent_Limiter::allow( 'cached' ) ) {
				Uich_Agent_Limiter::refuse();
			}
			if ( 'main' === $which ) {
				$body = self::main();
			} elseif ( 'full' === $which ) {
				$body = self::cached( 'full', array( __CLASS__, 'full' ) );
			} else {
				$type = substr( $which, 8 );
				$body = in_array( $type, self::section_types(), true ) ? self::cached( 'section-' . $type, array( __CLASS__, 'section' ), $type ) : null;
			}
			if ( null === $body ) {
				status_header( 404 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo "Not found.\n";
				exit;
			}
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: public, max-age=600' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built from escaped parts.
			exit;
		}

		/**
		 * The main llms.txt text (stored file in claude/manual mode).
		 *
		 * @return string
		 */
		public static function main() {
			$mode = Uich_Agent_Settings::value( 'llms.mode', 'generated' );
			if ( 'generated' !== $mode ) {
				$stored = Uich_Agent_Store::read( self::FILE );
				if ( is_string( $stored ) && '' !== trim( $stored ) ) {
					return $stored;
				}
			}
			// The key follows the Tools and Forms text, so switching a tool or a form on
			// or off shows in llms.txt straight away instead of waiting for a page edit.
			return self::cached( 'main-' . substr( md5( self::capabilities() ), 0, 10 ), array( __CLASS__, 'generate' ) );
		}

		/**
		 * Read a generated file from cache, building it when missing.
		 *
		 * @param string   $key      Cache key.
		 * @param callable $builder  Builder.
		 * @param mixed    $arg      Builder argument.
		 * @return string|null
		 */
		private static function cached( $key, $builder, $arg = null ) {
			$root = Uich_Agent_Store::cache_root();
			$gen  = (int) get_option( Uich_Agent_Pages::GEN_OPTION, 1 );
			$file = is_wp_error( $root ) ? '' : $root . 'llms/g' . $gen . '-' . md5( home_url( '/' ) . '|' . $key ) . '.txt';
			// Membership rules can change without a content edit (which is what starts a
			// new generation), so while they are in play a file is rebuilt after 10 minutes.
			$fresh = $file && is_file( $file ) && ( ! Uich_Agent_Access::membership_active() || ( time() - (int) filemtime( $file ) ) < 10 * MINUTE_IN_SECONDS );
			if ( $fresh ) {
				return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache.
			}
			$body = null === $arg ? call_user_func( $builder ) : call_user_func( $builder, $arg );
			if ( $file && is_string( $body ) ) {
				wp_mkdir_p( dirname( $file ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache write.
				@file_put_contents( $file, $body, LOCK_EX );
			}
			return $body;
		}

		/* -------------------------------------------------------------
		 * Builders
		 * ----------------------------------------------------------- */

		/**
		 * Build the main llms.txt.
		 *
		 * @return string
		 */
		public static function generate() {
			$s       = Uich_Agent_Settings::value( 'llms' );
			$name    = class_exists( 'Uich_Agent_Profile' ) && '' !== Uich_Agent_Profile::get()['name'] ? Uich_Agent_Profile::get()['name'] : self::line( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$summary = '' !== $s['summary'] ? $s['summary'] : self::line( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) );

			/**
			 * Filter the one-line summary (the site profile supplies a better one).
			 *
			 * @param string $summary Summary.
			 */
			$summary = (string) apply_filters( 'uichemy_agent_ready_llms_summary', $summary );

			$out = '# ' . ( '' !== $name ? $name : home_url( '/' ) ) . "\n\n";
			if ( '' !== $summary ) {
				$out .= '> ' . self::line( $summary ) . "\n\n";
			}
			if ( '' !== $s['intro'] ) {
				$out .= self::block( $s['intro'] ) . "\n\n";
			}

			// Say plainly that every page has a Markdown copy, with a real example, so an
			// AI reads the .md version instead of the designed HTML page.
			$md_on = false !== Uich_Agent_Settings::value( 'agent_pages.enabled', true ) && Uich_Agent_Settings::value( 'agent_pages.md_urls', true );
			if ( $md_on ) {
				// An inner page reads best as an example (/about/ -> /about.md), so skip the home page.
				$page = '';
				foreach ( self::key_pages( $s['key_pages'] ) as $sample ) {
					$url = (string) get_permalink( $sample );
					if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) ) {
						$page = $url;
						break;
					}
				}
				$out .= 'Every page has an AI-friendly Markdown version: add .md to its address'
					. ( $page ? ' (' . $page . ' → ' . Uich_Agent_Urls::md_url( $page ) . ')' : '' )
					. '.' . "\n\n";
			}

			// What an agent can do here.
			$out .= "## Agent access\n\n";
			if ( class_exists( 'Uich_Agent_MCP' ) && Uich_Agent_MCP::public_server() && Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				$out .= '- [Site MCP server](' . Uich_Agent_MCP::url() . '): public MCP endpoint for AI agents, no login. Streamable HTTP, POST JSON-RPC. Tools and forms you can use are listed below.' . "\n";
			} elseif ( class_exists( 'Uich_Agent_MCP' ) && Uich_Agent_Settings::value( 'mcp.enabled', true ) && Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
				$out .= '- Browser AI (WebMCP): an AI that runs in the visitor\'s browser finds this site\'s tools on every page. The tools and forms are listed below.' . "\n";
			}
			if ( $md_on ) {
				$out .= '- [Agent pages](' . home_url( '/index.md' ) . '): every public page as clean Markdown. Add .md to a page address, or send Accept: text/markdown.' . "\n";
			}
			$connect = (int) get_option( 'uichemy_agent_ready_connect_page', 0 );
			if ( $connect && 'publish' === get_post_status( $connect ) ) {
				$out .= '- [Connect your AI](' . get_permalink( $connect ) . '): how to add this site to ChatGPT, Claude, Cursor and other assistants.' . "\n";
			}
			$out .= "\n";

			// The tools and forms an agent can use right now.
			$out .= self::capabilities();

			/**
			 * Add sections after "Agent access" (the site profile adds contact and hours).
			 *
			 * @param string $markdown Extra sections (Markdown).
			 */
			$extra = (string) apply_filters( 'uichemy_agent_ready_llms_extra', '' );
			if ( '' !== trim( $extra ) ) {
				$out .= trim( $extra ) . "\n\n";
			}

			// Key pages.
			$key  = self::key_pages( $s['key_pages'] );
			$seen = array();
			if ( $key ) {
				$out .= "## Key pages\n\n";
				foreach ( $key as $post ) {
					$seen[ $post->ID ] = true;
					$out              .= self::item( $post ) . "\n";
				}
				$out .= "\n";
			}

			// One section per included post type.
			foreach ( self::section_types() as $type ) {
				$obj   = get_post_type_object( $type );
				$count = self::count( $type );
				if ( ! $obj || ! $count ) {
					continue;
				}
				// Everything of this type is already listed under Key pages: do not list it twice.
				$covered = 0;
				foreach ( $key as $key_post ) {
					if ( $key_post->post_type === $type ) {
						++$covered;
					}
				}
				if ( $count <= $covered ) {
					continue;
				}
				$out .= '## ' . self::line( $obj->labels->name ) . "\n\n";
				if ( $s['sections'] ) {
					$out .= '- [All ' . strtolower( self::line( $obj->labels->name ) ) . '](' . home_url( '/llms/' . $type . '.txt' ) . '): complete list (' . $count . ").\n";
				}
				foreach ( self::latest( $type, $s['sections'] ? 10 : 50 ) as $post ) {
					if ( isset( $seen[ $post->ID ] ) ) {
						continue; // Already listed under Key pages.
					}
					$out .= self::item( $post ) . "\n";
				}
				$out .= "\n";
			}

			// Optional.
			$optional = array();
			foreach ( $s['optional_links'] as $link ) {
				$optional[] = '- [' . self::line( $link['title'] ) . '](' . esc_url_raw( $link['url'] ) . ')' . ( '' !== $link['note'] ? ': ' . self::line( $link['note'] ) : '' );
			}
			if ( $s['full'] ) {
				$optional[] = '- [Full text](' . home_url( '/llms-full.txt' ) . '): key pages in one file.';
			}
			if ( $optional ) {
				$out .= "## Optional\n\n" . implode( "\n", $optional ) . "\n";
			}

			return rtrim( $out ) . "\n";
		}

		/**
		 * The "Tools" and "Forms" sections: what an agent can call on this site's MCP
		 * server, in the same terms the MCP itself uses, so a model reading llms.txt
		 * knows the tool names and fields without a separate tools/list call.
		 *
		 * Only what is switched on for agents appears. Nothing private: the text
		 * comes from the tools' own public descriptions and the forms' field labels.
		 *
		 * @return string Markdown ending with a blank line, or '' when there is nothing to offer.
		 */
		private static function capabilities() {
			static $memo = null;
			if ( null !== $memo ) {
				return $memo;
			}
			$memo = '';
			if ( ! class_exists( 'Uich_Agent_MCP' ) || ! class_exists( 'Uich_Agent_Tools' ) || ! Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				return $memo;
			}
			$public = Uich_Agent_MCP::public_server();
			// Without a public server the tools exist only for browser AI, so WebMCP must be on.
			if ( ! $public && ! Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
				return $memo;
			}
			$all = Uich_Agent_Tools::enabled();
			if ( ! $all ) {
				return $memo;
			}

			// Forms switched on for agents, with the page each one lives on.
			$forms = array();
			if ( class_exists( 'Uich_Agent_Forms' ) ) {
				foreach ( Uich_Agent_Forms::registry() as $form ) {
					if ( ! empty( $form['mode'] ) && 'off' !== $form['mode'] && ! empty( $form['tool'] ) && isset( $all[ $form['tool'] ] ) && 'publish' === get_post_status( (int) $form['page_id'] ) ) {
						$forms[ $form['tool'] ] = $form;
					}
				}
			}

			$tool_lines = array();
			foreach ( $all as $name => $tool ) {
				if ( isset( $forms[ $name ] ) ) {
					continue; // Listed under Forms.
				}
				$line = '- `' . $name . '`: ' . self::trim_text( isset( $tool['description'] ) ? $tool['description'] : ( isset( $tool['title'] ) ? $tool['title'] : '' ), 360 );
				$args = self::arguments( $tool );
				if ( '' !== $args ) {
					$line .= ' Arguments: ' . $args . '.';
				}
				if ( ! empty( $tool['write'] ) || ( isset( $tool['annotations']['readOnlyHint'] ) && false === $tool['annotations']['readOnlyHint'] ) ) {
					$line .= ' Sends data.';
				}
				$tool_lines[] = $line;
			}

			$out = '';
			if ( $tool_lines ) {
				$out .= "## Tools\n\n";
				if ( $public ) {
					$out .= 'Call these on the [Site MCP server](' . Uich_Agent_MCP::url() . ') with `tools/call` and the tool name. Start with `get_site_info` if it is listed.';
					if ( Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
						$out .= ' An agent running in the visitor\'s browser finds the same tools on the page itself (WebMCP).';
					}
				} else {
					$out .= 'An AI that runs in the visitor\'s browser finds these tools on every page of this site (WebMCP). Call them by name. Start with `get_site_info` if it is listed.';
				}
				$out .= "\n\n" . implode( "\n", $tool_lines ) . "\n\n";
			}

			if ( $forms ) {
				$out .= "## Forms\n\n";
				$out .= ( $public ? 'Each form has its own tool on the Site MCP server.' : 'Each form has its own tool for AI in the visitor\'s browser, and the person can always fill it in on its page.' ) . ' Ask the person for the details first, then call the tool.' . "\n\n";
				foreach ( $forms as $name => $form ) {
					$tool   = $all[ $name ];
					$sends  = ! empty( $tool['write'] );
					$title  = '' !== trim( (string) $form['title'] ) ? self::line( $form['title'] ) : $name;
					$line   = '- **' . $title . '** (tool `' . $name . '`): ' . self::trim_text( (string) $form['description'], 200 );
					$args   = self::arguments( $tool );
					$line  .= '' !== $args ? ' Fields: ' . $args . '.' : '';
					$line  .= $sends ? ' The tool sends the form for the person.' : ' The tool returns the page where the person sends it.';
					$line  .= ' Page: [' . self::trim_text( get_the_title( (int) $form['page_id'] ), 80 ) . '](' . get_permalink( (int) $form['page_id'] ) . ').';
					$out   .= $line . "\n";
				}
				$out .= "\n";
			}

			/**
			 * Filter the "Tools" and "Forms" sections of llms.txt.
			 *
			 * @param string $markdown Markdown sections.
			 */
			$memo = (string) apply_filters( 'uichemy_agent_ready_llms_capabilities', $out );
			return $memo;
		}

		/**
		 * A tool's arguments as one short line: name, a star when required, and the
		 * allowed values or format when there are any.
		 *
		 * @param array $tool Tool definition.
		 * @return string
		 */
		private static function arguments( array $tool ) {
			$props = isset( $tool['input_schema']['properties'] ) ? (array) $tool['input_schema']['properties'] : array();
			if ( ! $props ) {
				return '';
			}
			$required = isset( $tool['input_schema']['required'] ) ? (array) $tool['input_schema']['required'] : array();
			$parts    = array();
			foreach ( array_slice( $props, 0, 12, true ) as $key => $prop ) {
				$prop = (array) $prop;
				$hint = '';
				if ( ! empty( $prop['enum'] ) ) {
					$hint = implode( ' / ', array_map( array( __CLASS__, 'line' ), array_slice( (array) $prop['enum'], 0, 8 ) ) );
				} elseif ( isset( $prop['description'] ) && preg_match( '/\((email address|date|time|datetime-local)\b/i', (string) $prop['description'], $m ) ) {
					$hint = 'email address' === strtolower( $m[1] ) ? 'email' : strtolower( $m[1] );
				} elseif ( isset( $prop['type'] ) && in_array( $prop['type'], array( 'integer', 'number' ), true ) ) {
					$hint = 'number';
				} elseif ( isset( $prop['type'] ) && 'boolean' === $prop['type'] ) {
					$hint = 'yes/no';
				}
				$parts[] = '`' . $key . '`' . ( in_array( $key, $required, true ) ? '*' : '' ) . ( '' !== $hint ? ' (' . $hint . ')' : '' );
			}
			return implode( ', ', $parts ) . ( $required ? ' (* required)' : '' );
		}

		/**
		 * Plain text cut to a length at a word end.
		 *
		 * @param string $text Text.
		 * @param int    $max  Longest length.
		 * @return string
		 */
		private static function trim_text( $text, $max ) {
			$text = self::line( $text );
			if ( mb_strlen( $text ) <= $max ) {
				return $text;
			}
			$cut = mb_substr( $text, 0, $max );
			$sp  = mb_strrpos( $cut, ' ' );
			return rtrim( false !== $sp && $sp > $max * 0.6 ? mb_substr( $cut, 0, $sp ) : $cut, " ,;:." ) . '…';
		}

		/**
		 * Build one section file.
		 *
		 * @param string $type Post type.
		 * @return string|null
		 */
		public static function section( $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				return null;
			}
			$name  = self::line( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$label = self::line( $obj->labels->name );
			$out   = '# ' . $name . ': ' . $label . "\n\n> Every published item in " . strtolower( $label ) . ' on ' . home_url( '/' ) . ". Each link is the agent (Markdown) version of the page.\n\n## " . $label . "\n\n";
			foreach ( self::latest( $type, self::SECTION_MAX ) as $post ) {
				$out .= self::item( $post ) . "\n";
			}
			return $out;
		}

		/**
		 * Build llms-full.txt: key pages and the newest pages, as plain text, capped.
		 *
		 * @return string
		 */
		public static function full() {
			$max   = (int) Uich_Agent_Settings::value( 'llms.full_max_kb', 500 ) * 1024;
			$name  = self::line( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$out   = '# ' . $name . " (full text)\n\n> Key pages of " . home_url( '/' ) . " in one file. For the latest version of any page, fetch its agent link.\n\n";
			$posts = self::key_pages( Uich_Agent_Settings::value( 'llms.key_pages', array() ) );
			foreach ( self::section_types() as $type ) {
				$posts = array_merge( $posts, self::latest( $type, 30 ) );
			}
			$seen = array();
			foreach ( $posts as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				$text              = Uich_Agent_Access::public_text( $post, 1500 );
				if ( '' === trim( $text ) ) {
					continue; // Nothing to read: a builder-only or utility page.
				}
				$chunk = '## ' . self::title( $post ) . "\n\nSource: " . Uich_Agent_Urls::md_url( get_permalink( $post ) ) . "\n\n" . $text . "\n\n";
				if ( strlen( $out ) + strlen( $chunk ) > $max ) {
					$out .= "(Truncated: the file reached its size limit. Fetch individual agent pages for the rest.)\n";
					break;
				}
				$out .= $chunk;
			}
			return $out;
		}

		/* -------------------------------------------------------------
		 * Helpers
		 * ----------------------------------------------------------- */

		/**
		 * Post types that get a section: public, not blocked, included in agent pages.
		 *
		 * @return string[]
		 */
		public static function section_types() {
			$out = array();
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
				if ( Uich_Agent_Tools::listing_blocked( $type ) ) {
					continue;
				}
				$out[] = $type;
			}
			// Pages first, then posts, then the rest.
			usort(
				$out,
				function ( $a, $b ) {
					$rank = array(
						'page' => 0,
						'post' => 1,
					);
					return ( isset( $rank[ $a ] ) ? $rank[ $a ] : 2 ) - ( isset( $rank[ $b ] ) ? $rank[ $b ] : 2 );
				}
			);
			return $out;
		}

		/**
		 * Key pages: the configured ones, else the front page and top-level pages.
		 *
		 * @param int[] $ids Configured IDs.
		 * @return WP_Post[]
		 */
		private static function key_pages( array $ids ) {
			if ( ! $ids ) {
				$front = (int) get_option( 'page_on_front' );
				$ids   = $front ? array( $front ) : array();
				$ids   = array_merge(
					$ids,
					get_posts(
						array(
							'post_type'   => 'page',
							'post_status' => 'publish',
							'post_parent' => 0,
							'numberposts' => 12,
							'orderby'     => 'menu_order title',
							'order'       => 'ASC',
							'fields'      => 'ids',
						)
					)
				);
			}
			$out = array();
			foreach ( array_unique( $ids ) as $id ) {
				$post = get_post( $id );
				if ( $post && self::listable( $post ) ) {
					$out[] = $post;
				}
			}
			return $out;
		}

		/**
		 * Newest listable items of a type.
		 *
		 * @param string $type  Post type.
		 * @param int    $limit Max.
		 * @return WP_Post[]
		 */
		private static function latest( $type, $limit ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'has_password'     => false,
					'numberposts'      => $limit,
					'orderby'          => 'page' === $type ? 'menu_order title' : 'date',
					'order'            => 'page' === $type ? 'ASC' : 'DESC',
					'suppress_filters' => false,
					'meta_query'       => Uich_Agent_Access::meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hidden-from-agents flags.
				)
			);
			return array_values( array_filter( $posts, array( __CLASS__, 'listable' ) ) );
		}

		/**
		 * Number of listable items of a type.
		 *
		 * @param string $type Post type.
		 * @return int
		 */
		private static function count( $type ) {
			$counts = wp_count_posts( $type );
			return isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		/**
		 * Whether a post may be listed for agents.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function listable( $post ) {
			if ( ! Uich_Agent_Access::listable( $post ) ) {
				return false;
			}
			return ! self::utility_page( $post );
		}

		/**
		 * Cart, checkout, account and dashboard pages: personal screens with no
		 * content for agents.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function utility_page( WP_Post $post ) {
			if ( function_exists( 'wc_get_page_id' ) && in_array( $post->ID, array_map( 'wc_get_page_id', array( 'cart', 'checkout', 'myaccount' ) ), true ) ) {
				return true;
			}
			if ( 'page' === $post->post_type && preg_match( '/\[(woocommerce_cart|woocommerce_checkout|woocommerce_my_account|edd_checkout|edd_profile_editor|dokan-dashboard|wcfm_vendor_dashboard|pmpro_account|mepr-account-form)\b|wp:woocommerce\/(cart|checkout)/', (string) $post->post_content ) ) {
				return true;
			}
			$slug = (string) $post->post_name;
			$util = in_array( $slug, array( 'cart', 'checkout', 'my-account', 'account', 'dashboard', 'basket' ), true );
			/**
			 * Whether a page is a personal utility screen left out of llms files.
			 *
			 * @param bool    $util Detected.
			 * @param WP_Post $post Post.
			 */
			return (bool) apply_filters( 'uichemy_agent_ready_llms_utility_page', $util, $post );
		}

		/**
		 * One link line for a post.
		 *
		 * @param WP_Post $post Post.
		 * @return string
		 */
		private static function item( WP_Post $post ) {
			$note = Uich_Agent_Access::public_text( $post, 24, true );
			$url  = Uich_Agent_Settings::value( 'agent_pages.md_urls', true ) ? Uich_Agent_Urls::md_url( get_permalink( $post ) ) : get_permalink( $post );
			$title = str_replace( array( '[', ']' ), '', self::title( $post ) );
			return '- [' . $title . '](' . esc_url_raw( $url ) . ')' . ( '' !== $note ? ': ' . self::line( $note ) : '' );
		}

		/**
		 * A post's title for llms files; untitled posts use their address words.
		 *
		 * @param WP_Post $post Post.
		 * @return string
		 */
		public static function title( WP_Post $post ) {
			$title = trim( self::line( wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) ) );
			if ( '' === $title ) {
				$title = ucwords( trim( str_replace( array( '-', '_' ), ' ', (string) $post->post_name ) ) );
				// WordPress names untitled pages by ID ("22-2"): not a useful name.
				$title = '' !== $title && ! preg_match( '/^[\d\s]+$/', $title ) ? $title : 'Untitled';
			}
			return $title;
		}

		/**
		 * One line of plain text.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private static function line( $text ) {
			return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		}

		/**
		 * A plain-text block (no headings, so it cannot break the file's structure).
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private static function block( $text ) {
			$lines = preg_split( '/\R/', wp_strip_all_tags( (string) $text ) );
			$lines = array_map(
				function ( $l ) {
					return ltrim( $l, "# \t" );
				},
				$lines
			);
			return trim( implode( "\n", $lines ) );
		}

		/* -------------------------------------------------------------
		 * Ability actions
		 * ----------------------------------------------------------- */

		/**
		 * Add llms actions.
		 *
		 * @param array $actions Actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['get-llms']           = array(
				'description' => 'The llms.txt agents receive now, its mode (generated, claude or manual) and its address.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_get' ),
			);
			$actions['build-llms-context'] = array(
				'description' => 'Everything needed to write a top-quality llms.txt: site name and tagline, site profile, MCP address and tools, key page candidates with descriptions, sections with counts, and the generated draft. Read this, write the file, run validate-llms, then update-llms.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_context' ),
			);
			$actions['validate-llms']      = array(
				'description' => 'Check llms.txt text against the llmstxt.org format and this site: H1 first, optional blockquote, H2 sections with "- [name](url): note" links, links on this site resolve, size. Returns problems to fix.',
				'params'      => array(
					'text' => array(
						'type'        => 'string',
						'description' => 'The llms.txt text to check. Omit to check the live file.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_validate' ),
			);
			$actions['update-llms']        = array(
				'description' => 'Save a written llms.txt (mode "claude" or "manual"); from then on it is served as written and not regenerated. Send mode "generated" with no text to go back to the automatic file.',
				'params'      => array(
					'text' => array(
						'type'        => 'string',
						'description' => 'Full llms.txt text (max 200 KB).',
					),
					'mode' => array(
						'type'        => 'string',
						'enum'        => array( 'claude', 'manual', 'generated' ),
						'description' => 'Who wrote it. Default claude.',
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'act_update' ),
			);
			return $actions;
		}

		/**
		 * Action: get-llms.
		 *
		 * @return array
		 */
		public static function act_get() {
			return array(
				'url'  => home_url( '/llms.txt' ),
				'mode' => Uich_Agent_Settings::value( 'llms.mode', 'generated' ),
				'text' => self::main(),
			);
		}

		/**
		 * Action: build-llms-context.
		 *
		 * @return array
		 */
		public static function act_context() {
			$sections = array();
			foreach ( self::section_types() as $type ) {
				$obj        = get_post_type_object( $type );
				$sections[] = array(
					'type'  => $type,
					'label' => $obj ? $obj->labels->name : $type,
					'count' => self::count( $type ),
					'file'  => home_url( '/llms/' . $type . '.txt' ),
				);
			}
			$pages = array();
			foreach ( self::key_pages( array() ) as $post ) {
				$pages[] = array(
					'id'        => $post->ID,
					'title'     => get_the_title( $post ),
					'agent_url' => Uich_Agent_Urls::md_url( get_permalink( $post ) ),
					'summary'   => Uich_Agent_Access::public_text( $post, 40, true ),
				);
			}
			return array(
				'site'      => array(
					'name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'tagline' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
					'url'     => home_url( '/' ),
				),
				'site_info' => class_exists( 'Uich_Agent_Builtin_Tools' ) ? Uich_Agent_Builtin_Tools::site_info() : array(),
				'mcp'       => class_exists( 'Uich_Agent_MCP' ) && Uich_Agent_MCP::public_server() ? Uich_Agent_MCP::url() : '',
				'key_pages' => $pages,
				'sections'  => $sections,
				'draft'     => self::generate(),
				'format'    => 'H1 site name (required) > one-line blockquote summary > short plain paragraphs > "## Section" headings with "- [Name](url): note" lines > "## Optional" for links agents may skip. Lead with what an agent can do here (tools for browser AI, agent pages, how to contact or book), use agent (.md) links, state facts only from the site, no marketing language.',
			);
		}

		/**
		 * Action: validate-llms.
		 *
		 * @param array $params { text? }.
		 * @return array
		 */
		public static function act_validate( array $params ) {
			$text     = isset( $params['text'] ) ? (string) $params['text'] : self::main();
			$problems = array();
			$lines    = preg_split( '/\R/', $text );
			$first    = '';
			foreach ( $lines as $l ) {
				if ( '' !== trim( $l ) ) {
					$first = trim( $l );
					break;
				}
			}
			if ( ! preg_match( '/^# \S/', $first ) ) {
				$problems[] = 'The first line must be an H1 with the site name ("# Name").';
			}
			if ( preg_match_all( '/^# /m', $text ) > 1 ) {
				$problems[] = 'Use only one H1.';
			}
			if ( preg_match( '/^#{3,6} /m', $text ) ) {
				$problems[] = 'Use only H1 and H2 headings; deeper headings are not part of the format.';
			}
			$links  = 0;
			$broken = array();
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $lines as $l ) {
				if ( ! preg_match( '/^\s*-\s/', $l ) ) {
					continue;
				}
				// A line that starts with a link must be "- [name](url): note". Other list
				// lines are plain notes (the tools, Browser AI, forms) and may hold links
				// inside the text; those links are still checked below.
				if ( preg_match( '/^\s*- \[/', $l ) ) {
					if ( ! preg_match( '/^\s*- \[[^\]]+\]\(([^)\s]+)\)(: .+)?\s*$/', $l, $m ) ) {
						$problems[] = 'List line not in "- [name](url): note" form: ' . mb_substr( trim( $l ), 0, 80 );
						continue;
					}
					$urls = array( $m[1] );
				} else {
					preg_match_all( '/\[[^\]]+\]\(([^)\s]+)\)/', $l, $found );
					$urls = $found[1];
					if ( ! $urls && false !== strpos( $l, '](' ) ) {
						$problems[] = 'Broken link in: ' . mb_substr( trim( $l ), 0, 80 );
						continue;
					}
				}
				foreach ( $urls as $url ) {
					$links += self::check_link( $url, $host, $broken );
				}
			}
			if ( ! $links ) {
				$problems[] = 'No links found: list the key pages and what agents can use.';
			}
			foreach ( $broken as $url ) {
				$problems[] = 'Link may not resolve on this site: ' . $url;
			}
			if ( strlen( $text ) > 200 * 1024 ) {
				$problems[] = 'Larger than 200 KB; move long lists to section files.';
			}
			// Only a public MCP server has an address outside assistants should find here.
			if ( class_exists( 'Uich_Agent_MCP' ) && Uich_Agent_MCP::public_server() && false === stripos( $text, '/' . Uich_Agent_MCP::path() ) && Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				$problems[] = 'The site MCP address is missing; agents use it to act on the site.';
			}
			return array(
				'ok'       => empty( $problems ),
				'links'    => $links,
				'bytes'    => strlen( $text ),
				'problems' => $problems,
			);
		}

		/**
		 * Count one link and note it when it points at nothing on this site.
		 *
		 * @param string $url    Link target.
		 * @param string $host   This site's host.
		 * @param array  $broken Links that may not resolve (added to).
		 * @return int 1, so the caller can count links.
		 */
		private static function check_link( $url, $host, array &$broken ) {
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host || count( $broken ) >= 20 ) {
				return 1;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$page = Uich_Agent_Urls::page_path( $path );
			$id   = url_to_postid( home_url( false !== $page ? $page : $path ) );
			if ( ! $id ) {
				// Pages that also serve an archive (e.g. the WooCommerce shop) resolve by path,
				// whether the link is to the agent file or to the HTML page.
				$by_path = get_page_by_path( trim( substr( false !== $page ? $page : $path, strlen( Uich_Agent_Urls::home_path() ) ), '/' ) );
				$id      = $by_path ? $by_path->ID : 0;
			}
			$known = $id || in_array( untrailingslashit( $path ), array( '', '/index.md', '/llms-full.txt', '/' . Uich_Agent_Settings::value( 'mcp.path', 'mcp' ) ), true ) || 0 === strpos( $path, '/llms/' ) || false !== strpos( $path, '/category/' ) || false !== strpos( $path, '/tag/' );
			if ( ! $known ) {
				$broken[] = $url;
			}
			return 1;
		}

		/**
		 * Action: update-llms.
		 *
		 * @param array $params { text?, mode? }.
		 * @return array|WP_Error
		 */
		public static function act_update( array $params ) {
			$mode = isset( $params['mode'] ) ? (string) $params['mode'] : 'claude';
			if ( 'generated' === $mode ) {
				Uich_Agent_Settings::update( array( 'llms' => array( 'mode' => 'generated' ) ) );
				return array(
					'mode' => 'generated',
					'text' => self::generate(),
				);
			}
			$text = isset( $params['text'] ) ? (string) $params['text'] : '';
			$text = trim( wp_strip_all_tags( str_replace( "\r\n", "\n", $text ) ) );
			if ( '' === $text || strlen( $text ) > 200 * 1024 ) {
				return new WP_Error( 'uich_agent_llms', 'Send the full llms.txt text (1 byte to 200 KB).' );
			}
			$saved = Uich_Agent_Store::write( self::FILE, $text . "\n" );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			Uich_Agent_Settings::update( array( 'llms' => array( 'mode' => 'manual' === $mode ? 'manual' : 'claude' ) ) );
			return array(
				'mode'       => 'manual' === $mode ? 'manual' : 'claude',
				'url'        => home_url( '/llms.txt' ),
				'validation' => self::act_validate( array( 'text' => $text ) ),
			);
		}
	}
}
