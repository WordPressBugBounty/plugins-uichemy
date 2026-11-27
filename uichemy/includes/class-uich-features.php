<?php
/**
 * One place that answers "is this UiChemy feature switched on here?".
 *
 * Some of what UiChemy ships overlaps with a plugin a site may already run and
 * prefer - Custom Fields with ACF or JetEngine, for example. Each such feature
 * gets a switch under Settings > Features, and turning one off must behave like
 * deactivating a plugin: the module does not boot, its dashboard tab is gone,
 * its abilities are not registered and nothing on the MCP surface offers it.
 * Stored data is left alone, so switching it back on picks up where it was.
 *
 * Adding a feature is one row in uich_features(). The Settings defaults, the
 * sanitizer, the dashboard boot data and the Features page are all driven from
 * that list, so nothing else needs to learn its name.
 *
 * The switches live in the shared `uichemy_settings` option as
 * `feature_{slug}`, so they save through the same Settings pipeline as every
 * other toggle. A key absent from storage means ON, so a site that used a
 * feature before switches existed never loses it. A feature marked
 * `default_off` (Custom Fields, Module Studio, Sync with Astro) starts OFF on a new site
 * instead: activation writes its switch as off unless the site already has
 * data for it (uich_feature_seed_defaults()). Its dashboard tab stays either
 * way and, while off, offers to switch it on.
 *
 * A feature that already had an on/off switch of its own declares `store`
 * instead, and that setting stays the single source of truth: SEO's switch
 * is `module_enabled` in SEO's own settings, so the toggle on the SEO screen
 * and the one under Settings > Features are the same value, and neither can
 * disagree with the other.
 *
 * Loaded from uichemy.php before any module loader, because a loader asks
 * uich_feature_enabled() before it boots anything.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uich_features' ) ) {
	/**
	 * The features a site can switch off, keyed by slug.
	 *
	 * Labels are not translated here: this runs at plugin-file load, before the
	 * text domain is loaded. The Features page translates its own copy.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	function uich_features() {
		return array(
			'custom_fields' => array(
				'label'       => 'Custom Fields',
				'description' => 'Custom post types, taxonomies and field groups built with this plugin. Turn off if you model content with ACF, JetEngine or another plugin.',
				// New sites start with it off; the dashboard tab stays and offers to switch it on.
				'default_off' => true,
			),
			'seo'           => array(
				'label'       => 'SEO',
				'description' => 'Titles, descriptions, sharing previews, schema, sitemaps, redirects and the AI files. Turn off if you use Yoast, Rank Math or another SEO plugin.',
				// SEO's own switch (SEO > Advanced) is the stored value. Settings are
				// per language; the default language's copy is the one SEO reads.
				'store'       => array(
					'option' => 'uichemy_seo_site',
					'path'   => array( 'langs', 'default', 'module_enabled' ),
				),
			),
			'module_studio' => array(
				'label'       => 'Module Studio',
				'description' => 'Modules: PHP, CSS, JS and HTML placed site-wide or by condition, or small features of their own. Turn off if you manage custom code with another plugin.',
				'default_off' => true,
			),
			'sync_astro'    => array(
				'label'          => 'Sync with Astro',
				'description'    => 'A headless Astro frontend for this site (UiChemy Pro). While off, none of its code loads.',
				'default_off'    => true,
				// New in 5.6.2, so an updated site starts with it off too: with no stored
				// switch it is on only for a site already using it (see uich_feature_stored()).
				'off_until_used' => true,
			),
			'agent_ready'   => array(
				'label'       => 'Agent Ready',
				'description' => 'Makes the site usable by AI agents: agent pages, llms.txt, discovery files and a site MCP that visitors\' agents can connect to. Turn off if you do not want AI agents to read or use the site this way.',
				// Agent Ready's own option holds the switch; its first boot stores it
				// off on every site (opt-in).
				'default'     => false, // Opt-in: off until the owner turns it on.
				'store'       => array(
					'option' => 'uichemy_agent_ready',
					'path'   => array( 'enabled' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'uich_feature_setting_key' ) ) {
	/**
	 * The `uichemy_settings` key that stores one feature's switch.
	 *
	 * @param string $slug Feature slug.
	 * @return string
	 */
	function uich_feature_setting_key( $slug ) {
		return 'feature_' . sanitize_key( (string) $slug );
	}
}

