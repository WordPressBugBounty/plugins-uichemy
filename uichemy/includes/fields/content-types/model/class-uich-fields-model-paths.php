<?php
/**
 * Model file paths, key validation and identity.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Model_Paths
 */
class Uich_Fields_Model_Paths {

	/**
	 * Directory under wp-content holding the definition files. Never inside the plugin — plugin
	 * writes are wiped on update.
	 */
	const DIR_NAME = 'uichemy-fields';

	/** Bumped when the on-disk shape changes in a way older readers cannot parse. */
	const SCHEMA_VERSION = 1;

	/**
	 * Internal kind => directory name.
	 *
	 * @return array<string, string>
	 */
	public static function kind_dirs() {
		return array(
			'cpt'          => 'post-types',
			'tax'          => 'taxonomies',
			'group_fields' => 'field-groups',
		);
	}

	/**
	 * Internal kind => the field inside a definition that carries its key.
	 *
	 * @return array<string, string>
	 */
	public static function key_fields() {
		return array(
			'cpt'          => 'post_type_key',
			'tax'          => 'taxonomy_key',
			'group_fields' => 'key',
		);
	}

	/**
	 * The field holding a definition's key, for one kind.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @return string '' for an unknown kind.
	 */
	public static function key_field( $kind ) {
		$fields = self::key_fields();
		return isset( $fields[ $kind ] ) ? $fields[ $kind ] : '';
	}

	/**
	 * Internal kind => the value stored in a definition's own `kind` field.
	 *
	 * @return array<string, string>
	 */
	public static function kind_values() {
		return array(
			'cpt'          => 'cpt',
			'tax'          => 'taxonomy',
			'group_fields' => 'group_fields',
		);
	}

	/**
	 * The `kind` value a definition of this kind should carry.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @return string '' for an unknown kind.
	 */
	public static function kind_value( $kind ) {
		$values = self::kind_values();
		return isset( $values[ $kind ] ) ? $values[ $kind ] : '';
	}

	/**
	 * Longest key each kind allows.
	 *
	 * @return array<string, int>
	 */
	public static function key_max_lengths() {
		return array(
			'cpt'          => 20,
			'tax'          => 32,
			// Group keys are ours; they are only ever a meta-key segment. Capped so a key plus the
			// field name plus the `_uichemy_gf_user_{blog}_` prefix cannot approach MySQL's 255-char meta_key column.
			'group_fields' => 64,
		);
	}

	/**
	 * Names Windows refuses to use for a file, whatever the extension.
	 *
	 * @return string[]
	 */
	public static function reserved_filenames() {
		$out = array( 'con', 'prn', 'aux', 'nul' );
		for ( $i = 1; $i <= 9; $i++ ) {
			$out[] = 'com' . $i;
			$out[] = 'lpt' . $i;
		}
		// Ours: the generated index must never be shadowed by a definition called "index".
		$out[] = 'index';
		return $out;
	}

