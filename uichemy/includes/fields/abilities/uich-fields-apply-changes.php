<?php
/**
 * Ability: run several UiChemy writes as one ordered, locked, reversible batch.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

uich_fields_register_ability(
	'uichemy/apply-changes',
	array(
		'label'               => __( 'Apply Changes as One Batch', 'uichemy' ),
		'description'         => __(
			'Runs several UiChemy write abilities in the order given, under a single lock and a single batch id, and returns one combined undo list.',
			'uichemy',
		),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'steps'         => array(
					'type'        => 'array',
					'description' => 'The writes to run, in order.',
					'minItems'    => 1,
					'maxItems'    => 25,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'ability' => array(
								'type'        => 'string',
								'description' => 'A uichemy/* write ability name. Nesting uichemy/apply-changes is refused.',
							),
							'input'   => array(
								'type'        => 'object',
								'description' => 'The arguments for that ability.',
							),
							'label'   => array(
								'type'        => 'string',
								'description' => 'Optional name for this step, echoed back in the result.',
							),
						),
						'required'             => array( 'ability' ),
						'additionalProperties' => false,
					),
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => 'Check every step without storing anything. Refused unless every step supports a dry run.',
					'default'     => false,
				),
				'stop_on_error' => array(
					'type'        => 'boolean',
					'description' => 'Stop at the first failing step. Turn it off only when the steps are genuinely independent.',
					'default'     => true,
				),
			),
			'required'             => array( 'steps' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'batch_id'  => array( 'type' => 'string' ),
				'ran'       => array( 'type' => 'integer' ),
				'persisted' => array( 'type' => 'boolean' ),
				'steps'     => array( 'type' => 'array' ),
				'undo'      => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => 'uich_fields_mcp_apply_changes',
		'meta'                => array(
			'mode'           => 'write',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => false ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Use this when several changes only make sense together — a post type plus the taxonomy that classifies it, or a field group plus its fields.',
						'Steps run in the order given, so put the thing others depend on first. A definition must exist before anything can reference it.',
						'The undo list comes back in REVERSE order: replay it top to bottom and the batch unwinds correctly. Undoing forwards will fail or restore the wrong state.',
						'If a step fails, "persisted" tells you whether earlier steps already landed. When it is true the site has been changed and the undo list is the only way back — do not retry the whole batch blindly.',
						'dry_run is refused unless every step supports it, because a partial dry run would store some of the batch while reporting a preview.',
						'Each step is authorised on its own, and a step the site does not expose to assistants is refused, so this cannot reach an ability the caller could not call directly.',
					)
				),
				'readonly'     => false,
				'destructive'  => true,
				'idempotent'   => false,
			),
		),
	)
);

/**
 * Execute callback for uichemy/apply-changes.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function uich_fields_mcp_apply_changes( array $input ) {
	$steps   = isset( $input['steps'] ) && is_array( $input['steps'] ) ? array_values( $input['steps'] ) : array();
	$dry_run = ! empty( $input['dry_run'] );
	$stop    = ! isset( $input['stop_on_error'] ) || ! empty( $input['stop_on_error'] );

	if ( empty( $steps ) ) {
		return uich_fields_mcp_apply_changes_fail( 'steps is required and must contain at least one write.' );
	}

	// wp_get_ability() on a name that is not registered emits _doing_it_wrong, so one typo in a step
	// would put a PHP notice in the site's error log. Check the registry list instead.
	$registered = array();
	foreach ( wp_get_abilities() as $known ) {
		if ( is_object( $known ) && method_exists( $known, 'get_name' ) ) {
			$registered[] = $known->get_name();
		}
	}

	// When the batch itself is exposed to MCP, every step must be exposed too. Steps run through
	// execute(), which never consults meta.mcp.public, so without this an exposed batch would reach
	// writes the site deliberately closed (a permanent delete, an import) one level down.
	$batch_ability = wp_get_ability( 'uichemy/apply-changes' );
	$batch_meta    = ( $batch_ability instanceof WP_Ability ) ? $batch_ability->get_meta() : array();
	$batch_public  = ! empty( $batch_meta['mcp']['public'] );

	// Validate the whole plan before running any of it. A batch that fails on step 4 for a reason
	// visible at step 0 has changed the site for nothing.
	$plan = array();
	foreach ( $steps as $i => $step ) {
		$name = is_array( $step ) && isset( $step['ability'] ) ? (string) $step['ability'] : '';

		if ( 'uichemy/apply-changes' === $name ) {
			return uich_fields_mcp_apply_changes_fail( sprintf( 'Step %d is uichemy/apply-changes. A batch cannot contain itself.', $i + 1 ) );
		}

		if ( '' === $name || ! in_array( $name, $registered, true ) ) {
			return uich_fields_mcp_apply_changes_fail( sprintf( 'Step %d names "%s", which is not a registered ability.', $i + 1, $name ) );
		}

		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return uich_fields_mcp_apply_changes_fail( sprintf( 'Step %d names "%s", which could not be loaded.', $i + 1, $name ) );
		}
		if ( 0 !== strpos( $name, 'uichemy/' ) || 'write' !== uich_fields_ability_mode( $name ) ) {
			return uich_fields_mcp_apply_changes_fail( sprintf( 'Step %d is "%s", which is not a UiChemy write ability.', $i + 1, $name ) );
		}

		$step_meta = $ability->get_meta();
		if ( $batch_public && empty( $step_meta['mcp']['public'] ) ) {
			return uich_fields_mcp_apply_changes_fail(
				sprintf(
					'Step %d is "%s", which this site does not expose to assistants. A batch cannot reach a write that could not be called directly, so nothing was run.',
					$i + 1,
					$name
				)
			);
		}

		$args = ( isset( $step['input'] ) && is_array( $step['input'] ) ) ? $step['input'] : array();

		if ( $dry_run ) {
			$schema = $ability->get_input_schema();
			$props  = ( is_array( $schema ) && isset( $schema['properties'] ) ) ? array_keys( (array) $schema['properties'] ) : array();
			if ( ! in_array( 'dry_run', $props, true ) ) {
				return uich_fields_mcp_apply_changes_fail(
					sprintf(
						'Step %d is "%s", which cannot be dry run. Running the batch as a dry run would store that step for real while reporting a preview, so the whole batch is refused. Drop dry_run, or drop that step.',
						$i + 1,
						$name
					)
				);
			}
			$args['dry_run'] = true;
		}

		// Schema-check every step now. Left to execute(), a malformed step 3 is only found after
		// steps 1 and 2 have been stored.
		$valid = $ability->validate_input( $ability->normalize_input( $args ) );
		if ( is_wp_error( $valid ) ) {
			return uich_fields_mcp_apply_changes_fail(
				sprintf( 'Step %d ("%s") has invalid input, so nothing was run. %s', $i + 1, $name, $valid->get_error_message() )
			);
		}

		$plan[] = array(
			'index'   => $i,
			'name'    => $name,
			'label'   => isset( $step['label'] ) ? (string) $step['label'] : '',
			'ability' => $ability,
			'input'   => $args,
		);
	}

	$batch_id = 'b' . substr( md5( uniqid( 'uich', true ) ), 0, 12 );

	// One lock for the whole batch. The per-write lock is re-entrant, so each step's own acquire
	// nests inside this one instead of deadlocking against it.
	$lock = uich_fields_ability_lock( 'acquire', 'uichemy/apply-changes' );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	uich_fields_ability_batch( $batch_id );

	$results   = array();
	$undo      = array();
	$persisted = false;
	$failed    = false;

	try {
		foreach ( $plan as $step ) {
			$result = $step['ability']->execute( $step['input'] );

			$ok = ! is_wp_error( $result )
				&& ( ! is_array( $result ) || ! array_key_exists( 'success', $result ) || ! empty( $result['success'] ) );

			$entry = array(
				'step'    => $step['index'] + 1,
				'ability' => $step['name'],
				'ok'      => $ok,
			);
			if ( '' !== $step['label'] ) {
				$entry['label'] = $step['label'];
			}
			if ( is_wp_error( $result ) ) {
				$entry['error'] = $result->get_error_code();
				$entry['message'] = $result->get_error_message();
			} elseif ( is_array( $result ) ) {
				$entry['result'] = $result;
			}

			$results[] = $entry;

			if ( $ok ) {
				// A dry run stores nothing, so it neither persists nor produces an undo.
				if ( ! $dry_run ) {
					$persisted = true;
					if ( is_array( $result ) && isset( $result['undo'] ) && is_array( $result['undo'] ) ) {
						// Newest first: replaying this list top to bottom unwinds the batch in the
						// reverse of the order it was applied, which is the only order that works.
						array_unshift( $undo, array_merge( array( 'step' => $step['index'] + 1 ), $result['undo'] ) );
					}
				}
				continue;
			}

			$failed = true;
			if ( $stop ) {
				break;
			}
		}
	} finally {
		uich_fields_ability_batch( '' );
		uich_fields_ability_lock( 'release', 'uichemy/apply-changes' );
	}

	$ran = count( $results );

	return array(
		'success'   => ! $failed,
		'batch_id'  => $batch_id,
		'dry_run'   => $dry_run,
		'ran'       => $ran,
		'total'     => count( $plan ),
		// The flag that stops a caller assuming a failed batch changed nothing.
		'persisted' => $persisted,
		'steps'     => $results,
		'undo'      => $undo,
		'message'   => uich_fields_mcp_apply_changes_message( $failed, $persisted, $dry_run, $ran, count( $plan ), $batch_id ),
	);
}

/**
 * One-line summary for a batch result.
 *
 * @param bool   $failed    Whether any step failed.
 * @param bool   $persisted Whether anything was stored.
 * @param bool   $dry_run   Whether this was a dry run.
 * @param int    $ran       Steps attempted.
 * @param int    $total     Steps planned.
 * @param string $batch_id  Batch id.
 * @return string
 */
function uich_fields_mcp_apply_changes_message( bool $failed, bool $persisted, bool $dry_run, int $ran, int $total, string $batch_id ): string {
	if ( $dry_run ) {
		return $failed
			? sprintf( 'Dry run stopped at step %d of %d. Nothing was stored.', $ran, $total )
			: sprintf( 'Dry run: all %d steps passed their checks and nothing was stored.', $total );
	}

	if ( ! $failed ) {
		return sprintf( 'All %d steps applied as batch %s.', $total, $batch_id );
	}

	return $persisted
		? sprintf( 'Step %d of %d failed AFTER earlier steps were stored. The site has changed — use the undo list, in the order given, to roll batch %s back.', $ran, $total, $batch_id )
		: sprintf( 'Step %d of %d failed and nothing was stored.', $ran, $total );
}

/**
 * A refusal that happened before anything ran.
 *
 * @param string $message Why.
 * @return array
 */
function uich_fields_mcp_apply_changes_fail( string $message ): array {
	return array(
		'success'   => false,
		'persisted' => false,
		'error'     => $message,
	);
}