if ( ! function_exists( 'uich_feature_enabled' ) ) {
	/**
	 * Whether a feature is switched on.
	 *
	 * Not cached: the option is autoloaded, so this is an array lookup, and a
	 * static cache would answer the request that saves the switch with the old
	 * value.
	 *
	 * @param string $slug Feature slug from uich_features().
	 * @return bool True for an unknown slug, so a typo never silently disables something.
	 */
	function uich_feature_enabled( $slug ) {
		if ( ! isset( uich_features()[ $slug ] ) ) {
			return true;
		}

		$enabled = uich_feature_stored( $slug );

		/**
		 * Force a feature on or off regardless of the stored switch.
		 *
		 * @param bool   $enabled Whether the feature is on.
		 * @param string $slug    Feature slug.
		 */
		return (bool) apply_filters( 'uichemy_feature_enabled', $enabled, $slug );
	}
}

if ( ! function_exists( 'uich_feature_stored' ) ) {
	/**
	 * The stored switch, before the `uichemy_feature_enabled` filter.
	 *
	 * What Settings shows and saves: a filter forcing a feature off on one host
	 * must not be written back as the user's own choice on the next save.
	 *
	 * @param string $slug Feature slug.
	 * @return bool
	 */
	function uich_feature_stored( $slug ) {
		$store = uich_feature_store( $slug );
		if ( $store ) {
			$value = uich_feature_store_read( $store );
			if ( null === $value ) {
				$features = uich_features();
				return ! isset( $features[ $slug ]['default'] ) || (bool) $features[ $slug ]['default'];
			}
			return ! empty( $value );
		}

		$settings = get_option( 'uichemy_settings', array() );
		$key      = uich_feature_setting_key( $slug );
		if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
			return ! empty( $settings[ $key ] );
		}
		// Never chosen: on, except an `off_until_used` feature, which is on only where it is in use.
		$features = uich_features();
		return empty( $features[ $slug ]['off_until_used'] ) || uich_feature_in_use( $slug );
	}
}

if ( ! function_exists( 'uich_feature_setting_defaults' ) ) {
	/**
	 * `uichemy_settings` defaults for every feature switch: all on.
	 *
	 * @return array<string,int>
	 */
	function uich_feature_setting_defaults() {
		$defaults = array();
		foreach ( array_keys( uich_features() ) as $slug ) {
			$defaults[ uich_feature_setting_key( $slug ) ] = 1;
		}
		return $defaults;
	}
}

if ( ! function_exists( 'uich_feature_states' ) ) {
	/**
	 * slug => bool for every feature, for the dashboard boot data.
	 *
	 * @return array<string,bool>
	 */
	function uich_feature_states() {
		$states = array();
		foreach ( array_keys( uich_features() ) as $slug ) {
			$states[ $slug ] = uich_feature_enabled( $slug );
		}
		return $states;
	}
}

if ( ! function_exists( 'uich_feature_store' ) ) {
	/**
	 * Where a feature with a switch of its own keeps it, or null.
	 *
	 * @param string $slug Feature slug.
	 * @return array{option:string,path:array<int,string>}|null
	 */
	function uich_feature_store( $slug ) {
		$features = uich_features();
		return isset( $features[ $slug ]['store'] ) ? $features[ $slug ]['store'] : null;
	}
}

