<?php
/**
 * Abilities: Custom Fields — scaffold a complete content model in one call.
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
		'uichemy/scaffold-content-model',
		array(
			'label'               => __( 'Scaffold Content Model', 'uichemy' ),
			'description'         => __(
				'Creates a complete Custom Fields content model in one atomic call — a custom post type, its taxonomies, and its field groups — wiring the relationships between them and rolling everything back if any step fails.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'model'   => array(
						'type'        => 'object',
						'description' => 'The content model to build. Every section is optional, but at least one must be present.',
						'properties'  => array(
							'post_type'    => array(
								'type'        => 'object',
								'description' => 'Post type configuration. Requires post_type_key and label.',
							),
							'taxonomies'   => array(
								'type'        => 'array',
								'description' => 'Taxonomies to create. Each requires taxonomy_key and label. object_type defaults to the post type created in this same call.',
								'items'       => array( 'type' => 'object' ),
							),
							'field_groups' => array(
								'type'        => 'array',
								'description' => 'Field groups to create. Each requires title and fields. location_rules defaults to the post type created in this same call.',
								'items'       => array( 'type' => 'object' ),
							),
						),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Validate and return the plan without writing anything.',
						'default'     => false,
					),
				),
				'required'             => array( 'model' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'uich_fields_mcp_scaffold_content_model',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Use this instead of chaining create-cpt + create-taxonomy + create-field-group by hand. It handles dependency order and rolls back on failure; chaining by hand does neither.',
							'Order is handled for you: the post type is created first so taxonomies and field groups can reference it before WordPress has registered anything.',
							'Omit object_type on a taxonomy and location_rules on a field group to have them wired to the post type created in the same call.',
							'Run with dry_run true first when the model came from a vague instruction — it returns the full plan (keys, labels, field names) so the user can correct it before anything is written.',
							'post_type_key must be 1-20 characters; taxonomy_key 1-32. Both are lowercase letters, numbers, underscores and dashes only, and neither may be a WordPress reserved key.',
							'Field types must come from uichemy/list-field-types. A repeater nests its subfields under "fields", never "sub_fields".',
							'If any step fails the whole model is removed and the response reports which step failed and why. Fix that step and call again — nothing is left behind to clean up.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
				),
			),
		)
	);
}

/**
 * Build a complete content model: post type, then taxonomies, then field groups.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_scaffold_content_model( array $input ): array {
	$model = ( isset( $input['model'] ) && is_array( $input['model'] ) ) ? $input['model'] : array();
	if ( empty( $model ) ) {
		return uich_fields_mcp_ct_fail( 'model is required and must contain at least one of post_type, taxonomies or field_groups.' );
	}

	$post_type    = ( isset( $model['post_type'] ) && is_array( $model['post_type'] ) ) ? $model['post_type'] : array();
	$taxonomies   = ( isset( $model['taxonomies'] ) && is_array( $model['taxonomies'] ) ) ? $model['taxonomies'] : array();
	$field_groups = ( isset( $model['field_groups'] ) && is_array( $model['field_groups'] ) ) ? $model['field_groups'] : array();

	if ( empty( $post_type ) && empty( $taxonomies ) && empty( $field_groups ) ) {
		return uich_fields_mcp_ct_fail( 'model contained nothing to create. Provide post_type, taxonomies or field_groups.' );
	}

	$cpt_key = '';
	if ( ! empty( $post_type ) ) {
		if ( empty( $post_type['post_type_key'] ) || empty( $post_type['label'] ) ) {
			return uich_fields_mcp_ct_fail( 'model.post_type requires post_type_key and label.' );
		}
		$cpt_key = (string) $post_type['post_type_key'];
	}

	// Resolve the wiring BEFORE writing anything, so a dry run reports exactly what a real run does.
	$taxonomies   = uich_fields_mcp_scaffold_wire_taxonomies( $taxonomies, $cpt_key );
	$field_groups = uich_fields_mcp_scaffold_wire_groups( $field_groups, $cpt_key );

	$plan = uich_fields_mcp_scaffold_plan( $post_type, $taxonomies, $field_groups );

	// Every item goes through its own save's dry run before the first write, so a model that would
	// fail on its last step is refused while there is still nothing to roll back.
	$problems = uich_fields_mcp_scaffold_check( $post_type, $taxonomies, $field_groups, $cpt_key );
	if ( ! empty( $problems ) ) {
		return uich_fields_mcp_ct_fail(
			'The model was refused and nothing was written. ' . implode( ' ', $problems ),
			array(
				'problems' => $problems,
				'plan'     => $plan,
			)
		);
	}

	if ( ! empty( $input['dry_run'] ) ) {
		return array(
			'success' => true,
			'dry_run' => true,
			'plan'    => $plan,
			'message' => sprintf( 'Dry run: %d item(s) passed their checks and would be created. Nothing was written.', count( $plan ) ),
		);
	}

	// Every definition post created by this call, so a later failure can undo the earlier successes.
	$created = array();
	$steps   = array();

	// 1. Post type first: a taxonomy's object_type and a group's location rules may reference it, and both accept a
	// pending UiChemy CPT definition (known_or_pending_post_type).
	if ( ! empty( $post_type ) ) {
		$post_type['kind'] = 'cpt';

		$result = uich_fields_mcp_ct_rest(
			'POST',
			'/cpt',
			array(
				'id'   => 0,
				'data' => $post_type,
			)
		);
		if ( empty( $result['success'] ) ) {
			return uich_fields_mcp_scaffold_abort( $created, 'post_type', $cpt_key, $result, $steps );
		}

		$id        = isset( $result['data']['id'] ) ? (int) $result['data']['id'] : 0;
		$created[] = $id;
		$steps[]   = array(
			'step'   => 'post_type',
			'key'    => $cpt_key,
			'id'     => $id,
			'status' => 'created',
		);
	}

	// 2. Taxonomies.
	foreach ( $taxonomies as $taxonomy ) {
		if ( empty( $taxonomy['taxonomy_key'] ) || empty( $taxonomy['label'] ) ) {
			return uich_fields_mcp_scaffold_abort(
				$created,
				'taxonomy',
				isset( $taxonomy['taxonomy_key'] ) ? (string) $taxonomy['taxonomy_key'] : '(unnamed)',
				uich_fields_mcp_ct_fail( 'Each taxonomy requires taxonomy_key and label.' ),
				$steps
			);
		}

		$taxonomy['kind'] = 'taxonomy';

		$result = uich_fields_mcp_ct_rest(
			'POST',
			'/taxonomy',
			array(
				'id'   => 0,
				'data' => $taxonomy,
			)
		);
		if ( empty( $result['success'] ) ) {
			return uich_fields_mcp_scaffold_abort( $created, 'taxonomy', (string) $taxonomy['taxonomy_key'], $result, $steps );
		}

		$id        = isset( $result['data']['id'] ) ? (int) $result['data']['id'] : 0;
		$created[] = $id;
		$steps[]   = array(
			'step'   => 'taxonomy',
			'key'    => (string) $taxonomy['taxonomy_key'],
			'id'     => $id,
			'status' => 'created',
		);
	}

	// 3. Field groups.
	foreach ( $field_groups as $group ) {
		$title = isset( $group['title'] ) ? (string) $group['title'] : '';
		if ( '' === trim( $title ) ) {
			return uich_fields_mcp_scaffold_abort( $created, 'field_group', '(untitled)', uich_fields_mcp_ct_fail( 'Each field group requires a title.' ), $steps );
		}

		// Reuse the field-type allowlist the standalone ability enforces, so a typo fails here with
		// the same message rather than silently degrading to a text input at render time.
		if ( function_exists( 'uich_fields_mcp_ct_invalid_field_types' ) ) {
			$invalid = uich_fields_mcp_ct_invalid_field_types( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() );
			if ( ! empty( $invalid ) ) {
				return uich_fields_mcp_scaffold_abort(
					$created,
					'field_group',
					$title,
					uich_fields_mcp_ct_fail( sprintf( 'Unknown field type(s): %s. Call uichemy/list-field-types for the supported list.', implode( ', ', $invalid ) ) ),
					$steps
				);
			}
		}

		$result = uich_fields_mcp_ct_rest( 'POST', '/field-groups', array( 'data' => $group ) );
		if ( empty( $result['success'] ) ) {
			return uich_fields_mcp_scaffold_abort( $created, 'field_group', $title, $result, $steps );
		}

		$id        = isset( $result['data']['id'] ) ? (int) $result['data']['id'] : 0;
		$created[] = $id;
		$steps[]   = array(
			'step'   => 'field_group',
			// The stored key: it is generated when none was sent, and suffixed when the one sent was taken.
			'key'    => isset( $result['data']['data']['key'] ) ? (string) $result['data']['data']['key'] : '',
			'title'  => $title,
			'id'     => $id,
			'status' => 'created',
		);
	}

	return array(
		'success'    => true,
		'created'    => $steps,
		'post_type'  => $cpt_key,
		'next_steps' => array(
			'The definitions are saved. WordPress registers them on the next request (init:15), and rewrite rules flush once on the request after that.',
			'Verify with uichemy/get-content-types-bootstrap, or post_type_exists()/taxonomy_exists() on the next load.',
		),
		'message'    => sprintf( '%d item(s) created.', count( $steps ) ),
	);
}

/**
 * Run each item of a model through its own save's dry run, without writing anything.
 *
 * @param array  $post_type    Post type configuration.
 * @param array  $taxonomies   Taxonomy configurations, already wired.
 * @param array  $field_groups Field group configurations, already wired.
 * @param string $cpt_key      Post type key created in this call, or '' when none.
 * @return string[] One sentence per refused item, empty when the model is sound.
 */
