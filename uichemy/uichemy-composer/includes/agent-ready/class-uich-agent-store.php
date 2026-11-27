<?php
/**
 * Agent Ready storage: everything the owner configures lives in WordPress options.
 *
 * One option per kind of data, all in the site's own database (wp_options):
 *
 *   uichemy_agent_ready            master switch (enabled) and when it was decided
 *   uichemy_agent_ready_settings   every setting: the steps, discovery, WebMCP, rate limits...
 *   uichemy_agent_ready_forms      which forms AI may use, and how
 *   uichemy_agent_ready_profile    the business information
 *   uichemy_agent_ready_tools      the custom tools: tool name => definition
 *   uichemy_agent_ready_llms       the llms.txt text, only when written by hand or by an AI
 *   uichemy_agent_ready_audit      the result of the last readiness check
 *   uichemy_agent_ready_history    the last five previous versions of each of the above
 *   uichemy_agent_ready_gen        cache version (bumped when a page changes)
 *
 * Only generated output (the Markdown pages, llms files), the activity log and the
 * rate-limit counters stay on disk, in the cache folder: they are rebuilt or
 * pruned automatically and are never read back as configuration.
 *
 * Older versions kept the configuration in a private folder of files. The first
 * read after an update moves those files into the options above once, checks the
 * copy, then removes the files.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Store' ) ) {

	/**
	 * Options-backed store.
	 */
	class Uich_Agent_Store {

		/**
		 * Option holding the master switch.
		 */
		const OPTION = 'uichemy_agent_ready';

		/**
		 * Option that held the file hashes of the older, file-based store. Removed on migration.
		 */
		const HASH_OPTION = 'uichemy_agent_ready_hashes';

		/**
		 * Option holding the previous versions.
		 */
		const HISTORY_OPTION = 'uichemy_agent_ready_history';

		/**
		 * Option holding every custom tool, name => definition.
		 */
		const TOOLS_OPTION = 'uichemy_agent_ready_tools';

		/**
		 * Option set once the older files were moved here (with what was moved).
		 */
		const MIGRATED_OPTION = 'uichemy_agent_ready_dbmigrated';

		/**
		 * Short-lived lock, so two requests never move the files at the same time.
		 */
		const LOCK_OPTION = 'uichemy_agent_ready_dbmigrating';

		/**
		 * Name prefix of the older private folder.
		 */
		const PREFIX = 'uichemy-agent-';

		/**
		 * Cache folder for generated, untrusted output (relative to WP_CONTENT_DIR).
		 */
		const CACHE_DIR = 'cache/uichemy-agent';

		/**
		 * First line of every file the older store wrote.
		 */
		const GUARD = "<?php exit; ?>\n";

		/**
		 * Stored paths that have one option each: path => [ option, autoload ].
		 * Autoload is on for what almost every request reads.
		 */
		const MAP = array(
			'config.json'  => array( 'uichemy_agent_ready_settings', true ),
			'forms.json'   => array( 'uichemy_agent_ready_forms', true ),
			'profile.json' => array( 'uichemy_agent_ready_profile', true ),
			'audit.json'   => array( 'uichemy_agent_ready_audit', false ),
			'llms.txt'     => array( 'uichemy_agent_ready_llms', false ),
		);

		/**
		 * How many earlier versions are kept.
		 */
		const HISTORY_KEEP = 5;

		/**
		 * The module's option row.
		 *
		 * @return array{enabled?:bool,decided_at?:int,decided_as?:string}
		 */
		public static function state() {
			$state = get_option( self::OPTION, array() );
			return is_array( $state ) ? $state : array();
		}

		/**
		 * Merge keys into the option row.
		 *
		 * @param array $patch Keys to set.
		 * @return void
		 */
		public static function update_state( array $patch ) {
			update_option( self::OPTION, array_merge( self::state(), $patch ), true );
		}

		/**
		 * Normalise and validate a path.
		 *
		 * @param string $rel Path.
		 * @return string|false
		 */
		public static function clean_rel( $rel ) {
			$rel = str_replace( '\\', '/', (string) $rel );
			if ( '' === $rel || '/' === $rel[0] || false !== strpos( $rel, '..' ) ) {
				return false;
			}
			if ( ! preg_match( '#^[a-z0-9][a-z0-9_\-./]*$#', $rel ) || preg_match( '#(^|/)\.#', $rel ) ) {
				return false;
			}
			return $rel;
		}

		/**
		 * Where a path is stored.
		 *
		 * @param string $rel Clean path.
		 * @return array{0:string,1:string,2:bool,3:string}|false [ kind (single|tool), option, autoload, tool name ].
		 */
		private static function target( $rel ) {
			if ( isset( self::MAP[ $rel ] ) ) {
				return array( 'single', self::MAP[ $rel ][0], (bool) self::MAP[ $rel ][1], '' );
			}
			if ( preg_match( '#^tools/([a-z0-9_\-]+)/tool\.json$#', $rel, $m ) ) {
				return array( 'tool', self::TOOLS_OPTION, false, $m[1] );
			}
			return false;
		}

		/**
		 * The stored value of a path.
		 *
		 * @param string $rel Clean path.
		 * @return mixed|null Null when nothing is stored.
		 */
		private static function get_value( $rel ) {
			$t = self::target( $rel );
			if ( ! $t ) {
				return null;
			}
			$value = get_option( $t[1], null );
			if ( 'tool' === $t[0] ) {
				return is_array( $value ) && isset( $value[ $t[3] ] ) ? $value[ $t[3] ] : null;
			}
			return $value;
		}

		/**
		 * Read a stored text (llms.txt) or the JSON text of a stored record.
		 *
		 * @param string $rel Path.
		 * @return string|null|WP_Error Contents, or null when nothing is stored.
		 */
		public static function read( $rel ) {
			$rel = self::clean_rel( $rel );
			if ( false === $rel || ! self::target( $rel ) ) {
				return new WP_Error( 'uich_agent_path', 'Invalid path.' );
			}
			self::maybe_migrate();
			$value = self::get_value( $rel );
			if ( null === $value || false === $value ) {
				return null;
			}
			if ( is_string( $value ) ) {
				return $value;
			}
			$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return false === $json ? null : $json . "\n";
		}

		/**
		 * Read a stored record.
		 *
		 * @param string $rel Path.
		 * @return array|null|WP_Error
		 */
		public static function read_json( $rel ) {
			$rel = self::clean_rel( $rel );
			if ( false === $rel || ! self::target( $rel ) ) {
				return new WP_Error( 'uich_agent_path', 'Invalid path.' );
			}
			self::maybe_migrate();
			$value = self::get_value( $rel );
			if ( null === $value || false === $value ) {
				return null;
			}
			return is_array( $value ) ? $value : new WP_Error( 'uich_agent_json', sprintf( '%s is not a valid record.', $rel ) );
		}

		/**
		 * Store text, or the JSON text of a record.
		 *
		 * @param string $rel  Path.
		 * @param string $body Contents.
		 * @return true|WP_Error
		 */
		public static function write( $rel, $body ) {
			$rel = self::clean_rel( $rel );
			if ( false === $rel || ! self::target( $rel ) ) {
				return new WP_Error( 'uich_agent_path', 'Invalid path.' );
			}
			if ( '.json' === substr( $rel, -5 ) ) {
				$data = json_decode( (string) $body, true );
				return is_array( $data ) ? self::put( $rel, $data ) : new WP_Error( 'uich_agent_json', sprintf( '%s is not valid JSON.', $rel ) );
			}
			return self::put( $rel, (string) $body );
		}

		/**
		 * Store a record.
		 *
		 * @param string $rel  Path.
		 * @param array  $data Data.
		 * @return true|WP_Error
		 */
		public static function write_json( $rel, array $data ) {
			$rel = self::clean_rel( $rel );
			if ( false === $rel || ! self::target( $rel ) ) {
				return new WP_Error( 'uich_agent_path', 'Invalid path.' );
			}
			// A round trip through JSON gives plain arrays and proves the data can be encoded.
			$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$flat = false === $json ? null : json_decode( $json, true );
			if ( ! is_array( $flat ) ) {
				return new WP_Error( 'uich_agent_json', 'Could not encode ' . $rel . '.' );
			}
			return self::put( $rel, $flat );
		}

		/**
		 * Save a value, keeping the previous one in the history.
		 *
		 * @param string $rel   Clean path.
		 * @param mixed  $value Array or string.
		 * @return true|WP_Error
		 */
		private static function put( $rel, $value ) {
			self::maybe_migrate();
			$t   = self::target( $rel );
			$old = self::get_value( $rel );
			if ( $old === $value ) {
				return true;
			}
			if ( null !== $old && 'audit.json' !== $rel ) {
				self::remember( $rel, $old );
			}
			if ( 'tool' === $t[0] ) {
				$all          = get_option( $t[1], array() );
				$all          = is_array( $all ) ? $all : array();
				$all[ $t[3] ] = $value;
				ksort( $all );
				$value = $all;
				$store = $t[1];
			} else {
				$store = $t[1];
			}
			update_option( $store, $value, $t[2] );
			// update_option() says false when nothing changed; check the stored copy instead.
			return get_option( $store, null ) === $value ? true : new WP_Error( 'uich_agent_write', 'Could not save ' . $rel . '.' );
		}

		/**
		 * Remove a stored record.
		 *
		 * @param string $rel Path.
		 * @return true|WP_Error
		 */
		public static function delete( $rel ) {
			$rel = self::clean_rel( $rel );
			if ( false === $rel || ! self::target( $rel ) ) {
				return new WP_Error( 'uich_agent_path', 'Invalid path.' );
			}
			self::maybe_migrate();
			$t   = self::target( $rel );
			$old = self::get_value( $rel );
			if ( null !== $old ) {
				self::remember( $rel, $old );
			}
			if ( 'tool' === $t[0] ) {
				$all = get_option( $t[1], array() );
				$all = is_array( $all ) ? $all : array();
				unset( $all[ $t[3] ] );
				$all ? update_option( $t[1], $all, false ) : delete_option( $t[1] );
			} else {
				delete_option( $t[1] );
			}
			return true;
		}

		/**
		 * Every stored custom tool, name => definition.
		 *
		 * @return array
		 */
		public static function tools() {
			self::maybe_migrate();
			$all = get_option( self::TOOLS_OPTION, array() );
			return is_array( $all ) ? $all : array();
		}

		/**
		 * Keep an earlier version of a record (the last few only).
		 *
		 * @param string $rel   Path.
		 * @param mixed  $value Value before the change.
		 * @return void
		 */
		private static function remember( $rel, $value ) {
			$history = get_option( self::HISTORY_OPTION, array() );
			$history = is_array( $history ) ? $history : array();
			$list    = isset( $history[ $rel ] ) && is_array( $history[ $rel ] ) ? $history[ $rel ] : array();
			$list[]  = array(
				'at'    => time(),
				'value' => $value,
			);
			$history[ $rel ] = array_slice( $list, -self::HISTORY_KEEP );
			update_option( self::HISTORY_OPTION, $history, false );
		}

		/**
		 * The earlier versions of a record, oldest first.
		 *
		 * @param string $rel Path.
		 * @return array<int,array{at:int,value:mixed}>
		 */
		public static function history( $rel ) {
			$history = get_option( self::HISTORY_OPTION, array() );
			return is_array( $history ) && isset( $history[ $rel ] ) && is_array( $history[ $rel ] ) ? $history[ $rel ] : array();
		}

		/**
		 * Nothing can be changed outside Agent Ready any more, so there is nothing to trust.
		 *
		 * @param string $rel Path.
		 * @return true|WP_Error
		 */
		public static function trust( $rel ) {
			$rel = self::clean_rel( $rel );
			return false === $rel || ! self::target( $rel ) ? new WP_Error( 'uich_agent_path', 'Invalid path.' ) : true;
		}

		/**
		 * Records that no longer match their stored fingerprint. The database copy is
		 * the only copy, so this is always empty.
		 *
		 * @return array
		 */
		public static function untrusted() {
			return array();
		}

		/**
		 * The records that exist: path => 'database'.
		 *
		 * @return array<string,string>
		 */
		public static function hashes() {
			self::maybe_migrate();
			$out = array();
			foreach ( array_keys( self::MAP ) as $rel ) {
				if ( null !== get_option( self::MAP[ $rel ][0], null ) ) {
					$out[ $rel ] = 'database';
				}
			}
			foreach ( array_keys( self::tools() ) as $name ) {
				$out[ 'tools/' . $name . '/tool.json' ] = 'database';
			}
			return $out;
		}

		/* -------------------------------------------------------------
		 * One-time move from the older files
		 * ----------------------------------------------------------- */

		/**
		 * Move the older private-folder files into the options, once.
		 *
		 * @return void
		 */
		public static function maybe_migrate() {
			static $running = false;
			if ( $running || false !== get_option( self::MIGRATED_OPTION, false ) ) {
				return;
			}
			$running = true;
			// add_option() is atomic: only one request gets the lock. A lock older than two
			// minutes belongs to a request that died, so it is taken over.
			$lock = get_option( self::LOCK_OPTION, false );
			if ( false !== $lock && time() - (int) $lock > 120 ) {
				delete_option( self::LOCK_OPTION );
			}
			if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
				$running = false;
				return;
			}
			$report = self::migrate_files();
			update_option( self::MIGRATED_OPTION, array_merge( array( 'at' => time() ), $report ), false );
			delete_option( self::LOCK_OPTION );
			$running = false;
		}

		/**
		 * The older private folder, when it exists (never created here).
		 *
		 * @return string Path with a trailing slash, or ''.
		 */
		private static function old_root() {
			if ( defined( 'UICHEMY_AGENT_DIR' ) && is_string( UICHEMY_AGENT_DIR ) && '' !== UICHEMY_AGENT_DIR ) {
				$path = trailingslashit( UICHEMY_AGENT_DIR );
				return is_dir( $path ) ? $path : '';
			}
			$state = self::state();
			$name  = isset( $state['dir'] ) && preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-z0-9]{12}$/', $state['dir'] ) ? $state['dir'] : '';
			if ( '' === $name ) {
				return '';
			}
			$bases   = array( trailingslashit( WP_CONTENT_DIR ) );
			$uploads = wp_upload_dir( null, false );
			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$bases[] = trailingslashit( $uploads['basedir'] );
			}
			foreach ( $bases as $base ) {
				if ( is_dir( $base . $name . '/' ) ) {
					return $base . $name . '/';
				}
			}
			return '';
		}

		/**
		 * The file of a path in the older folder.
		 *
		 * @param string $root Folder.
		 * @param string $rel  Path.
		 * @return string
		 */
		private static function old_file( $root, $rel ) {
			$guarded = $root . ( '.json' === substr( $rel, -5 ) ? $rel . '.php' : $rel );
			return ! is_file( $guarded ) && is_file( $root . $rel ) ? $root . $rel : $guarded;
		}

		/**
		 * Copy each trusted older file into its option, check the copy, delete the file.
		 *
		 * A file that is not in the hash index, or whose bytes no longer match it, was
		 * changed outside Agent Ready: it is not imported and is left where it is.
		 *
		 * @return array{moved:string[],skipped:string[],folder:string}
		 */
		private static function migrate_files() {
			$report = array(
				'moved'   => array(),
				'skipped' => array(),
				'folder'  => 'none',
			);
			$root   = self::old_root();
			if ( '' === $root ) {
				return $report;
			}
			$hashes = get_option( self::HASH_OPTION, array() );
			$hashes = is_array( $hashes ) ? $hashes : array();

			$rels = array_keys( self::MAP );
			foreach ( (array) glob( $root . 'tools/*/tool.json*' ) as $file ) {
				$rels[] = 'tools/' . basename( dirname( $file ) ) . '/tool.json';
			}
			foreach ( array_unique( $rels ) as $rel ) {
				$file = self::old_file( $root, $rel );
				if ( ! is_file( $file ) || false === self::clean_rel( $rel ) ) {
					continue;
				}
				$body = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local file, one-time move.
				if ( false === $body ) {
					continue; // Gone already.
				}
				if ( ! isset( $hashes[ $rel ] ) || ! hash_equals( (string) $hashes[ $rel ], hash( 'sha256', $body ) ) ) {
					$report['skipped'][] = $rel;
					continue;
				}
				$text  = 0 === strpos( $body, self::GUARD ) ? substr( $body, strlen( self::GUARD ) ) : $body;
				$value = '.json' === substr( $rel, -5 ) ? json_decode( $text, true ) : $text;
				if ( null === $value || ( '.json' === substr( $rel, -5 ) && ! is_array( $value ) ) ) {
					$report['skipped'][] = $rel;
					continue;
				}
				// Never overwrite what is already in the database.
				if ( null === self::get_value( $rel ) ) {
					$t = self::target( $rel );
					if ( 'tool' === $t[0] ) {
						$all          = get_option( $t[1], array() );
						$all          = is_array( $all ) ? $all : array();
						$all[ $t[3] ] = $value;
						ksort( $all );
						update_option( $t[1], $all, false );
					} else {
						update_option( $t[1], $value, $t[2] );
					}
					if ( self::get_value( $rel ) !== $value ) {
						$report['skipped'][] = $rel;
						continue;
					}
				}
				$report['moved'][] = $rel;
				wp_delete_file( $file );
				if ( is_file( $root . $rel ) ) {
					wp_delete_file( $root . $rel );
				}
			}

			$report['folder'] = $report['skipped'] ? 'kept' : ( self::finish_migration() ? 'removed' : 'kept' );
			return $report;
		}

		/**
		 * Remove the older private folder when nothing is left in it but its own guard
		 * and old-version files, and forget its settings.
		 *
		 * @return bool Whether the folder is gone.
		 */
		public static function finish_migration() {
			$root = self::old_root();
			if ( '' !== $root && ! self::remove_old_root( $root ) ) {
				return false;
			}
			delete_option( self::HASH_OPTION );
			$state = self::state();
			unset( $state['dir'], $state['base'] );
			update_option( self::OPTION, $state, true );
			return true;
		}

		/**
		 * Delete the older folder when only its own guard and history files remain.
		 *
		 * @param string $root Folder with a trailing slash.
		 * @return bool Whether it was removed.
		 */
		private static function remove_old_root( $root ) {
			$guards = array( 'index.php', '.htaccess', 'web.config' );
			$files  = array();
			$dirs   = array();
			$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $item ) {
				$path = wp_normalize_path( $item->getPathname() );
				if ( $item->isDir() ) {
					$dirs[] = $path;
					continue;
				}
				$rel = ltrim( substr( $path, strlen( wp_normalize_path( $root ) ) ), '/' );
				// Only the guard files and the old version copies may go; anything else stays.
				if ( in_array( $rel, $guards, true ) || 0 === strpos( $rel, 'history/' ) ) {
					$files[] = $path;
				} else {
					return false;
				}
			}
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
			foreach ( $dirs as $dir ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder of the older store.
			}
			return @rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder of the older store.
		}

		/* -------------------------------------------------------------
		 * Generated output (never configuration)
		 * ----------------------------------------------------------- */

		/**
		 * Write the files that stop a web server from serving a folder.
		 *
		 * @param string $path Folder path with trailing slash.
		 * @return void
		 */
		private static function harden( $path ) {
			$files = array(
				'index.php'  => "<?php\n// Silence is golden.\n",
				'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
				'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			);
			foreach ( $files as $file => $body ) {
				if ( ! file_exists( $path . $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static guard files.
					@file_put_contents( $path . $file, $body );
				}
			}
		}

		/**
		 * Absolute path of the generated-output cache folder (Markdown pages, llms files, logs, counters).
		 *
		 * @return string|WP_Error
		 */
		public static function cache_root() {
			// One folder per site on multisite, so sites never read or clean each other's files.
			$path = trailingslashit( WP_CONTENT_DIR ) . self::CACHE_DIR . '/' . ( is_multisite() ? 'site-' . get_current_blog_id() . '/' : '' );
			if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
				return new WP_Error( 'uich_agent_cache', 'The Agent Ready cache folder is not writable.' );
			}
			if ( ! file_exists( $path . 'index.php' ) ) {
				self::harden( $path );
			}
			return $path;
		}
	}
}
