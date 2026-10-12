<?php
/**
 * Two renderings of an ability's schema: lean for listing, rich for reading.
 *
 * A tool list that carries every description and every instruction costs a client thousands of
 * tokens before it has decided which tool it wants. Lean is what a list should carry; rich is what
 * the one chosen tool is worth.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( ! function_exists( 'uich_fields_schema_lean' ) ) {
	/**
	 * The smallest rendering that still lets a client choose.
	 *
	 * Argument NAMES and types are kept, because dropping them turns "which tool" into a guess.
	 * What goes is prose: the long per-argument descriptions and the instruction block.
	 *
	 * @param object $ability Registered ability.
	 * @return array
	 */
	function uich_fields_schema_lean( $ability ): array {
		$name   = $ability->get_name();
		$schema = (array) $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		$args = array();
		foreach ( $props as $key => $spec ) {
			$type = isset( $spec['type'] ) ? $spec['type'] : 'string';
			$args[ $key ] = is_array( $type ) ? implode( '|', $type ) : (string) $type;
		}

		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		return array(
			'name'     => $name,
			'label'    => method_exists( $ability, 'get_label' ) ? $ability->get_label() : $name,
			'mode'     => uich_fields_ability_mode( $name ),
			'args'     => $args,
			'required' => array_values( $required ),
		);
	}
}

if ( ! function_exists( 'uich_fields_schema_rich' ) ) {
	/**
	 * Everything a client needs to call this one ability correctly, and nothing about any other.
	 *
	 * The safety facts are read from the schema, not restated: a `dry_run` reported here is one the
	 * ability really accepts, which is the same rule the standing sentence and the briefing follow.
	 *
	 * @param object $ability Registered ability.
	 * @return array
	 */
	function uich_fields_schema_rich( $ability ): array {
		$name   = $ability->get_name();
		$schema = (array) $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
		$meta   = (array) $ability->get_meta();

		$out = uich_fields_schema_lean( $ability );

		$out['description']  = method_exists( $ability, 'get_description' ) ? $ability->get_description() : '';
		$out['input_schema'] = $schema;
		$out['instructions'] = isset( $meta['annotations']['instructions'] ) ? (string) $meta['annotations']['instructions'] : '';

		if ( 'write' === $out['mode'] ) {
			$undo = uich_fields_ability_reverses( $name );
			$out['safety'] = array(
				'dry_run'    => in_array( 'dry_run', $props, true ),
				'if_match'   => in_array( 'if_match', $props, true ),
				'confirm'    => in_array( 'confirm', $props, true ),
				'reversible' => '' !== $undo,
				'undo_with'  => '' !== $undo ? $undo : null,
			);
		}

		return $out;
	}
}

if ( ! function_exists( 'uich_fields_mcp_list_abilities' ) ) {
	/**
	 * Execute callback for uichemy/list-abilities.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	function uich_fields_mcp_list_abilities( array $input ): array {
		$detail = isset( $input['detail'] ) ? (string) $input['detail'] : 'lean';
		$wanted = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		$mode   = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		// Naming one ability is a request for detail; making the caller also remember to say so
		// earned a second round trip for nothing.
		if ( '' !== $wanted && ! isset( $input['detail'] ) ) {
			$detail = 'rich';
		}

		$items = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( ! uich_fields_ability_is_ours( $name ) ) {
				continue;
			}
			if ( '' !== $wanted && $name !== $wanted ) {
				continue;
			}
			if ( '' !== $mode && uich_fields_ability_mode( $name ) !== $mode ) {
				continue;
			}

			$items[] = ( 'rich' === $detail )
				? uich_fields_schema_rich( $ability )
				: uich_fields_schema_lean( $ability );
		}

		if ( '' !== $wanted && ! $items ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No UiChemy ability is named "%s". Call this without the ability argument to see what there is.', $wanted ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'success'   => true,
			'detail'    => $detail,
			'total'     => count( $items ),
			'abilities' => $items,
		);
	}
}

uich_fields_register_ability(
	'uichemy/list-abilities',
	array(
		'label'            => __( 'List UiChemy Abilities', 'uichemy' ),
		'description'      => __(
			'Lists the UiChemy abilities with their arguments. Returns a compact form by default, and the full schema, instructions and safety features for one named ability.',
			'uichemy'
		),
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'detail'  => array(
					'type'        => 'string',
					'description' => 'lean returns names, arguments and types only. rich adds the full schema, the instructions and what safety arguments the ability accepts.',
					'enum'        => array( 'lean', 'rich' ),
					'default'     => 'lean',
				),
				'ability' => array(
					'type'        => 'string',
					'description' => 'One ability name, e.g. uichemy/create-cpt. Implies rich unless detail says otherwise.',
				),
				'mode'    => array(
					'type'        => 'string',
					'description' => 'Only abilities of this kind.',
					'enum'        => array( 'read', 'write' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'detail'    => array( 'type' => 'string' ),
				'total'     => array( 'type' => 'integer' ),
				'abilities' => array( 'type' => 'array' ),
			),
		),
		'execute_callback' => 'uich_fields_mcp_list_abilities',
		'meta'             => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Use the lean form to decide which ability you want; it is a fraction of the size of the full schemas.',
						'Then call again naming that one ability to get its schema, its instructions and which of dry_run, if_match and confirm it accepts.',
						'The safety block is read from the live schema, so it never names an argument this site would reject.',
						'For how to drive the writes in general, call uichemy/get-content-model instead — this ability describes shapes, not procedure.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);
