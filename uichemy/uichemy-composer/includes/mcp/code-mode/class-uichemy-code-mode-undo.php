<?php
/**
 * Undo for a Code Mode run.
 *
 * Before the first write a script makes to a page or post (any call with a
 * post_id), the post is snapshotted: its fields (title, content, status, slug,
 * dates, parent, order) and all of its meta, which is where the Composer
 * document, the builder data and the SEO head code live. The run's answer
 * carries a token; execute with undo: "<token>" puts every snapshotted post
 * back, within a day.
 *
 * It only restores a post nobody has changed since the run: restoring over
 * later work would lose it. Writes without a post_id (site settings, the
 * design system, redirects) and deleted posts cannot be undone this way; the
 * answer lists them under not_covered.
 *
 * Meta is restored first, then the fields through wp_update_post(), so every
 * save hook (SEO records, field caches, builder CSS) runs on the restored data.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Undo' ) ) {

	/**
	 * Snapshots posts before a run changes them, and puts them back.
	 */
	class UiChemy_Code_Mode_Undo {

		const OPTION_PREFIX = 'uichemy_cm_undo_';
		const INDEX_OPTION  = 'uichemy_cm_undo_index';
		const TTL           = DAY_IN_SECONDS;

		/** Largest snapshot of one post, in bytes. A bigger post is reported as not undoable. */
		const MAX_POST_BYTES = 4194304;

		/**
		 * Largest undo of one run, in bytes. It is one database row, and a row
		 * past MySQL's max_allowed_packet (often 16 MB) is refused; past this,
		 * further posts are reported as not undoable instead.
		 */
		const MAX_RUN_BYTES = 8388608;

		/** @var int Bytes snapshotted so far in this run. */
		private $bytes = 0;

		/** Post fields a snapshot keeps and restores. */
		const FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_password', 'comment_status', 'ping_status', 'post_date', 'post_date_gmt' );

		/** Meta that WordPress keeps for itself and that a restore must not touch. */
		const SKIP_META = array( '_edit_lock', '_edit_last' );

		/** @var array<int,array> Post id => snapshot. */
		private $posts = array();

		/** @var array<int,string> Calls whose changes are outside any snapshot. */
		private $not_covered = array();

		/**
		 * Snapshot a post before its first write in this run.
		 *
		 * @param int $post_id Post id.
		 */
		public function capture( $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || isset( $this->posts[ $post_id ] ) ) {
				return;
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$fields = array();
			foreach ( self::FIELDS as $field ) {
				$fields[ $field ] = $post->$field;
			}
			$meta = array();
			foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
				if ( ! in_array( $key, self::SKIP_META, true ) ) {
					$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
				}
			}

			$snapshot = array(
				'title'  => (string) $post->post_title,
				'type'   => (string) $post->post_type,
				'fields' => $fields,
				'meta'   => $meta,
			);
			$size = strlen( (string) wp_json_encode( $snapshot ) );
			if ( $size > self::MAX_POST_BYTES || $this->bytes + $size > self::MAX_RUN_BYTES ) {
				$this->not_covered[] = sprintf( 'post %d (%s)', $post_id, $size > self::MAX_POST_BYTES ? 'too large to snapshot' : 'over the undo size for one run' );
				$snapshot            = array(
					'title'     => (string) $post->post_title,
					'too_large' => true,
				);
			} else {
				$this->bytes += $size;
			}
			$this->posts[ $post_id ] = $snapshot;
		}

		/**
		 * A write this undo cannot cover (no post_id).
		 *
		 * @param string $call Call id, e.g. "design-system.set".
		 */
		public function not_covered( $call ) {
			if ( ! in_array( $call, $this->not_covered, true ) ) {
				$this->not_covered[] = $call;
			}
		}

		/**
		 * Keep the snapshots after the run, and say what the undo covers.
		 *
		 * @return array|null { token, posts, not_covered, expires } or null when nothing was changed.
		 */
		public function save() {
			if ( ! $this->posts && ! $this->not_covered ) {
				return null;
			}

			$posts = array();
			foreach ( $this->posts as $post_id => $snapshot ) {
				if ( ! empty( $snapshot['too_large'] ) ) {
					continue;
				}
				$post = get_post( $post_id );
				if ( ! $post instanceof WP_Post ) {
					$this->not_covered[] = sprintf( 'post %d (deleted)', $post_id );
					continue;
				}
				// What the post looks like right after the run: undo only touches it if this is unchanged.
				$snapshot['after'] = (string) $post->post_modified_gmt . '|' . md5( (string) $post->post_title . "\0" . (string) $post->post_content . "\0" . (string) $post->post_status );
				$posts[ $post_id ] = $snapshot;
			}

			$answer = array(
				'posts'       => array_keys( $posts ),
				'not_covered' => array_values( array_unique( $this->not_covered ) ),
			);
			if ( ! $posts ) {
				return $answer;
			}

			$token = wp_generate_password( 20, false, false );
			$saved = update_option(
				self::OPTION_PREFIX . $token,
				array(
					'user'    => get_current_user_id(),
					'created' => time(),
					'posts'   => $posts,
				),
				false
			);
			// No token for snapshots that were never stored (a database refusal,
			// e.g. a row over max_allowed_packet): say so instead.
			if ( ! $saved ) {
				foreach ( array_keys( $posts ) as $post_id ) {
					$answer['not_covered'][] = sprintf( 'post %d (the undo could not be saved)', $post_id );
				}
				$answer['posts'] = array();
				return $answer;
			}
			self::index( $token );

			return array_merge(
				array(
					'token'   => $token,
					'expires' => '24 hours',
				),
				$answer
			);
		}

		/**
		 * Put back every post a run changed.
		 *
		 * @param string $token Token from the run's answer.
		 * @return array|WP_Error { undone: [...], skipped: [...] }.
		 */
		public static function restore( $token ) {
			$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
			$data  = '' === $token ? false : get_option( self::OPTION_PREFIX . $token );
			if ( ! is_array( $data ) || empty( $data['posts'] ) ) {
				return new WP_Error( 'uichemy_code_mode_undo', 'There is nothing to undo for that token. It is used once and kept for 24 hours.' );
			}
			// Only the user whose run made the changes can put them back.
			if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
				return new WP_Error( 'uichemy_code_mode_undo', 'That undo token belongs to another user\'s run.' );
			}
			if ( time() - (int) $data['created'] > self::TTL ) {
				self::forget( $token );
				return new WP_Error( 'uichemy_code_mode_undo', 'That undo has expired (24 hours).' );
			}

			$undone  = array();
			$skipped = array();
			foreach ( $data['posts'] as $post_id => $snapshot ) {
				$post = get_post( (int) $post_id );
				if ( ! $post instanceof WP_Post ) {
					$skipped[] = array( 'post_id' => (int) $post_id, 'title' => $snapshot['title'], 'reason' => 'It was deleted after the run.' );
					continue;
				}
				$now = (string) $post->post_modified_gmt . '|' . md5( (string) $post->post_title . "\0" . (string) $post->post_content . "\0" . (string) $post->post_status );
				if ( $now !== $snapshot['after'] ) {
					$skipped[] = array( 'post_id' => (int) $post_id, 'title' => $snapshot['title'], 'reason' => 'It was changed again after the run, so it was left as it is.' );
					continue;
				}

				self::restore_meta( (int) $post_id, $snapshot['meta'] );
				$fields       = $snapshot['fields'];
				$fields['ID'] = (int) $post_id;
				$result       = wp_update_post( wp_slash( $fields ), true );
				if ( is_wp_error( $result ) ) {
					$skipped[] = array( 'post_id' => (int) $post_id, 'title' => $snapshot['title'], 'reason' => $result->get_error_message() );
					continue;
				}
				clean_post_cache( (int) $post_id );
				$undone[] = array( 'post_id' => (int) $post_id, 'title' => $snapshot['title'] );
			}

			self::forget( $token );

			return array(
				'undone'  => $undone,
				'skipped' => $skipped,
			);
		}

		/**
		 * Make a post's meta exactly what it was: keys added since are removed,
		 * every other key gets its old values back.
		 *
		 * @param int   $post_id Post id.
		 * @param array $meta    Key => list of values.
		 */
		private static function restore_meta( $post_id, array $meta ) {
			foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
				if ( ! isset( $meta[ $key ] ) && ! in_array( $key, self::SKIP_META, true ) ) {
					delete_post_meta( $post_id, $key );
				}
			}
			foreach ( $meta as $key => $values ) {
				delete_post_meta( $post_id, $key );
				foreach ( $values as $value ) {
					// add_post_meta() unslashes, so slash first or backslashes in code would be lost.
					add_post_meta( $post_id, $key, wp_slash( $value ) );
				}
			}
		}

		/**
		 * Remember a token, and drop the expired ones.
		 *
		 * @param string $token New token.
		 */
		private static function index( $token ) {
			$index = get_option( self::INDEX_OPTION, array() );
			$index = is_array( $index ) ? $index : array();
			foreach ( $index as $old => $expires ) {
				if ( (int) $expires < time() ) {
					delete_option( self::OPTION_PREFIX . $old );
					unset( $index[ $old ] );
				}
			}
			$index[ $token ] = time() + self::TTL;
			update_option( self::INDEX_OPTION, $index, false );
		}

		/**
		 * @param string $token Token.
		 */
		private static function forget( $token ) {
			delete_option( self::OPTION_PREFIX . $token );
			$index = get_option( self::INDEX_OPTION, array() );
			if ( is_array( $index ) && isset( $index[ $token ] ) ) {
				unset( $index[ $token ] );
				update_option( self::INDEX_OPTION, $index, false );
			}
		}
	}
}
