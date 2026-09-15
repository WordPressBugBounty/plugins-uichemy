<?php
/**
 * Bricks Builder storage driver.
 *
 * Bricks keeps each content area as a FLAT array of element arrays in its own
 * post meta key, joined into a tree by `parent` / `children` ids. A Composer
 * section is one element named `uichemy-composer` whose entire payload is a
 * JSON string at settings['uichemy_settings'] — see
 * includes/bricks/class-uichemy-bricks-composer.php.
 *
 * Bricks is a THEME, not a plugin, so availability is a stylesheet check and
 * the element class only ever loads through \Bricks\Elements::register_element().
 *
 * Two traps this driver exists to contain, both of which corrupt a whole page
 * rather than one section:
 *
 *   wp_slash  update_post_meta() runs wp_unslash() over the WHOLE value. The
 *             array is read UNslashed from get_post_meta(), so writing it back
 *             without re-slashing strips backslashes from every string in every
 *             element on the page — CSS escapes, JS, JSON quotes — and blanks it.
 *   uid       the renderer derives its scope class through sanitize_html_class(),
 *             so a lookup that only compares the sanitized form silently misses
 *             any element whose real id that sanitiser would alter.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Bricks_Driver' ) ) {

	/**
	 * Reads and writes Composer sections as Bricks elements.
	 */
	class UiChemy_Bricks_Driver extends UiChemy_Builder_Driver_Base {

		/**
		 * The Bricks element name that is a Composer section.
		 */
		const ELEMENT_NAME = 'uichemy-composer';

		/**
		 * Where the section payload lives inside the element's settings.
		 */
		const SETTINGS_KEY = 'uichemy_settings';

		/**
		 * Bricks' content-schema suffixes, newest first.
		 *
		 * The trailing number in `_bricks_page_content_2` is a Bricks schema
		 * version, not a constant. Hardcoding it means a Bricks major bump reads
		 * an empty page and reports the post as having no sections, so the key
		 * is DISCOVERED per post and this list is only the search order.
		 */
		const SCHEMA_SUFFIXES = array( '2', '3', '' );

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $slug = 'bricks';

		/**
		 * {@inheritDoc}
		 *
		 * @var string
		 */
		protected $label = 'Bricks';

		/**
		 * {@inheritDoc}
		 *
		 * Bricks keeps genuinely separate header/footer streams, which neither
		 * of the other builders does.
		 *
		 * @var string[]
		 */
		protected $caps = array( 'page_custom_code', 'header_footer_areas' );

		/**
		 * {@inheritDoc}
		 *
		 * A theme check, not a plugin check. \Bricks\Elements is required too:
		 * the stylesheet can be `bricks` while the theme is still booting, and
		 * this driver is reachable from `init`.
		 */
		public function is_available() {
			return 'bricks' === get_stylesheet() || class_exists( '\Bricks\Elements' );
		}

		/**
		 * The meta key holding one area's elements for this post.
		 *
		 * Bricks publishes these as constants, and they are ALWAYS preferred:
		 * the trailing number is a Bricks schema version, so reading the
		 * constant is what keeps this driver correct across a Bricks major bump
		 * instead of silently reading an empty page from a stale key.
		 *
		 * The literal fallbacks below are only for the case where the theme is
		 * not loaded yet — then a key already present on the post wins, and the
		 * newest known suffix is the last resort.
		 *
		 * @param int    $post_id Post id.
		 * @param string $area    content | header | footer.
		 * @return string
		 */
		private function meta_key( $post_id, $area = 'content' ) {
			$area = UiChemy_Section::area( $area );

			$constants = array(
				'content' => 'BRICKS_DB_PAGE_CONTENT',
				'header'  => 'BRICKS_DB_PAGE_HEADER',
				'footer'  => 'BRICKS_DB_PAGE_FOOTER',
			);
			if ( isset( $constants[ $area ] ) && defined( $constants[ $area ] ) ) {
				return (string) constant( $constants[ $area ] );
			}

			$base = '_bricks_page_' . $area;
			foreach ( self::SCHEMA_SUFFIXES as $suffix ) {
				$key = '' === $suffix ? $base : $base . '_' . $suffix;
				if ( metadata_exists( 'post', absint( $post_id ), $key ) ) {
					return $key;
				}
			}

			return $base . '_' . self::SCHEMA_SUFFIXES[0];
		}

		/**
		 * Every area meta key that exists on a post.
		 *
		 * @param int $post_id Post id.
		 * @return array<string,string> area => meta key.
		 */
		private function area_keys( $post_id ) {
			$keys = array();
			foreach ( UiChemy_Section::AREAS as $area ) {
				$keys[ $area ] = $this->meta_key( $post_id, $area );
			}

			return $keys;
		}

		/**
		 * Does this element's id answer to the given uid?
		 *
		 * Compares the raw id AND its sanitized form, because the renderer
		 * publishes the sanitized one in the scope class while storage keeps the
		 * raw one. Matching on only one of the two loses every element whose id
		 * differs between them.
		 *
		 * @param array  $element Bricks element.
		 * @param string $uid     Target uid.
		 * @return bool
		 */
		private function element_matches( $element, $uid ) {
			if ( ! is_array( $element ) || ! isset( $element['id'], $element['name'] ) ) {
				return false;
			}
			if ( self::ELEMENT_NAME !== $element['name'] ) {
				return false;
			}

			$id  = (string) $element['id'];
			$uid = (string) $uid;

			return $id === $uid || sanitize_html_class( $id ) === $uid;
		}

		/**
		 * {@inheritDoc}
		 */
		public function owns_post( $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return false;
			}

			foreach ( $this->area_keys( $post_id ) as $key ) {
				$elements = get_post_meta( $post_id, $key, true );
				if ( is_array( $elements ) && $elements ) {
					return true;
				}
			}

			return 'bricks' === (string) get_post_meta( $post_id, '_bricks_editor_mode', true );
		}

		/**
		 * {@inheritDoc}
		 */
		public function create_post( array $attrs ) {
			$post_id = $this->insert_post( $attrs );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			// Without this Bricks opens the post in the WordPress editor and
			// never reads the elements we are about to write.
			update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

			return $post_id;
		}

		/**
		 * {@inheritDoc}
		 */
		public function load_sections( $post_id, $area = 'content' ) {
			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$area     = UiChemy_Section::area( $area );
			$elements = get_post_meta( (int) $post->ID, $this->meta_key( $post->ID, $area ), true );
			if ( ! is_array( $elements ) ) {
				return array();
			}

			$sections = array();
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) || ! isset( $element['name'] ) || self::ELEMENT_NAME !== $element['name'] ) {
					continue;
				}

				$sections[] = UiChemy_Section::make(
					array(
						'uid'       => isset( $element['id'] ) ? (string) $element['id'] : '',
						'area'      => $area,
						'settings'  => $this->decode_settings( $element ),
						'container' => isset( $element['parent'] ) ? (string) $element['parent'] : '',
						'native'    => $element,
					)
				);
			}

			return UiChemy_Section::reindex( $sections );
		}

		/**
		 * Unpack the JSON blob a Bricks element carries.
		 *
		 * @param array $element Bricks element.
		 * @return array
		 */
		private function decode_settings( array $element ) {
			$raw      = isset( $element['settings'][ self::SETTINGS_KEY ] ) ? (string) $element['settings'][ self::SETTINGS_KEY ] : '{}';
			$settings = json_decode( $raw, true );

			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Pack the neutral map back into a Bricks element, preserving every
		 * other setting the element carries (Bricks' own style controls, the
		 * per-slot fields) rather than rebuilding the element from scratch.
		 *
		 * @param array  $element  Existing element, or a bare skeleton.
		 * @param string $uid      Section uid.
		 * @param array  $settings Neutral settings map.
		 * @return array
		 */
		private function encode_element( array $element, $uid, array $settings ) {
			$element['id']       = (string) $uid;
			$element['name']     = self::ELEMENT_NAME;
			$element['parent']   = isset( $element['parent'] ) ? $element['parent'] : 0;
			$element['children'] = isset( $element['children'] ) && is_array( $element['children'] ) ? $element['children'] : array();

			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$element['settings'] = array();
			}
			$element['settings'][ self::SETTINGS_KEY ] = wp_json_encode(
				$settings,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			return $element;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Non-Composer elements are left exactly where they are; only the
		 * Composer elements are replaced, in the caller's order, into the slots
		 * they already occupied.
		 */
		public function save_sections( $post_id, array $sections, $area = 'content' ) {
			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post_id  = (int) $post->ID;
			$area     = UiChemy_Section::area( $area );
			$key      = $this->meta_key( $post_id, $area );
			$existing = get_post_meta( $post_id, $key, true );
			$existing = is_array( $existing ) ? $existing : array();

			// Index the current Composer elements so a surviving uid keeps its
			// untouched native settings.
			$by_uid = array();
			$slots  = array();
			foreach ( $existing as $i => $element ) {
				if ( is_array( $element ) && isset( $element['name'] ) && self::ELEMENT_NAME === $element['name'] ) {
					$by_uid[ (string) $element['id'] ] = $element;
					$slots[]                           = $i;
				}
			}

			$rebuilt = array();
			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$uid      = isset( $section['uid'] ) ? (string) $section['uid'] : '';
				$settings = isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : array();

				$base = '';
				if ( '' !== $uid ) {
					foreach ( $by_uid as $known => $element ) {
						if ( $this->element_matches( $element, $uid ) ) {
							$base = $known;
							break;
						}
					}
				}

				if ( '' === $uid ) {
					$uid = UiChemy_Section::mint_uid( 6 );
				}

				$rebuilt[] = $this->encode_element(
					'' !== $base ? $by_uid[ $base ] : array( 'parent' => 0 ),
					$uid,
					$settings
				);
			}

			// Drop the old Composer elements, keep everything else in place, then
			// lay the rebuilt list back into the freed slots (appending any extra).
			$out  = array();
			$next = 0;
			foreach ( $existing as $i => $element ) {
				if ( in_array( $i, $slots, true ) ) {
					if ( isset( $rebuilt[ $next ] ) ) {
						$out[] = $rebuilt[ $next++ ];
					}
					continue;
				}
				$out[] = $element;
			}
			for ( $n = count( $rebuilt ); $next < $n; $next++ ) {
				$out[] = $rebuilt[ $next ];
			}

			// See the class docblock: update_post_meta() unslashes the whole
			// value, so this MUST be re-slashed or the entire page is corrupted.
			$written = update_post_meta( $post_id, $key, wp_slash( $out ) );

			return false === $written ? $this->explain_failed_write( $post_id, $key, $out ) : true;
		}

		/**
		 * Turn a refused meta write into an error that names the cause.
		 *
		 * Bricks hooks `update_post_metadata` and BLOCKS any write to its
		 * content keys unless the acting user passes
		 * Bricks\Capabilities::current_user_can_use_builder(). With no user in
		 * scope the write silently returns false — and the shipped inserters
		 * ignored that return, so an unauthorised call answered 200 with a
		 * widget id and wrote nothing. That is precisely the silent-success
		 * failure this driver exists to stop, so it is surfaced here.
		 *
		 * update_post_meta() also returns false when the stored value is already
		 * identical, which is a success, so that case is separated out first.
		 *
		 * @param int    $post_id Post id.
		 * @param string $key     Meta key.
		 * @param array  $out     What we tried to store.
		 * @return true|WP_Error
		 */
		private function explain_failed_write( $post_id, $key, array $out ) {
			$stored = get_post_meta( $post_id, $key, true );
			if ( is_array( $stored ) && wp_json_encode( $stored ) === wp_json_encode( $out ) ) {
				// Nothing changed — the write was a no-op, not a refusal.
				return true;
			}

			if ( class_exists( '\Bricks\Capabilities' )
				&& method_exists( '\Bricks\Capabilities', 'current_user_can_use_builder' )
				&& ! \Bricks\Capabilities::current_user_can_use_builder( $post_id ) ) {

				return new WP_Error(
					'uich_bricks_no_builder_access',
					sprintf(
						'Bricks refused the write to post %d: the acting user does not have Bricks builder access. Grant that role Bricks builder access (Bricks > Settings > Builder Access), or run as a user who has it.',
						absint( $post_id )
					),
					array( 'status' => 403 )
				);
			}

			return new WP_Error(
				'uich_bricks_write_failed',
				sprintf( 'Bricks content for post %d could not be saved to %s.', absint( $post_id ), $key ),
				array( 'status' => 500 )
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function new_section( array $settings, array $opts = array() ) {
			$uid = UiChemy_Section::mint_uid( 6 );

			return UiChemy_Section::make(
				array(
					'uid'       => $uid,
					'area'      => isset( $opts['area'] ) ? $opts['area'] : 'content',
					'settings'  => $settings,
					'container' => '',
					'native'    => $this->encode_element( array( 'parent' => 0 ), $uid, $settings ),
				)
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * Bricks' array is flat, so the "tree" is rebuilt from parent/children
		 * ids rather than walked.
		 */
		public function describe_structure( $post_id ) {
			$post = $this->require_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post_id = (int) $post->ID;
			$counter = 0;
			$areas   = array();

			foreach ( $this->area_keys( $post_id ) as $area => $key ) {
				$elements = get_post_meta( $post_id, $key, true );
				if ( ! is_array( $elements ) || ! $elements ) {
					continue;
				}

				$by_parent = array();
				foreach ( $elements as $element ) {
					if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
						continue;
					}
					$parent_id                 = isset( $element['parent'] ) ? (string) $element['parent'] : '0';
					$by_parent[ $parent_id ][] = $element;
				}

				$areas[ $area ] = $this->summarize( $by_parent, '0', $counter );
			}

			$structure = array();
			foreach ( $areas as $area => $nodes ) {
				$structure[] = array(
					'index'    => count( $structure ),
					'id'       => $area,
					'type'     => 'bricks-area',
					'label'    => ucfirst( $area ),
					'children' => $nodes,
				);
			}

			return array_merge(
				array(
					'post_id'               => $post_id,
					'post_title'            => $post->post_title,
					'post_type'             => $post->post_type,
					'post_status'           => $post->post_status,
					'builder'               => $this->slug,
					'top_level_count'       => count( $structure ),
					'total_uichemy_widgets' => $counter,
					'structure'             => $structure,
				),
				$this->edit_links( $post_id )
			);
		}

		/**
		 * Rebuild one branch of the flat element array.
		 *
		 * @param array  $by_parent Elements grouped by parent id.
		 * @param string $parent_id    Parent id to expand.
		 * @param int    $counter   Composer section counter (by reference).
		 * @return array
		 */
		private function summarize( array $by_parent, $parent_id, &$counter ) {
			if ( empty( $by_parent[ $parent_id ] ) ) {
				return array();
			}

			$out = array();
			foreach ( $by_parent[ $parent_id ] as $element ) {
				$node = array(
					'index' => count( $out ),
					'id'    => (string) $element['id'],
					'type'  => isset( $element['name'] ) ? (string) $element['name'] : '',
				);

				if ( isset( $element['name'] ) && self::ELEMENT_NAME === $element['name'] ) {
					$settings                     = $this->decode_settings( $element );
					$node['label']                = isset( $settings['_title'] ) ? (string) $settings['_title'] : '';
					$node['uichemy_widget_index'] = $counter++;
					$node['has_html']             = ! empty( $settings['raw_html'] );
					$node['has_css']              = ! empty( $settings['raw_css'] );
					$node['has_js']               = ! empty( $settings['raw_js'] );
				}

				$children = $this->summarize( $by_parent, (string) $element['id'], $counter );
				if ( $children ) {
					$node['children'] = $children;
				}

				$out[] = $node;
			}

			return $out;
		}

		/**
		 * {@inheritDoc}
		 */
		public function edit_links( $post_id ) {
			$links = parent::edit_links( $post_id );

			$links['bricks_link'] = add_query_arg(
				array( 'bricks' => 'run' ),
				get_permalink( absint( $post_id ) )
			);

			return $links;
		}

		/**
		 * {@inheritDoc}
		 */
		public function grep_query_args( array $args ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
			);
			foreach ( self::SCHEMA_SUFFIXES as $suffix ) {
				$args['meta_query'][] = array(
					'key'     => '' === $suffix ? '_bricks_page_content' : '_bricks_page_content_' . $suffix,
					'compare' => 'EXISTS',
				);
			}

			return $args;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Bricks compiles per-page CSS to disk. A meta write with no
		 * regeneration leaves the new section rendering unstyled, which reads
		 * to a user as "the tool wrote garbage" rather than "the cache is cold".
		 */
		public function after_write( $post_id ) {
			$post_id = absint( $post_id );

			if ( class_exists( '\Bricks\Assets_Files' ) && method_exists( '\Bricks\Assets_Files', 'generate_post_css_file' ) ) {
				try {
					\Bricks\Assets_Files::generate_post_css_file( $post_id );
					return;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Fall back to invalidating, so Bricks rebuilds on next render
			// instead of serving a stale file.
			delete_post_meta( $post_id, '_bricks_css_file' );
		}

		/**
		 * {@inheritDoc}
		 */
		public function readiness() {
			$theme_active = 'bricks' === get_stylesheet();
			$classes      = class_exists( '\Bricks\Elements' );
			$ready        = $theme_active && $classes;

			$notes = array();
			if ( ! $theme_active ) {
				$notes[] = 'Bricks is a theme, and it is not the active theme on this site.';
			} elseif ( ! $classes ) {
				$notes[] = 'The Bricks theme is active but its element API is unavailable.';
			}

			return array(
				'ready'  => $ready,
				'checks' => array(
					'bricks_theme_active' => $theme_active,
					'bricks_elements_api' => $classes,
					'bricks_enabled'      => ! function_exists( 'uichemy_composer_enabled' ) || uichemy_composer_enabled( 'bricks' ),
				),
				'notes'  => $notes,
			);
		}
	}
}
