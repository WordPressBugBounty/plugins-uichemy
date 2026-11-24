<?php
/**
 * POSIMYTH Analytics SDK — version-aware loader.
 *
 * Every POSIMYTH plugin ships its own copy of the shared SDK classes, each wrapped in a
 * class_exists guard. That guard alone made load ORDER decide the winner: whichever plugin
 * WordPress happened to include first defined Posimyth_Tracker_Base for everyone, so one outdated
 * sibling could silently downgrade the whole suite — e.g. an older do_request() without the
 * $bypass_consent parameter swallowed the deactivation survey's third argument and its feedback
 * never sent, with no error anywhere.
 *
 * So no plugin requires the shared classes directly any more. Each one calls
 * posimyth_sdk_register() with its bundled copy's directory; at plugins_loaded priority 0 — before
 * every consumer, which runs at priority 10 — the loader requires the files from the NEWEST
 * registered copy, as declared by that copy's version.php. Per-product subclasses are not shared
 * and are loaded by their own plugin after the winner exists.
 *
 * The loader itself must stay tiny and boring: it is duplicated into every plugin too, and only
 * the first copy's functions define. Change it only in a backwards-compatible way.
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'posimyth_sdk_register' ) ) {

	/**
	 * Registry of every bundled SDK copy seen this request.
	 *
	 * Static-in-function rather than a global so nothing outside these helpers can corrupt it.
	 *
	 * @param array|null $add Optional array{dir:string,version:string} to record.
	 * @return array<string,string> Map of directory => version.
	 */
	function posimyth_sdk_registry( ?array $add = null ): array {
		static $dirs = array();
		if ( null !== $add ) {
			$dirs[ $add['dir'] ] = $add['version'];
		}
		return $dirs;
	}

	/**
	 * The directory holding the highest-versioned copy. Ties keep the first registered — the
	 * copies are byte-identical apart from their text domain, so a tie has no wrong answer.
	 *
	 * @param array<string,string> $dirs Map of directory => version.
	 * @return string|null
	 */
	function posimyth_sdk_pick_latest( array $dirs ): ?string {
		$best_dir     = null;
		$best_version = '0';
		foreach ( $dirs as $dir => $version ) {
			if ( null === $best_dir || version_compare( $version, $best_version, '>' ) ) {
				$best_dir     = $dir;
				$best_version = $version;
			}
		}
		return $best_dir;
	}

	/**
	 * Requires the shared SDK classes from the newest registered copy. Idempotent: once the base
	 * class exists (from any copy), later calls do nothing.
	 */
	function posimyth_sdk_load_latest(): void {
		if ( class_exists( 'Posimyth_Tracker_Base', false ) ) {
			return;
		}

		$dir = posimyth_sdk_pick_latest( posimyth_sdk_registry() );
		if ( null === $dir ) {
			return;
		}

		$shared = array(
			'class-posimyth-tracker-base.php',
			'class-posimyth-consent-notice.php',
			'class-posimyth-deactivation-survey.php',
		);
		foreach ( $shared as $file ) {
			$path = $dir . '/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Records a plugin's bundled SDK copy and arranges for the newest one to load.
	 *
	 * Normally that happens once at plugins_loaded priority 0. When a plugin is included AFTER
	 * plugins_loaded has already fired — which is exactly what happens during its own activation
	 * request — every already-active sibling has registered by then, so loading immediately is
	 * both safe and required (the activation ping needs the classes in that same request).
	 *
	 * @param string $dir Absolute path of this plugin's posimyth-sdk directory (no trailing slash needed).
	 */
	function posimyth_sdk_register( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );

		$version_file = $dir . '/version.php';
		$version      = is_readable( $version_file ) ? (string) include $version_file : '0.0.0';

		posimyth_sdk_registry(
			array(
				'dir'     => $dir,
				'version' => $version,
			)
		);

		if ( did_action( 'plugins_loaded' ) ) {
			posimyth_sdk_load_latest();
			return;
		}

		if ( false === has_action( 'plugins_loaded', 'posimyth_sdk_load_latest' ) ) {
			add_action( 'plugins_loaded', 'posimyth_sdk_load_latest', 0 );
		}
	}
}
