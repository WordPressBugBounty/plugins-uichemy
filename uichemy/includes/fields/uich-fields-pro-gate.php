<?php
/**
 * The Free/Pro tier map for Content Fields — the ONE place a field type's tier is written down.
 *
 * Every other layer (the picker, REST save, the metabox, the value read/write API and the abilities)
 * asks this file rather than carrying its own list. A tier spelled out in two places drifts, and a
 * type that is Pro in the UI but Free at the REST layer is not gated at all.
 *
 * Mirrors uichemy-composer/includes/uichemy-pro-gate.php, including its two load-bearing rules:
 *   1. Allowlist, not denylist — a type not named in uich_fields_free_types() is Pro, so a new type
 *      has to be tiered deliberately rather than leaking into Free by being forgotten.
 *   2. The LISTS are filterable so a bundled build can retier; the VERDICTS are not, so there is no
 *      second door past the licence.
 *
 * @package Uichemy
 * @subpackage Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uich_fields_is_pro' ) ) {
	/**
	 * Whether this build may run Pro field types.
	 *
	 * Always routed through uichemy_is_pro(): a merged/bundled build has no separate Pro plugin and
	 * decides Pro by licence tier through the `uich_composer_is_pro` filter, so reading
	 * `defined( 'UICHEMY_PRO' )` here directly would read a licensed site as Free.
	 *
	 * The fallback is the expression uichemy_is_pro() itself uses, for the narrow window before the
	 * composer loader has declared it — this file is reachable from the plugin's load-time require.
	 * Defaulting to false there would refuse Pro types to a paying site.
	 *
	 * @return bool
	 */
	function uich_fields_is_pro() {
		if ( function_exists( 'uichemy_is_pro' ) ) {
			return (bool) uichemy_is_pro();
		}
		return defined( 'UICHEMY_PRO' ) && UICHEMY_PRO;
	}
}

