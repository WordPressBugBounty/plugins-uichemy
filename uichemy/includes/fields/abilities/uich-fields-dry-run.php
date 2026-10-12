<?php
/**
 * Shared dry-run helpers for ability write callbacks.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( ! function_exists( 'uich_fields_dry_run_arg' ) ) {
	/**
	 * The `dry_run` argument, declared the same way everywhere it is offered.
	 *
	 * @param string $what What the preview covers, e.g. "the group save".
	 * @return array Input schema fragment.
	 */
	function uich_fields_dry_run_arg( string $what ): array {
		return array(
			'type'        => 'boolean',
			/* translators: %s: what the write does, e.g. "the group save". */
			'description' => sprintf( 'Run every check %s performs and report the result, storing nothing.', $what ),
			'default'     => false,
		);
	}
}

if ( ! function_exists( 'uich_fields_dry_run' ) ) {
	/**
	 * A dry-run response, flat.
	 *
	 * Flat is not cosmetic: the lifecycle reads `dry_run` at the TOP level to decide not to attach
	 * an undo block, so a report nested under another key gets an undo for a write that never ran.
	 *
	 * @param string $would  What the real call would do: update, create, delete, no change.
	 * @param array  $report Extra context for the caller.
	 * @return array
	 */
	function uich_fields_dry_run( string $would, array $report = array() ): array {
		return array_merge(
			array(
				'success' => true,
				'dry_run' => true,
				'would'   => $would,
			),
			$report
		);
	}
}

if ( ! function_exists( 'uich_fields_dry_run_changes' ) ) {
	/**
	 * Which keys a settings write would actually change.
	 *
	 * Compares encoded values, so a nested array that is equal but differently ordered does not
	 * report as a change the real save would not make.
	 *
	 * @param array $before Stored option.
	 * @param array $after  Option as the write would leave it.
	 * @return array{changed:string[],added:string[],count:int}
	 */
	function uich_fields_dry_run_changes( array $before, array $after ): array {
		$changed = array();
		$added   = array();

		foreach ( $after as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) ) {
				$added[] = (string) $key;
				continue;
			}
			if ( wp_json_encode( $before[ $key ] ) !== wp_json_encode( $value ) ) {
				$changed[] = (string) $key;
			}
		}

		sort( $changed );
		sort( $added );

		return array(
			'changed' => $changed,
			'added'   => $added,
			'count'   => count( $changed ) + count( $added ),
		);
	}
}
