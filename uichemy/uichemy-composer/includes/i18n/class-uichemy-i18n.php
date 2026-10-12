<?php
/**
 * UiChemy_I18n — one small API over WPML and Polylang.
 *
 * Everything in UiChemy that has to know about the site's languages (theme-builder
 * display conditions, dynamic loops, SEO, language switcher) goes through here, so
 * no other class ever calls a WPML or Polylang function directly and the plugin
 * behaves exactly as before when neither is active.
 *
 * Contract:
 *  - Every method is safe to call when no multilingual plugin is active. Lookups
 *    return the ID / value they were given, language getters return ''.
 *  - A missing translation never returns 0 from a *_in()/original_*() lookup that
 *    was handed a real ID: callers get the input back, so a page that has not been
 *    translated yet still resolves to something usable.
 *
 * @package UiChemy
 * @subpackage UiChemy/i18n
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_I18n' ) ) {

	/**
	 * Language helper (WPML + Polylang).
	 */
	class UiChemy_I18n {

		/**
		 * Which multilingual plugin is driving the site.
		 *
		 * Polylang wins when both are present (they are mutually exclusive in
		 * practice; this only decides which API a freak install is read through).
		 *
		 * @return string 'polylang', 'wpml', or '' when the site is single-language.
		 */
		public static function engine() {
			if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' ) ) {
				return 'polylang';
			}
			if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) ) {
				return 'wpml';
			}
			return '';
		}

		/**
		 * Is a multilingual plugin active?
		 *
		 * @return bool
		 */
		public static function is_active() {
			return '' !== self::engine();
		}

		/**
		 * The site's default language code (e.g. "en").
		 *
		 * @return string '' when single-language.
		 */
		public static function default_language() {
			switch ( self::engine() ) {
				case 'polylang':
					$lang = pll_default_language();
					return is_string( $lang ) ? $lang : '';
				case 'wpml':
					$lang = apply_filters( 'wpml_default_language', null );
					return is_string( $lang ) ? $lang : '';
			}
			return '';
		}

		/**
		 * The language of the current request (front end) or admin context.
		 *
		 * @return string '' when single-language, or when WPML is on "All languages".
		 */
		public static function current_language() {
			switch ( self::engine() ) {
				case 'polylang':
					$lang = pll_current_language();
					return is_string( $lang ) ? $lang : '';
				case 'wpml':
					$lang = apply_filters( 'wpml_current_language', null );
					return ( is_string( $lang ) && 'all' !== $lang ) ? $lang : '';
			}
			return '';
		}

		/**
		 * Is the current request in a language other than the default one?
		 *
		 * @return bool
		 */
		public static function is_secondary_language() {
			$current = self::current_language();
			$default = self::default_language();
			return '' !== $current && '' !== $default && $current !== $default;
		}

		/**
		 * Active language codes.
		 *
		 * @return string[]
		 */
		public static function languages() {
			switch ( self::engine() ) {
				case 'polylang':
					$list = pll_languages_list( array( 'fields' => 'slug' ) );
					return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
				case 'wpml':
					$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
					return is_array( $list ) ? array_map( 'strval', array_keys( $list ) ) : array();
			}
			return array();
		}

		/**
		 * The post's ID in a given language.
		 *
		 * @param int    $post_id   Any translation of the post.
		 * @param string $lang      Target language code.
		 * @param string $post_type Post type (WPML needs it; read from the post when empty).
		 * @return int The translation's ID, or $post_id when there is none (never 0 for a real ID).
		 */
		public static function post_id_in( $post_id, $lang, $post_type = '' ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || '' === (string) $lang ) {
				return $post_id;
			}

			switch ( self::engine() ) {
				case 'polylang':
					$translated = (int) pll_get_post( $post_id, $lang );
					return $translated > 0 ? $translated : $post_id;
				case 'wpml':
					if ( '' === (string) $post_type ) {
						$post_type = (string) get_post_type( $post_id );
					}
					if ( '' === $post_type ) {
						return $post_id;
					}
					$translated = (int) apply_filters( 'wpml_object_id', $post_id, $post_type, true, $lang );
					return $translated > 0 ? $translated : $post_id;
			}
			return $post_id;
		}

		/**
		 * The post's ID in the site's default language.
		 *
		 * This is the language-neutral identity of a page: a theme-builder rule that
		 * says "show on page 12" must keep matching when the visitor is on the French
		 * or Arabic copy (ID 87), so both sides of the comparison are normalised here.
		 *
		 * @param int    $post_id   Any translation of the post.
		 * @param string $post_type Optional post type.
		 * @return int
		 */
		public static function original_post_id( $post_id, $post_type = '' ) {
			return self::post_id_in( $post_id, self::default_language(), $post_type );
		}

		/**
		 * The post's ID in the current language (for links, menus, "back to" targets).
		 *
		 * @param int    $post_id   Any translation of the post.
		 * @param string $post_type Optional post type.
		 * @return int
		 */
		public static function current_post_id( $post_id, $post_type = '' ) {
			return self::post_id_in( $post_id, self::current_language(), $post_type );
		}

		/**
		 * A term's ID in a given language.
		 *
		 * @param int    $term_id  Any translation of the term.
		 * @param string $taxonomy Taxonomy slug.
		 * @param string $lang     Target language code.
		 * @return int The translation's ID, or $term_id when there is none.
		 */
		public static function term_id_in( $term_id, $taxonomy, $lang ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 || '' === (string) $lang || '' === (string) $taxonomy ) {
				return $term_id;
			}

			switch ( self::engine() ) {
				case 'polylang':
					if ( ! function_exists( 'pll_get_term' ) ) {
						return $term_id;
					}
					$translated = (int) pll_get_term( $term_id, $lang );
					return $translated > 0 ? $translated : $term_id;
				case 'wpml':
					$translated = (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $lang );
					return $translated > 0 ? $translated : $term_id;
			}
			return $term_id;
		}

		/**
		 * A term's ID in the site's default language.
		 *
		 * @param int    $term_id  Any translation of the term.
		 * @param string $taxonomy Taxonomy slug.
		 * @return int
		 */
		public static function original_term_id( $term_id, $taxonomy ) {
			return self::term_id_in( $term_id, $taxonomy, self::default_language() );
		}

		/**
		 * Home URL for a language (current language when omitted).
		 *
		 * @param string $lang Language code.
		 * @return string
		 */
		public static function home_url( $lang = '' ) {
			$lang = '' === (string) $lang ? self::current_language() : (string) $lang;
			switch ( self::engine() ) {
				case 'polylang':
					if ( function_exists( 'pll_home_url' ) ) {
						return (string) pll_home_url( $lang );
					}
					break;
				case 'wpml':
					if ( '' !== $lang ) {
						return (string) apply_filters( 'wpml_home_url', home_url( '/' ), $lang );
					}
					break;
			}
			return home_url( '/' );
		}

		/**
		 * A URL in the current language.
		 *
		 * Used when a UiChemy-generated link points at a page by ID: the visitor on
		 * /fr/ should land on the French copy, not bounce to the default language.
		 *
		 * @param int    $post_id   Any translation of the target post.
		 * @param string $post_type Optional post type.
		 * @return string '' when the post does not exist.
		 */
		public static function permalink( $post_id, $post_type = '' ) {
			$id = self::current_post_id( $post_id, $post_type );
			$url = $id > 0 ? get_permalink( $id ) : '';
			return is_string( $url ) ? $url : '';
		}

		/**
		 * Switcher data for the active language plugin.
		 *
		 * @return array<int,array{code:string,name:string,url:string,current:bool,flag:string}>
		 */
		public static function switcher_items() {
			$items = array();
			switch ( self::engine() ) {
				case 'polylang':
					if ( ! function_exists( 'pll_the_languages' ) ) {
						break;
					}
					$raw = pll_the_languages(
						array(
							'raw'           => 1,
							'hide_if_empty' => 0,
						)
					);
					if ( is_array( $raw ) ) {
						foreach ( $raw as $code => $row ) {
							$items[] = array(
								'code'    => (string) $code,
								'name'    => isset( $row['name'] ) ? (string) $row['name'] : (string) $code,
								'url'     => isset( $row['url'] ) ? (string) $row['url'] : '',
								'current' => ! empty( $row['current_lang'] ),
								'flag'    => isset( $row['flag'] ) ? (string) $row['flag'] : '',
							);
						}
					}
					break;
				case 'wpml':
					$raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
					if ( is_array( $raw ) ) {
						foreach ( $raw as $code => $row ) {
							$items[] = array(
								'code'    => (string) $code,
								'name'    => isset( $row['native_name'] ) ? (string) $row['native_name'] : (string) $code,
								'url'     => isset( $row['url'] ) ? (string) $row['url'] : '',
								'current' => ! empty( $row['active'] ),
								'flag'    => isset( $row['country_flag_url'] ) ? (string) $row['country_flag_url'] : '',
							);
						}
					}
					break;
			}
			return $items;
		}
	}
}
