<?php
/**
 * The normalized Composer section — the one shape every builder is read into
 * and written back from.
 *
 * Sections travel as plain arrays (the house style everywhere else in the MCP
 * layer); this class only mints and normalises them so the shape cannot drift
 * between three drivers and the shared op layer.
 *
 * `native` is a driver-private back-reference to the untouched storage node. It
 * lets a driver round-trip settings it does not model — Elementor container
 * geometry, Bricks per-element style controls, Gutenberg innerBlocks — instead
 * of rebuilding a node from scratch and silently dropping them. It MUST be
 * stripped before anything reaches an MCP response; see strip_native().
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Section' ) ) {

	/**
	 * Mints and normalises the neutral section shape.
	 */
	class UiChemy_Section {

		/**
		 * The content areas a builder may expose. Bricks keeps three separate
		 * meta keys; Elementor and Gutenberg only ever use 'content'.
		 */
		const AREAS = array( 'content', 'header', 'footer' );

		/**
		 * The keys of the neutral settings map that carry code. Everything else
		 * in the map (slots, deps, custom code) is passed through untouched.
		 */
		const CODE_KEYS = array(
			'html' => 'raw_html',
			'css'  => 'raw_css',
			'js'   => 'raw_js',
		);

		/**
		 * Build a normalized section.
		 *
		 * @param array $args { uid, index, area, settings, container, native }.
		 * @return array
		 */
		public static function make( array $args = array() ) {
			$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array();

			return array(
				'uid'       => isset( $args['uid'] ) ? (string) $args['uid'] : '',
				'index'     => isset( $args['index'] ) ? (int) $args['index'] : 0,
				'area'      => self::area( isset( $args['area'] ) ? $args['area'] : 'content' ),
				'label'     => isset( $settings['_title'] ) ? (string) $settings['_title'] : '',
				'settings'  => $settings,
				'container' => isset( $args['container'] ) ? (string) $args['container'] : '',
				'native'    => isset( $args['native'] ) ? $args['native'] : null,
			);
		}

		/**
		 * Clamp an area to a known value.
		 *
		 * @param string $area Raw area.
		 * @return string
		 */
		public static function area( $area ) {
			$area = sanitize_key( (string) $area );
			return in_array( $area, self::AREAS, true ) ? $area : 'content';
		}

		/**
		 * A fresh uid. Lowercase alphanumerics only, so it survives the
		 * sanitize_html_class() the renderer applies when it builds the
		 * `uichemy-composer-<uid>` scope class — an id that changes under that
		 * sanitiser can never be found again by uid lookup.
		 *
		 * @param int $length Characters.
		 * @return string
		 */
		public static function mint_uid( $length = 7 ) {
			$length = max( 4, (int) $length );
			do {
				$uid = strtolower( wp_generate_password( $length, false, false ) );
			} while ( sanitize_html_class( $uid ) !== $uid );

			return $uid;
		}

		/**
		 * Assemble the neutral settings map from an html/css/js payload.
		 *
		 * @param array $payload { html?, css?, js?, label? }.
		 * @param array $base    Existing settings to merge into.
		 * @return array
		 */
		public static function settings_from_payload( array $payload, array $base = array() ) {
			$settings = $base;

			if ( isset( $payload['label'] ) ) {
				$settings['_title'] = sanitize_text_field( (string) $payload['label'] );
			}
			foreach ( self::CODE_KEYS as $short => $key ) {
				if ( isset( $payload[ $short ] ) ) {
					$settings[ $key ] = (string) $payload[ $short ];
				}
			}

			return $settings;
		}

		/**
		 * The { widget_id, label, html, css, js } shape the MCP read tools have
		 * always returned, so the op layer can answer in it for every builder.
		 *
		 * @param array $section Normalized section.
		 * @return array
		 */
		public static function to_code_response( array $section ) {
			$settings = isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : array();

			$out = array(
				'widget_id' => isset( $section['uid'] ) ? (string) $section['uid'] : '',
				'label'     => isset( $settings['_title'] ) ? (string) $settings['_title'] : '',
			);
			foreach ( self::CODE_KEYS as $short => $key ) {
				$out[ $short ] = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			}

			return $out;
		}

		/**
		 * Drop the driver-private back-reference from one section or a list of
		 * them. Always run this before a section reaches an MCP response: the
		 * native node can be a whole Elementor subtree, and it is meaningless
		 * to the client.
		 *
		 * @param array $sections One section or a list of sections.
		 * @return array
		 */
		public static function strip_native( array $sections ) {
			if ( isset( $sections['uid'] ) || array_key_exists( 'native', $sections ) ) {
				unset( $sections['native'] );
				return $sections;
			}

			foreach ( $sections as $i => $section ) {
				if ( is_array( $section ) ) {
					unset( $section['native'] );
					$sections[ $i ] = $section;
				}
			}

			return $sections;
		}

		/**
		 * Renumber `index` to match list order after a structural change.
		 *
		 * @param array $sections Ordered list.
		 * @return array
		 */
		public static function reindex( array $sections ) {
			$i = 0;
			foreach ( $sections as $k => $section ) {
				if ( is_array( $section ) ) {
					$section['index'] = $i++;
					$sections[ $k ]   = $section;
				}
			}

			return array_values( $sections );
		}

		/**
		 * Find a section's list position by uid, or by 0-based index when no uid
		 * is given. Returns null when nothing matches — callers must treat that
		 * as "not found" rather than falling back to position 0, which would
		 * edit the wrong section.
		 *
		 * @param array  $sections Ordered list.
		 * @param string $uid      Target uid, or '' to use $index.
		 * @param int    $index    0-based position, used only when $uid is ''.
		 * @return int|null
		 */
		public static function locate( array $sections, $uid = '', $index = -1 ) {
			$uid = (string) $uid;

			if ( '' !== $uid ) {
				foreach ( $sections as $i => $section ) {
					if ( is_array( $section ) && isset( $section['uid'] ) && (string) $section['uid'] === $uid ) {
						return (int) $i;
					}
				}
				return null;
			}

			$index = (int) $index;
			return ( $index >= 0 && isset( $sections[ $index ] ) ) ? $index : null;
		}
	}
}
