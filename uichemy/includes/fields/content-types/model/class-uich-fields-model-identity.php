<?php
/**
 * Stable numeric identity for a definition that no longer has a post row.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Identity
 */
class Uich_Fields_Model_Identity {

	/**
	 * Upper bound for a generated id.
	 */
	const MAX_ID = 2147480000;

	/**
	 * Ids below this are never handed out, so a real post ID from the old storage can never be
	 * mistaken for a derived one during the transition.
	 */
	const MIN_ID = 100000;

	/**
	 * Base value for a kind + key pair, before collision resolution.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param bool   $trashed Whether this is the trashed copy of that key.
	 * @return int
	 */
	public static function base_id( $kind, $key, $trashed = false ) {
		// The kind is part of the input so a post type and a taxonomy sharing a key — which is allowed, they live
		// in different namespaces — do not collide by construction.
		$hash = crc32( $kind . '|' . $key . ( $trashed ? '|#trash' : '' ) );
		$hash = (int) sprintf( '%u', $hash );

		return self::MIN_ID + ( $hash % ( self::MAX_ID - self::MIN_ID ) );
	}

	/**
	 * Index into the id map.
	 *
	 * @param string $kind    Model kind.
	 * @param string $key     Definition key.
	 * @param bool   $trashed Whether this is the trashed copy.
	 * @return string
	 */
	public static function map_key( $kind, $key, $trashed = false ) {
		return $kind . '|' . $key . ( $trashed ? '|#trash' : '' );
	}

	/**
	 * Assign ids to a whole model at once, resolving any collisions.
	 *
	 * @param array $definitions Live definitions, keyed kind => key => data.
	 * @param array $trashed     Trashed definitions, same shape.
	 * @return array{ids: array<string, int>, keys: array<int, array{kind: string, key: string, trashed: bool}>}
	 */
	public static function map( $definitions, $trashed = array() ) {
		$ids  = array();
		$keys = array();

		$pairs = array();
		foreach ( (array) $definitions as $kind => $defs ) {
			foreach ( (array) $defs as $key => $unused ) {
				$pairs[] = array( (string) $kind, (string) $key, false );
			}
		}
		foreach ( (array) $trashed as $kind => $defs ) {
			foreach ( (array) $defs as $key => $unused ) {
				$pairs[] = array( (string) $kind, (string) $key, true );
			}
		}
		usort(
			$pairs,
			function ( $a, $b ) {
				$cmp = strcmp( $a[0], $b[0] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$cmp = strcmp( $a[1], $b[1] );
				return 0 !== $cmp ? $cmp : ( ( $a[2] === $b[2] ) ? 0 : ( $a[2] ? 1 : -1 ) );
			}
		);

		foreach ( $pairs as $pair ) {
			list( $kind, $key, $is_trashed ) = $pair;

			$id = self::base_id( $kind, $key, $is_trashed );
			// Walk upward until a free slot is found. Wraps at the top so the walk cannot run off
			// the end of the range on a pathological set.
			$guard = 0;
			while ( isset( $keys[ $id ] ) ) {
				++$id;
				if ( $id > self::MAX_ID ) {
					$id = self::MIN_ID;
				}
				if ( ++$guard > 1000 ) {
					break;
				}
			}

			$ids[ self::map_key( $kind, $key, $is_trashed ) ] = $id;
			$keys[ $id ]                                      = array(
				'kind'    => $kind,
				'key'     => $key,
				'trashed' => $is_trashed,
			);
		}

		return array(
			'ids'  => $ids,
			'keys' => $keys,
		);
	}
}
