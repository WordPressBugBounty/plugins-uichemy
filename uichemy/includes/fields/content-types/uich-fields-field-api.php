<?php
/**
 * Uichemy Field API – template functions for getting/setting field values.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse object_id into type and id. Supports: post (int), user_123, term_45, option, comment_12.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, comment_3, or false for current post.
 * @return array{type: string, id: int|string} ['type' => 'post'|'user'|'term'|'option'|'comment', 'id' => ...]
 */
function uich_get_valid_object_id( $object_id = false ) {
	$type = 'post';
	$id   = 0;

	if ( false === $object_id || null === $object_id ) {
		$id = get_the_ID();
		if ( ! $id ) {
			return array(
				'type' => 'post',
				'id'   => 0,
			);
		}
		return array(
			'type' => 'post',
			'id'   => (int) $id,
		);
	}

	if ( is_numeric( $object_id ) ) {
		return array(
			'type' => 'post',
			'id'   => (int) $object_id,
		);
	}

	$str = (string) $object_id;
	if ( preg_match( '/^user_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'user',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '/^term_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'term',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '/^comment_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'comment',
			'id'   => (int) $m[1],
		);
	}
	if ( 'option' === $str || 'options' === $str ) {
		return array(
			'type' => 'option',
			'id'   => 'option',
		);
	}

	return array(
		'type' => 'post',
		'id'   => is_numeric( $str ) ? (int) $str : 0,
	);
}

/**
 * Get meta key prefix for object type.
 *
 * User values are always blog-scoped (`_uichemy_gf_user_{blog_id}_`), on single site too: wp_usermeta
 * is network-wide, so an unscoped key would make a user field on site A readable and writable on
 * site B, and scoping single site the same way means a later conversion to multisite needs no move.
 *
 * @param string $object_type One of: post, user, term, option, comment.
 * @return string Prefix e.g. _uichemy_gf_, _uichemy_gf_term_, _uichemy_gf_user_1_, uich_gf_option_, _uichemy_gf_comment_
 */
function uich_get_meta_key_prefix( $object_type ) {
	switch ( $object_type ) {
		case 'user':
			return '_uichemy_gf_user_' . absint( get_current_blog_id() ) . '_';
		case 'term':
			return '_uichemy_gf_term_';
		case 'option':
			return 'uich_gf_option_';
		case 'comment':
			return '_uichemy_gf_comment_';
		default:
			return '_uichemy_gf_';
	}
}

/**
 * Separator between the group key and the field name inside a value meta key.
 *
 * A double underscore, never a single one: with `_` the keys for group `a` + field `b_c` and group
 * `a_b` + field `c` were both `…a_b_c`, so two groups silently shared one value. Group keys
 * (Uich_Fields_Model_Paths::validate_key()) and field names (uich_normalize_field_name()) may not
 * contain `__` nor start or end with `_`, which makes the first `__` in a key an unambiguous split.
 */
if ( ! defined( 'UICH_FIELDS_META_KEY_SEPARATOR' ) ) {
	define( 'UICH_FIELDS_META_KEY_SEPARATOR', '__' );
}

/**
 * Normalise a field name into the form the meta key is built from.
 *
 * sanitize_key(), then runs of underscores collapsed to one and underscores trimmed from both ends,
 * so a name can never contain the group/field separator.
 *
 * @param string $name Raw field name.
 * @return string '' when nothing usable remains.
 */
function uich_normalize_field_name( $name ) {
	$name = sanitize_key( (string) $name );
	$name = preg_replace( '/_{2,}/', '_', $name );
	return trim( (string) $name, '_' );
}

/**
 * Canonical meta-key builder for a group field value, used by BOTH the read and write paths so they can
 * never diverge.
 *
 * Format: `{prefix}{group_key}__{field_name}` (see UICH_FIELDS_META_KEY_SEPARATOR).
 *
 * @param string $object_type post, user, term, option, comment.
 * @param string $group_key   Field group key.
 * @param string $field_name  Field name.
 * @return string Full meta key (or option name for the 'option' type).
 */
function uich_build_meta_key( $object_type, $group_key, $field_name ) {
	return uich_get_meta_key_prefix( $object_type ) . uich_meta_key_group_prefix( $group_key ) . uich_normalize_field_name( $field_name );
}

/**
 * The part of a value meta key that every field of one group shares: `{group_key}__`.
 *
 * Append it to uich_get_meta_key_prefix() for a LIKE match over one group's values.
 *
 * @param string $group_key Field group key.
 * @return string
 */
function uich_meta_key_group_prefix( $group_key ) {
	return sanitize_key( (string) $group_key ) . UICH_FIELDS_META_KEY_SEPARATOR;
}

/**
 * Whether a value row exists for this key — the test that decides if default_value applies.
 *
 * @param string $meta_key    Full meta key.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @return bool
 */
function uich_stored_value_exists( $meta_key, $object_type, $object_id ) {
	if ( 'option' === $object_type ) {
		$sentinel = '__uich_gf_option_not_set__';
		return get_option( $meta_key, $sentinel ) !== $sentinel;
	}
	if ( ! $object_id ) {
		return false;
	}
	return metadata_exists( $object_type, (int) $object_id, $meta_key );
}

/**
 * Get all field groups (optionally filtered by context). For option type, returns all group_fields.
 *
 * @param array $context Optional. Context for location matching. If type is option, all groups returned.
 * @return array List of group configs (id, key, title, fields, ...).
 */
function uich_get_all_groups_for_context( $context = array() ) {
	if ( ! class_exists( 'Uich_Fields_CPT' ) || ! class_exists( 'Uich_Fields_Field_Groups_Location' ) ) {
		return array();
	}

	$screen = isset( $context['screen'] ) ? $context['screen'] : '';
	if ( 'option' === $screen ) {
		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
		$out    = array();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$out[] = array(
				'id'                    => $row['id'],
				'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : $row['title'],
				'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $row['id'],
				'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
				'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
				'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
				'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
				'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
				'order_no'              => isset( $meta['order_no'] ) ? (int) $meta['order_no'] : 0,
				// Needed by uich_register_group_field_meta(): show_in_rest is the group's REST opt-in (off
				// unless set), and location_rules decide which object types its value meta is registered for.
				'show_in_rest'          => ! empty( $meta['show_in_rest'] ),
				'location_rules'        => isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				// order_no is always set by the array built above, so no fallback is reachable here.
				$ao = (int) $a['order_no'];
				$bo = (int) $b['order_no'];
				return $ao <=> $bo;
			}
		);
		return $out;
	}

	return Uich_Fields_Field_Groups_Location::get_matching_groups( $context );
}

/**
 * Build context array from object type and id (for get_matching_groups).
 *
 * @param string     $object_type post, user, term, option, comment.
 * @param int|string $object_id   ID or 'option'.
 * @param array      $overrides   Screen-specific values merged last (e.g. user_form on the add-user
 *                                screen, where there is no object to read it from).
 * @return array Context for location matching.
 */
function uich_build_context_for_object( $object_type, $object_id, $overrides = array() ) {
	$context = array();

	switch ( $object_type ) {
		case 'post':
			$context['post_id']   = (int) $object_id;
			$context['post_type'] = 'post';
			if ( $object_id ) {
				$post = get_post( $object_id );
				if ( $post ) {
					$context['post']          = $post;
					$context['post_type']     = $post->post_type;
					$context['post_status']   = $post->post_status;
					$template                 = get_post_meta( $object_id, '_wp_page_template', true );
					$resolved_template        = ( $template && 'default' !== $template ) ? $template : 'default';
					$context['post_template'] = $resolved_template;
					$context['page_template'] = $resolved_template;
					if ( 'page' === $post->post_type ) {
						if ( (int) get_option( 'page_on_front' ) === (int) $object_id ) {
							$context['page_type'] = 'front_page';
						} elseif ( (int) get_option( 'page_for_posts' ) === (int) $object_id ) {
							$context['page_type'] = 'posts_page';
						} elseif ( $post->post_parent > 0 ) {
								$context['page_type'] = 'child';
						} else {
							// A top-level page that has children is 'parent', not 'top_level'.
							$children             = get_children(
								array(
									'post_parent' => $object_id,
									'post_type'   => 'page',
									'numberposts' => 1,
								)
							);
							$context['page_type'] = ! empty( $children ) ? 'parent' : 'top_level';
						}
						$context['page_parent'] = (string) $post->post_parent;
					}
					if ( post_type_supports( $post->post_type, 'post-formats' ) ) {
						$format                 = get_post_format( $object_id );
						$context['post_format'] = $format ? $format : 'standard';
					}
				}
			}
			break;
		case 'user':
			$context['screen']    = 'user';
			$context['user_id']   = (int) $object_id;
			$context['user_form'] = 'edit';
			if ( $object_id ) {
				$user = get_user_by( 'id', $object_id );
				if ( $user && ! empty( $user->roles ) ) {
					$context['user_role'] = $user->roles[0];
				}
			}
			break;
		case 'term':
			$context['screen']  = 'term';
			$context['term_id'] = (int) $object_id;
			if ( $object_id ) {
				$term = get_term( $object_id );
				if ( $term && ! is_wp_error( $term ) ) {
					$context['taxonomy'] = $term->taxonomy;
				}
			}
			break;
		case 'option':
			$context['screen'] = 'option';
			break;
		case 'comment':
			$context['screen']     = 'comment';
			$context['comment_id'] = (int) $object_id;
			break;
		default:
			$context['post_id']   = is_numeric( $object_id ) ? (int) $object_id : 0;
			$context['post_type'] = 'post';
	}

	// Viewer keys, on every object type: they describe who is looking, not what is looked at.
	// user_role stays whatever the object branch set (the edited user's role); the viewer's is current_user_role.
	$viewer    = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$viewer_id = $viewer ? (int) $viewer->ID : 0;

	$context['current_user_id']   = $viewer_id;
	$context['current_user_role'] = ( $viewer && ! empty( $viewer->roles ) ) ? (string) $viewer->roles[0] : '';
	// On user screens user_id is the user being edited; everywhere else there is no object user, so
	// the viewer is the only sensible answer.
	if ( ! isset( $context['user_id'] ) ) {
		$context['user_id'] = $viewer_id;
	}
	// user_role is deliberately NOT defaulted to the viewer's role.

	// current_user is a list, not one value: an admin in wp-admin is both logged_in and viewing_back at once.
	// rule_matches() treats an array actual as "value is in the list".
	$current_user = array();
	if ( $viewer_id ) {
		$current_user[] = 'logged_in';
	}
	$current_user[]          = is_admin() ? 'viewing_back' : 'viewing_front';
	$context['current_user'] = $current_user;

	if ( ! empty( $overrides ) && is_array( $overrides ) ) {
		$context = array_merge( $context, $overrides );
	}

	return $context;
}

/**
 * Find field config and group_key by selector (field name) for a given object. First matching group/field wins.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, etc.
 * @return array|null ['field' => array, 'group_key' => string, 'object_type' => string, 'object_id' => mixed] or null.
 */
function uich_maybe_get_field_info( $selector, $object_id = false ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return null;
	}

	$obj         = uich_get_valid_object_id( $object_id );
	$object_type = $obj['type'];
	$object_id   = $obj['id'];

	$context = uich_build_context_for_object( $object_type, $object_id );

	// 1. Location-matched groups first — this governs which groups render in the editor and
	// whether an unsaved field falls back to its default_value ('matched' => true).
	$matched = uich_get_all_groups_for_context( $context );
	$info    = _uich_find_field_in_groups( $selector, $matched, $object_type, $object_id, true );
	if ( $info ) {
		return $info;
	}

	// 2. Fallback: a value may be stored even though the object no longer matches the group's location rules.
	$all  = uich_get_all_groups_for_context( array( 'screen' => 'option' ) );
	$info = _uich_find_field_in_groups( $selector, $all, $object_type, $object_id, false );
	if ( $info ) {
		return $info;
	}

	return null;
}

/**
 * Locate a field by selector within a list of groups, returning the resolution info (or null).
 *
 * @param string $selector    Field name or key.
 * @param array  $groups      Group configs.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   Object ID.
 * @param bool   $matched     Whether these groups matched the object's location context.
 * @return array|null
 */
function _uich_find_field_in_groups( $selector, $groups, $object_type, $object_id, $matched ) {
	foreach ( (array) $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		$field     = uich_find_top_level_field_by_selector( $selector, $fields );
		if ( $field ) {
			return array(
				'field'        => $field,
				'group_key'    => $group_key,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'matched'      => (bool) $matched,
				// Siblings of $field within the same group, needed to evaluate $field's own
				// conditional_logic (see uich_field_conditional_passes()) without a second lookup.
				'group_fields' => $fields,
			);
		}
	}
	return null;
}

