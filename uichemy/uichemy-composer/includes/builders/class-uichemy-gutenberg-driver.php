<?php
/**
 * Gutenberg (block editor) storage driver.
 *
 * Composer sections are `uichemy/composer` blocks in post_content, carrying
 * attrs.uid and attrs.settings. The block is server-rendered, so a self-closing
 * block with those two attributes is the whole payload — see
 * includes/blocks/class-uichemy-gutenberg-composer.php.
 *
 * Always available: the block editor is WordPress core, which is why this is
 * the driver that can be exercised on any site and therefore the one that
 * proves the load/save abstraction before Bricks is attempted.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Gutenberg_Driver' ) ) {

	/**
	 * Reads and writes Composer sections as blocks in post_content.
	 */
	class UiChemy_Gutenberg_Driver extends UiChemy_Builder_Driver_Base {

		/**
		 * The block that is a Composer section.
		 */
		const BLOCK_NAME = 'uichemy/composer';

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $slug = 'gutenberg';

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $label = 'Gutenberg';

		/**
		 * {@inheritDoc}
		 *
		 * No dynamic_tags: {{ }} binding is applied by
		 * UiChemy_Composer_Manager::apply_dynamic_tag_bindings(), which walks an
		 * Elementor tree. Claiming it here would silently drop tokens.
		 *
		 * @var string[]
		 */
		protected $caps = array( 'page_custom_code', 'theme_builder_native' );

		/**
		 * {@inheritDoc}
		 *
		 * Core. The only way this is false is the site owner switching the
		 * Composer block off in Settings, which the registry checks separately.
		 */
		public function is_available() {
			return function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Claims a post only when it actually holds a Composer block. Every post
		 * has a post_content, so a looser test here would claim Elementor and
		 * Bricks posts too — this is why the registry probes Gutenberg last.
		 */
		public function owns_post( $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post ) {
				return false;
			}

			return has_block( self::BLOCK_NAME, $post );
		}

		/**
		 * {@inheritDoc}
		 */
		public function create_post( array $attrs ) {
			return $this->insert_post( $attrs );
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

			$sections = array();
			$blocks   = parse_blocks( (string) $post->post_content );
			$this->collect( $blocks, $sections );

			return UiChemy_Section::reindex( $sections );
		}

		/**
		 * Depth-first walk collecting Composer blocks in document order.
		 *
		 * Recurses innerBlocks so a section nested inside a group or columns
		 * block is still found; a flat scan would miss it and then delete it on
		 * the next save, because save_sections() treats an absent uid as a
		 * deletion.
		 *
		 * @param array $blocks   parse_blocks() tree.
		 * @param array $sections Accumulator (by reference).
		 * @return void
		 */
		private function collect( array $blocks, array &$sections ) {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				if ( isset( $block['blockName'] ) && self::BLOCK_NAME === $block['blockName'] ) {
					$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
					$settings = isset( $attrs['settings'] ) && is_array( $attrs['settings'] ) ? $attrs['settings'] : array();

					$sections[] = UiChemy_Section::make(
						array(
							'uid'      => isset( $attrs['uid'] ) ? (string) $attrs['uid'] : '',
							'area'     => 'content',
							'settings' => $settings,
							'native'   => $block,
						)
					);
				}

				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$this->collect( $block['innerBlocks'], $sections );
				}
			}
		}

		/**
		 * {@inheritDoc}
		 *
		 * Reconciles by uid against the parsed tree: a known uid keeps its block
		 * in place (so surrounding non-Composer blocks and any wrapper group are
		 * preserved) and only has its settings replaced; an unknown uid is
		 * appended at top level; a uid no longer in the list is removed.
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

			$keep = array();
			foreach ( $sections as $section ) {
				if ( is_array( $section ) && ! empty( $section['uid'] ) ) {
					$keep[ (string) $section['uid'] ] = is_array( $section['settings'] ) ? $section['settings'] : array();
				}
			}

			$blocks = parse_blocks( (string) $post->post_content );
			$seen   = array();
			$blocks = $this->reconcile( $blocks, $keep, $seen );

			// Anything not already in the tree is new, appended in list order.
			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$uid = isset( $section['uid'] ) ? (string) $section['uid'] : '';
				if ( '' !== $uid && isset( $seen[ $uid ] ) ) {
					continue;
				}
				if ( '' === $uid ) {
					$uid = UiChemy_Section::mint_uid();
				}
				$blocks[] = $this->block_node( $uid, is_array( $section['settings'] ) ? $section['settings'] : array() );
			}

			// The order of top-level Composer blocks follows the caller's list.
			$blocks = $this->apply_order( $blocks, $sections );

			// wp_update_post() unconditionally wp_unslash()es its input, so the
			// serialized markup must be slashed first or every literal backslash
			// and every `<` inside raw_html/raw_css is corrupted on the way in.
			$result = wp_update_post(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => wp_slash( serialize_blocks( $blocks ) ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		/**
		 * Update-or-drop each existing Composer block, depth first.
		 *
		 * @param array $blocks Block tree.
		 * @param array $keep   uid => settings for the sections that survive.
		 * @param array $seen   uids found in the tree (by reference).
		 * @return array
		 */
		private function reconcile( array $blocks, array $keep, array &$seen ) {
			$out = array();

			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					$out[] = $block;
					continue;
				}

				if ( isset( $block['blockName'] ) && self::BLOCK_NAME === $block['blockName'] ) {
					$uid = isset( $block['attrs']['uid'] ) ? (string) $block['attrs']['uid'] : '';
					if ( '' === $uid || ! isset( $keep[ $uid ] ) ) {
						// Absent from the caller's list: this is the deletion path.
						continue;
					}
					$seen[ $uid ]               = true;
					$block['attrs']['uid']      = $uid;
					$block['attrs']['settings'] = $keep[ $uid ];
					$out[]                      = $block;
					continue;
				}

				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = $this->reconcile( $block['innerBlocks'], $keep, $seen );
				}

				$out[] = $block;
			}

			return $out;
		}

		/**
		 * Reorder the TOP-LEVEL Composer blocks to match the caller's list,
		 * leaving every other block and every nested section where it is.
		 *
		 * Nested sections are deliberately not re-homed: moving a block out of
		 * its group would change the rendered layout, which is not what a
		 * "move section" was asked to do. This mirrors the cross-container rule
		 * the Elementor mover has always applied.
		 *
		 * @param array $blocks   Block tree.
		 * @param array $sections Ordered section list.
		 * @return array
		 */
		private function apply_order( array $blocks, array $sections ) {
			$order = array();
			foreach ( $sections as $i => $section ) {
				if ( is_array( $section ) && ! empty( $section['uid'] ) ) {
					$order[ (string) $section['uid'] ] = $i;
				}
			}

			$slots   = array();
			$movable = array();
			foreach ( $blocks as $i => $block ) {
				if ( is_array( $block ) && isset( $block['blockName'] ) && self::BLOCK_NAME === $block['blockName'] ) {
					$uid = isset( $block['attrs']['uid'] ) ? (string) $block['attrs']['uid'] : '';
					if ( isset( $order[ $uid ] ) ) {
						$slots[]         = $i;
						$movable[ $uid ] = $block;
					}
				}
			}

			if ( count( $slots ) < 2 ) {
				return $blocks;
			}

			uasort(
				$movable,
				function ( $a, $b ) use ( $order ) {
					$ua = isset( $a['attrs']['uid'] ) ? (string) $a['attrs']['uid'] : '';
					$ub = isset( $b['attrs']['uid'] ) ? (string) $b['attrs']['uid'] : '';

					return $order[ $ua ] <=> $order[ $ub ];
				}
			);

			$sorted = array_values( $movable );
			foreach ( $slots as $n => $position ) {
				$blocks[ $position ] = $sorted[ $n ];
			}

			return $blocks;
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
					'native'   => $this->block_node( $uid, $settings ),
				)
			);
		}

		/**
		 * A self-closing uichemy/composer block node.
		 *
		 * @param string $uid      Section uid.
		 * @param array  $settings Neutral settings map.
		 * @return array
		 */
		private function block_node( $uid, array $settings ) {
			return array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => array(
					'uid'      => (string) $uid,
					'settings' => $settings,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
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

			$blocks    = parse_blocks( (string) $post->post_content );
			$counter   = 0;
			$structure = $this->summarize( $blocks, $counter );

			return array_merge(
				array(
					'post_id'               => (int) $post->ID,
					'post_title'            => $post->post_title,
					'post_type'             => $post->post_type,
					'post_status'           => $post->post_status,
					'builder'               => $this->slug,
					'top_level_count'       => count( $structure ),
					'total_uichemy_widgets' => $counter,
					'structure'             => $structure,
				),
				$this->edit_links( $post->ID )
			);
		}

		/**
		 * Recursive block summary, mirroring the shape the Elementor structure
		 * walker returns so a client reads one format for every builder.
		 *
		 * @param array $blocks  Block tree.
		 * @param int   $counter Composer section counter (by reference).
		 * @return array
		 */
		private function summarize( array $blocks, &$counter ) {
			$out = array();

			foreach ( $blocks as $index => $block ) {
				if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
					continue;
				}

				$node = array(
					'index' => $index,
					'id'    => isset( $block['attrs']['uid'] ) ? (string) $block['attrs']['uid'] : '',
					'type'  => (string) $block['blockName'],
				);

				if ( self::BLOCK_NAME === $block['blockName'] ) {
					$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
					$settings = isset( $attrs['settings'] ) && is_array( $attrs['settings'] ) ? $attrs['settings'] : array();

					$node['id']                   = isset( $attrs['uid'] ) ? (string) $attrs['uid'] : '';
					$node['label']                = isset( $settings['_title'] ) ? (string) $settings['_title'] : '';
					$node['uichemy_widget_index'] = $counter++;
					$node['has_html']             = ! empty( $settings['raw_html'] );
					$node['has_css']              = ! empty( $settings['raw_css'] );
					$node['has_js']               = ! empty( $settings['raw_js'] );
				}

				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$children = $this->summarize( $block['innerBlocks'], $counter );
					if ( $children ) {
						$node['children'] = $children;
					}
				}

				$out[] = $node;
			}

			return $out;
		}

		/**
		 * {@inheritDoc}
		 */
		public function grep_query_args( array $args ) {
			// No meta to key off — the blocks live in post_content. The block
			// delimiter is a stable literal, so a LIKE on it is exact enough to
			// narrow the set before the per-post scan.
			$args['s'] = '<!-- wp:' . self::BLOCK_NAME;

			return $args;
		}

		/**
		 * {@inheritDoc}
		 */
		public function readiness() {
			$ready = $this->is_available();

			return array(
				'ready'  => $ready,
				'checks' => array(
					'block_editor'  => $ready,
					'block_enabled' => ! function_exists( 'uichemy_composer_enabled' ) || uichemy_composer_enabled( 'gutenberg' ),
				),
				'notes'  => $ready
					? array()
					: array( 'The block editor functions are unavailable on this site.' ),
			);
		}
	}
}
