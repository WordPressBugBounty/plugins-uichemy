<?php
/**
 * UiChemy Custom Fields — ability lifecycle: exposure policy, read-only mode, write lock, journal,
 * undo and read cache.
 *
 * The safety parts (exposure, read-only refusal, lock, journal, undo) hang off
 * `wp_register_ability_args`, which exists since WordPress 6.9, and are enforced inside the wrapped
 * execute_callback. Only the conveniences (input coercion, read cache, telemetry) need the 7.1 hooks
 * and simply stay idle on older cores.
 *
 * @package Uichemy
 * @since   4.7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uich_fields_register_ability' ) ) {
	/**
	 * Register a UiChemy ability through the house envelope.
	 *
	 * The `wp_register_ability_args` filter below stays in place and remains the backstop, so an
	 * ability registered any other way still gets the policy — this wrapper is not a gate. What it
	 * adds is what a filter cannot: the constants every ability repeated live in one place, and a
	 * missing `meta.mode` is reported at registration instead of falling back to a guess and being
	 * discovered by an audit later.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability definition, minus whatever this fills in.
	 * @return mixed Whatever wp_register_ability() returns.
	 */
	function uich_fields_register_ability( $name, array $args ) {
		if ( ! isset( $args['category'] ) ) {
			$args['category'] = 'uichemy';
		}

		if ( ! isset( $args['permission_callback'] ) ) {
			$args['permission_callback'] = 'uich_fields_mcp_permission_callback';
		}

		// Declared, not derived. The filter can fall back to the annotation and then the name prefix,
		// but that fallback exists so nothing broke the day it landed — it is not a licence to omit.
		if ( ! isset( $args['meta']['mode'] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html( sprintf( '%s does not declare meta.mode; the lifecycle has to guess whether it writes.', (string) $name ) ),
				'4.8.0.8'
			);
		}

		// Recorded before registering: wp_register_ability() runs the lifecycle filter, which asks
		// uich_fields_ability_is_ours() about this very name.
		uich_fields_ability_is_ours( (string) $name, true );

		return wp_register_ability( $name, $args );
	}
}

if ( ! function_exists( 'uich_fields_ability_is_ours' ) ) {
	/**
	 * Whether an ability belongs to Custom Fields.
	 *
	 * Membership, not a name prefix: other abilities (Module Studio's among them) share the
	 * same namespaces, and matching on a prefix would put them under this lifecycle - forced
	 * private, behind the Custom Fields read-only switch, lock and journal.
	 *
	 * @param string $name     Ability name.
	 * @param bool   $register Record the name as ours (uich_fields_register_ability() only).
	 * @return bool
	 */
	function uich_fields_ability_is_ours( $name, $register = false ) {
		static $ours = array();

		if ( ! is_string( $name ) || '' === $name ) {
			return false;
		}
		if ( $register ) {
			$ours[ $name ] = true;
		}
		return isset( $ours[ $name ] );
	}
}

if ( ! function_exists( 'uich_fields_ability_cache_version' ) ) {
	/**
	 * Read-cache generation. Bumped by any write ability so cached reads expire at once.
	 *
	 * @param bool $bump Whether to advance the generation.
	 * @return int
	 */
	function uich_fields_ability_cache_version( $bump = false ) {
		$version = (int) get_option( 'uichemy_fields_ability_cache_version', 1 );

		if ( $bump ) {
			++$version;
			update_option( 'uichemy_fields_ability_cache_version', $version, false );
		}

		return $version;
	}
}

if ( ! function_exists( 'uich_fields_ability_policy' ) ) {
	/**
	 * Declared policy for every UiChemy ability, captured at registration.
	 *
	 * STEP 1. Policy used to be parsed out of the ability NAME, which failed silently both ways: a
	 * read not called get-/list- bumped the cache generation on every call and destroyed every other
	 * read's cache, and a write called get-* was served from cache and appeared to do nothing.
	 *
	 * @param string|null $name Ability name to read, or null to read the whole map.
	 * @param array|null  $set  Internal: policy to store for $name.
	 * @return array
	 */
	function uich_fields_ability_policy( $name = null, $set = null ) {
		static $map = array();

		if ( null !== $name && null !== $set ) {
			$map[ $name ] = $set;
			return $set;
		}
		if ( null === $name ) {
			return $map;
		}
		return isset( $map[ $name ] ) ? $map[ $name ] : array();
	}
}

if ( ! function_exists( 'uich_fields_ability_derive_policy' ) ) {
	/**
	 * Resolve mode + targets_object for one ability, preferring what it declares.
	 *
	 * The two fallbacks exist so nothing changes behaviour on the day this lands: an ability that
	 * declares nothing is classified exactly as the old name contracts classified it.
	 *
	 * @param array  $args Registration args.
	 * @param string $name Ability name.
	 * @return array{mode:string,targets_object:bool,declared:bool}
	 */
	function uich_fields_ability_derive_policy( $args, $name ) {
		$meta     = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
		$declared = isset( $meta['mode'] ) && in_array( $meta['mode'], array( 'read', 'validate', 'write' ), true );

		if ( $declared ) {
			$mode = $meta['mode'];
		} else {
			$readonly = null;
			if ( isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && array_key_exists( 'readonly', $meta['annotations'] ) ) {
				$readonly = (bool) $meta['annotations']['readonly'];
			}
			if ( null !== $readonly ) {
				$mode = $readonly ? 'read' : 'write';
			} else {
				// Last resort: the pre-Step-1 name contract, so an undeclared ability keeps its
				// current classification rather than silently flipping.
				$is_read = str_starts_with( (string) $name, 'uichemy/get-' ) || str_starts_with( (string) $name, 'uichemy/list-' );
				$mode    = $is_read ? 'read' : 'write';
			}
		}

		if ( array_key_exists( 'targets_object', $meta ) ) {
			$targets = (bool) $meta['targets_object'];
		} else {
			$targets = (bool) preg_match( '#^uichemy/(update|delete|toggle)-#', (string) $name );
		}

		return array(
			'mode'           => $mode,
			'targets_object' => $targets,
			'declared'       => $declared,
		);
	}
}

