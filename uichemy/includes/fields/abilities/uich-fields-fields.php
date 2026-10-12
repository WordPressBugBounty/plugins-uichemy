<?php
/**
 * Abilities: Custom Fields — the individual fields inside a group, plus the discovery reads an agent needs
 * before it can write a valid field or location rule.
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
		'uichemy/list-field-types',
		array(
			'label'               => __( 'List Field Types', 'uichemy' ),
			'description'         => __(
				'Lists every field type Custom Fields supports, grouped as the editor presents them, with the types that are no longer offered for new fields.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_list_field_types',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Call this before creating a field: an unrecognised type is stored as-is but renders nothing, so the field silently never appears.',
							'choice_types need a "choices" value: one "value : Label" pair per line.',
							'The repeater type nests its subfields under a "fields" array on the field itself, not "sub_fields".',
							'deprecated_types must never be used for a new field — they exist only so already-saved data keeps rendering.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/get-location-choices',
		array(
			'label'               => __( 'Get Location Rule Choices', 'uichemy' ),
			'description'         => __(
				'Returns the valid values for one field group location rule parameter — the post types, templates, roles, taxonomies or menus that can be selected.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'param' => array(
						'type'        => 'string',
						'description' => 'Location rule parameter to enumerate.',
						'enum'        => array(
							'post_type',
							'post',
							'page',
							'post_template',
							'page_template',
							'post_status',
							'post_format',
							'post_category',
							'post_taxonomy',
							'taxonomy',
							'page_type',
							'page_parent',
							'user_role',
							'current_user_role',
							'current_user',
							'user_form',
							'attachment',
							'comment',
							'widget',
							'nav_menu',
							'nav_menu_item',
						),
					),
				),
				'required'             => array( 'param' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_get_location_choices',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Always call this before writing a location rule — a rule whose value does not exist matches nothing, so the field group appears nowhere and there is no error to see.',
							'A location rule is { "param": <one of the enum values>, "operator": "==" or "!=", "value": <a value from this list> }.',
							'Rules in one array are ANDed; nesting arrays ORs the groups.',
							'A large site can exceed the choice limit — check the truncated flag before assuming the list is complete.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/get-post-object-terms',
		array(
			'label'               => __( 'Get Post Object and Term Choices', 'uichemy' ),
			'description'         => __(
				'Returns the post types and taxonomies available for configuring post_object, page_link, relationship and taxonomy fields.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_get_post_object_terms',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Use this to fill the post_type / taxonomy filters on a relational field, so the field only offers valid targets.',
							'Relevant to the field types post_object, page_link, relationship and taxonomy.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/add-field-to-group',
		array(
			'label'               => __( 'Add Field to Group', 'uichemy' ),
			'description'         => __(
				'Adds one field definition to an existing Custom Fields group, leaving the other fields and their saved values untouched.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group'    => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field'    => array(
						'type'        => 'object',
						'description' => 'Field definition. Requires name and label; type defaults to text.',
					),
					'position' => array(
						'type'        => 'integer',
						'description' => 'Zero-based index to insert at. Omit to append to the end.',
						'minimum'     => 0,
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_add_field_to_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Prefer this over uichemy/update-field-group when adding a field — that ability replaces the whole field list, so a mistake there drops every other field.',
							'Minimum viable field: { "name": "isbn", "label": "ISBN", "type": "text" }.',
							'name becomes part of the stored meta key, so it must be lowercase letters/numbers/underscores and unique within the group. The call is refused if the name is already taken.',
							'Useful per-field options: instructions, required, default_value, placeholder, choices (for select/checkbox/radio), conditional_logic, wrapper width.',
							'For a repeater, put its subfield definitions in a "fields" array on the field itself.',
							'Call uichemy/list-field-types first if you are unsure of the type slug.',
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
		'uichemy/update-field-in-group',
		array(
			'label'               => __( 'Update Field in Group', 'uichemy' ),
			'description'         => __(
				'Changes one field definition inside a Custom Fields group without rewriting the rest of the field list.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field' => array(
						'type'        => 'string',
						'description' => 'Name (or key) of the field to change.',
					),
					'data'  => array(
						'type'        => 'object',
						'description' => 'Field properties to change. Merged over the existing definition.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field', 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_update_field_in_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Merges: send only the properties you want to change, e.g. { "data": { "label": "ISBN-13", "required": true } }.',
							'Renaming a field via data.name is refused once values have been saved against the old name — the stored meta key is built from it, so a rename would orphan the data. Relay the error instead of forcing it.',
							'Changing type on a field that already holds values can make those values unreadable to the new type. Warn the user first.',
							'To reorder fields, use uichemy/update-field-group with a full fields array.',
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
		'uichemy/delete-field-from-group',
		array(
			'label'               => __( 'Delete Field from Group', 'uichemy' ),
			'description'         => __(
				'Removes one field definition from a Custom Fields group. Values already saved for that field are left in the database as orphaned meta.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field' => array(
						'type'        => 'string',
						'description' => 'Name (or key) of the field to remove.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_delete_field_from_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'The field stops rendering and uich_field_value() stops returning it immediately.',
							'Values already saved are NOT deleted — they stay as orphaned post/term/user meta. Re-adding a field with the same name in the same group brings them back.',
							'Because the removal itself is easy to undo but the confusion is not, confirm with the user before deleting a field that holds data.',
							'Removing a repeater removes its subfields with it.',
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
 * List supported field types.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function uich_fields_mcp_list_field_types( array $input ): array {
	unset( $input );

	$catalog = uich_fields_mcp_ct_field_type_catalog();
	$all     = uich_fields_mcp_ct_creatable_field_types();

	// Read from the tier gate at call time, never restated here: a hardcoded list would drift, and an
	// agent that believes a locked type is available walks straight into a refused save.
	$pro       = function_exists( 'uich_fields_pro_types' ) ? uich_fields_pro_types() : array();
	$is_pro    = ! function_exists( 'uich_fields_is_pro' ) || uich_fields_is_pro();
	$available = $is_pro ? $all : array_values( array_diff( $all, $pro ) );

	$notes = array(
		'choices'  => 'select, checkbox and radio read a newline-separated "value : Label" list from the field\'s choices property.',
		'repeater' => 'A repeater holds its subfield definitions in a "fields" array on the field itself.',
		// Stated because a wrong key is stored and ignored: the field then offers Posts only.
		'relational' => 'Settings are prefixed with the field type: post_object_post_types, relationship_post_types and page_link_post_types are arrays of post type keys (default ["post"]); a taxonomy field names its taxonomy in taxonomy_name; a user field filters with user_role_filter. A value outside those limits is sanitised to empty.',
	);
	// The locked types stay in `types` and `groups` on purpose — an agent asked what the product can
	// do should be able to say so — but `available` is what it may actually write.
	if ( ! $is_pro && $pro ) {
		$notes['pro'] = sprintf(
			'This site is on the Free tier. %s require UiChemy Pro: they are listed above but a save that includes one is refused, and an existing field of that type reads as empty. Use "available" to choose a type.',
			implode( ', ', $pro )
		);
	}

	return array(
		'success'          => true,
		'total'            => count( $all ),
		'groups'           => $catalog,
		'types'            => $all,
		'available'        => $available,
		'pro_types'        => $pro,
		'is_pro'           => $is_pro,
		'choice_types'     => uich_fields_mcp_ct_choice_field_types(),
		'deprecated_types' => uich_fields_mcp_ct_legacy_field_types(),
		'notes'            => $notes,
	);
}

/**
 * Location rule choices for one parameter.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_get_location_choices( array $input ): array {
	$param = isset( $input['param'] ) ? sanitize_key( (string) $input['param'] ) : '';
	if ( '' === $param ) {
		return uich_fields_mcp_ct_fail( 'param is required, e.g. "post_type".' );
	}

	$result = uich_fields_mcp_ct_rest( 'GET', '/location-choices', array( 'param' => $param ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$head = array(
		'success' => true,
		'param'   => $param,
	);

	// The choices are still enumerated — the caller may be deciding whether Pro is worth it — but a
	// rule written with this param would be refused on save and would never match, so say so here
	// rather than letting the save be the first place it finds out.
	if ( function_exists( 'uich_fields_location_param_allowed' ) && ! uich_fields_location_param_allowed( $param ) ) {
		$head['pro_required'] = true;
		$head['message']      = sprintf(
			'"%s" is a Pro location parameter and this site is on the Free tier: a group saved with this rule is refused, and an existing one does not attach. Upgrade at %s',
			$param,
			uich_fields_upgrade_url( 'ability-location-choices' )
		);
	}

	return array_merge( $head, is_array( $result['data'] ) ? $result['data'] : array() );
}

/**
 * Post type / taxonomy choices for relational fields.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function uich_fields_mcp_get_post_object_terms( array $input ): array {
	unset( $input );

	$result = uich_fields_mcp_ct_rest( 'GET', '/post-object-terms' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
}

/**
 * Resolve a group plus its current field list, or a failure shape.
 *
 * @param mixed $group Group ID or key.
 * @return array ['definition' => array, 'fields' => array] on success, or a failure shape.
 */
