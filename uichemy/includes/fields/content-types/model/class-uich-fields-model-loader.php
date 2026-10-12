<?php
/**
 * Assembling the model from its files.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Loader
 */
class Uich_Fields_Model_Loader {

	/**
	 * Read the whole model for one site.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return array{definitions:array<string,array<string,array>>, problems:array<int,array>, repairs:array<int,array>}
	 */
	public static function scan( $blog_id = 0 ) {
		$out = array(
			'definitions' => array(),
			'problems'    => array(),
			'repairs'     => array(),
		);

		foreach ( array_keys( Uich_Fields_Model_Paths::kind_dirs() ) as $kind ) {
			$one                         = self::scan_kind( $kind, $blog_id );
			$out['definitions'][ $kind ] = $one['definitions'];
			$out['problems']             = array_merge( $out['problems'], $one['problems'] );
			$out['repairs']              = array_merge( $out['repairs'], $one['repairs'] );
		}

		return $out;
	}

	/**
	 * Read every definition file of one kind.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return array{definitions:array<string,array>, problems:array<int,array>, repairs:array<int,array>}
	 */
	public static function scan_kind( $kind, $blog_id = 0 ) {
		$definitions = array();
		$problems    = array();
		$repairs     = array();

		$key_field  = Uich_Fields_Model_Paths::key_field( $kind );
		$kind_value = Uich_Fields_Model_Paths::kind_value( $kind );
		if ( '' === $key_field ) {
			return array(
				'definitions' => array(),
				'problems'    => array( self::problem( 'unknown_kind', '', '', sprintf( 'Unknown definition kind "%s".', $kind ) ) ),
				'repairs'     => array(),
			);
		}

		// key => list of files claiming it. Collected first so a duplicate is known before anything
		// is accepted — otherwise the first file read would win by accident of directory order.
		$claims = array();

		foreach ( Uich_Fields_Model_Store::list_files( $kind, $blog_id ) as $path ) {
			$file_key = Uich_Fields_Model_Paths::key_from_file( $path );

			$data = Uich_Fields_Model_Store::read_file( $path );
			if ( is_wp_error( $data ) ) {
				$problems[] = self::problem( 'unreadable', $kind, $file_key, $data->get_error_message(), $path, $data->get_error_code() );
				continue;
			}

			$inner = isset( $data[ $key_field ] ) && is_string( $data[ $key_field ] ) ? $data[ $key_field ] : '';
			if ( '' === $inner ) {
				$problems[] = self::problem(
					'missing_key',
					$kind,
					$file_key,
					/* translators: %s: name of the key field */
					sprintf( __( 'The file has no "%s", so there is nothing to identify it by.', 'uichemy' ), $key_field ),
					$path
				);
				continue;
			}

			// The key is what everything else keys off, so it is validated with the same rules that
			// would have applied had it arrived over REST.
			$valid = Uich_Fields_Model_Paths::validate_key( $kind, $inner );
			if ( is_wp_error( $valid ) ) {
				$problems[] = self::problem( 'invalid_key', $kind, $inner, $valid->get_error_message(), $path, $valid->get_error_code() );
				continue;
			}

			// A file that says it is a taxonomy while sitting in post-types/ has been moved by hand or by a bad
			// deploy.
			$declared = isset( $data['kind'] ) && is_string( $data['kind'] ) ? $data['kind'] : '';
			if ( '' !== $declared && $declared !== $kind_value ) {
				$problems[] = self::problem(
					'kind_mismatch',
					$kind,
					$inner,
					/* translators: 1: kind the file declares, 2: directory kind it sits in */
					sprintf( __( 'The file says it is "%1$s" but sits in the %2$s directory.', 'uichemy' ), $declared, $kind ),
					$path
				);
				continue;
			}

			if ( ! isset( $claims[ $inner ] ) ) {
				$claims[ $inner ] = array();
			}
			$claims[ $inner ][] = array(
				'path' => $path,
				'data' => $data,
				'name' => $file_key,
			);
		}

		foreach ( $claims as $key => $files ) {
			if ( count( $files ) > 1 ) {
				$names = array();
				foreach ( $files as $f ) {
					$names[] = basename( $f['path'] );
				}
				sort( $names );
				$problems[] = self::problem(
					'duplicate_key',
					$kind,
					$key,
					sprintf(
						/* translators: 1: number of files, 2: definition key, 3: comma-separated file names */
						_n(
							'%1$d file claims the key "%2$s" (%3$s). It was not loaded.',
							'%1$d files claim the key "%2$s" (%3$s). None of them was loaded — remove or re-key all but one.',
							count( $files ),
							'uichemy'
						),
						count( $files ),
						$key,
						implode( ', ', $names )
					)
				);
				continue;
			}

			$file                = $files[0];
			$definitions[ $key ] = $file['data'];

			// The filename is a cache of the key. When they disagree the key wins and the name is
			// scheduled to catch up; the model itself is already correct either way.
			if ( $file['name'] !== $key ) {
				$repairs[] = array(
					'type' => 'rename_file',
					'kind' => $kind,
					'key'  => $key,
					'from' => $file['path'],
					'to'   => Uich_Fields_Model_Paths::file_for_key( $kind, $key, $blog_id ),
					'note' => sprintf( 'File is named "%1$s.json" but holds the key "%2$s".', $file['name'], $key ),
				);
			}
		}

		ksort( $definitions );

		return array(
			'definitions' => $definitions,
			'problems'    => $problems,
			'repairs'     => $repairs,
		);
	}

