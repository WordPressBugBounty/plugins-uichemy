<?php
/**
 * WP-CLI: `wp uichemy seo derive|backfill|audit|export` (02 §15).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_CLI' ) && defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manage UiChemy SEO from the command line.
	 */
	class Uich_SEO_CLI {

		/**
		 * Re-read pages and refresh their SEO data.
		 *
		 * ## OPTIONS
		 *
		 * <id>...
		 * : One or more post ids.
		 *
		 * ## EXAMPLES
		 *
		 *     wp uichemy seo derive 12 34
		 *
		 * @param array $args Ids.
		 * @return void
		 */
		public function derive( $args ) {
			foreach ( $args as $id ) {
				if ( ! ctype_digit( (string) $id ) || ! get_post( (int) $id ) ) {
					WP_CLI::error( sprintf( '%s is not the id of an existing page or post.', $id ) );
				}
				$r = Uich_SEO_Record::rebuild( (int) $id );
				if ( Uich_SEO_Record::is_built( $r ) ) {
					$v = Uich_SEO_Resolver::for_post( (int) $id );
					WP_CLI::log( sprintf( '%d  %s', $id, $v ? $v['title']['value'] : '' ) );
				} else {
					WP_CLI::warning( sprintf( '%d is not a page UiChemy SEO handles.', $id ) );
				}
			}
			WP_CLI::success( 'Done.' );
		}

		/**
		 * Refresh SEO data for every published page, in batches with flat memory.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<n>]
		 * : Pages per batch. Default 50.
		 *
		 * [--site-checks]
		 * : Also run the site-wide checks (file sizes, broken links). Slower.
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 * @return void
		 */
		public function backfill( $args, $assoc_args ) {
			$batch_arg = WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 50 );
			if ( ! is_numeric( $batch_arg ) || (int) $batch_arg < 1 ) {
				WP_CLI::error( 'Use --batch with a whole number of 1 or more.' );
			}
			$batch = (int) $batch_arg;
			$site  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'site-checks', false );
			$types = array();
			foreach ( Uich_SEO_Settings::content_types() as $pt ) {
				$row = Uich_SEO_Settings::type_row( $pt );
				if ( 'attachment' !== $pt && $row['seo'] ) {
					$types[] = $pt;
				}
			}
			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				$types[] = UiChemy_Template_CPT::POST_TYPE;
			}
			$total = (int) ( new WP_Query( array( 'post_type' => $types, 'post_status' => array( 'publish', 'draft', 'future', 'private' ), 'fields' => 'ids', 'posts_per_page' => 1 ) ) )->found_posts;
			$bar   = WP_CLI\Utils\make_progress_bar( 'Refreshing SEO data', $total );
			$last  = 0;
			$done  = 0;
			do {
				global $wpdb;
				$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				// Keyset paging by ID keeps memory flat on large sites.
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in) AND post_status IN ('publish','draft','future','private') ORDER BY ID ASC LIMIT %d", array_merge( array( $last ), $types, array( $batch ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $ids as $id ) {
					Uich_SEO_Record::rebuild( (int) $id );
					if ( $site && 'publish' === get_post_status( $id ) ) {
						Uich_SEO_Audit::site_checks( (int) $id, true );
					}
					$last = (int) $id;
					++$done;
					$bar->tick();
				}
				wp_cache_flush_runtime();
			} while ( count( $ids ) === $batch );
			$bar->finish();
			WP_CLI::success( sprintf( '%d pages refreshed. Peak memory %s.', $done, size_format( memory_get_peak_usage( true ) ) ) );
		}

		/**
		 * List what needs attention.
		 *
		 * ## OPTIONS
		 *
		 * [<id>]
		 * : A single post. Omit for the whole site summary.
		 *
		 * [--format=<format>]
		 * : table, json or csv. Default table.
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 * @return void
		 */
		public function audit( $args, $assoc_args ) {
			$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
			if ( $args ) {
				if ( ! ctype_digit( (string) $args[0] ) || ! get_post( (int) $args[0] ) ) {
					WP_CLI::error( sprintf( '%s is not the id of an existing page or post.', $args[0] ) );
				}
				$rows = array_map(
					function ( $f ) {
						return array(
							'severity' => $f['severity'],
							'check'    => $f['check'],
							'message'  => $f['message'],
							'where'    => $f['node_path'] ? $f['section'] . ' ' . $f['node_path'] : '',
						);
					},
					Uich_SEO_Audit::stored( (int) $args[0] )
				);
				WP_CLI\Utils\format_items( $format, $rows, array( 'severity', 'check', 'message', 'where' ) );
				return;
			}
			$o    = Uich_SEO_Rest::overview()->get_data();
			$rows = array_map(
				function ( $f ) {
					return array(
						'severity' => $f['severity'],
						'check'    => $f['check'],
						'summary'  => $f['text'],
					);
				},
				$o['findings']
			);
			// Only the table is for people; json/csv must stay parseable.
			if ( 'table' === $format ) {
				WP_CLI::log( sprintf( 'SEO handled by: %s. Pages with SEO: %d of %d.', $o['tiles']['owner'], $o['tiles']['with_seo'], $o['tiles']['total'] ) );
			}
			WP_CLI\Utils\format_items( $format, $rows, array( 'severity', 'check', 'summary' ) );
		}

		/**
		 * Export every page's final SEO values (title, description, canonical, robots).
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : csv or json. Default csv.
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 * @return void
		 */
		public function export( $args, $assoc_args ) {
			$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'csv' );
			$rows   = array();
			$ids    = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ) );
			foreach ( $ids as $id ) {
				$r = Uich_SEO_Resolver::for_post( $id );
				if ( ! $r ) {
					continue;
				}
				$rows[] = array(
					'id'          => $id,
					'url'         => get_permalink( $id ),
					'title'       => $r['title']['value'],
					'description' => $r['description']['value'],
					'canonical'   => $r['canonical']['value'],
					'robots'      => Uich_SEO_Emitter::robots_line( $r['robots'] ),
				);
			}
			WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'url', 'title', 'description', 'canonical', 'robots' ) );
		}
	}

	WP_CLI::add_command( 'uichemy seo', 'Uich_SEO_CLI' );
}
