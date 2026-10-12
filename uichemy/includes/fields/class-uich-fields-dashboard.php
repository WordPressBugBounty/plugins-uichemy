<?php
/**
 * Hands the Content Fields screen the data it needs from PHP.
 *
 * The React app is part of the dashboard bundle now, so it needs no script of its own — only the
 * config object it reads. That object is localized onto the dashboard's own handle
 * (`uich-nd-script`), at priority 20, after Uich_ND_Enqueue has registered it at 10; localizing a
 * handle that is not registered yet is a silent no-op, and the screen would come up with every
 * list empty and no error to explain it.
 *
 * The lists here (post types, taxonomies, roles, image sizes) are read at request time rather than
 * baked into the bundle, because a post type registered by another plugin has to appear in the
 * location rules without a rebuild.
 *
 * @package Uichemy
 * @subpackage Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Fields_Dashboard' ) ) {

	final class Uich_Fields_Dashboard {

		/**
		 * The dashboard's script handle — see Uich_ND_Enqueue::HANDLE_JS.
		 */
		const HANDLE = 'uich-nd-script';

		/**
		 * Hook up.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'localize' ), 20 );
		}

		/**
		 * Localize the config, if the dashboard bundle is on this screen.
		 *
		 * @return void
		 */
		public static function localize() {
			if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
				return;
			}
			wp_localize_script( self::HANDLE, 'uichFieldsConfig', self::config() );
		}

		/**
		 * The config object the app reads.
		 *
		 * @return array
		 */
		public static function config() {
			return array(
				'adminUrl'   => admin_url(),
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				// Own namespace, never the shared 'content-types/v1' — two Fields modules on one site
				// must never share a REST route or a definitions post type. See class-uich-fields-loader.php.
				'restUrl'    => rest_url( UICH_FIELDS_REST_NS ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				// The module's own assets (select2, the group-fields stylesheet) live beside its PHP;
				// the React bundle is the dashboard's and is not addressed from here.
				'assets'     => defined( 'UICH_FIELDS_URL' ) ? UICH_FIELDS_URL : '',
				'pluginVer'  => defined( 'UICH_FIELDS_VER' ) ? UICH_FIELDS_VER : UICH_VERSION,

				// Used by the URL Slug fields to show a live permalink preview, so the admin can see
				// the URL a post type / taxonomy will actually produce before saving.
				'homeUrl'          => untrailingslashit( home_url() ),
				'permalinkFront'   => isset( $GLOBALS['wp_rewrite'] ) ? (string) $GLOBALS['wp_rewrite']->front : '/',
				'prettyPermalinks' => '' !== (string) get_option( 'permalink_structure' ),

				// The tier is read from PHP on every request, never baked into the bundle: a licence
				// activated after the build must lock/unlock the picker without a rebuild.
				'isPro'       => function_exists( 'uich_fields_is_pro' ) ? uich_fields_is_pro() : false,
				// Plus withdrawn Pro types the picker no longer offers (gallery), so an existing one is badged.
				'proTypes'    => function_exists( 'uich_fields_pro_types' ) ? array_values( array_unique( array_merge( uich_fields_pro_types(), array( 'gallery' ) ) ) ) : array(),
				'proParams'   => function_exists( 'uich_fields_pro_location_params' ) ? uich_fields_pro_location_params() : array(),
				'proFeatures' => array(
					'conditional_logic' => function_exists( 'uich_fields_conditional_logic_allowed' ) ? ! uich_fields_conditional_logic_allowed() : false,
				),
				'upgradeUrl'  => function_exists( 'uich_fields_upgrade_url' ) ? uich_fields_upgrade_url( 'content-fields-picker' ) : '',

				'postTypesList'  => self::post_types(),
				'taxonomiesList' => self::taxonomies(),
				'userRolesList'  => self::roles(),
				'imageSizes'     => self::image_sizes(),
			);
		}

		/**
		 * Selectable post types.
		 *
		 * @return array
		 */
		private static function post_types() {
			$out = array();
			foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $key => $obj ) {
				if ( in_array( $key, array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) ) {
					continue;
				}
				if ( self::is_internal_type( $key, $obj ) ) {
					continue;
				}
				$out[] = array(
					'value' => $key,
					'label' => ! empty( $obj->labels->name ) ? $obj->labels->name : $key,
				);
			}
			/**
			 * Filter the post types the pickers offer (location rules, "Used by").
			 *
			 * @param array $out Rows of { value: key, label: name }.
			 */
			$out = apply_filters( 'uich/fields_picker_post_types', $out );
			return self::disambiguate( is_array( $out ) ? $out : array() );
		}

		/**
		 * Whether a type is plumbing a builder or plugin registers for itself (Elementor templates, ACF's own
		 * screens), not something an editor attaches fields or terms to.
		 *
		 * @param string                      $key Post type or taxonomy key.
		 * @param WP_Post_Type|WP_Taxonomy $obj The registered object.
		 * @return bool
		 */
		private static function is_internal_type( $key, $obj ) {
			if ( class_exists( 'Uich_Fields_CPT' ) && Uich_Fields_CPT::POST_TYPE === $key ) {
				return true;
			}
			if ( ! empty( $obj->public ) ) {
				return false;
			}
			// A private type that is not even listed in the admin menu is internal; so are the builders' own.
			if ( isset( $obj->show_in_menu ) && ! $obj->show_in_menu ) {
				return true;
			}
			return 1 === preg_match( '/^(elementor|e-|acf-)/', (string) $key );
		}

		/**
		 * Append the key to any label two rows share, so "Categories" twice becomes "Categories (category)" and
		 * "Categories (elementor_library_category)".
		 *
		 * @param array $rows Rows of { value, label }.
		 * @return array
		 */
		private static function disambiguate( $rows ) {
			$count = array();
			foreach ( $rows as $row ) {
				$label           = isset( $row['label'] ) ? (string) $row['label'] : '';
				$count[ $label ] = isset( $count[ $label ] ) ? $count[ $label ] + 1 : 1;
			}
			foreach ( $rows as $i => $row ) {
				$label = isset( $row['label'] ) ? (string) $row['label'] : '';
				if ( $count[ $label ] > 1 && isset( $row['value'] ) ) {
					$rows[ $i ]['label'] = $label . ' (' . $row['value'] . ')';
				}
			}
			return array_values( $rows );
		}

		/**
		 * Selectable taxonomies.
		 *
		 * @return array
		 */
		private static function taxonomies() {
			$out = array();
			foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $key => $obj ) {
				if ( self::is_internal_type( $key, $obj ) ) {
					continue;
				}
				$out[] = array(
					'value' => $key,
					'label' => ! empty( $obj->labels->name ) ? $obj->labels->name : $key,
				);
			}
			/**
			 * Filter the taxonomies the pickers offer.
			 *
			 * @param array $out Rows of { value: key, label: name }.
			 */
			$out = apply_filters( 'uich/fields_picker_taxonomies', $out );
			return self::disambiguate( is_array( $out ) ? $out : array() );
		}

		/**
		 * Roles, for the permission rules.
		 *
		 * @return array
		 */
		private static function roles() {
			$names = wp_roles()->get_names();
			$out   = array();
			foreach ( $names as $slug => $label ) {
				$out[] = array(
					'value' => (string) $slug,
					'label' => (string) $label,
				);
			}
			return $out;
		}

		/**
		 * Registered image sizes, labelled with their dimensions.
		 *
		 * @return array
		 */
		private static function image_sizes() {
			$out = array();
			// wp_get_registered_image_subsizes() carries each size's configured dimensions; the old
			// image_get_intermediate_size( 0, … ) looked up attachment 0 and so never returned any.
			$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array_fill_keys( get_intermediate_image_sizes(), array() );
			foreach ( $sizes as $size => $data ) {
				$width  = isset( $data['width'] ) ? (int) $data['width'] : 0;
				$height = isset( $data['height'] ) ? (int) $data['height'] : 0;
				$label  = $size;
				if ( $width && $height ) {
					$label .= sprintf( ' (%d × %d)', $width, $height );
				}
				$out[] = array(
					'value' => $size,
					'label' => $label,
				);
			}
			return $out;
		}
	}
}
