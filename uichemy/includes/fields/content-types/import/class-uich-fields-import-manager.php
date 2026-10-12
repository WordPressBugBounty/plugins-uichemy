<?php
/**
 * Import orchestrator: scan (dry run), run, rollback.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uich_Fields_Import_Manager
 */
class Uich_Fields_Import_Manager {

	/** Back-reference written on the Uichemy definition, naming where it came from. */
	/**
	 * Post meta that recorded an import's origin while definitions were posts.
	 */
	const META_FROM = '_uichemy_import_from';

	/** Back-reference written on the SOURCE definition, naming the Uichemy definition it became. */
	const META_TO = '_uichemy_import_to';

	/** Prefix for value backups. Namespaced so it can be cleaned up without guessing. */
	const BAK_PREFIX = '_uichemy_import_bak_';

	/**
	 * Prefix of the per-source run lock.
	 *
	 * Claimed with add_option(), which inserts into a table with a UNIQUE index on option_name, so
	 * "does it exist" and "claim it" are one statement. That is what makes this a real mutex rather
	 * than the check-then-create pattern two workers can both pass: the import map is a
	 * read-modify-write over one JSON sidecar, and an interleaved pair of runs silently drops one
	 * side's mapping, which is what makes the NEXT run create a duplicate definition.
	 */
	const LOCK_OPTION = 'uich_import_lock_';

	/** A run that has not touched its lock for this long is treated as dead and can be taken over. */
	const LOCK_STALE_AFTER = 300;

	/**
	 * Option name holding one source's lock.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function lock_key( $slug ) {
		return self::LOCK_OPTION . sanitize_key( $slug );
	}

	/**
	 * Claim the run lock for a source.
	 *
	 * @param string $slug Source slug.
	 * @return string|WP_Error Token to release with, or an error naming the current holder.
	 */
	public static function acquire_lock( $slug ) {
		$key  = self::lock_key( $slug );
		$now  = time();
		$mine = array(
			'token'   => wp_generate_uuid4(),
			'user'    => get_current_user_id(),
			'started' => $now,
			'touched' => $now,
		);

		if ( add_option( $key, $mine, '', false ) ) {
			return $mine['token'];
		}

		$held = get_option( $key );
		$age  = ( is_array( $held ) && isset( $held['touched'] ) ) ? ( $now - (int) $held['touched'] ) : PHP_INT_MAX;
		if ( $age < self::LOCK_STALE_AFTER ) {
			$who = ( is_array( $held ) && ! empty( $held['user'] ) ) ? get_userdata( (int) $held['user'] ) : null;
			return new WP_Error(
				'uich_import_locked',
				$who
					? sprintf(
						/* translators: 1: user display name, 2: human-readable duration, e.g. "2 mins". */
						__( 'An import from this source is already running (started by %1$s, %2$s ago). Open the migration screen to follow it instead of starting a second one.', 'uichemy' ),
						$who->display_name,
						human_time_diff( (int) $held['started'], $now )
					)
					: __( 'An import from this source is already running. Open the migration screen to follow it instead of starting a second one.', 'uichemy' ),
				array(
					'status'  => 409,
					'running' => true,
					'started' => is_array( $held ) && isset( $held['started'] ) ? (int) $held['started'] : 0,
				)
			);
		}

		// Stale: a run killed by a timeout or a restart must not lock the source out for good.
		$mine['took_over'] = true;
		update_option( $key, $mine, false );
		return $mine['token'];
	}

	/**
	 * Keep a long batched job's lock alive between requests.
	 *
	 * @param string $slug  Source slug.
	 * @param string $token Token from acquire_lock().
	 * @return bool Whether the caller still owns the lock.
	 */
	public static function touch_lock( $slug, $token ) {
		$held = get_option( self::lock_key( $slug ) );
		if ( ! is_array( $held ) || ! isset( $held['token'] ) || ! hash_equals( (string) $held['token'], (string) $token ) ) {
			return false;
		}
		$held['touched'] = time();
		update_option( self::lock_key( $slug ), $held, false );
		return true;
	}

	/**
	 * Release the lock, but only if this caller still holds it — a run whose lock was taken over as
	 * stale must not delete the lock the new owner is relying on.
	 *
	 * @param string $slug  Source slug.
	 * @param string $token Token from acquire_lock().
	 * @return void
	 */
	public static function release_lock( $slug, $token ) {
		$held = get_option( self::lock_key( $slug ) );
		if ( is_array( $held ) && isset( $held['token'] ) && hash_equals( (string) $held['token'], (string) $token ) ) {
			delete_option( self::lock_key( $slug ) );
		}
	}

	/**
	 * Who holds a source's lock, for the progress UI.
	 *
	 * @param string $slug Source slug.
	 * @return array{running:bool,user:int,started:int,age:int}
	 */
	public static function lock_status( $slug ) {
		$held = get_option( self::lock_key( $slug ) );
		if ( ! is_array( $held ) || empty( $held['touched'] ) ) {
			return array(
				'running' => false,
				'user'    => 0,
				'started' => 0,
				'age'     => 0,
			);
		}
		$age = time() - (int) $held['touched'];
		return array(
			'running' => ( $age < self::LOCK_STALE_AFTER ),
			'user'    => isset( $held['user'] ) ? (int) $held['user'] : 0,
			'started' => isset( $held['started'] ) ? (int) $held['started'] : 0,
			'age'     => $age,
		);
	}

	/** Attempts made before an item is left for a human to look at. */
	const MAX_ATTEMPTS = 4;

	/**
	 * Seconds to wait before the next attempt, indexed by attempts already made. The last entry
	 * repeats. Retrying a database timeout immediately just reproduces it, so the first retry is
	 * the only free one.
	 */
	const RETRY_BACKOFF = array( 0, 30, 120, 600 );

	/** Phases a batched job moves through, in order. */
	const JOB_PHASES = array( 'definitions', 'values', 'finalise' );

	/**
	 * Failure ledger cap. The job lives in one option, so an import of 100k objects that fails on
	 * every one must not grow it without bound; the overflow is counted instead.
	 */
	const MAX_LEDGER = 500;

	/**
	 * Identity of one unit of value work: this object, under this group.
	 *
	 * @param int    $group_id    Group source id.
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private static function item_key( $group_id, $object_type, $object_id ) {
		return (int) $group_id . ':' . $object_type . ':' . (int) $object_id;
	}

	/**
	 * When the next attempt for an item becomes due.
	 *
	 * @param int $attempts Attempts already made.
	 * @return int Unix time.
	 */
	private static function next_attempt_at( $attempts ) {
		$schedule = self::RETRY_BACKOFF;
		$i        = max( 0, min( (int) $attempts, count( $schedule ) - 1 ) );
		return time() + (int) $schedule[ $i ];
	}

	/**
	 * Record or re-record a failed item, carrying its attempt count forward.
	 *
	 * @param array  $job  Job state (by reference).
	 * @param string $key  Item key.
	 * @param array  $meta phase, code, error, retryable, group, object_type, object_id, dest_id.
	 * @return void
	 */
	private static function record_failure( &$job, $key, $meta ) {
		if ( ! isset( $job['items'] ) || ! is_array( $job['items'] ) ) {
			$job['items'] = array();
		}
		$existing = isset( $job['items'][ $key ] ) ? $job['items'][ $key ] : array();
		$attempts = isset( $existing['attempts'] ) ? (int) $existing['attempts'] + 1 : 1;

		if ( ! isset( $job['items'][ $key ] ) && count( $job['items'] ) >= self::MAX_LEDGER ) {
			$job['ledger_overflow'] = isset( $job['ledger_overflow'] ) ? (int) $job['ledger_overflow'] + 1 : 1;
			return;
		}

		$job['items'][ $key ] = array(
			'status'    => 'failed',
			'phase'     => isset( $meta['phase'] ) ? (string) $meta['phase'] : 'values',
			'attempts'  => $attempts,
			'code'      => isset( $meta['code'] ) ? (string) $meta['code'] : 'uich_import_failed',
			'error'     => isset( $meta['error'] ) ? (string) $meta['error'] : '',
			// A value the sanitizer refuses is a configuration problem: retrying it produces the
			// same refusal for ever, so it is recorded but never picked up automatically.
			'retryable' => ! empty( $meta['retryable'] ),
			'last_try'  => time(),
			'next_try'  => self::next_attempt_at( $attempts ),
			'group'     => isset( $meta['group'] ) ? (int) $meta['group'] : 0,
			'object'    => array(
				'type' => isset( $meta['object_type'] ) ? (string) $meta['object_type'] : '',
				'id'   => isset( $meta['object_id'] ) ? (int) $meta['object_id'] : 0,
			),
			'dest_id'   => isset( $meta['dest_id'] ) ? (int) $meta['dest_id'] : 0,
			'label'     => isset( $meta['label'] ) ? (string) $meta['label'] : '',
		);
	}

	/**
	 * Drop an item from the ledger because it has now succeeded.
	 *
	 * @param array  $job Job state (by reference).
	 * @param string $key Item key.
	 * @return void
	 */
	private static function clear_failure( &$job, $key ) {
		if ( isset( $job['items'][ $key ] ) ) {
			unset( $job['items'][ $key ] );
		}
	}

	/**
	 * Failed items eligible for another attempt.
	 *
	 * @param array $job   Job state.
	 * @param bool  $force True for a manual retry: ignores the backoff and the retryable flag,
	 *                     because the operator has decided to try anyway.
	 * @return array<string, array> Item key => item.
	 */
	private static function due_failures( $job, $force = false ) {
		$out   = array();
		$now   = time();
		$items = isset( $job['items'] ) && is_array( $job['items'] ) ? $job['items'] : array();
		foreach ( $items as $key => $item ) {
			if ( 'failed' !== ( isset( $item['status'] ) ? $item['status'] : '' ) ) {
				continue;
			}
			if ( ! $force ) {
				if ( empty( $item['retryable'] ) ) {
					continue;
				}
				if ( (int) $item['attempts'] >= self::MAX_ATTEMPTS ) {
					continue;
				}
				if ( (int) $item['next_try'] > $now ) {
					continue;
				}
			}
			$out[ $key ] = $item;
		}
		return $out;
	}

	/**
	 * Mark a job phase.
	 *
	 * @param array  $job   Job state (by reference).
	 * @param string $phase One of JOB_PHASES.
	 * @param string $state pending|processing|completed|failed.
	 * @return void
	 */
	private static function set_phase( &$job, $phase, $state ) {
		if ( ! isset( $job['phases'] ) || ! is_array( $job['phases'] ) ) {
			$job['phases'] = array_fill_keys( self::JOB_PHASES, 'pending' );
		}
		$job['phases'][ $phase ] = $state;
	}