/**
 * Whether a resolved field's own conditional-logic rules currently pass, given its siblings' stored values.
 *
 * @param array $info Result of uich_maybe_get_field_info()/_uich_find_field_in_groups().
 * @return bool
 */
function uich_field_conditional_passes( $info ) {
	if ( ! is_array( $info ) || ! is_array( $info['field'] ?? null ) ) {
		return true;
	}
	$field = $info['field'];
	if ( empty( $field['conditional_logic_enabled'] ) || empty( $field['conditional_logic'] )
		|| ! class_exists( 'Uich_Fields_Field_Groups_Location' )
	) {
		return true;
	}
	// The matcher already passes everything on Free; short-circuiting here skips the sibling-value
	// lookup below, which is one stored read PER sibling field and would be done for an answer that
	// is fixed.
	if ( function_exists( 'uich_fields_conditional_logic_allowed' ) && ! uich_fields_conditional_logic_allowed() ) {
		return true;
	}
	$sibling_values = uich_get_sibling_field_values(
		isset( $info['group_fields'] ) ? $info['group_fields'] : array(),
		$info['group_key'],
		$info['object_type'],
		$info['object_id']
	);
	return Uich_Fields_Field_Groups_Location::match_field_conditional( $field['conditional_logic'], $sibling_values );
}

/**
 * Raw values of a group's top-level fields, keyed by field name.
 * This is the shape match_field_conditional() expects.
 *
 * @param array      $fields      Top-level fields of the group the field being resolved belongs to.
 * @param string     $group_key   Field group key.
 * @param string     $object_type post, user, term, option, comment.
 * @param int|string $object_id   Object ID.
 * @return array<string, mixed>
 */
function uich_get_sibling_field_values( $fields, $group_key, $object_type, $object_id ) {
	$values = array();
	$types  = array();
	foreach ( (array) $fields as $sibling ) {
		if ( ! is_array( $sibling ) || empty( $sibling['name'] ) ) {
			continue;
		}
		$name            = (string) $sibling['name'];
		$meta_key        = uich_build_meta_key( $object_type, $group_key, $name );
		$values[ $name ] = uich_get_stored_value( $meta_key, $object_type, $object_id, '' );
		$types[ $name ]  = isset( $sibling['type'] ) ? (string) $sibling['type'] : '';
	}
	// Under a key no field name can take (names never start with '_'): lets the Pro evaluator read a
	// Number/Range 0 as a value rather than empty.
	$values['__types'] = $types;
	return $values;
}

/**
 * Whether a field config matches a template selector (name or field key).
 *
 * @param array  $field    Field config.
 * @param string $selector Selector string.
 * @return bool
 */
function uich_field_matches_selector( $field, $selector ) {
	if ( ! is_array( $field ) || '' === $selector ) {
		return false;
	}
	$name = isset( $field['name'] ) ? (string) $field['name'] : '';
	if ( $name === $selector ) {
		return true;
	}
	$key = isset( $field['key'] ) ? (string) $field['key'] : '';
	if ( '' !== $key && $key === $selector ) {
		return true;
	}
	// Names are stored normalised (uich_normalize_field_name()), so a selector written the way the
	// source plugin spelled it ("Job-Title" for an imported `job-title`) still resolves.
	return '' !== $name && is_string( $selector ) && uich_normalize_field_name( $selector ) === $name;
}

/**
 * Find a top-level group field only (no recursion into repeater subfields).
 * Subfield values live inside repeater row arrays, not in separate meta keys.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Top-level fields from a group.
 * @return array|null
 */
function uich_find_top_level_field_by_selector( $selector, $fields ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return null;
	}
	foreach ( $fields as $f ) {
		if ( is_array( $f ) && uich_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
	}
	return null;
}

/**
 * Find a subfield definition inside a repeater (or container) field's `fields` list.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Subfields array.
 * @return array|null
 */
function uich_find_subfield_by_selector( $selector, $fields ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector || ! is_array( $fields ) ) {
		return null;
	}
	foreach ( $fields as $f ) {
		if ( is_array( $f ) && uich_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
	}
	return null;
}

/**
 * Find nested repeater subfield definition.
 *
 * @param array  $parent_repeater Parent repeater field config.
 * @param string $selector        Sub repeater name or key.
 * @return array|null
 */
function uich_find_subfield_repeater( $parent_repeater, $selector ) {
	if ( ! is_array( $parent_repeater ) || ( $parent_repeater['type'] ?? '' ) !== 'repeater' ) {
		return null;
	}
	$sub = uich_find_subfield_by_selector( $selector, $parent_repeater['fields'] ?? array() );
	if ( $sub && ( $sub['type'] ?? '' ) === 'repeater' ) {
		return $sub;
	}
	return null;
}

/**
 * Find field by name or key in flat or nested field list (deep search).
 * Prefer top-level helpers for meta resolution; this remains for legacy/internal use.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Array of field configs.
 * @return array|null Field config or null.
 */
function uich_find_field_in_list( $selector, $fields ) {
	foreach ( $fields as $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		if ( uich_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
		if ( ! empty( $f['fields'] ) && is_array( $f['fields'] ) ) {
			$sub = uich_find_field_in_list( $selector, $f['fields'] );
			if ( $sub ) {
				return $sub;
			}
		}
	}
	return null;
}

/**
 * Reference stack for uich_repeater_has_next() / uich_repeater_advance() — cursor-based row loops.
 *
 * @return array<int, array<string, mixed>>
 */
function &uich_repeater_loop_stack() {
	static $stack = array();
	return $stack;
}

/**
 * Whether a stack frame belongs to the given selector (name or key).
 *
 * @param array  $frame    Stack frame.
 * @param string $selector Selector the caller opened the loop with.
 * @return bool
 */
function uich_repeater_frame_matches_selector( $frame, $selector ) {
	if ( ! is_array( $frame ) || ! isset( $frame['field'] ) ) {
		return false;
	}
	if ( isset( $frame['selector'] ) && (string) $frame['selector'] === (string) $selector ) {
		return true;
	}
	return uich_field_matches_selector( $frame['field'], $selector );
}

/**
 * Format one repeater row using subfield type rules.
 *
 * @param array $row              Raw row (name => value).
 * @param array $repeater_field   Repeater field config.
 * @return array
 */
function uich_format_repeater_row_values( $row, $repeater_field ) {
	if ( ! is_array( $row ) || ! is_array( $repeater_field ) ) {
		return is_array( $row ) ? $row : array();
	}
	$subs = isset( $repeater_field['fields'] ) && is_array( $repeater_field['fields'] ) ? $repeater_field['fields'] : array();
	$map  = array();
	foreach ( $subs as $sf ) {
		if ( is_array( $sf ) && ! empty( $sf['name'] ) ) {
			$map[ $sf['name'] ] = $sf;
		}
	}
	$out = array();
	foreach ( $row as $k => $v ) {
		$out[ $k ] = isset( $map[ $k ] ) ? uich_format_field_value( $v, $map[ $k ] ) : $v;
	}
	return $out;
}

/**
 * Every row of a repeater as a plain array, formatted, with nested repeaters resolved in place.
 *
 * The cursor API below keeps loop state in a process-global stack, because a template loop has no
 * handle to hang state on. This one has no state at all: it returns the rows, so a template does
 * `foreach ( uich_repeater_rows( 'slides' ) as $row )` and nested repeaters are already arrays inside
 * each row — no inner loop, nothing to rewind, and two loops over the same repeater cannot interfere.
 * Prefer it. The cursor functions stay for callers that stream very large repeaters.
 *
 * @param string $selector  Repeater field name or key.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false for the current post.
 * @param bool   $format    Whether to run each subfield through its type's formatter.
 * @return array<int, array<string, mixed>> Empty when the selector is not a repeater.
 */
function uich_repeater_rows( $selector, $object_id = false, $format = true ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return array();
	}

	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info || ( $info['field']['type'] ?? '' ) !== 'repeater' ) {
		return array();
	}

	$rows = uich_field_value( $selector, $object_id, false );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$rows = array_values( $rows );

	if ( ! $format ) {
		return $rows;
	}

	$out = array();
	foreach ( $rows as $row ) {
		$out[] = is_array( $row ) ? uich_format_repeater_row_deep( $row, $info['field'] ) : $row;
	}
	return $out;
}

/**
 * Format one row, recursing into subfields that are themselves repeaters.
 *
 * uich_format_field_value() has no repeater case, so a nested repeater falls through it unformatted —
 * an image subfield two levels down would come back as a bare attachment ID.
 *
 * @param array $row            Raw row (name => value).
 * @param array $repeater_field Repeater field config.
 * @return array
 */
function uich_format_repeater_row_deep( $row, $repeater_field ) {
	$subs = ( is_array( $repeater_field ) && isset( $repeater_field['fields'] ) && is_array( $repeater_field['fields'] ) )
		? $repeater_field['fields']
		: array();

	$map = array();
	foreach ( $subs as $sf ) {
		if ( is_array( $sf ) && ! empty( $sf['name'] ) ) {
			$map[ $sf['name'] ] = $sf;
		}
	}

	$out = array();
	foreach ( $row as $k => $v ) {
		if ( ! isset( $map[ $k ] ) ) {
			$out[ $k ] = $v;
			continue;
		}
		if ( ( $map[ $k ]['type'] ?? '' ) === 'repeater' && is_array( $v ) ) {
			$inner = array();
			foreach ( array_values( $v ) as $inner_row ) {
				$inner[] = is_array( $inner_row ) ? uich_format_repeater_row_deep( $inner_row, $map[ $k ] ) : $inner_row;
			}
			$out[ $k ] = $inner;
			continue;
		}
		$out[ $k ] = uich_format_field_value( $v, $map[ $k ] );
	}
	return $out;
}

/**
 * Checks if a repeater (or nested repeater) has rows to loop over. Use with uich_repeater_advance().
 * Supports nested loops, recovering the outer frame from the stack when an inner loop ends.
 *
 * @param string $selector Top-level or nested repeater field name or key.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false for current post (parent loop only).
 * @return bool
 */
function uich_repeater_has_next( $selector, $object_id = false ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return false;
	}
	$stack = &uich_repeater_loop_stack();
	while ( true ) {
		if ( empty( $stack ) ) {
			break;
		}
		$top = &$stack[ count( $stack ) - 1 ];
		if ( uich_repeater_frame_matches_selector( $top, $selector ) ) {
			$n = count( $top['rows'] );
			if ( ( $top['i'] + 1 ) < $n ) {
				return true;
			}

			// Rows exhausted: pop the frame before returning false, or a stale frame still matches
			// the selector and looping the same repeater twice in one request yields nothing.
			array_pop( $stack );
			return false;
		}
		if ( $top['i'] >= 0 ) {
			$row = isset( $top['rows'][ $top['i'] ] ) ? $top['rows'][ $top['i'] ] : null;
			$sub = uich_find_subfield_repeater( $top['field'], $selector );
			if ( $sub && is_array( $row ) ) {
				$subname = $sub['name'];
				$rows    = isset( $row[ $subname ] ) && is_array( $row[ $subname ] ) ? $row[ $subname ] : array();
				$stack[] = array(
					'selector'  => $selector,
					'field'     => $sub,
					'rows'      => array_values( $rows ),
					'i'         => -1,
					'object_id' => $object_id,
				);
				return count( $rows ) > 0;
			}
		}
		$n         = count( $top['rows'] );
		$exhausted = ( $top['i'] + 1 ) >= $n;
		if ( count( $stack ) > 1 || $exhausted ) {
			array_pop( $stack );
			continue;
		}
		return false;
	}
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info || ( $info['field']['type'] ?? '' ) !== 'repeater' ) {
		return false;
	}
	$rows = uich_field_value( $selector, $object_id, false );
	if ( ! is_array( $rows ) ) {
		$rows = array();
	}
	$stack[] = array(
		'selector'  => $selector,
		'field'     => $info['field'],
		'rows'      => array_values( $rows ),
		'i'         => -1,
		'object_id' => $object_id,
	);
	return count( $rows ) > 0;
}

/**
 * Advance the current repeater loop (innermost). Call after uich_repeater_has_next() returns true.
 *
 * @param bool $format Unused; kept for signature stability (for row formatting use uich_repeater_row()).
 * @return bool False if no active loop or no next row.
 */
function uich_repeater_advance( $format = true ) {
	$stack = &uich_repeater_loop_stack();
	if ( empty( $stack ) ) {
		return false;
	}
	$top = &$stack[ count( $stack ) - 1 ];
	$n   = count( $top['rows'] );
	if ( ( $top['i'] + 1 ) >= $n ) {
		return false;
	}
	++$top['i'];
	return true;
}

