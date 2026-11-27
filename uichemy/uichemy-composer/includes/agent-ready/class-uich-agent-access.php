<?php
/**
 * One answer to "may an agent see this post?", used by every Agent Ready
 * surface: agent pages, llms.txt, search, collections and products.
 *
 * A post is visible to agents only when it is published, has no password, is
 * not hidden from agents, and is not members-only for a logged-out visitor.
 * The members-only test always runs as user 0, even when an admin triggers it,
 * so a preview or an admin action can never mark private content as public.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Access' ) ) {

	/**
	 * Agent visibility rules.
	 */
	class Uich_Agent_Access {

		/**
		 * Mirror of the SEO exclude tag (see sync_seo_hide()).
		 */
		const META_SEO_HIDE = '_uichemy_agent_seo_hide';

		/**
		 * Whether agents may see a post.
		 *
		 * @param WP_Post|int|null $post Post.
		 * @return bool
		 */
		public static function visible( $post ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
				return false;
			}
			if ( get_post_meta( $post->ID, Uich_Agent_Pages::META_HIDE, true ) || self::seo_excluded( $post ) ) {
				return false;
			}
			return ! self::restricted( $post );
		}

		/**
		 * Meta clauses that leave out posts hidden from agents (by the owner or by
		 * the SEO exclude tag).
		 *
		 * @return array
		 */
		public static function meta_query() {
			return array(
				array(
					'key'     => Uich_Agent_Pages::META_HIDE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_SEO_HIDE,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		/**
		 * Copy the SEO "exclude from AI" tag into its own meta key, so listing
		 * queries (archives, search, llms.txt) can leave those posts out in SQL.
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function sync_seo_hide( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return;
			}
			if ( self::seo_excluded( $post ) ) {
				update_post_meta( $post->ID, self::META_SEO_HIDE, 1 );
			} else {
				delete_post_meta( $post->ID, self::META_SEO_HIDE );
			}
		}

		/**
		 * Whether a membership plugin that can restrict posts is active. While one
		 * is, agent files are not served from cache, so a rule change applies at once.
		 *
		 * @return bool
		 */
		public static function membership_active() {
			$active = function_exists( 'pmpro_has_membership_access' ) || class_exists( 'MeprRule' ) || function_exists( 'rcp_user_can_access' ) || function_exists( 'wc_memberships_is_post_content_restricted' )
				|| function_exists( 'members_can_user_view_post' ) || function_exists( 'UM' ) || function_exists( 'sfwd_lms_has_access' ) || function_exists( 'llms_page_restricted' ) || function_exists( 'tutor_utils' ) || function_exists( 'is_protected_by_s2member' )
				|| has_filter( 'uichemy_agent_ready_post_restricted' ) || has_filter( 'uichemy_seo_ai_post_restricted' );
			/**
			 * Whether membership rules are in play (turns off the agent file fast cache).
			 *
			 * @param bool $active Detected.
			 */
			return (bool) apply_filters( 'uichemy_agent_ready_membership_active', $active );
		}

		/**
		 * Whether the page's SEO head code says to keep it from AI tools
		 * (<meta name="uichemy:ai" content="exclude">), read live.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function seo_excluded( WP_Post $post ) {
			if ( ! class_exists( 'Uich_SEO_Record' ) ) {
				return false;
			}
			$record = Uich_SEO_Record::get( $post->ID );
			return ! empty( $record['fields']['exclude_ai']['value'] );
		}

		/**
		 * Whether a post is members-only for a logged-out visitor.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function restricted( WP_Post $post ) {
			$user = get_current_user_id();
			if ( 0 !== $user ) {
				wp_set_current_user( 0 );
			}
			try {
				$restricted = false;
				if ( function_exists( 'pmpro_has_membership_access' ) && ! pmpro_has_membership_access( $post->ID ) ) {
					$restricted = true; // Paid Memberships Pro.
				} elseif ( class_exists( 'MeprRule' ) && method_exists( 'MeprRule', 'is_locked' ) && MeprRule::is_locked( $post ) ) {
					$restricted = true; // MemberPress.
				} elseif ( function_exists( 'rcp_user_can_access' ) && ! rcp_user_can_access( 0, $post->ID ) ) {
					$restricted = true; // Restrict Content (Pro).
				} elseif ( function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted( $post->ID ) ) {
					$restricted = true; // WooCommerce Memberships.
				} elseif ( function_exists( 'members_can_user_view_post' ) && ! members_can_user_view_post( 0, $post->ID ) ) {
					$restricted = true; // Members (content permissions).
				} elseif ( function_exists( 'UM' ) && is_object( UM() ) && method_exists( UM(), 'access' ) && method_exists( UM()->access(), 'is_restricted' ) && UM()->access()->is_restricted( $post->ID, false, true ) ) {
					$restricted = true; // Ultimate Member.
				} elseif ( function_exists( 'is_protected_by_s2member' ) && is_protected_by_s2member( $post->ID, 'singular' ) ) {
					$restricted = true; // s2Member.
				} elseif ( self::lms_locked( $post ) ) {
					$restricted = true; // Course lessons, topics and quizzes for enrolled students only.
				} elseif ( self::native_hidden( $post->ID ) ) {
					$restricted = true; // UiChemy's own per-page visibility rules (e.g. logged-in only).
				}
				/** This filter is documented in the SEO module (members-only content). */
				$restricted = (bool) apply_filters( 'uichemy_seo_ai_post_restricted', $restricted, $post );

				/**
				 * Whether a post is members-only for agents (for membership plugins not detected above).
				 *
				 * @param bool    $restricted Detected restriction.
				 * @param WP_Post $post       Post.
				 */
				$restricted = (bool) apply_filters( 'uichemy_agent_ready_post_restricted', $restricted, $post );
			} finally {
				if ( 0 !== $user ) {
					wp_set_current_user( $user );
				}
			}
			return $restricted;
		}

		/**
		 * Whether UiChemy's own per-page visibility rules (Composer Builder) hide the
		 * post from the current visitor (user 0 here). The page applies them through its
		 * queries, which llms files and search do not run, so they are evaluated here.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		private static function native_hidden( $post_id ) {
			if ( ! class_exists( 'Uich_CB_Visibility' ) || ! Uich_CB_Visibility::available() ) {
				return false;
			}
			/** This filter is documented in composer-builder/includes/class-uich-cb-visibility.php */
			if ( ! apply_filters( 'uich_cb/visibility_applies', true ) ) {
				return false;
			}
			$rules = Uich_CB_Visibility::get( $post_id );
			return ! empty( $rules ) && ! Uich_CB_Visibility::allows( $rules );
		}

		/**
		 * Whether a course lesson, topic, quiz or assignment is for enrolled students
		 * only (LearnDash, LifterLMS, Tutor LMS), checked as a logged-out visitor.
		 * Course and membership sales pages stay readable.
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		private static function lms_locked( WP_Post $post ) {
			$type = $post->post_type;
			if ( function_exists( 'sfwd_lms_has_access' ) && in_array( $type, array( 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-assignment', 'sfwd-essays' ), true ) ) {
				return ! sfwd_lms_has_access( $post->ID, 0 );
			}
			// LifterLMS: llms_page_restricted() only applies its rules on a singular page
			// request, so outside one it answers "accessible". Ask the parts directly.
			if ( class_exists( 'LLMS_Lesson' ) && in_array( $type, array( 'lesson', 'llms_quiz', 'llms_assignment' ), true ) ) {
				if ( 'lesson' === $type ) {
					$lesson = new LLMS_Lesson( $post->ID );
					return ! $lesson->is_free();
				}
				return true;
			}
			if ( function_exists( 'llms_is_post_restricted_by_membership' ) && llms_is_post_restricted_by_membership( $post->ID, 0 ) ) {
				return true;
			}
			if ( function_exists( 'tutor_utils' ) && in_array( $type, array( 'lesson', 'tutor_quiz', 'tutor_assignments' ), true ) ) {
				return ! get_post_meta( $post->ID, '_is_preview', true );
			}
			return false;
		}

		/**
		 * Plain text of a post exactly as a logged-out visitor is shown it, for
		 * llms files, search results and tool summaries.
		 *
		 * Never reads post_content directly: the content goes through the same
		 * filters as the real page (the_content / the excerpt filters) as user 0, so
		 * membership, course and visibility plugins that hide or swap content on
		 * render (including ones not detected in restricted()) apply here too. A
		 * hidden, members-only or unrenderable post gives ''.
		 *
		 * @param WP_Post $post           Post.
		 * @param int     $words          Word limit.
		 * @param bool    $prefer_excerpt Use the manual excerpt when there is one.
		 * @return string
		 */
		public static function public_text( WP_Post $post, $words = 40, $prefer_excerpt = false ) {
			static $rendering = false;
			static $memo      = array();
			if ( $rendering || ! self::visible( $post ) ) {
				return '';
			}
			$gen = class_exists( 'Uich_Agent_Pages' ) ? (int) get_option( Uich_Agent_Pages::GEN_OPTION, 1 ) : 1;
			$key = 'txt-' . md5( $post->ID . '|' . $post->post_modified_gmt . '|' . (int) $words . '|' . ( $prefer_excerpt ? 1 : 0 ) . '|' . $gen );
			// Membership rules can change without the post changing: while they are in
			// play, remember a result for this request only.
			$shared = ! self::membership_active();
			if ( isset( $memo[ $key ] ) ) {
				return $memo[ $key ];
			}
			$hit = $shared ? wp_cache_get( $key, 'uichemy_agent_ready' ) : false;
			if ( false !== $hit ) {
				return (string) $hit;
			}

			$rendering = true;
			$user      = get_current_user_id();
			$saved     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$html      = '';
			wp_set_current_user( 0 );
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			setup_postdata( $post );
			ob_start();
			try {
				if ( $prefer_excerpt && has_excerpt( $post ) ) {
					$html = (string) apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );
					$html = (string) apply_filters( 'the_excerpt', $html );
				} else {
					$html = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				}
			} catch ( Throwable $e ) {
				$html = ''; // A filter that fails here must not fall back to the raw content.
			} finally {
				ob_end_clean();
				$GLOBALS['post'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
				if ( $saved instanceof WP_Post ) {
					setup_postdata( $saved );
				}
				if ( 0 !== $user ) {
					wp_set_current_user( $user );
				}
				$rendering = false;
			}

			$text         = Uich_Agent_Convert::plain_text( $html, $words );
			$memo[ $key ] = $text;
			if ( $shared ) {
				wp_cache_set( $key, $text, 'uichemy_agent_ready', HOUR_IN_SECONDS );
			}
			return $text;
		}

		/**
		 * Whether agents may list a post in search results and llms files: visible,
		 * not noindex (unless the owner includes noindex pages), and not matched by
		 * an "Leave out addresses" pattern. The same rules the agent page applies.
		 *
		 * @param WP_Post|int|null $post Post.
		 * @return bool
		 */
		public static function listable( $post ) {
			$post = get_post( $post );
			if ( ! self::visible( $post ) ) {
				return false;
			}
			if ( ! Uich_Agent_Settings::value( 'agent_pages.include_noindex', false ) && self::noindex( $post ) ) {
				return false;
			}
			// WooCommerce "Hidden" catalog visibility: reachable by its link, never listed.
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post );
				if ( $product && 'hidden' === $product->get_catalog_visibility() ) {
					return false;
				}
			}
			return ! self::excluded_path( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
		}

		/**
		 * Whether a post is set to noindex (UiChemy SEO, Yoast or Rank Math).
		 *
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function noindex( WP_Post $post ) {
			$noindex = false;
			if ( class_exists( 'Uich_SEO_Record' ) ) {
				$record  = Uich_SEO_Record::get( $post->ID );
				$noindex = isset( $record['fields']['index']['value'] ) && false === (bool) $record['fields']['index']['value'];
			}
			if ( ! $noindex && '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
				$noindex = true;
			}
			if ( ! $noindex ) {
				$rank = get_post_meta( $post->ID, 'rank_math_robots', true );
				$noindex = is_array( $rank ) && in_array( 'noindex', $rank, true );
			}
			/**
			 * Whether a post is noindex for agent listings.
			 *
			 * @param bool    $noindex Detected.
			 * @param WP_Post $post    Post.
			 */
			return (bool) apply_filters( 'uichemy_agent_ready_post_noindex', $noindex, $post );
		}

		/**
		 * Whether a page path matches one of the "Leave out addresses" patterns.
		 *
		 * @param string $path Page path.
		 * @return bool
		 */
		public static function excluded_path( $path ) {
			$path = '' === $path ? '/' : $path;
			foreach ( (array) Uich_Agent_Settings::value( 'agent_pages.exclude_patterns', array() ) as $pattern ) {
				if ( function_exists( 'fnmatch' ) ? fnmatch( $pattern, $path ) : $pattern === $path ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Keep only posts agents may list.
		 *
		 * @param WP_Post[] $posts Posts.
		 * @return WP_Post[]
		 */
		public static function filter_listable( array $posts ) {
			return array_values( array_filter( $posts, array( __CLASS__, 'listable' ) ) );
		}

		/**
		 * Keep only visible posts.
		 *
		 * @param WP_Post[] $posts Posts.
		 * @return WP_Post[]
		 */
		public static function filter( array $posts ) {
			return array_values( array_filter( $posts, array( __CLASS__, 'visible' ) ) );
		}
	}
}
