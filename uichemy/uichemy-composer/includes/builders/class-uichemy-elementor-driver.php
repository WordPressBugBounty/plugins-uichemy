<?php
/**
 * Elementor storage driver.
 *
 * Composer sections are widget nodes inside the `_elementor_data` JSON tree,
 * nested under containers. Reads MUST go through uichemy_is_composer_widget_node()
 * rather than matching the current widget type literally: a site that has not
 * finished Uich_Composer_Migration still holds `proton` and `uichemy-builder`
 * nodes, and matching only the new name skips them — they render, but lose
 * their globals, their shared custom code and their MCP visibility.
 *
 * Writes always use the current name, `uichemy-composer`, literally.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Elementor_Driver' ) ) {

	/**
	 * Reads and writes Composer sections as Elementor widgets.
	 */
	class UiChemy_Elementor_Driver extends UiChemy_Builder_Driver_Base {

		/**
		 * The widget type written for a NEW section. Reads accept the legacy
		 * names too; writes never do.
		 */
		const WIDGET_TYPE = 'uichemy-composer';

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $slug = 'elementor';

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $label = 'Elementor';

		/**
		 * {@inheritDoc}
		 *
		 * @var string[]
		 */
		protected $caps = array(
			'page_custom_code',
			'dynamic_tags',
			'theme_builder_native',
			'atomic_globals',
		);

		/**
		 * {@inheritDoc}
		 */
		public function is_available() {
			return class_exists( '\Elementor\Plugin' );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Both halves are needed: the edit-mode flag alone is set on posts that
		 * were opened in Elementor and never saved, which hold no data.
		 */
		public function owns_post( $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return false;
			}

			if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
				return false;
			}

			$raw = get_post_meta( $post_id, '_elementor_data', true );

			return is_string( $raw ) && '' !== trim( $raw ) && '[]' !== trim( $raw );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Uses Elementor's own document factory so the post gets every piece of
		 * document meta Elementor expects, then stamps the meta the factory does
		 * not guarantee.
		 */
		public function create_post( array $attrs ) {
			if ( ! $this->is_available() ) {
				return new WP_Error( 'uich_elementor_missing', 'Elementor is not active.' );
			}

			// The document type stays 'page' whatever the post type is: it picks
			// the wp-page editing experience (no theme-builder conditions), which
			// is what a Composer-built post wants too.
			$document = \Elementor\Plugin::$instance->documents->create(
				'page',
				array(
					'post_title'  => isset( $attrs['post_title'] ) ? sanitize_text_field( (string) $attrs['post_title'] ) : '',
					'post_type'   => isset( $attrs['post_type'] ) ? sanitize_key( (string) $attrs['post_type'] ) : 'page',
					'post_status' => isset( $attrs['post_status'] ) ? sanitize_key( (string) $attrs['post_status'] ) : 'draft',
				)
			);

			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$post_id = $document ? (int) $document->get_main_id() : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'uich_page_create_failed', 'Failed to create Elementor page document.' );
			}

			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			// Elementor expects an ARRAY here; a JSON string such as "{}" can
			// throw a type error deep inside the editor.
			update_post_meta( $post_id, '_elementor_page_settings', array() );

			return $post_id;
		}

		/**
		 * Decode a post's element tree.
		 *
		 * @param int $post_id Post id.
		 * @return array|WP_Error
		 */
		private function load_tree( $post_id ) {
			$raw = get_post_meta( absint( $post_id ), '_elementor_data', true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return array();
			}

			$elements = json_decode( $raw, true );
			if ( ! is_array( $elements ) ) {
				return new WP_Error( 'uich_invalid_elementor_data', 'Elementor data is not valid JSON.' );
			}

			return $elements;
		}

		/**
		 * {@inheritDoc}
		 */
		public function load_sections( $post_id, $area = 'content' ) {
			$ok = $this->require_content_area( $area );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$elements = $this->load_tree( $post->ID );
			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$sections = array();
			$this->collect( $elements, '', $sections );

			return UiChemy_Section::reindex( $sections );
		}

		/**
		 * Depth-first collect, recording each widget's parent container id so a
		 * later save can put it back where it came from.
		 *
		 * @param array  $elements Element tree.
		 * @param string $parent_id   Parent container id.
		 * @param array  $sections Accumulator (by reference).
		 * @return void
		 */
		private function collect( array $elements, $parent_id, array &$sections ) {
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				if ( uichemy_is_composer_widget_node( $element ) ) {
					$sections[] = UiChemy_Section::make(
						array(
							'uid'       => isset( $element['id'] ) ? (string) $element['id'] : '',
							'area'      => 'content',
							'settings'  => isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(),
							'container' => (string) $parent_id,
							'native'    => $element,
						)
					);
				}

				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$this->collect( $element['elements'], isset( $element['id'] ) ? (string) $element['id'] : '', $sections );
				}
			}
		}

		/**
		 * {@inheritDoc}
		 */
		public function save_sections( $post_id, array $sections, $area = 'content' ) {
			$ok = $this->require_content_area( $area );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post_id  = (int) $post->ID;
			$elements = $this->load_tree( $post_id );
			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$keep = array();
			foreach ( $sections as $section ) {
				if ( is_array( $section ) && ! empty( $section['uid'] ) ) {
					$keep[ (string) $section['uid'] ] = is_array( $section['settings'] ) ? $section['settings'] : array();
				}
			}

			$seen     = array();
			$elements = $this->reconcile( $elements, $keep, $seen );

			// Whatever the caller listed but the tree does not hold is new. New
			// nodes are grafted in BEFORE the reorder, not after: appending them
			// afterwards would pin every freshly inserted or duplicated section
			// to the end of its container regardless of the position asked for.
			$fresh = array();
			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$uid = isset( $section['uid'] ) ? (string) $section['uid'] : '';
				if ( '' !== $uid && isset( $seen[ $uid ] ) ) {
					continue;
				}
				$fresh[] = $this->widget_node(
					'' !== $uid ? $uid : UiChemy_Section::mint_uid(),
					is_array( $section['settings'] ) ? $section['settings'] : array()
				);
			}

			if ( $fresh ) {
				$elements = $this->append_widgets( $elements, $fresh, $post_id );
			}

			$elements = $this->apply_order( $elements, $sections );

			return $this->persist( $post_id, $elements );
		}

		/**
		 * Update-or-drop each existing Composer widget, depth first.
		 *
		 * @param array $elements Element tree.
		 * @param array $keep     uid => settings.
		 * @param array $seen     uids found (by reference).
		 * @return array
		 */
		private function reconcile( array $elements, array $keep, array &$seen ) {
			$out = array();

			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					$out[] = $element;
					continue;
				}

				if ( uichemy_is_composer_widget_node( $element ) ) {
					$uid = isset( $element['id'] ) ? (string) $element['id'] : '';
					if ( '' === $uid || ! isset( $keep[ $uid ] ) ) {
						continue;
					}
					$seen[ $uid ]        = true;
					$element['settings'] = $keep[ $uid ];
					$out[]               = $element;
					continue;
				}

				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$element['elements'] = $this->reconcile( $element['elements'], $keep, $seen );
				}

				$out[] = $element;
			}

			return $out;
		}

		/**
		 * Reorder Composer widgets to the caller's order WITHIN each container.
		 *
		 * A cross-container move is deliberately not performed — it would change
		 * the rendered layout, which "move section" was not asked to do. This is
		 * the same limitation the original Elementor mover documented.
		 *
		 * @param array $elements Element tree.
		 * @param array $sections Ordered section list.
		 * @return array
		 */
		private function apply_order( array $elements, array $sections ) {
			$order = array();
			foreach ( $sections as $i => $section ) {
				if ( is_array( $section ) && ! empty( $section['uid'] ) ) {
					$order[ (string) $section['uid'] ] = $i;
				}
			}

			return $this->sort_branch( $elements, $order );
		}

		/**
		 * Sort one branch's Composer widgets, then recurse.
		 *
		 * @param array $elements Branch.
		 * @param array $order    uid => desired position.
		 * @return array
		 */
		private function sort_branch( array $elements, array $order ) {
			$slots   = array();
			$movable = array();

			foreach ( $elements as $i => $element ) {
				if ( is_array( $element ) && uichemy_is_composer_widget_node( $element ) ) {
					$uid = isset( $element['id'] ) ? (string) $element['id'] : '';
					if ( isset( $order[ $uid ] ) ) {
						$slots[]   = $i;
						$movable[] = $element;
					}
				}
			}

			if ( count( $slots ) > 1 ) {
				usort(
					$movable,
					function ( $a, $b ) use ( $order ) {
						return $order[ (string) $a['id'] ] <=> $order[ (string) $b['id'] ];
					}
				);
				foreach ( $slots as $n => $position ) {
					$elements[ $position ] = $movable[ $n ];
				}
			}

			foreach ( $elements as $i => $element ) {
				if ( is_array( $element ) && ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$element['elements'] = $this->sort_branch( $element['elements'], $order );
					$elements[ $i ]      = $element;
				}
			}

			return $elements;
		}

		/**
		 * Append new widgets, reusing the container that already holds Composer
		 * widgets so a page does not accumulate one container per section.
		 *
		 * @param array $elements Element tree.
		 * @param array $widgets  New widget nodes.
		 * @param int   $post_id  Post the tree belongs to, used to title a new container.
		 * @return array
		 */
		private function append_widgets( array $elements, array $widgets, $post_id = 0 ) {
			foreach ( $elements as $i => $element ) {
				if ( ! is_array( $element ) || empty( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
					continue;
				}
				foreach ( $element['elements'] as $child ) {
					if ( is_array( $child ) && uichemy_is_composer_widget_node( $child ) ) {
						$element['elements'] = array_merge( $element['elements'], $widgets );
						$elements[ $i ]      = $element;

						return $elements;
					}
				}
			}

			$elements[] = $this->container_node( $widgets, $post_id );

			return $elements;
		}

		/**
		 * {@inheritDoc}
		 */
		public function new_section( array $settings, array $opts = array() ) {
			unset( $opts );
			$uid = UiChemy_Section::mint_uid();

			return UiChemy_Section::make(
				array(
					'uid'      => $uid,
					'area'     => 'content',
					'settings' => $settings,
					'native'   => $this->widget_node( $uid, $settings ),
				)
			);
		}

		/**
		 * One Composer widget node.
		 *
		 * @param string $uid      Element id.
		 * @param array  $settings Neutral settings map.
		 * @return array
		 */
		private function widget_node( $uid, array $settings ) {
			return array(
				'id'         => (string) $uid,
				'elType'     => 'widget',
				'widgetType' => self::WIDGET_TYPE,
				'settings'   => $settings,
				'elements'   => array(),
			);
		}

		/**
		 * A zeroed container wrapping the given widgets, so the Composer CSS is
		 * the single source of truth for spacing.
		 *
		 * @param array $widgets Widget nodes.
		 * @param int   $post_id Post the container belongs to.
		 * @return array
		 */
		private function container_node( array $widgets, $post_id = 0 ) {
			// Title the wrapper with the post's own title so Elementor's navigator
			// shows something meaningful instead of a bare "Container".
			$extra = array();
			$title = $post_id ? (string) get_the_title( $post_id ) : '';
			if ( '' !== $title ) {
				$extra['_title'] = $title;
			}

			$settings = $extra;
			if ( class_exists( 'UiChemy_Composer_Manager' ) && method_exists( 'UiChemy_Composer_Manager', 'mcp_widget_container_default_settings' ) ) {
				$settings = UiChemy_Composer_Manager::mcp_widget_container_default_settings( $extra );
			}

			return array(
				'id'       => UiChemy_Section::mint_uid(),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => $settings,
				'elements' => $widgets,
			);
		}

		/**
		 * Write the tree back.
		 *
		 * Document::save() publishes as a side effect, so the status is captured
		 * and restored around it — every structural op routes through here, and
		 * silently publishing someone's draft is not an acceptable side effect
		 * of reordering a section.
		 *
		 * @param int   $post_id  Post id.
		 * @param array $elements Element tree.
		 * @return true|WP_Error
		 */
		private function persist( $post_id, array $elements ) {
			$status_before = get_post_status( $post_id );

			if ( $this->is_available() ) {
				$document = \Elementor\Plugin::$instance->documents->get_doc_or_auto_save( $post_id );
				if ( $document ) {
					try {
						$document->save( array( 'elements' => $elements ) );
					} catch ( \Throwable $e ) {
						// The authoritative meta write below still applies.
						unset( $e );
					}
					$this->restore_status( $post_id, $status_before );
				}
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
			$this->after_write( $post_id );

			return true;
		}

		/**
		 * Put a post's status back after an Elementor save flipped it. Never
		 * touches auto-drafts, which Elementor legitimately promotes.
		 *
		 * @param int    $post_id Post id.
		 * @param string $before  Status before the save.
		 * @return void
		 */
		private function restore_status( $post_id, $before ) {
			$before = (string) $before;
			if ( '' === $before || 'auto-draft' === $before ) {
				return;
			}

			$after = (string) get_post_status( $post_id );
			if ( $after === $before || '' === $after ) {
				return;
			}

			wp_update_post(
				array(
					'ID'          => (int) $post_id,
					'post_status' => $before,
				)
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function describe_structure( $post_id ) {
			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$elements = $this->load_tree( $post->ID );
			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$counter   = 0;
			$structure = $this->summarize( $elements, $counter );

			return array_merge(
				array(
					'post_id'               => (int) $post->ID,
					'post_title'            => $post->post_title,
					'post_type'             => $post->post_type,
					'post_status'           => $post->post_status,
					'builder'               => $this->slug,
					'elementor_edit_mode'   => get_post_meta( $post->ID, '_elementor_edit_mode', true ) ?: 'builder',
					'top_level_count'       => count( $elements ),
					'total_uichemy_widgets' => $counter,
					'structure'             => $structure,
				),
				$this->edit_links( $post->ID )
			);
		}

		/**
		 * Recursive Elementor tree summary.
		 *
		 * Emits `elType` / `widgetType` because those ARE Elementor's node
		 * vocabulary and clients have always read them, alongside the `type` key
		 * every builder reports.
		 *
		 * @param array $elements Element tree.
		 * @param int   $counter  Composer section counter (by reference).
		 * @return array
		 */
		private function summarize( array $elements, &$counter ) {
			$summary = array();

			foreach ( $elements as $index => $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$el_type     = isset( $element['elType'] ) ? $element['elType'] : 'unknown';
				$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : null;
				$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				$node = array(
					'index'  => $index,
					'id'     => isset( $element['id'] ) ? $element['id'] : '',
					'elType' => $el_type,
					'type'   => $el_type,
				);

				if ( $widget_type ) {
					$node['widgetType'] = $widget_type;
				}
				if ( uichemy_is_composer_widget_type( $widget_type ) ) {
					$node['uichemy_widget_index'] = $counter++;
					$node['label']                = isset( $settings['_title'] ) ? $settings['_title'] : '';
					$node['has_html']             = isset( $settings['raw_html'] ) && '' !== trim( $settings['raw_html'] );
					$node['has_css']              = isset( $settings['raw_css'] ) && '' !== trim( $settings['raw_css'] );
					$node['has_js']               = isset( $settings['raw_js'] ) && '' !== trim( $settings['raw_js'] );
				} elseif ( isset( $settings['_title'] ) && '' !== $settings['_title'] ) {
					$node['label'] = $settings['_title'];
				}

				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$node['children'] = $this->summarize( $element['elements'], $counter );
				}

				$summary[] = $node;
			}

			return $summary;
		}

		/**
		 * {@inheritDoc}
		 */
		public function edit_links( $post_id ) {
			$post_id = absint( $post_id );

			// Key ORDER is deliberate and matches what these responses have always
			// carried, so a consumer reading them positionally is unaffected.
			return array(
				'edit_link'      => (string) get_edit_post_link( $post_id, 'internal' ),
				'elementor_link' => add_query_arg(
					array(
						'post'   => $post_id,
						'action' => 'elementor',
					),
					admin_url( 'post.php' )
				),
				'preview_link'   => (string) get_permalink( $post_id ),
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function grep_query_args( array $args ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_elementor_data',
					'compare' => 'EXISTS',
				),
			);

			return $args;
		}

		/**
		 * {@inheritDoc}
		 */
		public function after_write( $post_id ) {
			delete_post_meta( absint( $post_id ), '_elementor_element_cache' );

			if ( $this->is_available() && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		/**
		 * {@inheritDoc}
		 */
		public function readiness() {
			$active = $this->is_available();
			$kit    = false;

			if ( $active && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
				$kit    = $kit_id > 0;
			}

			$notes = array();
			if ( ! $active ) {
				$notes[] = 'Elementor is not active on this site.';
			} elseif ( ! $kit ) {
				$notes[] = 'Elementor is active but has no active kit.';
			}

			return array(
				'ready'  => $active && $kit,
				'checks' => array(
					'elementor_active'  => $active,
					'active_kit_found'  => $kit,
					'elementor_enabled' => ! function_exists( 'uichemy_composer_enabled' ) || uichemy_composer_enabled( 'elementor' ),
				),
				'notes'  => $notes,
			);
		}
	}
}