if ( ! function_exists( 'uich_fields_ability_mode' ) ) {
	/**
	 * read | validate | write for a registered UiChemy ability.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	function uich_fields_ability_mode( $name ) {
		$policy = uich_fields_ability_policy( $name );
		if ( isset( $policy['mode'] ) ) {
			return $policy['mode'];
		}
		// Not seen by the registration filter (e.g. registered before this file loaded).
		return uich_fields_ability_derive_policy( array(), $name )['mode'];
	}
}

if ( ! function_exists( 'uich_fields_ability_targets_object' ) ) {
	/**
	 * Whether an ability addresses an arbitrary object id and so needs a per-object check.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	function uich_fields_ability_targets_object( $name ) {
		$policy = uich_fields_ability_policy( $name );
		if ( isset( $policy['targets_object'] ) ) {
			return (bool) $policy['targets_object'];
		}
		return uich_fields_ability_derive_policy( array(), $name )['targets_object'];
	}
}

if ( ! function_exists( 'uich_fields_ability_write_sentence' ) ) {
	/**
	 * STEP 4. The standing line appended to a write's description, built from what that write
	 * actually accepts.
	 *
	 * Every clause is gated on the feature existing, because this is the one channel a model
	 * cannot skip: telling all 40 writes to send `if_match` when nothing accepts it earns a
	 * validation error on every obedient call, and advertising `dry_run` on a write that has no
	 * such argument teaches the model that a safety check it never performed was performed.
	 * Add a clause back here the same day the feature lands, not before.
	 *
	 * Deliberately not translated: it names argument keys, and a translated argument key is a
	 * broken argument key.
	 *
	 * @param array  $args Registration args, read for the arguments this ability declares.
	 * @param string $name Ability name, read for whether anything reverses it.
	 * @return string
	 */
	function uich_fields_ability_write_sentence( $args = array(), $name = '' ) {
		$props = array();
		if ( isset( $args['input_schema']['properties'] ) && is_array( $args['input_schema']['properties'] ) ) {
			$props = array_keys( $args['input_schema']['properties'] );
		}

		$sentence = 'Writes to the site. Send writes one at a time, never concurrently.';

		if ( in_array( 'dry_run', $props, true ) ) {
			$sentence .= ' Check it with `dry_run: true` before persisting.';
		}
		if ( in_array( 'confirm', $props, true ) ) {
			$sentence .= ' If the response returns `requires_confirmation: true`, relay it to the user and only re-send with `confirm: true` once they agree.';
		}
		if ( in_array( 'if_match', $props, true ) ) {
			$sentence .= ' Include the `if_match` value from whichever read you built this payload from.';
		}
		if ( '' !== $name && '' !== uich_fields_ability_reverses( $name ) ) {
			$sentence .= ' The response carries an `undo` block: the ability and arguments that put this back. Keep it.';
		}

		return $sentence;
	}
}

if ( ! function_exists( 'uich_fields_ability_target_post' ) ) {
	/**
	 * The post id an ability call is addressing, or 0 when it cannot be resolved.
	 *
	 * Resolves a definition id (`id`) or a field group addressed by id or key (`group`) — the parent
	 * group is the record a field-level write rewrites. Numeric ids only: a non-numeric `id` is never
	 * cast, because casting would address a record the caller never named.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	function uich_fields_ability_target_post( $input ) {
		if ( ! is_array( $input ) ) {
			return 0;
		}

		if ( isset( $input['id'] ) ) {
			$id = $input['id'];
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
				return (int) $id > 0 ? (int) $id : 0;
			}
			return 0;
		}

		// A group is addressed by key; the module owns the key-to-definition lookup.
		if ( isset( $input['group'] ) && function_exists( 'uich_fields_mcp_ct_locate_group' ) ) {
			$definition = uich_fields_mcp_ct_locate_group( $input['group'] );
			if ( is_array( $definition ) && ! empty( $definition['id'] ) ) {
				return (int) $definition['id'];
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'uich_fields_ability_canonical' ) ) {
	/**
	 * Deep key-sorted copy, so two equal structures hash equal regardless of insertion order.
	 *
	 * @param mixed $value Value to canonicalise.
	 * @return mixed
	 */
	function uich_fields_ability_canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = uich_fields_ability_canonical( $v );
		}
		ksort( $out );
		return $out;
	}
}

if ( ! function_exists( 'uich_fields_ability_state_hash' ) ) {
	/**
	 * A hash of the stored state an ability call addresses, or '' when there is nothing to hash.
	 *
	 * Custom Fields definitions are not posts: their ids are synthetic and get_post() on one returns
	 * null, so the state is read through the module's own REST routes instead.
	 *
	 * Hashes the whole stored configuration rather than the row's last_update, which has one-second
	 * granularity and so cannot separate two writes inside the same second.
	 *
	 * @param array $input Ability input.
	 * @return string
	 */
	function uich_fields_ability_state_hash( $input ) {
		$state = uich_fields_ability_state( $input );
		return $state['hash'];
	}
}