/**
 * Current row data (innermost loop). 1-based index: see uich_repeater_index().
 *
 * @param bool $format Whether to run uich_format_field_value() per subfield.
 * @return array|null
 */
function uich_repeater_row( $format = true ) {
	$stack = &uich_repeater_loop_stack();
	if ( empty( $stack ) ) {
		return null;
	}
	$top = $stack[ count( $stack ) - 1 ];
	if ( $top['i'] < 0 || ! isset( $top['rows'][ $top['i'] ] ) ) {
		return null;
	}
	$row = $top['rows'][ $top['i'] ];
	if ( ! is_array( $row ) ) {
		return null;
	}
	if ( ! $format ) {
		return $row;
	}
	return uich_format_repeater_row_values( $row, $top['field'] );
}

/**
 * Current row index (1-based), or 0 if none.
 *
 * @return int
 */
function uich_repeater_index() {
	$stack = &uich_repeater_loop_stack();
	if ( empty( $stack ) ) {
		return 0;
	}
	$top = $stack[ count( $stack ) - 1 ];
	if ( $top['i'] < 0 ) {
		return 0;
	}
	return $top['i'] + 1;
}

/**
 * Pop repeater loop state (innermost), or clear all frames.
 *
 * @param bool $hard If true, clear entire stack.
 * @return void
 */
function uich_repeater_rewind( $hard = false ) {
	$stack = &uich_repeater_loop_stack();
	if ( $hard ) {
		$stack = array();
		return;
	}
	if ( ! empty( $stack ) ) {
		array_pop( $stack );
	}
}

/**
 * Get a sub value for the current repeater row (after uich_repeater_advance()).
 *
 * @param string $selector Subfield name or key.
 * @param bool   $format   Whether to format (default true).
 * @return mixed
 */
function uich_repeater_value( $selector, $format = true ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return null;
	}
	$stack = &uich_repeater_loop_stack();
	if ( empty( $stack ) ) {
		return null;
	}
	$top = $stack[ count( $stack ) - 1 ];
	if ( $top['i'] < 0 || ! isset( $top['rows'][ $top['i'] ] ) ) {
		return null;
	}
	$row = $top['rows'][ $top['i'] ];
	if ( ! is_array( $row ) ) {
		return null;
	}
	$sub = uich_find_subfield_by_selector( $selector, $top['field']['fields'] ?? array() );
	if ( ! $sub ) {
		return null;
	}
	$name = $sub['name'];
	$raw  = array_key_exists( $name, $row ) ? $row[ $name ] : null;
	if ( ! $format ) {
		return $raw;
	}
	return uich_format_field_value( $raw, $sub );
}

/**
 * Type of a sub field in the current repeater row (after uich_repeater_advance()).
 *
 * @param string $selector Subfield name or key.
 * @return string Field type, or '' when the selector does not resolve.
 */
function uich_repeater_value_type( $selector ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return '';
	}
	$stack = &uich_repeater_loop_stack();
	if ( empty( $stack ) ) {
		return '';
	}
	$top = $stack[ count( $stack ) - 1 ];
	$sub = uich_find_subfield_by_selector( $selector, $top['field']['fields'] ?? array() );

	return ( $sub && ! empty( $sub['type'] ) ) ? (string) $sub['type'] : '';
}

/**
 * Echo subfield value (escaped).
 *
 * @param string $selector Subfield name or key.
 * @return void
 */
function uich_print_repeater_value( $selector ) {
	$value = uich_repeater_value( $selector, true );

	// WYSIWYG values are already-formatted HTML (uich_format_field_value() runs them through uich/the_content), so
	// esc_html() would entity-encode every tag and the markup would render as literal text.
	if ( 'wysiwyg' === uich_repeater_value_type( $selector ) ) {
		if ( is_string( $value ) ) {
			echo wp_kses_post( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_kses_post
		}
		return;
	}

	// Escape exactly once.
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$parts[] = uich_escape_field_value( $item );
			}
		}
		echo implode( ', ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each element escaped by uich_escape_field_value
		return;
	}

	$value = uich_escape_field_value( $value );
	if ( is_scalar( $value ) ) {
		echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by uich_escape_field_value
	}
}

/**
 * Get raw stored value for a field (no formatting).
 *
 * @param string $meta_key    Full meta key.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @param mixed  $default     Default if not found.
 * @return mixed
 */
function uich_get_stored_value( $meta_key, $object_type, $object_id, $default = null ) {
	// An empty stored string is ambiguous: get_*_meta() returns '' both for "row exists and holds an empty
	// value" (the editor deliberately cleared the field) and for "no row at all".
	$resolve = static function ( $v, $type, $id ) use ( $meta_key, $default ) {
		if ( '' !== $v || null !== $default ) {
			return $v;
		}
		return metadata_exists( $type, (int) $id, $meta_key ) ? '' : $default;
	};

	switch ( $object_type ) {
		case 'user':
			if ( ! $object_id ) {
				return $default;
			}
			return $resolve( get_user_meta( (int) $object_id, $meta_key, true ), 'user', $object_id );
		case 'term':
			if ( ! $object_id ) {
				return $default;
			}
			return $resolve( get_term_meta( (int) $object_id, $meta_key, true ), 'term', $object_id );
		case 'option':
			$sentinel = '__uich_gf_option_not_set__';
			$v        = get_option( $meta_key, $sentinel );
			return ( $sentinel === $v ) ? $default : $v;
		case 'comment':
			if ( ! $object_id ) {
				return $default;
			}
			return $resolve( get_comment_meta( (int) $object_id, $meta_key, true ), 'comment', $object_id );
		default:
			return $resolve( get_post_meta( (int) $object_id, $meta_key, true ), 'post', $object_id );
	}
}

/**
 * Update stored value. Capability checks should be done by caller for update/delete.
 *
 * @param string $meta_key    Full meta key.
 * @param mixed  $value       Value to store.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @return bool
 */
function uich_update_stored_value( $meta_key, $value, $object_type, $object_id ) {
	switch ( $object_type ) {
		case 'user':
			return $object_id ? (bool) update_user_meta( (int) $object_id, $meta_key, $value ) : false;
		case 'term':
			return $object_id ? (bool) update_term_meta( (int) $object_id, $meta_key, $value ) : false;
		case 'option':
			// autoload=false: field values are read on demand via uich_field_value(), not on every page load.
			return update_option( $meta_key, $value, false );
		case 'comment':
			return $object_id ? (bool) update_comment_meta( (int) $object_id, $meta_key, $value ) : false;
		default:
			return (bool) update_post_meta( (int) $object_id, $meta_key, $value );
	}
}

/**
 * Delete stored value.
 *
 * @param string $meta_key    Full meta key.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @return bool
 */
function uich_delete_stored_value( $meta_key, $object_type, $object_id ) {
	switch ( $object_type ) {
		case 'user':
			return $object_id ? delete_user_meta( (int) $object_id, $meta_key ) : false;
		case 'term':
			return $object_id ? delete_term_meta( (int) $object_id, $meta_key ) : false;
		case 'option':
			return delete_option( $meta_key );
		case 'comment':
			return $object_id ? delete_comment_meta( (int) $object_id, $meta_key ) : false;
		default:
			return delete_post_meta( (int) $object_id, $meta_key );
	}
}

/**
 * Return reference to the request-level cache array (used by get_field, get_field_object, get_field_objects).
 *
 * @return array
 */
function &_uich_request_cache() {
	static $cache = array();
	return $cache;
}

/**
 * Clear request-level cache for a field (and all fields for that object) after update/delete.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id Object ID (post ID, user_1, term_2, option, etc.).
 */
function _uich_clear_field_request_cache( $selector, $object_id ) {
	$cache         = &_uich_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current' : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );

	// Clear every cached entry for this object, not just $selector's own entries.
	$normalized_id = (string) $normalized_id;
	foreach ( array_keys( $cache ) as $key ) {
		$parts = explode( '|', $key );
		$count = count( $parts );
		// Cast to string: explode() always yields strings, but $normalized_id may be an int
		// (post ID) — a strict compare would never match and silently no-op the clear.
		if ( $count >= 4 && ( 'v' === $parts[0] || 'o' === $parts[0] ) && (string) $parts[ $count - 2 ] === $normalized_id ) {
			unset( $cache[ $key ] );
		} elseif ( $count >= 3 && 'a' === $parts[0] && (string) $parts[2] === $normalized_id ) {
			unset( $cache[ $key ] );
		}
	}
}

/**
 * Drop the whole per-request read cache.
 *
 * Whole, not per object: a read with $object_id = false is cached under 'current', so no object-keyed
 * clear can know which entries a given meta write touched.
 *
 * @return void
 */
function uich_flush_request_cache() {
	$cache = &_uich_request_cache();
	$cache = array();
}

/**
 * Meta written outside uich_save_field_value() (update_post_meta, core REST meta, importers) must not be
 * hidden behind a value cached earlier in the same request.
 *
 * @param mixed  $meta_id    Unused.
 * @param mixed  $object_id  Unused.
 * @param string $meta_key   Meta key that changed.
 * @return void
 */
function uich_flush_request_cache_on_meta_change( $meta_id = null, $object_id = null, $meta_key = '' ) {
	if ( is_string( $meta_key ) && 0 === strpos( $meta_key, '_uichemy_gf_' ) ) {
		uich_flush_request_cache();
	}
}

/**
 * Option-scoped values: same reason, keyed by the option name.
 *
 * @param string $option Option name.
 * @return void
 */
function uich_flush_request_cache_on_option_change( $option = '' ) {
	if ( is_string( $option ) && 0 === strpos( $option, uich_get_meta_key_prefix( 'option' ) ) ) {
		uich_flush_request_cache();
	}
}

foreach ( array( 'post', 'user', 'term', 'comment' ) as $uich_meta_type ) {
	foreach ( array( 'added', 'updated', 'deleted' ) as $uich_meta_event ) {
		add_action( $uich_meta_event . '_' . $uich_meta_type . '_meta', 'uich_flush_request_cache_on_meta_change', 10, 3 );
	}
}
unset( $uich_meta_type, $uich_meta_event );
foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $uich_option_hook ) {
	add_action( $uich_option_hook, 'uich_flush_request_cache_on_option_change', 10, 1 );
}
unset( $uich_option_hook );

// A definition added, changed, trashed or restored mid-request changes which groups resolve, so every
// cached read is stale: a group deleted here must read as absent in this same request.
foreach ( array( 'uich/definition_saved', 'uich/definition_changed', 'uich/definition_deleted', 'uich/definition_deleted_permanently', 'uich/definition_restored' ) as $uich_def_hook ) {
	add_action( $uich_def_hook, 'uich_flush_request_cache', 10, 0 );
}
unset( $uich_def_hook );

// ----------------------------------------------------------------------------- 1. Core Field Retrieval
// (with request-level cache) -----------------------------------------------------------------------------.

/**
 * Return single field value. Formatted by default (e.g. image ID → URL).
 * Uses request-level cache: repeated calls for the same selector/object_id/format return cached value without DB hit.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, or false for current post.
 * @param bool   $format     Whether to format value (default true).
 * @return mixed Field value or null if not found.
 */
function uich_field_value( $selector, $object_id = false, $format = true ) {
	$cache         = &_uich_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current' : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'v|' . $selector . '|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( $info && ! uich_field_conditional_passes( $info ) ) {
		$info = null;
	}
	if ( ! $info ) {
		$value               = apply_filters( 'uich/load_field', null, $selector, $object_id );
		$cache[ $cache_key ] = $value;
		return $value;
	}

	$object_type = $info['object_type'];
	$object_id   = $info['object_id'];
	$group_key   = $info['group_key'];
	$field       = $info['field'];
	$meta_key    = uich_build_meta_key( $object_type, $group_key, $field['name'] );

	// A Pro field type on a Free build reads as EMPTY, not as an error: a template that calls this
	// in a loop must keep rendering, and the stored rows stay untouched for when the licence returns.
	if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( isset( $field['type'] ) ? $field['type'] : '' ) ) {
		$cache[ $cache_key ] = null;
		return null;
	}

	$value = apply_filters( 'uich/pre_load_field', null, $selector, $object_id );
	if ( null === $value ) {
		$value = uich_get_stored_value( $meta_key, $object_type, $object_id, null );
	}
	$exists = uich_stored_value_exists( $meta_key, $object_type, $object_id );
	// Apply default_value only when the object actually matches the group's location rules; a
	// fallback resolution (matched=false) must return stored values only, never manufacture a default.
	if ( ! $exists && ! empty( $info['matched'] ) && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}
	$value = apply_filters( 'uich/load_field', $value, $selector, $object_id );

	if ( $format ) {
		$value = uich_format_field_value( $value, $field );
		$value = apply_filters( 'uich/format_value', $value, $field, $object_id );
	}

	$cache[ $cache_key ] = $value;
	return $value;
}

