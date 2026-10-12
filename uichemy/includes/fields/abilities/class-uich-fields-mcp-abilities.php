<?php
/**
 * Bootstraps UiChemy Fields abilities and their category.
 *
 * @package Uichemy
 * @since   4.6.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- uich_fields_mcp_permission_callback() must live
// in the same file as the loader, or earlier than it.
if ( ! function_exists( 'uich_fields_mcp_permission_callback' ) ) {
	/**
	 * Permission callback for all UiChemy Fields abilities.
	 *
	 * @param mixed $input Ability input arguments (unused).
	 * @return bool Whether the current user may use the ability.
	 */
	function uich_fields_mcp_permission_callback( $input = null ) {
		return current_user_can( 'manage_options' );
	}
}

/**
 * Registers the UiChemy ability category and loads each ability file.
 */
class Uich_Fields_MCP_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var ?Uich_Fields_MCP_Abilities
	 */
	private static $instance;

	/**
	 * Whether abilities API hooks have been registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Gets the singleton instance of the class.
	 *
	 * @return Uich_Fields_MCP_Abilities
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// The kill switches live in the loader: the Custom Fields feature switch and the
		// `uichemy_fields_abilities_enabled` filter both stop it before this class is reached.
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers ability categories for UiChemy.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		if ( ! self::abilities_enabled() ) {
			return;
		}
		$this->ensure_category_registered();
	}

	/**
	 * Whether the abilities are switched on.
	 *
	 * Read when the Abilities API asks for them, not in the constructor (which runs on every request).
	 * The filter is the only switch: no other plugin's option is consulted.
	 *
	 * @return bool
	 */
	private static function abilities_enabled(): bool {
		/** This filter is documented in class-uich-fields-loader.php */
		return (bool) apply_filters( 'uichemy_fields_abilities_enabled', true );
	}

	/**
	 * Ensures the "uichemy" ability category exists.
	 *
	 * @return void
	 */
	private function ensure_category_registered(): void {
		if ( ! class_exists( 'WP_Ability_Categories_Registry' ) ) {
			return;
		}
		$registry = WP_Ability_Categories_Registry::get_instance();
		if ( ! $registry || $registry->is_registered( 'uichemy' ) ) {
			return;
		}
		$registry->register(
			'uichemy',
			array(
				'label'       => __( 'UiChemy Fields', 'uichemy' ),
				'description' => __( 'UiChemy Custom Fields abilities: custom post types, taxonomies, field groups, fields and field values.', 'uichemy' ),
			)
		);
	}

	/**
	 * Registers MCP abilities by loading ability files from the abilities directory.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! self::abilities_enabled() ) {
			return;
		}

		// Guarantee the category exists before any ability references it, even when categories_init did not run first.
		$this->ensure_category_registered();

		$base = UICH_FIELDS_DIR . 'abilities/';

		// The journal store, ahead of the lifecycle that writes to it.
		require_once $base . 'class-uich-fields-ability-journal.php';

		// Must load before the ability files: wp_register_ability_args only reaches abilities
		// registered after the filter is attached.
		require_once $base . 'lifecycle-filters.php';

		// Loaded ahead of the loop: every Custom Fields ability calls these helpers from its execute_callback.
		require_once $base . 'uich-fields-content-types-shared.php';

		// Loaded ahead of the loop for the same reason, but used by write abilities in every module.
		require_once $base . 'uich-fields-dry-run.php';

		// Registers no ability — it attaches the MCP handshake filter and generates the manual from
		// whatever ends up registered below.
		require_once $base . 'uich-fields-mcp-manual.php';

		// Must be attached before the first ability registers, or the preset does not reach it.
		require_once $base . 'uich-fields-mcp-exposure.php';

		foreach ( array(
			'uich-fields-content-types',
			'uich-fields-content-types-model',
			'uich-fields-cpt',
			'uich-fields-docs',
			'uich-fields-field-groups',
			'uich-fields-field-values',
			'uich-fields-fields',
			'uich-fields-apply-changes',
			'uich-fields-content-model',
			'uich-fields-list-changes',
			'uich-fields-scaffold',
			'uich-fields-schema',
			'uich-fields-taxonomy',
		) as $file ) {
			require_once $base . $file . '.php';
		}
	}
}
