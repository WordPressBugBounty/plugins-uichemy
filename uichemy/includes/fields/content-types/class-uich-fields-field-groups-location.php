<?php
/**
 * Location rules evaluator for Field Groups.
 * Matches field groups to current screen (post type, taxonomy, etc.).
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Field_Groups_Location
 */
class Uich_Fields_Field_Groups_Location {

	/**
	 * While true, rule_matches() evaluates a Pro param instead of failing it. Only get_pro_locked_groups()
	 * sets it, to ask "would this group show here if Pro were active?".
	 *
	 * @var bool
	 */
	private static $skip_pro_gate = false;

	/**
	 * Check if a field group's location rules match the current screen.
	 *
	 * @param array $location_rules Array of rule groups. Each group is array of rules.
	 *                              Rules match with AND within group; groups match with OR.
	 * @param array $context       Current context: post_type, post_id, taxonomy, term_id, user_id, screen.
	 * @return bool True if any rule group matches.
	 */
	public static function match( $location_rules, $context = array() ) {
		if ( empty( $location_rules ) || ! is_array( $location_rules ) ) {
			return false;
		}

		$first       = isset( $location_rules[0] ) ? $location_rules[0] : null;
		$is_nested   = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
		$rule_groups = $is_nested ? $location_rules : array( $location_rules );

		foreach ( $rule_groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group_matches = true;
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				if ( ! self::rule_matches( $rule, $context ) ) {
					$group_matches = false;
					break;
				}
			}
			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a single rule matches the context.
	 *
	 * @param array $rule    Rule: param, operator, value.
	 * @param array $context Context array.
	 * @return bool
	 */
	private static function rule_matches( $rule, $context ) {

		$param    = isset( $rule['param'] ) ? sanitize_key( $rule['param'] ) : '';
		$operator = self::sanitize_rule_operator( isset( $rule['operator'] ) ? $rule['operator'] : '' );
		$value    = isset( $rule['value'] ) ? $rule['value'] : '';

		// A Pro param on a Free build does not match, so the group simply does not attach. The rule
		// is NOT rewritten or removed — a licence that comes back makes the group reappear as it was.
		// Returning false (rather than true) because a rule that cannot be evaluated must not widen
		// where a group shows: an AND group would otherwise leak onto every screen it excluded.
		if ( ! self::$skip_pro_gate && '' !== $param && function_exists( 'uich_fields_location_param_allowed' ) && ! uich_fields_location_param_allowed( $param ) ) {
			return false;
		}

		$actual = self::get_param_value( $param, $context );

		// "All" means match any (for several params) — but only where the param exists at all.
		if ( (string) 'all' === $value || (string) '' === $value ) {
			if ( in_array( $param, array( 'taxonomy', 'post_taxonomy', 'comment', 'attachment', 'nav_menu', 'nav_menu_item', 'widget', 'user_form' ), true ) ) {
				$has_context = is_array( $actual ) ? ! empty( $actual ) : ( '' !== (string) $actual );
				if ( '==' === $operator || '=' === $operator ) {
					return $has_context;
				}
				if ( '!=' === $operator ) {
					return ! $has_context;
				}
			}
		}
		// Array actual (e.g. post_category terms): "equal" means value is in list.
		if ( is_array( $actual ) ) {
			if ( in_array( '==', array( $operator ), true ) || in_array( '=', array( $operator ), true ) ) {
				return in_array( (string) $value, array_map( 'strval', $actual ), true );
			}
			if ( '!=' === $operator ) {
				return ! in_array( (string) $value, array_map( 'strval', $actual ), true );
			}
			return false;
		}

		// Special handling for user_form "add_edit" convenience value.
		if ( 'user_form' === $param && 'add_edit' === (string) $value ) {
			if ( '==' === $operator || '=' === $operator ) {
				return in_array( (string) $actual, array( 'add', 'edit' ), true );
			}
			if ( '!=' === $operator ) {
				return ! in_array( (string) $actual, array( 'add', 'edit' ), true );
			}
		}

		switch ( $operator ) {
			case '==':
				return self::rule_values_equal( $actual, $value );
			case '!=':
				return ! self::rule_values_equal( $actual, $value );
			case '>':
				return $actual > $value;
			case '<':
				return $actual < $value;
			case 'contains':
				return is_string( $actual ) && is_string( $value ) && strpos( $actual, $value ) !== false;
			case 'empty':
				return empty( $actual );
			case '!empty':
				return ! empty( $actual );
			default:
				return self::rule_values_equal( $actual, $value );
		}
	}

	/**
	 * Type-safe equality for rule matching.
	 *
	 * @param mixed $actual Actual value.
	 * @param mixed $value  Rule value.
	 * @return bool
	 */
	public static function rule_values_equal( $actual, $value ) {
		if ( is_scalar( $actual ) && is_scalar( $value ) ) {
			return (string) $actual === (string) $value;
		}
		return $actual === $value;
	}

	/**
	 * The operators a rule may carry. Both matchers share this list.
	 *
	 * @return string[]
	 */
	private static function rule_operators() {
		return array( '==', '=', '!=', '>', '<', 'contains', 'empty', '!empty', 'all_set', 'range' );
	}

	/**
	 * Reduce a stored operator to one of the known operators.
	 *
	 * @param mixed $operator Raw stored operator.
	 * @return string One of rule_operators(), or '==' when unrecognised.
	 */
	public static function sanitize_rule_operator( $operator ) {
		if ( ! is_string( $operator ) || '' === $operator ) {
			return '==';
		}
		if ( in_array( $operator, self::rule_operators(), true ) ) {
			return $operator;
		}
		// Tolerate a value that has already been entity-encoded on its way through storage or an
		// older save, so a rule written by the previous code keeps working.
		$decoded = html_entity_decode( $operator, ENT_QUOTES, 'UTF-8' );

		return in_array( $decoded, self::rule_operators(), true ) ? $decoded : '==';
	}


	/**
	 * Evaluate per-field conditional logic rules against current field values.
	 * Rules structure mirrors location_rules but uses 'field', 'operator', 'value'.
	 *
	 * @param array $conditional_logic Array of groups of rules.
	 * @param array $values            Map of field_name => current value.
	 * @return bool True if conditions pass or no conditions defined.
	 */
	public static function match_field_conditional( $conditional_logic, $values ) {
		/*
		 * Conditional logic is a Pro feature. On Free the rules are IGNORED and everything passes —
		 * deliberately not "evaluate to false". A failing condition makes
		 * uich_field_conditional_passes() reject the field, which makes uich_field_value() return
		 * null, so evaluating false here would make every conditionally-shown field's stored value
		 * read as empty on the front end of a downgraded site. Showing a field that Pro would have
		 * hidden is a cosmetic difference; hiding content that is really there is data loss.
		 *
		 * The stored rules are untouched, so re-activating Pro restores the exact behaviour.
		 */
		if ( function_exists( 'uich_fields_conditional_logic_allowed' ) && ! uich_fields_conditional_logic_allowed() ) {
			return true;
		}

		if ( empty( $conditional_logic ) || ! is_array( $conditional_logic ) ) {
			return true;
		}

		/*
		 * The evaluation itself lives in UiChemy Pro (uichemy-pro/includes/fields/). This plugin owns
		 * the two primitives both rule systems share — sanitize_rule_operator() and
		 * rule_values_equal() — and nothing else about conditional logic.
		 *
		 * A null answer means no evaluator is loaded. Returning TRUE there is the same "ignore the
		 * rules" answer Free gives above, and for the same reason: a build whose licence says Pro but
		 * which is missing the Pro plugin must not start hiding content that is really stored.
		 */
		$result = apply_filters( 'uich_fields_evaluate_conditional', null, $conditional_logic, $values );

		return ( null === $result ) ? true : (bool) $result;
	}

	/**
	 * Get the current value for a location param.
	 *
	 * @param string $param   Param key (e.g. post_type, taxonomy).
	 * @param array  $context Context array.
	 * @return mixed
	 */
	private static function get_param_value( $param, $context ) {
		$post_type = isset( $context['post_type'] ) ? $context['post_type'] : '';
		$post_id   = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$post      = isset( $context['post'] ) ? $context['post'] : null;
		$screen    = isset( $context['screen'] ) ? $context['screen'] : '';

		if ( empty( $post_type ) && function_exists( 'get_current_screen' ) ) {
			$current = get_current_screen();
			if ( $current && ! empty( $current->post_type ) ) {
				$post_type = $current->post_type;
			}
		}

		/*
		 * Free owns the five Free location params and nothing else. The other sixteen are resolved by
		 * whatever answers `uich_fields_location_param_value` — UiChemy Pro registers a resolver for
		 * them from its own directory (uichemy-pro/includes/fields/).
		 *
		 * The resolver runs BEFORE this switch so a listener can also override a Free param if it
		 * ever needs to; returning null means "not mine" and hands the param back here.
		 */
		$resolved = apply_filters(
			'uich_fields_location_param_value',
			null,
			$param,
			$context,
			array(
				'post_type' => $post_type,
				'post_id'   => $post_id,
				'post'      => $post,
				'screen'    => $screen,
			)
		);
		if ( null !== $resolved ) {
			return $resolved;
		}

		switch ( $param ) {
			case 'post_type':
				// Term / user / comment / option screens carry no post object, so defaulting to 'post' leaked post_type rules there.
				if ( isset( $context['screen'] ) && in_array( $context['screen'], array( 'term', 'user', 'comment', 'option' ), true ) ) {
					return '';
				}
				return $post_type ? $post_type : 'post';

			case 'page_template':
				// `post_template` is the Pro twin of this one and lives with the resolver; both read
				// the same two context keys, so a site with Pro absent still resolves page_template.
				return isset( $context['page_template'] ) ? $context['page_template'] : ( isset( $context['post_template'] ) ? $context['post_template'] : 'default' );

			case 'taxonomy':
				// Term add/edit screens only.
				if ( isset( $context['screen'] ) && 'term' === $context['screen'] ) {
					return isset( $context['taxonomy'] ) ? $context['taxonomy'] : '';
				}
				return '';

			case 'user_form':
				return isset( $context['user_form'] ) ? $context['user_form'] : '';

			case 'comment':
				// Comment edit screens only; the rule value filters by the commented post's type (All, Post, Page, …).
				if ( ! isset( $context['screen'] ) || 'comment' !== $context['screen'] ) {
					return '';
				}
				$comment_id = isset( $context['comment_id'] ) ? (int) $context['comment_id'] : 0;
				if ( $comment_id ) {
					$comment = get_comment( $comment_id );
					$cpost   = $comment ? get_post( (int) $comment->comment_post_ID ) : null;
					if ( $cpost ) {
						return $cpost->post_type;
					}
				}
				return $post_type ? $post_type : '';

			default:
				/*
				 * A param nobody resolved. Falling back to the raw context value is deliberate: half
				 * the Pro params are plain context passthroughs (post_status, page_type, page_parent,
				 * user_role, current_user, post_template), and a build whose licence says Pro but
				 * which is missing the Pro plugin should degrade to "those still work" rather than
				 * silently changing which groups attach. The ones carrying real logic —
				 * post_category, post_taxonomy, attachment, current_user_role, post, page — resolve
				 * to nothing here, so their rules stop matching, which narrows and never leaks.
				 */
				return isset( $context[ $param ] ) ? $context[ $param ] : '';
		}
	}

	/**
	 * Get field groups that match the current admin screen.
	 *
	 * @param array $context Optional context override.
	 * @return array Array of field group configs (id, title, fields, etc.).
	 */
	public static function get_matching_groups( $context = array() ) {
		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();

		$matched = array();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$rules = isset( $meta['location_rules'] ) ? $meta['location_rules'] : array();
			if ( self::match( $rules, $context ) ) {
				$matched[] = array(
					'id'                    => $row['id'],
					'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : $row['title'],
					'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $row['id'],
					'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
					'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
					'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
					'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
					'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
					'order_no'              => isset( $meta['order_no'] ) ? (int) $meta['order_no'] : 0,
					// Carried so uich_register_group_field_meta() can honour the group's REST opt-out and resolve which
					// object types to register its value meta for.
					'show_in_rest'          => ! empty( $meta['show_in_rest'] ),
					'location_rules'        => $rules,
				);
			}
		}

		if ( ! empty( $matched ) ) {
			usort(
				$matched,
				function ( $a, $b ) {
					// order_no is always set by the array built above, so no fallback is reachable here.
					$ao = (int) $a['order_no'];
					$bo = (int) $b['order_no'];
					if ( $ao === $bo ) {
						return strcasecmp( (string) $a['title'], (string) $b['title'] );
					}
					return ( $ao < $bo ) ? -1 : 1;
				}
			);
		}

		return $matched;
	}

