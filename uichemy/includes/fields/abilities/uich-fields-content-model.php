<?php
/**
 * Ability: the whole content model in one call, with the operating briefing attached.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// The model changes on every definition write, and a definition saved from the admin UI never passes
// through an ability, so the lifecycle's cache generation would not move for it.
add_filter(
	'uich_fields_ability_cache_ttl',
	static function ( $ttl, $name ) {
		return ( 'uichemy/get-content-model' === $name ) ? 0 : $ttl;
	},
	10,
	2
);

uich_fields_register_ability(
	'uichemy/get-content-model',
	array(
		'label'               => __( 'Get Content Model', 'uichemy' ),
		'description'         => __(
			'The whole Custom Fields model in one call — every post type, taxonomy and field group, with counts and the rules for changing them safely. Call this first.',
			'uichemy',
		),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'briefing' => array(
					'type'        => 'boolean',
					'description' => 'Include the operating rules. Leave it on unless you have already read them this session.',
					'default'     => true,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'counts'        => array( 'type' => 'object' ),
				'post_types'    => array( 'type' => 'array' ),
				'taxonomies'    => array( 'type' => 'array' ),
				'field_groups'  => array( 'type' => 'array' ),
				'briefing'      => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'uich_fields_mcp_get_content_model',
		'meta'                => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'This is the grounding call: run it before anything else so you are working from what the site actually has, not from a guess at the names.',
						'It returns summaries, not full configurations. Read one definition in full with uichemy/get-content-type or uichemy/get-field-group before changing it.',
						'The briefing is generated from the live registry, not written by hand, so the ability names and arguments it lists are the ones this site really accepts.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

/**
 * Execute callback for uichemy/get-content-model.
 *
 * @param array $input Ability input.
 * @return array
 */
function uich_fields_mcp_get_content_model( array $input ): array {
	$result = uich_fields_mcp_ct_rest( 'GET', '/bootstrap' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$data       = is_array( $result['data'] ) ? $result['data'] : array();
	$defs       = isset( $data['cpt_tax_items'] ) && is_array( $data['cpt_tax_items'] ) ? $data['cpt_tax_items'] : array();
	$groups     = isset( $data['field_group_items'] ) && is_array( $data['field_group_items'] ) ? $data['field_group_items'] : array();
	$post_types = array();
	$taxonomies = array();

	foreach ( $defs as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		// Key and active live in the row's configuration, not on the row: read there or every key is ''.
		$kind  = ( 'taxonomy' === ( $row['kind'] ?? '' ) ) ? 'taxonomy' : 'cpt';
		$meta  = ( isset( $row['data'] ) && is_array( $row['data'] ) ) ? $row['data'] : array();
		$entry = array(
			'id'     => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'key'    => uich_fields_mcp_ct_key_of( $meta, $kind ),
			'title'  => isset( $row['title'] ) ? (string) $row['title'] : '',
			'active' => uich_fields_mcp_ct_is_active( $meta ),
		);
		if ( 'taxonomy' === $kind ) {
			$taxonomies[] = $entry;
		} else {
			$post_types[] = $entry;
		}
	}

	$field_groups = array();
	foreach ( $groups as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta           = ( isset( $row['data'] ) && is_array( $row['data'] ) ) ? $row['data'] : array();
		$field_groups[] = array(
			'id'     => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'key'    => uich_fields_mcp_ct_key_of( $meta, 'group_fields' ),
			'title'  => isset( $row['title'] ) ? (string) $row['title'] : '',
			'active' => uich_fields_mcp_ct_is_active( $meta ),
		);
	}

	$out = array(
		'success'      => true,
		'counts'       => array(
			'post_types'   => count( $post_types ),
			'taxonomies'   => count( $taxonomies ),
			'field_groups' => count( $field_groups ),
		),
		'post_types'   => $post_types,
		'taxonomies'   => $taxonomies,
		'field_groups' => $field_groups,
	);

	if ( ! isset( $input['briefing'] ) || ! empty( $input['briefing'] ) ) {
		$out['briefing'] = uich_fields_mcp_content_model_briefing();
	}

	return $out;
}

/**
 * The operating rules, with the volatile parts read from the live registry.
 *
 * Anything that can drift — which writes accept dry_run, which accept if_match — is computed rather
 * than written down, because a briefing that lists an argument the site does not accept sends the
 * caller into a validation error on its first obedient attempt.
 *
 * @return array
 */
function uich_fields_mcp_content_model_briefing(): array {
	$dry_run  = array();
	$if_match = array();
	$writes   = 0;

	foreach ( wp_get_abilities() as $ability ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
			continue;
		}
		$name = $ability->get_name();
		if ( 0 !== strpos( $name, 'uichemy/' ) || ! function_exists( 'uich_fields_ability_mode' ) ) {
			continue;
		}
		if ( 'write' !== uich_fields_ability_mode( $name ) ) {
			continue;
		}
		++$writes;

		$schema = $ability->get_input_schema();
		$props  = ( is_array( $schema ) && isset( $schema['properties'] ) ) ? array_keys( (array) $schema['properties'] ) : array();
		if ( in_array( 'dry_run', $props, true ) ) {
			$dry_run[] = $name;
		}
		if ( in_array( 'if_match', $props, true ) ) {
			$if_match[] = $name;
		}
	}

	sort( $dry_run );
	sort( $if_match );

	return array(
		'read_first'      => 'uichemy/get-content-model',
		'rules'           => array(
			'Send writes one at a time. They are serialised behind a lock, and a second write arriving while one is running is refused with a 409 rather than queued.',
			'Address a definition by the id this call returned. Those ids are the model\'s own, not WordPress post ids, and looking one up as a post finds nothing.',
			'Read a definition in full before changing it. uichemy/update-cpt and uichemy/update-taxonomy REPLACE the stored configuration, so a key you leave out reverts to its default.',
			'uichemy/update-field-group is the exception: it merges. The one part of it that replaces is the fields array, so sending fields omits nothing at your peril — use uichemy/add-field-to-group, uichemy/update-field-in-group, uichemy/delete-field-from-group and uichemy/reorder-fields-in-group to touch one field.',
			'Renaming a group key or a field name is refused once values are stored under it. That refusal is protecting data; relay it rather than working around it.',
			'A read that addresses one record returns config_hash. Send it back as if_match on the write and the write is refused if anything changed in between.',
			'A reversible write returns an undo block: the ability and arguments that put it back. Keep it — nothing else can reconstruct it later.',
			'Several changes that only make sense together go in one uichemy/apply-changes call: one lock, one batch id, one combined undo list in reverse order.',
			'uichemy/list-changes shows recent writes, including refused ones. It is a recent-activity record, not a complete history.',
		),
		'dry_run_writes'  => $dry_run,
		'if_match_writes' => $if_match,
		'write_count'     => $writes,
		'note'            => sprintf(
			/* translators: 1: number of writes accepting dry_run, 2: total write count. */
			__( '%1$d of %2$d writes accept dry_run. The rest apply immediately, so check the payload against a full read first.', 'uichemy' ),
			count( $dry_run ),
			$writes
		),
	);
}
