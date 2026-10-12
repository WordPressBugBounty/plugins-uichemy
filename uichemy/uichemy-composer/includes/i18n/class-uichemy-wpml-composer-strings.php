<?php
/**
 * UiChemy_WPML_Composer_Strings — teaches WPML's Elementor translation about Composer text.
 *
 * WPML's Advanced Translation Editor builds a job from the strings each Elementor widget
 * reports. A Composer widget keeps its copy inside `raw_html`, so by itself it reports
 * nothing. This module reports the widget's visible texts (see UiChemy_I18n_Composer_Text)
 * and, when a translation comes back, stores it in the translated copy's widget settings
 * (`uich_i18n`), where the renderer applies it.
 *
 * It plugs into WPML through the `integration-class` hook of
 * `wpml_elementor_widgets_to_translate` (see UiChemy_I18n_Compat), so it works with the
 * translation editor, WPML's duplicate-and-translate and its translation memory unchanged.
 *
 * This file may only be loaded when WPML's Elementor integration is present, because the
 * class extends WPML_Elementor_Module_With_Items; UiChemy_I18n_Compat guarantees that.
 *
 * @package UiChemy
 * @subpackage UiChemy/i18n
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPML_Elementor_Module_With_Items' ) && ! class_exists( 'UiChemy_WPML_Composer_Strings' ) ) {

	/**
	 * Composer widget -> WPML strings.
	 */
	class UiChemy_WPML_Composer_Strings extends WPML_Elementor_Module_With_Items {

		/**
		 * String-name prefix; the rest is `<key>-<widget id>`.
		 */
		const PREFIX = 'uichemy-text-';

		/**
		 * Texts longer than this get the multi-line editor.
		 */
		const AREA_THRESHOLD = 80;

		/**
		 * The widget setting that holds the translations.
		 *
		 * @return string
		 */
		public function get_items_field() {
			return UiChemy_I18n_Composer_Text::SETTING;
		}

		/**
		 * Required by the parent; unused because get() / update() are overridden.
		 *
		 * @param string $field Field key.
		 * @return string
		 */
		protected function get_title( $field ) {
			return __( 'Composer text', 'uichemy' );
		}

		/**
		 * Required by the parent; the strings here are not fixed fields.
		 *
		 * @return array
		 */
		protected function get_fields() {
			return array();
		}

		/**
		 * Required by the parent.
		 *
		 * @param string $field Field key.
		 * @return string
		 */
		protected function get_editor_type( $field ) {
			return 'LINE';
		}

		/**
		 * The translations already stored on this widget.
		 *
		 * @param array $element Elementor element.
		 * @return array
		 */
		public function get_items( $element ) {
			$items = isset( $element['settings'][ $this->get_items_field() ] ) ? $element['settings'][ $this->get_items_field() ] : array();
			return is_array( $items ) ? $items : array();
		}

		/**
		 * Report every visible text of the widget to WPML.
		 *
		 * @param string $node_id Elementor element id.
		 * @param array  $element Elementor element.
		 * @param array  $strings Strings collected so far.
		 * @return array
		 */
		public function get( $node_id, $element, $strings ) {
			$html = isset( $element['settings']['raw_html'] ) ? $element['settings']['raw_html'] : '';
			if ( ! is_string( $html ) || '' === trim( $html ) ) {
				return $strings;
			}

			foreach ( UiChemy_I18n_Composer_Text::extract( $html ) as $key => $row ) {
				$is_attr   = 'attr' === $row['kind'];
				$strings[] = new WPML_PB_String(
					$row['text'],
					self::name_for( $key, $node_id ),
					$is_attr ? __( 'Composer: alt / title / placeholder', 'uichemy' ) : __( 'Composer text', 'uichemy' ),
					( $is_attr || strlen( $row['text'] ) <= self::AREA_THRESHOLD ) ? 'LINE' : 'AREA'
				);
			}

			return $strings;
		}

		/**
		 * Store one translation on the translated copy of the widget.
		 *
		 * WPML assigns the returned item to settings[ uich_i18n ][ item['index'] ].
		 *
		 * @param string         $node_id Elementor element id.
		 * @param array          $element Elementor element (the translated copy).
		 * @param WPML_PB_String $string  The translated string.
		 * @return array|null Item to store, or null when the string is not ours.
		 */
		public function update( $node_id, $element, WPML_PB_String $string ) {
			$parsed = self::parse_name( $string->get_name() );
			if ( ! $parsed || (string) $parsed['node'] !== (string) $node_id ) {
				return null;
			}

			return array(
				'index' => $parsed['key'],
				'text'  => $string->get_value(),
			);
		}

		/**
		 * Name WPML stores a string under.
		 *
		 * @param string $key     Hash of the wording.
		 * @param string $node_id Elementor element id.
		 * @return string
		 */
		public static function name_for( $key, $node_id ) {
			return self::PREFIX . $key . '-' . $node_id;
		}

		/**
		 * Split a name made by name_for().
		 *
		 * @param string $name String name.
		 * @return array{key:string,node:string}|null
		 */
		public static function parse_name( $name ) {
			if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '([0-9a-f]{16})-(.+)$/', (string) $name, $m ) ) {
				return array(
					'key'  => $m[1],
					'node' => $m[2],
				);
			}
			return null;
		}
	}
}
