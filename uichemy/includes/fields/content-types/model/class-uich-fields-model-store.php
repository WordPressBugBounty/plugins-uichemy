<?php
/**
 * Reading and writing one definition file.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Store
 */
class Uich_Fields_Model_Store {

	/**
	 * Largest definition file that will be read, in bytes.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Deepest nesting json_decode() will accept.
	 */
	const MAX_DEPTH = 32;

	/** Keys stripped on write: a database ID in a file makes the model non-portable between sites. */
	const VOLATILE_KEYS = array( 'id', 'ID', 'post_id', 'ID_', 'definition_id' );

	/**
	 * Create the directories for one site, with their access guards.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function ensure_dirs( $blog_id = 0 ) {
		$root = Uich_Fields_Model_Paths::model_dir();
		$dirs = array( $root, Uich_Fields_Model_Paths::site_dir( $blog_id ) );
		foreach ( array_keys( Uich_Fields_Model_Paths::kind_dirs() ) as $kind ) {
			$dir = Uich_Fields_Model_Paths::kind_dir( $kind, $blog_id );
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			$dirs[] = $dir;
		}

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'uich_model_mkdir_failed',
					sprintf(
						/* translators: %s: directory path */
						__( 'Could not create the model directory at %s.', 'uichemy' ),
						$dir
					)
				);
			}
		}

		self::write_guards( $root );
		return true;
	}

	/**
	 * Block direct HTTP access to the model directory.
	 *
	 * @param string $root Model root directory.
	 */
	private static function write_guards( $root ) {
		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not initialised this early and this is a fixed local path inside wp-content.
		}

		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Uichemy model definitions. Not web-accessible.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				. "\n# nginx has no equivalent of this file. Add to the server block:\n"
				. "#   location ~* /wp-content/uichemy-fields/ { deny all; }\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as above.
		}
	}

	/**
	 * Whether the model can currently be written.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return bool
	 */
	public static function is_writable( $blog_id = 0 ) {
		$dir = Uich_Fields_Model_Paths::site_dir( $blog_id );
		if ( is_dir( $dir ) ) {
			return wp_is_writable( $dir );
		}
		// Not created yet — the question is whether we could create it.
		$parent = Uich_Fields_Model_Paths::model_dir();
		if ( is_dir( $parent ) ) {
			return wp_is_writable( $parent );
		}
		return wp_is_writable( WP_CONTENT_DIR );
	}

	/**
	 * Serialise a definition to the exact bytes that belong in its file.
	 *
	 * @param array $data Definition data.
	 * @return string
	 */
	public static function encode( $data ) {
		$data                    = self::strip_volatile( (array) $data );
		$data['$schema_version'] = Uich_Fields_Model_Paths::SCHEMA_VERSION;
		$data                    = self::sort_deep( $data );

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '';
		}
		// A trailing newline so the file is a well-formed text file and git does not report
		// "\ No newline at end of file" on every definition.
		return $json . "\n";
	}

	/**
	 * Order keys deterministically, without disturbing lists.
	 *
	 * @param mixed  $value Any value from a definition.
	 * @param string $key   Key this value sits under (order-significant maps are kept as authored).
	 * @return mixed
	 */
	private static function sort_deep( $value, $key = '' ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = ( array_keys( $value ) === range( 0, count( $value ) - 1 ) );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::sort_deep( $v, (string) $k );
		}
		// `choices` is a value => label map whose order is the order the editor shows, so it is never sorted.
		if ( ! $is_list && 'choices' !== $key ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * Remove keys that must never reach a file.
	 *
	 * @param array $data Definition data.
	 * @return array
	 */
	private static function strip_volatile( $data ) {
		foreach ( self::VOLATILE_KEYS as $k ) {
			unset( $data[ $k ] );
		}
		return $data;
	}

	/**
	 * Write one definition, atomically.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param array  $data    Definition data.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function write( $kind, $key, $data, $blog_id = 0 ) {
		$path = Uich_Fields_Model_Paths::file_for_key( $kind, $key, $blog_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! self::is_writable( $blog_id ) ) {
			return new WP_Error(
				'uich_model_read_only',
				__( 'The model directory is not writable, so the model is read-only. Definitions are managed from files.', 'uichemy' )
			);
		}
		$ready = self::ensure_dirs( $blog_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$json = self::encode( $data );
		if ( '' === $json ) {
			return new WP_Error( 'uich_model_encode_failed', __( 'Definition could not be encoded as JSON.', 'uichemy' ) );
		}
		// The reader refuses a file over MAX_BYTES, so writing one would report success and then hide the definition.
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return self::too_large_error( strlen( $json ) );
		}

		return self::atomic_put( $path, $json );
	}

	/**
	 * Error for a definition whose file would exceed what the reader accepts.
	 *
	 * @param int $bytes Encoded size.
	 * @return WP_Error
	 */
	public static function too_large_error( $bytes ) {
		return new WP_Error(
			'uich_model_too_large',
			sprintf(
				/* translators: 1: size in MB, 2: limit in MB */
				__( 'This definition is %1$s MB, which is over the %2$s MB limit for one definition file. Split it into smaller groups.', 'uichemy' ),
				number_format_i18n( $bytes / MB_IN_BYTES, 1 ),
				number_format_i18n( self::MAX_BYTES / MB_IN_BYTES, 0 )
			),
			array( 'status' => 413 )
		);
	}

	/**
	 * Size the definition would have on disk, for a preview that must not write.
	 *
	 * @param array $data Definition data.
	 * @return int Encoded size in bytes.
	 */
	public static function encoded_size( $data ) {
		return strlen( self::encode( $data ) );
	}

	/**
	 * Write bytes so a reader never observes a partial file.
	 *
	 * @param string $path  Destination.
	 * @param string $bytes Contents.
	 * @return true|WP_Error
	 */
	private static function atomic_put( $path, $bytes ) {
		$dir = dirname( $path );
		$tmp = $dir . '/.' . wp_unique_id( 'uichmodel-' ) . '.tmp';

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem offers no atomic write; the temp-then-rename sequence is the point of this method.
		$handle = fopen( $tmp, 'wb' );
		if ( false === $handle ) {
			return new WP_Error( 'uich_model_tmp_failed', __( 'Could not open a temporary file for writing.', 'uichemy' ) );
		}

		$written = fwrite( $handle, $bytes );
		if ( false === $written || strlen( $bytes ) !== $written ) {
			fclose( $handle );
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file inside the model directory; wp_delete_file() runs a filter that could refuse and strand it.
			return new WP_Error( 'uich_model_write_short', __( 'Definition file was not written completely.', 'uichemy' ) );
		}

		// Flush userspace buffers, then ask the OS to commit.
		fflush( $handle );
		if ( function_exists( 'fsync' ) ) {
			@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some filesystems (network mounts) legitimately refuse fsync; the rename below is what guarantees atomicity.
		}
		fclose( $handle );

		// WP_Filesystem::move() is the standard's suggestion, but it is copy+delete on several transports, and a
		// reader that opens the file mid-copy would see a half-written definition.
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above; the return value is checked and the failure is recorded.
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file; see above.
			return new WP_Error(
				'uich_model_rename_failed',
				sprintf(
					/* translators: %s: destination path */
					__( 'Could not move the temporary file into place at %s.', 'uichemy' ),
					$path
				)
			);
		}
		// phpcs:enable

		return true;
	}

	/**
	 * Read and decode one definition.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return array|WP_Error
	 */
	public static function read( $kind, $key, $blog_id = 0 ) {
		$path = Uich_Fields_Model_Paths::file_for_key( $kind, $key, $blog_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		return self::read_file( $path );
	}

	/**
	 * Write a sidecar file — an index or a map that belongs beside the definitions but is not one.
	 *
	 * @param string $name    File name, dot-prefixed so the loader skips it.
	 * @param array  $data    Contents.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function write_sidecar( $name, $data, $blog_id = 0 ) {
		if ( ! is_string( $name ) || '' === $name || 0 !== strpos( $name, '.' ) || false !== strpbrk( $name, '/\\' ) ) {
			return new WP_Error( 'uich_model_bad_sidecar', __( 'A sidecar file must be a dot-prefixed name with no path separators.', 'uichemy' ) );
		}
		$dir = Uich_Fields_Model_Paths::site_dir( $blog_id );
		if ( ! is_dir( $dir ) ) {
			$ready = self::ensure_dirs( $blog_id );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}
		return self::atomic_put( $dir . '/' . $name, self::encode( $data ) );
	}

	/**
	 * Read a sidecar written by write_sidecar().
	 *
	 * A missing sidecar is not an error — it means nothing has been recorded yet.
	 *
	 * @param string $name    File name.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return array Empty when absent or unreadable.
	 */
	public static function read_sidecar( $name, $blog_id = 0 ) {
		$path = Uich_Fields_Model_Paths::site_dir( $blog_id ) . '/' . $name;
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$data = self::read_file( $path );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read and decode a definition file by path.
	 *
	 * @param string $path Absolute path.
	 * @return array|WP_Error
	 */
	public static function read_file( $path ) {
		$path = wp_normalize_path( (string) $path );

		if ( ! Uich_Fields_Model_Paths::is_inside_model_dir( $path ) ) {
			return new WP_Error( 'uich_model_path_escape', __( 'Refused to read a file outside the model directory.', 'uichemy' ) );
		}
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'uich_model_not_found', __( 'Definition file does not exist.', 'uichemy' ) );
		}

		// Size is checked before reading so an oversized file never reaches memory; clearstatcache() is required
		// because PHP caches stat results per path within a request.
		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return new WP_Error(
				'uich_model_too_large',
				sprintf(
					/* translators: 1: file path, 2: maximum size in bytes */
					__( 'Definition file %1$s is larger than the %2$d byte limit and was not read.', 'uichemy' ),
					basename( $path ),
					self::MAX_BYTES
				)
			);
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a size-capped read of a local path already proven to be inside the model directory.
		if ( false === $raw ) {
			return new WP_Error( 'uich_model_unreadable', __( 'Definition file could not be read.', 'uichemy' ) );
		}

		return self::decode( $raw, basename( $path ) );
	}

	/**
	 * Decode definition JSON with every structural guard applied.
	 *
	 * @param string $raw   File contents.
	 * @param string $label Filename, for error messages.
	 * @return array|WP_Error
	 */
	public static function decode( $raw, $label = '' ) {
		$raw = (string) $raw;

		// Invalid UTF-8 makes json_decode() fail anyway, but with a generic syntax error; checking
		// first means the log names the actual problem.
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			if ( function_exists( 'mb_check_encoding' ) ) {
				return new WP_Error(
					'uich_model_bad_encoding',
					sprintf(
						/* translators: %s: filename */
						__( 'Definition file %s is not valid UTF-8.', 'uichemy' ),
						$label
					)
				);
			}
		}

		$data = json_decode( $raw, true, self::MAX_DEPTH );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'uich_model_bad_json',
				sprintf(
					/* translators: 1: filename, 2: JSON error message */
					__( 'Definition file %1$s is not valid JSON: %2$s', 'uichemy' ),
					$label,
					json_last_error_msg()
				)
			);
		}
		// A definition is a JSON object. json_decode( …, true ) gives an array for both an object
		// and a list, so the two are told apart by their keys: a non-empty list has keys 0..n-1.
		$is_list = is_array( $data ) && array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 );
		if ( ! is_array( $data ) || $is_list ) {
			// A bare list, string or number is not a definition.
			return new WP_Error(
				'uich_model_not_object',
				sprintf(
					/* translators: %s: filename */
					__( 'Definition file %s does not contain a JSON object.', 'uichemy' ),
					$label
				)
			);
		}

		$version = isset( $data['$schema_version'] ) ? $data['$schema_version'] : null;
		if ( ! is_int( $version ) ) {
			return new WP_Error(
				'uich_model_no_schema_version',
				sprintf(
					/* translators: %s: filename */
					__( 'Definition file %s has no numeric $schema_version.', 'uichemy' ),
					$label
				)
			);
		}
		if ( $version > Uich_Fields_Model_Paths::SCHEMA_VERSION ) {
			// Written by a NEWER version of the plugin. Refusing loudly is correct: guessing at a
			// shape we do not know would silently drop whatever the newer version added.
			return new WP_Error(
				'uich_model_future_schema',
				sprintf(
					/* translators: 1: filename, 2: file version, 3: supported version */
					__( 'Definition file %1$s uses schema version %2$d, but this version of Uichemy understands only %3$d. Update the plugin.', 'uichemy' ),
					$label,
					$version,
					Uich_Fields_Model_Paths::SCHEMA_VERSION
				)
			);
		}

		return $data;
	}

	/**
	 * Delete one definition file.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function delete( $kind, $key, $blog_id = 0 ) {
		$path = Uich_Fields_Model_Paths::file_for_key( $kind, $key, $blog_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local path already proven inside the model directory.
			return new WP_Error( 'uich_model_delete_failed', __( 'Definition file could not be deleted.', 'uichemy' ) );
		}
		return true;
	}

	/**
	 * Change a definition's key, keeping its identity through an interruption.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $from    Current key.
	 * @param string $to      New key.
	 * @param string $key_field Field inside the file that holds the key.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function rename( $kind, $from, $to, $key_field, $blog_id = 0 ) {
		if ( $from === $to ) {
			return true;
		}
		$src = Uich_Fields_Model_Paths::file_for_key( $kind, $from, $blog_id );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$dst = Uich_Fields_Model_Paths::file_for_key( $kind, $to, $blog_id );
		if ( is_wp_error( $dst ) ) {
			return $dst;
		}
		if ( file_exists( $dst ) ) {
			return new WP_Error(
				'uich_model_rename_target_exists',
				sprintf(
					/* translators: %s: the key being renamed to */
					__( 'A definition with the key "%s" already exists.', 'uichemy' ),
					$to
				)
			);
		}

		$data = self::read_file( $src );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Step 1 — the key inside the file becomes the new one, atomically.
		$data[ $key_field ] = $to;
		$step1              = self::atomic_put( $src, self::encode( $data ) );
		if ( is_wp_error( $step1 ) ) {
			return $step1;
		}

		// Step 2 — the filename catches up.
		if ( ! rename( $src, $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- both paths are inside the model directory and already validated.
			// Deliberately NOT rolled back.
			return new WP_Error(
				'uich_model_rename_incomplete',
				sprintf(
					/* translators: 1: old filename, 2: new key */
					__( 'The definition was renamed to "%2$s" but the file %1$s could not be renamed yet. It will be corrected on the next model scan.', 'uichemy' ),
					basename( $src ),
					$to
				)
			);
		}

		return true;
	}

	/**
	 * Every definition file of one kind for one site.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return string[] Absolute paths.
	 */
	public static function list_files( $kind, $blog_id = 0 ) {
		$dir = Uich_Fields_Model_Paths::kind_dir( $kind, $blog_id );
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return array();
		}
		$found = glob( $dir . '/*.json' );
		if ( ! $found ) {
			return array();
		}
		// Dot-prefixed names are ours (temp files, the index); never treat one as a definition.
		$out = array();
		foreach ( $found as $path ) {
			if ( '.' === substr( basename( $path ), 0, 1 ) ) {
				continue;
			}
			$out[] = wp_normalize_path( $path );
		}
		sort( $out );
		return $out;
	}
}
