<?php
/**
 * Ability: List the recent write activity recorded by the abilities lifecycle.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// The journal grows on every ability call, including refused ones — and a refused write changes
// nothing, so it correctly does not bump the lifecycle cache version. Caching this read would then
// keep serving a list from before the refusal, which is precisely the entry someone came to find.
add_filter(
	'uich_fields_ability_cache_ttl',
	static function ( $ttl, $name ) {
		return ( 'uichemy/list-changes' === $name ) ? 0 : $ttl;
	},
	10,
	2
);

uich_fields_register_ability(
	'uichemy/list-changes',
	array(
		'label'               => __( 'List Recent Changes', 'uichemy' ),
		'description'         => __(
			'Lists the most recent writes made through UiChemy abilities: which ability ran, who ran it, what it addressed, and whether it succeeded. Newest first.',
			'uichemy',
		),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'         => array(
					'type'        => 'integer',
					'description' => 'How many entries to return, newest first.',
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 20,
				),
				'ability'       => array(
					'type'        => 'string',
					'description' => 'Return only entries for this ability, e.g. "uichemy/update-cpt".',
				),
				'only_failures' => array(
					'type'        => 'boolean',
					'description' => 'Return only writes that did not succeed.',
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'total'      => array( 'type' => 'integer' ),
				'retained'   => array( 'type' => 'integer' ),
				'dropped'    => array( 'type' => 'integer' ),
				'truncated'  => array( 'type' => 'boolean' ),
				'oldest'     => array( 'type' => 'integer' ),
				'oldest_gmt' => array( 'type' => 'string' ),
				'store'      => array( 'type' => 'string' ),
				'changes'    => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => 'uich_fields_mcp_list_changes',
		'meta'                => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Activity is kept in its own table for a retention window (90 days by default), so "oldest_gmt" is how far back this can answer. "dropped" counts entries removed by that window; when it is above zero, "truncated" is true and this is no longer a complete history.',
						'"store" says which store answered. On a site where the table could not be created it falls back to an option holding only the most recent 200 entries, and "dropped" is -1 because that store cannot count what it lost.',
						'Refused writes are recorded too. A burst of failures from one ability is itself worth reporting to the user.',
						'Entries record hashes, never the payload, so a change cannot be reconstructed from here. Keep the "undo" block a write returned if you may need to reverse it.',
						'hash_before and hash_after are the same config_hash a read returns (for a field-value write, a hash of the stored value), so an unchanged pair means the write landed without altering anything.',
						'"object" is the object a field-value write addressed (post:88, user:5, term:9, comment:3, option) and "subject" is the field or definition key. "dry_run" marks a preview: it changed nothing, and changed_data is always false for it.',
						'Only writes made through an ability appear. Edits made in the WordPress admin do not pass through this layer and are not recorded.',
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
 * Execute callback for the uichemy/list-changes ability.
 *
 * @param array $input Ability input arguments.
 * @return array
 */
function uich_fields_mcp_list_changes( array $input ): array {
	if ( ! function_exists( 'uich_fields_ability_journal' ) ) {
		return array(
			'success' => false,
			'error'   => 'The abilities lifecycle is not loaded, so no write activity is being recorded.',
		);
	}

	$limit   = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
	$limit   = max( 1, min( 200, $limit ) );
	$ability = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
	$only_ko = ! empty( $input['only_failures'] );

	$table = class_exists( 'Uich_Fields_Ability_Journal' ) && Uich_Fields_Ability_Journal::table_ready();

	if ( $table ) {
		// Filtering and limiting happen in SQL. The option store had to load everything and filter
		// in PHP, which is why its `total` could only ever describe what it still held.
		$result   = Uich_Fields_Ability_Journal::query(
			array(
				'limit'         => $limit,
				'ability'       => $ability,
				'only_failures' => $only_ko,
			)
		);
		$recent   = $result['rows'];
		$total    = $result['total'];
		$stats    = Uich_Fields_Ability_Journal::stats();
		$retained = $stats['retained'];
		$dropped  = $stats['dropped'];
		$oldest   = $stats['oldest'];
	} else {
		$entries = uich_fields_ability_journal( 'list' );
		$max     = defined( 'UICH_FIELDS_JOURNAL_MAX' ) ? (int) UICH_FIELDS_JOURNAL_MAX : 200;

		// Filter first, then take the newest slice: filtering after slicing would return fewer than
		// $limit matches while older matching entries were still being held.
		$matched = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' !== $ability && $ability !== ( $entry['ability'] ?? '' ) ) {
				continue;
			}
			if ( $only_ko && ! empty( $entry['ok'] ) ) {
				continue;
			}
			$matched[] = $entry;
		}

		$total    = count( $matched );
		$recent   = array_reverse( array_slice( $matched, -$limit ) );
		$retained = count( $entries );
		// The option store cannot count what it dropped; a full store is the only signal it has.
		$dropped = ( $retained >= $max ) ? -1 : 0;
		$oldest  = $entries ? (int) ( $entries[0]['time'] ?? 0 ) : 0;
	}

	$names   = array();
	$changes = array();
	foreach ( $recent as $entry ) {
		$user_id = (int) ( $entry['user'] ?? 0 );
		if ( $user_id > 0 && ! array_key_exists( $user_id, $names ) ) {
			$user            = get_userdata( $user_id );
			$names[ $user_id ] = $user ? $user->display_name : '';
		}

		$time = (int) ( $entry['time'] ?? 0 );

		$changes[] = array(
			'time'         => $time,
			'time_gmt'     => $time ? gmdate( 'c', $time ) : '',
			'user'         => $user_id,
			'user_name'    => $user_id > 0 ? $names[ $user_id ] : '',
			'ability'      => (string) ( $entry['ability'] ?? '' ),
			'batch'        => (string) ( $entry['batch'] ?? '' ),
			'target'       => (int) ( $entry['target'] ?? 0 ),
			'object'       => (string) ( $entry['object'] ?? '' ),
			'subject'      => (string) ( $entry['subject'] ?? '' ),
			'dry_run'      => ! empty( $entry['dry_run'] ),
			'hash_before'  => (string) ( $entry['hash_before'] ?? '' ),
			'hash_after'   => (string) ( $entry['hash_after'] ?? '' ),
			// A dry run never changes anything, whatever the hashes say.
			'changed_data' => empty( $entry['dry_run'] ) && ( ( $entry['hash_before'] ?? '' ) !== ( $entry['hash_after'] ?? '' ) ),
			'ok'           => ! empty( $entry['ok'] ),
			'error'        => (string) ( $entry['error'] ?? '' ),
		);
	}

	return array(
		'success'    => true,
		'total'      => $total,
		'retained'   => $retained,
		// Counted, not inferred: entries were really removed, rather than the store merely being
		// near a limit. The option fallback cannot count, and reports -1 to say so.
		'dropped'    => $dropped,
		'truncated'  => 0 !== $dropped,
		'oldest'     => $oldest,
		'oldest_gmt' => $oldest ? gmdate( 'c', $oldest ) : '',
		'store'      => $table ? 'table' : 'option',
		'changes'    => $changes,
	);
}