if ( ! function_exists( 'uich_fields_free_types' ) ) {
	/**
	 * Field types available in Free.
	 *
	 * Deliberately a literal list rather than "the catalog minus the Pro types": this is the
	 * allowlist, so a type added to the catalog later is Pro until someone adds it here on purpose.
	 *
	 * @return string[] Type slugs.
	 */
	function uich_fields_free_types() {
		$free = array(
			// Basic.
			'text',
			'textarea',
			'number',
			'range',
			'email',
			'url',
			// Content.
			'image',
			'file',
			'wysiwyg',
			// Choice.
			'select',
			'checkbox',
			'radio',
			'true_false',
			// Relational, minus `relationship`.
			'link',
			'post_object',
			'page_link',
			'taxonomy',
			'user',
			// Advanced.
			'date',
			'time',
			'datetime',
			'color',
		);

		/**
		 * Filter the field types available without Pro.
		 *
		 * @param string[] $free Free type slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'uich_fields_free_types', $free ) ) );
	}
}

if ( ! function_exists( 'uich_fields_field_type_catalog' ) ) {
	/**
	 * Every field type the picker offers, grouped as the picker shows them: group => [ slug => label ].
	 *
	 * Lives here, not in the abilities layer, because the tier verdicts below are consulted on every
	 * save and front-end read, while the abilities layer registers lazily and is absent on most
	 * requests. The abilities catalog should read this one rather than keep a copy.
	 *
	 * @return array<string, array<string, string>>
	 */
	function uich_fields_field_type_catalog() {
		return array(
			'Basic'      => array(
				'text'     => 'Text',
				'textarea' => 'Textarea',
				'number'   => 'Number',
				'range'    => 'Range',
				'email'    => 'Email',
				'url'      => 'URL',
			),
			'Content'    => array(
				'image'   => 'Image',
				'file'    => 'File',
				'wysiwyg' => 'WYSIWYG Editor',
			),
			'Choice'     => array(
				'select'     => 'Select',
				'checkbox'   => 'Checkbox',
				'radio'      => 'Radio Button',
				'true_false' => 'True / False',
			),
			'Relational' => array(
				'link'         => 'Link',
				'post_object'  => 'Post Object',
				'page_link'    => 'Page Link',
				'relationship' => 'Relationship',
				'taxonomy'     => 'Taxonomy',
				'user'         => 'User',
			),
			'Advanced'   => array(
				'date'     => 'Date Picker',
				'time'     => 'Time Picker',
				'datetime' => 'Date Time Picker',
				'color'    => 'Color Picker',
			),
			'Layout'     => array(
				'repeater' => 'Repeater',
			),
		);
	}
}

if ( ! function_exists( 'uich_fields_creatable_field_types' ) ) {
	/**
	 * Flat list of the field type slugs the picker offers.
	 *
	 * @return string[]
	 */
	function uich_fields_creatable_field_types() {
		$flat = array();
		foreach ( uich_fields_field_type_catalog() as $types ) {
			foreach ( array_keys( $types ) as $slug ) {
				$flat[] = $slug;
			}
		}
		return $flat;
	}
}

if ( ! function_exists( 'uich_fields_pro_types' ) ) {
	/**
	 * Creatable field types that require Pro — the catalog minus the Free allowlist.
	 *
	 * Derived rather than listed so the two can never disagree, and so a type added to the catalog
	 * is tiered without a second edit. Withdrawn types the picker no longer offers (`gallery`) are
	 * not listed here because they cannot be created; uich_fields_type_is_pro() still locks them.
	 *
	 * @return string[] Type slugs.
	 */
	function uich_fields_pro_types() {
		// Subtracted, not hard-coded, so the `uich_fields_free_types` filter retiers both branches.
		return array_values( array_diff( uich_fields_creatable_field_types(), uich_fields_free_types() ) );
	}
}

if ( ! function_exists( 'uich_fields_type_allowed' ) ) {
	/**
	 * Whether this build may CREATE or EDIT a field of this type.
	 *
	 * The withdrawn legacy `password` type answers true: it is not a Pro feature but a type the
	 * picker no longer offers, and refusing it here would make an old group holding one uneditable
	 * rather than unsellable. `gallery` is a Pro type (as in ACF Pro), so it is locked on Free.
	 *
	 * Not filterable — see the file header.
	 *
	 * @param string $type Field type slug.
	 * @return bool
	 */
	function uich_fields_type_allowed( $type ) {
		return uich_fields_is_pro() || ! uich_fields_type_is_pro( $type );
	}
}

if ( ! function_exists( 'uich_fields_type_is_pro' ) ) {
	/**
	 * Whether this type is a Pro type AT ALL — independent of the current licence.
	 *
	 * uich_fields_type_allowed() answers "may this build use it", which is true for everything on a
	 * Pro site; the picker needs the other question so it can badge a locked type on Free and still
	 * mark it "Pro" on a licensed one.
	 *
	 * Answered from the Free allowlist (plus the free legacy types) alone: anything not named there
	 * is Pro, including withdrawn types such as `gallery` that the creatable catalog no longer lists.
	 *
	 * @param string $type Field type slug.
	 * @return bool
	 */
	function uich_fields_type_is_pro( $type ) {
		$type = (string) $type;

		// A field with no type renders as `text`; treat it the same way rather than locking it.
		if ( '' === $type ) {
			return false;
		}

		if ( in_array( $type, uich_fields_free_types(), true ) ) {
			return false;
		}

		// The withdrawn legacy `password` type is not a Pro feature — the picker just no longer offers
		// it. Locking it would make an old group holding one uneditable rather than unsellable.
		if ( in_array( $type, uich_fields_legacy_types(), true ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'uich_fields_legacy_types' ) ) {
	/**
	 * Withdrawn types the renderer still understands, the picker no longer offers, and Free may
	 * still use. `gallery` is deliberately NOT here: it is a Pro type.
	 *
	 * @return string[] Type slugs.
	 */
	function uich_fields_legacy_types() {
		return array( 'password' );
	}
}

if ( ! function_exists( 'uich_fields_pro_type_labels' ) ) {
	/**
	 * Human labels for the Pro types, for error messages and the upgrade UI.
	 *
	 * @return array<string, string> slug => label.
	 */
	function uich_fields_pro_type_labels() {
		$labels = array( 'gallery' => 'Gallery' );
		foreach ( uich_fields_field_type_catalog() as $group ) {
			$labels = array_merge( $labels, $group );
		}
		$out = array();
		foreach ( uich_fields_pro_types() as $slug ) {
			$out[ $slug ] = isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
		}
		$out['gallery'] = $labels['gallery'];
		return $out;
	}
}

if ( ! function_exists( 'uich_fields_free_location_params' ) ) {
	/**
	 * Location rule parameters available in Free.
	 *
	 * Chosen to cover the four object types a field group can attach to — posts, terms, users and
	 * comments — plus templates. Everything Pro adds is finer TARGETING within those, so Free is
	 * never in a position where a group simply cannot be attached to a kind of object at all.
	 *
	 * Allowlist, same as the types: a param added to the matcher later is Pro until named here.
	 *
	 * @return string[] Param slugs.
	 */
	function uich_fields_free_location_params() {
		$free = array(
			'post_type',
			'taxonomy',
			'user_form',
			'comment',
			'page_template',
		);

		/**
		 * Filter the location rule parameters available without Pro.
		 *
		 * @param string[] $free Free param slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'uich_fields_free_location_params', $free ) ) );
	}
}

if ( ! function_exists( 'uich_fields_all_location_params' ) ) {
	/**
	 * Every location rule parameter the matcher understands.
	 *
	 * Listed here rather than read from the ability schema because the matcher runs on every admin
	 * screen and front-end request, long before anything reads the ability registry.
	 *
	 * @return string[] Param slugs.
	 */
	function uich_fields_all_location_params() {
		return array(
			'post_type',
			'post',
			'page',
			'post_template',
			'page_template',
			'post_status',
			'post_format',
			'post_category',
			'post_taxonomy',
			'taxonomy',
			'page_type',
			'page_parent',
			'user_role',
			'current_user_role',
			'current_user',
			'user_form',
			'attachment',
			'comment',
			'widget',
			'nav_menu',
			'nav_menu_item',
		);
	}
}

if ( ! function_exists( 'uich_fields_pro_location_params' ) ) {
	/**
	 * Location rule parameters that require Pro — the full set minus the Free allowlist.
	 *
	 * @return string[] Param slugs, in matcher order.
	 */
	function uich_fields_pro_location_params() {
		return array_values( array_diff( uich_fields_all_location_params(), uich_fields_free_location_params() ) );
	}
}

if ( ! function_exists( 'uich_fields_location_param_is_pro' ) ) {
	/**
	 * Whether a location param is a Pro param at all — true on Pro builds too, for the badge.
	 *
	 * An UNKNOWN param answers false, not true: the matcher's `default` already treats one as
	 * non-matching, and reporting it as Pro would offer an upgrade that does not add it.
	 *
	 * @param string $param Param slug.
	 * @return bool
	 */
	function uich_fields_location_param_is_pro( $param ) {
		$param = (string) $param;
		if ( '' === $param || in_array( $param, uich_fields_free_location_params(), true ) ) {
			return false;
		}
		return in_array( $param, uich_fields_all_location_params(), true );
	}
}

if ( ! function_exists( 'uich_fields_location_param_allowed' ) ) {
	/**
	 * Whether this build may use a location param.
	 *
	 * Not filterable — see the file header.
	 *
	 * @param string $param Param slug.
	 * @return bool
	 */
	function uich_fields_location_param_allowed( $param ) {
		return uich_fields_is_pro() || ! uich_fields_location_param_is_pro( $param );
	}
}

if ( ! function_exists( 'uich_fields_pro_location_params_in_rules' ) ) {
	/**
	 * Every Pro location param used by a rules structure, as param => true.
	 *
	 * Location rules legitimately come in TWO shapes — a flat list of rules, and a list of OR-groups
	 * of rules — so both are walked rather than assuming the nested one.
	 *
	 * @param mixed $rules Location rules value.
	 * @return array<string, bool> Pro params found.
	 */
	function uich_fields_pro_location_params_in_rules( $rules ) {
		$found = array();
		if ( ! is_array( $rules ) ) {
			return $found;
		}

		foreach ( $rules as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// A rule has a `param`; anything else at this level is an OR-group of rules.
			$group = isset( $entry['param'] ) ? array( $entry ) : $entry;
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['param'] ) ) {
					continue;
				}
				$param = (string) $rule['param'];
				if ( uich_fields_location_param_is_pro( $param ) ) {
					$found[ $param ] = true;
				}
			}
		}

		return $found;
	}
}

if ( ! function_exists( 'uich_fields_conditional_logic_allowed' ) ) {
	/**
	 * Whether this build may use per-field conditional logic.
	 *
	 * The WHOLE feature is Pro, not a subset of its operators — so there is one verdict rather than
	 * a per-operator allowlist, and `uich_fields_conditional_logic_in_tree()` is what finds it.
	 *
	 * @return bool
	 */
	function uich_fields_conditional_logic_allowed() {
		return uich_fields_is_pro();
	}
}

if ( ! function_exists( 'uich_fields_field_has_conditional_logic' ) ) {
	/**
	 * Whether one field config actually carries conditional logic.
	 *
	 * BOTH the enabled flag and a non-empty rule set are required, matching every consumer in the
	 * module: a field left with `conditional_logic_enabled` toggled on but no rules behaves exactly
	 * like one with the feature off, so refusing to save it would block a harmless payload.
	 *
	 * @param mixed $field Field config.
	 * @return bool
	 */
	function uich_fields_field_has_conditional_logic( $field ) {
		return is_array( $field )
			&& ! empty( $field['conditional_logic_enabled'] )
			&& ! empty( $field['conditional_logic'] );
	}
}

if ( ! function_exists( 'uich_fields_conditional_logic_in_tree' ) ) {
	/**
	 * Names of every field in a tree that carries conditional logic, by dotted path.
	 *
	 * Recurses into `fields` so a repeater's subfields are covered — they can carry their own rules.
	 *
	 * @param mixed  $fields Field list.
	 * @param string $prefix Dotted path of the parent, '' at the top level.
	 * @return string[] Paths.
	 */
	function uich_fields_conditional_logic_in_tree( $fields, $prefix = '' ) {
		$found = array();
		if ( ! is_array( $fields ) ) {
			return $found;
		}

		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = '' !== (string) ( isset( $field['name'] ) ? $field['name'] : '' )
				? (string) $field['name']
				: (string) $i;
			$path = '' === $prefix ? $name : $prefix . '.' . $name;

			if ( uich_fields_field_has_conditional_logic( $field ) ) {
				$found[] = $path;
			}

			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$found = array_merge( $found, uich_fields_conditional_logic_in_tree( $field['fields'], $path ) );
			}
		}

		return $found;
	}
}

