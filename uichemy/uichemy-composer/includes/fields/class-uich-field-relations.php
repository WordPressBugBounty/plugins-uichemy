<?php
/**
 * Relation membership - JetEngine relations today, provider-neutral by design.
 *
 * A relation is NOT meta. JetEngine keeps its rows in its own database tables,
 * so a meta write aimed at a relation goes nowhere, returns success, and leaves
 * an empty relation behind. That is why this is a separate pair of actions
 * rather than a field type inside custom-fields/update.
 *
 * The names stay provider-neutral (`get-relations`, not `jet-relations`): Pods
 * has relations too, and ACF relationship fields are plain meta so they already
 * flow through get/update. The gate is "a provider that declares relation
 * support", which today means JetEngine only.
 *
 * The actions are omitted from meta.actions entirely when nothing on the site
 * supports relations, and answer with a specific reason if called anyway - never
 * a generic unknown-action error. A tag that always renders nothing is worse
 * than one that is absent, because the absence is the answer.
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

if ( ! class_exists( 'Uich_Field_Relations' ) ) {

	/**
	 * Reads and changes relation membership.
	 */
	final class Uich_Field_Relations {

		/**
		 * Whether any active provider supports relations.
		 *
		 * @return bool
		 */
		public static function supported() {
			$supported = function_exists( 'jet_engine' )
				&& ! empty( jet_engine()->relations )
				&& method_exists( jet_engine()->relations, 'get_active_relations' );

			/**
			 * Filter whether the relation actions are exposed.
			 *
			 * @since 5.1.0
			 *
			 * @param bool $supported Whether a relation-capable provider is active.
			 */
			return (bool) apply_filters( 'uichemy/fields/relations_supported', $supported );
		}

		/**
		 * The specific refusal for a call that arrived anyway.
		 *
		 * @return WP_Error
		 */
		public static function unsupported() {
			return new WP_Error(
				'uich_relations_unsupported',
				'No relation-capable provider is active on this site, so there are no relations to read or change. JetEngine is the one UiChemy supports; ACF relationship fields are plain meta and already work through uichemy-composer/custom-fields (action="get" / "update"). This is why the *-relations actions are absent from meta.actions here.'
			);
		}

		/**
		 * Every relation registered on the site, and - with an object - the
		 * membership of that object.
		 *
		 * @param array $args { object_type?, object_subtype?, object_id?, relation_id? }.
		 * @return array|WP_Error
		 */
		public static function get( $args ) {
			if ( ! self::supported() ) {
				return self::unsupported();
			}

			$args = is_array( $args ) ? $args : array();

			$relations = self::relation_list( isset( $args['relation_id'] ) ? $args['relation_id'] : 0 );

			if ( is_wp_error( $relations ) ) {
				return $relations;
			}

			$out = array(
				'provider'  => 'jetengine',
				'relations' => array(),
			);

			$target = null;

			if ( isset( $args['object_id'] ) && is_numeric( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
				$target = Uich_Field_Guard::resolve_target( $args );
				if ( is_wp_error( $target ) ) {
					return $target;
				}

				$can = Uich_Field_Guard::can( $target, 'read' );
				if ( is_wp_error( $can ) ) {
					return $can;
				}

				$out['object_id'] = $target['object_id'];
				$out['object']    = $target['label'];
			}

			foreach ( $relations as $id => $relation ) {
				$row = self::describe_relation( $id, $relation );

				if ( $target ) {
					$row['membership'] = self::membership( $relation, $target );
				}

				$out['relations'][] = $row;
			}

			if ( ! $out['relations'] ) {
				$out['message'] = 'JetEngine is active but no relations are registered. Create one in JetEngine > Relations - UiChemy does not create relations, because a relation defines the content model and its two sides have to be chosen deliberately.';
			}

			return $out;
		}

		/**
		 * Add or remove relation membership.
		 *
		 * @param array $args { relation_id, object_id, add?, remove?, replace? }.
		 * @return array|WP_Error
		 */
		public static function update( $args ) {
			if ( ! self::supported() ) {
				return self::unsupported();
			}

			// The Pro gate belongs HERE, next to the write, not in the caller's
			// switch. Uich_Field_Service gates get/update/delete as their own
			// first statement for the same reason: a gate in the dispatcher is
			// a gate someone adding the next action has to remember.
			$gate = Uich_Field_Service::values_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$args = is_array( $args ) ? $args : array();

			$rel_id = isset( $args['relation_id'] ) ? (int) $args['relation_id'] : 0;

			if ( ! $rel_id ) {
				return new WP_Error(
					'uich_relations_no_id',
					'"relation_id" is required. List the relations with action="get-relations" - each row carries its id and which side is the parent.'
				);
			}

			$relations = self::relation_list( $rel_id );
			if ( is_wp_error( $relations ) ) {
				return $relations;
			}

			$relation = reset( $relations );

			$target = Uich_Field_Guard::resolve_target( $args );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$can = Uich_Field_Guard::can( $target, 'write' );
			if ( is_wp_error( $can ) ) {
				return $can;
			}

			$side = self::side_of( $relation, $target );

			if ( is_wp_error( $side ) ) {
				return $side;
			}

			$add     = self::ids( isset( $args['add'] ) ? $args['add'] : array() );
			$remove  = self::ids( isset( $args['remove'] ) ? $args['remove'] : array() );
			$replace = isset( $args['replace'] ) && is_array( $args['replace'] ) ? self::ids( $args['replace'] ) : null;

			if ( null === $replace && ! $add && ! $remove ) {
				return new WP_Error(
					'uich_relations_nothing_to_do',
					'Pass "add", "remove", or "replace". "replace" sets the membership to exactly the ids given and detaches the rest - it is the destructive one, so it is named rather than implied.'
				);
			}

			$before = self::membership( $relation, $target );

			if ( null !== $replace ) {
				$current = isset( $before['ids'] ) ? $before['ids'] : array();
				$add     = array_values( array_diff( $replace, $current ) );
				$remove  = array_values( array_diff( $current, $replace ) );
			}

			$added   = array();
			$removed = array();

			foreach ( $add as $other ) {
				$result = 'parent' === $side
					? $relation->update( $target['object_id'], $other )
					: $relation->update( $other, $target['object_id'] );

				if ( false !== $result ) {
					$added[] = $other;
				}
			}

			foreach ( $remove as $other ) {
				if ( 'parent' === $side ) {
					$relation->delete_rows( $target['object_id'], $other );
				} else {
					$relation->delete_rows( $other, $target['object_id'] );
				}
				$removed[] = $other;
			}

			return array(
				'provider'   => 'jetengine',
				'relation'   => self::describe_relation( $rel_id, $relation ),
				'object_id'  => $target['object_id'],
				'object'     => $target['label'],
				'side'       => $side,
				'added'      => $added,
				'removed'    => $removed,
				// Re-read, because relation rows are the one thing here whose
				// state a caller cannot infer from what it sent.
				'membership' => self::membership( $relation, $target ),
			);
		}

		// ============================================================
		// HELPERS
		// ============================================================

		/**
		 * Active relation instances, optionally one by id.
		 *
		 * @param int $rel_id Relation id, or 0 for all.
		 * @return array<int,object>|WP_Error
		 */
		private static function relation_list( $rel_id = 0 ) {
			$rel_id = (int) $rel_id;
			$all    = (array) jet_engine()->relations->get_active_relations();

			if ( ! $rel_id ) {
				return $all;
			}

			if ( ! isset( $all[ $rel_id ] ) ) {
				return new WP_Error(
					'uich_relations_unknown',
					sprintf( 'No active relation with id %d. Active ids: %s.', $rel_id, implode( ', ', array_keys( $all ) ) ?: 'none' )
				);
			}

			return array( $rel_id => $all[ $rel_id ] );
		}

		/**
		 * One relation as a payload row.
		 *
		 * @param int    $id       Relation id.
		 * @param object $relation Relation instance.
		 * @return array
		 */
		private static function describe_relation( $id, $relation ) {
			return array(
				'relation_id'    => (int) $id,
				'name'           => method_exists( $relation, 'get_relation_name' ) ? (string) $relation->get_relation_name() : '',
				'parent_object'  => (string) $relation->get_args( 'parent_object' ),
				'child_object'   => (string) $relation->get_args( 'child_object' ),
				'type'           => (string) $relation->get_args( 'type' ),
				'single_parent'  => method_exists( $relation, 'is_single_parent' ) ? (bool) $relation->is_single_parent() : false,
				'single_child'   => method_exists( $relation, 'is_single_child' ) ? (bool) $relation->is_single_child() : false,
			);
		}

		/**
		 * Which side of a relation an object sits on.
		 *
		 * JetEngine names a side "posts::movie" - an object type and a subtype.
		 * An object that matches neither side is refused: attaching it would
		 * write a row the relation's own queries never return.
		 *
		 * @param object $relation Relation instance.
		 * @param array  $target   Resolved target.
		 * @return string|WP_Error 'parent' | 'child'.
		 */
		private static function side_of( $relation, $target ) {
			$parent = (string) $relation->get_args( 'parent_object' );
			$child  = (string) $relation->get_args( 'child_object' );

			$mine = self::jet_object_name( $target );

			if ( $mine === $parent ) {
				return 'parent';
			}

			if ( $mine === $child ) {
				return 'child';
			}

			return new WP_Error(
				'uich_relations_wrong_side',
				sprintf(
					'%s is "%s", which is neither side of this relation (parent: "%s", child: "%s"). Attaching it would write a row the relation never reads back.',
					isset( $target['label'] ) ? $target['label'] : 'That object',
					$mine,
					$parent,
					$child
				)
			);
		}

		/**
		 * A target expressed in JetEngine's own "type::name" form.
		 *
		 * @param array $target Resolved target.
		 * @return string
		 */
		private static function jet_object_name( $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$sub  = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';

			switch ( $type ) {
				case 'term':
					return 'terms::' . $sub;
				case 'user':
					return 'users::user';
				default:
					return 'posts::' . $sub;
			}
		}

		/**
		 * The ids currently related to a target through one relation.
		 *
		 * @param object $relation Relation instance.
		 * @param array  $target   Resolved target.
		 * @return array
		 */
		private static function membership( $relation, $target ) {
			$side = self::side_of( $relation, $target );

			if ( is_wp_error( $side ) ) {
				return array(
					'applies' => false,
					'reason'  => $side->get_error_message(),
					'ids'     => array(),
				);
			}

			/*
			 * A relation stored in its OWN table gets that table lazily, on its
			 * first write - JetEngine's Relation::update() opens with
			 * `if ( ! $this->db->is_table_exists() ) $this->db->create_table()`.
			 * So every read that happens before the first attach queries a table
			 * that is not there yet. The answer it produces is correct (no rows,
			 * so no membership), but $wpdb logs "table doesn't exist" against
			 * OUR call stack on the way, which reads in a debug log as UiChemy
			 * being broken on a relation that is merely empty.
			 *
			 * Both entry points hit this: get() reads membership to report it,
			 * and update() reads it as `$before` to diff against. Asking first
			 * costs one memoised SHOW TABLES - is_table_exists() caches, and
			 * SHOW TABLES never errors on a missing table.
			 *
			 * Relations that use the shared default table are unaffected: their
			 * table always exists, so this is false and the read proceeds.
			 */
			if ( isset( $relation->db ) && is_object( $relation->db )
				&& method_exists( $relation->db, 'is_table_exists' )
				&& ! $relation->db->is_table_exists()
			) {
				return array(
					'applies' => true,
					'side'    => $side,
					'ids'     => array(),
					'count'   => 0,
				);
			}

			$ids = 'parent' === $side
				? $relation->get_children( $target['object_id'], 'ids' )
				: $relation->get_parents( $target['object_id'], 'ids' );

			$ids = array_values( array_map( 'intval', (array) $ids ) );

			return array(
				'applies' => true,
				'side'    => $side,
				'ids'     => $ids,
				'count'   => count( $ids ),
			);
		}

		/**
		 * Normalise an id list.
		 *
		 * @param mixed $value Incoming ids.
		 * @return array<int,int>
		 */
		private static function ids( $value ) {
			$out = array();

			foreach ( (array) $value as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$out[] = (int) $id;
				}
			}

			return array_values( array_unique( $out ) );
		}
	}
}
