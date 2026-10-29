<?php
/**
 * UiChemy_Conditions — evaluate a template's display conditions for the request.
 *
 * Two coexisting shapes (additive, backward compatible):
 *
 *  Legacy (v1): entire site + explicit include/exclude of post IDs.
 *     array( 'scope' => 'entire'|'specific', 'include' => int[], 'exclude' => int[] )
 *
 *  Rules (v2): a list of include/exclude rules. When a non-empty `rules` array
 *  is present it takes precedence; otherwise the legacy fields are used, so old
 *  saved templates (and the pre-rebuild UI) keep working unchanged.
 *     array( 'rules' => array(
 *        array( 'match' => 'include'|'exclude', 'type' => 'entire' ),
 *        array( 'match' => ..., 'type' => 'post_type', 'value' => 'post' ),
 *        array( 'match' => ..., 'type' => 'taxonomy', 'taxonomy' => 'category', 'term' => 0 ),
 *        array( 'match' => ..., 'type' => 'author', 'value' => 0 ),
 *        array( 'match' => ..., 'type' => 'singular', 'value' => 123 ),
 *        array( 'match' => ..., 'type' => 'search' ),
 *     ) )
 *
 * @package UiChemy
 * @subpackage UiChemy/theme-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Conditions' ) ) {

	/**
	 * Matches template display conditions against the current request.
	 */
	class UiChemy_Conditions {

		/** Specificity weights, broad → narrow. Higher wins in the resolver. */
		const SPEC_ENTIRE   = 1;
		const SPEC_ARCHIVE  = 4;  // search / any-author / any-term archive kind
		const SPEC_POSTTYPE = 5;  // all of a post type
		const SPEC_GROUP    = 7;  // any-term-in-taxonomy / specific author
		const SPEC_SPECIFIC = 10; // a specific term or a specific post ID

		/**
		 * Evaluate a conditions set against the current request.
		 *
		 * @param mixed $conditions Raw or normalized conditions.
		 * @return array{match:bool,specificity:int}
		 */
		public static function evaluate( $conditions ) {
			$c = class_exists( 'UiChemy_Template_Store' )
				? UiChemy_Template_Store::sanitize_conditions( $conditions )
				: self::fallback_normalize( $conditions );

			if ( ! empty( $c['rules'] ) && is_array( $c['rules'] ) ) {
				return self::evaluate_rules( $c['rules'] );
			}

			return self::evaluate_legacy( $c );
		}

		/**
		 * Whether the conditions match the current request.
		 *
		 * @param mixed $conditions Conditions.
		 * @return bool
		 */
		public static function matches( $conditions ) {
			$result = self::evaluate( $conditions );
			return (bool) $result['match'];
		}

		/* ── Rules (v2) ──────────────────────────────────────────────────── */

		/**
		 * Evaluate the rule list. An exclude rule that matches removes the whole
		 * template (even site-wide); otherwise the highest-specificity matching
		 * include rule wins.
		 *
		 * @param array $rules Sanitized rules.
		 * @return array{match:bool,specificity:int}
		 */
		private static function evaluate_rules( array $rules ) {
			$best = -1;
			foreach ( $rules as $rule ) {
				$spec = self::rule_specificity( $rule );
				$hit  = self::rule_matches( $rule );
				if ( ! $hit ) {
					continue;
				}
				if ( 'exclude' === $rule['match'] ) {
					return array(
						'match'       => false,
						'specificity' => 0,
					);
				}
				if ( $spec > $best ) {
					$best = $spec;
				}
			}

			return array(
				'match'       => $best >= 0,
				'specificity' => max( 0, $best ),
			);
		}

		/**
		 * Tell page caches (WP Rocket, W3TC, LiteSpeed, most hosts) not to keep
		 * this response, once per request.
		 *
		 * @return void
		 */
		private static function no_page_cache() {
			static $done = false;
			if ( $done || is_admin() ) {
				return;
			}
			$done = true;
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				nocache_headers();
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
		}

		/**
		 * Whether a single rule matches the current request.
		 *
		 * @param array $rule Sanitized rule.
		 * @return bool
		 */
		private static function rule_matches( array $rule ) {
			$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';

			// The answer depends on the visitor or the day, not the URL, so this
			// page must not be cached as one page for everyone (D-03).
			if ( in_array( $type, array( 'login_status', 'user_role', 'day_of_week', 'os', 'browser' ), true ) ) {
				self::no_page_cache();
			}

			switch ( $type ) {
				case 'entire':
					return true;

				case 'post_type':
					if ( ! is_singular() ) {
						return false;
					}
					return get_post_type( self::object_id() ) === (string) $rule['value'];

				case 'singular':
					// `value` is one post id or a list of them; the rule matches
					// the view if it names the post being viewed.
					$current = self::norm_post( self::object_id() );
					foreach ( (array) $rule['value'] as $wanted ) {
						if ( $current === self::norm_post( (int) $wanted ) ) {
							return true;
						}
					}
					return false;

				case 'author':
					$author = (int) $rule['value'];
					if ( is_author() ) {
						return 0 === $author || (int) get_queried_object_id() === $author;
					}
					/*
					 * A NAMED author also claims that author's own posts — "show
					 * this header on everything by Priya" is a real placement.
					 *
					 * "Any author" (0) does not. Every post has an author, so on a
					 * singular the old check returned true for all of them: a rule
					 * the UI calls "Author Archive" quietly took over every post,
					 * page and product on the site. That is why the importer could
					 * not use this rule for an author design at all.
					 */
					if ( $author > 0 && is_singular() ) {
						$post = get_post( self::object_id() );
						return $post && (int) $post->post_author === $author;
					}
					return false;

				case 'search':
					/*
					 * An optional post type narrows the rule to ONE search surface.
					 * Without it a blog header scoped to "Search Results" also won
					 * /?s=x&post_type=product, because every search looked alike —
					 * which is the same mix-up post-type search templates exist to
					 * fix, one layer up.
					 */
					if ( ! is_search() ) {
						return false;
					}
					$searched = isset( $rule['value'] ) ? sanitize_key( (string) $rule['value'] ) : '';
					if ( '' === $searched || 'any' === $searched ) {
						return true;
					}
					if ( ! class_exists( 'UiChemy_Template_Resolver' ) ) {
						return true;
					}
					return UiChemy_Template_Resolver::searched_post_type() === $searched;

				case 'taxonomy':
					return self::taxonomy_matches(
						(string) $rule['taxonomy'],
						(int) $rule['term']
					);

				/* ── Standard ───────────────────────────────────────────────── */
				case 'all_singulars':
					return is_singular();

				case 'all_archives':
					// "Archive" as a reader means any listing page, which includes
					// the posts page and search results — both are archives in
					// every practical sense even though WordPress answers false to
					// is_archive() on them.
					return is_archive() || is_home() || is_search();

				/* ── Default pages ──────────────────────────────────────────── */
				case 'front_page':
					return is_front_page();

				case 'blog':
					return is_home();

				case 'date':
					return is_date();

				case 'error_404':
					return is_404();

				case 'woo_shop':
					return function_exists( 'is_shop' ) && is_shop();

				/* ── Date & time ────────────────────────────────────────────── */
				case 'day_of_week':
					// ISO-8601: 1 = Monday … 7 = Sunday, read in site time so the
					// rule means the day the SITE is having, not the server.
					$want = absint( isset( $rule['value'] ) ? $rule['value'] : 0 );
					return $want >= 1 && $want <= 7 && (int) current_time( 'N' ) === $want;

				/* ── Visitor ────────────────────────────────────────────────── */
				case 'login_status':
					return 'logged_out' === ( isset( $rule['value'] ) ? $rule['value'] : '' )
						? ! is_user_logged_in()
						: is_user_logged_in();

				case 'user_role':
					$role = isset( $rule['value'] ) ? sanitize_key( $rule['value'] ) : '';
					if ( '' === $role || ! is_user_logged_in() ) {
						return false;
					}
					$user = wp_get_current_user();
					return $user && in_array( $role, (array) $user->roles, true );

				case 'os':
					return self::ua_matches( 'os', isset( $rule['value'] ) ? $rule['value'] : '' );

				case 'browser':
					return self::ua_matches( 'browser', isset( $rule['value'] ) ? $rule['value'] : '' );
			}

			return false;
		}

		/**
		 * Match the visitor's user agent against an OS or browser key.
		 *
		 * Order matters in both tables. Edge and Opera both carry "Chrome" in
		 * their UA and Chrome carries "Safari", so the more specific product has
		 * to be tested first or every Edge visitor reads as Chrome. Same on the
		 * OS side: Android UAs contain "Linux", and iPadOS reports as Macintosh
		 * unless the touch hint is checked.
		 *
		 * A page varying by user agent is not safely cacheable — that is a
		 * property of the feature, not of this code, and it matches how the
		 * same conditions behave in Nexter.
		 *
		 * @param string $kind 'os' or 'browser'.
		 * @param string $want Expected key.
		 * @return bool
		 */
		private static function ua_matches( $kind, $want ) {
			$want = sanitize_key( (string) $want );
			if ( '' === $want || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
				return false;
			}
			$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

			if ( 'os' === $kind ) {
				$tests = array(
					'android' => array( 'android' ),
					'ios'     => array( 'iphone', 'ipad', 'ipod' ),
					'windows' => array( 'windows' ),
					'macos'   => array( 'macintosh', 'mac os x' ),
					'linux'   => array( 'linux', 'x11' ),
				);
			} else {
				$tests = array(
					'edge'    => array( 'edg/', 'edge/' ),
					'opera'   => array( 'opr/', 'opera' ),
					'firefox' => array( 'firefox/', 'fxios' ),
					'chrome'  => array( 'chrome/', 'crios' ),
					'safari'  => array( 'safari/' ),
				);
			}

			foreach ( $tests as $key => $needles ) {
				foreach ( $needles as $needle ) {
					if ( false !== strpos( $ua, $needle ) ) {
						return $key === $want;
					}
				}
			}
			return false;
		}

		/**
		 * Taxonomy rule: on a singular request the queried post must carry the
		 * term (or any term of the taxonomy when term = 0); on an archive request
		 * the queried term/taxonomy must match.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @param int    $term     Term ID, or 0 for "any term in this taxonomy".
		 * @return bool
		 */
		private static function taxonomy_matches( $taxonomy, $term ) {
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return false;
			}

			if ( is_singular() ) {
				if ( $term > 0 ) {
					if ( self::i18n_on() ) {
						// A French post carries the French term, the rule stores the
						// default-language one: compare their language-neutral IDs.
						$want  = self::norm_term( $term, $taxonomy );
						$terms = wp_get_object_terms( self::object_id(), $taxonomy, array( 'fields' => 'ids' ) );
						foreach ( is_array( $terms ) ? $terms : array() as $have ) {
							if ( self::norm_term( (int) $have, $taxonomy ) === $want ) {
								return true;
							}
						}
						return false;
					}
					return has_term( $term, $taxonomy, self::object_id() );
				}
				$terms = get_the_terms( self::object_id(), $taxonomy );
				return is_array( $terms ) && ! empty( $terms );
			}

			if ( is_tax( $taxonomy ) || ( 'category' === $taxonomy && is_category() ) || ( 'post_tag' === $taxonomy && is_tag() ) ) {
				if ( 0 === $term ) {
					return true;
				}
				return self::norm_term( (int) get_queried_object_id(), $taxonomy ) === self::norm_term( $term, $taxonomy );
			}

			return false;
		}

		/**
		 * Specificity weight for a rule (used for resolver tie-breaks).
		 *
		 * @param array $rule Rule.
		 * @return int
		 */
		private static function rule_specificity( array $rule ) {
			switch ( isset( $rule['type'] ) ? $rule['type'] : '' ) {
				case 'singular':
					return self::SPEC_SPECIFIC;
				case 'taxonomy':
					return ( (int) $rule['term'] > 0 ) ? self::SPEC_SPECIFIC : self::SPEC_GROUP;
				case 'author':
					return ( (int) $rule['value'] > 0 ) ? self::SPEC_GROUP : self::SPEC_ARCHIVE;
				case 'post_type':
					return self::SPEC_POSTTYPE;
				case 'search':
					// A search pinned to one post type is as narrow as a post-type
					// rule; an unscoped one is just "any listing page".
					$searched = isset( $rule['value'] ) ? sanitize_key( (string) $rule['value'] ) : '';
					return ( '' !== $searched && 'any' !== $searched ) ? self::SPEC_POSTTYPE : self::SPEC_ARCHIVE;
				case 'all_archives':
				case 'all_singulars':
					return self::SPEC_ARCHIVE;
				case 'blog':
				case 'date':
				case 'front_page':
				case 'error_404':
				case 'woo_shop':
					// A named context is narrower than "any archive" but broader
					// than one post: two of these can never both match, so they sit
					// at their own level rather than competing with post-type rules.
					return self::SPEC_POSTTYPE;
				case 'day_of_week':
				case 'login_status':
				case 'user_role':
				case 'os':
				case 'browser':
					// Visitor/time rules narrow WHO or WHEN, not WHAT is being
					// viewed. Scored as a group so a template gated to one role
					// beats a site-wide one, without outranking a page-specific rule.
					return self::SPEC_GROUP;
				case 'entire':
				default:
					return self::SPEC_ENTIRE;
			}
		}

		/* ── Legacy (v1) ─────────────────────────────────────────────────── */

		/**
		 * Evaluate the legacy scope/include/exclude shape (unchanged behaviour).
		 *
		 * @param array $c Normalized legacy conditions.
		 * @return array{match:bool,specificity:int}
		 */
		private static function evaluate_legacy( array $c ) {
			$object_id = self::norm_post( self::object_id() );
			$c['exclude'] = array_map( array( __CLASS__, 'norm_post' ), (array) $c['exclude'] );
			$c['include'] = array_map( array( __CLASS__, 'norm_post' ), (array) $c['include'] );

			if ( $object_id && in_array( $object_id, $c['exclude'], true ) ) {
				return array(
					'match'       => false,
					'specificity' => 0,
				);
			}

			if ( 'specific' === $c['scope'] ) {
				if ( $object_id && in_array( $object_id, $c['include'], true ) ) {
					return array(
						'match'       => true,
						'specificity' => self::SPEC_SPECIFIC,
					);
				}
				return array(
					'match'       => false,
					'specificity' => 0,
				);
			}

			return array(
				'match'       => true,
				'specificity' => self::SPEC_ENTIRE,
			);
		}

		/* ── Helpers ─────────────────────────────────────────────────────── */

		/**
		 * The current request's primary object ID (0 when none, e.g. archives).
		 *
		 * @return int
		 */
		private static function object_id() {
			$id = (int) get_queried_object_id();
			return $id > 0 ? $id : 0;
		}

		/**
		 * Is a multilingual plugin driving this site (and is the helper loaded)?
		 *
		 * @return bool
		 */
		private static function i18n_on() {
			return class_exists( 'UiChemy_I18n' ) && UiChemy_I18n::is_active();
		}

		/**
		 * A post ID as its language-neutral identity (the default-language copy).
		 *
		 * Rules are stored against whichever copy the author picked, while the
		 * request may be on any translation. Both sides go through here so a rule
		 * written on the English page still matches the French and Arabic copies.
		 * Identity function when no multilingual plugin is active.
		 *
		 * @param int $id Post ID.
		 * @return int
		 */
		private static function norm_post( $id ) {
			$id = (int) $id;
			return ( $id > 0 && self::i18n_on() ) ? UiChemy_I18n::original_post_id( $id ) : $id;
		}

		/**
		 * A term ID as its language-neutral identity (see norm_post()).
		 *
		 * @param int    $id       Term ID.
		 * @param string $taxonomy Taxonomy slug.
		 * @return int
		 */
		private static function norm_term( $id, $taxonomy ) {
			$id = (int) $id;
			return ( $id > 0 && self::i18n_on() ) ? UiChemy_I18n::original_term_id( $id, $taxonomy ) : $id;
		}

		/**
		 * Minimal normalizer used only if the store class is unavailable.
		 *
		 * @param mixed $conditions Conditions.
		 * @return array
		 */
		private static function fallback_normalize( $conditions ) {
			$conditions = is_array( $conditions ) ? $conditions : array();
			$scope      = ( isset( $conditions['scope'] ) && 'specific' === $conditions['scope'] ) ? 'specific' : 'entire';
			$ids        = static function ( $list ) {
				return is_array( $list ) ? array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) ) : array();
			};
			return array(
				'scope'   => $scope,
				'include' => isset( $conditions['include'] ) ? $ids( $conditions['include'] ) : array(),
				'exclude' => isset( $conditions['exclude'] ) ? $ids( $conditions['exclude'] ) : array(),  // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Small admin-scoped exclusion list.
				'rules'   => array(),
			);
		}
	}
}
