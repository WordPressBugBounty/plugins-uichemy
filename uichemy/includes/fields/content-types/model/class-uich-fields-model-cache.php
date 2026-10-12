<?php
/**
 * The derived cache: the database copy of a model whose source of truth is on disk.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Cache
 */
class Uich_Fields_Model_Cache {

	/** Option holding the derived model. NEVER autoloaded — it is the size of the whole model. */
	const OPTION = 'uich_model_cache';

	/** Generated. Its mtime and size are the fast path's entire validity check. */
	const INDEX_FILE = '.index.json';

	/**
	 * Request-level memo, so repeated reads in one request cost nothing at all.
	 *
	 * @var array<int, array|null>
	 */
	private static $memo = array();

	/**
	 * The model, rebuilding only if the cache is stale.
	 *
	 * @param int  $blog_id Blog ID, or 0 for the current one.
	 * @param bool $force   Skip every fast path and rescan.
	 * @return array{definitions:array, problems:array, hashes:array, model_hash:string, built:int}
	 */
	public static function get( $blog_id = 0, $force = false ) {
		$blog_id = $blog_id ? absint( $blog_id ) : get_current_blog_id();

		if ( ! $force && isset( self::$memo[ $blog_id ] ) ) {
			return self::$memo[ $blog_id ];
		}

		if ( ! $force ) {
			$cached = get_option( self::OPTION, null );
			if ( is_array( $cached ) && self::is_fresh( $cached, $blog_id ) ) {
				self::$memo[ $blog_id ] = $cached;
				return $cached;
			}
		}

		$model                  = self::rebuild( $blog_id );
		self::$memo[ $blog_id ] = $model;
		return $model;
	}

	/**
	 * Whether a cached model still matches the disk, at the cost of one stat().
	 *
	 * @param array $cached  Cached model.
	 * @param int   $blog_id Blog ID.
	 * @return bool
	 */
	private static function is_fresh( $cached, $blog_id ) {
		if ( defined( 'UICH_FIELDS_MODEL_DEV' ) && UICH_FIELDS_MODEL_DEV ) {
			// Local development: always rescan, so an edited file is visible immediately.
			return false;
		}
		if ( ! isset( $cached['index'] ) || ! is_array( $cached['index'] ) ) {
			return false;
		}

		$index = Uich_Fields_Model_Paths::site_dir( $blog_id ) . '/' . self::INDEX_FILE;
		clearstatcache( true, $index );
		if ( is_file( $index ) ) {
			$mtime = filemtime( $index );
			$size  = filesize( $index );

			if ( ! isset( $cached['index']['mtime'], $cached['index']['size'] )
				|| (int) $cached['index']['mtime'] !== (int) $mtime
				|| (int) $cached['index']['size'] !== (int) $size ) {
				return false;
			}
			// Files added, removed or edited by hand (a deploy, or a restored database beside untouched
			// files) never touch the index, so the file listing has to agree as well.
			return isset( $cached['listing'] ) && is_string( $cached['listing'] )
				&& hash_equals( $cached['listing'], self::listing_signature( $blog_id ) );
		}

		// No index file.
		return isset( $cached['listing'] ) && is_string( $cached['listing'] )
			&& hash_equals( $cached['listing'], self::listing_signature( $blog_id ) );
	}

	/**
	 * A signature of what is on disk, built without opening a single definition file.
	 *
	 * Public so the definitions cache can validate itself against the same view of the disk.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	public static function listing_signature( $blog_id = 0 ) {
		$parts = array();
		foreach ( array_keys( Uich_Fields_Model_Paths::kind_dirs() ) as $kind ) {
			$dir = Uich_Fields_Model_Paths::kind_dir( $kind, $blog_id );
			if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
				$parts[] = $kind . ':-';
				continue;
			}
			$files = glob( $dir . '/*.json' );
			if ( ! is_array( $files ) ) {
				$files = array();
			}
			sort( $files );
			$rows = array();
			foreach ( $files as $file ) {
				clearstatcache( true, $file );
				$rows[] = basename( $file ) . ':' . (int) filesize( $file ) . ':' . (int) filemtime( $file );
			}
			$parts[] = $kind . ':' . implode( ',', $rows );
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Scan, repair, validate and store.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return array
	 */
	public static function rebuild( $blog_id = 0 ) {
		$blog_id = $blog_id ? absint( $blog_id ) : get_current_blog_id();

		$scan = self::may_write()
			? Uich_Fields_Model_Loader::scan_and_repair( $blog_id )
			: Uich_Fields_Model_Loader::scan( $blog_id );

		$scan = Uich_Fields_Model_Validator::validate_scan( $scan );

		$hashes = self::hash_files( $blog_id );
		$drift  = self::detect_drift( $hashes );

		$model = array(
			'definitions' => isset( $scan['definitions'] ) ? $scan['definitions'] : array(),
			'problems'    => isset( $scan['problems'] ) ? $scan['problems'] : array(),
			'hashes'      => $hashes,
			'model_hash'  => md5( (string) wp_json_encode( $hashes ) ),
			'built'       => time(),
			'index'       => array(
				'mtime' => 0,
				'size'  => 0,
			),
			// The fallback freshness signal, for requests that cannot write an index file.
			'listing'     => self::listing_signature( $blog_id ),
		);

		if ( $drift ) {
			$model['problems'] = array_merge( $model['problems'], $drift );
		}

		// Two different writes, with two different risk profiles, and they were gated together.
		$may_write_files = self::may_write();
		if ( $may_write_files ) {
			$model['index'] = self::write_index( $model, $blog_id );
		}
		if ( $may_write_files || self::may_cache() ) {
			update_option( self::OPTION, $model, false );
		}

		self::$memo[ $blog_id ] = $model;
		return $model;
	}