	/**
	 * Absolute path to the model root, with no trailing slash.
	 *
	 * @return string
	 */
	public static function model_dir() {
		$dir = WP_CONTENT_DIR . '/' . self::DIR_NAME;

		/**
		 * Filter the model root directory.
		 *
		 * @param string $dir Absolute path, no trailing slash.
		 */
		$dir = (string) apply_filters( 'uich_fields_model_dir', $dir );

		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * Directory for one site's model.
	 *
	 * @param int $blog_id Blog ID, or 0 for the current one.
	 * @return string
	 */
	public static function site_dir( $blog_id = 0 ) {
		$blog_id = $blog_id ? absint( $blog_id ) : get_current_blog_id();
		if ( ! $blog_id ) {
			$blog_id = 1;
		}
		return self::model_dir() . '/sites/' . $blog_id;
	}

	/**
	 * Directory holding one kind of definition for one site.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return string|WP_Error
	 */
	public static function kind_dir( $kind, $blog_id = 0 ) {
		$dirs = self::kind_dirs();
		if ( ! isset( $dirs[ $kind ] ) ) {
			return new WP_Error(
				'uich_model_bad_kind',
				/* translators: %s: the unrecognised kind */
				sprintf( __( 'Unknown definition kind "%s".', 'uichemy' ), (string) $kind )
			);
		}
		return self::site_dir( $blog_id ) . '/' . $dirs[ $kind ];
	}

	/**
	 * Whether a key may be used as a definition key, and therefore as a filename.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @param string $key  Candidate key.
	 * @return true|WP_Error
	 */
	public static function validate_key( $kind, $key ) {
		$dirs = self::kind_dirs();
		if ( ! isset( $dirs[ $kind ] ) ) {
			return new WP_Error( 'uich_model_bad_kind', __( 'Unknown definition kind.', 'uichemy' ) );
		}

		if ( ! is_string( $key ) ) {
			return new WP_Error( 'uich_model_key_type', __( 'Definition key must be a string.', 'uichemy' ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'uich_model_key_empty', __( 'Definition key cannot be empty.', 'uichemy' ) );
		}

		// One expression covers `..`, `/`, `\`, a leading dot, whitespace, NUL bytes, unicode
		// look-alikes and anything else: only these characters are ever allowed.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $key ) ) {
			return new WP_Error(
				'uich_model_key_charset',
				__( 'Definition key may contain only lowercase letters, numbers, hyphens and underscores, and must start with a letter or number.', 'uichemy' )
			);
		}

		// A group key is the first segment of every value meta key (`{prefix}{group}__{field}`), so it
		// may not contain the `__` separator or end in `_`; either would let two groups map to one key.
		if ( 'group_fields' === $kind && ( false !== strpos( $key, '__' ) || '_' === substr( $key, -1 ) ) ) {
			return new WP_Error(
				'uich_model_key_separator',
				__( 'Field group key cannot contain a double underscore or end with an underscore.', 'uichemy' )
			);
		}

		$max = self::key_max_lengths();
		if ( strlen( $key ) > $max[ $kind ] ) {
			return new WP_Error(
				'uich_model_key_length',
				sprintf(
					/* translators: 1: maximum length */
					__( 'Definition key must be %1$d characters or fewer.', 'uichemy' ),
					$max[ $kind ]
				)
			);
		}

		if ( in_array( $key, self::reserved_filenames(), true ) ) {
			return new WP_Error(
				'uich_model_key_reserved_filename',
				sprintf(
					/* translators: %s: the rejected key */
					__( '"%s" cannot be used as a key because it is not a usable filename on every platform.', 'uichemy' ),
					$key
				)
			);
		}

		// The module's existing reserved-key list — core object types, FSE block types and the WordPress query
		// vars that corrupt URL parsing.
		if ( in_array( $kind, array( 'cpt', 'tax' ), true )
			&& class_exists( 'Uich_Fields_Admin_Logic' )
			&& in_array( $key, Uich_Fields_Admin_Logic::reserved_keys(), true )
		) {
			return new WP_Error(
				'uich_model_key_reserved',
				sprintf(
					/* translators: %s: the rejected key */
					__( '"%s" is reserved by WordPress and cannot be used as a key.', 'uichemy' ),
					$key
				)
			);
		}

		return true;
	}

	/**
	 * Absolute path of the file that holds one definition.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return string|WP_Error
	 */
	public static function file_for_key( $kind, $key, $blog_id = 0 ) {
		$valid = self::validate_key( $kind, $key );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$dir = self::kind_dir( $kind, $blog_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = $dir . '/' . $key . '.json';
		if ( ! self::is_inside_model_dir( $path ) ) {
			return new WP_Error( 'uich_model_path_escape', __( 'Resolved path is outside the model directory.', 'uichemy' ) );
		}
		return $path;
	}

	/**
	 * Whether a path resolves inside the model directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_inside_model_dir( $path ) {
		$path = wp_normalize_path( (string) $path );
		$root = wp_normalize_path( self::model_dir() );

		// A traversal segment anywhere is refused before any filesystem call.
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		$real_root = realpath( $root );
		if ( false === $real_root ) {
			// The root does not exist yet — compare textually; there is nothing to symlink through.
			return 0 === strpos( $path, $root . '/' );
		}
		$real_root = wp_normalize_path( $real_root );

		// Walk up to the deepest ancestor that exists and resolve that.
		$probe = $path;
		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				return false;
			}
			$probe = $parent;
		}
		$real_probe = realpath( $probe );
		if ( false === $real_probe ) {
			return false;
		}
		$real_probe = wp_normalize_path( $real_probe );

		return $real_probe === $real_root || 0 === strpos( $real_probe, $real_root . '/' );
	}

	/**
	 * The key a filename claims to hold.
	 *
	 * @param string $path File path.
	 * @return string '' when the name is not a definition file.
	 */
	public static function key_from_file( $path ) {
		$base = basename( (string) $path );
		if ( '.json' !== substr( $base, -5 ) ) {
			return '';
		}
		return substr( $base, 0, -5 );
	}

	/**
	 * A key that is free for a new or duplicated definition.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $desired Preferred key.
	 * @param string $exclude A key to treat as free (the definition being renamed).
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return string|WP_Error
	 */
	public static function unique_key( $kind, $desired, $exclude = '', $blog_id = 0 ) {
		$desired = sanitize_key( (string) $desired );
		if ( '' === $desired ) {
			$desired = 'untitled';
		}
		$max = self::key_max_lengths();

		// The key asked for, unchanged, is always tried first.
		if ( strlen( $desired ) <= $max[ $kind ] && self::key_is_free( $kind, $desired, $exclude, $blog_id ) ) {
			return $desired;
		}

		// Taken (or too long). Leave room for the "-2" style suffix so no candidate exceeds the cap.
		$stem = substr( $desired, 0, max( 1, $max[ $kind ] - 4 ) );
		$stem = rtrim( $stem, '-_' );
		if ( '' === $stem ) {
			$stem = 'untitled';
		}

		for ( $n = 2; $n <= 200; $n++ ) {
			$candidate = $stem . '-' . $n;
			if ( $candidate === $exclude ) {
				return $candidate;
			}
			if ( self::key_is_free( $kind, $candidate, $exclude, $blog_id ) ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'uich_model_key_exhausted',
			sprintf(
				/* translators: %s: the key stem that could not be made unique */
				__( 'Could not find a free key based on "%s".', 'uichemy' ),
				$stem
			)
		);
	}

	/**
	 * Whether a key is unused by every authority that could refuse it later.
	 *
	 * @param string $kind    cpt | tax | group_fields.
	 * @param string $key     Candidate key.
	 * @param string $exclude A key to treat as free.
	 * @param int    $blog_id Blog ID, or 0 for the current one.
	 * @return bool
	 */
	public static function key_is_free( $kind, $key, $exclude = '', $blog_id = 0 ) {
		if ( $key === $exclude ) {
			return true;
		}
		if ( is_wp_error( self::validate_key( $kind, $key ) ) ) {
			return false;
		}

		$file = self::file_for_key( $kind, $key, $blog_id );
		if ( is_wp_error( $file ) || file_exists( $file ) ) {
			return false;
		}

		// WordPress's live registry. A key free on disk but already registered by another plugin
		// would save and then silently lose the registration race.
		if ( 'cpt' === $kind && post_type_exists( $key ) ) {
			return false;
		}
		if ( 'tax' === $kind && taxonomy_exists( $key ) ) {
			return false;
		}

		// The definitions the database still holds. During the cutover both stores exist, and after
		// it this is the trash-aware check that stops a restored definition colliding.
		if ( in_array( $kind, array( 'cpt', 'tax' ), true )
			&& class_exists( 'Uich_Fields_Admin_Logic' )
			&& Uich_Fields_Admin_Logic::key_in_use( 'cpt' === $kind ? 'cpt' : 'taxonomy', $key, 0, true )
		) {
			return false;
		}

		return true;
	}
}
