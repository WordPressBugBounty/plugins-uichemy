<?php
/**
 * UiChemy_I18n_Strings — runtime string translation for Theme Builder templates.
 *
 * A Theme Builder header or footer is ONE template shared by every language (see
 * wpml-config.xml). Its text therefore cannot be translated by duplicating the page the
 * way ordinary content is, so while a template renders, each visible text is treated as
 * a translatable string:
 *
 *   - WPML:     registered in String Translation (context "UiChemy Theme Builder") and
 *               swapped for the translation of the current language.
 *   - Polylang: registered through pll_register_string() and swapped with pll__().
 *
 * The strings are keyed by their own text, so editing the English copy simply produces a
 * new string; the old translation is not applied to the new wording by accident.
 *
 * Nothing here runs unless a multilingual plugin is active AND a template is rendering
 * (UiChemy_Template_Render::get_render_html_for() wraps itself in enter() / leave()).
 *
 * @package UiChemy
 * @subpackage UiChemy/i18n
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_I18n_Strings' ) ) {

	/**
	 * Register + translate template strings.
	 */
	class UiChemy_I18n_Strings {

		/**
		 * String-translation context shown to translators.
		 */
		const CONTEXT = 'UiChemy Theme Builder';

		/**
		 * Option holding every string seen so far (name => text).
		 *
		 * Polylang only lists strings registered during the request that renders its
		 * Strings screen, so strings discovered on the front end must be remembered and
		 * replayed there. WPML stores them itself, but the registry is cheap and keeps
		 * both paths symmetrical.
		 */
		const OPTION = 'uichemy_i18n_strings';

		/**
		 * Attributes whose value is visible text.
		 *
		 * @var string[]
		 */
		const ATTRIBUTES = array( 'title', 'alt', 'placeholder', 'aria-label' );

		/**
		 * Nesting depth of template renders in progress.
		 *
		 * @var int
		 */
		private static $depth = 0;

		/**
		 * Registry loaded from the option (name => text); null until first needed.
		 *
		 * @var array<string,string>|null
		 */
		private static $registry = null;

		/**
		 * Whether the registry changed and must be saved at shutdown.
		 *
		 * @var bool
		 */
		private static $dirty = false;

		/**
		 * Names already pushed to WPML during this request.
		 *
		 * @var array<string,bool>
		 */
		private static $sent = array();

		/**
		 * Attach hooks. Safe to call more than once.
		 *
		 * @return void
		 */
		public static function init() {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;

			add_action( 'shutdown', array( __CLASS__, 'save_registry' ) );
			// Polylang's Strings screen lists what was registered while it loaded.
			add_action( 'init', array( __CLASS__, 'replay_for_polylang' ), 20 );
			// Elementor's element cache would freeze one language's output for all of them.
			add_filter( 'elementor/element/is_dynamic_content', array( __CLASS__, 'bypass_elementor_cache' ), 10, 2 );
		}

		/**
		 * A template render starts.
		 *
		 * @return void
		 */
		public static function enter() {
			++self::$depth;
		}

		/**
		 * A template render ends.
		 *
		 * @return void
		 */
		public static function leave() {
			self::$depth = max( 0, self::$depth - 1 );
		}

		/**
		 * Is a template rendering on a multilingual site?
		 *
		 * @return bool
		 */
		public static function in_scope() {
			return self::$depth > 0 && class_exists( 'UiChemy_I18n' ) && UiChemy_I18n::is_active();
		}

		/**
		 * Keep Elementor from caching a Composer widget that renders inside a template.
		 *
		 * @param bool  $is_dynamic Current verdict.
		 * @param array $raw_data   Element data.
		 * @return bool
		 */
		public static function bypass_elementor_cache( $is_dynamic, $raw_data = array() ) {
			if ( $is_dynamic ) {
				return $is_dynamic;
			}
			$type = is_array( $raw_data ) && isset( $raw_data['widgetType'] ) ? (string) $raw_data['widgetType'] : '';
			return ( in_array( $type, UiChemy_I18n_Compat::widget_types(), true ) && self::in_scope() );
		}

		/**
		 * Stable string name for a text.
		 *
		 * @param string $text Source text.
		 * @return string
		 */
		public static function name_for( $text ) {
			return 'tpl_' . substr( md5( (string) $text ), 0, 16 );
		}

		/**
		 * Should this text be offered for translation at all?
		 *
		 * Skips empty / punctuation-only text and anything that is still a template
		 * expression or a UiChemy dynamic-tag placeholder, which are code, not copy.
		 *
		 * @param string $text Text.
		 * @return bool
		 */
		public static function is_translatable( $text ) {
			$text = trim( (string) $text );
			if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) ) {
				return false;
			}
			if ( preg_match( '/\{\{|\{%|\{#|__[A-Z0-9_]{3,}__|<uichemy/i', $text ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Register a text and return its translation for the current language.
		 *
		 * @param string $text Source text (the template's own wording).
		 * @return string Translation, or $text when there is none.
		 */
		public static function process( $text ) {
			$text = (string) $text;
			if ( ! self::is_translatable( $text ) ) {
				return $text;
			}

			// Surrounding whitespace belongs to the layout, not to the string.
			if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', $text, $m ) ) {
				return $text;
			}
			$core = $m[2];

			self::register( $core );
			$translated = self::translate( $core );

			return ( '' !== $translated && $translated !== $core ) ? $m[1] . $translated . $m[3] : $text;
		}

		/**
		 * Make a text known to the translation plugin.
		 *
		 * @param string $core Trimmed source text.
		 * @return void
		 */
		public static function register( $core ) {
			$name = self::name_for( $core );

			$registry = self::registry();
			if ( ! isset( $registry[ $name ] ) ) {
				self::$registry[ $name ] = $core;
				self::$dirty             = true;
			}

			if ( 'wpml' === UiChemy_I18n::engine() && ! isset( self::$sent[ $name ] ) ) {
				self::$sent[ $name ] = true;
				// Registers the default-language value; a no-op without String Translation.
				do_action( 'wpml_register_single_string', self::CONTEXT, $name, $core ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- WPML's own hook.
			}
		}

		/**
		 * The current language's translation of a registered text.
		 *
		 * @param string $core Trimmed source text.
		 * @return string The translation, or $core.
		 */
		public static function translate( $core ) {
			if ( ! UiChemy_I18n::is_secondary_language() ) {
				return $core;
			}

			switch ( UiChemy_I18n::engine() ) {
				case 'wpml':
					$out = apply_filters( 'wpml_translate_single_string', $core, self::CONTEXT, self::name_for( $core ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- WPML's own hook.
					return is_string( $out ) ? $out : $core;
				case 'polylang':
					return function_exists( 'pll__' ) ? (string) pll__( $core ) : $core;
			}
			return $core;
		}

		/**
		 * Apply every translation that belongs to a rendering widget.
		 *
		 * Two independent sources, applied in this order:
		 *   1. the widget's own `uich_i18n` map: written by WPML's translation editor onto the
		 *      TRANSLATED copy of a page, so it applies regardless of context;
		 *   2. string translation for Theme Builder templates (see translate_dom()), which only
		 *      applies while a template renders.
		 *
		 * @param \DOMNode $dom      Parsed widget markup.
		 * @param array    $settings Widget settings.
		 * @return void
		 */
		public static function apply_translations( $dom, $settings ) {
			if ( class_exists( 'UiChemy_I18n_Composer_Text' ) && is_array( $settings ) && ! empty( $settings[ UiChemy_I18n_Composer_Text::SETTING ] ) ) {
				UiChemy_I18n_Composer_Text::apply( $dom, $settings[ UiChemy_I18n_Composer_Text::SETTING ] );
			}
			if ( self::in_scope() ) {
				self::translate_dom( $dom );
			}
		}

		/**
		 * Translate every visible string of a parsed template fragment, in place.
		 *
		 * Runs on the DOM the renderer already built. Text nodes are rewritten through
		 * DOMCharacterData::$data (serialisation escapes them); attributes through
		 * setAttribute(). Nothing structural is touched, so markup and classes survive.
		 *
		 * @param \DOMNode $root Fragment root.
		 * @return void
		 */
		public static function translate_dom( $root ) {
			if ( ! self::in_scope() || ! $root instanceof \DOMNode ) {
				return;
			}

			$skip = array( 'style', 'script', 'noscript', 'template', 'svg', 'textarea', 'code', 'pre' );

			foreach ( iterator_to_array( $root->childNodes ) as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$out = self::process( $child->data );
					if ( $out !== $child->data ) {
						$child->data = $out;
					}
				} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
					$tag = strtolower( $child->nodeName );
					if ( in_array( $tag, $skip, true ) || 0 === strncmp( $tag, 'uichemy-', 8 ) ) {
						continue;
					}
					foreach ( self::ATTRIBUTES as $attr ) {
						if ( $child instanceof \DOMElement && $child->hasAttribute( $attr ) ) {
							$value = $child->getAttribute( $attr );
							$out   = self::process( $value );
							if ( $out !== $value ) {
								$child->setAttribute( $attr, $out );
							}
						}
					}
					self::translate_dom( $child );
				}
			}
		}

		/**
		 * Registry accessor (loads the option once).
		 *
		 * @return array<string,string>
		 */
		private static function registry() {
			if ( null === self::$registry ) {
				$stored         = get_option( self::OPTION, array() );
				self::$registry = is_array( $stored ) ? $stored : array();
			}
			return self::$registry;
		}

		/**
		 * Persist newly discovered strings.
		 *
		 * @return void
		 */
		public static function save_registry() {
			if ( self::$dirty && is_array( self::$registry ) ) {
				update_option( self::OPTION, self::$registry, false );
				self::$dirty = false;
			}
		}

		/**
		 * Re-register remembered strings so Polylang lists them on its Strings screen.
		 *
		 * @return void
		 */
		public static function replay_for_polylang() {
			if ( ! is_admin() || ! function_exists( 'pll_register_string' ) ) {
				return;
			}
			foreach ( self::registry() as $name => $text ) {
				pll_register_string( (string) $name, (string) $text, self::CONTEXT, false !== strpos( (string) $text, "\n" ) || strlen( (string) $text ) > 80 );
			}
		}
	}
}