function uich_fields_mcp_scaffold_check( array $post_type, array $taxonomies, array $field_groups, string $cpt_key ): array {
	$problems = array();
	$reason   = static function ( array $result ): string {
		return isset( $result['error'] ) ? (string) $result['error'] : 'It was refused.';
	};

	if ( ! empty( $post_type ) ) {
		$result = uich_fields_mcp_create_cpt( array( 'data' => $post_type, 'dry_run' => true ) );
		if ( empty( $result['success'] ) ) {
			$problems[] = sprintf( 'Post type "%s": %s', $cpt_key, $reason( $result ) );
		}
	}

	foreach ( $taxonomies as $taxonomy ) {
		if ( ! is_array( $taxonomy ) || empty( $taxonomy['taxonomy_key'] ) || empty( $taxonomy['label'] ) ) {
			$problems[] = 'Each taxonomy requires taxonomy_key and label.';
			continue;
		}

		// The post type from this same call is not saved yet, so the taxonomy save would refuse it as
		// unknown. Swapped for one that exists: it is the taxonomy's own rules being checked here.
		$probe = $taxonomy;
		if ( '' !== $cpt_key && isset( $probe['object_type'] ) && is_array( $probe['object_type'] ) ) {
			foreach ( $probe['object_type'] as $index => $object_type ) {
				if ( $cpt_key === $object_type ) {
					$probe['object_type'][ $index ] = 'post';
				}
			}
		}

		$result = uich_fields_mcp_create_taxonomy( array( 'data' => $probe, 'dry_run' => true ) );
		if ( empty( $result['success'] ) ) {
			$problems[] = sprintf( 'Taxonomy "%s": %s', (string) $taxonomy['taxonomy_key'], $reason( $result ) );
		}
	}

	foreach ( $field_groups as $group ) {
		if ( ! is_array( $group ) ) {
			$problems[] = 'Each field group must be an object with a title.';
			continue;
		}

		$result = uich_fields_mcp_create_field_group( array( 'data' => $group, 'dry_run' => true ) );
		if ( empty( $result['success'] ) ) {
			$problems[] = sprintf( 'Field group "%s": %s', isset( $group['title'] ) ? (string) $group['title'] : '(untitled)', $reason( $result ) );
		}
	}

	return $problems;
}

