<?php
/**
 * Which provider owns which field, and what to say when none does.
 *
 * The routing decision is the one that matters. Route an ACF field to the meta
 * provider and the value renders on the front end while the editor shows blank;
 * route a JetEngine field to ACF and you write a reference row JetEngine never
 * reads. Both look like a successful write from every angle a caller can see.
 *
 * Order is significant: ACF is asked before JetEngine, and both before plain
 * meta, because ACF is the only one of the three with a storage convention of
 * its own. When both claim the same name the collision is REPORTED rather than
 * resolved silently - a caller can pin the provider explicitly.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Field_Registry' ) ) {

	/**
	 * The provider list, and field-name => provider resolution.
	 */
	final class Uich_Field_Registry {

		/**
		 * Provider instances, built once per request.
		 *
		 * @var array<string,Uich_Field_Provider>|null
		 */
		private static $providers = null;

		/**
		 * Cached definitions per target, so a batch write does not re-read every
		 * ACF group per field.
		 *
		 * @var array<string,array>
		 */
		private static $defs_cache = array();

		/**
		 * Every provider, active or not, in resolution order.
		 *
		 * @return array<string,Uich_Field_Provider>
		 */
		public static function providers() {
			if ( null !== self::$providers ) {
				return self::$providers;
			}

			$providers = array();

			foreach ( array( 'Uich_Field_Provider_ACF', 'Uich_Field_Provider_Jet', 'Uich_Field_Provider_Meta' ) as $class ) {
				if ( class_exists( $class ) ) {
					$provider = new $class();
					$providers[ $provider->slug() ] = $provider;
				}
			}

			/**
			 * Filter the field providers.
			 *
			 * Add a Uich_Field_Provider subclass keyed by its slug to teach the
			 * field layer about Pods, Meta Box or a bespoke store. Order is
			 * resolution order.
			 *
			 * @since 5.1.0
			 *
			 * @param array<string,Uich_Field_Provider> $providers Provider instances.
			 */
			self::$providers = (array) apply_filters( 'uichemy/fields/providers', $providers );

			return self::$providers;
		}

		/**
		 * The providers whose plugin is actually here.
		 *
		 * @return array<string,Uich_Field_Provider>
		 */
		public static function active() {
			$out = array();

			foreach ( self::providers() as $slug => $provider ) {
				if ( $provider->is_active() ) {
					$out[ $slug ] = $provider;
				}
			}

			return $out;
		}

		/**
		 * A report of which field-modelling plugins are present.
		 *
		 * Surfaced through describe-site and cpt/describe. The none-active case
		 * is the important one: with no PHP runner on the other end, an empty
		 * field map reads as "this site has no data", and the agent builds a
		 * hardcoded page. It has to read as "this site has no field-modelling
		 * plugin, and here is what to do".
		 *
		 * @return array
		 */
		public static function status() {
			$out = array(
				'providers'      => array(),
				'active'         => array(),
				'writes_enabled' => uichemy_field_writes_allowed(),
				'notes'          => array(),
			);

			foreach ( self::providers() as $slug => $provider ) {
				$active = $provider->is_active();

				$row = array(
					'provider' => $slug,
					'label'    => $provider->label(),
					'active'   => $active,
				);

				if ( 'acf' === $slug && $active && $provider instanceof Uich_Field_Provider_ACF ) {
					$row['edition']             = $provider->is_acf_pro() ? 'pro' : 'free';
					$row['pro_only_types']      = array( 'repeater', 'flexible_content', 'gallery', 'clone' );
					$row['pro_types_available'] = $provider->is_acf_pro();

					if ( ! $provider->is_acf_pro() ) {
						$out['notes'][] = 'ACF FREE is installed. repeater, flexible_content, gallery and clone are not registered field types on this build. A field declared as one of them does not error - ACF stores a single serialised array down its scalar path, which reads back intact here and shows as one empty row the moment ACF Pro is installed. UiChemy refuses writes to those fields for that reason.';
					}
				}

				$out['providers'][] = $row;

				if ( $active ) {
					$out['active'][] = $slug;
				}
			}

			$modelling = array_values( array_intersect( $out['active'], array( 'acf', 'jetengine' ) ) );

			if ( ! $modelling ) {
				$out['notes'][] = 'No field-modelling plugin is active on this site. Custom fields cannot be created here. Tell the user to install ACF or JetEngine, or offer to model the content with plain posts, categories and tags instead - do not report an empty field list as though the site had no data.';
			} elseif ( count( $modelling ) > 1 ) {
				$out['notes'][] = 'ACF and JetEngine are BOTH active. They store the same concepts differently (booleans, repeaters, checkboxes, media), so a field must be written through its own owner. Where a name is claimed by both, pass "provider" explicitly rather than letting UiChemy pick.';
			}

			/*
			 * writes_enabled on its own is a bare fact, and a bare fact is what
			 * this method exists to avoid: an agent that reads "false" with no
			 * reason concludes the fields are read-only and hardcodes the page.
			 *
			 * Only with a modelling plugin active, and only AFTER the notes
			 * above. With neither ACF nor JetEngine there are no fields to be
			 * wrong about, and "the fields listed here are real" would land
			 * directly beside "no field-modelling plugin is active".
			 *
			 * It does NOT offer uichemy-composer/dynamic as the way to show
			 * these values. Uich_Dynamic::fields_map() returns early on a Free
			 * build for exactly this reason - a custom-field binding resolves
			 * to nothing outside uichemy_dynamic_free_fields() - so pointing
			 * there would hand back a page that looks built and is blank, which
			 * is the failure this whole layer exists to prevent.
			 */
			if ( $modelling && ! $out['writes_enabled'] ) {
				$out['notes'][] = 'The fields listed here are REAL, but this build can neither read nor write their values, nor create post types, taxonomies or fields - that needs UiChemy Pro (' . uichemy_upgrade_url( 'custom-fields' ) . '). This is a licence boundary, not an empty site, so do not report the site as having no data. Nor can a custom-field value be RENDERED on this build: only post title, content, link, id and the featured-image chain resolve, so do not bind one and do not invent placeholder content in its place. Say plainly that the value layer needs Pro, and build what is asked from the free post fields or from content the user supplies. ACF and JetEngine can still edit these values in their own admin screens.';
			}

			return $out;
		}

		/**
		 * One provider by slug.
		 *
		 * @param string $slug Provider slug.
		 * @return Uich_Field_Provider|WP_Error
		 */
		public static function provider( $slug ) {
			$providers = self::providers();
			$slug      = sanitize_key( (string) $slug );

			if ( ! isset( $providers[ $slug ] ) ) {
				return new WP_Error(
					'uich_field_unknown_provider',
					sprintf( 'Unknown provider "%s". Known: %s.', $slug, implode( ', ', array_keys( $providers ) ) )
				);
			}

			if ( ! $providers[ $slug ]->is_active() ) {
				return new WP_Error(
					'uich_field_provider_inactive',
					sprintf( '%s is not active on this site.', $providers[ $slug ]->label() )
				);
			}

			return $providers[ $slug ];
		}

		/**
		 * Every field definition reachable on one target, per provider.
		 *
		 * @param array $target Resolved target.
		 * @return array<string,array<string,array>> Provider slug => name => definition.
		 */
		public static function definitions( $target ) {
			$cache_key = self::cache_key( $target );

			if ( isset( self::$defs_cache[ $cache_key ] ) ) {
				return self::$defs_cache[ $cache_key ];
			}

			$out = array();

			foreach ( self::active() as $slug => $provider ) {
				$defs = $provider->definitions( $target );
				if ( $defs ) {
					$out[ $slug ] = $defs;
				}
			}

			self::$defs_cache[ $cache_key ] = $out;

			return $out;
		}

		/**
		 * Flatten definitions to one name => definition map, first provider wins,
		 * with collisions recorded on the definition itself.
		 *
		 * @param array $target Resolved target.
		 * @return array<string,array>
		 */
		public static function flat_definitions( $target ) {
			$by_provider = self::definitions( $target );
			$flat        = array();

			foreach ( $by_provider as $slug => $defs ) {
				foreach ( $defs as $name => $def ) {
					if ( isset( $flat[ $name ] ) ) {
						$flat[ $name ]['also_claimed_by'][] = $slug;
						continue;
					}

					$def['also_claimed_by'] = array();
					$flat[ $name ]          = $def;
				}
			}

			return $flat;
		}

		/**
		 * Resolve one field name on one target to its definition and provider.
		 *
		 * @param string $name   Field name.
		 * @param array  $target Resolved target.
		 * @param string $prefer Optional provider slug to pin.
		 * @return array{def:array,provider:Uich_Field_Provider}|WP_Error
		 */
		public static function resolve( $name, $target, $prefer = '' ) {
			$name        = (string) $name;
			$by_provider = self::definitions( $target );

			if ( '' !== $prefer ) {
				$prefer = sanitize_key( $prefer );

				if ( ! isset( $by_provider[ $prefer ] ) || ! isset( $by_provider[ $prefer ][ $name ] ) ) {
					return new WP_Error(
						'uich_field_not_on_provider',
						sprintf(
							'%s has no field "%s" on %s. Its fields there: %s.',
							$prefer,
							$name,
							isset( $target['label'] ) ? $target['label'] : 'that object',
							isset( $by_provider[ $prefer ] ) ? ( implode( ', ', array_keys( $by_provider[ $prefer ] ) ) ?: 'none' ) : 'none'
						)
					);
				}

				return array(
					'def'      => $by_provider[ $prefer ][ $name ],
					'provider' => self::providers()[ $prefer ],
				);
			}

			foreach ( $by_provider as $slug => $defs ) {
				if ( isset( $defs[ $name ] ) ) {
					return array(
						'def'      => $defs[ $name ],
						'provider' => self::providers()[ $slug ],
					);
				}
			}

			return new WP_Error(
				'uich_field_unknown',
				sprintf(
					'No field named "%s" is attached to %s, so nothing was written. UiChemy refuses an unknown name rather than storing it as loose meta - loose meta is a value the site never reads back, reported as a success. Known fields there: %s. Read them with uichemy-composer/custom-fields (action="list"); create one with uichemy-composer/cpt (action="add-fields").',
					$name,
					isset( $target['label'] ) ? $target['label'] : 'that object',
					self::known_names( $target ) ?: 'none - no field group targets this object'
				)
			);
		}

		/**
		 * Comma-separated known field names on a target, for error messages.
		 *
		 * @param array $target Resolved target.
		 * @return string
		 */
		public static function known_names( $target ) {
			$names = array();

			foreach ( self::definitions( $target ) as $slug => $defs ) {
				foreach ( array_keys( $defs ) as $name ) {
					$names[] = $name . ' (' . $slug . ')';
				}
			}

			return implode( ', ', $names );
		}

		/**
		 * Drop the per-target definition cache.
		 *
		 * Called after a field-group change, since the definitions a target has
		 * are exactly what such a change alters.
		 *
		 * @return void
		 */
		public static function flush() {
			self::$defs_cache = array();
			self::$providers  = null;
		}

		/**
		 * Cache key for one target.
		 *
		 * @param array $target Resolved target.
		 * @return string
		 */
		private static function cache_key( $target ) {
			return implode(
				'|',
				array(
					isset( $target['object_type'] ) ? $target['object_type'] : '',
					isset( $target['object_subtype'] ) ? $target['object_subtype'] : '',
					isset( $target['object_id'] ) ? (int) $target['object_id'] : 0,
				)
			);
		}
	}
}
