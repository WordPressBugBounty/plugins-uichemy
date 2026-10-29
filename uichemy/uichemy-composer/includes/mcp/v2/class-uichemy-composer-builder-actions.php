<?php
/**
 * The page / post actions for UiChemy's own Composer Builder (composer-builder/).
 *
 * A Composer Builder page is ONE document, not a list of sections: html, css,
 * js, plus the page's own code for the end of <head> and of <body>. So when the
 * Composer Builder is the selected builder (UiChemy_Builder_Context::
 * is_composer_mode()), uichemy-composer/page and /post drop every section
 * action and offer these instead: create, get-code, set-code, patch-code. In
 * every other mode they are not offered at all. Not an ability of its own -
 * UiChemy_Abilities serves the schemas and UiChemy_MCP_V2_Router dispatches here.
 *
 * Every write goes through Uich_CB_Content::save_cb_content(), the same door
 * the editor uses, so the code policy, the post_content mirror, the enabled
 * flag and the revision all happen exactly as they do for a save in the UI.
 *
 * @link       https://posimyth.com/
 * @since      5.2.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Composer_Builder_Actions' ) ) {

	/**
	 * Schemas and handlers for the Composer Builder page / post actions.
	 */
	final class UiChemy_Composer_Builder_Actions {

		/**
		 * Public parameter name => key in the stored document's `content`.
		 *
		 * The document keys describe WHERE the code prints; the public names are
		 * what a caller thinks it is writing.
		 */
		const FIELDS = array(
			'html'          => 'html',
			'css'           => 'css',
			'js'            => 'js',
			'head_code'     => 'before_head_end_html',
			'body_end_code' => 'before_body_end_html',
		);

		/**
		 * Is the Composer Builder's storage layer loaded?
		 *
		 * @return bool
		 */
		public static function available() {
			return class_exists( 'Uich_CB_Content' ) && class_exists( 'Uich_CB_Policy' );
		}

		// ============================================================
		// SCHEMAS
		// ============================================================

		/**
		 * The five code parts, as schema properties.
		 *
		 * @return array
		 */
		private static function field_properties() {
			return array(
				'html'          => array( 'type' => 'string', 'description' => 'The page markup - the whole page.' ),
				'css'           => array( 'type' => 'string', 'description' => 'The page styles. Reference design-system tokens as var(--name). Include real tablet and mobile rules.' ),
				'js'            => array( 'type' => 'string', 'description' => 'The page script. Not stored for users without unfiltered_html.' ),
				'head_code'     => array( 'type' => 'string', 'description' => 'This page\'s own code for just before </head>.' ),
				'body_end_code' => array( 'type' => 'string', 'description' => 'This page\'s own code for just before </body>.' ),
			);
		}

		/**
		 * The upload_images parameter, shared by every write.
		 *
		 * @return array
		 */
		private static function upload_images_property() {
			return array(
				'type'        => 'boolean',
				'description' => 'Sideload the <img>/<video>/<source> URLs and CSS url(...) in html and css into the media library (default true). Only public HTTPS URLs can be fetched; anything that could not is listed under image_failures.',
			);
		}

		/**
		 * The document actions, in the page / post action-schema format.
		 *
		 * @param string $noun 'page' | 'post' - how the item is called.
		 * @return array<int,array>
		 */
		public static function schemas( $noun ) {
			$post_id       = array( 'type' => 'integer', 'description' => 'The ' . $noun . ' (from action="create" or action="list").' );
			$expected_hash = array( 'type' => 'string', 'description' => 'The "hash" from your last get-code / set-code / patch-code. When it no longer matches, the write is refused - the ' . $noun . ' changed since you read it (in the editor or another call); call "get-code" again.' );

			$create_props = array( 'post_id' => array( 'type' => 'integer', 'description' => 'Convert this existing ' . $noun . ' instead of creating a new one. With no html, its current content becomes the html.' ) );
			if ( 'post' === $noun ) {
				$create_props['post_type'] = array( 'type' => 'string', 'description' => 'Post type for a NEW post (default "post"). Any public type with editor support - "product", "portfolio", a custom one. Ignored with post_id.' );
			}
			$create_props = array_merge(
				$create_props,
				array(
					'title'  => array( 'type' => 'string', 'description' => ucfirst( $noun ) . ' title.' ),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
						'description' => 'Post status (default "draft" for a new ' . $noun . '; unchanged when converting). Publish once it looks right, or nothing can be looked at.',
					),
				),
				self::field_properties(),
				array(
					'upload_images' => self::upload_images_property(),
					'confirm'       => array( 'type' => 'boolean', 'description' => 'REQUIRED to convert a ' . $noun . ' another builder (Elementor, Bricks, Gutenberg) already owns: its old data is kept, but it then renders this document instead. Ask the user first.' ),
				)
			);

			return array(
				array(
					'name'                     => 'create',
					'description'              => 'Create a ' . $noun . ' with its WHOLE code in one call - html, css, js, head_code, body_end_code. One document holds the whole ' . $noun . ': put every part of it in html. With post_id, converts that existing ' . $noun . ' instead.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => $create_props,
						'required'   => array(),
					),
				),
				array(
					'name'                     => 'get-code',
					'description'              => 'Read the ' . $noun . '\'s code - html, css, js, head_code, body_end_code - plus title, status, links and the "hash" to pass back on the next write. Do this before every set-code / patch-code.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id' => $post_id,
							'fields'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string', 'enum' => array_keys( self::FIELDS ) ),
								'description' => 'Return only these parts. Omit for all five.',
							),
						),
						'required'   => array( 'post_id' ),
					),
				),
				array(
					'name'                     => 'set-code',
					'description'              => 'Replace one or more parts wholesale. Parts you do not pass are left exactly as they are; pass an empty string to clear one. For a small change prefer "patch-code", which cannot silently rewrite the rest.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array_merge(
							array( 'post_id' => $post_id ),
							self::field_properties(),
							array(
								'upload_images' => self::upload_images_property(),
								'expected_hash' => $expected_hash,
							)
						),
						'required'   => array( 'post_id' ),
					),
				),
				array(
					'name'                     => 'patch-code',
					'description'              => 'Literal find/replace edits inside ONE part, without resending it. Every exact, case-sensitive occurrence of find is replaced, so include enough surrounding text to make it unique. To ADD a block, anchor on text you keep. Each edit reports its own match count; when no edit matches, nothing is saved.',
					'action_parameters_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'       => $post_id,
							'field'         => array(
								'type'        => 'string',
								'enum'        => array_keys( self::FIELDS ),
								'description' => 'Which part to edit (default "html").',
							),
							'edits'         => array(
								'type'        => 'array',
								'description' => 'Applied in order. Add markup by anchoring on text you keep: find "</main>", replace "<section>…</section></main>". Delete by replacing with "".',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'find'    => array( 'type' => 'string' ),
										'replace' => array( 'type' => 'string' ),
									),
									'required'   => array( 'find', 'replace' ),
								),
							),
							'upload_images' => self::upload_images_property(),
							'expected_hash' => $expected_hash,
						),
						'required'   => array( 'post_id', 'edits' ),
					),
				),
			);
		}

		// ============================================================
		// ACTIONS
		// ============================================================

		/**
		 * create - a new page / post, or convert an existing one.
		 *
		 * @param array  $params Action parameters.
		 * @param string $domain 'page' | 'post'.
		 * @return array|WP_Error
		 */
		public static function create( array $params, $domain = 'page' ) {
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			$status  = isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : '';

			if ( '' !== $status && ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				return new WP_Error( 'uich_cb_bad_status', sprintf( 'Unknown status "%s". Valid: draft, publish, pending, private.', $status ) );
			}

			$post_id = $post_id ? self::prepare_existing( $post_id, $params, $domain ) : self::insert_new( $params, $status );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$converting = ! empty( $params['post_id'] );
			$fields     = self::fields_from( $params );

			// Converting with no html: what the post already shows becomes the
			// starting markup, so the page does not go blank on conversion.
			if ( $converting && ! array_key_exists( 'html', $fields ) ) {
				$fields = array_merge( self::existing_content( $post_id, $fields ), $fields );
			}

			$media  = self::sideload( $fields, $params );
			$fields = $media['fields'];

			$document = Uich_CB_Content::get_cb_content( $post_id );
			foreach ( $fields as $name => $value ) {
				$document['content'][ self::FIELDS[ $name ] ] = $value;
			}

			if ( ! Uich_CB_Content::save_cb_content( $post_id, $document ) ) {
				return new WP_Error( 'uich_cb_save_failed', 'The ' . $domain . ' was created but its code could not be saved.' );
			}

			// Status last, so a published page never goes live without its code.
			if ( $converting && '' !== $status ) {
				$updated = self::update_status( $post_id, $status );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}

			return array_merge(
				array( 'created' => ! $converting, 'converted' => $converting ),
				self::response( $post_id, $fields ),
				$media['report']
			);
		}

		/**
		 * get-code - read one page / post.
		 *
		 * @param array $params Action parameters.
		 * @return array|WP_Error
		 */
		public static function get_code( array $params ) {
			$post_id = self::editable_page( $params );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$result = self::response( $post_id );

			if ( ! empty( $params['fields'] ) && is_array( $params['fields'] ) ) {
				$wanted         = array_intersect( array_keys( self::FIELDS ), array_map( 'strval', $params['fields'] ) );
				$result['code'] = array_intersect_key( $result['code'], array_flip( $wanted ) );
			}

			unset( $result['stripped'] );

			return $result;
		}

		/**
		 * set-code - replace one or more parts.
		 *
		 * @param array $params Action parameters.
		 * @return array|WP_Error
		 */
		public static function set_code( array $params ) {
			$post_id = self::editable_page( $params );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$fields = self::fields_from( $params );
			if ( empty( $fields ) ) {
				return new WP_Error( 'uich_cb_nothing_to_set', 'Pass at least one of: ' . implode( ', ', array_keys( self::FIELDS ) ) . '.' );
			}

			$document = Uich_CB_Content::get_cb_content( $post_id );

			$stale = self::check_hash( $document, $params );
			if ( is_wp_error( $stale ) ) {
				return $stale;
			}

			$media  = self::sideload( $fields, $params );
			$fields = $media['fields'];

			foreach ( $fields as $name => $value ) {
				$document['content'][ self::FIELDS[ $name ] ] = $value;
			}

			if ( ! Uich_CB_Content::save_cb_content( $post_id, $document ) ) {
				return new WP_Error( 'uich_cb_save_failed', 'The code could not be saved.' );
			}

			return array_merge( array( 'updated' => array_keys( $fields ) ), self::response( $post_id, $fields, array_keys( $fields ) ), $media['report'] );
		}

		/**
		 * What a post shows today, as starting code for its builder document.
		 *
		 * Composer sections (Elementor, Bricks, the block editor's Composer
		 * blocks) bring their own html / css / js, region by region - reading
		 * raw post_content instead kept only Elementor's plain-text fallback and
		 * lost every section's styles and scripts. Other content is rendered the
		 * way the page showed it: blocks through do_blocks(), classic text with
		 * its paragraphs. Fields the caller passed are never overwritten.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $given   Fields the caller already passed.
		 * @return array<string,string>
		 */
		private static function existing_content( $post_id, array $given ) {
			$slug     = class_exists( 'UiChemy_Builder_Registry' ) ? UiChemy_Builder_Registry::detect_post_builder( $post_id ) : '';
			$driver   = $slug ? UiChemy_Builder_Registry::get( $slug ) : null;
			$sections = ( $driver && $driver->is_available() ) ? $driver->load_sections( $post_id, 'content' ) : array();

			if ( ! is_wp_error( $sections ) && ! empty( $sections ) ) {
				// Folded by storage's own schema-1 path, so each section's JS keeps
				// a block of its own: two sections declaring the same
				// `const uichemy_controller_bridge` joined bare would be a
				// SyntaxError and none of the page's JS would run.
				$folded = Uich_CB_Content::normalize( array( 'sections' => array_values( (array) $sections ) ) );
				$code   = isset( $folded['content'] ) ? (array) $folded['content'] : array();
				if ( isset( $code['html'] ) && '' !== trim( (string) $code['html'] ) ) {
					$out = array( 'html' => (string) $code['html'] );
					foreach ( array( 'css', 'js' ) as $part ) {
						if ( ! empty( $code[ $part ] ) && ! array_key_exists( $part, $given ) ) {
							$out[ $part ] = (string) $code[ $part ];
						}
					}
					return $out;
				}
			}

			$raw = (string) get_post_field( 'post_content', $post_id );
			return array( 'html' => has_blocks( $raw ) ? do_blocks( $raw ) : wpautop( $raw ) );
		}

		/**
		 * patch-code - literal find/replace edits inside one part.
		 *
		 * @param array $params Action parameters.
		 * @return array|WP_Error
		 */
		public static function patch_code( array $params ) {
			$post_id = self::editable_page( $params );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$field = isset( $params['field'] ) && '' !== (string) $params['field'] ? sanitize_key( (string) $params['field'] ) : 'html';
			if ( ! isset( self::FIELDS[ $field ] ) ) {
				return new WP_Error( 'uich_cb_bad_field', sprintf( 'Unknown field "%s". Valid: %s.', $field, implode( ', ', array_keys( self::FIELDS ) ) ) );
			}

			if ( empty( $params['edits'] ) || ! is_array( $params['edits'] ) ) {
				return new WP_Error( 'uich_cb_no_edits', '"edits" is required: [{ "find": "...", "replace": "..." }].' );
			}

			$document = Uich_CB_Content::get_cb_content( $post_id );

			$stale = self::check_hash( $document, $params );
			if ( is_wp_error( $stale ) ) {
				return $stale;
			}

			$value   = (string) $document['content'][ self::FIELDS[ $field ] ];
			$report  = array();
			$matched = 0;
			$media   = array( 'report' => array() );

			foreach ( array_values( $params['edits'] ) as $i => $edit ) {
				$find    = is_array( $edit ) && isset( $edit['find'] ) ? (string) $edit['find'] : '';
				$replace = is_array( $edit ) && isset( $edit['replace'] ) ? (string) $edit['replace'] : '';

				// Only the NEW text is sideloaded - what is already stored was
				// handled when it was written.
				if ( in_array( $field, array( 'html', 'css' ), true ) && '' !== $replace ) {
					$one     = self::sideload( array( $field => $replace ), $params );
					$replace = $one['fields'][ $field ];
					$media   = self::merge_media_reports( $media, $one );
				}

				if ( '' === $find ) {
					$report[] = array( 'edit' => $i, 'matches' => 0, 'error' => '"find" is empty.' );
					continue;
				}

				$count = substr_count( $value, $find );
				if ( $count > 0 ) {
					$value    = str_replace( $find, $replace, $value );
					$matched += $count;
				}
				$report[] = array( 'edit' => $i, 'matches' => $count );
			}

			if ( 0 === $matched ) {
				// An empty part has nothing to anchor a find on: that is a set-code
				// job, and saying so saves a round trip.
				if ( '' === trim( (string) $value ) ) {
					return new WP_Error(
						'uich_cb_patch_empty_field',
						'Nothing was saved: ' . $field . ' is empty, so there is no text for "find" to match. Write it with action="set-code" and ' . $field . '="…" instead.',
						array( 'status' => 400 )
					);
				}
				return new WP_Error(
					'uich_cb_patch_no_match',
					'No edit matched, so nothing was saved. "find" must match the current ' . $field . ' exactly - call action="get-code" and copy the text from there.',
					array( 'edits' => $report )
				);
			}

			$document['content'][ self::FIELDS[ $field ] ] = $value;

			if ( ! Uich_CB_Content::save_cb_content( $post_id, $document ) ) {
				return new WP_Error( 'uich_cb_save_failed', 'The code could not be saved.' );
			}

			return array_merge(
				array(
					'field' => $field,
					'edits' => $report,
				),
				self::response( $post_id, array( $field => $value ), array( $field ) ),
				$media['report']
			);
		}

		// ============================================================
		// HELPERS
		// ============================================================

		/**
		 * Insert a new post for create.
		 *
		 * @param array  $params Action parameters.
		 * @param string $status Requested status, or ''.
		 * @return int|WP_Error Post ID.
		 */
		private static function insert_new( array $params, $status ) {
			$post_type = isset( $params['post_type'] ) && '' !== (string) $params['post_type'] ? sanitize_key( (string) $params['post_type'] ) : 'page';
			$status    = '' !== $status ? $status : 'draft';

			if ( class_exists( 'Uich_CB_Post_Types' ) && Uich_CB_Post_Types::is_builder_type( $post_type ) ) {
				return new WP_Error( 'uich_cb_use_theme_builder', 'Templates are created with uichemy-composer/theme-builder (action="create"), which also sets where they render. Edit one afterwards with uichemy-composer/post by its post_id.' );
			}

			if ( ! in_array( $post_type, Uich_CB_Content::supported_post_types(), true ) ) {
				return new WP_Error( 'uich_cb_unsupported_type', sprintf( '"%s" cannot be built here. Supported post types: %s.', $post_type, implode( ', ', Uich_CB_Content::supported_post_types() ) ) );
			}

			$type_object = get_post_type_object( $post_type );
			if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
				return new WP_Error( 'uich_cb_forbidden', sprintf( 'You cannot create "%s" posts.', $post_type ) );
			}
			if ( 'publish' === $status && ! current_user_can( $type_object->cap->publish_posts ) ) {
				return new WP_Error( 'uich_cb_forbidden', 'You cannot publish here. Create it as a draft instead.' );
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => $status,
					'post_title'   => isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '',
					'post_content' => '',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return (int) $post_id;
		}

		/**
		 * Validate an existing post for create-with-post_id and claim it.
		 *
		 * @param int    $post_id Post ID.
		 * @param array  $params  Action parameters.
		 * @param string $domain  'page' | 'post'.
		 * @return int|WP_Error Post ID.
		 */
		private static function prepare_existing( $post_id, array $params, $domain ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'uich_cb_not_found', sprintf( 'No post with ID %d.', $post_id ) );
			}

			if ( 'page' === $domain && 'page' !== $post->post_type ) {
				return new WP_Error( 'uich_cb_wrong_ability', sprintf( 'Post %d is a "%s", not a page - convert it with uichemy-composer/post.', $post_id, $post->post_type ) );
			}

			if ( ! Uich_CB_Policy::can_edit( $post ) ) {
				return new WP_Error( 'uich_cb_forbidden', sprintf( 'Post %d cannot be built here: its type ("%s") is not supported, or you lack permission.', $post_id, $post->post_type ) );
			}

			if ( Uich_CB_Content::is_enabled( $post_id ) ) {
				return new WP_Error( 'uich_cb_already_enabled', sprintf( 'Post %d already holds a document. Use action="get-code", then "set-code" or "patch-code".', $post_id ) );
			}

			$owner = class_exists( 'UiChemy_Builder_Registry' ) ? UiChemy_Builder_Registry::detect_post_builder( $post_id ) : '';
			if ( '' !== $owner && empty( $params['confirm'] ) ) {
				return new WP_Error(
					'uich_cb_needs_confirm',
					sprintf( 'Post %d is built with %s. Converting keeps that data but the post will render this document instead. Ask the user, then call again with "confirm": true.', $post_id, $owner ),
					array( 'requires_confirmation' => true, 'current_builder' => $owner )
				);
			}

			Uich_CB_Content::claim_as_draft( $post_id );

			if ( isset( $params['title'] ) && '' !== trim( (string) $params['title'] ) ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => sanitize_text_field( (string) $params['title'] ),
					)
				);
			}

			return $post_id;
		}

		/**
		 * Change a converted post's status.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $status  New status.
		 * @return true|WP_Error
		 */
		private static function update_status( $post_id, $status ) {
			if ( 'publish' === $status && ! Uich_CB_Policy::can_publish( $post_id ) ) {
				return new WP_Error( 'uich_cb_forbidden', 'The post was converted, but you cannot publish it.' );
			}

			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				),
				true
			);

			return is_wp_error( $updated ) ? $updated : true;
		}

		/**
		 * Resolve post_id to a Composer Builder page the user may edit.
		 *
		 * @param array $params Action parameters.
		 * @return int|WP_Error
		 */
		private static function editable_page( array $params ) {
			$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
			if ( ! $post_id || ! get_post( $post_id ) ) {
				return new WP_Error( 'uich_cb_not_found', '"post_id" is required and must be an existing post.' );
			}

			if ( ! Uich_CB_Content::is_enabled( $post_id ) ) {
				return new WP_Error( 'uich_cb_not_enabled', sprintf( 'Post %d holds no document yet. Convert it with action="create" and "post_id".', $post_id ) );
			}

			if ( ! Uich_CB_Policy::can_edit( $post_id ) ) {
				return new WP_Error( 'uich_cb_forbidden', sprintf( 'You cannot edit post %d.', $post_id ) );
			}

			return $post_id;
		}

		/**
		 * The code parts present in the call, as strings.
		 *
		 * @param array $params Action parameters.
		 * @return array<string,string> Public name => value.
		 */
		private static function fields_from( array $params ) {
			$fields = array();

			foreach ( array_keys( self::FIELDS ) as $name ) {
				if ( array_key_exists( $name, $params ) && null !== $params[ $name ] ) {
					$fields[ $name ] = (string) $params[ $name ];
				}
			}

			return $fields;
		}

		/**
		 * Sideload the media referenced by html / css into the media library.
		 *
		 * The same helper the section writes use, so a document imports images,
		 * video and CSS backgrounds exactly as a section does.
		 *
		 * @param array $fields Public name => value about to be written.
		 * @param array $params Action parameters (upload_images, default true).
		 * @return array{fields:array,report:array}
		 */
		private static function sideload( array $fields, array $params ) {
			$out = array(
				'fields' => $fields,
				'report' => array(),
			);

			$wanted = ! isset( $params['upload_images'] ) || ! empty( $params['upload_images'] );
			if ( ! $wanted || ( ! isset( $fields['html'] ) && ! isset( $fields['css'] ) )
				|| ! class_exists( 'UiChemy_Composer_Manager' ) || ! method_exists( 'UiChemy_Composer_Manager', 'mcp_upload_html_images_to_media_library' ) ) {
				return $out;
			}

			$result = UiChemy_Composer_Manager::mcp_upload_html_images_to_media_library(
				isset( $fields['html'] ) ? $fields['html'] : '',
				isset( $fields['css'] ) ? $fields['css'] : ''
			);

			if ( isset( $fields['html'] ) && isset( $result['html'] ) ) {
				$out['fields']['html'] = (string) $result['html'];
			}
			if ( isset( $fields['css'] ) && isset( $result['css'] ) ) {
				$out['fields']['css'] = (string) $result['css'];
			}

			$out['report'] = array(
				'image_uploads'  => isset( $result['uploaded'] ) ? array_values( (array) $result['uploaded'] ) : array(),
				'image_failures' => isset( $result['failed'] ) ? array_values( (array) $result['failed'] ) : array(),
			);

			return $out;
		}

		/**
		 * Fold one sideload report into another.
		 *
		 * @param array $into Accumulated { report }.
		 * @param array $one  One sideload() result.
		 * @return array
		 */
		private static function merge_media_reports( array $into, array $one ) {
			foreach ( array( 'image_uploads', 'image_failures' ) as $key ) {
				if ( ! empty( $one['report'][ $key ] ) ) {
					$into['report'][ $key ] = array_merge( isset( $into['report'][ $key ] ) ? $into['report'][ $key ] : array(), $one['report'][ $key ] );
				}
			}

			return $into;
		}

		/**
		 * Fingerprint of the stored code, for expected_hash.
		 *
		 * @param array $document Canonical document.
		 * @return string
		 */
		private static function hash( array $document ) {
			return md5( (string) wp_json_encode( $document['content'] ) );
		}

		/**
		 * Refuse a write whose expected_hash is stale.
		 *
		 * @param array $document Current stored document.
		 * @param array $params   Action parameters.
		 * @return true|WP_Error
		 */
		private static function check_hash( array $document, array $params ) {
			if ( empty( $params['expected_hash'] ) ) {
				return true;
			}

			$current = self::hash( $document );
			if ( hash_equals( $current, (string) $params['expected_hash'] ) ) {
				return true;
			}

			return new WP_Error(
				'uich_cb_stale',
				'The code changed since you read it (in the editor or another call), so nothing was saved. Call action="get-code" again and redo the edit on the current code.',
				array( 'status' => 409, 'hash' => $current )
			);
		}

		/**
		 * The shared response: the post, its code, links, hash, and what the
		 * code policy did not store as sent.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $sent    Public name => value written in this call.
		 * @return array
		 */
		private static function response( $post_id, array $sent = array(), $only = null ) {
			$post     = get_post( $post_id );
			$document = Uich_CB_Content::get_cb_content( $post_id );

			$code = array();
			foreach ( self::FIELDS as $name => $key ) {
				$code[ $name ] = (string) $document['content'][ $key ];
			}

			$stripped = array();
			foreach ( $sent as $name => $value ) {
				if ( isset( $code[ $name ] ) && $code[ $name ] !== $value ) {
					$stripped[] = $name;
				}
			}

			$result = array(
				'post_id'     => (int) $post_id,
				'title'       => $post ? $post->post_title : '',
				'status'      => $post ? $post->post_status : '',
				'post_type'   => $post ? $post->post_type : '',
				'builder'     => UiChemy_Builder_Context::COMPOSER,
				// A write answers with the parts it touched (plus the hash for the
				// next edit), not the whole page: echoing every field after each
				// patch made a long build pay for the page many times over.
				'code'        => null === $only ? $code : array_intersect_key( $code, array_flip( (array) $only ) ),
				'hash'        => self::hash( $document ),
				'edit_url'    => Uich_CB_Content::edit_url( $post_id ),
				'preview_url' => $post ? (string) get_preview_post_link( $post ) : '',
				'url'         => (string) get_permalink( $post_id ),
				'stripped'    => $stripped,
			);

			if ( ! empty( $stripped ) ) {
				$result['stripped_note'] = 'These parts were changed by the code policy while saving (js is removed and html filtered for users without unfiltered_html). "code" shows what was actually stored.';
			}

			return $result;
		}
	}
}
