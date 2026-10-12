<?php
/**
 * Register internal CPT "uich_content_types" for storing Custom Post Type and Taxonomy definitions.
 * All fields stored in a single meta key as sanitized array. Conditional registration (admin-only where appropriate).
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_CPT
 */
class Uich_Fields_CPT {

	const POST_TYPE = 'uich_content_types';
	const META_KEY  = '_uichemy_content_type_data';

	/**
	 * Single instance.
	 *
	 * @var Uich_Fields_CPT|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Uich_Fields_CPT
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers the internal storage post type early on init (before the user
	 * CPT/taxonomy registration at priority 15).
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ), 5 );
	}

	/**
	 * Register the private internal post type that stores CPT / taxonomy / field-group definitions.
	 */
	public function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		\register_post_type(
			self::POST_TYPE,
			array(
				'label'               => 'Uichemy Content Types',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Get meta key for stored data.
	 *
	 * @return string
	 */
	public static function get_meta_key() {
		return self::META_KEY;
	}
}