/**
 * Echo field value (HTML-escaped for safe output).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 */
function uich_print_field( $selector, $object_id = false ) {
	$value = uich_field_value( $selector, $object_id, true );
	$info  = uich_maybe_get_field_info( $selector, $object_id );
	$field = ( $info && is_array( $info['field'] ) ) ? $info['field'] : array();

	// WYSIWYG values are already-formatted HTML (see uich_format_field_value()'s 'wysiwyg' case, which runs
	// them through uich/the_content).
	if ( isset( $field['type'] ) && 'wysiwyg' === $field['type'] ) {
		if ( is_string( $value ) ) {
			echo wp_kses_post( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_kses_post
		}
		return;
	}

	echo uich_field_print_markup( $value, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every leaf is escaped inside uich_field_print_markup()
}

/**
 * One value as display text, already escaped.
 *
 * Per-type on purpose: imploding the formatted array printed an image's ID/alt/title, a user's email
 * and nothing at all for terms, repeaters and booleans. A user prints the display name only.
 *
 * @param mixed $value Formatted value (any return format).
 * @param array $field Field config; an empty array falls back to generic scalar printing.
 * @return string Escaped text.
 */
function uich_field_print_markup( $value, $field ) {
	$type = isset( $field['type'] ) ? (string) $field['type'] : '';

	if ( null === $value || '' === $value ) {
		return '';
	}
	if ( 'true_false' === $type ) {
		return esc_html( uich_to_bool( $value ) ? __( 'Yes', 'uichemy' ) : __( 'No', 'uichemy' ) );
	}

	if ( 'repeater' === $type ) {
		if ( ! is_array( $value ) ) {
			return '';
		}
		$subs = array();
		foreach ( isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array() as $sub ) {
			if ( is_array( $sub ) && ! empty( $sub['name'] ) ) {
				$subs[ $sub['name'] ] = $sub;
			}
		}
		// Rows separated by "; ", the cells of one row by ", ": the closest plain-text reading of a table.
		$rows = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = array();
			foreach ( $row as $name => $cell ) {
				$text = uich_field_print_markup( $cell, isset( $subs[ $name ] ) ? $subs[ $name ] : array() );
				if ( '' !== $text ) {
					$cells[] = $text;
				}
			}
			if ( $cells ) {
				$rows[] = implode( ', ', $cells );
			}
		}
		return implode( '; ', $rows );
	}

	// A list of items, or one item that is itself an array (image, user, link): normalise to a list.
	$is_list = is_array( $value ) && ! empty( $value ) && array_values( $value ) === $value;
	$items   = $is_list ? $value : array( $value );

	$out = array();
	foreach ( $items as $item ) {
		$text = uich_field_item_print_text( $item, $type );
		if ( '' !== $text ) {
			$out[] = $text;
		}
	}
	return implode( ', ', $out );
}

/**
 * Escaped display text for one item of a field value.
 *
 * @param mixed  $item Image/user/post/term/link item in any shape the formatter can return.
 * @param string $type Field type.
 * @return string Escaped text, '' when there is nothing printable.
 */
function uich_field_item_print_text( $item, $type ) {
	if ( null === $item || '' === $item ) {
		return '';
	}

	switch ( $type ) {
		case 'image':
		case 'file':
		case 'link':
			$url = '';
			if ( is_array( $item ) ) {
				if ( ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
					$url = $item['url'];
				} elseif ( ! empty( $item['id'] ) ) {
					$url = (string) wp_get_attachment_url( (int) $item['id'] );
				}
			} elseif ( is_numeric( $item ) ) {
				$url = (string) wp_get_attachment_url( (int) $item );
			} elseif ( is_string( $item ) ) {
				$url = $item;
			}
			return esc_url( $url );

		case 'user':
			if ( $item instanceof WP_User ) {
				return esc_html( $item->display_name );
			}
			if ( is_array( $item ) && ! empty( $item['name'] ) && is_scalar( $item['name'] ) ) {
				return esc_html( (string) $item['name'] );
			}
			$id = is_array( $item ) ? ( isset( $item['id'] ) ? $item['id'] : ( isset( $item['ID'] ) ? $item['ID'] : 0 ) ) : $item;
			$u  = is_numeric( $id ) ? get_user_by( 'id', (int) $id ) : false;
			return $u ? esc_html( $u->display_name ) : '';

		case 'post_object':
		case 'relationship':
			if ( $item instanceof WP_Post ) {
				return esc_html( get_the_title( $item ) );
			}
			$id = is_array( $item ) ? ( isset( $item['ID'] ) ? $item['ID'] : 0 ) : $item;
			return ( is_numeric( $id ) && get_post( (int) $id ) ) ? esc_html( get_the_title( (int) $id ) ) : '';

		case 'taxonomy':
			if ( $item instanceof WP_Term ) {
				return esc_html( $item->name );
			}
			$id   = is_array( $item ) ? ( isset( $item['term_id'] ) ? $item['term_id'] : 0 ) : $item;
			$term = is_numeric( $id ) ? get_term( (int) $id ) : null;
			return ( $term && ! is_wp_error( $term ) ) ? esc_html( $term->name ) : '';
	}

	if ( is_array( $item ) ) {
		$parts = array();
		foreach ( $item as $leaf ) {
			if ( is_scalar( $leaf ) && '' !== (string) $leaf ) {
				$parts[] = uich_escape_field_value( $leaf );
			}
		}
		return implode( ', ', $parts );
	}
	// Escape exactly once.
	return is_scalar( $item ) ? (string) uich_escape_field_value( $item ) : '';
}

/**
 * Return all field values for an object as associative array [ field_name => value ].
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @param bool  $format    Whether to format values (default true).
 * @return array Associative array of field name => value, or empty array.
 */
function uich_field_values( $object_id = false, $format = true ) {
	$objects = uich_field_definitions( $object_id, $format );
	if ( ! is_array( $objects ) ) {
		return array();
	}
	$out = array();
	foreach ( $objects as $name => $field_obj ) {
		$out[ $name ] = isset( $field_obj['value'] ) ? $field_obj['value'] : null;
	}
	return $out;
}

/**
 * Return field metadata plus value (field object).
 * Uses request-level cache: repeated calls for the same selector/object_id/format return cached object without DB hit.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, or false.
 * @param bool   $format     Whether to format the value (default true).
 * @return array|null Field array with 'value' key, or null.
 */
function uich_field_definition( $selector, $object_id = false, $format = true ) {
	$cache         = &_uich_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current' : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'o|' . $selector . '|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( $info && ! uich_field_conditional_passes( $info ) ) {
		$info = null;
	}
	if ( ! $info ) {
		$cache[ $cache_key ] = null;
		return null;
	}

	$object_type = $info['object_type'];
	$object_id   = $info['object_id'];
	$group_key   = $info['group_key'];
	$field       = $info['field'];
	$meta_key    = uich_build_meta_key( $object_type, $group_key, $field['name'] );

	// Same lock as uich_field_value(), but the field object itself is still returned: callers use it
	// to read the label and type, and `pro_locked` is how a template can say WHY the value is empty.
	if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( isset( $field['type'] ) ? $field['type'] : '' ) ) {
		$field['value']      = null;
		$field['pro_locked'] = true;
		$cache[ $cache_key ] = $field;
		return $field;
	}

	$value  = uich_get_stored_value( $meta_key, $object_type, $object_id, null );
	$exists = uich_stored_value_exists( $meta_key, $object_type, $object_id );
	// Default only for a location-matched object (see uich_field_value).
	if ( ! $exists && ! empty( $info['matched'] ) && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}
	if ( $format ) {
		$value = uich_format_field_value( $value, $field );
	}
	$field['value'] = apply_filters( 'uich/format_value', $value, $field, $object_id );

	$cache[ $cache_key ] = $field;
	return $field;
}

/**
 * Return all field objects for an object.
 * Uses request-level cache: repeated calls for the same object_id/format return cached array without DB hit.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @param bool  $format    Whether to format values (default true).
 * @return array|false Associative array field_name => field object (with value), or false.
 */
function uich_field_definitions( $object_id = false, $format = true ) {
	$cache         = &_uich_request_cache();
	$obj           = uich_get_valid_object_id( $object_id );
	$normalized_id = ( false === $object_id ) ? 'current' : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'a|objects|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$context = uich_build_context_for_object( $obj['type'], $obj['id'] );
	$groups  = uich_get_all_groups_for_context( $context );

	$collected = array();
	$claimed   = array();
	foreach ( $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$name = $field['name'];
			// First group wins, exactly like the single reader (uich_maybe_get_field_info()); a later group
			// reusing the name must not overwrite it, or the two readers return different values.
			if ( isset( $claimed[ $name ] ) ) {
				continue;
			}
			$claimed[ $name ] = true;
			if ( ! uich_field_conditional_passes(
				array(
					'field'        => $field,
					'group_key'    => $group_key,
					'group_fields' => $fields,
					'object_type'  => $obj['type'],
					'object_id'    => $obj['id'],
				)
			) ) {
				continue;
			}
			$meta_key = uich_build_meta_key( $obj['type'], $group_key, $name );
			$value    = uich_get_stored_value( $meta_key, $obj['type'], $obj['id'], null );
			$exists   = uich_stored_value_exists( $meta_key, $obj['type'], $obj['id'] );
			if ( ! $exists && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
				$value = $field['default_value'];
			}
			if ( $format ) {
				$value = uich_format_field_value( $value, $field );
			}
			$field['value']     = $value;
			$collected[ $name ] = $field;
		}
	}

	$result              = empty( $collected ) ? false : $collected;
	$cache[ $cache_key ] = $result;
	return $result;
}

// ----------------------------------------------------------------------------- 2. Raw Database Value
// -----------------------------------------------------------------------------.

/**
 * Return unformatted meta value.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return mixed Raw value or null.
 */
function uich_field_value_raw( $selector, $object_id = false ) {
	return uich_field_value( $selector, $object_id, false );
}

/**
 * Return all raw meta values for an object.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @return array Associative array field_name => raw value.
 */
function uich_field_values_raw( $object_id = false ) {
	return uich_field_values( $object_id, false );
}

// ----------------------------------------------------------------------------- 3. Field Metadata
// -----------------------------------------------------------------------------.

/**
 * Get field label.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context to resolve field.
 * @return string Empty string if not found.
 */
function uich_field_label( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	return ( $info && ! empty( $info['field']['label'] ) ) ? $info['field']['label'] : '';
}

/**
 * Get field type.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return string e.g. text, image, select, repeater, group.
 */
function uich_field_type( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	return ( $info && ! empty( $info['field']['type'] ) ) ? $info['field']['type'] : '';
}

/**
 * Get field settings (instructions, default_value, required, wrapper, etc.).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return array Settings array or empty array.
 */
function uich_get_field_settings( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info || ! is_array( $info['field'] ) ) {
		return array();
	}
	$f        = $info['field'];
	$settings = array();
	$keys     = array(
		'instructions',
		'default_value',
		'required',
		'wrapper',
		'wrapper_class',
		'wrapper_id',
		'wrapper_width',
		'placeholder',
		'prepend',
		'append',
		'min',
		'max',
		'step',
		'rows',
		'choices',
		'return_format',
		'multiple',
		'allow_null',
		'layout',
		'post_object_return_format',
		'post_object_post_types',
		'post_object_post_status',
		'relationship_return_format',
		'relationship_post_types',
		'relationship_post_status',
		'relationship_max_posts',
		'user_return_format',
		'user_role_filter',
		'taxonomy_name',
		'taxonomy_return_format',
		'taxonomy_appearance',
		'taxonomy_save_terms',
		'page_link_post_types',
		'page_link_post_status',
		'date_display_format',
		'date_custom_display_format',
		'date_return_format',
		'datetime_display_format',
		'datetime_custom_display_format',
		'datetime_return_format',
		'time_display_format',
		'time_custom_display_format',
		'time_return_format',
		'time_custom_return_format',
		'color_return_format',
		'color_enable_transparency',
		'color_show_custom_palette',
		'color_palette_source',
		'color_custom_palette',
	);
	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $f ) ) {
			$settings[ $k ] = $f[ $k ];
		}
	}
	return $settings;
}

/**
 * Get field key (group_key + name style identifier). Uichemy uses name as primary; key can be group_key + name.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return string Field key or empty.
 */
function uich_get_field_key( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return '';
	}
	return ( isset( $info['field']['key'] ) && '' !== $info['field']['key'] ) ? $info['field']['key'] : $info['group_key'] . '_' . $info['field']['name'];
}

