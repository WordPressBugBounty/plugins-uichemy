<?php
/**
 * Fast Load & Critical CSS optimizer for UiChemy pages.
 *
 * Speeds up front-end load times on pages developed through UiChemy / Composer:
 *   1. Dequeues jQuery Migrate (jquery-migrate.min.js) and jQuery UI Core (core.min.js)
 *      on the front end for regular visitors (kept active for admin and editor contexts).
 *   2. Extracts and inlines critical scoped CSS for the first header and hero sections
 *      directly inside <head> so above-the-fold content paints instantly without waiting
 *      for body or footer stylesheets.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Fast_Load' ) ) {

	class UiChemy_Fast_Load {

		/**
		 * How many sections at the start of a document count as its starting sections.
		 * Keep in step with STARTING_SECTIONS in composer-code.jsx (the editor).
		 */
		const STARTING_SECTIONS = 3;

		/**
		 * Where a section's CSS or JS is printed when the author has not chosen.
		 *
		 * CSS and JS follow the same rule: the starting sections go before </head>,
		 * later sections before </body>. Head JS still waits for the DOM (see
		 * print_head_placed_code()).
		 *
		 * The editor shows this as the selected option until one is chosen, so the
		 * dropdown and the front end agree.
		 *
		 * @param string $kind          'css' or 'js'.
		 * @param int    $section_index 0-based position of the section in its document.
		 * @return string 'head' or 'body'
		 */
		public static function default_placement( $kind, $section_index ) {
			unset( $kind ); // Same rule for 'css' and 'js'.
			if ( (int) $section_index >= 0 && (int) $section_index < self::STARTING_SECTIONS ) {
				return 'head';
			}
			return 'body';
		}

		/**
		 * Settings that are Elementor's own options, by registry key. For these the
		 * Elementor option is the single source of truth: UiChemy reads it live and
		 * writes to it, so the two screens can never disagree.
		 *
		 * `on` / `off` are the option values when the UiChemy switch is on / off;
		 * `values` lists the allowed values of a choice. Kept as a constant (not in
		 * registry()) so reading a setting early in the request never needs translations.
		 */
		const SYNC = array(
			'perf_elementor_fonts'        => array(
				'option'  => 'elementor_google_font',
				'on'      => '0',
				'off'     => '1',
				'default' => '1',
			),
			'perf_font_awesome'           => array(
				'option'  => 'elementor_load_fa4_shim',
				'on'      => '',
				'off'     => 'yes',
				'default' => '',
			),
			'perf_elementor_font_display' => array(
				'option'  => 'elementor_font_display',
				'default' => 'swap',
				'values'  => array( 'auto', 'block', 'swap', 'fallback', 'optional' ),
			),
		);

		/**
		 * The one list of performance settings. Everything else reads it: the option
		 * defaults, the Dashboard > Performance screen, the MCP tools' setting names
		 * and the sanitizer. Add an entry here and it appears in all of them.
		 *
		 * Each entry is its own on/off switch in the `uichemy_settings` option and
		 * defaults to on. The master switch (`enable_fast_load`) is not in this list.
		 * `group` is where the dashboard shows it: 'optimizations' or 'elementor'
		 * (the Elementor Optimisations section).
		 *
		 * @return array<int,array{name:string,key:string,group:string,tag:string,label:string,desc:string}>
		 */
		public static function registry() {
			if ( null !== self::$registry_cache ) {
				return self::$registry_cache;
			}
			self::$registry_cache = array(
				array(
					'name'  => 'critical-css',
					'key'   => 'perf_critical_css',
					'group' => 'optimizations',
					'tag'   => __( 'Header / Footer', 'uichemy' ),
					'label' => __( 'Header & first sections critical CSS', 'uichemy' ),
					'desc'  => __( 'Puts the CSS of the header and first sections in <head>, so the top of the page shows at once.', 'uichemy' ),
				),
				array(
					'name'  => 'remove-js',
					'key'   => 'perf_remove_js',
					'group' => 'optimizations',
					'tag'   => __( 'JS', 'uichemy' ),
					'label' => __( 'Remove unused JavaScript', 'uichemy' ),
					'desc'  => __( 'Removes jQuery and Elementor scripts on pages built only with Composer widgets.', 'uichemy' ),
				),
				array(
					'name'  => 'defer-js',
					'key'   => 'perf_defer_js',
					'group' => 'optimizations',
					'tag'   => __( 'JS', 'uichemy' ),
					'label' => __( 'Defer third-party scripts', 'uichemy' ),
					'desc'  => __( 'Loads library scripts (GSAP, Lenis…) without blocking the page.', 'uichemy' ),
				),
				array(
					'name'  => 'lazy-images',
					'key'   => 'perf_images',
					'group' => 'optimizations',
					'tag'   => __( 'Images', 'uichemy' ),
					'label' => __( 'Lazy-load images', 'uichemy' ),
					'desc'  => __( 'Loads images below the first sections only when they come into view.', 'uichemy' ),
				),
				array(
					'name'  => 'lcp-animations',
					'key'   => 'perf_lcp_keyframes',
					'group' => 'optimizations',
					'tag'   => __( 'Animation', 'uichemy' ),
					'label' => __( 'LCP-safe entrance animations', 'uichemy' ),
					'desc'  => __( 'Starts fade-in animations at 1% opacity, so PageSpeed can measure LCP.', 'uichemy' ),
				),
				// ---- Elementor Optimisations: Elementor's own assets. They only apply to pages
				// built from Composer widgets alone; a page with any native Elementor widget
				// keeps all of them.
				array(
					'name'  => 'elementor-css',
					'key'   => 'perf_elementor_css',
					'group' => 'elementor',
					'tag'   => __( 'CSS', 'uichemy' ),
					'label' => __( 'Elementor CSS', 'uichemy' ),
					'desc'  => __( 'Stops Elementor\'s CSS on pages that have no Elementor widgets.', 'uichemy' ),
				),
				array(
					'name'  => 'elementor-fonts',
					'key'   => 'perf_elementor_fonts',
					'group' => 'elementor',
					'tag'   => __( 'Fonts', 'uichemy' ),
					'label' => __( 'Disable Elementor Google Fonts', 'uichemy' ),
					'desc'  => __( 'Stops Elementor loading Google Fonts.', 'uichemy' ),
					// This switch IS Elementor's setting (single source of truth): on = Disable.
					'sync'  => array_merge( self::SYNC['perf_elementor_fonts'], array( 'where' => __( 'Elementor > Settings > Advanced > Google Fonts', 'uichemy' ) ) ),
				),
				array(
					'name'      => 'elementor-font-display',
					// Only meaningful while Elementor loads Google Fonts: the dashboard hides
					// this row when "Disable Elementor Google Fonts" is on.
					'hide_when' => array( 'perf_elementor_fonts' => 1 ),
					'key'       => 'perf_elementor_font_display',
					'group'     => 'elementor',
					'tag'       => __( 'Fonts', 'uichemy' ),
					'type'      => 'select',
					'label'     => __( 'Google Fonts load', 'uichemy' ),
					'desc'      => __( 'How text shows while a font loads. Swap is recommended.', 'uichemy' ),
					'choices'   => array(
						array( 'value' => 'auto', 'label' => __( 'Default', 'uichemy' ) ),
						array( 'value' => 'block', 'label' => __( 'Blocking', 'uichemy' ) ),
						array( 'value' => 'swap', 'label' => __( 'Swap', 'uichemy' ) ),
						array( 'value' => 'fallback', 'label' => __( 'Fallback', 'uichemy' ) ),
						array( 'value' => 'optional', 'label' => __( 'Optional', 'uichemy' ) ),
					),
					'sync'      => array_merge( self::SYNC['perf_elementor_font_display'], array( 'where' => __( 'Elementor > Settings > Advanced > Google Fonts Load', 'uichemy' ) ) ),
				),
				array(
					'name'  => 'elementor-icons',
					'key'   => 'perf_elementor_icons',
					'group' => 'elementor',
					'tag'   => __( 'Icons', 'uichemy' ),
					'label' => __( 'Elementor icons (eicons)', 'uichemy' ),
					'desc'  => __( 'Stops the eicons font, unless a section uses an eicon.', 'uichemy' ),
				),
				array(
					'name'  => 'font-awesome',
					'key'   => 'perf_font_awesome',
					'group' => 'elementor',
					'tag'   => __( 'Icons', 'uichemy' ),
					'label' => __( 'Skip Font Awesome 4 support', 'uichemy' ),
					'desc'  => __( 'Stops Elementor\'s Font Awesome files, unless a section uses them.', 'uichemy' ),
					// This switch IS Elementor's setting (single source of truth): on = No.
					'sync'  => array_merge( self::SYNC['perf_font_awesome'], array( 'where' => __( 'Elementor > Settings > Advanced > Load Font Awesome 4 Support', 'uichemy' ) ) ),
				),
				array(
					'name'  => 'elementor-assets',
					'key'   => 'perf_elementor_assets',
					'group' => 'elementor',
					'tag'   => __( 'Assets', 'uichemy' ),
					'label' => __( 'Other Elementor assets', 'uichemy' ),
					'desc'  => __( 'Stops Elementor\'s slider, animation, lightbox and gallery CSS.', 'uichemy' ),
				),
			);
			return self::$registry_cache;
		}

		/**
		 * @var array|null
		 */
		private static $registry_cache = null;

		/**
		 * UIDs of sections whose critical CSS was already printed in <head>.
		 *
		 * @var array<string,bool>
		 */
		private static $printed_uids = array();

		/**
		 * Cache of is_uichemy_page check per request.
		 *
		 * @var bool|null
		 */
		private static $is_uichemy_page_cache = null;

		/**
		 * Role of each critical section UID: 'header' | 'hero' | 'below'.
		 *
		 * @var array<string,string>
		 */
		private static $roles = array();

		/**
		 * Whether <head> output is currently being buffered.
		 *
		 * @var bool
		 */
		private static $buffering = false;

		/**
		 * Whether third-party head libraries were switched to defer on this request.
		 * Widget JS then waits for DOMContentLoaded so it still runs after them.
		 *
		 * @var bool
		 */
		private static $libs_deferred = false;

		/**
		 * Number of images already left eager in header/hero sections.
		 *
		 * @var int
		 */
		private static $eager_images = 0;

		/**
		 * Cached result of composer_only_page().
		 *
		 * @var bool|null
		 */
		private static $composer_only = null;

		/**
		 * Authored code of the page (widget data + site custom code), for asset checks.
		 *
		 * @var string
		 */
		private static $page_blob = '';

		/**
		 * Widget data only (no site-level code), for the Elementor-class guard.
		 *
		 * @var string
		 */
		private static $tree_blob = '';

		/**
		 * Whether the page's own code needs jQuery: it mentions jQuery, or it loads a
		 * script by URL that may need it. Only jQuery is kept in that case.
		 *
		 * @var bool
		 */
		private static $needs_jquery = false;

		/**
		 * Whether the page's own code needs Elementor's front-end scripts: it mentions
		 * elementorFrontend or a script that only Elementor provides. Those scripts need
		 * jQuery too, so this keeps both.
		 *
		 * @var bool
		 */
		private static $needs_elementor_js = false;

		/**
		 * Whether a rendered Composer section printed an eicon class.
		 *
		 * @var bool
		 */
		private static $eicons_used = false;

		/**
		 * Whether a rendered section printed a Font Awesome class, and the Font Awesome
		 * handles dropped before that was known (re-queued late when it turns out used).
		 *
		 * @var bool
		 */
		private static $fa_used = false;

		/**
		 * @var string[]
		 */
		private static $fa_dropped = array();

		/**
		 * Section UIDs whose raw CSS / JS was printed in <head> because the section's
		 * placement is 'Before </head>' (raw_css_placement / raw_js_placement = 'head').
		 * Placement values: '' (default), 'head' (Before </head>), 'body' (Before </body>).
		 *
		 * @var array<string,bool>
		 */
		private static $head_css_uids = array();
		private static $head_js_uids  = array();

		/**
		 * CSS / JS of sections placed 'Before </body>', printed just before </body>.
		 *
		 * @var array<int,array{kind:string,uid:string,code:string}>
		 */
		private static $body_queue = array();

		/**
		 * Whether the end-of-body printer already ran this request.
		 *
		 * @var bool
		 */
		private static $body_printed = false;

		/**
		 * Sections loaded per post this request.
		 *
		 * @var array<int,array|null>
		 */
		private static $sections_cache = array();

		/**
		 * Hook into WordPress lifecycle.
		 *
		 * @return void
		 */
		public static function init() {
			// Hook default scripts early to remove jquery-migrate from jquery's deps.
			add_action( 'wp_default_scripts', array( __CLASS__, 'on_default_scripts' ) );

			// Dequeue and deregister unused jQuery scripts on front end late in the enqueue pipeline.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'on_enqueue_scripts' ), 999 );

			// Elementor runtime/CSS that a Composer-only page never uses. Runs again right
			// before footer scripts print, because Elementor enqueues some handles late.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_unused_assets' ), 1000 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'drop_unused_assets' ), 1 );
			// Elementor enqueues its frontend/post CSS in wp_head itself, just before
			// wp_print_styles (priority 8); catch it there.
			add_action( 'wp_head', array( __CLASS__, 'drop_unused_assets' ), 7 );
			// Elementor's Google-font <link>s are enqueued inside wp_print_styles itself.
			add_action( 'wp_print_styles', array( __CLASS__, 'drop_unused_assets' ), 999 );

			// Google Fonts load defaults to Swap. Elementor reads its own option with an 'auto'
			// fallback, so supply 'swap' there when the site never saved one. A saved value
			// (any choice, including Default) always wins, and nothing changes while the
			// master switch is off.
			add_filter( 'default_option_elementor_font_display', array( __CLASS__, 'default_font_display' ), 10, 1 );

			// Print critical header & hero CSS inside <head>.
			add_action( 'wp_head', array( __CLASS__, 'print_critical_header_hero_css' ), 5 );

			// Per-section CSS / JS placement. Independent of the Fast Load switch: it is
			// the author's explicit choice for that section.
			add_action( 'wp_head', array( __CLASS__, 'print_head_placed_code' ), 6 );
			add_action( 'wp_footer', array( __CLASS__, 'print_body_placed_code' ), 9999 );

			// Buffer everything printed in <head> so Google Fonts stylesheets can be made
			// non-blocking and third-party library <script> tags deferred.
			add_action( 'wp_head', array( __CLASS__, 'start_head_buffer' ), 0 );
			add_action( 'wp_head', array( __CLASS__, 'end_head_buffer' ), 9999 );
		}

		/**
		 * Default for Elementor's "Google Fonts Load" (font-display) when the site never
		 * saved one: Swap, so text shows in the fallback font while a web font loads.
		 *
		 * @param mixed $default Elementor's own default ('auto').
		 * @return mixed
		 */
		public static function default_font_display( $default ) {
			return self::is_enabled() ? self::SYNC['perf_elementor_font_display']['default'] : $default;
		}

		/**
		 * Setting name (as used by the MCP tools) => option key.
		 *
		 * @return array<string,string> The master switch plus every registry entry.
		 */
		public static function setting_names() {
			$map = array( 'performance-optimization' => 'enable_fast_load' );
			foreach ( self::registry() as $row ) {
				if ( self::is_toggle( $row ) ) {
					$map[ $row['name'] ] = $row['key'];
				}
			}
			return $map;
		}

		/**
		 * Option keys of every on/off registry entry (not the master switch).
		 *
		 * @return string[]
		 */
		public static function option_keys() {
			$keys = array();
			foreach ( self::registry() as $row ) {
				if ( self::is_toggle( $row ) ) {
					$keys[] = $row['key'];
				}
			}
			return $keys;
		}

		/**
		 * Default value of every on/off registry entry, by option key. Most start on (1);
		 * an entry opts out with 'default' => 0.
		 *
		 * @return array<string,int>
		 */
		public static function option_defaults() {
			$defaults = array();
			foreach ( self::registry() as $row ) {
				if ( self::is_toggle( $row ) ) {
					$defaults[ $row['key'] ] = isset( $row['default'] ) ? (int) $row['default'] : 1;
				}
			}
			return $defaults;
		}

		/**
		 * Whether a registry entry is an on/off switch (the default) rather than a choice.
		 *
		 * @param array $row Registry entry.
		 * @return bool
		 */
		private static function is_toggle( $row ) {
			return empty( $row['type'] ) || 'toggle' === $row['type'];
		}

		/**
		 * Live value of every setting that is Elementor's own option, keyed by
		 * registry key: 1 / 0 for switches, the string for choices.
		 *
		 * @return array<string,int|string>
		 */
		public static function synced_values() {
			$out = array();
			foreach ( self::SYNC as $key => $sync ) {
				$value = (string) get_option( $sync['option'], $sync['default'] );
				$out[ $key ] = isset( $sync['on'] ) ? ( $value === (string) $sync['on'] ? 1 : 0 ) : $value;
			}
			return $out;
		}

		/**
		 * Overlay the live Elementor-backed values onto a settings array, so the
		 * dashboard always shows what Elementor actually has.
		 *
		 * @param array $opts Settings.
		 * @return array
		 */
		public static function with_synced( $opts ) {
			return array_merge( (array) $opts, self::synced_values() );
		}

		/**
		 * Write the Elementor-backed settings found in $input to Elementor's own
		 * options. Only keys that are present are touched, and an option is only
		 * written when its value actually changes.
		 *
		 * @param array $input Keys from the dashboard save or an MCP call (registry key => value).
		 * @return void
		 */
		public static function save_synced( $input ) {
			$input = (array) $input;
			foreach ( self::SYNC as $key => $sync ) {
				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}
				if ( isset( $sync['on'] ) ) {
					$new = ! empty( $input[ $key ] ) ? (string) $sync['on'] : (string) $sync['off'];
				} else {
					$new = sanitize_key( (string) $input[ $key ] );
					if ( ! in_array( $new, $sync['values'], true ) ) {
						continue;
					}
				}
				if ( (string) get_option( $sync['option'], $sync['default'] ) !== $new ) {
					update_option( $sync['option'], $new );
				}
			}
		}

		/**
		 * Whether one optimization's own switch is on. Missing means on. This does NOT
		 * look at the master switch (see is_enabled()).
		 *
		 * @param string $key An option key (see self::registry()).
		 * @return bool
		 */
		public static function opt( $key ) {
			// A switch that is Elementor's own setting reads Elementor's option.
			if ( isset( self::SYNC[ $key ]['on'] ) ) {
				return (string) get_option( self::SYNC[ $key ]['option'], self::SYNC[ $key ]['default'] ) === (string) self::SYNC[ $key ]['on'];
			}
			$opts = get_option( 'uichemy_settings', array() );
			if ( ! is_array( $opts ) || ! array_key_exists( $key, $opts ) ) {
				// Never saved: the registry default (on, unless the entry says otherwise).
				$defaults = self::option_defaults();
				return ! isset( $defaults[ $key ] ) || ! empty( $defaults[ $key ] );
			}
			return ! empty( $opts[ $key ] );
		}

		/**
		 * Check whether the fast load optimization switch is enabled in settings.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			$opts = get_option( 'uichemy_settings', array() );
			// On by default: only an explicit saved 0 turns it off.
			$on = ! is_array( $opts ) || ! array_key_exists( 'enable_fast_load', $opts ) || ! empty( $opts['enable_fast_load'] );

			/**
			 * Filter whether fast load optimization is enabled.
			 *
			 * @param bool $on
			 */
			return (bool) apply_filters( 'uichemy/perf/fast_load_enabled', $on );
		}

		/**
		 * MCP: every performance setting with its current state.
		 *
		 * Includes the master switch and each on/off registry entry, with the label,
		 * group and description a client needs to explain it. The choice setting
		 * (Google Fonts load) is a dashboard control and is not listed here.
		 *
		 * @return array{active:bool,settings:array<int,array>}
		 */
		public static function mcp_list() {
			$rows = array(
				array(
					'setting'     => 'performance-optimization',
					'label'       => __( 'Performance Optimization', 'uichemy' ),
					'group'       => 'master',
					'enabled'     => self::opt( 'enable_fast_load' ),
					'description' => __( 'Master switch for every optimization below.', 'uichemy' ),
				),
			);
			foreach ( self::registry() as $row ) {
				if ( ! self::is_toggle( $row ) ) {
					continue;
				}
				$item = array(
					'setting'     => $row['name'],
					'label'       => $row['label'],
					'group'       => $row['group'],
					'enabled'     => self::opt( $row['key'] ),
					'description' => $row['desc'],
				);
				if ( ! empty( $row['sync'] ) ) {
					$item['synced_with'] = $row['sync']['where'];
				}
				$rows[] = $item;
			}
			return array(
				'active'   => self::is_enabled(),
				'settings' => $rows,
			);
		}

		/**
		 * MCP: turn one performance setting on or off.
		 *
		 * @param string $setting A key of self::setting_names().
		 * @param bool   $enabled New state.
		 * @return array|WP_Error The setting's new state.
		 */
		public static function mcp_toggle( $setting, $enabled ) {
			$setting = sanitize_key( str_replace( '_', '-', (string) $setting ) );
			$names   = self::setting_names();
			if ( ! isset( $names[ $setting ] ) ) {
				return new WP_Error( 'uich_unknown_setting', sprintf( 'Unknown performance setting "%s". Valid: %s.', $setting, implode( ', ', array_keys( $names ) ) ) );
			}
			$key = $names[ $setting ];
			if ( isset( self::SYNC[ $key ] ) ) {
				// This switch is an Elementor setting: change Elementor's option.
				self::save_synced( array( $key => $enabled ? 1 : 0 ) );
			} else {
				$opts = get_option( 'uichemy_settings', array() );
				$opts = is_array( $opts ) ? $opts : array();

				$opts[ $key ] = $enabled ? 1 : 0;
				update_option( 'uichemy_settings', $opts );
			}

			$out = array(
				'setting' => $setting,
				'enabled' => self::opt( $key ),
				'active'  => self::is_enabled(),
			);
			if ( 'enable_fast_load' !== $key && ! self::is_enabled() ) {
				$out['note'] = 'Saved, but performance-optimization is off, so nothing runs until it is enabled.';
			}
			return $out;
		}

		/**
		 * Check whether the current request is an admin or editor context.
		 *
		 * jQuery Migrate and jQuery UI must NEVER be disabled in admin or editor
		 * mode so builders (Elementor, Gutenberg, Bricks) and admin screens remain fully functional.
		 *
		 * @return bool
		 */
		public static function is_admin_or_editor() {
			// 1. WordPress admin area
			if ( is_admin() ) {
				return true;
			}

			// 2-4. Elementor editor/preview, Bricks builder, Customizer preview
			if ( self::in_builder_context() ) {
				return true;
			}

			// 5. User capabilities (Administrator or Editor user roles)
			if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Whether this request is a page-builder editor, preview or the Customizer.
		 * (Capability is not considered here: a logged-in admin viewing the live page
		 * is not in a builder context.)
		 *
		 * @return bool
		 */
		public static function in_builder_context() {
			// Elementor editor or preview iframe
			if ( class_exists( '\Elementor\Plugin' ) ) {
				if ( isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
					return true;
				}
				if ( isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
					return true;
				}
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['elementor-preview'] ) || ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) ) {
				return true;
			}

			// Bricks builder
			if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
				return true;
			}
			if ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() ) {
				return true;
			}
			if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
				return true;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			// Customizer preview
			if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
				return true;
			}

			return false;
		}

		/**
		 * Check whether the queried post/page was developed through UiChemy / Composer.
		 *
		 * @param int $post_id Optional post ID.
		 * @return bool
		 */
		public static function is_uichemy_page( $post_id = 0 ) {
			if ( null !== self::$is_uichemy_page_cache && 0 === $post_id ) {
				return self::$is_uichemy_page_cache;
			}

			if ( ! $post_id ) {
				if ( is_singular() ) {
					$post_id = get_queried_object_id();
				} elseif ( is_front_page() || is_home() ) {
					$post_id = (int) get_option( 'page_on_front' );
					if ( ! $post_id ) {
						$post_id = get_queried_object_id();
					}
				}
			}

			if ( ! $post_id ) {
				return false;
			}

			$post_id = (int) $post_id;

			// 1. UiChemy template CPT (Theme Builder)
			if ( 'uichemy_template' === get_post_type( $post_id ) ) {
				if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
					self::$is_uichemy_page_cache = true;
				}
				return true;
			}

			// 2. Webpage import marker meta
			if ( '' !== (string) get_post_meta( $post_id, '_uich_webpage_source', true ) ) {
				if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
					self::$is_uichemy_page_cache = true;
				}
				return true;
			}

			// 3. Page uses active UiChemy Theme Builder template (header, footer, or single)
			if ( class_exists( 'UiChemy_Template_Resolver' ) ) {
				if ( UiChemy_Template_Resolver::resolve( 'header' ) || UiChemy_Template_Resolver::resolve( 'footer' ) || UiChemy_Template_Resolver::resolve( 'single' ) ) {
					if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
						self::$is_uichemy_page_cache = true;
					}
					return true;
				}
			}

			// 4. Elementor data contains UiChemy Composer widget types
			$elem_data = get_post_meta( $post_id, '_elementor_data', true );
			if ( is_string( $elem_data ) && '' !== $elem_data ) {
				$needles = function_exists( 'uichemy_composer_widget_json_needles' )
					? uichemy_composer_widget_json_needles()
					: array( '"widgetType":"uichemy-composer"', '"widgetType":"composer"', '"widgetType":"proton"', '"widgetType":"uichemy-builder"' );

				foreach ( $needles as $needle ) {
					if ( false !== strpos( $elem_data, $needle ) ) {
						if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
							self::$is_uichemy_page_cache = true;
						}
						return true;
					}
				}
			}

			// 5. Gutenberg post content contains UiChemy Composer blocks
			$post = get_post( $post_id );
			if ( $post && ! empty( $post->post_content ) ) {
				if ( false !== strpos( $post->post_content, 'wp:uichemy' )
					|| ( function_exists( 'has_block' ) && ( has_block( 'uichemy/composer', $post ) || has_block( 'uichemy-composer', $post ) ) ) ) {
					if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
						self::$is_uichemy_page_cache = true;
					}
					return true;
				}
			}

			// 6. Bricks content meta contains UiChemy Composer element
			$bricks_content = get_post_meta( $post_id, '_bricks_page_content_2', true );
			if ( is_array( $bricks_content ) ) {
				foreach ( $bricks_content as $el ) {
					if ( is_array( $el ) && isset( $el['name'] ) && ( 'uichemy-composer' === $el['name'] || 'uichemy_composer' === $el['name'] ) ) {
						if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
							self::$is_uichemy_page_cache = true;
						}
						return true;
					}
				}
			}

			if ( 0 === $post_id || get_queried_object_id() === $post_id ) {
				self::$is_uichemy_page_cache = false;
			}

			return false;
		}

		/**
		 * Whether the current request qualifies for fast load optimization:
		 *   - Feature is enabled in settings
		 *   - Request is NOT from an admin or editor
		 *   - Page is developed with UiChemy / Composer
		 *
		 * @return bool
		 */
		public static function should_optimize() {
			if ( ! self::is_enabled() ) {
				return false;
			}
			if ( self::is_admin_or_editor() ) {
				return false;
			}
			if ( ! self::is_uichemy_page() ) {
				return false;
			}
			return true;
		}

		/**
		 * Remove jquery-migrate from the default jquery script dependencies.
		 *
		 * @param WP_Scripts $scripts
		 * @return void
		 */
		public static function on_default_scripts( $scripts ) {
			if ( ! self::should_optimize() || ! self::opt( 'perf_remove_js' ) ) {
				return;
			}

			if ( ! empty( $scripts->registered['jquery'] ) && ! empty( $scripts->registered['jquery']->deps ) ) {
				$scripts->registered['jquery']->deps = array_values(
					array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) )
				);
			}
		}

		/**
		 * Dequeue and deregister jquery-migrate.min.js and core.min.js (jQuery UI core) on front-end.
		 *
		 * @return void
		 */
		public static function on_enqueue_scripts() {
			if ( ! self::should_optimize() || ! self::opt( 'perf_remove_js' ) ) {
				return;
			}

			// Dequeue and deregister jquery-migrate
			wp_dequeue_script( 'jquery-migrate' );
			wp_deregister_script( 'jquery-migrate' );

			$wp_scripts = wp_scripts();
			if ( isset( $wp_scripts->registered['jquery'] ) && ! empty( $wp_scripts->registered['jquery']->deps ) ) {
				$wp_scripts->registered['jquery']->deps = array_values(
					array_diff( $wp_scripts->registered['jquery']->deps, array( 'jquery-migrate' ) )
				);
			}

			// Dequeue and deregister jQuery UI Core (core.min.js)
			wp_dequeue_script( 'jquery-ui-core' );
			wp_deregister_script( 'jquery-ui-core' );
		}

		/**
		 * True when every widget on the page (and in its resolved header/footer/single
		 * templates) is a Composer widget whose code never touches jQuery or Elementor's
		 * front-end JS. Only then are Elementor's runtime and jQuery dropped.
		 *
		 * @return bool
		 */
		private static function composer_only_page() {
			if ( null !== self::$composer_only ) {
				return self::$composer_only;
			}
			self::$composer_only = false;

			$ids = array( (int) get_queried_object_id() );
			if ( class_exists( 'UiChemy_Template_Resolver' ) ) {
				foreach ( array( 'header', 'footer', 'single' ) as $loc ) {
					$tid = (int) UiChemy_Template_Resolver::resolve( $loc );
					if ( $tid ) {
						$ids[] = $tid;
					}
				}
			}
			$ids = array_filter( array_unique( $ids ) );
			if ( ! $ids || ! function_exists( 'uichemy_is_composer_widget_node' ) ) {
				return false;
			}

			$blob    = '';
			$widgets = 0;
			foreach ( $ids as $id ) {
				$raw  = get_post_meta( $id, '_elementor_data', true );
				$tree = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
				if ( ! is_array( $tree ) ) {
					return false; // Not an Elementor tree (e.g. builder template): unknown, keep everything.
				}
				if ( ! self::tree_is_composer_only( $tree, $widgets ) ) {
					return false;
				}
				$blob .= $raw;
			}

			self::$tree_blob = $blob;
			if ( class_exists( 'UiChemy_Composer_Manager' ) && method_exists( 'UiChemy_Composer_Manager', 'get_site_custom_code_option' ) ) {
				$site  = UiChemy_Composer_Manager::get_site_custom_code_option();
				$blob .= ( $site['head'] ?? '' ) . ( $site['footer'] ?? '' );
			}

			if ( $widgets < 1 ) {
				return false;
			}

			self::$page_blob = $blob;

			// Composer code that uses jQuery must keep working, so jQuery stays. That covers
			// code that mentions it, and scripts loaded by URL, which are not read here and
			// may need jQuery themselves. Elementor's own scripts are a separate question:
			// they stay only when the code actually uses Elementor's front-end API.
			self::$needs_jquery        = (bool) preg_match( '/jQuery|\$\(|\$\./i', $blob ) || self::scripts_may_need_jquery( $blob );
			self::$needs_elementor_js = (bool) preg_match( '/elementorFrontend|elementor-frontend|elementorProFrontend|waypoint/i', $blob );

			self::$composer_only = (bool) apply_filters( 'uichemy/perf/drop_elementor_assets', true );
			return self::$composer_only;
		}

		/**
		 * Does the authored code load a script by URL that may need jQuery?
		 *
		 * Finds every script URL: `<script src>` tags in section HTML and custom code,
		 * and the URLs in a section's assets list. A script is assumed to need jQuery
		 * unless it is a known library that does not (GSAP, Lenis, Swiper, analytics and
		 * so on). The assumption costs a little (jQuery stays on a page that did not need
		 * it); the opposite mistake breaks the page.
		 *
		 * @param string $blob Section data and custom code.
		 * @return bool
		 */
		private static function scripts_may_need_jquery( $blob ) {
			// Section data is JSON inside JSON: undo the escaping so URLs read normally.
			$text = str_replace( '\\/', '/', stripslashes( stripslashes( (string) $blob ) ) );

			$urls = array();
			if ( preg_match_all( '#<script\b[^>]*?\bsrc\s*=\s*["\']?([^"\'\s>]+)#i', $text, $m ) ) {
				$urls = array_merge( $urls, $m[1] );
			}
			// `.js` must end the file name (not sit inside a host such as cdn.jsdelivr.net).
			if ( preg_match_all( '#https?://[^\s"\'<>\\)]+?\.js(?=$|[?\#\s"\'<>\\)])(?:\?[^\s"\'<>\\)]*)?#i', $text, $m ) ) {
				$urls = array_merge( $urls, $m[0] );
			}

			$free = '#(gsap|scrolltrigger|motionpath|drawsvg|splittext|lenis|split-?type|anime|three|lottie|swiper|alpine|aos|googletagmanager|google-analytics|gtag|fbevents|hotjar|clarity|iframe_api|vimeo|recaptcha|turnstile|stripe)#i';
			foreach ( array_unique( $urls ) as $url ) {
				$url = html_entity_decode( (string) $url );
				$ok  = (bool) preg_match( $free, wp_parse_url( $url, PHP_URL_HOST ) . ' ' . wp_parse_url( $url, PHP_URL_PATH ) );

				/**
				 * Filter whether a script URL is known not to need jQuery.
				 *
				 * @param bool   $ok  True when the script is known not to need jQuery.
				 * @param string $url The script URL.
				 */
				if ( ! apply_filters( 'uichemy/perf/jquery_free_script', $ok, $url ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether an Elementor tree holds only Composer widgets.
		 *
		 * @param array $nodes   Tree.
		 * @param int   $widgets Running widget count (by reference).
		 * @return bool
		 */
		private static function tree_is_composer_only( array $nodes, &$widgets ) {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( isset( $node['elType'] ) && 'widget' === $node['elType'] ) {
					if ( ! uichemy_is_composer_widget_node( $node ) ) {
						return false;
					}
					$widgets++;
				}
				if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) && ! self::tree_is_composer_only( $node['elements'], $widgets ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Dequeue Elementor's front-end runtime, jQuery, eicons and core block CSS when
		 * the page provably does not use them (Elementor's runtime needs jQuery, so
		 * those two go together).
		 *
		 * @return void
		 */
		public static function drop_unused_assets() {
			if ( ! self::should_optimize() || ! self::composer_only_page() ) {
				return;
			}

			$drop_js  = self::opt( 'perf_remove_js' );
			$drop_css = self::opt( 'perf_elementor_css' );

			if ( $drop_js ) {
				self::drop_unused_scripts();
			}
			if ( $drop_css ) {
				self::drop_unused_styles();
			}
		}

		/**
		 * Dequeue Elementor's front-end runtime and jQuery (they go together).
		 *
		 * @return void
		 */
		private static function drop_unused_scripts() {
			$scripts = wp_scripts();

			// Elementor's front-end scripts, unless the page's code uses Elementor's API.
			if ( ! self::$needs_elementor_js ) {
				foreach ( (array) $scripts->queue as $handle ) {
					if ( 0 === strpos( $handle, 'elementor' ) || 'swiper' === $handle || 'share-link' === $handle ) {
						wp_dequeue_script( $handle );
					}
				}
			}

			// jQuery goes only when the page's code does not need it (and Elementor's
			// scripts, which need it, are gone) and nothing still queued depends on it.
			$needed = self::$needs_jquery || self::$needs_elementor_js;
			if ( self::$needs_jquery ) {
				// jQuery is normally only on the page because Elementor's scripts depend on it.
				// With those gone, nothing would load it, so load it for the page's own code.
				wp_enqueue_script( 'jquery' );
			}
			if ( ! $needed ) {
				foreach ( (array) $scripts->queue as $handle ) {
					if ( self::script_needs_jquery( $handle, $scripts ) ) {
						$needed = true;
						break;
					}
				}
			}
			if ( ! $needed ) {
				foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $h ) {
					wp_dequeue_script( $h );
					wp_deregister_script( $h );
				}
			}
		}

		/**
		 * Dequeue Elementor's stylesheets, fonts, eicons and core block CSS.
		 *
		 * @return void
		 */
		private static function drop_unused_styles() {
			// Elementor's own stylesheets on every breakpoint. Composer sections carry
			// their own scoped CSS; measured on desktop and 375px mobile, removing these
			// changes no style inside the widgets and only drops Elementor's widget
			// spacing between sections, even when Elementor containers wrap the widgets.
			//
			// Each handle belongs to one group, and each group has its own switch (see
			// registry()): css, fonts, icons, font_awesome, assets.
			// Exceptions: the kit CSS stays when authored code reads Elementor's global
			// variables (--e-global-*); eicons and Font Awesome come back (late, in the
			// footer) when a rendered section actually prints one of their classes.
			$on = array(
				'css'          => self::opt( 'perf_elementor_css' ),
				'fonts'        => self::opt( 'perf_elementor_fonts' ),
				'icons'        => self::opt( 'perf_elementor_icons' ),
				'font_awesome' => self::opt( 'perf_font_awesome' ),
				'assets'       => self::opt( 'perf_elementor_assets' ),
			);

			$kit_handle = 'elementor-post-' . (int) get_option( 'elementor_active_kit' );
			$uses_kit   = (bool) preg_match( '/--e-global|var\(\s*--e-/', self::$page_blob );

			foreach ( (array) wp_styles()->queue as $handle ) {
				$group = self::style_group( $handle );
				if ( '' === $group || empty( $on[ $group ] ) ) {
					continue;
				}
				if ( ( 'icons' === $group && self::$eicons_used ) || ( 'font_awesome' === $group && self::$fa_used ) || ( $uses_kit && $kit_handle === $handle ) ) {
					continue;
				}
				if ( 'font_awesome' === $group ) {
					self::$fa_dropped[] = $handle;
					// The FA4 shim registers a script under the same handle.
					wp_dequeue_script( $handle );
				}
				wp_dequeue_style( $handle );
			}

			// Core block-editor CSS is dead weight on a page with no blocks. It is part of
			// the Elementor CSS switch (it is what that setting has always removed).
			if ( $on['css'] && ! has_blocks() ) {
				wp_dequeue_style( 'wp-block-library' );
				wp_dequeue_style( 'wp-block-library-theme' );
				wp_dequeue_style( 'classic-theme-styles' );
			}
		}

		/**
		 * Which Elementor group a stylesheet handle belongs to.
		 *
		 * @param string $handle Style handle.
		 * @return string css | fonts | icons | font_awesome | assets, or '' when it is not an Elementor asset.
		 */
		private static function style_group( $handle ) {
			$handle = (string) $handle;
			if ( 0 === strpos( $handle, 'elementor-gf-' ) ) {
				return 'fonts';
			}
			if ( preg_match( '/^(font-awesome|fontawesome|elementor-icons-fa-|elementor-icons-shared-)/', $handle ) ) {
				return 'font_awesome';
			}
			if ( 'elementor-icons' === $handle ) {
				return 'icons';
			}
			if ( preg_match( '/^(swiper$|e-swiper|e-animation-|e-shapes$|e-lightbox$|e-apple-webkit$|elementor-gallery$)/', $handle ) ) {
				return 'assets';
			}
			if ( preg_match( '/^(elementor-|widget-|base-desktop$|base-mobile$)/', $handle ) ) {
				return 'css';
			}
			return '';
		}

		/**
		 * Does a script, or anything it depends on, need jQuery?
		 *
		 * @param string     $handle  Script handle.
		 * @param WP_Scripts $scripts Registry.
		 * @param array      $seen    Visited handles.
		 * @return bool
		 */
		private static function script_needs_jquery( $handle, $scripts, array &$seen = array() ) {
			if ( isset( $seen[ $handle ] ) || empty( $scripts->registered[ $handle ] ) ) {
				return false;
			}
			$seen[ $handle ] = true;
			foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
				if ( 'jquery' === $dep || 'jquery-core' === $dep || 'jquery-migrate' === $dep || self::script_needs_jquery( $dep, $scripts, $seen ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Start buffering <head> output.
		 *
		 * @return void
		 */
		public static function start_head_buffer() {
			if ( ! self::should_optimize() ) {
				return;
			}
			self::$buffering = true;
			ob_start();
		}

		/**
		 * Stop buffering <head> output and print it with the optimizations applied.
		 *
		 * @return void
		 */
		public static function end_head_buffer() {
			if ( ! self::$buffering ) {
				return;
			}
			self::$buffering = false;
			$html            = ob_get_clean();
			if ( false === $html ) {
				return;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Re-printing markup already printed by wp_head callbacks, with only loading attributes added.
			echo self::optimize_head_html( $html );
		}

		/**
		 * Make Google Fonts stylesheets non-blocking and defer third-party scripts.
		 *
		 * @param string $html Markup printed by wp_head.
		 * @return string
		 */
		public static function optimize_head_html( $html ) {
			$html = (string) $html;

			// 1. Google Fonts: swap in once loaded instead of blocking first paint.
			//    Every font URL we touch already uses display=swap (or Elementor's own
			//    font-display), so text paints in the fallback font meanwhile.
			/**
			 * Filter whether Google Fonts stylesheets load without blocking render.
			 * Off by default: in testing the font swap moved the section below the hero
			 * (CLS 0.025 -> 0.155) and cost more than the blocking request saved.
			 *
			 * @param bool $async Default false.
			 */
			$async_fonts = (bool) apply_filters( 'uichemy/perf/async_google_fonts', false );
			$html        = ! $async_fonts ? $html : preg_replace_callback(
				'#<link\b[^>]*>#i',
				static function ( $m ) {
					$tag = $m[0];
					if ( ! preg_match( '#\brel=([\'"])stylesheet\1#i', $tag )
						|| ! preg_match( '#\bhref=([\'"])https?://fonts\.googleapis\.com/#i', $tag )
						|| false !== stripos( $tag, 'onload=' ) ) {
						return $tag;
					}
					$async = preg_replace( '#\brel=([\'"])stylesheet\1#i', 'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $tag, 1 );
					return $async . '<noscript>' . $tag . '</noscript>';
				},
				$html
			);

			// 2. Third-party library scripts: defer (document order is preserved).
			if ( self::opt( 'perf_defer_js' ) && self::defer_is_safe() ) {
				$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
				$count     = 0;
				$html      = preg_replace_callback(
					'#<script\b([^>]*)>#i',
					static function ( $m ) use ( $site_host, &$count ) {
						$attrs = $m[1];
						if ( ! preg_match( '#\bsrc=([\'"])(https?:)?//([^/\'"]+)#i', $attrs, $src ) ) {
							return $m[0];
						}
						$src_host = preg_replace( '#:\d+$#', '', $src[3] );
						if ( strtolower( $src_host ) === strtolower( (string) $site_host )
							|| preg_match( '#\b(defer|async|nomodule)\b#i', $attrs )
							|| preg_match( '#\btype=([\'"])module\1#i', $attrs ) ) {
							return $m[0];
						}
						$count++;
						return '<script' . $attrs . ' defer>';
					},
					$html
				);
				if ( $count > 0 ) {
					self::$libs_deferred = true;
				}
			}

			return $html;
		}

		/**
		 * Deferring a head library is only safe when nothing inline needs it
		 * before DOMContentLoaded. UiChemy's own widget JS is handled by
		 * maybe_wrap_dom_ready(); hand-written inline <script> blocks in the Composer
		 * site/page custom-code boxes are not ours to rewrite, so their presence
		 * turns the deferral off.
		 *
		 * @return bool
		 */
		private static function defer_is_safe() {
			$blob = '';
			if ( class_exists( 'UiChemy_Composer_Manager' ) && method_exists( 'UiChemy_Composer_Manager', 'get_site_custom_code_option' ) ) {
				$site = UiChemy_Composer_Manager::get_site_custom_code_option();
				$blob .= ( $site['head'] ?? '' ) . ( $site['footer'] ?? '' );
			}

			$post_id = (int) get_queried_object_id();
			if ( $post_id ) {
				$raw  = get_post_meta( $post_id, '_elementor_data', true );
				$tree = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
				if ( is_array( $tree ) ) {
					$blob .= self::collect_custom_code( $tree );
				}
			}

			$safe = true;
			if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $blob, $found, PREG_SET_ORDER ) ) {
				foreach ( $found as $sc ) {
					$is_classic = ! preg_match( '#\btype=([\'"])(?!text/javascript\1)#i', $sc[1] );
					if ( $is_classic && '' !== trim( $sc[2] ) && ! preg_match( '#\bsrc=#i', $sc[1] ) ) {
						$safe = false;
						break;
					}
				}
			}

			/**
			 * Filter whether third-party head libraries may be deferred.
			 *
			 * @param bool $safe Computed result.
			 */
			return (bool) apply_filters( 'uichemy/perf/defer_head_libraries', $safe );
		}

		/**
		 * Concatenate every *custom_code* string setting found in an Elementor tree.
		 *
		 * @param array $nodes Elementor element tree.
		 * @return string
		 */
		private static function collect_custom_code( array $nodes ) {
			$out = '';
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( ! empty( $node['settings'] ) && is_array( $node['settings'] ) ) {
					foreach ( $node['settings'] as $key => $val ) {
						if ( is_string( $val ) && false !== strpos( (string) $key, 'custom_code' ) ) {
							$out .= $val;
						}
					}
				}
				if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$out .= self::collect_custom_code( $node['elements'] );
				}
			}
			return $out;
		}

		/**
		 * Wrap widget JS so it runs on DOMContentLoaded when head libraries were
		 * deferred; otherwise return it untouched.
		 *
		 * @param string $js Widget JS.
		 * @return string
		 */
		public static function maybe_wrap_dom_ready( $js ) {
			if ( ! self::$libs_deferred ) {
				return $js;
			}
			return "(function(){function r(){\n" . $js . "\n}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',r);}else{r();}})();";
		}

		/**
		 * Note what a rendered Composer section needs from Elementor. Currently: if it
		 * prints an eicon class, re-queue eicons (WordPress prints a style enqueued
		 * during the body in the footer) and stop drop_unused_assets() removing it.
		 *
		 * @param string $html Rendered section HTML.
		 * @return void
		 */
		public static function note_rendered_markup( $html ) {
			$html = (string) $html;
			if ( ! self::$eicons_used && false !== stripos( $html, 'eicon-' )
				&& preg_match( '#\sclass\s*=\s*([\'"])[^\'"]*\beicon-[a-z]#i', $html ) ) {
				self::$eicons_used = true;
				if ( self::should_optimize() && wp_style_is( 'elementor-icons', 'registered' ) ) {
					wp_enqueue_style( 'elementor-icons' );
				}
			}
			if ( ! self::$fa_used && false !== stripos( $html, 'fa' )
				&& preg_match( '#\sclass\s*=\s*([\'"])[^\'"]*(?:\bfa-[a-z0-9]|\bfa[srlbdt]?\b)#i', $html ) ) {
				self::$fa_used = true;
				foreach ( self::$fa_dropped as $handle ) {
					if ( wp_style_is( $handle, 'registered' ) ) {
						wp_enqueue_style( $handle );
					}
				}
			}
		}

		/**
		 * Number of Composer sections rendered so far on this request (front end).
		 *
		 * @var int
		 */
		private static $sections_seen = 0;

		/**
		 * Set loading="lazy" on Composer <img> tags that have no loading attribute.
		 *
		 * Runs on every front-end Composer render (not the editor), independent of the
		 * Fast Load toggle, because markup authored in the Composer HTML box rarely
		 * carries the attribute and WordPress's own lazy-loading pass does not reach
		 * it. Which images stay eager:
		 *   - Fast Load on: a budget of 4 across the header and hero sections.
		 *   - Fast Load off (or not an optimizable page): every image in the first two
		 *     Composer sections, which are normally the header and hero.
		 * An image that already has a loading or fetchpriority attribute is left alone,
		 * so authors can opt a hero image out with loading="eager".
		 *
		 * @param string $html Section HTML.
		 * @param string $uid  Section/widget id.
		 * @return string
		 */
		public static function optimize_images( $html, $uid ) {
			$html = (string) $html;
			self::note_rendered_markup( $html );
			if ( ! self::is_enabled() || ! self::opt( 'perf_images' ) ) {
				return $html;
			}

			$has_img = false !== stripos( $html, '<img' );
			if ( '' === $html || ( ! $has_img && false === stripos( $html, 'preload' ) ) ) {
				return $html;
			}

			$index = $has_img ? self::$sections_seen++ : self::$sections_seen;
			// Roles exist only when the critical CSS pass ran; without it (switched off
			// or not an optimizable request) fall back to "first two sections stay eager".
			$fast  = self::should_optimize() && ! empty( self::$roles );
			$role  = $fast ? ( self::$roles[ (string) $uid ] ?? '' ) : '';

			$lazy_srcs = array();
			$html      = preg_replace_callback(
				'#<img\b[^>]*>#i',
				static function ( $m ) use ( $fast, $role, $index, &$lazy_srcs ) {
					$tag = $m[0];
					if ( preg_match( '#\s(?:loading|fetchpriority)\s*=#i', $tag ) ) {
						// Already lazy in the authored markup: its preload must still go.
						if ( preg_match( '#\sloading\s*=\s*([\'"]?)lazy\1#i', $tag ) && preg_match( '#\ssrc\s*=\s*([\'"])(.*?)\1#i', $tag, $src ) ) {
							$lazy_srcs[ html_entity_decode( $src[2] ) ] = true;
						}
						return $tag;
					}
					if ( $fast && ( 'header' === $role || 'hero' === $role ) && self::$eager_images < 4 ) {
						self::$eager_images++;
						return $tag;
					}
					if ( ! $fast && $index < 2 ) {
						return $tag;
					}
					$tag = preg_replace( '#<img\b#i', '<img loading="lazy"', $tag, 1 );
					if ( ! preg_match( '#\sdecoding\s*=#i', $tag ) ) {
						$tag = preg_replace( '#<img\b#i', '<img decoding="async"', $tag, 1 );
					}
					if ( preg_match( '#\ssrc\s*=\s*([\'"])(.*?)\1#i', $tag, $src ) ) {
						$lazy_srcs[ html_entity_decode( $src[2] ) ] = true;
					}
					return $tag;
				},
				$html
			);

			// Markup exported from React SSR ships a <link rel="preload" as="image"> for
			// every <img>. A preload fetches immediately and defeats loading="lazy".
			//   - Always: drop the preload of an image that was just made lazy.
			//   - Fast Load on, section below the hero or further down: drop every image
			//     preload — nothing there is on screen at load.
			//   - Fast Load on, hero: keep the preloads of its eager images and mark them
			//     fetchpriority="high" so the likely LCP image is fetched first.
			if ( false !== stripos( $html, 'preload' ) ) {
				$drop_all = $fast && 'header' !== $role && 'hero' !== $role;
				$boost    = $fast && 'hero' === $role;
				$html     = preg_replace_callback(
					'#<link\b[^>]*>#i',
					static function ( $m ) use ( $lazy_srcs, $drop_all, $boost ) {
						$tag = $m[0];
						if ( ! preg_match( '#\srel\s*=\s*([\'"]?)preload\1#i', $tag ) || ! preg_match( '#\sas\s*=\s*([\'"]?)image\1#i', $tag ) ) {
							return $tag;
						}
						if ( $drop_all ) {
							return '';
						}
						if ( preg_match( '#\shref\s*=\s*([\'"])(.*?)\1#i', $tag, $href ) && isset( $lazy_srcs[ html_entity_decode( $href[2] ) ] ) ) {
							return '';
						}
						if ( $boost && ! preg_match( '#\sfetchpriority\s*=#i', $tag ) ) {
							return preg_replace( '#<link\b#i', '<link fetchpriority="high"', $tag, 1 );
						}
						return $tag;
					},
					$html
				);
			}

			return $html;
		}

		/**
		 * Make entrance animations LCP-safe: in the first frame (from / 0%) of every
		 * @keyframes block, turn `opacity:0` into `opacity:.01`.
		 *
		 * Chrome never takes a paint at opacity 0 as a Largest Contentful Paint
		 * candidate. When every above-the-fold element (hero image wrapper, heading,
		 * text, buttons) fades up from 0, the page has no candidate at all and
		 * Lighthouse / PageSpeed report NO_LCP (and with it, no TBT). 1% opacity is
		 * invisible to the eye but counts as painted.
		 *
		 * Keyframe names are global, so this runs on every Composer section's CSS
		 * (head critical CSS and footer CSS alike) — otherwise a later, untouched
		 * definition of the same name would win.
		 *
		 * @param string $css Scoped CSS.
		 * @return string
		 */
		public static function lcp_safe_keyframes( $css ) {
			$css = (string) $css;
			if ( false === stripos( $css, 'keyframes' ) || ! self::is_enabled() || ! self::opt( 'perf_lcp_keyframes' ) ) {
				return $css;
			}
			$out = preg_replace_callback(
				'/(@(?:-webkit-)?keyframes\s+[^{\s]+\s*\{\s*(?:from|0%)\s*(?:,[^{]*)?\{)([^}]*)(\})/i',
				static function ( $m ) {
					return $m[1] . preg_replace( '/(\bopacity\s*:\s*)0(?![.\d])/i', '${1}.01', $m[2] ) . $m[3];
				},
				$css
			);
			return null === $out ? $css : $out;
		}

		/**
		 * Load a post's Composer sections through its builder driver (cached per request).
		 *
		 * @param int $post_id Post ID.
		 * @return array Sections, or an empty array.
		 */
		private static function load_sections_for( $post_id ) {
			$post_id = (int) $post_id;
			if ( array_key_exists( $post_id, self::$sections_cache ) ) {
				return (array) self::$sections_cache[ $post_id ];
			}
			self::$sections_cache[ $post_id ] = array();
			if ( ! class_exists( 'UiChemy_Builder_Registry' ) || ! $post_id ) {
				return array();
			}
			$builder = UiChemy_Builder_Registry::detect_post_builder( $post_id );
			$driver  = UiChemy_Builder_Registry::get( $builder );
			if ( ! $driver ) {
				return array();
			}
			$sections = $driver->load_sections( $post_id );
			if ( is_wp_error( $sections ) || ! is_array( $sections ) ) {
				return array();
			}
			self::$sections_cache[ $post_id ] = $sections;
			return $sections;
		}

		/**
		 * Was this section's own CSS already printed in <head> (critical CSS or
		 * a 'Before Head' placement)?
		 *
		 * @param string $uid Section / widget id.
		 * @return bool
		 */
		public static function is_css_in_head( $uid ) {
			$uid = (string) $uid;
			return isset( self::$printed_uids[ $uid ] ) || isset( self::$head_css_uids[ $uid ] );
		}

		/**
		 * Was this section's JS already printed in <head> ('Before Head' placement)?
		 *
		 * @param string $uid Section / widget id.
		 * @return bool
		 */
		public static function is_js_in_head( $uid ) {
			return isset( self::$head_js_uids[ (string) $uid ] );
		}

		/**
		 * Print the CSS / JS of every section whose placement is 'Before Head'.
		 *
		 * Widgets render inside the_content, after wp_head, so a widget cannot put
		 * anything in <head> itself. This reads the page's own sections (and the
		 * resolved header / footer / single templates) up front, the same way the
		 * critical CSS does, and prints the opted-in ones here. Each widget then skips
		 * its footer output for what was printed (is_css_in_head / is_js_in_head).
		 * CSS and JS have separate settings: raw_css_placement, raw_js_placement.
		 * An unset placement (CSS or JS) follows default_placement(): the starting
		 * sections print here, later ones keep the footer output.
		 *
		 * @return void
		 */
		public static function print_head_placed_code() {
			if ( is_admin() || self::in_builder_context() ) {
				return;
			}
			if ( ! function_exists( 'uichemy_custom_code_allowed' ) || ! uichemy_custom_code_allowed() ) {
				return;
			}

			$ids = array( (int) get_queried_object_id() );
			if ( class_exists( 'UiChemy_Template_Resolver' ) ) {
				foreach ( array( 'header', 'footer', 'single' ) as $loc ) {
					$ids[] = (int) UiChemy_Template_Resolver::resolve( $loc );
				}
			}

			$css_out = array();
			$js_out  = array();
			foreach ( array_unique( array_filter( $ids ) ) as $post_id ) {
				$trusted = self::author_allows_raw_code( $post_id );
				foreach ( array_values( self::load_sections_for( $post_id ) ) as $position => $sec ) {
					$uid      = (string) ( $sec['uid'] ?? '' );
					$settings = isset( $sec['settings'] ) && is_array( $sec['settings'] ) ? $sec['settings'] : array();
					if ( '' === $uid ) {
						continue;
					}

					// What the author chose, else the default for this position (starting
					// sections: head). 'body' keeps the footer output.
					$css_placement = (string) ( $settings['raw_css_placement'] ?? '' );
					if ( 'head' !== $css_placement && 'body' !== $css_placement ) {
						$css_placement = self::default_placement( 'css', $position );
					}

					$css = (string) ( $settings['raw_css'] ?? '' );
					if ( 'head' === $css_placement && '' !== trim( $css ) && ! self::is_css_in_head( $uid ) ) {
						if ( ! $trusted && class_exists( 'UiChemy_Composer_Manager' ) ) {
							$css = UiChemy_Composer_Manager::sanitize_css_block( $css );
						}
						$scoped = class_exists( 'UiChemy_Composer_Renderer' )
							? UiChemy_Composer_Renderer::scope_css_for_scope( $css, '.elementor-element-' . $uid )
							: $css;
						if ( '' !== trim( $scoped ) ) {
							self::$head_css_uids[ $uid ] = true;
							$css_out[]                   = '<style id="uich-head-css-' . esc_attr( $uid ) . '">' . "\n" . $scoped . "\n</style>";
						}
					}

					$js_placement = (string) ( $settings['raw_js_placement'] ?? '' );
					if ( 'head' !== $js_placement && 'body' !== $js_placement ) {
						$js_placement = self::default_placement( 'js', $position );
					}

					$js = (string) ( $settings['raw_js'] ?? '' );
					if ( $trusted && 'head' === $js_placement && '' !== trim( $js ) && ! self::is_js_in_head( $uid ) && class_exists( 'UiChemy_Composer_Renderer' ) ) {
						$runtime = UiChemy_Composer_Renderer::elementor_js_runtime( $js, $uid );
						if ( '' !== $runtime ) {
							self::$head_js_uids[ $uid ] = true;
							// The DOM does not exist yet in <head>: run once it does (this is
							// after the footer scripts, so bundled libraries are ready too).
							$js_out[] = '<script id="uich-head-js-' . esc_attr( $uid ) . '">(function(){function r(){' . "\n" . $runtime . "\n" . '}if(document.readyState===\'loading\'){document.addEventListener(\'DOMContentLoaded\',r);}else{r();}})();</script>';
						}
					}
				}
			}

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Author CSS/JS; emitted only under the same capability rules as the footer output (uichemy_custom_code_allowed + author unfiltered_html, CSS sanitized otherwise).
			if ( $css_out ) {
				echo "\n<!-- UiChemy: CSS placed before head -->\n" . implode( "\n", $css_out ) . "\n";
			}
			if ( $js_out ) {
				echo "\n<!-- UiChemy: JS placed before head -->\n" . implode( "\n", $js_out ) . "\n";
			}
			// phpcs:enable
		}

		/**
		 * Queue a section's CSS or JS for the end of the body ('Before Body').
		 *
		 * Called by the widget, which has already applied scoping, sanitizing and the
		 * author-trust rule. Printed by print_body_placed_code() at wp_footer 9999,
		 * after every other footer script, so bundled libraries are ready. If that
		 * printer already ran (a widget rendered very late), print straight away.
		 *
		 * @param string $kind 'css' or 'js'.
		 * @param string $uid  Widget id.
		 * @param string $code Ready-to-print CSS or JS.
		 * @return void
		 */
		public static function queue_body_code( $kind, $uid, $code ) {
			$code = (string) $code;
			if ( '' === trim( $code ) || ! in_array( $kind, array( 'css', 'js' ), true ) ) {
				return;
			}
			$item = array(
				'kind' => $kind,
				'uid'  => (string) $uid,
				'code' => $code,
			);
			if ( self::$body_printed ) {
				self::print_body_item( $item );
				return;
			}
			self::$body_queue[] = $item;
		}

		/**
		 * Print the queued 'Before Body' CSS and JS.
		 *
		 * @return void
		 */
		public static function print_body_placed_code() {
			foreach ( self::$body_queue as $item ) {
				self::print_body_item( $item );
			}
			self::$body_queue   = array();
			self::$body_printed = true;
		}

		/**
		 * Echo one queued item.
		 *
		 * @param array $item { kind, uid, code }
		 * @return void
		 */
		private static function print_body_item( $item ) {
			$id = esc_attr( $item['uid'] );
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Author CSS/JS already passed the widget's scoping, sanitizing and unfiltered_html rules.
			if ( 'css' === $item['kind'] ) {
				echo "\n<style id=\"uich-body-css-" . $id . "\">\n" . $item['code'] . "\n</style>\n";
			} else {
				// Same rule as every other widget script: when head libraries were deferred,
				// wait for DOMContentLoaded so they have run first.
				echo "\n<script id=\"uich-body-js-" . $id . "\">\n" . self::maybe_wrap_dom_ready( $item['code'] ) . "\n</script>\n";
			}
			// phpcs:enable
		}

		/**
		 * Whether a post's author may emit raw code (same rule as the widget).
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		private static function author_allows_raw_code( $post_id ) {
			$author_id = (int) get_post_field( 'post_author', (int) $post_id );
			return $author_id > 0 && user_can( $author_id, 'unfiltered_html' );
		}

		/**
		 * Check whether a section UID had its critical CSS inlined in <head>.
		 *
		 * @param string $uid
		 * @return bool
		 */
		public static function is_critical_uid( $uid ) {
			return isset( self::$printed_uids[ (string) $uid ] );
		}

		/**
		 * Inlines the critical CSS for the first header and hero sections directly in <head>.
		 *
		 * @return void
		 */
		public static function print_critical_header_hero_css() {
			if ( ! self::should_optimize() || ! self::opt( 'perf_critical_css' ) ) {
				return;
			}

			$critical_css = self::get_header_hero_critical_css();
			if ( '' === trim( $critical_css ) ) {
				return;
			}

			// Icon font guard ensures Font Awesome, Dashicons, and eicons do not flash tofu boxes.
			$guard = '';
			if ( class_exists( 'UiChemy_Composer_Renderer' ) ) {
				$guard = UiChemy_Composer_Renderer::icon_font_guard();
			}

			echo "\n<!-- UiChemy Fast Load Critical CSS: First Header & Hero Section -->\n";
			if ( '' !== $guard ) {
				echo $guard . "\n";
			}
			echo "<style id=\"uich-critical-header-hero\">\n" . $critical_css . "\n</style>\n";
		}

		/**
		 * Build critical CSS for header and hero sections.
		 *
		 * @return string Scoped CSS block.
		 */
		public static function get_header_hero_critical_css() {
			$post_id = get_queried_object_id();
			if ( ! $post_id && ( is_front_page() || is_home() ) ) {
				$post_id = (int) get_option( 'page_on_front' );
			}

			$css_parts = array();

			// 1. Check if an active UiChemy Theme Builder header template exists
			$header_template_id = 0;
			if ( class_exists( 'UiChemy_Template_Resolver' ) ) {
				$header_template_id = (int) UiChemy_Template_Resolver::resolve( 'header' );
			}

			if ( $header_template_id ) {
				$header_css = self::extract_sections_css( $header_template_id, 'all' );
				if ( '' !== $header_css ) {
					$css_parts[] = "/* --- UiChemy Header Template (ID: {$header_template_id}) --- */\n" . $header_css;
				}
			}

			// 2. Extract sections from the current page
			if ( $post_id ) {
				$mode     = $header_template_id ? 'hero_only' : 'header_and_hero';
				$page_css = self::extract_sections_css( $post_id, $mode );
				if ( '' !== $page_css ) {
					$css_parts[] = "/* --- UiChemy Page Hero / Above-the-fold (Post: {$post_id}) --- */\n" . $page_css;
				}
			}

			return implode( "\n\n", $css_parts );
		}

		/**
		 * Extract and scope the CSS for designated sections of a post.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $mode    'all' | 'hero_only' | 'header_and_hero'.
		 * @return string
		 */
		private static function extract_sections_css( $post_id, $mode ) {
			if ( ! class_exists( 'UiChemy_Builder_Registry' ) ) {
				return '';
			}

			$builder  = UiChemy_Builder_Registry::detect_post_builder( $post_id );
			$sections = self::load_sections_for( $post_id );
			if ( empty( $sections ) ) {
				return '';
			}

			// uid => role. 'below' is a starting section after the hero, which is
			// usually partly visible on first paint.
			$picked = array();

			if ( 'all' === $mode ) {
				// Header template: every section belongs to the header.
				foreach ( $sections as $sec ) {
					$picked[] = array( $sec, 'header' );
				}
			} else {
				// The starting sections are picked by position alone; section labels and
				// markup play no part, so a section named "hero" further down is never
				// treated as the top of the page.
				//   Header template: 0 = hero, 1-2 = below.
				//   No header template (page carries its own header): 0 = header,
				//   1 = hero, 2 = below.
				$hero_idx = 'hero_only' === $mode ? 0 : 1;
				foreach ( array_slice( array_values( $sections ), 0, self::STARTING_SECTIONS ) as $i => $sec ) {
					if ( $i < $hero_idx ) {
						$role = 'header';
					} elseif ( $i === $hero_idx ) {
						$role = 'hero';
					} else {
						$role = 'below';
					}
					$picked[] = array( $sec, $role );
				}
			}

			$css_out = array();

			foreach ( $picked as $pair ) {
				list( $sec, $role ) = $pair;
				$uid                = (string) ( $sec['uid'] ?? '' );
				if ( '' === $uid || isset( self::$printed_uids[ $uid ] ) ) {
					continue;
				}
				self::$roles[ $uid ] = $role;

				$settings = isset( $sec['settings'] ) && is_array( $sec['settings'] ) ? $sec['settings'] : array();
				// The author placed this section's CSS 'Before Body' on purpose: keep it
				// out of <head> (the widget prints it at the end of the body).
				if ( 'body' === ( $settings['raw_css_placement'] ?? '' ) ) {
					continue;
				}
				self::$printed_uids[ $uid ] = true;
				$raw_css                    = isset( $settings['raw_css'] ) ? (string) $settings['raw_css'] : '';
				if ( '' === trim( $raw_css ) ) {
					continue;
				}

				if ( 'elementor' === $builder ) {
					$scope_selector = '.elementor-element-' . $uid;
				} else {
					$scope_selector = '.uichemy-composer-' . $uid;
				}

				if ( class_exists( 'UiChemy_Composer_Renderer' ) ) {
					$scoped = UiChemy_Composer_Renderer::scope_css_for_scope( $raw_css, $scope_selector );
				} else {
					$scoped = $raw_css;
				}

				if ( '' !== trim( $scoped ) ) {
					$label     = ! empty( $sec['label'] ) ? ' [' . esc_html( $sec['label'] ) . ']' : '';
					$label    .= ' (' . $role . ')';
					$css_out[] = "/* Section UID: {$uid}{$label} */\n" . $scoped;
				}
			}

			return implode( "\n\n", $css_out );
		}
	}
}
