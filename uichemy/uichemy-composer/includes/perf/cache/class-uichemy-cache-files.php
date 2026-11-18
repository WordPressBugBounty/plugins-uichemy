<?php
/**
 * Page cache files: the drop-in, the WP_CACHE line, the cache folder and its
 * contents (clear, background cleanup, size cap).
 *
 * Rules (docs/page-cache/SECURITY.md S3–S5, S11):
 *   - Never overwrite or remove an advanced-cache.php we did not write.
 *   - wp-config.php: add or remove only our one marked line, only when
 *     writable, with a backup and an atomic rename.
 *   - Every delete is checked to be inside the cache folder.
 *   - "Clear all" bumps a generation number (instant); old files are deleted
 *     in small batches by cron.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Files' ) ) {

	class UiChemy_Cache_Files {

		/** Option: generation number and bookkeeping. */
		const STATE_OPTION = 'uichemy_cache_state';

		/** The comment that marks our wp-config.php line. */
		const CONFIG_MARKER = '// Added for UiChemy page cache';

		/** Background cleanup event. */
		const GC_HOOK = 'uichemy_cache_gc';

		/** Files deleted per cleanup run. */
		const GC_BATCH = 2000;

		/**
		 * Size cap (S11): disk space only. Disk is the resource at risk; the
		 * number of pages isn't (query strings and alias addresses are never
		 * stored, so pages can't multiply on their own). Oldest pages go first.
		 */
		const MAX_BYTES = 524288000;

		// ------------------------------------------------------------------
		// State.
		// ------------------------------------------------------------------

		/**
		 * @return array{generation:int,full_at:int}
		 */
		public static function state() {
			$s = get_option( self::STATE_OPTION, array() );
			$s = is_array( $s ) ? $s : array();
			return array(
				'generation' => isset( $s['generation'] ) ? (int) $s['generation'] : 1,
				'full_at'    => isset( $s['full_at'] ) ? (int) $s['full_at'] : 0,
			);
		}

		/**
		 * @param array $changes Keys of state().
		 * @return void
		 */
		private static function set_state( array $changes ) {
			update_option( self::STATE_OPTION, array_merge( self::state(), $changes ), false );
		}

		// ------------------------------------------------------------------
		// Folder and config.json.
		// ------------------------------------------------------------------

		/**
		 * Create the cache folder with its guards. Returns false when not writable.
		 *
		 * @return bool
		 */
		public static function ensure_root() {
			$root = UiChemy_Cache_Engine::root();
			if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
				return false;
			}
			if ( ! wp_is_writable( $root ) ) {
				return false;
			}
			// wp_mkdir_p() copies the parent's permissions; a world-writable parent
			// (some hosts use 777 or 1777) must not make our folder writable by others.
			@chmod( $root, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			// Nothing in here is ever requested directly: pages are sent by the
			// drop-in. Deny all web access (Apache), and keep an empty index.
			$guards = array(
				'index.html' => '',
				'.htaccess'  => "# UiChemy page cache: no direct access.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			);
			foreach ( $guards as $name => $body ) {
				if ( ! file_exists( $root . '/' . $name ) ) {
					UiChemy_Cache_Engine::write( $root . '/' . $name, $body );
				}
			}
			return true;
		}

		/**
		 * Write the snapshot the drop-in reads.
		 *
		 * @param bool     $enabled    Serve and store.
		 * @param int|null $generation Generation to publish (default: the current one).
		 * @return bool
		 */
		public static function write_config( $enabled, $generation = null ) {
			if ( ! self::ensure_root() ) {
				return false;
			}
			$snapshot = UiChemy_Cache_Settings::snapshot( $enabled, null === $generation ? self::state()['generation'] : (int) $generation );
			$ok       = UiChemy_Cache_Engine::write( UiChemy_Cache_Engine::root() . '/config.json', (string) wp_json_encode( $snapshot ) );
			UiChemy_Cache_Engine::reset_config();
			return $ok;
		}

		// ------------------------------------------------------------------
		// Drop-in.
		// ------------------------------------------------------------------

		/**
		 * The shipped drop-in.
		 *
		 * @return string
		 */
		public static function dropin_source() {
			return __DIR__ . '/advanced-cache.php';
		}

		/**
		 * @return string
		 */
		public static function dropin_target() {
			return WP_CONTENT_DIR . '/advanced-cache.php';
		}

		/**
		 * Is the installed drop-in ours and identical to the shipped copy?
		 *
		 * @return bool
		 */
		public static function dropin_current() {
			$t = self::dropin_target();
			return is_file( $t ) && sha1_file( $t ) === sha1_file( self::dropin_source() );
		}

		/**
		 * Copy the drop-in in place. Refuses when another plugin's is there.
		 *
		 * @return true|WP_Error foreign_dropin | dropin_write.
		 */
		public static function install_dropin() {
			$state = UiChemy_Cache_Host::dropin_state();
			if ( 'foreign' === $state['state'] ) {
				return new WP_Error( 'foreign_dropin', 'Another plugin owns advanced-cache.php.' );
			}
			if ( self::dropin_current() ) {
				return true;
			}
			$src = self::dropin_source();
			$dst = self::dropin_target();
			$tmp = $dst . '.uichemy-' . wp_generate_password( 8, false ) . '.tmp';
			if ( ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'dropin_write', 'wp-content is not writable.' );
			}
			if ( sha1_file( $tmp ) !== sha1_file( $src ) || ! @rename( $tmp, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( $tmp );
				return new WP_Error( 'dropin_write', 'wp-content is not writable.' );
			}
			return true;
		}

		/**
		 * Remove the drop-in, only if it is ours.
		 *
		 * @return void
		 */
		public static function remove_dropin() {
			if ( 'ours' === UiChemy_Cache_Host::dropin_state()['state'] ) {
				wp_delete_file( self::dropin_target() );
			}
		}

		// ------------------------------------------------------------------
		// wp-config.php.
		// ------------------------------------------------------------------

		/**
		 * wp-config.php, in ABSPATH or one level up (as core looks for it).
		 *
		 * @return string '' when not found.
		 */
		public static function wp_config_path() {
			$path = '';
			if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
				$path = ABSPATH . 'wp-config.php';
			} else {
				$up = dirname( ABSPATH ) . '/wp-config.php';
				if ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
					$path = $up;
				}
			}
			/**
			 * The wp-config.php the page cache edits (tests point it at a copy).
			 *
			 * @param string $path Path, '' when not found.
			 */
			return (string) apply_filters( 'uichemy/cache/wp_config_path', $path );
		}

		/**
		 * The exact line we add (copy.json N02).
		 *
		 * @return string
		 */
		public static function config_line() {
			return "define( 'WP_CACHE', true ); " . self::CONFIG_MARKER;
		}

		/**
		 * Make WP_CACHE true: nothing to do if it already is; otherwise add our
		 * line after the opening <?php.
		 *
		 * @return true|WP_Error needs_setup when the file can't be safely edited.
		 */
		public static function ensure_wp_cache() {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return true;
			}
			return self::add_wp_cache_line();
		}

		/**
		 * Add our marked WP_CACHE line to wp-config.php.
		 *
		 * @return true|WP_Error needs_setup when the file can't be safely edited.
		 */
		public static function add_wp_cache_line() {
			$file = self::wp_config_path();
			if ( ! $file || ! is_readable( $file ) ) {
				return new WP_Error( 'needs_setup', 'wp-config.php not found.' );
			}
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== strpos( $body, self::CONFIG_MARKER ) ) {
				return true; // Ours is there; takes effect on the next request.
			}
			// Someone else defines WP_CACHE (probably false): don't fight it.
			if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $body ) ) {
				return new WP_Error( 'needs_setup', 'WP_CACHE is defined elsewhere in wp-config.php.' );
			}
			if ( ! preg_match( '/^<\?php[^\n]*\n/', $body ) ) {
				return new WP_Error( 'needs_setup', 'wp-config.php has an unexpected start.' );
			}
			$new = preg_replace( '/^(<\?php[^\n]*\n)/', '$1' . self::config_line() . "\n", $body, 1 );
			return self::replace_wp_config( $file, $body, $new );
		}

		/**
		 * Remove only our marked line.
		 *
		 * @return true|WP_Error
		 */
		public static function remove_wp_cache_line() {
			$file = self::wp_config_path();
			if ( ! $file || ! is_readable( $file ) ) {
				return true;
			}
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === strpos( $body, self::CONFIG_MARKER ) ) {
				return true;
			}
			$new = preg_replace( '/^[^\n]*' . preg_quote( self::CONFIG_MARKER, '/' ) . '[^\n]*\n?/m', '', $body );
			return self::replace_wp_config( $file, $body, $new );
		}

		/**
		 * Backup, write a temp copy, rename over. The backup is a .php file (so a
		 * web request can't read it as text) and is removed once the new file is
		 * in place and reads back exactly.
		 *
		 * @param string $file wp-config.php.
		 * @param string $old  Current contents.
		 * @param string $new  New contents.
		 * @return true|WP_Error
		 */
		private static function replace_wp_config( $file, $old, $new ) {
			// A symlinked wp-config.php (containers, deploy tools) is edited at its
			// target, so the link survives and the real file gets the change.
			if ( is_link( $file ) ) {
				$target = realpath( $file );
				if ( ! $target || ! is_file( $target ) ) {
					return new WP_Error( 'needs_setup', 'wp-config.php is a link UiChemy cannot follow.' );
				}
				$file = $target;
			}
			$dir = dirname( $file );
			if ( ! is_string( $new ) || $new === $old || ! wp_is_writable( $file ) || ! wp_is_writable( $dir ) ) {
				return is_string( $new ) && $new === $old ? true : new WP_Error( 'needs_setup', 'wp-config.php is not writable.' );
			}
			$backup = $dir . '/wp-config-uichemy-backup.php';
			$tmp    = $dir . '/wp-config-uichemy-' . wp_generate_password( 8, false ) . '.php';
			$perms  = fileperms( $file ) & 0777;
			if ( ! @copy( $file, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'needs_setup', 'Could not back up wp-config.php.' );
			}
			@chmod( $backup, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same protection as the original.
			if ( false === @file_put_contents( $tmp, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				wp_delete_file( $backup );
				return new WP_Error( 'needs_setup', 'wp-config.php is not writable.' );
			}
			@chmod( $tmp, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( $tmp );
				wp_delete_file( $backup );
				return new WP_Error( 'needs_setup', 'wp-config.php is not writable.' );
			}
			clearstatcache( true, $file );
			if ( (string) file_get_contents( $file ) !== $new ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				@copy( $backup, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@chmod( $file, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				wp_delete_file( $backup );
				return new WP_Error( 'needs_setup', 'wp-config.php could not be verified; the original was restored.' );
			}
			wp_delete_file( $backup );
			return true;
		}

		// ------------------------------------------------------------------
		// Clearing.
		// ------------------------------------------------------------------

		/**
		 * Clear everything: bump the generation (instant), delete later.
		 *
		 * @return bool False when the folder isn't writable.
		 */
		public static function clear_all() {
			$next = self::state()['generation'] + 1;
			if ( ! is_dir( UiChemy_Cache_Engine::root() ) ) {
				self::set_state( array( 'generation' => $next ) );
				return true;
			}
			// The drop-in's snapshot moves first: if that write fails, the plugin and
			// the drop-in stay on the same generation and nothing in use is deleted.
			$config = UiChemy_Cache_Engine::config( true );
			$ok     = self::write_config( $config ? $config['enabled'] : false, $next );
			if ( $ok ) {
				self::set_state( array( 'generation' => $next ) );
			}
			self::schedule_gc( 30 );
			// Without a working WP-Cron, old copies would pile up: tidy a small batch now.
			if ( count( self::old_generations() ) > 2 ) {
				self::gc( 300 );
			}
			return $ok;
		}

		/**
		 * Folders of generations no longer served.
		 *
		 * @return string[]
		 */
		private static function old_generations() {
			$current = self::state()['generation'];
			return array_values(
				array_filter(
					(array) glob( UiChemy_Cache_Engine::root() . '/pages/g*', GLOB_ONLYDIR ),
					static function ( $dir ) use ( $current ) {
						return (int) substr( basename( $dir ), 1 ) !== $current;
					}
				)
			);
		}

		/**
		 * Clear pages by URL (every scheme and device variant). Only URLs on this
		 * site; others are ignored.
		 *
		 * @param string[] $urls Absolute URLs.
		 * @return int Pages cleared.
		 */
		public static function clear_urls( array $urls ) {
			$config = UiChemy_Cache_Engine::config( true );
			if ( ! $config ) {
				return 0;
			}
			$count = 0;
			foreach ( array_unique( $urls ) as $url ) {
				foreach ( self::hashes_for_url( $url, $config ) as $hash ) {
					$base = UiChemy_Cache_Engine::file_base( $hash, $config['generation'] );
					$tomb = UiChemy_Cache_Engine::tombstone( $hash );
					if ( wp_mkdir_p( dirname( $tomb ) ) && UiChemy_Cache_Engine::inside_root( dirname( $tomb ) ) ) {
						// The exact time, so a render that starts later in the same second may still save.
						UiChemy_Cache_Engine::write( $tomb, sprintf( '%.6F', microtime( true ) ) );
					}
					foreach ( array( '.html', '.html.gz', '.meta' ) as $ext ) {
						if ( is_file( $base . $ext ) && UiChemy_Cache_Engine::inside_root( $base . $ext ) ) {
							wp_delete_file( $base . $ext );
							if ( '.html' === $ext ) {
								++$count;
							}
						}
					}
				}
			}
			return $count;
		}

		/**
		 * Every key a URL can be stored under.
		 *
		 * @param string $url    Absolute URL.
		 * @param array  $config Snapshot.
		 * @return string[]
		 */
		public static function hashes_for_url( $url, array $config ) {
			$parts = wp_parse_url( $url );
			if ( ! $parts || empty( $parts['host'] ) ) {
				return array();
			}
			$host = strtolower( $parts['host'] ) . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
			if ( $host !== $config['host'] ) {
				return array();
			}
			$path = UiChemy_Cache_Engine::normalize_path( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' );
			if ( null === $path ) {
				return array();
			}
			$out = array();
			foreach ( array( 'http', 'https' ) as $scheme ) {
				foreach ( array( 'desktop', 'mobile' ) as $device ) {
					$out[] = UiChemy_Cache_Engine::hash( $scheme, $config['host'], $path, $device );
				}
			}
			return $out;
		}

		/**
		 * Seconds since a page's freshest cached copy was saved, or null when no
		 * fresh copy exists (used by preload to refresh pages before they expire).
		 *
		 * @param string $url    Absolute URL.
		 * @param string $device desktop | mobile | '' for any.
		 * @return int|null
		 */
		public static function age( $url, $device = '' ) {
			$config = UiChemy_Cache_Engine::config( true );
			if ( ! $config || empty( $config['enabled'] ) ) {
				return null;
			}
			$parts = wp_parse_url( $url );
			$path  = UiChemy_Cache_Engine::normalize_path( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' );
			if ( null === $path || empty( $parts['host'] ) ) {
				return null;
			}
			$best = null;
			foreach ( array( 'http', 'https' ) as $scheme ) {
				foreach ( '' === $device ? array( 'desktop', 'mobile' ) : array( $device ) as $d ) {
					$file = UiChemy_Cache_Engine::fresh_file( UiChemy_Cache_Engine::hash( $scheme, $config['host'], $path, $d ), $config );
					if ( $file ) {
						$age  = max( 0, time() - (int) filemtime( $file ) );
						$best = null === $best ? $age : min( $best, $age );
					}
				}
			}
			return $best;
		}

		/**
		 * Is a page stored and fresh right now?
		 *
		 * @param string $url Absolute URL.
		 * @return bool
		 */
		public static function is_cached( $url ) {
			$config = UiChemy_Cache_Engine::config( true );
			if ( ! $config || empty( $config['enabled'] ) ) {
				return false;
			}
			foreach ( self::hashes_for_url( $url, $config ) as $hash ) {
				if ( UiChemy_Cache_Engine::fresh_file( $hash, $config ) ) {
					return true;
				}
			}
			return false;
		}

		// ------------------------------------------------------------------
		// Background cleanup and size cap.
		// ------------------------------------------------------------------

		/**
		 * @param int $delay Seconds from now.
		 * @return void
		 */
		public static function schedule_gc( $delay = 3600 ) {
			if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
				wp_schedule_single_event( time() + (int) $delay, self::GC_HOOK );
			}
		}

		/**
		 * One cleanup run: old generations, stale tombstones, then the size cap.
		 *
		 * @param int $budget Most files to delete in this run.
		 * @return array{deleted:int,more:bool}
		 */
		public static function gc( $budget = self::GC_BATCH ) {
			$root    = UiChemy_Cache_Engine::root();
			$current = self::state()['generation'];
			$budget  = max( 1, (int) $budget );
			$deleted = 0;

			foreach ( (array) glob( $root . '/pages/g*', GLOB_ONLYDIR ) as $dir ) {
				if ( (int) substr( basename( $dir ), 1 ) === $current ) {
					continue;
				}
				$deleted += self::delete_tree( $dir, $budget - $deleted );
				if ( $deleted >= $budget ) {
					break;
				}
			}

			// Tombstones only matter for renders in flight.
			foreach ( (array) glob( $root . '/purged/*/*' ) as $tomb ) {
				if ( $deleted >= $budget ) {
					break;
				}
				if ( is_file( $tomb ) && time() - (int) filemtime( $tomb ) > HOUR_IN_SECONDS ) {
					wp_delete_file( $tomb );
					++$deleted;
				}
			}

			self::prune_stats();

			if ( $deleted < $budget ) {
				$deleted += self::enforce_cap( $budget - $deleted );
			}

			$more = $deleted >= $budget;
			if ( $more ) {
				self::schedule_gc( 60 );
			}
			return array(
				'deleted' => $deleted,
				'more' => $more,
			);
		}

		/**
		 * Keep the current generation under the size cap, oldest first.
		 *
		 * @param int $budget Max files to delete.
		 * @return int Deleted.
		 */
		private static function enforce_cap( $budget ) {
			$files = self::current_files();
			$bytes = 0;
			foreach ( $files as $f ) {
				$bytes += $f['size'];
			}
			if ( $bytes <= self::MAX_BYTES ) {
				return 0;
			}
			self::set_state( array( 'full_at' => time() ) );
			usort(
				$files,
				static function ( $a, $b ) {
					return $a['mtime'] - $b['mtime'];
				}
			);
			$deleted = 0;
			$count   = count( $files );
			foreach ( $files as $f ) {
				if ( $bytes <= self::MAX_BYTES || $deleted >= $budget ) {
					break;
				}
				foreach ( array( $f['file'], $f['file'] . '.gz', substr( $f['file'], 0, -5 ) . '.meta' ) as $p ) {
					if ( is_file( $p ) && UiChemy_Cache_Engine::inside_root( $p ) ) {
						wp_delete_file( $p );
					}
				}
				$bytes -= $f['size'];
				--$count;
				++$deleted;
			}
			return $deleted;
		}

		/**
		 * Stored pages in the current generation.
		 *
		 * @return array<int,array{file:string,size:int,mtime:int}>
		 */
		private static function current_files() {
			$out = array();
			foreach ( (array) glob( UiChemy_Cache_Engine::generation_dir( self::state()['generation'] ) . '/*/*.html' ) as $file ) {
				$gz    = $file . '.gz';
				$out[] = array(
					'file'  => $file,
					'size'  => (int) filesize( $file ) + ( is_file( $gz ) ? (int) filesize( $gz ) : 0 ),
					'mtime' => (int) filemtime( $file ),
				);
			}
			return $out;
		}

		/**
		 * Pages cached and bytes used, for the status card.
		 *
		 * @return array{count:int,bytes:int}
		 */
		public static function stats() {
			$count = 0;
			$bytes = 0;
			foreach ( self::current_files() as $f ) {
				++$count;
				$bytes += $f['size'];
			}
			return array(
				'count' => $count,
				'bytes' => $bytes,
			);
		}

		/**
		 * Traffic counts for the usage chart: hits (served from the cache) and
		 * misses (a page built fresh and saved: the first visit after a change or
		 * expiry), from the hourly files the drop-in appends
		 * to (UiChemy_Cache_Engine::tick). Reads at most STATS_DAYS * 24 * 2 tiny
		 * files, only when the screen or the AI asks.
		 *
		 * @return array{hourly:array,daily:array,total:array{hits:int,misses:int,rate:?int},since:int}
		 */
		public static function usage() {
			$days   = UiChemy_Cache_Engine::STATS_DAYS;
			$now    = time();
			$offset = (int) wp_timezone()->getOffset( new DateTime( '@' . $now ) );
			$hour0  = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
			$day0   = (int) ( floor( ( $now + $offset ) / DAY_IN_SECONDS ) * DAY_IN_SECONDS ) - $offset;

			$hourly = array();
			for ( $i = 23; $i >= 0; $i-- ) {
				$hourly[ $hour0 - $i * HOUR_IN_SECONDS ] = array(
					't' => $hour0 - $i * HOUR_IN_SECONDS,
					'h' => 0,
					'm' => 0,
				);
			}
			$daily = array();
			for ( $i = $days - 1; $i >= 0; $i-- ) {
				$daily[ $day0 - $i * DAY_IN_SECONDS ] = array(
					't' => $day0 - $i * DAY_IN_SECONDS,
					'h' => 0,
					'm' => 0,
				);
			}

			$hits   = 0;
			$misses = 0;
			$since  = 0;
			clearstatcache();
			foreach ( (array) glob( UiChemy_Cache_Engine::root() . '/stats/[hm]-??????????' ) as $file ) {
				$kind = $file[ strlen( $file ) - 12 ];
				$when = DateTime::createFromFormat( 'YmdH', substr( $file, -10 ), new DateTimeZone( 'UTC' ) );
				$n    = (int) filesize( $file );
				if ( ! $when || ! $n ) {
					continue;
				}
				$t = (int) $when->setTime( (int) $when->format( 'H' ), 0, 0 )->getTimestamp();
				$d = (int) ( floor( ( $t + $offset ) / DAY_IN_SECONDS ) * DAY_IN_SECONDS ) - $offset;
				if ( ! isset( $daily[ $d ] ) ) {
					continue;
				}
				$daily[ $d ][ $kind ] += $n;
				if ( isset( $hourly[ $t ] ) ) {
					$hourly[ $t ][ $kind ] += $n;
				}
				if ( 'h' === $kind ) {
					$hits += $n;
				} else {
					$misses += $n;
				}
				$since = ! $since || $t < $since ? $t : $since;
			}
			// Labels in the site's time zone ("17 Nov", "3 PM"), so the chart never guesses it.
			foreach ( $hourly as $t => $row ) {
				$hourly[ $t ]['l'] = wp_date( 'g A', $t );
			}
			foreach ( $daily as $t => $row ) {
				$daily[ $t ]['l'] = wp_date( 'j M', $t + 12 * HOUR_IN_SECONDS );
			}
			$all = $hits + $misses;
			return array(
				'hourly' => array_values( $hourly ),
				'daily'  => array_values( $daily ),
				'total'  => array(
					'hits' => $hits,
					'misses' => $misses,
					'rate' => $all ? (int) round( 100 * $hits / $all ) : null,
				),
				'since'  => $since,
			);
		}

		/**
		 * The raw hourly counts (UTC hour starts) for the screen, which groups them
		 * into the viewer's own hours and days. Only hours with traffic are listed.
		 *
		 * @return array<int,array{0:int,1:int,2:int}> [ hour start, hits, misses ]
		 */
		public static function hours() {
			$out = array();
			clearstatcache();
			foreach ( (array) glob( UiChemy_Cache_Engine::root() . '/stats/[hm]-??????????' ) as $file ) {
				$when = DateTime::createFromFormat( 'YmdH', substr( $file, -10 ), new DateTimeZone( 'UTC' ) );
				$n    = (int) filesize( $file );
				if ( ! $when || ! $n ) {
					continue;
				}
				$t = (int) $when->setTime( (int) $when->format( 'H' ), 0, 0 )->getTimestamp();
				if ( $t < time() - ( UiChemy_Cache_Engine::STATS_DAYS + 1 ) * DAY_IN_SECONDS || $t > time() + HOUR_IN_SECONDS ) {
					continue;
				}
				if ( ! isset( $out[ $t ] ) ) {
					$out[ $t ] = array( $t, 0, 0 );
				}
				$out[ $t ][ 'h' === $file[ strlen( $file ) - 12 ] ? 1 : 2 ] += $n;
			}
			ksort( $out );
			return array_values( $out );
		}

		/**
		 * Remove hourly counts older than STATS_DAYS.
		 *
		 * @return void
		 */
		private static function prune_stats() {
			$limit = gmdate( 'YmdH', time() - UiChemy_Cache_Engine::STATS_DAYS * DAY_IN_SECONDS );
			foreach ( (array) glob( UiChemy_Cache_Engine::root() . '/stats/[hm]-??????????' ) as $file ) {
				if ( substr( $file, -10 ) < $limit ) {
					wp_delete_file( $file );
				}
			}
		}

		/**
		 * Delete a folder inside the cache root, up to $budget files.
		 *
		 * @param string $dir    Folder.
		 * @param int    $budget Max files.
		 * @return int Files deleted.
		 */
		public static function delete_tree( $dir, $budget = PHP_INT_MAX ) {
			if ( ! is_dir( $dir ) || ! UiChemy_Cache_Engine::inside_root( $dir ) ) {
				return 0;
			}
			$deleted = 0;
			$items   = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				if ( $deleted >= $budget ) {
					return $deleted;
				}
				$path = $item->getPathname();
				if ( $item->isLink() ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				} elseif ( $item->isDir() ) {
					@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					wp_delete_file( $path );
					++$deleted;
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			return $deleted;
		}

		// ------------------------------------------------------------------
		// Teardown (deactivate, uninstall, turned off).
		// ------------------------------------------------------------------

		/**
		 * Remove our drop-in, our wp-config line, our folder and our cron event.
		 *
		 * @return void
		 */
		public static function teardown() {
			self::remove_dropin();
			self::remove_wp_cache_line();
			wp_clear_scheduled_hook( self::GC_HOOK );
			wp_clear_scheduled_hook( 'uichemy_cache_gc_hourly' );
			wp_clear_scheduled_hook( 'uichemy_cache_preload' );
			delete_option( 'uichemy_cache_preload' );
			$root = UiChemy_Cache_Engine::root();
			if ( is_dir( $root ) ) {
				self::delete_tree( $root );
			}
			// wp-content/uichemy is shared with other UiChemy features: remove it only when empty.
			@rmdir( dirname( $root ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}