// ----------------------------------------------------------------------------- 4. Field Existence /
// Validation -----------------------------------------------------------------------------.

/**
 * Check if field exists and has a value for the given object.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function uich_has_field( $selector, $object_id = false ) {
	$value = uich_field_value( $selector, $object_id, false );
	return null !== $value && '' !== $value && ( ! is_array( $value ) || ! empty( $value ) );
}

/**
 * Check if field is registered (exists in a matching group).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function uich_field_exists( $selector, $object_id = false ) {
	return uich_maybe_get_field_info( $selector, $object_id ) !== null;
}

/**
 * Check if field value is empty.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function uich_is_field_empty( $selector, $object_id = false ) {
	$value = uich_field_value( $selector, $object_id, false );
	return null === $value || '' === $value || ( is_array( $value ) && empty( $value ) );
}

// ----------------------------------------------------------------------------- 5. Field Value Management
// -----------------------------------------------------------------------------.

/**
 * Update field value. Checks edit capability for the object.
 *
 * @param string $selector  Field name.
 * @param mixed  $value     Value to save.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool Success.
 */
function uich_save_field_value( $selector, $value, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}

	$obj = array(
		'type' => $info['object_type'],
		'id'   => $info['object_id'],
	);
	if ( ! uich_current_user_can_edit_object( $obj ) ) {
		return false;
	}

	// Refuse the write rather than performing it — on a Free build the stored value must survive
	// untouched, so "do nothing and report failure" is the only safe answer.
	if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( isset( $info['field']['type'] ) ? $info['field']['type'] : '' ) ) {
		return false;
	}

	$value = apply_filters( 'uich/update_value', $value, $info['field'], $object_id );

	// Route through the module's own per-type sanitizer — the same call the admin save handlers and the
	// abilities layer make.
	if ( ! class_exists( 'Uich_Fields_Field_Groups_Render' ) ) {
		return false;
	}
	$renderer = Uich_Fields_Field_Groups_Render::get_instance();
	if ( ! is_object( $renderer ) || ! method_exists( $renderer, 'sanitize_value_for_field' ) ) {
		return false;
	}
	$sent  = $value;
	$value = $renderer->sanitize_value_for_field( uich_page_link_ids_to_permalinks( $value, $info['field'] ), $info['field'] );

	// Refused, not stored: a value the sanitiser wiped would otherwise replace the previous good one.
	if ( '' !== uich_field_value_rejection( $sent, $value, $info['field'] ) ) {
		return false;
	}

	$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );

	$updated = uich_update_stored_value( $meta_key, $value, $info['object_type'], $info['object_id'] );
	if ( $updated ) {
		_uich_clear_field_request_cache( $selector, $object_id );
		if ( 'post' === $info['object_type'] ) {
			uich_purge_page_cache_for_post( $info['object_id'] );
		}
		return true;
	}

	// update_post_meta() (and its user/term/option counterparts) return false both on a genuine write failure
	// AND when the new value is identical to what's already stored (a no-op).
	$current = uich_get_stored_value( $meta_key, $info['object_type'], $info['object_id'], null );
	return $current === $value;
}

/**
 * A page_link field given a bare post ID (or a list of them) becomes that post's permalink.
 *
 * The stored shape is a URL; an ID that is not a published, existing post becomes '' and is then
 * refused by uich_field_value_rejection() instead of being stored as a bogus host.
 *
 * @param mixed $value Value as sent.
 * @param array $field Field config.
 * @return mixed Value with numeric IDs replaced by permalinks; untouched for any other field type.
 */
function uich_page_link_ids_to_permalinks( $value, $field ) {
	if ( ! is_array( $field ) || ! isset( $field['type'] ) || 'page_link' !== $field['type'] ) {
		return $value;
	}
	$to_url = static function ( $v ) {
		if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( trim( $v ) ) ) ) {
			$id = (int) $v;
			return ( $id > 0 && get_post( $id ) ) ? (string) get_permalink( $id ) : '';
		}
		return $v;
	};
	return is_array( $value ) ? array_map( $to_url, $value ) : $to_url( $value );
}

/**
 * Whether a value carries nothing: null, '', whitespace, or an array holding only such values.
 *
 * @param mixed $value Any value.
 * @return bool
 */
function uich_value_is_blank( $value ) {
	if ( null === $value ) {
		return true;
	}
	if ( is_string( $value ) ) {
		return '' === trim( $value );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $v ) {
			if ( ! uich_value_is_blank( $v ) ) {
				return false;
			}
		}
		return true;
	}
	return false;
}

/**
 * Why a write must be refused after sanitising, or '' when it may be stored.
 *
 * A deliberate clear (blank input) always passes. Otherwise input that the sanitiser reduced to
 * nothing — a number field given 'abc', a select given a choice it does not declare, a `javascript:`
 * URL — is refused rather than stored empty over the previous value. A value that was merely
 * adjusted (a clamped number, a snapped step) still stores.
 *
 * @param mixed $sent      Value as sent.
 * @param mixed $sanitised Value after sanitize_value_for_field().
 * @param array $field     Field config.
 * @return string Human-readable reason, '' to accept.
 */
function uich_field_value_rejection( $sent, $sanitised, $field ) {
	if ( uich_value_is_blank( $sent ) ) {
		return '';
	}
	$type = ( is_array( $field ) && isset( $field['type'] ) ) ? (string) $field['type'] : '';

	if ( uich_value_is_blank( $sanitised ) ) {
		return sprintf(
			/* translators: %s: field type. */
			__( 'The value is not valid for a %s field, so it was refused and the stored value was kept.', 'uichemy' ),
			$type
		);
	}

	// Partly outside the declared choices: the sanitiser would silently drop the rest.
	if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
		$valid = uich_field_choice_keys( $field );
		if ( ! empty( $valid ) ) {
			foreach ( (array) $sent as $v ) {
				if ( uich_value_is_blank( $v ) ) {
					continue;
				}
				if ( ! is_scalar( $v ) || ! in_array( (string) $v, $valid, true ) ) {
					return __( 'The value is not one of the choices this field declares, so it was refused and the stored value was kept.', 'uichemy' );
				}
			}
		}
	}

	return '';
}

/**
 * Delete field value.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool
 */
function uich_erase_field_value( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}
	if ( ! uich_current_user_can_edit_object(
		array(
			'type' => $info['object_type'],
			'id'   => $info['object_id'],
		)
	) ) {
		return false;
	}
	$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );
	$deleted  = uich_delete_stored_value( $meta_key, $info['object_type'], $info['object_id'] );
	if ( $deleted ) {
		_uich_clear_field_request_cache( $selector, $object_id );
	}
	return $deleted;
}

/**
 * Reset field to default value (from field config).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool
 */
function uich_reset_field( $selector, $object_id = false ) {
	$info = uich_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}
	$default = isset( $info['field']['default_value'] ) ? $info['field']['default_value'] : '';
	return uich_save_field_value( $selector, $default, $object_id );
}

/**
 * Check if current user can edit the given object (post/user/term/option).
 *
 * @param array $obj Object reference: 'type' (string) and 'id' (int|string).
 * @return bool
 */
function uich_current_user_can_edit_object( $obj ) {
	$type = isset( $obj['type'] ) ? $obj['type'] : 'post';
	$id   = isset( $obj['id'] ) ? $obj['id'] : 0;

	switch ( $type ) {
		case 'user':
			return current_user_can( 'edit_user', (int) $id );
		case 'term':
			$term = get_term( (int) $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return false;
			}
			return current_user_can( 'edit_term', (int) $id );
		case 'option':
			return current_user_can( 'manage_options' );
		case 'comment':
			return current_user_can( 'edit_comment', (int) $id );
		default:
			return current_user_can( 'edit_post', (int) $id );
	}
}

// ----------------------------------------------------------------------------- 6. Field Formatting
// -----------------------------------------------------------------------------.

/**
 * Normalize one post_object / relationship item to WP_Post when return format is object.
 *
 * @param mixed  $item   ID, array with ID, WP_Post, or lite array.
 * @param string $return Return format key: id|object.
 * @return mixed null when the referenced post no longer exists.
 */
function uich_format_post_reference_item( $item, $return ) {
	$id = 0;
	if ( $item instanceof WP_Post ) {
		$id = (int) $item->ID;
	} elseif ( is_numeric( $item ) ) {
		$id = (int) $item;
	} elseif ( is_array( $item ) && ! empty( $item['ID'] ) ) {
		$id = (int) $item['ID'];
	}
	if ( ! $id ) {
		return $item;
	}
	// Re-resolved on every read: a stored ID outlives the post, and a dangling one must not be returned.
	$post = get_post( $id );
	if ( ! $post ) {
		return null;
	}
	return ( 'id' === $return ) ? (int) $post->ID : $post;
}

/**
 * Normalize user field item to WP_User when format is object.
 *
 * @param mixed  $item Stored id, row array, or WP_User.
 * @param string $fmt  id|object|array.
 * @return mixed null when the referenced user no longer exists.
 */
function uich_format_user_field_item( $item, $fmt ) {
	$id = 0;
	if ( $item instanceof WP_User ) {
		$id = (int) $item->ID;
	} elseif ( is_numeric( $item ) ) {
		$id = (int) $item;
	} elseif ( is_array( $item ) ) {
		if ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
			$id = (int) $item['ID'];
		} elseif ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
			$id = (int) $item['id'];
		}
	}
	if ( ! $id ) {
		return $item;
	}

	// Re-resolved on every read, never from a stored snapshot: a deleted user must read as empty, not as
	// their old name and email.
	$u = get_user_by( 'id', $id );
	if ( ! $u ) {
		return null;
	}
	if ( 'id' === $fmt ) {
		return (int) $u->ID;
	}
	if ( 'object' === $fmt ) {
		return $u;
	}
	if ( 'array' === $fmt ) {
		return array(
			'id'       => (int) $u->ID,
			'username' => $u->user_login,
			'name'     => $u->display_name,
			'email'    => $u->user_email,
			'roles'    => array_values( (array) $u->roles ),
		);
	}
	return $item;
}

/**
 * Normalize taxonomy field entry to WP_Term when return format is object.
 *
 * @param mixed  $item Term id, lite array, or WP_Term.
 * @param string $taxonomy Taxonomy slug.
 * @param bool   $as_object Return WP_Term (true) or the term ID (false).
 * @return mixed null when the referenced term no longer exists.
 */
function uich_format_taxonomy_field_item( $item, $taxonomy, $as_object = true ) {
	$id = 0;
	if ( $item instanceof WP_Term ) {
		$id = (int) $item->term_id;
	} elseif ( is_numeric( $item ) ) {
		$id = (int) $item;
	} elseif ( is_array( $item ) && ! empty( $item['term_id'] ) ) {
		$id = (int) $item['term_id'];
	}
	if ( ! $id ) {
		return $item;
	}
	// Re-resolved on every read: a stored term ID outlives the term, and a dangling one must not be returned.
	$term = $taxonomy ? get_term( $id, $taxonomy ) : get_term( $id );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	return $as_object ? $term : (int) $term->term_id;
}

/**
 * Coerce a true_false field value to a real boolean.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function uich_to_bool( $value ) {
	if ( is_string( $value ) ) {
		$normalized = strtolower( trim( $value ) );
		if ( in_array( $normalized, array( 'false', 'no', 'off', '0', '' ), true ) ) {
			return false;
		}
	}
	return (bool) $value;
}

/**
 * Apply formatting rules to a value based on field type (e.g. image ID → URL, date format).
 *
 * @param mixed $value Raw value.
 * @param array $field Field config (must have 'type' key).
 * @return mixed Formatted value.
 */
