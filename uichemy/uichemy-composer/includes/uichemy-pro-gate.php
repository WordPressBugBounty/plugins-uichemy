<?php
/**
 * Free / Pro gating helpers shared by both builds.
 *
 * This file is IDENTICAL in Free and Pro (only the main plugin file differs
 * between them), so every gate reads the same code. `uichemy_is_pro()` itself is
 * defined in each main plugin file — the guarded copy below is only a safety net
 * for the case where this include is reached first.
 *
 * @link       https://posimyth.com
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'uichemy_custom_code_allowed' ) ) {
	/**
	 * Whether Composer widgets may STORE and RENDER their raw_css / raw_js.
	 *
	 * This governs the code that already lives in a widget, not the editor UI that
	 * authors it — the CSS/JS panel stays a Pro feature either way. It must stay
	 * true for imported designs to work at all: an imported header, footer or page
	 * carries its entire design in raw_css, so gating the render path renders the
	 * imported site unstyled, and gating the save path erases that CSS the first
	 * time anyone presses Update in Elementor.
	 *
	 * Return uichemy_is_pro() from the filter to restrict stored code to Pro:
	 *
	 *     add_filter( 'uichemy/composer/allow_custom_code', 'uichemy_is_pro' );
	 *
	 * @return bool
	 */
	function uichemy_custom_code_allowed() {
		/**
		 * Filter whether stored Composer raw_css / raw_js may be kept and emitted.
		 *
		 * @param bool $allowed Default true (imported designs must render).
		 */
		return (bool) apply_filters( 'uichemy/composer/allow_custom_code', true );
	}
}

if ( ! function_exists( 'uichemy_composer_enabled' ) ) {
	/**
	 * Whether the Composer element should be registered for a given page builder.
	 *
	 * Three independent switches in Settings, one per builder, so a site that only
	 * uses (say) Gutenberg is not offered a Composer widget in the other two — and,
	 * more usefully, does not pay for their editor assets.
	 *
	 * Defaults to ON for every builder: this reads a stored option, and a site that
	 * upgrades into this feature has no value stored yet. Defaulting to off there
	 * would silently unregister a widget that is already sitting in live pages,
	 * which renders them blank.
	 *
	 * @param string $builder elementor | gutenberg | bricks.
	 * @return bool
	 */
	function uichemy_composer_enabled( $builder ) {
		$builder = sanitize_key( (string) $builder );
		if ( ! in_array( $builder, array( 'elementor', 'gutenberg', 'bricks' ), true ) ) {
			return false;
		}

		$opts = get_option( 'uichemy_settings', array() );
		$key  = 'enable_' . $builder;
		$on   = ! is_array( $opts ) || ! array_key_exists( $key, $opts ) ? true : ! empty( $opts[ $key ] );

		/**
		 * Filter whether the Composer registers for one builder.
		 *
		 * @param bool   $on      Whether it is enabled.
		 * @param string $builder Builder slug.
		 */
		return (bool) apply_filters( 'uichemy/builder/enabled', $on, $builder );
	}
}

if ( ! function_exists( 'uichemy_fast_load_enabled' ) ) {
	/**
	 * Whether fast load optimization is enabled in Settings.
	 *
	 * @return bool
	 */
	function uichemy_fast_load_enabled() {
		$opts = get_option( 'uichemy_settings', array() );
		$on   = ! is_array( $opts ) || ! array_key_exists( 'enable_fast_load', $opts ) || ! empty( $opts['enable_fast_load'] );
		return (bool) apply_filters( 'uichemy/perf/fast_load_enabled', $on );
	}
}

/**
 * Safety net only — both uichemy.php and uichemy-pro.php define this before any
 * include runs. Kept guarded so it can never redeclare.
 */
if ( ! function_exists( 'uichemy_is_pro' ) ) {
	function uichemy_is_pro() {
		return defined( 'UICHEMY_PRO' ) && UICHEMY_PRO;
	}
}

