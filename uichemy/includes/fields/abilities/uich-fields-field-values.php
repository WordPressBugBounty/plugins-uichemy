<?php
/**
 * Abilities: Custom Fields — reading and writing the actual field VALUES stored against a post, term, user,
 * comment or the options screen.
 *
 * @package Uichemy
 * @since   4.7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Whether the field value API is loaded.
 *
 * @return bool
 */
function uich_fields_mcp_ct_value_api_active(): bool {
	return function_exists( 'uich_field_value' ) && function_exists( 'uich_maybe_get_field_info' );
}

// Field values change through ordinary editing — a post save, a profile update, a term edit — none of which
// pass through an ability, so the lifecycle cache-version bump never fires for them.
add_filter(
	'uich_fields_ability_cache_ttl',
	static function ( $ttl, $name ) {
		if ( 'uichemy/get-field-value' === $name || 'uichemy/get-field-values' === $name ) {
			return 0;
		}
		return $ttl;
	},
	10,
	2
);

if ( uich_fields_mcp_ct_module_active() && uich_fields_mcp_ct_value_api_active() ) {

	uich_fields_register_ability(
		'uichemy/get-field-value',
		array(
			'label'               => __( 'Get Field Value', 'uichemy' ),
			'description'         => __(
				'Reads one Custom Fields custom field value from a post, page, term, user, comment or the options screen.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'field'     => array(
						'type'        => 'string',
						'description' => 'Field name, e.g. "isbn".',
					),
					'object_id' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Post ID (123), or "user_5", "term_9", "comment_3", or "option" for the options screen.',
					),
					'format'    => array(
						'type'        => 'boolean',
						'description' => 'Return the formatted value (image ID resolved to a URL, etc.) rather than the raw stored value.',
						'default'     => true,
					),
				),
				'required'             => array( 'field', 'object_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_get_field_value',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'object_id encodes the object type: a bare number is a post or page, "user_5" is a user, "term_9" is a term, "comment_3" is a comment, "option" is the options screen.',
							'Returns value plus the field type, label, group key and meta key, so you can see exactly where the value lives.',
							'A null value means one of three things: no value saved, no field group matches this object, or the field is hidden by its conditional logic. The "resolved" flag distinguishes them.',
							'Set format false when you need the raw stored value — an attachment ID rather than a URL, a term ID rather than a term object.',
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
		'uichemy/get-field-values',
		array(
			'label'               => __( 'Get All Field Values', 'uichemy' ),
			'description'         => __(
				'Reads every Custom Fields custom field value that applies to one post, term, user, comment or the options screen.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'object_id' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Post ID (123), or "user_5", "term_9", "comment_3", or "option".',
					),
					'format'    => array(
						'type'        => 'boolean',
						'description' => 'Return formatted values rather than raw stored values.',
						'default'     => true,
					),
				),
				'required'             => array( 'object_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_get_field_values',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Returns only the fields whose group location rules match this object — that is the same set the editor shows.',
							'Use this to understand what custom data an object carries before changing any of it.',
							'An empty result means no field group targets this object; check uichemy/list-field-groups and its location rules.',
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
		'uichemy/update-field-value',
		array(
			'label'               => __( 'Update Field Value', 'uichemy' ),
			'description'         => __(
				'Writes one Custom Fields custom field value onto a post, term, user, comment or the options screen.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'field'     => array(
						'type'        => 'string',
						'description' => 'Field name, e.g. "isbn".',
					),
					'object_id' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Post ID (123), or "user_5", "term_9", "comment_3", or "option".',
					),
					'value'     => array(
						// A field value is genuinely any JSON type, but the keyword is not optional:
						// rest_validate_value_from_schema() emits _doing_it_wrong on every call
						// without it, so each value write was writing a notice to the site's log.
						'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
						'description' => 'Value to store. Use the raw form: an attachment ID for image/file, a post ID for post_object, an array of rows for a repeater.',
					),
					'dry_run'   => array(
						'type'        => 'boolean',
						'description' => 'Run every check and report what would be stored after sanitising, without writing.',
						'default'     => false,
					),
				),
				'required'             => array( 'field', 'object_id', 'value' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_update_field_value',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Store the RAW value, not the formatted one: an attachment ID for image and file, a post ID for post_object, a term ID for taxonomy, an array of row objects for a repeater.',
							'Read the field first with uichemy/get-field-value and format=false to see the shape the field already stores.',
							'The write is refused if no field group targeting this object defines the field — that means the value would be unreadable, so check the group location rules rather than retrying.',
							'This overwrites the existing value with no undo. Confirm with the user before changing data on a live site.',
							'The value IS sanitised before it is stored, through the same path the editor saves through: the protocol allowlist on url and link, wp_kses_post on wysiwyg, the choice allowlist on select/radio/checkbox, min/max clamping on number and range, email validation, and ID validation on every relational type. A non-empty value the sanitiser reduces to nothing (a choice the field does not declare, "abc" for a number, a javascript: URL, an ID that does not exist) is REFUSED with code uich_fields_value_rejected and the previous value is kept; a value that is only adjusted (a clamped number) is stored and reported with sanitized: true. A page_link field also accepts a bare post ID, stored as that post\'s permalink. Read it back to see what landed— or send dry_run: true, which reports the value AFTER the sanitiser.',
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
		'uichemy/delete-field-value',
		array(
			'label'               => __( 'Delete Field Value', 'uichemy' ),
			'description'         => __(
				'Deletes one stored Custom Fields custom field value from a post, term, user, comment or the options screen.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'field'     => array(
						'type'        => 'string',
						'description' => 'Field name, e.g. "isbn".',
					),
					'object_id' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Post ID (123), or "user_5", "term_9", "comment_3", or "option".',
					),
					'dry_run'   => array(
						'type'        => 'boolean',
						'description' => 'Report whether a stored value exists, without deleting it.',
						'default'     => false,
					),
				),
				'required'             => array( 'field', 'object_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_delete_field_value',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Removes the stored row entirely, so the field falls back to its default value where one is configured.',
							'This is different from writing an empty value: an empty value is stored and read back as empty, whereas a deleted value lets the default apply again.',
							'IRREVERSIBLE for that value. Read it with uichemy/get-field-value first if there is any chance it is wanted back.',
							'The field definition itself is untouched — use uichemy/delete-field-from-group to remove the field.',
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
 * Normalize the object_id input to what the field API accepts.
 *
 * @param mixed $object_id Raw input.
 * @return int|string
 */
function uich_fields_mcp_ct_object_id( $object_id ) {
	if ( is_int( $object_id ) ) {
		return $object_id;
	}
	if ( is_string( $object_id ) && ctype_digit( $object_id ) ) {
		return (int) $object_id;
	}
	return is_string( $object_id ) ? strtolower( trim( $object_id ) ) : 0;
}

/**
 * Human description of the object an object_id points at.
 *
 * @param mixed $object_id Normalized object id.
 * @return string
 */
function uich_fields_mcp_ct_object_label( $object_id ): string {
	$parsed = uich_get_valid_object_id( $object_id );
	return ( 'option' === $parsed['type'] )
		? 'the options screen'
		: sprintf( '%s %s', $parsed['type'], (string) $parsed['id'] );
}

/**
 * Read one field value.
 *
 * @param array $input Ability input.
 * @return array
 */
/**
 * Withhold a stored secret from an ability response.
 *
 * The withdrawn `password` type stored its values as cleartext meta. The admin UI never prints
 * them back; an MCP read must not either, or the secret lands in an AI agent's context and logs.
 *
 * @param string $type  Field type.
 * @param mixed  $value Stored/formatted value.
 * @return mixed
 */
function uich_fields_mcp_mask_secret( $type, $value ) {
	if ( 'password' !== (string) $type ) {
		return $value;
	}
	return ( null === $value || '' === $value ) ? $value : '********';
}

function uich_fields_mcp_get_field_value( array $input ): array {
	$field = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $field ) {
		return uich_fields_mcp_ct_fail( 'field is required, e.g. "isbn".' );
	}

	$object_id = uich_fields_mcp_ct_object_id( isset( $input['object_id'] ) ? $input['object_id'] : 0 );
	$format    = ! isset( $input['format'] ) || (bool) $input['format'];

	$parsed = uich_get_valid_object_id( $object_id );
	$gone   = uich_fields_mcp_ct_missing_object( $parsed['type'], $parsed['id'] );
	if ( null !== $gone ) {
		return $gone;
	}
	if ( ! uich_fields_mcp_ct_can_edit_object( $parsed['type'], $parsed['id'] ) ) {
		return uich_fields_mcp_ct_denied( $parsed['type'], $parsed['id'], 'read' );
	}

	$info = uich_maybe_get_field_info( $field, $object_id );
	if ( ! $info ) {
		return array(
			'success'   => true,
			'resolved'  => false,
			'field'     => $field,
			'object_id' => $object_id,
			'value'     => null,
			'message'   => sprintf(
				'No field group defining "%s" applies to %s, and no value is stored for it there.',
				$field,
				uich_fields_mcp_ct_object_label( $object_id )
			),
		);
	}

	$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );

	return array(
		'success'     => true,
		'resolved'    => true,
		'field'       => (string) $info['field']['name'],
		'label'       => isset( $info['field']['label'] ) ? (string) $info['field']['label'] : '',
		'type'        => isset( $info['field']['type'] ) ? (string) $info['field']['type'] : 'text',
		'group_key'   => (string) $info['group_key'],
		'object_type' => (string) $info['object_type'],
		'object_id'   => $info['object_id'],
		'meta_key'    => $meta_key,
		'formatted'   => $format,
		// Reading through uich_field_value() rather than the raw meta keeps conditional logic and the
		// default_value fallback in play, so this matches what the site itself would render.
		'value'       => uich_fields_mcp_mask_secret(
			isset( $info['field']['type'] ) ? (string) $info['field']['type'] : 'text',
			uich_field_value( $field, $object_id, $format )
		),
	);
}

/**
 * Read every field value that applies to an object.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_get_field_values( array $input ): array {
	$object_id = uich_fields_mcp_ct_object_id( isset( $input['object_id'] ) ? $input['object_id'] : 0 );
	$format    = ! isset( $input['format'] ) || (bool) $input['format'];

	$parsed = uich_get_valid_object_id( $object_id );
	$gone   = uich_fields_mcp_ct_missing_object( $parsed['type'], $parsed['id'] );
	if ( null !== $gone ) {
		return $gone;
	}
	if ( ! uich_fields_mcp_ct_can_edit_object( $parsed['type'], $parsed['id'] ) ) {
		return uich_fields_mcp_ct_denied( $parsed['type'], $parsed['id'], 'read' );
	}

	$values  = uich_field_values( $object_id, $format );
	$objects = uich_field_definitions( $object_id, $format );

	if ( ! is_array( $values ) ) {
		$values = array();
	}

	$fields = array();
	if ( is_array( $objects ) ) {
		foreach ( $objects as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$type     = isset( $definition['type'] ) ? (string) $definition['type'] : 'text';
			$fields[] = array(
				'field' => (string) $name,
				'label' => isset( $definition['label'] ) ? (string) $definition['label'] : '',
				'type'  => $type,
				'value' => uich_fields_mcp_mask_secret( $type, isset( $definition['value'] ) ? $definition['value'] : null ),
			);
			if ( 'password' === $type && array_key_exists( (string) $name, $values ) ) {
				$values[ (string) $name ] = uich_fields_mcp_mask_secret( $type, $values[ (string) $name ] );
			}
		}
	}

	return array(
		'success'   => true,
		'object_id' => $object_id,
		'object'    => uich_fields_mcp_ct_object_label( $object_id ),
		'total'     => count( $fields ),
		'formatted' => $format,
		'fields'    => $fields,
		'values'    => $values,
	);
}

/**
 * Write one field value.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_update_field_value( array $input ): array {
	$field = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $field ) {
		return uich_fields_mcp_ct_fail( 'field is required, e.g. "isbn".' );
	}
	if ( ! array_key_exists( 'value', $input ) ) {
		return uich_fields_mcp_ct_fail( 'value is required. To remove a value entirely use uichemy/delete-field-value.' );
	}

	$object_id = uich_fields_mcp_ct_object_id( isset( $input['object_id'] ) ? $input['object_id'] : 0 );

	// A missing object is a 404, not a location-rules or permission failure.
	$parsed = uich_get_valid_object_id( $object_id );
	$gone   = uich_fields_mcp_ct_missing_object( $parsed['type'], $parsed['id'] );
	if ( null !== $gone ) {
		return $gone;
	}

	$info = uich_maybe_get_field_info( $field, $object_id );
	if ( ! $info ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'No field group defining "%s" applies to %s, so a value written there would never be read back. Check the group location rules with uichemy/get-field-group.',
				$field,
				uich_fields_mcp_ct_object_label( $object_id )
			)
		);
	}

	// The resolver falls back to ANY group so an orphaned value stays readable. A write through that
	// fallback would create a new orphan: stored, but never shown on this object.
	if ( empty( $info['matched'] ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'"%s" belongs to the field group "%s", whose location rules do not include %s, so a value written there would never be shown. Check the group location rules with uichemy/get-field-group.',
				(string) $info['field']['name'],
				(string) $info['group_key'],
				uich_fields_mcp_ct_object_label( $object_id )
			),
			array( 'code' => 'uich_fields_field_not_located' )
		);
	}

	// Checked against the RESOLVED object rather than the raw input, because that is the object the
	// write actually lands on.
	if ( ! uich_fields_mcp_ct_can_edit_object( (string) $info['object_type'], $info['object_id'] ) ) {
		return uich_fields_mcp_ct_denied( (string) $info['object_type'], $info['object_id'] );
	}

	// The tier gate, checked here because this ability writes through uich_update_stored_value()
	// rather than uich_save_field_value() — so neither the field API's gate nor the metabox save
	// handler's covers it, and without this an agent could write a Pro field type on a Free site.
	// Placed above the dry run so a preview reports the refusal instead of a pass the real call
	// would reject.
	if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( (string) ( $info['field']['type'] ?? '' ) ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'"%s" is a %s field, and that field type requires UiChemy Pro. The stored value was left untouched. Upgrade at %s',
				(string) $info['field']['name'],
				(string) ( $info['field']['type'] ?? '' ),
				uich_fields_upgrade_url( 'ability-update-field-value' )
			)
		);
	}

	$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );
	$previous = uich_get_stored_value( $meta_key, $info['object_type'], $info['object_id'], null );

	// Run the module's own per-type sanitizer, the same one every save_* form handler uses.
	$value = uich_fields_mcp_ct_sanitize_value( uich_page_link_ids_to_permalinks( $input['value'], $info['field'] ), $info['field'] );
	if ( null === $value ) {
		return uich_fields_mcp_ct_fail(
			'The field value sanitizer is unavailable, so the write was refused rather than storing an unchecked value.'
		);
	}

	// Refused, not stored empty over the previous value. Above the dry run so a preview reports the same
	// refusal the real call would make.
	$rejection = uich_field_value_rejection( $input['value'], $value, $info['field'] );
	if ( '' !== $rejection ) {
		return uich_fields_mcp_ct_fail(
			$rejection,
			array(
				'code'          => 'uich_fields_value_rejected',
				'field'         => (string) $info['field']['name'],
				'field_type'    => (string) ( $info['field']['type'] ?? '' ),
				'kept_previous' => true,
				'dry_run'       => ! empty( $input['dry_run'] ),
			)
		);
	}

	// Stops on the line before the meta write, so the field resolution, the per-object capability
	// check and the module's own sanitiser have all run. Reporting the SANITISED value is the point:
	// this is where a caller sees that a javascript: URL became empty or a number was clamped,
	// before committing to it.
	if ( ! empty( $input['dry_run'] ) ) {
		return array(
			'success'      => true,
			'dry_run'      => true,
			'would'        => uich_fields_mcp_ct_same_value( $previous, $value ) ? 'no change' : 'update',
			'field'        => (string) $info['field']['name'],
			'field_type'   => (string) ( $info['field']['type'] ?? '' ),
			'object_type'  => (string) $info['object_type'],
			'object_id'    => $info['object_id'],
			'meta_key'     => $meta_key,
			'stored_value' => $value,
			'sanitised'    => ! uich_fields_mcp_ct_same_value( $input['value'], $value ),
			'sanitized'    => ! uich_fields_mcp_ct_same_value( $input['value'], $value ),
			'message'      => __( 'Dry run: the value passed every check and nothing was written. Compare stored_value with what you sent before re-sending without dry_run.', 'uichemy' ),
		);
	}

	$stored = uich_update_stored_value( $meta_key, $value, $info['object_type'], $info['object_id'] );

	// The field API memoizes per request; without this the same request would keep serving the
	// pre-write value (including for sibling fields whose conditional logic depends on this one).
	_uich_clear_field_request_cache( $field, $object_id );

	if ( ! $stored ) {
		// update_*_meta() returns false when the stored value is already identical, which is a no-op rather than
		// a failure — report it as such instead of as an error.
		if ( uich_fields_mcp_ct_same_value( $previous, $value ) ) {
			return array(
				'success'  => true,
				'field'    => (string) $info['field']['name'],
				'meta_key' => $meta_key,
				'message'  => 'Value already matches; nothing changed.',
			);
		}
		return uich_fields_mcp_ct_fail(
			sprintf( 'Could not write "%s" to %s.', $field, uich_fields_mcp_ct_object_label( $object_id ) )
		);
	}

	$out = array(
		'success'     => true,
		'field'       => (string) $info['field']['name'],
		'type'        => isset( $info['field']['type'] ) ? (string) $info['field']['type'] : 'text',
		'group_key'   => (string) $info['group_key'],
		'object_type' => (string) $info['object_type'],
		'object_id'   => $info['object_id'],
		'meta_key'    => $meta_key,
		'stored'      => $value,
		'message'     => sprintf( 'Value stored for "%s".', (string) $info['field']['name'] ),
	);

	// Tell the caller when sanitizing changed what it sent — a clamped number, a stripped `javascript:` URI, a
	// choice that is not in the field's allowlist, a truncated repeater.
	if ( ! uich_fields_mcp_ct_same_value( $input['value'], $value ) ) {
		$out['sanitized'] = true;
		$out['message']  .= ' The value was adjusted to satisfy the field definition — compare "stored" against what you sent.';
	}

	return $out;
}

/**
 * Whether a sanitized value is materially the same as what the caller sent.
 *
 * @param mixed $sent   Value the caller supplied.
 * @param mixed $stored Value after sanitizing.
 * @return bool
 */
function uich_fields_mcp_ct_same_value( $sent, $stored ): bool {
	if ( is_numeric( $sent ) && is_numeric( $stored ) ) {
		// Tolerance rather than ==: floats that survive a divide-and-multiply round trip can land
		// a hair off, and a fractional-currency field is the common case here.
		return abs( ( (float) $sent ) - ( (float) $stored ) ) < 0.000001;
	}

	if ( is_bool( $stored ) && is_scalar( $sent ) ) {
		// true_false stores a real bool; callers routinely send 1/0/"yes".
		return (bool) filter_var( $sent, FILTER_VALIDATE_BOOLEAN ) === $stored;
	}

	return $sent === $stored;
}

/**
 * Sanitize a value against its field definition using the module's own per-type sanitizer.
 *
 * @param mixed $raw   Value as supplied by the caller.
 * @param array $field Field config.
 * @return mixed Sanitized value, or null when it could not be sanitized.
 */
function uich_fields_mcp_ct_sanitize_value( $raw, array $field ) {
	if ( ! class_exists( 'Uich_Fields_Field_Groups_Render' ) ) {
		return null;
	}

	$renderer = Uich_Fields_Field_Groups_Render::get_instance();
	if ( ! is_object( $renderer ) || ! method_exists( $renderer, 'sanitize_value_for_field' ) ) {
		return null;
	}

	return $renderer->sanitize_value_for_field( $raw, $field );
}

/**
 * Delete one stored field value.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_delete_field_value( array $input ): array {
	$field = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $field ) {
		return uich_fields_mcp_ct_fail( 'field is required, e.g. "isbn".' );
	}

	$object_id = uich_fields_mcp_ct_object_id( isset( $input['object_id'] ) ? $input['object_id'] : 0 );

	$parsed = uich_get_valid_object_id( $object_id );
	$gone   = uich_fields_mcp_ct_missing_object( $parsed['type'], $parsed['id'] );
	if ( null !== $gone ) {
		return $gone;
	}

	$info = uich_maybe_get_field_info( $field, $object_id );
	if ( ! $info ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'No field named "%s" resolves for %s, so there is nothing to delete.',
				$field,
				uich_fields_mcp_ct_object_label( $object_id )
			)
		);
	}

	if ( ! uich_fields_mcp_ct_can_edit_object( (string) $info['object_type'], $info['object_id'] ) ) {
		return uich_fields_mcp_ct_denied( (string) $info['object_type'], $info['object_id'] );
	}

	// Refusing the DELETE matters more than refusing the write: the whole downgrade promise is that
	// a Pro field's stored value survives until the licence returns, and this route would erase it.
	if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( (string) ( $info['field']['type'] ?? '' ) ) ) {
		return uich_fields_mcp_ct_fail(
			sprintf(
				'"%s" is a %s field, and that field type requires UiChemy Pro. Its stored value was left in place rather than deleted. Upgrade at %s',
				(string) $info['field']['name'],
				(string) ( $info['field']['type'] ?? '' ),
				uich_fields_upgrade_url( 'ability-delete-field-value' )
			)
		);
	}

	$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );

	// Reports whether there is anything to delete, which is the one thing worth knowing first: a
	// delete against a field that never held a value is a no-op, not a failure.
	if ( ! empty( $input['dry_run'] ) ) {
		$existing = uich_get_stored_value( $meta_key, $info['object_type'], $info['object_id'], null );
		return array(
			'success'     => true,
			'dry_run'     => true,
			'would'       => ( null === $existing ) ? 'nothing to delete' : 'delete',
			'field'       => (string) $info['field']['name'],
			'object_type' => (string) $info['object_type'],
			'object_id'   => $info['object_id'],
			'meta_key'    => $meta_key,
			'has_value'   => ( null !== $existing ),
			'message'     => __( 'Dry run: nothing was deleted. Re-send without dry_run to apply it.', 'uichemy' ),
		);
	}

	$deleted = uich_delete_stored_value( $meta_key, $info['object_type'], $info['object_id'] );

	_uich_clear_field_request_cache( $field, $object_id );

	return array(
		'success'     => true,
		'deleted'     => (bool) $deleted,
		'field'       => (string) $info['field']['name'],
		'object_type' => (string) $info['object_type'],
		'object_id'   => $info['object_id'],
		'meta_key'    => $meta_key,
		'message'     => $deleted
			? sprintf( 'Stored value for "%s" deleted; the field falls back to its default where one is set.', (string) $info['field']['name'] )
			: sprintf( 'No stored value existed for "%s"; nothing to delete.', (string) $info['field']['name'] ),
	);
}
