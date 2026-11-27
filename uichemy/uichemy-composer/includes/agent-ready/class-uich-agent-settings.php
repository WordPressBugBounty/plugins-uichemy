<?php
/**
 * Agent Ready settings: one WordPress option (uichemy_agent_ready_settings).
 *
 * Every option the settings screen shows is here, with its default and its
 * sanitiser, so the dashboard, the admin abilities and the runtime read one
 * shape. Reads merge the stored file over the defaults, so a key added in a
 * later version simply takes its default on old sites.
 *
 * The master switch is NOT in this file: it lives in the module's option row
 * (Uich_Agent_Store::OPTION) because Settings > Features reads it before the
 * module boots.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Settings' ) ) {

	/**
	 * Settings shape, read and write.
	 */
	class Uich_Agent_Settings {

		/**
		 * File name inside the store.
		 */
		const FILE = 'config.json';

		/**
		 * Schema version of config.json.
		 */
		const VERSION = 1;

		/**
		 * Request-level cache.
		 *
		 * @var array|null
		 */
		private static $cache = null;

		/**
		 * Defaults for every option.
		 *
		 * Rate limits are per IP per minute except `site`, which is per minute for
		 * the whole site. They are starting values; final numbers come from load
		 * tests on a shared-host profile.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'version'     => self::VERSION,
				'agent_pages' => array(
					'enabled'            => true,
					'param_name'         => 'view',
					'param_value'        => 'agent',
					'md_urls'            => true,
					'accept_negotiation' => true,
					'post_types'         => array(), // Empty = every public type.
					'archives'           => array(
						'post_type' => true,
						'taxonomy'  => true,
						'author'    => false,
					),
					'exclude_patterns'   => array(),
					'include_noindex'    => false,
					'content'            => array(
						'front_matter'   => true,
						'profile_footer' => true,
						'image_alt'      => true,
					),
					'warm'               => array(
						'enabled' => true,
						'speed'   => 'gentle',
					),
				),
				'llms'        => array(
					'enabled'        => true,
					'mode'           => 'generated',
					'summary'        => '',
					'intro'          => '',
					'key_pages'      => array(),
					'optional_links' => array(),
					'sections'       => true,
					'full'           => true,
					'full_max_kb'    => 500,
					'per_language'   => true,
				),
				'discovery'   => array(
					'link_headers'   => true,
					'head_links'     => true,
					'ai_catalog'     => true,
					'api_catalog'    => true,
					'server_card'    => true,
					'bots'           => array(
						'default' => 'allow',
						'rules'   => array(),
					),
					'content_signal' => array(
						'search'   => true,
						'ai_input' => true,
						'ai_train' => false,
					),
					'webmcp'         => array(
						'forms'             => true,
						'trial_token'       => '',
						'isolation_headers' => false,
					),
					'ask_ai'         => array(
						'prompt'     => '',
						'assistants' => array( 'chatgpt', 'claude', 'perplexity', 'grok' ),
					),
				),
				'mcp'         => array(
					'enabled'         => true,
					'path'            => 'mcp',
					'name'            => '',
					'instructions'    => '',
					'rate'            => array(
						'cached'   => 120,
						'uncached' => 30,
						'write'    => 10,
						'site'     => 600,
					),
					'max_results'       => 20,
					'allowed_origins'   => array(),
					'trusted_proxy'     => '',
					'disabled_builtins' => array(),
					// Master switch for anything that sends or changes data (form sending,
					// the requests inbox, plugin write tools). Only a person can switch it on.
					'writes'            => array(
						'enabled' => false,
					),
					'inbox'             => array(
						'enabled'      => false,
						'kinds'        => array( 'booking', 'enquiry' ),
						// Email each new request to the owner, through the site's own mail setup.
						'notify'       => true,
						'notify_email' => '',
					),
				),
				'logs'        => array(
					'retention_days' => 30,
				),
			);
		}

		/**
		 * Current settings, defaults filled in.
		 *
		 * A config.json that fails its hash check is ignored (defaults are used)
		 * and reported by get_status(), never silently trusted.
		 *
		 * @return array
		 */
		public static function get() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}
			$stored = Uich_Agent_Store::read_json( self::FILE );
			$stored = is_array( $stored ) ? $stored : array();
			self::$cache = self::sanitize( self::merge( self::defaults(), $stored ) );
			return self::$cache;
		}

		/**
		 * Whether agents may send or change data (form sending, inbox, plugin write tools).
		 *
		 * @return bool
		 */
		public static function writes_enabled() {
			return (bool) self::value( 'mcp.writes.enabled', false );
		}

		/**
		 * One value by dot path.
		 *
		 * @param string $path    Dot path.
		 * @param mixed  $default Fallback.
		 * @return mixed
		 */
		public static function value( $path, $default = null ) {
			$node = self::get();
			foreach ( explode( '.', (string) $path ) as $key ) {
				if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
					return $default;
				}
				$node = $node[ $key ];
			}
			return $node;
		}

		/**
		 * Merge a partial update into the stored settings and save.
		 *
		 * @param bool  $human True only for a person on the dashboard (cookie + REST nonce).
		 * @param array $patch Partial settings (same shape as defaults()).
		 * @return array|WP_Error The saved settings.
		 */
		public static function update( array $patch, $human = false ) {
			// The write switch is a person's decision: an AI client's change to it is ignored.
			if ( ! $human && isset( $patch['mcp'] ) && is_array( $patch['mcp'] ) ) {
				unset( $patch['mcp']['writes'] );
			}
			$next  = self::sanitize( self::merge( self::get(), $patch ) );
			$saved = Uich_Agent_Store::write_json( self::FILE, $next );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::$cache = $next;
			/**
			 * Fires after Agent Ready settings are saved.
			 *
			 * @param array $next The saved settings.
			 */
			do_action( 'uichemy_agent_ready_settings_updated', $next );
			return $next;
		}

		/**
		 * Reset to defaults.
		 *
		 * @return array|WP_Error
		 */
		public static function reset() {
			self::$cache = null;
			$saved       = Uich_Agent_Store::write_json( self::FILE, self::defaults() );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			do_action( 'uichemy_agent_ready_settings_updated', self::defaults() );
			return self::get();
		}

		/**
		 * Drop the request cache (tests, imports).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$cache = null;
		}

		/**
		 * Recursive merge where lists are replaced, not appended.
		 *
		 * @param array $base  Base.
		 * @param array $patch Patch.
		 * @return array
		 */
		private static function merge( array $base, array $patch ) {
			foreach ( $patch as $key => $value ) {
				if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
					$base[ $key ] = self::merge( $base[ $key ], $value );
				} else {
					$base[ $key ] = $value;
				}
			}
			return $base;
		}

		/**
		 * Whether an array is a list (sequential keys) - an empty array counts as a list.
		 *
		 * @param array $value Array.
		 * @return bool
		 */
		private static function is_list( array $value ) {
			return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		/**
		 * Clamp every value to its type and range. Unknown keys are dropped.
		 *
		 * @param array $in Merged settings.
		 * @return array
		 */
		public static function sanitize( array $in ) {
			$d   = self::defaults();
			$out = array( 'version' => self::VERSION );

			$ap               = self::section( $in, 'agent_pages' );
			$out['agent_pages'] = array(
				'enabled'            => self::flag( $ap, 'enabled', true ),
				'param_name'         => self::slug( isset( $ap['param_name'] ) ? $ap['param_name'] : '', $d['agent_pages']['param_name'] ),
				'param_value'        => self::slug( isset( $ap['param_value'] ) ? $ap['param_value'] : '', $d['agent_pages']['param_value'] ),
				'md_urls'            => self::flag( $ap, 'md_urls', true ),
				'accept_negotiation' => self::flag( $ap, 'accept_negotiation', true ),
				'post_types'         => self::slug_list( isset( $ap['post_types'] ) ? $ap['post_types'] : array() ),
				'archives'           => array(
					'post_type' => self::flag( self::section( $ap, 'archives' ), 'post_type', true ),
					'taxonomy'  => self::flag( self::section( $ap, 'archives' ), 'taxonomy', true ),
					'author'    => self::flag( self::section( $ap, 'archives' ), 'author', false ),
				),
				'exclude_patterns'   => self::text_list( isset( $ap['exclude_patterns'] ) ? $ap['exclude_patterns'] : array(), 200, 100 ),
				'include_noindex'    => self::flag( $ap, 'include_noindex', false ),
				'content'            => array(
					'front_matter'   => self::flag( self::section( $ap, 'content' ), 'front_matter', true ),
					'profile_footer' => self::flag( self::section( $ap, 'content' ), 'profile_footer', true ),
					'image_alt'      => self::flag( self::section( $ap, 'content' ), 'image_alt', true ),
				),
				'warm'               => array(
					'enabled' => self::flag( self::section( $ap, 'warm' ), 'enabled', true ),
					'speed'   => self::pick( self::section( $ap, 'warm' ), 'speed', array( 'gentle', 'normal', 'fast' ), 'gentle' ),
				),
			);
			// The parameter must never collide with WordPress's own query vars.
			if ( in_array( $out['agent_pages']['param_name'], array( 'p', 'page', 'paged', 'name', 's', 'cat', 'tag', 'author', 'feed', 'preview', 'page_id', 'post_type', 'm', 'year', 'monthnum', 'day', 'attachment' ), true ) ) {
				$out['agent_pages']['param_name'] = $d['agent_pages']['param_name'];
			}

			$ll          = self::section( $in, 'llms' );
			$out['llms'] = array(
				'enabled'        => self::flag( $ll, 'enabled', true ),
				'mode'           => self::pick( $ll, 'mode', array( 'generated', 'claude', 'manual' ), 'generated' ),
				'summary'        => self::text( isset( $ll['summary'] ) ? $ll['summary'] : '', 500 ),
				'intro'          => self::textarea( isset( $ll['intro'] ) ? $ll['intro'] : '', 4000 ),
				'key_pages'      => self::id_list( isset( $ll['key_pages'] ) ? $ll['key_pages'] : array(), 50 ),
				'optional_links' => self::link_list( isset( $ll['optional_links'] ) ? $ll['optional_links'] : array(), 50 ),
				'sections'       => self::flag( $ll, 'sections', true ),
				'full'           => self::flag( $ll, 'full', true ),
				'full_max_kb'    => self::int( $ll, 'full_max_kb', 50, 5000, 500 ),
				'per_language'   => self::flag( $ll, 'per_language', true ),
			);

			$di          = self::section( $in, 'discovery' );
			$bots        = self::section( $di, 'bots' );
			$rules       = array();
			foreach ( ( isset( $bots['rules'] ) && is_array( $bots['rules'] ) ? $bots['rules'] : array() ) as $bot => $rule ) {
				$bot = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $bot );
				if ( '' !== $bot && in_array( $rule, array( 'allow', 'block' ), true ) && count( $rules ) < 100 ) {
					$rules[ $bot ] = $rule;
				}
			}
			$cs                = self::section( $di, 'content_signal' );
			$signals           = array(
				'search'   => self::flag( $cs, 'search', true ),
				'ai_input' => self::flag( $cs, 'ai_input', true ),
				'ai_train' => self::flag( $cs, 'ai_train', false ),
			);
			// AI crawlers follow the owner's wishes: allowed when search or AI answers
			// is on, blocked when both are off. There is no separate switch for it.
			$crawlers          = ( $signals['search'] || $signals['ai_input'] ) ? 'allow' : 'block';
			$wm                = self::section( $di, 'webmcp' );
			$ask               = self::section( $di, 'ask_ai' );
			$out['discovery']  = array(
				'link_headers'   => self::flag( $di, 'link_headers', true ),
				'head_links'     => self::flag( $di, 'head_links', true ),
				'ai_catalog'     => self::flag( $di, 'ai_catalog', true ),
				'api_catalog'    => self::flag( $di, 'api_catalog', true ),
				'server_card'    => self::flag( $di, 'server_card', true ),
				'bots'           => array(
					'default' => $crawlers,
					'rules'   => $rules,
				),
				'content_signal' => $signals,
				'webmcp'         => array(
					'forms'             => self::flag( $wm, 'forms', true ),
					'trial_token'       => preg_replace( '/[^A-Za-z0-9+\/=]/', '', substr( (string) ( isset( $wm['trial_token'] ) ? $wm['trial_token'] : '' ), 0, 2000 ) ),
					'isolation_headers' => self::flag( $wm, 'isolation_headers', false ),
				),
				'ask_ai'         => array(
					'prompt'     => self::text( isset( $ask['prompt'] ) ? $ask['prompt'] : '', 500 ),
					'assistants' => array_values( array_intersect( self::slug_list( isset( $ask['assistants'] ) ? $ask['assistants'] : array() ), array( 'chatgpt', 'claude', 'perplexity', 'grok', 'gemini' ) ) ),
				),
			);

			$mc         = self::section( $in, 'mcp' );
			$rate       = self::section( $mc, 'rate' );
			$path       = trim( (string) ( isset( $mc['path'] ) ? $mc['path'] : '' ), '/' );
			$path       = preg_match( '#^[a-z0-9][a-z0-9\-]{0,40}$#', $path ) && ! in_array( $path, array( 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed' ), true ) ? $path : 'mcp';
			$origins    = array();
			foreach ( ( isset( $mc['allowed_origins'] ) && is_array( $mc['allowed_origins'] ) ? $mc['allowed_origins'] : array() ) as $origin ) {
				$origin = self::origin( $origin );
				if ( $origin && count( $origins ) < 50 ) {
					$origins[] = $origin;
				}
			}
			$out['mcp'] = array(
				'enabled'         => self::flag( $mc, 'enabled', true ),
				'path'            => $path,
				'name'            => self::text( isset( $mc['name'] ) ? $mc['name'] : '', 120 ),
				'instructions'    => self::textarea( isset( $mc['instructions'] ) ? $mc['instructions'] : '', 2000 ),
				'rate'            => array(
					'cached'   => self::int( $rate, 'cached', 10, 10000, 120 ),
					'uncached' => self::int( $rate, 'uncached', 1, 2000, 30 ),
					'write'    => self::int( $rate, 'write', 1, 500, 10 ),
					'site'     => self::int( $rate, 'site', 10, 100000, 600 ),
				),
				'max_results'     => self::int( $mc, 'max_results', 1, 100, 20 ),
				'allowed_origins' => array_values( array_unique( $origins ) ),
				'trusted_proxy'   => self::pick( $mc, 'trusted_proxy', array( '', 'cloudflare', 'x-forwarded-for' ), '' ),
				'writes'            => array(
					'enabled' => self::flag( self::section( $mc, 'writes' ), 'enabled', false ),
				),
				'inbox'             => array(
					'enabled'      => self::flag( self::section( $mc, 'inbox' ), 'enabled', false ),
					'kinds'        => array_values( array_intersect( self::slug_list( isset( $mc['inbox']['kinds'] ) ? $mc['inbox']['kinds'] : array( 'booking', 'enquiry' ) ), array( 'booking', 'enquiry', 'quote', 'callback' ) ) ),
					'notify'       => self::flag( self::section( $mc, 'inbox' ), 'notify', true ),
					'notify_email' => sanitize_email( isset( $mc['inbox']['notify_email'] ) ? (string) $mc['inbox']['notify_email'] : '' ),
				),
				'disabled_builtins' => array_values(
					array_filter(
						array_map( 'sanitize_key', isset( $mc['disabled_builtins'] ) ? (array) $mc['disabled_builtins'] : array() ),
						function ( $name ) {
							return (bool) preg_match( '/^[a-z][a-z0-9_]{2,47}$/', $name );
						}
					)
				),
			);

			$lg          = self::section( $in, 'logs' );
			$out['logs'] = array(
				'retention_days' => self::int( $lg, 'retention_days', 1, 365, 30 ),
			);

			return $out;
		}

		/* -------------------------------------------------------------
		 * Sanitiser helpers
		 * ----------------------------------------------------------- */

		/**
		 * A sub-array or an empty array.
		 *
		 * @param array  $in  Parent.
		 * @param string $key Key.
		 * @return array
		 */
		private static function section( $in, $key ) {
			return is_array( $in ) && isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? $in[ $key ] : array();
		}

		/**
		 * Boolean with a default.
		 *
		 * @param array  $in      Parent.
		 * @param string $key     Key.
		 * @param bool   $default Default.
		 * @return bool
		 */
		private static function flag( $in, $key, $default ) {
			return is_array( $in ) && array_key_exists( $key, $in ) ? (bool) filter_var( $in[ $key ], FILTER_VALIDATE_BOOLEAN ) : (bool) $default;
		}

		/**
		 * Integer clamped to a range.
		 *
		 * @param array  $in      Parent.
		 * @param string $key     Key.
		 * @param int    $min     Min.
		 * @param int    $max     Max.
		 * @param int    $default Default.
		 * @return int
		 */
		private static function int( $in, $key, $min, $max, $default ) {
			if ( ! is_array( $in ) || ! isset( $in[ $key ] ) || ! is_numeric( $in[ $key ] ) ) {
				return $default;
			}
			return max( $min, min( $max, (int) $in[ $key ] ) );
		}

		/**
		 * One of a fixed set.
		 *
		 * @param array  $in      Parent.
		 * @param string $key     Key.
		 * @param array  $allowed Allowed values.
		 * @param string $default Default.
		 * @return string
		 */
		private static function pick( $in, $key, array $allowed, $default ) {
			return is_array( $in ) && isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $default;
		}

		/**
		 * A URL-safe slug, or the default when empty.
		 *
		 * @param mixed  $value   Value.
		 * @param string $default Default.
		 * @return string
		 */
		private static function slug( $value, $default ) {
			$value = sanitize_key( (string) $value );
			return '' === $value ? $default : substr( $value, 0, 40 );
		}

		/**
		 * A list of slugs.
		 *
		 * @param mixed $value Value.
		 * @return string[]
		 */
		private static function slug_list( $value ) {
			$out = array();
			foreach ( (array) $value as $item ) {
				$item = sanitize_key( (string) $item );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return array_values( array_unique( array_slice( $out, 0, 100 ) ) );
		}

		/**
		 * Single-line plain text.
		 *
		 * @param mixed $value Value.
		 * @param int   $max   Max length.
		 * @return string
		 */
		private static function text( $value, $max ) {
			return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
		}

		/**
		 * Multi-line plain text (Markdown allowed, no HTML).
		 *
		 * @param mixed $value Value.
		 * @param int   $max   Max length.
		 * @return string
		 */
		private static function textarea( $value, $max ) {
			return mb_substr( sanitize_textarea_field( wp_strip_all_tags( (string) $value ) ), 0, $max );
		}

		/**
		 * A list of single-line strings.
		 *
		 * @param mixed $value Value.
		 * @param int   $max   Max length of each.
		 * @param int   $count Max items.
		 * @return string[]
		 */
		private static function text_list( $value, $max, $count ) {
			$out = array();
			foreach ( (array) $value as $item ) {
				$item = self::text( $item, $max );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return array_slice( array_values( array_unique( $out ) ), 0, $count );
		}

		/**
		 * A list of positive post IDs.
		 *
		 * @param mixed $value Value.
		 * @param int   $count Max items.
		 * @return int[]
		 */
		private static function id_list( $value, $count ) {
			$out = array_filter( array_map( 'absint', (array) $value ) );
			return array_slice( array_values( array_unique( $out ) ), 0, $count );
		}

		/**
		 * A list of {title,url,note} links (http/https only).
		 *
		 * @param mixed $value Value.
		 * @param int   $count Max items.
		 * @return array
		 */
		private static function link_list( $value, $count ) {
			$out = array();
			foreach ( (array) $value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$url = esc_url_raw( isset( $item['url'] ) ? (string) $item['url'] : '', array( 'http', 'https' ) );
				if ( '' === $url ) {
					continue;
				}
				$out[] = array(
					'title' => self::text( isset( $item['title'] ) ? $item['title'] : $url, 120 ),
					'url'   => $url,
					'note'  => self::text( isset( $item['note'] ) ? $item['note'] : '', 200 ),
				);
			}
			return array_slice( $out, 0, $count );
		}

		/**
		 * A scheme://host[:port] origin, or false.
		 *
		 * @param mixed $value Value.
		 * @return string|false
		 */
		private static function origin( $value ) {
			$parts = wp_parse_url( trim( (string) $value ) );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
				return false;
			}
			$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			return $origin;
		}
	}
}
