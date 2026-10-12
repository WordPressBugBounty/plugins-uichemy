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
 * other toggle. A key absent from storage means ON: every feature ships
 * enabled, and a site that has never opened Settings must not lose one.
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
			return null === $value || ! empty( $value );
		}

		$settings = get_option( 'uichemy_settings', array() );
		$key      = uich_feature_setting_key( $slug );
		return ! is_array( $settings ) || ! array_key_exists( $key, $settings ) || ! empty( $settings[ $key ] );
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
