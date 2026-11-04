<?php
/**
 * Load the Google Fonts a page's UiChemy CSS asks for.
 *
 * The Font family picker writes `font-family: Montserrat` and nothing else, so
 * a family the site did not already link fell back to the theme's font on the
 * live page. Nothing is stored for this: the CSS UiChemy prints is read as it
 * is printed, and each Google family in it gets one stylesheet <link>, once
 * per request. The editor's canvas does the same in JS
 * (new-dashboard/src/uichemy-composer/composer-font-autoload.js) — keep the
 * family list and the URL in step with it.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Google_Fonts' ) ) {

	/**
	 * Google font links for UiChemy CSS.
	 */
	class UiChemy_Google_Fonts {

		/**
		 * The Font family picker's Google families. System, installed and
		 * generic families are left out: they are never requested.
		 *
		 * @var string[]
		 */
		const FAMILIES = array(
			'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Nunito', 'Nunito Sans',
			'Source Sans Pro', 'Source Sans 3', 'Raleway', 'Oswald', 'Mulish', 'Work Sans', 'Karla',
			'PT Sans', 'Noto Sans', 'DM Sans', 'Manrope', 'Plus Jakarta Sans', 'Rubik', 'IBM Plex Sans',
			'Playfair Display', 'Merriweather', 'Lora', 'PT Serif', 'Source Serif Pro', 'Source Serif 4',
			'Crimson Text', 'Cormorant Garamond', 'Libre Baskerville', 'EB Garamond', 'Bitter',
			'JetBrains Mono', 'Fira Code', 'Source Code Pro', 'IBM Plex Mono', 'Roboto Mono', 'Inconsolata',
		);

		/**
		 * Families already linked on this request, by lower case.
		 *
		 * @var array<string,true>
		 */
		private static $printed = array();

		/**
		 * The Google families CSS names in its `font-family` declarations, each
		 * once, in the order first met. Every name in a stack counts.
		 *
		 * @param string $css CSS or a style="" value.
		 * @return string[]
		 */
		public static function families_in_css( $css ) {
			$out = array();
			$css = (string) $css;
			if ( '' === $css || false === stripos( $css, 'font-family' ) ) {
				return $out;
			}

			$known = array();
			foreach ( self::FAMILIES as $family ) {
				$known[ strtolower( $family ) ] = $family;
			}

			if ( preg_match_all( '/font-family\s*:\s*([^;}{]+)/i', $css, $m ) ) {
				foreach ( $m[1] as $stack ) {
					// A var() fallback is a token's default, not a pick: only the
					// names written out count (same as the canvas).
					$stack = preg_replace( array( '/!important/i', '/var\([^()]*(?:\([^()]*\)[^()]*)*\)/i' ), '', $stack );
					foreach ( explode( ',', $stack ) as $part ) {
						$name = strtolower( trim( trim( trim( $part ), '"\'' ) ) );
						if ( isset( $known[ $name ] ) && ! in_array( $known[ $name ], $out, true ) ) {
							$out[] = $known[ $name ];
						}
					}
				}
			}

			return $out;
		}

		/**
		 * The stylesheet URL for one family, every weight and italic: Google's
		 * first CSS API, which serves whichever weights the family has (css2
		 * fails the whole request on one it lacks). The URL Elementor builds, so
		 * a family both load is one file in the browser cache.
		 *
		 * @param string $family Family name.
		 * @return string
		 */
		public static function url( $family ) {
			$weights = '100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic';
			return 'https://fonts.googleapis.com/css?family=' . str_replace( '%20', '+', rawurlencode( $family ) ) . ':' . $weights . '&display=swap';
		}

		/**
		 * <link> tags for the Google families `$css` uses that this request has
		 * not linked yet ('' when there are none).
		 *
		 * @param string $css CSS about to print.
		 * @return string
		 */
		public static function links_for_css( $css ) {
			/**
			 * Whether UiChemy links the Google Fonts its CSS uses. Return false to
			 * serve fonts yourself (self-hosted, or no requests to Google).
			 *
			 * @since 5.5.2
			 *
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'uichemy_google_fonts_autoload', true ) ) {
				return '';
			}

			$out = '';
			foreach ( self::families_in_css( $css ) as $family ) {
				$key = strtolower( $family );
				if ( isset( self::$printed[ $key ] ) ) {
					continue;
				}
				self::$printed[ $key ] = true;
				$out .= '<link rel="stylesheet" id="uich-gf-' . esc_attr( sanitize_title( $family ) ) . '" href="' . esc_url( self::url( $family ) ) . '" media="all" />' . "\n";
			}

			return $out;
		}
	}
}
