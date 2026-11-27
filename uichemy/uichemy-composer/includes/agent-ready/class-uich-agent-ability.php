<?php
/**
 * The Agent Ready admin ability: uichemy-composer/agent-ready.
 *
 * One ability with an action router, the house pattern (see
 * uichemy-composer/cpt). The admin MCP (v2 and v3) is offered a small set, named
 * like the SEO ability (list / update / patch): audit, list-settings,
 * update-settings, get-/update-/patch-page-md and get-/update-/patch-llms. It is
 * admin-only. Custom tools are built as Module Studio modules that
 * add a tool through the `uichemy_agent_ready_tools` filter.
 *
 * The dashboard needs many more operations (page list, tools, forms, requests...).
 * Those stay in the internal registry below, registered through the
 * `uichemy_agent_ready_actions` filter and reached by the dashboard's own REST
 * route through execute(); they are not part of the MCP ability.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Ability' ) ) {

	/**
	 * Ability registration and routing.
	 */
	class Uich_Agent_Ability {

		/**
		 * Ability name.
		 */
		const NAME = 'uichemy-composer/agent-ready';

		/**
		 * Ability category (registered by UiChemy_Abilities).
		 */
		const CATEGORY = 'uichemy-composer';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 10 );
		}

		/**
		 * Every action: name => { description, params (JSON Schema properties), readonly, callback }.
		 *
		 * @return array
		 */
		public static function actions() {
			$actions = array(
				'get-settings'    => array(
					'description' => 'Read every Agent Ready setting with its default.',
					'params'      => array(),
					'readonly'    => true,
					'callback'    => array( __CLASS__, 'act_get_settings' ),
				),
				'update-settings' => array(
					'description' => 'Change settings. Send only the keys to change, in the same shape get-settings returns (for example {"agent_pages":{"param_name":"view"}}). Lists replace the stored list.',
					'params'      => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'Partial settings.',
						),
					),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_update_settings' ),
				),
				'reset-settings'  => array(
					'description' => 'Put every setting back to its default. The master switch is not changed.',
					'params'      => array(),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_reset_settings' ),
				),
				'get-status'      => array(
					'description' => 'Module state: on/off, where it is stored (the database), integrity, public URLs (site MCP, llms.txt, agent page example) and which related plugins were detected.',
					'params'      => array(),
					'readonly'    => true,
					'callback'    => array( __CLASS__, 'act_get_status' ),
				),
				'trust-file'      => array(
					'description' => 'Accept a configuration file that was changed outside Agent Ready, after a person has reviewed it. Until then the file is ignored. Use get-status to see which files are untrusted.',
					'params'      => array(
						'file' => array(
							'type'        => 'string',
							'description' => 'Record name, e.g. "config.json" (the settings).',
						),
					),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_trust_file' ),
				),
			);

			/**
			 * Add actions to the Agent Ready ability.
			 *
			 * @param array $actions name => { description, params, readonly, callback }.
			 */
			return apply_filters( 'uichemy_agent_ready_actions', $actions );
		}

		/**
		 * Register the ability.
		 *
		 * @return void
		 */
		public static function register() {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::NAME ) ) {
				return;
			}

			$actions = self::exposed_actions();
			$meta    = array();
			foreach ( $actions as $name => $action ) {
				// "parameters" is a map of name => schema, with "required" next to it: the shape
				// the other abilities use, and the one Code Mode turns into a method signature.
				$meta[] = array(
					'name'        => $name,
					'description' => $action['description'],
					'parameters'  => empty( $action['params'] ) ? new stdClass() : $action['params'],
					'required'    => isset( $action['required'] ) ? $action['required'] : array(),
					'readonly'    => ! empty( $action['readonly'] ),
				);
			}

			wp_register_ability(
				self::NAME,
				array(
					'label'               => 'UiChemy Builder: Agent Ready',
					'description'         => 'Agent Ready: make this site usable by AI agents (Markdown pages, llms.txt, discovery files, tools and forms for AI in the visitor\'s browser). Actions: audit (score the site as an agent meets it and list what to fix); list-settings / update-settings (every Agent Ready setting); get-page-md / update-page-md / patch-page-md (the Markdown AI reads for one page); get-llms / update-llms / patch-llms (the llms.txt file).',
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'            => array(
								'type'        => 'string',
								'enum'        => array_keys( $actions ),
								'description' => 'Which operation to run.',
							),
							'action_parameters' => array(
								'type'        => 'object',
								'description' => 'Parameters for the chosen action. See meta.actions.',
							),
						),
						'required'   => array( 'action' ),
					),
					'execute_callback'    => array( __CLASS__, 'execute_exposed' ),
					'permission_callback' => array( __CLASS__, 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'title'       => 'UiChemy Builder: Agent Ready',
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'actions'      => $meta,
					),
				)
			);
		}

		/**
		 * Admin only, and only while the dashboard's MCP switch is on.
		 *
		 * @return bool
		 */
		public static function permission_check() {
			if ( class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'permission_check' ) ) {
				return (bool) UiChemy_Abilities::permission_check();
			}
			return current_user_can( 'manage_options' );
		}

		/**
		 * The three actions the MCP ability offers.
		 *
		 * @return array name => { description, params, readonly, callback }
		 */
		public static function exposed_actions() {
			return array(
				'audit'        => array(
					'description' => 'Check the live site the way an AI agent meets it (as a logged-out visitor, over HTTP) and score it 0-100. Each check has pass, warn, fail or na, what was seen, and how to fix it. Without parameters it returns the last saved result (and runs a check when there is none yet); "stale" is true when settings, forms, tools or pages changed since. Pass refresh true to run a new check: it takes up to about 10 seconds.',
					'params'      => array(
						'refresh' => array(
							'type'        => 'boolean',
							'description' => 'true runs a new check now; false or missing returns the last saved result.',
						),
					),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_audit' ),
				),
				'list-settings' => array(
					'description' => 'Every Agent Ready setting with its default: the Markdown pages, llms.txt, discovery files, browser AI tools, rate limits and logs. Change them with update-settings, in the same shape.',
					'params'      => array(),
					'readonly'    => true,
					'callback'    => array( __CLASS__, 'act_get_settings' ),
				),
				'update-settings' => array(
					'description' => 'Change Agent Ready settings; only the keys you pass change. Send them in the same shape list-settings returns, e.g. {"llms":{"summary":"..."}}; lists replace the stored list. The switch that lets AI send forms or requests (mcp.writes) belongs to the site owner and is never changed here. Returns the saved settings.',
					'params'      => array(
						'values' => array(
							'type'        => 'object',
							'description' => 'Settings to change (partial).',
						),
					),
					'required'    => array( 'values' ),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_update_values' ),
				),
				'get-page-md'  => array(
					'description' => 'The Markdown copy AI reads for one page or post (its .md address), and whether it is the automatic copy or one written by hand. Pass post_id or url.',
					'params'      => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Page or post ID.',
						),
						'url'     => array(
							'type'        => 'string',
							'description' => 'Page URL or path on this site, e.g. "/menu/". Used when post_id is not given.',
						),
					),
					'readonly'    => true,
					'callback'    => array( __CLASS__, 'act_get_page_md' ),
				),
				'update-page-md' => array(
					'description' => 'Write the Markdown AI reads for one page or post. From then on it is served exactly as written and no longer follows changes to the page, so only do it when the automatic copy reads badly, and read it first with get-page-md. Send an empty markdown to go back to the automatic copy. Plain Markdown only (HTML tags are removed), up to 200 KB. For small changes use patch-page-md.',
					'params'      => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'Page or post ID.',
						),
						'markdown' => array(
							'type'        => 'string',
							'description' => 'The full Markdown, front matter included if wanted. Empty string: back to the automatic copy.',
						),
					),
					'required'    => array( 'post_id', 'markdown' ),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_set_page_md' ),
				),
				'patch-page-md' => array(
					'description' => 'Literal find/replace edits on the Markdown AI reads for one page, instead of resending it. The edits apply to the current copy (read it with get-page-md and copy "find" from there); the result is saved as a hand-written copy, which no longer follows changes to the page. Every exact, case-sensitive occurrence of find is replaced; each edit reports its count, and nothing is saved unless every edit matched.',
					'params'      => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Page or post ID.',
						),
						'edits'   => self::edits_schema(),
					),
					'required'    => array( 'post_id', 'edits' ),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_patch_page_md' ),
				),
				'get-llms'      => array(
					'description' => 'The llms.txt AI receives now, its address and its mode: generated (built from the site and settings, kept up to date), claude or manual (written by hand, served as written).',
					'params'      => array(),
					'readonly'    => true,
					'callback'    => array( __CLASS__, 'act_get_llms' ),
				),
				'update-llms'   => array(
					'description' => 'Write the whole llms.txt yourself (llmstxt.org format: an H1, an optional > summary, H2 sections of "- [name](url): note" links). From then on it is served as written and no longer updates with the site. Send mode "generated" with no text to go back to the automatic file. To change only the summary, intro or key pages and keep it automatic, use update-settings (llms.*) instead. For small edits use patch-llms.',
					'params'      => array(
						'text' => array(
							'type'        => 'string',
							'description' => 'The full llms.txt text (max 200 KB).',
						),
						'mode' => array(
							'type'        => 'string',
							'enum'        => array( 'claude', 'generated' ),
							'description' => '"claude" (default) saves text; "generated" goes back to the automatic file.',
						),
					),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_update_llms' ),
				),
				'patch-llms'    => array(
					'description' => 'Literal find/replace edits on the current llms.txt (read it with get-llms and copy "find" from there). The result is saved as a hand-written file that no longer updates with the site. Every exact, case-sensitive occurrence of find is replaced; each edit reports its count, and nothing is saved unless every edit matched.',
					'params'      => array(
						'edits' => self::edits_schema(),
					),
					'required'    => array( 'edits' ),
					'readonly'    => false,
					'callback'    => array( __CLASS__, 'act_patch_llms' ),
				),
			);
		}

		/**
		 * Schema of a list of literal find/replace edits (same shape as Page::patchSectionCode).
		 *
		 * @return array
		 */
		private static function edits_schema() {
			return array(
				'type'        => 'array',
				'description' => 'Edits applied in order: [{ find, replace }]. find must match the current text exactly (case-sensitive); every occurrence is replaced, so include enough text to make it unique. An empty replace deletes.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'find'    => array( 'type' => 'string' ),
						'replace' => array( 'type' => 'string' ),
					),
					'required'   => array( 'find' ),
				),
			);
		}

		/**
		 * Apply literal find/replace edits to a text.
		 *
		 * @param string $text  Text.
		 * @param mixed  $edits List of { find, replace? }.
		 * @return array|WP_Error { text, results: [{ find, count }], changed, missed }
		 */
		private static function apply_edits( $text, $edits ) {
			if ( ! is_array( $edits ) || ! $edits ) {
				return new WP_Error( 'uich_agent_edits', 'Pass "edits": a list of { find, replace }.' );
			}
			$results = array();
			$changed = 0;
			$missed  = 0;
			foreach ( array_slice( array_values( $edits ), 0, 100 ) as $edit ) {
				$find    = is_array( $edit ) && isset( $edit['find'] ) ? (string) $edit['find'] : '';
				$replace = is_array( $edit ) && isset( $edit['replace'] ) ? (string) $edit['replace'] : '';
				if ( '' === $find ) {
					$results[] = array(
						'find'  => '',
						'count' => 0,
						'error' => 'empty find',
					);
					++$missed;
					continue;
				}
				$count     = substr_count( $text, $find );
				$text      = $count ? str_replace( $find, $replace, $text ) : $text;
				$changed  += $count;
				$missed   += $count ? 0 : 1;
				$results[] = array(
					'find'  => mb_substr( $find, 0, 80 ),
					'count' => $count,
				);
			}
			return array(
				'text'    => $text,
				'results' => $results,
				'changed' => $changed,
				'missed'  => $missed,
			);
		}

		/**
		 * Action: update-settings (values => settings).
		 *
		 * @param array $params { values }.
		 * @return array|WP_Error
		 */
		public static function act_update_values( array $params ) {
			return self::act_update_settings(
				array(
					'settings' => isset( $params['values'] ) && is_array( $params['values'] ) ? $params['values'] : array(),
				)
			);
		}

		/**
		 * Action: patch-page-md.
		 *
		 * @param array $params { post_id, edits }.
		 * @return array|WP_Error
		 */
		public static function act_patch_page_md( array $params ) {
			$current = self::act_get_page_md( array( 'post_id' => isset( $params['post_id'] ) ? $params['post_id'] : 0 ) );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( empty( $current['shared'] ) && empty( $current['edited'] ) ) {
				return new WP_Error( 'uich_agent_page', 'AI gets no copy of this page (not public, members-only or hidden), so there is nothing to patch.' );
			}
			$done = self::apply_edits( (string) $current['markdown'], isset( $params['edits'] ) ? $params['edits'] : null );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( $done['missed'] ) {
				return array(
					'saved'   => false,
					'results' => $done['results'],
					'note'    => 'An edit did not match (count 0), so nothing was saved. Copy find exactly from get-page-md.',
				);
			}
			$saved = self::act_set_page_md(
				array(
					'post_id'  => $current['post_id'],
					'markdown' => $done['text'],
				)
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$saved['saved']   = true;
			$saved['results'] = $done['results'];
			return $saved;
		}

		/**
		 * Action: get-llms.
		 *
		 * @return array|WP_Error
		 */
		public static function act_get_llms() {
			if ( ! class_exists( 'Uich_Agent_Llms' ) ) {
				return new WP_Error( 'uich_agent_llms', 'llms.txt is not available while Agent Ready is off.' );
			}
			return Uich_Agent_Llms::act_get();
		}

		/**
		 * Action: update-llms.
		 *
		 * @param array $params { text?, mode? }.
		 * @return array|WP_Error
		 */
		public static function act_update_llms( array $params ) {
			if ( ! class_exists( 'Uich_Agent_Llms' ) ) {
				return new WP_Error( 'uich_agent_llms', 'llms.txt is not available while Agent Ready is off.' );
			}
			$mode = isset( $params['mode'] ) && 'generated' === $params['mode'] ? 'generated' : 'claude';
			return Uich_Agent_Llms::act_update(
				array(
					'mode' => $mode,
					'text' => isset( $params['text'] ) ? (string) $params['text'] : '',
				)
			);
		}

		/**
		 * Action: patch-llms.
		 *
		 * @param array $params { edits }.
		 * @return array|WP_Error
		 */
		public static function act_patch_llms( array $params ) {
			$current = self::act_get_llms();
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$done = self::apply_edits( (string) $current['text'], isset( $params['edits'] ) ? $params['edits'] : null );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( $done['missed'] ) {
				return array(
					'saved'   => false,
					'results' => $done['results'],
					'note'    => 'An edit did not match (count 0), so nothing was saved. Copy find exactly from get-llms.',
				);
			}
			$saved = self::act_update_llms( array( 'text' => $done['text'] ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$saved['saved']   = true;
			$saved['results'] = $done['results'];
			return $saved;
		}

		/**
		 * The public post a page action targets: post_id, else a URL or path on this site.
		 *
		 * @param array $params { post_id?, url? }.
		 * @return WP_Post|WP_Error
		 */
		private static function target_post( array $params ) {
			$id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			$url = ! $id && isset( $params['url'] ) ? trim( (string) $params['url'] ) : '';
			if ( '' !== $url ) {
				$url  = '/' === $url[0] ? home_url( $url ) : $url;
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				$host = wp_parse_url( $url, PHP_URL_HOST );
				// The page's own agent address (page.md, index.md) points at the page.
				$page = $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ? false : Uich_Agent_Urls::page_path( $path );
				if ( false !== $page ) {
					$url = home_url( substr( $page, strlen( Uich_Agent_Urls::home_path() ) ) );
				}
				$id = url_to_postid( $url );
				if ( ! $id && untrailingslashit( $url ) === untrailingslashit( home_url() ) && 'page' === get_option( 'show_on_front' ) ) {
					$id = (int) get_option( 'page_on_front' );
				}
			}
			$post = $id ? get_post( $id ) : null;
			if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
				return new WP_Error( 'uich_agent_page', 'No page or post found. Pass post_id, or the url of a page on this site.', array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'uich_agent_page', 'You cannot edit this page.', array( 'status' => 403 ) );
			}
			return $post;
		}

		/**
		 * Action: get-page-md.
		 *
		 * @param array $params { post_id?, url? }.
		 * @return array|WP_Error
		 */
		public static function act_get_page_md( array $params ) {
			$post = self::target_post( $params );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$own = (string) get_post_meta( $post->ID, Uich_Agent_Pages::META_MD, true );
			$out = array(
				'post_id'   => $post->ID,
				'title'     => get_the_title( $post ),
				'agent_url' => Uich_Agent_Urls::md_url( get_permalink( $post ) ),
				'shared'    => class_exists( 'Uich_Agent_Access' ) && Uich_Agent_Access::visible( $post ),
				'edited'    => '' !== trim( $own ),
			);
			if ( $out['edited'] ) {
				$out['markdown'] = $own;
				return $out;
			}
			if ( ! $out['shared'] ) {
				$out['markdown'] = '';
				$out['note']     = 'AI gets no copy of this page: it is not public, is members-only, or is hidden from AI.';
				return $out;
			}
			$file = Uich_Agent_Page_Actions::get_agent_file( array( 'url' => get_permalink( $post ) ) );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$out['markdown']  = isset( $file['markdown'] ) ? $file['markdown'] : '';
			$out['truncated'] = ! empty( $file['truncated'] );
			return $out;
		}

		/**
		 * Action: set-page-md. Saves through the same path as the dashboard's Edit popup.
		 *
		 * @param array $params { post_id, markdown }.
		 * @return array|WP_Error
		 */
		public static function act_set_page_md( array $params ) {
			$post = self::target_post( $params );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$md   = isset( $params['markdown'] ) ? (string) $params['markdown'] : '';
			$done = Uich_Agent_Page_Actions::set_page_notes(
				array(
					'items' => array(
						array(
							'post_id'  => $post->ID,
							'markdown' => $md,
						),
					),
				)
			);
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$row = isset( $done['items'][0] ) ? $done['items'][0] : array();
			if ( ! empty( $row['error'] ) ) {
				return new WP_Error( 'uich_agent_page', (string) $row['error'] );
			}
			return array(
				'post_id'   => $post->ID,
				'edited'    => '' !== trim( $md ),
				'agent_url' => Uich_Agent_Urls::md_url( get_permalink( $post ) ),
				'note'      => '' === trim( $md ) ? 'Back to the automatic copy.' : 'Saved. AI now reads this text for the page; it no longer follows changes to the page until you go back to the automatic copy (update-page-md with an empty markdown).',
			);
		}

		/**
		 * Action: audit (last result, or a new check).
		 *
		 * @param array $params { refresh? }.
		 * @return array|WP_Error
		 */
		public static function act_audit( array $params ) {
			if ( ! class_exists( 'Uich_Agent_Audit' ) ) {
				return new WP_Error( 'uich_agent_audit', 'The audit is not available.' );
			}
			$refresh = ! empty( $params['refresh'] );
			$last    = Uich_Agent_Audit::last();
			if ( ! $refresh && isset( $last['score'] ) && null !== $last['score'] ) {
				return $last;
			}
			return Uich_Agent_Audit::run();
		}

		/**
		 * Route one call from the MCP ability: only the three offered actions.
		 *
		 * @param array $arguments { action, action_parameters?: object }.
		 * @return array|WP_Error
		 */
		public static function execute_exposed( $arguments ) {
			return self::route( $arguments, self::exposed_actions() );
		}

		/**
		 * Route one call from the dashboard (every internal action).
		 *
		 * @param array $arguments { action, action_parameters?: object }.
		 * @return array|WP_Error
		 */
		public static function execute( $arguments ) {
			return self::route( $arguments, self::actions() );
		}

		/**
		 * Check and run one action out of a set.
		 *
		 * @param array $arguments { action, action_parameters?: object }.
		 * @param array $actions   Allowed actions.
		 * @return array|WP_Error
		 */
		private static function route( $arguments, array $actions ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$name      = isset( $arguments['action'] ) ? (string) $arguments['action'] : '';
			$params    = isset( $arguments['action_parameters'] ) && is_array( $arguments['action_parameters'] ) ? $arguments['action_parameters'] : array();

			if ( ! isset( $actions[ $name ] ) ) {
				return new WP_Error( 'uich_agent_unknown_action', sprintf( 'Unknown action "%s". Available: %s.', $name, implode( ', ', array_keys( $actions ) ) ) );
			}

			$unknown = array_diff( array_keys( $params ), array_keys( $actions[ $name ]['params'] ) );
			if ( $unknown ) {
				$allowed = array_keys( $actions[ $name ]['params'] );
				return new WP_Error( 'uich_agent_unknown_param', sprintf( '%s does not take %s. Allowed: %s.', $name, implode( ', ', $unknown ), $allowed ? implode( ', ', $allowed ) : 'no parameters' ), array( 'status' => 400 ) );
			}

			return call_user_func( $actions[ $name ]['callback'], $params );
		}

		/* -------------------------------------------------------------
		 * Actions
		 * ----------------------------------------------------------- */

		/**
		 * Action: get-settings.
		 *
		 * @return array
		 */
		public static function act_get_settings() {
			return array(
				'settings' => Uich_Agent_Settings::get(),
				'defaults' => Uich_Agent_Settings::defaults(),
			);
		}

		/**
		 * Action: update-settings.
		 *
		 * @param array $params { settings }.
		 * @return array|WP_Error
		 */
		public static function act_update_settings( array $params ) {
			if ( empty( $params['settings'] ) || ! is_array( $params['settings'] ) ) {
				return new WP_Error( 'uich_agent_bad_settings', 'Pass "settings" as an object with the keys to change.' );
			}
			$saved = Uich_Agent_Settings::update( $params['settings'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$out = array( 'settings' => $saved );
			if ( isset( $params['settings']['mcp']['writes'] ) ) {
				$out['note'] = 'mcp.writes was not changed: only the site owner can allow agents to send or change data, with "Let AI press Send by itself" in Agent Ready > Setup > step 5.';
			}
			return $out;
		}

		/**
		 * Action: reset-settings.
		 *
		 * @return array|WP_Error
		 */
		public static function act_reset_settings() {
			$saved = Uich_Agent_Settings::reset();
			return is_wp_error( $saved ) ? $saved : array( 'settings' => $saved );
		}

		/**
		 * Action: get-status.
		 *
		 * @return array
		 */
		public static function act_get_status() {
			return Uich_Agent_Status::get();
		}

		/**
		 * Action: trust-file.
		 *
		 * @param array $params { file }.
		 * @return array|WP_Error
		 */
		public static function act_trust_file( array $params ) {
			$file = isset( $params['file'] ) ? (string) $params['file'] : '';
			$done = Uich_Agent_Store::trust( $file );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			Uich_Agent_Settings::flush();
			return array(
				'trusted' => $file,
				'status'  => Uich_Agent_Status::get(),
			);
		}
	}
}