if ( ! function_exists( 'uich_fields_upgrade_url' ) ) {
	/**
	 * The upgrade URL behind every Content Fields lock, tagged so we can tell which CTA converted.
	 *
	 * @param string $source CTA identifier.
	 * @return string
	 */
	function uich_fields_upgrade_url( $source = 'content-fields' ) {
		if ( function_exists( 'uichemy_upgrade_url' ) ) {
			return uichemy_upgrade_url( $source );
		}
		return 'https://uichemy.com/pricing/';
	}
}

if ( ! function_exists( 'uich_fields_pro_types_in_tree' ) ) {
	/**
	 * Every Pro-type field in a field tree, keyed by its dotted path.
	 *
	 * Recurses into `fields` — a repeater nests its subfields there, not under `sub_fields` — so a
	 * Pro type inside a repeater row is found too.
	 *
	 * @param mixed  $fields Field list.
	 * @param string $prefix Dotted path of the parent, '' at the top level.
	 * @return array<string, string> path => type slug.
	 */
	function uich_fields_pro_types_in_tree( $fields, $prefix = '' ) {
		$found = array();
		if ( ! is_array( $fields ) ) {
			return $found;
		}

		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = '' !== (string) ( isset( $field['name'] ) ? $field['name'] : '' )
				? (string) $field['name']
				: (string) $i;
			$path = '' === $prefix ? $name : $prefix . '.' . $name;
			$type = (string) ( isset( $field['type'] ) ? $field['type'] : '' );

			if ( '' !== $type && uich_fields_type_is_pro( $type ) ) {
				$found[ $path ] = $type;
			}

			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$found = array_merge( $found, uich_fields_pro_types_in_tree( $field['fields'], $path ) );
			}
		}

		return $found;
	}
}

