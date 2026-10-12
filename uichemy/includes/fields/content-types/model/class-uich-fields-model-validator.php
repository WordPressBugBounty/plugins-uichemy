<?php
/**
 * The single validation choke point for definitions arriving from disk.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Validator
 */
class Uich_Fields_Model_Validator {

	/**
	 * Sanitize and validate one definition.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @param array  $data Raw decoded definition.
	 * @return array|WP_Error Sanitized definition.
	 */
	public static function validate( $kind, $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'uich_model_not_array', __( 'Definition is not an object.', 'uichemy' ) );
		}
		$key_field = Uich_Fields_Model_Paths::key_field( $kind );
		if ( '' === $key_field ) {
			return new WP_Error( 'uich_model_bad_kind', __( 'Unknown definition kind.', 'uichemy' ) );
		}

		$key = isset( $data[ $key_field ] ) && is_string( $data[ $key_field ] ) ? $data[ $key_field ] : '';
		$ok  = Uich_Fields_Model_Paths::validate_key( $kind, $key );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// The schema marker is ours, not part of the definition, and must not survive into the
		// sanitized array where an unknown key could be written back to meta.
		unset( $data['$schema_version'] );

		$clean = self::sanitize( $kind, $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// The sanitizer works from a field registry and drops anything it does not know, which means it can also
		// drop the key if the incoming shape was odd.
		$clean[ $key_field ] = $key;

		return $clean;
	}

	/**
	 * Run the module's own sanitizer for this kind.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @param array  $data Raw definition.
	 * @return array|WP_Error
	 */
	private static function sanitize( $kind, $data ) {
		if ( ! class_exists( 'Uich_Fields_Admin_Logic' ) ) {
			return new WP_Error(
				'uich_model_no_sanitizer',
				__( 'The Content Fields module is not loaded, so definitions cannot be validated.', 'uichemy' )
			);
		}

		if ( 'group_fields' === $kind ) {
			return (array) Uich_Fields_Admin_Logic::sanitize_group_fields_meta( $data );
		}

		// Admin_Logic speaks 'cpt' and 'taxonomy'; the model layer's kind for the latter is 'tax'.
		return (array) Uich_Fields_Admin_Logic::sanitize_meta( $data, 'cpt' === $kind ? 'cpt' : 'taxonomy' );
	}

	/**
	 * Capability mappings the sanitizer removed because their target was unsafe.
	 *
	 * @param string $kind  cpt | tax | group_fields.
	 * @param array  $raw   Definition as it arrived.
	 * @param array  $clean Definition after sanitising.
	 * @return array<int, string> Human-readable descriptions of what was dropped.
	 */
	public static function stripped_capabilities( $kind, $raw, $clean ) {
		if ( 'group_fields' === $kind ) {
			return array();
		}
		$before = isset( $raw['capabilities'] ) && is_array( $raw['capabilities'] ) ? $raw['capabilities'] : array();
		if ( ! $before ) {
			return array();
		}
		$after = isset( $clean['capabilities'] ) && is_array( $clean['capabilities'] ) ? $clean['capabilities'] : array();

		$out = array();
		foreach ( $before as $cap_key => $target ) {
			if ( ! is_string( $target ) || '' === $target ) {
				continue;
			}
			if ( ! isset( $after[ $cap_key ] ) || $after[ $cap_key ] !== $target ) {
				$out[] = sprintf(
					/* translators: 1: capability being mapped, 2: the target that was refused */
					__( '"%1$s" was not mapped to "%2$s" — that target is not allowed, so the mapping was dropped.', 'uichemy' ),
					(string) $cap_key,
					$target
				);
			}
		}
		return $out;
	}

	/**
	 * Validate every definition a scan produced, keeping the good ones.
	 *
	 * @param array $scan Result from Uich_Fields_Model_Loader::scan().
	 * @return array The scan, with definitions sanitized and any refusals appended to `problems`.
	 */
	public static function validate_scan( $scan ) {
		if ( ! isset( $scan['definitions'] ) || ! is_array( $scan['definitions'] ) ) {
			return $scan;
		}

		foreach ( $scan['definitions'] as $kind => $defs ) {
			foreach ( (array) $defs as $key => $data ) {
				$clean = self::validate( $kind, $data );
				if ( is_wp_error( $clean ) ) {
					unset( $scan['definitions'][ $kind ][ $key ] );
					$scan['problems'][] = array(
						'type'    => 'invalid_definition',
						'kind'    => $kind,
						'key'     => $key,
						'file'    => $key . '.json',
						'code'    => $clean->get_error_code(),
						'message' => $clean->get_error_message(),
					);
					continue;
				}

				// The definition is accepted — the sanitizer has already made it safe — but anything it had to remove
				// is worth saying out loud rather than leaving as a silent no-op in a file somebody committed.
				foreach ( self::stripped_capabilities( $kind, $data, $clean ) as $message ) {
					$scan['problems'][] = array(
						'type'    => 'capability_stripped',
						'kind'    => $kind,
						'key'     => $key,
						'file'    => $key . '.json',
						'code'    => 'uich_model_unsafe_capability',
						'message' => $message,
					);
				}

				$scan['definitions'][ $kind ][ $key ] = $clean;
			}
		}

		return $scan;
	}
}