if ( ! function_exists( 'uichemy_upgrade_url' ) ) {
	/**
	 * The single upgrade/pricing URL behind every "Upgrade to Pro" CTA — admin
	 * dashboard, composer upsell modal and locked feature cards alike. Keeping it
	 * in one function means the link is changed once, and `$source` lets us tell
	 * which CTA converted without hard-coding query strings at each call site.
	 *
	 * @param string $source Optional CTA identifier (e.g. 'theme-builder', 'role-manager').
	 * @return string
	 */
	function uichemy_upgrade_url( $source = '' ) {
		$url = 'https://uichemy.com/pricing/';

		if ( '' !== (string) $source ) {
			$url = add_query_arg( 'utm_source', sanitize_key( $source ), $url );
		}

		/**
		 * Filter the UiChemy upgrade URL.
		 *
		 * @param string $url    The pricing URL (with any source arg already applied).
		 * @param string $source The CTA identifier passed by the caller.
		 */
		return (string) apply_filters( 'uichemy_upgrade_url', $url, (string) $source );
	}
}

if ( ! function_exists( 'uichemy_white_label_settings' ) ) {
	/**
	 * The effective White Label settings for this build.
	 *
	 * White Label is a Pro feature, so in Free this returns an EMPTY array — every
	 * consumer already treats a missing `enabled` flag as "white label off", so
	 * branding falls back to UiChemy's own name, icon and widget labels.
	 *
	 * This must be the ONLY way white-label settings are read. Hiding the settings
	 * screen is not enough on its own: a site that configured White Label under Pro
	 * and then downgraded would otherwise keep its custom branding — including
	 * "hide from other admins" — with no UI left to turn it off. The stored option
	 * is never deleted, so reactivating Pro restores the exact configuration.
	 *
	 * @return array
	 */
	function uichemy_white_label_settings() {
		if ( ! uichemy_is_pro() ) {
			return array();
		}

		$wl = get_option( 'uichemy_white_label', array() );

		return is_array( $wl ) ? $wl : array();
	}
}

if ( ! function_exists( 'uichemy_editor_pro_features' ) ) {
	/**
	 * Editor (composer) features that require Pro.
	 *
	 * `ai_chat` covers the whole Chat tab — the conversation UI, the Agent Bridge
	 * connect flow, model selection, history and the Draw→Ask-AI hand-off — plus
	 * every REST route behind them (`/agent/turn`, `/agent/models`, `/chat/*`).
	 * It is also a `UiChemy_Roles::FEATURES` slug, so two gates stack there: the
	 * Role Manager decides whether a role may use it, this list decides whether the
	 * build ships it, and it must clear both.
	 *
	 * `loop_pagination` covers the loop's Numbered page links and Load more button
	 * (`loop_pagination()` / `loop_load_more()` in Uich_Dynamic_Functions). It is NOT
	 * a role slug — entries here don't have to be. `UiChemy_Rest_Permissions::
	 * check_feature()` only ever receives role slugs, so a non-role entry simply
	 * never reaches it.
	 *
	 * @return string[]
	 */
	function uichemy_editor_pro_features() {
		/**
		 * Filter the editor features that require Pro.
		 *
		 * @param string[] $features Feature slugs.
		 */
		return (array) apply_filters( 'uichemy/editor/pro_features', array( 'ai_chat', 'loop_pagination' ) );
	}
}

