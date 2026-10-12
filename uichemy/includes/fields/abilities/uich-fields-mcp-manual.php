<?php
/**
 * The third guidance channel: an operating manual pushed at the MCP handshake.
 *
 * The briefing (uichemy/get-content-model) has to be asked for, and the standing sentence on each
 * write is only read once a client is already looking at that tool. This one arrives before the
 * client has done anything.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( ! function_exists( 'uich_fields_mcp_manual' ) ) {
	/**
	 * The manual, generated from the live registry — never written by hand.
	 *
	 * Same rule as the briefing: which writes accept `dry_run` and `if_match` is read from their
	 * schemas at call time. A hand-kept list drifts, and a manual that names an argument the site
	 * does not accept sends an obedient client straight into a validation error.
	 *
	 * @param string[] $names Ability names to describe. Defaults to every exposed UiChemy ability.
	 * @return string Empty when nothing of ours is exposed.
	 */
	function uich_fields_mcp_manual( array $names = array() ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return '';
		}

		$reads     = array();
		$writes    = array();
		$dry       = array();
		$if_match  = array();
		$confirm   = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( ! uich_fields_ability_is_ours( $name ) ) {
				continue;
			}
			if ( $names && ! in_array( $name, $names, true ) ) {
				continue;
			}

			$schema = $ability->get_input_schema();
			$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] )
				? array_keys( $schema['properties'] )
				: array();

			if ( 'write' === uich_fields_ability_mode( $name ) ) {
				$writes[] = $name;
				if ( in_array( 'dry_run', $props, true ) ) {
					$dry[] = $name;
				}
				if ( in_array( 'if_match', $props, true ) ) {
					$if_match[] = $name;
				}
				if ( in_array( 'confirm', $props, true ) ) {
					$confirm[] = $name;
				}
			} else {
				$reads[] = $name;
			}
		}

		if ( ! $reads && ! $writes ) {
			return '';
		}

		$lines = array(
			'UiChemy — how to drive these tools.',
			'',
			sprintf(
				'Reachable here: %d read tool(s) and %d write tool(s). Anything not listed is switched off for this site, not missing.',
				count( $reads ),
				count( $writes )
			),
		);

		if ( $writes ) {
			$lines[] = '';
			$lines[] = 'Before writing:';
			$lines[] = '- Call uichemy/get-content-model once. It reports how this site is actually configured, and which arguments these tools accept right now.';
			$lines[] = '- Send writes one at a time. A write takes a site-wide lock; a second concurrent write is refused, not queued.';

			if ( $dry ) {
				$lines[] = sprintf(
					'- %d of the %d writes accept dry_run. Send dry_run true first, show the user what comes back, and only then send it for real.',
					count( $dry ),
					count( $writes )
				);
			}
			if ( $if_match ) {
				$lines[] = sprintf(
					'- %d writes accept if_match. Pass the config_hash from whichever read you built the payload from; the write is refused if the record changed underneath you.',
					count( $if_match )
				);
			}
			if ( $confirm ) {
				$lines[] = sprintf(
					'- %d writes can answer requires_confirmation true. That is not an error: relay it to the user and re-send with confirm true only once they agree.',
					count( $confirm )
				);
			}

			$lines[] = '';
			$lines[] = 'After writing:';
			$lines[] = '- Successful writes return an undo block naming what puts the change back. Keep it; the user will ask.';
			$lines[] = '- uichemy/list-changes shows recent writes, refusals included. It is a bounded recent-activity record, not a complete audit log.';
			$lines[] = '- uichemy/apply-changes runs several writes under one lock and returns its undo list newest first. Replay it top to bottom.';
		}

		$lines[] = '';
		$lines[] = 'Two things that are easy to get wrong here:';
		$lines[] = '- A Custom Fields definition id is not a WordPress post id. Read definitions through the UiChemy tools; a post lookup returns nothing and reports no error.';
		$lines[] = '- Renaming a field group key or a field name is refused once values are stored against it. That refusal is correct — surface it, do not work around it.';

		/**
		 * Filters the operating manual sent at the MCP handshake.
		 *
		 * @param string   $manual The generated manual.
		 * @param string[] $reads  Exposed read ability names.
		 * @param string[] $writes Exposed write ability names.
		 */
		return (string) apply_filters( 'uich_fields_mcp_manual', implode( "\n", $lines ), $reads, $writes );
	}
}

if ( ! function_exists( 'uich_fields_mcp_server_uichemy_abilities' ) ) {
	/**
	 * Which UiChemy abilities a given MCP server actually exposes as tools.
	 *
	 * Asks the server rather than the registry: exposure is resolved after registration finishes,
	 * and a server may carry only a subset.
	 *
	 * @param object $server McpServer instance.
	 * @return string[]
	 */
	function uich_fields_mcp_server_uichemy_abilities( $server ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) ) {
			return array();
		}

		$found = array();
		foreach ( (array) $server->get_tools() as $tool ) {
			if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_observability_context' ) ) {
				continue;
			}
			$context = (array) $tool->get_observability_context();
			$name    = isset( $context['ability_name'] ) ? (string) $context['ability_name'] : '';
			if ( '' !== $name && uich_fields_ability_is_ours( $name ) ) {
				$found[] = $name;
			}
		}

		return array_values( array_unique( $found ) );
	}
}

// The plugin ships no MCP adapter of its own. When another plugin's adapter serves a server that
// carries our tools, the client still needs our rules — so the manual is APPENDED to whatever that
// server already says about itself, never substituted for it.
add_filter(
	'mcp_adapter_initialize_response',
	static function ( $result, $server = null ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
			return $result;
		}

		$ours = uich_fields_mcp_server_uichemy_abilities( $server );
		if ( ! $ours ) {
			return $result;
		}

		$manual = uich_fields_mcp_manual( $ours );
		if ( '' === $manual ) {
			return $result;
		}

		$data     = (array) $result->toArray();
		$existing = isset( $data['instructions'] ) ? trim( (string) $data['instructions'] ) : '';

		// Already appended — a second transport init on the same request must not double it.
		if ( '' !== $existing && false !== strpos( $existing, 'UiChemy — how to drive these tools.' ) ) {
			return $result;
		}

		$data['instructions'] = ( '' !== $existing ) ? $existing . "\n\n" . $manual : $manual;

		$class = get_class( $result );
		if ( ! method_exists( $class, 'fromArray' ) ) {
			return $result;
		}

		// The DTO is immutable, so the documented round trip is the only way to change it. If the
		// shape ever stops round-tripping, keep the server's own response rather than breaking the
		// handshake for a paragraph of guidance.
		try {
			$rebuilt = $class::fromArray( $data );
		} catch ( \Throwable $e ) {
			return $result;
		}

		return is_object( $rebuilt ) ? $rebuilt : $result;
	},
	10,
	2
);