function uich_fields_mcp_ct_group_fields( $group ): array {
	$definition = uich_fields_mcp_ct_locate_group( $group );
	if ( ! $definition ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'No field group found for "%s". Call uichemy/list-field-groups to see the available IDs and keys.',
				is_scalar( $group ) ? (string) $group : ''
			)
		);
	}

	$fields = isset( $definition['meta']['fields'] ) && is_array( $definition['meta']['fields'] )
		? $definition['meta']['fields']
		: array();

	return array(
		'success'    => true,
		'definition' => $definition,
		'fields'     => $fields,
	);
}

/**
 * Index of a field within a field list, matched on name then key.
 *
 * @param array  $fields   Field definitions.
 * @param string $selector Field name or key.
 * @return int|null
 */
function uich_fields_mcp_ct_field_index( array $fields, string $selector ): ?int {
	foreach ( $fields as $index => $field ) {
		if ( is_array( $field ) && isset( $field['name'] ) && $selector === (string) $field['name'] ) {
			return (int) $index;
		}
	}
	foreach ( $fields as $index => $field ) {
		if ( is_array( $field ) && isset( $field['key'] ) && $selector === (string) $field['key'] ) {
			return (int) $index;
		}
	}
	return null;
}

/**
 * Add one field to a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_add_field_to_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = uich_fields_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$field = isset( $input['field'] ) && is_array( $input['field'] ) ? $input['field'] : array();
	if ( empty( $field['name'] ) ) {
		return uich_fields_mcp_ct_fail( 'field.name is required, e.g. { "name": "isbn", "label": "ISBN", "type": "text" }.' );
	}

	$name = sanitize_key( (string) $field['name'] );
	if ( '' === $name ) {
		return uich_fields_mcp_ct_fail( 'field.name must contain lowercase letters, numbers or underscores.' );
	}
	// Refuse rather than store the sanitised form: the caller would go on to address a name that does not exist.
	if ( $name !== (string) $field['name'] ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'field.name "%s" is not valid: use lowercase letters, numbers, underscores and hyphens only. The closest valid name is "%s".', sanitize_text_field( (string) $field['name'] ), $name )
		);
	}
	$field['name'] = $name;

	if ( empty( $field['type'] ) ) {
		$field['type'] = 'text';
	}
	if ( empty( $field['label'] ) ) {
		$field['label'] = ucwords( str_replace( '_', ' ', $name ) );
	}

	$invalid = uich_fields_mcp_ct_invalid_field_types( array( $field ) );
	if ( ! empty( $invalid ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'Unknown field type(s): %s. Call uichemy/list-field-types for the supported list.', implode( ', ', $invalid ) )
		);
	}

	$problems = uich_fields_mcp_ct_field_problems( array( $field ) );
	if ( ! empty( $problems ) ) {
		return uich_fields_mcp_ct_fail( 'The field was not added: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$fields = $resolved['fields'];

	// Refuse a duplicate rather than letting finalize_group_meta() auto-rename it: a silent rename returns a field
	// whose name is not the one the caller asked for.
	if ( null !== uich_fields_mcp_ct_field_index( $fields, $name ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'The field group already has a field named "%s". Use uichemy/update-field-in-group to change it.', $name )
		);
	}

	$position = ( isset( $input['position'] ) && is_numeric( $input['position'] ) )
		? max( 0, min( count( $fields ), (int) $input['position'] ) )
		: count( $fields );

	array_splice( $fields, $position, 0, array( $field ) );

	$result = uich_fields_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'field'    => $name,
		'position' => $position,
		'message'  => sprintf( 'Field "%s" added.', $name ),
	);
}

/**
 * Update one field inside a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_update_field_in_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = uich_fields_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$selector = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $selector ) {
		return uich_fields_mcp_ct_fail( 'field is required: the name (or key) of the field to change.' );
	}

	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return uich_fields_mcp_ct_fail( 'data is required and must contain at least one property to change.' );
	}

	$fields = $resolved['fields'];
	$index  = uich_fields_mcp_ct_field_index( $fields, $selector );
	if ( null === $index ) {
		$existing = implode( ', ', uich_fields_mcp_ct_field_names( $fields ) );
		return uich_fields_mcp_ct_fail(
			sprintf(
				'No field named "%s" in this group. Existing fields: %s.',
				$selector,
				'' !== $existing ? $existing : 'none'
			)
		);
	}

	if ( isset( $data['name'] ) ) {
		$data['name'] = sanitize_key( (string) $data['name'] );
		if ( '' === $data['name'] ) {
			return uich_fields_mcp_ct_fail( 'data.name must contain lowercase letters, numbers or underscores.' );
		}
		$clash = uich_fields_mcp_ct_field_index( $fields, $data['name'] );
		if ( null !== $clash && $clash !== $index ) {
			return uich_fields_mcp_ct_fail( sprintf( 'Another field in this group is already named "%s".', $data['name'] ) );
		}
	}

	$merged = array_merge( $fields[ $index ], $data );

	$invalid = uich_fields_mcp_ct_invalid_field_types( array( $merged ) );
	if ( ! empty( $invalid ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf( 'Unknown field type(s): %s. Call uichemy/list-field-types for the supported list.', implode( ', ', $invalid ) )
		);
	}

	// A stored field keeps its name and type unchallenged, so a change INTO a withdrawn type is checked here.
	$legacy = array_keys( uich_fields_mcp_ct_legacy_field_types() );
	if ( isset( $data['type'] ) && in_array( (string) $data['type'], $legacy, true ) && (string) ( $fields[ $index ]['type'] ?? '' ) !== (string) $data['type'] ) {
		return uich_fields_mcp_ct_fail( sprintf( 'The type "%s" is withdrawn and cannot be given to a field.', (string) $data['type'] ) );
	}

	$problems = uich_fields_mcp_ct_field_problems( array( $merged ), $fields );
	if ( ! empty( $problems ) ) {
		return uich_fields_mcp_ct_fail( 'The field was not changed: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$fields[ $index ] = $merged;

	// Goes through the group PUT so the rename lock runs: renaming a field that already holds
	// saved values is refused there rather than silently orphaning the stored meta.
	$result = uich_fields_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'field'    => isset( $merged['name'] ) ? (string) $merged['name'] : $selector,
		'changed'  => array_keys( $data ),
	);
}

/**
 * Remove one field from a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_delete_field_from_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = uich_fields_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$selector = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $selector ) {
		return uich_fields_mcp_ct_fail( 'field is required: the name (or key) of the field to remove.' );
	}

	$fields = $resolved['fields'];
	$index  = uich_fields_mcp_ct_field_index( $fields, $selector );
	if ( null === $index ) {
		$existing = implode( ', ', uich_fields_mcp_ct_field_names( $fields ) );
		return uich_fields_mcp_ct_fail(
			sprintf(
				'No field named "%s" in this group. Existing fields: %s.',
				$selector,
				'' !== $existing ? $existing : 'none'
			)
		);
	}

	$removed = isset( $fields[ $index ]['name'] ) ? (string) $fields[ $index ]['name'] : $selector;

	array_splice( $fields, $index, 1 );

	$result = uich_fields_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'   => true,
		'group_id'  => $resolved['definition']['id'],
		'field'     => $removed,
		'remaining' => uich_fields_mcp_ct_field_names( $fields ),
		'message'   => sprintf(
			'Field "%s" removed from the group. Any values already saved for it remain in the database as orphaned meta.',
			$removed
		),
	);
}

/**
 * Reorder the fields in a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_reorder_fields_in_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = uich_fields_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$fields = $resolved['fields'];
	if ( count( $fields ) < 2 ) {
		return uich_fields_mcp_ct_fail( 'This group has fewer than two fields, so there is nothing to reorder.' );
	}

	$order = isset( $input['order'] ) && is_array( $input['order'] ) ? array_values( $input['order'] ) : array();
	if ( empty( $order ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'order is required: every field name in the new order. This group has: %s.',
				implode( ', ', uich_fields_mcp_ct_field_names( $fields ) )
			)
		);
	}

	// A partial order is refused rather than "the rest, appended".
	$indexes = array();
	$unknown = array();
	foreach ( $order as $selector ) {
		$index = uich_fields_mcp_ct_field_index( $fields, trim( (string) $selector ) );
		if ( null === $index ) {
			$unknown[] = (string) $selector;
			continue;
		}
		$indexes[] = $index;
	}
	if ( ! empty( $unknown ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'Unknown field(s) in order: %s. This group has: %s.',
				implode( ', ', $unknown ),
				implode( ', ', uich_fields_mcp_ct_field_names( $fields ) )
			)
		);
	}

	$unique = array_unique( $indexes );
	if ( count( $unique ) !== count( $indexes ) ) {
		return uich_fields_mcp_ct_fail( 'order lists the same field more than once.' );
	}
	if ( count( $unique ) !== count( $fields ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'order must list every field in the group. Expected %d, got %d — missing: %s.',
				count( $fields ),
				count( $unique ),
				implode( ', ', array_values( array_diff( uich_fields_mcp_ct_field_names( $fields ), array_map( 'strval', $order ) ) ) )
			)
		);
	}

	$reordered = array();
	foreach ( $indexes as $index ) {
		$reordered[] = $fields[ $index ];
	}

	// Through the group PUT like every other field write, so the rename lock and the group's own
	// sanitisation run. Only the order changed, so no field can be renamed by this call.
	$result = uich_fields_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $reordered ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'order'    => uich_fields_mcp_ct_field_names( $reordered ),
	);
}

if ( uich_fields_mcp_ct_module_active() ) {

	uich_fields_register_ability(
		'uichemy/reorder-fields-in-group',
		array(
			'label'               => __( 'Reorder Fields In Group', 'uichemy' ),
			'description'         => __(
				'Changes the order of the fields in a Custom Fields group without touching the fields themselves or any stored value.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'order' => array(
						'type'        => 'array',
						'description' => 'Every field name in the group, in the order wanted. Must list all of them.',
						'items'       => array( 'type' => 'string' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'order' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'group_id' => array( 'type' => 'integer' ),
					'order'    => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_reorder_fields_in_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Call uichemy/get-field-group first to read the current field names — order must list every one of them.',
							'A partial order is refused, not appended: the caller must decide where every field goes.',
							'This changes display order only. No field is renamed and no stored value moves.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);
}
