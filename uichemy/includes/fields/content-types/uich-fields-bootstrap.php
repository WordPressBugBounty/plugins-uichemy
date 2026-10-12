<?php
/**
 * Bootstrap for Custom Content Types: CPT registration, conditional CPT/Taxonomy loader, Ajax.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The REST namespace, guarded. The loader normally defines this; it is repeated here because the
 * classes below read it in a class-constant expression, which fatals rather than warns when the
 * constant is missing, and this bootstrap is reachable without the loader (tests, direct includes).
 */
if ( ! defined( 'UICH_FIELDS_REST_NS' ) ) {
	define( 'UICH_FIELDS_REST_NS', 'uichemy-fields/v1' );
}

/*
 * One-shot module flags live in ONE autoloaded option, so a one-time check costs no query per admin
 * request. A flag still set only under its legacy option is read from there and copied forward.
 */
if ( ! function_exists( 'uich_fields_flag' ) ) {
	/**
	 * Read a module flag.
	 *
	 * @param string $name   Flag name.
	 * @param string $legacy Legacy standalone option holding it, if any.
	 * @return mixed 0 when unset.
	 */
	function uich_fields_flag( $name, $legacy = '' ) {
		$all = get_option( 'uichemy_fields_versions', array() );
		$all = is_array( $all ) ? $all : array();
		if ( isset( $all[ $name ] ) ) {
			return $all[ $name ];
		}
		if ( '' !== $legacy ) {
			$old = get_option( $legacy, 0 );
			if ( $old ) {
				uich_fields_flag_set( $name, $old );
				return $old;
			}
		}
		return 0;
	}

	/**
	 * Write a module flag.
	 *
	 * @param string $name  Flag name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	function uich_fields_flag_set( $name, $value ) {
		$all = get_option( 'uichemy_fields_versions', array() );
		$all = is_array( $all ) ? $all : array();
		if ( isset( $all[ $name ] ) && $all[ $name ] === $value ) {
			return;
		}
		$all[ $name ] = $value;
		update_option( 'uichemy_fields_versions', $all, true );
	}

	/**
	 * Remove a module flag and its legacy option.
	 *
	 * @param string $name   Flag name.
	 * @param string $legacy Legacy standalone option, if any.
	 * @return void
	 */
	function uich_fields_flag_delete( $name, $legacy = '' ) {
		$all = get_option( 'uichemy_fields_versions', array() );
		if ( is_array( $all ) && isset( $all[ $name ] ) ) {
			unset( $all[ $name ] );
			update_option( 'uichemy_fields_versions', $all, true );
		}
		if ( '' !== $legacy ) {
			delete_option( $legacy );
		}
	}
}

$content_types_dir = __DIR__;

require_once $content_types_dir . '/class-uich-fields-fields.php';
require_once $content_types_dir . '/class-uich-fields-field-groups-fields.php';
require_once $content_types_dir . '/class-uich-fields-cpt.php';
require_once $content_types_dir . '/class-uich-fields-definitions-repository.php';
require_once $content_types_dir . '/class-uich-fields-register.php';
require_once $content_types_dir . '/class-uich-fields-admin-logic.php';
require_once $content_types_dir . '/class-uich-fields-field-groups-location.php';
require_once $content_types_dir . '/class-uich-fields-field-groups-render.php';
require_once $content_types_dir . '/uich-fields-field-api.php';

// File-first model layer.
require_once $content_types_dir . '/model/class-uich-fields-model-paths.php';
require_once $content_types_dir . '/model/class-uich-fields-model-store.php';
require_once $content_types_dir . '/model/class-uich-fields-model-loader.php';
require_once $content_types_dir . '/model/class-uich-fields-model-identity.php';
require_once $content_types_dir . '/model/class-uich-fields-model-rows.php';
require_once $content_types_dir . '/model/class-uich-fields-model-writer.php';
require_once $content_types_dir . '/model/class-uich-fields-model-validator.php';
require_once $content_types_dir . '/model/class-uich-fields-model-cache.php';
require_once $content_types_dir . '/model/class-uich-fields-model-sync.php';


// The mirror listens for definition meta writes, so it has to be attached before any of the
// instances below can perform one.
Uich_Fields_Model_Sync::init();

Uich_Fields_CPT::get_instance();
Uich_Fields_Definitions_Repository::get_instance();
Uich_Fields_Register::get_instance();

/*
 * REST controllers and the importers are only needed in admin, AJAX, cron, CLI and REST requests. On a
 * public page they are never reached, so they load on first use through the class map, and the
 * controllers attach on rest_api_init instead of being built on every request.
 */
$uich_fields_deferred = array(
	'Uich_Fields_REST_Field_Groups' => '/class-uich-fields-rest-field-groups.php',
	'Uich_Fields_REST_Bootstrap'    => '/class-uich-fields-rest-bootstrap.php',
	'Uich_Fields_Model_REST'        => '/model/class-uich-fields-model-rest.php',
	'Uich_Fields_Import_Source'     => '/import/class-uich-fields-import-source.php',
	'Uich_Fields_Import_ACF'        => '/import/class-uich-fields-import-acf.php',
	'Uich_Fields_Import_Metabox'    => '/import/class-uich-fields-import-metabox.php',
	'Uich_Fields_Import_Pods'       => '/import/class-uich-fields-import-pods.php',
	'Uich_Fields_Import_JetEngine'  => '/import/class-uich-fields-import-jetengine.php',
	'Uich_Fields_Import_Manager'    => '/import/class-uich-fields-import-manager.php',
	'Uich_Fields_Import_REST'       => '/import/class-uich-fields-import-rest.php',
);

spl_autoload_register(
	function ( $class_name ) use ( $uich_fields_deferred, $content_types_dir ) {
		// PHP class names are case-insensitive; callers do not always match the declared case.
		$wanted = strtolower( $class_name );
		foreach ( $uich_fields_deferred as $name => $file ) {
			if ( strtolower( $name ) === $wanted ) {
				require_once $content_types_dir . $file;
				return;
			}
		}
	}
);

$uich_fields_boot_controllers = function () {
	Uich_Fields_REST_Field_Groups::get_instance();
	Uich_Fields_REST_Bootstrap::get_instance();
	Uich_Fields_Import_REST::get_instance();
	Uich_Fields_Model_REST::get_instance();
};

if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	$uich_fields_boot_controllers();
} else {
	// Priority 1: each controller then adds its own route callback to this same hook.
	add_action( 'rest_api_init', $uich_fields_boot_controllers, 1 );
}
// Instantiate in all contexts: the render class binds its persist hooks unconditionally, so REST and frontend saves work.
Uich_Fields_Field_Groups_Render::get_instance();
