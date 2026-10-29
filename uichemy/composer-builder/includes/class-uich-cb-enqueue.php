<?php
/**
 * Uich_CB_Enqueue — asset wiring for the Composer Builder.
 *
 * Two surfaces, both served from here so there is one answer to "where does the
 * builder's JavaScript come from":
 *
 *   editor_assets()     the full-screen builder screen (React bundle + shell CSS)
 *   block_editor_assets() the "Edit with UiChemy" button inside Gutenberg
 *
 * The React bundle is read out of new-dashboard/build/ through UICH_CB_BUILD_*,
 * exactly as UiChemy_Composer_Enqueue::enqueue_composer_react() reads the merged
 * composer panel — including the generated `*.asset.php`, so the WordPress script
 * dependencies webpack computed (react, react-dom, wp-element, the automatic JSX
 * runtime, …) are never hard-coded here and drift out of date.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Enqueue' ) ) {

	/**
	 * Registers and enqueues the builder's scripts and styles.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Enqueue {

		/**
		 * Handle: the compiled React builder app.
		 */
		const HANDLE_APP_JS = 'uich-cb-app';

		/**
		 * Handle: the compiled React builder stylesheet.
		 */
		const HANDLE_APP_CSS = 'uich-cb-app-style';

		/**
		 * Handle: the pre-React shell stylesheet for the builder screen.
		 */
		const HANDLE_SHELL_CSS = 'uich-cb-shell';

		/**
		 * Handle: the block-editor "Edit with UiChemy" integration.
		 */
		const HANDLE_GUTENBERG_JS = 'uich-cb-gutenberg';

		/**
		 * Handle: styles for the block-editor button.
		 */
		const HANDLE_GUTENBERG_CSS = 'uich-cb-gutenberg-style';

		/**
		 * Webpack entry name for the block-editor stylesheet.
		 *
		 * Built rather than static because it carries the dashboard's design
		 * tokens — see composer-builder/src/editor-entry.js.
		 */
		const EDITOR_BUNDLE = 'editor-button';

		/**
		 * Is the React bundle actually built?
		 *
		 * A source checkout that has not run `npm run build` has no bundle, and
		 * the builder screen would render as a white page with no explanation.
		 * Uich_CB_Editor asks this and prints a real message instead.
		 *
		 * @return bool
		 */
		public static function has_build() {
			return file_exists( self::build_path( 'js' ) ) && file_exists( self::build_path( 'asset' ) );
		}

		/**
		 * Absolute path to one of the bundle's build artefacts.
		 *
		 * @param string $type One of 'js', 'css', 'asset'.
		 * @return string
		 */
		private static function build_path( $type ) {
			$map = array(
				'js'    => UICH_CB_BUNDLE . '.js',
				'css'   => UICH_CB_BUNDLE . '.css',
				'asset' => UICH_CB_BUNDLE . '.asset.php',
			);

			return UICH_CB_BUILD_PATH . ( isset( $map[ $type ] ) ? $map[ $type ] : '' );
		}

		/**
		 * Everything the full-screen builder screen loads.
		 *
		 * Called from Uich_CB_Editor immediately before the screen is printed —
		 * that screen is not a wp-admin page, so it has no `admin_enqueue_scripts`
		 * of its own and prints whatever is enqueued by the time it runs.
		 *
		 * @param int $post_id Post being edited.
		 * @return void
		 */
		public static function editor_assets( $post_id ) {
			$shell_css = UICH_CB_PATH . 'assets/css/uich-cb-editor.css';
			wp_enqueue_style(
				self::HANDLE_SHELL_CSS,
				UICH_CB_URL . 'assets/css/uich-cb-editor.css',
				array(),
				file_exists( $shell_css ) ? (string) filemtime( $shell_css ) : UICH_VERSION
			);

			if ( ! self::has_build() ) {
				return;
			}

			$js_file    = self::build_path( 'js' );
			$css_file   = self::build_path( 'css' );
			$asset_file = self::build_path( 'asset' );

			$asset   = include $asset_file;
			$deps    = ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) ? $asset['dependencies'] : array();
			$version = isset( $asset['version'] ) ? (string) $asset['version'] : (string) filemtime( $js_file );

			if ( file_exists( $css_file ) ) {
				wp_enqueue_style(
					self::HANDLE_APP_CSS,
					UICH_CB_BUILD_URL . UICH_CB_BUNDLE . '.css',
					array( self::HANDLE_SHELL_CSS ),
					(string) filemtime( $css_file )
				);
				// Load the generated RTL build (builder-rtl.css) on RTL locales.
				wp_style_add_data( self::HANDLE_APP_CSS, 'rtl', 'replace' );
			}

			wp_enqueue_script(
				self::HANDLE_APP_JS,
				UICH_CB_BUILD_URL . UICH_CB_BUNDLE . '.js',
				$deps,
				$version,
				true
			);

			// The side panel is the Composer's own ComposerPanel, and its Chat tab
			// is Pro's bundle (uichemy-pro: UiChemy_Chat_Assets), which registers
			// itself through window.UichComposerRuntime — published by THIS
			// bundle (src/editor/index.jsx imports composer-runtime-exports).
			// Pro enqueues on the same action Elementor and Gutenberg fire, and
			// depends on the 'uichemy-composer-composer' handle; here that handle
			// is an alias for our bundle, so Pro's chat loads after it exactly as
			// it does after the Composer bundle in the other builders.
			if ( ! wp_script_is( 'uichemy-composer-composer', 'registered' ) ) {
				wp_register_script( 'uichemy-composer-composer', false, array( self::HANDLE_APP_JS ), $version, true );
			}
			if ( defined( 'UICHEMY_PATH' ) ) {
				/** This action is documented in uichemy-composer/includes/admin/class-uichemy-composer-enqueue.php */
				do_action( 'uichemy/composer/enqueue_editor_scripts', UICHEMY_PATH . 'assets/js/' );
			}

			// Both globals are the vendored editor's own names: index.jsx reads
			// UIPB_BOOT, host/page-client.js reads UIPB_REST.
			self::print_payload( self::HANDLE_APP_JS, 'UIPB_BOOT', self::boot_payload( $post_id ) );
			self::print_payload(
				self::HANDLE_APP_JS,
				'UIPB_REST',
				array(
					'root'    => esc_url_raw( rest_url( Uich_CB_Rest::NS . Uich_CB_Rest::BASE ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					// Core's `rest-nonce` action hands a logged-in user a fresh
					// REST nonce, so an editor left open past the nonce's life
					// can keep saving (A-05).
					'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				)
			);
			// The SEO panel is the Composer's own (new-dashboard/src/seo), bundled
			// into this editor, and reads its REST root, nonce and post from
			// `window.uichemySeo` — the global every other editor gets. Absent when
			// this page has no SEO (a template, a type with SEO off, no module):
			// the editor then shows no SEO button.
			if ( class_exists( 'Uich_SEO_Admin' ) && method_exists( 'Uich_SEO_Admin', 'builder_config' ) ) {
				$seo = Uich_SEO_Admin::builder_config( $post_id );
				if ( $seo ) {
					self::print_payload( self::HANDLE_APP_JS, 'uichemySeo', $seo );
				}
			}
			// Where the chat's two engines live — WordPress AI and the local CLI
			// agent — and whether this user may use the chat at all.
			self::print_payload( self::HANDLE_APP_JS, 'UIPB_AGENT', self::agent_payload() );
			// Site-wide globals — the SAME store the Composer panels in the
			// other builders edit, so a variable made here is the one they see.
			if ( class_exists( 'UiChemy_Composer_Manager' ) ) {
				self::print_payload( self::HANDLE_APP_JS, 'uichComposerEditorCfg', self::globals_payload( $post_id ) );
			}

			// The uploaded code files (wp-content/uichemy-composer/) the Code tab
			// shows under their folder: where references to them start, and
			// whether this user may save them.
			$code_base = class_exists( 'Uich_CB_Code_Files' ) ? Uich_CB_Code_Files::base_path() : '';
			if ( '' !== $code_base && '' !== Uich_CB_Code_Files::dir() ) {
				self::print_payload(
					self::HANDLE_APP_JS,
					'UIPB_CODE_FILES',
					array(
						'folder'   => Uich_CB_Code_Files::DIRNAME,
						'basePath' => $code_base,
						'canWrite' => Uich_CB_Code_Files::can_write(),
					)
				);
			}

			// Dynamic data (Twig): the picker scripts and the config the inspector
			// reads - window.uichAtomFields (the site's custom fields, which
			// integrations are active, Pro), uichAtomPreview and uichAtomFormCfg.
			// The SAME call the Gutenberg, Bricks and Elementor editors make, after
			// the editor bundle as there, so the pickers, loops, conditions and
			// forms behave identically in every builder.
			if ( class_exists( 'Uich_DD_Enqueue' ) && function_exists( 'uichemy_is_pro' ) && defined( 'UICHEMY_URL' ) ) {
				Uich_DD_Enqueue::editor_assets();
			}

			// The WordPress media library, for the Content tab's image / video
			// pickers and the background field. Only for users who may upload:
			// anyone else gets the URL box alone, and the library's AJAX would
			// refuse them anyway. Attached to this post so an upload made here
			// shows under it in the Media screen, as it would from the block
			// editor.
			//
			// The screen is not wp-admin, so the frame's templates are not
			// printed by admin_footer; they go out on the screen's own footer
			// hook, after wp_print_footer_scripts() — wp.media only reads them
			// when a frame first opens.
			if ( current_user_can( 'upload_files' ) ) {
				wp_enqueue_media( array( 'post' => (int) $post_id ) );
				add_action( 'uich_cb/editor/footer', 'wp_print_media_templates' );
			}

			// The CMS side panel (top bar › CMS › an item) edits its custom fields
			// with Custom Fields' own rows (Uich_CB_Rest_Cms::item_payload); their
			// grid and widths come from that module's stylesheet — the same file
			// and handle its post-screen boxes load.
			if ( defined( 'UICH_FIELDS_BOOTED' ) && defined( 'UICH_FIELDS_DIR' ) && defined( 'UICH_FIELDS_URL' ) ) {
				$gf_css = UICH_FIELDS_DIR . 'assets/css/admin/uich-gf-style.css';
				if ( file_exists( $gf_css ) ) {
					wp_enqueue_style( 'uich-gf-render', UICH_FIELDS_URL . 'assets/css/admin/uich-gf-style.css', array(), (string) filemtime( $gf_css ) );
				}
			}

			wp_set_script_translations( self::HANDLE_APP_JS, 'uichemy' );
		}

		/**
		 * The site-wide globals config the editor's Globals panel reads.
		 *
		 * The panel (src/editor/shell/globals) and the inspector's variable
		 * picker read the `#uichemy-globals` block out of `siteCode.head`, and
		 * save through `uichemy_composer_save_site_custom_code` — the AJAX action
		 * and the `uichemy_composer_site_custom_code` option every other Composer
		 * panel uses. Nothing here stores anything of its own; this only hands
		 * the editor what those panels already get, so the globals stay one set
		 * across every builder.
		 *
		 * Deliberately only these keys. The vendored editor reads others from
		 * the same global (`isPro`, `builder`, `contentOnly`, …) and treats an
		 * absent key the way it did before this global existed here.
		 *
		 * `canEditSiteCode` mirrors the save handler's own gate (it refuses
		 * anyone without `unfiltered_html`), so the panel can say "view only"
		 * instead of reporting a save the server is about to refuse.
		 *
		 * @param int $post_id Post being edited.
		 * @return array
		 */
		private static function globals_payload( $post_id = 0 ) {
			// Design mode mounts the same ComposerPanel Elementor and Gutenberg do,
			// so it gets the same account / role / brand keys from the same place
			// (Pro gating, Role Manager features, editor mode, custom fonts).
			$shared = method_exists( 'UiChemy_Composer_Enqueue', 'panel_access_cfg' )
				? UiChemy_Composer_Enqueue::panel_access_cfg()
				: array();

			// UiChemy → Settings → Scroll timeline, read as the front end reads it
			// (UiChemy_Composer_Enqueue): no key yet means on.
			$settings      = (array) get_option( 'uichemy_settings', array() );
			$gsap_timeline = ! isset( $settings['enable_gsap_timeline'] ) || ! empty( $settings['enable_gsap_timeline'] );

			return array_merge(
				$shared,
				array(
					'siteCode'        => UiChemy_Composer_Manager::get_site_custom_code_option(),
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'ajaxNonce'       => wp_create_nonce( UiChemy_Composer_Manager::EDITOR_AJAX_NONCE_ACTION ),
					'canEditSiteCode' => current_user_can( 'unfiltered_html' ),
					'postId'          => (int) $post_id,
					'builder'         => 'uichemy',
					'gsapTimeline'    => $gsap_timeline,
				)
			);
		}

		/**
		 * The chat's config (`window.UIPB_AGENT`, read by src/editor/ai/providers.js).
		 *
		 * Two engines, and the builder owns neither of them:
		 *
		 *   wp   WordPress's own AI Client, keyed under Settings → Connectors. The
		 *        turn endpoint is Pro's (UiChemy_Agent_Endpoint), which keeps the
		 *        API key server-side and runs ONE provider round trip per call —
		 *        the tool loop runs in the browser, against the builder's tools.
		 *   cli  The local Agent Bridge (`npx @uichemy/agent-bridge`), which drives
		 *        Claude / Codex / Gemini / OpenCode already on the user's machine.
		 *        Nothing here can see it; the browser talks to 127.0.0.1 directly.
		 *
		 * `allowed` mirrors UiChemy_Rest_Permissions::check_feature( 'ai_chat' ) —
		 * the gate every agent/chat route already sits behind — so the panel can
		 * say "Pro" or "not for your role" instead of failing on send. It is a
		 * courtesy, not the boundary: the turn route refuses on its own.
		 *
		 * @return array
		 */
		private static function agent_payload() {
			$pro_allowed = ! function_exists( 'uichemy_editor_feature_allowed' ) || uichemy_editor_feature_allowed( 'ai_chat' );
			$role_ok     = current_user_can( 'manage_options' )
				|| ( class_exists( 'UiChemy_Roles' ) && UiChemy_Roles::can_use( 'ai_chat' ) );

			$wp_available = class_exists( 'UiChemy_Agent_Endpoint' ) && function_exists( 'wp_ai_client_prompt' );

			$payload = array(
				'allowed'    => $pro_allowed && $role_ok,
				'locked'     => ! $pro_allowed ? 'pro' : ( $role_ok ? '' : 'role' ),
				'upgradeUrl' => function_exists( 'uichemy_upgrade_url' ) ? uichemy_upgrade_url( 'composer-builder-ai-chat' ) : '',
				'wp'         => array(
					'available'     => $wp_available,
					'turnUrl'       => $wp_available ? esc_url_raw( rest_url( 'uichemy/v1/agent/turn' ) ) : '',
					'modelsUrl'     => $wp_available ? esc_url_raw( rest_url( 'uichemy/v1/agent/models' ) ) : '',
					// WordPress 7.0's own screen for AI provider keys. Only offered
					// to someone who can open it.
					'connectorsUrl' => current_user_can( 'manage_options' ) ? esc_url_raw( admin_url( 'options-connectors.php' ) ) : '',
				),
				'cli'        => array(
					'origin'  => 'http://127.0.0.1:3131',
					'package' => '@uichemy/agent-bridge',
				),
			);

			/**
			 * Filter the builder chat's config.
			 *
			 * @since 5.2.1
			 *
			 * @param array $payload Chat config.
			 */
			return (array) apply_filters( 'uich_cb/agent_payload', $payload );
		}

		/**
		 * The "Edit with UiChemy" button inside the block editor.
		 *
		 * Plain `wp.*` globals, no build step — the same approach as the merged
		 * runtime's uichemy-tb-editor.js, so the button keeps working on any
		 * WordPress version without being recompiled.
		 *
		 * @param int $post_id Post being edited.
		 * @return void
		 */
		public static function block_editor_assets( $post_id ) {
			// Compiled from the design system's own token files, so the button
			// is the dashboard's primary button rather than a lookalike. Absent
			// only in a source checkout that has not been built; the button then
			// falls back to the editor's native styling instead of disappearing.
			$css = UICH_CB_BUILD_PATH . self::EDITOR_BUNDLE . '.css';
			if ( file_exists( $css ) ) {
				wp_enqueue_style(
					self::HANDLE_GUTENBERG_CSS,
					UICH_CB_BUILD_URL . self::EDITOR_BUNDLE . '.css',
					array(),
					(string) filemtime( $css )
				);
				wp_style_add_data( self::HANDLE_GUTENBERG_CSS, 'rtl', 'replace' );
			}

			$js = UICH_CB_PATH . 'assets/js/uich-cb-gutenberg.js';
			wp_enqueue_script(
				self::HANDLE_GUTENBERG_JS,
				UICH_CB_URL . 'assets/js/uich-cb-gutenberg.js',
				array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
				file_exists( $js ) ? (string) filemtime( $js ) : UICH_VERSION,
				true
			);

			$brand = uich_brand_name();

			self::print_payload(
				self::HANDLE_GUTENBERG_JS,
				'uichComposerBuilderEditor',
				array(
					'postId'   => (int) $post_id,
					'editUrl'  => Uich_CB_Content::edit_url( $post_id ),
					// This post's own edit screen. A new page is still on
					// post-new.php when the button is pressed, and Back from the
					// builder to that URL makes a NEW empty page — so the script
					// swaps the history entry to this first.
					'exitUrl'  => Uich_CB_Content::exit_url( $post_id ),
					/*
					 * The MONOCHROME mark, not the full-colour one.
					 *
					 * The button is a solid brand fill, so the mark on it has to
					 * be flat white. uichemy-logo.svg is a raster-filled colour
					 * mark — flattening that with a CSS filter turns it into an
					 * unreadable white blob. bw-logo.svg is the single-path glyph
					 * the admin menu already tints, and it inverts cleanly.
					 *
					 * uich_brand_menu_icon() returns a white-labelled logo when
					 * one is set, and bw-logo.svg otherwise, so `logoMono` below
					 * tells the JS which it got: a customer's own artwork is
					 * never run through the filter.
					 */
					'logoUrl'  => uich_brand_menu_icon(),
					'logoMono' => ( '' === uich_brand_logo_url() ),
					'brand'    => $brand,
					'mountId'  => UICH_CB_MOUNT,
					// Built with the builder: the site shows its document, not
					// what this editor saves (U-03).
					'managed'  => Uich_CB_Content::is_enabled( $post_id ),
					'i18n'     => array(
						/* translators: %s: product name, e.g. "UiChemy". */
						'edit'     => sprintf( __( 'Edit with %s', 'uichemy' ), $brand ),
						'opening'  => __( 'Opening…', 'uichemy' ),
						'saving'   => __( 'Saving…', 'uichemy' ),
						'panel'    => $brand,
						/* translators: %s: product name, e.g. "UiChemy". */
						'intro'    => sprintf( __( 'Build this page visually with the %s builder.', 'uichemy' ), $brand ),
						'failed'   => __( 'Could not open the builder. Please save the page and try again.', 'uichemy' ),
						/* translators: %s: product name, e.g. "UiChemy". */
						'managed'  => sprintf( __( 'This page is built with %s. Changes saved here are not shown on the site, and the next save in the builder replaces them. Edit the page in the builder instead.', 'uichemy' ), $brand ),
					),
				)
			);

			wp_set_script_translations( self::HANDLE_GUTENBERG_JS, 'uichemy' );
		}

		/**
		 * The stored document, in the shape the vendored editor expects.
		 *
		 * Storage is `{settings, content{html,css,js}}`; the editor speaks
		 * `{version, rev, sections}`. Uich_CB_Content::to_editor_cb_content()
		 * owns that translation — see it for why the page arrives as one
		 * section.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		private static function cb_content_payload( $post_id ) {
			return Uich_CB_Content::to_editor_cb_content( $post_id );
		}

		/**
		 * Attach a data payload to a script handle as real JSON.
		 *
		 * Deliberately NOT wp_localize_script(): that helper casts every
		 * TOP-LEVEL scalar to a string while leaving nested ones alone, so the
		 * same payload reaches JavaScript with `schema: "1"` and `isRTL: ""`
		 * next to a nested `post.id: 4881`. The builder document is structured
		 * data that round-trips through REST, and a booleans-as-empty-strings
		 * payload is a bug waiting to be written against. wp_json_encode keeps
		 * every type exactly as PHP had it.
		 *
		 * @param string $handle Registered script handle.
		 * @param string $object Global variable name to define.
		 * @param array  $data   Payload.
		 * @return void
		 */
		private static function print_payload( $handle, $object, $data ) {
			$json = wp_json_encode( $data );

			if ( false === $json ) {
				$json = '{}';
			}

			wp_add_inline_script(
				$handle,
				'var ' . $object . ' = ' . $json . ';',
				'before'
			);
		}

		/**
		 * The page's stored title, as the editor edits it.
		 *
		 * @param int $post_id Post being edited.
		 * @return string
		 */
		private static function raw_title( $post_id ) {
			$title = (string) get_post_field( 'post_title', $post_id, 'raw' );

			// WordPress's placeholder is not a name anyone gave the page.
			return Uich_CB_Content::is_placeholder_title( $title ) ? '' : $title;
		}

		/**
		 * The plural name of a post's type ("Pages", "Products"), or ''.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		private static function post_type_plural( $post_id ) {
			$type = get_post_type_object( (string) get_post_type( $post_id ) );

			return $type && isset( $type->labels->name ) ? (string) $type->labels->name : '';
		}

		/**
		 * Boot payload for the vendored editor (`window.UIPB_BOOT`).
		 *
		 * The editor tree under src/editor/ is vendored from the page-builder
		 * repo and is NOT hand-edited, so the SHAPE of this array is dictated by
		 * src/editor/index.jsx and src/editor/host/index.js — postId, canvas,
		 * template, document, publish — rather than chosen here. Renaming a key
		 * on this side is how the editor quietly boots with no document.
		 *
		 * @param int $post_id Post being edited.
		 * @return array
		 */
		private static function boot_payload( $post_id ) {
			$post_id = (int) $post_id;

			$payload = array(
				'postId'   => $post_id,
				// The top bar's page menu reads UIPB_BOOT.title and falls back to
				// "Untitled page" — which is what it showed for every page until
				// this was sent. The RAW title, not get_the_title(): the name is
				// renamed in place now, and what you edit has to be what is
				// stored — not its texturised, entity-encoded display form.
				'title'    => self::raw_title( $post_id ),
				// The front end of THIS post, with the canvas bridge switched on.
				// Built here rather than in JS because a permalink is the site's
				// business — `?p=<id>` is only correct with plain permalinks.
				'canvas'   => Uich_CB_Canvas::url( $post_id ),
				'template' => (string) get_page_template_slug( $post_id ),
				// What is being edited, for the logo menu's way back: "Back to
				// posts" to the Posts list, "Back to pages" to Pages, a custom
				// type's own list by its plural name (HomeMark.jsx). A Theme
				// Builder template is flagged separately (isThemeTemplate).
				'postType'      => (string) get_post_type( $post_id ),
				'postTypeLabel' => self::post_type_plural( $post_id ),
				// Page settings (template, hide title, featured image), so the
				// panel opens filled instead of waiting on a request.
				'pageSettings' => Uich_CB_Page_Settings::get( $post_id ),
				// Whether the media library is on the page for this user — the
				// Content tab and the background field offer it only then, and a
				// URL box otherwise. Same capability wp_enqueue_media() is gated
				// on in editor_assets().
				'canUpload' => current_user_can( 'upload_files' ),
				// The stored layout, in the editor's own document shape. An empty
				// document is `rev: 0` + no sections — NOT an empty array, which
				// the editor would read as "no document at all".
				'document' => self::cb_content_payload( $post_id ),
				// Same shape the status route answers with, from the same
				// method — the top bar must not read one thing at boot and
				// another after publishing.
				'publish'  => Uich_CB_Rest_Page::publish_state( $post_id ),
				// The top bar's mark: the white-labelled logo when one is set, else
				// UiChemy's own gradient mark — the same answer the paste buttons in
				// the other editors get.
				'brand'    => array(
					'name' => function_exists( 'uich_brand_name' ) ? uich_brand_name() : 'UiChemy',
					'logo' => function_exists( 'uich_brand_button_logo_url' ) ? esc_url_raw( uich_brand_button_logo_url() ) : '',
				),
			);

			/**
			 * Filter the editor boot payload.
			 *
			 * @since 5.2.1
			 *
			 * @param array $payload Boot payload.
			 * @param int   $post_id Post being edited.
			 */
			return (array) apply_filters( 'uich_cb/boot_payload', $payload, $post_id );
		}
	}
}