if ( ! function_exists( 'uich_fields_assert_field_types_allowed' ) ) {
	/**
	 * Refuse Pro field types this build may not use.
	 *
	 * This is the real lock, not the picker: hiding a type in React is bypassed by anyone calling a
	 * write path directly, so the tier has to be enforced where the definition is written.
	 *
	 * `$stored` is what makes a downgrade survivable. A group that already holds a repeater must
	 * stay editable in Free, so a Pro type ALREADY stored at that path is allowed through and only a
	 * newly added one is refused.
	 *
	 * @param array $fields Submitted field tree.
	 * @param array $stored Field tree currently stored, empty on create.
	 * @return true|WP_Error
	 */
	function uich_fields_assert_field_types_allowed( $fields, $stored = array() ) {
		if ( uich_fields_is_pro() ) {
			return true;
		}

		$incoming = uich_fields_pro_types_in_tree( $fields );
		if ( empty( $incoming ) ) {
			return true;
		}

		// Same path AND same type: a path reused for a different Pro type is a new field.
		$existing = uich_fields_pro_types_in_tree( $stored );
		$added    = array();
		foreach ( $incoming as $path => $type ) {
			if ( ! isset( $existing[ $path ] ) || $existing[ $path ] !== $type ) {
				$added[ $path ] = $type;
			}
		}

		if ( empty( $added ) ) {
			return true;
		}

		$labels = uich_fields_pro_type_labels();
		$named  = array();
		foreach ( $added as $path => $type ) {
			$named[] = sprintf( '%s (%s)', $path, isset( $labels[ $type ] ) ? $labels[ $type ] : $type );
		}

		return new WP_Error(
			'uich_fields_pro_field_type',
			sprintf(
				/* translators: 1: comma-separated "field name (Type)" list, 2: upgrade URL. */
				__( 'These field types require UiChemy Pro: %1$s. Upgrade at %2$s', 'uichemy' ),
				implode( ', ', $named ),
				uich_fields_upgrade_url( 'content-fields-save' )
			),
			array(
				'status'      => 403,
				'pro_fields'  => $added,
				'upgrade_url' => uich_fields_upgrade_url( 'content-fields-save' ),
			)
		);
	}
}