if ( ! function_exists( 'uichemy_editor_feature_allowed' ) ) {
	/**
	 * Whether this build may run the given editor feature at all.
	 *
	 * Pro runs everything. In Free anything listed by uichemy_editor_pro_features()
	 * is refused — and refused at the REST layer too (see
	 * UiChemy_Rest_Permissions::check_feature()), not just hidden in the UI, so the
	 * lock cannot be bypassed by calling the endpoints directly.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	function uichemy_editor_feature_allowed( $feature ) {
		if ( uichemy_is_pro() ) {
			return true;
		}

		return ! in_array( (string) $feature, uichemy_editor_pro_features(), true );
	}
}

if ( ! function_exists( 'uichemy_dynamic_free_fields' ) ) {
	/**
	 * The ONLY dynamic-data fields Free may resolve, keyed by provider kind.
	 *
	 * Free ships four dynamic tags: Post Title, Post Image, Post Content and Post
	 * URL. Everything else — the rest of Post, ALL Term/Category fields, Site, User,
	 * Archive, Request, WooCommerce, ACF, JetEngine and the raw-Twig tags — is Pro.
	 *
	 * Notes on the entries that are not literally one of the four:
	 * - `url` / `permalink` are aliases of `link`, and `featured_image` of
	 *   `thumbnail` (see Uich_Post_Provider::uich_get) — the same tag by another name.
	 * - the whole `image` chain is allowed because it IS Post Image: an <img> needs
	 *   src/alt/width/height to render at all.
	 * - `term` is present but EMPTY, and `post.categories` is absent: Category Title
	 *   became Pro on 2026-07-31, so neither the category hop nor any term field
	 *   resolves in Free. The empty array is kept (rather than the key removed) so
	 *   the tiering reads explicitly instead of relying on the unknown-kind refusal.
	 * - `post.id` is PLUMBING, not a tag. The Loop tab's "Exclude current post"
	 *   option compiles to `post.id` (class-uichemy-composer-manager.php,
	 *   composer-construct-tab.jsx), and loops are a Free feature — blocking it
	 *   would break that option. The pickable "Post ID" tag stays Pro: it is hidden
	 *   from the picker and its Elementor tag class is not registered in Free.
	 *
	 * WooCommerce products need no entry: Uich_Product_Provider extends
	 * Uich_Post_Provider and reports kind `post`, so a product's title/content/
	 * image still resolve while every commerce field (price, sku, stock, …) falls
	 * outside this list and is refused.
	 *
	 * @return array<string,string[]> Provider kind => allowed field names.
	 */
	function uichemy_dynamic_free_fields() {
		$fields = array(
			'post'    => array( '__toString', 'title', 'content', 'link', 'url', 'permalink', 'thumbnail', 'featured_image', 'id', 'ID' ),
			'term'    => array(),
			'image'   => array( '__toString', 'src', 'url', 'srcset', 'img_sizes', 'sizes', 'alt', 'width', 'height', 'caption', 'id', 'ID' ),
			'user'    => array(),
			'site'    => array(),
			'request' => array(),
		);

		/**
		 * Filter the dynamic fields Free may resolve.
		 *
		 * @param array<string,string[]> $fields Provider kind => allowed field names.
		 */
		return (array) apply_filters( 'uichemy/dynamic/free_fields', $fields );
	}
}

if ( ! function_exists( 'uichemy_free_loop_sources' ) ) {
	/**
	 * Loop sources Free may query. Free loops over POSTS only.
	 *
	 * Pro adds Products (WooCommerce), Terms / Categories, Users and External API
	 * (JSON) — the `get_*` Twig functions behind the Loop panel's "Show" select.
	 *
	 * @return string[] Twig function names.
	 */
	function uichemy_free_loop_sources() {
		/**
		 * Filter the loop sources available in Free.
		 *
		 * @param string[] $sources Twig function names (e.g. get_posts).
		 */
		return (array) apply_filters( 'uichemy/dynamic/free_loop_sources', array( 'get_posts' ) );
	}
}

if ( ! function_exists( 'uichemy_loop_source_allowed' ) ) {
	/**
	 * Whether this build may run the given loop source.
	 *
	 * In Free a Pro source returns an EMPTY collection, so the loop falls through to
	 * its `{% else %}` empty state instead of silently rendering Pro data — the same
	 * rule the field allowlist follows, and it applies to imported templates and to
	 * sites that downgraded from Pro.
	 *
	 * @param string $source Twig function name.
	 * @return bool
	 */
	function uichemy_loop_source_allowed( $source ) {
		if ( uichemy_is_pro() ) {
			return true;
		}

		return in_array( (string) $source, uichemy_free_loop_sources(), true );
	}
}

if ( ! function_exists( 'uichemy_dynamic_field_allowed' ) ) {
	/**
	 * Whether this build may resolve `<kind>.<field>`.
	 *
	 * Enforced at the Twig sandbox boundary (Uich_Provider::uich_read), so it covers
	 * every chained hop — `post.author.name`, `product.price`,
	 * `post.thumbnail.src('large')` — no matter how the template was authored.
	 * Hiding fields in the picker is the courtesy; this is the rule.
	 *
	 * @param string $kind  Provider kind: post|term|image|user|site|request.
	 * @param string $field Field name being read.
	 * @return bool
	 */
	function uichemy_dynamic_field_allowed( $kind, $field ) {
		if ( uichemy_is_pro() ) {
			return true;
		}

		$free = uichemy_dynamic_free_fields();
		$kind = (string) $kind;

		// An unknown provider kind is refused rather than allowed: a new provider
		// must be tiered deliberately, not leak into Free by being forgotten.
		if ( ! isset( $free[ $kind ] ) || ! is_array( $free[ $kind ] ) ) {
			return false;
		}

		return in_array( (string) $field, $free[ $kind ], true );
	}
}

