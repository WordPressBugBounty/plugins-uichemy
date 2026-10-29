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
		 * The UiChemy Composer Builder (composer-builder/). Selectable like a
		 * builder - in onboarding, or pinned with ?builder=composer - but it has
		 * no driver in the registry: a Composer Builder page is one document with
		 * no sections, so the MCP surface switches mode instead (see
		 * is_composer_mode()).
		 */
		const COMPOSER = 'composer';

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
		 * The pin as the caller sent it, limited to known slugs PLUS "composer",
		 * which normalize() drops because the registry has no driver for it.
		 *
		 * @var string|null
		 */
		private static $pinned_raw = null;

		/**
		 * Reset and re-read the pin from a REST request.
		 *
		 * @param WP_REST_Request|null $request Current request.
		 * @return void
		 */
		public static function prime_from_request( $request = null ) {
			self::$pinned     = '';
			self::$pinned_raw = '';

			if ( $request instanceof WP_REST_Request ) {
				$from_header = (string) $request->get_header( self::HEADER );
				$from_query  = (string) $request->get_param( self::QUERY_ARG );

				// Query arg first: it is the one the caller can see in the URL,
				// so it is the one they expect to win when both are present.
				$raw              = '' !== $from_query ? $from_query : $from_header;
				self::$pinned     = self::normalize( $raw );
				self::$pinned_raw = self::normalize_selectable( $raw );
			}

			if ( '' === self::$pinned ) {
				self::$pinned = self::pin_from_superglobals();
			}
			if ( '' === self::$pinned_raw ) {
				self::$pinned_raw = self::normalize_selectable( self::raw_from_superglobals() );
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
			return self::normalize( self::raw_from_superglobals() );
		}

		/**
		 * The unvalidated ?builder= / X-UiChemy-Builder value of this request.
		 *
		 * @return string
		 */
		private static function raw_from_superglobals() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$raw = '';
			if ( isset( $_GET[ self::QUERY_ARG ] ) ) {
				$raw = wp_unslash( $_GET[ self::QUERY_ARG ] );
			} elseif ( isset( $_SERVER['HTTP_X_UICHEMY_BUILDER'] ) ) {
				$raw = wp_unslash( $_SERVER['HTTP_X_UICHEMY_BUILDER'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * A registry slug or "composer", else ''.
		 *
		 * @param string $slug Raw slug.
		 * @return string
		 */
		private static function normalize_selectable( $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( self::COMPOSER === $slug ) {
				return $slug;
			}

			return self::normalize( $slug );
		}

		/**
		 * Is the Composer Builder loaded AND switched on (Settings > Features)?
		 *
		 * Switched off, its pages still render but cannot be opened, so it can
		 * be neither the selected builder nor offered to an agent.
		 *
		 * @return bool
		 */
		public static function composer_available() {
			if ( ! class_exists( 'Uich_CB_Content' ) ) {
				return false;
			}

			return ! function_exists( 'uich_cb_builder_enabled' ) || uich_cb_builder_enabled();
		}

		/**
		 * Is the Composer Builder this site's chosen builder, but switched off?
		 *
		 * Then nothing may build pages: falling back to another builder would
		 * put that builder's section pages on a site that chose one-document
		 * Composer pages. A request pinned to another builder is exempt.
		 *
		 * @return bool
		 */
		public static function composer_switched_off() {
			if ( ! class_exists( 'Uich_CB_Content' ) || self::composer_available() ) {
				return false;
			}
			if ( null === self::$pinned_raw ) {
				self::$pinned_raw = self::normalize_selectable( self::raw_from_superglobals() );
			}
			if ( '' !== self::$pinned_raw && self::COMPOSER !== self::$pinned_raw ) {
				return false;
			}

			return class_exists( 'Uich_ND_Settings' ) && self::COMPOSER === Uich_ND_Settings::get_builder();
		}

		/**
		 * Is the Composer Builder the SELECTED builder for this request?
		 *
		 * A pin on the request (?builder= / X-UiChemy-Builder) wins; otherwise
		 * the onboarding choice decides. The MCP surface shapes itself on this:
		 * in Composer Builder mode page / post offer the document actions
		 * (get-code / set-code / patch-code) and none of the section actions,
		 * and in every other mode it is the other way round.
		 *
		 * @return bool
		 */
		public static function is_composer_mode() {
			if ( ! self::composer_available() ) {
				return false;
			}

			if ( null === self::$pinned_raw ) {
				self::$pinned_raw = self::normalize_selectable( self::raw_from_superglobals() );
			}

			if ( '' !== self::$pinned_raw ) {
				return self::COMPOSER === self::$pinned_raw;
			}

			if ( ! class_exists( 'Uich_ND_Settings' ) ) {
				return false;
			}
			$saved = Uich_ND_Settings::get_builder();
			if ( '' !== $saved ) {
				return self::COMPOSER === $saved;
			}
			// No choice saved yet: the dashboard's own default
			// (new-dashboard/src/dashboard/default-builder.js) — Elementor or
			// Bricks when the site runs one, the Composer Builder otherwise. MCP
			// built into Gutenberg while the dashboard showed Composer (E-02).
			return class_exists( 'UiChemy_Builder_Registry' )
				&& ! UiChemy_Builder_Registry::is_available( 'elementor' )
				&& ! UiChemy_Builder_Registry::is_available( 'bricks' );
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
			if ( self::composer_switched_off() ) {
				return '';
			}

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
			if ( '' === $slug && self::composer_switched_off() ) {
				return new WP_Error(
					'uich_composer_switched_off',
					'This site builds with the UiChemy Composer Builder, but it is switched off (UiChemy > Settings > Features). Nothing was written. Ask the site owner to switch it back on, or to choose another page builder in the UiChemy dashboard.'
				);
			}
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
		 * Rewrite section-mode call names in text an agent will act on (a fix
		 * hint, a usage line, a skill written for sections) into the Composer
		 * Builder document actions, when Composer Builder mode is selected.
		 *
		 * In that mode page / post offer get-code / set-code / patch-code only,
		 * so a hint naming update-page-code sent the agent into a refused call.
		 * Outside Composer Builder mode the text is returned unchanged.
		 *
		 * @param string $text  Text naming section-mode actions.
		 * @param bool   $force Rewrite whatever the mode - for text about one
		 *                      post that is itself a Composer Builder document.
		 * @return string
		 */
		public static function for_mode( $text, $force = false ) {
			if ( ! is_string( $text ) || '' === $text || ( ! $force && ! self::is_composer_mode() ) ) {
				return $text;
			}

			return strtr(
				$text,
				array(
					'(action="update-page-code")'        => '(action="patch-code", field="head_code" or "body_end_code")',
					'(action="set-page-code")'           => '(action="set-code" with head_code / body_end_code)',
					'(action="get-page-code")'           => '(action="get-code")',
					'update-page-code / set-page-code'   => 'patch-code / set-code on head_code',
					'update-page-code (or set-page-code' => 'patch-code with field "head_code" (or set-code',
					'update-page-code'                   => 'patch-code (field "head_code")',
					'set-page-code'                      => 'set-code (head_code)',
					'get-page-code'                      => 'get-code',
					'patch-section-code'                 => 'patch-code',
					'set-section-code'                   => 'set-code',
					'get-section-code'                   => 'get-code',
					'create-with-sections'               => 'create',
					'append-section'                     => 'patch-code',
					'insert-section'                     => 'patch-code',
					'page_before_head'                   => 'head_code',
					'page_before_body'                   => 'body_end_code',
					'in the section HTML'                => 'in the page html',
					'Edit the section HTML'              => 'Edit the page html',
					// Descriptions and messages that taught one-widget-per-section.
					'Pass this html as a section to'     => 'Put this html into the page html with',
					'Insert this element into the section HTML you pass to' => 'Insert this element into the html you pass to',
					'Section CSS and JS belong in the section, through' => 'A page\'s own CSS and JS belong in its document, through',
					'Per-section CSS and JS still belong in the section itself via' => 'A page\'s own CSS and JS still belong in its document via',
					'one animation or utility system several sections depend on' => 'one animation or utility system several pages depend on',
					'the same block appearing in three or more sections' => 'the same block appearing on three or more pages',
					'anything shared across sections or pages' => 'anything shared across pages',
					'an animation system several sections use' => 'an animation system several pages use',
					'instead of repeating it in every widget' => 'instead of repeating it in every page',
					'before writing any section CSS'     => 'before writing any page CSS',
					'before you write section CSS'       => 'before you write page CSS',
					'from section CSS'                   => 'from page CSS',
					'into a section instead of hardcoding it' => 'into a page instead of hardcoding it',
					'when the section was generated'     => 'when the page was generated',
					'to also check one page for missing sections' => 'to also check one page (an empty Composer Builder document is reported)',
					'what the UiChemy widget is and what one section means' => 'what a Composer Builder page document is',
					'(elementor | bricks | gutenberg)'   => '(elementor | bricks | gutenberg | composer)',
					'Elementor (including free), Bricks or the block editor' => 'the Composer Builder, Elementor (including free), Bricks or the block editor',
					'Which part of each section to search' => 'Which part of each document to search',
					'Nothing to do with its sections'    => 'Nothing to do with its code',
					'NOT the widget content'             => 'NOT the document content',
					'in a Composer section'              => 'in a Composer Builder document',
					'built from Composer widgets, section by section' => 'built as Composer Builder documents, region by region',
				)
			);
		}

		/**
		 * for_mode() over every string in a nested result.
		 *
		 * @param mixed $value Result value.
		 * @return mixed
		 */
		public static function for_mode_deep( $value, $force = false ) {
			if ( is_string( $value ) ) {
				return self::for_mode( $value, $force );
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					$value[ $k ] = self::for_mode_deep( $v, $force );
				}
			}
			return $value;
		}

		/**
		 * Test seam — forget the pin.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$pinned     = null;
			self::$pinned_raw = null;
		}
	}
}
