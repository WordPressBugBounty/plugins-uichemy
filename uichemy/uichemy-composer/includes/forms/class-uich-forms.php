<?php
/**
 * Uich_Forms — turns any <form data-atom-form="key"> in widget output into a managed form.
 *
 * On render we inject the REST endpoint, a nonce and a honeypot, and mark the form for the
 * front-end submit handler. On submit (REST) we validate (nonce, honeypot, rate-limit), save
 * to the DB, and run the email action. Field name="" attributes are the keys.
 *
 * There is deliberately NO submit-timing check: this text and the shipped skill both used to
 * claim one, which sent agents and docs on to repeat a protection that was never implemented.
 * Adding one would also refuse legitimate fast submits (autofill, a returning visitor), so the
 * claim was removed rather than the behaviour invented.
 *
 * No Twig needed for forms — forms are INPUT; Twig is OUTPUT. (Twig may still pre-fill a field's
 * value, e.g. value="{{ request.get('email') }}".)
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Forms' ) ) {
	class Uich_Forms {

		const NONCE_ACTION = 'uich_atom_form';
		const REST_NS      = 'uichemy/v1';
		const REST_ROUTE   = '/atom-form';
		const SCHEMA_FIELD = '_uich_schema';

		private static $runtime_printed = false;

		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
			// The same submit through admin-ajax, for sites whose security plugin
			// closes the REST API to visitors (M-03).
			add_action( 'wp_ajax_uich_form_submit', array( __CLASS__, 'ajax_submit' ) );
			add_action( 'wp_ajax_nopriv_uich_form_submit', array( __CLASS__, 'ajax_submit' ) );
		}

		/**
		 * admin-ajax entry for a form submission: the REST handler, unchanged.
		 *
		 * @return void
		 */
		public static function ajax_submit() {
			$request = new WP_REST_Request( 'POST', '/uichemy/v1/form-submit' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The handler verifies _uich_nonce itself.
			$request->set_body_params( wp_unslash( $_POST ) );
			$request->set_file_params( $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above; files are checked in handle_uploads().
			$response = self::handle_submit( $request );
			wp_send_json( $response instanceof WP_REST_Response ? $response->get_data() : $response );
		}

		/**
		 * Create the form nonce in a logged-out (user id 0) context.
		 *
		 * WP nonces are bound to the current user id. The form is rendered with the
		 * page's current user (e.g. a logged-in admin previewing the page), but on
		 * submit WordPress core forces the REST request to user id 0 whenever no
		 * `X-WP-Nonce` header is sent — which is exactly what our front-end runtime
		 * does. Creating the nonce as user id 0 here makes it match the user id 0
		 * context the nonce is verified in, so logged-in and logged-out visitors
		 * both pass. It is also cache-safe (the same token for everyone).
		 *
		 * @return string
		 */
		private static function create_form_nonce() {
			$current = get_current_user_id();
			if ( $current ) {
				wp_set_current_user( 0 );
			}
			$nonce = wp_create_nonce( self::NONCE_ACTION );
			if ( $current ) {
				wp_set_current_user( $current );
			}
			return $nonce;
		}

		/**
		 * Verify a form nonce in the same logged-out (user id 0) context it is
		 * created in by {@see create_form_nonce()}. Submissions normally already
		 * run as user id 0, but forcing it here keeps verification correct even if
		 * a future submission path arrives authenticated.
		 *
		 * @param string $nonce Nonce value from the submission.
		 * @return bool
		 */
		private static function verify_form_nonce( $nonce ) {
			$current = get_current_user_id();
			if ( $current ) {
				wp_set_current_user( 0 );
			}
			$valid = (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
			if ( $current ) {
				wp_set_current_user( $current );
			}
			return $valid;
		}

		/*
		---------------------------------------------------------------------
		 * Front-end: adopt forms in the rendered output
		 * ------------------------------------------------------------------- */

		/**
		 * Inject infrastructure into every <form data-atom-form> in $html.
		 *
		 * @param string $html      Widget output HTML.
		 * @param string $widget_id Elementor widget id.
		 * @param int    $post_id   Current post id.
		 * @param int    $config_id Post whose form settings apply, when it is not
		 *                          $post_id - the template a header / footer form
		 *                          lives in. 0 = $post_id.
		 * @return string
		 */
		public static function process_output( $html, $widget_id, $post_id, $config_id = 0 ) {
			if ( false !== strpos( $html, 'data-atom-form' ) && class_exists( 'Uich_DD_Enqueue' ) ) {
				// This output carries a managed form, so the message it prints after
				// submitting needs its styling. Queued here rather than site-wide:
				// a page with no form has nothing to style.
				Uich_DD_Enqueue::enqueue_public();
			}
			if ( false === strpos( $html, 'data-atom-form' ) ) {
				return $html;
			}

			$endpoint = esc_url( rest_url( self::REST_NS . self::REST_ROUTE ) );
			$nonce    = self::create_form_nonce();
			$wid      = esc_attr( $widget_id );
			$pid      = (int) $post_id;
			$cid      = (int) $config_id;

			$html = preg_replace_callback(
				'/<form\b([^>]*\bdata-atom-form\b[^>]*)>(.*?)<\/form>/is',
				function ( $m ) use ( $endpoint, $nonce, $wid, $pid, $cid ) {
					$attrs = $m[1];

					// Drop the editor-only marker that flags a form the composer
					// injected (used by removeForm to unwrap) — it has no meaning on
					// the front end and shouldn't leak into the page source.
					$attrs = preg_replace( '/\s*data-atom-form-wrap\s*=\s*(["\'])[^"\']*\1/i', '', $attrs );

					// Pull the form key from data-atom-form="...".
					$form_key = 'form';
					if ( preg_match( '/data-atom-form\s*=\s*["\']([^"\']+)["\']/i', $attrs, $km ) ) {
						$form_key = sanitize_key( $km[1] );
					}

					// Add our class + endpoint, keep the author's attributes.
					if ( preg_match( '/class\s*=\s*["\']([^"\']*)["\']/i', $attrs, $cm ) ) {
						$attrs = str_replace( $cm[0], 'class="' . $cm[1] . ' uich-atom-form"', $attrs );
					} else {
						$attrs .= ' class="uich-atom-form"';
					}
					$attrs .= ' data-uich-endpoint="' . $endpoint . '"';

					$hidden  = '<input type="hidden" name="_uich_form_key" value="' . esc_attr( $form_key ) . '">';
					$hidden .= '<input type="hidden" name="_uich_widget_id" value="' . $wid . '">';
					$hidden .= '<input type="hidden" name="_uich_post_id" value="' . $pid . '">';
					if ( $cid && $cid !== $pid ) {
						$hidden .= '<input type="hidden" name="_uich_config_id" value="' . $cid . '">';
					}
					$hidden .= '<input type="hidden" name="_uich_nonce" value="' . esc_attr( $nonce ) . '">';
					$hidden .= '<div class="uich-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><input type="text" name="_uich_hp" tabindex="-1" autocomplete="off"></div>';

					// Signed record of which fields are required / must be emails, so the REST
					// handler can enforce them server-side (the browser check alone is bypassable).
					// Always sent, even with no rules, so a submission WITHOUT a valid
					// one can be refused: leaving the field out skipped every check (I-02).
					$schema      = self::extract_schema( $m[2] );
					$schema_json = wp_json_encode( $schema );
					$hidden     .= '<input type="hidden" name="' . self::SCHEMA_FIELD . '" value="' . esc_attr( $schema_json ) . '">';
					$hidden     .= '<input type="hidden" name="' . self::SCHEMA_FIELD . '_sig" value="' . esc_attr( self::sign_schema( $schema_json, $form_key, $wid, $pid ) ) . '">';

					// Per-form config set by the Form tab (data-atom-* attributes) → travels to submit.
					if ( preg_match( '/data-atom-actions\s*=\s*["\']([^"\']*)["\']/i', $attrs, $am ) ) {
						$hidden .= '<input type="hidden" name="_uich_actions" value="' . esc_attr( $am[1] ) . '">';
					}
					if ( preg_match( '/data-atom-success\s*=\s*["\']([^"\']*)["\']/i', $attrs, $sm ) ) {
						$hidden .= '<input type="hidden" name="_uich_success" value="' . esc_attr( $sm[1] ) . '">';
					}
					if ( preg_match( '/data-atom-redirect\s*=\s*["\']([^"\']*)["\']/i', $attrs, $rm ) ) {
						$hidden .= '<input type="hidden" name="_uich_redirect" value="' . esc_attr( $rm[1] ) . '">';
					}
					// Map NON-sensitive data-atom-* form settings → hidden fields. Sensitive ones
					// (email recipients, webhook URL) are NOT emitted to page source — they're stored
					// server-side (post meta) and loaded at submit by post + form key. See save_config.
					$pass = array(
						'subject' => '_uich_subject',
					);
					foreach ( $pass as $attr => $field ) {
						if ( preg_match( '/data-atom-' . preg_quote( $attr, '/' ) . '\s*=\s*["\']([^"\']*)["\']/i', $attrs, $pm ) && '' !== $pm[1] ) {
							$hidden .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $pm[1] ) . '">';
						}
					}

					// Submission metadata (Elementor-parity): page URL + title for the entry record.
					$hidden .= '<input type="hidden" name="_uich_referer" value="' . esc_attr( get_permalink( $pid ) ) . '">';
					$hidden .= '<input type="hidden" name="_uich_page_title" value="' . esc_attr( get_the_title( $pid ) ) . '">';

					// Infrastructure goes at the END of the form, not the start: author CSS
					// like `.tabs>input:nth-of-type(2):checked~...` counts the form's own
					// direct inputs, and prepended hidden inputs shift every index.
					return '<form' . $attrs . '>' . $m[2] . $hidden . '</form>';
				},
				$html
			);

			self::print_runtime();
			return $html;
		}

		/** Print the tiny submit handler once per page. */
		private static function print_runtime() {
			if ( self::$runtime_printed ) {
				return;
			}
			self::$runtime_printed = true;
			add_action( 'wp_footer', array( __CLASS__, 'output_runtime_script' ), 99 );
		}

		public static function output_runtime_script() {
			?>
			<script>
			(function(){
				document.addEventListener('submit', function(e){
					var form = e.target;
					if(!form || !form.classList || !form.classList.contains('uich-atom-form')) return;
					e.preventDefault();
					// Never post a form the browser considers invalid. Forms marked
					// novalidate (or with their own JS validation) still reach this
					// handler, so check the constraints here before sending anything.
					if(form.checkValidity && !form.checkValidity()){
						if(form.reportValidity){ form.reportValidity(); }
						return;
					}
					var endpoint = form.getAttribute('data-uich-endpoint');
					var nonceEl = form.querySelector('[name="_uich_nonce"]');
					var msg = form.querySelector('.uich-form-message');
					if(!msg){ msg = document.createElement('div'); msg.className='uich-form-message'; form.appendChild(msg); }
					var btn = form.querySelector('[type="submit"]');
					if(btn){ btn.disabled = true; }
					// NOTE: do NOT send X-WP-Nonce — that triggers WP's wp_rest cookie-nonce
					// check and 403s logged-in users. Our own nonce travels in the _uich_nonce
					// body field and is verified server-side.
					var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var retried = false;
					// REST first; when the site refuses it to visitors (401/403 or not
					// JSON), the same submit through admin-ajax.
					var send = function(){
						var data = new FormData(form);
						return fetch(endpoint, { method:'POST', body:data }).then(function(r){
							if(r.status === 401 || r.status === 403 || r.status === 404){ throw 'rest'; }
							return r.json();
						}).catch(function(err){
							if(err !== 'rest' && !(err instanceof SyntaxError)){ throw err; }
							var fd = new FormData(form);
							fd.append('action', 'uich_form_submit');
							return fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
						}).then(function(res){
							// An expired nonce in a cached page: take the fresh one, try once more.
							if(res && !res.success && res.nonce && !retried && nonceEl){
								retried = true;
								nonceEl.value = res.nonce;
								return send();
							}
							return res;
						});
					};
					send().then(function(res){
						if(btn){ btn.disabled = false; }
						msg.textContent = (res && res.message) ? res.message : ((res && res.success) ? 'Thank you!' : 'Something went wrong.');
						msg.className = 'uich-form-message ' + ((res && res.success) ? 'is-success' : 'is-error');
						if(res && res.success){
							if(res.redirect){ window.location.href = res.redirect; }
							else { form.reset(); }
						}
					}).catch(function(){
						if(btn){ btn.disabled = false; }
						msg.textContent = 'Network error. Please try again.';
						msg.className = 'uich-form-message is-error';
					});
				}, false);
			})();
			</script>
			<?php
		}

		/*
		---------------------------------------------------------------------
		 * REST: handle submissions
		 * ------------------------------------------------------------------- */

		public static function register_routes() {
			register_rest_route(
				self::REST_NS,
				self::REST_ROUTE,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_submit' ),
					'permission_callback' => '__return_true', // public forms; secured by nonce + honeypot + rate limit.
				)
			);
			// Editors save sensitive form config (email-to/from/reply/email2/webhook) server-side,
			// keyed by post + form — so it never appears in page source.
			register_rest_route(
				self::REST_NS,
				'/atom-form-config',
				array(
					'methods'             => array( 'GET', 'POST' ),
					'callback'            => array( __CLASS__, 'config_route' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' ); },
				)
			);
		}

		const CONFIG_META = '_uich_atom_forms';

		/** All forms' server-side config for a post: array( form_key => cfg ). */
		public static function get_config( $post_id, $form_key ) {
			$all = get_post_meta( (int) $post_id, self::CONFIG_META, true );
			$all = is_array( $all ) ? $all : array();
			return isset( $all[ $form_key ] ) ? $all[ $form_key ] : array();
		}

		public static function config_route( $request ) {
			$post_id  = (int) $request->get_param( 'post_id' );
			$form_key = sanitize_key( (string) $request->get_param( 'form_key' ) );
			if ( ! $post_id || '' === $form_key || ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_REST_Response( array( 'ok' => false ), 200 );
			}
			// GET → return current config so the Form tab can populate its fields.
			if ( 'GET' === $request->get_method() ) {
				return new WP_REST_Response(
					array(
						'ok'     => true,
						'config' => self::get_config( $post_id, $form_key ),
					),
					200
				);
			}
			$cfg              = array(
				'email_to'    => sanitize_email( (string) $request->get_param( 'email_to' ) ),
				'email_from'  => sanitize_text_field( (string) $request->get_param( 'email_from' ) ),
				'email_reply' => sanitize_email( (string) $request->get_param( 'email_reply' ) ),
				'email2'      => sanitize_email( (string) $request->get_param( 'email2' ) ),
				'webhook'     => esc_url_raw( (string) $request->get_param( 'webhook' ) ),
			);
			$all              = get_post_meta( $post_id, self::CONFIG_META, true );
			$all              = is_array( $all ) ? $all : array();
			$all[ $form_key ] = $cfg;
			update_post_meta( $post_id, self::CONFIG_META, $all );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		/**
		 * Store the files a visitor attached to a form.
		 *
		 * Only the types in `uich_form_upload_mimes` (images, PDF, plain text and
		 * Word documents by default — never SVG, HTML or scripts), checked by
		 * content as well as name (wp_handle_upload()), and no larger than
		 * `uich_form_upload_max_bytes` (5 MB). Files go under
		 * uploads/uichemy-forms/<random>/ with a random name prefix, so a
		 * submission's file cannot be found by guessing.
		 *
		 * @param array $files $_FILES-shaped array from the request.
		 * @return array|WP_Error Field key => file URL.
		 */
		private static function handle_uploads( $files ) {
			$out = array();
			if ( empty( $files ) || ! is_array( $files ) ) {
				return $out;
			}

			/**
			 * File types a form upload may be, as extension pattern => MIME type.
			 *
			 * @param array $mimes Allowed types.
			 */
			$mimes = (array) apply_filters(
				'uich_form_upload_mimes',
				array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'gif'          => 'image/gif',
					'webp'         => 'image/webp',
					'pdf'          => 'application/pdf',
					'txt'          => 'text/plain',
					'doc'          => 'application/msword',
					'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				)
			);
			/**
			 * Largest file a form accepts, in bytes.
			 *
			 * @param int $bytes Default 5 MB.
			 */
			$max = (int) apply_filters( 'uich_form_upload_max_bytes', 5 * MB_IN_BYTES );

			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$dir    = 'uichemy-forms/' . wp_generate_password( 20, false );
			$subdir = static function ( $uploads ) use ( $dir ) {
				$uploads['subdir'] = '/' . $dir;
				$uploads['path']   = $uploads['basedir'] . '/' . $dir;
				$uploads['url']    = $uploads['baseurl'] . '/' . $dir;
				return $uploads;
			};

			foreach ( $files as $key => $file ) {
				$key = sanitize_key( str_replace( array( ' ', '.' ), '_', (string) $key ) );
				if ( '' === $key || 0 === strpos( $key, '_uich_' ) || ! is_array( $file ) || ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
					continue;
				}
				if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
					continue;
				}
				if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > $max ) {
					return new WP_Error( 'uich_form_upload', __( 'A file could not be uploaded. Please check its size and try again.', 'uichemy' ) );
				}
				$file['name'] = wp_generate_password( 8, false ) . '-' . sanitize_file_name( (string) $file['name'] );

				add_filter( 'upload_dir', $subdir );
				$result = wp_handle_upload(
					$file,
					array(
						'test_form' => false,
						'mimes'     => $mimes,
					)
				);
				remove_filter( 'upload_dir', $subdir );

				if ( empty( $result['url'] ) || ! empty( $result['error'] ) ) {
					return new WP_Error( 'uich_form_upload', __( 'That type of file is not accepted.', 'uichemy' ) );
				}
				$out[ $key ] = esc_url_raw( $result['url'] );
			}

			return $out;
		}

		/**
		 * Where a submission came from: 'same' (this site), 'cross' (another
		 * site) or 'unknown' (the browser sent neither Origin nor Referer).
		 *
		 * The form nonce is a shared public token, so on its own it cannot tell
		 * a visitor's own submission from one another site makes their browser
		 * send. Browsers put Origin on every POST, and a page cannot forge it.
		 *
		 * @param WP_REST_Request $request The submission.
		 * @return string
		 */
		private static function request_origin( $request ) {
			$origin = (string) $request->get_header( 'origin' );
			if ( '' === $origin && isset( $_SERVER['HTTP_ORIGIN'] ) ) {
				$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
			}
			$source = $origin;
			if ( '' === $source ) {
				$source = (string) $request->get_header( 'referer' );
				if ( '' === $source && isset( $_SERVER['HTTP_REFERER'] ) ) {
					$source = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
				}
			}
			if ( '' === $source ) {
				return 'unknown';
			}
			// "null" is what a sandboxed frame or a data: page sends: never this site.
			$host = 'null' === strtolower( $source ) ? '' : strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
			$port = wp_parse_url( $source, PHP_URL_PORT );
			$host = $host && $port ? $host . ':' . $port : $host;

			$allowed = array();
			foreach ( array( home_url(), site_url() ) as $url ) {
				$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$p = wp_parse_url( $url, PHP_URL_PORT );
				if ( $h ) {
					$allowed[] = $p ? $h . ':' . $p : $h;
				}
			}
			/**
			 * Hosts form submissions may come from (a headless front end, a
			 * mapped domain). Same host:port form as above.
			 *
			 * @param string[] $allowed Allowed hosts.
			 */
			$allowed = (array) apply_filters( 'uich_form_allowed_hosts', array_unique( $allowed ) );

			return ( '' !== $host && in_array( $host, $allowed, true ) ) ? 'same' : 'cross';
		}

		public static function handle_submit( $request ) {
			$params = $request->get_params();

			// Another site's page posting through a visitor's browser: refused
			// before anything else, so it gets no fresh nonce either.
			$origin = self::request_origin( $request );
			if ( 'cross' === $origin ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Security check failed. Please refresh and try again.', 'uichemy' ),
					),
					403
				);
			}

			$nonce = isset( $params['_uich_nonce'] ) ? $params['_uich_nonce'] : '';
			if ( ! self::verify_form_nonce( $nonce ) ) {
				// A page cached for longer than a nonce lives carries an expired one
				// (J2-06). The nonce is the same public token for every visitor, so
				// handing a fresh one back costs nothing; the runtime retries once.
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Security check failed. Please refresh and try again.', 'uichemy' ),
						'nonce'   => self::create_form_nonce(),
					),
					200
				);
			}

			// Honeypot — pretend success, save nothing.
			if ( ! empty( $params['_uich_hp'] ) ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => __( 'Thank you!', 'uichemy' ),
					),
					200
				);
			}

			// Per-IP throttle — refuse scripted floods BEFORE they reach the DB save
			// and wp_mail() calls below. The nonce is a shared, cache-safe token, so
			// this (with the honeypot) is the real anti-automation gate. Returned as
			// 200 + success:false so the front-end shows the message inline, matching
			// every other validation response here. Tunable via `uich_form_rate_limit`.
			if ( ! self::within_rate_limit() ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Too many submissions. Please wait a moment and try again.', 'uichemy' ),
					),
					200
				);
			}

			// Real submitter id. The REST request is forced to user 0 (we send no
			// X-WP-Nonce), so get_current_user_id() is 0 here even for logged-in users.
			// Read it straight from the auth cookie so the stored user_id is accurate.
			// Only from a request this site's own page sent: the cookie rides along
			// on whatever reaches the server, so without a known origin it names
			// the browser's user, not the author of the submission.
			$user_id = get_current_user_id();
			if ( ! $user_id && 'same' === $origin ) {
				$cookie_uid = wp_validate_auth_cookie( '', 'logged_in' );
				if ( $cookie_uid ) {
					$user_id = (int) $cookie_uid;
				}
			}

			$form_key  = isset( $params['_uich_form_key'] ) ? sanitize_key( $params['_uich_form_key'] ) : 'form';
			$widget_id = isset( $params['_uich_widget_id'] ) ? sanitize_text_field( $params['_uich_widget_id'] ) : '';
			$post_id   = isset( $params['_uich_post_id'] ) ? (int) $params['_uich_post_id'] : 0;

			// Collect real fields (skip internal _uich_* keys). Sanitize each.
			$values = array();
			foreach ( $params as $key => $val ) {
				if ( 0 === strpos( $key, '_uich_' ) ) {
					continue;
				}
				if ( is_array( $val ) ) {
					$values[ $key ] = array_map( 'sanitize_text_field', $val );
				} elseif ( false !== strpos( $key, 'message' ) || false !== strpos( $key, 'content' ) ) {
					$values[ $key ] = sanitize_textarea_field( $val );
				} else {
					$values[ $key ] = sanitize_text_field( $val );
				}
			}

			// A submission whose fields are all blank is never worth storing, whatever the
			// form declares. (Keys are always present for a rendered form, so empty() on
			// $values alone would let an untouched form through.)
			if ( ! self::has_content( $values ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Please fill in the form.', 'uichemy' ),
					),
					200
				);
			}

			// Enforce the required / email rules recorded when the form was rendered.
			$schema = self::read_schema( $params, $form_key, $widget_id, $post_id );
			if ( null === $schema ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'This form is out of date. Please refresh the page and try again.', 'uichemy' ),
					),
					200
				);
			}
			$errors = self::validate_values( $values, $schema );

			/**
			 * Filter the validation errors for a submission (field key => error code:
			 * 'required' or 'email'). Return an empty array to accept the submission.
			 *
			 * @param array  $errors   Field key => error code.
			 * @param array  $values   Sanitized field key => value.
			 * @param string $form_key Form key.
			 */
			$errors = apply_filters( 'uich_form_validation_errors', $errors, $values, $form_key );
			if ( ! empty( $errors ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => in_array( 'email', $errors, true ) && ! in_array( 'required', $errors, true )
							? __( 'Please enter a valid email address.', 'uichemy' )
							: __( 'Please fill in all required fields.', 'uichemy' ),
						'errors'  => $errors,
					),
					200
				);
			}

			// File fields: stored, then recorded as the file's URL (I-01). A file
			// that cannot be accepted fails the whole submission, with a reason.
			$uploaded = self::handle_uploads( $request->get_file_params() );
			if ( is_wp_error( $uploaded ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $uploaded->get_error_message(),
					),
					200
				);
			}
			$values = array_merge( $values, $uploaded );

			// Per-form actions from the Form tab. When the _uich_actions field is present
			// (composer always emits it for a configured form) we honour it exactly — an
			// empty value means the author turned BOTH actions off, so nothing runs. Only
			// a totally absent field (legacy / hand-authored forms) falls back to the default.
			$actions = isset( $params['_uich_actions'] )
				? array_filter( array_map( 'trim', explode( ',', (string) $params['_uich_actions'] ) ) )
				: array( 'save_db', 'email' );

			$submission_id = 0;
			if ( in_array( 'save_db', $actions, true ) ) {
				$snapshot = array();
				foreach ( $values as $k => $v ) {
					$snapshot[] = array(
						'key'   => $k,
						'label' => self::humanize( $k ),
					);
				}
				$submission_id = Uich_Forms_DB::add(
					array(
						'form_key'        => $form_key,
						'form_name'       => self::humanize( $form_key ),
						'widget_id'       => $widget_id,
						'post_id'         => $post_id,
						'user_id'         => $user_id,
						// Visitor IPs and user agents are not kept. The columns stay
						// (older rows, schema), written empty. The rate limit only
						// holds a hash of the IP, briefly.
						'user_ip'         => '',
						'user_agent'      => '',
						'referer'         => isset( $params['_uich_referer'] ) ? esc_url_raw( $params['_uich_referer'] ) : '',
						'fields_snapshot' => wp_json_encode( $snapshot ),
						'meta'            => wp_json_encode(
							array(
								'page_title' => isset( $params['_uich_page_title'] ) ? sanitize_text_field( $params['_uich_page_title'] ) : '',
								'page_url'   => isset( $params['_uich_referer'] ) ? esc_url_raw( $params['_uich_referer'] ) : '',
							)
						),
					),
					$values
				);

				if ( ! $submission_id ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => __( 'Could not save submission.', 'uichemy' ),
						),
						200
					);
				}
			}

			// Run the configured actions, recording an Elementor-style actions log.
			$log = array();
			if ( $submission_id ) {
				$log[] = array(
					'name'   => 'collect_submissions',
					'label'  => __( 'Collect Submissions', 'uichemy' ),
					'status' => 'success',
				);
			}

			// Sensitive settings come from server-side config (not page source).
			$cfg = self::get_config( $post_id, $form_key );
			// A form in a header / footer template is configured on the template,
			// while _uich_post_id is the page it was submitted from. Fall back to
			// the template's settings - only a theme-builder template, so the field
			// cannot point a submission at any other post's recipients.
			$config_id = isset( $params['_uich_config_id'] ) ? (int) $params['_uich_config_id'] : 0;
			if ( empty( $cfg ) && $config_id && class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === get_post_type( $config_id ) ) {
				$cfg = self::get_config( $config_id, $form_key );
			}

			if ( in_array( 'email', $actions, true ) ) {
				$opts  = array(
					'subject' => isset( $params['_uich_subject'] ) ? sanitize_text_field( $params['_uich_subject'] ) : '',
					'to'      => ! empty( $cfg['email_to'] ) ? $cfg['email_to'] : '',
					'from'    => ! empty( $cfg['email_from'] ) ? $cfg['email_from'] : '',
					'reply'   => ! empty( $cfg['email_reply'] ) ? $cfg['email_reply'] : '',
				);
				$ok    = self::send_email( $form_key, $values, $post_id, $opts );
				$log[] = array(
					'name'   => 'email',
					'label'  => __( 'Email', 'uichemy' ),
					'status' => $ok ? 'success' : 'failed',
				);
			}

			if ( ! empty( $cfg['email2'] ) ) {
				$ok2   = self::send_email( $form_key, $values, $post_id, array( 'to' => $cfg['email2'] ) );
				$log[] = array(
					'name'   => 'email2',
					'label'  => __( 'Email 2', 'uichemy' ),
					'status' => $ok2 ? 'success' : 'failed',
				);
			}

			if ( ! empty( $cfg['webhook'] ) ) {
				$ok3   = self::run_webhook( $cfg['webhook'], $values, $form_key, $post_id );
				$log[] = array(
					'name'   => 'webhook',
					'label'  => __( 'Webhook', 'uichemy' ),
					'status' => $ok3 ? 'success' : 'failed',
				);
			}

			// Persist the actions log + counts onto the saved submission.
			if ( $submission_id && ! empty( $log ) ) {
				Uich_Forms_DB::update_actions_log( $submission_id, $log );
			}

			/**
			 * Fires after a submission passes validation — hook extra actions (CRM, Slack, etc.).
			 *
			 * @param int   $submission_id Submission id (0 when "save to database" is off).
			 * @param array $values        Field key => value.
			 * @param array $params        Raw params.
			 */
			do_action( 'uich_form_submitted', $submission_id, $values, $params );

			$success_msg = ( isset( $params['_uich_success'] ) && '' !== $params['_uich_success'] )
				? sanitize_text_field( $params['_uich_success'] )
				: __( 'Thank you! Your submission has been received.', 'uichemy' );
			$response    = array(
				'success' => true,
				'message' => $success_msg,
			);
			if ( isset( $params['_uich_redirect'] ) && '' !== $params['_uich_redirect'] ) {
				$response['redirect'] = esc_url_raw( $params['_uich_redirect'] );
			}

			$response = apply_filters( 'uich_form_response', $response, $form_key, $values );
			return new WP_REST_Response( $response, 200 );
		}

		/*
		---------------------------------------------------------------------
		 * Validation helpers
		 * ------------------------------------------------------------------- */

		/**
		 * Whether any submitted value is non-blank (arrays are checked recursively).
		 *
		 * @param array $values Sanitized field values.
		 * @return bool
		 */
		private static function has_content( $values ) {
			foreach ( (array) $values as $val ) {
				if ( is_array( $val ) ? self::has_content( $val ) : '' !== trim( (string) $val ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Read a form's controls and record which ones are required and which are emails.
		 *
		 * @param string $form_html Inner HTML of the <form>.
		 * @return array { required: string[], email: string[] } — request keys, as PHP parses them.
		 */
		private static function extract_schema( $form_html ) {
			$required = array();
			$email    = array();
			if ( ! preg_match_all( '/<(input|select|textarea)\b([^>]*)>/i', (string) $form_html, $controls, PREG_SET_ORDER ) ) {
				return array(
					'required' => array(),
					'email'    => array(),
				);
			}
			foreach ( $controls as $c ) {
				$attrs = $c[2];
				if ( ! preg_match( '/(?<![\w-])name\s*=\s*(["\'])(.*?)\1/i', $attrs, $nm ) ) {
					continue;
				}
				// PHP request keys drop "[...]" suffixes and turn spaces / dots into underscores.
				$key = str_replace( array( ' ', '.' ), '_', preg_replace( '/\[.*$/', '', $nm[2] ) );
				if ( '' === $key || 0 === strpos( $key, '_uich_' ) ) {
					continue;
				}
				$type = 'text';
				if ( 'input' === strtolower( $c[1] ) && preg_match( '/(?<![\w-])type\s*=\s*["\']?([a-z-]+)/i', $attrs, $tm ) ) {
					$type = strtolower( $tm[1] );
				}
				if ( in_array( $type, array( 'hidden', 'submit', 'button', 'reset', 'image', 'file' ), true ) ) {
					continue;
				}
				if ( preg_match( '/(?<![\w-])required(?![\w-])(?!\s*=\s*["\']?false)/i', $attrs ) ) {
					$required[ $key ] = true;
				}
				if ( 'email' === $type ) {
					$email[ $key ] = true;
				}
			}
			return array(
				'required' => array_keys( $required ),
				'email'    => array_keys( $email ),
			);
		}

		/**
		 * Signature tying a schema to one form, so a visitor cannot loosen it.
		 */
		private static function sign_schema( $schema_json, $form_key, $widget_id, $post_id ) {
			return wp_hash( $schema_json . '|' . $form_key . '|' . $widget_id . '|' . (int) $post_id, 'nonce' );
		}

		/**
		 * The schema posted with a submission, or null when it is absent or its signature is wrong.
		 */
		private static function read_schema( $params, $form_key, $widget_id, $post_id ) {
			$json = isset( $params[ self::SCHEMA_FIELD ] ) ? (string) $params[ self::SCHEMA_FIELD ] : '';
			$sig  = isset( $params[ self::SCHEMA_FIELD . '_sig' ] ) ? (string) $params[ self::SCHEMA_FIELD . '_sig' ] : '';
			if ( '' === $json || '' === $sig ) {
				return null;
			}
			if ( ! hash_equals( self::sign_schema( $json, $form_key, $widget_id, $post_id ), $sig ) ) {
				return null;
			}
			$schema = json_decode( $json, true );
			return is_array( $schema ) ? $schema : null;
		}

		/**
		 * Check values against a schema.
		 *
		 * @return array Field key => 'required' | 'email'.
		 */
		private static function validate_values( $values, $schema ) {
			$errors = array();
			if ( ! is_array( $schema ) ) {
				return $errors;
			}
			foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
				$val = $values[ $key ] ?? '';
				if ( is_array( $val ) ? ! self::has_content( $val ) : '' === trim( (string) $val ) ) {
					$errors[ $key ] = 'required';
				}
			}
			foreach ( (array) ( $schema['email'] ?? array() ) as $key ) {
				$val = $values[ $key ] ?? '';
				if ( ! is_array( $val ) && '' !== trim( (string) $val ) && ! is_email( trim( (string) $val ) ) ) {
					$errors[ $key ] = 'email';
				}
			}
			return $errors;
		}

		/*
		---------------------------------------------------------------------
		 * Helpers
		 * ------------------------------------------------------------------- */

		/**
		 * Send a submission email. $opts: to, subject, from (name), reply (reply-to email).
		 *
		 * @return bool wp_mail result.
		 */
		private static function send_email( $form_key, $values, $post_id, $opts = array() ) {
			$opts = wp_parse_args(
				$opts,
				array(
					'to'      => '',
					'subject' => '',
					'from'    => '',
					'reply'   => '',
				)
			);

			$to = '' !== $opts['to'] ? $opts['to'] : get_option( 'admin_email' );
			$to = apply_filters( 'uich_form_email_to', $to, $form_key );

			$subject = '' !== $opts['subject']
				? $opts['subject']
				: sprintf( /* translators: %s form name */ __( 'New submission: %s', 'uichemy' ), self::humanize( $form_key ) );
			$subject = apply_filters( 'uich_form_email_subject', $subject, $form_key );

			$lines = array();
			foreach ( $values as $k => $v ) {
				$lines[] = self::humanize( $k ) . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : $v );
			}
			$lines[] = '';
			$lines[] = __( 'Page:', 'uichemy' ) . ' ' . get_permalink( $post_id );
			$body    = apply_filters( 'uich_form_email_body', implode( "\n", $lines ), $form_key, $values );

			$headers = array();
			if ( '' !== $opts['from'] ) {
				$headers[] = 'From: ' . $opts['from'] . ' <' . get_option( 'admin_email' ) . '>';
			}
			if ( '' !== $opts['reply'] ) {
				$headers[] = 'Reply-To: ' . $opts['reply'];
			}

			return (bool) wp_mail( $to, $subject, $body, $headers );
		}

		/** POST the submission values to a webhook URL (Zapier/Make/n8n style). */
		private static function run_webhook( $url, $values, $form_key, $post_id ) {
			if ( ! $url ) {
				return false;
			}
			// The submission fields are spread onto the top level (email, name, …) so
			// services that read fields at the root — FluentCRM's incoming webhook,
			// most CRMs — find them directly. The structured keys (form/fields/…) are
			// merged last so they always win over a same-named field, and keep the
			// nested `fields` object intact for Zapier/Make/n8n consumers.
			$payload = array_merge(
				is_array( $values ) ? $values : array(),
				array(
					'form'      => $form_key,
					'fields'    => $values,
					'page_url'  => get_permalink( $post_id ),
					'submitted' => current_time( 'mysql' ),
				)
			);
			$res     = wp_safe_remote_post(
				$url,
				array(
					'timeout' => 8,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			);
			return ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) < 400;
		}

		private static function client_ip() {
			// phpcs:ignore
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			return apply_filters( 'uich_form_client_ip', $ip );
		}

		/**
		 * Per-IP submit throttle — the public endpoint's real anti-automation gate
		 * alongside the honeypot.
		 *
		 * The form nonce is a shared, cache-safe token (see create_form_nonce), so
		 * it does not tell a script apart from a browser: an attacker can harvest it
		 * once and replay it. Without a throttle, a scripted flood fans out into a
		 * DB row plus one or more wp_mail() calls PER request — a database + mail
		 * DoS and a spam vector. This is a fixed-window counter kept in a transient,
		 * bucketed by IP + time window, checked before any save or email runs.
		 *
		 * Both the window and the ceiling are filterable via `uich_form_rate_limit`
		 * for busy or shared-IP (NAT / CDN) sites; a `max` of 0 disables the gate.
		 *
		 * @return bool True if within the limit, false if the request should be refused.
		 */
		private static function within_rate_limit() {
			$ip = self::client_ip();
			if ( '' === $ip ) {
				return true; // Client not identifiable — fail open rather than block real users.
			}

			/**
			 * Filter the form submit rate limit.
			 *
			 * @param array $limits array{ window:int seconds, max:int submissions per window }.
			 */
			$limits = apply_filters(
				'uich_form_rate_limit',
				array(
					'window' => MINUTE_IN_SECONDS,
					'max'    => 5,
				)
			);

			$window = isset( $limits['window'] ) ? max( 1, (int) $limits['window'] ) : MINUTE_IN_SECONDS;
			$max    = isset( $limits['max'] ) ? (int) $limits['max'] : 5;

			if ( $max <= 0 ) {
				return true; // Throttle disabled via filter.
			}

			$bucket = (int) floor( time() / $window );
			$key    = 'uich_form_rl_' . md5( $ip . '|' . $bucket );
			$count  = (int) get_transient( $key );

			if ( $count >= $max ) {
				return false;
			}

			set_transient( $key, $count + 1, $window );
			return true;
		}

		private static function humanize( $key ) {
			return ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) );
		}
	}
}
