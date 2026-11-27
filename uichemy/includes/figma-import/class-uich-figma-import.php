<?php
/**
 * Figma import: pulls an AI page the site blocked from UiChemy and publishes it here,
 * running uichemy-api's publish steps (steps/publish.ts) on the plugin's own abilities.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** UiChemy API URL; override in wp-config.php for staging or local. */
if ( ! defined( 'UICH_FIGMA_IMPORT_API_URL' ) ) {
	define( 'UICH_FIGMA_IMPORT_API_URL', 'https://core.uichemy.com' );
}

if ( ! class_exists( 'Uich_Figma_Import' ) ) {

	/** REST routes, the background job and the publish steps. */
	class Uich_Figma_Import {

		const REST_NAMESPACE = 'uichemy/v2/figma-import';
		const JOB_PREFIX     = 'uich_figma_import_job_';
		// What a design's import has done so far, so a retry continues instead of creating again.
		const STATE_PREFIX = 'uich_figma_import_state_';
		const TIME_LIMIT   = 600;
		// The page create copies every image into the media library.
		const API_TIMEOUT = 60;

		// Page template per header/footer version; `leftOut`: a part is left to the site's own. Bricks needs none.
		const PAGE_TEMPLATES = array(
			'elementor' => array(
				'templates' => 'elementor_header_footer',
				'sections'  => 'elementor_canvas',
				'leftOut'   => 'elementor_header_footer',
			),
			'gutenberg' => array(
				'templates' => 'uichemy-canvas',
				'sections'  => 'uichemy-canvas',
				'leftOut'   => 'uichemy-full-width',
			),
			'composer'  => array(
				'templates' => 'uichemy-canvas',
				'sections'  => 'uichemy-canvas',
				'leftOut'   => 'uichemy-full-width',
			),
		);

		// The progress groups the dashboard shows, in order.
		const GROUPS = array( 'design', 'styles', 'header', 'page', 'finish' );

		/** @var array<int, array{job_id: string, secret: string}> */
		private $pending_jobs = array();

		/** @var string Transient key of the job running now ('' outside one). */
		private $job_key = '';

		/** @var Uich_Webpage_Auth */
		private $auth;

		public function __construct() {
			$this->auth = new Uich_Webpage_Auth();
		}

		public function init() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
			add_action( 'shutdown', array( $this, 'run_pending_jobs' ), 999 );
		}

		public function register_routes() {
			register_rest_route(
				self::REST_NAMESPACE,
				'/designs',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_designs' ),
					'permission_callback' => array( $this, 'can_import' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/designs/(?P<task_id>[A-Za-z0-9_-]{6,128})',
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_delete' ),
					'permission_callback' => array( $this, 'can_import' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/start',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_start' ),
					'permission_callback' => array( $this, 'can_import' ),
					'args'                => array(
						'task_id' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => array( __CLASS__, 'valid_task_id' ),
						),
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/job/(?P<job_id>[A-Za-z0-9]{32})',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_job' ),
					'permission_callback' => array( $this, 'can_import' ),
				)
			);
		}

		public function can_import() {
			return current_user_can( 'manage_options' );
		}

		public static function valid_task_id( $value ) {
			return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{6,128}$/', $value );
		}


		/** GET /designs: the user's designs that never reached their site. */
		public function rest_designs() {
			$res = $this->api( 'GET', '/express/wp-imports' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return rest_ensure_response(
				array(
					'designs' => isset( $res['designs'] ) && is_array( $res['designs'] ) ? $res['designs'] : array(),
					'builder' => self::site_builder(),
				)
			);
		}

		/** DELETE /designs/{task_id}: removes a design from the list; a page already made stays. */
		public function rest_delete( WP_REST_Request $request ) {
			$task_id = (string) $request->get_param( 'task_id' );
			$live    = get_transient( self::STATE_PREFIX . $task_id . '_job' );
			if ( is_string( $live ) && '' !== $live ) {
				$job = get_transient( self::JOB_PREFIX . $live );
				if ( is_array( $job ) && in_array( $job['status'], array( 'pending', 'running' ), true ) && time() - (int) $job['updated'] < 180 ) {
					return new WP_Error( 'import_running', __( 'This design is importing. Wait for it to finish, then delete it.', 'uichemy' ), array( 'status' => 409 ) );
				}
			}
			$res = $this->api( 'DELETE', '/express/wp-imports/' . rawurlencode( $task_id ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			delete_option( self::STATE_PREFIX . $task_id );
			delete_transient( self::STATE_PREFIX . $task_id . '_job' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		/** POST /start: queue the import; it runs after this response is sent. */
		public function rest_start( WP_REST_Request $request ) {
			$task_id = (string) $request->get_param( 'task_id' );

			// One import per design at a time: a second would make a second page.
			$live = get_transient( self::STATE_PREFIX . $task_id . '_job' );
			if ( is_string( $live ) && '' !== $live ) {
				$job = get_transient( self::JOB_PREFIX . $live );
				if ( is_array( $job ) && in_array( $job['status'], array( 'pending', 'running' ), true ) && time() - (int) $job['updated'] < 180 ) {
					return rest_ensure_response( array( 'job_id' => $live ) );
				}
			}

			$job_id = wp_generate_password( 32, false );
			$secret = wp_generate_password( 64, false );
			set_transient(
				self::JOB_PREFIX . $job_id,
				array(
					'status'   => 'pending',
					'secret'   => $secret,
					'task_id'  => $task_id,
					'groups'   => array_fill_keys( self::GROUPS, array( 'status' => 'pending' ) ),
					'result'   => null,
					'error'    => null,
					'created'  => time(),
					'updated'  => time(),
				),
				HOUR_IN_SECONDS
			);
			set_transient( self::STATE_PREFIX . $task_id . '_job', $job_id, HOUR_IN_SECONDS );
			$this->pending_jobs[] = array(
				'job_id' => $job_id,
				'secret' => $secret,
			);
			return rest_ensure_response( array( 'job_id' => $job_id ) );
		}

		/** GET /job/{id}: progress, then the result or the error. */
		public function rest_job( WP_REST_Request $request ) {
			$job = get_transient( self::JOB_PREFIX . $request->get_param( 'job_id' ) );
			if ( ! is_array( $job ) ) {
				return new WP_Error( 'job_not_found', __( 'This import has expired. Start it again.', 'uichemy' ), array( 'status' => 404 ) );
			}
			unset( $job['secret'] );
			return rest_ensure_response( $job );
		}


		public function run_pending_jobs() {
			if ( empty( $this->pending_jobs ) ) {
				return;
			}
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			$jobs               = $this->pending_jobs;
			$this->pending_jobs = array();
			foreach ( $jobs as $job ) {
				$this->run_job( $job['job_id'], $job['secret'] );
			}
		}

		private function run_job( $job_id, $secret ) {
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Images are copied into the media library during the import.
				set_time_limit( self::TIME_LIMIT );
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$key = self::JOB_PREFIX . $job_id;
			$job = get_transient( $key );
			if ( ! is_array( $job ) || empty( $job['secret'] ) || ! hash_equals( (string) $job['secret'], $secret ) || 'pending' !== $job['status'] ) {
				return;
			}
			$this->job_key = $key;
			$this->update_job( array( 'status' => 'running' ) );

			try {
				$result = $this->import( (string) $job['task_id'] );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'import_crashed', $e->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				$this->update_job(
					array(
						'status' => 'error',
						'error'  => $result->get_error_message(),
					)
				);
			} else {
				$this->update_job(
					array(
						'status' => 'complete',
						'result' => $result,
					)
				);
			}
			$this->job_key = '';
		}

		private function update_job( array $changes ) {
			if ( '' === $this->job_key ) {
				return;
			}
			$job = get_transient( $this->job_key );
			if ( ! is_array( $job ) ) {
				return;
			}
			$job            = array_merge( $job, $changes );
			$job['updated'] = time();
			set_transient( $this->job_key, $job, HOUR_IN_SECONDS );
		}

		/** Sets a progress group's status (running, done, warning or error) and note. */
		private function group( $group, $status, $note = '' ) {
			if ( '' === $this->job_key ) {
				return;
			}
			$job = get_transient( $this->job_key );
			if ( ! is_array( $job ) ) {
				return;
			}
			$job['groups'][ $group ] = array_filter(
				array(
					'status' => $status,
					'note'   => $note,
				)
			);
			$job['updated']          = time();
			set_transient( $this->job_key, $job, HOUR_IN_SECONDS );
		}


		/** Imports one design; a retry continues from saved state and never creates twice. */
		private function import( $task_id ) {
			$this->group( 'design', 'running' );
			$design = $this->api( 'GET', '/express/wp-imports/' . rawurlencode( $task_id ) );
			if ( is_wp_error( $design ) ) {
				$this->group( 'design', 'error', $design->get_error_message() );
				return $design;
			}
			$payload = isset( $design['payload'] ) && is_array( $design['payload'] ) ? $design['payload'] : array();
			$builder = isset( $design['builder'] ) ? sanitize_key( $design['builder'] ) : 'elementor';
			$page_only = isset( $design['importType'] ) && 'page' === $design['importType'];
			$title     = trim( (string) ( isset( $payload['title'] ) ? $payload['title'] : ( isset( $design['title'] ) ? $design['title'] : '' ) ) );
			$title     = '' !== $title ? $title : __( 'Figma design', 'uichemy' );

			// Checked before pinning: the abilities' actions were registered for the site's own mode.
			$mismatch = $this->builder_mismatch( $builder );
			if ( $mismatch ) {
				$this->group( 'design', 'error', $mismatch->get_error_message() );
				return $mismatch;
			}
			$this->pin_builder( $builder );

			$sections  = self::sections_of( isset( $payload['sections'] ) ? $payload['sections'] : array() );
			$document  = 'composer' === $builder ? self::document_of( isset( $payload['document'] ) ? $payload['document'] : null ) : null;
			$templates = self::templates_of( isset( $payload['templates'] ) ? $payload['templates'] : array() );
			if ( $document ? '' === trim( $document['html'] ) : empty( $sections ) ) {
				$error = new WP_Error( 'empty_design', __( 'The design has no sections to import.', 'uichemy' ) );
				$this->group( 'design', 'error', $error->get_error_message() );
				return $error;
			}
			$this->group( 'design', 'done' );

			$state_key = self::STATE_PREFIX . $task_id;
			$st        = get_option( $state_key, array() );
			$st        = is_array( $st ) ? $st : array();
			$save      = function () use ( $state_key, &$st ) {
				update_option( $state_key, $st, false );
			};

			// Site styles: the design's new globals, then its logo (Full Setup only).
			$this->group( 'styles', 'running' );
			$notes = array();
			if ( ! $page_only && empty( $st['globalsDone'] ) && ! empty( $payload['globals']['addCss'] ) ) {
				$added = $this->add_globals( (string) $payload['globals']['addCss'] );
				if ( is_wp_error( $added ) ) {
					// The page still looks the same: every variable it uses falls back to its own value.
					$notes[] = __( 'The colours and fonts could not be added to the site; the page keeps its own.', 'uichemy' );
				}
				$st['globalsDone'] = true;
				$save();
			}
			$logo = isset( $payload['logo'] ) && is_array( $payload['logo'] ) ? $payload['logo'] : null;
			if ( ! $page_only && empty( $st['logoDone'] ) && ! empty( $logo['url'] ) ) {
				$set = $this->ability(
					'platform',
					'set-site-settings',
					array(
						'logo_url'    => $logo['url'],
						'logo_width'  => isset( $logo['width'] ) ? $logo['width'] : null,
						'logo_height' => isset( $logo['height'] ) ? $logo['height'] : null,
					)
				);
				if ( is_wp_error( $set ) ) {
					$notes[] = __( 'The logo could not be set as the site logo.', 'uichemy' );
				}
				$st['logoDone'] = true;
				$save();
			}
			$this->group( 'styles', $notes ? 'warning' : 'done', implode( ' ', $notes ) );

			// Header and footer: the site's templates, or the page's first/last sections.
			$this->group( 'header', 'running' );
			$header_done = $this->header_footer( $st, $save, $templates, $title, $builder, $page_only );
			if ( is_wp_error( $header_done ) ) {
				$this->group( 'header', 'error', $header_done->get_error_message() );
				return $header_done;
			}
			$this->group( 'header', 'done', self::version_note( $st ) );

			$left_out = isset( $st['leftOut'] ) ? (array) $st['leftOut'] : array();
			$header   = self::kept( $templates, 'header', $left_out );
			$footer   = self::kept( $templates, 'footer', $left_out );
			$as_parts = 'sections' === $st['version'];
			if ( $as_parts && $templates ) {
				$sections = array_merge(
					$header ? array( self::as_section( $header ) ) : array(),
					$sections,
					$footer ? array( self::as_section( $footer ) ) : array()
				);
			}
			if ( $document ) {
				$parts = $as_parts ? array( $header, $footer ) : array( null, null );
				foreach ( array( 'html', 'css', 'js' ) as $k ) {
					$document[ $k ] = implode(
						'js' === $k ? "\n\n" : "\n",
						array_filter(
							array( $parts[0] ? $parts[0][ $k ] : '', $document[ $k ], $parts[1] ? $parts[1][ $k ] : '' ),
							function ( $v ) {
								return '' !== trim( $v );
							}
						)
					);
				}
			}
			if ( ! isset( $st['gsapSections'] ) ) {
				$st['gsapSections'] = array();
				foreach ( $sections as $i => $s ) {
					if ( preg_match( '/\bgsap\b|\bScrollTrigger\b/', $s['js'] ) ) {
						$st['gsapSections'][] = $i;
					}
				}
				$save();
			}

			$this->group( 'page', 'running' );
			if ( empty( $st['postId'] ) ) {
				if ( $document ) {
					$created = $this->ability( 'page', 'create', array_merge( array( 'title' => $title, 'status' => 'draft', 'upload_images' => true ), $document ) );
				} else {
					$created = $this->ability(
						'page',
						'create-with-sections',
						array(
							'title'            => $title,
							'status'           => 'draft',
							'builder'          => $builder,
							'upload_images'    => true,
							'sections'         => $sections,
							'page_before_head' => (string) ( isset( $payload['pageHead'] ) ? $payload['pageHead'] : '' ),
							'page_before_body' => (string) ( isset( $payload['pageBody'] ) ? $payload['pageBody'] : '' ),
						)
					);
				}
				if ( is_wp_error( $created ) ) {
					$this->group( 'page', 'error', $created->get_error_message() );
					return $created;
				}
				$data    = self::unwrap( $created );
				$post_id = (int) self::first( $data, array( 'post_id', 'id', 'ID' ) );
				if ( ! $post_id ) {
					$error = new WP_Error(
						'page_not_created',
						sprintf(
							/* translators: %s: page builder name */
							__( 'WordPress did not create the page. Check that UiChemy is set to %s, then try again.', 'uichemy' ),
							self::builder_label( $builder )
						)
					);
					$this->group( 'page', 'error', $error->get_error_message() );
					return $error;
				}
				$st['postId']        = $post_id;
				$st['previewUrl']    = (string) self::first( $data, array( 'preview_link', 'preview_url' ) );
				$st['editUrl']       = (string) self::first( $data, array( 'elementor_link', 'edit_link', 'edit_url' ) );
				$st['imageFailures'] = isset( $data['image_failures'] ) && is_array( $data['image_failures'] ) ? count( $data['image_failures'] ) : 0;
				$save();
			}
			// Motion scripts print before </body> (Elementor only; the others already print JS after GSAP).
			if ( empty( $st['placementDone'] ) && 'elementor' === $builder ) {
				foreach ( $st['gsapSections'] as $i ) {
					$this->ability(
						'performance',
						'set-post-sections-code-placement',
						array(
							'post_id'       => $st['postId'],
							'section_index' => $i,
							'type'          => 'js',
							'placement'     => 'before-body-end',
						)
					);
				}
				$st['placementDone'] = true;
				$save();
			}
			$this->group(
				'page',
				! empty( $st['imageFailures'] ) ? 'warning' : 'done',
				! empty( $st['imageFailures'] ) ? sprintf(
					/* translators: %d: number of images */
					_n( '%d image could not be copied to the media library; it still shows from its original address.', '%d images could not be copied to the media library; they still show from their original address.', $st['imageFailures'], 'uichemy' ),
					$st['imageFailures']
				) : ''
			);

			$this->group( 'finish', 'running' );
			$notes = array();
			if ( ! $page_only && empty( $st['menuDone'] ) && $templates ) {
				$menu = $this->make_menu( isset( $payload['menu'] ) && is_array( $payload['menu'] ) ? $payload['menu'] : array(), (int) $st['postId'] );
				if ( is_wp_error( $menu ) ) {
					$notes[] = __( 'The site menu could not be made from the design.', 'uichemy' );
				}
				$st['menuDone'] = true;
				$save();
			}
			$by_builder = isset( self::PAGE_TEMPLATES[ $builder ] ) ? self::PAGE_TEMPLATES[ $builder ] : null;
			$template   = $by_builder ? $by_builder[ $left_out ? 'leftOut' : $st['version'] ] : null;
			if ( empty( $st['templateSet'] ) && $template ) {
				$set = $this->set_page_template( (int) $st['postId'], $template );
				if ( is_wp_error( $set ) ) {
					// The page is there either way; it just shows inside the theme's own layout.
					$notes[] = __( "The page template could not be set; the page shows in the theme's layout.", 'uichemy' );
				} else {
					$st['templateSet'] = true;
				}
				$save();
			}

			$result = array(
				'postId'     => (int) $st['postId'],
				'title'      => $title,
				'previewUrl' => ! empty( $st['previewUrl'] ) ? $st['previewUrl'] : get_permalink( (int) $st['postId'] ),
				'editUrl'    => ! empty( $st['editUrl'] ) ? $st['editUrl'] : get_edit_post_link( (int) $st['postId'], 'raw' ),
				'headerFooter' => $st['version'],
			);
			// The conversion counts as published: the Figma plugin stops offering Continue.
			$done = $this->api(
				'POST',
				'/express/wp-imports/' . rawurlencode( $task_id ) . '/done',
				array(
					'siteUrl'    => home_url( '/' ),
					'postId'     => $result['postId'],
					'previewUrl' => self::web_url( $result['previewUrl'] ),
					'editUrl'    => self::web_url( $result['editUrl'] ),
				)
			);
			if ( is_wp_error( $done ) ) {
				$notes[] = __( 'The page is imported, but UiChemy could not be told; the design may still show as not published.', 'uichemy' );
			} else {
				delete_option( $state_key );
			}
			$this->group( 'finish', $notes ? 'warning' : 'done', implode( ' ', $notes ) );
			return $result;
		}

		/** Header/footer as site templates, or as page sections when the theme can't show them. */
		private function header_footer( array &$st, callable $save, array $templates, $title, $builder, $page_only ) {
			if ( empty( $st['version'] ) ) {
				if ( ! $templates ) {
					$st['version'] = 'sections';
				} elseif ( $page_only ) {
					// Page Only: the design's part goes on the page only where the site has no site-wide one.
					$existing = $this->existing( $st );
					if ( is_wp_error( $existing ) ) {
						return $existing;
					}
					$st['leftOut'] = array();
					foreach ( $templates as $t ) {
						foreach ( $existing as $e ) {
							if ( $e['kind'] === $t['kind'] ) {
								$st['leftOut'][] = $t['kind'];
								break;
							}
						}
					}
					$st['version'] = 'sections';
				} else {
					$existing = $this->existing( $st );
					if ( is_wp_error( $existing ) ) {
						return $existing;
					}
					$st['templates'] = isset( $st['templates'] ) ? $st['templates'] : array();
					foreach ( $templates as $t ) {
						if ( ! empty( $st['templates'][ $t['kind'] ]['postId'] ) ) {
							continue;
						}
						$made = $this->create_template( $t, $title, $builder );
						if ( is_wp_error( $made ) ) {
							return $made;
						}
						$st['templates'][ $t['kind'] ] = $made;
						$save();
					}
					$blocked = array();
					foreach ( $templates as $t ) {
						if ( isset( $st['templates'][ $t['kind'] ]['willRender'] ) && false === $st['templates'][ $t['kind'] ]['willRender'] ) {
							$blocked[] = $t['kind'];
						}
					}
					if ( $blocked ) {
						// The theme would not show them: switch ours off, use page sections instead.
						foreach ( $st['templates'] as $made ) {
							if ( ! empty( $made['postId'] ) ) {
								$this->ability( 'theme-builder', 'toggle', array( 'post_id' => (int) $made['postId'], 'status' => 'inactive' ) );
							}
						}
						$st['version']       = 'sections';
						$st['versionReason'] = 'theme';
					} else {
						$st['version'] = 'templates';
					}
				}
				$save();
			}

			// Switch off (never delete) the site-wide ones that were active before.
			if ( 'templates' === $st['version'] ) {
				$mine = array();
				foreach ( isset( $st['templates'] ) ? $st['templates'] : array() as $made ) {
					$mine[] = isset( $made['postId'] ) ? (int) $made['postId'] : 0;
				}
				$kinds = wp_list_pluck( $templates, 'kind' );
				foreach ( $st['existing'] as $i => $e ) {
					if ( ! empty( $e['switchedOff'] ) || in_array( (int) $e['postId'], $mine, true ) || ! in_array( $e['kind'], $kinds, true ) ) {
						continue;
					}
					$off = 'uichemy_native' === $e['system']
						? $this->ability( 'theme-builder', 'toggle', array( 'post_id' => (int) $e['postId'], 'status' => 'inactive' ) )
						: $this->ability( 'template', 'toggle', array( 'post_id' => (int) $e['postId'], 'active' => false ) );
					if ( ! is_wp_error( $off ) ) {
						$st['existing'][ $i ]['switchedOff'] = true;
						$save();
					}
				}
			}
			return true;
		}

		/** The site-wide headers/footers active before this import (UiChemy, Elementor Pro, Nexter). */
		private function existing( array &$st ) {
			if ( isset( $st['existing'] ) ) {
				return $st['existing'];
			}
			$schema = $this->ability( 'describe-site', 'schema', array() );
			$listed = $this->ability( 'theme-builder', 'list', array() );
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}
			$platform = self::unwrap( $schema );
			$platform = isset( $platform['platform'] ) && is_array( $platform['platform'] ) ? $platform['platform'] : array();
			$native   = is_wp_error( $listed ) ? array() : self::rows( self::unwrap( $listed ), 'templates' );
			$entire   = function ( $id ) use ( $native ) {
				foreach ( $native as $t ) {
					if ( (int) $t['id'] !== $id ) {
						continue;
					}
					$c = isset( $t['conditions'] ) && is_array( $t['conditions'] ) ? $t['conditions'] : array();
					if ( isset( $c['scope'] ) && 'entire' === $c['scope'] ) {
						return true;
					}
					foreach ( isset( $c['rules'] ) && is_array( $c['rules'] ) ? $c['rules'] : array() as $r ) {
						if ( isset( $r['type'] ) && 'entire' === $r['type'] && ( ! isset( $r['match'] ) || 'exclude' !== $r['match'] ) ) {
							return true;
						}
					}
				}
				return false;
			};
			$out = array();
			foreach ( array( 'header', 'footer' ) as $kind ) {
				foreach ( self::rows( $platform, 'active_' . $kind ) as $t ) {
					$system  = isset( $t['system'] ) ? (string) $t['system'] : '';
					$post_id = isset( $t['post_id'] ) ? (int) $t['post_id'] : 0;
					if ( ! $post_id ) {
						continue;
					}
					$conditions = isset( $t['conditions'] ) && is_array( $t['conditions'] ) ? array_map( 'strval', $t['conditions'] ) : array();
					// Only what shows on the whole site is replaced; a header for some pages stays.
					$site_wide = 'uichemy_native' === $system ? $entire( $post_id )
						: ( 'elementor_pro' === $system ? in_array( 'include/general', $conditions, true ) : 'nexter' === $system );
					if ( $site_wide ) {
						$out[] = array(
							'kind'   => $kind,
							'system' => $system,
							'postId' => $post_id,
						);
					}
				}
			}
			$st['existing'] = $out;
			return $out;
		}

		/** Creates one header/footer template, active on the entire site. */
		private function create_template( array $t, $title, $builder ) {
			$label  = '' !== $t['label'] ? $t['label'] : ( 'header' === $t['kind'] ? 'Header' : 'Footer' );
			$params = array(
				'type'          => $t['kind'],
				'title'         => $title . ' — ' . ( 'header' === $t['kind'] ? 'Header' : 'Footer' ),
				'label'         => $label,
				'html'          => $t['html'],
				'css'           => $t['css'],
				'js'            => $t['js'],
				'status'        => 'active',
				'placement'     => array( 'scope' => 'entire' ),
				'upload_images' => true,
			);
			// In Composer Builder mode every template is a document.
			if ( 'composer' !== $builder ) {
				$params['builder'] = $builder;
			}
			$res = $this->ability( 'theme-builder', 'create', $params );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$data    = self::unwrap( $res );
			$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'template_not_created', sprintf( 'The %s template was not created.', $t['kind'] ) );
			}
			return array(
				'postId'     => $post_id,
				'willRender' => ! ( isset( $data['will_render'] ) && false === $data['will_render'] ),
			);
		}

		/** Site menu from the design's nav when the site has none; links use page_id so they survive publishing. */
		private function make_menu( array $links, $post_id ) {
			$menus = $this->ability( 'platform', 'get-menu', array() );
			if ( is_wp_error( $menus ) ) {
				return $menus;
			}
			if ( self::rows( self::unwrap( $menus ), 'menus' ) ) {
				return false;
			}
			$base = home_url( '/?page_id=' . $post_id );
			$seen = array();
			$add  = array();
			foreach ( $links as $l ) {
				$name = isset( $l['title'] ) ? trim( (string) $l['title'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$href = isset( $l['href'] ) ? (string) $l['href'] : '#';
				$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
				$slug = '' !== $slug ? $slug : 'section';
				if ( preg_match( '#^https?://#i', $href ) ) {
					$url = $href;
				} elseif ( preg_match( '/^#[\w-]+$/', $href ) ) {
					$url = $base . $href;
				} else {
					$url = $base . '#' . $slug;
				}
				for ( $i = 2; isset( $seen[ $url ] ); $i++ ) {
					$url = $base . '#' . $slug . '-' . $i;
				}
				$seen[ $url ] = true;
				$add[]        = array(
					'title' => $name,
					'url'   => $url,
				);
			}
			if ( ! $add ) {
				return false;
			}
			$made = $this->ability(
				'platform',
				'update-menu',
				array(
					'menu_name' => 'Main Menu',
					'add_links' => $add,
				)
			);
			return is_wp_error( $made ) ? $made : true;
		}

		/** The page template, set the way the REST API sets it (it checks the template exists). */
		private function set_page_template( $post_id, $template ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
			$request->set_body_params( array( 'template' => $template ) );
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				return $response->as_error();
			}
			return true;
		}

		/** Adds only the design's new globals to the site's block (globals.ts addGlobals). */
		private function add_globals( $add_css ) {
			$current = $this->ability( 'design-system', 'get', array() );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$current = is_string( $current ) ? $current : ( isset( self::unwrap( $current )['css'] ) ? (string) self::unwrap( $current )['css'] : '' );
			$merged  = self::merge_globals( $current, $add_css );
			if ( null === $merged ) {
				return true;
			}
			$set = $this->ability( 'design-system', 'set', array( 'css' => $merged ) );
			return is_wp_error( $set ) ? $set : true;
		}

		/** The globals block with the design's new lines added, or null when nothing is new. */
		public static function merge_globals( $current, $add_css ) {
			$strip = function ( $css ) {
				return preg_replace( '#/\*.*?\*/#s', '', $css );
			};
			$have = array();
			preg_match_all( '/--([\w-]+)\s*:\s*([^;}]+);?/', $strip( $current ), $m, PREG_SET_ORDER );
			foreach ( $m as $d ) {
				$have[ $d[1] ] = trim( $d[2] );
			}
			$classes = array();
			preg_match_all( '/\.([\w-]+)\s*\{([^}]*)\}/', preg_replace( '/:root\s*\{[^}]*\}/', '', $strip( $current ) ), $m, PREG_SET_ORDER );
			foreach ( $m as $c ) {
				$classes[ $c[1] ] = $c[2];
			}
			$lines = array();
			$added = 0;
			foreach ( explode( "\n", $add_css ) as $line ) {
				if ( preg_match( '/^\s*\.([\w-]+)\s*\{([^}]*)\}\s*$/', $line, $c ) ) {
					if ( ! isset( $classes[ $c[1] ] ) ) {
						$lines[] = $line;
						++$added;
					}
					continue;
				}
				if ( ! preg_match( '/^\s*--([\w-]+)\s*:\s*([^;]+);/', $line, $d ) ) {
					$lines[] = $line;
					continue;
				}
				if ( ! isset( $have[ $d[1] ] ) ) {
					$lines[] = $line;
					++$added;
				}
				// Already there (same value, or another one the site keeps): not added again.
			}
			if ( ! $added ) {
				return null;
			}
			// A `:root` block left with nothing but its comments is dropped.
			$text = trim( preg_replace( '#:root\s*\{(?:\s|/\*.*?\*/)*\}\s*#s', '', implode( "\n", $lines ) ) );
			// No comment before `:root`: the Globals panel would read it as the selector and hide every variable.
			return rtrim( $current ) . ( '' !== trim( $current ) ? "\n\n" : '' ) . $text . "\n";
		}


		/** Runs one `uichemy-composer/<ability>` action the way the MCP endpoint does. */
		private function ability( $ability, $action, array $params ) {
			if ( ! class_exists( 'UiChemy_MCP_Server_V2' ) ) {
				return new WP_Error( 'mcp_unavailable', __( "UiChemy's site tools are not loaded on this site.", 'uichemy' ) );
			}
			$res = UiChemy_MCP_Server_V2::handle_execute(
				array(
					'name'      => 'uichemy-composer/' . $ability,
					'arguments' => array(
						'action'            => $action,
						'action_parameters' => $params,
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$data = self::unwrap( $res );
			if ( isset( $data['success'] ) && false === $data['success'] ) {
				return new WP_Error( 'ability_failed', isset( $data['message'] ) ? (string) $data['message'] : sprintf( '%s %s failed.', $ability, $action ) );
			}
			return $res;
		}

		/** As the API pins it: a Composer Builder design needs the plugin in its document mode. */
		private function pin_builder( $builder ) {
			if ( ! class_exists( 'UiChemy_Builder_Context' ) ) {
				return;
			}
			$request = new WP_REST_Request( 'POST', '/uichemy/v2/mcp' );
			if ( 'composer' === $builder ) {
				$request->set_param( UiChemy_Builder_Context::QUERY_ARG, 'composer' );
			}
			UiChemy_Builder_Context::prime_from_request( $request );
		}

		/** Why this site cannot take the design's builder, or null. */
		private function builder_mismatch( $builder ) {
			if ( ! class_exists( 'UiChemy_Builder_Context' ) ) {
				return new WP_Error( 'mcp_unavailable', __( "UiChemy's site tools are not loaded on this site.", 'uichemy' ) );
			}
			UiChemy_Builder_Context::reset();
			$ok = 'composer' === $builder
				? UiChemy_Builder_Context::is_composer_mode()
				: ! UiChemy_Builder_Context::is_composer_mode() && class_exists( 'UiChemy_Builder_Registry' ) && UiChemy_Builder_Registry::is_available( $builder );
			if ( $ok ) {
				return null;
			}
			return new WP_Error(
				'builder_mismatch',
				sprintf(
					/* translators: %s: page builder name */
					__( 'This design was made for %1$s. Install and select %1$s in UiChemy on this site, then import again.', 'uichemy' ),
					self::builder_label( $builder )
				)
			);
		}

		/** The builder UiChemy on this site is set to (for the dashboard to flag a mismatch early). */
		public static function site_builder() {
			if ( ! class_exists( 'UiChemy_Builder_Context' ) ) {
				return '';
			}
			UiChemy_Builder_Context::reset();
			return UiChemy_Builder_Context::is_composer_mode() ? 'composer' : UiChemy_Builder_Context::session_default();
		}


		/** A UiChemy API request with the account's JWT; a 401 renews it once. */
		private function api( $method, $path, $body = null ) {
			$send = function ( $bearer ) use ( $method, $path, $body ) {
				$args = array(
					'method'    => $method,
					'timeout'   => self::API_TIMEOUT,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $bearer,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'sslverify'   => function_exists( 'uich_webpage_http_sslverify' ) ? uich_webpage_http_sslverify() : true,
					// Never follow a redirect: it would carry the account token to another host.
					'redirection' => 0,
				);
				if ( null !== $body ) {
					$args['body'] = wp_json_encode( $body );
				}
				return wp_remote_request( untrailingslashit( UICH_FIGMA_IMPORT_API_URL ) . $path, $args );
			};

			$bearer = $this->auth->get_app_bearer();
			if ( ! $bearer ) {
				return new WP_Error( 'not_authenticated', __( 'Sign in to UiChemy first.', 'uichemy' ), array( 'status' => 401 ) );
			}
			$res = $send( $bearer );
			if ( ! is_wp_error( $res ) && 401 === wp_remote_retrieve_response_code( $res ) && $this->auth->renew_app_bearer() ) {
				$bearer = $this->auth->get_app_bearer();
				$res    = $bearer ? $send( $bearer ) : $res;
			}
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'api_unreachable', sprintf( /* translators: %s: error */ __( 'Could not reach UiChemy: %s', 'uichemy' ), $res->get_error_message() ), array( 'status' => 502 ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 401 === $code ) {
				return new WP_Error( 'not_authenticated', __( 'Your UiChemy session has expired. Please sign in again.', 'uichemy' ), array( 'status' => 401 ) );
			}
			// No JSON 404: the UiChemy server answering has no Figma import routes (an older deploy).
			if ( 404 === $code && ! is_array( $data ) ) {
				return new WP_Error( 'api_route_missing', __( 'The UiChemy server this site talks to does not support importing Figma designs yet.', 'uichemy' ), array( 'status' => 502 ) );
			}
			if ( $code >= 400 || ! is_array( $data ) ) {
				$message = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( /* translators: %d: HTTP status */ __( 'UiChemy answered with an error (%d).', 'uichemy' ), $code );
				return new WP_Error( 'api_error', $message, array( 'status' => 404 === $code ? 404 : 502 ) );
			}
			return $data;
		}


		/** An http(s) address, or null (the API stores only those). */
		private static function web_url( $url ) {
			return is_string( $url ) && preg_match( '#^https?://#i', $url ) ? $url : null;
		}

		private static function rows( $data, $key ) {
			if ( ! is_array( $data ) || ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				return array();
			}
			return array_values( array_filter( $data[ $key ], 'is_array' ) );
		}

		private static function sections_of( $raw ) {
			$out = array();
			foreach ( is_array( $raw ) ? $raw : array() as $r ) {
				if ( is_array( $r ) ) {
					$out[] = self::part( $r );
				}
			}
			return $out;
		}

		private static function templates_of( $raw ) {
			$out = array();
			foreach ( is_array( $raw ) ? $raw : array() as $r ) {
				if ( is_array( $r ) && isset( $r['kind'] ) && in_array( $r['kind'], array( 'header', 'footer' ), true ) ) {
					$out[] = array_merge( array( 'kind' => $r['kind'] ), self::part( $r ) );
				}
			}
			return $out;
		}

		private static function part( array $r ) {
			return array(
				'label' => isset( $r['label'] ) ? (string) $r['label'] : '',
				'html'  => isset( $r['html'] ) ? (string) $r['html'] : '',
				'css'   => isset( $r['css'] ) ? (string) $r['css'] : '',
				'js'    => isset( $r['js'] ) ? (string) $r['js'] : '',
			);
		}

		private static function document_of( $raw ) {
			if ( ! is_array( $raw ) ) {
				return null;
			}
			$doc = array();
			foreach ( array( 'html', 'css', 'js', 'head_code', 'body_end_code' ) as $k ) {
				$doc[ $k ] = isset( $raw[ $k ] ) ? (string) $raw[ $k ] : '';
			}
			return $doc;
		}

		private static function kept( array $templates, $kind, array $left_out ) {
			if ( in_array( $kind, $left_out, true ) ) {
				return null;
			}
			foreach ( $templates as $t ) {
				if ( $t['kind'] === $kind ) {
					return $t;
				}
			}
			return null;
		}

		private static function as_section( array $t ) {
			return array(
				'label' => $t['label'],
				'html'  => $t['html'],
				'css'   => $t['css'],
				'js'    => $t['js'],
			);
		}

		/** An ability's answer as an array, `data` unwrapped (page-lookup.ts unwrapMcpResponse). */
		private static function unwrap( $raw ) {
			if ( is_string( $raw ) ) {
				$raw = json_decode( $raw, true );
			}
			$data = is_array( $raw ) ? $raw : array();
			if ( isset( $data['data'] ) && is_array( $data['data'] ) && ! wp_is_numeric_array( $data['data'] ) ) {
				$data = $data['data'];
			}
			return $data;
		}

		private static function first( array $data, array $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $data[ $k ] ) && '' !== $data[ $k ] ) {
					return $data[ $k ];
				}
			}
			return '';
		}

		private static function version_note( array $st ) {
			if ( 'templates' === $st['version'] ) {
				return __( 'Added as site-wide header and footer.', 'uichemy' );
			}
			if ( ! empty( $st['leftOut'] ) ) {
				return __( "Your site's own header or footer is kept; the design's goes on the page where the site has none.", 'uichemy' );
			}
			if ( ! empty( $st['versionReason'] ) ) {
				return __( "Your theme can't show UiChemy headers and footers, so they were added to the page.", 'uichemy' );
			}
			return '';
		}

		private static function builder_label( $builder ) {
			$labels = array(
				'elementor' => 'Elementor',
				'gutenberg' => 'Gutenberg',
				'bricks'    => 'Bricks',
				'composer'  => 'Composer Builder',
			);
			return isset( $labels[ $builder ] ) ? $labels[ $builder ] : ucfirst( $builder );
		}
	}
}
