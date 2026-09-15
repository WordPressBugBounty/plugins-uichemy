<?php
/**
 * Which page builder is this request talking about?
 *
 * One endpoint, /wp-json/uichemy/v2/mcp, serves all three builders. Resolution
 * order, highest first:
 *
 *   1. `builder` inside the ability's action_parameters   (per-call override)
 *   2. ?builder= on the endpoint URL, or the X-UiChemy-Builder header
 *      (co-equal: a client may drop the query string on POST)
 *   3. the post's OWN builder, when the action names a post_id
 *   4. Uich_ND_Settings::get_builder() — the onboarding choice
 *   5. the first available builder, in elementor -> bricks -> gutenberg order
 *
 * Gutenberg is core, so step 5 can never resolve empty on a live site.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Builder_Context' ) ) {

	/**
	 * Per-request builder resolution.
	 */
	class UiChemy_Builder_Context {

		/**
		 * Query arg and header a client uses to pin a builder.
		 */
		const QUERY_ARG = 'builder';
		const HEADER    = 'X-UiChemy-Builder';

		/**
		 * The pin from the request URL/header, resolved once per request.
		 *
		 * Null means "not yet computed"; '' means "computed, nothing pinned".
		 * Primed from UiChemy_MCP_Server_V2::ensure_relay_session(), which is
		 * already the per-request hook scoped to this route — a bare lazy
		 * static would survive across requests in a reused php-fpm worker and
		 * leak one client's pin into the next client's call.
		 *
		 * @var string|null
		 */
		private static $pinned = null;

		/**
		 * Reset and re-read the pin from a REST request.
		 *
		 * @param WP_REST_Request|null $request Current request.
		 * @return void
		 */
		public static function prime_from_request( $request = null ) {
			self::$pinned = '';

			if ( $request instanceof WP_REST_Request ) {
				$from_header = (string) $request->get_header( self::HEADER );
				$from_query  = (string) $request->get_param( self::QUERY_ARG );

				// Query arg first: it is the one the caller can see in the URL,
				// so it is the one they expect to win when both are present.
				self::$pinned = self::normalize( '' !== $from_query ? $from_query : $from_header );
			}

			if ( '' === self::$pinned ) {
				self::$pinned = self::pin_from_superglobals();
			}
		}

		/**
		 * Fallback read for callers that run before (or outside) a REST
		 * request — notably ability REGISTRATION, which happens on `init` and
		 * so cannot see a WP_REST_Request. Only ever used to shape prose, never
		 * to decide what is registered; see the tier-2 rule in the ability
		 * descriptions.
		 *
		 * @return string
		 */
		private static function pin_from_superglobals() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$raw = '';
			if ( isset( $_GET[ self::QUERY_ARG ] ) ) {
				$raw = wp_unslash( $_GET[ self::QUERY_ARG ] );
			} elseif ( isset( $_SERVER['HTTP_X_UICHEMY_BUILDER'] ) ) {
				$raw = wp_unslash( $_SERVER['HTTP_X_UICHEMY_BUILDER'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return self::normalize( is_string( $raw ) ? $raw : '' );
		}

		/**
		 * The builder pinned for this request, or '' when none is.
		 *
		 * @return string
		 */
		public static function pinned() {
			if ( null === self::$pinned ) {
				self::$pinned = self::pin_from_superglobals();
			}

			return self::$pinned;
		}

		/**
		 * Clamp a slug to a REGISTERED builder. An unknown or misspelled value
		 * resolves to '' and therefore falls through to the next precedence
		 * tier, rather than selecting a driver that does not exist.
		 *
		 * @param string $slug Raw slug.
		 * @return string
		 */
		public static function normalize( $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				return '';
			}

			return in_array( $slug, UiChemy_Builder_Registry::slugs(), true ) ? $slug : '';
		}

		/**
		 * The session default: the onboarding choice, else the first available
		 * builder. Used when nothing more specific applies.
		 *
		 * @return string
		 */
		public static function session_default() {
			if ( class_exists( 'Uich_ND_Settings' ) && method_exists( 'Uich_ND_Settings', 'get_builder' ) ) {
				$chosen = self::normalize( Uich_ND_Settings::get_builder() );
				if ( '' !== $chosen && UiChemy_Builder_Registry::is_available( $chosen ) ) {
					return $chosen;
				}
			}

			foreach ( UiChemy_Builder_Registry::DETECT_ORDER as $slug ) {
				if ( UiChemy_Builder_Registry::is_available( $slug ) ) {
					return $slug;
				}
			}

			$available = array_keys( UiChemy_Builder_Registry::available() );

			return $available ? (string) reset( $available ) : '';
		}

		/**
		 * The builder for an operation, applied in full precedence order.
		 *
		 * @param array $params  The action's parameters (may carry `builder`).
		 * @param int   $post_id Target post, or 0 when the op creates one.
		 * @return string Builder slug, or '' when nothing is available.
		 */
		public static function resolve( array $params = array(), $post_id = 0 ) {
			$explicit = isset( $params[ self::QUERY_ARG ] ) ? self::normalize( $params[ self::QUERY_ARG ] ) : '';
			if ( '' !== $explicit ) {
				return $explicit;
			}

			$pinned = self::pinned();
			if ( '' !== $pinned ) {
				return $pinned;
			}

			$post_id = absint( $post_id );
			if ( $post_id ) {
				$owner = UiChemy_Builder_Registry::detect_post_builder( $post_id );
				if ( '' !== $owner ) {
					return $owner;
				}
			}

			return self::session_default();
		}

		/**
		 * Resolve a builder for an operation on an EXISTING post, refusing a
		 * conflict rather than coercing it.
		 *
		 * Coercion here fails silently AND successfully: Elementor renders
		 * _elementor_data and never reads post_content, so writing a
		 * uichemy/composer block into an Elementor page returns 200 with a
		 * widget id and changes nothing a visitor can see. The agent reports
		 * the section shipped and the user finds an unchanged page — the most
		 * expensive failure mode for a tool-driven build, and mixed storage on
		 * one post is not automatically recoverable.
		 *
		 * Two deliberate carve-outs:
		 *   - a post no builder claims is ADOPTED by the requested builder;
		 *   - reads pass $for_write = false and always follow the post's real
		 *     owner, because reads are how a model discovers a mismatch in the
		 *     first place.
		 *
		 * @param array $params    Action parameters.
		 * @param int   $post_id   Existing post id.
		 * @param bool  $for_write Whether the caller intends to write.
		 * @return string|WP_Error Builder slug, or a mismatch error.
		 */
		public static function resolve_for_post( array $params, $post_id, $for_write = true ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return new WP_Error( 'uich_invalid_post_id', 'Invalid post_id.' );
			}

			$owner     = UiChemy_Builder_Registry::detect_post_builder( $post_id );
			$requested = isset( $params[ self::QUERY_ARG ] ) ? self::normalize( $params[ self::QUERY_ARG ] ) : '';
			if ( '' === $requested ) {
				$requested = self::pinned();
			}

			// Reads follow the post, always. Echoing the real builder back is
			// what lets a mis-pinned client correct itself.
			if ( ! $for_write ) {
				if ( '' !== $owner ) {
					return $owner;
				}

				return '' !== $requested ? $requested : self::session_default();
			}

			if ( '' === $owner ) {
				// Unclaimed: adopt whatever was asked for.
				return '' !== $requested ? $requested : self::session_default();
			}

			if ( '' === $requested || $requested === $owner ) {
				return $owner;
			}

			return self::mismatch_error( $post_id, $owner, $requested );
		}

		/**
		 * The mismatch refusal, carrying both builders and both remedies so a
		 * model can correct itself in one turn instead of guessing.
		 *
		 * @param int    $post_id   Post id.
		 * @param string $owner     Builder that actually owns the post.
		 * @param string $requested Builder the caller asked for.
		 * @return WP_Error
		 */
		public static function mismatch_error( $post_id, $owner, $requested ) {
			$owner_label     = self::label( $owner );
			$requested_label = self::label( $requested );

			return new WP_Error(
				'uich_builder_mismatch',
				sprintf(
					/* translators: 1: post id, 2: owning builder, 3: requested builder, 4: owning builder slug. */
					'Post %1$d was built with %2$s, but this call asked for %3$s. Writing %3$s data into a post owned by %2$s succeeds and renders nothing, so it is refused. Either re-issue this call with builder="%4$s", or create a new post with %3$s.',
					absint( $post_id ),
					$owner_label,
					$requested_label,
					$owner
				),
				array(
					'status'            => 409,
					'post_id'           => absint( $post_id ),
					'detected_builder'  => $owner,
					'requested_builder' => $requested,
					'remedies'          => array(
						'use_detected' => 'Re-issue with builder="' . $owner . '".',
						'create_new'   => 'Create a new post with builder="' . $requested . '".',
					),
				)
			);
		}

		/**
		 * A driver's label, falling back to the slug when it is not registered.
		 *
		 * @param string $slug Builder slug.
		 * @return string
		 */
		public static function label( $slug ) {
			$driver = UiChemy_Builder_Registry::get( $slug );

			return $driver ? $driver->label() : (string) $slug;
		}

		/**
		 * Resolve straight to a driver, or an error explaining which builder is
		 * missing — never a null the caller has to guess about.
		 *
		 * @param string $slug Builder slug.
		 * @return UiChemy_Builder_Driver|WP_Error
		 */
		public static function driver( $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				return new WP_Error(
					'uich_no_builder',
					'No page builder is available on this site. Install Elementor or Bricks, or use the block editor.'
				);
			}

			$driver = UiChemy_Builder_Registry::get( $slug );
			if ( ! $driver ) {
				return new WP_Error(
					'uich_unknown_builder',
					sprintf( 'Unknown page builder "%s". Known builders: %s.', $slug, implode( ', ', UiChemy_Builder_Registry::slugs() ) )
				);
			}

			if ( ! $driver->is_available() ) {
				$readiness = $driver->readiness();
				$notes     = isset( $readiness['notes'] ) && is_array( $readiness['notes'] ) ? array_filter( (array) $readiness['notes'] ) : array();

				// The driver's own note is the specific reason; only fall back to
				// a generic line when it has none, so the two never double up.
				return new WP_Error(
					'uich_builder_unavailable',
					$notes
						? implode( ' ', $notes )
						: sprintf( '%s is not available on this site.', $driver->label() )
				);
			}

			return $driver;
		}

		/**
		 * Test seam — forget the pin.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$pinned = null;
		}
	}
}