/**
 * Default each taxonomy's object_type to the post type being created in the same call, so the
 * caller does not have to repeat the key it just supplied.
 *
 * @param array  $taxonomies Taxonomy configurations.
 * @param string $cpt_key    Post type key created in this call, or '' when none.
 * @return array
 */
function uich_fields_mcp_scaffold_wire_taxonomies( array $taxonomies, string $cpt_key ): array {
	if ( '' === $cpt_key ) {
		return $taxonomies;
	}
	foreach ( $taxonomies as $index => $taxonomy ) {
		if ( ! is_array( $taxonomy ) ) {
			continue;
		}
		if ( empty( $taxonomy['object_type'] ) ) {
			$taxonomies[ $index ]['object_type'] = array( $cpt_key );
		}
	}
	return $taxonomies;
}

/**
 * Default each field group's location rules to the post type being created in the same call.
 *
 * @param array  $groups  Field group configurations.
 * @param string $cpt_key Post type key created in this call, or '' when none.
 * @return array
 */
function uich_fields_mcp_scaffold_wire_groups( array $groups, string $cpt_key ): array {
	if ( '' === $cpt_key ) {
		return $groups;
	}
	foreach ( $groups as $index => $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}
		if ( empty( $group['location_rules'] ) ) {
			$groups[ $index ]['location_rules'] = array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $cpt_key,
				),
			);
		}
	}
	return $groups;
}