	/**
	 * Active groups this build hides on the given screen ONLY because a rule uses a Pro param.
	 *
	 * Lets the editor say a group exists instead of leaving it silently missing. Always empty on Pro,
	 * where no param is gated. A group that would not match even with Pro is not listed.
	 *
	 * @param array $context Same context get_matching_groups() takes.
	 * @return array<int,array{id:int,title:string}>
	 */
	public static function get_pro_locked_groups( $context = array() ) {
		if ( ! function_exists( 'uich_fields_location_param_allowed' ) ) {
			return array();
		}
		$locked = array();
		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$rules = isset( $meta['location_rules'] ) ? $meta['location_rules'] : array();
			if ( empty( $rules ) || ! is_array( $rules ) || self::match( $rules, $context ) ) {
				continue;
			}
			self::$skip_pro_gate = true;
			try {
				$would_match = self::match( $rules, $context );
			} finally {
				self::$skip_pro_gate = false;
			}
			if ( $would_match ) {
				$locked[] = array(
					'id'    => (int) $row['id'],
					'title' => ! empty( $meta['title'] ) ? (string) $meta['title'] : (string) $row['title'],
				);
			}
		}
		return $locked;
	}

	/**
	 * Rule params grouped by the object type whose edit screen they can appear on.
	 *
	 * @return array<string, string[]>
	 */
	private static function params_by_object_type() {
		return array(
			// 'attachment' is here because an attachment is a post type.
			'post'    => array(
				'post_type',
				'post',
				'page',
				'post_template',
				'page_template',
				'post_status',
				'post_format',
				'post_category',
				'post_taxonomy',
				'page_type',
				'page_parent',
				'attachment',
			),
			'term'    => array( 'taxonomy' ),
			'user'    => array( 'user_role', 'user_form' ),
			'comment' => array( 'comment' ),
		);
	}

	/**
	 * Whether ANY active group could render on the edit screen of $object_type.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @return bool
	 */
	public static function targets_object_type( $object_type ) {
		$map = self::params_by_object_type();
		if ( ! isset( $map[ $object_type ] ) ) {
			return true;
		}
		$mine       = $map[ $object_type ];
		$everywhere = array( 'current_user', 'current_user_role' );
		$known      = array_merge( $everywhere, ...array_values( $map ) );

		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$rules = isset( $meta['location_rules'] ) ? $meta['location_rules'] : array();
			if ( empty( $rules ) || ! is_array( $rules ) ) {
				// match() returns false for empty rules, so such a group never renders anywhere.
				continue;
			}

			$first       = isset( $rules[0] ) ? $rules[0] : null;
			$is_nested   = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
			$rule_groups = $is_nested ? $rules : array( $rules );

			foreach ( $rule_groups as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
						continue;
					}
					$param = sanitize_key( $rule['param'] );
					if ( in_array( $param, $everywhere, true ) ) {
						return true;
					}
					if ( in_array( $param, $mine, true ) ) {
						return true;
					}
					if ( ! in_array( $param, $known, true ) ) {
						// An unrecognised param (a newer rule type, or one added by a filter) must
						// not silently suppress assets.
						return true;
					}
				}
			}
		}

		return false;
	}
}
