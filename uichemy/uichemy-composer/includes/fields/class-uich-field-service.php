<?php
/**
 * The field-value service: list, get, update, delete - and the one write funnel.
 *
 * Every writer in UiChemy that touches a custom field comes through
 * Uich_Field_Service::update(), so there is one place where the five steps
 * happen and no second implementation to drift:
 *
 *   1. Resolve the target - object_type + object_subtype + object_id => a real WP
 *      object, with an edit-capability check on THAT object.
 *   2. Route each name to its owning provider. Never assume meta.
 *   3. Refuse unknown names, with the list of known ones.
 *   4. Coerce by declared type - dates, booleans, media, terms, rows.
 *   5. Assert the storage SHAPE, then return the re-read value.
 *
 * Step 5 is not round-trip equality. Equality PASSES on both known corruption
 * paths - an ACF value written around update_field() reads back fine and shows
 * blank in the editor, and an ACF-free "repeater" reads back intact and appears
 * as one empty row under ACF Pro. So the assertion is structural, and the
 * re-read value is returned as well, because with no PHP runner on the other end
 * it is the only self-correction signal the caller will ever get.
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

if ( ! class_exists( 'Uich_Field_Service' ) ) {

	/**
	 * Orchestrates field reads and writes across providers.
	 */
	final class Uich_Field_Service {

		/**
		 * Option prefix under which a write snapshot is parked for revert.
		 */
		const REVERT_PREFIX = 'uich_field_revert_';

		/**
		 * How long a revert token stays usable.
		 */
		const REVERT_TTL = DAY_IN_SECONDS;

		// ============================================================
		// LIST - definitions
		// ============================================================

		/**
		 * Field DEFINITIONS for an object type, or for one specific object.
		 *
		 * Definitions, not values: this is the call that has to happen before any
		 * binding is written, because an unknown field name renders empty rather
		 * than failing - a guess produces a page that looks built and is blank,
		 * with nothing in the output to say so.
		 *
		 * @param array $args { object_type, object_subtype?, object_id?, provider?, names? }.
		 * @return array|WP_Error
		 */
		public static function list_fields( $args ) {
			$args = is_array( $args ) ? $args : array();

			$target = self::target_for_listing( $args );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$prefer = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : '';
			$only   = isset( $args['names'] ) ? array_map( 'strval', (array) $args['names'] ) : array();

			// One row per NAME, not per provider. A name is a single meta key, so
			// two providers claiming it are two views of one field - and JetEngine
			// registers its fields through register_post_meta() as well, which
			// means every JetEngine field would otherwise be listed twice. The
			// row reports the owner a write will actually route to, and
			// `also_claimed_by` names the rest.
			if ( '' !== $prefer ) {
				$by_provider = Uich_Field_Registry::definitions( $target );
				$defs        = isset( $by_provider[ $prefer ] ) ? (array) $by_provider[ $prefer ] : array();
			} else {
				$defs = Uich_Field_Registry::flat_definitions( $target );
			}

			$fields = array();

			foreach ( $defs as $name => $def ) {
				if ( $only && ! in_array( (string) $name, $only, true ) ) {
					continue;
				}

				if ( empty( $def['also_claimed_by'] ) ) {
					unset( $def['also_claimed_by'] );
				} else {
					$def['collision_note'] = sprintf(
						'"%s" is also defined by %s. A write goes through %s. If that is the wrong owner, pass provider explicitly - the two store the same concepts differently, so the wrong one produces a value the template never reads.',
						$name,
						implode( ' and ', (array) $def['also_claimed_by'] ),
						$def['provider']
					);
				}

				$fields[] = $def;
			}

			$out = array(
				'object_type'    => $target['object_type'],
				'object_subtype' => $target['object_subtype'],
				'object_id'      => $target['object_id'] ? $target['object_id'] : null,
				'fields'         => $fields,
				'field_count'    => count( $fields ),
				'providers'      => Uich_Field_Registry::status(),
				'groups'         => self::groups_for( $target ),
			);

			if ( ! $fields ) {
				$out['message'] = sprintf(
					'No custom fields are attached to %s. That is the answer, not an error - either no field group targets it, or no field-modelling plugin is active (see "providers"). Create fields with uichemy-composer/cpt (action="register-field-group" then "add-fields"). Do NOT bind a guessed field name: an unknown name renders empty and reports nothing.',
					'' !== $target['object_subtype'] ? $target['object_type'] . ' / ' . $target['object_subtype'] : $target['object_type']
				);
			}

			return $out;
		}

		/**
		 * Field groups that reach THIS target, per provider.
		 *
		 * Scoped, not every group on the site. An unscoped list is the reason a
		 * model offers an options-page field as a candidate on a post template,
		 * where it can never resolve - and a group listed against a post type it
		 * does not target reads as "this field is available here" when it is not.
		 *
		 * @param array $target Resolved target.
		 * @return array<int,array>
		 */
		private static function groups_for( $target ) {
			if ( ! class_exists( 'Uich_Model' ) ) {
				return array();
			}

			return Uich_Model::groups_targeting(
				isset( $target['object_type'] ) ? $target['object_type'] : 'post',
				isset( $target['object_subtype'] ) ? $target['object_subtype'] : ''
			);
		}

		/**
		 * Resolve a target for a LISTING call, where object_id is optional.
		 *
		 * A listing keyed only on the object type is the normal discovery call -
		 * "what fields does a `movie` have" is asked before any movie exists.
		 *
		 * @param array $args Ability parameters.
		 * @return array|WP_Error
		 */
		private static function target_for_listing( $args ) {
			$has_id = isset( $args['object_id'] ) && is_numeric( $args['object_id'] ) && (int) $args['object_id'] > 0;

			if ( $has_id ) {
				$target = Uich_Field_Guard::resolve_target( $args );
				if ( is_wp_error( $target ) ) {
					return $target;
				}

				$can = Uich_Field_Guard::can( $target, 'read' );
				if ( is_wp_error( $can ) ) {
					return $can;
				}

				return $target;
			}

			$type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : 'post';
			$sub  = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';

			if ( ! in_array( $type, Uich_Field_Guard::object_types(), true ) ) {
				return new WP_Error(
					'uich_field_bad_object_type',
					sprintf( 'Unknown object_type "%s". Valid: %s.', $type, implode( ', ', Uich_Field_Guard::object_types() ) )
				);
			}

			if ( 'post' === $type && '' !== $sub && ! post_type_exists( $sub ) ) {
				return new WP_Error(
					'uich_field_bad_subtype',
					sprintf( 'No post type "%s" is registered. Real ones: %s. Create one with uichemy-composer/cpt (action="register-post-type").', $sub, implode( ', ', get_post_types( array(), 'names' ) ) )
				);
			}

			if ( 'term' === $type && '' !== $sub && ! taxonomy_exists( $sub ) ) {
				return new WP_Error(
					'uich_field_bad_subtype',
					sprintf( 'No taxonomy "%s" is registered. Real ones: %s.', $sub, implode( ', ', get_taxonomies( array(), 'names' ) ) )
				);
			}

			return array(
				'object_type'    => $type,
				'object_subtype' => 'user' === $type ? 'user' : $sub,
				'object_id'      => 0,
				'acf_id'         => 'options' === $type ? 'option' : '',
				'label'          => '' !== $sub ? $type . ' / ' . $sub : $type,
			);
		}

		// ============================================================
		// GET - values
		// ============================================================

		/**
		 * Read field VALUES on one object.
		 *
		 * Always returns both `value` (what is stored, and what is safe to send
		 * back in a write) and `formatted` (what renders). There is no format
		 * parameter, because setting it wrongly is the corruption path: writing a
		 * rendered ACF date back turns 7 September into today, and the corrupted
		 * value reads back looking like a date, so verification passes.
		 *
		 * @param array $args { object_type, object_subtype?, object_id, names?, provider? }.
		 * @return array|WP_Error
		 */
		public static function get( $args ) {
			$args = is_array( $args ) ? $args : array();

			$gate = self::values_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$target = Uich_Field_Guard::resolve_target( $args );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$can = Uich_Field_Guard::can( $target, 'read' );
			if ( is_wp_error( $can ) ) {
				return $can;
			}

			$prefer = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : '';
			$names  = isset( $args['names'] ) ? array_map( 'strval', (array) $args['names'] ) : array();

			$flat = Uich_Field_Registry::flat_definitions( $target );

			if ( ! $names ) {
				$names = array_keys( $flat );
			}

			$values  = array();
			$unknown = array();

			foreach ( $names as $name ) {
				$resolved = Uich_Field_Registry::resolve( $name, $target, $prefer );

				if ( is_wp_error( $resolved ) ) {
					$unknown[] = array(
						'name'   => $name,
						'reason' => $resolved->get_error_message(),
					);
					continue;
				}

				$values[] = self::read_one( $resolved['def'], $resolved['provider'], $target );
			}

			$out = array(
				'object_type'    => $target['object_type'],
				'object_subtype' => $target['object_subtype'],
				'object_id'      => $target['object_id'],
				'object'         => $target['label'],
				'values'         => $values,
			);

			if ( $unknown ) {
				$out['unknown_fields'] = $unknown;
			}

			if ( ! $values && ! $unknown ) {
				$out['message'] = sprintf( 'No custom fields are attached to %s. Nothing to read.', $target['label'] );
			}

			return $out;
		}

		/**
		 * Read one field into the response row.
		 *
		 * @param array                $def      Normalised definition.
		 * @param Uich_Field_Provider  $provider Owning provider.
		 * @param array                $target   Resolved target.
		 * @return array
		 */
		private static function read_one( $def, $provider, $target ) {
			$row = array(
				'name'          => $def['name'],
				'provider'      => $def['provider'],
				'type'          => $def['type'],
				'value_shape'   => $def['value_shape'],
				'return_format' => $def['return_format'],
				// Carried on the READ because reading a value is usually the step
				// before printing it, and the printing traps are the ones that
				// reach a visitor: a JetEngine date bound bare renders a Unix
				// timestamp on the page.
				'print_hint'    => $def['print_hint'],
			);

			if ( Uich_Field_Guard::is_sensitive( $def['name'] ) ) {
				$row['value']     = null;
				$row['formatted'] = null;
				$row['withheld']  = 'The field name looks like a credential, so the value is reported by name only. Ask the user for it rather than reading it here.';

				return $row;
			}

			$read = $provider->read( $def, $target );

			$row['value']     = isset( $read['value'] ) ? $read['value'] : null;
			$row['formatted'] = isset( $read['formatted'] ) ? $read['formatted'] : null;
			$row['is_empty']  = ( null === $row['value'] || '' === $row['value'] || array() === $row['value'] );

			if ( $row['value'] !== $row['formatted'] ) {
				$row['write_back'] = 'Send "value" back on a write, never "formatted" - the formatted form is display output and writing it corrupts the stored value in a way every read-back still looks correct.';
			}

			return $row;
		}

		// ============================================================
		// UPDATE - the write funnel
		// ============================================================

		/**
		 * Write field values, optionally to several objects, optionally dry.
		 *
		 * @param array $args { object_type, object_subtype?, object_id|object_ids, fields, provider?, dry_run? }.
		 * @return array|WP_Error
		 */
		public static function update( $args ) {
			$args = is_array( $args ) ? $args : array();

			$gate = self::values_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();

			if ( ! $fields ) {
				return new WP_Error(
					'uich_field_no_fields',
					'"fields" is required: an object of { field_name: value }. Read the names with action="list" first - an unknown name is refused here, not written as loose meta.'
				);
			}

			$dry     = ! empty( $args['dry_run'] );
			$prefer  = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : '';
			$ids     = self::object_ids( $args );
			$results = array();

			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			$snapshot = array();

			foreach ( $ids as $id ) {
				$one = self::update_one(
					array_merge( $args, array( 'object_id' => $id ) ),
					$fields,
					$prefer,
					$dry
				);

				if ( is_wp_error( $one ) ) {
					// A per-object failure does not abort the batch: the objects
					// already written stay written, and saying which is more
					// useful than a single error for the whole call.
					$results[] = array(
						'object_id' => $id,
						'ok'        => false,
						'error'     => $one->get_error_message(),
						'code'      => $one->get_error_code(),
					);
					continue;
				}

				if ( ! empty( $one['snapshot'] ) ) {
					$snapshot[] = $one['snapshot'];
					unset( $one['snapshot'] );
				}

				$results[] = $one;
			}

			$out = array(
				'dry_run'   => $dry,
				'objects'   => $results,
				'written'   => count(
					array_filter(
						$results,
						static function ( $r ) {
							return ! empty( $r['ok'] );
						}
					)
				),
				'failed'    => count(
					array_filter(
						$results,
						static function ( $r ) {
							return empty( $r['ok'] );
						}
					)
				),
			);

			if ( $dry ) {
				$out['message'] = 'Nothing was written. Every field was resolved, coerced and validated, and the coerced values are reported as "would_store" so you can see exactly what a real write would put in storage.';

				return $out;
			}

			if ( $snapshot ) {
				$out['revert_token'] = self::park_snapshot( $snapshot );
				$out['revert_note']  = sprintf( 'Pass revert_token to action="revert" within %d hours to restore the previous values of the fields this call changed. It restores nothing else.', (int) ( self::REVERT_TTL / HOUR_IN_SECONDS ) );
			}

			return $out;
		}

		/**
		 * The write funnel for one object.
		 *
		 * Validation is a separate pass over every field BEFORE anything is
		 * written, so a payload with one bad value does not leave the object half
		 * updated.
		 *
		 * @param array  $args   Ability parameters with a single object_id.
		 * @param array  $fields name => value.
		 * @param string $prefer Provider slug to pin, or ''.
		 * @param bool   $dry    Validate only.
		 * @return array|WP_Error
		 */
		private static function update_one( $args, $fields, $prefer, $dry ) {
			// 1 - resolve the target and check the capability on THIS object.
			$target = Uich_Field_Guard::resolve_target( $args );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$can = Uich_Field_Guard::can( $target, 'write' );
			if ( is_wp_error( $can ) ) {
				return $can;
			}

			// 2 + 3 - route every name, refusing unknowns, and 4 - coerce. All
			// before any write.
			$plan  = array();
			$notes = array();

			foreach ( $fields as $name => $value ) {
				$name = (string) $name;

				// The denylist runs BEFORE resolution, so a protected or
				// plugin-owned key gets the reason that names the right door -
				// `_price` has to say "use uichemy-composer/store", not "unknown
				// field". No provider claims those keys, so resolving first would
				// bury every one of them under the generic unknown-name error.
				$allowed = Uich_Field_Guard::meta_allowed( $name, $target );
				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}

				$resolved = Uich_Field_Registry::resolve( $name, $target, $prefer );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}

				$def = $resolved['def'];

				if ( empty( $def['writable'] ) ) {
					return new WP_Error( 'uich_field_not_writable', sprintf( 'Field "%s" is not writable. %s', $name, $def['reason'] ) );
				}

				$coerced = Uich_Field_Value::coerce( $def, $value, $target );
				if ( is_wp_error( $coerced ) ) {
					return $coerced;
				}

				$notes = array_merge( $notes, $coerced['notes'] );

				$plan[] = array(
					'def'      => $def,
					'provider' => $resolved['provider'],
					'value'    => $coerced['value'],
				);
			}

			if ( $dry ) {
				$would = array();
				foreach ( $plan as $step ) {
					$would[] = array(
						'name'        => $step['def']['name'],
						'provider'    => $step['def']['provider'],
						'type'        => $step['def']['type'],
						'would_store' => $step['value'],
						'write_hint'  => $step['def']['write_hint'],
						// A1: the write path is where a value is bound next, so
						// the PRINTING trap has to arrive here too. An agent that
						// only ever calls update never sees the hint that stops it
						// binding a boolean bare (renders "1" or nothing) or a
						// taxonomy field bare (renders a term ID).
						'print_hint'  => $step['def']['print_hint'],
					);
				}

				return array(
					'object_id'  => $target['object_id'],
					'object'     => $target['label'],
					'ok'         => true,
					'would_store' => $would,
					'notes'      => array_values( array_unique( $notes ) ),
				);
			}

			// Snapshot the prior values of exactly the fields being changed,
			// before anything is written.
			$prior = array();
			foreach ( $plan as $step ) {
				$read = $step['provider']->read( $step['def'], $target );
				$prior[ $step['def']['name'] ] = array(
					'provider' => $step['def']['provider'],
					'value'    => isset( $read['value'] ) ? $read['value'] : null,
				);
			}

			$written  = array();
			$problems = array();

			foreach ( $plan as $step ) {
				$ok = $step['provider']->write( $step['def'], $step['value'], $target );

				if ( is_wp_error( $ok ) ) {
					$problems[] = $ok->get_error_message();
					continue;
				}

				// 5 - assert the SHAPE the owning plugin expects, then re-read.
				$shape_problems = $step['provider']->assert_shape( $step['def'], $target );
				$reread         = $step['provider']->read( $step['def'], $target );

				$row = array(
					'name'       => $step['def']['name'],
					'provider'   => $step['def']['provider'],
					'type'       => $step['def']['type'],
					'stored'     => isset( $reread['value'] ) ? $reread['value'] : null,
					'formatted'  => isset( $reread['formatted'] ) ? $reread['formatted'] : null,
					// A1.
					'print_hint' => $step['def']['print_hint'],
				);

				// A5 - what the TOKEN resolves to, not what storage holds. These
				// are different systems and they have disagreed: a number field
				// storing "22" printed an unrelated image URL, because the render
				// engine coerced any numeric meta colliding with an attachment ID
				// into that attachment. Storage was perfect and the page was
				// wrong, and nothing in this response said so.
				$row = array_merge( $row, self::render_check( $step['def'], $target ) );

				if ( $shape_problems ) {
					$row['shape_problems'] = $shape_problems;
					$problems              = array_merge( $problems, $shape_problems );
				}

				$written[] = $row;
			}

			$out = array(
				'object_id' => $target['object_id'],
				'object'    => $target['label'],
				'ok'        => empty( $problems ),
				'fields'    => $written,
				'snapshot'  => array(
					'object_type'    => $target['object_type'],
					'object_subtype' => $target['object_subtype'],
					'object_id'      => $target['object_id'],
					'prior'          => $prior,
				),
			);

			if ( $notes ) {
				$out['notes'] = array_values( array_unique( $notes ) );
			}

			if ( $problems ) {
				$out['problems'] = array_values( array_unique( $problems ) );
				$out['warning']  = 'The write ran and the values above ARE in storage, but the storage shape is not what the owning plugin expects. Do not report this as a success - read "problems" and fix the cause.';
			}

			$out['verified'] = 'storage + token resolution. "stored" is what the owning plugin holds; "renders_as" is what {{ ... }} prints for it today. Neither is a check that the PAGE is right - layout, conditions and the template that binds the field are not exercised by this call. Look at the rendered page before reporting the build done.';

			return $out;
		}

		/**
		 * What the dynamic token for this field actually resolves to, right now.
		 *
		 * The storage assertion above answers "did the owning plugin get the shape
		 * it expects". This answers the different question an agent is really
		 * asking when it writes a value and immediately binds it: "will the page
		 * show this". The render engine is a separate system with its own
		 * coercions, and a write confirmation that reads as end-to-end when it is
		 * not is how a wrong page ships with a clean tool log.
		 *
		 * Best-effort by design: a target the dynamic layer has no provider for
		 * returns no keys at all rather than a misleading empty string.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array
		 */
		private static function render_check( $def, $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : '';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
			$name = isset( $def['name'] ) ? (string) $def['name'] : '';

			if ( 'post' !== $type || ! $id || '' === $name ) {
				return array();
			}

			if ( ! class_exists( 'Uich_Dynamic' ) || ! method_exists( 'Uich_Dynamic', 'post_provider' ) ) {
				return array();
			}

			// post.meta() is a Pro provider. Without it every token resolves to
			// null, and reporting that as "renders as nothing" would blame the
			// write for a licence boundary.
			if ( ! function_exists( 'uichemy_is_pro' ) || ! uichemy_is_pro() ) {
				return array();
			}

			$post = get_post( $id );

			if ( ! $post ) {
				return array();
			}

			$provider = Uich_Dynamic::post_provider( $post );

			if ( ! ( $provider instanceof Uich_Provider ) ) {
				return array();
			}

			// uich_get(), not uich_read(): the Free/Pro gate belongs to the
			// template engine, and reporting "" here on a Free build would read as
			// a broken write rather than an unlicensed feature.
			$resolved = $provider->uich_get( 'meta', array( $name ) );

			$out = array(
				'token'      => sprintf( '{{ post.meta(\'%s\') }}', $name ),
				'renders_as' => self::stringify_like_template( $resolved ),
			);

			if ( is_array( $resolved ) ) {
				$out['render_note'] = 'The token resolves to an ARRAY, and an array prints as nothing. Loop it, or print one member - see "print_hint".';
			} elseif ( $resolved instanceof Uich_Provider ) {
				$out['render_note'] = sprintf(
					'The token resolves to a %s object, not a scalar. It prints as the string above and chains further (see "print_hint").',
					method_exists( $resolved, 'uich_kind' ) ? $resolved->uich_kind() : 'provider'
				);
			} elseif ( '' === $out['renders_as'] ) {
				$out['render_note'] = 'The token resolves to NOTHING on the page even though the value is in storage. That is the signature of a wrong provider prefix, a Free-tier field, or a value the render layer reads through a different key - do not bind this until it prints.';
			}

			return $out;
		}

		/**
		 * Render a resolved token value the way the template engine prints it.
		 *
		 * @param mixed $value Resolved value.
		 * @return string
		 */
		private static function stringify_like_template( $value ) {
			if ( null === $value || false === $value ) {
				return '';
			}

			if ( true === $value ) {
				return '1';
			}

			if ( is_array( $value ) ) {
				return '';
			}

			if ( $value instanceof Uich_Provider ) {
				$value = $value->uich_get( '__toString', array() );

				if ( null === $value || is_array( $value ) || is_object( $value ) ) {
					return '';
				}
			}

			return (string) $value;
		}

		// ============================================================
		// DELETE
		// ============================================================

		/**
		 * Clear field values on one object.
		 *
		 * Separate from update() because it is destructive and because a null in
		 * a `fields` payload is too easy to send by accident.
		 *
		 * @param array $args { object_type, object_subtype?, object_id, names, provider? }.
		 * @return array|WP_Error
		 */
		public static function delete( $args ) {
			$args = is_array( $args ) ? $args : array();

			$gate = self::values_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$names = isset( $args['names'] ) ? array_filter( array_map( 'strval', (array) $args['names'] ) ) : array();

			if ( ! $names ) {
				return new WP_Error(
					'uich_field_no_names',
					'"names" is required - the field names to clear. Clearing every field on an object is not offered, because it is never what was meant.'
				);
			}

			$target = Uich_Field_Guard::resolve_target( $args );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$can = Uich_Field_Guard::can( $target, 'write' );
			if ( is_wp_error( $can ) ) {
				return $can;
			}

			$prefer  = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : '';
			$cleared = array();
			$prior   = array();

			foreach ( $names as $name ) {
				$resolved = Uich_Field_Registry::resolve( $name, $target, $prefer );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}

				$read = $resolved['provider']->read( $resolved['def'], $target );
				$prior[ $name ] = array(
					'provider' => $resolved['def']['provider'],
					'value'    => isset( $read['value'] ) ? $read['value'] : null,
				);

				$ok = $resolved['provider']->clear( $resolved['def'], $target );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}

				$cleared[] = array(
					'name'     => $name,
					'provider' => $resolved['def']['provider'],
					'was'      => $prior[ $name ]['value'],
				);
			}

			$token = self::park_snapshot(
				array(
					array(
						'object_type'    => $target['object_type'],
						'object_subtype' => $target['object_subtype'],
						'object_id'      => $target['object_id'],
						'prior'          => $prior,
					),
				)
			);

			return array(
				'object_id'    => $target['object_id'],
				'object'       => $target['label'],
				'cleared'      => $cleared,
				'revert_token' => $token,
				'revert_note'  => 'The cleared values are recoverable with action="revert" and this token. The field DEFINITIONS were not touched - this cleared values only.',
			);
		}

		// ============================================================
		// REVERT
		// ============================================================

		/**
		 * Restore the values a previous update() or delete() replaced.
		 *
		 * @param array $args { revert_token }.
		 * @return array|WP_Error
		 */
		public static function revert( $args ) {
			/*
			 * Deliberately NOT behind values_gate(). A revert needs a token, and
			 * the only thing that mints one is park_snapshot(), reached solely
			 * from the gated update() and delete(). So a Free build has no token
			 * to present and this refuses on the token check instead.
			 *
			 * The one window it leaves open is a Pro -> Free downgrade inside
			 * the 24h transient TTL, where undoing what Pro wrote is the
			 * behaviour we want: the alternative is stranding a site mid-change
			 * because the licence lapsed between the write and the undo.
			 */
			$args  = is_array( $args ) ? $args : array();
			$token = isset( $args['revert_token'] ) ? sanitize_key( (string) $args['revert_token'] ) : '';

			if ( '' === $token ) {
				return new WP_Error( 'uich_field_no_token', '"revert_token" is required. It is returned by every action="update" and action="delete" that changed something.' );
			}

			$snapshot = get_transient( self::REVERT_PREFIX . $token );

			if ( ! is_array( $snapshot ) ) {
				return new WP_Error(
					'uich_field_token_expired',
					sprintf( 'Revert token "%s" is unknown or has expired (tokens last %d hours). The values it held are not recoverable from here.', $token, (int) ( self::REVERT_TTL / HOUR_IN_SECONDS ) )
				);
			}

			$restored = array();

			foreach ( $snapshot as $entry ) {
				$target = Uich_Field_Guard::resolve_target( $entry );
				if ( is_wp_error( $target ) ) {
					continue;
				}

				$can = Uich_Field_Guard::can( $target, 'write' );
				if ( is_wp_error( $can ) ) {
					return $can;
				}

				foreach ( (array) ( isset( $entry['prior'] ) ? $entry['prior'] : array() ) as $name => $row ) {
					$resolved = Uich_Field_Registry::resolve( (string) $name, $target, isset( $row['provider'] ) ? $row['provider'] : '' );
					if ( is_wp_error( $resolved ) ) {
						continue;
					}

					$value = isset( $row['value'] ) ? $row['value'] : null;

					if ( null === $value || '' === $value ) {
						$resolved['provider']->clear( $resolved['def'], $target );
					} else {
						$resolved['provider']->write( $resolved['def'], $value, $target );
					}

					$restored[] = array(
						'object_id' => $target['object_id'],
						'name'      => (string) $name,
						'value'     => $value,
					);
				}
			}

			delete_transient( self::REVERT_PREFIX . $token );

			return array(
				'restored' => $restored,
				'message'  => 'The token is now spent. Reverting a revert is not possible - take a fresh read before the next write.',
			);
		}

		// ============================================================
		// HELPERS
		// ============================================================

		/**
		 * Park a snapshot and return its token.
		 *
		 * A transient rather than a row of its own: a revert is useful for
		 * minutes, not for the life of the site, and an unbounded audit table is
		 * a different feature with a different retention question.
		 *
		 * @param array $snapshot Per-object prior values.
		 * @return string
		 */
		private static function park_snapshot( $snapshot ) {
			$token = wp_generate_password( 12, false, false );
			$token = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $token ) );

			set_transient( self::REVERT_PREFIX . $token, $snapshot, self::REVERT_TTL );

			return $token;
		}

		/**
		 * The object ids one update call addresses.
		 *
		 * `object_ids` exists so seeding twenty populated posts is twenty writes
		 * in one call rather than twenty calls.
		 *
		 * @param array $args Ability parameters.
		 * @return array<int,int>|WP_Error
		 */
		private static function object_ids( $args ) {
			$ids = array();

			if ( isset( $args['object_ids'] ) && is_array( $args['object_ids'] ) ) {
				foreach ( $args['object_ids'] as $id ) {
					if ( is_numeric( $id ) && (int) $id > 0 ) {
						$ids[] = (int) $id;
					}
				}
			}

			if ( isset( $args['object_id'] ) && is_numeric( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
				$ids[] = (int) $args['object_id'];
			}

			$ids = array_values( array_unique( $ids ) );

			if ( ! $ids ) {
				return new WP_Error(
					'uich_field_missing_object_id',
					'Pass "object_id" for one object, or "object_ids" for several. Resolve real ids with uichemy-composer/describe-site (action="entities").'
				);
			}

			if ( count( $ids ) > 100 ) {
				return new WP_Error(
					'uich_field_batch_too_large',
					sprintf( '%d objects in one call is beyond what this runs safely. Send at most 100 per call.', count( $ids ) )
				);
			}

			return $ids;
		}

		/**
		 * Whether this build may read and write custom-field VALUES.
		 *
		 * Definitions stay readable everywhere: knowing the content model is what
		 * stops a guessed binding, and that is worth having in every build.
		 *
		 * Public because the relation write in Uich_Field_Relations is the same
		 * boundary reached through a different class, and one gate for the whole
		 * value layer is the point.
		 *
		 * @return true|WP_Error
		 */
		public static function values_gate() {
			if ( uichemy_field_writes_allowed() ) {
				return true;
			}

			return new WP_Error(
				'uich_field_requires_pro',
				'Reading and writing custom-field VALUES needs UiChemy Pro. Every custom-field dynamic tag is already Pro, so a value written here could not be rendered by this build - the write would be real and useless. Field DEFINITIONS are readable in every build: use action="list" to learn the content model, and uichemy-composer/dynamic (action="list-fields") for what this build can bind. Upgrade at ' . uichemy_upgrade_url( 'custom-fields' ) . '.'
			);
		}
	}
}