if ( ! function_exists( 'uichemy_pro_feature_map' ) ) {
	/**
	 * Which dashboard surfaces are Pro-only. Consumed by the admin menu (to build
	 * the localized payload the React app gates on) so PHP stays the single source
	 * of truth — the JS never hard-codes the list.
	 *
	 * Only surfaces that exist in BOTH builds belong here — the map answers "is
	 * this locked behind Pro", and a screen Free does not ship at all has no
	 * meaningful answer. The License screen is one of those: it is registered only
	 * in Pro and its nav item is gated on isPro(), not on this map. It used to
	 * carry a `license => false` entry, which read as "License is not a Pro
	 * feature" in Free — the opposite of the truth, for a key nothing read.
	 *
	 * @return array<string,bool> Keyed by feature slug; true = requires Pro.
	 */
	function uichemy_pro_feature_map() {
		$is_pro = uichemy_is_pro();

		return array(
			'role_manager'  => ! $is_pro,
			'white_label'   => ! $is_pro,
			'module_studio' => ! $is_pro,
		);
	}
}

if ( ! function_exists( 'uichemy_field_writes_allowed' ) ) {
	/**
	 * Whether this build may read and write custom-field VALUES, and create
	 * post types, taxonomies and fields through ACF / JetEngine.
	 *
	 * Pro, on the same reasoning as every other custom-field gate: ACF,
	 * JetEngine and WooCommerce field data are Pro dynamic tags
	 * (uichemy_dynamic_free_fields() lets Free resolve five post fields and the
	 * image chain, and nothing else), so a Free user who could WRITE an ACF
	 * value still could not RENDER it. The write would be real and useless.
	 *
	 * Field DEFINITIONS are deliberately NOT behind this. Knowing the content
	 * model is what stops a guessed binding - an unknown field name renders
	 * empty rather than failing - so `custom-fields/list` and `cpt/describe`
	 * answer in every build, and in Free the most valuable thing they report is
	 * that the value layer needs Pro.
	 *
	 * One function, so the Free/Pro line for this whole subsystem moves in one
	 * place if that decision changes.
	 *
	 * DELIBERATELY NOT FILTERABLE, and do not add a filter here. This gate is a
	 * thin read of uichemy_is_pro(), which is already the one supported seam:
	 * merged and bundled builds ship no separate Pro plugin and decide Pro by
	 * licence tier through `uich_composer_is_pro` (see
	 * includes/composer/class-uich-composer-loader.php), so such a build reaches
	 * this subsystem the same way it reaches every other Pro feature. A filter
	 * of its own would add nothing a legitimate build needs, and would be a
	 * second door into the value and content-model layers that bypasses the
	 * licence tier entirely.
	 *
	 * @since 5.1.1
	 *
	 * @return bool
	 */
	function uichemy_field_writes_allowed() {
		return uichemy_is_pro();
	}
}

if ( ! function_exists( 'uichemy_shell_quote' ) ) {
	/**
	 * Quote one argument for a POSIX shell, as escapeshellarg() does on Linux and
	 * macOS, for the example `curl` commands handed to AI clients. Nothing here is
	 * executed; it only has to read right. escapeshellarg() itself is in
	 * disable_functions on many shared and sandbox hosts (TasteWP among them),
	 * and calling it there was a fatal that took down media and font uploads and
	 * Module Studio's list with it.
	 *
	 * @param string $arg Argument.
	 * @return string The argument in single quotes.
	 */
	function uichemy_shell_quote( $arg ) {
		return "'" . str_replace( "'", "'\\''", str_replace( "\0", '', (string) $arg ) ) . "'";
	}
}

