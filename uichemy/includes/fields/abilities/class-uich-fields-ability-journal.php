<?php
/**
 * The ability write journal, stored in its own table.
 *
 * It was a bounded option holding the most recent 200 entries, which meant a client retrying a
 * failing call in a loop could push out the successful write someone was looking for. A table keeps
 * what actually happened, for as long as the retention window says.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Fields_Ability_Journal' ) ) {

	/**
	 * Stores and reads ability write activity.
	 */
	class Uich_Fields_Ability_Journal {

		const TABLE_SUFFIX      = 'uichemy_fields_journal';
		const SCHEMA_VERSION    = 2;
		const OPTION_SCHEMA_VER = 'uichemy_fields_journal_db';
		const OPTION_DROPPED    = 'uichemy_fields_journal_dropped';
		const OPTION_PRUNED_AT  = 'uichemy_fields_journal_pruned_at';

		// The option the journal used to live in. Read once during install, then removed.
		const LEGACY_OPTION = 'uichemy_fields_journal';

		/**
		 * Whether the table is present, resolved once per request.
		 *
		 * @var ?bool
		 */
		private static $ready = null;

		/**
		 * True while install_table() runs, so its own table_ready() check does not start a second install.
		 *
		 * @var bool
		 */
		private static $installing = false;

		/**
		 * Hook the installer up.
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );
			if ( is_multisite() ) {
				add_action( 'wp_initialize_site', array( __CLASS__, 'on_initialize_site' ), 20 );
				add_action( 'wp_uninitialize_site', array( __CLASS__, 'on_uninitialize_site' ), 20 );
			}
		}

		/**
		 * Table name for the current site.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;
			return $wpdb->prefix . self::TABLE_SUFFIX;
		}

		/**
		 * How many days of activity to keep.
		 *
		 * @return int
		 */
		public static function retain_days() {
			$days = defined( 'UICH_FIELDS_JOURNAL_DAYS' ) ? (int) UICH_FIELDS_JOURNAL_DAYS : 90;
			/**
			 * Filters how long ability write activity is kept.
			 *
			 * @param int $days Retention window in days. 0 keeps everything.
			 */
			return max( 0, (int) apply_filters( 'uich_fields_ability_journal_days', $days ) );
		}

		/**
		 * Hard ceiling, so a burst inside one retention window cannot grow the table without limit.
		 *
		 * @return int
		 */
		public static function max_rows() {
			$max = defined( 'UICH_FIELDS_JOURNAL_MAX_ROWS' ) ? (int) UICH_FIELDS_JOURNAL_MAX_ROWS : 50000;
			return max( 100, (int) apply_filters( 'uich_fields_ability_journal_max_rows', $max ) );
		}

		/**
		 * Install or upgrade the table when the schema version has moved.
		 */
		public static function maybe_install_table() {
			if ( (int) uich_fields_flag( 'journal_db', self::OPTION_SCHEMA_VER ) >= self::SCHEMA_VERSION ) {
				return;
			}
			self::install_table();
		}

		/**
		 * Create the table and carry over whatever the option still holds.
		 */
		public static function install_table() {
			global $wpdb;
			self::$installing = true;
			$table   = self::table_name();
			$charset = $wpdb->get_charset_collate();

			// 191 rather than 255 on the indexed columns: utf8mb4 indexes cap at 767 bytes on older
			// MySQL, and an ability name has never come close to 191 anyway.
			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				logged_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ability VARCHAR(191) NOT NULL DEFAULT '',
				batch VARCHAR(32) NOT NULL DEFAULT '',
				target BIGINT NOT NULL DEFAULT 0,
				hash_before VARCHAR(32) NOT NULL DEFAULT '',
				hash_after VARCHAR(32) NOT NULL DEFAULT '',
				object_ref VARCHAR(64) NOT NULL DEFAULT '',
				subject VARCHAR(191) NOT NULL DEFAULT '',
				dry_run TINYINT(1) NOT NULL DEFAULT 0,
				ok TINYINT(1) NOT NULL DEFAULT 0,
				error VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY logged_at (logged_at),
				KEY ability_logged (ability, logged_at),
				KEY ok_logged (ok, logged_at),
				KEY batch (batch)
			) {$charset};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			self::$ready = null;
			if ( ! self::table_ready() ) {
				// Creation failed — a read-only or restricted database is a real deployment. Leave
				// the schema version alone so the next admin request tries again, and let the writer
				// keep falling back to the option.
				self::$installing = false;
				return;
			}

			self::$installing = false;
			self::migrate_option();
			uich_fields_flag_set( 'journal_db', self::SCHEMA_VERSION );
		}

		/**
		 * Move whatever the bounded option still holds into the table, once.
		 */
		private static function migrate_option() {
			$legacy = get_option( self::LEGACY_OPTION, array() );
			if ( ! is_array( $legacy ) || ! $legacy ) {
				delete_option( self::LEGACY_OPTION );
				return;
			}

			foreach ( $legacy as $entry ) {
				if ( is_array( $entry ) ) {
					self::insert( $entry );
				}
			}

			delete_option( self::LEGACY_OPTION );
		}

		/**
		 * Whether the table exists. Resolved once per request — every write asks.
		 *
		 * @return bool
		 */
		public static function table_ready() {
			if ( null !== self::$ready ) {
				return self::$ready;
			}
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::$ready = ( $found === $table );
			if ( self::$ready ) {
				self::ensure_schema();
			}
			return self::$ready;
		}

		/**
		 * Bring an older table up to the current schema the first time it is used.
		 *
		 * admin_init alone is not enough: abilities run over REST, which never fires it, so an upgraded
		 * plugin would keep inserting into columns the table does not have yet and lose every entry.
		 * Attempted once per request, so a failing upgrade cannot loop.
		 */
		private static function ensure_schema() {
			static $tried = false;
			if ( $tried || self::$installing || (int) uich_fields_flag( 'journal_db', self::OPTION_SCHEMA_VER ) >= self::SCHEMA_VERSION ) {
				return;
			}
			$tried = true;
			self::install_table();
		}

		/**
		 * Write one entry.
		 *
		 * @param array $entry Journal entry in the lifecycle's shape.
		 * @return bool Whether it was stored in the table.
		 */
		public static function append( array $entry ) {
			if ( ! self::table_ready() ) {
				return false;
			}
			$done = self::insert( $entry );
			self::maybe_prune();
			return $done;
		}

		/**
		 * Insert without pruning, so the migration does not prune while it is still filling.
		 *
		 * @param array $entry Journal entry.
		 * @return bool
		 */
		private static function insert( array $entry ) {
			global $wpdb;
			$time = isset( $entry['time'] ) ? (int) $entry['time'] : time();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false !== $wpdb->insert(
				self::table_name(),
				array(
					'logged_at'   => gmdate( 'Y-m-d H:i:s', $time ),
					'user_id'     => (int) ( $entry['user'] ?? 0 ),
					'ability'     => substr( (string) ( $entry['ability'] ?? '' ), 0, 191 ),
					'batch'       => substr( (string) ( $entry['batch'] ?? '' ), 0, 32 ),
					'target'      => (int) ( $entry['target'] ?? 0 ),
					'hash_before' => substr( (string) ( $entry['hash_before'] ?? '' ), 0, 32 ),
					'hash_after'  => substr( (string) ( $entry['hash_after'] ?? '' ), 0, 32 ),
					'object_ref'  => substr( (string) ( $entry['object'] ?? '' ), 0, 64 ),
					'subject'     => substr( (string) ( $entry['subject'] ?? '' ), 0, 191 ),
					'dry_run'     => empty( $entry['dry_run'] ) ? 0 : 1,
					'ok'          => empty( $entry['ok'] ) ? 0 : 1,
					'error'       => substr( (string) ( $entry['error'] ?? '' ), 0, 191 ),
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}

		/**
		 * Drop what has aged out, at most once a day, plus anything over the hard ceiling.
		 */
		private static function maybe_prune() {
			$today = gmdate( 'Y-m-d' );
			if ( get_option( self::OPTION_PRUNED_AT, '' ) === $today ) {
				return;
			}
			update_option( self::OPTION_PRUNED_AT, $today, false );
			self::prune();
		}

		/**
		 * Apply the retention window and the row ceiling.
		 *
		 * @return int Rows removed.
		 */
		public static function prune() {
			global $wpdb;
			if ( ! self::table_ready() ) {
				return 0;
			}
			$table   = self::table_name();
			$dropped = 0;

			$days = self::retain_days();
			if ( $days > 0 ) {
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dropped += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $cutoff ) );
			}

			$max = self::max_rows();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			if ( $count > $max ) {
				// Delete by id below the cut rather than with OFFSET: one indexed range, and it stays
				// correct if rows are written while this runs.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cut = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d, 1", $max ) );
				if ( $cut > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$dropped += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cut ) );
				}
			}

			if ( $dropped > 0 ) {
				// Counted, because "truncated" has to mean something a caller can trust: entries were
				// really removed, rather than the store merely being near its limit.
				update_option( self::OPTION_DROPPED, (int) get_option( self::OPTION_DROPPED, 0 ) + $dropped, false );
			}

			return $dropped;
		}

		/**
		 * Read entries, newest first.
		 *
		 * @param array $args limit, ability, only_failures, batch.
		 * @return array{rows:array,total:int}
		 */
		public static function query( array $args = array() ) {
			global $wpdb;
			if ( ! self::table_ready() ) {
				return array(
					'rows'  => array(),
					'total' => 0,
				);
			}

			$table = self::table_name();
			$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 20;

			$where  = array( '1=1' );
			$params = array();

			if ( ! empty( $args['ability'] ) ) {
				$where[]  = 'ability = %s';
				$params[] = (string) $args['ability'];
			}
			if ( ! empty( $args['only_failures'] ) ) {
				$where[] = 'ok = 0';
			}
			if ( ! empty( $args['batch'] ) ) {
				$where[]  = 'batch = %s';
				$params[] = (string) $args['batch'];
			}

			$clause = implode( ' AND ', $where );

			// Counted separately so `total` is how many match, not how many were returned — the
			// difference is the whole point of a limit.
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

			$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d";
			$all      = array_merge( $params, array( $limit ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $all ), ARRAY_A );

			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'time'        => (int) strtotime( (string) $row['logged_at'] . ' UTC' ),
					'user'        => (int) $row['user_id'],
					'ability'     => (string) $row['ability'],
					'batch'       => (string) $row['batch'],
					'target'      => (int) $row['target'],
					'hash_before' => (string) $row['hash_before'],
					'hash_after'  => (string) $row['hash_after'],
					'object'      => isset( $row['object_ref'] ) ? (string) $row['object_ref'] : '',
					'subject'     => isset( $row['subject'] ) ? (string) $row['subject'] : '',
					'dry_run'     => ! empty( $row['dry_run'] ),
					'ok'          => ! empty( $row['ok'] ),
					'error'       => (string) $row['error'],
				);
			}

			return array(
				'rows'  => $out,
				'total' => $total,
			);
		}

		/**
		 * How much is held, and how much has been dropped.
		 *
		 * @return array{retained:int,dropped:int,oldest:int}
		 */
		public static function stats() {
			global $wpdb;
			if ( ! self::table_ready() ) {
				return array(
					'retained' => 0,
					'dropped'  => 0,
					'oldest'   => 0,
				);
			}
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$retained = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$oldest = (string) $wpdb->get_var( "SELECT logged_at FROM {$table} ORDER BY id ASC LIMIT 1" );

			return array(
				'retained' => $retained,
				'dropped'  => (int) get_option( self::OPTION_DROPPED, 0 ),
				'oldest'   => '' !== $oldest ? (int) strtotime( $oldest . ' UTC' ) : 0,
			);
		}

		/**
		 * Empty the journal.
		 */
		public static function clear() {
			global $wpdb;
			if ( self::table_ready() ) {
				$table = self::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
			delete_option( self::OPTION_DROPPED );
			delete_option( self::LEGACY_OPTION );
		}

		/**
		 * Create the table on a new site in a network.
		 *
		 * @param mixed $site New site object.
		 */
		public static function on_initialize_site( $site ) {
			if ( ! is_object( $site ) || empty( $site->blog_id ) ) {
				return;
			}
			switch_to_blog( (int) $site->blog_id );
			self::$ready = null;
			self::install_table();
			self::$ready = null;
			restore_current_blog();
		}

		/**
		 * Remove the table with the site.
		 *
		 * @param mixed $site Site being removed.
		 */
		public static function on_uninitialize_site( $site ) {
			global $wpdb;
			if ( ! is_object( $site ) || empty( $site->blog_id ) ) {
				return;
			}
			switch_to_blog( (int) $site->blog_id );
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			uich_fields_flag_delete( 'journal_db', self::OPTION_SCHEMA_VER );
			delete_option( self::OPTION_DROPPED );
			delete_option( self::OPTION_PRUNED_AT );
			self::$ready = null;
			restore_current_blog();
		}
	}
}