if ( ! function_exists( 'uich_fields_assert_conditional_logic_allowed' ) ) {
	/**
	 * Refuse conditional logic on a build that may not use it.
	 *
	 * `$stored` keeps a downgraded site editable: a field that ALREADY carries rules can still be
	 * saved, so the group's other fields stay reachable and the rules are never silently stripped.
	 *
	 * @param array $fields Submitted field tree.
	 * @param array $stored Field tree currently stored, empty on create.
	 * @return true|WP_Error
	 */
	function uich_fields_assert_conditional_logic_allowed( $fields, $stored = array() ) {
		if ( uich_fields_conditional_logic_allowed() ) {
			return true;
		}

		$incoming = uich_fields_conditional_logic_in_tree( $fields );
		if ( empty( $incoming ) ) {
			return true;
		}

		$added = array_values( array_diff( $incoming, uich_fields_conditional_logic_in_tree( $stored ) ) );
		if ( empty( $added ) ) {
			return true;
		}

		return new WP_Error(
			'uich_fields_pro_conditional_logic',
			sprintf(
				/* translators: 1: comma-separated field name list, 2: upgrade URL. */
				__( 'Conditional logic requires UiChemy Pro. Remove it from: %1$s. Upgrade at %2$s', 'uichemy' ),
				implode( ', ', $added ),
				uich_fields_upgrade_url( 'content-fields-conditional' )
			),
			array(
				'status'      => 403,
				'pro_fields'  => $added,
				'upgrade_url' => uich_fields_upgrade_url( 'content-fields-conditional' ),
			)
		);
	}
}

