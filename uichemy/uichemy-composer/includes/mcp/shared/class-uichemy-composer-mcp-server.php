<?php
/**
 * Shared MCP handlers for UiChemy
 *
 * The execute_* handlers behind the v2 abilities (site readiness, design
 * system, page / section writes, templates, media upload). The v2 router
 * (class-uichemy-mcp-v2-router.php) dispatches here.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Composer_MCP_Server' ) ) {

	/**
	 * Handler callables the v2 router dispatches to.
	 */
	class UiChemy_Composer_MCP_Server {

		// ============================================================
		// CONSTANTS
		// ============================================================

		const SERVER_ID          = 'uichemy-composer-mcp';
		const SERVER_VERSION     = '1.0.0';
		// Enable flag read by UiChemy_Abilities (class-uichemy-abilities.php). The
		// class referenced this constant without defining it, which fatals on every
		// ability call; define it so the check works. Absent option → default '1'
		// (enabled), matching uichemy_settings['enable_mcp'] = 1.
		const ENABLED_OPTION     = 'uichemy_composer_mcp_enabled';

		// ============================================================
		// HANDLERS ONLY
		//
		// This class registers no MCP server and publishes no tool table. It
		// holds the execute_* handlers the v2 router (UiChemy_MCP_V2_Router)
		// dispatches to. The legacy /wp-json/uichemy/v1/mcp flat-tool endpoint
		// was removed in 5.2.x; v2 (/wp-json/uichemy/v2/mcp) is the only surface.
		// ============================================================

		public static function execute_check_config( $arguments = array() ) {
			$has_globals_class  = class_exists( 'UiChemy_Globals_CSS' ) && method_exists( 'UiChemy_Composer_Manager', 'get_globals_block_css' );
			$has_elementor      = class_exists( '\Elementor\Plugin' );
			$has_elementor_pro  = class_exists( '\ElementorPro\Plugin' ) || defined( 'ELEMENTOR_PRO_VERSION' );
			$has_nexter_ext     = post_type_exists( 'nxt_builder' );
			$kit_available      = false;
			$active_kit_id      = null;

			$active_theme     = wp_get_theme();
			$has_nexter_theme = (
				strtolower( (string) $active_theme->get( 'TextDomain' ) ) === 'nexter' ||
				strtolower( (string) $active_theme->get( 'Name' ) ) === 'nexter' ||
				strtolower( get_template() ) === 'nexter'
			);

			if ( $has_elementor ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				if ( $kit ) {
					$kit_available = true;
					$active_kit_id = $kit->get_id();
				}
			}

			$nav_menus      = wp_get_nav_menus();
			$has_nav_menu   = ! empty( $nav_menus );
			$nav_menu_names = array_values(
				array_map( function ( $m ) { return $m->name; }, $nav_menus )
			);

			if ( $has_elementor_pro ) {
				$header_footer_system = 'elementor_pro';
			} elseif ( $has_nexter_ext ) {
				$header_footer_system = 'nexter';
			} else {
				$header_footer_system = 'elementor';
			}

			$active_header = self::detect_active_header_footer_templates( 'header' );
			$active_footer = self::detect_active_header_footer_templates( 'footer' );

			$custom_logo_id  = absint( get_theme_mod( 'custom_logo', 0 ) );
			$has_custom_logo = $custom_logo_id > 0 && (bool) get_post( $custom_logo_id );
			$custom_logo_url = $has_custom_logo ? (string) wp_get_attachment_url( $custom_logo_id ) : null;

			$site_icon_id  = absint( get_option( 'site_icon', 0 ) );
			$has_site_icon = $site_icon_id > 0 && (bool) get_post( $site_icon_id );
			$site_icon_url = $has_site_icon ? get_site_icon_url( 192 ) : null;

			$experiments    = $has_elementor ? \Elementor\Plugin::$instance->experiments : null;
			$atomic_enabled = $has_elementor
				&& $experiments
				&& method_exists( $experiments, 'is_feature_active' )
				&& (bool) $experiments->is_feature_active( 'e_atomic_elements' );

			// Readiness follows the RESOLVED builder, not Elementor. Tying it to
			// Elementor told an agent to stop on a perfectly buildable Bricks or
			// Gutenberg site — and because this is data rather than prose, the
			// agent believed it over anything the usage guide said.
			$builder   = '';
			$builders  = array();
			$notes     = array();
			$has_kit   = $has_globals_class;

			$builder_reason = '';
			if ( class_exists( 'UiChemy_Builder_Registry' ) && class_exists( 'UiChemy_Builder_Context' ) ) {
				$builder = UiChemy_Builder_Context::resolve();

				$chosen = class_exists( 'Uich_ND_Settings' ) ? (string) Uich_ND_Settings::get_builder() : '';
				$builder_reason = ( '' !== $chosen && $chosen === $builder )
					? 'Chosen page builder during onboarding.'
					: 'No onboarding choice stored, so the first available builder was used.';

				foreach ( UiChemy_Builder_Registry::all() as $slug => $driver ) {
					// The block editor is present on EVERY WordPress site, so listing
					// it beside a deliberately installed builder reads as a choice
					// the site has not actually offered — and an agent then treats
					// two builders as interchangeable when one of them is simply
					// core. It is reported only when it IS the builder in use.
					// A cross-builder write still names both sides in its error, so
					// nothing is hidden at the moment it matters.
					if ( 'gutenberg' === $slug && 'gutenberg' !== $builder ) {
						continue;
					}

					$readiness         = $driver->readiness();
					$builders[ $slug ] = array(
						'label'     => $driver->label(),
						'available' => $driver->is_available(),
						'enabled'   => ! function_exists( 'uichemy_composer_enabled' ) || uichemy_composer_enabled( $slug ),
						'ready'     => ! empty( $readiness['ready'] ),
						'checks'    => isset( $readiness['checks'] ) ? $readiness['checks'] : array(),
						'notes'     => isset( $readiness['notes'] ) ? $readiness['notes'] : array(),
					);
				}

				$resolved_driver = '' !== $builder ? UiChemy_Builder_Registry::get( $builder ) : null;
				if ( $resolved_driver ) {
					$resolved_readiness = $resolved_driver->readiness();
					$has_kit            = $has_globals_class && ! empty( $resolved_readiness['ready'] );
					$notes              = isset( $resolved_readiness['notes'] ) ? $resolved_readiness['notes'] : array();
				} else {
					$has_kit = false;
					$notes[] = 'No page builder is available on this site. Install Elementor or Bricks, or use the block editor.';
				}
			} else {
				// Driver layer absent (shouldn't happen) — fall back to the old gate.
				$has_kit = $has_globals_class && $has_elementor && $kit_available;
			}

			$is_ready = $has_kit;

			// The Composer Builder picked in onboarding (or pinned with
			// ?builder=composer). It has no driver, so the loop above never saw it:
			// report it as THE builder, ready whenever it is loaded - it needs no
			// kit, since its pages are plain html/css/js documents.
			if ( class_exists( 'UiChemy_Builder_Context' ) && UiChemy_Builder_Context::is_composer_mode() ) {
				$saved_builder = class_exists( 'Uich_ND_Settings' ) ? (string) Uich_ND_Settings::get_builder() : '';

				$builder = UiChemy_Builder_Context::COMPOSER;
				if ( UiChemy_Builder_Context::COMPOSER === $saved_builder ) {
					$builder_reason = 'Chosen page builder during onboarding.';
				} elseif ( '' === $saved_builder ) {
					$builder_reason = 'No page builder saved yet; neither Elementor nor Bricks is active, so the bundled Composer Builder is the default.';
				} else {
					$builder_reason = 'Pinned on this MCP endpoint (?builder=composer).';
				}
				$is_ready       = true;
				$notes          = array();

				$builders[ UiChemy_Builder_Context::COMPOSER ] = array(
					'label'     => 'UiChemy Composer Builder',
					'available' => true,
					'enabled'   => true,
					'ready'     => true,
					'checks'    => array(),
					'notes'     => array( 'One document per page - html, css, js, head_code, body_end_code - holding the whole page. Build with uichemy-composer/page and /post: create, get-code, set-code, patch-code.' ),
				);
			}

			// Chosen, but switched off in Settings > Features: nothing may build.
			// Falling back to another builder would mix its section pages into a
			// site that chose one-document Composer Builder pages.
			if ( class_exists( 'UiChemy_Builder_Context' ) && UiChemy_Builder_Context::composer_switched_off() ) {
				$builder        = UiChemy_Builder_Context::COMPOSER;
				$builder_reason = 'Chosen page builder during onboarding, but switched off in UiChemy > Settings > Features.';
				$is_ready       = false;
				$notes          = array( 'The UiChemy Composer Builder is switched off. Ask the site owner to switch it on (UiChemy > Settings > Features) or choose another page builder in the UiChemy dashboard; do not build until then.' );

				$builders[ UiChemy_Builder_Context::COMPOSER ] = array(
					'label'     => 'UiChemy Composer Builder',
					'available' => true,
					'enabled'   => false,
					'ready'     => false,
					'checks'    => array(),
					'notes'     => $notes,
				);
			}

			// The Composer Builder exists here but is not the selected builder:
			// say so, and how to select it, or an agent asked to build "in the
			// UiChemy Builder" has no way to know the page tools it sees are the
			// section ones of another builder.
			if ( class_exists( 'UiChemy_Builder_Context' )
				&& UiChemy_Builder_Context::composer_available()
				&& ! UiChemy_Builder_Context::is_composer_mode()
			) {
				$builders[ UiChemy_Builder_Context::COMPOSER ] = array(
					'label'     => 'UiChemy Composer Builder',
					'available' => true,
					'enabled'   => true,
					'ready'     => true,
					'selected'  => false,
					'checks'    => array(),
					'notes'     => array( 'Installed but not the selected builder, so page / post offer ' . $builder . ' section tools. To build one-document Composer Builder pages, connect to /wp-json/uichemy/v2/mcp?builder=composer (or send the X-UiChemy-Builder: composer header), or have the site owner pick "UiChemy Builder" as the page builder in the UiChemy dashboard. Ask the user before switching.' ),
				);
			}

			return array(
				'ready'                => $is_ready,
				'builder'              => $builder,
				'builder_reason'       => $builder_reason,
				// Diagnostics, not a menu: every write lands in `builder` unless the
				// caller passes one explicitly or targets a post another builder owns.
				'builders'             => $builders,
				// Kept for compatibility: Uich_Dynamic and the pipeline docs read
				// these by name. They describe ELEMENTOR specifically and are
				// meaningless on another builder — route on `builder` instead.
				'header_footer_system' => $header_footer_system,
				'atomic_enabled'       => $atomic_enabled,
				'checks'               => array(
					'uichemy_globals_class' => $has_globals_class,
					'elementor_active'      => $has_elementor,
					'elementor_pro_active'  => $has_elementor_pro,
					'nexter_extension'      => $has_nexter_ext,
					'nexter_theme'          => $has_nexter_theme,
					'active_kit_found'      => $kit_available,
					'has_nav_menu'          => $has_nav_menu,
					'has_custom_logo'       => $has_custom_logo,
					'has_site_icon'         => $has_site_icon,
				),
				'nav_menus'            => $nav_menu_names,
				'active_header'        => $active_header,
				'active_footer'        => $active_footer,
				'branding'             => array(
					'custom_logo_id'  => $has_custom_logo ? $custom_logo_id : null,
					'custom_logo_url' => $custom_logo_url,
					'site_icon_id'    => $has_site_icon ? $site_icon_id : null,
					'site_icon_url'   => $site_icon_url,
				),
				'diagnostics'          => array(
					'active_kit_id'  => $active_kit_id,
					'wp_version'     => get_bloginfo( 'version' ),
					'php_version'    => phpversion(),
					'site_url'       => get_site_url(),
					'server'         => self::SERVER_ID,
					'server_version' => self::SERVER_VERSION,
				),
				'message'              => $is_ready
					? sprintf( 'Configuration looks good. Building with %s.', '' !== $builder && isset( $builders[ $builder ] ) ? $builders[ $builder ]['label'] : 'the active page builder' )
					: trim( 'Configuration issue detected. ' . implode( ' ', $notes ) ),
			);
		}

		private static function detect_active_header_footer_templates( $type ) {
			$active = array();

			// Cap well above any realistic template count so an active header/footer
			// is never missed past a low limit (the result drives the merge-conflict
			// warning — under-counting silently clobbers an existing header/footer).
			$ep_posts = get_posts( array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs in admin/editor context.
				'meta_query'     => array(
					array( 'key' => '_elementor_template_type', 'value' => $type ),
				),
			) );
			foreach ( $ep_posts as $pid ) {
				$conditions = get_post_meta( (int) $pid, '_elementor_conditions', true );
				if ( ! empty( $conditions ) && is_array( $conditions ) ) {
					$active[] = array(
						'system'     => 'elementor_pro',
						'post_id'    => (int) $pid,
						'title'      => get_the_title( (int) $pid ),
						'conditions' => $conditions,
					);
				}
			}

			if ( post_type_exists( 'nxt_builder' ) ) {
				$nxt_posts = get_posts( array(
					'post_type'      => 'nxt_builder',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs in admin/editor context.
					'meta_query'     => array(
						'relation' => 'AND',
						array( 'key' => 'nxt-hooks-layout-sections', 'value' => $type ),
						array( 'key' => 'nxt_build_status', 'value'   => '1' ),
					),
				) );
				foreach ( $nxt_posts as $pid ) {
					$active[] = array(
						'system'  => 'nexter',
						'post_id' => (int) $pid,
						'title'   => get_the_title( (int) $pid ),
					);
				}
			}

			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				$native_posts = get_posts( array(
					'post_type'      => UiChemy_Template_CPT::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs in admin/editor context.
					'meta_query'     => array(
						'relation' => 'AND',
						array( 'key' => UiChemy_Template_CPT::META_TYPE, 'value' => $type ),
						array( 'key' => UiChemy_Template_CPT::META_STATUS, 'value' => 'active' ),
					),
				) );
				foreach ( $native_posts as $pid ) {
					$active[] = array(
						'system'  => 'uichemy_native',
						'post_id' => (int) $pid,
						'title'   => get_the_title( (int) $pid ),
					);
				}
			}

			return $active;
		}

		/**
		 * Read the #uichemy-globals CSS block (the design-system source of truth).
		 *
		 * @param array $arguments Unused.
		 * @return array|WP_Error { css: string }
		 */
		public static function execute_get_globals_css( $arguments = array() ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) || ! method_exists( 'UiChemy_Composer_Manager', 'get_globals_block_css' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer Manager unavailable. Ensure UiChemy plugin is active.' );
			}
			return array( 'css' => UiChemy_Composer_Manager::get_globals_block_css() );
		}

		/**
		 * Replace the #uichemy-globals CSS block with the supplied full CSS.
		 *
		 * @param array $arguments { css: string }.
		 * @return array|WP_Error { success: bool, css: string }
		 */
		public static function execute_set_globals_css( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) || ! method_exists( 'UiChemy_Composer_Manager', 'upsert_globals_block' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer Manager unavailable. Ensure UiChemy plugin is active.' );
			}
			$arguments = is_array( $arguments ) ? $arguments : array();
			$css       = isset( $arguments['css'] ) ? (string) $arguments['css'] : '';
			$saved     = UiChemy_Composer_Manager::upsert_globals_block( $css );
			return array(
				'success' => true,
				'css'     => $saved,
			);
		}


		/**
		 * Incrementally merge edit ops into the #uichemy-globals block.
		 *
		 * @param array $arguments { set, remove, set_classes, remove_classes }.
		 * @return array|WP_Error { success, css }
		 */
		public static function execute_edit_globals_css( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) || ! method_exists( 'UiChemy_Composer_Manager', 'edit_globals_block' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer Manager unavailable. Ensure UiChemy plugin is active.' );
			}
			$arguments = is_array( $arguments ) ? $arguments : array();
			$edits     = isset( $arguments['edits'] ) && is_array( $arguments['edits'] ) ? $arguments['edits'] : array();
			// Also accept a single top-level find/replace as a convenience.
			if ( empty( $edits ) && isset( $arguments['find'] ) ) {
				$edits = array(
					array(
						'find'    => $arguments['find'],
						'replace' => isset( $arguments['replace'] ) ? $arguments['replace'] : '',
					),
				);
			}
			// A find that matches several places replaces every one of them, and an
			// anchor that looked unique (":is(.elementor-widget-…") silently copied
			// a block into two spots. Count first: more than one match is refused
			// unless the edit says all:true, and a refusal writes nothing.
			if ( method_exists( 'UiChemy_Composer_Manager', 'get_globals_block_css' ) ) {
				$probe = UiChemy_Composer_Manager::get_globals_block_css();
				$multi = array();
				foreach ( $edits as $edit ) {
					if ( ! is_array( $edit ) || ! isset( $edit['find'] ) || '' === (string) $edit['find'] ) {
						continue;
					}
					$hits = substr_count( $probe, (string) $edit['find'] );
					if ( $hits > 1 && empty( $edit['all'] ) ) {
						$multi[] = array( 'find' => (string) $edit['find'], 'count' => $hits );
					}
					// Later edits see what earlier ones produced.
					$probe = str_replace( (string) $edit['find'], isset( $edit['replace'] ) ? (string) $edit['replace'] : '', $probe );
				}
				if ( $multi ) {
					return new WP_Error(
						'uich_globals_find_not_unique',
						'Nothing was saved: ' . count( $multi ) . ' edit(s) match more than one place, so every copy would change. Add surrounding text to make each find unique, or pass "all": true on an edit that really should change every match. ' . wp_json_encode( $multi ),
						array( 'status' => 409, 'ambiguous' => $multi )
					);
				}
			}

			$result       = UiChemy_Composer_Manager::edit_globals_block( $edits );
			$replacements = isset( $result['replacements'] ) ? $result['replacements'] : array();

			// A count:0 entry means that edit's `find` text wasn't present in the block —
			// nothing changed for it. Report success only when EVERY edit in the batch
			// actually matched; otherwise a caller (human or AI) that only checks the
			// top-level flag would be told "done" while a requested change silently
			// never applied (the bug this guards against: an AI reports "added" to the
			// user off a bare `success: true` without inspecting `replacements`).
			$all_matched = ! empty( $replacements );
			foreach ( $replacements as $r ) {
				if ( empty( $r['count'] ) ) {
					$all_matched = false;
					break;
				}
			}

			return array(
				'success'      => $all_matched,
				'css'          => isset( $result['css'] ) ? $result['css'] : '',
				'replacements' => $replacements,
			);
		}
		private static function is_atomic_enabled() {
			if ( ! class_exists( '\Elementor\Plugin' ) ) {
				return false;
			}
			$experiments = \Elementor\Plugin::$instance->experiments;
			if ( ! $experiments || ! method_exists( $experiments, 'is_feature_active' ) ) {
				return false;
			}
			return (bool) $experiments->is_feature_active( 'e_atomic_elements' );
		}

		public static function execute_create_uichemy_composer_page( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$payload   = array(
				'builder'       => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
				'title'         => isset( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : 'UiChemy AI Landing Page',
				'status'        => isset( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'draft',
				'source'        => isset( $arguments['source'] ) ? sanitize_text_field( (string) $arguments['source'] ) : 'mcp',
				'label'         => isset( $arguments['label'] ) ? sanitize_text_field( (string) $arguments['label'] ) : '',
				'html'          => isset( $arguments['html'] ) ? (string) $arguments['html'] : '',
				'css'           => isset( $arguments['css'] ) ? (string) $arguments['css'] : '',
				'js'            => isset( $arguments['js'] ) ? (string) $arguments['js'] : '',
				'page_css'      => isset( $arguments['page_before_head'] ) ? (string) $arguments['page_before_head'] : '',
				'page_js'       => isset( $arguments['page_before_body'] ) ? (string) $arguments['page_before_body'] : '',
				'site_css'      => isset( $arguments['site_before_head'] ) ? (string) $arguments['site_before_head'] : '',
				'site_js'       => isset( $arguments['site_before_body'] ) ? (string) $arguments['site_before_body'] : '',
				'upload_images' => isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true,
			);

			// Only forwarded when supplied, so the manager keeps defaulting to page.
			// uichemy-composer/post sets this; uichemy-composer/page pins it to "page" upstream.
			if ( ! empty( $arguments['post_type'] ) ) {
				$payload['post_type'] = sanitize_key( (string) $arguments['post_type'] );
			}

			$create_result = UiChemy_Composer_Manager::mcp_create_page_with_generated_code( $payload );
			if ( is_wp_error( $create_result ) ) {
				return $create_result;
			}

			return $create_result;
		}

		public static function execute_add_uichemy_composer_section( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'uich_mcp_error', 'Missing required parameter: post_id' );
			}

			$payload = array(
				'builder'       => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
				'post_id'       => $post_id,
				'label'         => isset( $arguments['label'] ) ? sanitize_text_field( (string) $arguments['label'] ) : 'Section',
				'source'        => isset( $arguments['source'] ) ? sanitize_text_field( (string) $arguments['source'] ) : 'mcp',
				'html'          => isset( $arguments['html'] ) ? (string) $arguments['html'] : '',
				'css'           => isset( $arguments['css'] ) ? (string) $arguments['css'] : '',
				'js'            => isset( $arguments['js'] ) ? (string) $arguments['js'] : '',
				'page_css'      => isset( $arguments['page_before_head'] ) ? (string) $arguments['page_before_head'] : '',
				'page_js'       => isset( $arguments['page_before_body'] ) ? (string) $arguments['page_before_body'] : '',
				'site_css'      => isset( $arguments['site_before_head'] ) ? (string) $arguments['site_before_head'] : '',
				'site_js'       => isset( $arguments['site_before_body'] ) ? (string) $arguments['site_before_body'] : '',
				'upload_images' => isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true,
			);

			$add_result = UiChemy_Composer_Manager::mcp_add_section_to_page( $payload );
			if ( is_wp_error( $add_result ) ) {
				return $add_result;
			}

			return $add_result;
		}

		public static function execute_create_uichemy_composer_header_footer( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$type      = isset( $arguments['type'] ) ? sanitize_key( (string) $arguments['type'] ) : 'header';
			if ( ! in_array( $type, array( 'header', 'footer' ), true ) ) {
				return new WP_Error( 'uich_mcp_error', 'Parameter "type" must be "header" or "footer".' );
			}

			$payload = array(
				'builder'       => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
				'type'          => $type,
				'title'         => isset( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : ( ucfirst( $type ) . ' UiChemy' ),
				'label'         => isset( $arguments['label'] ) ? sanitize_text_field( (string) $arguments['label'] ) : ucfirst( $type ),
				'source'        => isset( $arguments['source'] ) ? sanitize_text_field( (string) $arguments['source'] ) : 'mcp',
				'system'        => isset( $arguments['system'] ) ? sanitize_key( (string) $arguments['system'] ) : 'auto',
				'html'          => isset( $arguments['html'] ) ? (string) $arguments['html'] : '',
				'css'           => isset( $arguments['css'] ) ? (string) $arguments['css'] : '',
				'js'            => isset( $arguments['js'] ) ? (string) $arguments['js'] : '',
				// Caller-facing names first, like the other creators; internal names
				// stay supported for in-process callers.
				'site_css'      => isset( $arguments['site_before_head'] )
					? (string) $arguments['site_before_head']
					: ( isset( $arguments['site_css'] ) ? (string) $arguments['site_css'] : '' ),
				'site_js'       => isset( $arguments['site_before_body'] )
					? (string) $arguments['site_before_body']
					: ( isset( $arguments['site_js'] ) ? (string) $arguments['site_js'] : '' ),
				'upload_images' => isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true,
			);

			$result = UiChemy_Composer_Manager::mcp_create_header_footer_template( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}

		public static function execute_create_single_post_widget( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$payload   = array(
				'builder'       => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
				'post_type'           => isset( $arguments['post_type'] ) ? sanitize_key( (string) $arguments['post_type'] ) : 'post',
				'title'               => isset( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : 'Single Post UiChemy',
				'label'               => isset( $arguments['label'] ) ? sanitize_text_field( (string) $arguments['label'] ) : 'Single Post',
				'source'              => isset( $arguments['source'] ) ? sanitize_text_field( (string) $arguments['source'] ) : 'mcp',
				'html'                => isset( $arguments['html'] ) ? (string) $arguments['html'] : '',
				'css'                 => isset( $arguments['css'] ) ? (string) $arguments['css'] : '',
				'js'                  => isset( $arguments['js'] ) ? (string) $arguments['js'] : '',
				'site_css'            => isset( $arguments['site_before_head'] ) ? (string) $arguments['site_before_head'] : '',
				'site_js'             => isset( $arguments['site_before_body'] ) ? (string) $arguments['site_before_body'] : '',
				'upload_images'       => isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true,
				'force_deactivate'    => isset( $arguments['force_deactivate'] ) ? (bool) $arguments['force_deactivate'] : false,
				'create_sample_post'  => isset( $arguments['create_sample_post'] ) ? (bool) $arguments['create_sample_post'] : false,
				'sample_post_title'   => isset( $arguments['sample_post_title'] ) ? sanitize_text_field( (string) $arguments['sample_post_title'] ) : '',
				'sample_post_content' => isset( $arguments['sample_post_content'] ) ? (string) $arguments['sample_post_content'] : '',
			);

			$result = UiChemy_Composer_Manager::mcp_create_single_post_template( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}

		public static function execute_set_page_site_code( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$post_id   = isset( $arguments['post_id'] ) ? (int) $arguments['post_id'] : 0;
			if ( $post_id <= 0 ) {
				return new WP_Error( 'uich_mcp_error', 'Missing or invalid post_id.' );
			}

			// Only forward the scopes the caller actually supplied. The manager treats
			// a present key as "replace this scope", so passing all four unconditionally
			// would clear the three the caller never mentioned.
			$payload = array(
				'post_id' => $post_id,
				'builder' => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
			);
			$map     = array(
				'site_before_head' => 'site_css',
				'site_before_body' => 'site_js',
				'page_before_head' => 'page_css',
				'page_before_body' => 'page_js',
			);
			foreach ( $map as $public_key => $payload_key ) {
				if ( isset( $arguments[ $public_key ] ) ) {
					$payload[ $payload_key ] = (string) $arguments[ $public_key ];
				}
			}

			if ( ! method_exists( 'UiChemy_Composer_Manager', 'mcp_set_page_site_code' ) ) {
				return new WP_Error( 'uich_mcp_error', 'mcp_set_page_site_code is not yet implemented.' );
			}

			$update_result = UiChemy_Composer_Manager::mcp_set_page_site_code( $payload );
			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}

			return $update_result;
		}

		public static function execute_list_pages( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$post_type = isset( $arguments['post_type'] ) ? sanitize_key( (string) $arguments['post_type'] ) : 'page';
			$status    = isset( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'any';
			$per_page  = isset( $arguments['per_page'] ) ? min( 100, max( 1, (int) $arguments['per_page'] ) ) : 20;
			$cb_loaded = class_exists( 'Uich_CB_Content' );
			$cb_only   = $cb_loaded && ! empty( $arguments['composer_builder'] );

			$query = array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			// Only Composer Builder pages - the ones get-code / set-code / patch-code edit.
			if ( $cb_only ) {
				$query['meta_key']   = Uich_CB_Content::META_ENABLED; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed key, capped at 100 rows.
				$query['meta_value'] = '1'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			}

			$posts = get_posts( $query );

			$items = array();
			foreach ( $posts as $post ) {
				// The builder link has to come from whichever builder OWNS this post,
				// not from a hardcoded ?action=elementor. Every row used to carry an
				// Elementor edit link even on a site with no Elementor — a dead link
				// an agent could follow. A post no builder claims simply gets none.
				// The Composer Builder has no driver, so it is checked first: its
				// pages are edited with get-code / set-code / patch-code.
				$row_is_cb   = $cb_loaded && Uich_CB_Content::is_enabled( $post->ID );
				$row_builder = $row_is_cb
					? 'composer'
					: ( class_exists( 'UiChemy_Builder_Registry' ) ? UiChemy_Builder_Registry::detect_post_builder( $post->ID ) : '' );
				$row_driver  = ! $row_is_cb && '' !== $row_builder ? UiChemy_Builder_Registry::get( $row_builder ) : null;

				if ( $row_is_cb ) {
					$row_links = array(
						'edit_link' => Uich_CB_Content::edit_url( $post->ID ),
						'edit_with' => 'get-code / set-code / patch-code',
					);
				} else {
					$row_links = $row_driver
						? $row_driver->edit_links( $post->ID )
						: array( 'edit_link' => (string) get_edit_post_link( $post->ID, 'internal' ) );
				}

				$items[] = array_merge(
					array(
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'status'   => $post->post_status,
						'type'     => $post->post_type,
						'builder'  => $row_builder,
						'modified' => $post->post_modified,
						'url'      => get_permalink( $post->ID ),
					),
					$row_links
				);
			}

			return array(
				'post_type' => $post_type,
				'status'    => $status,
				'total'     => count( $items ),
				'pages'     => $items,
			);
		}

		public static function execute_list_templates( $arguments ) {
			$arguments   = is_array( $arguments ) ? $arguments : array();
			$type_filter = isset( $arguments['type'] ) ? sanitize_key( (string) $arguments['type'] ) : 'all';
			$per_page    = isset( $arguments['per_page'] ) ? min( 100, max( 1, (int) $arguments['per_page'] ) ) : 50;

			$templates  = array();
			$meta_query = array();
			if ( 'all' !== $type_filter ) {
				$meta_query[] = array( 'key' => '_elementor_template_type', 'value' => $type_filter );
			}

			$el_posts = get_posts( array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs in admin/editor context.
				'meta_query'     => $meta_query,
			) );
			foreach ( $el_posts as $post ) {
				$template_type = get_post_meta( $post->ID, '_elementor_template_type', true );
				$conditions    = get_post_meta( $post->ID, '_elementor_conditions', true );
				$is_active     = ! empty( $conditions ) && is_array( $conditions );
				$templates[]   = array(
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'system'         => 'elementor_library',
					'template_type'  => $template_type ? $template_type : 'unknown',
					'status'         => $post->post_status,
					'active'         => $is_active,
					'conditions'     => $is_active ? $conditions : array(),
					'edit_link'      => get_edit_post_link( $post->ID, 'internal' ),
					'elementor_link' => add_query_arg( array( 'post' => $post->ID, 'action' => 'elementor' ), admin_url( 'post.php' ) ),
				);
			}

			if ( post_type_exists( 'nxt_builder' ) ) {
				$nxt_meta = array();
				if ( 'all' !== $type_filter ) {
					$nxt_meta[] = array( 'key' => 'nxt-hooks-layout-sections', 'value' => $type_filter );
				}
				$nxt_posts = get_posts( array(
					'post_type'      => 'nxt_builder',
					'post_status'    => 'any',
					'posts_per_page' => $per_page,
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs in admin/editor context.
					'meta_query'     => $nxt_meta,
				) );
				foreach ( $nxt_posts as $post ) {
					$nxt_type    = get_post_meta( $post->ID, 'nxt-hooks-layout-sections', true );
					$is_active   = '1' === get_post_meta( $post->ID, 'nxt_build_status', true );
					$templates[] = array(
						'id'             => $post->ID,
						'title'          => $post->post_title,
						'system'         => 'nexter',
						'template_type'  => $nxt_type ? $nxt_type : 'unknown',
						'status'         => $post->post_status,
						'active'         => $is_active,
						'conditions'     => array(),
						'edit_link'      => get_edit_post_link( $post->ID, 'internal' ),
						'elementor_link' => add_query_arg( array( 'post' => $post->ID, 'action' => 'elementor' ), admin_url( 'post.php' ) ),
					);
				}
			}

			// UiChemy's OWN theme-builder templates. These were missing entirely,
			// which meant the tool a model uses to see what already exists never
			// showed the templates UiChemy itself creates — so it would happily
			// create a second header over the top of one it had just made.
			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				$uich_posts = get_posts(
					array(
						'post_type'      => UiChemy_Template_CPT::POST_TYPE,
						'post_status'    => 'any',
						'posts_per_page' => $per_page,
						'no_found_rows'  => true,
					)
				);
				foreach ( $uich_posts as $post ) {
					$tpl_type = (string) get_post_meta( $post->ID, UiChemy_Template_CPT::META_TYPE, true );
					if ( 'all' !== $type_filter && $tpl_type !== $type_filter ) {
						continue;
					}
					$editor      = method_exists( 'UiChemy_Template_CPT', 'editor_for' ) ? UiChemy_Template_CPT::editor_for( $post->ID ) : '';
					$templates[] = array(
						'id'            => $post->ID,
						'title'         => $post->post_title,
						'system'        => 'uichemy',
						'builder'       => $editor,
						'template_type' => '' !== $tpl_type ? $tpl_type : 'unknown',
						'status'        => $post->post_status,
						'active'        => 'active' === (string) get_post_meta( $post->ID, UiChemy_Template_CPT::META_STATUS, true ),
						'conditions'    => (array) get_post_meta( $post->ID, UiChemy_Template_CPT::META_CONDITIONS, true ),
						'edit_link'     => get_edit_post_link( $post->ID, 'internal' ),
					);
				}
			}

			// Bricks' own template library.
			if ( post_type_exists( 'bricks_template' ) ) {
				$bricks_posts = get_posts(
					array(
						'post_type'      => 'bricks_template',
						'post_status'    => 'any',
						'posts_per_page' => $per_page,
						'no_found_rows'  => true,
					)
				);
				foreach ( $bricks_posts as $post ) {
					$b_type = (string) get_post_meta( $post->ID, '_bricks_template_type', true );
					if ( 'all' !== $type_filter && $b_type !== $type_filter ) {
						continue;
					}
					$templates[] = array(
						'id'            => $post->ID,
						'title'         => $post->post_title,
						'system'        => 'bricks',
						'builder'       => 'bricks',
						'template_type' => '' !== $b_type ? $b_type : 'unknown',
						'status'        => $post->post_status,
						// Bricks decides placement from its own template conditions,
						// which live in meta rather than a boolean flag.
						'active'        => ! empty( get_post_meta( $post->ID, '_bricks_template_conditions', true ) ),
						'conditions'    => (array) get_post_meta( $post->ID, '_bricks_template_conditions', true ),
						'edit_link'     => get_edit_post_link( $post->ID, 'internal' ),
						'bricks_link'   => add_query_arg( array( 'bricks' => 'run' ), get_permalink( $post->ID ) ),
					);
				}
			}

			// Block-theme templates. Unlike every other system these resolve from
			// theme FILES as well as the database, so get_block_templates() is the
			// only correct source — a wp_template post query misses everything the
			// theme ships and never had customised.
			if ( function_exists( 'get_block_templates' ) && wp_is_block_theme() ) {
				foreach ( array( 'wp_template', 'wp_template_part' ) as $block_type ) {
					foreach ( (array) get_block_templates( array(), $block_type ) as $bt ) {
						$slug = isset( $bt->slug ) ? (string) $bt->slug : '';
						if ( 'all' !== $type_filter && $slug !== $type_filter ) {
							continue;
						}
						$templates[] = array(
							'id'            => isset( $bt->wp_id ) && $bt->wp_id ? (int) $bt->wp_id : 0,
							'title'         => isset( $bt->title ) && '' !== $bt->title ? (string) $bt->title : $slug,
							'system'        => 'block_theme',
							'builder'       => 'gutenberg',
							'template_type' => $slug,
							// 'custom' means it has been saved into the database;
							// 'theme' means it is still the file the theme ships.
							'status'        => isset( $bt->source ) ? (string) $bt->source : 'theme',
							'active'        => true,
							'conditions'    => array(),
							'edit_link'     => admin_url( 'site-editor.php?postType=' . $block_type . '&postId=' . rawurlencode( (string) ( $bt->id ?? '' ) ) ),
						);
					}
					if ( count( $templates ) >= $per_page * 4 ) {
						break;
					}
				}
			}

			return array(
				'type_filter' => $type_filter,
				'total'       => count( $templates ),
				'templates'   => $templates,
			);
		}

		public static function execute_get_post_structure( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
			$builder   = isset( $arguments['builder'] ) ? (string) $arguments['builder'] : '';
			if ( ! $post_id ) {
				return new WP_Error( 'uich_mcp_error', 'Missing required parameter: post_id' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'uich_mcp_error', "Post ID {$post_id} not found." );
			}

			// A Composer Builder page is claimed by no driver, so without this it
			// would read as the empty answer below - "nothing here" - when it has
			// a whole document. The driver lookup refuses it and names the ability.
			if ( UiChemy_Composer_Manager::is_composer_builder_post( $post_id ) ) {
				return UiChemy_Composer_Manager::mcp_driver_for_post( $post_id, $builder, false );
			}

			// A post no builder claims is not an error — it is an empty answer, and
			// the caller needs to be able to tell those apart.
			if ( class_exists( 'UiChemy_Builder_Registry' )
				&& '' === UiChemy_Builder_Registry::detect_post_builder( $post_id ) ) {
				return array(
					'post_id'               => $post_id,
					'post_title'            => $post->post_title,
					'post_type'             => $post->post_type,
					'builder'               => '',
					'elementor_enabled'     => false,
					'total_uichemy_widgets' => 0,
					'structure'             => array(),
					'message'               => 'No page builder has written content to this post yet, so it has no structure to report.',
				);
			}

			$driver = UiChemy_Composer_Manager::mcp_driver_for_post( $post_id, $builder, false );
			if ( is_wp_error( $driver ) ) {
				return $driver;
			}

			return $driver->describe_structure( $post_id );
		}

		public static function execute_find_and_update_section_code( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments    = is_array( $arguments ) ? $arguments : array();
			$post_id      = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
			$builder      = isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '';
			$action       = isset( $arguments['action'] ) ? sanitize_key( (string) $arguments['action'] ) : 'get';
			$widget_index = isset( $arguments['widget_index'] ) ? (int) $arguments['widget_index'] : 0;
			$element_id   = isset( $arguments['element_id'] ) ? sanitize_text_field( (string) $arguments['element_id'] ) : '';
			// Site-wide custom code (~130 KB) is omitted by default so a plain `get`
			// does not blow the tool-result token cap. Opt in with include_site_code=true.
			$include_site_code = isset( $arguments['include_site_code'] ) ? (bool) $arguments['include_site_code'] : false;

			if ( ! $post_id ) {
				return new WP_Error( 'uich_mcp_error', 'Missing required parameter: post_id' );
			}
			if ( ! in_array( $action, array( 'get', 'set' ), true ) ) {
				return new WP_Error( 'uich_mcp_error', 'Parameter "action" must be "get" or "set".' );
			}

			// element_id (Elementor data-id) resolves the exact widget regardless of
			// DOM/data ordering; widget_index is the positional fallback.
			$get_result = UiChemy_Composer_Manager::mcp_get_section_code( $post_id, $widget_index, $element_id, $include_site_code );
			if ( is_wp_error( $get_result ) ) {
				return $get_result;
			}

			if ( 'get' === $action ) {
				return $get_result;
			}

			$html          = isset( $arguments['html'] ) ? (string) $arguments['html'] : '';
			$css           = isset( $arguments['css'] ) ? (string) $arguments['css'] : '';
			$js            = isset( $arguments['js'] ) ? (string) $arguments['js'] : '';
			$upload_images = isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true;

			if ( '' === trim( $html ) && '' === trim( $css ) && '' === trim( $js ) ) {
				return new WP_Error( 'uich_mcp_error', 'For action="set" at least one of html, css, or js must be provided.' );
			}

			$widget_id  = $get_result['widget_id'];
			$set_result = UiChemy_Composer_Manager::mcp_sync_generated_code_to_widget(
				$post_id,
				array(
					'builder'       => $builder,
					'mode'          => 'replace',
					'widget_id'     => $widget_id,
					'html'          => $html,
					'css'           => $css,
					'js'            => $js,
					'upload_images' => $upload_images,
					'source'        => 'mcp',
					'label'         => $get_result['label'],
				)
			);
			if ( is_wp_error( $set_result ) ) {
				return $set_result;
			}

			return $set_result;
		}

		public static function execute_insert_section_at_index( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Manager' ) ) {
				return new WP_Error( 'uich_mcp_error', 'Composer manager class not found.' );
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'uich_mcp_error', 'Missing required parameter: post_id' );
			}

			$payload = array(
				'builder'       => isset( $arguments['builder'] ) ? sanitize_key( (string) $arguments['builder'] ) : '',
				'post_id'       => $post_id,
				'insert_index'  => isset( $arguments['insert_index'] ) ? (int) $arguments['insert_index'] : 0,
				'label'         => isset( $arguments['label'] ) ? sanitize_text_field( (string) $arguments['label'] ) : 'Section',
				'source'        => isset( $arguments['source'] ) ? sanitize_text_field( (string) $arguments['source'] ) : 'mcp',
				'html'          => isset( $arguments['html'] ) ? (string) $arguments['html'] : '',
				'css'           => isset( $arguments['css'] ) ? (string) $arguments['css'] : '',
				'js'            => isset( $arguments['js'] ) ? (string) $arguments['js'] : '',
				'upload_images' => isset( $arguments['upload_images'] ) ? (bool) $arguments['upload_images'] : true,
			);

			$result = UiChemy_Composer_Manager::mcp_insert_section_at_index( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}

		/**
		 * Issue a temporary upload slot for an AI-generated image.
		 *
		 * Thin wrapper around UiChemy_Composer_Upload::issue_slot — the heavy
		 * lifting (token mint, transient store, curl example) lives in
		 * class-uichemy-composer-upload.php. We just sanitize the MCP-side
		 * inputs and forward.
		 */
		public static function execute_request_media_upload( $arguments ) {
			if ( ! class_exists( 'UiChemy_Composer_Upload' ) ) {
				require_once UICHEMY_PATH . 'includes/mcp/shared/class-uichemy-composer-upload.php';
			}

			$arguments = is_array( $arguments ) ? $arguments : array();
			$payload   = array();
			if ( isset( $arguments['ttl_minutes'] ) ) {
				$payload['ttl_minutes'] = (int) $arguments['ttl_minutes'];
			}

			return UiChemy_Composer_Upload::issue_slot( $payload );
		}
	}
}
