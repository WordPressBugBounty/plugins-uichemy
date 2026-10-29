<?php
/**
 * Uich_CB_Code_Files — the uploaded .css / .js files, as the Code tab edits them.
 *
 * A build can store a stylesheet or script as a real file under
 * `wp-content/uichemy-composer/` (the `uichemy-composer/code-file` ability, or
 * an upload) and reference it from a page with a <link> / <script src>. The
 * builder's Code tab shows the files the page references, under that folder,
 * with their real names and sub-folders, and lets them be edited in place.
 *
 * The folder is the one the code-files layer writes to: Uich_Code_Files::dir()
 * when that class is loaded, otherwise the same two candidates it tries
 * (wp-content first, uploads as the fallback). Nothing here creates the folder
 * or a file — the Code tab edits what exists.
 *
 * Files are site-level, not the page's: one stylesheet can serve many pages. So
 * writing one needs the same right the site-wide code does (`unfiltered_html`),
 * not only the right to edit this page.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Code_Files' ) ) {

	/**
	 * Locate, read and write the uploaded code files.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Code_Files {

		/**
		 * Folder name under wp-content (or uploads).
		 */
		const DIRNAME = 'uichemy-composer';

		/**
		 * The extensions the Code tab opens as text.
		 */
		const EXTENSIONS = array( 'css', 'js' );

		/**
		 * Largest file the editor loads (bytes). A minified vendor bundle past
		 * this is better edited as a real file than in a browser buffer.
		 */
		const MAX_BYTES = 5242880; // 5 MB.

		/**
		 * The folder, with a trailing slash, or '' when there is none.
		 *
		 * @return string
		 */
		public static function dir() {
			if ( class_exists( 'Uich_Code_Files' ) && is_callable( array( 'Uich_Code_Files', 'dir' ) ) ) {
				$dir = (string) Uich_Code_Files::dir();
				return '' !== $dir && is_dir( $dir ) ? trailingslashit( $dir ) : '';
			}

			foreach ( self::candidates() as $candidate ) {
				if ( is_dir( $candidate['dir'] ) ) {
					return $candidate['dir'];
				}
			}

			return '';
		}

		/**
		 * The folder's public URL, with a trailing slash, or ''.
		 *
		 * @return string
		 */
		public static function base_url() {
			if ( class_exists( 'Uich_Code_Files' ) && is_callable( array( 'Uich_Code_Files', 'base_url' ) ) ) {
				$url = (string) Uich_Code_Files::base_url();
				return '' !== $url ? trailingslashit( $url ) : '';
			}

			$dir = self::dir();
			foreach ( self::candidates() as $candidate ) {
				if ( $candidate['dir'] === $dir ) {
					return $candidate['url'];
				}
			}

			return '';
		}

		/**
		 * The folder's URL path (`/wp-content/uichemy-composer/`) — what a
		 * reference in page code contains, however the host is written.
		 *
		 * @return string
		 */
		public static function base_path() {
			$url = self::base_url();
			if ( '' === $url ) {
				return '';
			}

			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			return '' === $path ? '' : trailingslashit( $path );
		}

		/**
		 * Where the folder can be, in the order the code-files layer tries.
		 *
		 * @return array<int,array{dir:string,url:string}>
		 */
		private static function candidates() {
			$out = array(
				array(
					'dir' => trailingslashit( WP_CONTENT_DIR ) . self::DIRNAME . '/',
					'url' => trailingslashit( content_url() ) . self::DIRNAME . '/',
				),
			);

			$uploads = wp_upload_dir( null, false );
			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$out[] = array(
					'dir' => trailingslashit( $uploads['basedir'] ) . self::DIRNAME . '/',
					'url' => trailingslashit( $uploads['baseurl'] ) . self::DIRNAME . '/',
				);
			}

			return $out;
		}

		/**
		 * May the current user change these files?
		 *
		 * @return bool
		 */
		public static function can_write() {
			return current_user_can( 'unfiltered_html' );
		}

		/**
		 * Normalise a relative path inside the folder, or '' when it is not one.
		 *
		 * `a.css`, `vendor/lib.js` — sub-folders allowed, each segment a plain
		 * name. No `..`, no dot-files, no other extension.
		 *
		 * @param string $path Relative path.
		 * @return string
		 */
		public static function clean_path( $path ) {
			$path = str_replace( '\\', '/', rawurldecode( (string) $path ) );
			$path = trim( $path, '/' );

			if ( '' === $path || false !== strpos( $path, "\0" ) ) {
				return '';
			}

			foreach ( explode( '/', $path ) as $segment ) {
				if ( '' === $segment || '.' === $segment[0] || ! preg_match( '/^[A-Za-z0-9._-]+$/', $segment ) ) {
					return '';
				}
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			return in_array( $ext, self::EXTENSIONS, true ) ? $path : '';
		}

		/**
		 * The absolute path of an existing file inside the folder, or ''.
		 *
		 * Resolved with realpath() and checked to still be inside the folder, so
		 * a symlink cannot lead out of it.
		 *
		 * @param string $path Relative path (already cleaned).
		 * @return string
		 */
		private static function locate( $path ) {
			$dir = self::dir();
			if ( '' === $dir || '' === $path ) {
				return '';
			}

			$base = realpath( $dir );
			$file = realpath( $dir . $path );

			if ( false === $base || false === $file || ! is_file( $file ) ) {
				return '';
			}

			return 0 === strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $base ) ) ) ? $file : '';
		}

		/**
		 * Read files for the Code tab.
		 *
		 * @param string[] $paths Relative paths.
		 * @return array<string,array> Relative path => { exists, lang, url, contents?, hash?, too_large? }.
		 */
		public static function read_many( array $paths ) {
			$out = array();

			foreach ( array_slice( array_values( array_unique( array_map( 'strval', $paths ) ) ), 0, 100 ) as $raw ) {
				$path = self::clean_path( $raw );
				if ( '' === $path ) {
					continue;
				}

				$file = self::locate( $path );
				$item = array(
					'exists' => '' !== $file,
					'lang'   => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
					'url'    => self::base_url() . $path,
				);

				if ( '' !== $file ) {
					$size = (int) filesize( $file );
					if ( $size > self::MAX_BYTES ) {
						$item['too_large'] = true;
					} else {
						$contents         = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file inside the plugin's own folder.
						$item['contents'] = $contents;
						$item['hash']     = md5( $contents );
					}
				}

				$out[ $path ] = $item;
			}

			return $out;
		}

		/**
		 * Write an existing file.
		 *
		 * @param string $path          Relative path.
		 * @param string $contents      New contents.
		 * @param string $expected_hash The hash the editor read, or '' to skip the check.
		 * @return array|WP_Error { path, hash }.
		 */
		public static function write( $path, $contents, $expected_hash = '' ) {
			$path = self::clean_path( $path );
			if ( '' === $path ) {
				return new WP_Error( 'uich_cb_code_file_path', __( 'That is not a .css or .js file in the code folder.', 'uichemy' ), array( 'status' => 400 ) );
			}

			$file = self::locate( $path );
			if ( '' === $file ) {
				return new WP_Error( 'uich_cb_code_file_missing', __( 'That file does not exist in the code folder.', 'uichemy' ), array( 'status' => 404 ) );
			}

			$contents = (string) $contents;
			if ( strlen( $contents ) > self::MAX_BYTES ) {
				return new WP_Error( 'uich_cb_code_file_size', __( 'The file is too large to save from the editor.', 'uichemy' ), array( 'status' => 413 ) );
			}

			$current = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- As above.
			if ( '' !== $expected_hash && ! hash_equals( md5( $current ), (string) $expected_hash ) ) {
				return new WP_Error(
					'uich_cb_code_file_stale',
					__( 'This file changed since it was opened. Reopen it to get the current version.', 'uichemy' ),
					array( 'status' => 409, 'hash' => md5( $current ) )
				);
			}

			if ( ! wp_is_writable( $file ) || false === file_put_contents( $file, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The code folder is written directly, as the code-files layer does.
				return new WP_Error( 'uich_cb_code_file_write', __( 'The file could not be written.', 'uichemy' ), array( 'status' => 500 ) );
			}

			/**
			 * Fires after the Code tab saved an uploaded code file.
			 *
			 * @param string $path Relative path inside the code folder.
			 */
			do_action( 'uich_cb/code_file_saved', $path );

			return array(
				'path' => $path,
				'hash' => md5( $contents ),
			);
		}
	}
}
