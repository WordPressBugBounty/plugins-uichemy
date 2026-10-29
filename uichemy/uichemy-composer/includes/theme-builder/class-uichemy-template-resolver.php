<?php
/**
 * UiChemy_Template_Resolver — pick the winning template for the current request.
 *
 * Given a location type (one of UiChemy_Template_CPT::TYPES), returns the single
 * template post ID that should render on this request, or 0 if none applies.
 * Results are memoised per request.
 *
 * Logic:
 *  - header/footer: resolved by display conditions (entire site + include/
 *    exclude specific posts) — best-matching active template wins.
 *  - single/archive: resolved by TARGET (the queried post type / taxonomy /
 *    author / date / front / blog), most specific first, then page conditions
 *    within the matching target; a targeted template beats the "any" fallback.
 *  - error_404 / single_product / product_archive / order_received / search: whole request-
 *    context locations not narrowed; newest active wins.
 *
 * @package UiChemy
 * @subpackage UiChemy/theme-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Template_Resolver' ) ) {

	/**
	 * Resolves which template renders for a location on the current request.
	 */
	class UiChemy_Template_Resolver {

		/**
		 * Per-request memo of type => resolved post ID.
		 *
		 * @var array<string,int>
		 */
		private static $cache = array();

		/**
		 * Resolve the winning template ID for a location type.
		 *
		 * @param string $type Location type (header|footer|error_404).
		 * @return int Post ID, or 0 when none applies.
		 */
		public static function resolve( $type ) {
			$type = (string) $type;

			if ( isset( self::$cache[ $type ] ) ) {
				return self::$cache[ $type ];
			}

			$id = 0;

			if ( class_exists( 'UiChemy_Template_CPT' )
				&& class_exists( 'UiChemy_Template_Store' )
				&& UiChemy_Template_CPT::is_valid_type( $type )
			) {
				$active = UiChemy_Template_Store::get_active( $type );

				// header/footer are narrowed by page-level display conditions
				// (entire site + include/exclude specific posts): the best-matching
				// active template by specificity wins.
				//
				// single/archive are narrowed by TARGET (the queried context) with
				// page conditions applied within the matching target: a template
				// targeting the exact post type / taxonomy beats the "any" fallback.
				//
				// product_archive is narrowed by page-level conditions too, so a
				// shop template and a product-category template can both be active.
				//
				// search is narrowed by TARGET too: a site search is not one
				// context either. A shop and a blog on the same site want
				// different result layouts, and before this a second search
				// template had nowhere to go — "newest active wins" handed every
				// search to whichever was saved last, and any display conditions
				// set on a search template were saved, shown in the UI, and then
				// never evaluated.
				//
				// The remaining whole-context types (404, single_product,
				// product_archive, order_received, search) are not narrowed —
				// newest active wins.
				if ( in_array( $type, array( 'header', 'footer' ), true ) && self::viewing_header_footer_template() ) {
					// A header or footer template's own URL — where the Composer
					// Builder's canvas loads it — shows that template alone.
					// Wrapping it in the site's header and footer would put a
					// header being edited under the live one, or twice when it IS
					// the live one. A single / archive / 404 template is a page
					// body, so it keeps the site's header and footer around it,
					// as it will have on the front end.
					$id = 0;
				} elseif ( in_array( $type, array( 'header', 'footer' ), true ) ) {
					$id = self::best_match( $active );
					// UiChemy WINS the slot. If another theme-builder system
					// (Elementor Pro's elementor_library, Nexter's nxt_builder, or
					// the Bricks theme) also has an active template here, ours is the
					// one that renders and theirs is displaced — see
					// UiChemy_Locations, which removes the incumbent's callback
					// rather than rendering alongside it, so a slot never emits two
					// headers.
					//
					// This used to defer to the other system, which meant a user who
					// deliberately built a UiChemy header watched their old one keep
					// rendering with no indication why.
					//
					// Filter back to the old deferential behaviour per slot:
					//     add_filter( 'uichemy/theme_builder/defer_to_competing', '__return_true' );
					if ( $id && self::has_competing_active_template( $type )
						&& apply_filters( 'uichemy/theme_builder/defer_to_competing', false, $type, $id ) ) {
						$id = 0;
					}
				} elseif ( 'single' === $type ) {
					$id = self::pick_by_target( $active, self::single_priority() );
				} elseif ( 'archive' === $type ) {
					$id = self::pick_by_target( $active, self::archive_priority() );
				} elseif ( 'search' === $type ) {
					$id = self::pick_by_target( $active, self::search_priority() );
				} elseif ( 'product_archive' === $type ) {
					/*
					 * A store's listings are not one context. /shop/ and a product
					 * category archive routinely want different layouts, and an
					 * import that ships both ("Shop Home" + "Product listing") has
					 * nowhere to put the second one while this type is a single
					 * unnarrowed slot — "newest active wins" would just hand the
					 * whole store whichever imported last.
					 *
					 * Conditions decide instead, exactly as they do for
					 * header/footer. A template with no conditions still matches
					 * (at SPEC_ENTIRE), so a single shop template keeps behaving
					 * as before and only loses to one that names its context.
					 */
					$id = self::best_match( $active );
					if ( ! $id && ! empty( $active ) ) {
						$id = (int) $active[0];
					}
				} else {
					$id = ! empty( $active ) ? (int) $active[0] : 0;
				}
			}

			self::$cache[ $type ] = $id;
			return $id;
		}

		/**
		 * Is this request a header or footer template's own URL?
		 *
		 * @return bool
		 */
		private static function viewing_header_footer_template() {
			if ( ! is_singular( UiChemy_Template_CPT::POST_TYPE ) ) {
				return false;
			}

			$type = (string) get_post_meta( (int) get_queried_object_id(), UiChemy_Template_CPT::META_TYPE, true );

			return in_array( $type, array( 'header', 'footer' ), true );
		}

		/**
		 * From a newest-first list of active template IDs, return the one whose
		 * display conditions best match the current request, or 0 if none match.
		 *
		 * @param int[] $ids Active template IDs (newest first).
		 * @return int
		 */
		private static function best_match( $ids ) {
			if ( empty( $ids ) || ! class_exists( 'UiChemy_Conditions' ) ) {
				return 0;
			}

			$best      = 0;
			$best_spec = -1;
			foreach ( $ids as $id ) {
				$conditions = get_post_meta( $id, UiChemy_Template_CPT::META_CONDITIONS, true );
				$result     = UiChemy_Conditions::evaluate( $conditions );
				if ( $result['match'] && $result['specificity'] > $best_spec ) {
					$best      = (int) $id;
					$best_spec = (int) $result['specificity'];
				}
			}
			return $best;
		}

		/**
		 * From active templates, pick the one whose target best matches the
		 * current request. Walks $priorities most-specific first; within the first
		 * target that has active templates, applies page conditions (best_match).
		 * If that target's templates are all excluded by conditions, falls through
		 * to the next (less specific) target.
		 *
		 * @param int[]    $ids        Active template IDs (newest first).
		 * @param string[] $priorities Target keys, most specific first.
		 * @return int
		 */
		private static function pick_by_target( $ids, $priorities ) {
			if ( empty( $ids ) ) {
				return 0;
			}
			foreach ( $priorities as $want ) {
				$matches = array();
				foreach ( $ids as $id ) {
					if ( self::effective_target( $id ) === $want ) {
						$matches[] = (int) $id;
					}
				}
				if ( ! empty( $matches ) ) {
					$best = self::best_match( $matches );
					if ( $best ) {
						return $best;
					}
				}
			}
			return 0;
		}

		/**
		 * A template's target, as its display conditions state it.
		 *
		 * The stored target comes from the menu the template was made from —
		 * "Single (Post / Page)" gives `pt:post`. A template then set to show on
		 * "All of a post type › Doctor" kept `pt:post`, so a doctor page (which
		 * looks for `pt:doctor`, then `any`) never reached it, and the rule the
		 * user chose did nothing. When the include rules name exactly one post
		 * type, that is the target; otherwise the stored one stands.
		 *
		 * @param int $id Template ID.
		 * @return string
		 */
		private static function effective_target( $id ) {
			$stored     = UiChemy_Template_CPT::target_for( $id );
			$conditions = get_post_meta( $id, UiChemy_Template_CPT::META_CONDITIONS, true );
			$rules      = ( is_array( $conditions ) && isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ) ? $conditions['rules'] : array();
			$types      = array();
			$any        = false;
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ( isset( $rule['match'] ) && 'exclude' === $rule['match'] ) || ! isset( $rule['type'] ) ) {
					continue;
				}
				if ( 'post_type' === $rule['type'] && ! empty( $rule['value'] ) ) {
					$types[ (string) $rule['value'] ] = true;
				} elseif ( 'singular' === $rule['type'] && ! empty( $rule['value'] ) ) {
					// One particular page or post: its own type (D-02).
					$type = get_post_type( (int) $rule['value'] );
					if ( $type ) {
						$types[ $type ] = true;
					}
				} elseif ( in_array( $rule['type'], array( 'entire', 'all_singulars' ), true ) ) {
					// Every single view: the `any` target, tried for every type.
					$any = true;
				}
			}
			if ( $any || count( $types ) > 1 ) {
				return 'any';
			}
			return 1 === count( $types ) ? 'pt:' . key( $types ) : $stored;
		}

		/**
		 * Target keys to try for a single request, most specific first.
		 *
		 * @return string[]
		 */
		private static function single_priority() {
			$priorities = array();
			if ( is_front_page() ) {
				$priorities[] = 'front';
			}
			$post_type = get_post_type();
			if ( $post_type ) {
				$priorities[] = 'pt:' . $post_type;
			}
			$priorities[] = 'any';
			return $priorities;
		}

		/**
		 * Target keys to try for an archive request, most specific first.
		 *
		 * @return string[]
		 */
		private static function archive_priority() {
			$priorities = array();
			if ( is_home() ) {
				$priorities[] = 'blog';
			} elseif ( is_author() ) {
				$priorities[] = 'author';
			} elseif ( is_date() ) {
				$priorities[] = 'date';
			} elseif ( is_post_type_archive() ) {
				// A custom post type's own archive (E-06).
				$post_type = get_query_var( 'post_type' );
				$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
				if ( $post_type ) {
					$priorities[] = 'pt:' . $post_type;
				}
			} elseif ( is_tax() || is_category() || is_tag() ) {
				$obj = get_queried_object();
				if ( $obj && ! empty( $obj->taxonomy ) ) {
					$priorities[] = 'tax:' . $obj->taxonomy;
				}
			}
			$priorities[] = 'any';
			return $priorities;
		}

		/**
		 * Target keys to try for a search request, most specific first.
		 *
		 * The post type comes from the query itself, so `/?s=shirt&post_type=product`
		 * reaches a `pt:product` template while a bare `/?s=shirt` falls through to
		 * `any`. A multi-post-type search ( `post_type[]=post&post_type[]=page` )
		 * is deliberately treated as a general search: no single template owns it,
		 * so it belongs to the fallback rather than to whichever type happens to
		 * be listed first.
		 *
		 * @return string[]
		 */
		private static function search_priority() {
			$priorities = array();

			$post_type = self::searched_post_type();
			if ( '' !== $post_type ) {
				$priorities[] = 'pt:' . $post_type;
			}

			$priorities[] = 'any';
			return $priorities;
		}

		/**
		 * The single post type a search request is scoped to, or '' when it spans
		 * several (or none).
		 *
		 * Reads the query rather than $_GET: a search can be narrowed by a
		 * rewrite rule or by another plugin's pre_get_posts, and both are
		 * invisible to the raw query string.
		 *
		 * @param WP_Query|null $query Query to read. Defaults to the global one,
		 *                             which is right at template_include time but
		 *                             NOT inside pre_get_posts: query_posts()
		 *                             replaces $GLOBALS['wp_query'], so a caller
		 *                             holding the query being prepared must pass
		 *                             it rather than let this read a different one.
		 * @return string
		 */
		public static function searched_post_type( $query = null ) {
			$post_type = ( $query instanceof WP_Query ) ? $query->get( 'post_type' ) : get_query_var( 'post_type' );

			if ( is_array( $post_type ) ) {
				$post_type = ( 1 === count( $post_type ) ) ? reset( $post_type ) : '';
			}

			$post_type = is_string( $post_type ) ? sanitize_key( $post_type ) : '';

			// 'any' is WordPress's own "every searchable type" keyword, not a type.
			if ( '' === $post_type || 'any' === $post_type ) {
				return '';
			}

			return post_type_exists( $post_type ) ? $post_type : '';
		}

		/**
		 * Whether an active template applies to this request for a type.
		 *
		 * @param string $type Location type.
		 * @return bool
		 */
		public static function has_active( $type ) {
			return self::resolve( $type ) > 0;
		}

		/**
		 * Public read of the coexistence check, for reporting.
		 *
		 * The suppression itself is correct - two systems both rendering a header
		 * is worse than one - but it was INVISIBLE. A template could be created,
		 * flagged active, and then quietly stood down at render time because
		 * another builder owned the slot, with nothing in any response saying so.
		 *
		 * @param string $type 'header' or 'footer'.
		 * @return bool
		 */
		public static function competing_system_owns( $type ) {
			if ( ! in_array( (string) $type, array( 'header', 'footer' ), true ) ) {
				return false;
			}

			return self::has_competing_active_template( (string) $type );
		}

		/**
		 * Whether Elementor Pro or Nexter already has its OWN active template for
		 * this exact header/footer slot — used to decide slot ownership when both
		 * systems are present (UiChemy only takes the slot when neither does).
		 *
		 * @param string $type 'header' or 'footer'.
		 * @return bool
		 */
		private static function has_competing_active_template( $type ) {
			$ep_posts = get_posts( array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs once per request, memoised by the resolver cache.
				'meta_query'     => array(
					array( 'key' => '_elementor_template_type', 'value' => $type ),
				),
			) );
			foreach ( $ep_posts as $pid ) {
				$conditions = get_post_meta( (int) $pid, '_elementor_conditions', true );
				if ( ! empty( $conditions ) && is_array( $conditions ) ) {
					return true;
				}
			}

			if ( post_type_exists( 'nxt_builder' ) ) {
				$nxt_posts = get_posts( array(
					'post_type'      => 'nxt_builder',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required meta query for template/widget matching; runs once per request, memoised by the resolver cache.
					'meta_query'     => array(
						'relation' => 'AND',
						array( 'key' => 'nxt-hooks-layout-sections', 'value' => $type ),
						array( 'key' => 'nxt_build_status', 'value'   => '1' ),
					),
				) );
				if ( ! empty( $nxt_posts ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Clear the per-request memo (used by tests / after template writes).
		 *
		 * @return void
		 */
		public static function flush_cache() {
			self::$cache = array();
		}
	}
}