	/**
	 * Apply the repairs a scan asked for.
	 *
	 * @param array $repairs Repairs from scan().
	 * @param int   $blog_id Blog ID, or 0 for the current one.
	 * @return array{applied:int, failed:array<int,string>}
	 */
	public static function repair( $repairs, $blog_id = 0 ) {
		$applied = 0;
		$failed  = array();

		if ( ! Uich_Fields_Model_Store::is_writable( $blog_id ) ) {
			return array(
				'applied' => 0,
				'failed'  => array( __( 'The model directory is read-only, so nothing was repaired.', 'uichemy' ) ),
			);
		}

		foreach ( (array) $repairs as $repair ) {
			if ( ! is_array( $repair ) || ! isset( $repair['type'] ) || 'rename_file' !== $repair['type'] ) {
				continue;
			}
			$from = isset( $repair['from'] ) ? (string) $repair['from'] : '';
			$to   = isset( $repair['to'] ) ? $repair['to'] : '';

			if ( is_wp_error( $to ) || '' === $from || '' === $to ) {
				/* translators: %s: file name */
				$failed[] = sprintf( __( 'Could not resolve a target for %s.', 'uichemy' ), basename( $from ) );
				continue;
			}
			if ( ! Uich_Fields_Model_Paths::is_inside_model_dir( $from )
				|| ! Uich_Fields_Model_Paths::is_inside_model_dir( $to ) ) {
				/* translators: %s: file name */
				$failed[] = sprintf( __( 'Refused to move %s outside the model directory.', 'uichemy' ), basename( $from ) );
				continue;
			}
			if ( ! file_exists( $from ) ) {
				// Already repaired, by a concurrent request or a previous run.
				continue;
			}
			if ( file_exists( $to ) ) {
				// The correct name is taken by something else. That is a duplicate-key situation
				// wearing different clothes, and it needs a human, not a rename.
				/* translators: 1: file being renamed, 2: file that already has the target name */
				$failed[] = sprintf( __( '%1$s cannot be renamed because %2$s already exists.', 'uichemy' ), basename( $from ), basename( $to ) );
				continue;
			}

			if ( rename( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- both paths are containment-checked immediately above; WP_Filesystem has no equivalent that preserves atomicity.
				++$applied;
			} else {
				/* translators: %s: file name */
				$failed[] = sprintf( __( 'Could not rename %s.', 'uichemy' ), basename( $from ) );
			}
		}

		return array(
			'applied' => $applied,
			'failed'  => $failed,
		);
	}

	/**
	 * Scan, and repair anything repairable, in one call.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return array Scan result, with a `repaired` count added.
	 */
	public static function scan_and_repair( $blog_id = 0 ) {
		$scan = self::scan( $blog_id );
		if ( ! $scan['repairs'] ) {
			$scan['repaired'] = 0;
			return $scan;
		}

		$result = self::repair( $scan['repairs'], $blog_id );
		$again  = self::scan( $blog_id );

		$again['repaired'] = $result['applied'];
		if ( $result['failed'] ) {
			foreach ( $result['failed'] as $message ) {
				$again['problems'][] = self::problem( 'repair_failed', '', '', $message );
			}
		}
		return $again;
	}

	/**
	 * One problem, in the shape the audit and the admin notice both consume.
	 *
	 * @param string $type    Machine type.
	 * @param string $kind    cpt | tax | group_fields, or ''.
	 * @param string $key     Definition key, or ''.
	 * @param string $message Human-readable explanation.
	 * @param string $path    File path, or ''.
	 * @param string $code    Underlying error code, or ''.
	 * @return array
	 */
	private static function problem( $type, $kind, $key, $message, $path = '', $code = '' ) {
		return array(
			'type'    => $type,
			'kind'    => $kind,
			'key'     => $key,
			'file'    => '' === $path ? '' : basename( $path ),
			'code'    => $code,
			'message' => $message,
		);
	}
}
