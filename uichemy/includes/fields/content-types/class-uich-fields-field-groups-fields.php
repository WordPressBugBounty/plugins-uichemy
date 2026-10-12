<?php
/**
 * Field registry for Field Groups (Group Fields).
 * Defines schema, sanitization, and defaults for field group configuration.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Field_Groups_Fields
 */
class Uich_Fields_Field_Groups_Fields {

	const TYPE_KEY      = 'key';
	const TYPE_TEXT     = 'text';
	const TYPE_TEXTAREA = 'textarea';
	const TYPE_BOOL     = 'bool';
	const TYPE_INT      = 'int';
	const TYPE_JSON     = 'json';
	const TYPE_ARRAY    = 'array';

	/**
	 * Field group meta keys and their sanitize type.
	 *
	 * @return array<string, string>
	 */
	public static function get_fields() {
		return array(
			'kind'                  => self::TYPE_KEY,
			'title'                 => self::TYPE_TEXT,
			'key'                   => self::TYPE_KEY,
			'description'           => self::TYPE_TEXTAREA,
			'fields'                => self::TYPE_JSON,
			'location_rules'        => self::TYPE_JSON,
			'position'              => self::TYPE_TEXT,
			'style'                 => self::TYPE_TEXT,
			'label_placement'       => self::TYPE_TEXT,
			'instruction_placement' => self::TYPE_TEXT,
			'order_no'              => self::TYPE_INT,
			'active'                => self::TYPE_BOOL,
			'show_in_rest'          => self::TYPE_BOOL,
		);
	}

	/**
	 * Default values for field group meta.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		return array(
			'title'                 => '',
			'key'                   => '',
			'description'           => '',
			'fields'                => array(),
			'location_rules'        => array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'post',
				),
			),
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'order_no'              => 0,
			'active'                => true,
			// Opt-in: an exposed value is public on any published post.
			'show_in_rest'          => false,
		);
	}

	/**
	 * Sanitize a single value by type.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  One of TYPE_* constants.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_value( $value, $type ) {
		switch ( $type ) {
			case self::TYPE_KEY:
				return is_string( $value ) ? sanitize_key( $value ) : '';
			case self::TYPE_TEXT:
				return isset( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
			case self::TYPE_TEXTAREA:
				return isset( $value ) ? sanitize_textarea_field( wp_unslash( $value ) ) : '';
			case self::TYPE_BOOL:
				return ! empty( $value );
			case self::TYPE_INT:
				return isset( $value ) && '' !== $value ? (int) $value : 0;
			case self::TYPE_JSON:
				if ( is_array( $value ) ) {
					return self::sanitize_config_tree( $value );
				}
				if ( is_string( $value ) ) {
					$decoded = json_decode( wp_unslash( $value ), true );
					return is_array( $decoded ) ? self::sanitize_config_tree( $decoded ) : array();
				}
				return array();
			case self::TYPE_ARRAY:
				return is_array( $value ) ? $value : array();
			default:
				return $value;
		}
	}

	/**
	 * Recursively sanitize a field-config / location-rules tree (labels, names, instructions, defaults,
	 * choices, conditional logic, nested repeater subfields, etc.).
	 *
	 * @param mixed $value Config subtree.
	 * @return mixed
	 */
	public static function sanitize_config_tree( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				// Sanitize string keys too.
				$clean_k         = is_string( $k ) ? sanitize_text_field( $k ) : $k;
				$out[ $clean_k ] = self::sanitize_config_value( $clean_k, $v );
			}
			return $out;
		}
		return self::sanitize_config_value( '', $value );
	}

	/**
	 * Sanitize one config value by its (case-insensitive) key name. Arrays recurse.
	 *
	 * @param mixed $key   Array key the value sits under.
	 * @param mixed $value The value.
	 * @return mixed
	 */
	private static function sanitize_config_value( $key, $value ) {
		if ( is_array( $value ) ) {
			return self::sanitize_config_tree( $value );
		}
		// Preserve non-string scalars (bool/int/float) and null untouched.
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$rich = array( 'default_value', 'instructions', 'message', 'description', 'content' );
		if ( is_string( $key ) && in_array( strtolower( $key ), $rich, true ) ) {
			return wp_kses_post( $value );
		}

		// Values that are legitimately multi-line and are parsed line-by-line downstream.
		$multiline = array( 'choices' );
		if ( is_string( $key ) && in_array( strtolower( $key ), $multiline, true ) ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Remove exact-duplicate { param, operator, value } conditions within each AND group of a location-rules
	 * structure.
	 *
	 * @param mixed $rules Location rules value.
	 * @return mixed De-duplicated rules (unchanged if not a usable array).
	 */
	public static function dedupe_location_rules( $rules ) {
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return $rules;
		}

		$first     = isset( $rules[0] ) ? $rules[0] : null;
		$is_nested = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );
		$groups    = $is_nested ? $rules : array( $rules );

		$clean_groups = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$seen  = array();
			$clean = array();
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$value = isset( $rule['value'] ) ? $rule['value'] : '';
				$sig   = ( isset( $rule['param'] ) ? (string) $rule['param'] : '' ) . '|'
					. ( isset( $rule['operator'] ) ? (string) $rule['operator'] : '' ) . '|'
					. ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
				if ( isset( $seen[ $sig ] ) ) {
					continue;
				}
				$seen[ $sig ] = true;
				$clean[]      = $rule;
			}
			if ( ! empty( $clean ) ) {
				$clean_groups[] = $clean;
			}
		}

		if ( empty( $clean_groups ) ) {
			return $rules;
		}

		return $is_nested ? $clean_groups : $clean_groups[0];
	}
}
