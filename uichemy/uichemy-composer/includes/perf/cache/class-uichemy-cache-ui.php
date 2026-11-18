<?php
/**
 * Page cache in the admin (K10): dashboard data and ajax, admin bar menu, and
 * "Don't cache this page" (C05) in the editor.
 *
 * Ajax types ride on the dashboard's own `uichemy_dashboard` action, which has
 * already checked the nonce and manage_options before `uichemy/admin/ajax`
 * fires. The admin bar uses admin-post.php with its own nonce. The per-page
 * switch needs edit_post for that page. Labels are the copy.json strings.
 *
 * @package UiChemy
 * @subpackage UiChemy/perf/cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Cache_Ui' ) ) {

	class UiChemy_Cache_Ui {

		const CLEAR_ACTION = 'uichemy_cache_clear';

		/**
		 * Hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'uichemy/admin/ajax', array( __CLASS__, 'ajax' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
			add_action( 'admin_post_' . self::CLEAR_ACTION, array( __CLASS__, 'admin_bar_clear' ) );
			add_action( 'init', array( __CLASS__, 'register_meta' ) );
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
			add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
			add_action( 'elementor/documents/register_controls', array( __CLASS__, 'elementor_controls' ) );
			add_action( 'elementor/document/after_save', array( __CLASS__, 'elementor_saved' ), 5, 2 );
			add_action( 'updated_post_meta', array( __CLASS__, 'on_override_change' ), 10, 3 );
			add_action( 'added_post_meta', array( __CLASS__, 'on_override_change' ), 10, 3 );
			add_action( 'deleted_post_meta', array( __CLASS__, 'on_override_change' ), 10, 3 );
		}

		/**
		 * Settings screen URL.
		 *
		 * @return string
		 */
		public static function settings_url() {
			return admin_url( 'admin.php?page=uichemy#/performance' );
		}

		// ------------------------------------------------------------------
		// Dashboard.
		// ------------------------------------------------------------------

		/**
		 * Boot data for the Performance screen (`data.cache`).
		 *
		 * @return array
		 */
		public static function boot() {
			return array(
				'settings' => UiChemy_Cache_Settings::public_settings(),
				'defaults' => UiChemy_Cache_Settings::defaults(),
				'status'   => self::status(),
				'detected' => UiChemy_Cache_Rules::detected(),
				'pages'    => self::page_labels( UiChemy_Cache_Settings::get()['exclude_pages'] ),
			);
		}

		/**
		 * Status plus what the notices need.
		 *
		 * @return array
		 */
		public static function status() {
			$s                 = UiChemy_Cache_Ai::status_summary();
			$s['multisite']    = is_multisite();
			$s['folder']       = 'wp-content/uichemy/cache';
			$s['full']         = UiChemy_Cache_Files::state()['full_at'] > time() - DAY_IN_SECONDS;
			$s['limit']        = '500 MB';
			$s['host_ttl']     = '';
			$s['host_cleared'] = get_option( UiChemy_Cache_Host_Purge::LAST_OPTION, null );
			$detect            = UiChemy_Cache_Host::detect();
			if ( $detect['host'] ) {
				$entry          = UiChemy_Cache_Host::host( $detect['host']['id'] );
				$s['host_ttl']  = $entry && ! empty( $entry['ttl'] ) ? $entry['ttl'] : '';
			}
			$s['dropin_plugin'] = $detect['dropin']['plugin'];
			// The chart groups these in the viewer's own time zone (the AI gets site-time buckets in `usage`).
			$s['usage_hours'] = 'on' === $s['state'] ? UiChemy_Cache_Files::hours() : array();
			return $s;
		}

		/**
		 * Dashboard ajax types (nonce and manage_options already checked).
		 *
		 * @param string $type Ajax type.
		 * @return void
		 */
		public static function ajax( $type ) {
			if ( 0 !== strpos( (string) $type, 'cache_' ) ) {
				return;
			}
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by UiChemy_Admin_Menu::ajax() before this action.
			switch ( $type ) {
				case 'cache_status':
					if ( ! empty( $_POST['redetect'] ) ) {
						UiChemy_Cache_Host::detect( true );
						UiChemy_Cache::apply();
					}
					wp_send_json_success( self::boot() );
					break;

				case 'cache_save':
					$raw = isset( $_POST['changes'] ) ? json_decode( wp_unslash( $_POST['changes'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by UiChemy_Cache_Settings::validate().
					$res = UiChemy_Cache_Ai::set_settings( array( 'changes' => is_array( $raw ) ? $raw : array() ) );
					if ( is_wp_error( $res ) ) {
						$data = $res->get_error_data();
						wp_send_json_error(
							array(
								'code' => $res->get_error_code(),
								'message' => $res->get_error_message(),
								'field' => isset( $data['field'] ) ? $data['field'] : '',
							),
							400
						);
					}
					wp_send_json_success( self::boot() );
					break;

				case 'cache_test':
					$t = UiChemy_Cache_Ai::cache_test();
					wp_send_json_success(
						array(
							'test' => $t,
							'boot' => self::boot(),
						)
					);
					break;

				case 'cache_clear':
					$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
					$res   = UiChemy_Cache_Ai::cache_clear(
						array(
							'scope' => $scope,
							'url' => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
						)
					);
					if ( is_wp_error( $res ) ) {
						wp_send_json_error(
							array(
								'code' => $res->get_error_code(),
								'message' => $res->get_error_message(),
							),
							400
						);
					}
					UiChemy_Cache_Clear::flush();
					wp_send_json_success(
						array(
							'result' => $res,
							'boot' => self::boot(),
						)
					);
					break;

				case 'cache_check':
					$res = UiChemy_Cache_Ai::cache_status(
						array(
							'url'     => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
							'post_id' => isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0,
						)
					);
					if ( is_wp_error( $res ) ) {
						wp_send_json_error(
							array(
								'code' => $res->get_error_code(),
								'message' => $res->get_error_message(),
							),
							400
						);
					}
					wp_send_json_success( $res );
					break;

				case 'cache_pages':
					$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
					wp_send_json_success( array( 'pages' => self::search_pages( $q ) ) );
					break;
			}
			// phpcs:enable
			wp_send_json_error( array( 'message' => 'Unknown cache action.' ), 400 );
		}

		/**
		 * Published, cached post types matching a search, for the page picker.
		 *
		 * @param string $q Search text.
		 * @return array<int,array{id:int,title:string,path:string}>
		 */
		private static function search_pages( $q ) {
			$types = array();
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $t ) {
				if ( UiChemy_Cache_Clear::cacheable_type( $t ) ) {
					$types[] = $t;
				}
			}
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					's'              => $q,
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			return self::page_labels( $ids );
		}

		/**
		 * @param int[] $ids Post IDs.
		 * @return array<int,array{id:int,title:string,path:string}>
		 */
		private static function page_labels( array $ids ) {
			$out = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post ) {
					$out[] = array(
						'id'    => (int) $id,
						'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
						'path'  => UiChemy_Cache_Settings::post_rel_path( $id ),
					);
				}
			}
			return $out;
		}

		// ------------------------------------------------------------------
		// Admin bar.
		// ------------------------------------------------------------------

		/**
		 * "Cache" menu for administrators.
		 *
		 * @param WP_Admin_Bar $bar Admin bar.
		 * @return void
		 */
		public static function admin_bar( $bar ) {
			if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
				return;
			}
			$status = UiChemy_Cache::status();
			if ( ! in_array( $status['state'], array( 'on', 'host' ), true ) ) {
				return;
			}
			$bar->add_node(
				array(
					'id'    => 'uichemy-cache',
					'title' => esc_html__( 'Cache', 'uichemy' ),
					'href'  => self::settings_url(),
				)
			);
			if ( ! is_admin() ) {
				$url  = self::current_url();
				$text = 'host' === $status['state']
					/* translators: %s: host name */
					? sprintf( __( 'Cached by %s', 'uichemy' ), $status['host'] )
					: ( UiChemy_Cache_Files::is_cached( $url ) ? __( 'Cached', 'uichemy' ) : __( 'Not cached yet', 'uichemy' ) );
				$bar->add_node(
					array(
						'parent' => 'uichemy-cache',
						'id'     => 'uichemy-cache-status',
						/* translators: %s: page cache status, e.g. "Cached" */
						'title'  => esc_html( sprintf( __( 'This page: %s', 'uichemy' ), $text ) ),
					)
				);
				$bar->add_node(
					array(
						'parent' => 'uichemy-cache',
						'id'     => 'uichemy-cache-clear-page',
						'title'  => esc_html__( 'Clear this page', 'uichemy' ),
						'href'   => self::clear_link( 'page', $url ),
					)
				);
			}
			$bar->add_node(
				array(
					'parent' => 'uichemy-cache',
					'id'     => 'uichemy-cache-clear-all',
					'title'  => esc_html__( 'Clear all cache', 'uichemy' ),
					'href'   => self::clear_link( 'all', '' ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'uichemy-cache',
					'id'     => 'uichemy-cache-settings',
					'title'  => esc_html__( 'Cache settings', 'uichemy' ),
					'href'   => self::settings_url(),
				)
			);
		}

		/**
		 * @param string $scope page | all.
		 * @param string $url   Page URL.
		 * @return string
		 */
		private static function clear_link( $scope, $url ) {
			$args = array(
				'action' => self::CLEAR_ACTION,
				'scope' => $scope,
			);
			if ( $url ) {
				$args['url'] = rawurlencode( $url );
			}
			return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::CLEAR_ACTION );
		}

		/**
		 * admin-post.php handler for the admin bar items.
		 *
		 * @return void
		 */
		public static function admin_bar_clear() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'uichemy' ), 403 );
			}
			check_admin_referer( self::CLEAR_ACTION );
			$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
			$url   = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw.
			UiChemy_Cache_Ai::cache_clear(
				'page' === $scope ? array(
					'scope' => 'page',
					'url' => $url,
				) : array( 'scope' => 'all' )
			);
			UiChemy_Cache_Clear::flush();
			$back = wp_get_referer();
			wp_safe_redirect( $back ? $back : self::settings_url() );
			exit;
		}

		/**
		 * The URL of the page being viewed, on this site's host.
		 *
		 * @return string
		 */
		private static function current_url() {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$uri = strtok( $uri, '?' );
			$home = wp_parse_url( home_url( '/' ) );
			return $home['scheme'] . '://' . $home['host'] . ( empty( $home['port'] ) ? '' : ':' . $home['port'] ) . $uri;
		}

		// ------------------------------------------------------------------
		// C05 "Don't cache this page".
		// ------------------------------------------------------------------

		/**
		 * Register the per-page meta: REST-visible, writable only with edit_post.
		 *
		 * @return void
		 */
		public static function register_meta() {
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
				register_post_meta(
					$type,
					UiChemy_Cache_Settings::POST_META,
					array(
						'type'          => 'boolean',
						'single'        => true,
						'default'       => false,
						'show_in_rest'  => true,
						'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}

		/**
		 * "Performance" box in the block and classic editors.
		 *
		 * @param string $post_type Post type.
		 * @return void
		 */
		public static function meta_box( $post_type ) {
			if ( ! UiChemy_Cache_Clear::cacheable_type( $post_type ) ) {
				return;
			}
			add_meta_box( 'uichemy-cache', __( 'Performance', 'uichemy' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'low' );
		}

		/**
		 * @param WP_Post $post Post.
		 * @return void
		 */
		public static function render_meta_box( $post ) {
			wp_nonce_field( 'uichemy_cache_meta', 'uichemy_cache_meta_nonce' );
			$on     = (bool) get_post_meta( $post->ID, UiChemy_Cache_Settings::POST_META, true );
			$status = self::editor_status( $post->ID );
			echo '<p><label><input type="checkbox" name="uichemy_cache_disabled" value="1"' . checked( $on, true, false ) . '> ' . esc_html__( 'Don\'t cache this page', 'uichemy' ) . '</label></p>';
			echo '<p class="description">' . esc_html__( 'Turn on if this page shows different content to each visitor.', 'uichemy' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Page cache', 'uichemy' ) . ':</strong> ' . esc_html( $status ) . '</p>';
		}

		/**
		 * Editor page status (copy.json editor.page_status_values).
		 *
		 * @param int $post_id Post.
		 * @return string
		 */
		public static function editor_status( $post_id ) {
			$status = UiChemy_Cache::status();
			if ( 'host' === $status['state'] && $status['host'] ) {
				/* translators: %s: host name */
				return sprintf( __( 'Cached by %s', 'uichemy' ), $status['host'] );
			}
			if ( 'on' !== $status['state'] ) {
				return __( 'Page cache is off', 'uichemy' );
			}
			if ( 'publish' !== get_post_status( $post_id ) ) {
				return __( 'Not cached yet', 'uichemy' );
			}
			$r = UiChemy_Cache_Ai::cache_status( array( 'post_id' => $post_id ) );
			if ( is_wp_error( $r ) ) {
				return __( 'Not cached yet', 'uichemy' );
			}
			if ( 'HIT' === $r['result'] ) {
				return __( 'Cached', 'uichemy' );
			}
			if ( 'BYPASS' === $r['result'] && isset( $r['reason']['label'] ) ) {
				/* translators: %s: reason, e.g. "it contains a booking form" */
				return sprintf( __( 'Not cached: %s', 'uichemy' ), $r['reason']['label'] );
			}
			return __( 'Not cached yet', 'uichemy' );
		}

		/**
		 * @param int     $post_id Post.
		 * @param WP_Post $post    Post.
		 * @return void
		 */
		public static function save_meta_box( $post_id, $post ) {
			if ( ! isset( $_POST['uichemy_cache_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['uichemy_cache_meta_nonce'] ) ), 'uichemy_cache_meta' ) ) {
				return;
			}
			if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			if ( ! empty( $_POST['uichemy_cache_disabled'] ) ) {
				update_post_meta( $post_id, UiChemy_Cache_Settings::POST_META, true );
			} else {
				delete_post_meta( $post_id, UiChemy_Cache_Settings::POST_META );
			}
		}

		/**
		 * Elementor: a switch in Page Settings, mirrored to the same post meta.
		 *
		 * @param object $document Elementor document.
		 * @return void
		 */
		public static function elementor_controls( $document ) {
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! method_exists( $document, 'start_controls_section' ) ) {
				return;
			}
			$post_id = (int) $document->get_main_id();
			if ( ! $post_id || ! UiChemy_Cache_Clear::cacheable_type( (string) get_post_type( $post_id ) ) ) {
				return;
			}
			$document->start_controls_section(
				'uichemy_cache_section',
				array(
					'label' => __( 'Performance', 'uichemy' ),
					'tab'   => 'settings',
				)
			);
			$document->add_control(
				'uichemy_cache_disabled',
				array(
					'label'        => __( 'Don\'t cache this page', 'uichemy' ),
					'type'         => 'switcher',
					'return_value' => 'yes',
					'default'      => get_post_meta( $post_id, UiChemy_Cache_Settings::POST_META, true ) ? 'yes' : '',
					'description'  => __( 'Turn on if this page shows different content to each visitor.', 'uichemy' ),
				)
			);
			$document->add_control(
				'uichemy_cache_status',
				array(
					'type' => 'raw_html',
					'raw'  => esc_html( __( 'Page cache', 'uichemy' ) . ': ' . self::editor_status( $post_id ) ),
				)
			);
			$document->end_controls_section();
		}

		/**
		 * Elementor saved: copy the page setting to the post meta.
		 *
		 * @param object $document Elementor document.
		 * @param array  $data     Saved data.
		 * @return void
		 */
		public static function elementor_saved( $document, $data ) {
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
				return;
			}
			$post_id = (int) $document->get_main_id();
			if ( ! array_key_exists( 'uichemy_cache_disabled', $data['settings'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			if ( 'yes' === $data['settings']['uichemy_cache_disabled'] ) {
				update_post_meta( $post_id, UiChemy_Cache_Settings::POST_META, true );
			} else {
				delete_post_meta( $post_id, UiChemy_Cache_Settings::POST_META );
			}
		}

		/**
		 * The per-page switch changed: the drop-in's exclusion list holds its path,
		 * so rewrite the snapshot and clear the page.
		 *
		 * @param mixed  $meta_id  Meta ID(s).
		 * @param int    $post_id  Post.
		 * @param string $meta_key Key.
		 * @return void
		 */
		public static function on_override_change( $meta_id, $post_id, $meta_key ) {
			if ( UiChemy_Cache_Settings::POST_META !== $meta_key ) {
				return;
			}
			$config = UiChemy_Cache_Engine::config( true );
			if ( $config ) {
				UiChemy_Cache_Files::write_config( $config['enabled'] );
			}
			$link = get_permalink( $post_id );
			if ( $link ) {
				UiChemy_Cache_Clear::queue_urls( array( $link ) );
			}
		}
	}
}
