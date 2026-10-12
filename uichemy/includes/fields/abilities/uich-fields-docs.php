<?php
/**
 * Ability: serve the plugin's own documentation to an assistant.
 *
 * The docs are already written and already correct — what was missing was any way for an assistant
 * to read them, so it guessed at the template API instead and wrote calls that do not exist.
 *
 * @package Uichemy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( ! function_exists( 'uich_fields_doc_topics' ) ) {
	/**
	 * The documents on offer, keyed by topic slug.
	 *
	 * An allowlist, not a directory scan: the topic comes from the caller, and a scan would turn
	 * this read into a way to ask for any .md file the plugin happens to ship.
	 *
	 * @return array<string,array{file:string,title:string,summary:string}>
	 */
	function uich_fields_doc_topics(): array {
		return array(
			'fields-api' => array(
				'file'    => 'uichemy-fields-api.md',
				'title'   => __( 'Custom Fields — Developer API', 'uichemy' ),
				'summary' => __( 'The template functions for reading and writing field values, including how $object_id addresses posts, terms, users and options.', 'uichemy' ),
			),
		);
	}
}

if ( ! function_exists( 'uich_fields_mcp_get_docs' ) ) {
	/**
	 * Execute callback for uichemy/get-docs.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	function uich_fields_mcp_get_docs( array $input ): array {
		$topics = uich_fields_doc_topics();
		$wanted = isset( $input['topic'] ) ? sanitize_key( (string) $input['topic'] ) : '';

		if ( '' === $wanted ) {
			$list = array();
			foreach ( $topics as $slug => $topic ) {
				$list[] = array(
					'topic'   => $slug,
					'title'   => $topic['title'],
					'summary' => $topic['summary'],
				);
			}
			return array(
				'success' => true,
				'total'   => count( $list ),
				'topics'  => $list,
				'note'    => 'Call again with one topic to read it. For how to drive the abilities themselves, call uichemy/get-content-model.',
			);
		}

		if ( ! isset( $topics[ $wanted ] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'No document is filed under "%s". Available: %s.',
					$wanted,
					implode( ', ', array_keys( $topics ) )
				),
			);
		}

		// Built from the allowlisted basename and the plugin's own directory, so the caller's string
		// never reaches the filesystem.
		$path = UICH_FIELDS_DIR . 'docs/' . $topics[ $wanted ]['file'];
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'The "%s" document is not present in this build.', $wanted ),
			);
		}

		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin's own bundled file.

		$section = isset( $input['section'] ) ? trim( (string) $input['section'] ) : '';
		$matched = '';
		if ( '' !== $section ) {
			// Markdown headings only. Returning a byte range instead would hand back a fragment that
			// starts mid-sentence, which is worse than returning the whole file.
			$parts = preg_split( '/^(#{1,3} .+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE );
			$parts = is_array( $parts ) ? $parts : array();
			for ( $i = 0; $i < count( $parts ); $i++ ) {
				if ( 0 !== strpos( (string) $parts[ $i ], '#' ) ) {
					continue;
				}
				if ( false !== stripos( (string) $parts[ $i ], $section ) ) {
					$matched .= $parts[ $i ] . ( isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '' );
				}
			}
			if ( '' === $matched ) {
				return array(
					'success'  => false,
					'error'    => sprintf( 'No heading in "%s" matches "%s". Call without section to read the whole document.', $wanted, $section ),
					'headings' => uich_fields_doc_headings( $body ),
				);
			}
			$body = $matched;
		}

		return array(
			'success'  => true,
			'topic'    => $wanted,
			'title'    => $topics[ $wanted ]['title'],
			'section'  => '' !== $section ? $section : null,
			'headings' => uich_fields_doc_headings( $body ),
			'markdown' => $body,
			'bytes'    => strlen( $body ),
		);
	}
}

if ( ! function_exists( 'uich_fields_doc_headings' ) ) {
	/**
	 * The headings in a document, so a caller can ask for one section instead of all 15 KB.
	 *
	 * @param string $body Markdown.
	 * @return string[]
	 */
	function uich_fields_doc_headings( string $body ): array {
		if ( ! preg_match_all( '/^#{1,3} (.+)$/m', $body, $m ) ) {
			return array();
		}
		return array_map( 'trim', $m[1] );
	}
}

uich_fields_register_ability(
	'uichemy/get-docs',
	array(
		'label'            => __( 'Get UiChemy Documentation', 'uichemy' ),
		'description'      => __(
			'Serves the plugin\'s own developer documentation for the Custom Fields template API. Lists the available topics when called without one.',
			'uichemy'
		),
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'   => array(
					'type'        => 'string',
					'description' => 'Topic slug. Omit to list what is available.',
				),
				'section' => array(
					'type'        => 'string',
					'description' => 'Return only the headings matching this text, with their content. Use it to avoid pulling a whole document into the conversation.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'markdown' => array( 'type' => 'string' ),
				'headings' => array( 'type' => 'array' ),
			),
		),
		'execute_callback' => 'uich_fields_mcp_get_docs',
		'meta'             => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Read this before writing template code against Custom Fields. The template API is the uich_* family — uich_field_value(), uich_field_values(), uich_has_field(), uich_field_definition() and friends — and it does not use ACF\'s function names, so a guessed get_field()-style call is a fatal error.',
						'Call without arguments first to see the topics, then name one.',
						'Prefer section over reading the whole document: the API reference is around 15 KB.',
						'This serves documentation. For how the abilities behave, call uichemy/get-content-model; for one ability\'s schema, call uichemy/list-abilities.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);
