<?php
/**
 * Abilities: Custom Fields — the file store and the third-party migration.
 *
 * @package Uichemy
 * @since   4.8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// These three reads are never cached: their value changes without any ability being called, and the read cache
// is invalidated only by an ability write's generation bump.
add_filter(
	'uich_fields_ability_cache_ttl',
	static function ( $ttl, $name ) {
		if ( 'uichemy/get-import-scan' === $name
			|| 'uichemy/list-import-sources' === $name
			|| 'uichemy/get-model-status' === $name ) {
			return 0;
		}
		return $ttl;
	},
	10,
	2
);

/**
 * Whether the migration module is present in this build.
 *
 * @return bool
 */
function uich_fields_mcp_ct_import_active(): bool {
	return uich_fields_mcp_ct_module_active() && class_exists( 'Uich_Fields_Import_Manager' );
}

/**
 * GET /model/status — is the file store healthy?
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_get_model_status( array $input ): array {
	unset( $input );
	$result = uich_fields_mcp_ct_rest( 'GET', '/model/status' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$data = is_array( $result['data'] ) ? $result['data'] : array();

	// Lead with a single boolean.
	$healthy = ! empty( $data['writable'] )
		&& empty( $data['problems'] )
		&& empty( $data['differences'] )
		&& empty( $data['last_error'] );

	return array_merge(
		array(
			'success' => true,
			'healthy' => $healthy,
		),
		$data
	);
}

/**
 * POST /model/repair — fix files whose name has drifted from the key inside them.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_repair_model( array $input ): array {
	$dry_run = ! isset( $input['dry_run'] ) || ! empty( $input['dry_run'] );

	$result = uich_fields_mcp_ct_rest( 'POST', '/model/repair', array( 'dry_run' => $dry_run ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge(
		array(
			'success' => true,
			'dry_run' => $dry_run,
		),
		is_array( $result['data'] ) ? $result['data'] : array()
	);
}

/**
 * GET /import/sources — which third-party plugins have something to migrate.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_list_import_sources( array $input ): array {
	unset( $input );
	if ( ! uich_fields_mcp_ct_import_active() ) {
		return uich_fields_mcp_ct_fail( 'The Custom Fields migration module is not available on this site.' );
	}

	$result = uich_fields_mcp_ct_rest( 'GET', '/import/sources' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$data    = is_array( $result['data'] ) ? $result['data'] : array();
	$sources = isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array();

	// detect() only returns sources that are actually present and active — a plugin that is not installed
	// never appears.
	return array(
		'success' => true,
		'total'   => count( $sources ),
		'sources' => $sources,
	);
}

/**
 * GET /import/scan — what a migration from one source would do.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_get_import_scan( array $input ): array {
	if ( ! uich_fields_mcp_ct_import_active() ) {
		return uich_fields_mcp_ct_fail( 'The Custom Fields migration module is not available on this site.' );
	}

	$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : '';
	if ( '' === $source ) {
		return uich_fields_mcp_ct_fail( 'source is required. Call uichemy/list-import-sources for the available slugs.' );
	}

	$result = uich_fields_mcp_ct_rest( 'GET', '/import/scan', array( 'source' => $source ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
}

/**
 * POST /import/run — migrate one source's definitions and values.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_import_from_source( array $input ): array {
	if ( ! uich_fields_mcp_ct_import_active() ) {
		return uich_fields_mcp_ct_fail( 'The Custom Fields migration module is not available on this site.' );
	}

	$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : '';
	if ( '' === $source ) {
		return uich_fields_mcp_ct_fail( 'source is required. Call uichemy/list-import-sources for the available slugs.' );
	}

	// Deactivating the source's own definitions is not reversible from an agent's side without a
	// rollback, so it is opt-in even though the wizard offers it as a checkbox.
	// The import cannot preview itself in place: phase 3 checks field groups against the post
	// types phase 1 just created, so a run that wrote nothing would report failures the real
	// import never hits. The scan is the module's own plan, so dry_run returns that and says so.
	if ( ! empty( $input['dry_run'] ) ) {
		$scan = uich_fields_mcp_ct_rest( 'GET', '/import/scan', array( 'source' => $source ) );
		if ( empty( $scan['success'] ) ) {
			return $scan;
		}
		return uich_fields_dry_run(
			'import',
			array(
				'plan'        => $scan['data'],
				'preview'     => 'scan',
				'checked'     => 'Source availability, every key conflict and its suggested rename, blocked groups, and the number of values that would be copied.',
				'not_checked' => 'Per-definition save refusals, which only the real import can hit, because each phase validates against what the previous one created.',
			)
		);
	}

	$result = uich_fields_mcp_ct_rest(
		'POST',
		'/import/run',
		array(
			'source'         => $source,
			'disable_source' => ! empty( $input['disable_source'] ),
			'import_values'  => ! isset( $input['import_values'] ) || ! empty( $input['import_values'] ),
		)
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
}

if ( uich_fields_mcp_ct_module_active() ) {

	uich_fields_register_ability(
		'uichemy/get-model-status',
		array(
			'label'               => __( 'Get Model Status', 'uichemy' ),
			'description'         => __(
				'Reports whether the Custom Fields file store is writable and in sync, including the last mirror-write error if there was one.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'healthy' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_get_model_status',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Definitions are stored as JSON files, and a failed file write never fails the save — it is recorded and reported here instead.',
							'Check this before reporting a definition write as successful on a site you do not control: healthy false means the database copy and the files disagree.',
							'healthy false with a permission error usually means wp-content is read-only, which is a deployment choice, not a bug.',
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
		'uichemy/list-import-sources',
		array(
			'label'               => __( 'List Import Sources', 'uichemy' ),
			'description'         => __(
				'Lists the third-party field plugins Custom Fields can migrate from (ACF / Secure Custom Fields, Meta Box, Pods, JetEngine) and whether each has anything to migrate on this site.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'total'   => array( 'type' => 'integer' ),
					'sources' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_list_import_sources',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Use the "slug" of a source, not its label, for uichemy/get-import-scan and uichemy/import-from-source.',
							'Only sources that are installed and active are listed, so anything returned here can be scanned.',
							'"acf" covers both Advanced Custom Fields and Secure Custom Fields; they share the same storage.',
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
		'uichemy/get-import-scan',
		array(
			'label'               => __( 'Get Import Scan', 'uichemy' ),
			'description'         => __(
				'Reports exactly what a migration from one source would create — post types, taxonomies, field groups and how many values — without changing anything.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source' => array(
						'type'        => 'string',
						'description' => 'Source slug from uichemy/list-import-sources, e.g. "acf", "metabox", "pods", "jetengine".',
					),
				),
				'required'             => array( 'source' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_get_import_scan',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Always scan before importing, and show the user the plan — the import copies definitions AND values, and a large site can take a while.',
							'"warnings" in the plan are items that will be skipped, with the reason. Read them out rather than summarising them away.',
							'This result is never cached, because it reflects the source plugin\'s own data, which changes outside this module.',
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
		'uichemy/repair-model',
		array(
			'label'               => __( 'Repair Model Files', 'uichemy' ),
			'description'         => __(
				'Repairs Custom Fields definition files whose filename has drifted from the key stored inside them. Defaults to a dry run.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Report what would be repaired without touching anything. Defaults to true.',
						'default'     => true,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'dry_run' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_repair_model',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Run with dry_run true first and show the user the list. Only then run with dry_run false.',
							'The key INSIDE the file is authoritative; the filename is a derived cache of it. A repair renames the file, never the key.',
							'Two files claiming the same key are both refused and neither is repaired — that needs a human to decide which one to keep.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	uich_fields_register_ability(
		'uichemy/import-from-source',
		array(
			'label'               => __( 'Import From Source', 'uichemy' ),
			'description'         => __(
				'Migrates one third-party source (ACF / Secure Custom Fields, Meta Box, Pods or JetEngine) into Custom Fields, copying its post types, taxonomies, field groups and stored values.',
				'uichemy'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'         => array(
						'type'        => 'string',
						'description' => 'Source slug from uichemy/list-import-sources.',
					),
					'disable_source' => array(
						'type'        => 'boolean',
						'description' => 'Deactivate the source plugin\'s own definitions afterwards, so they stop competing. Defaults to false.',
						'default'     => false,
					),
					'import_values'  => array(
						'type'        => 'boolean',
						'description' => 'Copy stored field values as well as the definitions. Defaults to true.',
						'default'     => true,
					),
					'dry_run'        => array(
						'type'        => 'boolean',
						'description' => 'Return the import plan — every key conflict, suggested rename, blocked group and the value count — without importing. The response states what the plan could not check.',
						'default'     => false,
					),
				),
				'required'             => array( 'source' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'uich_fields_mcp_import_from_source',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Call uichemy/get-import-scan first and get the user to agree to the plan. This writes definitions and values.',
							'The migration COPIES; it does not move. The source plugin keeps its own definitions unless disable_source is true.',
							'Re-running is an upsert on the source item, not a duplicate — but it will overwrite UiChemy-side edits made since the last run.',
							'Report the "errors" and "warnings" arrays verbatim; a partial import leaves the source active on purpose.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
				),
			),
		)
	);
}
