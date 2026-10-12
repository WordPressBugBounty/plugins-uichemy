<?php
/**
 * Abilities: Custom Fields — custom taxonomy writes (create, update).
 *
 * @package Uichemy
 * @since   4.7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( uich_fields_mcp_ct_module_active() ) {

	uich_fields_register_ability(
		'uichemy/create-taxonomy',
		array(
			'label'               => __( 'Create Custom Taxonomy', 'uichemy' ),
			'description'         => __(
				'Registers a new custom taxonomy through Custom Fields and attaches it to one or more post types.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'data'    => array(
						'type'        => 'object',
						'description' => 'Taxonomy configuration. Requires taxonomy_key, label and object_type.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check this save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_create_taxonomy',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Minimum viable payload: { "data": { "taxonomy_key": "genre", "label": "Genres", "singular_label": "Genre", "object_type": ["post"] } }.',
							'object_type is an array of post type keys the taxonomy attaches to — include custom keys created via uichemy/create-cpt.',
							'taxonomy_key must be 1-32 characters, lowercase letters/numbers/underscores, and must not collide with a reserved key (category, post_tag, nav_menu, link_category, post_format, …) or an existing taxonomy.',
							'Set hierarchical true for category-style terms, false for tag-style.',
							'Common options: public, show_ui, show_in_rest, show_admin_column, show_tag_cloud, show_in_nav_menus, rewrite, rewrite_slug, rewrite_hierarchical, sort, description, labels, default_term_enabled + default_term.',
							'Set active false to create it switched off. The default is active.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/update-taxonomy',
		array(
			'label'               => __( 'Update Custom Taxonomy', 'uichemy' ),
			'description'         => __(
				'Updates an existing Custom Fields custom taxonomy definition by ID.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Definition ID of the taxonomy to update.',
						'minimum'     => 1,
					),
					'data'    => array(
						'type'        => 'object',
						'description' => 'Full taxonomy configuration to store.',
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => 'Acknowledge a change that hides existing terms (e.g. deactivating a taxonomy that still has terms).',
						'default'     => false,
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check this save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'id', 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_update_taxonomy',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Read the definition with uichemy/get-content-type first and send the merged result back — this replaces the stored configuration rather than patching it.',
							'Renaming taxonomy_key orphans every term already saved under the old key. Warn the user before doing it.',
							'Removing a post type from object_type does not delete term assignments, but they stop being visible for that post type.',
							'If the response returns requires_confirmation=true, relay the message and only re-send with confirm=true once the user agrees.',
							'To only switch a taxonomy on or off, prefer uichemy/toggle-content-type.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
				),
			),
		)
	);
}

/**
 * Create a taxonomy definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_create_taxonomy( array $input ): array {
	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data['taxonomy_key'] ) ) {
		return uich_fields_mcp_ct_fail( 'data.taxonomy_key is required, e.g. "genre".' );
	}
	if ( empty( $data['object_type'] ) || ! is_array( $data['object_type'] ) ) {
		return uich_fields_mcp_ct_fail( 'data.object_type is required and must be an array of post type keys, e.g. ["post"].' );
	}

	$data['kind'] = 'taxonomy';

	$result = uich_fields_mcp_ct_rest(
		'POST',
		'/taxonomy',
		array(
			'id'      => 0,
			'data'    => $data,
			'dry_run' => ! empty( $input['dry_run'] ),
		)
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// A dry run has no stored key to echo back, and nothing was created to look up.
	if ( ! empty( $input['dry_run'] ) ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	$response = array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );

	// Echo the key that was actually stored — it may have been sanitised or suffixed.
	return uich_fields_mcp_ct_with_stored_key( $response, isset( $response['id'] ) ? (int) $response['id'] : 0 );
}

/**
 * Update a taxonomy definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_update_taxonomy( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return uich_fields_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$definition = uich_fields_mcp_ct_definition( $id );
	if ( ! $definition ) {
		return uich_fields_mcp_ct_fail( sprintf( 'No Custom Fields definition exists with ID %d.', $id ) );
	}
	if ( 'taxonomy' !== $definition['kind'] ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'Definition %d is a "%s", not a taxonomy. Use the matching ability for that kind.', $id, $definition['kind'] )
		);
	}

	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return uich_fields_mcp_ct_fail( 'data is required and must contain the configuration to store.' );
	}

	$data['kind'] = 'taxonomy';

	$result = uich_fields_mcp_ct_rest(
		'POST',
		'/taxonomy',
		array(
			'id'      => $id,
			'data'    => $data,
			'confirm' => ! empty( $input['confirm'] ),
			'dry_run' => ! empty( $input['dry_run'] ),
		)
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$response = array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );

	// A dry run stored nothing, so there is no stored key to read back.
	if ( ! empty( $input['dry_run'] ) ) {
		return $response;
	}

	// Echo the key that was actually stored — it may have been sanitised or suffixed.
	return uich_fields_mcp_ct_with_stored_key( $response, isset( $response['id'] ) ? (int) $response['id'] : 0 );
}
