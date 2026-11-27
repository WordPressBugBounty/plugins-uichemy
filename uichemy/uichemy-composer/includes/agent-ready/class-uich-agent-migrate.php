<?php
/**
 * One-time move of the SEO module's AI settings into Agent Ready.
 *
 * Agent Ready now answers /llms.txt and the .md pages. On its first run it
 * copies what the owner set in SEO so nothing is lost:
 * - SEO llms intro and extra text -> llms.intro (when Agent Ready's is empty)
 * - SEO per-type "Markdown" switches -> agent_pages.post_types
 * - each post's "exclude from AI" -> Agent Ready's hide-from-agents flag
 *
 * SEO's own data is never deleted, so switching Agent Ready off gives SEO its
 * behaviour back unchanged. A log of what was copied is kept in the option.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Migrate' ) ) {

	/**
	 * SEO to Agent Ready migration.
	 */
	class Uich_Agent_Migrate {

		/**
		 * Option recording the run.
		 */
		const OPTION = 'uichemy_agent_ready_migrated';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
		}

		/**
		 * Run once, when the SEO module is present.
		 *
		 * @return void
		 */
		public static function maybe_run() {
			$done = get_option( self::OPTION );
			if ( $done ) {
				self::repair_types( $done );
				return;
			}
			if ( ! class_exists( 'Uich_SEO_Settings' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			update_option( self::OPTION, array( 'at' => time(), 'running' => true ), false );
			$log = self::run();
			update_option( self::OPTION, array_merge( array( 'at' => time() ), $log ), false );
		}

		/**
		 * Public types that can have agent pages. Media, templates and other blocked
		 * types never have them, so their SEO switch says nothing about the owner's choice.
		 *
		 * @return string[]
		 */
		private static function content_types() {
			return array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), Uich_Agent_Tools::BLOCKED_TYPES ) );
		}

		/**
		 * Undo a too-narrow type list left by an earlier version of this migration.
		 *
		 * It counted media and template types (whose SEO switch is always off) as
		 * switched off, so it saved the types that existed then as the only ones
		 * allowed, and every content type added later was refused. When the owner
		 * switched no real content type off in SEO and the list was never changed
		 * since, the list is cleared again: empty means every public type.
		 *
		 * @param mixed $done The migration record.
		 * @return void
		 */
		private static function repair_types( $done ) {
			if ( ! is_array( $done ) || ! empty( $done['types_checked'] ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$done['types_checked'] = time();
			$saved                 = (array) Uich_Agent_Settings::value( 'agent_pages.post_types', array() );
			$moved                 = isset( $done['post_types'] ) ? (array) $done['post_types'] : array();
			if ( $saved && $saved === $moved && class_exists( 'Uich_SEO_Settings' ) ) {
				$off = false;
				foreach ( self::content_types() as $type ) {
					$row = Uich_SEO_Settings::type_row( $type );
					if ( empty( $row['markdown'] ) ) {
						$off = true;
						break;
					}
				}
				if ( ! $off ) {
					Uich_Agent_Settings::update( array( 'agent_pages' => array( 'post_types' => array() ) ), true );
					$done['types_repaired'] = $moved;
				}
			}
			update_option( self::OPTION, $done, false );
		}

		/**
		 * Copy the settings. Returns what was copied.
		 *
		 * @return array
		 */
		public static function run() {
			$log   = array();
			$seo   = (array) Uich_SEO_Settings::all();
			$patch = array();

			$intro = trim( (string) ( isset( $seo['llms_intro'] ) ? $seo['llms_intro'] : '' ) . "\n\n" . (string) ( isset( $seo['llms_extra'] ) ? $seo['llms_extra'] : '' ) );
			if ( '' !== $intro && '' === Uich_Agent_Settings::value( 'llms.intro', '' ) ) {
				$patch['llms']['intro'] = $intro;
				$log['intro']           = true;
			}

			$types = array();
			$off   = false;
			foreach ( self::content_types() as $type ) {
				$row = Uich_SEO_Settings::type_row( $type );
				if ( ! empty( $row['markdown'] ) ) {
					$types[] = $type;
				} else {
					$off = true;
				}
			}
			// Only narrow the list when the owner switched some type off in SEO.
			if ( $off && $types ) {
				$patch['agent_pages']['post_types'] = $types;
				$log['post_types']                  = $types;
			}

			if ( $patch ) {
				Uich_Agent_Settings::update( $patch );
			}

			$hidden = 0;
			if ( class_exists( 'Uich_SEO_Resolver' ) ) {
				$ids = get_posts(
					array(
						'post_type'   => 'any',
						'post_status' => 'any',
						'numberposts' => 2000,
						'fields'      => 'ids',
						'meta_key'    => '_uichemy_seo', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration.
					)
				);
				foreach ( $ids as $id ) {
					$r = Uich_SEO_Resolver::for_post( $id );
					if ( is_array( $r ) && ! empty( $r['exclude_ai'] ) ) {
						// The mirror key follows the tag: removing the tag later shows the page again.
						update_post_meta( $id, Uich_Agent_Access::META_SEO_HIDE, 1 );
						++$hidden;
					}
				}
			}
			$log['hidden'] = $hidden;
			return $log;
		}
	}
}
