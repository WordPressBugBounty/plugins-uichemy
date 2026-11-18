<?php
/**
 * Page cache rule packs and the content guard.
 *
 * Packs (data/rule-packs.json) switch on when their plugin is active. They add:
 *   - pages never saved, by ID (WooCommerce cart, EDD checkout, resolved from
 *     the plugin's own settings, never by URL text) or by path;
 *   - shortcodes and blocks that make a page uncacheable wherever they appear
 *     (detected while the page renders, so it works whatever the URL);
 *   - tracking parameters that must not be ignored (AffiliateWP's ?ref=).
 *
 * The same refusal path serves Composer's own per-visitor parts: Theme Builder
 * conditions on day / OS / browser and request dynamic data fire
 * `uichemy/cache/varies` while they render.
 *
 * Packs and built-in rules can't be switched off, by settings or by AI.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Rules' ) ) {

	class UiChemy_Cache_Rules {

		/** @var array|null */
		private static $packs = null;

		/** @var array|null Active packs. */
		private static $active = null;

		/** @var array|null First refusal raised while this page rendered. */
		private static $refusal = null;

		/** @var array<string,string|false> Shortcode tag => pack id (false = not blocked). */
		private static $tag_owner = array();

		/**
		 * Hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy/cache/page_reason', array( __CLASS__, 'page_reason' ), 10, 2 );
			add_filter( 'uichemy/cache/post_reason', array( __CLASS__, 'post_reason' ), 10, 2 );
			add_action( 'uichemy/cache/varies', array( __CLASS__, 'on_varies' ), 10, 3 );
			add_filter( 'do_shortcode_tag', array( __CLASS__, 'on_shortcode' ), 10, 2 );
			add_filter( 'render_block', array( __CLASS__, 'on_block' ), 10, 2 );
		}

		/**
		 * data/rule-packs.json.
		 *
		 * @return array
		 */
		public static function packs() {
			if ( null === self::$packs ) {
				$raw         = file_get_contents( __DIR__ . '/data/rule-packs.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local data file.
				$json        = is_string( $raw ) ? json_decode( $raw, true ) : null;
				self::$packs = isset( $json['packs'] ) && is_array( $json['packs'] ) ? $json['packs'] : array();
			}
			return self::$packs;
		}

		/**
		 * Packs whose plugin is active.
		 *
		 * @return array
		 */
		public static function active() {
			if ( null === self::$active ) {
				$slugs = array();
				foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
					$slugs[ strtok( (string) $file, '/' ) ] = true;
				}
				self::$active = array();
				foreach ( self::packs() as $pack ) {
					foreach ( $pack['slugs'] as $slug ) {
						if ( isset( $slugs[ $slug ] ) ) {
							self::$active[] = $pack;
							break;
						}
					}
				}
			}
			return self::$active;
		}

		/**
		 * Forget detection (after plugins change, and in tests).
		 *
		 * @param array|null $packs Use these as the active packs.
		 * @return void
		 */
		public static function reset( $packs = null ) {
			self::$active    = $packs;
			self::$refusal   = null;
			self::$tag_owner = array();
		}

		/**
		 * Extra bypass cookies from packs (built-in list covers today's packs).
		 *
		 * @return string[]
		 */
		public static function cookies() {
			$out = array();
			foreach ( self::active() as $pack ) {
				foreach ( isset( $pack['cookies'] ) ? $pack['cookies'] : array() as $c ) {
					$out[] = $c;
				}
			}
			return $out;
		}

		/**
		 * Path rules for the drop-in: pack paths plus pack page IDs as paths.
		 *
		 * @return array<int,array{p:string,prefix:bool,plugin:string}>
		 */
		public static function paths() {
			$out = array();
			foreach ( self::active() as $pack ) {
				foreach ( isset( $pack['paths'] ) ? $pack['paths'] : array() as $r ) {
					$out[] = array(
						'p' => $r['p'],
						'prefix' => ! empty( $r['prefix'] ),
						'plugin' => $pack['name'],
					);
				}
				foreach ( self::page_ids( $pack ) as $id ) {
					$path = UiChemy_Cache_Settings::post_rel_path( $id );
					if ( $path && '/' !== $path ) {
						$out[] = array(
							'p' => $path,
							'prefix' => false,
							'plugin' => $pack['name'],
						);
					}
				}
			}
			return $out;
		}

		/**
		 * Parameters a pack needs to see (removed from the ignore list).
		 *
		 * @return string[]
		 */
		public static function unignored_params() {
			$out = array();
			foreach ( self::active() as $pack ) {
				foreach ( isset( $pack['unignore_params'] ) ? $pack['unignore_params'] : array() as $p ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		/**
		 * Page IDs a pack protects, read from the plugin's own settings.
		 *
		 * @param array $pack Pack.
		 * @return int[]
		 */
		public static function page_ids( array $pack ) {
			$ids = array();
			foreach ( isset( $pack['page_ids'] ) ? $pack['page_ids'] : array() as $ref ) {
				list( $kind, $name ) = array_pad( explode( ':', $ref, 2 ), 2, '' );
				$id = 0;
				if ( 'wc' === $kind && function_exists( 'wc_get_page_id' ) ) {
					$id = (int) wc_get_page_id( $name );
				} elseif ( 'edd' === $kind && function_exists( 'edd_get_option' ) ) {
					$id = (int) edd_get_option( $name, 0 );
				}
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}

		/**
		 * Content guard patterns for the engine (HTML markers). None needed today:
		 * components are caught while they render.
		 *
		 * @return array
		 */
		public static function guard() {
			return array();
		}

		// ------------------------------------------------------------------
		// Refusals while a page renders.
		// ------------------------------------------------------------------

		/**
		 * Refuse to save the current page. The first refusal wins.
		 *
		 * @param string $reason Reason code.
		 * @param string $detail Plugin or component.
		 * @return void
		 */
		public static function refuse( $reason, $detail ) {
			if ( self::$refusal ) {
				return;
			}
			self::$refusal = array( $reason, $detail );
			if ( class_exists( 'UiChemy_Cache_Engine', false ) && is_array( UiChemy_Cache_Engine::$verdict ) ) {
				UiChemy_Cache_Engine::$verdict = array(
					'store' => false,
					'reason' => $reason,
					'detail' => $detail,
				);
			}
		}

		/**
		 * A Composer part that differs per visitor rendered.
		 *
		 * Request values: a query parameter is safe unless it is on the ignore
		 * list, because any other parameter already bypasses the cache (so the
		 * saved page always saw "no value"). Form posts and the referrer always
		 * vary.
		 *
		 * @param string $what  day_of_week | os | browser | request.
		 * @param string $field For request: get | post | referrer.
		 * @param array  $args  For request.get: the parameter name.
		 * @return void
		 */
		public static function on_varies( $what, $field = '', $args = array() ) {
			if ( 'request' === $what && 'get' === $field ) {
				$config = class_exists( 'UiChemy_Cache_Engine', false ) ? UiChemy_Cache_Engine::config() : null;
				$param  = isset( $args[0] ) ? (string) $args[0] : '';
				if ( ! $config || '' === $param || ! UiChemy_Cache_Engine::param_ignored( $param, $config['ignored_params'] ) ) {
					return;
				}
			}
			$labels = array(
				'day_of_week' => 'UiChemy Theme Builder (day of week condition)',
				'os'          => 'UiChemy Theme Builder (operating system condition)',
				'browser'     => 'UiChemy Theme Builder (browser condition)',
				'request'     => 'UiChemy dynamic data (request values)',
			);
			self::refuse( 'rule_pack', isset( $labels[ $what ] ) ? $labels[ $what ] : 'UiChemy' );
		}

		/**
		 * do_shortcode_tag: refuse when a blocked shortcode renders.
		 *
		 * @param string $output Shortcode output.
		 * @param string $tag    Shortcode tag.
		 * @return string
		 */
		public static function on_shortcode( $output, $tag ) {
			$pack = self::shortcode_pack( (string) $tag );
			if ( $pack ) {
				self::refuse( 'blocked_content', isset( $pack['component'] ) ? $pack['component'] : $pack['name'] );
			}
			return $output;
		}

		/**
		 * render_block: refuse when a blocked block renders.
		 *
		 * @param string $content Block HTML.
		 * @param array  $block   Parsed block.
		 * @return string
		 */
		public static function on_block( $content, $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( '' !== $name ) {
				$pack = self::block_pack( $name );
				if ( $pack ) {
					self::refuse( 'blocked_content', isset( $pack['component'] ) ? $pack['component'] : $pack['name'] );
				}
			}
			return $content;
		}

		/**
		 * Which active pack blocks this shortcode: listed by name, or registered
		 * by the pack's own plugin ("own_components").
		 *
		 * @param string $tag Shortcode tag.
		 * @return array|null
		 */
		public static function shortcode_pack( $tag ) {
			if ( ! array_key_exists( $tag, self::$tag_owner ) ) {
				self::$tag_owner[ $tag ] = false;
				foreach ( self::active() as $pack ) {
					if ( in_array( $tag, isset( $pack['shortcodes'] ) ? $pack['shortcodes'] : array(), true ) ) {
						self::$tag_owner[ $tag ] = $pack['id'];
						break;
					}
				}
				if ( ! self::$tag_owner[ $tag ] ) {
					global $shortcode_tags;
					$file = isset( $shortcode_tags[ $tag ] ) ? self::callback_file( $shortcode_tags[ $tag ] ) : '';
					$pack = self::pack_for_file( $file );
					if ( $pack ) {
						self::$tag_owner[ $tag ] = $pack['id'];
					}
				}
			}
			return self::$tag_owner[ $tag ] ? self::pack_by_id( self::$tag_owner[ $tag ] ) : null;
		}

		/**
		 * Which active pack blocks this block.
		 *
		 * @param string $name Block name.
		 * @return array|null
		 */
		public static function block_pack( $name ) {
			$key = 'block:' . $name;
			if ( ! array_key_exists( $key, self::$tag_owner ) ) {
				self::$tag_owner[ $key ] = false;
				foreach ( self::active() as $pack ) {
					if ( in_array( $name, isset( $pack['blocks'] ) ? $pack['blocks'] : array(), true ) ) {
						self::$tag_owner[ $key ] = $pack['id'];
						break;
					}
				}
				if ( ! self::$tag_owner[ $key ] && class_exists( 'WP_Block_Type_Registry' ) ) {
					$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
					$file = $type && $type->render_callback ? self::callback_file( $type->render_callback ) : '';
					$pack = self::pack_for_file( $file );
					if ( $pack ) {
						self::$tag_owner[ $key ] = $pack['id'];
					}
				}
			}
			return self::$tag_owner[ $key ] ? self::pack_by_id( self::$tag_owner[ $key ] ) : null;
		}

		/**
		 * The active "own_components" pack whose plugin folder holds $file.
		 *
		 * @param string $file PHP file.
		 * @return array|null
		 */
		private static function pack_for_file( $file ) {
			if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
				return null;
			}
			$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
			$file    = wp_normalize_path( $file );
			if ( 0 !== strpos( $file, $plugins ) ) {
				return null;
			}
			$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
			foreach ( self::active() as $pack ) {
				if ( ! empty( $pack['own_components'] ) && in_array( $slug, $pack['slugs'], true ) ) {
					return $pack;
				}
			}
			return null;
		}

		/**
		 * The file a callback is defined in.
		 *
		 * @param mixed $cb Callback.
		 * @return string
		 */
		private static function callback_file( $cb ) {
			try {
				if ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
					$cb = explode( '::', $cb, 2 );
				}
				if ( is_array( $cb ) && 2 === count( $cb ) ) {
					$ref = new ReflectionMethod( $cb[0], $cb[1] );
				} elseif ( $cb instanceof Closure || ( is_string( $cb ) && function_exists( $cb ) ) ) {
					$ref = new ReflectionFunction( $cb );
				} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
					$ref = new ReflectionMethod( $cb, '__invoke' );
				} else {
					return '';
				}
				return (string) $ref->getFileName();
			} catch ( \ReflectionException $e ) {
				return '';
			}
		}

		/**
		 * @param string $id Pack id.
		 * @return array|null
		 */
		private static function pack_by_id( $id ) {
			foreach ( self::active() as $pack ) {
				if ( $pack['id'] === $id ) {
					return $pack;
				}
			}
			return null;
		}

		// ------------------------------------------------------------------
		// Verdict filters.
		// ------------------------------------------------------------------

		/**
		 * uichemy/cache/page_reason: page IDs and refusals raised so far.
		 *
		 * @param array|null $reason  Earlier answer.
		 * @param int        $post_id Queried post.
		 * @return array|null
		 */
		public static function page_reason( $reason, $post_id ) {
			if ( $reason ) {
				return $reason;
			}
			if ( self::$refusal ) {
				return self::$refusal;
			}
			if ( $post_id ) {
				foreach ( self::active() as $pack ) {
					if ( in_array( (int) $post_id, self::page_ids( $pack ), true ) ) {
						return array( 'rule_pack', $pack['name'] );
					}
				}
			}
			return null;
		}

		/**
		 * uichemy/cache/post_reason: the same rules, read from saved content (for
		 * cache-status and the editor, where nothing is rendering).
		 *
		 * @param array|null $reason  Earlier answer.
		 * @param int        $post_id Post.
		 * @return array|null
		 */
		public static function post_reason( $reason, $post_id ) {
			if ( $reason ) {
				return $reason;
			}
			$from_ids = self::page_reason( null, $post_id );
			if ( $from_ids ) {
				return $from_ids;
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				return null;
			}
			if ( preg_match_all( '/\[([A-Za-z0-9_-]+)/', $post->post_content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $tag ) {
					$pack = shortcode_exists( $tag ) ? self::shortcode_pack( $tag ) : null;
					if ( $pack ) {
						return array( 'blocked_content', isset( $pack['component'] ) ? $pack['component'] : $pack['name'] );
					}
				}
			}
			if ( preg_match_all( '/<!--\s+wp:([a-z0-9-]+\/[a-z0-9-]+)/', $post->post_content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $name ) {
					$pack = self::block_pack( $name );
					if ( $pack ) {
						return array( 'blocked_content', isset( $pack['component'] ) ? $pack['component'] : $pack['name'] );
					}
				}
			}
			return null;
		}

		/**
		 * The plugin a cookie belongs to, for reason texts.
		 *
		 * @param string $cookie Cookie name.
		 * @return string
		 */
		public static function plugin_for_cookie( $cookie ) {
			$map = array(
				'wordpress_logged_in_' => 'WordPress',
				'wp-postpass_'         => 'WordPress',
				'comment_author_'      => 'WordPress',
				'woocommerce'          => 'WooCommerce',
				'wp_woocommerce'       => 'WooCommerce',
				'store_notice'         => 'WooCommerce',
				'wcml_'                => 'WooCommerce Multilingual',
				'edd_'                 => 'Easy Digital Downloads',
				'cart_discounts'       => 'Easy Digital Downloads',
				'preset_discount'      => 'Easy Digital Downloads',
				'rcp-'                 => 'Restrict Content Pro',
				'affwp'                => 'AffiliateWP',
				'wp_affwp'             => 'AffiliateWP',
				'pmpro_'               => 'Paid Memberships Pro',
			);
			foreach ( $map as $prefix => $plugin ) {
				if ( 0 === strpos( (string) $cookie, $prefix ) ) {
					return $plugin;
				}
			}
			return 'a plugin';
		}

		/**
		 * Detected plugins panel (copy.json detected_plugins).
		 *
		 * @return array<int,array{panel:string,plugins:string[]}>
		 */
		public static function detected() {
			$groups = array();
			foreach ( self::active() as $pack ) {
				$groups[ $pack['panel'] ][] = $pack['name'];
			}
			$out = array();
			foreach ( $groups as $panel => $names ) {
				$out[] = array(
					'panel' => $panel,
					'plugins' => array_values( array_unique( $names ) ),
				);
			}
			return $out;
		}
	}
}