if ( ! function_exists( 'uich_feature_store_read' ) ) {
	/**
	 * Read a store's value; null when it was never set.
	 *
	 * @param array $store { option, path }.
	 * @return mixed|null
	 */
	function uich_feature_store_read( $store ) {
		$node = get_option( $store['option'], array() );
		foreach ( $store['path'] as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return null;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}
}

if ( ! function_exists( 'uich_feature_set' ) ) {
	/**
	 * Switch a feature on or off, wherever it is stored.
	 *
	 * Writes only the switch: every other key in the option is preserved, and an
	 * unknown slug is ignored rather than creating a stray setting.
	 *
	 * @param string $slug    Feature slug.
	 * @param bool   $enabled On or off.
	 * @return void
	 */
	function uich_feature_set( $slug, $enabled ) {
		if ( ! isset( uich_features()[ $slug ] ) ) {
			return;
		}

		$store = uich_feature_store( $slug );
		if ( ! $store ) {
			$settings = get_option( 'uichemy_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();

			$settings[ uich_feature_setting_key( $slug ) ] = $enabled ? 1 : 0;
			update_option( 'uichemy_settings', $settings );
			return;
		}

		$option = get_option( $store['option'], array() );
		$option = is_array( $option ) ? $option : array();
		$node   = &$option;
		foreach ( $store['path'] as $i => $segment ) {
			if ( count( $store['path'] ) - 1 === $i ) {
				$node[ $segment ] = (bool) $enabled;
				break;
			}
			if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
				$node[ $segment ] = array();
			}
			$node = &$node[ $segment ];
		}
		unset( $node );

		update_option( $store['option'], $option );
	}
}

if ( ! function_exists( 'uich_feature_in_use' ) ) {
	/**
	 * Whether the site already has data for a feature, so it must stay on.
	 *
	 * Read straight from the database: on activation the feature's own module
	 * may not be loaded.
	 *
	 * @param string $slug Feature slug.
	 * @return bool
	 */
	function uich_feature_in_use( $slug ) {
		global $wpdb;
		switch ( $slug ) {
			case 'custom_fields':
				// Any definition (post type, taxonomy, field group, options page), even trashed.
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'uich_content_types' ) ) ) {
					return true;
				}
				// Definitions now live as JSON files, so a site can have them and no rows at all. Same path as
				// Uich_Fields_Model_Paths::site_dir(), which is not loaded while the feature is off.
				$dir = untrailingslashit( wp_normalize_path( (string) apply_filters( 'uich_fields_model_dir', WP_CONTENT_DIR . '/uichemy-fields' ) ) )
					. '/sites/' . max( 1, (int) get_current_blog_id() );
				foreach ( array( 'post-types', 'taxonomies', 'field-groups' ) as $sub ) {
					if ( glob( $dir . '/' . $sub . '/*.json' ) ) {
						return true;
					}
				}
				return false;
			case 'module_studio':
				// Created the first time Module Studio stores a module.
				return '' !== (string) get_option( 'uichemy_module_studio_code_dir', '' );
			case 'sync_astro':
				// Pro's opt-in record, its older signals (wizard done, frontend URL saved) or a deploy under way.
				foreach ( array( 'uich_sync_astro_enabled', 'hatch_setup_wizard_completed', 'hatch_frontend_url', 'uich_hatch_deploy_pending' ) as $option ) {
					if ( get_option( $option ) ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}
}

if ( ! function_exists( 'uich_feature_seed_defaults' ) ) {
	/**
	 * On activation, switch each `default_off` feature off on a site that never
	 * chose for it and has no data for it.
	 *
	 * A switch already stored (on or off) is the site's own choice and stays.
	 * A site with data for the feature keeps it on: that site was using it.
	 *
	 * @return void
	 */
	function uich_feature_seed_defaults() {
		$settings = get_option( 'uichemy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		foreach ( uich_features() as $slug => $feature ) {
			if ( empty( $feature['default_off'] ) || uich_feature_store( $slug ) ) {
				continue;
			}
			if ( array_key_exists( uich_feature_setting_key( $slug ), $settings ) || uich_feature_in_use( $slug ) ) {
				continue;
			}
			uich_feature_set( $slug, false );
		}
	}
}

if ( ! function_exists( 'uich_feature_auto_enable' ) ) {
	/**
	 * Switch a `default_off` feature on because an agent is writing to it.
	 *
	 * Custom Fields and Module Studio start off on a new site only so they stay
	 * out of the way until they are used. An agent that creates a post type or
	 * writes a module IS using it, so its first write switches the feature on
	 * rather than refusing. Reads never call this. Features without
	 * `default_off` (SEO) are the user's call and are never switched on here.
	 *
	 * The switch is the same one Settings > Features writes, so the user can
	 * turn it off again there.
	 *
	 * @param string $slug Feature slug.
	 * @return bool True when this call switched it on; false when it was already
	 *              on or may not be switched on automatically.
	 */
	function uich_feature_auto_enable( $slug ) {
		$features = uich_features();
		if ( empty( $features[ $slug ]['default_off'] ) || uich_feature_stored( $slug ) ) {
			return false;
		}

		uich_feature_set( $slug, true );
		if ( 'custom_fields' === $slug ) {
			// Its post types and taxonomies need the rewrite rules rebuilt once they register.
			update_option( 'uich_ct_flush_rewrite', 1, true );
		}

		/**
		 * A `default_off` feature was switched on by an agent's first write to it.
		 *
		 * @param string $slug Feature slug.
		 */
		do_action( 'uichemy_feature_auto_enabled', $slug );

		return uich_feature_stored( $slug );
	}
}

if ( ! function_exists( 'uich_feature_auto_enabled_note' ) ) {
	/**
	 * What an ability tells the agent after it switched a feature on.
	 *
	 * @param string $slug Feature slug.
	 * @return string
	 */
	function uich_feature_auto_enabled_note( $slug ) {
		$features = uich_features();
		$label    = isset( $features[ $slug ]['label'] ) ? $features[ $slug ]['label'] : $slug;
		return sprintf( '%s was switched off on this site, so this write switched it on (UiChemy > Settings > Features). Tell the user it is on now and that they can switch it off again there.', $label );
	}
}
