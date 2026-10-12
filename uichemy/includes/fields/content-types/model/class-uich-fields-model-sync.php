<?php
/**
 * One-time upgrade of database-stored definitions (`uich_content_types` posts) into definition files.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Sync
 */
class Uich_Fields_Model_Sync {

	/**
	 * Post meta recording the key this definition was last exported under.
	 */
	const LAST_KEY_META = '_uichemy_model_file_key';

	/**
	 * Option holding the most recent export failure, for the admin to surface.
	 */
	const ERROR_OPTION = 'uich_model_sync_error';

	/**
	 * Set once a site's database definitions have been exported into files.
	 */
	const MIGRATED_OPTION = 'uich_model_migrated';

	/**
	 * Short-lived flag that stops a failed migration retrying on every admin request.
	 */
	const MIGRATION_BACKOFF = 'uich_model_migration_backoff';

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Attach the one-time upgrade path.
	 *
	 * Definitions are files; nothing writes `uich_content_types` posts any more. The live
	 * post-to-file mirror (meta / trash / untrash / delete hooks) is deliberately NOT attached: on a
	 * site that still holds stale definition posts, trashing, restoring or emptying them would have
	 * overwritten or deleted the live definition files.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_from_database' ), 5 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::maybe_migrate_from_database();
		}
	}

	/**
	 * One-time export of database definitions into files, for a site upgrading into file storage.
	 *
	 * @return void
	 */
	public static function maybe_migrate_from_database() {
		if ( uich_fields_flag( 'model_migrated', self::MIGRATED_OPTION ) ) {
			return;
		}
		if ( get_transient( self::MIGRATION_BACKOFF ) ) {
			return;
		}
		if ( ! self::ready() ) {
			return;
		}

		// Anything already on disk means this site is past the migration.
		foreach ( array_keys( Uich_Fields_Model_Paths::kind_dirs() ) as $kind ) {
			if ( Uich_Fields_Model_Store::list_files( $kind ) ) {
				uich_fields_flag_set( 'model_migrated', 1 );
				// Files left over from before a database restore are adopted as they are, so every cache built
				// from the restored database is dropped and rebuilt from them.
				Uich_Fields_Model_Cache::flush();
				if ( class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
					Uich_Fields_Definitions_Repository::get_instance()->flush();
				}
				return;
			}
		}

		$existing = get_posts(
			array(
				'post_type'              => Uich_Fields_CPT::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! $existing ) {
			// A fresh install with nothing to migrate. Mark it done so this never runs again.
			uich_fields_flag_set( 'model_migrated', 1 );
			return;
		}

		$result = self::export_all();
		if ( $result['failed'] > 0 || ! $result['exported'] ) {
			set_transient( self::MIGRATION_BACKOFF, 1, HOUR_IN_SECONDS );
			return;
		}

		uich_fields_flag_set( 'model_migrated', 1 );
		Uich_Fields_Model_Cache::flush();
		if ( class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
			Uich_Fields_Definitions_Repository::get_instance()->flush();
		}
	}

	/**
	 * Stop exporting until resume() is called.
	 */
	public static function suspend() {
		self::$suspended = true;
	}

	/**
	 * Resume exporting.
	 */
	public static function resume() {
		self::$suspended = false;
	}

	/**
	 * Whether the exporter is currently suspended.
	 *
	 * @return bool
	 */
	public static function is_suspended() {
		return self::$suspended;
	}

	/**
	 * Write one definition post out to its file.
	 *
	 * @param int $post_id Definition post ID.
	 * @return true|WP_Error True on success (including a deliberate no-op).
	 */
	private static function export( $post_id ) {
		if ( self::$suspended || ! self::ready() ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post || Uich_Fields_CPT::POST_TYPE !== $post->post_type ) {
			return true;
		}

		// A trashed or auto-draft definition is not part of the model; it is never migrated.
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return true;
		}

		$meta = get_post_meta( $post_id, Uich_Fields_CPT::get_meta_key(), true );
		if ( ! is_array( $meta ) || ! $meta ) {
			return true;
		}

		$kind = self::model_kind( $meta );
		if ( '' === $kind ) {
			return true;
		}
		$key_field = Uich_Fields_Model_Paths::key_field( $kind );
		$key       = isset( $meta[ $key_field ] ) && is_string( $meta[ $key_field ] ) ? $meta[ $key_field ] : '';

		$valid = Uich_Fields_Model_Paths::validate_key( $kind, $key );
		if ( is_wp_error( $valid ) ) {
			return self::fail( $valid );
		}

		// The post title is the one piece of a definition that never lived in the meta.
		$name_field = ( 'group_fields' === $kind ) ? 'title' : 'label';
		if ( empty( $meta[ $name_field ] ) && '' !== trim( (string) $post->post_title ) ) {
			$meta[ $name_field ] = $post->post_title;
		}

		// Files are the authority: a database copy never overwrites a definition file that exists.
		$target = Uich_Fields_Model_Paths::file_for_key( $kind, $key );
		if ( is_string( $target ) && file_exists( $target ) ) {
			return true;
		}

		$written = Uich_Fields_Model_Store::write( $kind, $key, $meta );
		if ( is_wp_error( $written ) ) {
			return self::fail( $written );
		}

		update_post_meta( $post_id, self::LAST_KEY_META, $key );
		self::clear_error();
		Uich_Fields_Model_Cache::flush();

		return true;
	}

	/**
	 * Write every definition currently in the database out to files.
	 *
	 * @return array { exported:int, failed:int, errors:array<int, string> }
	 */
	public static function export_all() {
		$out = array(
			'exported' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);
		if ( ! self::ready() ) {
			$out['errors'][] = __( 'The model directory is not available.', 'uichemy' );
			return $out;
		}

		$posts = get_posts(
			array(
				'post_type'              => Uich_Fields_CPT::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $id ) {
			$result = self::export( (int) $id );
			if ( is_wp_error( $result ) ) {
				++$out['failed'];
				$out['errors'][] = $result->get_error_message();
				continue;
			}
			++$out['exported'];
		}

		return $out;
	}

	/**
	 * Map the `kind` stored in meta onto the model layer's kind.
	 *
	 * @param array $meta Definition meta.
	 * @return string Model kind, or '' if unrecognised.
	 */
	private static function model_kind( $meta ) {
		$value = isset( $meta['kind'] ) && '' !== $meta['kind'] ? (string) $meta['kind'] : 'cpt';
		foreach ( Uich_Fields_Model_Paths::kind_values() as $kind => $meta_value ) {
			if ( $meta_value === $value ) {
				return $kind;
			}
		}
		return '';
	}

	/**
	 * Whether the model layer is loaded and its directory usable.
	 *
	 * @return bool
	 */
	private static function ready() {
		return class_exists( 'Uich_Fields_Model_Store' )
			&& Uich_Fields_Model_Store::is_writable();
	}

	/**
	 * Record an export failure without disturbing the save that triggered it.
	 *
	 * @param WP_Error $error The failure.
	 * @return WP_Error The same error, for the caller to return.
	 */
	private static function fail( $error ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'time'    => time(),
			),
			false
		);
		return $error;
	}

	/**
	 * Clear a previously recorded failure after a successful export.
	 */
	private static function clear_error() {
		if ( false !== get_option( self::ERROR_OPTION, false ) ) {
			delete_option( self::ERROR_OPTION );
		}
	}

	/**
	 * The last export failure, if any.
	 *
	 * @return array|false
	 */
	public static function last_error() {
		return get_option( self::ERROR_OPTION, false );
	}
}
