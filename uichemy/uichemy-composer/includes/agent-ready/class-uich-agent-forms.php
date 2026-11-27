<?php
/**
 * Composer forms for AI agents: site MCP submit tools and WebMCP.
 *
 * Each form is off for agents until the owner switches it on, per form:
 *   off      agents cannot use it (default)
 *   confirm  browser agents (WebMCP) may fill it; a person clicks Send.
 *            On the site MCP the agent gets the page link to finish there.
 *   submit   agents may send it, through the site MCP or WebMCP.
 *
 * A site MCP submission goes through the Composer forms module's own handler,
 * exactly like a visitor's: the page is loaded as a logged-out visitor to read
 * the form's fresh nonce, signed field rules and widget ID, and the values are
 * posted from this site's own origin. So every check the form already has
 * still runs: nonce, signature, required fields, email format, honeypot, the
 * form's own per-IP limit, notifications and webhooks. Agent Ready adds its own
 * write rate limit, typed field schemas with length caps and a "via AI agent"
 * marker on the submission.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Forms' ) ) {

	/**
	 * Agent access to Composer forms.
	 */
	class Uich_Agent_Forms {

		/**
		 * File holding per-form agent settings.
		 */
		const FILE = 'forms.json';

		/**
		 * Longest value accepted for one field.
		 */
		const FIELD_MAX = 2000;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			// The forms module may load after Agent Ready, so its presence is checked
			// when a tool or page is built, not here.
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
			add_filter( 'uichemy_agent_ready_builtin_tools', array( __CLASS__, 'tools' ) );
			if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				add_action( 'template_redirect', array( __CLASS__, 'webmcp_start' ), 3 );
				add_action( 'wp_head', array( __CLASS__, 'origin_trial' ), 1 );
				add_action( 'wp_footer', array( __CLASS__, 'webmcp_tools' ), 50 );
				add_action( 'send_headers', array( __CLASS__, 'isolation_headers' ) );
			}
		}

		/**
		 * Saved per-form settings, keyed "{page_id}:{form_key}".
		 *
		 * @return array
		 */
		public static function registry() {
			$data = Uich_Agent_Store::read_json( self::FILE );
			return is_array( $data ) && isset( $data['forms'] ) && is_array( $data['forms'] ) ? $data['forms'] : array();
		}

		/**
		 * Save the registry.
		 *
		 * @param array $forms Forms.
		 * @return true|WP_Error
		 */
		private static function save_registry( array $forms ) {
			$done = Uich_Agent_Store::write_json( self::FILE, array( 'forms' => $forms ) );
			if ( ! is_wp_error( $done ) && class_exists( 'Uich_Agent_Tools' ) ) {
				Uich_Agent_Tools::flush();
			}
			return $done;
		}

		/* -------------------------------------------------------------
		 * Reading forms from a rendered page
		 * ----------------------------------------------------------- */

		/**
		 * The Composer forms on a page, as a logged-out visitor receives it.
		 *
		 * @param int $page_id Page ID.
		 * @return array|WP_Error form_key => { hidden: array, fields: array }
		 */
		public static function read_page_forms( $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				return new WP_Error( 'uich_agent_form_page', 'The form page is not public.' );
			}
			// Same-site hops only: a page that redirects elsewhere is never fetched.
			$resp = Uich_Agent_Urls::fetch_own( get_permalink( $post ), 'text/html' );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'uich_agent_form_page', 'The form page could not be loaded.' );
			}
			$html = (string) wp_remote_retrieve_body( $resp );
			if ( ! preg_match_all( '/<form\b([^>]*\bdata-atom-form\s*=\s*["\']([^"\']+)["\'][^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER ) ) {
				return array();
			}
			$out = array();
			foreach ( $forms as $f ) {
				$key = sanitize_key( $f[2] );
				$out[ $key ] = array(
					'hidden' => self::hidden_inputs( $f[3] ),
					'fields' => self::fields( $f[3] ),
				);
			}
			return $out;
		}

		/**
		 * Builder content fields that can hold a UiChemy form.
		 */
		const CONTENT_KEYS = array( '_uichemy_content', '_elementor_data', '_bricks_page_content_2' );

		/**
		 * The UiChemy forms of a page, read from its saved content (post content
		 * and the builders' own fields) instead of loading the page over HTTP.
		 * Used to list forms: it is fast and never waits on a second PHP worker.
		 *
		 * @param int $page_id Page ID.
		 * @return array form_key => { fields: array }
		 */
		public static function stored_page_forms( $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post ) {
				return array();
			}
			$texts = array();
			if ( false !== strpos( $post->post_content, 'data-atom-form' ) ) {
				$texts[] = $post->post_content;
				// Block attributes keep their markup JSON-escaped.
				self::collect_strings( parse_blocks( $post->post_content ), $texts );
			}
			foreach ( self::CONTENT_KEYS as $key ) {
				$raw = get_post_meta( $page_id, $key, true );
				if ( is_string( $raw ) ) {
					if ( false === strpos( $raw, 'atom-form' ) ) {
						continue;
					}
					$json = json_decode( $raw, true );
					$raw  = null !== $json ? $json : $raw;
				}
				self::collect_strings( $raw, $texts );
			}
			$out = array();
			foreach ( $texts as $html ) {
				if ( false === strpos( $html, 'data-atom-form' ) || ! preg_match_all( '/<form\b([^>]*\bdata-atom-form\s*=\s*["\']([^"\']+)["\'][^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $forms as $f ) {
					$key = sanitize_key( $f[2] );
					if ( '' !== $key && ! isset( $out[ $key ] ) ) {
						$out[ $key ] = array( 'fields' => self::fields( $f[3] ) );
					}
				}
			}
			return $out;
		}

		/**
		 * Every string inside a nested value that mentions a form.
		 *
		 * @param mixed $value Value.
		 * @param array $texts Found strings (by reference).
		 * @param int   $depth Depth guard.
		 * @return void
		 */
		private static function collect_strings( $value, array &$texts, $depth = 0 ) {
			if ( $depth > 40 || count( $texts ) > 200 ) {
				return;
			}
			if ( is_string( $value ) ) {
				if ( false === strpos( $value, 'data-atom-form' ) ) {
					return;
				}
				// Some builders keep an element's settings as a JSON string inside their data.
				$first = substr( ltrim( $value ), 0, 1 );
				if ( '{' === $first || '[' === $first ) {
					$json = json_decode( $value, true );
					if ( null !== $json ) {
						self::collect_strings( $json, $texts, $depth + 1 );
						return;
					}
				}
				$texts[] = $value;
				return;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				foreach ( (array) $value as $item ) {
					self::collect_strings( $item, $texts, $depth + 1 );
				}
			}
		}

		/**
		 * Hidden infrastructure inputs (_uich_*) of a form.
		 *
		 * @param string $inner Form inner HTML.
		 * @return array
		 */
		private static function hidden_inputs( $inner ) {
			$out = array();
			if ( preg_match_all( '/<input\b[^>]*type\s*=\s*["\']hidden["\'][^>]*>/i', $inner, $m ) ) {
				foreach ( $m[0] as $tag ) {
					if ( preg_match( '/name\s*=\s*["\'](_uich_[a-z_]+)["\']/i', $tag, $n ) && preg_match( '/value\s*=\s*(["\'])(.*?)\1/is', $tag, $v ) ) {
						$out[ $n[1] ] = html_entity_decode( $v[2], ENT_QUOTES, 'UTF-8' );
					}
				}
			}
			return $out;
		}

		/**
		 * The visible fields of a form: name, type, label, required, options.
		 *
		 * @param string $inner Form inner HTML.
		 * @return array
		 */
		private static function fields( $inner ) {
			$labels = array();
			if ( preg_match_all( '/<label\b[^>]*for\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/label>/is', $inner, $lm, PREG_SET_ORDER ) ) {
				foreach ( $lm as $l ) {
					$labels[ $l[1] ] = trim( wp_strip_all_tags( preg_replace( '/<(select|textarea)\b.*?<\/\1>/is', '', $l[2] ) ), " *:\t\n" );
				}
			}
			$out = array();
			if ( ! preg_match_all( '/<(input|select|textarea)\b([^>]*)>(?:(.*?)<\/\1>)?/is', $inner, $cm, PREG_SET_ORDER ) ) {
				return $out;
			}
			foreach ( $cm as $c ) {
				$attrs = $c[2];
				if ( ! preg_match( '/(?<![\w-])name\s*=\s*(["\'])(.*?)\1/i', $attrs, $nm ) ) {
					continue;
				}
				$name = str_replace( array( ' ', '.' ), '_', preg_replace( '/\[.*$/', '', $nm[2] ) );
				if ( '' === $name || 0 === strpos( $name, '_uich_' ) || isset( $out[ $name ] ) ) {
					continue;
				}
				$tag  = strtolower( $c[1] );
				$type = 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'select' : 'text' );
				if ( 'input' === $tag && preg_match( '/(?<![\w-])type\s*=\s*["\']?([a-z-]+)/i', $attrs, $tm ) ) {
					$type = strtolower( $tm[1] );
				}
				if ( in_array( $type, array( 'hidden', 'submit', 'button', 'reset', 'image', 'file', 'password' ), true ) ) {
					continue;
				}
				$id    = preg_match( '/(?<![\w-])id\s*=\s*["\']([^"\']+)["\']/i', $attrs, $im ) ? $im[1] : '';
				$label = isset( $labels[ $id ] ) ? $labels[ $id ] : ( preg_match( '/(?:aria-label|placeholder)\s*=\s*["\']([^"\']+)["\']/i', $attrs, $pm ) ? $pm[1] : $name );
				$field = array(
					'name'     => $name,
					'type'     => $type,
					'label'    => mb_substr( sanitize_text_field( $label ), 0, 80 ),
					'required' => (bool) preg_match( '/(?<![\w-])required(?![\w-])(?!\s*=\s*["\']?false)/i', $attrs ),
				);
				if ( 'select' === $type && isset( $c[3] ) && preg_match_all( '/<option\b[^>]*value\s*=\s*["\']([^"\']*)["\']/i', $c[3], $om ) ) {
					$field['options'] = array_values( array_filter( array_map( 'sanitize_text_field', $om[1] ), 'strlen' ) );
				}
				$out[ $name ] = $field;
			}
			return array_slice( $out, 0, 30, true );
		}

		/* -------------------------------------------------------------
		 * Site MCP tools
		 * ----------------------------------------------------------- */

		/**
		 * One tool per form switched to "submit" or "confirm".
		 *
		 * @param array $tools Built-in tools.
		 * @return array
		 */
		public static function tools( array $tools ) {
			if ( ! class_exists( 'Uich_Forms' ) ) {
				return $tools;
			}
			foreach ( self::registry() as $id => $form ) {
				if ( empty( $form['mode'] ) || 'off' === $form['mode'] ) {
					continue;
				}
				$props    = array();
				$required = array();
				foreach ( $form['fields'] as $f ) {
					$prop = array(
						'type'        => 'number' === $f['type'] ? 'number' : ( 'checkbox' === $f['type'] ? 'boolean' : 'string' ),
						'description' => $f['label'] . ( 'email' === $f['type'] ? ' (email address)' : '' ) . ( in_array( $f['type'], array( 'date', 'time', 'datetime-local' ), true ) ? ' (' . $f['type'] . ', ISO format)' : '' ),
					);
					if ( 'string' === $prop['type'] ) {
						$prop['maxLength'] = 'textarea' === $f['type'] ? self::FIELD_MAX : 200;
					}
					if ( ! empty( $f['options'] ) ) {
						$prop['enum'] = $f['options'];
					}
					$props[ $f['name'] ] = $prop;
					if ( ! empty( $f['required'] ) ) {
						$required[] = $f['name'];
					}
				}
				// "submit" sends only while the owner allows agents to send data.
				$submit                 = 'submit' === $form['mode'] && Uich_Agent_Settings::writes_enabled();
				$tools[ $form['tool'] ] = array(
					'name'         => $form['tool'],
					'title'        => $form['title'],
					'description'  => $form['description'] . ( $submit ? ' Sends the form for the person; tell them what was sent.' : ' Returns the page where the person sends it themselves.' ),
					'input_schema' => array(
						'type'       => 'object',
						'properties' => $props ? $props : new stdClass(),
						'required'   => $submit ? $required : array(),
					),
					'annotations'  => array(
						'readOnlyHint'    => ! $submit,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => false,
					),
					'source'       => 'form',
					'write'        => $submit,
					'run'          => function ( $args ) use ( $id ) {
						return Uich_Agent_Forms::run( $id, $args );
					},
				);
			}
			return $tools;
		}

		/**
		 * Run a form tool.
		 *
		 * @param string $id   Registry key.
		 * @param array  $args Checked arguments.
		 * @return array|WP_Error
		 */
		public static function run( $id, array $args ) {
			$reg  = self::registry();
			$form = isset( $reg[ $id ] ) ? $reg[ $id ] : null;
			if ( ! $form ) {
				return new WP_Error( 'uich_agent_form', 'This form is not available.' );
			}
			$page_url = get_permalink( $form['page_id'] );
			if ( 'submit' !== $form['mode'] || ! Uich_Agent_Settings::writes_enabled() ) {
				return array(
					'url'  => $page_url,
					'note' => 'This form is sent by the person: open the page, check the details and press send.',
				);
			}

			$live = self::read_page_forms( $form['page_id'] );
			if ( is_wp_error( $live ) || ! isset( $live[ $form['form_key'] ] ) ) {
				return new WP_Error( 'uich_agent_form', 'The form could not be reached right now.' );
			}
			$live = $live[ $form['form_key'] ];
			// The form changed since it was enabled: stop, so an agent never fills a
			// form whose fields the owner has not seen.
			if ( array_keys( $live['fields'] ) !== array_keys( $form['fields'] ) ) {
				return new WP_Error( 'uich_agent_form', 'This form changed and must be re-enabled by the site owner before agents can send it.' );
			}

			$request = new WP_REST_Request( 'POST', '/' . Uich_Forms::REST_NS . Uich_Forms::REST_ROUTE );
			$request->set_header( 'origin', untrailingslashit( home_url() ) );
			foreach ( $live['hidden'] as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$request->set_param( '_uich_hp', '' );
			foreach ( $form['fields'] as $name => $f ) {
				if ( ! array_key_exists( $name, $args ) ) {
					continue;
				}
				$value = $args[ $name ];
				if ( is_bool( $value ) ) {
					$value = $value ? '1' : '';
				}
				$request->set_param( $name, mb_substr( sanitize_textarea_field( (string) $value ), 0, self::FIELD_MAX ) );
			}
			$request->set_param( 'submitted_via', 'AI agent (site MCP)' );

			$response = Uich_Forms::handle_submit( $request );
			$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();
			$ok       = is_array( $data ) && ! empty( $data['success'] );
			return array(
				'sent'    => $ok,
				'message' => is_array( $data ) && isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '',
				'errors'  => is_array( $data ) && isset( $data['errors'] ) ? $data['errors'] : null,
				'page'    => $page_url,
			);
		}

		/* -------------------------------------------------------------
		 * WebMCP
		 * ----------------------------------------------------------- */

		/**
		 * On a page with agent-enabled forms, add WebMCP attributes to them.
		 *
		 * @return void
		 */
		public static function webmcp_start() {
			if ( ! class_exists( 'Uich_Forms' ) || ! Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) || ! Uich_Agent_Settings::value( 'mcp.enabled', true ) || ( class_exists( 'Uich_Agent_Pages' ) && Uich_Agent_Pages::is_agent_request() ) ) {
				return;
			}
			$page = get_queried_object_id();
			$here = array();
			foreach ( self::registry() as $form ) {
				if ( (int) $form['page_id'] === (int) $page && 'off' !== $form['mode'] ) {
					$here[ $form['form_key'] ] = $form;
				}
			}
			if ( ! $here ) {
				return;
			}
			ob_start(
				function ( $html ) use ( $here ) {
					return preg_replace_callback(
						'/<form\b([^>]*\bdata-atom-form\s*=\s*["\']([^"\']+)["\'][^>]*)>/i',
						function ( $m ) use ( $here ) {
							$key = sanitize_key( $m[2] );
							if ( ! isset( $here[ $key ] ) || false !== stripos( $m[1], 'toolname' ) ) {
								return $m[0];
							}
							$form  = $here[ $key ];
							$extra = ' toolname="' . esc_attr( $form['tool'] ) . '" tooldescription="' . esc_attr( $form['description'] ) . '"';
							if ( 'submit' === $form['mode'] && Uich_Agent_Settings::writes_enabled() ) {
								$extra .= ' toolautosubmit';
							}
							return '<form' . $m[1] . $extra . '>';
						},
						$html
					);
				}
			);
		}

		/**
		 * WebMCP tools for AI agents that run in the visitor's browser.
		 *
		 * The site's tools (search, site info, opening hours, forms, requests…) are
		 * offered through document.modelContext (navigator.modelContext in older Chrome).
		 * Each call goes to this same site's own endpoint (Uich_Agent_MCP, browser only),
		 * so its checks, rate limits and log apply unchanged, and no tool logic is
		 * duplicated here. Tools that send data (forms, requests) appear only while the
		 * owner has allowed it, and carry readOnlyHint false so the browser can ask the
		 * visitor first. Forms also keep their declarative WebMCP attributes above.
		 *
		 * @return void
		 */
		public static function webmcp_tools() {
			if ( ! Uich_Agent_Settings::value( 'discovery.webmcp.forms', true ) || ! Uich_Agent_Settings::value( 'mcp.enabled', true ) || ! class_exists( 'Uich_Agent_MCP' ) ) {
				return;
			}
			if ( is_feed() || is_embed() || ( class_exists( 'Uich_Agent_Pages' ) && Uich_Agent_Pages::is_agent_request() ) ) {
				return;
			}
			$url = wp_make_link_relative( Uich_Agent_MCP::url() );
			if ( '' === $url ) {
				return;
			}
			// Logs one line to the console so the owner can check WebMCP is working.
			$script = '(function(){var tag="[UiChemy Agent Ready] WebMCP:";var mc=document.modelContext||navigator.modelContext;'
				. 'if(!mc){if(window.console)console.info(tag,"this browser has no document.modelContext, so no tools are offered. In Chrome, turn on WebMCP in chrome://flags and relaunch.");return;}'
				. 'if(!window.fetch)return;var url=' . wp_json_encode( $url ) . ',n=0;'
				. 'function rpc(m,p,name){var h={"Content-Type":"application/json","Accept":"application/json","Mcp-Method":m};if(name)h["Mcp-Name"]=name;'
				. 'return fetch(url,{method:"POST",credentials:"omit",headers:h,body:JSON.stringify({jsonrpc:"2.0",id:++n,method:m,params:p||{}})}).then(function(r){if(!r.ok)throw new Error("the site answered "+r.status);return r.json();});}'
				. 'function wrap(t){return{name:t.name,description:t.description||t.title||t.name,inputSchema:t.inputSchema||{type:"object",properties:{}},annotations:{readOnlyHint:!(t.annotations&&t.annotations.readOnlyHint===false)},'
				. 'execute:function(a){return rpc("tools/call",{name:t.name,arguments:a||{}},t.name).then(function(r){if(r&&r.error)return{content:[{type:"text",text:String(r.error.message||"Error")}],isError:true};return r&&r.result?r.result:{content:[]};});}};}'
				. 'rpc("tools/list").then(function(res){var list=(res&&res.result&&res.result.tools)||[];'
				. 'var defs=list.map(wrap);'
				. 'if(!defs.length){console.info(tag,"the site offers no tools.");return;}'
				. 'var done=[];var jobs=[];'
				. 'if(typeof mc.registerTool==="function"){defs.forEach(function(d){try{var r=mc.registerTool(d);jobs.push(Promise.resolve(r).then(function(){done.push(d.name);}).catch(function(e){console.warn(tag,"could not add "+d.name,e);}));}catch(e){console.warn(tag,"could not add "+d.name,e);}});}'
				. 'else if(typeof mc.provideContext==="function"){try{jobs.push(Promise.resolve(mc.provideContext({tools:defs})).then(function(){defs.forEach(function(d){done.push(d.name);});}));}catch(e){console.warn(tag,"could not add tools",e);}}'
				. 'else{console.warn(tag,"navigator.modelContext has no registerTool or provideContext.");return;}'
				. 'Promise.all(jobs).then(function(){console.info(tag,done.length+" tool(s) ready: "+done.join(", "));});'
				. '}).catch(function(e){console.warn(tag,"could not reach the site\'s tools. Is Browser AI (WebMCP) on?",e);});})();';
			wp_print_inline_script_tag( $script, array( 'id' => 'uichemy-webmcp-tools' ) );
		}

		/**
		 * Chrome origin-trial token for WebMCP, when the owner has one.
		 *
		 * @return void
		 */
		public static function origin_trial() {
			$token = (string) Uich_Agent_Settings::value( 'discovery.webmcp.trial_token', '' );
			if ( '' !== $token ) {
				printf( '<meta http-equiv="origin-trial" content="%s" />' . "\n", esc_attr( $token ) );
			}
		}

		/**
		 * Opt-in cross-origin isolation headers (can break embeds and payment widgets).
		 *
		 * @return void
		 */
		public static function isolation_headers() {
			if ( headers_sent() || ! Uich_Agent_Settings::value( 'discovery.webmcp.isolation_headers', false ) ) {
				return;
			}
			header( 'Cross-Origin-Opener-Policy: same-origin' );
			header( 'Cross-Origin-Embedder-Policy: credentialless' );
		}

		/* -------------------------------------------------------------
		 * Ability actions
		 * ----------------------------------------------------------- */

		/**
		 * Add form actions.
		 *
		 * @param array $actions Actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['scan-forms']     = array(
				'description' => 'List the Composer forms on a page with their fields (name, type, label, required, options) as a visitor sees them, and whether agents may use each one.',
				'params'      => array(
					'page_id' => array(
						'type'        => 'integer',
						'description' => 'Page or post ID.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_scan' ),
			);
			$actions['set-form-agent'] = array(
				'description' => 'Let agents use one form: mode "off", "confirm" (agent fills, person sends) or "submit" (agent sends). Ask the site owner before "submit". The tool name and description are what agents see; the field list is captured now, and a later change to the form switches agent sending off until re-enabled.',
				'params'      => array(
					'page_id'     => array( 'type' => 'integer' ),
					'form_key'    => array(
						'type'        => 'string',
						'description' => 'Form key from scan-forms.',
					),
					'mode'        => array(
						'type' => 'string',
						'enum' => array( 'off', 'confirm', 'submit' ),
					),
					'tool'        => array(
						'type'        => 'string',
						'description' => 'Tool name, e.g. "send_enquiry" (lower-case, underscores).',
					),
					'title'       => array( 'type' => 'string' ),
					'description' => array(
						'type'        => 'string',
						'description' => 'When an agent should use it, e.g. "Ask the clinic a question or request a call back."',
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'act_set' ),
			);
			$actions['find-form-pages'] = array(
				'description' => 'Published public pages that contain a UiChemy form, newest first (up to 50), so every form can be listed without asking for a page.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_find_pages' ),
			);
			$actions['list-site-forms'] = array(
				'description' => 'Every UiChemy form on the site in one call: page, form key, fields (read from saved content, no page loads) and whether agents may use it.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_site_forms' ),
			);
			$actions['list-agent-forms'] = array(
				'description' => 'Every form agents may use, with its mode and tool name.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_list' ),
			);
			return $actions;
		}

		/**
		 * Action: scan-forms.
		 *
		 * @param array $params { page_id }.
		 * @return array|WP_Error
		 */
		public static function act_scan( array $params ) {
			if ( ! class_exists( 'Uich_Forms' ) ) {
				return new WP_Error( 'uich_agent_forms', 'The Composer forms module is not active.' );
			}
			$page_id = isset( $params['page_id'] ) ? absint( $params['page_id'] ) : 0;
			$forms   = self::read_page_forms( $page_id );
			if ( is_wp_error( $forms ) ) {
				return $forms;
			}
			$reg = self::registry();
			$out = array();
			foreach ( $forms as $key => $form ) {
				$id    = $page_id . ':' . $key;
				$out[] = array(
					'form_key' => $key,
					'fields'   => array_values( $form['fields'] ),
					'mode'     => isset( $reg[ $id ] ) ? $reg[ $id ]['mode'] : 'off',
					'tool'     => isset( $reg[ $id ] ) ? $reg[ $id ]['tool'] : '',
				);
			}
			return array(
				'page'  => get_permalink( $page_id ),
				'forms' => $out,
			);
		}

		/**
		 * Action: set-form-agent.
		 *
		 * @param array $params Params.
		 * @return array|WP_Error
		 */
		public static function act_set( array $params ) {
			$page_id = isset( $params['page_id'] ) ? absint( $params['page_id'] ) : 0;
			$key     = isset( $params['form_key'] ) ? sanitize_key( $params['form_key'] ) : '';
			$mode    = isset( $params['mode'] ) && in_array( $params['mode'], array( 'off', 'confirm', 'submit' ), true ) ? $params['mode'] : 'off';
			$id      = $page_id . ':' . $key;
			$reg     = self::registry();

			if ( ! current_user_can( 'edit_post', $page_id ) ) {
				return new WP_Error( 'uich_agent_cap', 'You cannot edit this page.' );
			}
			if ( 'off' === $mode ) {
				unset( $reg[ $id ] );
				$done = self::save_registry( $reg );
				return is_wp_error( $done ) ? $done : array( 'form' => $id, 'mode' => 'off' );
			}

			// Saved content first: it needs no second request, so a busy server never makes
			// the switch wait. Load the page only when the form is not found there.
			$forms = 'publish' === get_post_status( $page_id ) && '' === get_post_field( 'post_password', $page_id ) ? self::stored_page_forms( $page_id ) : array();
			if ( ! isset( $forms[ $key ] ) ) {
				$forms = self::read_page_forms( $page_id );
				if ( is_wp_error( $forms ) ) {
					return $forms;
				}
			}
			if ( ! isset( $forms[ $key ] ) ) {
				return new WP_Error( 'uich_agent_form', 'No form with that key on the page. Run scan-forms first.' );
			}
			$tool = isset( $params['tool'] ) ? (string) $params['tool'] : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{2,47}$/', $tool ) ) {
				return new WP_Error( 'uich_agent_form', 'Give the form a tool name: 3-48 lower-case letters, digits and underscores.' );
			}
			foreach ( $reg as $other_id => $other ) {
				if ( $other_id !== $id && $other['tool'] === $tool ) {
					return new WP_Error( 'uich_agent_form', 'Another form already uses that tool name.' );
				}
			}
			if ( in_array( $tool, Uich_Agent_Builtin_Tools::names(), true ) || isset( Uich_Agent_Tools::definitions()[ $tool ] ) ) {
				return new WP_Error( 'uich_agent_form', 'That tool name is already used by another tool.' );
			}
			// Title and description left out keep what was saved before.
			$prev        = isset( $reg[ $id ] ) ? $reg[ $id ] : array();
			$description = mb_substr( sanitize_text_field( isset( $params['description'] ) ? (string) $params['description'] : ( isset( $prev['description'] ) ? $prev['description'] : '' ) ), 0, 300 );
			if ( '' === $description ) {
				return new WP_Error( 'uich_agent_form', 'Add a description that tells agents when to use this form.' );
			}
			$reg[ $id ] = array(
				'page_id'     => $page_id,
				'form_key'    => $key,
				'mode'        => $mode,
				'tool'        => $tool,
				'title'       => mb_substr( sanitize_text_field( isset( $params['title'] ) && '' !== $params['title'] ? (string) $params['title'] : ( ! empty( $prev['title'] ) ? $prev['title'] : $tool ) ), 0, 80 ),
				'description' => $description,
				'fields'      => $forms[ $key ]['fields'],
				'updated_at'  => time(),
			);
			$done = self::save_registry( $reg );
			return is_wp_error( $done ) ? $done : array( 'form' => $reg[ $id ] );
		}

		/**
		 * Action: find-form-pages.
		 *
		 * Looks for the form markup (data-atom-form) in saved page content and in
		 * the page builders' own content fields, so no page has to be rendered to
		 * find out whether it has a form. scan-forms then reads each page.
		 *
		 * @return array
		 */
		public static function act_find_pages() {
			global $wpdb;
			if ( ! class_exists( 'Uich_Forms' ) ) {
				return new WP_Error( 'uich_agent_forms', 'The Composer forms module is not active.' );
			}
			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
			if ( ! $types ) {
				return array( 'pages' => array() );
			}
			$keys  = array( '_uichemy_content', '_elementor_data', '_bricks_page_content_2' );
			$like  = '%' . $wpdb->esc_like( 'data-atom-form' ) . '%';
			$t_in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$k_in  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($t_in)
					AND ( p.post_content LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ($k_in) AND m.meta_value LIKE %s ) )
					ORDER BY p.post_modified DESC LIMIT 50",
					array_merge( $types, array( $like ), $keys, array( $like ) )
				)
			);
			$out = array();
			foreach ( array_map( 'absint', (array) $ids ) as $id ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$out[] = array(
					'id'    => $id,
					'title' => get_the_title( $id ),
					'url'   => get_permalink( $id ),
					'type'  => get_post_type( $id ),
				);
			}
			return array( 'pages' => $out );
		}

		/**
		 * Action: list-site-forms.
		 *
		 * @return array|WP_Error
		 */
		public static function act_site_forms() {
			$pages = self::act_find_pages();
			if ( is_wp_error( $pages ) ) {
				return $pages;
			}
			$reg  = self::registry();
			$out  = array();
			$seen = array();
			foreach ( $pages['pages'] as $page ) {
				foreach ( self::stored_page_forms( $page['id'] ) as $key => $form ) {
					$id          = $page['id'] . ':' . $key;
					$seen[ $id ] = true;
					$saved       = isset( $reg[ $id ] ) ? $reg[ $id ] : array();
					$out[]       = array(
						'id'          => $id,
						'page_id'     => $page['id'],
						'page_title'  => $page['title'],
						'page'        => $page['url'],
						'form_key'    => $key,
						'fields'      => array_values( $form['fields'] ),
						'mode'        => isset( $saved['mode'] ) ? $saved['mode'] : 'off',
						'tool'        => isset( $saved['tool'] ) ? $saved['tool'] : '',
						'title'       => isset( $saved['title'] ) ? $saved['title'] : '',
						'description' => isset( $saved['description'] ) ? $saved['description'] : '',
						'missing'     => false,
					);
				}
			}
			// Forms set up for agents whose page no longer has them.
			foreach ( $reg as $id => $saved ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$out[] = array(
					'id'          => $id,
					'page_id'     => (int) $saved['page_id'],
					'page_title'  => get_the_title( $saved['page_id'] ),
					'page'        => get_permalink( $saved['page_id'] ),
					'form_key'    => isset( $saved['form_key'] ) ? $saved['form_key'] : substr( $id, strpos( $id, ':' ) + 1 ),
					'fields'      => array_values( isset( $saved['fields'] ) ? (array) $saved['fields'] : array() ),
					'mode'        => $saved['mode'],
					'tool'        => $saved['tool'],
					'title'       => isset( $saved['title'] ) ? $saved['title'] : '',
					'description' => isset( $saved['description'] ) ? $saved['description'] : '',
					'missing'     => true,
				);
			}
			return array( 'forms' => $out );
		}

		/**
		 * Action: list-agent-forms.
		 *
		 * @return array
		 */
		public static function act_list() {
			$out = array();
			foreach ( self::registry() as $id => $form ) {
				$out[] = array(
					'id'          => $id,
					'page_id'     => (int) $form['page_id'],
					'page_title'  => get_the_title( $form['page_id'] ),
					'form_key'    => isset( $form['form_key'] ) ? $form['form_key'] : substr( $id, strpos( $id, ':' ) + 1 ),
					'page'        => get_permalink( $form['page_id'] ),
					'mode'        => $form['mode'],
					'tool'        => $form['tool'],
					'title'       => isset( $form['title'] ) ? $form['title'] : '',
					'description' => isset( $form['description'] ) ? $form['description'] : '',
					'fields'      => array_keys( $form['fields'] ),
				);
			}
			return array( 'forms' => $out );
		}
	}
}
