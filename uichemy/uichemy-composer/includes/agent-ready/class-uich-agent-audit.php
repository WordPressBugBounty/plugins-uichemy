<?php
/**
 * Agent Ready audit: check the site the way an agent meets it, and score it.
 *
 * Every check fetches the site's own public URLs over HTTP as a logged-out
 * visitor (loopback), so it sees what agents see, including caching and bot
 * protection in front of WordPress. Checks are reported against the open
 * AgentReady specification's rule IDs (AR-FIND, AR-READ, AR-ACT) so agencies
 * can show clients a standard list, plus a few UiChemy safety checks (SAFE).
 *
 * Score: MUST = 3, SHOULD = 2, MAY = 1 points. Pass earns all of them, warn
 * half, fail none; checks that do not apply to this site are left out.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Audit' ) ) {

	/**
	 * Audit, agent preview and log actions.
	 */
	class Uich_Agent_Audit {

		/**
		 * Stored result in the private folder.
		 */
		const FILE = 'audit.json';

		/**
		 * Points by level.
		 */
		const WEIGHTS = array(
			'MUST'   => 3,
			'SHOULD' => 2,
			'MAY'    => 1,
		);

		/**
		 * The AI crawlers checked against robots.txt and bot protection.
		 */
		const AGENTS = array(
			'GPTBot'          => 'GPTBot/1.2 (+https://openai.com/gptbot)',
			'OAI-SearchBot'   => 'OAI-SearchBot/1.0 (+https://openai.com/searchbot)',
			'ClaudeBot'       => 'ClaudeBot/1.0 (+claudebot@anthropic.com)',
			'Claude-User'     => 'Claude-User/1.0 (+Claude-User@anthropic.com)',
			'PerplexityBot'   => 'PerplexityBot/1.0 (+https://perplexity.ai/perplexitybot)',
			'Google-Extended' => 'Mozilla/5.0 (compatible; Google-Extended)',
		);

		/**
		 * Responses fetched during one audit, by key.
		 *
		 * @var array
		 */
		private static $cache = array();

		/**
		 * Why the server could not reach its own site during the current check, if it could not.
		 *
		 * @var string
		 */
		private static $unreachable = '';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
			add_filter( 'uichemy_agent_ready_status', array( __CLASS__, 'status' ) );
			add_filter( 'uichemy_seo_ai_answers', array( __CLASS__, 'seo_summary' ) );
		}

		/**
		 * The "AI answers" block in the SEO audit: last score and what fails.
		 *
		 * @param array $out Default summary.
		 * @return array
		 */
		public static function seo_summary( $out ) {
			$last = Uich_Agent_Store::read_json( self::FILE );
			if ( ! is_array( $last ) || is_wp_error( $last ) || ! isset( $last['score'] ) ) {
				return $out;
			}
			$order   = array(
				'fail' => 0,
				'warn' => 1,
			);
			$failing = array();
			foreach ( (array) $last['checks'] as $c ) {
				if ( isset( $order[ $c['status'] ] ) ) {
					$failing[] = array(
						'id'     => $c['id'],
						'title'  => $c['title'],
						'status' => $c['status'],
						'level'  => $c['level'],
					);
				}
			}
			usort(
				$failing,
				function ( $a, $b ) use ( $order ) {
					return $order[ $a['status'] ] <=> $order[ $b['status'] ];
				}
			);
			return array(
				'state'   => 'ready',
				'score'   => (int) $last['score'],
				'ran_at'  => (int) $last['ran_at'],
				'failing' => array_slice( $failing, 0, 5 ),
				'more'    => max( 0, count( $failing ) - 5 ),
				'text'    => $failing
					? __( 'Some checks for AI assistants need attention. Open Agent Ready to fix them.', 'uichemy' )
					: __( 'Every check for AI assistants passes.', 'uichemy' ),
			);
		}

		/**
		 * Add the actions.
		 *
		 * @param array $actions Existing actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['run-audit']     = array(
				'description' => 'Check the live site the way an AI agent meets it (as a logged-out visitor, over HTTP) and score it 0-100. Results use the AgentReady specification\'s rule IDs (AR-FIND, AR-READ, AR-ACT) plus UiChemy safety checks (SAFE), each with pass/warn/fail, what was seen, and how to fix it. Saves the result for the dashboard.',
				'params'      => array(),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'run' ),
			);
			$actions['get-audit']     = array(
				'description' => 'The last saved audit result and score, without running it again.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'last' ),
			);
			$actions['test-as-agent'] = array(
				'description' => 'Fetch one page of this site exactly as an agent would: the agent file (Markdown) with its status and headers, word count, the first part of the text, and why it is refused if it is. Use it to check a page before and after changes.',
				'params'      => array(
					'url' => array(
						'type'        => 'string',
						'description' => 'A page URL or path on this site, e.g. "/menu/".',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'test_as_agent' ),
			);
			$actions['get-agent-log'] = array(
				'description' => 'What agents did on the site: totals by kind (agent pages, MCP calls), a total per day, unique visitors, errors, tools used and agent names, plus the latest entries. Visitor IPs are never stored, only a keyed hash.',
				'params'      => array(
					'days'  => array(
						'type'        => 'integer',
						'description' => 'Days back, 1 up to the log retention setting (default 7).',
					),
					'limit' => array(
						'type'        => 'integer',
						'description' => 'Latest entries to return, 0-200 (default 50).',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'agent_log' ),
			);
			return $actions;
		}

		/**
		 * Last audit score in the status summary.
		 *
		 * @param array $status Status.
		 * @return array
		 */
		public static function status( array $status ) {
			$last              = Uich_Agent_Store::read_json( self::FILE );
			$status['audit'] = is_array( $last ) && ! is_wp_error( $last ) ? array(
				'score'   => isset( $last['score'] ) ? (int) $last['score'] : null,
				'ran_at'  => isset( $last['ran_at'] ) ? (int) $last['ran_at'] : 0,
				'failing' => isset( $last['counts']['fail'] ) ? (int) $last['counts']['fail'] : 0,
			) : null;
			return $status;
		}

		/* ------------------------------------------------------------------ */
		/* run-audit                                                           */
		/* ------------------------------------------------------------------ */

		/**
		 * Action: run-audit.
		 *
		 * @return array
		 */
		public static function run() {
			self::$cache = array();
			$started     = microtime( true );
			$checks      = array();
			foreach ( self::checks() as $id => $check ) {
				self::$unreachable = '';
				try {
					$result = call_user_func( $check[2] );
				} catch ( Throwable $e ) {
					$result = array( 'fail', 'The check could not run: ' . $e->getMessage() );
				}
				// The server could not reach its own pages (a timeout, a busy or blocked
				// loopback). That says nothing about what agents meet, so the check is not
				// scored as a problem with the site.
				if ( '' !== self::$unreachable && 'pass' !== $result[0] ) {
					$result = array( 'na', 'Could not check right now: this server could not reach its own site (' . self::$unreachable . '). Press Check again in a minute.' );
				}
				$checks[] = array(
					'id'     => $id,
					'title'  => $check[0],
					'level'  => $check[1],
					'status' => $result[0],
					'seen'   => $result[1],
					'fix'    => isset( $result[2] ) ? $result[2] : '',
				);
			}

			$earned = 0;
			$total  = 0;
			$counts = array(
				'pass' => 0,
				'warn' => 0,
				'fail' => 0,
				'na'   => 0,
			);
			foreach ( $checks as $c ) {
				++$counts[ $c['status'] ];
				if ( 'na' === $c['status'] ) {
					continue;
				}
				$w      = self::WEIGHTS[ $c['level'] ];
				$total += $w;
				$earned += 'pass' === $c['status'] ? $w : ( 'warn' === $c['status'] ? $w / 2 : 0 );
			}

			$result = array(
				'score'   => $total ? (int) round( 100 * $earned / $total ) : 0,
				'counts'  => $counts,
				'checks'  => $checks,
				'ran_at'  => time(),
				'seconds' => round( microtime( true ) - $started, 1 ),
				'spec'    => 'AgentReady open specification (agentready.org) rule IDs; SAFE checks are UiChemy\'s own.',
				'inputs'  => self::fingerprint(),
			);
			Uich_Agent_Store::write_json( self::FILE, $result );
			return $result;
		}

		/**
		 * Action: get-audit.
		 *
		 * @return array
		 */
		public static function last() {
			$last = Uich_Agent_Store::read_json( self::FILE );
			if ( ! is_array( $last ) || is_wp_error( $last ) ) {
				return array(
					'score' => null,
					'note'  => 'No check has run yet.',
				);
			}
			// Settings, forms, tools, business info or pages changed since: the score is out of date.
			$last['stale'] = ! isset( $last['inputs'] ) || $last['inputs'] !== self::fingerprint();
			return $last;
		}

		/**
		 * A short hash of everything the checks depend on.
		 *
		 * @return string
		 */
		public static function fingerprint() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$modified = (string) $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_status = 'publish'" );
			return md5(
				(string) wp_json_encode(
					array(
						Uich_Agent_Settings::get(),
						class_exists( 'Uich_Agent_Forms' ) ? Uich_Agent_Forms::registry() : array(),
						class_exists( 'Uich_Agent_Tools' ) ? array_keys( Uich_Agent_Tools::definitions() ) : array(),
						class_exists( 'Uich_Agent_Profile' ) ? Uich_Agent_Profile::get() : array(),
						$modified,
						get_option( 'show_on_front' ),
						get_option( 'page_on_front' ),
						get_option( 'blog_public' ),
					)
				)
			);
		}

		/**
		 * The home page lists posts and there are none, so it says "Nothing found".
		 *
		 * @return bool
		 */
		private static function empty_home() {
			$counts = wp_count_posts( 'post' );
			return 'posts' === get_option( 'show_on_front' ) && empty( $counts->publish );
		}

		/**
		 * Published pages whose text shows a price, as [ title, sample ] each.
		 *
		 * @return array
		 */
		private static function pages_with_price() {
			$out = array();
			$ids = get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'numberposts' => 100,
					'orderby'     => 'modified',
					'fields'      => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				// Only pages agents can see: a hidden or members-only price is not public.
				if ( ! Uich_Agent_Access::listable( $id ) ) {
					continue;
				}
				$raw = (string) get_post_field( 'post_content', $id );
				foreach ( array( '_uichemy_content', '_elementor_data', '_bricks_page_content_2' ) as $key ) {
					$meta = get_post_meta( $id, $key, true );
					$raw .= ' ' . ( is_string( $meta ) ? $meta : (string) wp_json_encode( $meta ) );
				}
				$text = html_entity_decode( wp_strip_all_tags( str_replace( array( '\\u003c', '\\u003e', '\\/' ), array( '<', '>', '/' ), $raw ) ), ENT_QUOTES, 'UTF-8' );
				if ( preg_match( '/(?:[$€£₹¥]\s?\d[\d,.]*|\b\d[\d,.]*\s?(?:USD|EUR|GBP|INR|AUD|CAD)\b)/u', $text, $m ) ) {
					$out[] = array( get_the_title( $id ), trim( $m[0] ) );
				}
			}
			return $out;
		}

		/**
		 * The checks: id => [ title, level, callable returning [status, seen, fix] ].
		 *
		 * @return array
		 */
		private static function checks() {
			$checks = array(
				'AR-FIND-01' => array( 'Agents allowed in robots.txt and through bot protection', 'MUST', array( __CLASS__, 'c_find_robots' ) ),
				'AR-FIND-02' => array( 'First-party facts agents can quote', 'SHOULD', array( __CLASS__, 'c_find_facts' ) ),
				'AR-FIND-03' => array( 'Pages listed in a sitemap', 'SHOULD', array( __CLASS__, 'c_find_sitemap' ) ),
				'AR-FIND-04' => array( 'AI catalog published and linked', 'MAY', array( __CLASS__, 'c_find_catalog' ) ),
				'AR-READ-01' => array( 'Content readable without JavaScript', 'MUST', array( __CLASS__, 'c_read_nojs' ) ),
				'AR-READ-02' => array( 'Real HTTP status codes', 'MUST', array( __CLASS__, 'c_read_status' ) ),
				'AR-READ-03' => array( 'Home page in raw HTML with one H1', 'SHOULD', array( __CLASS__, 'c_read_home' ) ),
				'AR-READ-06' => array( '.well-known discovery files', 'SHOULD', array( __CLASS__, 'c_read_wellknown' ) ),
				'AR-READ-07' => array( 'llms.txt', 'SHOULD', array( __CLASS__, 'c_read_llms' ) ),
				'AR-READ-08' => array( 'JSON-LD describing the business', 'SHOULD', array( __CLASS__, 'c_read_jsonld' ) ),
				'AR-READ-09' => array( 'Markdown versions of pages', 'MAY', array( __CLASS__, 'c_read_markdown' ) ),
				'AR-READ-11' => array( 'Prices on a first-party page', 'SHOULD', array( __CLASS__, 'c_read_pricing' ) ),
				'AR-ACT-01'  => array( 'Agents can act without a sign-in wall', 'SHOULD', array( __CLASS__, 'c_act_auth' ) ),
				'AR-ACT-02'  => array( 'Machine-readable API contract', 'SHOULD', array( __CLASS__, 'c_act_contract' ) ),
				'AR-ACT-03'  => array( 'Site tools answer', 'SHOULD', array( __CLASS__, 'c_act_mcp' ) ),
				'AR-ACT-04'  => array( 'MCP server card', 'MAY', array( __CLASS__, 'c_act_card' ) ),
				'AR-ACT-07'  => array( 'WebMCP on forms', 'SHOULD', array( __CLASS__, 'c_act_webmcp' ) ),
				'AR-ACT-08'  => array( 'Agents can hand off a purchase', 'MAY', array( __CLASS__, 'c_act_pay' ) ),
				'AR-ACT-10'  => array( 'Agents can complete a task', 'MUST', array( __CLASS__, 'c_act_task' ) ),
				'SAFE-01'    => array( 'Private and password pages stay private', 'MUST', array( __CLASS__, 'c_safe_private' ) ),
				'SAFE-02'    => array( 'Agent files do not compete with pages in search', 'MUST', array( __CLASS__, 'c_safe_canonical' ) ),
				'SAFE-03'    => array( 'Private folder and logs blocked from the web', 'MUST', array( __CLASS__, 'c_safe_folder' ) ),
				'SAFE-04'    => array( 'Content-Signal states AI training preference', 'SHOULD', array( __CLASS__, 'c_safe_signal' ) ),
			);
			/**
			 * Add or remove audit checks.
			 *
			 * @param array $checks id => [ title, level (MUST|SHOULD|MAY), callable returning [ status, seen, fix ] ].
			 */
			return apply_filters( 'uichemy_agent_ready_audit_checks', $checks );
		}

		/* ---- Find ---- */

		/**
		 * AR-FIND-01.
		 *
		 * @return array
		 */
		public static function c_find_robots() {
			$robots  = self::get( '/robots.txt' );
			$blocked = array();
			if ( 200 === $robots['code'] ) {
				foreach ( array_keys( self::AGENTS ) as $bot ) {
					if ( self::robots_blocks_root( $robots['body'], $bot ) ) {
						$blocked[] = $bot;
					}
				}
			}
			$walled = array();
			foreach ( array( 'ClaudeBot', 'GPTBot', 'PerplexityBot' ) as $bot ) {
				$r = self::get( '/', array( 'User-Agent' => self::AGENTS[ $bot ] ), 'home-' . $bot );
				if ( in_array( $r['code'], array( 401, 403, 429, 503 ), true ) ) {
					$walled[] = $bot . ' (' . $r['code'] . ')';
				}
			}
			if ( $walled ) {
				return array( 'fail', 'Bot protection refused: ' . implode( ', ', $walled ) . '.', 'Allow these agents in the firewall or CDN bot settings (Cloudflare, Wordfence, host WAF).' );
			}
			$ai_bots  = array_diff( $blocked, array( 'GPTBot', 'Google-Extended' ) );
			$training = array_intersect( $blocked, array( 'GPTBot', 'Google-Extended' ) );
			if ( $ai_bots ) {
				return array( 'fail', 'robots.txt blocks the whole site for: ' . implode( ', ', $ai_bots ) . '.', 'Turn on Search or AI answers in Agent Ready > Help AI find and use your site, or remove the Disallow lines added by another plugin.' );
			}
			$seen = 200 === $robots['code'] ? 'Search and answer agents are allowed.' : 'No robots.txt (agents treat that as allowed).';
			if ( $training ) {
				$seen .= ' Training crawlers blocked by choice: ' . implode( ', ', $training ) . '.';
			}
			return array( 'pass', $seen );
		}

		/**
		 * Whether robots.txt disallows "/" for a bot (its own group, else "*").
		 *
		 * @param string $txt robots.txt.
		 * @param string $bot Token.
		 * @return bool
		 */
		private static function robots_blocks_root( $txt, $bot ) {
			$groups  = array();
			$current = array();
			$agents  = array();
			$reading = false;
			foreach ( preg_split( '/\R/', (string) $txt ) as $line ) {
				$line = trim( preg_replace( '/#.*/', '', $line ) );
				if ( '' === $line || false === strpos( $line, ':' ) ) {
					continue;
				}
				list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
				$k             = strtolower( $k );
				if ( 'user-agent' === $k ) {
					if ( ! $reading && $agents ) {
						$groups[] = array( $agents, $current );
						$agents   = array();
						$current  = array();
					}
					$agents[] = strtolower( $v );
					$reading  = true;
					continue;
				}
				$reading = false;
				if ( in_array( $k, array( 'allow', 'disallow' ), true ) ) {
					$current[] = array( $k, $v );
				}
			}
			if ( $agents ) {
				$groups[] = array( $agents, $current );
			}
			$rules = null;
			foreach ( $groups as $g ) {
				if ( in_array( strtolower( $bot ), $g[0], true ) ) {
					$rules = $g[1];
				}
			}
			if ( null === $rules ) {
				foreach ( $groups as $g ) {
					if ( in_array( '*', $g[0], true ) ) {
						$rules = $g[1];
					}
				}
			}
			$disallow_root = false;
			foreach ( (array) $rules as $r ) {
				if ( '/' === $r[1] ) {
					$disallow_root = 'disallow' === $r[0] ? true : false;
					if ( 'allow' === $r[0] ) {
						return false;
					}
				}
			}
			return $disallow_root;
		}

		/**
		 * AR-FIND-02.
		 *
		 * @return array
		 */
		public static function c_find_facts() {
			$p    = Uich_Agent_Profile::get();
			$miss = array();
			if ( empty( $p['name'] ) ) {
				$miss[] = 'name';
			}
			if ( empty( $p['summary'] ) ) {
				$miss[] = 'summary';
			}
			if ( empty( $p['locations'] ) && empty( $p['contacts']['phone'] ) && empty( $p['contacts']['email'] ) && empty( $p['contacts']['booking_url'] ) ) {
				$miss[] = 'contact or location';
			}
			if ( ! Uich_Agent_Profile::exists() ) {
				return array( 'warn', 'No site profile yet: agents have to guess the business facts from page text.', 'Fill in Agent Ready > Business info ("Fill from my site" drafts it for you).' );
			}
			if ( $miss ) {
				return array( 'warn', 'Site profile is missing: ' . implode( ', ', $miss ) . '.', 'Add these in Agent Ready > Business info.' );
			}
			return array( 'pass', 'Site profile has the name, summary and contact details; they appear in llms.txt, agent pages and get_site_info.' );
		}

		/**
		 * AR-FIND-03.
		 *
		 * @return array
		 */
		public static function c_find_sitemap() {
			foreach ( array( '/wp-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml' ) as $path ) {
				$r = self::get( $path );
				if ( 200 === $r['code'] && false !== stripos( $r['body'], '<urlset' ) || ( 200 === $r['code'] && false !== stripos( $r['body'], '<sitemapindex' ) ) ) {
					return array( 'pass', 'Sitemap at ' . $path . '.' );
				}
			}
			return array( 'fail', 'No sitemap found at /wp-sitemap.xml, /sitemap.xml or /sitemap_index.xml.', 'Turn on the sitemap in UiChemy SEO, or check that another plugin has not disabled the WordPress sitemap.' );
		}

		/**
		 * AR-FIND-04.
		 *
		 * @return array
		 */
		public static function c_find_catalog() {
			$r = self::get( '/.well-known/ai-catalog.json' );
			if ( 200 !== $r['code'] || ! is_array( json_decode( $r['body'], true ) ) ) {
				return array( 'warn', 'No AI catalog at /.well-known/ai-catalog.json.', 'Switch on the AI catalog file in Agent Ready > Discovery files.' );
			}
			return array( 'pass', 'AI catalog published at /.well-known/ai-catalog.json.' );
		}

		/* ---- Read ---- */

		/**
		 * AR-READ-01.
		 *
		 * @return array
		 */
		public static function c_read_nojs() {
			$home = self::get( '/' );
			if ( 200 !== $home['code'] ) {
				return array( 'fail', 'Home page returned HTTP ' . $home['code'] . ' to a logged-out visitor.' );
			}
			$conv  = Uich_Agent_Convert::page( $home['body'], home_url( '/' ) );
			$words = str_word_count( wp_strip_all_tags( $conv['markdown'] ) );
			if ( $words < 40 ) {
				if ( self::empty_home() ) {
					return array( 'fail', sprintf( 'The home page has only %d words: it shows your latest posts, and there are none yet.', $words ), 'Choose a home page in WordPress Settings > Reading (A static page), or publish posts.' );
				}
				return array( 'fail', sprintf( 'Only %d words of text in the HTML before JavaScript runs.', $words ), 'Make sure the main content is in the server-rendered HTML, not loaded by scripts.' );
			}
			return array( 'pass', sprintf( '%d words of content in the server HTML.', $words ) );
		}

		/**
		 * AR-READ-02.
		 *
		 * @return array
		 */
		public static function c_read_status() {
			$slug = 'agent-ready-missing-' . wp_generate_password( 8, false, false );
			$html = self::get( '/' . $slug . '/' );
			$md   = self::get( '/' . $slug . '.md' );
			$bad  = array();
			if ( 404 !== $html['code'] && 410 !== $html['code'] ) {
				$bad[] = 'a missing page returned ' . $html['code'];
			}
			if ( 404 !== $md['code'] ) {
				$bad[] = 'a missing agent file returned ' . $md['code'];
			}
			if ( $bad ) {
				return array( 'fail', ucfirst( implode( '; ', $bad ) ) . ' instead of 404.', 'Check redirect or "404 to home" plugins: agents treat a 200 as real content.' );
			}
			return array( 'pass', 'Missing pages and agent files return 404.' );
		}

		/**
		 * AR-READ-03.
		 *
		 * @return array
		 */
		public static function c_read_home() {
			$home = self::get( '/' );
			if ( 200 !== $home['code'] ) {
				return array( 'fail', 'Home page not reachable.' );
			}
			$h1 = preg_match_all( '#<h1[\s>]#i', $home['body'] );
			if ( 1 !== $h1 ) {
				if ( 0 === $h1 && self::empty_home() ) {
					return array( 'warn', 'The home page has no H1: it shows your latest posts, and there are none yet.', 'Choose a home page in WordPress Settings > Reading, with one H1 that names the business and what it does.' );
				}
				return array( 'warn', sprintf( 'Home page has %d H1 headings.', $h1 ), 'Use exactly one H1 that names the business and what it does.' );
			}
			return array( 'pass', 'One H1 in the home page HTML.' );
		}

		/**
		 * AR-READ-06.
		 *
		 * @return array
		 */
		public static function c_read_wellknown() {
			$found = array();
			foreach ( array( '/.well-known/api-catalog', '/.well-known/ai-catalog.json' ) as $path ) {
				if ( 200 === self::get( $path )['code'] ) {
					$found[] = $path;
				}
			}
			if ( ! $found ) {
				return array( 'fail', 'No .well-known discovery files.', 'Switch on the API catalog file in Agent Ready > Discovery files.' );
			}
			return array( 2 === count( $found ) ? 'pass' : 'warn', 'Found: ' . implode( ', ', $found ) . '.' );
		}

		/**
		 * AR-READ-07.
		 *
		 * @return array
		 */
		public static function c_read_llms() {
			$r    = self::get( '/llms.txt' );
			$over = Uich_Agent_Discovery::overrides();
			if ( isset( $over['/llms.txt'] ) ) {
				return array( 'warn', 'A file named llms.txt in your site\'s main folder is served instead of Agent Ready\'s llms.txt.', 'Delete or rename that llms.txt file on your server (by FTP or your host\'s file manager) so Agent Ready can serve its own.' );
			}
			if ( 200 !== $r['code'] ) {
				return array( 'fail', '/llms.txt returned ' . $r['code'] . '.', 'Another plugin or a static file may be answering /llms.txt; remove it so Agent Ready serves it.' );
			}
			$check = Uich_Agent_Llms::act_validate( array( 'text' => $r['body'] ) );
			if ( ! $check['ok'] ) {
				return array( 'warn', 'llms.txt has problems: ' . implode( ' ', array_slice( $check['problems'], 0, 3 ) ), 'Fix the listed links in Agent Ready > llms.txt, or switch it back to "Generated from your pages".' );
			}
			return array( 'pass', sprintf( 'llms.txt is valid: %d links, %s KB.', $check['links'], number_format_i18n( $check['bytes'] / 1024, 1 ) ) );
		}

		/**
		 * AR-READ-08.
		 *
		 * @return array
		 */
		public static function c_read_jsonld() {
			$home = self::get( '/' );
			preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $home['body'], $m );
			$types = array();
			foreach ( $m[1] as $json ) {
				$data = json_decode( html_entity_decode( $json ), true );
				array_walk_recursive(
					$data,
					function ( $v, $k ) use ( &$types ) {
						if ( '@type' === $k ) {
							$types[] = $v;
						}
					}
				);
			}
			if ( ! $m[1] ) {
				return array( 'fail', 'No JSON-LD on the home page.', 'Turn on schema in UiChemy SEO; the site profile adds the business details to it.' );
			}
			$biz = array_filter(
				$types,
				function ( $t ) {
					return in_array( $t, array( 'Organization', 'LocalBusiness', 'Person' ), true ) || in_array( $t, Uich_Agent_Profile::types(), true );
				}
			);
			if ( ! $biz ) {
				return array( 'warn', 'JSON-LD found (' . implode( ', ', array_slice( array_unique( $types ), 0, 5 ) ) . ') but nothing describes the business.', 'Set the business type in Agent Ready > Business info.' );
			}
			return array( 'pass', 'JSON-LD includes ' . implode( ', ', array_unique( $biz ) ) . '.' );
		}

		/**
		 * AR-READ-09.
		 *
		 * @return array
		 */
		public static function c_read_markdown() {
			$md   = self::get( '/index.md' );
			$neg  = self::get( '/', array( 'Accept' => 'text/markdown' ), 'home-accept' );
			$seen = array();
			$ok   = 200 === $md['code'] && 0 === stripos( (string) $md['type'], 'text/markdown' );
			$seen[] = '/index.md ' . ( $ok ? 'works' : 'returned ' . $md['code'] );
			$neg_ok = 200 === $neg['code'] && 0 === stripos( (string) $neg['type'], 'text/markdown' );
			$seen[] = 'Accept: text/markdown ' . ( $neg_ok ? 'works' : 'returns HTML' );
			if ( ! $ok ) {
				return array( 'fail', implode( '; ', $seen ) . '.', 'Switch on .md addresses in Agent Ready > Setup > step 1. A page cache in front may be answering first.' );
			}
			if ( ! $neg_ok ) {
				return array( 'warn', implode( '; ', $seen ) . '.', 'A page cache is serving cached HTML to Markdown requests: bypass the cache when Accept contains text/markdown.' );
			}
			return array( 'pass', implode( '; ', $seen ) . '.' );
		}

		/**
		 * AR-READ-11.
		 *
		 * @return array
		 */
		public static function c_read_pricing() {
			if ( class_exists( 'WooCommerce' ) ) {
				return array( 'pass', 'Product prices are public through the shop pages and the search_products tool.' );
			}
			$p = Uich_Agent_Profile::get();
			if ( ! empty( $p['price_range'] ) ) {
				return array( 'pass', 'Price range in the site profile: ' . $p['price_range'] . '.' );
			}
			$found = self::pages_with_price();
			if ( $found ) {
				// Every page with prices, so the owner sees the whole picture (a few by name, then a count).
				$names = array();
				foreach ( array_slice( $found, 0, 5 ) as $f ) {
					$names[] = sprintf( '"%1$s" (%2$s)', $f[0], $f[1] );
				}
				$more = count( $found ) > 5 ? sprintf( ' and %d more', count( $found ) - 5 ) : '';
				$seen = 1 === count( $found )
					? sprintf( 'The page %s shows prices, but Business info has no price range.', $names[0] )
					: sprintf( '%1$d pages show prices: %2$s%3$s. Business info has no price range.', count( $found ), implode( ', ', $names ), $more );
				return array( 'warn', $seen, 'Add a price range in Agent Ready > Business info so agents can answer "how much".' );
			}
			// Many businesses never publish prices (a clinic, a law firm): nothing to fix.
			return array( 'na', 'No prices on the site\'s public pages.' );
		}

		/* ---- Act ---- */

		/**
		 * AR-ACT-01.
		 *
		 * @return array
		 */
		public static function c_act_auth() {
			if ( ! Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				return array( 'na', 'The site MCP is off.' );
			}
			return array( 'pass', 'Public tools need no sign-in; they only see what a logged-out visitor sees.' );
		}

		/**
		 * AR-ACT-02.
		 *
		 * @return array
		 */
		public static function c_act_contract() {
			if ( ! Uich_Agent_MCP::public_server() ) {
				return array( 'na', 'Not used: the site\'s tools are offered to AI in the visitor\'s browser (WebMCP), not as a public API.' );
			}
			$r = self::get( '/.well-known/api-catalog' );
			if ( 200 === $r['code'] && false !== strpos( $r['body'], Uich_Agent_MCP::url() ) ) {
				return array( 'pass', 'The API catalog lists the site MCP, whose tools/list is the machine-readable contract.' );
			}
			return array( 'warn', 'The API catalog does not list the site MCP.', 'Switch on the API catalog file in Agent Ready > Discovery files.' );
		}

		/**
		 * AR-ACT-03.
		 *
		 * @return array
		 */
		public static function c_act_mcp() {
			if ( ! Uich_Agent_MCP::public_server() ) {
				// Tools are for AI in the visitor's browser: check that they answer to the site's own pages.
				if ( ! Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
					return array( 'warn', 'Browser AI (WebMCP) is off, so no tools are offered.', 'Switch on Browser AI in Agent Ready > Setup > step 4.' );
				}
				$list  = self::mcp( 'tools/list', (object) array() );
				$count = isset( $list['result']['tools'] ) ? count( $list['result']['tools'] ) : 0;
				return array( $count ? 'pass' : 'warn', sprintf( '%d tool(s) answer for AI in the visitor\'s browser.', $count ) );
			}
			if ( ! Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				return array( 'fail', 'AI tools are off.', 'Switch on Browser AI in Agent Ready > Setup > step 4.' );
			}
			$init = self::mcp( 'initialize', array( 'protocolVersion' => Uich_Agent_MCP::VERSIONS[0], 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'uichemy-audit', 'version' => '1' ) ) );
			if ( empty( $init['result']['serverInfo'] ) ) {
				return array( 'fail', 'POST ' . Uich_Agent_MCP::url() . ' did not answer initialize (HTTP ' . $init['_code'] . ').', 'A firewall or cache may block POST to that address; allow it.' );
			}
			$list  = self::mcp( 'tools/list', (object) array() );
			$count = isset( $list['result']['tools'] ) ? count( $list['result']['tools'] ) : 0;
			return array( $count ? 'pass' : 'warn', sprintf( 'Site MCP answers at %s with %d tools.', Uich_Agent_MCP::url(), $count ) );
		}

		/**
		 * AR-ACT-04.
		 *
		 * @return array
		 */
		public static function c_act_card() {
			if ( ! Uich_Agent_MCP::public_server() ) {
				return array( 'na', 'Not used: there is no public MCP server to describe.' );
			}
			$path = '/' . trim( (string) Uich_Agent_Settings::value( 'mcp.path', 'mcp' ), '/' ) . '/server-card';
			$r    = self::get( $path );
			return 200 === $r['code'] ? array( 'pass', 'Server card at ' . $path . '.' ) : array( 'warn', 'No server card at ' . $path . '.', 'Switch on the MCP server card in Agent Ready > Discovery files.' );
		}

		/**
		 * AR-ACT-07.
		 *
		 * @return array
		 */
		public static function c_act_webmcp() {
			if ( ! class_exists( 'Uich_Forms' ) ) {
				return array( 'na', 'UiChemy forms are not in use.' );
			}
			$on = array_filter(
				Uich_Agent_Forms::registry(),
				function ( $f ) {
					return ! empty( $f['mode'] ) && 'off' !== $f['mode'];
				}
			);
			if ( ! Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) ) {
				return array( 'warn', 'WebMCP is switched off.', 'Switch on browser agents in Agent Ready > WebMCP.' );
			}
			if ( ! $on ) {
				// No form on any page: there is nothing to open to agents.
				$site = Uich_Agent_Forms::act_site_forms();
				$live = is_array( $site ) && isset( $site['forms'] ) ? array_filter(
					$site['forms'],
					function ( $f ) {
						return empty( $f['missing'] );
					}
				) : null;
				if ( array() === $live ) {
					return array( 'na', 'There are no UiChemy forms on the site\'s pages.' );
				}
				return array( 'warn', 'No form is open to agents yet.', 'Switch on your contact or booking form in Agent Ready > Setup > step 5.' );
			}
			return array( 'pass', sprintf( '%d form(s) carry WebMCP attributes and a tool for browser AI.', count( $on ) ) );
		}

		/**
		 * AR-ACT-08.
		 *
		 * @return array
		 */
		public static function c_act_pay() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return array( 'na', 'No shop on this site.' );
			}
			$off = (array) Uich_Agent_Settings::value( 'mcp.disabled_builtins', array() );
			if ( in_array( 'build_checkout_link', $off, true ) ) {
				return array( 'warn', 'The checkout hand-off tool is switched off.', 'Switch on "build_checkout_link" in Agent Ready > Setup > step 4 > Tools.' );
			}
			return array( 'pass', 'Agents can build a checkout link; the buyer pays in their own browser.' );
		}

		/**
		 * AR-ACT-10.
		 *
		 * @return array
		 */
		public static function c_act_task() {
			if ( ! Uich_Agent_Settings::value( 'mcp.enabled', true ) ) {
				return array( 'fail', 'AI tools are off, so agents cannot do anything on the site.', 'Switch on Browser AI in Agent Ready > Setup > step 4.' );
			}
			$tasks = array();
			$tools = Uich_Agent_Tools::enabled();
			foreach ( array( 'build_checkout_link', 'send_contact_request' ) as $t ) {
				if ( isset( $tools[ $t ] ) ) {
					$tasks[] = $t;
				}
			}
			foreach ( $tools as $name => $tool ) {
				if ( ! empty( $tool['annotations']['readOnlyHint'] ) || in_array( $name, $tasks, true ) ) {
					continue;
				}
				if ( isset( $tool['annotations']['readOnlyHint'] ) && false === $tool['annotations']['readOnlyHint'] ) {
					$tasks[] = $name;
				}
			}
			$switch = Uich_Agent_Settings::writes_enabled() ? '' : ' Agents cannot send forms or requests while "Let AI press Send by itself" is off (Agent Ready > Setup > step 5).';
			if ( ! $tasks ) {
				return array( 'warn', 'Agents can read the site but not complete a task (contact, book, buy).' . $switch, 'In Agent Ready, switch on a form (Setup > step 5), switch on “Send a request” or add a link tool to your booking page (Setup > step 4 > Tools).' );
			}
			return array( 'pass', 'Task tools: ' . implode( ', ', array_slice( $tasks, 0, 6 ) ) . '.' . $switch );
		}

		/* ---- Safety ---- */

		/**
		 * SAFE-01.
		 *
		 * @return array
		 */
		public static function c_safe_private() {
			$leaks = array();
			$posts = get_posts(
				array(
					'post_type'   => 'any',
					'post_status' => array( 'private', 'publish' ),
					'numberposts' => 20,
					'orderby'     => 'modified',
				)
			);
			$tested = 0;
			foreach ( $posts as $post ) {
				if ( 'private' !== $post->post_status && '' === $post->post_password ) {
					continue;
				}
				$url = self::pretty_url( $post );
				$md  = self::get( (string) wp_parse_url( Uich_Agent_Urls::md_url( $url ), PHP_URL_PATH ) );
				++$tested;
				if ( 200 === $md['code'] && false !== strpos( $md['body'], (string) get_the_title( $post ) ) && '' !== get_the_title( $post ) ) {
					$leaks[] = get_the_title( $post );
				}
				if ( $tested >= 5 ) {
					break;
				}
			}
			if ( $leaks ) {
				return array( 'fail', 'Agent files returned private content: ' . implode( ', ', $leaks ) . '.', 'Switch Agent Ready off (Settings > Features) and contact UiChemy support.' );
			}
			return array( 'pass', $tested ? sprintf( '%d private or password-protected item(s) checked: none reachable.', $tested ) : 'No private or password-protected content to check.' );
		}

		/**
		 * The address a private or password post would have if it were public.
		 * WordPress links private pages as ?page_id=N, which would map to
		 * /index.md and test nothing.
		 *
		 * @param WP_Post $post Post.
		 * @return string
		 */
		private static function pretty_url( WP_Post $post ) {
			if ( ! function_exists( 'get_sample_permalink' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			list( $template, $name ) = get_sample_permalink( $post->ID );
			$url                     = str_replace( array( '%pagename%', '%postname%' ), $name, (string) $template );
			return false === strpos( $url, '%' ) && false === strpos( $url, '?' ) ? $url : get_permalink( $post );
		}

		/**
		 * SAFE-02.
		 *
		 * @return array
		 */
		public static function c_safe_canonical() {
			$md = self::get( '/index.md' );
			if ( 200 !== $md['code'] ) {
				return array( 'na', 'Agent files are not served.' );
			}
			$link = (string) $md['link'];
			if ( false === stripos( $link, 'rel="canonical"' ) ) {
				return array( 'fail', 'Agent files have no canonical Link header.', 'Something strips response headers; check the server or CDN config.' );
			}
			return array( 'pass', 'Agent files point search engines to the HTML page with a canonical Link header.' );
		}

		/**
		 * SAFE-03.
		 *
		 * @return array
		 */
		public static function c_safe_folder() {
			// Settings, forms and the profile are in the database, so only the log files can be reached from the web.
			$open = array();
			if ( class_exists( 'Uich_Agent_Log' ) ) {
				Uich_Agent_Log::purge_legacy(); // Old plain-text logs are removed before they are probed.
			}
			$cache = Uich_Agent_Store::cache_root();
			$rel   = is_wp_error( $cache ) ? '/cache/uichemy-agent/' : substr( wp_normalize_path( $cache ), strlen( wp_normalize_path( WP_CONTENT_DIR ) ) );
			$logs  = self::get( (string) wp_parse_url( content_url( untrailingslashit( $rel ) . '/logs/' . gmdate( 'Y-m-d' ) . '.jsonl' ), PHP_URL_PATH ) );
			if ( 200 === $logs['code'] ) {
				$open[] = 'logs';
			}
			if ( $open ) {
				return array( 'fail', 'Readable from the web: ' . implode( ', ', $open ) . '.', 'The server ignores .htaccess (nginx?). Add a deny rule for wp-content/cache/uichemy-agent/logs.' );
			}
			return array( 'pass', 'Settings are kept in the database and logs are refused to web visitors.' );
		}

		/**
		 * SAFE-04.
		 *
		 * @return array
		 */
		public static function c_safe_signal() {
			$r = self::get( '/robots.txt' );
			if ( 200 === $r['code'] && preg_match( '/^Content-Signal:\s*(.+)$/mi', $r['body'], $m ) ) {
				return array( 'pass', 'robots.txt states: Content-Signal: ' . trim( $m[1] ) . '.' );
			}
			return array( 'warn', 'No Content-Signal line in robots.txt.', 'Choose Search, AI answers and AI training in Agent Ready > Help AI find and use your site. A static robots.txt file overrides WordPress.' );
		}

		/* ------------------------------------------------------------------ */
		/* test-as-agent and logs                                              */
		/* ------------------------------------------------------------------ */

		/**
		 * Action: test-as-agent.
		 *
		 * @param array $p { url }.
		 * @return array|WP_Error
		 */
		public static function test_as_agent( array $p ) {
			$url  = isset( $p['url'] ) ? trim( (string) $p['url'] ) : '/';
			$url  = '/' === substr( $url, 0, 1 ) ? home_url( $url ) : $url;
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				return new WP_Error( 'uich_agent_offsite', 'Only pages on this site can be tested.' );
			}
			$md_path = (string) wp_parse_url( Uich_Agent_Urls::md_url( $url ), PHP_URL_PATH );
			$md      = self::get( $md_path, array(), 'test-' . $md_path );
			$out     = array(
				'page'       => $url,
				'agent_file' => home_url( $md_path ),
				'status'     => $md['code'],
				'headers'    => array(
					'content-type'  => $md['type'],
					'cache-control' => $md['cache'],
					'link'          => $md['link'],
				),
			);
			if ( 200 === $md['code'] ) {
				$body             = $md['body'];
				$out['words']     = str_word_count( wp_strip_all_tags( $body ) );
				$out['bytes']     = strlen( $body );
				$out['preview']   = mb_substr( $body, 0, 1500 );
				$out['truncated'] = mb_strlen( $body ) > 1500;
				// Read the llms.txt agents actually get: a real file may override ours.
				$served           = self::get( '/llms.txt' );
				$out['in_llms']   = 200 === $served['code'] && false !== strpos( (string) $served['body'], Uich_Agent_Urls::md_url( $url ) );
			} elseif ( 404 === $md['code'] ) {
				$out['why'] = self::why_refused( $url );
			} elseif ( 429 === $md['code'] ) {
				$out['why'] = 'Rate limited: too many agent requests from this server in the last minute. Try again shortly.';
			}
			return $out;
		}

		/**
		 * Explain why a page has no agent file.
		 *
		 * @param string $url Page URL.
		 * @return string
		 */
		private static function why_refused( $url ) {
			$id = url_to_postid( $url );
			if ( ! $id ) {
				return 'The page does not exist, or it is a search, feed, date or account page (those never get agent files).';
			}
			$post = get_post( $id );
			if ( 'publish' !== $post->post_status ) {
				return 'The page is not published.';
			}
			if ( '' !== $post->post_password ) {
				return 'The page is password protected.';
			}
			if ( get_post_meta( $id, Uich_Agent_Pages::META_HIDE, true ) ) {
				return 'The page is hidden from agents (page setting).';
			}
			if ( Uich_Agent_Access::restricted( $post ) ) {
				return 'The page is for members only.';
			}
			$types = (array) Uich_Agent_Settings::value( 'agent_pages.post_types', array() );
			if ( isset( $types[ $post->post_type ] ) && empty( $types[ $post->post_type ]['enabled'] ) ) {
				return sprintf( 'Agent files are off for the "%s" type.', $post->post_type );
			}
			return 'An exclude pattern or a noindex setting keeps it out. See Agent Ready > AI-friendly pages.';
		}

		/**
		 * Action: get-agent-log.
		 *
		 * @param array $p { days?, limit? }.
		 * @return array
		 */
		public static function agent_log( array $p ) {
			$keep  = max( 1, (int) Uich_Agent_Settings::value( 'logs.retention_days', 30 ) );
			$days  = isset( $p['days'] ) ? max( 1, min( $keep, 365, (int) $p['days'] ) ) : min( 7, $keep );
			$limit = isset( $p['limit'] ) ? max( 0, min( 200, (int) $p['limit'] ) ) : 50;
			return array(
				'summary' => Uich_Agent_Log::summary( $days ),
				'latest'  => $limit ? Uich_Agent_Log::recent( $days, $limit ) : array(),
			);
		}

		/* ------------------------------------------------------------------ */
		/* HTTP                                                                */
		/* ------------------------------------------------------------------ */

		/**
		 * GET one of the site's own paths as a logged-out visitor.
		 *
		 * @param string $path    Path on this site.
		 * @param array  $headers Extra headers.
		 * @param string $key     Cache key (defaults to the path).
		 * @return array { code, body, type, link, cache }
		 */
		private static function get( $path, array $headers = array(), $key = '' ) {
			$key = '' === $key ? $path : $key;
			if ( isset( self::$cache[ $key ] ) ) {
				if ( 0 === self::$cache[ $key ]['code'] ) {
					self::$unreachable = self::$cache[ $key ]['error'];
				}
				return self::$cache[ $key ];
			}
			return self::$cache[ $key ] = self::request( 'GET', $path, $headers );
		}

		/**
		 * One MCP JSON-RPC call to the site's own endpoint.
		 *
		 * @param string       $method Method.
		 * @param array|object $params Params.
		 * @return array
		 */
		private static function mcp( $method, $params ) {
			$r    = self::request(
				'POST',
				(string) wp_parse_url( Uich_Agent_MCP::url(), PHP_URL_PATH ),
				array(
					'Content-Type'         => 'application/json',
					'Accept'               => 'application/json, text/event-stream',
					'Origin'               => untrailingslashit( home_url() ),
					'Mcp-Method'           => $method,
					'MCP-Protocol-Version' => Uich_Agent_MCP::VERSIONS[0],
				),
				wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => $method,
						'params'  => $params,
					)
				)
			);
			$data          = json_decode( $r['body'], true );
			$data          = is_array( $data ) ? $data : array();
			$data['_code'] = $r['code'];
			return $data;
		}

		/**
		 * Loopback request: same host only, no cookies, no redirects followed.
		 *
		 * @param string      $method  Method.
		 * @param string      $path    Path.
		 * @param array       $headers Headers.
		 * @param string|null $body    Body.
		 * @return array
		 */
		private static function request( $method, $path, array $headers = array(), $body = null ) {
			$url  = home_url( ltrim( $path, '/' ) );
			$args = array(
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array_merge( array( 'User-Agent' => 'UiChemy-AgentReady-Audit/1.0' ), $headers ),
				'cookies'     => array(),
			);
			if ( null !== $body ) {
				$args['body'] = $body;
			}
			$r = wp_safe_remote_request( $url, $args );
			if ( is_wp_error( $r ) ) {
				self::$unreachable = $r->get_error_message();
				return array(
					'code'  => 0,
					'body'  => '',
					'type'  => '',
					'link'  => '',
					'cache' => '',
					'error' => $r->get_error_message(),
				);
			}
			$link = wp_remote_retrieve_header( $r, 'link' );
			return array(
				'code'  => (int) wp_remote_retrieve_response_code( $r ),
				'body'  => (string) wp_remote_retrieve_body( $r ),
				'type'  => (string) wp_remote_retrieve_header( $r, 'content-type' ),
				'link'  => is_array( $link ) ? implode( ', ', $link ) : (string) $link,
				'cache' => (string) wp_remote_retrieve_header( $r, 'cache-control' ),
			);
		}
	}
}
