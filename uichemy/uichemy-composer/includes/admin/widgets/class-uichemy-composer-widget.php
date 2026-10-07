<?php
/**
 * Composer Elementor widget.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Composer_Widget' ) ) {
	class UiChemy_Composer_Widget extends \Elementor\Widget_Base {

		/**
		 * Number of editable slots (text / link / image / SVG / background) the
		 * widget pre-registers in the Elementor panel. Elementor registers these
		 * controls statically at editor-config time, so this is a fixed ceiling
		 * rather than a true "unlimited" — it must stay in sync with SLOT_COUNT in
		 * composer/src/composer-slots.js. Keep it high enough to cover real widgets
		 * but not so high that the panel registers thousands of controls and hangs.
		 */
		const SLOT_COUNT = 60;

		/**
		 * The Elementor widget type, as stored in `_elementor_data`.
		 *
		 * This string IS the content contract. Elementor renders an unregistered
		 * widget type as nothing at all, so changing it orphans every widget already
		 * saved under the previous name — which is why the legacy names are still
		 * served, by the hidden aliases in UiChemy_Composer_Widget_Legacy, until
		 * Uich_Composer_Migration has rewritten those rows.
		 *
		 * Keep in sync with Uich_Composer_Migration::NEW_WIDGET.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'uichemy-composer';
		}

		public function get_title() {
			$wl = uichemy_white_label_settings();
			if ( ! empty( $wl['enabled'] ) && ! empty( $wl['plugin_name'] ) && empty( $wl['widget_name'] ) ) {
				return esc_html( $wl['plugin_name'] );
			}
			if ( ! empty( $wl['enabled'] ) && ! empty( $wl['widget_name'] ) ) {
				return esc_html( $wl['widget_name'] );
			}
			return esc_html__( 'Composer', 'uichemy' );
		}

		public function get_icon() {
			$wl = uichemy_white_label_settings();
			if ( ! empty( $wl['enabled'] ) && ! empty( $wl['widget_icon'] ) ) {
				return 'dashicons ' . sanitize_html_class( $wl['widget_icon'] );
			}

			/*
			 * An uploaded brand logo with no dashicon picked. Elementor only accepts
			 * a CSS class here, so the logo is applied as a per-widget CSS variable
			 * the icon rule below consumes (see uichemy_composer_wl_icon_css()) —
			 * without this, a site that set only a logo kept UiChemy's mark in the
			 * widget panel.
			 */
			if ( ! empty( $wl['enabled'] ) && ! empty( $wl['logo_url'] ) ) {
				return 'uichemy-composer-icon uichemy-composer-icon--wl';
			}

			return 'uichemy-composer-icon';
		}

		public function get_categories() {
			return array( 'uichemy' );
		}

		public function get_keywords() {
			// Include the brand name ("uichemy") and common synonyms so the widget
			// surfaces regardless of which term the user searches for. Elementor's
			// search matches the title plus every entry here (case-insensitive,
			// substring). 'protuno' stays in the list: the widget shipped under that
			// name, so users who learned it still find the widget after the rename.
			// When white label is on, expose the custom names too.
			$keywords = array(
				'uichemy',
				'composer',
				'protuno',
				'ai',
				'builder',
				'html',
				'code',
				'dynamic',
				'text',
				'block',
				'generate',
				'widget',
			);

			$wl = uichemy_white_label_settings();
			if ( ! empty( $wl['enabled'] ) ) {
				foreach ( array( 'plugin_name', 'widget_name' ) as $wl_key ) {
					if ( ! empty( $wl[ $wl_key ] ) ) {
						$keywords[] = sanitize_text_field( $wl[ $wl_key ] );
					}
				}
			}

			return $keywords;
		}

		protected function register_controls() {

			// ── Content section ───────────────────────────────────────────────────
			$this->start_controls_section(
				'section_content',
				array(
					'label' => esc_html__( 'Text', 'uichemy' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			// Empty-state notice — shown only when none of the registered slots is a
			// visible plain-text item. A text slot is present when
			// slot_{i}_visible === yes AND is_link === no AND is_image === no
			// AND is_svg === no AND is_dynamic === no, so the notice shows while
			// every slot fails that. The is_dynamic term keeps this honest for a
			// widget whose only text is Twig (a loop card): every field is hidden,
			// so "No text found" is what the panel should say.
			// Registered before the "Add Text" button so the notice sits ABOVE it.
			$text_empty_terms = array();
			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$text_empty_terms[] = array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => "slot_{$i}_visible",
							'operator' => '!==',
							'value'    => 'yes',
						),
						array(
							'name'     => "slot_{$i}_is_link",
							'operator' => '!==',
							'value'    => 'no',
						),
						array(
							'name'     => "slot_{$i}_is_image",
							'operator' => '!==',
							'value'    => 'no',
						),
						array(
							'name'     => "slot_{$i}_is_svg",
							'operator' => '!==',
							'value'    => 'no',
						),
						array(
							'name'     => "slot_{$i}_is_dynamic",
							'operator' => '!==',
							'value'    => 'no',
						),
					),
				);
			}

			$this->add_control(
				'content_text_empty',
				array(
					'type'       => \Elementor\Controls_Manager::RAW_HTML,
					'raw'        => '<div style="text-align:center;padding:10px 0;color:#9da5ae;font-size:12px">' . esc_html__( 'No text found', 'uichemy' ) . '</div>',
					'conditions' => array(
						'relation' => 'and',
						'terms'    => $text_empty_terms,
					),
				)
			);

			$this->add_control(
				'content_text_edit',
				array(
					'type'      => \Elementor\Controls_Manager::RAW_HTML,
					'raw'       => '<div style="text-align:center;padding:6px 0"><button class="elementor-button elementor-button-default" onclick="elementor.channels.editor.trigger(\'uichemy:composer:edit_layers\')">Add Text</button></div>',
					'condition' => array(
						'raw_html' => '',
						'raw_css'  => '',
						'raw_js'   => '',
					),
				)
			);

			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$this->add_control(
					"slot_{$i}_original",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => '',
					)
				);

				$this->add_control(
					"slot_{$i}_visible",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				$this->add_control(
					"slot_{$i}_is_link",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				$this->add_control(
					"slot_{$i}_is_image",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				$this->add_control(
					"slot_{$i}_is_svg",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				// Set by syncSlotSettingsFromNode() when the slot's text (or its
				// link URL) is a Twig value — `{{ post.title }}` data binding or
				// `{% for %}` / `{% endif %}` loop scaffolding. Such a slot has no
				// editable content, so its control is hidden rather than shown as a
				// field that cannot usefully be typed into. Defaults to 'no' so
				// content saved before this flag existed keeps its controls visible
				// until the composer re-derives the slots.
				$this->add_control(
					"slot_{$i}_is_dynamic",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				$this->add_control(
					"slot_{$i}_link_is_dynamic",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'no',
					)
				);

				$this->add_control(
					"slot_{$i}_svg_mode",
					array(
						'type'    => \Elementor\Controls_Manager::HIDDEN,
						'default' => 'code',
					)
				);

				$this->add_control(
					"slot_{$i}",
					array(
						'label'       => esc_html__( 'Text', 'uichemy' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'render_type' => 'none',
						'dynamic'     => array(
							'active' => true,
						),
						'condition'   => array(
							"slot_{$i}_visible"    => 'yes',
							"slot_{$i}_is_link"    => 'no',
							"slot_{$i}_is_image"   => 'no',
							"slot_{$i}_is_svg"     => 'no',
							"slot_{$i}_is_dynamic" => 'no',
						),
					)
				);
			}

			$this->add_control(
				'raw_html',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'raw_css',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'raw_js',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			// Where this widget's CSS / JS is printed: '' = Normal (the long-standing
			// output), 'head' = Before Head, 'body' = Before Body (end of the body).
			$this->add_control(
				'raw_css_placement',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'raw_js_placement',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'page_custom_code_head',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'page_custom_code_footer',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'site_custom_code_head',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'site_custom_code_footer',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			// 3rd-party asset deps — JSON arrays, one per scope.
			$this->add_control(
				'raw_deps_standard',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'raw_deps_page',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->add_control(
				'raw_deps_site',
				array(
					'type'    => \Elementor\Controls_Manager::HIDDEN,
					'default' => '',
				)
			);

			$this->end_controls_section();

			// ── Links section ────────────────────────────────────────────────────
			$this->start_controls_section(
				'section_link',
				array(
					'label' => esc_html__( 'Links', 'uichemy' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			// Empty-state notice — shown only when none of the registered slots is a
			// visible link. A link slot is present when slot_{i}_visible === yes
			// AND is_link === yes, so the notice shows while every slot fails that.
			// A link whose text AND URL are both Twig has neither field rendered, so
			// it counts as absent too — hence the nested 'and' term. Only one of the
			// two being dynamic still leaves an editable field, so the slot counts.
			// Registered before the "Add Links" button so the notice sits ABOVE it.
			$link_empty_terms = array();
			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$link_empty_terms[] = array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => "slot_{$i}_visible",
							'operator' => '!==',
							'value'    => 'yes',
						),
						array(
							'name'     => "slot_{$i}_is_link",
							'operator' => '!==',
							'value'    => 'yes',
						),
						array(
							'relation' => 'and',
							'terms'    => array(
								array(
									'name'     => "slot_{$i}_is_dynamic",
									'operator' => '!==',
									'value'    => 'no',
								),
								array(
									'name'     => "slot_{$i}_link_is_dynamic",
									'operator' => '!==',
									'value'    => 'no',
								),
							),
						),
					),
				);
			}

			$this->add_control(
				'content_links_empty',
				array(
					'type'       => \Elementor\Controls_Manager::RAW_HTML,
					'raw'        => '<div style="text-align:center;padding:10px 0;color:#9da5ae;font-size:12px">' . esc_html__( 'No link found', 'uichemy' ) . '</div>',
					'conditions' => array(
						'relation' => 'and',
						'terms'    => $link_empty_terms,
					),
				)
			);

			$this->add_control(
				'content_links_edit',
				array(
					'type'      => \Elementor\Controls_Manager::RAW_HTML,
					'raw'       => '<div style="text-align:center;padding:6px 0"><button class="elementor-button elementor-button-default" onclick="elementor.channels.editor.trigger(\'uichemy:composer:edit_layers\')">Add Links</button></div>',
					'condition' => array(
						'raw_html' => '',
						'raw_css'  => '',
						'raw_js'   => '',
					),
				)
			);

			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$this->add_control(
					"slot_{$i}_link_text",
					array(
						'label'       => esc_html__( 'Link Text', 'uichemy' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'render_type' => 'none',
						'dynamic'     => array(
							'active' => true,
						),
						'condition'   => array(
							"slot_{$i}_visible"    => 'yes',
							"slot_{$i}_is_link"    => 'yes',
							"slot_{$i}_is_dynamic" => 'no',
						),
					)
				);

				$this->add_control(
					"slot_{$i}_link",
					array(
						'label'   => esc_html__( 'URL', 'uichemy' ),
						'type'    => \Elementor\Controls_Manager::URL,
						'dynamic' => array(
							'active' => true,
						),
						// The URL is flagged separately from the link text: an anchor
						// can pair a static href with a `{{ … }}` label or the reverse,
						// and one shared flag would hide a field that is still editable.
						'condition' => array(
							"slot_{$i}_visible"         => 'yes',
							"slot_{$i}_is_link"         => 'yes',
							"slot_{$i}_link_is_dynamic" => 'no',
						),
					)
				);
			}

			$this->end_controls_section();

			// ── Media section ─────────────────────────────────────────────────────
			$this->start_controls_section(
				'section_media',
				array(
					'label' => esc_html__( 'Media', 'uichemy' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			// Empty-state notice — shown only when none of the registered slots is a
			// visible media item (image or SVG). A media slot is present when
			// slot_{i}_visible === yes AND ( is_image === yes OR is_svg === yes ),
			// so the notice shows while every slot fails that test.
			// Registered before the "Add Media" button so the notice sits ABOVE it.
			$media_empty_terms = array();
			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$media_empty_terms[] = array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => "slot_{$i}_visible",
							'operator' => '!==',
							'value'    => 'yes',
						),
						array(
							'relation' => 'and',
							'terms'    => array(
								array(
									'name'     => "slot_{$i}_is_image",
									'operator' => '!==',
									'value'    => 'yes',
								),
								array(
									'name'     => "slot_{$i}_is_svg",
									'operator' => '!==',
									'value'    => 'yes',
								),
							),
						),
					),
				);
			}

			// Background-image slots (bgslot_*) also count as media: the notice must
			// stay hidden while any element carries a CSS background-image. So the
			// empty state additionally requires every bg-slot to be invisible.
			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$media_empty_terms[] = array(
					'name'     => "bgslot_{$i}_visible",
					'operator' => '!==',
					'value'    => 'yes',
				);
			}

			$this->add_control(
				'content_media_empty',
				array(
					'type'       => \Elementor\Controls_Manager::RAW_HTML,
					'raw'        => '<div style="text-align:center;padding:10px 0;color:#9da5ae;font-size:12px">' . esc_html__( 'No images or "icons" found', 'uichemy' ) . '</div>',
					'conditions' => array(
						'relation' => 'and',
						'terms'    => $media_empty_terms,
					),
				)
			);

			$this->add_control(
				'content_media_edit',
				array(
					'type'      => \Elementor\Controls_Manager::RAW_HTML,
					'raw'       => '<div style="text-align:center;padding:6px 0"><button class="elementor-button elementor-button-default" onclick="elementor.channels.editor.trigger(\'uichemy:composer:edit_layers\')">Add Media</button></div>',
					'condition' => array(
						'raw_html' => '',
						'raw_css'  => '',
						'raw_js'   => '',
					),
				)
			);

			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$this->add_control(
					"slot_{$i}_image",
					array(
						'label'   => esc_html__( 'Choose Image', 'uichemy' ),
						'type'    => \Elementor\Controls_Manager::MEDIA,
						'default' => array(
							'url' => '',
							'id'  => '',
						),
						'dynamic' => array(
							'active' => true,
						),
						'condition' => array(
							"slot_{$i}_visible"  => 'yes',
							"slot_{$i}_is_image" => 'yes',
						),
					)
				);

				$this->add_control(
					"slot_{$i}_image_alt",
					array(
						'label'       => esc_html__( 'Alt', 'uichemy' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'render_type' => 'none',
						'condition'   => array(
							"slot_{$i}_visible"  => 'yes',
							"slot_{$i}_is_image" => 'yes',
						),
					)
				);

				$this->add_control(
					"slot_{$i}_svg_code_media",
					array(
						'label'       => esc_html__( 'Choose SVG', 'uichemy' ),
						'type'        => \Elementor\Controls_Manager::MEDIA,
						'media_type'  => 'svg',
						'render_type' => 'none',
						'default'     => array(
							'url' => '',
							'id'  => '',
						),
						'dynamic'     => array(
							'active' => true,
						),
						'condition'   => array(
							"slot_{$i}_visible"  => 'yes',
							"slot_{$i}_is_svg"   => 'yes',
							"slot_{$i}_svg_mode" => 'code',
						),
					)
				);

				$this->add_control(
					"slot_{$i}_svg_code",
					array(
						'type'        => \Elementor\Controls_Manager::HIDDEN,
						'default'     => '',
						'render_type' => 'none',
						'condition'   => array(
							"slot_{$i}_visible"  => 'yes',
							"slot_{$i}_is_svg"   => 'yes',
							"slot_{$i}_svg_mode" => 'code',
						),
					)
				);

				$this->add_control(
					"slot_{$i}_svg_url",
					array(
						'label'      => esc_html__( 'Choose SVG', 'uichemy' ),
						'type'       => \Elementor\Controls_Manager::MEDIA,
						'media_type' => 'svg',
						'default'    => array(
							'url' => '',
							'id'  => '',
						),
						'dynamic'    => array(
							'active' => true,
						),
						'condition'  => array(
							"slot_{$i}_visible"  => 'yes',
							"slot_{$i}_is_svg"   => 'yes',
							"slot_{$i}_svg_mode" => 'url',
						),
					)
				);
			}

			// ── Background-image slots ────────────────────────────────────────────
			// Elements whose CSS carries a `background-image: url(...)` are surfaced
			// here so the image can be swapped without leaving the panel. These are
			// DERIVED from raw_html inline styles + raw_css by the editor JS
			// (bgslot_* keys), which also applies a pick by rewriting the url() back
			// into its source — so the render path needs no special handling.
			// `render_type => none` keeps Elementor from re-rendering on its own;
			// our JS drives the live preview instead.
			for ( $i = 0; $i < self::SLOT_COUNT; $i++ ) {
				$this->add_control(
					"bgslot_{$i}_visible",
					array(
						'type'        => \Elementor\Controls_Manager::HIDDEN,
						'default'     => '',
						'render_type' => 'none',
					)
				);

				$this->add_control(
					"bgslot_{$i}_selector",
					array(
						'type'        => \Elementor\Controls_Manager::HIDDEN,
						'default'     => '',
						'render_type' => 'none',
					)
				);

				$this->add_control(
					"bgslot_{$i}_image",
					array(
						'label'       => esc_html__( 'Background Image', 'uichemy' ),
						'type'        => \Elementor\Controls_Manager::MEDIA,
						'render_type' => 'none',
						'default'     => array(
							'url' => '',
							'id'  => '',
						),
						'dynamic'     => array(
							'active' => true,
						),
						'condition'   => array(
							"bgslot_{$i}_visible" => 'yes',
						),
					)
				);
			}

			$this->end_controls_section();

			$this->start_controls_section(
				'section_style',
				array(
					'label' => esc_html__( 'Composer', 'uichemy' ),
					'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
				)
			);

			$this->add_control(
				'style_layers_edit',
				array(
					'label'       => esc_html__( 'Edit Layer', 'uichemy' ),
					'type'        => \Elementor\Controls_Manager::BUTTON,
					'button_type' => 'default',
					'text'        => esc_html__( 'Edit', 'uichemy' ),
					'event'       => 'uichemy:composer:edit_layers',
				)
			);

			$this->add_control(
				'style_code_edit',
				array(
					'label'       => esc_html__( 'Edit Code', 'uichemy' ),
					'type'        => \Elementor\Controls_Manager::BUTTON,
					'button_type' => 'default',
					'text'        => esc_html__( 'Edit', 'uichemy' ),
					'event'       => 'uichemy:composer:edit_code',
				)
			);

			$this->add_control(
				'style_chat_edit',
				array(
					'label'       => esc_html__( 'Edit With AI', 'uichemy' ),
					'type'        => \Elementor\Controls_Manager::BUTTON,
					'button_type' => 'default',
					'text'        => esc_html__( 'Chat', 'uichemy' ),
					'event'       => 'uichemy:composer:edit_chat',
				)
			);

			$this->end_controls_section();
		}

		/**
		 * Build an HTML asset tag (<link> or <script>) from a dep config array.
		 *
		 * @param array $dep Dep entry from raw_deps_* JSON.
		 * @return string HTML tag or empty string.
		 */
		private function build_asset_tag_html( $dep ) {
			// Delegates to the renderer's builder, which every scope now shares.
			// This was one of three copies of the same logic: a dep shape added in
			// one place produced an old-style tag through the others, and "Run with"
			// was exactly such a shape.
			if ( class_exists( 'UiChemy_Composer_Renderer' ) ) {
				return UiChemy_Composer_Renderer::build_asset_tag_html( $dep );
			}
			return '';
		}

		/**
		 * Build asset injection HTML (before-position or after-position) from a JSON deps string.
		 * Returns [ before_html, after_html ].
		 *
		 * @param string $raw_deps_json JSON string from widget setting.
		 * @param bool   $is_editor     Whether we are in the Elementor editor.
		 * @return array{ 0: string, 1: string }
		 */
		private function build_standard_deps_output( $raw_deps_json, $is_editor ) {
			static $injected_urls = array();

			$before = '';
			$after  = '';

			if ( empty( $raw_deps_json ) ) {
				return array( $before, $after );
			}

			$deps = json_decode( $raw_deps_json, true );
			if ( ! is_array( $deps ) ) {
				return array( $before, $after );
			}

			foreach ( $deps as $dep ) {
				if ( empty( $dep['enabled'] ) ) {
					continue;
				}

				$url_key = isset( $dep['url'] ) ? trim( (string) $dep['url'] ) : '';
				if ( '' === $url_key ) {
					continue;
				}

				// Deduplicate across multiple widgets on the same page.
				if ( isset( $injected_urls[ $url_key ] ) ) {
					continue;
				}
				$injected_urls[ $url_key ] = true;

				$tag = $this->build_asset_tag_html( $dep );
				if ( '' === $tag ) {
					continue;
				}

				$position = isset( $dep['position'] ) ? (string) $dep['position'] : 'before';

				// In editor, inject <link> tags via JS into <head> to avoid layout shifts.
				if ( $is_editor && isset( $dep['kind'] ) && 'style' === $dep['kind'] ) {
					$ver     = isset( $dep['v'] ) ? trim( (string) $dep['v'] ) : '';
					$url_raw = isset( $dep['url'] ) ? trim( (string) $dep['url'] ) : '';
					if ( '' !== $ver && '—' !== $ver ) {
						$url_raw = str_replace( '{v}', $ver, $url_raw );
					} else {
						$url_raw = str_replace( '{v}', '', $url_raw );
					}
					$url_raw  = esc_url( $url_raw );
					$url_js   = esc_js( $url_raw );
					$media_attr = '';
					$attrs    = isset( $dep['attrs'] ) && is_array( $dep['attrs'] ) ? $dep['attrs'] : array();
					if ( in_array( 'print', $attrs, true ) ) {
						$media_attr = 'print';
					} elseif ( in_array( 'all', $attrs, true ) ) {
						$media_attr = 'all';
					}
					$media_js = esc_js( $media_attr );
					$js_tag   = "<script>(function(){var u='" . $url_js . "';if(!document.querySelector('link[href=\"'+u+'\"]')){var l=document.createElement('link');l.rel='stylesheet';l.href=u;" . ( $media_attr ? "l.media='" . $media_js . "';" : '' ) . "document.head.appendChild(l);}})();</script>";
					if ( 'after' === $position ) {
						$after .= $js_tag . "\n";
					} else {
						$before .= $js_tag . "\n";
					}
					continue;
				}

				if ( 'after' === $position ) {
					$after .= $tag . "\n";
				} else {
					$before .= $tag . "\n";
				}
			}

			return array( $before, $after );
		}

		private function get_media_url_from_setting( $setting ) {
			if ( is_array( $setting ) ) {
				return trim( (string) ( $setting['url'] ?? '' ) );
			}
			return trim( (string) $setting );
		}

		/**
		 * Is this stored media value a dynamic-data token rather than a URL?
		 *
		 * Older content can carry a `{{ … }}` in the MEDIA control (the composer now
		 * refuses to put one there, but existing widgets still have it). Writing it to
		 * src emits a URL that cannot resolve, so the image is permanently missing.
		 * Callers must LEAVE THE NODE ALONE on a hit — not clear the attribute, which
		 * would blank the image just as effectively — because the token also sits in
		 * raw_html, where Twig resolves it normally.
		 *
		 * The percent-encoded form counts: a token used as an attribute value can be
		 * URL-encoded before it is stored (see Uich_Dynamic::restore_encoded_tokens()).
		 *
		 * @param string $value Stored media URL.
		 * @return bool
		 */
		private static function is_dynamic_media_token( $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				return false;
			}
			return (bool) preg_match( '/\{\{|\{%/', $value ) || (bool) preg_match( '/%7b%7b|%7b%25/i', $value );
		}

		private function get_slot_kind( $node ) {
			if ( ! $node instanceof \DOMNode ) {
				return null;
			}
			if ( XML_TEXT_NODE === $node->nodeType ) {
				return 'text';
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				return null;
			}
			$tag_name = strtolower( $node->nodeName );
			if ( 'a' === $tag_name ) {
				return 'anchor';
			}
			if ( 'img' === $tag_name ) {
				// img[data-as="svg"] was originally a <svg> tag — keep it as SVG slot.
				// Any other <img> is always an image slot, even if src points to a .svg file.
				if ( 'svg' === $node->getAttribute( 'data-as' ) ) {
					return 'svg';
				}
				return 'image';
			}
			if ( 'svg' === $tag_name ) {
				return 'svg';
			}
			return 'text';
		}

		/**
		 * Wrap every element carrying a `data-uich-link` marker in an <a>, then strip
		 * the marker attributes. The composer stores per-element links as attributes
		 * (rather than wrapping in the editor) so element paths / slot indices stay
		 * stable; the real <a> wrapper is materialised here at render time.
		 *
		 * @since 5.1.0
		 * @param \DOMDocument $dom Parsed document.
		 * @return void
		 */
		private function wrap_element_links( $dom ) {
			if ( ! $dom instanceof \DOMDocument ) {
				return;
			}
			$xpath = new \DOMXPath( $dom );
			$nodes = $xpath->query( '//*[@data-uich-link]' );
			if ( ! $nodes || 0 === $nodes->length ) {
				return;
			}
			// Snapshot to a plain array — wrapping mutates the tree, so we must not
			// iterate the live DOMNodeList while modifying it.
			$targets = array();
			foreach ( $nodes as $node ) {
				$targets[] = $node;
			}
			// True when $el has an ancestor that is ALSO a link target. Wrapping both
			// would nest <a> inside <a> (invalid); the outermost link wins, the inner
			// one is skipped. isSameNode() is used because PHP DOM hands back distinct
			// wrapper objects for the same underlying node, so === / in_array is
			// unreliable for identity.
			$has_target_ancestor = function ( $el ) use ( $targets ) {
				$p = $el->parentNode;
				while ( $p instanceof \DOMElement ) {
					foreach ( $targets as $t ) {
						if ( $t->isSameNode( $p ) ) {
							return true;
						}
					}
					$p = $p->parentNode;
				}
				return false;
			};
			foreach ( $targets as $el ) {
				if ( ! $el instanceof \DOMElement ) {
					continue;
				}
				// Nested link target: strip its markers and skip wrapping so we never
				// emit <a><a>…</a></a>.
				if ( $has_target_ancestor( $el ) ) {
					$el->removeAttribute( 'data-uich-link' );
					$el->removeAttribute( 'data-uich-link-target' );
					$el->removeAttribute( 'data-uich-link-rel' );
					continue;
				}
				$parent = $el->parentNode;
				// Skip when there is no element parent to reparent into (e.g. a
				// top-level node): wrapping there is unsafe / meaningless.
				if ( ! $parent instanceof \DOMElement ) {
					$el->removeAttribute( 'data-uich-link' );
					$el->removeAttribute( 'data-uich-link-target' );
					$el->removeAttribute( 'data-uich-link-rel' );
					continue;
				}
				$url    = trim( (string) $el->getAttribute( 'data-uich-link' ) );
				$target = (string) $el->getAttribute( 'data-uich-link-target' );
				$rel_in = (string) $el->getAttribute( 'data-uich-link-rel' );
				// Always strip the markers, even if the URL is empty/invalid.
				$el->removeAttribute( 'data-uich-link' );
				$el->removeAttribute( 'data-uich-link-target' );
				$el->removeAttribute( 'data-uich-link-rel' );
				$safe_url = esc_url( $url );
				if ( '' === $safe_url ) {
					continue;
				}
				$anchor = $dom->createElement( 'a' );
				$anchor->setAttribute( 'href', $safe_url );
				// Live-page editor only: mark the wrapper so the layer-path stamper
				// (layer_path_stamp_script) can step over it. This <a> exists only
				// in the rendered page, not in raw_html, so counting it would shift
				// the path of everything inside and after it.
				if ( class_exists( 'UiChemy_Composer_Enqueue' ) && UiChemy_Composer_Enqueue::$frontend_editor_active ) {
					$anchor->setAttribute( 'data-uich-link-wrap', '1' );
				}
				$rel = array();
				if ( '_blank' === $target ) {
					$anchor->setAttribute( 'target', '_blank' );
					$rel[] = 'noopener';
				}
				if ( false !== strpos( $rel_in, 'nofollow' ) ) {
					$rel[] = 'nofollow';
				}
				if ( $rel ) {
					$anchor->setAttribute( 'rel', implode( ' ', array_unique( $rel ) ) );
				}
				$parent->replaceChild( $anchor, $el );
				$anchor->appendChild( $el );
			}
		}

		private function get_text_nodes( $node, $skip_own_text = false ) {
			$text_nodes  = array();
			$inline_tags = array( 'a', 'span', 'strong', 'em', 'b', 'i', 'u', 'label', 'button' );
			$ignore_tags = array( 'style', 'script', 'noscript', 'template' );

			foreach ( $node->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					// $skip_own_text: this text belongs to a wrapping <a> (its Link
					// Text), so it must not become a standalone text slot.
					if ( $skip_own_text ) {
						continue;
					}
					$val = trim( $child->nodeValue );
					if ( ! empty( $val ) ) {
						$text_nodes[] = $child;
					}
				} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
					$tag_name = strtolower( $child->nodeName );
					// Skip <uichemy-*> custom elements and their entire subtree.
					// Their inner template tokens (e.g. {nav_item}) are rendered
					// server-side by the PHP extraction layer and must never be
					// treated as editable text slots.
					if ( strncmp( $tag_name, 'uichemy-', 8 ) === 0 ) {
						continue;
					}
					if ( in_array( $tag_name, $ignore_tags, true ) ) {
						continue;
					}
					if ( in_array( $tag_name, array( 'img', 'svg' ), true ) ) {
						$text_nodes[] = $child;
					} elseif ( 'a' === $tag_name && $this->anchor_wraps_slot_content( $child ) ) {
						// <a> wrapping media or block content (e.g.
						// <a>txt<h1>..</h1></a>, <a><img></a>): the anchor is one
						// slot — its URL plus its own direct text as the Link Text —
						// and its children are recursed into separate slots
						// (heading text, nested media, …). The anchor's own text
						// must NOT spawn a standalone text slot, so recurse with
						// $skip_own_text. Mirrors the JS extractSlotTextNodes.
						$text_nodes[] = $child;
						$text_nodes   = array_merge( $text_nodes, $this->get_text_nodes( $child, true ) );
					} elseif ( in_array( $tag_name, $inline_tags, true ) ) {
						// Other inline tags (span, button, …) are a single text
						// slot, unless one wraps an <img>/<svg> — then expose media.
						$has_media = ( $child->getElementsByTagName( 'img' )->length > 0 )
							|| ( $child->getElementsByTagName( 'svg' )->length > 0 );
						if ( $has_media || $this->inline_wraps_block_content( $child ) ) {
							$child_text_nodes = $this->get_text_nodes( $child );
							$text_nodes       = array_merge( $text_nodes, $child_text_nodes );
						} else {
							$inline_text = trim( (string) $child->textContent );
							if ( '' !== $inline_text ) {
								$text_nodes[] = $child;
							}
						}
					} else {
						$child_text_nodes = $this->get_text_nodes( $child );
						$text_nodes       = array_merge( $text_nodes, $child_text_nodes );
					}
				}
			}

			return $text_nodes;
		}

		/**
		 * True when an <a> wraps content that becomes its own slots — nested
		 * <img>/<svg> media, or block elements like <h1>/<div>/<p> carrying text.
		 * Mirrors the JS anchorWrapsSlotContent helper.
		 */
		private function anchor_wraps_slot_content( $node ) {
			if ( ! $node instanceof \DOMElement || 'a' !== strtolower( $node->nodeName ) ) {
				return false;
			}
			$inline_tags = array( 'a', 'span', 'strong', 'em', 'b', 'i', 'u', 'label', 'button' );
			$ignore_tags = array( 'style', 'script', 'noscript', 'template' );
			// Void / empty-content elements never count as block content (e.g. <br>).
			$void_tags = array( 'br', 'wbr', 'hr', 'area', 'base', 'col', 'embed', 'input', 'link', 'meta', 'param', 'source', 'track' );
			foreach ( $node->getElementsByTagName( '*' ) as $el ) {
				$t = strtolower( $el->nodeName );
				if ( strncmp( $t, 'uichemy-', 8 ) === 0 || in_array( $t, $ignore_tags, true ) ) {
					continue;
				}
				if ( in_array( $t, array( 'img', 'svg' ), true ) ) {
					return true; // nested media
				}
				// A block-level element (h1/div/p/…) breaks out — based on
				// STRUCTURE, not text, so emptying its text never collapses the
				// anchor back to a plain text slot (which would wipe the block).
				if ( ! in_array( $t, $inline_tags, true ) && ! in_array( $t, $void_tags, true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * True when a NON-anchor inline slot element (button/label/span/…) wraps
		 * content that must become its own slots — nested <img>/<svg> media, or
		 * block elements like <div>/<h1>/<p>. Mirrors the JS inlineWrapsBlockContent
		 * so editor and published render agree on slot extraction.
		 */
		private function inline_wraps_block_content( $node ) {
			if ( ! $node instanceof \DOMElement ) {
				return false;
			}
			$inline_tags = array( 'a', 'span', 'strong', 'em', 'b', 'i', 'u', 'label', 'button' );
			$ignore_tags = array( 'style', 'script', 'noscript', 'template' );
			$void_tags   = array( 'br', 'wbr', 'hr', 'area', 'base', 'col', 'embed', 'input', 'link', 'meta', 'param', 'source', 'track' );
			foreach ( $node->getElementsByTagName( '*' ) as $el ) {
				$t = strtolower( $el->nodeName );
				if ( strncmp( $t, 'uichemy-', 8 ) === 0 || in_array( $t, $ignore_tags, true ) ) {
					continue;
				}
				if ( in_array( $t, array( 'img', 'svg' ), true ) ) {
					return true; // nested media
				}
				if ( ! in_array( $t, $inline_tags, true ) && ! in_array( $t, $void_tags, true ) ) {
					return true; // block content
				}
			}
			return false;
		}

		/**
		 * True when the element has at least one DIRECT element child (e.g. a
		 * <button> wrapping a badge <span>). Such slots must never be written via
		 * a raw nodeValue assignment — that replaces ALL children with one text
		 * node, silently destroying the nested elements. Mirrors the JS
		 * elementHasElementChildren.
		 *
		 * @param \DOMNode $node Candidate slot node.
		 * @return bool
		 */
		private function element_has_element_children( $node ) {
			if ( ! $node instanceof \DOMElement ) {
				return false;
			}
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whitespace-normalize a slot text value for comparison: collapse runs of
		 * whitespace to a single space and trim. Used to detect stale pre-split
		 * slot values that still contain the FULL textContent (own text + nested
		 * children's text) of an element.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		private function normalize_slot_text_ws( $text ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		}

		/**
		 * Set an anchor's text (Link Text) without removing element children
		 * (nested media/blocks). Mirrors the JS setAnchorTextPreservingChildren.
		 */
		private function set_anchor_text_preserving_children( $node, $text ) {
			if ( ! $node instanceof \DOMElement ) {
				return;
			}
			$safe = (string) $text;
			// Collect the anchor's DIRECT text-node children.
			$text_children = array();
			foreach ( $node->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$text_children[] = $child;
				}
			}
			if ( '' === trim( $safe ) ) {
				// Empty link text — drop all stray text nodes, keep media/blocks.
				foreach ( $text_children as $child ) {
					$node->removeChild( $child );
				}
				return;
			}
			if ( empty( $text_children ) ) {
				$text_node = $node->ownerDocument->createTextNode( '' );
				$node->insertBefore( $text_node, $node->firstChild );
				// DOMDocument escapes &, <, > on saveHTML(), so assign the raw
				// value here — pre-escaping with htmlspecialchars() double-encodes
				// (e.g. "&" would render as "&amp;").
				$text_node->nodeValue = $safe;
				return;
			}
			// Consolidate to exactly ONE text node: set the first (preserves its
			// position relative to media/block) to the full value and drop the
			// rest, so read/write stay in sync and Link Text spacing is preserved.
			// Raw value — DOMDocument escapes on output; pre-escaping double-encodes.
			$text_children[0]->nodeValue = $safe;
			for ( $i = 1; $i < count( $text_children ); $i++ ) {
				$node->removeChild( $text_children[ $i ] );
			}
		}

		/**
		 * Set a NON-anchor inline slot element's own text (button/span/label/…)
		 * without removing its element children. The consolidation rules are
		 * identical to the anchor Link Text writer — the logic is tag-agnostic
		 * (it only touches DIRECT text-node children) — so delegate to it.
		 * Mirrors the JS setElementTextPreservingChildren.
		 */
		private function set_element_text_preserving_children( $node, $text ) {
			$this->set_anchor_text_preserving_children( $node, $text );
		}

		private function apply_slot_settings_to_node( $node, $settings, $slot_index ) {
			if ( ! $node instanceof \DOMNode ) {
				return;
			}

			$kind = $this->get_slot_kind( $node );

			if ( 'image' === $kind && $node instanceof \DOMElement ) {
				$is_image = isset( $settings[ "slot_{$slot_index}_is_image" ] ) ? $settings[ "slot_{$slot_index}_is_image" ] : 'no';
				if ( 'yes' !== $is_image ) {
					return;
				}
				$image_setting = isset( $settings[ "slot_{$slot_index}_image" ] ) ? $settings[ "slot_{$slot_index}_image" ] : array();
				$url           = $this->get_media_url_from_setting( $image_setting );
				// Stored token, not a URL — leave the authored markup (and its own
				// token, which Twig resolves) untouched. Clearing src here would blank
				// the image just as surely as writing the token would.
				if ( self::is_dynamic_media_token( $url ) ) {
					return;
				}
				// Raw value — DOMDocument escapes attribute values on saveHTML().
				// Pre-escaping with htmlspecialchars() double-encodes: a URL query
				// "?w=1&h=2" would ship as "?w=1&amp;amp;h=2" (wrong resource), and
				// an alt of "Bob's" as "Bob&#039;s" (literal entity to a screen
				// reader). Same rule the text-node writers already follow above.
				if ( '' !== $url ) {
					$node->setAttribute( 'src', $url );
				} else {
					$node->removeAttribute( 'src' );
				}
				$alt = isset( $settings[ "slot_{$slot_index}_image_alt" ] ) ? trim( (string) $settings[ "slot_{$slot_index}_image_alt" ] ) : '';
				if ( '' !== $alt ) {
					$node->setAttribute( 'alt', $alt );
				} else {
					$node->removeAttribute( 'alt' );
				}
				return;
			}

			if ( 'svg' === $kind && $node instanceof \DOMElement ) {
				$is_svg = isset( $settings[ "slot_{$slot_index}_is_svg" ] ) ? $settings[ "slot_{$slot_index}_is_svg" ] : 'no';
				if ( 'yes' !== $is_svg ) {
					return;
				}
				$svg_mode = isset( $settings[ "slot_{$slot_index}_svg_mode" ] ) ? (string) $settings[ "slot_{$slot_index}_svg_mode" ] : 'code';
				if ( 'url' === $svg_mode ) {
					$url_setting = isset( $settings[ "slot_{$slot_index}_svg_url" ] ) ? $settings[ "slot_{$slot_index}_svg_url" ] : array();
					$url         = $this->get_media_url_from_setting( $url_setting );
					// Same rule as the image slot: a stored token is not a URL.
					if ( self::is_dynamic_media_token( $url ) ) {
						return;
					}
					if ( '' === $url ) {
						$tag_name_check = strtolower( $node->nodeName );
						if ( 'svg' === $tag_name_check ) {
							$node->removeAttribute( 'data-uc-svg-source' );
							while ( $node->firstChild ) {
								$node->removeChild( $node->firstChild );
							}
						} elseif ( 'img' === $tag_name_check ) {
							$node->removeAttribute( 'src' );
						}
						return;
					}
					$tag_name = strtolower( $node->nodeName );
					if ( 'img' === $tag_name ) {
						// Raw — saveHTML() escapes; pre-escaping double-encodes.
						$node->setAttribute( 'src', $url );
						return;
					}
					if ( 'svg' !== $tag_name ) {
						return;
					}
					$node->setAttribute( 'data-uc-svg-source', $url );
					while ( $node->firstChild ) {
						$node->removeChild( $node->firstChild );
					}
					$doc        = $node->ownerDocument;
					$image_node = $doc->createElementNS( 'http://www.w3.org/2000/svg', 'image' );
					$image_node->setAttribute( 'href', $url );
					$image_node->setAttributeNS( 'http://www.w3.org/1999/xlink', 'xlink:href', $url );
					$image_node->setAttribute( 'width', '100%' );
					$image_node->setAttribute( 'height', '100%' );
					$image_node->setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
					$node->appendChild( $image_node );
					return;
				}

				$svg_code = isset( $settings[ "slot_{$slot_index}_svg_code" ] ) ? trim( (string) $settings[ "slot_{$slot_index}_svg_code" ] ) : '';
				if ( '' === $svg_code || ! preg_match( '/^\s*<svg\b/i', $svg_code ) ) {
					$code_media_setting = isset( $settings[ "slot_{$slot_index}_svg_code_media" ] ) ? $settings[ "slot_{$slot_index}_svg_code_media" ] : array();
					$code_media_url     = $this->get_media_url_from_setting( $code_media_setting );
					if ( '' === $code_media_url || self::is_dynamic_media_token( $code_media_url ) ) {
						return;
					}
					$tag_name = strtolower( $node->nodeName );
					if ( 'img' === $tag_name ) {
						// Raw — saveHTML() escapes; pre-escaping double-encodes.
						$node->setAttribute( 'src', $code_media_url );
						return;
					}
					if ( 'svg' !== $tag_name ) {
						return;
					}
					$node->setAttribute( 'data-uc-svg-source', $code_media_url );
					while ( $node->firstChild ) {
						$node->removeChild( $node->firstChild );
					}
					$doc        = $node->ownerDocument;
					$image_node = $doc->createElementNS( 'http://www.w3.org/2000/svg', 'image' );
					$image_node->setAttribute( 'href', $code_media_url );
					$image_node->setAttributeNS( 'http://www.w3.org/1999/xlink', 'xlink:href', $code_media_url );
					$image_node->setAttribute( 'width', '100%' );
					$image_node->setAttribute( 'height', '100%' );
					$image_node->setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
					$node->appendChild( $image_node );
					return;
				}
				$parsed_dom = new \DOMDocument();
				libxml_use_internal_errors( true );
				$parsed_dom->loadHTML( '<?xml encoding="utf-8" ?>' . $svg_code, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
				libxml_clear_errors();
				$parsed_svg = $parsed_dom->getElementsByTagName( 'svg' )->item( 0 );
				if ( ! $parsed_svg instanceof \DOMElement || ! $node->parentNode instanceof \DOMNode ) {
					return;
				}
				$imported = $node->ownerDocument->importNode( $parsed_svg, true );
				$node->parentNode->replaceChild( $imported, $node );
				return;
			}

			// Link-wrapper anchor (wraps media/blocks): its text content (Link
			// Text) must be written without wiping the nested children — handled
			// below via set_anchor_text_preserving_children, not a raw nodeValue
			// write which would destroy the nested media/heading.
			$anchor_wraps_slots = ( 'anchor' === $kind && $this->anchor_wraps_slot_content( $node ) );

			$is_link  = isset( $settings[ "slot_{$slot_index}_is_link" ] ) && 'yes' === $settings[ "slot_{$slot_index}_is_link" ];
			$slot_val = '';
			if ( $is_link ) {
				$link_text_val = isset( $settings[ "slot_{$slot_index}_link_text" ] ) ? trim( $settings[ "slot_{$slot_index}_link_text" ] ) : '';
				// Fallback to slot_{i} for any existing saved data before the split.
				$slot_val = '' !== $link_text_val ? $link_text_val : ( isset( $settings[ "slot_{$slot_index}" ] ) ? trim( $settings[ "slot_{$slot_index}" ] ) : '' );
			} else {
				$slot_val = isset( $settings[ "slot_{$slot_index}" ] ) ? trim( $settings[ "slot_{$slot_index}" ] ) : '';
			}
			if ( $anchor_wraps_slots ) {
				// Link Text is the SOLE source for a link-wrapper anchor's own text —
				// no slot_{i} fallback, so clearing it removes the text. Nested
				// children (heading/media) are preserved. Applied only once the editor
				// has synced this slot (is_link = yes), which is also where link_text
				// is seeded: every control is returned with its default, so an empty
				// value alone cannot mean "the user cleared it".
				if ( $is_link ) {
					$this->set_anchor_text_preserving_children( $node, (string) ( isset( $settings[ "slot_{$slot_index}_link_text" ] ) ? $settings[ "slot_{$slot_index}_link_text" ] : '' ) );
				}
			} elseif ( '' !== $slot_val ) {
				// Assign the raw value: DOMDocument escapes &, <, > when the tree
				// is serialized (saveHTML). Pre-escaping with htmlspecialchars()
				// here would double-encode, e.g. "hello &" -> "hello &amp;".
				if ( XML_TEXT_NODE === $node->nodeType ) {
					$original = (string) $node->nodeValue;
					$lead     = ( preg_match( '/^\s+/', $original, $m_lead ) ) ? $m_lead[0] : '';
					$trail    = ( preg_match( '/\s+$/', $original, $m_trail ) ) ? $m_trail[0] : '';
					$node->nodeValue = $lead . $slot_val . $trail;
				} elseif ( XML_ELEMENT_NODE === $node->nodeType ) {
					if ( $this->element_has_element_children( $node ) ) {
						// Inline slot wrapping nested elements (e.g. <button>Yearly
						// <span class="badge">2 months free</span></button>): the slot
						// value is the element's OWN text only (mirrors the JS
						// getElementOwnText), so write it via the children-preserving
						// path — a raw nodeValue assignment would replace ALL children
						// with one text node and destroy the nested markup (this is
						// what collapsed absolutely-positioned badges on render).
						//
						// Stale-value guard: slot values saved BEFORE the own-text
						// split contain the element's full textContent (own text +
						// children's text). Writing such a value as own text would
						// duplicate the children's words. If the value matches the
						// node's current full textContent (whitespace-normalized),
						// the DOM already shows exactly that content — skip the write.
						$is_stale_full_text = $this->normalize_slot_text_ws( $slot_val )
							=== $this->normalize_slot_text_ws( (string) $node->textContent );
						if ( ! $is_stale_full_text ) {
							$this->set_element_text_preserving_children( $node, $slot_val );
						}
					} else {
						$node->nodeValue = $slot_val;
					}
				}
			}

			if ( 'anchor' === $kind && $node instanceof \DOMElement ) {
				$link_setting = isset( $settings[ "slot_{$slot_index}_link" ] ) ? $settings[ "slot_{$slot_index}_link" ] : array();
				$url          = isset( $link_setting['url'] ) ? trim( $link_setting['url'] ) : '';
				if ( ! empty( $url ) ) {
					// Raw — saveHTML() escapes; pre-escaping double-encodes, which
					// corrupts any link carrying a query string (?a=1&b=2).
					$node->setAttribute( 'href', $url );
				}
				// As with Link Text: the link control only owns target once the slot
				// has been synced. Until then the authored attributes stand.
				if ( ! empty( $link_setting['is_external'] ) ) {
					$node->setAttribute( 'target', '_blank' );
				} elseif ( $is_link ) {
					$node->removeAttribute( 'target' );
				}
				if ( ! empty( $link_setting['nofollow'] ) ) {
					$node->setAttribute( 'rel', 'nofollow' );
				}
				if ( ! empty( $link_setting['custom_attributes'] ) ) {
					$custom_attributes = preg_split( '/((\r?\n)|\|\||\|)/', $link_setting['custom_attributes'] );
					foreach ( $custom_attributes as $attr ) {
						$attr = explode( '|', trim( $attr ), 2 );
						if ( isset( $attr[0], $attr[1] ) ) {
							$node->setAttribute( trim( $attr[0] ), trim( $attr[1] ) );
						}
					}
				}
			}
		}

		/**
		 * SVG attributes whose spec name is camelCase, keyed by their lowercased form.
		 * Excludes names that are kebab-case in SVG and only camelCase in React
		 * (stop-color, clip-path) — "fixing" those would break them.
		 *
		 * @return array<string,string>
		 */
		private static function svg_camelcase_attributes() {
			static $map = null;
			if ( null !== $map ) {
				return $map;
			}
			$names = array(
				'viewBox',
				'preserveAspectRatio',
				'patternUnits',
				'patternContentUnits',
				'patternTransform',
				'gradientUnits',
				'gradientTransform',
				'spreadMethod',
				'clipPathUnits',
				'maskUnits',
				'maskContentUnits',
				'markerWidth',
				'markerHeight',
				'markerUnits',
				'refX',
				'refY',
				'stdDeviation',
				'textLength',
				'lengthAdjust',
				'startOffset',
				'pathLength',
				'baseFrequency',
				'numOctaves',
				'filterUnits',
				'primitiveUnits',
				'kernelMatrix',
				'kernelUnitLength',
				'preserveAlpha',
				'edgeMode',
				'targetX',
				'targetY',
				'tableValues',
				'xChannelSelector',
				'yChannelSelector',
				'diffuseConstant',
				'specularConstant',
				'specularExponent',
				'surfaceScale',
				'limitingConeAngle',
				'pointsAtX',
				'pointsAtY',
				'pointsAtZ',
				'systemLanguage',
				'requiredExtensions',
				'requiredFeatures',
				'attributeName',
				'attributeType',
				'calcMode',
				'keyTimes',
				'keySplines',
				'keyPoints',
				'repeatCount',
				'repeatDur',
				'baseProfile',
				'zoomAndPan',
			);
			$map = array();
			foreach ( $names as $name ) {
				$map[ strtolower( $name ) ] = $name;
			}
			return $map;
		}

		/**
		 * Restore camelCase SVG attribute names after DOMDocument serialization, which
		 * lowercases them. Only text inside an <svg> … </svg> region is rewritten.
		 *
		 * @param string $html Serialized markup.
		 * @return string
		 */
		public static function restore_svg_attribute_case( $html ) {
			$text = (string) $html;
			if ( '' === $text || false === stripos( $text, '<svg' ) ) {
				return $text;
			}

			$map = self::svg_camelcase_attributes();

			$restored = preg_replace_callback(
				'#<svg\b[^>]*>.*?</svg\s*>#is',
				static function ( $region ) use ( $map ) {
					return preg_replace_callback(
						'/(\s)([A-Za-z][A-Za-z0-9_-]*)(\s*=)/',
						static function ( $attr ) use ( $map ) {
							$lower = strtolower( $attr[2] );
							return isset( $map[ $lower ] )
								? $attr[1] . $map[ $lower ] . $attr[3]
								: $attr[0];
						},
						$region[0]
					);
				},
				$text
			);

			// preg failure returns null; keep the original rather than emitting nothing.
			return ( null === $restored ) ? $text : $restored;
		}

		private function scope_css_to_widget( $raw_css, $widget_scope_selector ) {
			$css   = trim( (string) $raw_css );
			$scope = trim( (string) $widget_scope_selector );
			if ( '' === $css || '' === $scope ) {
				return $css;
			}

			$scoped = $this->scope_css_block_to_widget( $css, $scope );
			$scoped = $this->reorder_responsive_media_queries_to_end( $scoped );

			// Entrance animations must not start at opacity 0, or Chrome records no LCP.
			return class_exists( 'UiChemy_Fast_Load' ) ? UiChemy_Fast_Load::lcp_safe_keyframes( $scoped ) : $scoped;
		}

		/**
		 * Sort top-level @media rules to the END of the scoped CSS so they take precedence over base
		 * rules at the same specificity. Without this, a desktop/base rule written after a @media rule
		 * in raw_css collapses to identical specificity once scoped and wins the cascade by source
		 * order — defeating the breakpoint override on small viewports.
		 */
		private function reorder_responsive_media_queries_to_end( $css ) {
			$text = (string) $css;
			if ( '' === trim( $text ) || false === strpos( $text, '@media' ) ) {
				return $text;
			}

			$blocks = array();
			$offset = 0;
			$len    = strlen( $text );

			while ( $offset < $len ) {
				$pre_start = $offset;
				while ( $offset < $len ) {
					$c = $text[ $offset ];
					if ( ctype_space( $c ) ) {
						$offset++;
						continue;
					}
					if ( '/' === $c && $offset + 1 < $len && '*' === $text[ $offset + 1 ] ) {
						$end = strpos( $text, '*/', $offset + 2 );
						if ( false === $end ) {
							$offset = $len;
							break;
						}
						$offset = $end + 2;
						continue;
					}
					break;
				}
				$prelude = substr( $text, $pre_start, $offset - $pre_start );
				if ( $offset >= $len ) {
					if ( '' !== $prelude ) {
						$blocks[] = array(
							'type' => 'rule',
							'text' => $prelude,
							'max'  => 0.0,
							'min'  => 0.0,
						);
					}
					break;
				}

				$header_start = $offset;
				$quote        = '';
				while ( $offset < $len ) {
					$c = $text[ $offset ];
					$n = $offset + 1 < $len ? $text[ $offset + 1 ] : '';
					if ( '' !== $quote ) {
						if ( '\\' === $c ) {
							$offset += 2;
							continue;
						}
						if ( $c === $quote ) {
							$quote = '';
						}
						$offset++;
						continue;
					}
					if ( '"' === $c || "'" === $c ) {
						$quote = $c;
						$offset++;
						continue;
					}
					if ( '/' === $c && '*' === $n ) {
						$end = strpos( $text, '*/', $offset + 2 );
						if ( false === $end ) {
							$offset = $len;
							break;
						}
						$offset = $end + 2;
						continue;
					}
					if ( '{' === $c || ';' === $c ) {
						break;
					}
					$offset++;
				}
				if ( $offset >= $len ) {
					$blocks[] = array(
						'type' => 'rule',
						'text' => $prelude . substr( $text, $header_start ),
						'max'  => 0.0,
						'min'  => 0.0,
					);
					break;
				}
				if ( ';' === $text[ $offset ] ) {
					$offset++;
					$blocks[] = array(
						'type' => 'rule',
						'text' => $prelude . substr( $text, $header_start, $offset - $header_start ),
						'max'  => 0.0,
						'min'  => 0.0,
					);
					continue;
				}

				$header     = trim( substr( $text, $header_start, $offset - $header_start ) );
				$depth      = 1;
				$body_quote = '';
				$offset++;
				while ( $offset < $len && $depth > 0 ) {
					$c = $text[ $offset ];
					$n = $offset + 1 < $len ? $text[ $offset + 1 ] : '';
					if ( '' !== $body_quote ) {
						if ( '\\' === $c ) {
							$offset += 2;
							continue;
						}
						if ( $c === $body_quote ) {
							$body_quote = '';
						}
						$offset++;
						continue;
					}
					if ( '"' === $c || "'" === $c ) {
						$body_quote = $c;
						$offset++;
						continue;
					}
					if ( '/' === $c && '*' === $n ) {
						$end = strpos( $text, '*/', $offset + 2 );
						if ( false === $end ) {
							$offset = $len;
							break;
						}
						$offset = $end + 2;
						continue;
					}
					if ( '{' === $c ) {
						$depth++;
					} elseif ( '}' === $c ) {
						$depth--;
					}
					$offset++;
				}
				$block_text = $prelude . substr( $text, $header_start, $offset - $header_start );

				if ( preg_match( '/^\s*@media\b/i', $header ) ) {
					$media_text = preg_replace( '/^\s*@media\s+/i', '', $header );
					$max_w      = PHP_INT_MAX;
					$min_w      = 0.0;
					if ( preg_match( '/max-width\s*:\s*([\d.]+)\s*px/i', $media_text, $m ) ) {
						$max_w = (float) $m[1];
					}
					if ( preg_match( '/min-width\s*:\s*([\d.]+)\s*px/i', $media_text, $m ) ) {
						$min_w = (float) $m[1];
					}
					$blocks[] = array(
						'type' => 'media',
						'text' => $block_text,
						'max'  => $max_w,
						'min'  => $min_w,
						'idx'  => count( $blocks ),
					);
				} else {
					$blocks[] = array(
						'type' => 'rule',
						'text' => $block_text,
						'max'  => 0.0,
						'min'  => 0.0,
					);
				}
			}

			$base_blocks  = array();
			$media_blocks = array();
			foreach ( $blocks as $b ) {
				if ( 'media' === $b['type'] ) {
					$media_blocks[] = $b;
				} else {
					$base_blocks[] = $b;
				}
			}
			usort(
				$media_blocks,
				function ( $a, $b ) {
					if ( $a['max'] !== $b['max'] ) {
						return ( $b['max'] - $a['max'] ) > 0 ? 1 : -1;
					}
					if ( $a['min'] !== $b['min'] ) {
						return ( $a['min'] - $b['min'] ) > 0 ? 1 : -1;
					}
					return ( isset( $a['idx'] ) ? $a['idx'] : 0 ) - ( isset( $b['idx'] ) ? $b['idx'] : 0 );
				}
			);

			$out = '';
			foreach ( $base_blocks as $b ) {
				$out .= $b['text'];
			}
			foreach ( $media_blocks as $b ) {
				$out .= $b['text'];
			}
			return $out;
		}

		private function scope_css_block_to_widget( $css, $scope, $base_scope = null ) {
			$css    = (string) $css;
			$output = '';
			$offset = 0;
			$length = strlen( $css );
			$base   = null === $base_scope ? $scope : (string) $base_scope;

			while ( $offset < $length ) {
				$open = $this->find_next_css_open_brace( $css, $offset );
				if ( false === $open ) {
					$output .= substr( $css, $offset );
					break;
				}

				$close = $this->find_matching_css_brace( $css, $open );
				if ( false === $close ) {
					$output .= substr( $css, $offset );
					break;
				}

				$prelude = substr( $css, $offset, $open - $offset );
				$body    = substr( $css, $open + 1, $close - $open - 1 );

				$at_rule_prelude = $prelude;
				$is_at_rule      = (bool) preg_match( '/^\s*@([a-z-]+)/i', $prelude, $matches );
				if ( ! $is_at_rule && preg_match( '/@(media|supports|container|layer|scope|document)\b/i', $prelude, $embedded ) ) {
					// Strip leading garbage (e.g. stale `.elementor-element-x @media (...)` prefix).
					$at_pos          = strpos( $prelude, $embedded[0] );
					$at_rule_prelude = false === $at_pos ? $prelude : substr( $prelude, $at_pos );
					$is_at_rule      = (bool) preg_match( '/^\s*@([a-z-]+)/i', $at_rule_prelude, $matches );
				}
				if ( $is_at_rule ) {
					$at_rule = strtolower( $matches[1] );
					if ( in_array( $at_rule, array( 'media', 'supports', 'container', 'layer', 'scope', 'document' ), true ) ) {
						// Bump specificity inside @media by doubling the base scope. Keeps breakpoint
						// rules winning over higher-specificity base selectors that authored complex
						// chains like `.scope .a .b img { width: 100% }`.
						$inner_scope = 'media' === $at_rule ? $base . $base : $scope;
						$body        = $this->scope_css_block_to_widget( $body, $inner_scope, $base );
					}
					$output .= $at_rule_prelude . '{' . $body . '}';
				} else {
					$scoped_prelude = $this->prefix_css_selector_group( $prelude, $scope, $base );
					$output        .= ( '' === $scoped_prelude ? $prelude : $scoped_prelude ) . '{' . $body . '}';
				}

				$offset = $close + 1;
			}

			return $output;
		}

		private function prefix_css_selector_group( $selector_group, $scope, $base_scope = null ) {
			if ( preg_match( '/^\s*@/', (string) $selector_group ) ) {
				return '';
			}
			$base = null === $base_scope ? $scope : (string) $base_scope;

			$leading_group = '';
			if ( preg_match( '/^(\s*(?:\/\*.*?\*\/\s*)*)(.*)$/s', (string) $selector_group, $group_matches ) ) {
				$leading_group  = isset( $group_matches[1] ) ? $group_matches[1] : '';
				$selector_group = isset( $group_matches[2] ) ? $group_matches[2] : $selector_group;
			}

			$parts  = explode( ',', (string) $selector_group );
			$scoped = array();
			foreach ( $parts as $part ) {
				$selector_leading = '';
				$selector         = (string) $part;
				if ( preg_match( '/^(\s*(?:\/\*.*?\*\/\s*)*)(.*)$/s', $selector, $selector_matches ) ) {
					$selector_leading = isset( $selector_matches[1] ) ? $selector_matches[1] : '';
					$selector         = isset( $selector_matches[2] ) ? $selector_matches[2] : $selector;
				}
				$selector = trim( $selector );
				if ( '' === $selector ) {
					continue;
				}

				$selector = str_ireplace( '{{WRAPPER}}', $scope, $selector );
				$selector = preg_replace(
					'/(^|[\s>+~,(])selector(?=$|[\s>+~#.:,\[])/i',
					'$1' . $scope,
					$selector
				);
				$selector = trim( (string) $selector );
				if ( '' === $selector ) {
					continue;
				}

				$selector_lower = strtolower( $selector );
				if ( 'from' === $selector_lower || 'to' === $selector_lower || preg_match( '/^\d+%$/', $selector ) ) {
					$scoped[] = $selector_leading . $selector;
					continue;
				}
				if ( ':root' === $selector ) {
					$scoped[] = $selector_leading . $scope;
					continue;
				}
				if ( 0 === strpos( $selector, $base ) ) {
					// Already prefixed with the base scope — replace the base with the active (possibly
					// boosted) scope so @media bodies still pick up the doubled-scope specificity.
					$tail     = substr( $selector, strlen( $base ) );
					$scoped[] = $selector_leading . $scope . $tail;
					continue;
				}
				$scoped[] = $selector_leading . $scope . ' ' . $selector;
			}

			return empty( $scoped ) ? '' : $leading_group . implode( ', ', $scoped );
		}

		private function find_next_css_open_brace( $css, $offset ) {
			$length = strlen( $css );
			$quote  = '';

			for ( $i = (int) $offset; $i < $length; $i++ ) {
				$char = $css[ $i ];
				$next = $i + 1 < $length ? $css[ $i + 1 ] : '';

				if ( '' !== $quote ) {
					if ( '\\' === $char ) {
						$i++;
						continue;
					}
					if ( $char === $quote ) {
						$quote = '';
					}
					continue;
				}

				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
					continue;
				}

				if ( '/' === $char && '*' === $next ) {
					$end = strpos( $css, '*/', $i + 2 );
					if ( false === $end ) {
						return false;
					}
					$i = $end + 1;
					continue;
				}

				if ( '{' === $char ) {
					return $i;
				}
			}

			return false;
		}

		private function find_matching_css_brace( $css, $open_index ) {
			$length = strlen( $css );
			$depth  = 0;
			$quote  = '';

			for ( $i = (int) $open_index; $i < $length; $i++ ) {
				$char = $css[ $i ];
				$next = $i + 1 < $length ? $css[ $i + 1 ] : '';

				if ( '' !== $quote ) {
					if ( '\\' === $char ) {
						$i++;
						continue;
					}
					if ( $char === $quote ) {
						$quote = '';
					}
					continue;
				}

				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
					continue;
				}

				if ( '/' === $char && '*' === $next ) {
					$end = strpos( $css, '*/', $i + 2 );
					if ( false === $end ) {
						return false;
					}
					$i = $end + 1;
					continue;
				}

				if ( '{' === $char ) {
					$depth++;
					continue;
				}

				if ( '}' === $char ) {
					$depth--;
					if ( 0 === $depth ) {
						return $i;
					}
				}
			}

			return false;
		}

		/**
		 * Extract <uichemy:*> dynamic tags from HTML before DOMDocument processing.
		 * Replaces each tag with an HTML comment placeholder and returns the
		 * modified HTML plus a map of placeholder index → tag info.
		 *
		 * @param string $html Raw HTML string.
		 * @return array{ 0: string, 1: array } [ modified_html, tag_map ]
		 */
		private function extract_dynamic_tags( $html ) {
			$tag_map  = array();
			$index    = 0;
			$last_pos = 0;

			// Captures: $m[1]=type, $m[2]=attrs, $m[3]=inner content (empty for self-closing tags).
			$html = preg_replace_callback(
				'/<uichemy-([a-z0-9_-]+)((?:\s[^>]*)?)\s*(?:\/>\s*|>([\s\S]*?)<\/uichemy-\1>)/is',
				function ( $m ) use ( &$tag_map, &$index, &$last_pos, $html ) {
					$type    = strtolower( $m[1] );
					$attrs   = trim( $m[2] );
					$content = isset( $m[3] ) ? $m[3] : '';

					// Dynamically find preceding HTML to check if we are wrapped inside an open <nav> tag.
					$pos = strpos( $html, $m[0], $last_pos );
					if ( false === $pos ) {
						$pos = $last_pos;
					}
					$preceding_html = substr( $html, 0, $pos );
					$last_pos       = $pos + strlen( $m[0] );

					$is_wrapped_in_nav    = false;
					$is_wrapped_in_anchor = false;
					if ( '' !== $preceding_html ) {
						$nav_open_count  = preg_match_all( '/<nav\b/i', $preceding_html );
						$nav_close_count = preg_match_all( '/<\/nav\b/i', $preceding_html );
						if ( $nav_open_count > $nav_close_count ) {
							$is_wrapped_in_nav = true;
						}
						// Same balance for <a>: a tag that renders its own anchor must not
						// emit one inside an existing link, because the parser hoists the
						// inner <a> out of the outer one.
						$a_open_count  = preg_match_all( '/<a\b/i', $preceding_html );
						$a_close_count = preg_match_all( '/<\/a\b/i', $preceding_html );
						if ( $a_open_count > $a_close_count ) {
							$is_wrapped_in_anchor = true;
						}
					}

					$tag_map[ $index ] = array(
						'type'                 => $type,
						'attrs'                => $attrs,
						'content'              => $content,
						'is_wrapped_in_nav'    => $is_wrapped_in_nav,
						'is_wrapped_in_anchor' => $is_wrapped_in_anchor,
					);
					$placeholder = "<!-- uich-dyn-{$index} -->";
					$index++;
					return $placeholder;
				},
				$html
			);
			return array( $html, $tag_map );
		}

		/**
		 * Replace comment placeholders produced by extract_dynamic_tags() with
		 * the actual rendered content for each dynamic tag type.
		 *
		 * @param string $output    HTML output string containing placeholders.
		 * @param array  $tag_map   Map of index → ['type', 'attrs'].
		 * @param bool   $is_editor Whether rendering inside the Elementor editor.
		 * @return string Final HTML with dynamic tags resolved.
		 */
		private function restore_dynamic_tags( $output, $tag_map, $is_editor ) {
			if ( empty( $tag_map ) ) {
				return $output;
			}
			return preg_replace_callback(
				'/<!-- uich-dyn-(\d+) -->/',
				function ( $m ) use ( $tag_map, $is_editor ) {
					$key = (int) $m[1];
					if ( ! isset( $tag_map[ $key ] ) ) {
						return '';
					}
					return $this->render_dynamic_tag_content(
						$tag_map[ $key ]['type'],
						$tag_map[ $key ]['attrs'],
						$is_editor,
						$tag_map[ $key ]['content'] ?? '',
						$tag_map[ $key ]['is_wrapped_in_nav'] ?? false,
						$tag_map[ $key ]['is_wrapped_in_anchor'] ?? false
					);
				},
				$output
			);
		}

		/**
		 * Resolve which post ID to pull content from.
		 * In the editor the global post is the template — walk up to the current
		 * Elementor document, or fall back to the latest published post.
		 *
		 * @param bool $is_editor
		 * @return int Post ID, or 0 if none found.
		 */
		private function resolve_preview_post_id( $is_editor ) {
			$post_id = get_the_ID();

			if ( ! $is_editor ) {
				return (int) $post_id;
			}

			$editor_post_id = 0;
			if ( class_exists( '\Elementor\Plugin' )
				&& isset( \Elementor\Plugin::$instance->documents )
			) {
				$current_doc = \Elementor\Plugin::$instance->documents->get_current();
				if ( $current_doc ) {
					$editor_post_id = $current_doc->get_main_id();
				}
			}

			if ( ! $editor_post_id ) {
				$editor_post_id = (int) $post_id;
			}

			$post_type       = $editor_post_id ? get_post_type( $editor_post_id ) : false;
			$is_real_content = $editor_post_id && $post_type && 'elementor_library' !== $post_type;

			if ( ! $is_real_content ) {
				$fallback = get_posts( array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'post_type'   => 'post',
					'orderby'     => 'date',
					'order'       => 'DESC',
				) );
				$editor_post_id = ! empty( $fallback ) ? (int) $fallback[0]->ID : 0;
			}

			return $editor_post_id;
		}

		/**
		 * Walk the filtered post content and ensure every heading has an id=""
		 * attribute. Headings that already carry an id are left untouched.
		 * Duplicate slugs get a numeric suffix (-2, -3 …).
		 *
		 * @param string $content Filtered post content HTML.
		 * @return string Content with id attributes injected on headings.
		 */
		private function apply_heading_ids( $content ) {
			$id_count = array();
			return preg_replace_callback(
				'/<(h[1-6])([^>]*?)>(.*?)<\/h[1-6]>/is',
				function ( $m ) use ( &$id_count ) {
					$tag   = $m[1];
					$attrs = $m[2];
					$inner = $m[3];
					if ( preg_match( '/\bid\s*=/i', $attrs ) ) {
						return $m[0]; // already has id
					}
					$base = sanitize_title( wp_strip_all_tags( $inner ) );
					if ( ! $base ) {
						return $m[0];
					}
					$id = $base;
					if ( isset( $id_count[ $base ] ) ) {
						$id_count[ $base ]++;
						$id = $base . '-' . $id_count[ $base ];
					} else {
						$id_count[ $base ] = 0;
					}
					return "<{$tag}{$attrs} id=\"" . esc_attr( $id ) . "\">{$inner}</{$tag}>";
				},
				$content
			);
		}

		/**
		 * Extract an ordered list of headings from filtered content, resolving
		 * the same id="" values that apply_heading_ids() would produce.
		 *
		 * @param string $content Filtered post content HTML (already has heading ids, or not).
		 * @return array[] Each entry: [ 'level' => int, 'text' => string, 'id' => string ]
		 */
		private function extract_headings( $content ) {
			preg_match_all( '/<h([1-6])([^>]*?)>(.*?)<\/h[1-6]>/is', $content, $matches, PREG_SET_ORDER );
			$id_count = array();
			$headings = array();
			foreach ( $matches as $m ) {
				$level = (int) $m[1];
				$attrs = $m[2];
				$inner = $m[3];
				$text  = wp_strip_all_tags( $inner );
				if ( preg_match( '/\bid\s*=\s*["\']([^"\']*)["\']/', $attrs, $id_m ) ) {
					$id = trim( $id_m[1] );
				} else {
					$base = sanitize_title( $text );
					if ( ! $base ) {
						continue;
					}
					$id = $base;
					if ( isset( $id_count[ $base ] ) ) {
						$id_count[ $base ]++;
						$id = $base . '-' . $id_count[ $base ];
					} else {
						$id_count[ $base ] = 0;
					}
				}
				if ( $id ) {
					$headings[] = array(
						'level' => $level,
						'text'  => $text,
						'id'    => $id,
					);
				}
			}
			return $headings;
		}

		/**
		 * Parse the template content inside <uichemy-nav-menu> to extract class names
		 * and structural options for the rendered menu.
		 *
		 * Recognises:
		 *   <li for="nav_item in nav_menu" class="…">
		 *   <ul if="sub_items in nav_item" class="…">
		 *   <li for="sub_item in nav_item.sub_items" class="…">
		 *
		 * @param string $content Inner HTML of the <uichemy-nav-menu> tag.
		 * @return array{item_class:string, has_submenu:bool, submenu_attrs:string, sub_item_class:string}
		 */
		private function parse_nav_template( $content ) {
			$item_class     = '';
			$has_submenu    = false;
			$submenu_attrs  = '';
			$sub_item_class = '';

			// Top-level item: <li for="nav_item in nav_menu" …>
			if ( preg_match( '/<li\b([^>]*?)\bfor=["\']nav_item\s+in\s+nav_menu["\'][^>]*>/i', $content, $m ) ) {
				if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $m[0], $cm ) ) {
					$item_class = trim( $cm[1] );
				}
			}

			// Submenu container: <ul if="sub_items in nav_item" …>
			if ( preg_match( '/<ul\b([^>]*?)\bif=["\']sub_items\s+in\s+nav_item["\'][^>]*>/i', $content, $m ) ) {
				$has_submenu = true;
				// Collect attrs from the <ul> tag excluding the if="" directive.
				$ul_tag     = $m[0];
				$clean_tag  = preg_replace( '/\s*\bif=["\'][^"\']*["\']/', '', $ul_tag );
				$inner_attrs = preg_replace( '/^<ul\s*|\s*>$/', '', trim( $clean_tag ) );
				$submenu_attrs = trim( (string) $inner_attrs );
			}

			// Sub-item: <li for="sub_item in nav_item.sub_items" …>
			if ( preg_match( '/<li\b[^>]*\bfor=["\']sub_item\s+in\s+nav_item\.sub_items["\'][^>]*>/i', $content, $m ) ) {
				if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $m[0], $cm ) ) {
					$sub_item_class = trim( $cm[1] );
				}
			}

			return array(
				'item_class'    => $item_class,
				'has_submenu'   => $has_submenu,
				'submenu_attrs' => $submenu_attrs,
				'sub_item_class' => $sub_item_class,
			);
		}

		/**
		 * Fetch the active WordPress navigation menu items.
		 * Tries common theme location names in priority order, then falls back
		 * to the first registered location.
		 *
		 * @return array{ top: WP_Post[], by_parent: array<int, WP_Post[]> }|array Empty on failure.
		 */
		private function get_active_nav_menu_items() {
			$locations = get_nav_menu_locations();
			$menu_id   = 0;

			if ( ! empty( $locations ) && is_array( $locations ) ) {
				// Normalize keys to lowercase for a case-insensitive search
				$normalized_locations = array();
				foreach ( $locations as $k => $v ) {
					$normalized_locations[ strtolower( $k ) ] = (int) $v;
				}

				foreach ( array( 'primary', 'main', 'header', 'primary-menu', 'main-navigation', 'header-menu', 'menu-1' ) as $loc ) {
					if ( ! empty( $normalized_locations[ $loc ] ) ) {
						$menu_id = $normalized_locations[ $loc ];
						break;
					}
				}

				// If priority locations are not found/assigned, look for ANY active assigned location
				if ( ! $menu_id ) {
					foreach ( $locations as $loc_slug => $loc_menu_id ) {
						if ( ! empty( $loc_menu_id ) ) {
							$menu_id = (int) $loc_menu_id;
							break;
						}
					}
				}
			}

			// No location-assigned menu found — fall back to the first registered menu
			if ( ! $menu_id ) {
				$all_menus = wp_get_nav_menus();
				if ( ! empty( $all_menus ) && ! is_wp_error( $all_menus ) ) {
					$menu_id = (int) $all_menus[0]->term_id;
				}
			}

			if ( ! $menu_id ) {
				return array();
			}

			$items = wp_get_nav_menu_items( $menu_id );
			if ( ! $items || is_wp_error( $items ) ) {
				return array();
			}

			$top_level = array();
			$by_parent = array();
			foreach ( $items as $item ) {
				$pid = (int) $item->menu_item_parent;
				if ( 0 === $pid ) {
					$top_level[] = $item;
				} else {
					$by_parent[ $pid ][] = $item;
				}
			}

			return array( 'top' => $top_level, 'by_parent' => $by_parent );
		}

		/**
		 * Build the final <nav> HTML for a nav-menu tag using parsed template config
		 * and fetched menu items.
		 *
		/**
		 * Build the final <nav> or <ul> HTML for a nav-menu tag using parsed template config
		 * and fetched menu items.
		 *
		 * @param array  $tpl        Output of parse_nav_template().
		 * @param array  $menu_data  Output of get_active_nav_menu_items().
		 * @param string $outer_attrs Attribute string for the outer <nav> (from the tag).
		 * @param bool   $is_wrapped_in_nav Whether the tag is already wrapped in a <nav> container.
		 * @return string
		 */
		private function render_nav_menu_html( $tpl, $menu_data, $outer_attrs, $is_wrapped_in_nav = false ) {
			$top       = $menu_data['top'];
			$by_parent = $menu_data['by_parent'];

			$item_class     = $tpl['item_class'];
			$sub_item_class = $tpl['sub_item_class'];
			$submenu_attrs  = $tpl['submenu_attrs'];

			$items_html = '';
			foreach ( $top as $item ) {
				$item_id      = (int) $item->ID;
				$sub_items    = isset( $by_parent[ $item_id ] ) ? $by_parent[ $item_id ] : array();
				$has_children = count( $sub_items ) > 0;

				// Use only user-defined classes — no auto-injected modifiers.
				$li_attr = $item_class ? ' class="' . esc_attr( $item_class ) . '"' : '';
				$link    = '<a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a>';

				$submenu_html = '';
				if ( $has_children ) {
					$sub_html = '';
					foreach ( $sub_items as $sub ) {
						$sub_link  = '<a href="' . esc_url( $sub->url ) . '">' . esc_html( $sub->title ) . '</a>';
						$sub_class = $sub_item_class ? ' class="' . esc_attr( $sub_item_class ) . '"' : '';
						$sub_html .= '<li' . $sub_class . '>' . $sub_link . '</li>';
					}
					// Wrap submenu with user-defined attrs (class, etc.) from <ul if="…">.
					$ul_open      = '<ul' . ( $submenu_attrs ? ' ' . $submenu_attrs : '' ) . '>';
					$submenu_html = $ul_open . $sub_html . '</ul>';
				}

				$items_html .= '<li' . $li_attr . '>' . $link . $submenu_html . '</li>';
			}

			$outer_attr = $outer_attrs ? ' ' . $outer_attrs : '';
			if ( $is_wrapped_in_nav ) {
				// Render direct <ul> so horizontal/vertical flex/grid layouts and BEM selector specificity are perfectly preserved
				return '<ul' . $outer_attr . '>' . $items_html . '</ul>';
			}

			// Outer tag becomes <nav> with a <ul> wrapper so <li> items are
			// valid HTML children and browsers / DOMDocument never auto-insert
			// an implicit <ul> that would shift CSS selector specificity.
			return '<nav' . $outer_attr . '><ul>' . $items_html . '</ul></nav>';
		}

		/**
		 * Parse the template content inside <uichemy-toc> to extract class names
		 * and structural options for the rendered TOC.
		 *
		 * Recognises:
		 *   <li for="heading in headings" class="…">
		 *   <ul if="sub_headings in heading" class="…">
		 *   <li for="sub_heading in heading.sub_headings" class="…">
		 *
		 * @param string $content Inner HTML of the <uichemy-toc> tag.
		 * @return array{item_class:string, has_submenu:bool, submenu_attrs:string, sub_item_class:string}
		 */
		private function parse_toc_template( $content ) {
			$item_class     = '';
			$has_submenu    = false;
			$submenu_attrs  = '';
			$sub_item_class = '';

			// Top-level item: <li for="heading in headings" …>
			if ( preg_match( '/<li\b[^>]*\bfor=["\']heading\s+in\s+headings["\'][^>]*>/i', $content, $m ) ) {
				if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $m[0], $cm ) ) {
					$item_class = trim( $cm[1] );
				}
			}

			// Submenu container: <ul if="sub_headings in heading" …>
			if ( preg_match( '/<ul\b[^>]*\bif=["\']sub_headings\s+in\s+heading["\'][^>]*>/i', $content, $m ) ) {
				$has_submenu = true;
				$ul_tag      = $m[0];
				$clean_tag   = preg_replace( '/\s*\bif=["\'][^"\']*["\']/', '', $ul_tag );
				$inner_attrs = preg_replace( '/^<ul\s*|\s*>$/', '', trim( $clean_tag ) );
				$submenu_attrs = trim( (string) $inner_attrs );
			}

			// Sub-item: <li for="sub_heading in heading.sub_headings" …>
			if ( preg_match( '/<li\b[^>]*\bfor=["\']sub_heading\s+in\s+heading\.sub_headings["\'][^>]*>/i', $content, $m ) ) {
				if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $m[0], $cm ) ) {
					$sub_item_class = trim( $cm[1] );
				}
			}

			return array(
				'item_class'     => $item_class,
				'has_submenu'    => $has_submenu,
				'submenu_attrs'  => $submenu_attrs,
				'sub_item_class' => $sub_item_class,
			);
		}

		/**
		 * Convert a flat ordered heading list into a two-level tree:
		 * top-level headings (those with no shallower ancestor in the list)
		 * and their direct/indirect children grouped by parent heading id.
		 *
		 * Uses a depth-stack so the parent of any heading is always the nearest
		 * preceding heading at a shallower level, regardless of how many levels
		 * are skipped.
		 *
		 * @param array[] $headings Output of extract_headings().
		 * @return array{ top: array[], by_parent: array<string, array[]> }
		 */
		private function build_heading_tree( $headings ) {
			$top       = array();
			$by_parent = array();
			$stack     = array(); // each entry is a heading array

			foreach ( $headings as $h ) {
				$level = (int) $h['level'];

				// Pop entries at the same or deeper level — they are closed.
				while ( ! empty( $stack ) && (int) end( $stack )['level'] >= $level ) {
					array_pop( $stack );
				}

				if ( empty( $stack ) ) {
					$top[] = $h;
				} else {
					$parent_id                 = end( $stack )['id'];
					$by_parent[ $parent_id ][] = $h;
				}

				$stack[] = $h;
			}

			return array( 'top' => $top, 'by_parent' => $by_parent );
		}

		/**
		 * Build the final TOC HTML using parsed template config and the heading tree.
		 * Rendering is fully recursive — h3 inside h2 inside h1 all work correctly,
		 * with the sub-item template (class + ul attrs) re-applied at every depth.
		 *
		 * @param array  $tpl          Output of parse_toc_template().
		 * @param array  $heading_data Output of build_heading_tree().
		 * @param string $outer_attrs  Attribute string for the outer <nav> (from the tag).
		 * @return string
		 */
		private function render_toc_html( $tpl, $heading_data, $outer_attrs ) {
			$by_parent      = $heading_data['by_parent'];
			$item_class     = $tpl['item_class'];
			$sub_item_class = $tpl['sub_item_class'];
			$submenu_attrs  = $tpl['submenu_attrs'];
			$has_sub_tpl    = $tpl['has_submenu'];

			/**
			 * Recursively render a list of headings.
			 * Top-level items use $item_class; every deeper level uses $sub_item_class.
			 * The same $submenu_attrs <ul> wrapper is applied at every nesting depth.
			 */
			$render_items = null;
			$render_items = function( $items, $is_top_level ) use (
				&$render_items, $by_parent,
				$item_class, $sub_item_class, $submenu_attrs, $has_sub_tpl
			) {
				$html = '';
				foreach ( $items as $h ) {
					$li_class = $is_top_level ? $item_class : $sub_item_class;
					$li_attr  = $li_class ? ' class="' . esc_attr( $li_class ) . '"' : '';
					$link     = '<a href="#' . esc_attr( $h['id'] ) . '">' . esc_html( $h['text'] ) . '</a>';

					$sub_items    = $has_sub_tpl && isset( $by_parent[ $h['id'] ] ) ? $by_parent[ $h['id'] ] : array();
					$submenu_html = '';
					if ( ! empty( $sub_items ) ) {
						$ul_open      = '<ul' . ( $submenu_attrs ? ' ' . $submenu_attrs : '' ) . '>';
						$submenu_html = $ul_open . $render_items( $sub_items, false ) . '</ul>';
					}

					$html .= '<li' . $li_attr . '>' . $link . $submenu_html . '</li>';
				}
				return $html;
			};

			$nav_attr = $outer_attrs ? ' ' . $outer_attrs : '';
			return '<nav' . $nav_attr . '>' . $render_items( $heading_data['top'], true ) . '</nav>';
		}

		/**
		 * Render the output for a single <uichemy-*> dynamic tag.
		 *
		 * @param string $type              Tag type slug (e.g. 'post-content', 'toc', 'nav-menu').
		 * @param string $attrs_str         Raw attribute string from the original tag.
		 * @param bool   $is_editor         Whether rendering inside the Elementor editor.
		 * @param string $content           Inner HTML content of the tag (for content-bearing tags).
		 * @param bool   $is_wrapped_in_nav Whether the tag is already wrapped in a <nav> container.
		 * @return string Rendered HTML.
		 */
		private function render_dynamic_tag_content( $type, $attrs_str, $is_editor, $content = '', $is_wrapped_in_nav = false, $is_wrapped_in_anchor = false ) {
			$attrs_str = trim( $attrs_str );
			$attr_open = $attrs_str ? ' ' . $attrs_str : '';
			$open_tag  = "<div{$attr_open}>";
			$close_tag = '</div>';

			$post_id = $this->resolve_preview_post_id( $is_editor );

			// ── post-content ──────────────────────────────────────────────────────
			if ( 'post-content' === $type ) {
				$post_content = '';
				if ( $post_id ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional use of the WordPress core 'the_content' filter to render stored post content.
					$post_content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
					// Ensure headings carry id="" so <uichemy-toc /> links resolve.
					$post_content = $this->apply_heading_ids( $post_content );
				}
				if ( $attrs_str ) {
					return $open_tag . $post_content . $close_tag;
				}
				return $post_content;
			}

			// ── toc ───────────────────────────────────────────────────────────────
			if ( 'toc' === $type ) {
				$heading_data = array( 'top' => array(), 'by_parent' => array() );
				if ( $post_id ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional use of the WordPress core 'the_content' filter to render stored post content.
					$post_content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
					$headings     = $this->extract_headings( $post_content );
					$heading_data = $this->build_heading_tree( $headings );
				}
				if ( empty( $heading_data['top'] ) ) {
					$nav_attr = $attrs_str ? ' ' . $attrs_str : '';
					return '<nav' . $nav_attr . '></nav>';
				}
				$tpl = $content
					? $this->parse_toc_template( $content )
					: array(
						'item_class'     => '',
						'has_submenu'    => true,
						'submenu_attrs'  => '',
						'sub_item_class' => '',
					);
				return $this->render_toc_html( $tpl, $heading_data, $attrs_str );
			}

			// ── site-logo ─────────────────────────────────────────────────────────
			// Renders the WordPress "Site Logo" (set via Appearance → Customize →
			// Site Identity → Logo). The default output is a clickable link to
			// the home URL wrapping an <img> with width/height/alt resolved from
			// the attachment. Any attrs on the tag are passed through to the
			// wrapping <a> element (e.g. `class="site-logo"` or `data-foo="bar"`).
			//
			//   Self-closing:   <uichemy-site-logo />
			//   With class:     <uichemy-site-logo class="header-logo" />
			//
			// If no custom logo is configured on the site, the site name is
			// rendered as a text fallback inside the same <a> wrapper so the
			// header doesn't collapse during preview.
			if ( 'site-logo' === $type ) {
				$logo_url    = '';
				$logo_width  = 0;
				$logo_height = 0;
				$logo_alt    = '';

				$logo_id = (int) get_theme_mod( 'custom_logo' );
				if ( $logo_id ) {
					// Prefer the design-sourced dimensions set by set_site_branding()
					// (stored as attachment meta). WP core's wp_get_attachment_image_src()
					// returns false for SVG logos (wp_attachment_is_image() doesn't
					// recognize image/svg+xml), which would otherwise render a blank
					// <img> for a text/vector logo — this meta covers both the "right
					// size" and the "SVG renders at all" cases.
					$meta_width  = (int) get_post_meta( $logo_id, '_uich_logo_width', true );
					$meta_height = (int) get_post_meta( $logo_id, '_uich_logo_height', true );
					if ( $meta_width && $meta_height ) {
						$logo_url    = (string) wp_get_attachment_url( $logo_id );
						$logo_width  = $meta_width;
						$logo_height = $meta_height;
					} else {
						$logo_src = wp_get_attachment_image_src( $logo_id, 'full' );
						if ( $logo_src ) {
							$logo_url    = (string) $logo_src[0];
							$logo_width  = (int) $logo_src[1];
							$logo_height = (int) $logo_src[2];
						}
					}
					if ( $logo_url ) {
						$logo_alt = (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true );
					}
				}
				if ( '' === $logo_alt ) {
					$logo_alt = (string) get_bloginfo( 'name' );
				}

				$home_url = esc_url( home_url( '/' ) );
				$a_attr   = $attrs_str ? ' ' . $attrs_str : '';

				if ( '' === $logo_url ) {
					// No custom logo set — render a branded icon mark using the
					// site's first Elementor global color (falls back to UiChemy
					// brand purple). Never shows the site name as plain text.
					$brand_color = '#6c3ff5';
					if ( class_exists( '\Elementor\Plugin' ) ) {
						try {
							$kit      = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
							$settings = $kit ? $kit->get_settings_for_display() : array();
							// Atomic mode stores colors in 'custom_colors'; classic in 'system_colors'
							$colors = ! empty( $settings['custom_colors'] ) ? $settings['custom_colors']
								: ( ! empty( $settings['system_colors'] ) ? $settings['system_colors'] : array() );
							foreach ( $colors as $color_item ) {
								if ( ! empty( $color_item['color'] ) && '#' === substr( $color_item['color'], 0, 1 ) ) {
									$brand_color = esc_attr( $color_item['color'] );
									break;
								}
							}
						} catch ( \Exception $e ) {
							// Ignore — keep the default brand color
						}
					}
					// "U" arc mark — two vertical bars joined at the bottom with a curve.
					$svg = '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">'
						. '<rect width="36" height="36" rx="8" fill="' . $brand_color . '"/>'
						. '<path d="M11 11v10q0 4 7 4t7-4V11" stroke="white" stroke-width="2.8" stroke-linecap="round" fill="none"/>'
						. '</svg>';
					// Inside an existing link the wrapping anchor would be invalid.
					if ( $is_wrapped_in_anchor ) {
						return '<span' . $a_attr . ' style="display:inline-block;line-height:0;">' . $svg . '</span>';
					}
					return '<a' . $a_attr . ' href="' . $home_url . '" style="display:inline-block;line-height:0;">' . $svg . '</a>';
				}

				// Already inside a link — emit the mark alone; the surrounding anchor
				// provides the link.
				if ( $is_wrapped_in_anchor ) {
					return sprintf(
						'<img%s src="%s" width="%d" height="%d" alt="%s" />',
						$a_attr,
						esc_url( $logo_url ),
						$logo_width,
						$logo_height,
						esc_attr( $logo_alt )
					);
				}

				return sprintf(
					'<a%s href="%s"><img src="%s" width="%d" height="%d" alt="%s" /></a>',
					$a_attr,
					$home_url,
					esc_url( $logo_url ),
					$logo_width,
					$logo_height,
					esc_attr( $logo_alt )
				);
			}

			// ── site-icon ─────────────────────────────────────────────────────────
			// Renders the WordPress "Site Icon" — the favicon set via Appearance
			// → Customize → Site Identity → Site Icon. This is a DIFFERENT
			// setting from the Site Logo (favicons live in `option('site_icon')`,
			// not in the `custom_logo` theme mod). Useful when you want the
			// browser-tab icon inside your header (e.g. as a small avatar next
			// to the brand name, or as a mobile-only logo).
			//
			//   Self-closing:        <uichemy-site-icon />
			//   With class + size:   <uichemy-site-icon class="favicon" data-size="64" />
			//
			// Attrs are forwarded to the wrapping <a>. The optional `data-size`
			// attr picks which generated favicon size to load (WordPress emits
			// 32 / 192 / 270 / 512 by default — 192 is the safe default for a
			// crisp render at normal CSS sizes).
			if ( 'site-icon' === $type ) {
				$icon_size = 192;
				if ( $attrs_str && preg_match( '/\bdata-size\s*=\s*"(\d+)"/i', $attrs_str, $sm ) ) {
					$icon_size = max( 16, (int) $sm[1] );
				}

				$icon_url = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( $icon_size ) : '';
				$home_url = esc_url( home_url( '/' ) );
				$a_attr   = $attrs_str ? ' ' . $attrs_str : '';

				if ( '' === $icon_url ) {
					// No site icon configured — fall back to a clearly-empty
					// link so the user sees that the slot exists in the layout
					// but knows they still need to upload one in Customize.
					return '<a' . $a_attr . ' href="' . $home_url . '"></a>';
				}

				$icon_alt = (string) get_bloginfo( 'name' );

				return sprintf(
					'<a%s href="%s"><img src="%s" width="%d" height="%d" alt="%s" /></a>',
					$a_attr,
					$home_url,
					esc_url( $icon_url ),
					$icon_size,
					$icon_size,
					esc_attr( $icon_alt )
				);
			}

			// ── nav-menu ──────────────────────────────────────────────────────────
			// ── woo-* ─────────────────────────────────────────────────────────────
			// Delegated to UiChemy_Woo_Tags, which the Gutenberg/Bricks renderer
			// also uses. This branch was missing here entirely, so every woo tag
			// rendered nothing in Elementor while working in the other builders.
			if ( class_exists( 'UiChemy_Woo_Tags' ) && UiChemy_Woo_Tags::handles( $type ) ) {
				return UiChemy_Woo_Tags::render( $type, $attrs_str, $is_editor );
			}

			if ( 'nav-menu' === $type ) {
				$menu_data = $this->get_active_nav_menu_items();
				if ( empty( $menu_data ) ) {
					$nav_attr = $attrs_str ? ' ' . $attrs_str : '';
					return $is_wrapped_in_nav ? '<ul' . $nav_attr . '></ul>' : '<nav' . $nav_attr . '></nav>';
				}
				$tpl = $content
					? $this->parse_nav_template( $content )
					: array(
						'item_class'     => '',
						'has_submenu'    => true,
						'submenu_attrs'  => '',
						'sub_item_class' => '',
					);
				return $this->render_nav_menu_html( $tpl, $menu_data, $attrs_str, $is_wrapped_in_nav );
			}

			return '';
		}

		
		private function build_referenced_global_typography_css( $html, $scope ) {
			static $typo = null;

			if ( null === $typo ) {
				$typo = array();

				if ( ! class_exists( '\UiChemy_Atomic_Globals' ) && defined( 'UICHEMY_PATH' ) ) {
					$globals_file = UICHEMY_PATH . 'includes/admin/globals/class-uichemy-atomic-globals.php';
					if ( is_readable( $globals_file ) ) {
						require_once $globals_file;
					}
				}

				if ( class_exists( '\UiChemy_Atomic_Globals' )
					&& method_exists( '\UiChemy_Atomic_Globals', 'get_elementor_typo_classes' ) ) {
					$entries = \UiChemy_Atomic_Globals::get_elementor_typo_classes();
					if ( is_array( $entries ) ) {
						foreach ( $entries as $entry ) {
							if ( ! is_array( $entry ) ) {
								continue;
							}
							$label = isset( $entry['label'] ) ? trim( (string) $entry['label'] ) : '';
							$value = isset( $entry['value'] ) ? $entry['value'] : null;
							if ( '' === $label || ! is_array( $value ) || empty( $value['desktop'] ) ) {
								continue;
							}
							$typo[ $label ] = $value;
						}
					}
				}
			}

			if ( empty( $typo ) || '' === (string) $html ) {
				return '';
			}

			$base   = '';
			$tablet = '';
			$mobile = '';

			foreach ( $typo as $label => $value ) {
				$pattern = '/class\s*=\s*"[^"]*(?<![\w-])' . preg_quote( $label, '/' ) . '(?![\w-])[^"]*"/i';
				if ( ! preg_match( $pattern, $html ) ) {
					continue;
				}
				$selector = $scope . ' .' . $label;

				$decls = $this->build_typography_declarations( $value['desktop'] );
				if ( '' !== $decls ) {
					$base .= $selector . '{' . $decls . '}';
				}
				if ( ! empty( $value['tablet'] ) ) {
					$td = $this->build_typography_declarations( $value['tablet'] );
					if ( '' !== $td ) {
						$tablet .= $selector . '{' . $td . '}';
					}
				}
				if ( ! empty( $value['mobile'] ) ) {
					$md = $this->build_typography_declarations( $value['mobile'] );
					if ( '' !== $md ) {
						$mobile .= $selector . '{' . $md . '}';
					}
				}
			}

			if ( '' !== $tablet ) {
				$base .= '@media(max-width:1024px){' . $tablet . '}';
			}
			if ( '' !== $mobile ) {
				$base .= '@media(max-width:767px){' . $mobile . '}';
			}

			return $base;
		}

		/**
		 * Convert an Elementor global-variable typography value map into a CSS
		 * declaration string. Values already carry units (e.g. "48px", "1.15em").
		 *
		 * @param array $value Breakpoint value map (font-family / size / weight / …).
		 * @return string
		 */
		private function build_typography_declarations( $value ) {
			if ( ! is_array( $value ) ) {
				return '';
			}
			$props = array(
				'font-family'     => 'font-family',
				'font-weight'     => 'font-weight',
				'font-size'       => 'font-size',
				'line-height'     => 'line-height',
				'letter-spacing'  => 'letter-spacing',
				'text-transform'  => 'text-transform',
				'font-style'      => 'font-style',
				'text-decoration' => 'text-decoration',
			);
			$out = '';
			foreach ( $props as $key => $prop ) {
				if ( ! isset( $value[ $key ] ) ) {
					continue;
				}
				$val = trim( (string) $value[ $key ] );
				if ( '' === $val ) {
					continue;
				}
				if ( 'font-family' === $prop ) {
					$val = '"' . str_replace( array( '"', ';', '{', '}' ), '', $val ) . '", sans-serif';
				} else {
					$val = str_replace( array( ';', '{', '}', '<' ), '', $val );
				}
				$out .= $prop . ':' . $val . ';';
			}
			return $out;
		}

		/**
		 * Convert non-structural HTML named entities (e.g. &check;, &rarr;, &hellip;)
		 * to numeric character references so DOMDocument/libxml — which only knows the
		 * five XML entities plus numeric refs — keeps the intended glyph instead of
		 * leaving the literal "&check;" text in the output. The five structural
		 * entities are left untouched, as decoding them would corrupt the markup.
		 *
		 * @param string $html Raw HTML about to be handed to DOMDocument.
		 * @return string
		 */
		private function normalize_html_entities_for_libxml( $html ) {
			if ( '' === (string) $html || false === strpos( (string) $html, '&' ) ) {
				return (string) $html;
			}

			return preg_replace_callback(
				'/&([a-zA-Z][a-zA-Z0-9]+);/',
				function ( $m ) {
					$lower = strtolower( $m[1] );
					if ( in_array( $lower, array( 'amp', 'lt', 'gt', 'quot', 'apos' ), true ) ) {
						return $m[0];
					}
					$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( '' === $decoded || $decoded === $m[0] ) {
						return $m[0];
					}
					$out = '';
					$len = function_exists( 'mb_strlen' ) ? mb_strlen( $decoded, 'UTF-8' ) : strlen( $decoded );
					for ( $i = 0; $i < $len; $i++ ) {
						$ch = function_exists( 'mb_substr' ) ? mb_substr( $decoded, $i, 1, 'UTF-8' ) : $decoded[ $i ];
						$cp = function_exists( 'mb_ord' ) ? mb_ord( $ch, 'UTF-8' ) : ord( $ch );
						if ( false === $cp ) {
							return $m[0];
						}
						$out .= '&#' . $cp . ';';
					}
					return $out;
				},
				(string) $html
			);
		}

		
		/**
		 * Inline script that stamps the section's elements with their layer paths.
		 *
		 * The composer panel maps a clicked element to its Layers entry by its
		 * position in the markup. A section's own JS breaks that the moment it runs:
		 * a text effect splits a heading into one span per letter, a WebGL scene
		 * inserts its canvas, ScrollTrigger wraps a pinned block in `.pin-spacer`.
		 * So the position is recorded up front as `data-uich-path`, and the panel
		 * prefers it over counting.
		 *
		 * This runs as a parser-blocking inline script placed directly after the
		 * section's markup, i.e. before any footer / deferred script has touched
		 * it. (The Elementor editor stamps the same way from JS — see
		 * composer-stamps.js — because scripts injected there have no
		 * `document.currentScript`.)
		 *
		 * The walk MUST match parseHtmlToLayers() in composer-layer-tree.jsx: skip
		 * SCRIPT/STYLE/TEMPLATE/NOSCRIPT/META/LINK/HEAD, and do not descend into
		 * `<svg>` or `img[data-as="svg"]`.
		 *
		 * Only printed for a visitor the live-page editor loads for.
		 *
		 * @return string
		 */
		private static function layer_path_stamp_script() {
			// Front-end-only nodes that are not in raw_html and must not take an index:
			//   - the <a> wrap_element_links() puts around a linked element
			//     (transparent: its child is stamped in its place);
			//   - the hidden fields and honeypot Uich_Forms injects into a form.
			return '<script>(function(s){try{var r=s&&s.parentNode;if(!r)return;'
				. 'var K={SCRIPT:1,STYLE:1,TEMPLATE:1,NOSCRIPT:1,META:1,LINK:1,HEAD:1};'
				. 'var w=function(n,b,k){var c=n.firstChild;for(;c;c=c.nextSibling){'
				. 'if(c.nodeType!==1||K[c.tagName])continue;'
				. 'if(c.hasAttribute("data-uich-link-wrap")){w(c,b,k);continue;}'
				. 'if((c.tagName==="INPUT"&&(c.name||"").indexOf("_uich_")===0)||(c.classList&&c.classList.contains("uich-hp")))continue;'
				. 'var p=b+"."+(k.i++);c.setAttribute("data-uich-path",p);'
				. 'var t=c.tagName.toUpperCase();if(t==="SVG"||(t==="IMG"&&c.getAttribute("data-as")==="svg")){'
				// Stale stamps saved inside an svg by an older build would answer to another element's path.
				. 'var q=c.querySelectorAll("[data-uich-path]");for(var j=0;j<q.length;j++)q[j].removeAttribute("data-uich-path");continue;}w(c,p,{i:0});}};'
				. 'w(r,"0",{i:0});}catch(e){}})(document.currentScript);</script>';
		}

		private function build_editor_js_runtime( $raw_js ) {
			$raw_js = (string) $raw_js;
			if ( '' === trim( $raw_js ) ) {
				return '';
			}

			// Motion variables -> a values preamble plus plain property reads. A
			// section with no motion block comes back byte-identical.
			if ( class_exists( 'UiChemy_Motion' ) ) {
				$raw_js = UiChemy_Motion::compile( $raw_js );
			}

			$wid_json   = wp_json_encode( 'w' . $this->get_id() );
			$scope_json = wp_json_encode( '.elementor-element-' . $this->get_id() );
			$body_json  = wp_json_encode( $raw_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( false === $wid_json || false === $scope_json || false === $body_json ) {
				return '';
			}

			// %1$s = widget key, %2$s = scope selector, %3$s = JS body — all already
			// JSON-encoded, so they are safe JS string literals.
			$template = <<<'JS'
(function(){
  var WID=%1$s, SCOPE=%2$s, BODY=%3$s;
  var G=(window.__ucComposerEditorJS=window.__ucComposerEditorJS||{});
  var prev=G[WID];
  if(prev){
    prev.intervals.forEach(function(i){clearInterval(i);});
    prev.timeouts.forEach(function(i){clearTimeout(i);});
    prev.rafs.forEach(function(i){cancelAnimationFrame(i);});
    prev.listeners.forEach(function(l){try{l.t.removeEventListener(l.e,l.h,l.o);}catch(e){}});
  }
  // Elementor replaces a widget's DOM on every edit, but ScrollTriggers bound to
  // the OLD node are not auto-removed — they pile up and fight the fresh ones. A
  // trigger whose element has left the document is dead by definition, so drop it.
  //
  // The same sweep answers a second question: did this repaint orphan ANOTHER
  // section's work? Every trigger is tagged with the section whose run created it
  // (`__uichW`, set after the run below and by the editor's own runner). A dead
  // trigger owned by a different section means a shared script — one section
  // animating the whole page — had bound it to the nodes this repaint threw away;
  // a LIVE trigger this section owns but which sits outside it means this section
  // IS that shared script. Either way the only clean state is every section fresh
  // and run top to bottom, which the editor shell does on request (see
  // softReloadComposerSections in composer-elementor.jsx).
  //
  // Every trigger this section's PREVIOUS run created is dropped too, wherever it
  // sits. One bound to an element that survived the repaint — in another section,
  // or on <body> — is not "dead" by the test above, so re-running the script used
  // to add a second copy beside it: a section whose script animates the page
  // gained one more trigger in every other section on each edit.
  var orphaned=false, ownRoot=document.querySelector(SCOPE);
  var SEC='.elementor-widget-uichemy-composer,.elementor-widget-composer,.elementor-widget-proton,.elementor-widget-uichemy-builder';
  if(window.ScrollTrigger&&window.ScrollTrigger.getAll){
    window.ScrollTrigger.getAll().forEach(function(st){
      try{
        var el=st.trigger, live=!!(el&&document.contains(el));
        if(el&&!live){
          if(st.__uichW&&st.__uichW!==WID){ orphaned=true; }
          st.kill();
        } else if(st.__uichW===WID){
          // In ANOTHER section: this section is the shared script (see above).
          // On <body> or outside every section: just this script's own, redone below.
          if(live&&ownRoot&&!ownRoot.contains(el)&&el.closest&&el.closest(SEC)){ orphaned=true; }
          st.kill();
        }
      }catch(e){}
    });
  }
  // When the shell confirms a reload is coming, this section's script is NOT run
  // here: the reload runs it again over fresh nodes, and a first run that loads a
  // module would finish against the nodes the reload has since replaced — failing
  // loudly and leaving a stray trigger behind.
  var reloading=false;
  if(orphaned&&!window.__uichSoftReloading){
    try{ var shell=window.parent; if(shell&&shell.__uichComposerSoftReload){ reloading=shell.__uichComposerSoftReload()===true; } }catch(e){}
  }
  // WebGL contexts orphaned by the same re-render. Browsers ration these and drop
  // the oldest once the ceiling is reached, which reads as older sections going
  // black rather than as an error.
  try{ if(window.UiChemyThree&&window.UiChemyThree.sweep){ window.UiChemyThree.sweep(); } }catch(e){}
  var R=G[WID]={intervals:[],timeouts:[],rafs:[],listeners:[]};
  var tries=0;
  function fire(target,type,h){try{h.call(target,new Event(type));}catch(e){console.error('[Composer editor JS]',e);}}
  function run(){
    var root=document.querySelector(SCOPE);
    if(!root){ if(tries++<20){ window.setTimeout(run,50); } return; }
    var oSI=window.setInterval,oST=window.setTimeout,oRAF=window.requestAnimationFrame,
        owA=window.addEventListener,odA=document.addEventListener,
        oQS=document.querySelector,oQSA=document.querySelectorAll;
    // ── Whose trigger is this? ──
    // A trigger is tagged with the section whose script created it. Reading the
    // list before and after the script's SYNCHRONOUS run is not enough: real
    // sections wait first — `LIBS_READY.then(init)`, `setTimeout(init, 0)` — and
    // build their animation in that callback, long after this function returned.
    // Those triggers went untagged, so the sweep above could not tell that a
    // repaint had orphaned them and the page was left half-initialised.
    //
    // So a callback the script registers through a promise or a timeout is run
    // in a "zone": the same before/after tagging around it, and the same
    // wrapping in force while it runs, so anything it schedules in turn is
    // covered as well. rAF and event listeners are deliberately NOT zoned —
    // GSAP captures requestAnimationFrame for its own ticker, and a listener
    // has to stay removable by the function the author registered.
    var PT=window.Promise&&window.Promise.prototype, oThen=PT&&PT.then;
    function snapST(){ try{ return (window.ScrollTrigger&&window.ScrollTrigger.getAll)?window.ScrollTrigger.getAll().slice():null; }catch(e){ return null; } }
    function tagNew(b){ try{ window.ScrollTrigger.getAll().forEach(function(st){ if(!st.__uichW&&b.indexOf(st)===-1){ st.__uichW=WID; } }); }catch(e){} }
    function zone(f){
      if(typeof f!=='function'){ return f; }
      return function(){
        var b=snapST(), sT=window.setTimeout, th=PT&&PT.then;
        window.setTimeout=zST; if(PT){ PT.then=zThen; }
        try{ return f.apply(this,arguments); }
        finally{ window.setTimeout=sT; if(PT){ PT.then=th; } if(b){ tagNew(b); } }
      };
    }
    function zST(f){
      var a=Array.prototype.slice.call(arguments); a[0]=zone(f);
      var i=oST.apply(window,a); R.timeouts.push(i);
      // A self-rescheduling timeout would otherwise grow this list for ever.
      if(R.timeouts.length>400){ R.timeouts.splice(0,200); }
      return i;
    }
    function zThen(a,b){ return oThen.call(this,zone(a),zone(b)); }
    window.setInterval=function(f,t){var i=oSI(f,t);R.intervals.push(i);return i;};
    window.setTimeout=zST;
    if(PT){ PT.then=zThen; }
    window.requestAnimationFrame=function(f){var i=oRAF(f);R.rafs.push(i);return i;};
    window.addEventListener=function(type,h,o){
      if(type==='load'||type==='DOMContentLoaded'){fire(window,type,h);return;}
      owA.call(window,type,h,o);R.listeners.push({t:window,e:type,h:h,o:o});
    };
    document.addEventListener=function(type,h,o){
      if(type==='DOMContentLoaded'||type==='load'||type==='readystatechange'){fire(document,type,h);return;}
      odA.call(document,type,h,o);R.listeners.push({t:document,e:type,h:h,o:o});
    };
    // Scope the author JS's element lookups to THIS widget instance. Generated
    // section JS targets its template wrapper class (e.g. '.uichemy-services-4')
    // via document.querySelector — first match only — so two sections of the
    // same template type (a duplicated section) would both re-target the first
    // one and the second's own nodes (scroll-reveal, etc.) never initialise.
    // Falls back to a document-wide lookup when a selector isn't found inside
    // this instance, so genuinely global queries still resolve.
    document.querySelector=function(s){var r=null;try{r=root.querySelector(s);}catch(e){}return r||oQS.call(document,s);};
    document.querySelectorAll=function(s){var r=null;try{r=root.querySelectorAll(s);}catch(e){}return (r&&r.length)?r:oQSA.call(document,s);};
    var stBefore=null;
    try{ if(window.ScrollTrigger&&window.ScrollTrigger.getAll){ stBefore=window.ScrollTrigger.getAll().slice(); } }catch(e){}
    try{ (new Function(BODY))(); }
    catch(e){ console.error('[Composer editor JS]',e); }
    finally{
      window.setInterval=oSI;window.setTimeout=oST;window.requestAnimationFrame=oRAF;
      if(PT){ PT.then=oThen; }
      window.addEventListener=owA;document.addEventListener=odA;
      document.querySelector=oQS;document.querySelectorAll=oQSA;
    }
    // Tag what this run created with its section (read by the sweep above), and
    // put the list back in page order straight away so GSAP's own automatic
    // refresh — which lands before the deferred one below — measures the
    // sections under a re-created pin with its spacing already in place.
    if(stBefore){
      try{ window.ScrollTrigger.getAll().forEach(function(st){ if(!st.__uichW&&stBefore.indexOf(st)===-1){ st.__uichW=WID; } }); }catch(e){}
      try{ if(window.ScrollTrigger.sort){ window.ScrollTrigger.sort(); } }catch(e){}
    }
    // A script that loads an ES module (`import(url)`) builds its animation while
    // that module EVALUATES — not inside any callback of this script, so the zone
    // above never sees it. For such a section, keep watching for a few seconds
    // and claim the triggers that appear and that nobody else has tagged: every
    // other section's are tagged the moment they are made, so what is left over
    // while a module is loading is this section's.
    if(stBefore&&/\bimport\s*\(/.test(BODY)){
      var lateN=0, lateIv=oSI(function(){
        lateN++;
        try{ window.ScrollTrigger.getAll().forEach(function(st){ if(!st.__uichW&&stBefore.indexOf(st)===-1){ st.__uichW=WID; } }); }catch(e){}
        if(lateN>=40){ clearInterval(lateIv); }
      },200);
      R.intervals.push(lateIv);
    }
    // sort() first: this run's triggers were just re-created, so they sit LAST in
    // ScrollTrigger's list whatever their place on the page, and refresh() works
    // through that list in order. A re-created pin would then add its spacing
    // after everything below it had already been measured — the next section's
    // animation started a whole pin-length early. sort() restores page order.
    if(window.ScrollTrigger&&window.ScrollTrigger.refresh){
      oST(function(){ try{ if(window.ScrollTrigger.sort){ window.ScrollTrigger.sort(); } window.ScrollTrigger.refresh(); }catch(e){} },150);
    }
  }
  if(!reloading){ window.setTimeout(run,0); }
})();
JS;

			$js = sprintf( $template, $wid_json, $scope_json, $body_json );
			return '<script>' . $js . '</script>';
		}

		/**
		 * Wrap a widget's author JS for the FRONT END so it initialises the correct
		 * widget instance.
		 *
		 * Generated section JS scopes itself by the template's wrapper class, e.g.
		 * `document.querySelector('.uichemy-services-4')`. That returns the FIRST
		 * match in the document, so when a page carries two sections of the same
		 * template type (a duplicated section) the second one's script re-targets
		 * the first section and the second's own nodes are never initialised —
		 * scroll-reveal elements, for instance, stay stuck at their `opacity: 0`
		 * start state and the whole section renders blank.
		 *
		 * Each widget already has a unique `.elementor-element-<id>` wrapper, so we
		 * run the author JS with `document.querySelector[All]` temporarily scoped to
		 * THIS instance (falling back to a document-wide lookup when a selector
		 * isn't found inside it, so genuinely global queries still resolve). The
		 * author's real work usually runs from a DOMContentLoaded/load handler, and
		 * this inline script prints in the footer where that event may not have
		 * fired yet — so those handlers are fired synchronously here, while the
		 * scoped lookups are in effect (by the footer the widget DOM is fully
		 * parsed, so nothing is missed). Registration is intercepted rather than
		 * forwarded, so the browser's later real DOMContentLoaded can't double-run.
		 *
		 * Mirrors build_editor_js_runtime() (which solves the same problem for the
		 * Elementor preview); this is the leaner front-end counterpart.
		 *
		 * @param string $raw_js Author JavaScript (no <script> wrapper).
		 * @return string Wrapped JS (no <script> wrapper), or '' when empty.
		 */
		private function build_frontend_js_runtime( $raw_js ) {
			return \UiChemy_Composer_Renderer::elementor_js_runtime( $raw_js, $this->get_id() );
		}

		/**
		 * Whether the current content may emit raw executable code (inline JS /
		 * unfiltered CSS) on the front end. Resolved from the post author's
		 * `unfiltered_html` capability — the same capability WordPress core uses to
		 * decide whether an author's Custom HTML block is stored raw. On multisite,
		 * and when DISALLOW_UNFILTERED_HTML is set, this returns false for everyone.
		 *
		 * @return bool
		 */
		protected function content_allows_raw_code() {
			$post_id = get_the_ID();

			if ( ! $post_id ) {
				// No post context (e.g. a template preview): fall back to the
				// current user, so only privileged sessions ever see raw code.
				return current_user_can( 'unfiltered_html' );
			}

			$author_id = (int) get_post_field( 'post_author', $post_id );

			return $author_id > 0 && user_can( $author_id, 'unfiltered_html' );
		}

		/**
		 * Emit a widget's author-supplied inline JavaScript through WordPress's
		 * core script pipeline instead of a hand-written <script> tag.
		 *
		 * A single print-less handle (registered with no src, in the footer) is
		 * enqueued the first time it is needed; every widget's JS is then attached
		 * to it with wp_add_inline_script(). Because widgets render inside
		 * the_content — after wp_head — the handle is deferred to the footer so the
		 * inline data is still emitted (WordPress prints footer scripts at
		 * wp_footer, after body rendering). This must only be called for content
		 * whose author holds `unfiltered_html` (see content_allows_raw_code()).
		 *
		 * A section that uses a bundled library must run AFTER it. The shared inline
		 * handle carries no dependencies — it cannot, or every page with any section
		 * JS would pull in every library — so a section is attached to a handle named
		 * for the libraries it actually needs, and that handle declares them. Without
		 * this the section's own code could run before `THREE` or `gsap` existed, and
		 * whether it did depended on script order the section cannot see.
		 *
		 * @param string            $js   Author JavaScript (no <script> wrapper).
		 * @param array<int,string> $deps Script handles this section's JS needs first.
		 * @return void
		 */
		protected function enqueue_inline_widget_js( $js, $deps = array() ) {
			$js = (string) $js;
			if ( '' === trim( $js ) ) {
				return;
			}

			$deps   = array_values( array_unique( array_filter( (array) $deps ) ) );
			sort( $deps );
			// One handle per distinct dependency set, so sections needing nothing keep
			// the plain handle and only the ones needing a library wait for it.
			$handle = 'uichemy-composer-widget-inline'
				. ( $deps ? '-' . substr( md5( implode( '|', $deps ) ), 0, 8 ) : '' );

			// Fallback: if footer scripts have already been printed (widget rendered
			// during/after wp_footer — theme-builder locations, loop items, an
			// AJAX/REST render, or a cache/optimizer that flushed early), attaching
			// inline data to the handle would never flush. Print it directly instead
			// so the JS is not silently dropped on the front end.
			if ( wp_script_is( $handle, 'done' ) || did_action( 'wp_print_footer_scripts' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Gated on the author's unfiltered_html capability (see content_allows_raw_code()); mirrors core's Custom HTML block.
				echo '<script>' . $js . '</script>';
				return;
			}

			if ( ! wp_script_is( $handle, 'registered' ) ) {
				// src = false → a "dummy" handle that carries only inline scripts.
				// in_footer = true so a render-time enqueue is still printed.
				wp_register_script( $handle, false, $deps, UICHEMY_VERSION, true );
			}
			if ( ! wp_script_is( $handle, 'enqueued' ) ) {
				wp_enqueue_script( $handle );
			}

			wp_add_inline_script( $handle, $js );
		}

		/**
		 * Emit a widget's per-instance scoped CSS through WordPress's core
		 * stylesheet pipeline instead of a hand-written <style> tag.
		 *
		 * A single print-less style handle (registered with no src) collects every
		 * widget's scoped CSS via wp_add_inline_style(). Because widgets render
		 * inside the_content — after wp_head has printed and finalised the style
		 * queue — WordPress would not otherwise flush a late-enqueued style, so the
		 * handle is explicitly printed once on wp_footer via wp_print_styles().
		 *
		 * Note: this moves the per-instance CSS to just before </body>. That is a
		 * deliberate trade-off to satisfy the "load CSS via the enqueue pipeline"
		 * guideline; the CSS is scoped to the widget, so at most a brief
		 * unstyled-content flash is possible for heavy custom rules.
		 *
		 * @param string $css Scoped per-instance CSS (no <style> wrapper).
		 * @return void
		 */
		protected function enqueue_inline_widget_css( $css ) {
			$css = (string) $css;
			if ( '' === trim( $css ) ) {
				return;
			}

			$handle = 'uichemy-composer-widget-inline';

			// Fallback: if the handle has already been flushed, or this widget is
			// rendering during/after wp_footer (theme-builder locations, loop items,
			// an AJAX/REST render, or a cache/optimizer that flushed early), the
			// wp_footer printer below either already ran or never will for this
			// instance — a late wp_add_inline_style() would be lost. The CSS is
			// scoped to this widget, so printing it directly here is safe.
			if ( wp_style_is( $handle, 'done' ) || did_action( 'wp_footer' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Per-instance CSS scoped to this widget; no author markup, only style rules.
				echo '<style>' . $css . '</style>';
				return;
			}

			if ( ! wp_style_is( $handle, 'registered' ) ) {
				wp_register_style( $handle, false, array(), UICHEMY_VERSION );
				// Late (body-time) enqueued styles are not auto-flushed in the
				// footer, so print this handle explicitly once at wp_footer.
				add_action(
					'wp_footer',
					static function () use ( $handle ) {
						if ( wp_style_is( $handle, 'enqueued' ) ) {
							wp_print_styles( $handle );
						}
					},
					20
				);
			}
			if ( ! wp_style_is( $handle, 'enqueued' ) ) {
				wp_enqueue_style( $handle );
			}

			wp_add_inline_style( $handle, $css );
		}

		/**
		 * Enqueue the loop pagination runtime (AJAX paging + skeleton loading).
		 * Called only when the rendered output actually contains a loop control, so a
		 * page without one never loads it. Idempotent (shared handle). Versioned on
		 * the file's own mtime — it is a hand-maintained asset that changes
		 * independently of UICHEMY_VERSION, so a fix would otherwise sit behind a
		 * browser cache until the plugin version bumps.
		 */
		public static function enqueue_loop_runtime() {
			// One definition, in the renderer the other builders already share, so
			// the handle and cache-busting version cannot drift between builders.
			if ( class_exists( 'UiChemy_Composer_Renderer' ) ) {
				UiChemy_Composer_Renderer::enqueue_loop_runtime();
			}
		}

		/**
		 * Enqueue the GSAP libraries (core + ScrollTrigger + MotionPath + DrawSVG).
		 * Called only when a section's own JS actually uses GSAP, so the libraries
		 * never load on pages that don't animate. Idempotent (shared handles). Also
		 * used by the editor-preview enqueue hook so the preview animates too.
		 *
		 * These are the libraries only — UiChemy ships no fixed-schema animation
		 * runtime of its own. Animation lives in the section's authored JS, which is
		 * where the full GSAP API (timelines, plugins, custom eases) stays reachable.
		 */
		public static function enqueue_gsap_runtime() {
			if ( ! defined( 'UICHEMY_URL' ) ) {
				return;
			}
			$ver = defined( 'UICHEMY_VERSION' ) ? UICHEMY_VERSION : false;
			wp_enqueue_script( 'uichemy-gsap-core', UICHEMY_URL . 'assets/js/vendor/gsap/gsap.min.js', array(), $ver, true );
			wp_enqueue_script( 'uichemy-gsap-scrolltrigger', UICHEMY_URL . 'assets/js/vendor/gsap/ScrollTrigger.min.js', array( 'uichemy-gsap-core' ), $ver, true );
			wp_enqueue_script( 'uichemy-gsap-motionpath', UICHEMY_URL . 'assets/js/vendor/gsap/MotionPathPlugin.min.js', array( 'uichemy-gsap-core' ), $ver, true );
			wp_enqueue_script( 'uichemy-gsap-drawsvg', UICHEMY_URL . 'assets/js/vendor/gsap/DrawSVGPlugin.min.js', array( 'uichemy-gsap-core' ), $ver, true );
		}

		/**
		 * Enqueue three.js (core plus OrbitControls, GLTFLoader and the
		 * post-processing chain), bundled to one global by the `three` webpack
		 * entry — see src/uichemy-three/index.js for why a bundle rather than the
		 * published ESM files.
		 *
		 * Called only when a section's own JS mentions THREE, so a page with no 3D
		 * never pays the ~800 KB. Idempotent (one shared handle). Also used by the
		 * editor-preview enqueue hook so the preview renders too.
		 */
		public static function enqueue_three_runtime() {
			if ( ! defined( 'UICHEMY_BUILD_URL' ) ) {
				return;
			}
			$path = defined( 'UICHEMY_BUILD_PATH' ) ? UICHEMY_BUILD_PATH . 'three.js' : '';
			$ver  = ( $path && file_exists( $path ) )
				? filemtime( $path )
				: ( defined( 'UICHEMY_VERSION' ) ? UICHEMY_VERSION : false );

			wp_enqueue_script( 'uichemy-three', UICHEMY_BUILD_URL . 'three.js', array(), $ver, true );
		}

		protected function render() {
			$settings = $this->get_settings_for_display();

			if ( empty( $settings['raw_html'] ) ) {
				return;
			}

			$is_editor = class_exists( '\Elementor\Plugin' )
				&& isset( \Elementor\Plugin::$instance->editor )
				&& \Elementor\Plugin::$instance->editor->is_edit_mode();

			$raw_html = $settings['raw_html'];

			// Dynamic data: run the Twig engine over the authored HTML so {{ values }},
			// {% for loops %} and {% if conditions %} resolve before the rest of the
			// pipeline (slots, CSS scoping, dynamic tags) runs on the result.
			$had_dynamic = class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( $raw_html );
			if ( $had_dynamic ) {
				$raw_html = Uich_Dynamic::render_html( $raw_html, array( 'widget_id' => $this->get_id() ) );
			}

			// Extract <uichemy:*> dynamic tags before DOMDocument sees them so
			// libxml does not mangle the custom namespace-like tag names.
			[ $raw_html_for_dom, $dynamic_tag_map ] = $this->extract_dynamic_tags( $raw_html );

			// DOMDocument (libxml) only understands HTML4 + numeric entities. HTML5-only
			// named entities such as &check; are left un-decoded and render as literal
			// text. Convert every non-structural named entity to a numeric ref libxml
			// can parse, preserving the intended glyph.
			$raw_html_for_dom = $this->normalize_html_entities_for_libxml( $raw_html_for_dom );

			$dom                     = new \DOMDocument();
			$dom->preserveWhiteSpace = true;

			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $raw_html_for_dom, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();

			// Position-indexed slots (slot_0…slot_19) only line up with static HTML.
			// When Twig ran, loops/conditions change the node count, so slots would
			// clobber the rendered output — skip them entirely in that case.
			if ( ! $had_dynamic ) {
				$text_nodes = $this->get_text_nodes( $dom );

				foreach ( $text_nodes as $i => $node ) {
					if ( $i >= self::SLOT_COUNT ) {
						break;
					}
					$this->apply_slot_settings_to_node( $node, $settings, $i );
				}
			}

			$widget_scope_selector = '.elementor-element-' . $this->get_id();
			$style_nodes           = $dom->getElementsByTagName( 'style' );
			if ( $style_nodes && $style_nodes->length > 0 ) {
				for ( $s = 0; $s < $style_nodes->length; $s++ ) {
					$style_node = $style_nodes->item( $s );
					if ( ! $style_node instanceof \DOMElement ) {
						continue;
					}
					$raw_style_css = '';
					foreach ( $style_node->childNodes as $style_child ) {
						$raw_style_css .= $style_child->nodeValue;
					}
					$style_node->nodeValue = $this->scope_css_to_widget( $raw_style_css, $widget_scope_selector );
				}
			}

			// Materialise per-element links: wrap any element carrying a
			// data-uich-link marker (a link set on a non-anchor element via the
			// composer's Link field) in a real <a>. Runs on the final DOM, after
			// slots + CSS scoping.
			//
			// FRONT END ONLY: wrapping inserts an <a> level that is NOT present in the
			// composer's raw_html/layer tree, which would shift every path below it and
			// make the editor's selection outline / element picker land on the wrong
			// node. In the editor we leave the element unwrapped (its data-uich-link
			// attribute stays, harmless + hidden) so the canvas DOM matches the layer
			// tree; the link is materialised on the real front-end render.
			if ( ! $is_editor ) {
				$this->wrap_element_links( $dom );
			}

			$output = '';
			foreach ( $dom->childNodes as $child ) {
				$output .= $dom->saveHTML( $child );
			}

			$output = str_replace( '<?xml encoding="utf-8" ?>', '', $output );

			// DOMDocument lowercases attribute names; put the SVG casing back.
			$output = self::restore_svg_attribute_case( $output );

			// Restore dynamic tags with their rendered content.
			$output = $this->restore_dynamic_tags( $output, $dynamic_tag_map, $is_editor );

			// Adopt any <form data-atom-form> elements in the output as managed Atom
			// forms: inject the REST endpoint, nonce, honeypot and hidden config
			// fields, and register the front-end submit-handler runtime. Without this
			// a marked form renders as bare markup that does a plain browser submit,
			// so nothing is ever captured / emailed / webhooked. Front end only — the
			// editor preview never submits, and the runtime attaches on wp_footer,
			// which the preview's per-widget re-render does not re-fire (process_output
			// itself no-ops when the output contains no data-atom-form).
			if ( ! $is_editor && class_exists( 'Uich_Forms' ) ) {
				$output = Uich_Forms::process_output( $output, $this->get_id(), (int) get_the_ID() );
			}

			// Whether this content may emit raw executable code (inline JS) / keep
			// unfiltered CSS / load external assets on the front end. Keyed to the
			// post author's `unfiltered_html` capability — the same gate WordPress
			// core uses for the Custom HTML block. raw_js / raw_css / raw_deps are
			// stored as plain text, so Elementor's save-time kses can't inspect
			// them; this is the render-time backstop for
			// UiChemy_Composer_Manager::sanitize_composer_widget_code_on_save(), and it
			// also covers direct-to-meta import paths that bypass the editor save.
			$content_trusted = $this->content_allows_raw_code();

			// Inject standard-scope 3rd-party assets. These emit external
			// <script src>/<link> tags, so on the front end they are gated on the
			// same capability as raw_js. The editor always builds them so the
			// preview matches what a privileged author will ship.
			$deps_before = '';
			$deps_after  = '';
			if ( $is_editor || $content_trusted ) {
				[ $deps_before, $deps_after ] = $this->build_standard_deps_output(
					! empty( $settings['raw_deps_standard'] ) ? $settings['raw_deps_standard'] : '',
					$is_editor
				);
			}

			// A dependency bound with "Run with" ships as an inert carrier, which
			// needs the runtime that watches its element. Checked against the
			// markup AND both dep blocks: a bound asset can sit in page- or
			// site-scope code while the element it names lives in this widget, so
			// neither half alone is a reliable signal.
			if ( ! $is_editor && class_exists( 'UiChemy_RunWith' )
				&& UiChemy_RunWith::output_needs_runtime( $output . $deps_before . $deps_after ) ) {
				UiChemy_RunWith::enqueue_runtime();
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $deps_before ) echo $deps_before;

			// GSAP and three.js — only load a library when the section's own JS
			// actually uses it, so a page pays for what it animates with and
			// nothing else. Front end only; the editor preview loads them via
			// elementor/preview/enqueue_scripts (see the enqueue class).
			$section_js  = (string) ( $settings['raw_js'] ?? '' );
			$script_deps = array();
			if ( ! $is_editor ) {
				if ( preg_match( '/\bgsap\b|\bScrollTrigger\b/', $section_js ) ) {
					self::enqueue_gsap_runtime();
					$script_deps[] = 'uichemy-gsap-core';
					$script_deps[] = 'uichemy-gsap-scrolltrigger';
				}
				if ( preg_match( '/\bTHREE\b/', $section_js ) ) {
					self::enqueue_three_runtime();
					$script_deps[] = 'uichemy-three';
				}
			}

			// Loop runtime — same on-demand rule as GSAP: only ship the script when
			// the markup actually needs it. The two page controls emit nothing once
			// there is nothing left to load, so the final page of a loop costs
			// nothing either; data-uich-filter is the author opting a filter control
			// in, and is the third thing the runtime handles.
			if ( ! $is_editor && ( false !== strpos( $output, 'uich-loop-more"' )
				|| false !== strpos( $output, 'uich-loop-pagination"' )
				|| false !== strpos( $output, 'data-uich-filter' ) ) ) {
				self::enqueue_loop_runtime();
			}

			// Icon fonts (Font Awesome, eicons, dashicons) paint their glyph through
			// a font-family the icon plugin sets on the element. The stored raw_css
			// below starts with `*{font-family:... !important}` on every converted
			// section, and the generated design system adds a second wildcard
			// !important rule — either one takes that family away and the browser
			// paints a blank box instead of the icon. This hands it back. Shared
			// with UiChemy_Composer_Renderer (the Gutenberg/Bricks path) so it is
			// defined once and printed once per request, whichever path runs first.
			if ( class_exists( 'UiChemy_Composer_Renderer' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, self-authored <style> with no dynamic input.
				echo \UiChemy_Composer_Renderer::icon_font_guard();
			}

			if ( ! $is_editor && class_exists( 'UiChemy_Fast_Load' ) ) {
				$output = UiChemy_Fast_Load::optimize_images( $output, $this->get_id() );
			}

			if ( ! $is_editor && class_exists( 'UiChemy_Fast_Load' ) ) {
				$output = UiChemy_Fast_Load::optimize_images( $output, $this->get_id() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw_html markup is kses-filtered on save for users without unfiltered_html (Elementor pipeline); admins author it raw, mirroring core's Custom HTML block.
			echo $output;

			// Live-page editor only: stamp every element with its layer path.
			if ( ! $is_editor && class_exists( 'UiChemy_Composer_Enqueue' ) && UiChemy_Composer_Enqueue::$frontend_editor_active ) {
				echo self::layer_path_stamp_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, self-authored <script> with no dynamic input.
			}

			// Stored raw_css is emitted unless a site opts out via
			// uichemy/composer/allow_custom_code. Authoring CSS remains a Pro feature
			// (the editor panel is Pro-only); this only governs code that is already
			// stored, which imported headers/footers/pages depend on entirely.
			$css_source = ( uichemy_custom_code_allowed() && '' !== (string) ( $settings['raw_css'] ?? '' ) )
				? ( $content_trusted
					? $settings['raw_css']
					: \UiChemy_Composer_Manager::sanitize_css_block( $settings['raw_css'] ) )
				: '';
			$scoped_css = '' !== $css_source
				? $this->scope_css_to_widget( $css_source, $widget_scope_selector )
				: '';

			// This section's own CSS was already printed in <head> (Fast Load critical
			// CSS, or the section's 'Before Head' placement): don't print it again here.
			// The synthesized global-typography CSS below is not part of that head
			// output, so it still goes out.
			if ( class_exists( 'UiChemy_Fast_Load' ) && UiChemy_Fast_Load::is_css_in_head( $this->get_id() ) ) {
				$scoped_css = '';
			}

			// 'Before Body' placement: this section's own CSS is printed just before
			// </body> instead of through the footer stylesheet (Normal).
			if ( '' !== $scoped_css && 'body' === (string) ( $settings['raw_css_placement'] ?? '' ) && class_exists( 'UiChemy_Fast_Load' ) ) {
				UiChemy_Fast_Load::queue_body_code( 'css', $this->get_id(), $scoped_css );
				$scoped_css = '';
			}

			// Prepend synthesized CSS for referenced global typography classes so the
			// widget's raw_css (emitted after) can still override it in the cascade.
			$global_typo_css = $this->build_referenced_global_typography_css( $output, $widget_scope_selector );

			$combined_css = $global_typo_css;
			if ( '' !== $global_typo_css && '' !== $scoped_css ) {
				$combined_css .= "\n";
			}
			$combined_css .= $scoped_css;

			if ( '' !== $combined_css ) {
				if ( $is_editor ) {
					// In the editor, inject CSS via JS into <head> so it is never inside
					// the widget's inner HTML. This means the CSS survives Elementor's
					// widget re-render cycle (panel open/close, settings changes) without
					// any flash or layout collapse — <head> styles are untouched by DOM
					// updates to the widget container.
					$widget_id  = esc_js( $this->get_id() );
					$css_json   = wp_json_encode( $combined_css );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo "<script>(function(){var id='uich-w-" . $widget_id . "';var old=document.getElementById(id);if(old)old.parentNode.removeChild(old);var s=document.createElement('style');s.id=id;s.textContent=" . $css_json . ";document.head.appendChild(s);})();</script>";
				} else {
					// Frontend: hand the per-instance CSS to WordPress through the
					// core stylesheet pipeline via wp_add_inline_style() instead of
					// printing a hand-written <style> tag.
					$this->enqueue_inline_widget_css( $combined_css );
				}
			}

			// Stored raw_js is emitted unless a site opts out via
			// uichemy/composer/allow_custom_code (see the raw_css note above). Authoring
			// JS remains a Pro feature; imported designs may ship behaviour here.
			if ( uichemy_custom_code_allowed() && ! empty( $settings['raw_js'] ) ) {
				if ( $is_editor ) {
					echo $this->build_editor_js_runtime( $settings['raw_js'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Editor-preview only ($is_editor); the author JS is wp_json_encode()'d into a safe JS string literal, never raw front-end output.
				} elseif ( $content_trusted ) {
					// Frontend: hand the author's inline JS to WordPress through the
					// core enqueue pipeline via wp_add_inline_script() instead of
					// printing a hand-written <script> tag. Emitted only when the
					// content author holds `unfiltered_html` (mirroring core's Custom
					// HTML block); for everyone else the field was already blanked at
					// save time by
					// UiChemy_Composer_Manager::sanitize_composer_widget_code_on_save().
					// Wrapped so element lookups scope to this widget instance —
					// otherwise a duplicated section of the same template type would
					// re-target the first instance and never initialise its own
					// nodes (see build_frontend_js_runtime()).
					// 'Before Head' placement prints the JS in <head> (see UiChemy_Fast_Load);
					// everything else keeps the footer output.
					if ( class_exists( 'UiChemy_Fast_Load' ) && UiChemy_Fast_Load::is_js_in_head( $this->get_id() ) ) {
						// Printed in <head> already.
					} elseif ( 'body' === (string) ( $settings['raw_js_placement'] ?? '' ) && class_exists( 'UiChemy_Fast_Load' ) ) {
						// 'Before Body': printed just before </body>, after the other footer scripts.
						UiChemy_Fast_Load::queue_body_code( 'js', $this->get_id(), $this->build_frontend_js_runtime( $settings['raw_js'] ) );
					} else {
						$this->enqueue_inline_widget_js( $this->build_frontend_js_runtime( $settings['raw_js'] ), $script_deps );
					}
				}
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $deps_after ) echo $deps_after;
		}
	}
}