<?php
/**
 * Abilities: Custom Fields — custom post type writes (create, update) plus the shared
 * activate/deactivate toggle for post types and taxonomies.
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
		'uichemy/create-cpt',
		array(
			'label'               => __( 'Create Custom Post Type', 'uichemy' ),
			'description'         => __(
				'Registers a new custom post type through Custom Fields, with labels, visibility, supports, rewrite rules, capabilities and REST settings.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'data'    => array(
						'type'        => 'object',
						'description' => 'Post type configuration. Requires post_type_key and label; everything else falls back to sensible defaults.',
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
			'execute_callback'    => 'uich_fields_mcp_create_cpt',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Minimum viable payload: { "data": { "post_type_key": "book", "label": "Books", "singular_label": "Book" } }.',
							'post_type_key must be 1-20 characters, lowercase letters/numbers/underscores, and must not collide with a WordPress reserved key (post, page, attachment, revision, nav_menu_item, …) or an existing post type.',
							'Common options: public, show_ui, show_in_rest, hierarchical, has_archive, archive_slug, rewrite_slug, supports (array of title/editor/thumbnail/excerpt/custom-fields/…), taxonomies (array of taxonomy keys), menu_icon, menu_position, description, labels (object of label overrides).',
							'Capability options (capability_type, map_meta_cap, capabilities) only take effect together — set map_meta_cap true when supplying a custom capabilities map.',
							'Set active false to create it switched off. The default is active.',
							'If the key is already taken the call fails with a clear message; call uichemy/list-content-types first to check.',
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
		'uichemy/update-cpt',
		array(
			'label'               => __( 'Update Custom Post Type', 'uichemy' ),
			'description'         => __(
				'Updates an existing Custom Fields custom post type definition by ID.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Definition ID of the post type to update.',
						'minimum'     => 1,
					),
					'data'    => array(
						'type'        => 'object',
						'description' => 'Full post type configuration to store.',
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => 'Acknowledge a change that hides existing content (e.g. deactivating a post type that still has posts).',
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
			'execute_callback'    => 'uich_fields_mcp_update_cpt',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Read the definition with uichemy/get-content-type first and send the merged result back — this replaces the stored configuration rather than patching it, so omitted keys fall back to defaults.',
							'Renaming post_type_key orphans every post already saved under the old key. Warn the user before doing it.',
							'If the response returns requires_confirmation=true, relay the message and only re-send with confirm=true once the user agrees.',
							'To only switch a post type on or off, prefer uichemy/toggle-content-type — it preserves the rest of the configuration for you.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/toggle-content-type',
		array(
			'label'               => __( 'Activate or Deactivate Content Type', 'uichemy' ),
			'description'         => __(
				'Switches a Custom Fields custom post type or taxonomy on or off without touching the rest of its configuration.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Definition ID of the post type or taxonomy.',
						'minimum'     => 1,
					),
					'active'  => array(
						'type'        => 'boolean',
						'description' => 'Target state. Omit to flip whatever the current state is.',
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => 'Acknowledge that deactivating hides existing posts or terms.',
						'default'     => false,
					),
					'dry_run' => uich_fields_dry_run_arg( 'the definition save' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_toggle_content_type',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Reads the current configuration, changes only the active flag, and saves it back — no other option is disturbed.',
							'Deactivating does not delete anything, but the post type or taxonomy stops being registered, so its posts/terms become unreachable in the admin and on the front end until it is switched back on.',
							'When content exists, the first call returns requires_confirmation=true with a count. Relay that to the user before re-sending with confirm=true.',
							'For field groups use uichemy/toggle-field-group instead.',
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
 * Create a custom post type definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_create_cpt( array $input ): array {
	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data['post_type_key'] ) ) {
		return uich_fields_mcp_ct_fail( 'data.post_type_key is required, e.g. "book".' );
	}

	$data['kind'] = 'cpt';

	$result = uich_fields_mcp_ct_rest(
		'POST',
		'/cpt',
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
 * Update a custom post type definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_update_cpt( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return uich_fields_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$definition = uich_fields_mcp_ct_definition( $id );
	if ( ! $definition ) {
		return uich_fields_mcp_ct_fail( sprintf( 'No Custom Fields definition exists with ID %d.', $id ) );
	}
	if ( 'cpt' !== $definition['kind'] ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'Definition %d is a "%s", not a post type. Use the matching ability for that kind.', $id, $definition['kind'] )
		);
	}

	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return uich_fields_mcp_ct_fail( 'data is required and must contain the configuration to store.' );
	}

	$data['kind'] = 'cpt';

	$result = uich_fields_mcp_ct_rest(
		'POST',
		'/cpt',
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

/**
 * Activate or deactivate a post type / taxonomy, preserving every other option.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_toggle_content_type( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return uich_fields_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$definition = uich_fields_mcp_ct_definition( $id );
	if ( ! $definition ) {
		return uich_fields_mcp_ct_fail( sprintf( 'No Custom Fields definition exists with ID %d.', $id ) );
	}

	if ( 'group_fields' === $definition['kind'] ) {
		return uich_fields_mcp_ct_fail( 'That ID is a field group. Use uichemy/toggle-field-group instead.' );
	}

	$route = ( 'taxonomy' === $definition['kind'] ) ? '/taxonomy' : '/cpt';

	// Send the stored configuration back with only `active` changed.
	$data           = $definition['meta'];
	$was_active     = uich_fields_mcp_ct_is_active( $data );
	$target         = array_key_exists( 'active', $input ) ? (bool) $input['active'] : ! $was_active;
	$data['active'] = $target;

	if ( $target === $was_active ) {
		return array(
			'success' => true,
			'id'      => $id,
			'active'  => $target,
			'message' => sprintf( 'Already %s; nothing changed.', $target ? 'active' : 'inactive' ),
			// Stated here, or the lifecycle attaches toggle-back to a call that changed nothing —
			// replaying that undo would flip a state the caller never touched.
			'undo'    => array(
				'strategy' => 'none',
				'reason'   => 'Nothing changed, so there is nothing to reverse.',
			),
		);
	}

	$result = uich_fields_mcp_ct_rest(
		'POST',
		$route,
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

	// The definition save owns the preview; flat, because the lifecycle reads dry_run at the top.
	if ( ! empty( $input['dry_run'] ) ) {
		return uich_fields_dry_run(
			'update',
			array(
				'id'     => $id,
				'kind'   => $definition['kind'],
				'active' => $target,
				'from'   => $was_active,
			)
		);
	}

	return array_merge(
		array(
			'success' => true,
			'id'      => $id,
			'kind'    => $definition['kind'],
			'active'  => $target,
		),
		is_array( $result['data'] ) ? $result['data'] : array()
	);
}
