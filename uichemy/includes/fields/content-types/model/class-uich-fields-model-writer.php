<?php
/**
 * The one place a definition is written when files are the storage.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Writer
 */
class Uich_Fields_Model_Writer {

	/**
	 * Resolve an id back to the definition it names.
	 *
	 * @param int $id Derived definition id.
	 * @return array{kind: string, key: string, trashed: bool}|null
	 */
	public static function lookup( $id ) {
		$id = (int) $id;
		if ( $id < 1 ) {
			return null;
		}

		$model = Uich_Fields_Model_Cache::get();
		$live  = isset( $model['definitions'] ) ? $model['definitions'] : array();
		$trash = Uich_Fields_Model_Rows::read_trash();

		$map = Uich_Fields_Model_Identity::map( $live, $trash );

		return isset( $map['keys'][ $id ] ) ? $map['keys'][ $id ] : null;
	}

	/**
	 * A definition's contents, addressed by id.
	 *
	 * @param int $id Definition id.
	 * @return array|null Null when the id names nothing.
	 */
	public static function definition( $id ) {
		$found = self::lookup( $id );
		if ( ! $found ) {
			return null;
		}
		if ( ! empty( $found['trashed'] ) ) {
			$trash = Uich_Fields_Model_Rows::read_trash();
			return isset( $trash[ $found['kind'] ][ $found['key'] ] ) ? $trash[ $found['kind'] ][ $found['key'] ] : null;
		}
		$model = Uich_Fields_Model_Cache::get();
		return isset( $model['definitions'][ $found['kind'] ][ $found['key'] ] )
			? $model['definitions'][ $found['kind'] ][ $found['key'] ]
			: null;
	}

	/**
	 * The id a definition will have, without needing the model to be built first.
	 *
	 * @param string $kind Model kind.
	 * @param string $key  Definition key.
	 * @return int
	 */
	public static function id_for( $kind, $key ) {
		$model = Uich_Fields_Model_Cache::get();
		$all   = isset( $model['definitions'] ) ? $model['definitions'] : array();
		if ( ! isset( $all[ $kind ][ $key ] ) ) {
			$all[ $kind ][ $key ] = array();
		}
		$map = Uich_Fields_Model_Identity::map( $all, Uich_Fields_Model_Rows::read_trash() );
		$mk  = Uich_Fields_Model_Identity::map_key( $kind, $key, false );

		return isset( $map['ids'][ $mk ] ) ? (int) $map['ids'][ $mk ] : 0;
	}

	/**
	 * The id of the live or the trashed copy of a definition, or 0 when that copy does not exist.
	 *
	 * @param string $kind    Model kind.
	 * @param string $key     Definition key.
	 * @param bool   $trashed Whether to address the trashed copy.
	 * @return int
	 */
	public static function id_of( $kind, $key, $trashed = false ) {
		$model = Uich_Fields_Model_Cache::get();
		$map   = Uich_Fields_Model_Identity::map(
			isset( $model['definitions'] ) ? $model['definitions'] : array(),
			Uich_Fields_Model_Rows::read_trash()
		);
		$mk    = Uich_Fields_Model_Identity::map_key( $kind, $key, (bool) $trashed );

		return isset( $map['ids'][ $mk ] ) ? (int) $map['ids'][ $mk ] : 0;
	}

