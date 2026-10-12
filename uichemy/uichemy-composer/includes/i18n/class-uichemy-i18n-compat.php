<?php
/**
 * UiChemy_I18n_Compat — hooks that teach WPML / Polylang about UiChemy content.
 *
 * Passive by design: every callback is attached to a filter that only fires when the
 * corresponding multilingual plugin is installed, so on a single-language site this
 * file costs one add_filter() per hook and changes nothing.
 *
 * @package UiChemy
 * @subpackage UiChemy/i18n
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_I18n_Compat' ) ) {

	/**
	 * Registers UiChemy's translatable fields with the multilingual plugins.
	 */
	class UiChemy_I18n_Compat {

		/**
		 * Elementor widget type of the Composer widget (the content contract in
		 * `_elementor_data`, see UiChemy_Composer_Widget::get_name()).
		 */
		const WIDGET = 'uichemy-composer';

		/**
		 * Attach every hook. Safe to call more than once.
		 *
		 * @return void
		 */
		public static function init() {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;

			// WPML Elementor: which widget settings are translatable strings.
			add_filter( 'wpml_elementor_widgets_to_translate', array( __CLASS__, 'wpml_elementor_widgets' ) );
		}

		/**
		 * Tell WPML's Elementor integration which Composer widget settings are text.
		 *
		 * A Composer widget keeps its markup in `raw_html`, and the per-element overrides
		 * an author types in the panel in `slot_{n}` (text) settings. The slot values are
		 * real strings, so a translator sees them in the translation editor like any other
		 * Elementor text control. The markup itself is intentionally NOT offered: handing a
		 * translator a raw HTML/CSS blob invites them to break the layout.
		 *
		 * @param array $widgets Widget definitions keyed by widget type.
		 * @return array
		 */
		public static function wpml_elementor_widgets( $widgets ) {
			$count  = class_exists( 'UiChemy_Composer_Widget' ) ? (int) UiChemy_Composer_Widget::SLOT_COUNT : 60;
			$fields = array();

			for ( $i = 0; $i < $count; $i++ ) {
				$fields[] = array(
					'field'       => "slot_{$i}",
					/* translators: %d: position of the text slot inside the widget */
					'type'        => sprintf( __( 'Composer text %d', 'uichemy' ), $i + 1 ),
					'editor_type' => 'AREA',
				);
			}

			// Every name the Composer widget has ever carried in `_elementor_data`: customer
			// sites keep `proton` until the migration rewrites the row, and a page that is
			// not registered here would lose its slot text in the translation editor.
			// The visible copy itself (text nodes and alt / title / placeholder / aria-label in
			// `raw_html`) is reported by UiChemy_WPML_Composer_Strings, which stores each
			// translation in the translated widget's `uich_i18n` setting. It extends a WPML class,
			// so it is only available once WPML's Elementor integration has loaded.
			$integration = self::load_wpml_integration() ? 'UiChemy_WPML_Composer_Strings' : null;

			foreach ( self::widget_types() as $type ) {
				$widgets[ $type ] = array(
					'conditions' => array( 'widgetType' => $type ),
					'fields'     => $fields,
				);
				if ( $integration ) {
					$widgets[ $type ]['integration-class'] = $integration;
				}
			}

			return $widgets;
		}

		/**
		 * Load the WPML-dependent string module, if WPML's Elementor integration is present.
		 *
		 * @return bool Whether UiChemy_WPML_Composer_Strings can be used.
		 */
		private static function load_wpml_integration() {
			if ( ! class_exists( 'WPML_Elementor_Module_With_Items' ) ) {
				return false;
			}
			require_once __DIR__ . '/class-uichemy-wpml-composer-strings.php';
			return class_exists( 'UiChemy_WPML_Composer_Strings' );
		}

		/**
		 * Every Elementor widget type that is the Composer widget, current name first.
		 *
		 * @return string[]
		 */
		public static function widget_types() {
			return function_exists( 'uichemy_composer_widget_types' ) ? uichemy_composer_widget_types() : array( self::WIDGET );
		}
	}
}