function uich_format_field_value( $value, $field ) {
	if ( ! is_array( $field ) || empty( $field['type'] ) ) {
		return $value;
	}
	$type = $field['type'];

	// true_false is resolved before the empty guard below.
	if ( 'true_false' === $type ) {
		return ( null === $value ) ? null : uich_to_bool( $value );
	}

	if ( null === $value || '' === $value ) {
		return $value;
	}

	switch ( $type ) {
		case 'image':
			$id = is_array( $value ) ? ( isset( $value['id'] ) ? $value['id'] : ( isset( $value['ID'] ) ? $value['ID'] : 0 ) ) : (int) $value;
			if ( ! $id ) {
				return $value;
			}
			$return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
			if ( 'url' === $return ) {
				$src = wp_get_attachment_image_src( $id, 'full' );
				return $src ? $src[0] : '';
			}
			if ( 'id' === $return ) {
				return $id;
			}
			$src = wp_get_attachment_image_src( $id, 'full' );
			return array(
				'id'    => $id,
				'url'   => $src ? $src[0] : '',
				'alt'   => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title' => get_the_title( $id ),
			);
		case 'file':
			$id = is_array( $value ) ? ( isset( $value['id'] ) ? $value['id'] : ( isset( $value['ID'] ) ? $value['ID'] : 0 ) ) : (int) $value;
			if ( ! $id ) {
				return $value;
			}
			$return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
			if ( 'url' === $return ) {
				return wp_get_attachment_url( $id );
			}
			if ( 'id' === $return ) {
				return $id;
			}
			// Both return false for a deleted or non-attachment ID, and the shape promises a string.
			$file_url  = wp_get_attachment_url( $id );
			$file_path = get_attached_file( $id );
			return array(
				'id'       => $id,
				'url'      => is_string( $file_url ) ? $file_url : '',
				'title'    => get_the_title( $id ),
				'filename' => is_string( $file_path ) ? basename( $file_path ) : '',
			);
		case 'post_object':
			$return   = isset( $field['post_object_return_format'] ) ? $field['post_object_return_format'] : 'object';
			$multiple = ! empty( $field['multiple'] );
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					$value = array( $value );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( '' === $item || null === $item ) {
						continue;
					}
					$resolved = uich_format_post_reference_item( $item, $return );
					if ( null !== $resolved ) {
						$out[] = $resolved;
					}
				}
				return $out;
			}
			return uich_format_post_reference_item( $value, $return );
		case 'relationship':
			$return = isset( $field['relationship_return_format'] ) ? $field['relationship_return_format'] : 'object';
			if ( ! is_array( $value ) ) {
				// Explicit rather than falsy: a relationship id of 0 is a stored value, and wrapping '' would yield array( '' ).
				$value = ( '' !== $value && null !== $value ) ? array( $value ) : array();
			}
			$out = array();
			foreach ( $value as $item ) {
				if ( '' === $item || null === $item ) {
					continue;
				}
				$resolved = uich_format_post_reference_item( $item, $return );
				if ( null !== $resolved ) {
					$out[] = $resolved;
				}
			}
			return $out;
		case 'user':
			$fmt      = isset( $field['user_return_format'] ) ? sanitize_key( (string) $field['user_return_format'] ) : 'array';
			$multiple = ! empty( $field['multiple'] );
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					$value = array( $value );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( '' === $item || null === $item ) {
						continue;
					}
					$resolved = uich_format_user_field_item( $item, $fmt );
					if ( null !== $resolved ) {
						$out[] = $resolved;
					}
				}
				return $out;
			}
			return uich_format_user_field_item( $value, $fmt );
		case 'taxonomy':
			$tax        = isset( $field['taxonomy_name'] ) ? sanitize_key( (string) $field['taxonomy_name'] ) : '';
			$as_object  = isset( $field['taxonomy_return_format'] ) && 'object' === $field['taxonomy_return_format'];
			$appearance = isset( $field['taxonomy_appearance'] ) ? sanitize_key( (string) $field['taxonomy_appearance'] ) : 'checkbox';
			$multiple   = in_array( $appearance, array( 'checkbox', 'multi_select' ), true );
			if ( $multiple && is_array( $value ) ) {
				$out = array();
				foreach ( $value as $item ) {
					if ( '' === $item || null === $item ) {
						continue;
					}
					$resolved = uich_format_taxonomy_field_item( $item, $tax, $as_object );
					if ( null !== $resolved ) {
						$out[] = $resolved;
					}
				}
				return $out;
			}
			return uich_format_taxonomy_field_item( $value, $tax, $as_object );
		case 'wysiwyg':
			if ( ! is_string( $value ) ) {
				return $value;
			}
			return uich_format_wysiwyg( $value );
		case 'date':
		case 'datetime':
		case 'time':
		case 'color':
		case 'link':
		case 'page_link':
			return $value;
		case 'repeater':
			if ( ! is_array( $value ) ) {
				return $value;
			}
			$subs = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();
			$map  = array();
			foreach ( $subs as $sf ) {
				if ( is_array( $sf ) && ! empty( $sf['name'] ) ) {
					$map[ $sf['name'] ] = $sf;
				}
			}
			$out = array();
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					$out[] = $row;
					continue;
				}
				$formatted_row = array();
				foreach ( $row as $k => $v ) {
					$formatted_row[ $k ] = isset( $map[ $k ] ) ? uich_format_field_value( $v, $map[ $k ] ) : $v;
				}
				$out[] = $formatted_row;
			}
			return $out;
		case 'true_false':
			return uich_to_bool( $value );
		case 'number':
		case 'range':
			return is_numeric( $value ) ? ( strpos( (string) $value, '.' ) !== false ? (float) $value : (int) $value ) : $value;
		default:
			return $value;
	}
}

/**
 * Escape value for safe HTML output (scalar or array recursively).
 *
 * @param mixed $value Value to escape.
 * @return mixed Escaped value (string or array of strings).
 */
function uich_escape_field_value( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'uich_escape_field_value', $value );
	}
	if ( is_scalar( $value ) ) {
		return esc_html( (string) $value );
	}
	return '';
}

// ----------------------------------------------------------------------------- 7. Field Group Functions
// -----------------------------------------------------------------------------.

/**
 * Get a single field group by ID.
 *
 * @param int $group_id Post ID of the group (uich_content_types post).
 * @return array|null Group config or null.
 */
function uich_group_definition( $group_id ) {
	if ( ! class_exists( 'Uich_Fields_CPT' ) ) {
		return null;
	}
	// Definitions are files.
	$group_id = (int) $group_id;
	$meta     = Uich_Fields_Model_Writer::definition( $group_id );
	if ( ! is_array( $meta ) || ( isset( $meta['kind'] ) ? $meta['kind'] : '' ) !== 'group_fields' ) {
		return null;
	}
	return array(
		'id'                    => $group_id,
		'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : ( ! empty( $meta['key'] ) ? $meta['key'] : '' ),
		'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $group_id,
		'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
		'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
		'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
		'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
		'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
		'location_rules'        => isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
		'show_in_rest'          => ! empty( $meta['show_in_rest'] ),
	);
}

/**
 * Split location rules into their OR groups of AND rules (the shape Location::match() reads).
 *
 * @param mixed $rules Stored location_rules.
 * @return array<int, array> List of rule lists.
 */
function uich_location_rule_groups( $rules ) {
	if ( empty( $rules ) || ! is_array( $rules ) ) {
		return array();
	}
	$first = isset( $rules[0] ) ? $rules[0] : null;
	$nest  = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
	$out   = array();
	foreach ( $nest ? $rules : array( $rules ) as $group ) {
		if ( is_array( $group ) ) {
			$out[] = array_values( array_filter( $group, 'is_array' ) );
		}
	}
	return $out;
}

/**
 * Whether two location rule sets can both match one object. Conservative: anything not provably
 * disjoint counts as overlapping, because a missed collision is worse than a spurious warning.
 *
 * Provably disjoint = every pair of AND-groups holds two `==` rules on the same param with different
 * values (post_type == page vs post_type == post), or targets different object types.
 *
 * @param mixed $rules_a Location rules of group A.
 * @param mixed $rules_b Location rules of group B.
 * @return bool
 */
function uich_location_rules_overlap( $rules_a, $rules_b ) {
	$families = array(
		'post'    => array( 'post_type', 'post', 'page', 'post_template', 'page_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'page_type', 'page_parent', 'attachment' ),
		'term'    => array( 'taxonomy' ),
		'user'    => array( 'user_role', 'user_form' ),
		'comment' => array( 'comment' ),
	);
	$family   = static function ( $param ) use ( $families ) {
		foreach ( $families as $name => $params ) {
			if ( in_array( $param, $params, true ) ) {
				return $name;
			}
		}
		return '';
	};
	$equals   = static function ( $rule ) {
		$op = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
		return in_array( $op, array( '==', '=' ), true );
	};

	$groups_a = uich_location_rule_groups( $rules_a );
	$groups_b = uich_location_rule_groups( $rules_b );
	// No rules: the group renders nowhere, so there is nothing to collide with.
	if ( empty( $groups_a ) || empty( $groups_b ) ) {
		return false;
	}

	foreach ( $groups_a as $ga ) {
		foreach ( $groups_b as $gb ) {
			$disjoint = false;
			foreach ( $ga as $ra ) {
				foreach ( $gb as $rb ) {
					$pa = isset( $ra['param'] ) ? sanitize_key( $ra['param'] ) : '';
					$pb = isset( $rb['param'] ) ? sanitize_key( $rb['param'] ) : '';
					$fa = $family( $pa );
					$fb = $family( $pb );
					if ( '' !== $fa && '' !== $fb && $fa !== $fb ) {
						$disjoint = true;
						break 2;
					}
					if ( '' !== $pa && $pa === $pb && $equals( $ra ) && $equals( $rb ) ) {
						$va = isset( $ra['value'] ) ? (string) $ra['value'] : '';
						$vb = isset( $rb['value'] ) ? (string) $rb['value'] : '';
						if ( '' !== $va && '' !== $vb && 'all' !== $va && 'all' !== $vb && $va !== $vb ) {
							$disjoint = true;
							break 2;
						}
					}
				}
			}
			if ( ! $disjoint ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Warnings for a just-saved group whose field names are also defined by another active group that can
 * match the same object. Only one group is read per name (the first by order), so the second one's
 * value is silently never returned.
 *
 * @param array $meta Saved group definition (key, active, fields, location_rules).
 * @return string[] Human-readable warnings; empty when there is no collision.
 */
function uich_field_name_collision_warnings( $meta ) {
	if ( ! is_array( $meta ) || ! class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
		return array();
	}
	if ( isset( $meta['active'] ) && ! $meta['active'] ) {
		return array();
	}
	$key   = isset( $meta['key'] ) ? (string) $meta['key'] : '';
	$names = array();
	foreach ( isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? $meta['fields'] : array() as $field ) {
		if ( is_array( $field ) && ! empty( $field['name'] ) ) {
			$names[ uich_normalize_field_name( $field['name'] ) ] = (string) $field['name'];
		}
	}
	if ( empty( $names ) ) {
		return array();
	}

	$warnings = array();
	$rows     = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
	foreach ( $rows['group_fields'] as $row ) {
		$other = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
		if ( 'publish' !== $row['status'] || ( isset( $other['active'] ) && ! $other['active'] ) ) {
			continue;
		}
		if ( ( isset( $other['key'] ) ? (string) $other['key'] : '' ) === $key ) {
			continue;
		}
		if ( ! uich_location_rules_overlap( isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(), isset( $other['location_rules'] ) ? $other['location_rules'] : array() ) ) {
			continue;
		}
		$shared = array();
		foreach ( isset( $other['fields'] ) && is_array( $other['fields'] ) ? $other['fields'] : array() as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) && isset( $names[ uich_normalize_field_name( $field['name'] ) ] ) ) {
				$shared[] = (string) $field['name'];
			}
		}
		if ( $shared ) {
			$warnings[] = sprintf(
				/* translators: 1: field names, 2: other group title. */
				__( 'The field name(s) "%1$s" are also defined by the active field group "%2$s" for an overlapping location. Only one group is read for a given field name, so rename one of them.', 'uichemy' ),
				implode( '", "', array_unique( $shared ) ),
				! empty( $other['title'] ) ? (string) $other['title'] : ( isset( $other['key'] ) ? (string) $other['key'] : '' )
			);
		}
	}
	return $warnings;
}

/**
 * Get all field groups (optionally for a context).
 *
 * @param array $context Optional. Context for location matching. Empty = all group_fields.
 * @return array List of group configs.
 */
function uich_group_definitions( $context = array() ) {
	if ( empty( $context ) ) {
		return uich_get_all_groups_for_context( array( 'screen' => 'option' ) );
	}
	return uich_get_all_groups_for_context( $context );
}

/**
 * Get fields for a group.
 *
 * @param int $group_id Group post ID.
 * @return array Field configs.
 */
function uich_get_group_fields( $group_id ) {
	$group = uich_group_definition( $group_id );
	return ( $group && isset( $group['fields'] ) ) ? (array) $group['fields'] : array();
}

// --- 8. Caching (request-level cache) ---

/**
 * Get field value from manual request cache.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Object ID.
 * @return mixed Cached value or null if not in cache.
 */
function uich_cache_get_field( $selector, $object_id = false ) {
	static $cache = array();
	$key          = $selector . '|' . ( is_scalar( $object_id ) ? $object_id : ( $object_id ? serialize( $object_id ) : 'current' ) );
	return isset( $cache[ $key ] ) ? $cache[ $key ] : null;
}

/**
 * Set field value in manual request cache.
 *
 * @param string $selector  Field name.
 * @param mixed  $value     Value to cache.
 * @param mixed  $object_id Object ID.
 */
function uich_cache_set_field( $selector, $value, $object_id = false ) {
	static $cache  = array();
	$key           = $selector . '|' . ( is_scalar( $object_id ) ? $object_id : ( $object_id ? serialize( $object_id ) : 'current' ) );
	$cache[ $key ] = $value;
}

// ----------------------------------------------------------------------------- 9. Bulk Data
// -----------------------------------------------------------------------------.

/**
 * Return all registered fields across all field groups (flat list with group info).
 *
 * @return array Array of field configs with group_key/group_id added.
 */
function uich_get_all_fields() {
	$groups = uich_group_definitions( array() );
	$out    = array();
	foreach ( $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) || empty( $f['name'] ) ) {
				continue;
			}
			$f['group_key'] = $group_key;
			$f['group_id']  = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$out[]          = $f;
		}
	}
	return $out;
}