if ( ! function_exists( 'uich_fields_ability_state' ) ) {
	/**
	 * The stored state an ability call addresses: its hash, and the configuration itself when the
	 * module can hand it over.
	 *
	 * One fetch serves both the if_match comparison and the undo snapshot; computing them separately
	 * doubled the work and could read two different versions of the same record.
	 *
	 * @param array $input Ability input.
	 * @return array{hash:string,data:mixed}
	 */
	function uich_fields_ability_state( $input ) {
		$none = array(
			'hash' => '',
			'data' => null,
		);

		$id = uich_fields_ability_target_post( $input );
		if ( $id <= 0 ) {
			// A field-value write addresses an object + field, not a definition: hash the stored value.
			$value_state = uich_fields_ability_value_state( $input );
			return null !== $value_state ? $value_state : $none;
		}

		// No get_post() here: every ability in this plugin addresses a definition, and a post that
		// happened to share the number would be hashed in its place.
		if ( ! function_exists( 'uich_fields_mcp_ct_rest' ) ) {
			return $none;
		}

		// Field groups and CPT/taxonomy definitions live behind different routes; try both rather
		// than inferring from the ability name, which would break the moment one is renamed.
		foreach ( array( '/definitions/' . $id, '/field-groups/' . $id ) as $route ) {
			$result = uich_fields_mcp_ct_rest( 'GET', $route );
			if ( ! empty( $result['success'] ) && isset( $result['data'] ) ) {
				return array(
					'hash' => substr( md5( (string) wp_json_encode( uich_fields_ability_canonical( $result['data'] ) ) ), 0, 16 ),
					'data' => $result['data'],
				);
			}
		}

		return $none;
	}
}
if ( ! function_exists( 'uich_fields_ability_value_state' ) ) {
	/**
	 * The stored state of the field value an ability call addresses (`field` + `object_id`), or null
	 * when the input does not address one.
	 *
	 * Only the hash is kept (`data` stays null): the value itself may be a secret or personal data and
	 * the journal records hashes, never payloads.
	 *
	 * @param mixed $input Ability input.
	 * @return array{hash:string,data:null}|null
	 */
	function uich_fields_ability_value_state( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['field'], $input['object_id'] ) || ! is_string( $input['field'] ) || ! function_exists( 'uich_maybe_get_field_info' ) ) {
			return null;
		}

		$object = function_exists( 'uich_fields_mcp_ct_object_id' ) ? uich_fields_mcp_ct_object_id( $input['object_id'] ) : $input['object_id'];
		$info   = uich_maybe_get_field_info( trim( $input['field'] ), $object );
		if ( ! $info ) {
			return null;
		}

		$meta_key = uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );
		$value    = uich_get_stored_value( $meta_key, $info['object_type'], $info['object_id'], null );

		return array(
			'hash' => substr( md5( (string) wp_json_encode( uich_fields_ability_canonical( $value ) ) ), 0, 16 ),
			'data' => null,
		);
	}
}

if ( ! function_exists( 'uich_fields_ability_addresses_record' ) ) {
	/**
	 * Whether this ability's declared input can be resolved to a single stored record.
	 *
	 * if_match is only offered where it can actually be verified. Advertising it on an ability whose
	 * state we cannot hash would accept the argument and silently ignore it, which is worse than not
	 * offering it at all — the caller believes it is protected.
	 *
	 * @param array $args Registration args.
	 * @return bool
	 */
	function uich_fields_ability_addresses_record( $args ) {
		if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) {
			return false;
		}
		$props = array_keys( $args['input_schema']['properties'] );

		return in_array( 'id', $props, true ) || in_array( 'group', $props, true );
	}
}
if ( ! function_exists( 'uich_fields_ability_reverses' ) ) {
	/**
	 * The ability that undoes another, or '' when nothing reverses it.
	 *
	 * Declared rather than guessed. An undo descriptor that does not work is worse than an honest
	 * "none": the caller stops keeping its own safety net because it believes one exists.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	function uich_fields_ability_reverses( $name ) {
		$map = array(
			// A definition write is reversed by re-applying the configuration we captured first.
			'uichemy/update-cpt'              => 'uichemy/update-cpt',
			'uichemy/update-taxonomy'         => 'uichemy/update-taxonomy',
			'uichemy/update-field-group'      => 'uichemy/update-field-group',
			// Field-level writes rewrite their parent group, so they are reversed by putting the
			// whole group configuration captured before the write back in place.
			'uichemy/add-field-to-group'      => 'uichemy/update-field-group',
			'uichemy/update-field-in-group'   => 'uichemy/update-field-group',
			'uichemy/delete-field-from-group' => 'uichemy/update-field-group',
			'uichemy/reorder-fields-in-group' => 'uichemy/update-field-group',
			// Trashing is reversed by restoring; toggling by toggling back.
			'uichemy/delete-content-type'     => 'uichemy/restore-content-type',
			'uichemy/delete-field-group'      => 'uichemy/restore-content-type',
			'uichemy/restore-content-type'    => 'uichemy/delete-content-type',
			'uichemy/toggle-content-type'     => 'uichemy/toggle-content-type',
			'uichemy/toggle-field-group'      => 'uichemy/toggle-field-group',
		);

		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}
}

if ( ! function_exists( 'uich_fields_ability_restore_args' ) ) {
	/**
	 * Extra arguments a restore-config replay must carry, keyed by the ability that replays it.
	 *
	 * Each entry is limited to what that ability's input schema declares — every schema sets
	 * additionalProperties:false, so an extra key turns the undo into a validation error.
	 * update-field-group MERGES by default, so its replay sends `replace` to drop anything the write
	 * added; the post type and taxonomy updates already replace, and carry `confirm` because putting
	 * back an inactive state can hide content.
	 *
	 * @param string $ability Ability that replays the configuration.
	 * @return array|null Extra args, or null when the ability cannot replay a configuration.
	 */
	function uich_fields_ability_restore_args( $ability ) {
		$map = array(
			'uichemy/update-cpt'         => array( 'confirm' => true ),
			'uichemy/update-taxonomy'    => array( 'confirm' => true ),
			'uichemy/update-field-group' => array( 'replace' => true ),
		);

		return isset( $map[ $ability ] ) ? $map[ $ability ] : null;
	}
}

if ( ! function_exists( 'uich_fields_ability_read_only_refusal' ) ) {
	/**
	 * The refusal for a write while read-only mode is on, or null when the write may run.
	 *
	 * Checked inside the wrapped execute_callback so it holds on every supported core: the
	 * permission-layer filter that also enforces it only exists from WordPress 7.1.
	 *
	 * @param string $name Ability name.
	 * @return WP_Error|null
	 */
	function uich_fields_ability_read_only_refusal( $name ) {
		if ( ! function_exists( 'uich_fields_mcp_ct_read_only' ) || ! uich_fields_mcp_ct_read_only() ) {
			return null;
		}
		$listed = function_exists( 'uich_fields_mcp_ct_write_abilities' ) && in_array( $name, (array) uich_fields_mcp_ct_write_abilities(), true );
		if ( ! $listed && 'write' !== uich_fields_ability_mode( $name ) ) {
			return null;
		}

		return new WP_Error(
			'uichemy_fields_abilities_read_only',
			__( 'Custom Fields abilities are in read-only mode on this site, so this change was refused. Reading definitions and field values still works.', 'uichemy' ),
			array( 'status' => 403 )
		);
	}
}