	/**
	 * Create or update a definition file.
	 *
	 * @param string $kind         Model kind: cpt | tax | group_fields.
	 * @param array  $meta         Sanitized definition.
	 * @param int    $existing_id  Id of the definition being updated, or 0 to create.
	 * @param string $display_name Name to fold in when the definition carries none of its own.
	 * @return int|WP_Error The definition's id.
	 */
	public static function save( $kind, $meta, $existing_id = 0, $display_name = '' ) {
		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'uich_model_not_array', __( 'Definition is not an object.', 'uichemy' ) );
		}

		$key_field = Uich_Fields_Model_Paths::key_field( $kind );
		if ( '' === $key_field ) {
			return new WP_Error( 'uich_model_bad_kind', __( 'Unknown definition kind.', 'uichemy' ) );
		}
		$key = isset( $meta[ $key_field ] ) && is_string( $meta[ $key_field ] ) ? $meta[ $key_field ] : '';

		$valid = Uich_Fields_Model_Paths::validate_key( $kind, $key );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// The name has nowhere else to live now that there is no post title. Only filled when the
		// definition has none, so a name the UI is already showing is never overwritten.
		$name_field = ( 'group_fields' === $kind ) ? 'title' : 'label';
		if ( empty( $meta[ $name_field ] ) && '' !== trim( (string) $display_name ) ) {
			$meta[ $name_field ] = $display_name;
		}

		$previous = null;
		if ( $existing_id ) {
			$previous = self::lookup( $existing_id );
			if ( ! $previous ) {
				return new WP_Error(
					'uich_model_not_found',
					__( 'That definition no longer exists.', 'uichemy' ),
					array( 'status' => 404 )
				);
			}
			if ( $previous['kind'] !== $kind ) {
				return new WP_Error(
					'uich_model_kind_mismatch',
					__( 'A definition cannot change what kind of thing it is.', 'uichemy' ),
					array( 'status' => 400 )
				);
			}
		}

		$written = Uich_Fields_Model_Store::write( $kind, $key, $meta );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// A rename leaves the old file behind, and a stale file still naming its own old key is exactly the
		// duplicate the loader refuses to resolve.
		if ( $previous && $previous['key'] !== $key ) {
			Uich_Fields_Model_Store::delete( $kind, $previous['key'] );
		}

		Uich_Fields_Model_Cache::flush();
		self::flush_readers();

		return self::id_for( $kind, $key );
	}

	/**
	 * Move a definition into the trash.
	 *
	 * @param int $id Definition id.
	 * @return true|WP_Error
	 */
	public static function trash( $id ) {
		$found = self::lookup( $id );
		if ( ! $found ) {
			return new WP_Error( 'uich_model_not_found', __( 'That definition no longer exists.', 'uichemy' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $found['trashed'] ) ) {
			return new WP_Error(
				'uich_model_already_trashed',
				__( 'That definition is already in the Trash.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}
		return self::move( $found['kind'], $found['key'], false );
	}

	/**
	 * Restore a definition from the trash.
	 *
	 * @param int $id Definition id.
	 * @return true|WP_Error
	 */
	public static function untrash( $id ) {
		$found = self::lookup( $id );
		if ( ! $found ) {
			return new WP_Error( 'uich_model_not_found', __( 'That definition no longer exists.', 'uichemy' ), array( 'status' => 404 ) );
		}
		if ( empty( $found['trashed'] ) ) {
			return new WP_Error(
				'uich_ct_not_trashed',
				__( 'This item is not in the Trash.', 'uichemy' ),
				array( 'status' => 400 )
			);
		}
		return self::move( $found['kind'], $found['key'], true );
	}

	/**
	 * Move a definition file between the live tree and the trash tree.
	 *
	 * @param string $kind    Model kind.
	 * @param string $key     Definition key.
	 * @param bool   $restore True to move out of the trash, false to move into it.
	 * @return true|WP_Error
	 */
	private static function move( $kind, $key, $restore ) {
		$live  = Uich_Fields_Model_Paths::file_for_key( $kind, $key );
		$trash = Uich_Fields_Model_Rows::trash_file( $kind, $key );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		if ( is_wp_error( $trash ) ) {
			return $trash;
		}

		$from = $restore ? $trash : $live;
		$to   = $restore ? $live : $trash;

		if ( ! file_exists( $from ) ) {
			// Already where it was asked to be. Reporting success is correct: the caller wanted the
			// definition in a state, and it is in that state.
			return true;
		}

		// Never clobber: rename() silently replaces an existing target, which destroyed the older
		// trashed copy when a definition reusing its key was trashed too (or, on restore, the live
		// definition that claimed the key meanwhile). Refuse and let the user pick which copy to keep.
		if ( file_exists( $to ) ) {
			return new WP_Error(
				$restore ? 'uich_ct_key_conflict' : 'uich_model_trash_conflict',
				$restore
					? sprintf(
						/* translators: %s: the definition key that is already in use. */
						__( 'This item cannot be restored because the key "%s" is now used by another definition. Rename or remove that one first, then restore this item.', 'uichemy' ),
						$key
					)
					: sprintf(
						/* translators: %s: definition key. */
						__( 'Another definition with the key "%s" is already in the Trash. Permanently delete or restore that one first, then try again.', 'uichemy' ),
						$key
					),
				array(
					'status' => 409,
					'key'    => $key,
				)
			);
		}

		$dir = dirname( $to );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'uich_model_mkdir_failed', __( 'The trash directory could not be created.', 'uichemy' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- both paths are inside the model directory; a direct rename is atomic where WP_Filesystem::move() may copy.
		if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the return value is checked and reported below.
			return new WP_Error( 'uich_model_move_failed', __( 'The definition file could not be moved.', 'uichemy' ) );
		}

		Uich_Fields_Model_Cache::flush();
		self::flush_readers();

		return true;
	}

	/**
	 * Delete a definition permanently, wherever it currently lives.
	 *
	 * @param int $id Definition id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$found = self::lookup( $id );
		if ( ! $found ) {
			return new WP_Error( 'uich_model_not_found', __( 'That definition no longer exists.', 'uichemy' ), array( 'status' => 404 ) );
		}

		// Delete exactly the copy this id names, and nothing else.
		if ( ! empty( $found['trashed'] ) ) {
			$trash = Uich_Fields_Model_Rows::trash_file( $found['kind'], $found['key'] );
			if ( is_wp_error( $trash ) ) {
				return $trash;
			}
			if ( file_exists( $trash ) && ! @unlink( $trash ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- a validated path inside the model directory; the failure is reported below.
				return new WP_Error( 'uich_model_delete_failed', __( 'Definition file could not be deleted.', 'uichemy' ) );
			}
		} else {
			$done = Uich_Fields_Model_Store::delete( $found['kind'], $found['key'] );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		Uich_Fields_Model_Cache::flush();
		self::flush_readers();

		return true;
	}

	/**
	 * Drop every cache that is derived from the model.
	 */
	private static function flush_readers() {
		if ( class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
			Uich_Fields_Definitions_Repository::get_instance()->flush();
		}
		if ( class_exists( 'Uich_Fields_Register' ) ) {
			Uich_Fields_Register::get_instance()->flush_cache();
		}
	}
}