/**
 * Return all values for an object: meta, field_objects, formatted_values.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @return array ['meta' => [], 'field_objects' => [], 'formatted_values' => []].
 */
function uich_get_all_field_data( $object_id = false ) {
	$field_objects = uich_field_definitions( $object_id, true );
	$meta          = array();
	$formatted     = array();
	if ( is_array( $field_objects ) ) {
		foreach ( $field_objects as $name => $obj ) {
			$meta[ $name ]      = isset( $obj['value'] ) ? $obj['value'] : null;
			$formatted[ $name ] = $meta[ $name ];
		}
	}
	return array(
		'meta'             => $meta,
		'field_objects'    => $field_objects ? $field_objects : array(),
		'formatted_values' => $formatted,
	);
}

/**
 * Export field groups config as JSON.
 *
 * @param array $group_ids Optional. Specific group IDs to export. Empty = all.
 * @return string JSON string.
 */
function uich_export_fields( $group_ids = array() ) {
	$groups = array();
	if ( empty( $group_ids ) ) {
		$groups = uich_group_definitions( array() );
	} else {
		foreach ( $group_ids as $id ) {
			$g = uich_group_definition( (int) $id );
			if ( $g ) {
				$groups[] = $g;
			}
		}
	}
	return wp_json_encode( $groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
}

/**
 * Import field config from JSON (as produced by uich_export_fields()). Creates or updates field group
 * definitions through Uich_Fields_Admin_Logic::save_field_group(). Requires manage_options.
 *
 * @param string $json JSON string from uich_export_fields.
 * @return array ['success' => bool, 'ids' => array of definition IDs, 'errors' => []].
 */
function uich_import_fields( $json ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Permission denied.', 'uichemy' ) ),
		);
	}
	if ( ! class_exists( 'Uich_Fields_CPT' ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Content Types not loaded.', 'uichemy' ) ),
		);
	}
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Invalid JSON.', 'uichemy' ) ),
		);
	}
	if ( isset( $data['id'] ) || isset( $data['key'] ) ) {
		$data = array( $data );
	}
	$ids    = array();
	$errors = array();
	foreach ( $data as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}
		$meta = array(
			'active'                => isset( $group['active'] ) ? (bool) $group['active'] : true,
			'title'                 => isset( $group['title'] ) && is_string( $group['title'] ) && '' !== trim( $group['title'] ) ? $group['title'] : __( 'Imported Group', 'uichemy' ),
			'key'                   => isset( $group['key'] ) && is_string( $group['key'] ) ? $group['key'] : '',
			'description'           => isset( $group['description'] ) ? $group['description'] : '',
			'fields'                => isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array(),
			'position'              => isset( $group['position'] ) ? $group['position'] : 'normal',
			'style'                 => isset( $group['style'] ) ? $group['style'] : 'default',
			'label_placement'       => isset( $group['label_placement'] ) ? $group['label_placement'] : 'top',
			'instruction_placement' => isset( $group['instruction_placement'] ) ? $group['instruction_placement'] : 'label',
			'location_rules'        => isset( $group['location_rules'] ) && is_array( $group['location_rules'] ) ? $group['location_rules'] : array(),
			'order_no'              => isset( $group['order_no'] ) ? (int) $group['order_no'] : 0,
			// REST exposure is opt-in: only an export that explicitly carried it turns it on.
			'show_in_rest'          => ! empty( $group['show_in_rest'] ),
		);

		// Update-in-place when the export's key already belongs to a definition, so re-importing the same file is
		// idempotent instead of creating a second group on the same key.
		$post_id = 0;
		if ( '' !== $meta['key'] && class_exists( 'Uich_Fields_Admin_Logic' ) ) {
			$existing = Uich_Fields_Admin_Logic::find_definition_id_by_key( 'group_fields', sanitize_key( $meta['key'] ) );
			if ( $existing ) {
				$post_id = (int) $existing;
			}
		}

		// The same write path as the dashboard and the JSON import: sanitisation, the Free/Pro tier
		// checks, the rename lock, key finalisation, the file write and the cache/hook announcements.
		$saved = Uich_Fields_Admin_Logic::save_field_group( $post_id, $meta );
		if ( is_wp_error( $saved ) ) {
			$errors[] = $saved->get_error_message();
			continue;
		}
		$saved = isset( $saved['id'] ) ? (int) $saved['id'] : 0;
		$ids[] = (int) $saved;
	}
	return array(
		'success' => count( $errors ) === 0,
		'ids'     => $ids,
		'errors'  => $errors,
	);
}

// --- 10. Page-Cache Purge Integration ---

/**
 * Purge a single post's page cache after one of its field values changes.
 *
 * @param int $post_id Post ID.
 */
function uich_purge_page_cache_for_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $post_id );
	}
	if ( has_action( 'litespeed_purge_post' ) ) {
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook name.
	}
}

/**
 * Purge the whole page cache after a CPT/taxonomy definition changes.
 * A structural change affects archives, single views, rewrite rules and REST responses site-wide.
 *
 * @param int    $post_id Definition post ID (unused; part of the action signature).
 * @param string $kind    'cpt', 'taxonomy', or 'group_fields'.
 */
function uich_purge_page_cache_all( $post_id = 0, $kind = '' ) {
	// A group SAVE changes no output; deleting or restoring one makes its values unreadable or readable
	// again, so those purge.
	if ( 'group_fields' === $kind && 'uich/definition_saved' === current_action() ) {
		return;
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook name.
	}
}

add_action( 'uich/definition_saved', 'uich_purge_page_cache_all', 10, 2 );
add_action( 'uich/definition_deleted', 'uich_purge_page_cache_all', 10, 2 );
add_action( 'uich/definition_deleted_permanently', 'uich_purge_page_cache_all', 10, 2 );
add_action( 'uich/definition_restored', 'uich_purge_page_cache_all', 10, 2 );

// --- 11. Multilingual (Polylang) String Registration ---

/**
 * Register a definition's translatable strings with Polylang after it's saved.
 *
 * @param int    $post_id Definition post ID (unused, part of the action signature).
 * @param string $kind    'cpt', 'taxonomy', or 'group_fields'.
 * @param array  $meta    Sanitized definition meta.
 */
function uich_ct_register_polylang_strings( $post_id, $kind, $meta ) {
	if ( ! function_exists( 'pll_register_string' ) || ! is_array( $meta ) ) {
		return;
	}
	$group      = 'Uichemy Content Fields';
	$key_prefix = 'uich_ct_' . $kind . '_' . ( isset( $meta['post_type_key'] ) ? $meta['post_type_key'] : ( isset( $meta['taxonomy_key'] ) ? $meta['taxonomy_key'] : ( isset( $meta['key'] ) ? $meta['key'] : $post_id ) ) );

	foreach ( array( 'label', 'singular_label', 'title', 'description' ) as $field ) {
		if ( ! empty( $meta[ $field ] ) && is_string( $meta[ $field ] ) ) {
			pll_register_string( $key_prefix . '_' . $field, $meta[ $field ], $group );
		}
	}
	if ( ! empty( $meta['labels'] ) && is_array( $meta['labels'] ) ) {
		foreach ( $meta['labels'] as $label_key => $label_value ) {
			if ( is_string( $label_value ) && '' !== $label_value ) {
				pll_register_string( $key_prefix . '_label_' . $label_key, $label_value, $group );
			}
		}
	}
}
add_action( 'uich/definition_saved', 'uich_ct_register_polylang_strings', 10, 3 );

// --- 12. Core meta registration (REST API / block editor / Block Bindings) ---

/**
 * Build the `show_in_rest` value for a meta field that stores a list.
 *
 * @param string $items_type REST type of a single item ('string' | 'integer').
 * @return array{schema:array{type:string, items:array{type:string}}}
 */
function uich_rest_array_schema( $items_type ) {
	return array(
		'schema' => array(
			'type'  => 'array',
			'items' => array( 'type' => $items_type ),
		),
	);
}

/**
 * Resolve the register_meta() shape for a field: value type, REST exposure, and which sanitizer to apply.
 *
 * @param array $field Field config.
 * @return array{type:string, show_in_rest:(bool|array), sanitize:string}
 */
function uich_field_meta_rest_config( $field ) {
	$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';
	$multiple = ! empty( $field['multiple'] );

	// Object / nested shapes REST can't model.
	$opaque = array(
		'type'         => 'string',
		'show_in_rest' => false,
		'sanitize'     => 'raw',
	);

	// Read the per-type return-format key each field actually stores under. These are NOT a shared
	// `return_format` key — reading the wrong one mis-detects the stored shape.
	$fmt = static function ( $key, $default ) use ( $field ) {
		return isset( $field[ $key ] ) && '' !== $field[ $key ]
			? sanitize_key( (string) $field[ $key ] )
			: $default;
	};

	switch ( $type ) {
		case 'number':
		case 'range':
			return array(
				'type'         => 'number',
				'show_in_rest' => true,
				'sanitize'     => 'number',
			);
		case 'true_false':
			return array(
				'type'         => 'boolean',
				'show_in_rest' => true,
				'sanitize'     => 'bool',
			);
		case 'url':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'url',
			);
		case 'email':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'email',
			);
		case 'wysiwyg':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'wysiwyg',
			);
		case 'textarea':
			// Must not fall through to 'text': sanitize_text_field() collapses newlines, losing a textarea's line breaks (MB-11).
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'textarea',
			);
		case 'password':
			// VULN-001 follow-up: the type is no longer offered for new fields, but legacy values were still exposed
			// to anonymous REST readers in cleartext because this fell through to the default branch.
			return array(
				'type'         => 'string',
				'show_in_rest' => false,
				'sanitize'     => 'text',
			);
		case 'image':
		case 'file':
			return array(
				'type'         => 'integer',
				'show_in_rest' => true,
				'sanitize'     => 'integer',
			);
		case 'gallery':
			return array(
				'type'         => 'array',
				'show_in_rest' => uich_rest_array_schema( 'integer' ),
				'sanitize'     => 'array_int',
			);
		case 'checkbox':
			return array(
				'type'         => 'array',
				'show_in_rest' => uich_rest_array_schema( 'string' ),
				'sanitize'     => 'array_text',
				'choices'      => true,
			);
		case 'radio':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
				'choices'      => true,
			);
		case 'select':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => uich_rest_array_schema( 'string' ),
					'sanitize'     => 'array_text',
					'choices'      => true,
				)
				: array(
					'type'         => 'string',
					'show_in_rest' => true,
					'sanitize'     => 'text',
					'choices'      => true,
				);
		case 'page_link':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => uich_rest_array_schema( 'string' ),
					'sanitize'     => 'array_url',
				)
				: array(
					'type'         => 'string',
					'show_in_rest' => true,
					'sanitize'     => 'url',
				);
		// Relational fields store IDs whatever their return format (that setting governs reads only),
		// so the REST schema is always the ID shape - an opaque schema would let a REST write store
		// a post/term/user snapshot the module never reads back as an ID.
		case 'user':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => uich_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'relationship':
			return array(
				'type'         => 'array',
				'show_in_rest' => uich_rest_array_schema( 'integer' ),
				'sanitize'     => 'array_int',
			);
		case 'post_object':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => uich_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'taxonomy':
			// Multiplicity comes from taxonomy_appearance (checkbox is the default), not `field_type` — which no
			// code path ever writes.
			$tax_multi = in_array( $fmt( 'taxonomy_appearance', 'checkbox' ), array( 'checkbox', 'multi_select' ), true );
			return $tax_multi
				? array(
					'type'         => 'array',
					'show_in_rest' => uich_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'color':
			// rgba_array stores {r,g,b,a} with int/float members; the generic text sanitizer would
			// stringify each one. Hex / rgba-string formats are plain scalars.
			if ( 'rgba_array' === $fmt( 'color_return_format', 'hex' ) ) {
				return $opaque;
			}
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
			);
		case 'link':
		case 'repeater':
		case 'flexible_content':
			return $opaque;
		default:
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
			);
	}
}