if ( ! function_exists( 'uichemy_add_chunk_translations' ) ) {
	/**
	 * Translate the strings in a script's code-split chunks too.
	 *
	 * wp_set_script_translations() loads ONE JSON file per handle, named after
	 * the handle's own file (md5 of e.g. "new-dashboard/build/index.js"). The
	 * build splits each bundle into numbered chunks (523.6b06d4be.js, 107.js)
	 * that load on demand and are never registered handles, and language packs
	 * ship a JSON per chunk that WordPress never opens. Every string living in a
	 * chunk stayed in English (wordpress.org support: "Strings from these .js
	 * files are not displayed translated").
	 *
	 * So: find the chunk files beside the handle's file, load each chunk's JSON
	 * from the same places WordPress looks (the given $path, the domain's
	 * registered path, WP_LANG_DIR/plugins), and hand their strings to the
	 * page before the bundle runs. wp.i18n.setLocaleData() merges them into the
	 * same domain. Call it right after wp_set_script_translations().
	 *
	 * @param string $handle Registered script handle.
	 * @param string $domain Text domain.
	 * @param string $path   Optional folder holding the JSON files, as passed to wp_set_script_translations().
	 * @return int Number of chunk files whose translations were added.
	 */
	function uichemy_add_chunk_translations( $handle, $domain = 'uichemy', $path = '' ) {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( 'en_US' === $locale ) {
			return 0;
		}
		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered[ $handle ] ) ) {
			return 0;
		}

		// The handle's file, relative to its plugin folder, as WordPress names
		// translation files: "new-dashboard/build/index.js".
		$src_path     = (string) wp_parse_url( (string) $scripts->registered[ $handle ]->src, PHP_URL_PATH );
		$plugins_path = (string) wp_parse_url( plugins_url(), PHP_URL_PATH );
		if ( '' === $plugins_path || 0 !== strpos( $src_path, trailingslashit( $plugins_path ) ) ) {
			return 0;
		}
		$parts = explode( '/', trim( substr( $src_path, strlen( $plugins_path ) ), '/' ) );
		if ( count( $parts ) < 2 ) {
			return 0;
		}
		$plugin_dir = array_shift( $parts );
		array_pop( $parts ); // the handle's own file; WordPress already loads its JSON.
		$rel_dir = implode( '/', $parts );
		$abs_dir = trailingslashit( WP_PLUGIN_DIR ) . $plugin_dir . ( '' !== $rel_dir ? '/' . $rel_dir : '' );

		$chunks = glob( $abs_dir . '/*.js' );
		if ( empty( $chunks ) ) {
			return 0;
		}

		$folders = array();
		if ( $path ) {
			$folders[] = untrailingslashit( $path );
		}
		global $wp_textdomain_registry;
		if ( isset( $wp_textdomain_registry ) && method_exists( $wp_textdomain_registry, 'get' ) ) {
			$registered = $wp_textdomain_registry->get( $domain, $locale );
			if ( $registered ) {
				$folders[] = untrailingslashit( $registered );
			}
		}
		$folders[] = WP_LANG_DIR . '/plugins';
		$folders   = array_unique( $folders );

		$messages = array();
		$found    = 0;
		foreach ( $chunks as $chunk ) {
			$name = basename( $chunk );
			// Chunks are numbered ("523.6b06d4be.js", "107.js"); entry files are named.
			if ( ! preg_match( '/^\d+(\.[0-9a-f]+)?\.js$/', $name ) ) {
				continue;
			}
			$md5 = md5( ( '' !== $rel_dir ? $rel_dir . '/' : '' ) . $name );
			foreach ( $folders as $folder ) {
				$file = $folder . '/' . $domain . '-' . $locale . '-' . $md5 . '.json';
				if ( ! is_readable( $file ) ) {
					continue;
				}
				$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local language file.
				$data = isset( $json['locale_data'] ) && is_array( $json['locale_data'] ) ? reset( $json['locale_data'] ) : null;
				if ( is_array( $data ) ) {
					$messages = array_merge( $messages, $data );
					++$found;
				}
				break;
			}
		}
		if ( ! $messages ) {
			return 0;
		}
		if ( ! isset( $messages[''] ) ) {
			$messages[''] = array( 'domain' => $domain, 'lang' => $locale );
		}

		wp_add_inline_script(
			$handle,
			sprintf(
				'( function( domain, data ) { var i18n = window.wp && window.wp.i18n; if ( i18n && i18n.setLocaleData ) { i18n.setLocaleData( data, domain ); } } )( %s, %s );',
				wp_json_encode( $domain ),
				wp_json_encode( $messages )
			),
			'before'
		);
		return $found;
	}
}