	/**
	 * Copy one object's values and keep the failure ledger in step.
	 *
	 * The try/catch is the point: a database timeout inside the value write used to take the whole
	 * request down, losing the cursor and every count with it. Caught here it becomes one recorded,
	 * retryable item and the batch carries on.
	 *
	 * @param Uich_Fields_Import_Source $source      Source adapter.
	 * @param array                           $job         Job state (by reference).
	 * @param array                           $row         Group row from the job (a summary).
	 * @param array                           $def         Group descriptor from read_groups().
	 * @param string                          $object_type post|term|user|comment.
	 * @param int                             $object_id   Object id.
	 * @return void
	 */
	private static function process_object( $source, &$job, $row, $def, $object_type, $object_id ) {
		$gid    = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
		$fields = isset( $def['data']['fields'] ) ? $def['data']['fields'] : array();
		$key    = self::item_key( $gid, $object_type, $object_id );
		$common = array(
			'phase'       => 'values',
			'group'       => $gid,
			'object_type' => $object_type,
			'object_id'   => (int) $object_id,
			'dest_id'     => isset( $row['uich_fields_id'] ) ? (int) $row['uich_fields_id'] : 0,
			'label'       => self::object_label( $object_type, (int) $object_id ),
		);

		try {
			$out = self::copy_object_values( $source, $fields, $object_type, (int) $object_id );
		} catch ( Throwable $e ) {
			self::record_failure(
				$job,
				$key,
				array_merge(
					$common,
					array(
						'code'      => 'uich_import_exception',
						'error'     => $e->getMessage(),
						'retryable' => true,
					)
				)
			);
			++$job['values']['failed'];
			return;
		}

		$job['values']['written']   += $out['written'];
		$job['values']['skipped']   += $out['skipped'];
		$job['values']['unchanged'] += $out['unchanged'];
		$job['values']['failed']    += $out['failed'];
		if ( $out['errors'] && count( $job['errors'] ) < 50 ) {
			$job['errors'] = array_merge( $job['errors'], $out['errors'] );
		}

		if ( $out['failed'] > 0 ) {
			self::record_failure(
				$job,
				$key,
				array_merge(
					$common,
					array(
						'code'      => 'uich_import_value_refused',
						'error'     => implode( '; ', array_slice( $out['errors'], 0, 5 ) ),
						'retryable' => false,
					)
				)
			);
			return;
		}

		// Succeeded, so a record from a previous attempt goes away rather than lingering as a
		// permanent "failed" in the summary.
		self::clear_failure( $job, $key );
	}