/**
 * Declared choice VALUES for a select / checkbox / radio field, parsing the same array-or- "value :
 * label"-lines shape the renderer accepts.
 *
 * @param array $field Field config.
 * @return string[] Declared choice values as strings.
 */
function uich_field_choice_keys( $field ) {
	$choices = ( is_array( $field ) && isset( $field['choices'] ) ) ? $field['choices'] : array();

	if ( is_string( $choices ) ) {
		$parsed = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
				$parsed[ trim( $m[1] ) ] = trim( $m[2] );
			} else {
				$parsed[ $line ] = $line;
			}
		}
		$choices = $parsed;
	}
	if ( ! is_array( $choices ) ) {
		return array();
	}

	// Indexed list of { value, label } objects (a REST client / JS serialization shape).
	$keys = array_keys( $choices );
	if ( array_keys( $keys ) === $keys ) {
		$first = reset( $choices );
		// Indexed list of scalars: array_keys() would make the allowlist "0","1". The scalar is the value.
		if ( ! is_array( $first ) && null !== $first ) {
			$out = array();
			foreach ( $choices as $choice ) {
				if ( is_scalar( $choice ) ) {
					$out[] = (string) $choice;
				}
			}
			return $out;
		}
		if ( is_array( $first ) && array_key_exists( 'value', $first ) ) {
			$out = array();
			foreach ( $choices as $choice ) {
				if ( is_array( $choice ) && array_key_exists( 'value', $choice ) ) {
					$out[] = (string) $choice['value'];
				}
			}
			return $out;
		}
	}

	return array_map( 'strval', array_keys( $choices ) );
}

/**
 * Sanitize a group-field meta value written through core (e.g.
 *
 * @param mixed  $value    Incoming value.
 * @param string $sanitize Sanitize kind: url|array_url|email|wysiwyg|number|integer|bool|array_int|array_text|raw|text.
 * @param array  $field    Optional field config. Supplied for choice-backed types so a REST write is
 *                         held to the same declared-choices allowlist the admin save path enforces.
 * @return mixed
 */
function uich_sanitize_registered_field_meta_value( $value, $sanitize, $field = array() ) {
	// Choice-backed types (select / radio / checkbox): keep only values the field actually declares.
	if ( is_array( $field ) && ! empty( $field['type'] )
		&& in_array( (string) $field['type'], array( 'select', 'radio', 'checkbox' ), true )
	) {
		$valid = uich_field_choice_keys( $field );
		if ( ! empty( $valid ) ) {
			if ( is_array( $value ) ) {
				$out = array();
				foreach ( $value as $v ) {
					if ( is_scalar( $v ) && in_array( (string) $v, $valid, true ) ) {
						$out[] = (string) $v;
					}
				}
				return array_values( array_unique( $out ) );
			}
			$v = is_scalar( $value ) ? (string) $value : '';
			return in_array( $v, $valid, true ) ? $v : '';
		}
	}

	switch ( (string) $sanitize ) {
		case 'array_url':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $v ) ) ) );
		case 'url':
			return is_array( $value )
				? array_map( 'esc_url_raw', array_map( 'strval', $value ) )
				: esc_url_raw( (string) $value );
		case 'email':
			$e = sanitize_email( (string) $value );
			return is_email( $e ) ? $e : '';
		case 'wysiwyg':
			return wp_kses_post( (string) $value );
		case 'textarea':
			return sanitize_textarea_field( (string) $value );
		case 'number':
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			// Keep an integer literal too large for PHP_INT_MAX as digits; `+ 0` would promote it to a
			// float and store it in scientific notation, so the stored value stops matching the input.
			if ( is_string( $value ) && preg_match( '/^[+-]?\d+$/', $value ) && ! is_int( $value + 0 ) ) {
				return $value;
			}
			return $value + 0;
		case 'integer':
			return absint( $value );
		case 'bool':
			// 'false' arrives from form posts and JSON round trips, and PHP treats a non-empty string as truthy.
			return ( $value && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		case 'array_int':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_values( array_filter( array_map( 'absint', $v ) ) );
		case 'array_text':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_map( 'sanitize_text_field', array_map( 'strval', $v ) );
		case 'raw':
			return $value; // Complex/nested shapes — not exposed to REST; left to the plugin's own save path.
		case 'text':
		default:
			return is_array( $value )
				? array_map( 'sanitize_text_field', array_map( 'strval', $value ) )
				: sanitize_text_field( (string) $value );
	}
}

/**
 * Resolve which meta object types a field group targets, from its location rules.
 *
 * @param mixed $location_rules Stored location_rules (flat or nested groups of {param,operator,value}).
 * @return string[] Subset of: post, user, term, comment.
 */
function uich_group_meta_object_types( $location_rules ) {
	$types = array();
	if ( is_array( $location_rules ) && ! empty( $location_rules ) ) {
		$nested = isset( $location_rules[0] ) && is_array( $location_rules[0] ) && ! isset( $location_rules[0]['param'] );
		$groups = $nested ? $location_rules : array( $location_rules );
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
					continue;
				}
				$p = sanitize_key( $rule['param'] );
				if ( in_array( $p, array( 'user_role', 'current_user', 'user_form' ), true ) ) {
					$types['user'] = true;
				} elseif ( 'taxonomy' === $p ) {
					$types['term'] = true;
				} elseif ( 'comment' === $p ) {
					$types['comment'] = true;
				} elseif ( in_array( $p, array( 'widget', 'nav_menu', 'nav_menu_item' ), true ) ) {
					continue; // No object meta table for these.
				} else {
					$types['post'] = true; // post_type/post/page/status/format/template/etc. + ambiguous.
				}
			}
		}
	}
	if ( empty( $types ) ) {
		$types['post'] = true; // No (or non-meta) location → default to post.
	}
	return array_keys( $types );
}

/**
 * Register every top-level field's value meta with core for REST, the block editor and Block Bindings.
 * Done per object type the field's group actually targets.
 *
 * @return void
 */
function uich_register_group_field_meta() {
	if ( ! function_exists( 'uich_group_definitions' ) || ! function_exists( 'uich_build_meta_key' ) ) {
		return;
	}
	$groups = uich_group_definitions( array() );
	if ( empty( $groups ) || ! is_array( $groups ) ) {
		return;
	}

	// REST write gate per object type — the correct edit_* meta-cap for each.
	$auth_caps = array(
		'post'    => 'edit_post',
		'user'    => 'edit_user',
		'term'    => 'edit_term',
		'comment' => 'edit_comment',
	);

	$seen = array();
	foreach ( $groups as $group ) {
		if ( ! is_array( $group ) || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			continue;
		}
		$group_key = isset( $group['key'] ) ? (string) $group['key'] : '';
		if ( '' === $group_key ) {
			continue;
		}
		// Group-level "Show in REST" is opt-in: values on a published post (and /wp/v2/users) are
		// readable by anonymous visitors once exposed, so nothing is exposed unless the group says so.
		$group_in_rest = array_key_exists( 'show_in_rest', $group )
			&& filter_var( $group['show_in_rest'], FILTER_VALIDATE_BOOLEAN );

		$object_types = uich_group_meta_object_types( isset( $group['location_rules'] ) ? $group['location_rules'] : array() );

		foreach ( $group['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			// A Pro type on a Free build reads as empty everywhere else (uich_field_value()); registering
			// its meta would hand REST and the block editor a read/write door past that lock.
			if ( function_exists( 'uich_fields_type_allowed' ) && ! uich_fields_type_allowed( isset( $field['type'] ) ? $field['type'] : '' ) ) {
				continue;
			}
			$cfg = uich_field_meta_rest_config( $field );

			// VULN-002 hardening: honor a per-field opt-out.
			if ( array_key_exists( 'show_in_rest', $field ) && ! filter_var( $field['show_in_rest'], FILTER_VALIDATE_BOOLEAN ) ) {
				$cfg['show_in_rest'] = false;
			}

			// The group-level opt-out wins over any per-field default.
			if ( ! $group_in_rest ) {
				$cfg['show_in_rest'] = false;
			}

			foreach ( $object_types as $object_type ) {
				$cap      = isset( $auth_caps[ $object_type ] ) ? $auth_caps[ $object_type ] : 'edit_post';
				$meta_key = uich_build_meta_key( $object_type, $group_key, $field['name'] );
				$dedupe   = $object_type . '|' . $meta_key;
				if ( '' === $meta_key || isset( $seen[ $dedupe ] ) ) {
					continue; // Dedupe: same key on same object type registered once.
				}
				$seen[ $dedupe ] = true;

				/**
				 * Whether to register this field's value meta with core. Return false to keep the
				 * field out of the REST API / block editor entirely.
				 *
				 * @param bool   $register    Default true.
				 * @param array  $field       Field config.
				 * @param string $meta_key    The meta key.
				 * @param string $object_type post | user | term | comment.
				 */
				if ( ! apply_filters( 'uich/register_field_meta', true, $field, $meta_key, $object_type ) ) {
					continue;
				}

				$sanitize     = $cfg['sanitize'];
				$field_config = $field;
				$in_rest      = ! empty( $cfg['show_in_rest'] );
				$args         = array(
					'single'            => true,
					'type'              => $cfg['type'],
					'description'       => isset( $field['label'] ) ? (string) $field['label'] : '',
					// Shape-aware: bool exposes as a plain type; array types carry an item schema;
					// object/nested shapes are registered with false (sanitized but not REST-exposed).
					'show_in_rest'      => $cfg['show_in_rest'],
					'sanitize_callback' => function ( $value ) use ( $sanitize, $field_config ) {
						return uich_sanitize_registered_field_meta_value( $value, $sanitize, $field_config );
					},
					'auth_callback'     => function ( $allowed, $meta_key, $object_id ) use ( $cap, $in_rest ) {
						// Only a REST-exposed field may be written through core's generic meta APIs
						// (REST, XML-RPC custom fields, the Custom Fields box). The others are
						// protected `_uichemy_gf_*` keys whose values only this module's own save path
						// sanitizes; opaque types (repeater, link, flexible content, rgba) would
						// otherwise be stored raw, past type sanitizing, location rules and Pro gating.
						if ( ! $in_rest ) {
							return false;
						}
						return current_user_can( $cap, (int) $object_id );
					},
				);

				/**
				 * Filter the register_meta() args for a group field before registration (e.g. to
				 * change show_in_rest, type, or the auth/sanitize callbacks).
				 *
				 * @param array  $args        register_meta() args.
				 * @param array  $field       Field config.
				 * @param string $meta_key    The meta key.
				 * @param string $object_type post | user | term | comment.
				 */
				$args = apply_filters( 'uich/register_field_meta_args', $args, $field, $meta_key, $object_type );

				register_meta( $object_type, $meta_key, $args );
			}
		}
	}
}
add_action( 'init', 'uich_register_group_field_meta', 20 );

if ( ! function_exists( 'uich_format_wysiwyg' ) ) {
	/**
	 * Format a WYSIWYG value the way the_content would, without running the_content.
	 *
	 * Page builders hook `the_content` to replace whatever passes through it with the CURRENT
	 * POST's builder document: on a single template a WYSIWYG field printed the whole page
	 * (Elementor's document for visitors; for a logged-in editor, the front-end editor's inline
	 * <style>/<script> as visible text once |kses_post had stripped the tags). A field is not the
	 * post's content, so it gets its own filter carrying only WordPress's text formatters - the
	 * same separation ACF makes with `acf_the_content`.
	 *
	 * @param string $value Stored HTML.
	 * @return string
	 */
	function uich_format_wysiwyg( $value ) {
		/**
		 * Filters a WYSIWYG field value at render. Add formatters here, never to `the_content`.
		 *
		 * @param string $value Field HTML.
		 */
		return (string) apply_filters( 'uich/the_content', (string) $value );
	}

	foreach ( array(
		'wptexturize'        => 10,
		'convert_smilies'    => 20,
		'wpautop'            => 10,
		'shortcode_unautop'  => 10,
		'do_shortcode'       => 11,
		'convert_chars'      => 10,
	) as $uich_formatter => $uich_priority ) {
		if ( function_exists( $uich_formatter ) ) {
			add_filter( 'uich/the_content', $uich_formatter, $uich_priority );
		}
	}
	if ( function_exists( 'wp_filter_content_tags' ) ) {
		// srcset / sizes / lazy-loading on images inside the field, as the_content would add.
		add_filter( 'uich/the_content', 'wp_filter_content_tags', 12 );
	}
	unset( $uich_formatter, $uich_priority );
}

