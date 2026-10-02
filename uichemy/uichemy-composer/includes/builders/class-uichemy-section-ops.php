<?php
/**
 * Every structural section operation, written ONCE for all page builders.
 *
 * This file is the point of the whole builders/ directory. Because a driver
 * only has to load and save an ordered list of normalized sections, move,
 * duplicate, delete, insert, patch and grep are ordinary array work — and a
 * fourth builder inherits all of them the day its driver lands, without a line
 * being added here.
 *
 * Nothing below may reference a builder by name. If an operation ever needs to
 * know which builder it is running against, that knowledge belongs behind a
 * driver capability (UiChemy_Builder_Driver::supports()), not in a branch here.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Section_Ops' ) ) {

	/**
	 * Builder-independent Composer section operations.
	 */
	class UiChemy_Section_Ops {

		/**
		 * Run the two shared pre-passes every write path has always run: sideload
		 * remote images into the media library, then match the design system's
		 * globals over the CSS.
		 *
		 * Kept here rather than in the drivers so all three builders get exactly
		 * the same treatment — an image that uploads on Elementor and not on
		 * Bricks would be a difference nobody could explain.
		 *
		 * @param string $html          Section HTML.
		 * @param string $css           Section CSS.
		 * @param bool   $upload_images Whether to sideload images.
		 * @return array { html, css, uploaded, failed, dynamic_globals }
		 */
		public static function prepare_code( $html, $css, $upload_images = true ) {
			$html = (string) $html;
			$css  = (string) $css;

			$uploaded = array();
			$failed   = array();

			if ( $upload_images && '' !== trim( $html ) && method_exists( 'UiChemy_Composer_Manager', 'mcp_upload_html_images_to_media_library' ) ) {
				$media = UiChemy_Composer_Manager::mcp_upload_html_images_to_media_library( $html, $css );
				if ( is_array( $media ) ) {
					$html     = isset( $media['html'] ) ? (string) $media['html'] : $html;
					$css      = isset( $media['css'] ) ? (string) $media['css'] : $css;
					$uploaded = isset( $media['uploaded'] ) ? (array) $media['uploaded'] : array();
					$failed   = isset( $media['failed'] ) ? (array) $media['failed'] : array();
				}
			}

			$globals = array();
			if ( method_exists( 'UiChemy_Composer_Manager', 'mcp_prepare_import_html_css_with_globals' ) ) {
				$prepared = UiChemy_Composer_Manager::mcp_prepare_import_html_css_with_globals( $html, $css );
				if ( is_array( $prepared ) ) {
					$html    = isset( $prepared['html'] ) ? (string) $prepared['html'] : $html;
					$css     = isset( $prepared['css'] ) ? (string) $prepared['css'] : $css;
					$globals = isset( $prepared['dynamic_globals'] ) ? (array) $prepared['dynamic_globals'] : array();
				}
			}

			return array(
				'html'            => $html,
				'css'             => $css,
				'uploaded'        => $uploaded,
				'failed'          => $failed,
				'dynamic_globals' => $globals,
			);
		}

		/**
		 * Load a post's sections, or bail with the driver's error.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		private static function sections( $driver, $post_id, $area ) {
			$sections = $driver->load_sections( $post_id, $area );

			return is_wp_error( $sections ) ? $sections : (array) $sections;
		}

		/**
		 * Commit a section list and invalidate whatever the builder caches.
		 *
		 * @param UiChemy_Builder_Driver $driver   Driver.
		 * @param int                    $post_id  Post id.
		 * @param array                  $sections Ordered list.
		 * @param string                 $area     Content area.
		 * @return true|WP_Error
		 */
		private static function commit( $driver, $post_id, array $sections, $area ) {
			$saved = $driver->save_sections( $post_id, UiChemy_Section::reindex( $sections ), $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$driver->after_write( $post_id );

			return true;
		}

		/**
		 * The "no such section" error, naming what WAS there so the caller can
		 * retry against a real target instead of guessing.
		 *
		 * @param array  $sections Current sections.
		 * @param string $uid      Requested uid.
		 * @param int    $index    Requested index.
		 * @return WP_Error
		 */
		private static function not_found( array $sections, $uid, $index ) {
			$known = array();
			foreach ( $sections as $section ) {
				$known[] = sprintf( '%d:%s', (int) $section['index'], (string) $section['uid'] );
			}

			return new WP_Error(
				'uich_widget_not_found',
				sprintf(
					'No Composer section matched %s. This post has %d: %s.',
					'' !== (string) $uid ? 'element_id "' . $uid . '"' : 'section_index ' . (int) $index,
					count( $sections ),
					$known ? implode( ', ', $known ) : 'none'
				),
				array( 'status' => 404 )
			);
		}

		/**
		 * Every section's code on a post.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function get_all( $driver, $post_id, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$out = array();
			foreach ( $sections as $section ) {
				$row                  = UiChemy_Section::to_code_response( $section );
				$row['section_index'] = (int) $section['index'];
				$out[]                = $row;
			}

			return array(
				'post_id'  => absint( $post_id ),
				'builder'  => $driver->slug(),
				'sections' => $out,
			);
		}

		/**
		 * One section's code, addressed by element_id or 0-based index.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param int                    $index   Section index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function get_one( $driver, $post_id, $uid = '', $index = 0, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$row                  = UiChemy_Section::to_code_response( $sections[ $at ] );
			$row['post_id']       = absint( $post_id );
			$row['builder']       = $driver->slug();
			$row['section_index'] = (int) $sections[ $at ]['index'];

			return $row;
		}

		/**
		 * Replace one section's html/css/js wholesale.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param array                  $payload { html?, css?, js?, label?, upload_images? }.
		 * @param int                    $index   Section index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function set_one( $driver, $post_id, $uid, array $payload, $index = -1, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$prepared = self::prepare_code(
				isset( $payload['html'] ) ? $payload['html'] : '',
				isset( $payload['css'] ) ? $payload['css'] : '',
				! isset( $payload['upload_images'] ) || (bool) $payload['upload_images']
			);
			if ( isset( $payload['html'] ) ) {
				$payload['html'] = $prepared['html'];
			}
			if ( isset( $payload['css'] ) ) {
				$payload['css'] = $prepared['css'];
			}

			$sections[ $at ]['settings'] = UiChemy_Section::settings_from_payload( $payload, $sections[ $at ]['settings'] );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return self::get_one( $driver, $post_id, (string) $sections[ $at ]['uid'], $at, $area );
		}

		/**
		 * Where a section's CSS and JS are printed on the front end.
		 *
		 * Stored as raw_css_placement / raw_js_placement on the section: '' = use the
		 * default (UiChemy_Fast_Load::default_placement()), 'head' = Before Head,
		 * 'body' = Before Body. Only the Elementor widget honours them.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param int                    $index   Section index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error { post_id, builder, widget_id, section_index, label, css_placement, css_is_default, js_placement, js_is_default }
		 */
		public static function get_placement( $driver, $post_id, $uid = '', $index = 0, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$settings = (array) $sections[ $at ]['settings'];
			$index    = (int) $sections[ $at ]['index'];
			$css_set  = self::placement_name( isset( $settings['raw_css_placement'] ) ? $settings['raw_css_placement'] : '' );
			$js_set   = self::placement_name( isset( $settings['raw_js_placement'] ) ? $settings['raw_js_placement'] : '' );

			// The effective placement: what the author chose, else the default for this
			// position (the same rule the editor dropdown and the front end use).
			return array(
				'post_id'        => absint( $post_id ),
				'builder'        => $driver->slug(),
				'widget_id'      => (string) $sections[ $at ]['uid'],
				'section_index'  => $index,
				'label'          => isset( $settings['_title'] ) ? (string) $settings['_title'] : '',
				'css_placement'  => $css_set ? $css_set : UiChemy_Fast_Load::default_placement( 'css', $index ),
				'css_is_default' => ! $css_set,
				'js_placement'   => $js_set ? $js_set : UiChemy_Fast_Load::default_placement( 'js', $index ),
				'js_is_default'  => ! $js_set,
			);
		}

		/**
		 * Set a section's CSS placement, JS placement, or both.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param array                  $payload { css_placement?, js_placement? } each normal|head|body.
		 * @param int                    $index   Section index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error The section's placement after the change.
		 */
		public static function set_placement( $driver, $post_id, $uid, array $payload, $index = -1, $area = 'content' ) {
			$keys = array(
				'css_placement' => 'raw_css_placement',
				'js_placement'  => 'raw_js_placement',
			);

			$changes = array();
			foreach ( $keys as $field => $setting ) {
				if ( ! isset( $payload[ $field ] ) ) {
					continue;
				}
				$value = strtolower( trim( (string) $payload[ $field ] ) );
				if ( ! in_array( $value, array( 'normal', 'head', 'body' ), true ) ) {
					return new WP_Error( 'uich_invalid_placement', sprintf( '%s must be "normal", "head" or "body", got "%s".', $field, $value ) );
				}
				$changes[ $setting ] = 'normal' === $value ? '' : $value;
			}
			if ( empty( $changes ) ) {
				return new WP_Error( 'uich_missing_param', 'Pass css_placement, js_placement, or both (normal | head | body).' );
			}
			if ( 'elementor' !== $driver->slug() ) {
				return new WP_Error( 'uich_placement_unsupported', 'CSS / JS placement is only applied by the Elementor widget; this post is built with ' . $driver->slug() . '.' );
			}

			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$sections[ $at ]['settings'] = array_merge( (array) $sections[ $at ]['settings'], $changes );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return self::get_placement( $driver, $post_id, (string) $sections[ $at ]['uid'], $at, $area );
		}

		/**
		 * Placement of every section on a post, as a table (one row per section).
		 *
		 * Uses the names of the table tools: before-head-end (printed before </head>)
		 * and before-body-end (printed before </body>), each with a flag saying whether
		 * that is just the default for the section's position.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error { post_id, builder, total_sections, starting_sections, sections[] }
		 */
		public static function get_placement_table( $driver, $post_id, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$rows = array();
			foreach ( $sections as $section ) {
				$settings = (array) $section['settings'];
				$index    = (int) $section['index'];
				$css_set  = self::placement_name( isset( $settings['raw_css_placement'] ) ? $settings['raw_css_placement'] : '' );
				$js_set   = self::placement_name( isset( $settings['raw_js_placement'] ) ? $settings['raw_js_placement'] : '' );

				$rows[] = array(
					'section_index'  => $index,
					'element_id'     => (string) $section['uid'],
					'label'          => isset( $settings['_title'] ) ? (string) $settings['_title'] : '',
					'css'            => self::table_name( $css_set ? $css_set : UiChemy_Fast_Load::default_placement( 'css', $index ) ),
					'css_is_default' => ! $css_set,
					'js'             => self::table_name( $js_set ? $js_set : UiChemy_Fast_Load::default_placement( 'js', $index ) ),
					'js_is_default'  => ! $js_set,
				);
			}

			return array(
				'post_id'           => absint( $post_id ),
				'builder'           => $driver->slug(),
				'total_sections'    => count( $rows ),
				'starting_sections' => UiChemy_Fast_Load::STARTING_SECTIONS,
				'sections'          => $rows,
			);
		}

		/**
		 * Set one section's CSS or JS placement using the table tools' names.
		 *
		 * @param UiChemy_Builder_Driver $driver    Driver.
		 * @param int                    $post_id   Post id.
		 * @param int                    $index     0-based section index.
		 * @param string                 $type      'css' or 'js'.
		 * @param string                 $placement before-head-end | before-body-end | default.
		 * @param string                 $area      Content area.
		 * @return array|WP_Error That section's row of the table after the change.
		 */
		public static function set_table_placement( $driver, $post_id, $index, $type, $placement, $area = 'content' ) {
			$type      = strtolower( trim( (string) $type ) );
			$placement = strtolower( trim( (string) $placement ) );
			if ( ! in_array( $type, array( 'css', 'js' ), true ) ) {
				return new WP_Error( 'uich_invalid_placement', sprintf( 'type must be "css" or "js", got "%s".', $type ) );
			}
			$map = array(
				'before-head-end' => 'head',
				'before-body-end' => 'body',
				'default'         => 'normal',
			);
			if ( ! isset( $map[ $placement ] ) ) {
				return new WP_Error( 'uich_invalid_placement', sprintf( 'placement must be "before-head-end", "before-body-end" or "default", got "%s".', $placement ) );
			}

			$saved = self::set_placement( $driver, $post_id, '', array( $type . '_placement' => $map[ $placement ] ), (int) $index, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$table = self::get_placement_table( $driver, $post_id, $area );
			if ( is_wp_error( $table ) ) {
				return $table;
			}
			foreach ( $table['sections'] as $row ) {
				if ( (int) $row['section_index'] === (int) $index ) {
					return array_merge( array( 'post_id' => $table['post_id'], 'builder' => $table['builder'] ), $row );
				}
			}
			return $saved;
		}

		/**
		 * Stored placement name to the table tools' name.
		 *
		 * @param string $name head|body
		 * @return string before-head-end|before-body-end
		 */
		private static function table_name( $name ) {
			return 'head' === $name ? 'before-head-end' : 'before-body-end';
		}

		/**
		 * Stored placement value to its public name.
		 *
		 * @param mixed $value Stored value.
		 * @return string head|body, or '' when none is stored (the default applies)
		 */
		private static function placement_name( $value ) {
			return in_array( $value, array( 'head', 'body' ), true ) ? $value : '';
		}

		/**
		 * Add a section at the end.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param array                  $payload { html?, css?, js?, label?, upload_images? }.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function append( $driver, $post_id, array $payload, $area = 'content' ) {
			return self::insert_at( $driver, $post_id, $payload, PHP_INT_MAX, $area );
		}

		/**
		 * Add a section at a 0-based position, clamped to the ends.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param array                  $payload { html?, css?, js?, label?, upload_images? }.
		 * @param int                    $at      Desired position.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function insert_at( $driver, $post_id, array $payload, $at, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$prepared        = self::prepare_code(
				isset( $payload['html'] ) ? $payload['html'] : '',
				isset( $payload['css'] ) ? $payload['css'] : '',
				! isset( $payload['upload_images'] ) || (bool) $payload['upload_images']
			);
			$payload['html'] = $prepared['html'];
			$payload['css']  = $prepared['css'];

			$section = $driver->new_section(
				UiChemy_Section::settings_from_payload( $payload ),
				array( 'area' => $area )
			);

			$at = max( 0, min( (int) $at, count( $sections ) ) );
			array_splice( $sections, $at, 0, array( $section ) );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'post_id'        => absint( $post_id ),
				'builder'        => $driver->slug(),
				'widget_id'      => (string) $section['uid'],
				'section_index'  => $at,
				'label'          => isset( $section['settings']['_title'] ) ? (string) $section['settings']['_title'] : '',
				'image_uploads'  => $prepared['uploaded'],
				'image_failures' => $prepared['failed'],
				'message'        => sprintf( 'Section added to post %d at index %d.', absint( $post_id ), $at ),
			);
		}

		/**
		 * Move a section to a new 0-based position.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param int                    $to      Target position.
		 * @param int                    $index   Source index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function move( $driver, $post_id, $uid, $to, $index = -1, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$moved = array_splice( $sections, $at, 1 );
			$to    = max( 0, min( (int) $to, count( $sections ) ) );
			array_splice( $sections, $to, 0, $moved );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'post_id'   => absint( $post_id ),
				'builder'   => $driver->slug(),
				'widget_id' => (string) $moved[0]['uid'],
				'from'      => $at,
				'to'        => $to,
				'message'   => sprintf( 'Section moved from index %d to %d.', $at, $to ),
			);
		}

		/**
		 * Copy a section in directly after itself.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param int                    $index   Source index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @param string                 $label   Optional label for the copy.
		 * @return array|WP_Error
		 */
		public static function duplicate( $driver, $post_id, $uid, $index = -1, $area = 'content', $label = '' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			// A fresh node from the driver, so the copy gets a NEW uid — reusing
			// the source id would make the two indistinguishable to every
			// subsequent uid lookup.
			$settings = $sections[ $at ]['settings'];
			if ( '' !== (string) $label ) {
				$settings['_title'] = (string) $label;
			}
			$copy = $driver->new_section( $settings, array( 'area' => $area ) );
			array_splice( $sections, $at + 1, 0, array( $copy ) );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'post_id'       => absint( $post_id ),
				'builder'       => $driver->slug(),
				'widget_id'     => (string) $copy['uid'],
				'source_id'     => (string) $sections[ $at ]['uid'],
				'section_index' => $at + 1,
				'message'       => sprintf( 'Section duplicated at index %d.', $at + 1 ),
			);
		}

		/**
		 * Remove a section. DESTRUCTIVE — the caller owns the confirm-token
		 * handshake; by the time this runs the deletion is agreed.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param int                    $post_id Post id.
		 * @param string                 $uid     Element id, or ''.
		 * @param int                    $index   Source index, used when uid is ''.
		 * @param string                 $area    Content area.
		 * @return array|WP_Error
		 */
		public static function delete( $driver, $post_id, $uid, $index = -1, $area = 'content' ) {
			$sections = self::sections( $driver, $post_id, $area );
			if ( is_wp_error( $sections ) ) {
				return $sections;
			}

			$at = UiChemy_Section::locate( $sections, $uid, $index );
			if ( null === $at ) {
				return self::not_found( $sections, $uid, $index );
			}

			$removed = array_splice( $sections, $at, 1 );

			$saved = self::commit( $driver, $post_id, $sections, $area );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'post_id'   => absint( $post_id ),
				'builder'   => $driver->slug(),
				'widget_id' => (string) $removed[0]['uid'],
				'remaining' => count( $sections ),
				'message'   => sprintf( 'Section deleted. %d remain.', count( $sections ) ),
			);
		}

		/**
		 * Page-level head/footer code, merged into the FIRST section.
		 *
		 * Page code is per-page, but it is STORED on a section (every builder keeps
		 * it in the same settings map), so exactly one section has to own it or the
		 * same <style> is emitted once per section.
		 *
		 * @param array  $sections Ordered list, by reference.
		 * @param string $page_css Raw page CSS.
		 * @param string $page_js  Raw page JS.
		 * @return bool Whether an existing section took the code.
		 */
		public static function merge_page_code( array &$sections, $page_css, $page_js ) {
			$page_css = trim( (string) $page_css );
			$page_js  = trim( (string) $page_js );
			if ( ( '' === $page_css && '' === $page_js ) || empty( $sections ) ) {
				return false;
			}

			$settings = $sections[0]['settings'];

			foreach ( array(
				array( $page_css, 'page_custom_code_head', 'mcp_ensure_style_tag' ),
				array( $page_js, 'page_custom_code_footer', 'mcp_ensure_script_tag' ),
			) as $spec ) {
				list( $code, $key, $wrapper ) = $spec;
				if ( '' === $code ) {
					continue;
				}
				$block    = UiChemy_Composer_Manager::$wrapper( $code );
				$existing = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
				// Idempotent: re-sending the same page code must not stack copies.
				if ( false === strpos( $existing, $block ) ) {
					$settings[ $key ] = '' === $existing ? $block : $existing . "\n" . $block;
				}
			}

			$sections[0]['settings'] = $settings;

			return true;
		}

		/**
		 * REPLACE the page-level head/footer code on the first section.
		 *
		 * null leaves a scope untouched; '' deliberately clears it. That three-way
		 * distinction is the whole point — a caller rewriting page head must be able
		 * to leave page body alone without having to resend it.
		 *
		 * @param array       $sections Ordered list, by reference.
		 * @param string|null $head     New head block, or null.
		 * @param string|null $footer   New footer block, or null.
		 * @return bool Whether a section took the code.
		 */
		public static function replace_page_code( array &$sections, $head, $footer ) {
			if ( empty( $sections ) ) {
				return false;
			}

			$settings = $sections[0]['settings'];
			if ( null !== $head ) {
				$settings['page_custom_code_head'] = (string) $head;
			}
			if ( null !== $footer ) {
				$settings['page_custom_code_footer'] = (string) $footer;
			}
			$sections[0]['settings'] = $settings;

			// The replace above lands on the first section only, but the frontend
			// printer dedupes by a hash of the whole block — so on a page with two or
			// more sections the others still hold the PREVIOUS block, two signatures
			// survive the dedupe, and the page emits its page code twice. A duplicated
			// <link> is merely wasteful; a duplicated <script> RUNS TWICE.
			foreach ( $sections as $i => $section ) {
				if ( null !== $head ) {
					$section['settings']['page_custom_code_head'] = (string) $head;
				}
				if ( null !== $footer ) {
					$section['settings']['page_custom_code_footer'] = (string) $footer;
				}
				$sections[ $i ] = $section;
			}

			return true;
		}

		/**
		 * Copy the first section's page code onto every section.
		 *
		 * The render path emits page code from whichever section it reaches first,
		 * and that is not necessarily section 0 once sections are reordered — so all
		 * of them carry the same canonical copy.
		 *
		 * @param array $sections Ordered list, by reference.
		 * @return void
		 */
		public static function sync_page_code( array &$sections ) {
			$head   = '';
			$footer = '';
			foreach ( $sections as $section ) {
				if ( '' === $head && ! empty( $section['settings']['page_custom_code_head'] ) ) {
					$head = (string) $section['settings']['page_custom_code_head'];
				}
				if ( '' === $footer && ! empty( $section['settings']['page_custom_code_footer'] ) ) {
					$footer = (string) $section['settings']['page_custom_code_footer'];
				}
			}
			if ( '' === $head && '' === $footer ) {
				return;
			}

			foreach ( $sections as $i => $section ) {
				if ( '' !== $head ) {
					$section['settings']['page_custom_code_head'] = $head;
				}
				if ( '' !== $footer ) {
					$section['settings']['page_custom_code_footer'] = $footer;
				}
				$sections[ $i ] = $section;
			}
		}

		/**
		 * Render every Composer section on a post to front-end HTML.
		 *
		 * The Composer element in each builder does the same three things — resolve
		 * dynamic tokens, scope the CSS to `.uichemy-composer-<uid>`, hand the
		 * settings map to the shared renderer — so a builder whose own render path
		 * cannot be reached outside its editor (Bricks templates, rendered by
		 * UiChemy's theme builder rather than by Bricks) can be served from here
		 * and produce byte-identical output.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver that owns the post.
		 * @param int                    $post_id Post id.
		 * @param string                 $area    Content area.
		 * @return string Rendered HTML, '' when there is nothing to render.
		 */
		public static function render_html_for( $driver, $post_id, $area = 'content' ) {
			if ( ! class_exists( 'UiChemy_Composer_Renderer' ) ) {
				return '';
			}

			$sections = $driver->load_sections( $post_id, $area );
			if ( is_wp_error( $sections ) || empty( $sections ) ) {
				return '';
			}

			$out = '';
			foreach ( $sections as $section ) {
				$settings = $section['settings'];
				$uid      = (string) $section['uid'];
				$scope    = '.uichemy-composer-' . sanitize_html_class( $uid );

				if ( ! empty( $settings['raw_html'] ) && class_exists( 'Uich_Dynamic' ) ) {
					$settings['raw_html'] = Uich_Dynamic::render_html(
						(string) $settings['raw_html'],
						array( 'widget_id' => $uid )
					);
				}

				// Opt-in: full-page designs that style <body> need the CSS as authored.
				$scope_css = empty( $settings['css_unscoped'] );

				$out .= '<div class="uichemy-composer-' . esc_attr( sanitize_html_class( $uid ) ) . '">'
					. UiChemy_Composer_Renderer::render_html( $settings, $scope, $uid, false, $scope_css )
					. '</div>';
			}

			return $out;
		}

		/**
		 * Search every section's code on a set of posts, like grep -rn.
		 *
		 * @param UiChemy_Builder_Driver $driver  Driver.
		 * @param array                  $post_ids Posts to scan.
		 * @param string                 $needle   Literal text to find.
		 * @param array                  $opts     { case_sensitive?, max_matches? }.
		 * @return array
		 */
		public static function grep( $driver, array $post_ids, $needle, array $opts = array() ) {
			$needle = (string) $needle;
			if ( '' === $needle ) {
				return array(
					'builder' => $driver->slug(),
					'needle'  => $needle,
					'matches' => array(),
				);
			}

			$cased = ! empty( $opts['case_sensitive'] );
			$max   = isset( $opts['max_matches'] ) ? max( 1, (int) $opts['max_matches'] ) : 200;

			$matches = array();
			foreach ( $post_ids as $post_id ) {
				$sections = $driver->load_sections( $post_id );
				if ( is_wp_error( $sections ) ) {
					continue;
				}

				foreach ( $sections as $section ) {
					foreach ( UiChemy_Section::CODE_KEYS as $short => $key ) {
						$code = isset( $section['settings'][ $key ] ) ? (string) $section['settings'][ $key ] : '';
						if ( '' === $code ) {
							continue;
						}
						$hit = $cased
							? ( false !== strpos( $code, $needle ) )
							: ( false !== stripos( $code, $needle ) );
						if ( ! $hit ) {
							continue;
						}

						$matches[] = array(
							'post_id'       => absint( $post_id ),
							'builder'       => $driver->slug(),
							'widget_id'     => (string) $section['uid'],
							'section_index' => (int) $section['index'],
							'label'         => (string) $section['label'],
							'field'         => $short,
							'lines'         => self::matching_lines( $code, $needle, $cased ),
						);

						if ( count( $matches ) >= $max ) {
							break 3;
						}
					}
				}
			}

			return array(
				'builder' => $driver->slug(),
				'needle'  => $needle,
				'matches' => $matches,
			);
		}

		/**
		 * The numbered lines of a blob that contain a needle.
		 *
		 * @param string $code   Haystack.
		 * @param string $needle Needle.
		 * @param bool   $cased  Case-sensitive.
		 * @return array<int,array{line:int,text:string}>
		 */
		private static function matching_lines( $code, $needle, $cased ) {
			$out = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $code ) as $n => $line ) {
				$hit = $cased ? ( false !== strpos( $line, $needle ) ) : ( false !== stripos( $line, $needle ) );
				if ( $hit ) {
					$out[] = array(
						'line' => $n + 1,
						'text' => trim( $line ),
					);
				}
			}

			return $out;
		}
	}
}
