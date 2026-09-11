<?php
/**
 * Terms: creating them, and putting them on a post.
 *
 * Registering a taxonomy and populating it are different capabilities, and
 * UiChemy used to have only the first. The consequence was reproducible and
 * expensive: a build models a `discipline` taxonomy, adds a taxonomy field for
 * it, writes half its content around it, and only then discovers that no term
 * can ever be created - by which point the model is committed. One verification
 * build recovered by inventing a parallel `select` field and driving the whole
 * front end off that, leaving the real taxonomy decorative.
 *
 * So this is deliberately NOT part of the model provider chain. A term is
 * content, not schema: it belongs to WordPress, not to ACF or JetEngine, and it
 * is created the same way whichever of them registered the taxonomy. There is
 * one implementation and it works on `category` exactly as it works on a
 * JetEngine taxonomy created ten seconds ago.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/model
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Model_Terms' ) ) {

	/**
	 * ensure-term and set-terms, for any registered taxonomy.
	 */
	final class Uich_Model_Terms {

		// ============================================================
		// ENSURE-TERM
		// ============================================================

		/**
		 * Create terms in a taxonomy, or return the ones already there.
		 *
		 * Idempotent on purpose: this is the call a build makes before it assigns
		 * anything, and a run that retries must not end up with "Residential" and
		 * "Residential-2".
		 *
		 * @param array $args { taxonomy, terms|name, parent? }.
		 * @return array|WP_Error
		 */
		public static function ensure_term( $args ) {
			$args = is_array( $args ) ? $args : array();

			$taxonomy = self::resolve_taxonomy( isset( $args['taxonomy'] ) ? $args['taxonomy'] : '' );

			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$cap = self::capability_check( $taxonomy, 'manage_terms' );

			if ( is_wp_error( $cap ) ) {
				return $cap;
			}

			$requested = self::normalise_term_input( $args );

			if ( is_wp_error( $requested ) ) {
				return $requested;
			}

			$rows = array();

			foreach ( $requested as $row ) {
				$result = self::ensure_one( $taxonomy, $row );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$rows[] = $result;
			}

			$object = get_taxonomy( $taxonomy );

			return array(
				'taxonomy'    => $taxonomy,
				'label'       => $object ? $object->labels->name : $taxonomy,
				'terms'       => $rows,
				'created'     => count( array_filter( wp_list_pluck( $rows, 'created' ) ) ),
				'existing'    => count( $rows ) - count( array_filter( wp_list_pluck( $rows, 'created' ) ) ),
				'term_count'  => (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ),
				'next_step'   => sprintf(
					'Put them on a post with action="set-terms" { "post_id": 12, "taxonomy": "%s", "terms": ["%s"] }. A term that exists but is on no post still makes every archive and filtered loop empty.',
					$taxonomy,
					isset( $rows[0]['slug'] ) ? $rows[0]['slug'] : 'slug'
				),
			);
		}

		/**
		 * Create or fetch one term.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @param array  $row      { name, slug?, description?, parent? }.
		 * @return array|WP_Error
		 */
		private static function ensure_one( $taxonomy, $row ) {
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';

			if ( '' === $name ) {
				return new WP_Error(
					'uich_term_no_name',
					sprintf( 'A term needs a "name" - the label a visitor sees, e.g. { "taxonomy": "%s", "terms": ["Residential"] }.', $taxonomy )
				);
			}

			$insert = array();

			if ( isset( $row['slug'] ) && '' !== (string) $row['slug'] ) {
				$insert['slug'] = sanitize_title( (string) $row['slug'] );
			}

			if ( isset( $row['description'] ) && '' !== (string) $row['description'] ) {
				$insert['description'] = wp_kses_post( (string) $row['description'] );
			}

			if ( isset( $row['parent'] ) && '' !== (string) $row['parent'] ) {
				$object = get_taxonomy( $taxonomy );

				if ( ! $object || empty( $object->hierarchical ) ) {
					return new WP_Error(
						'uich_term_not_hierarchical',
						sprintf( '"%s" is a flat taxonomy, so its terms cannot have a parent. Register it with hierarchical=true if you need nesting - it cannot be changed from here afterwards.', $taxonomy )
					);
				}

				$parent = self::find_term( $taxonomy, $row['parent'] );

				if ( ! $parent ) {
					return new WP_Error(
						'uich_term_parent_missing',
						sprintf( 'Parent term "%s" does not exist in %s. Create it in its own call first - a parent is never created implicitly, because a typo would quietly produce a second top-level term instead of an error.', (string) $row['parent'], $taxonomy )
					);
				}

				$insert['parent'] = (int) $parent->term_id;
			}

			$existing = self::find_term( $taxonomy, isset( $insert['slug'] ) ? $insert['slug'] : $name );

			if ( ! $existing ) {
				$existing = self::find_term( $taxonomy, $name );
			}

			$created = false;

			if ( $existing instanceof WP_Term ) {
				$changes = array();

				foreach ( array( 'description', 'parent' ) as $key ) {
					// The slug is deliberately not in that list: it is the term's
					// public URL, and nothing rewrites the links already pointing
					// at the old one.
					if ( isset( $insert[ $key ] ) && (string) $existing->{$key} !== (string) $insert[ $key ] ) {
						$changes[ $key ] = $insert[ $key ];
					}
				}

				if ( $changes ) {
					wp_update_term( (int) $existing->term_id, $taxonomy, $changes );
					$existing = get_term( (int) $existing->term_id, $taxonomy );
				}

				$term = $existing;
			} else {
				$new = wp_insert_term( $name, $taxonomy, $insert );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				$term    = get_term( (int) $new['term_id'], $taxonomy );
				$created = true;
			}

			if ( ! ( $term instanceof WP_Term ) ) {
				return new WP_Error( 'uich_term_failed', sprintf( 'The term "%s" could not be read back after writing it to %s.', $name, $taxonomy ) );
			}

			return array(
				'term_id'  => (int) $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'parent'   => (int) $term->parent,
				'count'    => (int) $term->count,
				'created'  => $created,
				'link'     => get_term_link( $term ) && ! is_wp_error( get_term_link( $term ) ) ? get_term_link( $term ) : '',
				'edit_url' => admin_url( 'term.php?taxonomy=' . rawurlencode( $taxonomy ) . '&tag_ID=' . (int) $term->term_id ),
			);
		}

		// ============================================================
		// SET-TERMS
		// ============================================================

		/**
		 * Assign terms to a post.
		 *
		 * Refuses an unknown term by default. Assigning a term that does not
		 * exist is the failure that cannot be seen: WordPress accepts a slug it
		 * has never heard of by creating nothing, the write reports success, and
		 * the archive stays empty forever. Pass create_missing=true to make the
		 * creation explicit.
		 *
		 * @param array $args { post_id, taxonomy, terms, append?, create_missing? }.
		 * @return array|WP_Error
		 */
		public static function set_terms( $args ) {
			$args = is_array( $args ) ? $args : array();

			$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;

			if ( ! $post ) {
				return new WP_Error(
					'uich_term_no_post',
					'"post_id" is required and must be a real post. Find one with uichemy-composer/post (action="list").'
				);
			}

			$taxonomy = self::resolve_taxonomy( isset( $args['taxonomy'] ) ? $args['taxonomy'] : '' );

			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				return new WP_Error(
					'uich_term_wrong_object_type',
					sprintf(
						'Post %d is a "%s", and "%s" is not registered for that post type - the terms would be written and no query would ever return them. %s accepts: %s. Attach the taxonomy with action="register-taxonomy" (or re-register the post type) before assigning.',
						$post_id,
						$post->post_type,
						$taxonomy,
						$post->post_type,
						implode( ', ', get_object_taxonomies( $post->post_type ) ) ? implode( ', ', get_object_taxonomies( $post->post_type ) ) : 'no taxonomies at all'
					)
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'uich_term_cannot_edit', sprintf( 'You cannot edit post %d.', $post_id ) );
			}

			$cap = self::capability_check( $taxonomy, 'assign_terms' );

			if ( is_wp_error( $cap ) ) {
				return $cap;
			}

			$wanted = isset( $args['terms'] ) ? (array) $args['terms'] : array();
			$wanted = array_values( array_filter( array_map( 'strval', $wanted ), 'strlen' ) );

			$append  = ! empty( $args['append'] );
			$create  = ! empty( $args['create_missing'] );
			$ids     = array();
			$missing = array();
			$made    = array();

			foreach ( $wanted as $one ) {
				$term = self::find_term( $taxonomy, $one );

				if ( $term instanceof WP_Term ) {
					$ids[] = (int) $term->term_id;
					continue;
				}

				if ( ! $create ) {
					$missing[] = $one;
					continue;
				}

				$new = self::ensure_one( $taxonomy, array( 'name' => $one ) );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				$ids[]  = (int) $new['term_id'];
				$made[] = $new;
			}

			if ( $missing ) {
				return new WP_Error(
					'uich_field_unknown_term',
					sprintf(
						'No term matching %s exists in "%s", so NOTHING was assigned - not even the terms that do exist, because a partial assignment is harder to notice than a refusal. WordPress would have accepted these silently and the archive would stay empty forever. Existing terms: %s. Create them with action="ensure-term", or repeat this call with create_missing=true.',
						'"' . implode( '", "', $missing ) . '"',
						$taxonomy,
						self::term_list( $taxonomy )
					)
				);
			}

			if ( ! $wanted && ! $append ) {
				// An explicit empty list clears the taxonomy on this post. Allowed,
				// but named in the response so it is never a surprise.
				$ids = array();
			}

			$before = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
			$before = is_wp_error( $before ) ? array() : $before;

			$result = wp_set_object_terms( $post_id, $ids, $taxonomy, $append );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			clean_post_cache( $post_id );

			$after = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
			$after = is_wp_error( $after ) ? array() : $after;

			$out = array(
				'post_id'  => $post_id,
				'post'     => get_the_title( $post_id ),
				'taxonomy' => $taxonomy,
				'append'   => $append,
				'before'   => array_values( $before ),
				'terms'    => array_map(
					static function ( $term ) {
						return array(
							'term_id' => (int) $term->term_id,
							'name'    => $term->name,
							'slug'    => $term->slug,
						);
					},
					$after
				),
				'ok'       => true,
			);

			if ( $made ) {
				$out['created'] = $made;
				$out['note']    = 'create_missing was on, so the terms above were created as part of this call. Check the spelling now - a typo has just become a real term.';
			}

			if ( ! $after ) {
				$out['warning'] = sprintf( 'Post %d now has NO terms in "%s". Every archive and filtered loop for that taxonomy will skip it.', $post_id, $taxonomy );
			}

			return $out;
		}

		// ============================================================
		// SHARED
		// ============================================================

		/**
		 * Validate a taxonomy slug, or say what this site has instead.
		 *
		 * @param mixed $slug Requested slug.
		 * @return string|WP_Error
		 */
		private static function resolve_taxonomy( $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug ) {
				return new WP_Error(
					'uich_term_no_taxonomy',
					sprintf( '"taxonomy" is required. This site has: %s.', implode( ', ', get_taxonomies( array(), 'names' ) ) )
				);
			}

			if ( ! taxonomy_exists( $slug ) ) {
				return new WP_Error(
					'uich_term_unknown_taxonomy',
					sprintf(
						'No taxonomy "%s" is registered here. This site has: %s. A JetEngine taxonomy created earlier in THIS request does not exist yet - it registers on the next one, so call this again.',
						$slug,
						implode( ', ', get_taxonomies( array(), 'names' ) )
					)
				);
			}

			return $slug;
		}

		/**
		 * Whether the current user may do this to this taxonomy.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @param string $which    'manage_terms' | 'assign_terms'.
		 * @return true|WP_Error
		 */
		private static function capability_check( $taxonomy, $which ) {
			$object = get_taxonomy( $taxonomy );

			if ( ! $object || empty( $object->cap->{$which} ) ) {
				return true;
			}

			if ( current_user_can( $object->cap->{$which} ) ) {
				return true;
			}

			return new WP_Error(
				'uich_term_forbidden',
				sprintf( 'Your user cannot %s in "%s" (capability: %s).', 'manage_terms' === $which ? 'create terms' : 'assign terms', $taxonomy, $object->cap->{$which} )
			);
		}

		/**
		 * Find a term by id, slug or name - in that order.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @param mixed  $ref      Term id, slug or name.
		 * @return WP_Term|null
		 */
		private static function find_term( $taxonomy, $ref ) {
			$ref = is_scalar( $ref ) ? trim( (string) $ref ) : '';

			if ( '' === $ref ) {
				return null;
			}

			if ( ctype_digit( $ref ) ) {
				$by_id = get_term( (int) $ref, $taxonomy );

				if ( $by_id instanceof WP_Term ) {
					return $by_id;
				}
			}

			foreach ( array( 'slug', 'name' ) as $field ) {
				$term = get_term_by( $field, $ref, $taxonomy );

				if ( $term instanceof WP_Term ) {
					return $term;
				}
			}

			// A name given in title case against a slugged term, e.g. "Residential"
			// vs `residential`. Tried last so an exact match always wins.
			$term = get_term_by( 'slug', sanitize_title( $ref ), $taxonomy );

			return $term instanceof WP_Term ? $term : null;
		}

		/**
		 * The terms a taxonomy actually has, for an error message.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @return string
		 */
		private static function term_list( $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 50,
				)
			);

			if ( is_wp_error( $terms ) || ! $terms ) {
				return 'none - the taxonomy is empty';
			}

			return implode( ', ', wp_list_pluck( $terms, 'slug' ) );
		}

		/**
		 * Accept "terms": ["A","B"], "terms": [{name,slug}], or "name": "A".
		 *
		 * @param array $args Action params.
		 * @return array<int,array>|WP_Error
		 */
		private static function normalise_term_input( $args ) {
			$raw = array();

			if ( isset( $args['terms'] ) ) {
				$raw = is_array( $args['terms'] ) ? $args['terms'] : array( $args['terms'] );
			} elseif ( isset( $args['name'] ) ) {
				$raw = array( $args );
			}

			$out = array();

			foreach ( $raw as $row ) {
				if ( is_scalar( $row ) ) {
					$out[] = array( 'name' => (string) $row );
					continue;
				}

				if ( is_array( $row ) ) {
					$out[] = array(
						'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
						'slug'        => isset( $row['slug'] ) ? (string) $row['slug'] : '',
						'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
						'parent'      => isset( $row['parent'] ) ? (string) $row['parent'] : '',
					);
				}
			}

			if ( ! $out ) {
				return new WP_Error(
					'uich_term_no_terms',
					'"terms" is required: ["Residential", "Commercial"], or [{"name":"Residential","slug":"residential"}] when you need to pin the slug the URL uses.'
				);
			}

			return $out;
		}
	}
}