	/**
	 * Whether this request may store the rebuilt model in an option.
	 *
	 * @return bool
	 */
	private static function may_cache() {
		return ! wp_installing();
	}

	/**
	 * Whether this request is allowed to write during a rebuild.
	 *
	 * A front-end page view is not. Anything that is already an administrative action is.
	 *
	 * @return bool
	 */
	private static function may_write() {
		if ( ! Uich_Fields_Model_Store::is_writable() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		if ( is_admin() ) {
			return true;
		}
		// A REST write, but not a REST read: a GET from an anonymous visitor is a front-end
		// request wearing a different hat.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			return in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		}
		return false;
	}

	/**
	 * A hash of every definition file, keyed by its path relative to the site directory.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array<string, string>
	 */
	public static function hash_files( $blog_id = 0 ) {
		$out  = array();
		$root = Uich_Fields_Model_Paths::site_dir( $blog_id );

		foreach ( array_keys( Uich_Fields_Model_Paths::kind_dirs() ) as $kind ) {
			foreach ( Uich_Fields_Model_Store::list_files( $kind, $blog_id ) as $path ) {
				$rel = ltrim( str_replace( $root, '', wp_normalize_path( $path ) ), '/' );
				$md5 = md5_file( $path );
				if ( false !== $md5 ) {
					$out[ $rel ] = $md5;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Files whose contents changed without the UI having written them.
	 *
	 * @param array $current Current hashes.
	 * @return array<int, array> Problems.
	 */
	private static function detect_drift( $current ) {
		$previous = get_option( self::OPTION, null );
		if ( ! is_array( $previous ) || empty( $previous['hashes'] ) || ! is_array( $previous['hashes'] ) ) {
			// Nothing to compare against — a first build is not drift.
			return array();
		}

		$out = array();
		foreach ( $current as $rel => $hash ) {
			if ( isset( $previous['hashes'][ $rel ] ) && $previous['hashes'][ $rel ] !== $hash ) {
				$out[] = array(
					'type'    => 'file_changed_outside_ui',
					'kind'    => '',
					'key'     => '',
					'file'    => $rel,
					'code'    => '',
					'message' => sprintf(
						/* translators: %s: file path relative to the model directory */
						__( '%s changed outside the Uichemy admin. This is a notice, not a block — anything able to write here can change the model.', 'uichemy' ),
						$rel
					),
				);
			}
		}
		foreach ( array_keys( $previous['hashes'] ) as $rel ) {
			if ( ! isset( $current[ $rel ] ) ) {
				$out[] = array(
					'type'    => 'file_removed_outside_ui',
					'kind'    => '',
					'key'     => '',
					'file'    => $rel,
					'code'    => '',
					'message' => sprintf(
						/* translators: %s: file path relative to the model directory */
						__( '%s was removed outside the Uichemy admin.', 'uichemy' ),
						$rel
					),
				);
			}
		}

		return $out;
	}

	/**
	 * Write `.index.json` and return the stat the fast path will compare against.
	 *
	 * @param array $model   The model being cached.
	 * @param int   $blog_id Blog ID.
	 * @return array{mtime:int, size:int}
	 */
	private static function write_index( $model, $blog_id ) {
		$dir = Uich_Fields_Model_Paths::site_dir( $blog_id );
		if ( ! is_dir( $dir ) ) {
			$ready = Uich_Fields_Model_Store::ensure_dirs( $blog_id );
			if ( is_wp_error( $ready ) ) {
				return array(
					'mtime' => 0,
					'size'  => 0,
				);
			}
		}

		$path    = $dir . '/' . self::INDEX_FILE;
		$payload = array(
			'$schema_version' => Uich_Fields_Model_Paths::SCHEMA_VERSION,
			'_generated'      => 'This file is generated by Uichemy. Do not edit it by hand.',
			'built'           => (int) $model['built'],
			'model_hash'      => (string) $model['model_hash'],
			'files'           => (array) $model['hashes'],
		);

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return array(
				'mtime' => 0,
				'size'  => 0,
			);
		}

		// Same atomic discipline as a definition: a half-written index would make the fast path
		// compare against nonsense.
		$tmp = $dir . '/.' . wp_unique_id( 'uichindex-' ) . '.tmp';
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem has no atomic write; the temp-then-rename is the point.
		if ( false === file_put_contents( $tmp, $json . "\n" ) || ! rename( $tmp, $path ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			return array(
				'mtime' => 0,
				'size'  => 0,
			);
		}
		// phpcs:enable

		clearstatcache( true, $path );
		return array(
			'mtime' => (int) filemtime( $path ),
			'size'  => (int) filesize( $path ),
		);
	}

	/**
	 * Drop the cache so the next read rebuilds.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 */
	public static function flush( $blog_id = 0 ) {
		$blog_id = $blog_id ? absint( $blog_id ) : get_current_blog_id();
		unset( self::$memo[ $blog_id ] );
		delete_option( self::OPTION );
	}
}