if ( ! function_exists( 'uich_fields_assert_location_params_allowed' ) ) {
	/**
	 * Refuse Pro location rule parameters this build may not use.
	 *
	 * A rule written straight to a write path would otherwise be stored and then silently never
	 * match. `$stored` keeps a downgraded site editable — a group already targeting a Pro param can
	 * still be saved. Keyed by param, not position, so moving a stored rule between OR-groups is a
	 * reorder rather than a new rule.
	 *
	 * @param mixed $rules  Submitted location rules.
	 * @param mixed $stored Location rules currently stored, empty on create.
	 * @return true|WP_Error
	 */
	function uich_fields_assert_location_params_allowed( $rules, $stored = array() ) {
		if ( uich_fields_is_pro() ) {
			return true;
		}

		$incoming = uich_fields_pro_location_params_in_rules( $rules );
		if ( empty( $incoming ) ) {
			return true;
		}

		$added = array_keys( array_diff_key( $incoming, uich_fields_pro_location_params_in_rules( $stored ) ) );
		if ( empty( $added ) ) {
			return true;
		}

		return new WP_Error(
			'uich_fields_pro_location_param',
			sprintf(
				/* translators: 1: comma-separated location parameter list, 2: upgrade URL. */
				__( 'These location rule parameters require UiChemy Pro: %1$s. Upgrade at %2$s', 'uichemy' ),
				implode( ', ', $added ),
				uich_fields_upgrade_url( 'content-fields-location' )
			),
			array(
				'status'      => 403,
				'pro_params'  => $added,
				'upgrade_url' => uich_fields_upgrade_url( 'content-fields-location' ),
			)
		);
	}
}

if ( ! function_exists( 'uich_fields_assert_group_tier' ) ) {
	/**
	 * Every Free/Pro check a field group write must pass: Pro field types, Pro location params and
	 * conditional logic. The ONE function every group write path calls (REST create/update,
	 * Admin_Logic::save_field_group() — and through it the JSON import and uich_import_fields()).
	 *
	 * @param array $meta   Sanitized incoming group definition.
	 * @param array $stored The definition currently stored under this id, empty on create.
	 * @return true|WP_Error
	 */
	function uich_fields_assert_group_tier( $meta, $stored = array() ) {
		$meta   = is_array( $meta ) ? $meta : array();
		$stored = is_array( $stored ) ? $stored : array();

		$checks = array(
			uich_fields_assert_field_types_allowed(
				isset( $meta['fields'] ) ? $meta['fields'] : array(),
				isset( $stored['fields'] ) ? $stored['fields'] : array()
			),
			uich_fields_assert_location_params_allowed(
				isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
				isset( $stored['location_rules'] ) ? $stored['location_rules'] : array()
			),
			uich_fields_assert_conditional_logic_allowed(
				isset( $meta['fields'] ) ? $meta['fields'] : array(),
				isset( $stored['fields'] ) ? $stored['fields'] : array()
			),
		);
		foreach ( $checks as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
}