/**
 * Human-readable plan of what would be created, used by dry_run and by the failure report.
 *
 * @param array $post_type    Post type configuration.
 * @param array $taxonomies   Taxonomy configurations.
 * @param array $field_groups Field group configurations.
 * @return array
 */
function uich_fields_mcp_scaffold_plan( array $post_type, array $taxonomies, array $field_groups ): array {
	$plan = array();

	if ( ! empty( $post_type ) ) {
		$plan[] = array(
			'step'  => 'post_type',
			'key'   => isset( $post_type['post_type_key'] ) ? (string) $post_type['post_type_key'] : '',
			'label' => isset( $post_type['label'] ) ? (string) $post_type['label'] : '',
		);
	}

	foreach ( $taxonomies as $taxonomy ) {
		if ( ! is_array( $taxonomy ) ) {
			continue;
		}
		$plan[] = array(
			'step'         => 'taxonomy',
			'key'          => isset( $taxonomy['taxonomy_key'] ) ? (string) $taxonomy['taxonomy_key'] : '',
			'label'        => isset( $taxonomy['label'] ) ? (string) $taxonomy['label'] : '',
			'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
			'object_type'  => isset( $taxonomy['object_type'] ) ? (array) $taxonomy['object_type'] : array(),
		);
	}

	foreach ( $field_groups as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}
		$names = array();
		if ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) {
			foreach ( $group['fields'] as $field ) {
				if ( is_array( $field ) && ! empty( $field['name'] ) ) {
					$names[] = (string) $field['name'] . ' (' . ( isset( $field['type'] ) ? (string) $field['type'] : 'text' ) . ')';
				}
			}
		}
		$plan[] = array(
			'step'   => 'field_group',
			'title'  => isset( $group['title'] ) ? (string) $group['title'] : '',
			'fields' => $names,
		);
	}

	return $plan;
}

/**
 * Undo every definition created so far and report which step failed.
 *
 * @param int[]  $created      Definition post IDs created so far.
 * @param string $step         Step that failed.
 * @param string $key          Key/title of the failing item.
 * @param array  $result       Failure payload from the REST helper.
 * @param array  $steps        Steps completed before the failure.
 * @return array
 */
function uich_fields_mcp_scaffold_abort( array $created, string $step, string $key, array $result, array $steps ): array {
	$rolled_back = 0;
	$left_behind = array();
	foreach ( array_reverse( $created ) as $definition_id ) {
		$definition_id = (int) $definition_id;
		if ( $definition_id <= 0 ) {
			continue;
		}
		// Never wp_delete_post(): a definition id is synthetic, so that call removes nothing here —
		// or permanently deletes an unrelated post that happens to carry the same number.
		$removed = Uich_Fields_Admin_Logic::delete_definition_permanently( $definition_id );
		if ( is_wp_error( $removed ) ) {
			$left_behind[] = $definition_id;
		} else {
			++$rolled_back;
		}
	}

	if ( class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
		Uich_Fields_Definitions_Repository::get_instance()->flush();
	}

	$error = isset( $result['error'] ) ? (string) $result['error'] : 'Unknown error.';

	return array(
		'success'       => false,
		'failed_step'   => $step,
		'failed_item'   => $key,
		'error'         => sprintf( 'Scaffold failed at %s "%s": %s', $step, $key, $error ),
		'completed'     => $steps,
		'rolled_back'   => $rolled_back,
		// Named, because "rolled back" with something still on the site is the claim that hides a half-built model.
		'left_behind'   => $left_behind,
		'rollback_note' => uich_fields_mcp_scaffold_rollback_note( $rolled_back, $left_behind ),
	);
}

/**
 * What the rollback actually achieved, in one sentence.
 *
 * @param int   $rolled_back Definitions removed.
 * @param int[] $left_behind Definition ids that could not be removed.
 * @return string
 */
function uich_fields_mcp_scaffold_rollback_note( int $rolled_back, array $left_behind ): string {
	if ( ! empty( $left_behind ) ) {
		return sprintf(
			'%1$d already-created item(s) were removed, but %2$d could not be and are still on the site (definition ids: %3$s). Remove them with uichemy/delete-content-type before calling again.',
			$rolled_back,
			count( $left_behind ),
			implode( ', ', $left_behind )
		);
	}

	return $rolled_back > 0
		? sprintf( '%d already-created item(s) were removed, so nothing partial was left behind. Fix the reported step and call again.', $rolled_back )
		: 'Nothing had been created yet, so there was nothing to roll back.';
}