if ( ! function_exists( 'uich_fields_ability_journal_target' ) ) {
	/**
	 * The id a journal row is filed under.
	 *
	 * The addressed definition — or the parent group of a field-level write — when there is one. A
	 * field-value write addresses an object instead, so it is filed under that object's numeric post
	 * id; term, user and option objects have no single numeric id and are filed under 0.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	function uich_fields_ability_journal_target( $input ) {
		$target = uich_fields_ability_target_post( $input );
		if ( $target > 0 || ! is_array( $input ) || ! isset( $input['object_id'] ) ) {
			return $target;
		}

		$object = $input['object_id'];
		if ( is_int( $object ) || ( is_string( $object ) && ctype_digit( $object ) ) ) {
			return max( 0, (int) $object );
		}

		return 0;
	}
}

if ( ! function_exists( 'uich_fields_ability_journal_object' ) ) {
	/**
	 * The object a field-value write addressed, as `post:88`, `user:5`, `term:9`, `comment:3` or `option`.
	 *
	 * @param mixed $input Ability input.
	 * @return string '' when the call addressed no object.
	 */
	function uich_fields_ability_journal_object( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['object_id'] ) || ! function_exists( 'uich_get_valid_object_id' ) ) {
			return '';
		}
		$object = function_exists( 'uich_fields_mcp_ct_object_id' ) ? uich_fields_mcp_ct_object_id( $input['object_id'] ) : $input['object_id'];
		$parsed = uich_get_valid_object_id( $object );

		return 'option' === $parsed['type'] ? 'option' : $parsed['type'] . ':' . (int) $parsed['id'];
	}
}

if ( ! function_exists( 'uich_fields_ability_journal_subject' ) ) {
	/**
	 * What a write was about, in a few words: the field, or the key of the definition it touched.
	 *
	 * @param mixed $input  Ability input.
	 * @param array $before Snapshot taken before the write (its config names a definition that is gone after a trash).
	 * @param mixed $result Callback result.
	 * @return string
	 */
	function uich_fields_ability_journal_subject( $input, $before, $result ) {
		$keys = array( 'post_type_key', 'taxonomy_key', 'key' );

		if ( is_array( $input ) && isset( $input['field'] ) ) {
			$field = $input['field'];
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$field = $field['name'];
			}
			if ( is_string( $field ) && '' !== $field ) {
				return $field;
			}
		}

		if ( is_array( $result ) ) {
			if ( ! empty( $result['key'] ) && is_string( $result['key'] ) ) {
				return $result['key'];
			}
			// A scaffold reports what it created, one step per definition.
			if ( ! empty( $result['created'] ) && is_array( $result['created'] ) ) {
				$parts = array();
				foreach ( $result['created'] as $step ) {
					if ( is_array( $step ) && ! empty( $step['key'] ) ) {
						$parts[] = ( isset( $step['step'] ) ? $step['step'] . ':' : '' ) . $step['key'];
					}
				}
				if ( $parts ) {
					return implode( ', ', $parts );
				}
			}
		}

		if ( is_array( $input ) ) {
			if ( isset( $input['group'] ) && is_string( $input['group'] ) ) {
				return $input['group'];
			}
			$data = ( isset( $input['data'] ) && is_array( $input['data'] ) ) ? $input['data'] : array();
			foreach ( $keys as $k ) {
				if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
		}

		$config = isset( $before['data'] ) ? uich_fields_ability_config_payload( $before['data'] ) : null;
		if ( is_array( $config ) ) {
			foreach ( $keys as $k ) {
				if ( ! empty( $config[ $k ] ) && is_string( $config[ $k ] ) ) {
					return $config[ $k ];
				}
			}
		}

		return '';
	}
}

if ( ! function_exists( 'uich_fields_ability_batch' ) ) {
	/**
	 * The batch id the current write belongs to, if any.
	 *
	 * Set by uichemy/apply-changes for the length of one batch so every journal row it produces can be
	 * found together later. Without it a batch is indistinguishable from several unrelated writes that
	 * happened to arrive at the same second, and reviewing a whole task as a unit is impossible.
	 *
	 * @param string|null $set Batch id to enter, '' to leave, or null to read.
	 * @return string
	 */
	function uich_fields_ability_batch( $set = null ) {
		static $current = '';

		if ( null !== $set ) {
			$current = (string) $set;
		}

		return $current;
	}
}

if ( ! function_exists( 'uich_fields_ability_write_succeeded' ) ) {
	/**
	 * Whether a write result represents a change that actually landed.
	 *
	 * The module reports a refusal as `success => false` in an ordinary array rather than a WP_Error,
	 * so treating "not a WP_Error" as success journals refusals as changes and hands back an undo
	 * descriptor for a write that never happened.
	 *
	 * @param mixed $result Callback result.
	 * @return bool
	 */
	function uich_fields_ability_write_succeeded( $result ) {
		if ( is_wp_error( $result ) ) {
			return false;
		}
		if ( is_array( $result ) && array_key_exists( 'success', $result ) ) {
			return (bool) $result['success'];
		}

		return true;
	}
}

if ( ! function_exists( 'uich_fields_ability_config_payload' ) ) {
	/**
	 * The writable configuration inside a definition record.
	 *
	 * A read returns an envelope — id, title, kind, last_update, status, content_count and the
	 * configuration under `data` — while the update abilities take that inner configuration as their
	 * own `data`. Handing the envelope back produced an undo descriptor that replayed cleanly and
	 * restored nothing, which is the exact failure an undo feature exists to prevent.
	 *
	 * @param mixed $record Record as a read returned it.
	 * @return array|null
	 */
	function uich_fields_ability_config_payload( $record ) {
		if ( ! is_array( $record ) ) {
			return null;
		}
		if ( isset( $record['data'] ) && is_array( $record['data'] ) ) {
			return $record['data'];
		}

		return $record;
	}
}

