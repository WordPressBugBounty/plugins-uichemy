<?php
/**
 * Uich_Functions â€” whitelisted functions callable from templates.
 *
 * These power loops: get_posts() returns an array of provider objects you can {% for %} over,
 * plus a few utility helpers. Security: queries are capped and only viewable post types are
 * returned.
 *
 * The Pro loop sources (get_products, get_terms, get_users, get_api), the pagination and
 * Load-more renderers and breadcrumbs are added by class-uich-functions-pro.php through the
 * `uich_dynamic_functions` filter — that file ships only in Pro. See docs/free-pro-split-plan.md.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Functions' ) ) {
	class Uich_Functions {

		const MAX_ITEMS = 100;
		const RANGE_MAX = 1000;

		/** @var array<string,callable> */
		private static $registry = null;

		/** Post IDs already rendered this request â€” powers `avoid_duplicates`. */
		private static $seen_posts = array();

		/** Pagination state of the last paginated query â€” powers loop_pagination(). */
		private static $last_pagination = array(
			'total'   => 0,
			'current' => 1,
		);

		/**
		 * Offset state of the last "Load more" query â€” powers loop_load_more().
		 *
		 * Load more is offset-based, not page-based, because the first render and each
		 * subsequent click can show a DIFFERENT number of items ("show 9, then 3 per
		 * click"). Uniform page numbers cannot express that, so the URL carries how
		 * many items have already been shown rather than a page index.
		 */
		private static $last_loop_more = array(
			'found' => 0,
			'shown' => 0,
		);

		/** Upper bound on ?loop_more= so a crafted URL can't force a deep offset scan. */
		const MAX_OFFSET = 10000;

		/**
		 * Pagination state left by the last query_posts() run.
		 *
		 * query_posts() lives here because get_posts is a Free loop source, but the
		 * renderers that consume this state (loop_pagination, loop_load_more) are Pro
		 * and live in class-uich-functions-pro.php — hence the read accessors.
		 *
		 * @return array{total:int,current:int}
		 */
		public static function last_pagination() {
			return self::$last_pagination;
		}

		/**
		 * Offset state left by the last "Load more" query. See last_pagination().
		 *
		 * @return array{found:int,shown:int}
		 */
		public static function last_loop_more() {
			return self::$last_loop_more;
		}

		public static function call( $name, $args = array() ) {
			$reg = self::registry();
			if ( ! isset( $reg[ $name ] ) ) {
				return null;
			}
			return call_user_func( $reg[ $name ], $args );
		}

		private static function registry() {
			if ( null !== self::$registry ) {
				return self::$registry;
			}

			$fns = array();

			/*
			---- single getters -------------------------------------------- */
			// get_user() and get_term() return Pro providers, so they are registered by
			// class-uich-functions-pro.php instead of here.
			$fns['get_post']  = function ( $a ) {
				$id = isset( $a[0] ) ? (int) $a[0] : 0;
				return $id ? Uich_Dynamic::post_provider( get_post( $id ) ) : null;
			};
			$fns['get_image'] = function ( $a ) {
				$id = isset( $a[0] ) ? (int) $a[0] : 0;
				return $id ? new Uich_Image_Provider( $id ) : null;
			};

			/*
			---- collection queries (loops) -------------------------------- */
			// Free loops over POSTS. Products, Terms, Users and External API are Pro
			// and are added by class-uich-functions-pro.php through the
			// `uich_dynamic_functions` filter below â€” that file does not exist in Free,
			// so those functions are simply unknown here and a loop using one renders
			// its {% else %} empty state. See docs/free-pro-split-plan.md.
			$fns['get_posts'] = function ( $a ) {
				return self::query_posts( $a, null );
			};

			// current_page() stays: it only reads a URL parameter and a stored loop may
			// carry `paged: current_page()`. The pagination RENDERERS (loop_pagination,
			// loop_load_more) and the Load-more offset helpers are Pro.
			$fns['current_page'] = function () {
				return self::current_page();
			};

			/* ---- utility --------------------------------------------------- */
			$fns['range']        = function ( $a ) {
				$start = isset( $a[0] ) ? (int) $a[0] : 0;
				$end   = isset( $a[1] ) ? (int) $a[1] : 0;
				$step  = isset( $a[2] ) ? (int) $a[2] : 1;
				// Cap span to prevent a template from allocating a huge array (DoS).
				if ( abs( $end - $start ) > self::RANGE_MAX ) {
					$end = $start + ( ( $end >= $start ? 1 : -1 ) * self::RANGE_MAX );
				}
				return range( $start, $end, max( 1, abs( $step ) ) );
			};
			$fns['min']          = function ( $a ) {
				return self::flat_numeric( $a, 'min' );
			};
			$fns['max']          = function ( $a ) {
				return self::flat_numeric( $a, 'max' );
			};
			$fns['date']         = function ( $a ) {
				$expr = isset( $a[0] ) ? (string) $a[0] : 'now';
				return ( 'now' === $expr ) ? time() : strtotime( $expr );
			};
			$fns['html_classes'] = function ( $a ) {
				$out = array();
				foreach ( $a as $item ) {
					if ( is_string( $item ) && '' !== $item ) {
						$out[] = $item;
					} elseif ( is_array( $item ) ) {
						foreach ( $item as $cls => $on ) {
							if ( $on ) {
								$out[] = $cls;
							}
						}
					}
				}
				return implode( ' ', array_map( 'sanitize_html_class', $out ) );
			};

			/**
			 * Let this build add its own template functions.
			 *
			 * Pro adds the Products / Terms / Users / External-API loop sources, the
			 * pagination and Load-more renderers, and breadcrumbs â€” see
			 * includes/dynamic/class-uich-functions-pro.php, which Free does not ship.
			 * An unknown function resolves to nothing in the engine, so a stored
			 * template that calls one renders empty rather than erroring.
			 *
			 * @param callable[] $fns Function name => callable.
			 */
			self::$registry = apply_filters( 'uich_dynamic_functions', $fns );
			return self::$registry;
		}

		/** Shared WP_Query runner for get_posts/get_products. */
		public static function query_posts( $a, $force_type ) {
			$args     = self::normalize_args( $a );
			$defaults = array(
				'post_status'         => 'publish',
				'posts_per_page'      => 10,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			);
			// `avoid_duplicates` is ours, not a WP_Query key â€” pull it out before the query runs.
			$avoid = ! empty( $args['avoid_duplicates'] );
			unset( $args['avoid_duplicates'] );
			// `sticky` is ours as well. WP core only floats sticky posts on the main home
			// query -- WP_Query::get_posts() guards that behind `$this->is_home` -- so a
			// widget loop never inherits it. We resolve the IDs and do the float ourselves.
			$sticky_mode = '';
			if ( isset( $args['sticky'] ) ) {
				$sticky_mode = ( true === $args['sticky'] ) ? 'first' : strtolower( trim( (string) $args['sticky'] ) );
				if ( in_array( $sticky_mode, array( '1', 'true', 'yes' ), true ) ) {
					$sticky_mode = 'first';
				}
			}
			unset( $args['sticky'] );
			$sticky_ids = array();
			if ( in_array( $sticky_mode, array( 'first', 'only', 'exclude' ), true ) ) {
				$sticky_ids = array_values( array_filter( array_map( 'intval', (array) get_option( 'sticky_posts' ) ) ) );
			}

			$args = wp_parse_args( $args, $defaults );
			if ( $force_type ) {
				$args['post_type'] = $force_type;
			} elseif ( empty( $args['post_type'] ) ) {
				$args['post_type'] = 'post';
			}
			// Pagination needs the total-pages count, so disable the no_found_rows shortcut.
			$paged = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 0;
			if ( $paged ) {
				$args['paged']         = $paged;
				$args['no_found_rows'] = false;
			}
			// Load more works the same way but by offset: it needs found_posts to know
			// whether anything is left, which no_found_rows would otherwise skip.
			$has_offset = isset( $args['offset'] );
			$offset     = $has_offset ? max( 0, (int) $args['offset'] ) : 0;
			if ( $has_offset ) {
				$args['offset']        = $offset;
				$args['no_found_rows'] = false;
			}
			// Exclude posts already shown by earlier loops on this page.
			if ( $avoid && ! empty( self::$seen_posts ) ) {
				$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
				$args['post__not_in'] = array_values( array_unique( array_merge( $existing, self::$seen_posts ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Intentional; VIP-only advisory, acceptable for this query.
			}
			// Cap result size for safety/performance.
			$ppp = (int) $args['posts_per_page'];
			if ( $ppp < 0 || $ppp > self::MAX_ITEMS ) {
				$args['posts_per_page'] = self::MAX_ITEMS;
			}
			// Only allow public, queryable post types.
			$pt = (array) $args['post_type'];
			foreach ( $pt as $t ) {
				if ( ! post_type_exists( $t ) ) {
					return array();
				}
			}
			$args = apply_filters( 'uich_dynamic_get_posts_args', $args );

			// Sticky handling. `only` and `exclude` are plain arg tweaks. `first` needs a
			// second query, because a single query cannot both float the sticky posts and
			// leave everything else in the caller's own order.
			$sticky_items = array();
			$sticky_shown = 0;
			$sticky_total = 0;
			$eff_ppp      = (int) $args['posts_per_page'];
			$run_main     = true;
			if ( 'only' === $sticky_mode ) {
				$args['post__in'] = self::sticky_in( $args, $sticky_ids );
				if ( empty( $args['post__in'] ) ) {
					return array();
				}
			} elseif ( 'exclude' === $sticky_mode && $sticky_ids ) {
				$args['post__not_in'] = self::sticky_not_in( $args, $sticky_ids ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Intentional; VIP-only advisory, acceptable for this query.
			} elseif ( 'first' === $sticky_mode && $sticky_ids ) {
				$first_batch = ( $paged <= 1 && ! $offset );
				$s_in        = self::sticky_in( $args, $sticky_ids );
				if ( $s_in ) {
					// Cloned from $args on purpose: post_type, taxonomies, author and date
					// filters must apply to the stickies too, or a sticky post from some
					// other category walks into this loop.
					$s_args                   = $args;
					$s_args['post__in']       = $s_in;
					$s_args['posts_per_page'] = self::MAX_ITEMS;
					$s_args['no_found_rows']  = true;
					unset( $s_args['paged'], $s_args['offset'] );
					if ( ! $first_batch ) {
						// Later pages only need the count, to know how many slots page 1 ate.
						$s_args['fields'] = 'ids';
					}
					$sq           = new WP_Query( $s_args );
					$sticky_total = count( $sq->posts );
					$sticky_shown = $eff_ppp > 0 ? min( $sticky_total, $eff_ppp ) : $sticky_total;
					if ( $first_batch ) {
						foreach ( array_slice( $sq->posts, 0, $sticky_shown ) as $post ) {
							if ( $avoid ) {
								self::$seen_posts[] = (int) $post->ID;
							}
							$sticky_items[] = Uich_Dynamic::post_provider( $post );
						}
					}
				}
				// Ask for 6 and 6 comes back: the main query gives up one seat per sticky
				// post already on screen. Page 1 is therefore shorter than a full page,
				// which is why the pages after it walk by offset rather than by `paged`.
				$page_one_cap = max( 0, $eff_ppp - $sticky_shown );
				if ( $has_offset ) {
					$args['offset'] = max( 0, $offset - $sticky_shown );
					if ( $first_batch ) {
						$args['posts_per_page'] = $page_one_cap;
						$run_main               = $page_one_cap > 0;
					}
				} elseif ( $paged > 1 ) {
					$args['offset'] = $page_one_cap + ( $paged - 2 ) * $eff_ppp;
					unset( $args['paged'] );
				} else {
					$args['posts_per_page'] = $page_one_cap;
					$run_main               = $page_one_cap > 0;
				}
				// Excluded on EVERY page, not just page 1. Otherwise a sticky post appears
				// twice: floated at the top, and again in its natural date position.
				$args['post__not_in'] = self::sticky_not_in( $args, $sticky_ids ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Intentional; VIP-only advisory, acceptable for this query.
			}

			$q     = null;
			$items = array();
			if ( $run_main ) {
				$q = new WP_Query( $args );
				foreach ( $q->posts as $post ) {
					if ( $avoid ) {
						self::$seen_posts[] = (int) $post->ID;
					}
					// One factory decides which provider this build wraps a post in — Free's
					// four-field one, or Pro's full/Product provider. Choosing the Product
					// class here would mean naming a class Free does not ship.
					$items[] = Uich_Dynamic::post_provider( $post );
				}
			}
			if ( $sticky_items ) {
				$items = array_merge( $sticky_items, $items );
			}
			if ( $q && $paged ) {
				$total = (int) $q->max_num_pages;
				if ( $sticky_shown ) {
					// max_num_pages assumes every page holds posts_per_page rows, but page 1
					// is short by the stickies sitting on it. Count it ourselves.
					$rest  = max( 0, (int) $q->found_posts - max( 0, $eff_ppp - $sticky_shown ) );
					$total = 1 + ( $eff_ppp > 0 ? (int) ceil( $rest / $eff_ppp ) : 0 );
				}
				self::$last_pagination = array(
					'total'   => $total,
					'current' => $paged,
				);
			} elseif ( $paged ) {
				// posts_per_page was fully spent on stickies, so there is no main query to
				// read found_posts from -- reachable when a loop asks for fewer posts than
				// it has stickies. Count the remainder separately, or the pager would report
				// a single page and hide everything after this one.
				$c_args                   = $args;
				$c_args['fields']         = 'ids';
				$c_args['posts_per_page'] = 1;
				$c_args['paged']          = 1;
				$c_args['no_found_rows']  = false;
				unset( $c_args['offset'] );
				$cq                    = new WP_Query( $c_args );
				$rest                  = max( 0, (int) $cq->found_posts - max( 0, $eff_ppp - $sticky_shown ) );
				self::$last_pagination = array(
					'total'   => 1 + ( $eff_ppp > 0 ? (int) ceil( $rest / $eff_ppp ) : 0 ),
					'current' => $paged,
				);
			}
			if ( $has_offset ) {
				self::$last_loop_more = array(
					'found' => ( $q ? (int) $q->found_posts : 0 ) + $sticky_total,
					'shown' => $offset + count( $items ),
				);
			}
			wp_reset_postdata();
			return $items;
		}

		/**
		 * Sticky IDs narrowed to what the caller's own post__in / post__not_in allow.
		 *
		 * An existing post__not_in has to be folded in by hand: WP_Query builds the two
		 * as an if/elseif (see WP_Query::get_posts()), so a post__in silently voids it.
		 *
		 * @param array $args WP_Query args as built so far.
		 * @param int[] $ids  Sticky post IDs.
		 * @return int[]
		 */
		private static function sticky_in( $args, $ids ) {
			if ( ! empty( $args['post__in'] ) ) {
				$ids = array_intersect( $ids, array_map( 'intval', (array) $args['post__in'] ) );
			}
			if ( ! empty( $args['post__not_in'] ) ) {
				$ids = array_diff( $ids, array_map( 'intval', (array) $args['post__not_in'] ) );
			}
			return array_values( $ids );
		}

		/**
		 * Add IDs to post__not_in without dropping whatever is already excluded.
		 *
		 * @param array $args WP_Query args as built so far.
		 * @param int[] $ids  IDs to exclude.
		 * @return int[]
		 */
		private static function sticky_not_in( $args, $ids ) {
			$existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			return array_values( array_unique( array_merge( array_map( 'intval', $existing ), $ids ) ) );
		}


		/** Current loop page from the ?loop_page= param (falls back to the main paged var). */
		public static function current_page() {
			$p = isset( $_GET['loop_page'] ) ? (int) $_GET['loop_page'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination nav param.
			if ( $p < 1 ) {
				$p = (int) get_query_var( 'paged' );
			}
			if ( $p < 1 ) {
				$p = (int) get_query_var( 'page' );
			}
			return max( 1, $p );
		}


		/** Accept either a single assoc-array arg ({...}) or flat args. */
		public static function normalize_args( $a ) {
			if ( isset( $a[0] ) && is_array( $a[0] ) ) {
				return $a[0];
			}
			return is_array( $a ) ? $a : array();
		}

		private static function flat_numeric( $a, $fn ) {
			$vals = array();
			foreach ( $a as $item ) {
				if ( is_array( $item ) ) {
					$vals = array_merge( $vals, array_map( 'floatval', $item ) );
				} elseif ( is_numeric( $item ) ) {
					$vals[] = (float) $item;
				}
			}
			if ( empty( $vals ) ) {
				return null;
			}
			return 'min' === $fn ? min( $vals ) : max( $vals );
		}
	}
}