	/**
	 * A readable name for a failed item, for the failed-items list.
	 *
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private static function object_label( $object_type, $object_id ) {
		switch ( $object_type ) {
			case 'term':
				$term = get_term( (int) $object_id );
				return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
			case 'user':
				$user = get_userdata( (int) $object_id );
				return $user ? $user->display_name : '';
			case 'comment':
				/* translators: %d: comment ID. */
				return sprintf( __( 'Comment #%d', 'uichemy' ), (int) $object_id );
			default:
				return (string) get_the_title( (int) $object_id );
		}
	}

	/**
	 * Re-attempt failed items only. A completed object must never be written a second time.
	 *
	 * @param string $slug Source slug.
	 * @param array  $args force (bool) ignores the backoff and the retryable flag; limit (int).
	 * @return array|WP_Error
	 */
	public static function retry_failed( $slug, $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'uich_import_forbidden', __( 'You do not have permission to do this.', 'uichemy' ), array( 'status' => 403 ) );
		}
		$args  = wp_parse_args(
			$args,
			array(
				'force' => false,
				'limit' => 0,
			)
		);
		$limit = (int) $args['limit'] > 0 ? (int) $args['limit'] : self::JOB_DEFAULT_LIMIT;

		$job = self::job_status( $slug );
		if ( ! $job ) {
			return new WP_Error( 'uich_import_no_job', __( 'There is no migration to retry for this source.', 'uichemy' ), array( 'status' => 404 ) );
		}
		$source = self::source( $slug );
		if ( ! $source ) {
			return new WP_Error( 'uich_import_unknown_source', __( 'Unknown import source.', 'uichemy' ), array( 'status' => 400 ) );
		}

		// Same lock as a run: a retry writes values and the ledger, so it must not race the job.
		$lock = self::acquire_lock( $slug );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$due = self::due_failures( $job, ! empty( $args['force'] ) );
			if ( ! $due ) {
				return array(
					'source'    => $slug,
					'retried'   => 0,
					'recovered' => 0,
					'remaining' => count( self::due_failures( $job, true ) ),
					'items'     => self::failed_summary( $job ),
				);
			}

			$by_group = array();
			foreach ( (array) $job['groups'] as $row ) {
				$by_group[ (int) $row['source_id'] ] = $row;
			}
			// Same rebuild the stepper does: the job stores a summary per group, not the fields.
			$defs = array();
			foreach ( $source->read_groups() as $g ) {
				if ( empty( $g['blocked'] ) ) {
					$defs[ (int) $g['source_id'] ] = $g;
				}
			}

			$retried   = 0;
			$recovered = 0;
			foreach ( $due as $key => $item ) {
				if ( $retried >= $limit ) {
					break;
				}
				$gid = (int) $item['group'];
				if ( ! isset( $by_group[ $gid ] ) || ! isset( $defs[ $gid ] ) ) {
					// The group is gone (rolled back), so there is nothing to retry against.
					self::clear_failure( $job, $key );
					continue;
				}
				++$retried;
				self::process_object( $source, $job, $by_group[ $gid ], $defs[ $gid ], $item['object']['type'], (int) $item['object']['id'] );
				if ( ! isset( $job['items'][ $key ] ) ) {
					++$recovered;
				}
			}

			$job['updated'] = time();
			update_option( self::job_key( $slug ), $job, false );

			return array(
				'source'    => $slug,
				'retried'   => $retried,
				'recovered' => $recovered,
				'remaining' => count( self::due_failures( $job, true ) ),
				'items'     => self::failed_summary( $job ),
			);
		} finally {
			self::release_lock( $slug, $lock );
		}
	}

	/**
	 * Failed items shaped for the failed-items list.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	public static function failed_summary( $job ) {
		$out   = array();
		$items = isset( $job['items'] ) && is_array( $job['items'] ) ? $job['items'] : array();
		foreach ( $items as $key => $item ) {
			$attempts = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;
			$out[]    = array(
				'key'       => $key,
				'label'     => ( isset( $item['label'] ) && '' !== $item['label'] ) ? $item['label'] : $key,
				'object'    => isset( $item['object'] ) ? $item['object'] : array(),
				'phase'     => isset( $item['phase'] ) ? $item['phase'] : '',
				'attempts'  => $attempts,
				'max'       => self::MAX_ATTEMPTS,
				'error'     => isset( $item['error'] ) ? $item['error'] : '',
				'code'      => isset( $item['code'] ) ? $item['code'] : '',
				'retryable' => ! empty( $item['retryable'] ),
				'exhausted' => ! empty( $item['retryable'] ) && $attempts >= self::MAX_ATTEMPTS,
				'next_try'  => isset( $item['next_try'] ) ? (int) $item['next_try'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Available sources on this site.
	 *
	 * @return Uich_Fields_Import_Source[] Keyed by slug.
	 */
	public static function sources() {
		$all = array(
			// One entry covers both ACF and Secure Custom Fields: SCF is the wordpress.org fork and
			// shares every post type and meta key, so two entries would read the same rows twice.
			new Uich_Fields_Import_ACF(),
			new Uich_Fields_Import_MetaBox(),
			new Uich_Fields_Import_Pods(),
			new Uich_Fields_Import_JetEngine(),
		);
		$out = array();
		foreach ( $all as $s ) {
			$out[ $s->slug() ] = $s;
		}
		/**
		 * Filter the registered import sources.
		 *
		 * @param Uich_Fields_Import_Source[] $out Sources keyed by slug.
		 */
		return apply_filters( 'uich/import_sources', $out );
	}

	/**
	 * Whether a source's definition ids are post ids, so post-level bookkeeping is safe to run.
	 *
	 * @param string $slug Source slug.
	 * @return bool
	 */
	private static function source_uses_post_ids( $slug ) {
		$source = self::source( $slug );
		return ! $source || $source->definitions_are_posts();
	}

	/**
	 * One source by slug, or null.
	 *
	 * @param string $slug Source slug.
	 * @return Uich_Fields_Import_Source|null
	 */
	public static function source( $slug ) {
		$sources = self::sources();
		return isset( $sources[ $slug ] ) ? $sources[ $slug ] : null;
	}

	/**
	 * Sources that actually have data here.
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	public static function detect() {
		$out = array();
		foreach ( self::sources() as $slug => $source ) {
			if ( $source->is_available() ) {
				$out[] = array(
					'slug'  => $slug,
					'label' => $source->label(),
				);
			}
		}
		return $out;
	}

	/**
	 * Whether this importer still holds value backups (post, term, user or comment meta).
	 *
	 * @param string $slug Source slug.
	 * @return bool
	 */
	public static function has_backups( $slug ) {
		global $wpdb;
		$like  = $wpdb->esc_like( self::BAK_PREFIX . $slug . '_' ) . '%';
		$ulike = $wpdb->esc_like( self::user_backup_prefix( $slug ) ) . '%';
		$pairs = array(
			array( $wpdb->postmeta, $like ),
			array( $wpdb->termmeta, $like ),
			array( $wpdb->usermeta, $ulike ),
			array( $wpdb->commentmeta, $like ),
		);
		foreach ( $pairs as $pair ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are core properties; must reflect the live rows.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$pair[0]} WHERE meta_key LIKE %s LIMIT 1", $pair[1] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Finished migrations that still need the operator: failed items to retry, or value backups
	 * that keep Undo possible. Lets Undo / Retry / Delete backups outlive the result screen.
	 *
	 * @return array<int, array>
	 */
	public static function open_results() {
		$out = array();
		foreach ( self::sources() as $slug => $source ) {
			$job = self::job_status( $slug );
			if ( ! $job || empty( $job['finished'] ) ) {
				continue;
			}
			$failed  = isset( $job['items'] ) && is_array( $job['items'] ) ? count( $job['items'] ) : 0;
			$backups = self::has_backups( $slug );
			if ( ! $failed && ! $backups ) {
				continue;
			}
			$out[] = array(
				'slug'        => $slug,
				'label'       => $source->label(),
				'finished_at' => isset( $job['updated'] ) ? (int) $job['updated'] : 0,
				'values'      => isset( $job['values'] ) ? $job['values'] : array(),
				'failed'      => $failed,
				'items'       => array_slice( self::failed_summary( $job ), 0, 20 ),
				'has_backups' => $backups,
				'can_undo'    => (bool) self::mapped_for_source( $slug ),
			);
		}
		return $out;
	}

	/**
	 * PHASE 0 — dry run.
	 *
	 * @param string $slug Source slug.
	 * @return array|WP_Error
	 */
	public static function scan( $slug ) {
		$source = self::source( $slug );
		if ( ! $source ) {
			return new WP_Error( 'uich_import_unknown_source', __( 'Unknown import source.', 'uichemy' ), array( 'status' => 400 ) );
		}
		if ( ! $source->is_available() ) {
			return new WP_Error( 'uich_import_unavailable', __( 'This source has nothing to import on this site.', 'uichemy' ), array( 'status' => 400 ) );
		}

		$taxonomies = $source->read_taxonomies();
		$post_types = $source->read_post_types();
		$groups     = $source->read_groups();

		$plan = array(
			'source'      => $slug,
			'label'       => $source->label(),
			'taxonomies'  => array(),
			'post_types'  => array(),
			'groups'      => array(),
			'blocked'     => array(),
			'value_count' => 0,
			'warnings'    => array(),
		);

		// Definition warnings are surfaced the same way group warnings are. Without this the adapters'
		// per-item warnings were built and then dropped, so a degraded advanced setting was invisible.
		foreach ( $taxonomies as $t ) {
			$t_warnings           = isset( $t['warnings'] ) ? (array) $t['warnings'] : array();
			$t_key                = isset( $t['data']['taxonomy_key'] ) ? $t['data']['taxonomy_key'] : '';
			$t_info               = self::key_conflict_info( 'taxonomy', $t_key );
			$plan['taxonomies'][] = array(
				'key'               => $t_key,
				'label'             => isset( $t['data']['label'] ) ? $t['data']['label'] : '',
				'conflict'          => $t_info['reason'],
				'suggested_key'     => $t_info['suggested_key'],
				'source_registered' => self::registered_by_source( $source, 'taxonomy', $t_key, (int) $t['source_id'] ),
				'warnings'          => $t_warnings,
			);
			$plan['warnings'] = array_merge( $plan['warnings'], $t_warnings );
		}
		foreach ( $post_types as $p ) {
			$p_warnings           = isset( $p['warnings'] ) ? (array) $p['warnings'] : array();
			$p_key                = isset( $p['data']['post_type_key'] ) ? $p['data']['post_type_key'] : '';
			$p_info               = self::key_conflict_info( 'cpt', $p_key );
			$plan['post_types'][] = array(
				'key'               => $p_key,
				'label'             => isset( $p['data']['label'] ) ? $p['data']['label'] : '',
				'conflict'          => $p_info['reason'],
				'suggested_key'     => $p_info['suggested_key'],
				'source_registered' => self::registered_by_source( $source, 'cpt', $p_key, (int) $p['source_id'] ),
				'warnings'          => $p_warnings,
			);
			$plan['warnings'] = array_merge( $plan['warnings'], $p_warnings );
		}

		foreach ( $groups as $g ) {
			if ( ! empty( $g['blocked'] ) ) {
				$plan['blocked'][] = array(
					'key'    => isset( $g['data']['key'] ) ? $g['data']['key'] : '',
					'title'  => isset( $g['data']['title'] ) ? $g['data']['title'] : '',
					'reason' => isset( $g['skipped'][0]['reason'] ) ? $g['skipped'][0]['reason'] : '',
				);
				continue;
			}
			$fields               = isset( $g['data']['fields'] ) ? $g['data']['fields'] : array();
			$objects              = self::count_objects( $source, $g );
			$plan['value_count'] += $objects * max( 1, count( $fields ) );
			$plan['groups'][]     = array(
				'key'          => isset( $g['data']['key'] ) ? $g['data']['key'] : '',
				'title'        => isset( $g['data']['title'] ) ? $g['data']['title'] : '',
				'field_count'  => count( $fields ),
				'object_count' => $objects,
				'skipped'      => isset( $g['skipped'] ) ? $g['skipped'] : array(),
				'warnings'     => isset( $g['warnings'] ) ? $g['warnings'] : array(),
			);
			$plan['warnings']     = array_merge( $plan['warnings'], isset( $g['warnings'] ) ? $g['warnings'] : array() );
		}

		// A key the source still registers would be registered twice, so the run switches the source off.
		$plan['disable_source_required'] = false;
		$still                           = array();
		foreach ( array( 'post_types' => 'cpt', 'taxonomies' => 'taxonomy' ) as $bucket => $kind ) {
			foreach ( $plan[ $bucket ] as $item ) {
				if ( ! empty( $item['source_registered'] ) ) {
					$still[] = array(
						'kind' => $kind,
						'key'  => $item['key'],
					);
				}
			}
		}
		if ( $still ) {
			$plan['disable_source_required'] = true;
			$plan['warnings'][]              = self::forced_disable_notice( $still, true );
		}

		// Source-level notes last: they explain the limits of the adapter itself, and must show even
		// when the plan is otherwise empty.
		$plan['warnings'] = array_merge( $plan['warnings'], (array) $source->scan_notes() );
		$plan['warnings'] = array_values( array_unique( array_filter( $plan['warnings'] ) ) );
		return $plan;
	}

	/**
	 * PHASES 1-6 — run the import.
	 *
	 * @param string $slug Source slug.
	 * @param array  $args Import options: 'disable_source' (bool) disables the source's definitions
	 *                     afterwards, 'import_values' (bool) copies values as well as definitions.
	 * @return array|WP_Error
	 */
	public static function run( $slug, $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'uich_import_forbidden', __( 'You do not have permission to do this.', 'uichemy' ), array( 'status' => 403 ) );
		}
		$source = self::source( $slug );
		if ( ! $source || ! $source->is_available() ) {
			return new WP_Error( 'uich_import_unavailable', __( 'This source has nothing to import on this site.', 'uichemy' ), array( 'status' => 400 ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'disable_source' => false,
				'import_values'  => true,
				// { cpt: {old:new}, taxonomy: {old:new} } — the dry run's suggested keys, accepted by
				// the user. Applied to the definition AND to every reference to it.
				'key_map'        => array(),
			)
		);
		$key_map = self::normalize_key_map( $args['key_map'] );

		// A key the source still registers cannot also be registered by the import, so the source is
		// switched off whatever was asked for. start_job() makes the same call for its own run.
		$still = empty( $args['defer_disable'] ) ? self::still_registered( $source, $key_map ) : array();

		$report = array(
			'source'     => $slug,
			'taxonomies' => array(
				'created' => 0,
				'failed'  => 0,
			),
			'post_types' => array(
				'created' => 0,
				'failed'  => 0,
			),
			'groups'     => array(
				'created' => 0,
				'failed'  => 0,
			),
			'values'     => array(
				'written'   => 0,
				'skipped'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
			'disabled'   => 0,
			'errors'     => array(),
			'warnings'   => array(),
			'migrated'   => array(),
			// Per-item reasons. The counters alone cannot say WHICH definition was dropped or why,
			// which is what left users guessing after a partial import.
			'skipped'    => array(),
			'renamed'    => array(),
		);

		// Definitions that could not be created, so a dependent group can say WHY it is incomplete.
		$failed_keys = array(
			'cpt'      => array(),
			'taxonomy' => array(),
		);

		// The two kinds validate references differently: a post type listing an unknown taxonomy drops the
		// association silently, while a taxonomy listing an unknown post type errors.

		// The source plugin is still active — the migration copies rather than moves — so the keys it registers
		// are exactly the keys being imported.
		$source_pt_keys  = array();
		$source_tax_keys = array();
		foreach ( $source->read_post_types() as $p ) {
			if ( ! empty( $p['data']['post_type_key'] ) ) {
				$source_pt_keys[] = $p['data']['post_type_key'];
			}
		}
		foreach ( $source->read_taxonomies() as $t ) {
			if ( ! empty( $t['data']['taxonomy_key'] ) ) {
				$source_tax_keys[] = $t['data']['taxonomy_key'];
			}
		}
		// Claimed before the first write, not after: everything below reads the import map, decides
		// what already exists and writes it back, and two runs interleaving there lose one side's
		// mapping — which is what turns a retry into a duplicate definition.
		$lock = self::acquire_lock( $slug );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( $still && ! $args['disable_source'] ) {
			$args['disable_source'] = true;
			$report['warnings'][]   = self::forced_disable_notice( $still );
		}

		Uich_Fields_Admin_Logic::begin_source_import( $slug, 'cpt', $source_pt_keys );
		Uich_Fields_Admin_Logic::begin_source_import( $slug, 'taxonomy', $source_tax_keys );

		try {

			// PHASE 1 — post types.
			$created_post_types = array();
			foreach ( $source->read_post_types() as $p ) {
				$src_key    = isset( $p['data']['post_type_key'] ) ? $p['data']['post_type_key'] : '';
				$p['data']  = self::apply_key_map( $p['data'], 'cpt', $key_map );
				$final_key  = isset( $p['data']['post_type_key'] ) ? $p['data']['post_type_key'] : '';
				if ( $final_key !== $src_key ) {
					$report['renamed'][] = array(
						'kind' => 'cpt',
						'from' => $src_key,
						'to'   => $final_key,
					);
				}
				// Update what this source already produced rather than trying to create it again —
				// see target_for_item(). Re-running a migration is an upsert, not a second import.
				$target = self::target_for_item( $slug, (int) $p['source_id'], 'cpt', $final_key );
				$res    = Uich_Fields_Admin_Logic::save_cpt( $target, $p['data'], true );
				if ( is_wp_error( $res ) ) {
					++$report['post_types']['failed'];
					$report['errors'][] = sprintf( 'post type %s: %s', '' !== $final_key ? $final_key : '?', $res->get_error_message() );
					$failed_keys['cpt'][ $src_key ] = $res->get_error_message();
					$report['skipped'][]            = array(
						'kind'          => 'cpt',
						'key'           => $src_key,
						'label'         => isset( $p['data']['label'] ) ? $p['data']['label'] : '',
						'reason'        => $res->get_error_message(),
						'suggested_key' => Uich_Fields_Admin_Logic::suggest_available_key( 'cpt', $src_key ),
					);
					continue;
				}
				++$report['post_types']['created'];
				self::link( (int) $res['id'], $slug, (int) $p['source_id'] );
				$report['migrated'][] = (int) $p['source_id'];
				if ( ! empty( $res['warnings'] ) ) {
					$report['warnings'] = array_merge( $report['warnings'], (array) $res['warnings'] );
				}
				// Adapter warnings too: these name the advanced settings that could not be carried.
				if ( ! empty( $p['warnings'] ) ) {
					$report['warnings'] = array_merge( $report['warnings'], (array) $p['warnings'] );
				}
				if ( ! empty( $p['data']['taxonomies'] ) ) {
					$created_post_types[] = array(
						'id'   => (int) $res['id'],
						'data' => $p['data'],
					);
				}
			}

			// PHASE 2 — taxonomies.
			foreach ( $source->read_taxonomies() as $t ) {
				$src_key   = isset( $t['data']['taxonomy_key'] ) ? $t['data']['taxonomy_key'] : '';
				$t['data'] = self::apply_key_map( $t['data'], 'taxonomy', $key_map );
				$final_key = isset( $t['data']['taxonomy_key'] ) ? $t['data']['taxonomy_key'] : '';
				if ( $final_key !== $src_key ) {
					$report['renamed'][] = array(
						'kind' => 'taxonomy',
						'from' => $src_key,
						'to'   => $final_key,
					);
				}
				$target = self::target_for_item( $slug, (int) $t['source_id'], 'tax', $final_key );
				$res    = Uich_Fields_Admin_Logic::save_taxonomy( $target, $t['data'], true );
				if ( is_wp_error( $res ) ) {
					++$report['taxonomies']['failed'];
					$report['errors'][] = sprintf( 'taxonomy %s: %s', '' !== $final_key ? $final_key : '?', $res->get_error_message() );
					$failed_keys['taxonomy'][ $src_key ] = $res->get_error_message();
					$report['skipped'][]                 = array(
						'kind'          => 'taxonomy',
						'key'           => $src_key,
						'label'         => isset( $t['data']['label'] ) ? $t['data']['label'] : '',
						'reason'        => $res->get_error_message(),
						'suggested_key' => Uich_Fields_Admin_Logic::suggest_available_key( 'taxonomy', $src_key ),
					);
					continue;
				}
				++$report['taxonomies']['created'];
				self::link( (int) $res['id'], $slug, (int) $t['source_id'] );
				$report['migrated'][] = (int) $t['source_id'];
				if ( ! empty( $res['warnings'] ) ) {
					$report['warnings'] = array_merge( $report['warnings'], (array) $res['warnings'] );
				}
				if ( ! empty( $t['warnings'] ) ) {
					$report['warnings'] = array_merge( $report['warnings'], (array) $t['warnings'] );
				}
			}

			// PHASE 2b — reinstate the post type associations that phase 1 could not keep. Only post
			// types that actually declared taxonomies are touched, so this is a no-op for most imports.
			foreach ( $created_post_types as $pt ) {
				$again = Uich_Fields_Admin_Logic::save_cpt( $pt['id'], $pt['data'], true );
				if ( is_wp_error( $again ) ) {
					$report['warnings'][] = sprintf(
						/* translators: 1: post type key, 2: error message */
						__( 'Taxonomy associations for "%1$s" could not be restored: %2$s', 'uichemy' ),
						isset( $pt['data']['post_type_key'] ) ? $pt['data']['post_type_key'] : '?',
						$again->get_error_message()
					);
				}
			}

			// PHASE 3 — field groups, through their own REST controller.
			$groups = $source->read_groups();
			foreach ( $groups as $g ) {
				$g_key = isset( $g['data']['key'] ) ? $g['data']['key'] : '';
				if ( ! empty( $g['blocked'] ) ) {
					$reason               = isset( $g['skipped'][0]['reason'] ) ? $g['skipped'][0]['reason'] : '';
					$report['warnings'][] = $reason;
					$report['skipped'][]  = array(
						'kind'          => 'group_fields',
						'key'           => $g_key,
						'label'         => isset( $g['data']['title'] ) ? $g['data']['title'] : '',
						'reason'        => $reason,
						'suggested_key' => '',
					);
					continue;
				}

				// Follow the renames applied in phases 1-2, or the group's location rules would still
				// point at the source key — which for a core clash resolves to the CORE object.
				$g['data'] = self::apply_key_map( $g['data'], 'group_fields', $key_map );

				// A group whose target definition failed still saves, but it would silently attach to
				// whatever else owns that key. Say so instead of leaving the user to work it out.
				$deps = self::location_dependencies( isset( $g['data']['location_rules'] ) ? $g['data']['location_rules'] : array() );
				foreach ( array( 'cpt', 'taxonomy' ) as $dep_kind ) {
					foreach ( $deps[ $dep_kind ] as $dep_key ) {
						if ( ! isset( $failed_keys[ $dep_kind ][ $dep_key ] ) ) {
							continue;
						}
						$report['warnings'][] = sprintf(
							/* translators: 1: field group title, 2: dependency key, 3: the dependency's own error */
							__( 'Field group "%1$s" was imported but its location still targets "%2$s", which could not be created: %3$s', 'uichemy' ),
							isset( $g['data']['title'] ) ? $g['data']['title'] : $g_key,
							$dep_key,
							$failed_keys[ $dep_kind ][ $dep_key ]
						);
					}
				}

				// Re-running an import must UPDATE what it created last time, not add a second copy.
				$existing = self::target_for_item( $slug, (int) $g['source_id'], 'group_fields', $g_key );
				if ( $existing ) {
					// Never downgrade a group that is already active: the source reads as disabled
					// after our own "disable source" run, and copying that would switch the group off.
					$current = Uich_Fields_Model_Writer::definition( $existing );
					if ( is_array( $current ) && ( ! isset( $current['active'] ) || $current['active'] ) ) {
						$g['data']['active'] = true;
					}
				}
				$id = $existing ? self::update_group( $existing, $g['data'] ) : self::create_group( $g['data'] );
				if ( is_wp_error( $id ) ) {
					++$report['groups']['failed'];
					$report['errors'][] = sprintf( 'group %s: %s', '' !== $g_key ? $g_key : '?', $id->get_error_message() );
					$report['skipped'][] = array(
						'kind'          => 'group_fields',
						'key'           => $g_key,
						'label'         => isset( $g['data']['title'] ) ? $g['data']['title'] : '',
						'reason'        => $id->get_error_message(),
						'suggested_key' => '',
					);
					continue;
				}
				++$report['groups']['created'];
				self::link( $id, $slug, (int) $g['source_id'] );
				$report['migrated'][] = (int) $g['source_id'];

				// PHASE 4 — values for this group.
				if ( $args['import_values'] ) {
					$v                              = self::import_values( $source, $g );
					$report['values']['written']   += $v['written'];
					$report['values']['skipped']   += $v['skipped'];
					$report['values']['unchanged'] += $v['unchanged'];
					$report['values']['failed']    += $v['failed'];
					$report['errors']               = array_merge( $report['errors'], $v['errors'] );
				}
			}

			// PHASE 5 — disable the source, never delete: rollback depends on it.
			if ( $args['disable_source'] && ! $report['errors'] ) {
				$report['disabled'] = self::disable_tracked( $source, $report['migrated'] );
			} elseif ( $args['disable_source'] ) {
				$report['warnings'][] = __( 'The source was left active because some items failed to import.', 'uichemy' );
				// The source keeps those keys, so the imported definitions never registered: say so as an error.
				$report['errors'] = array_merge( $report['errors'], self::not_registered_errors( $still ) );
			}

			$report['warnings'] = array_values( array_filter( array_unique( $report['warnings'] ) ) );
			return $report;

		} finally {
			// Unconditionally.
			Uich_Fields_Admin_Logic::end_source_import();
			self::release_lock( $slug, $lock );
		}
	}

	/**
	 * PHASE 4 — walk every object the group applies to and copy its values.
	 *
	 * @param Uich_Fields_Import_Source $source   Source adapter.
	 * @param array                           $group    Group descriptor from read_groups().
	 * @return array{written:int,skipped:int,unchanged:int,failed:int,errors:string[]}
	 */
	private static function import_values( $source, $group ) {
		$out    = array(
			'written'   => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'errors'    => array(),
		);
		$fields = isset( $group['data']['fields'] ) ? $group['data']['fields'] : array();
		if ( ! $fields ) {
			return $out;
		}

		// Same per-object copy the stepped job uses, so the two paths cannot drift apart.
		foreach ( self::objects_for( $source, $group ) as $object_type => $ids ) {
			foreach ( $ids as $object_id ) {
				$one                  = self::copy_object_values( $source, $fields, $object_type, (int) $object_id );
				$out['written']      += $one['written'];
				$out['skipped']      += $one['skipped'];
				$out['unchanged']    += $one['unchanged'];
				$out['failed']       += $one['failed'];
				if ( $one['errors'] && count( $out['errors'] ) < 20 ) {
					$out['errors'] = array_slice( array_merge( $out['errors'], $one['errors'] ), 0, 20 );
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the stored value already matches what the import wanted to write.
	 *
	 * @param mixed $stored Value currently stored.
	 * @param mixed $wanted Value the import tried to write.
	 * @return bool
	 */
	private static function same_value( $stored, $wanted ) {
		return wp_json_encode( self::normalise_for_compare( $stored ) ) === wp_json_encode( self::normalise_for_compare( $wanted ) );
	}

	/**
	 * Reduce a value to the shape the database hands back, so 5 and "5" (or true and "1") compare equal.
	 *
	 * @param mixed $value Any stored or incoming value.
	 * @return mixed
	 */
	private static function normalise_for_compare( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = self::normalise_for_compare( $v );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( null === $value ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * Marker after the slug that tells a restorable backup from the old kind, which held the NEW value.
	 */
	const BAK_V2 = 'v2__';

	/**
	 * Backup meta key for one value row. Starts with BAK_PREFIX . slug so cleanup_backups() still finds it.
	 *
	 * @param string $slug        Source slug.
	 * @param string $object_type post|term|user|comment.
	 * @param string $meta_key    The UiChemy value meta key the import overwrites.
	 * @return string
	 */
	private static function backup_key( $slug, $object_type, $meta_key ) {
		$prefix = 'user' === $object_type ? self::user_backup_prefix( $slug ) : self::BAK_PREFIX . $slug . '_';
		// A long key is hashed to stay inside the 255-character meta_key column; the real key travels in the payload.
		$tail = strlen( $meta_key ) > 150 ? 'h' . md5( $meta_key ) : $meta_key;
		return $prefix . self::BAK_V2 . $tail;
	}

	/**
	 * Where a write to this field would land: the same resolution uich_save_field_value() performs.
	 *
	 * @param array  $field       Field descriptor.
	 * @param string $object_type post|term|user|comment.
	 * @param int    $object_id   Object ID.
	 * @return array|null { type, id, meta_key, info } or null when no active group takes the field.
	 */
	private static function value_target( $field, $object_type, $object_id ) {
		$context = 'post' === $object_type ? (int) $object_id : $object_type . '_' . (int) $object_id;
		$info    = uich_maybe_get_field_info( (string) $field['name'], $context );
		if ( ! $info ) {
			return null;
		}
		return array(
			'type'     => $info['object_type'],
			'id'       => (int) $info['object_id'],
			'meta_key' => uich_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] ),
			'info'     => $info,
		);
	}

	/**
	 * Store what is CURRENTLY in the value row (or that there was none) so a rollback can put it back.
	 *
	 * add-unique keeps the FIRST backup: a re-run must never replace the pre-import state with a value
	 * this import wrote.
	 *
	 * @param string $slug   Source slug.
	 * @param array  $target Result of value_target().
	 * @return void
	 */
	private static function backup( $slug, $target ) {
		$type = $target['type'];
		$id   = $target['id'];
		$key  = self::backup_key( $slug, $type, $target['meta_key'] );
		if ( ! $id || metadata_exists( $type, $id, $key ) ) {
			return;
		}
		$exists  = metadata_exists( $type, $id, $target['meta_key'] );
		$payload = array(
			'key'    => $target['meta_key'],
			'exists' => $exists,
			'value'  => $exists ? get_metadata( $type, $id, $target['meta_key'], true ) : null,
		);
		// Slashed because add_metadata() unslashes: without it a backslash in a value would not survive the round trip.
		add_metadata( $type, $id, $key, wp_slash( $payload ), true );
	}

	/**
	 * Put every imported value back to what it was before the import, then drop the backups.
	 *
	 * Only backups carrying the BAK_V2 marker are restorable; the older kind held the new value and is ignored.
	 *
	 * @param string $slug Source slug.
	 * @return array{restored:int, removed:int}
	 */
	private static function restore_values( $slug ) {
		global $wpdb;
		$out    = array(
			'restored' => 0,
			'removed'  => 0,
		);
		$tables = array(
			'post'    => array( $wpdb->postmeta, 'post_id' ),
			'term'    => array( $wpdb->termmeta, 'term_id' ),
			'user'    => array( $wpdb->usermeta, 'user_id' ),
			'comment' => array( $wpdb->commentmeta, 'comment_id' ),
		);
		foreach ( $tables as $type => $t ) {
			$prefix = 'user' === $type ? self::user_backup_prefix( $slug ) : self::BAK_PREFIX . $slug . '_';
			$like   = $wpdb->esc_like( $prefix . self::BAK_V2 ) . '%';
			$id_col = 'user' === $type ? 'umeta_id' : 'meta_id';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the backup rows only exist in these tables; the table and column names are fixed above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_col} AS mid, {$t[1]} AS oid, meta_value FROM {$t[0]} WHERE meta_key LIKE %s", $like ) );
			foreach ( (array) $rows as $row ) {
				$payload = self::decode_backup( $row->meta_value );
				// Only a UiChemy value key may be written or deleted from here, whatever a payload claims.
				if ( is_array( $payload ) && isset( $payload['key'] ) && is_string( $payload['key'] ) && 0 === strpos( $payload['key'], '_uichemy_gf_' ) ) {
					if ( ! empty( $payload['exists'] ) ) {
						update_metadata( $type, (int) $row->oid, $payload['key'], wp_slash( isset( $payload['value'] ) ? $payload['value'] : '' ) );
						++$out['restored'];
					} else {
						delete_metadata( $type, (int) $row->oid, $payload['key'] );
						++$out['removed'];
					}
				}
				delete_metadata_by_mid( $type, (int) $row->mid );
			}
		}
		return $out;
	}

	/**
	 * A backup payload as stored, decoded without instantiating objects.
	 *
	 * @param string $raw meta_value column.
	 * @return mixed
	 */
	private static function decode_backup( $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes false: a plain array decode of our own payload.
		return @unserialize( trim( $raw ), array( 'allowed_classes' => false ) );
	}

	/**
	 * Backup key prefix for USER meta.
	 *
	 * Post, term and comment meta live in per-site tables, but on multisite usermeta is shared by
	 * the whole network: an unscoped key let one site's cleanup delete another site's backups.
	 * Single site keeps the original key.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function user_backup_prefix( $slug ) {
		if ( is_multisite() ) {
			return self::BAK_PREFIX . 'b' . (int) get_current_blog_id() . '_' . $slug . '_';
		}
		return self::BAK_PREFIX . $slug . '_';
	}

	/**
	 * Remove this importer's backup rows once the result has been accepted.
	 *
	 * @param string $slug Source slug.
	 * @return int Rows removed.
	 */
	public static function cleanup_backups( $slug ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}
		$like = $wpdb->esc_like( self::BAK_PREFIX . $slug . '_' ) . '%';
		$n    = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $like ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::user_backup_prefix( $slug ) ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads third-party definition posts, for which no WordPress API exists; an import scan must see current data, so caching it would report a stale plan.
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s", $like ) );
		return $n;
	}

	/**
	 * Undo an import: delete the Uichemy definitions it created and re-publish the source's.
	 *
	 * @param string $slug Source slug.
	 * @return array|WP_Error
	 */
	public static function rollback( $slug ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'uich_import_forbidden', __( 'You do not have permission to do this.', 'uichemy' ), array( 'status' => 403 ) );
		}
		// Same lock as run(): rollback deletes definitions and rewrites the map, so it must not
		// interleave with an import that is deciding what already exists.
		$lock = self::acquire_lock( $slug );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Values first, while the definitions still exist: put every imported value back to its
		// pre-import state (or remove it), then drop the backups so the database is where it started.
		$values = self::restore_values( $slug );
		self::cleanup_backups( $slug );

		// Driven by the import map, not by a meta query: the definitions this created are files, and the query
		// that used to find them returns nothing now.
		$mapped   = self::mapped_for_source( $slug );
		$disabled = self::recorded_disabled( $slug );
		$removed  = 0;
		$errors   = array();
		foreach ( $mapped as $row ) {
			if ( $row['source_id'] && self::source_uses_post_ids( $slug ) ) {
				delete_post_meta( $row['source_id'], self::META_TO );
				// Only what THIS import switched off: a definition the user had disabled stays disabled.
				$post = get_post( $row['source_id'] );
				if ( $post && in_array( (int) $row['source_id'], $disabled, true ) && in_array( $post->post_status, array( 'acf-disabled', 'draft' ), true ) ) {
					wp_update_post(
						array(
							'ID'          => $row['source_id'],
							'post_status' => 'publish',
						)
					);
				}
			}
			$deleted = Uich_Fields_Admin_Logic::delete_definition_permanently( $row['id'] );
			if ( is_wp_error( $deleted ) ) {
				$errors[] = $deleted->get_error_message();
			} else {
				++$removed;
			}
		}

		// Put back whatever disable_source switched off. Post-based sources did this above by
		// re-publishing their definition posts; sources backed by their own tables cannot, and
		// without this a rollback would leave the site with neither plugin's definitions active.
		$source = self::source( $slug );
		if ( $source && $disabled ) {
			$source->enable_definitions( $disabled );
		}
		delete_option( self::disabled_key( $slug ) );

		// Forget what was just undone, so a later run treats the source as un-imported rather than
		// pointing at definitions that no longer exist.
		$map = self::read_map();
		unset( $map[ $slug ] );
		self::write_map( $map );

		if ( class_exists( 'Uich_Fields_Definitions_Repository' ) ) {
			Uich_Fields_Definitions_Repository::get_instance()->flush();
		}
		if ( class_exists( 'Uich_Fields_Model_Cache' ) ) {
			Uich_Fields_Model_Cache::flush();
		}

		self::release_lock( $slug, $lock );

		$reverted = $values['restored'] + $values['removed'];
		$message  = sprintf(
			/* translators: %d: number of definitions removed */
			_n( '%d imported definition removed and the original re-enabled.', '%d imported definitions removed and the originals re-enabled.', $removed, 'uichemy' ),
			$removed
		);
		if ( $reverted ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of field values put back to their pre-import state */
				_n( '%d imported value was reverted.', '%d imported values were reverted.', $reverted, 'uichemy' ),
				$reverted
			);
		}

		return array(
			'removed' => $removed,
			'values'  => $values,
			'errors'  => $errors,
			'message' => $message,
		);
	}

	/**
	 * Option holding the source definition ids this importer actually switched off, per source.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function disabled_key( $slug ) {
		return 'uich_import_disabled_' . sanitize_key( $slug );
	}

	/**
	 * Source definition ids a previous run switched off (and so a rollback may switch back on).
	 *
	 * @param string $slug Source slug.
	 * @return int[]
	 */
	private static function recorded_disabled( $slug ) {
		$ids = get_option( self::disabled_key( $slug ), array() );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * Disable the source's definitions and remember which ones this call really changed.
	 *
	 * Rollback re-enables only those: re-publishing every mapped definition would also switch on
	 * one the user had deliberately disabled before the migration.
	 *
	 * @param Uich_Fields_Import_Source $source Source adapter.
	 * @param array                     $ids    Source definition ids that were migrated.
	 * @return int Number disabled.
	 */
	private static function disable_tracked( $source, $ids ) {
		$ids    = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$posts  = $source->definitions_are_posts();
		$before = array();
		if ( $posts ) {
			foreach ( $ids as $id ) {
				$before[ $id ] = get_post_status( $id );
			}
		}
		$n = $source->disable_definitions( $ids );

		$changed = array();
		foreach ( $ids as $id ) {
			// A table-backed source cannot be diffed from here, so every id it was asked to disable counts.
			if ( ! $posts || ( isset( $before[ $id ] ) && get_post_status( $id ) !== $before[ $id ] ) ) {
				$changed[] = $id;
			}
		}
		if ( $changed ) {
			$slug = $source->slug();
			update_option( self::disabled_key( $slug ), array_values( array_unique( array_merge( self::recorded_disabled( $slug ), $changed ) ) ), false );
		}
		return $n;
	}

	// ----------------------------------------------------------------- Internals
	// -----------------------------------------------------------------.

	/**
	 * Create a field group through its REST controller.
	 *
	 * @param array $data Group data.
	 * @return int|WP_Error New definition post ID.
	 */
	/**
	 * The Uichemy definition a previous run created from this source definition, if it still exists.
	 *
	 * @param string $slug      Source slug.
	 * @param int    $source_id Source definition post ID.
	 * @param string $kind      cpt | tax | group_fields. Required whenever one source post can stand
	 *                          for more than one definition, which is how Pods works.
	 * @return int 0 when there is none.
	 */
	private static function existing_for_source( $slug, $source_id, $kind = '' ) {
		$source_id = (int) $source_id;
		if ( ! $source_id ) {
			return 0;
		}
		foreach ( self::mapped_for_source( $slug ) as $row ) {
			if ( $row['source_id'] !== $source_id ) {
				continue;
			}
			// A source post can stand for more than one definition, so the kind has to match too.
			if ( '' !== $kind && $row['kind'] !== $kind ) {
				continue;
			}
			return $row['id'];
		}
		return 0;
	}

	/**
	 * The definition a source item should be written to: its recorded provenance, or failing that, whatever
	 * already holds its key.
	 *
	 * @param string $slug      Source slug.
	 * @param int    $source_id Source record id.
	 * @param string $kind      Model kind: cpt | tax | group_fields.
	 * @param string $key       Definition key from the source.
	 * @return int Definition id to update, or 0 to create.
	 */
	private static function target_for_item( $slug, $source_id, $kind, $key ) {
		$mapped = self::existing_for_source( $slug, $source_id, $kind );
		if ( $mapped ) {
			return $mapped;
		}
		$key = (string) $key;
		if ( '' === $key ) {
			return 0;
		}

		// BUG-006: a key match alone cannot tell "the definition my last import wrote" from "the one
		// the user built themselves", and adopting the latter overwrites their fields in place and
		// strands their values. Only a definition this importer recorded provenance for may be reused.
		if ( ! self::key_is_import_owned( $kind, $key ) ) {
			return 0;
		}

		// The FILE decides, not the id.
		$file = Uich_Fields_Model_Paths::file_for_key( $kind, $key );
		if ( is_wp_error( $file ) || ! file_exists( $file ) ) {
			return 0;
		}

		return (int) Uich_Fields_Model_Writer::id_for( $kind, $key );
	}

	/**
	 * Whether a previous import created the definition holding this kind + key.
	 *
	 * Provenance lives in the import map written by self::link(). Anything absent from it is
	 * user-authored as far as import is concerned.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @param string $key  Definition key from the source.
	 * @return bool
	 */
	private static function key_is_import_owned( $kind, $key ) {
		$key = (string) $key;
		foreach ( self::read_map() as $by_kind ) {
			if ( ! is_array( $by_kind ) || empty( $by_kind[ $kind ] ) || ! is_array( $by_kind[ $kind ] ) ) {
				continue;
			}
			foreach ( $by_kind[ $kind ] as $row ) {
				if ( is_array( $row ) && isset( $row['key'] ) && (string) $row['key'] === $key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Update an existing field group through its REST controller.
	 *
	 * @param int   $id   Definition post ID.
	 * @param array $data Group data.
	 * @return int|WP_Error
	 */
	private static function update_group( $id, $data ) {
		$request = new WP_REST_Request( 'PUT', '/' . UICH_FIELDS_REST_NS . '/field-groups/' . (int) $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'data' => $data ) ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$err = $response->as_error();
			return is_wp_error( $err ) ? $err : new WP_Error( 'uich_import_group_failed', __( 'The field group could not be updated.', 'uichemy' ) );
		}
		return (int) $id;
	}

	/**
	 * Create a field group through its REST controller.
	 *
	 * @param array $data Group data.
	 * @return int|WP_Error New definition post ID.
	 */
	private static function create_group( $data ) {
		$request = new WP_REST_Request( 'POST', '/' . UICH_FIELDS_REST_NS . '/field-groups' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'data' => $data ) ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$err = $response->as_error();
			return is_wp_error( $err ) ? $err : new WP_Error( 'uich_import_group_failed', __( 'The field group could not be created.', 'uichemy' ) );
		}
		$body = $response->get_data();
		if ( empty( $body['id'] ) ) {
			return new WP_Error( 'uich_import_group_failed', __( 'The field group was not returned by the API.', 'uichemy' ) );
		}
		return (int) $body['id'];
	}

	/**
	 * Sidecar recording which source definition each imported one came from.
	 */
	const MAP_FILE = '.import-map.json';

	/**
	 * The whole map: slug => kind => source_id => { kind, key }.
	 *
	 * @return array
	 */
	private static function read_map() {
		$map = Uich_Fields_Model_Store::read_sidecar( self::MAP_FILE );
		unset( $map['$schema_version'] );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Persist the map.
	 *
	 * @param array $map Map to write.
	 * @return void
	 */
	private static function write_map( $map ) {
		Uich_Fields_Model_Store::write_sidecar( self::MAP_FILE, $map );
	}

	/**
	 * Every definition imported from one source, as { kind, key, id } rows that still exist.
	 *
	 * @param string $slug Source slug.
	 * @return array<int, array{source_id:int, kind:string, key:string, id:int}>
	 */
	private static function mapped_for_source( $slug ) {
		$map = self::read_map();
		$out = array();
		if ( empty( $map[ $slug ] ) || ! is_array( $map[ $slug ] ) ) {
			return $out;
		}
		$flat = array();
		foreach ( $map[ $slug ] as $kind => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $source_id => $entry ) {
				$flat[] = array( (int) $source_id, $entry );
			}
		}
		foreach ( $flat as $pair ) {
			list( $source_id, $entry ) = $pair;
			if ( ! is_array( $entry ) || empty( $entry['kind'] ) || empty( $entry['key'] ) ) {
				continue;
			}
			$id = Uich_Fields_Model_Writer::id_for( $entry['kind'], $entry['key'] );
			if ( ! $id ) {
				continue;
			}
			$file = Uich_Fields_Model_Paths::file_for_key( $entry['kind'], $entry['key'] );
			if ( is_wp_error( $file ) || ! file_exists( $file ) ) {
				continue;
			}
			$out[] = array(
				'source_id' => (int) $source_id,
				'kind'      => (string) $entry['kind'],
				'key'       => (string) $entry['key'],
				'id'        => (int) $id,
			);
		}
		return $out;
	}

	/**
	 * Record the two-way link between a source definition and the Uichemy one, so a re-run updates
	 * instead of duplicating and a rollback knows what to undo.
	 *
	 * @param int    $def_id     Uichemy definition post ID.
	 * @param string $slug      Source slug.
	 * @param int    $source_id Source definition post ID.
	 */
	private static function link( $def_id, $slug, $source_id ) {
		$found = Uich_Fields_Model_Writer::lookup( (int) $def_id );
		if ( $found ) {
			$map = self::read_map();
			$map[ $slug ][ $found['kind'] ][ (string) (int) $source_id ] = array(
				'kind' => $found['kind'],
				'key'  => $found['key'],
			);
			self::write_map( $map );
		}

		// The reverse link stays post meta: it lives on the SOURCE plugin's own post, and it is what
		// tells a re-scan that this definition has already been imported. Guarded on the source
		// actually using post ids — JetEngine numbers its rows from 1, so an unguarded write lands
		// on whatever post holds that id.
		if ( $source_id && self::source_uses_post_ids( $slug ) ) {
			update_post_meta( $source_id, self::META_TO, $def_id );
		}
	}

	// --- Batched run (job) ---
	// run() imports in one request, which stops working once the value count meets the PHP time limit.

	/** Option holding the in-progress job for a source. Not autoloaded. */
	const JOB_OPTION = 'uich_import_job_';

	/** Object types are walked in a fixed order so a cursor means the same thing on every request. */
	const JOB_OBJECT_TYPES = array( 'post', 'term', 'user', 'comment' );

	/** Objects per step when the caller does not say. Small enough to stay well inside any host's limit. */
	const JOB_DEFAULT_LIMIT = 25;

	/**
	 * Option name for a source's job.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function job_key( $slug ) {
		return self::JOB_OPTION . sanitize_key( $slug );
	}

	/**
	 * The job currently in progress for a source, if any.
	 *
	 * @param string $slug Source slug.
	 * @return array|null
	 */
	public static function job_status( $slug ) {
		$job = get_option( self::job_key( $slug ) );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Discard a job without undoing anything it already wrote.
	 *
	 * @param string $slug Source slug.
	 * @return bool
	 */
	public static function cancel_job( $slug ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return delete_option( self::job_key( $slug ) );
	}

	/**
	 * PHASE 1-3 as one short request, then hand back a job the client steps through.
	 *
	 * @param string $slug Source slug.
	 * @param array  $args Options; `disable_source` disables the source definitions once values finish.
	 * @return array|WP_Error Job state.
	 */
	public static function start_job( $slug, $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'uich_import_forbidden', __( 'You do not have permission to do this.', 'uichemy' ), array( 'status' => 403 ) );
		}
		$source = self::source( $slug );
		if ( ! $source || ! $source->is_available() ) {
			return new WP_Error( 'uich_import_unavailable', __( 'This source has nothing to import on this site.', 'uichemy' ), array( 'status' => 400 ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'disable_source' => false,
				'key_map'        => array(),
			)
		);

		// Same rule as run(): a key the source still registers forces its definitions off at the end.
		$still = self::still_registered( $source, self::normalize_key_map( $args['key_map'] ) );
		if ( $still ) {
			$args['disable_source'] = true;
		}

		// Definitions only. Values are what the stepping exists for.
		$report = self::run(
			$slug,
			array(
				'import_values'  => false,
				'disable_source' => false,
				'defer_disable'  => true,
				'key_map'        => $args['key_map'],
			)
		);
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( $still ) {
			$report['warnings'][] = self::forced_disable_notice( $still );
		}

		// run() released its own lock when the definition phase ended. The value phase spans many
		// requests, so the job carries the lock itself and every step re-proves ownership.
		$lock = self::acquire_lock( $slug );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Pair each imported group with the Uichemy definition it produced, and total up the work.
		$groups = array();
		$total  = 0;
		foreach ( $source->read_groups() as $g ) {
			if ( ! empty( $g['blocked'] ) ) {
				continue;
			}
			$uich_id = self::existing_for_source( $slug, (int) $g['source_id'] );
			if ( ! $uich_id ) {
				continue;
			}
			$rules   = isset( $g['data']['location_rules'] ) ? $g['data']['location_rules'] : array();
			$fields  = isset( $g['data']['fields'] ) ? $g['data']['fields'] : array();
			$counts  = array();
			$g_total = 0;
			foreach ( self::JOB_OBJECT_TYPES as $type ) {
				$n               = $source->objects_count( $rules, $type );
				$counts[ $type ] = $n;
				$g_total        += $n;
			}
			$total   += $g_total;
			$groups[] = array(
				'source_id'   => (int) $g['source_id'],
				'uich_fields_id'   => (int) $uich_id,
				'key'         => isset( $g['data']['key'] ) ? $g['data']['key'] : '',
				'title'       => isset( $g['data']['title'] ) ? $g['data']['title'] : '',
				'field_count' => count( $fields ),
				'counts'      => $counts,
			);
		}

		$job = array(
			'source'    => $slug,
			'label'     => $source->label(),
			'created'   => time(),
			'updated'   => time(),
			'args'      => array( 'disable_source' => (bool) $args['disable_source'] ),
			'groups'    => $groups,
			'cursor'    => array(
				'g'      => 0,
				't'      => 0,
				'offset' => 0,
			),
			'total'     => $total,
			'processed' => 0,
			'defs'      => array(
				'post_types' => $report['post_types'],
				'taxonomies' => $report['taxonomies'],
				'groups'     => $report['groups'],
			),
			'values'    => array(
				'written'   => 0,
				'skipped'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
			'migrated'  => $report['migrated'],
			// Kept so finish_job() can name them if it has to leave the source active.
			'still_registered' => $still,
			'errors'    => $report['errors'],
			'warnings'  => $report['warnings'],
			'disabled'  => 0,
			'finished'  => empty( $groups ) || 0 === $total,
			// Held for the life of the job; step_job() re-proves it and releases it at the end.
			'lock'      => $lock,
			// §34 checkpoints: definitions are done by the time a job exists, so a resume never
			// recreates a group — it picks the values phase back up at the cursor.
			'phases'    => array(
				'definitions' => 'completed',
				'values'      => 'pending',
				'finalise'    => 'pending',
			),
			// Failures only. Success is implied by the cursor, which keeps this bounded.
			'items'     => array(),
		);

		if ( $job['finished'] ) {
			$job = self::finish_job( $source, $job );
		}

		update_option( self::job_key( $slug ), $job, false );
		return $job;
	}

	/**
	 * Copy one page of values and advance the cursor.
	 *
	 * @param string $slug  Source slug.
	 * @param int    $limit Objects to process in this request.
	 * @return array|WP_Error Job state.
	 */
	public static function step_job( $slug, $limit = 0 ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'uich_import_forbidden', __( 'You do not have permission to do this.', 'uichemy' ), array( 'status' => 403 ) );
		}
		$job = self::job_status( $slug );
		if ( ! $job ) {
			return new WP_Error( 'uich_import_no_job', __( 'There is no migration in progress for this source.', 'uichemy' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $job['finished'] ) ) {
			return $job;
		}
		// Ownership, re-proved every request: two clients stepping the same job would each advance
		// the cursor, so half the objects would be skipped rather than written twice.
		if ( empty( $job['lock'] ) || ! self::touch_lock( $slug, $job['lock'] ) ) {
			return new WP_Error(
				'uich_import_locked',
				__( 'This migration is being run somewhere else. Open the migration screen to follow it rather than stepping it from here.', 'uichemy' ),
				array(
					'status'  => 409,
					'running' => true,
				)
			);
		}
		$source = self::source( $slug );
		if ( ! $source ) {
			return new WP_Error( 'uich_import_unknown_source', __( 'Unknown import source.', 'uichemy' ), array( 'status' => 400 ) );
		}

		$limit  = $limit > 0 ? (int) $limit : self::JOB_DEFAULT_LIMIT;
		$budget = $limit;
		// Kept separate: the retry drain below runs after $budget is spent, and still needs to know
		// how much work one request is allowed to do.
		$budget_start = $limit;
		// Copied to a local: indexing a class constant array inline is fine on 8.x but the plugin's
		// floor is PHP 7.4, and this loop is not the place to find out where that changed.
		$types = self::JOB_OBJECT_TYPES;

		// Group definitions are re-read per step rather than stored in the job: the option would carry every field of
		// every group, and stale field config would write mis-sanitized values.
		$defs = array();
		foreach ( $source->read_groups() as $g ) {
			if ( empty( $g['blocked'] ) ) {
				$defs[ (int) $g['source_id'] ] = $g;
			}
		}

		while ( $budget > 0 ) {
			$gi = (int) $job['cursor']['g'];
			if ( ! isset( $job['groups'][ $gi ] ) ) {
				$job['finished'] = true;
				break;
			}
			$row = $job['groups'][ $gi ];
			$ti  = (int) $job['cursor']['t'];
			if ( ! isset( $types[ $ti ] ) ) {
				// Group exhausted — next group.
				$job['cursor'] = array(
					'g'      => $gi + 1,
					't'      => 0,
					'offset' => 0,
				);
				continue;
			}
			$type = $types[ $ti ];
			$def  = isset( $defs[ $row['source_id'] ] ) ? $defs[ $row['source_id'] ] : null;

			if ( ! $def || empty( $row['counts'][ $type ] ) ) {
				// Nothing of this type for this group.
				$job['cursor']['t']      = $ti + 1;
				$job['cursor']['offset'] = 0;
				continue;
			}

			$rules  = isset( $def['data']['location_rules'] ) ? $def['data']['location_rules'] : array();
			$fields = isset( $def['data']['fields'] ) ? $def['data']['fields'] : array();
			$ids    = $source->objects_page( $rules, $type, (int) $job['cursor']['offset'], $budget );

			if ( ! $ids ) {
				$job['cursor']['t']      = $ti + 1;
				$job['cursor']['offset'] = 0;
				continue;
			}

			foreach ( $ids as $object_id ) {
				// Per object, so one failure is one recorded item rather than a lost batch.
				self::process_object( $source, $job, $row, $def, $type, (int) $object_id );
				++$job['processed'];
				--$budget;
			}
			$job['cursor']['offset'] = (int) $job['cursor']['offset'] + count( $ids );
		}

		if ( ! empty( $job['finished'] ) ) {
			// The cursor is spent, but items that failed on a transient error are still owed an
			// attempt. Draining them here is what makes retry automatic rather than a button.
			$due = self::due_failures( $job );
			if ( $due ) {
				$job['finished'] = false;
				$defs_by_id      = array();
				foreach ( $source->read_groups() as $g ) {
					if ( empty( $g['blocked'] ) ) {
						$defs_by_id[ (int) $g['source_id'] ] = $g;
					}
				}
				$rows = array();
				foreach ( (array) $job['groups'] as $r ) {
					$rows[ (int) $r['source_id'] ] = $r;
				}
				$n = 0;
				foreach ( $due as $key => $item ) {
					if ( $n >= $budget_start ) {
						break;
					}
					$gid = (int) $item['group'];
					if ( ! isset( $rows[ $gid ], $defs_by_id[ $gid ] ) ) {
						self::clear_failure( $job, $key );
						continue;
					}
					++$n;
					self::process_object( $source, $job, $rows[ $gid ], $defs_by_id[ $gid ], $item['object']['type'], (int) $item['object']['id'] );
				}
				// Nothing left that is due right now: the rest is either waiting out its backoff
				// or permanent, and neither should hold the job open.
				if ( ! self::due_failures( $job ) ) {
					$job['finished'] = true;
				}
			}
		}

		self::set_phase( $job, 'values', ! empty( $job['finished'] ) ? 'completed' : 'processing' );

		if ( ! empty( $job['finished'] ) ) {
			$job = self::finish_job( $source, $job );
		}

		$job['updated'] = time();
		update_option( self::job_key( $slug ), $job, false );
		return $job;
	}

	/**
	 * Last phase: disable the source, on the same terms the single-request run uses.
	 *
	 * @param Uich_Fields_Import_Source $source Source adapter.
	 * @param array                           $job    Job state.
	 * @return array
	 */
	private static function finish_job( $source, $job ) {
		if ( ! empty( $job['args']['disable_source'] ) ) {
			if ( empty( $job['errors'] ) ) {
				$job['disabled'] = self::disable_tracked( $source, $job['migrated'] );
			} else {
				$job['warnings'][] = __( 'The source was left active because some items failed to import.', 'uichemy' );
				if ( ! empty( $job['still_registered'] ) ) {
					$job['errors'] = array_merge( (array) $job['errors'], self::not_registered_errors( $job['still_registered'] ) );
				}
			}
		}
		$job['warnings'] = array_values( array_filter( array_unique( $job['warnings'] ) ) );
		$job['finished'] = true;

		// The only place a job ends, so the only place the lock has to come off. Left in the job
		// state as a spent token so job_status() can still say which run produced this result.
		if ( ! empty( $job['source'] ) && ! empty( $job['lock'] ) ) {
			self::release_lock( (string) $job['source'], (string) $job['lock'] );
		}

		return $job;
	}

	/**
	 * Copy every field value of one object. Same write path and unchanged/failed rules as the
	 * single-request importer — see import_values() for why a false return has to be re-read.
	 *
	 * @param Uich_Fields_Import_Source $source      Source adapter.
	 * @param array                           $fields      Group fields.
	 * @param string                          $object_type post|term|user|comment.
	 * @param int                             $object_id   Object ID.
	 * @return array{written:int,skipped:int,unchanged:int,failed:int,errors:string[]}
	 */
	private static function copy_object_values( $source, $fields, $object_type, $object_id ) {
		$out     = array(
			'written'   => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'errors'    => array(),
		);
		$context = 'post' === $object_type ? (int) $object_id : $object_type . '_' . (int) $object_id;

		foreach ( (array) $fields as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}
			$value = $source->read_value( $field, $object_type, (int) $object_id );
			if ( null === $value ) {
				++$out['skipped'];
				continue;
			}
			// Where the write will land. No target means no ACTIVE group takes this field, which is a
			// definition problem and says nothing about the value.
			$target = self::value_target( $field, $object_type, (int) $object_id );
			if ( ! $target ) {
				++$out['failed'];
				$out['errors'][] = sprintf(
					/* translators: 1: field name, 2: object type (post, term, user, comment), 3: object id, 4: reason */
					__( 'value %1$s on %2$s %3$d was not copied: %4$s', 'uichemy' ),
					$field['name'],
					$object_type,
					(int) $object_id,
					self::no_target_reason( (string) $field['name'] )
				);
				continue;
			}
			self::backup( $source->slug(), $target );

			$had  = metadata_exists( $target['type'], $target['id'], $target['meta_key'] );
			$prev = $had ? get_metadata( $target['type'], $target['id'], $target['meta_key'], true ) : null;

			// The one write path: routes through sanitize_value_for_field().
			$ok  = uich_save_field_value( $field['name'], $value, $context );
			$now = metadata_exists( $target['type'], $target['id'], $target['meta_key'] ) ? get_metadata( $target['type'], $target['id'], $target['meta_key'], true ) : null;

			if ( $ok ) {
				// update_metadata() compares strictly, so an int against the string the database returns
				// counts as a change; compare the stored form before and after instead.
				if ( $had && self::same_value( $prev, $now ) ) {
					++$out['unchanged'];
				} else {
					++$out['written'];
				}
				continue;
			}
			if ( $had && self::same_value( $now, $value ) ) {
				++$out['unchanged'];
				continue;
			}
			++$out['failed'];
			$out['errors'][] = sprintf(
				/* translators: 1: field name, 2: object type (post, term, user, comment), 3: object id */
				__( 'value %1$s on %2$s %3$d was not saved: the save was refused (field type unavailable on this plan, no permission, or a write error).', 'uichemy' ),
				$field['name'],
				$object_type,
				(int) $object_id
			);
		}

		return $out;
	}

	/**
	 * Why no field group takes a field: it exists but is switched off, or nothing defines it at all.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function no_target_reason( $name ) {
		$groups = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
		foreach ( $groups['group_fields'] as $row ) {
			$meta = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
			foreach ( isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? $meta['fields'] : array() as $f ) {
				if ( is_array( $f ) && isset( $f['name'] ) && (string) $f['name'] === $name && isset( $meta['active'] ) && ! $meta['active'] ) {
					return sprintf(
						/* translators: %s: field group title */
						__( 'the field group "%s" is inactive.', 'uichemy' ),
						! empty( $meta['title'] ) ? (string) $meta['title'] : ( isset( $row['title'] ) ? (string) $row['title'] : '' )
					);
				}
			}
		}
		return __( 'no active field group defines this field.', 'uichemy' );
	}

	/**
	 * Objects a group applies to.
	 *
	 * @param Uich_Fields_Import_Source $source Source adapter.
	 * @param array                           $group  Group descriptor.
	 * @return array<string, int[]>
	 */
	private static function objects_for( $source, $group ) {
		$rules  = isset( $group['data']['location_rules'] ) ? $group['data']['location_rules'] : array();
		$method = new ReflectionMethod( $source, 'objects_for_location' );
		$method->setAccessible( true );
		return $method->invoke( $source, $rules, 0 );
	}

	/**
	 * How many objects a group would touch (for the dry-run estimate).
	 *
	 * @param Uich_Fields_Import_Source $source Source adapter.
	 * @param array                           $group  Group descriptor.
	 * @return int
	 */
	private static function count_objects( $source, $group ) {
		$n = 0;
		foreach ( self::objects_for( $source, $group ) as $ids ) {
			$n += count( $ids );
		}
		return $n;
	}

	/**
	 * Whether a key is already taken, so the dry run can say so before anything is written.
	 *
	 * @param string $kind cpt|taxonomy.
	 * @param string $key  Key.
	 * @return string Empty when free, otherwise a human reason.
	 */
	/**
	 * Sanitize a caller-supplied key map into { cpt: {old:new}, taxonomy: {old:new} }.
	 *
	 * Only keys the save would accept survive, so a hand-crafted request cannot smuggle a reserved
	 * key past the dry run by supplying its own mapping.
	 *
	 * @param mixed $key_map Raw map from the request.
	 * @return array
	 */
	private static function normalize_key_map( $key_map ) {
		$out = array(
			'cpt'      => array(),
			'taxonomy' => array(),
		);
		if ( ! is_array( $key_map ) ) {
			return $out;
		}
		foreach ( array( 'cpt', 'taxonomy' ) as $kind ) {
			if ( empty( $key_map[ $kind ] ) || ! is_array( $key_map[ $kind ] ) ) {
				continue;
			}
			foreach ( $key_map[ $kind ] as $old => $new ) {
				$old = sanitize_key( $old );
				$new = sanitize_key( $new );
				if ( '' === $old || '' === $new || $old === $new ) {
					continue;
				}
				if ( '' !== Uich_Fields_Admin_Logic::reserved_key_reason( $new ) ) {
					continue;
				}
				$out[ $kind ][ $old ] = $new;
			}
		}
		return $out;
	}

	/**
	 * Apply a key map to one definition: its own key plus every reference it makes to another.
	 *
	 * Renaming the definition without rewriting the references is the trap — a taxonomy renamed to
	 * uich_category would leave its field group pointing at the core `category` taxonomy instead.
	 *
	 * @param array  $data    Definition data.
	 * @param string $kind    'cpt', 'taxonomy' or 'group_fields'.
	 * @param array  $key_map Normalized key map.
	 * @return array
	 */
	private static function apply_key_map( $data, $kind, $key_map ) {
		if ( empty( $key_map['cpt'] ) && empty( $key_map['taxonomy'] ) ) {
			return $data;
		}
		$cpt = $key_map['cpt'];
		$tax = $key_map['taxonomy'];

		if ( 'cpt' === $kind ) {
			if ( isset( $data['post_type_key'], $cpt[ $data['post_type_key'] ] ) ) {
				$data['post_type_key'] = $cpt[ $data['post_type_key'] ];
			}
			if ( ! empty( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ) {
				$data['taxonomies'] = array_values(
					array_map(
						static function ( $t ) use ( $tax ) {
							return isset( $tax[ $t ] ) ? $tax[ $t ] : $t;
						},
						$data['taxonomies']
					)
				);
			}
			return $data;
		}

		if ( 'taxonomy' === $kind ) {
			if ( isset( $data['taxonomy_key'], $tax[ $data['taxonomy_key'] ] ) ) {
				$data['taxonomy_key'] = $tax[ $data['taxonomy_key'] ];
			}
			if ( ! empty( $data['object_type'] ) && is_array( $data['object_type'] ) ) {
				$data['object_type'] = array_values(
					array_map(
						static function ( $p ) use ( $cpt ) {
							return isset( $cpt[ $p ] ) ? $cpt[ $p ] : $p;
						},
						$data['object_type']
					)
				);
			}
			return $data;
		}

		if ( ! empty( $data['location_rules'] ) && is_array( $data['location_rules'] ) ) {
			$data['location_rules'] = self::remap_location_rules( $data['location_rules'], $key_map );
		}
		return $data;
	}

	/**
	 * Rewrite post_type / taxonomy values inside location rules, at whatever nesting depth.
	 *
	 * @param array $rules   Location rules (flat list, or OR-groups of AND-rules).
	 * @param array $key_map Normalized key map.
	 * @return array
	 */
	private static function remap_location_rules( $rules, $key_map ) {
		foreach ( $rules as $i => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( isset( $rule['param'] ) ) {
				$kind = ( 'post_type' === $rule['param'] ) ? 'cpt' : ( ( 'taxonomy' === $rule['param'] ) ? 'taxonomy' : '' );
				if ( '' !== $kind && isset( $rule['value'], $key_map[ $kind ][ $rule['value'] ] ) ) {
					$rules[ $i ]['value'] = $key_map[ $kind ][ $rule['value'] ];
				}
				continue;
			}
			$rules[ $i ] = self::remap_location_rules( $rule, $key_map );
		}
		return $rules;
	}

	/**
	 * Definition keys a group's location rules depend on, as kind => keys.
	 *
	 * @param array $rules Location rules.
	 * @return array{cpt:string[],taxonomy:string[]}
	 */
	private static function location_dependencies( $rules ) {
		$out = array(
			'cpt'      => array(),
			'taxonomy' => array(),
		);
		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( isset( $rule['param'] ) ) {
				if ( empty( $rule['value'] ) || ! is_scalar( $rule['value'] ) ) {
					continue;
				}
				if ( 'post_type' === $rule['param'] ) {
					$out['cpt'][] = (string) $rule['value'];
				} elseif ( 'taxonomy' === $rule['param'] ) {
					$out['taxonomy'][] = (string) $rule['value'];
				}
				continue;
			}
			$nested            = self::location_dependencies( $rule );
			$out['cpt']        = array_merge( $out['cpt'], $nested['cpt'] );
			$out['taxonomy']   = array_merge( $out['taxonomy'], $nested['taxonomy'] );
		}
		$out['cpt']      = array_values( array_unique( $out['cpt'] ) );
		$out['taxonomy'] = array_values( array_unique( $out['taxonomy'] ) );
		return $out;
	}

	/**
	 * Whether the source plugin itself still registers this key right now.
	 *
	 * @param Uich_Fields_Import_Source $source    Source adapter.
	 * @param string                    $kind      cpt|taxonomy.
	 * @param string                    $key       Definition key (after any key map).
	 * @param int                       $source_id Source definition id.
	 * @return bool
	 */
	private static function registered_by_source( $source, $kind, $key, $source_id ) {
		$key = (string) $key;
		// A core key is a rename problem, reported on its own; it is not the source's to switch off.
		if ( '' === $key || '' !== Uich_Fields_Admin_Logic::reserved_key_reason( $key ) ) {
			return false;
		}
		if ( ! ( 'cpt' === $kind ? post_type_exists( $key ) : taxonomy_exists( $key ) ) ) {
			return false;
		}
		if ( $source->definitions_are_posts() ) {
			// A definition the source already switched off registers nothing, so a registered key is ours.
			return 'publish' === get_post_status( (int) $source_id );
		}
		return ! Uich_Fields_Admin_Logic::key_in_use( $kind, $key, 0, true );
	}

	/**
	 * The post types and taxonomies the source still registers under the keys the import will use.
	 *
	 * @param Uich_Fields_Import_Source $source  Source adapter.
	 * @param array                     $key_map Normalised key map.
	 * @return array<int, array{kind:string,key:string}>
	 */
	private static function still_registered( $source, $key_map ) {
		$out = array();
		foreach ( array(
			'cpt'      => array( $source->read_post_types(), 'post_type_key' ),
			'taxonomy' => array( $source->read_taxonomies(), 'taxonomy_key' ),
		) as $kind => $set ) {
			foreach ( $set[0] as $item ) {
				$data = self::apply_key_map( $item['data'], $kind, $key_map );
				$key  = isset( $data[ $set[1] ] ) ? (string) $data[ $set[1] ] : '';
				if ( self::registered_by_source( $source, $kind, $key, (int) $item['source_id'] ) ) {
					$out[] = array(
						'kind' => $kind,
						'key'  => $key,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * The warning that says the source is being switched off because it still registers these keys.
	 *
	 * @param array<int, array{kind:string,key:string}> $still  Result of still_registered().
	 * @param bool                                      $future True for the scan (it has not happened yet).
	 * @return string
	 */
	private static function forced_disable_notice( $still, $future = false ) {
		$keys = implode( ', ', array_unique( wp_list_pluck( $still, 'key' ) ) );
		$n    = count( $still );
		if ( $future ) {
			return sprintf(
				/* translators: %s: comma-separated list of keys */
				_n(
					'The source plugin still registers %s. Its definitions will be disabled when you run the migration, so the key is registered only once.',
					'The source plugin still registers these keys: %s. Its definitions will be disabled when you run the migration, so each key is registered only once.',
					$n,
					'uichemy'
				),
				$keys
			);
		}
		return sprintf(
			/* translators: %s: comma-separated list of keys */
			_n(
				'The source plugin still registered %s, so its definitions were disabled to register the key only once.',
				'The source plugin still registered these keys: %s, so its definitions were disabled to register each key only once.',
				$n,
				'uichemy'
			),
			$keys
		);
	}

	/**
	 * Errors for definitions that exist but cannot register while the source keeps their keys.
	 *
	 * @param array<int, array{kind:string,key:string}> $still Result of still_registered().
	 * @return string[]
	 */
	private static function not_registered_errors( $still ) {
		$out = array();
		foreach ( (array) $still as $item ) {
			$out[] = sprintf(
				/* translators: 1: post type or taxonomy key */
				__( '%1$s: Not registered. The source plugin still registers this key, so the imported definition stays inactive until the source is disabled.', 'uichemy' ),
				$item['key']
			);
		}
		return $out;
	}

	/**
	 * Why a source key cannot be used, plus a replacement the save would actually accept.
	 *
	 * Reserved keys are tested BEFORE the registered check: a core key like `category` is both, and
	 * reporting it as "disable the source plugin first" sends the user after something they cannot
	 * disable instead of renaming the key.
	 *
	 * @param string $kind 'cpt' or 'taxonomy'.
	 * @param string $key  Source key.
	 * @return array{reason:string,suggested_key:string}
	 */
	private static function key_conflict_info( $kind, $key ) {
		$key  = (string) $key;
		$none = array(
			'reason'        => '',
			'suggested_key' => '',
		);

		if ( '' === $key ) {
			return array(
				'reason'        => __( 'The source definition has no usable key.', 'uichemy' ),
				'suggested_key' => '',
			);
		}

		$reserved = Uich_Fields_Admin_Logic::reserved_key_reason( $key );
		if ( '' !== $reserved ) {
			$suggestion = Uich_Fields_Admin_Logic::suggest_available_key( $kind, $key );
			if ( 'object_type' === $reserved ) {
				$reason = ( 'cpt' === $kind )
					/* translators: %s: reserved key */
					? sprintf( __( '"%s" is a WordPress core post type or taxonomy, so it cannot be reused as a post type key.', 'uichemy' ), $key )
					/* translators: %s: reserved key */
					: sprintf( __( '"%s" is a WordPress core post type or taxonomy, so it cannot be reused as a taxonomy key.', 'uichemy' ), $key );
			} else {
				/* translators: %s: reserved key */
				$reason = sprintf( __( '"%s" is a reserved WordPress query variable; using it would break URL handling site-wide.', 'uichemy' ), $key );
			}
			return array(
				'reason'        => $reason,
				'suggested_key' => $suggestion,
			);
		}

		if ( Uich_Fields_Admin_Logic::key_in_use( $kind, $key, 0, true ) ) {
			return array(
				'reason'        => __( 'A UiChemy content type already uses this key.', 'uichemy' ),
				'suggested_key' => '',
			);
		}

		$registered = ( 'cpt' === $kind ) ? post_type_exists( $key ) : taxonomy_exists( $key );
		if ( $registered ) {
			return array(
				'reason'        => __( 'Still registered by the source plugin. Its definition will be disabled when the migration runs.', 'uichemy' ),
				'suggested_key' => '',
			);
		}
		return $none;
	}
}
