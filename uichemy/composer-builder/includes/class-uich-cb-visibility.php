<?php
/**
 * Uich_CB_Visibility — who may see a post on the live site.
 *
 * Set per item in the editor's CMS (top bar › CMS › an item › Visibility). The
 * rules are the Theme Builder's own, in its own shape and through its own
 * sanitizer and evaluator (UiChemy_Template_Store / UiChemy_Conditions), but
 * only the rules about the VISITOR and the TIME — login status, user role, day
 * of week, operating system, browser — since a rule about which page is being
 * viewed means nothing for one post:
 *
 *   _uich_cb_visibility = { rules: [ { match: include|exclude, type, value } ] }
 *
 * How a post reads it:
 *   no rules              everyone sees it
 *   any include rules     at least one of them must match
 *   any exclude rule      that matches hides it, whatever the includes say
 *
 * A hidden post is taken out of every front-end query (pre_get_posts adds it
 * to `post__not_in`, and posts_results drops it from queries that ignore that,
 * such as `p` and `post__in`): its own page answers 404 and it is missing from
 * the blog, archives, search, feeds, sitemaps and UiChemy's loops, for that
 * visitor. Reads that run no query are closed too: a REST item, oEmbed, the
 * previous / next links and Twig's get_post() (Uich_Functions::post_visible()).
 * wp-admin is left alone, and in the editor's REST calls, previews and the
 * builder canvas a user still finds every post they can edit.
 *
 * A page that varies by visitor is not safely cacheable as one page — the same
 * caveat as the Theme Builder's visitor rules. Full-page caches generally skip
 * logged-in visitors, so login and role rules behave; day, OS and browser rules
 * need a cache that varies on them.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Visibility' ) ) {

	/**
	 * Per-post visibility conditions.
	 *
	 * @since 5.4.1
	 */
	final class Uich_CB_Visibility {

		/**
		 * Post meta holding a post's rules.
		 */
		const META = '_uich_cb_visibility';

		/**
		 * The rule types a post's visibility can use: about who is visiting and
		 * when, never about which page is being viewed.
		 *
		 * @var string[]
		 */
		const RULE_TYPES = array( 'login_status', 'user_role', 'day_of_week', 'os', 'browser' );

		/**
		 * This request's hidden post IDs, worked out once.
		 *
		 * @var int[]|null
		 */
		private static $hidden = null;

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ), 20 );
			add_filter( 'posts_results', array( __CLASS__, 'filter_results' ), 20, 2 );
			add_filter( 'oembed_request_post_id', array( __CLASS__, 'filter_oembed' ), 20 );
			add_filter( 'get_previous_post_where', array( __CLASS__, 'filter_adjacent' ), 20 );
			add_filter( 'get_next_post_where', array( __CLASS__, 'filter_adjacent' ), 20 );
			add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'filter_rest_item' ), 20, 3 );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Rules
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Can rules be evaluated here? They rely on the Theme Builder's classes.
		 *
		 * @return bool
		 */
		public static function available() {
			return class_exists( 'UiChemy_Conditions' ) && class_exists( 'UiChemy_Template_Store' );
		}

		/**
		 * Clean a rule list: the Theme Builder's sanitizer, then only the
		 * visitor and time rules.
		 *
		 * @param mixed $rules Raw rules.
		 * @return array<int,array>
		 */
		public static function sanitize( $rules ) {
			if ( ! self::available() ) {
				return array();
			}
			$clean = UiChemy_Template_Store::sanitize_condition_rules( $rules );

			return array_values(
				array_filter(
					$clean,
					static function ( $rule ) {
						return in_array( $rule['type'], self::RULE_TYPES, true );
					}
				)
			);
		}

		/**
		 * A post's rules, or an empty list.
		 *
		 * @param int $post_id Post ID.
		 * @return array<int,array>
		 */
		public static function get( $post_id ) {
			$stored = get_post_meta( (int) $post_id, self::META, true );
			return ( is_array( $stored ) && isset( $stored['rules'] ) ) ? self::sanitize( $stored['rules'] ) : array();
		}

		/**
		 * Save a post's rules; an empty list removes them (visible to everyone).
		 *
		 * @param int   $post_id Post ID.
		 * @param mixed $rules   Raw rules.
		 * @return array<int,array> The rules as stored.
		 */
		public static function set( $post_id, $rules ) {
			$clean = self::sanitize( $rules );
			if ( $clean ) {
				update_post_meta( (int) $post_id, self::META, array( 'rules' => $clean ) );
			} else {
				delete_post_meta( (int) $post_id, self::META );
			}
			self::$hidden = null;
			return $clean;
		}

		/**
		 * Does this rule list let the current visitor see the post?
		 *
		 * The Theme Builder's evaluator answers "does any include match, and no
		 * exclude?". A list with only excludes means "everyone except…", so it
		 * is given an include-everyone first.
		 *
		 * @param array $rules Sanitized rules.
		 * @return bool
		 */
		public static function allows( array $rules ) {
			if ( ! $rules || ! self::available() ) {
				return true;
			}
			$has_include = false;
			foreach ( $rules as $rule ) {
				if ( 'include' === $rule['match'] ) {
					$has_include = true;
					break;
				}
			}
			if ( ! $has_include ) {
				array_unshift(
					$rules,
					array(
						'match' => 'include',
						'type'  => 'entire',
					)
				);
			}

			return UiChemy_Conditions::matches( array( 'rules' => $rules ) );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * The live site
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Is this a request where visibility applies?
		 *
		 * @return bool
		 */
		private static function applies() {
			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return false;
			}

			/**
			 * Filter whether per-post visibility rules apply to this request.
			 *
			 * @since 5.4.1
			 *
			 * @param bool $applies Whether they apply.
			 */
			return (bool) apply_filters( 'uich_cb/visibility_applies', true );
		}

		/**
		 * Is this the editor at work? Its CMS reads posts over REST, and the
		 * builder canvas is a front-end page — but only for someone who can
		 * edit: the canvas switch is a plain query parameter anyone could add.
		 *
		 * @return bool
		 */
		private static function editing() {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return false;
			}
			return ( defined( 'REST_REQUEST' ) && REST_REQUEST )
				|| ( class_exists( 'Uich_CB_Canvas' ) && Uich_CB_Canvas::is_canvas() );
		}

		/**
		 * Is this post hidden from the current visitor?
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function is_hidden( $post_id ) {
			$post_id = (int) $post_id;
			return $post_id && self::applies() && in_array( $post_id, self::hidden_ids(), true );
		}

		/**
		 * The posts hidden from the current visitor.
		 *
		 * Read straight from post meta — a WP_Query here would run this same
		 * hook again — and evaluated once per request. While editing, a post the
		 * user can edit is never hidden from them; one they cannot edit still is.
		 *
		 * @return int[]
		 */
		public static function hidden_ids() {
			if ( null !== self::$hidden ) {
				return self::$hidden;
			}
			self::$hidden = array();
			$editing      = self::editing();

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Hook-safe read of one meta key; a WP_Query would recurse into pre_get_posts.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );

			foreach ( (array) $rows as $row ) {
				$stored = maybe_unserialize( $row->meta_value );
				$rules  = ( is_array( $stored ) && isset( $stored['rules'] ) ) ? self::sanitize( $stored['rules'] ) : array();
				if ( $rules && ! self::allows( $rules ) && ! ( $editing && current_user_can( 'edit_post', (int) $row->post_id ) ) ) {
					self::$hidden[] = (int) $row->post_id;
				}
			}

			return self::$hidden;
		}

		/**
		 * pre_get_posts: leave the hidden posts out of every front-end query.
		 *
		 * The main query of a hidden post's own page then finds nothing, which
		 * WordPress answers with a 404 — the same as a post that does not exist,
		 * so nothing about it is revealed.
		 *
		 * @param WP_Query $query Query.
		 * @return void
		 */
		public static function filter_query( $query ) {
			if ( ! $query instanceof WP_Query || $query->get( 'uich_cb_ignore_visibility' ) ) {
				return;
			}
			if ( $query->is_main_query() && $query->is_preview() ) {
				return;
			}
			if ( ! self::applies() ) {
				return;
			}

			$hidden = self::hidden_ids();
			if ( ! $hidden ) {
				return;
			}

			$not_in = (array) $query->get( 'post__not_in' );
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Short list of posts gated by visibility rules.
			$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'absint', $not_in ), $hidden ) ) ) );
		}

		/**
		 * posts_results: drop hidden posts from what a query found.
		 *
		 * Core ignores `post__not_in` whenever `p` or `post__in` is set, so
		 * filter_query() alone lets `?p=ID` and `post__in` loops through. A
		 * preview keeps the post for someone who can edit it.
		 *
		 * @param array    $posts Posts found.
		 * @param WP_Query $query Query.
		 * @return array
		 */
		public static function filter_results( $posts, $query ) {
			if ( ! $posts || ! is_array( $posts ) || ! $query instanceof WP_Query || $query->get( 'uich_cb_ignore_visibility' ) || ! self::applies() ) {
				return $posts;
			}
			$hidden = self::hidden_ids();
			if ( ! $hidden ) {
				return $posts;
			}
			$preview = $query->is_main_query() && $query->is_preview();

			return array_values(
				array_filter(
					$posts,
					static function ( $post ) use ( $hidden, $preview ) {
						$id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
						if ( ! in_array( $id, $hidden, true ) ) {
							return true;
						}
						return $preview && current_user_can( 'edit_post', $id );
					}
				)
			);
		}

		/**
		 * oembed_request_post_id: no embed of a hidden post.
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		public static function filter_oembed( $post_id ) {
			return self::is_hidden( $post_id ) ? 0 : $post_id;
		}

		/**
		 * get_{previous,next}_post_where: never link to a hidden post.
		 *
		 * @param string $where WHERE clause.
		 * @return string
		 */
		public static function filter_adjacent( $where ) {
			if ( ! self::applies() ) {
				return $where;
			}
			$hidden = self::hidden_ids();
			if ( ! $hidden ) {
				return $where;
			}
			return $where . ' AND p.ID NOT IN (' . implode( ',', array_map( 'absint', $hidden ) ) . ')';
		}

		/**
		 * rest_request_before_callbacks: a single REST item (wp/v2/<base>/<id>)
		 * is read with get_post(), not a query, so a hidden post is answered as
		 * the missing post it is for this visitor. Refused here, before the
		 * controller runs: an error returned later, from rest_prepare_*, is not
		 * something core's controllers handle.
		 *
		 * @param mixed           $response Response so far.
		 * @param array           $handler  Route handler.
		 * @param WP_REST_Request $request  Request.
		 * @return mixed
		 */
		public static function filter_rest_item( $response, $handler, $request ) {
			if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
				return $response;
			}
			if ( ! preg_match( '#^/wp/v2/[^/]+/(\d+)(?:/|$)#', $request->get_route(), $m ) ) {
				return $response;
			}
			if ( self::is_hidden( (int) $m[1] ) ) {
				return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'uichemy' ), array( 'status' => 404 ) );
			}
			return $response;
		}
	}
}
