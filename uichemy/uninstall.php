<?php
/**
 * Fired when the plugin is deleted.
 *
 * WordPress only runs the uninstall.php at the plugin root, and it runs it WITHOUT loading uichemy.php,
 * so nothing here may call a plugin class. Everything is kept unless "Delete all data on uninstall" is on
 * (`uichemy_settings['delete_on_uninstall']`, checked per site on multisite).
 *
 * Order matters: the Custom Fields cleanup runs first because the builder/SEO cleanup it hands over to
 * (uichemy-composer/uninstall.php) deletes every `uichemy_*` option, including that setting.
 *
 * Intentionally NOT removed: user content (pages, posts, and entries of custom post types created with
 * Custom Fields). Only the field values stored beside them are removed.
 *
 * @link    https://posimyth.com
 * @package UiChemy
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'uichemy_uninstall_fields_site' ) ) {

	/**
	 * Remove the Custom Fields data of the CURRENT site, gated by that site's opt-in setting.
	 *
	 * @return void
	 */
	function uichemy_uninstall_fields_site() {
		global $wpdb;

		$settings = get_option( 'uichemy_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
			return; // Default: keep all data.
		}

		// 1. Capabilities the module granted to the administrator role (only those it recorded, never
		// one the role already had).
		$granted = get_option( 'uich_fields_granted_admin_caps', array() );
		$admin   = get_role( 'administrator' );
		if ( $admin && is_array( $granted ) ) {
			foreach ( array_keys( $granted ) as $cap ) {
				if ( is_string( $cap ) && '' !== $cap && $admin->has_cap( $cap ) ) {
					$admin->remove_cap( $cap );
				}
			}
		}

		// 2. Stored field values (post, term, comment, and this site's user meta) and import backups.
		// The post/term/comment prefixes share `_uichemy_gf_`; user values carry the blog id.
		$gf       = $wpdb->esc_like( '_uichemy_gf_' ) . '%';
		$bak      = $wpdb->esc_like( '_uichemy_import_bak_' ) . '%';
		$user_gf  = $wpdb->esc_like( '_uichemy_gf_user_' . get_current_blog_id() . '_' ) . '%';
		$user_bak = $wpdb->esc_like( '_uichemy_import_bak_b' . get_current_blog_id() . '_' ) . '%';
		foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->commentmeta ) as $meta_table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE meta_key LIKE %s OR meta_key LIKE %s", $gf, $bak ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names.
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $user_gf, $user_bak ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// 3. The definition posts (post types, taxonomies, field groups) and their config meta.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'uich_content_types' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ids ) {
			$ids = array_map( 'absint', $ids );
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$in = implode( ',', $chunk );
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers only.
				$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers only.
			}
		}

		// 4. Ability journal table.
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}uichemy_fields_journal`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from trusted $wpdb->prefix.

		// 5. Options by prefix (the `uichemy_` ones go with the composer cleanup) plus their transients.
		// `uich_gf_option_` holds options-page VALUES, so it goes with the other field values.
		$prefixes = array( 'uich_ct_', 'uich_model_', 'uich_fields_', 'uich_import_', 'uich_gf_option_' );
		foreach ( $prefixes as $prefix ) {
			$like  = $wpdb->esc_like( $prefix ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $names as $name ) {
				delete_option( $name );
			}
		}
		$transient_prefixes = array( 'uich_ct_', 'uich_model_', 'uich_gf_required_err_', 'uichemy_fields_ab_' );
		foreach ( $transient_prefixes as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $kind ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $kind . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		// 6. Scheduled work of the builder runtime that the composer cleanup does not unschedule.
		wp_unschedule_hook( 'uichemy_composer_migration_run' );

		// 7. This site's model files (wp-content/uichemy-fields/sites/{id}), then the shared parents once
		// nothing else lives in them (other sites of a network keep theirs).
		$model_root = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/uichemy-fields';
		$site_dir   = $model_root . '/sites/' . max( 1, (int) get_current_blog_id() );
		uichemy_uninstall_rmdir( $site_dir );
		@rmdir( $model_root . '/sites' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fails harmlessly while other sites remain.
		if ( is_dir( $model_root ) && ! is_dir( $model_root . '/sites' ) ) {
			$left = array_diff( (array) scandir( $model_root ), array( '.', '..', 'index.php', '.htaccess' ) );
			if ( ! $left ) {
				uichemy_uninstall_rmdir( $model_root );
			}
		}
	}
}

if ( ! function_exists( 'uichemy_uninstall_rmdir' ) ) {

	/**
	 * Recursively delete a directory under wp-content.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	function uichemy_uninstall_rmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				uichemy_uninstall_rmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort; a locked file must not abort the uninstall.
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	$uichemy_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $uichemy_site_ids as $uichemy_site_id ) {
		switch_to_blog( (int) $uichemy_site_id );
		uichemy_uninstall_fields_site();
		restore_current_blog();
	}
} else {
	uichemy_uninstall_fields_site();
}

// Builder, SEO, chat and every `uichemy_*` option (this deletes the setting read above, so it runs last).
require_once __DIR__ . '/uichemy-composer/uninstall.php';