if ( ! function_exists( 'uich_fields_ability_undo_descriptor' ) ) {
	/**
	 * How to reverse a write that just landed, or an honest refusal to claim.
	 *
	 * @param string $name   Ability that ran.
	 * @param array  $input  Input it ran with.
	 * @param array  $before Snapshot taken before it ran.
	 * @return array
	 */
	function uich_fields_ability_undo_descriptor( $name, $input, $before ) {
		$reverses = uich_fields_ability_reverses( $name );
		if ( '' === $reverses ) {
			return array(
				'strategy' => 'none',
				'reason'   => 'Nothing reverses this write automatically. Keep your own copy of what it replaced.',
			);
		}

		$id = uich_fields_ability_target_post( $input );
		if ( $id <= 0 ) {
			return array(
				'strategy' => 'none',
				'reason'   => 'The record this changed could not be identified, so no reversal can be addressed to it.',
			);
		}

		// Re-applying the captured configuration only works when we actually captured one. Field-level
		// writes land here too: their reversal replays the parent group's configuration.
		$restore_args = uich_fields_ability_restore_args( $reverses );
		if ( null !== $restore_args ) {
			$payload = uich_fields_ability_config_payload( $before['data'] );
			if ( ! is_array( $payload ) ) {
				return array(
					'strategy' => 'none',
					'reason'   => 'The previous configuration could not be read before the write, so it cannot be put back.',
				);
			}
			return array(
				'strategy' => 'restore-config',
				'ability'  => $reverses,
				'input'    => array_merge(
					array(
						'id'   => $id,
						'data' => $payload,
					),
					$restore_args
				),
			);
		}

		// A toggle reverses by putting back the state captured before it ran — which also covers a
		// toggle that omitted `active` and flipped whatever was stored.
		if ( 0 === strpos( $name, 'uichemy/toggle-' ) ) {
			$previous = uich_fields_ability_config_payload( $before['data'] );
			if ( is_array( $previous ) && array_key_exists( 'active', $previous ) ) {
				$was_active = (bool) filter_var( $previous['active'], FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_array( $input ) && array_key_exists( 'active', $input ) ) {
				$was_active = ! filter_var( $input['active'], FILTER_VALIDATE_BOOLEAN );
			} else {
				return array(
					'strategy' => 'none',
					'reason'   => 'The state before this toggle could not be read, so the previous one is not known.',
				);
			}

			$back = array(
				'id'     => $id,
				'active' => $was_active,
			);
			// Only toggle-content-type declares confirm (switching a type off can hide content).
			if ( 'uichemy/toggle-content-type' === $reverses ) {
				$back['confirm'] = true;
			}

			return array(
				'strategy' => 'toggle-back',
				'ability'  => $reverses,
				'input'    => $back,
			);
		}

		// Trash and restore move a definition to a new id, so the id this call was sent is dead once
		// it lands. The callback reports the moved id with its own undo block; reaching here means it
		// could not, and a descriptor addressed to the old id would fail or hit the wrong record.
		return array(
			'strategy' => 'none',
			'reason'   => 'The record moved to a new id and that id could not be resolved. Find it with uichemy/list-content-types-trash or uichemy/list-content-types, then call ' . $reverses . ' on it.',
		);
	}
}

if ( ! function_exists( 'uich_fields_ability_record_write' ) ) {
	/**
	 * Append one write to the journal.
	 *
	 * Records the hashes rather than the payload: an input can carry field values, and a recent
	 * activity list is not the place to keep a second copy of someone's content.
	 *
	 * @param string $name   Ability that ran.
	 * @param array  $input  Input it ran with.
	 * @param array  $before Snapshot taken before it ran.
	 * @param mixed  $result Callback result.
	 * @return void
	 */
	function uich_fields_ability_record_write( $name, $input, $before, $result ) {
		$ok = uich_fields_ability_write_succeeded( $result );
		// Flagged from the input as well as the result: a dry run refused by a check never reports dry_run.
		$dry = ( is_array( $input ) && ! empty( $input['dry_run'] ) ) || ( is_array( $result ) && ! empty( $result['dry_run'] ) );

		$target = uich_fields_ability_journal_target( $input );
		$probe  = $input;
		// A create addresses nothing yet; the id it returned is what it made.
		if ( $ok && ! $dry && $target <= 0 && is_array( $result ) && isset( $result['id'] ) && is_numeric( $result['id'] ) && (int) $result['id'] > 0 ) {
			$target = (int) $result['id'];
			$probe  = array( 'id' => $target );
		}

		// Nothing changed on a dry run or a refusal, so the hash does not move.
		$hash_after = $before['hash'];
		if ( $ok && ! $dry ) {
			$hash_after = uich_fields_ability_state_hash( $probe );
			// A batch-style write (scaffold) has no single record to re-read: hash what it reports creating.
			if ( '' === $hash_after && is_array( $result ) && ! empty( $result['created'] ) ) {
				$hash_after = substr( md5( (string) wp_json_encode( $result['created'] ) ), 0, 16 );
			}
		}

		uich_fields_ability_journal(
			'append',
			array(
				'time'        => time(),
				'user'        => get_current_user_id(),
				'ability'     => $name,
				'batch'       => uich_fields_ability_batch(),
				'target'      => $target,
				'object'      => uich_fields_ability_journal_object( $input ),
				'subject'     => uich_fields_ability_journal_subject( $input, $before, $result ),
				'dry_run'     => $dry,
				'hash_before' => $before['hash'],
				'hash_after'  => $hash_after,
				'ok'          => $ok,
				'error'       => is_wp_error( $result ) ? $result->get_error_code() : ( ( is_array( $result ) && ! $ok && isset( $result['error'] ) ) ? (string) $result['error'] : '' ),
			)
		);
	}
}

if ( ! function_exists( 'uich_fields_ability_journal' ) ) {
	/**
	 * Append to, read, or clear the write journal.
	 *
	 * Bounded and option-backed on purpose: a dedicated table needs a schema version and a migration
	 * to maintain, and this has to be safe to ship before that exists. The cost is that it keeps the
	 * most recent UICH_FIELDS_JOURNAL_MAX entries only — it is a recent-activity record, not an
	 * audit log, and nothing should be built on the assumption that an old entry is still there.
	 * autoload is off: this must never join the options loaded on every request.
	 *
	 * @param string $op    'append' | 'list' | 'clear'.
	 * @param array  $entry Entry to append.
	 * @return array Entries for 'list', empty otherwise.
	 */
	function uich_fields_ability_journal( $op, $entry = array() ) {
		$key = 'uichemy_fields_journal';
		$max = defined( 'UICH_FIELDS_JOURNAL_MAX' ) ? (int) UICH_FIELDS_JOURNAL_MAX : 200;

		// The table is the store. What follows is the fallback for a site where it could not be
		// created — a read-only or restricted database is a real deployment, and losing the record
		// of a write is worse than keeping only the most recent 200.
		$table = class_exists( 'Uich_Fields_Ability_Journal' ) && Uich_Fields_Ability_Journal::table_ready();

		if ( 'clear' === $op ) {
			if ( $table ) {
				Uich_Fields_Ability_Journal::clear();
				return array();
			}
			delete_option( $key );
			return array();
		}

		if ( $table && 'append' === $op && ! empty( $entry ) ) {
			Uich_Fields_Ability_Journal::append( $entry );
			return array();
		}

		if ( $table && 'list' === $op ) {
			// Oldest first, matching what the option store returned, so a caller that reads this
			// directly does not have to know which store answered.
			$result = Uich_Fields_Ability_Journal::query( array( 'limit' => 200 ) );
			return array_reverse( $result['rows'] );
		}

		$log = get_option( $key, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		if ( 'list' === $op ) {
			return array_values( $log );
		}

		if ( 'append' !== $op || empty( $entry ) ) {
			return array();
		}

		$log[] = $entry;
		if ( count( $log ) > $max ) {
			$log = array_slice( $log, -$max );
		}

		// add_option first so the row is created with autoload off; update_option keeps whatever
		// autoload the row already has, so a row created elsewhere as autoload=yes would stay that way.
		if ( ! add_option( $key, $log, '', false ) ) {
			update_option( $key, $log, false );
		}

		return array();
	}
}
if ( ! function_exists( 'uich_fields_ability_lock' ) ) {
	/**
	 * STEP 3. Re-entrant write lock around a whole ability.
	 *
	 * Claimed with add_option(), which is a single INSERT and so atomic — the same primitive the
	 * import runner uses. A transient would be wrong here: an object-cache backend may drop the key
	 * and hand the lock to two callers at once.
	 *
	 * Re-entrancy is required, not a nicety: scaffold-content-model performs several writes inside
	 * one ability and must not deadlock against its own caller.
	 *
	 * @param string $op   'acquire' | 'release' | 'depth'.
	 * @param string $name Ability name, for the error message.
	 * @return mixed True/WP_Error on acquire, void on release, int on depth.
	 */
	function uich_fields_ability_lock( $op, $name = '' ) {
		static $depth = 0;
		static $token = '';

		$key   = 'uichemy_fields_write_lock';
		$stale = 300;

		if ( 'depth' === $op ) {
			return $depth;
		}

		if ( 'release' === $op ) {
			if ( $depth > 0 ) {
				--$depth;
			}
			if ( 0 !== $depth || '' === $token ) {
				return null;
			}
			$held = get_option( $key );
			if ( is_array( $held ) && isset( $held['token'] ) && hash_equals( (string) $held['token'], $token ) ) {
				delete_option( $key );
			}
			$token = '';
			return null;
		}

		// acquire
		if ( $depth > 0 ) {
			++$depth;   // already ours
			return true;
		}

		$now  = time();
		$mine = array(
			'token'   => wp_generate_uuid4(),
			'user'    => get_current_user_id(),
			'ability' => (string) $name,
			'started' => $now,
		);

		// autoload false: this row is written and deleted constantly and must not ride alloptions.
		if ( add_option( $key, $mine, '', false ) ) {
			$token = $mine['token'];
			$depth = 1;
			return true;
		}

		$held = get_option( $key );
		$age  = ( is_array( $held ) && isset( $held['started'] ) ) ? ( $now - (int) $held['started'] ) : PHP_INT_MAX;

		if ( $age < $stale ) {
			$other = ( is_array( $held ) && ! empty( $held['ability'] ) ) ? (string) $held['ability'] : 'another write';
			return new WP_Error(
				'uich_fields_ability_write_locked',
				sprintf(
					/* translators: 1: ability being called, 2: ability already running. */
					'%1$s was refused because %2$s is still writing. Issue writes one at a time, not in parallel, then retry.',
					(string) $name,
					$other
				),
				array( 'status' => 409 )
			);
		}

		// A run killed mid-flight must not lock the site out for good.
		$mine['took_over'] = true;
		update_option( $key, $mine, false );
		$token             = $mine['token'];
		$depth             = 1;
		return true;
	}
}

if ( ! function_exists( 'uich_fields_register_ability_lifecycle' ) ) {
	/**
	 * Registers the ability lifecycle filters.
	 *
	 * Runs on every core that has the Abilities API (6.9+). Everything that keeps a write safe —
	 * exposure, read-only refusal, the Pro gate, the lock, the journal and the undo block — is applied
	 * through `wp_register_ability_args` (6.9+) and enforced inside the wrapped callbacks. The hooks
	 * added further down that only exist from 7.1 (input coercion, read cache, telemetry) are
	 * conveniences: on an older core they never fire and nothing unsafe follows from that.
	 *
	 * @return void
	 */
	function uich_fields_register_ability_lifecycle() {
		// STEP 1. Both contracts now read the DECLARED policy instead of parsing the name. The
		// get-/list- convention stays as a style rule; it stops being load-bearing.
		$mode    = static fn( $n ) => uich_fields_ability_is_ours( $n ) ? uich_fields_ability_mode( $n ) : '';
		$is_read = static fn( $n ) => 'read' === $mode( $n );

		$ttl = static fn( $n ) => (int) apply_filters( 'uich_fields_ability_cache_ttl', 15 * MINUTE_IN_SECONDS, $n );

		// SEC: the cache key includes the current user. wp_pre_execute_ability fires before the permission check, so a
		// shared entry would have served another user's result.
		$ckey = static fn( $n, $i ) => 'uichemy_fields_ab_' . md5( uich_fields_ability_cache_version() . '|' . get_current_user_id() . '|' . $n . '|' . wp_json_encode( $i ) );

		// A. STEP 2. The single place policy is applied. Every UiChemy ability already passes through
		// this filter, so nothing in the 35 ability files has to change to be covered — and a new
		// ability cannot be the one that forgot a guard.
		add_filter(
			'wp_register_ability_args',
			static function ( $args, $name ) {
				if ( ! uich_fields_ability_is_ours( $name ) ) {
					return $args;
				}
				if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
					$args['meta'] = array();
				}

				// Unified public flag — keep every UiChemy ability discoverable under 7.1.
				if ( ! isset( $args['meta']['public'] ) ) {
					$args['meta']['public'] = true;
				}

				// STEP 1. Resolve and remember the policy, writing it back so anything reading the
				// registered meta sees the same answer this file uses.
				$policy                          = uich_fields_ability_derive_policy( $args, $name );
				$args['meta']['mode']            = $policy['mode'];
				$args['meta']['targets_object']  = $policy['targets_object'];
				uich_fields_ability_policy( $name, $policy );

				// Central Pro gate (opt-in list of ability names), wrapped around the permission
				// callback so it holds on every core rather than only where wp_ability_permission_result
				// exists. uich_fields_is_pro(), not defined( 'UICH_PRO_VERSION' ): that constant comes
				// from the separate Pro PLUGIN, so a build that decides Pro by licence tier would refuse
				// every listed ability to a paying site.
				if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
					$permission_inner            = $args['permission_callback'];
					$args['permission_callback'] = static function ( $input = null ) use ( $permission_inner, $name ) {
						$pro = (array) apply_filters( 'uich_fields_ability_pro_list', array() );
						if ( in_array( $name, $pro, true ) && function_exists( 'uich_fields_is_pro' ) && ! uich_fields_is_pro() ) {
							return new WP_Error( 'uich_fields_pro_required', __( 'This feature requires UiChemy Pro.', 'uichemy' ), array( 'status' => 403 ) );
						}
						return $permission_inner( $input );
					};
				}

				// MCP exposure, in one place so it can be closed without editing 72 files. Sits after
				// the policy so the rule can be stated in terms of the mode.
				//
				// A write is CLOSED unless the filter opens it, and its own declaration cannot reopen
				// it. Exposure defaulted to on, so activating any MCP adapter would have published
				// every delete, import and batch at once; a copy-pasted `'public' => true` in a new
				// write file must not be able to undo that quietly. Reads keep their declaration.
				if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
					$args['meta']['mcp'] = array();
				}
				$declared_public = ( 'write' !== $policy['mode'] ) && ! empty( $args['meta']['mcp']['public'] );

				$args['meta']['mcp']['public'] = (bool) apply_filters(
					'uich_fields_ability_mcp_public',
					$declared_public,
					$name,
					$policy['mode']
				);

				// Keep the annotation consistent with the mode, so a client reading either gets the
				// same answer.
				if ( ! isset( $args['meta']['annotations'] ) || ! is_array( $args['meta']['annotations'] ) ) {
					$args['meta']['annotations'] = array();
				}
				if ( ! array_key_exists( 'readonly', $args['meta']['annotations'] ) ) {
					$args['meta']['annotations']['readonly'] = ( 'read' === $policy['mode'] );
				}

				// TIER B. Optimistic concurrency. The argument is injected centrally rather than added
				// to 40 schemas by hand, and only where the state can actually be hashed — every
				// schema sets additionalProperties:false, so an uninjected if_match is a hard
				// validation error rather than a silently ignored one.
				$addresses_record = uich_fields_ability_addresses_record( $args );
				if ( 'write' === $policy['mode'] && $addresses_record ) {
					$args['input_schema']['properties']['if_match'] = array(
						'type'        => 'string',
						'description' => 'The config_hash from the read this payload was built from. The write is refused if the record changed since.',
					);
				}
				// STEP 4. Append the standing sentence to every write's description. It goes
				// ALONGSIDE the per-ability annotations.instructions, never in place of them — those
				// name each ability's own traps and are the better guidance.
				if ( 'write' === $policy['mode'] && isset( $args['description'] ) && is_string( $args['description'] ) ) {
					$sentence = uich_fields_ability_write_sentence( $args, $name );
					if ( false === strpos( $args['description'], 'Send writes one at a time' ) ) {
						$args['description'] = rtrim( $args['description'] ) . ' ' . $sentence;
					}
				}

				// TIER B. A read that addresses one record returns its config_hash. Attached centrally
				// so no read has to remember, and only onto array results — a read that returns a
				// scalar has nowhere to put it.
				if ( 'read' === $policy['mode'] && $addresses_record && isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
					$read_inner               = $args['execute_callback'];
					$args['execute_callback'] = static function ( $input = null ) use ( $read_inner ) {
						$result = $read_inner( $input );
						// Only a read that found its record: a hash on a "not found" names nothing.
						if ( is_array( $result ) && ! isset( $result['config_hash'] ) && ( ! array_key_exists( 'success', $result ) || ! empty( $result['success'] ) ) ) {
							$hash = uich_fields_ability_state_hash( $input );
							if ( '' !== $hash ) {
								$result['config_hash'] = $hash;
							}
						}
						return $result;
					};
				}
				// STEP 3. Wrap the callback itself, which is the only anchor that gives try/finally
				// semantics. wp_pre_execute_ability fires BEFORE the permission check and before input
				// validation, and wp_ability_execute_result does not fire when either of those aborts —
				// measured — so acquiring there leaked the lock on every refused or malformed call, and
				// a stream of unauthorised writes would have 409'd every legitimate one.
				//
				// As the callback, this runs only once the call is actually going to do work.
				if ( 'write' === $policy['mode'] && isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
					$inner                    = $args['execute_callback'];
					$args['execute_callback'] = static function ( $input = null ) use ( $inner, $name ) {
						// Read-only mode, enforced here so it holds on 6.9/7.0 as well, where the
						// permission-layer filter never fires. Journalled: a refusal that leaves no
						// trace would make the failures view report an all-clear.
						$read_only = uich_fields_ability_read_only_refusal( $name );
						if ( null !== $read_only ) {
							uich_fields_ability_record_write( $name, $input, array( 'hash' => '', 'data' => null ), $read_only );
							return $read_only;
						}

						$lock = uich_fields_ability_lock( 'acquire', $name );
						if ( is_wp_error( $lock ) ) {
							// A lock conflict is two callers writing at once, which is exactly what the
							// journal exists to show. The state is unknown here — another write holds
							// the lock — so the entry carries no hashes rather than a guess.
							uich_fields_ability_record_write( $name, $input, array( 'hash' => '', 'data' => null ), $lock );
							return $lock;
						}
						try {
							// TIER B. Snapshot first: the same read serves the if_match comparison and
							// the undo, and after the write the previous configuration is gone.
							$before = uich_fields_ability_state( $input );

							// Inside the lock on purpose: comparing before taking it leaves a window in
							// which another caller writes between the check and this write.
							if ( is_array( $input ) && isset( $input['if_match'] ) && '' !== $input['if_match'] ) {
								$refusal = null;
								if ( '' === $before['hash'] ) {
									$refusal = new WP_Error(
										'uich_fields_if_match_unverifiable',
										__( 'This record no longer exists, so if_match cannot be checked. Read it again before writing.', 'uichemy' ),
										array( 'status' => 409 )
									);
								} elseif ( ! hash_equals( $before['hash'], (string) $input['if_match'] ) ) {
									$refusal = new WP_Error(
										'uich_fields_if_match_failed',
										sprintf(
											/* translators: 1: hash sent by the caller, 2: current hash. */
											__( 'This record changed since you read it (you sent %1$s, it is now %2$s). Read it again and rebuild the payload — do not retry this one.', 'uichemy' ),
											(string) $input['if_match'],
											$before['hash']
										),
										array( 'status' => 409 )
									);
								}

								// Journalled like any other outcome: a refusal that leaves no trace makes
								// the only_failures filter report an all-clear that was never true.
								if ( null !== $refusal ) {
									uich_fields_ability_record_write( $name, $input, $before, $refusal );
									return $refusal;
								}

								// The inner callback never declared if_match; leaving it in trips its own validation.
								unset( $input['if_match'] );
							}

							$result = $inner( $input );
							uich_fields_ability_record_write( $name, $input, $before, $result );

							// Expire every cached read the moment a write lands. Done here rather than
							// in wp_ability_execute_result, which only exists from 7.1.
							$was_dry = ( is_array( $result ) && ! empty( $result['dry_run'] ) );
							if ( ! $was_dry && uich_fields_ability_write_succeeded( $result ) ) {
								uich_fields_ability_cache_version( true );
							}

							// A dry run changed nothing, so there is nothing to reverse. Attaching an undo
							// block to it — even an honest "none" — invites the reading that something
							// happened and could not be undone.
							if ( is_array( $result ) && ! $was_dry && ! isset( $result['undo'] ) && uich_fields_ability_write_succeeded( $result ) ) {
								$result['undo'] = uich_fields_ability_undo_descriptor( $name, $input, $before );
							}

							return $result;
						} finally {
							uich_fields_ability_lock( 'release', $name );
						}
					};
				}

				return $args;
			},
			10,
			2
		);

		// B. Agents send "on"/"yes"/"1" for switches; accept them instead of failing the schema.
		add_filter(
			'wp_ability_normalize_input',
			static function ( $input, $name ) {
				if ( ! uich_fields_ability_is_ours( $name ) || ! is_array( $input ) ) {
					return $input;
				}

				// 'status' is deliberately absent: this plugin types it as an integer enum of 0|1,
				// and coercing that to a bool would fail validation.
				foreach ( array( 'enabled', 'active', 'switch' ) as $key ) {
					// Only word-shaped values are coerced. A numeric 0/1 is left alone so integer
					// schemas keep working.
					if ( ! array_key_exists( $key, $input ) || is_bool( $input[ $key ] ) || is_numeric( $input[ $key ] ) ) {
						continue;
					}
					$coerced = filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $coerced ) {
						$input[ $key ] = $coerced;
					}
				}

				return $input;
			},
			10,
			2
		);

		// D. Usage telemetry (opt-in; default off to avoid a write on every call).
		add_action(
			'wp_ability_invoked',
			static function ( $name, $input, $ability ) {
				unset( $input, $ability );
				if ( ! uich_fields_ability_is_ours( $name ) || ! apply_filters( 'uich_fields_ability_telemetry', false, $name ) ) {
					return;
				}
				$stats          = (array) get_option( 'uichemy_fields_ability_usage', array() );
				$stats[ $name ] = isset( $stats[ $name ] ) ? ( (int) $stats[ $name ] + 1 ) : 1;
				update_option( 'uichemy_fields_ability_usage', $stats, false );
			},
			10,
			3
		);

		// A held lock must not outlive the request if the ability fataled inside its own callback —
		// the stale takeover is the backstop, this is the fast path.
		add_action(
			'shutdown',
			static function () {
				$guard = 0;
				while ( uich_fields_ability_lock( 'depth' ) > 0 && $guard++ < 64 ) {
					uich_fields_ability_lock( 'release' );
				}
			},
			0
		);

		// E. Serve read-only abilities from cache (short-circuit).
		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, $name, $input, $ability = null ) use ( $is_read, $ckey, $ttl ) {
				if ( ! $is_read( $name ) || $ttl( $name ) <= 0 ) {
					return $pre;
				}

				if ( $ability instanceof WP_Ability ) {
					$allowed = $ability->check_permissions( $input );
					if ( is_wp_error( $allowed ) || true !== $allowed ) {
						// Fall through to execute(), which produces the proper permission error.
						return $pre;
					}
				}

				$hit = get_transient( $ckey( $name, $input ) );

				return ( false !== $hit ) ? $hit : $pre;
			},
			10,
			4
		);

		// G. Fill the read cache (7.1+). A write expires it from inside its own wrapped callback, so
		// that half holds on every core.
		add_filter(
			'wp_ability_execute_result',
			static function ( $result, $name, $input ) use ( $is_read, $ckey, $ttl ) {
				if ( ! is_wp_error( $result ) && $is_read( $name ) && $ttl( $name ) > 0 ) {
					set_transient( $ckey( $name, $input ), $result, $ttl( $name ) );
				}

				return $result;
			},
			10,
			3
		);
	}
}

uich_fields_register_ability_lifecycle();
