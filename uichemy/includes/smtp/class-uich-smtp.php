<?php
/**
 * SMTP: send the site's mail through a real mail server (Settings > SMTP).
 *
 * Two mailers:
 *  - custom: any SMTP server (host, port, encryption, login).
 *  - gmail:  a Google account over XOAUTH2, connected with the site owner's own
 *            Google Cloud OAuth client (Uich_SMTP_Gmail_Token).
 *
 * Everything lives in one option, `uichemy_smtp`. The SMTP password, the Google
 * client secret and the refresh token are stored encrypted (Uich_SMTP_Secret) and
 * never leave the server: the settings screen only learns whether one is saved.
 * UICHEMY_SMTP_PASSWORD in wp-config.php overrides the stored password.
 * Either password is sent only to the server, port and login it was set up
 * for (see binding()); UICHEMY_SMTP_HOST names the host the wp-config one
 * may go to.
 *
 * Ported from Nexter Extension's SMTP Email, with the Gmail OAuth library swapped
 * for PHPMailer's own token-provider interface (no Composer dependency).
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SMTP' ) ) {

	/**
	 * Settings, the PHPMailer hook, the screen's AJAX and the Google callback.
	 */
	class Uich_SMTP {

		const OPTION       = 'uichemy_smtp';
		const AJAX_ACTION  = 'uichemy_smtp';
		const OAUTH_ACTION = 'uichemy_smtp_oauth';
		const NONCE        = 'uichemy_dashboard';
		const OAUTH_STATE  = 'uichemy_smtp_oauth_state';

		/** Option: changes whenever pending Google sign-ins must stop counting (Disconnect, import). */
		const OAUTH_EPOCH = 'uichemy_smtp_oauth_epoch';

		/** Seconds a Google sign-in may take from Connect to the callback. */
		const OAUTH_TTL = 600;

		/** Transient: the current Gmail access token and the account it belongs to. */
		const GMAIL_ACCESS = 'uichemy_smtp_gmail_access';

		/** Boot. Cheap on every request: the mailer hook reads the option only when mail is sent. */
		public static function init() {
			// After other plugins' phpmailer_init at 999 (Nexter's SMTP among them), so ours wins
			// when both are on. The screen warns about that case.
			add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 1000 );
			// wp_mail() sets the From BEFORE phpmailer_init, and its default (wordpress@<domain>)
			// is refused outright on some hosts (localhost) and by most providers. So the From
			// set here goes in through WordPress's own filters as well.
			add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from' ), 1000 );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 1000 );
			if ( is_admin() ) {
				add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax' ) );
				add_action( 'admin_post_' . self::OAUTH_ACTION, array( __CLASS__, 'oauth_callback' ) );
			}
		}

		/* ── Settings ─────────────────────────────────────────────────────── */

		/** @return array Default settings. */
		public static function defaults() {
			return array(
				'enabled'    => false,
				'mailer'     => 'custom',
				'from_email' => '',
				'from_name'  => '',
				'custom'     => array(
					'host'              => '',
					'port'              => 587,
					'encryption'        => 'tls',
					'auto_tls'          => true,
					'auth'              => true,
					'username'          => '',
					'password'          => '',
					'allow_self_signed' => false,
					// binding() of the server the stored password was entered for.
					'password_bind'     => '',
					// binding() of the server the wp-config password was first used with.
					'constant_bind'     => '',
				),
				'gmail'      => array(
					'client_id'     => '',
					'client_secret' => '',
					'refresh_token' => '',
					'email'         => '',
				),
			);
		}

		/**
		 * Stored settings over the defaults. Secrets stay encrypted here.
		 *
		 * @return array
		 */
		public static function settings() {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$d      = self::defaults();
			$out    = array_merge( $d, array_intersect_key( $stored, $d ) );
			foreach ( array( 'custom', 'gmail' ) as $k ) {
				$out[ $k ] = array_merge( $d[ $k ], isset( $stored[ $k ] ) && is_array( $stored[ $k ] ) ? array_intersect_key( $stored[ $k ], $d[ $k ] ) : array() );
			}

			// Settings saved before passwords were bound: the server they point at now
			// is the one the password was saved for, so they keep sending as before.
			$raw     = isset( $stored['custom'] ) && is_array( $stored['custom'] ) ? $stored['custom'] : array();
			$migrate = false;
			if ( ! array_key_exists( 'password_bind', $raw ) && '' !== (string) $out['custom']['host'] ) {
				$out['custom']['password_bind'] = self::binding( $out['custom'] );
				$migrate                        = true;
			}
			if ( '' !== self::constant_password() && '' === (string) $out['custom']['constant_bind'] && '' !== (string) $out['custom']['host'] ) {
				$out['custom']['constant_bind'] = self::binding( $out['custom'] );
				$migrate                        = true;
			}
			if ( $migrate && ! empty( $stored ) ) {
				update_option( self::OPTION, $out, false );
			}
			return $out;
		}

		/**
		 * The server, port and login a password is sent to.
		 *
		 * @param array $c Custom-mailer settings.
		 * @return string
		 */
		public static function binding( $c ) {
			return 'v2:' . md5( self::binding_subject( $c ) );
		}

		/**
		 * Host, port and login as one string.
		 *
		 * @param array $c Custom-mailer settings.
		 * @return string
		 */
		private static function binding_subject( $c ) {
			$port = isset( $c['port'] ) ? (int) $c['port'] : 587;
			return strtolower( trim( isset( $c['host'] ) ? (string) $c['host'] : '' ) ) . '|' . $port . '|' .
				( isset( $c['username'] ) ? (string) $c['username'] : '' );
		}

		/**
		 * Whether a recorded binding names these settings' server, port and login.
		 * A binding recorded in the earlier format (which also carried a transport
		 * flag) matches on the same server, port and login whatever that flag was.
		 *
		 * @param string $bound Recorded binding.
		 * @param array  $c     Custom-mailer settings.
		 * @return bool
		 */
		private static function binding_matches( $bound, $c ) {
			$bound = (string) $bound;
			if ( 0 === strpos( $bound, 'v2:' ) ) {
				return hash_equals( $bound, self::binding( $c ) );
			}
			$subject = self::binding_subject( $c );
			return hash_equals( $bound, md5( $subject . '|1' ) ) || hash_equals( $bound, md5( $subject . '|0' ) );
		}

		/**
		 * The wp-config password, or ''.
		 *
		 * @return string
		 */
		private static function constant_password() {
			return defined( 'UICHEMY_SMTP_PASSWORD' ) ? (string) UICHEMY_SMTP_PASSWORD : '';
		}

		/**
		 * Whether the wp-config password may be sent to these settings' server:
		 * the host UICHEMY_SMTP_HOST names, else the server it was first used with.
		 *
		 * @param array $c Custom-mailer settings.
		 * @return bool
		 */
		private static function constant_allowed( $c ) {
			if ( '' === self::constant_password() ) {
				return false;
			}
			if ( defined( 'UICHEMY_SMTP_HOST' ) && '' !== (string) UICHEMY_SMTP_HOST ) {
				return strtolower( trim( (string) UICHEMY_SMTP_HOST ) ) === strtolower( trim( (string) $c['host'] ) );
			}
			$bound = isset( $c['constant_bind'] ) ? (string) $c['constant_bind'] : '';
			return '' === $bound || self::binding_matches( $bound, $c );
		}

		/**
		 * Whether the stored password was saved for these settings' server.
		 *
		 * @param array $c Custom-mailer settings.
		 * @return bool
		 */
		private static function stored_allowed( $c ) {
			$bound = isset( $c['password_bind'] ) ? (string) $c['password_bind'] : '';
			return '' === $bound || self::binding_matches( $bound, $c );
		}

		/**
		 * The SMTP password in clear, wp-config override first. Empty when the
		 * password was set up for another server: it is entered again for a new one.
		 *
		 * @param array $custom Custom-mailer settings.
		 * @return string
		 */
		public static function password( $custom ) {
			if ( self::constant_allowed( $custom ) ) {
				return self::constant_password();
			}
			if ( ! self::stored_allowed( $custom ) ) {
				return '';
			}
			return Uich_SMTP_Secret::decrypt( isset( $custom['password'] ) ? $custom['password'] : '' );
		}

		/**
		 * What the settings screen may see: secrets reduced to "is one saved".
		 *
		 * @return array
		 */
		public static function view() {
			$s = self::settings();
			return array(
				'enabled'    => (bool) $s['enabled'],
				'mailer'     => $s['mailer'],
				'from_email' => $s['from_email'],
				'from_name'  => $s['from_name'],
				'custom'     => array(
					'host'              => $s['custom']['host'],
					'port'              => (int) $s['custom']['port'],
					'encryption'        => $s['custom']['encryption'],
					'auto_tls'          => (bool) $s['custom']['auto_tls'],
					'auth'              => (bool) $s['custom']['auth'],
					'username'          => $s['custom']['username'],
					'has_password'      => ( '' !== $s['custom']['password'] && self::stored_allowed( $s['custom'] ) ) || self::constant_allowed( $s['custom'] ),
					'password_constant' => defined( 'UICHEMY_SMTP_PASSWORD' ) && '' !== (string) UICHEMY_SMTP_PASSWORD,
					'allow_self_signed' => (bool) $s['custom']['allow_self_signed'],
				),
				'gmail'      => array(
					'client_id'         => $s['gmail']['client_id'],
					'has_client_secret' => '' !== $s['gmail']['client_secret'],
					'connected'         => '' !== $s['gmail']['refresh_token'] && '' !== $s['gmail']['email'],
					'email'             => $s['gmail']['email'],
					'redirect_uri'      => self::redirect_uri(),
				),
				'site_email' => get_option( 'admin_email' ),
				'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'nexter'     => self::nexter_state(),
				'override'   => self::mail_override(),
			);
		}

		/**
		 * The plugin that replaced wp_mail(), if one did (FluentSMTP, Post SMTP…).
		 *
		 * wp_mail() is pluggable. A plugin that defines its own may send without
		 * PHPMailer's phpmailer_init, and then the settings here do not apply.
		 *
		 * @return string Plugin name, or ''.
		 */
		public static function mail_override() {
			if ( ! function_exists( 'wp_mail' ) ) {
				return '';
			}
			try {
				$file = wp_normalize_path( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() );
			} catch ( \ReflectionException $e ) {
				return '';
			}
			if ( 0 === strpos( $file, wp_normalize_path( ABSPATH . WPINC ) ) ) {
				return '';
			}
			$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
			if ( 0 === strpos( $file, $plugins ) ) {
				$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ( get_plugins() as $path => $data ) {
					if ( 0 === strpos( $path, $slug . '/' ) && ! empty( $data['Name'] ) ) {
						return $data['Name'];
					}
				}
				return $slug;
			}
			return __( 'another plugin', 'uichemy' );
		}

		/**
		 * Merge a posted draft into the stored settings.
		 *
		 * A secret that is absent or null keeps the stored one; '' clears it.
		 *
		 * @param array $draft Posted settings.
		 * @param array $base  Settings to merge into.
		 * @return array|WP_Error Clean settings (secrets encrypted).
		 */
		public static function merge( $draft, $base ) {
			$draft = is_array( $draft ) ? $draft : array();
			$out   = $base;

			if ( array_key_exists( 'enabled', $draft ) ) {
				$out['enabled'] = ! empty( $draft['enabled'] );
			}
			if ( isset( $draft['mailer'] ) ) {
				$out['mailer'] = 'gmail' === $draft['mailer'] ? 'gmail' : 'custom';
			}
			if ( array_key_exists( 'from_email', $draft ) ) {
				$email = sanitize_email( (string) $draft['from_email'] );
				if ( '' !== trim( (string) $draft['from_email'] ) && ! is_email( $email ) ) {
					return new WP_Error( 'uich_smtp_from', __( 'The From email is not a valid email address.', 'uichemy' ) );
				}
				$out['from_email'] = $email;
			}
			if ( array_key_exists( 'from_name', $draft ) ) {
				$out['from_name'] = sanitize_text_field( (string) $draft['from_name'] );
			}

			if ( isset( $draft['custom'] ) && is_array( $draft['custom'] ) ) {
				$c   = $draft['custom'];
				$cur = $out['custom'];
				if ( array_key_exists( 'host', $c ) ) {
					// A host name or IP, nothing else: no scheme, path or spaces.
					$host = strtolower( trim( (string) $c['host'] ) );
					$host = preg_replace( '#^[a-z]+://#', '', $host );
					if ( '' !== $host && ! preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) ) {
						return new WP_Error( 'uich_smtp_host', __( 'The SMTP host should be a server name like smtp.example.com.', 'uichemy' ) );
					}
					$cur['host'] = $host;
				}
				if ( array_key_exists( 'port', $c ) ) {
					$port = absint( $c['port'] );
					if ( $port < 1 || $port > 65535 ) {
						return new WP_Error( 'uich_smtp_port', __( 'The SMTP port must be a number between 1 and 65535.', 'uichemy' ) );
					}
					$cur['port'] = $port;
				}
				if ( isset( $c['encryption'] ) ) {
					$cur['encryption'] = in_array( $c['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $c['encryption'] : 'tls';
				}
				foreach ( array( 'auto_tls', 'auth', 'allow_self_signed' ) as $flag ) {
					if ( array_key_exists( $flag, $c ) ) {
						$cur[ $flag ] = ! empty( $c[ $flag ] );
					}
				}
				if ( array_key_exists( 'username', $c ) ) {
					$cur['username'] = sanitize_text_field( (string) $c['username'] );
				}
				$was_bound = (string) $out['custom']['password_bind'];
				if ( array_key_exists( 'password', $c ) && null !== $c['password'] ) {
					// Passwords are not sanitized: any character is valid in one.
					$cur['password']      = Uich_SMTP_Secret::encrypt( (string) $c['password'] );
					$cur['password_bind'] = self::binding( $cur );
				} elseif ( '' === $was_bound && '' === (string) $out['custom']['host'] ) {
					// First setup: a password typed before the host belongs to the host now set.
					$cur['password_bind'] = self::binding( $cur );
				} elseif ( self::binding( $cur ) !== self::binding( $out['custom'] ) ) {
					// A stored password belongs to the server, port and login it was saved
					// for. A change to any of them needs the password entered again: it is
					// never sent anywhere it was not entered for.
					$cur['password']      = '';
					$cur['password_bind'] = '';
				} elseif ( '' !== $was_bound && self::binding_matches( $was_bound, $cur ) ) {
					// Same server: a binding in the earlier format is recorded in the current one.
					$cur['password_bind'] = self::binding( $cur );
				}
				// The wp-config password is bound on first use only (settings()); a later
				// change of server here does not move it.
				$cur['constant_bind'] = (string) $out['custom']['constant_bind'];
				$out['custom'] = $cur;
			}

			if ( isset( $draft['gmail'] ) && is_array( $draft['gmail'] ) ) {
				$g   = $draft['gmail'];
				$cur = $out['gmail'];
				$was = $cur['client_id'];
				if ( array_key_exists( 'client_id', $g ) ) {
					$cur['client_id'] = sanitize_text_field( (string) $g['client_id'] );
				}
				if ( array_key_exists( 'client_secret', $g ) && null !== $g['client_secret'] ) {
					$cur['client_secret'] = Uich_SMTP_Secret::encrypt( sanitize_text_field( (string) $g['client_secret'] ) );
				}
				// A token and a secret belong to the client that issued them.
				if ( $was !== $cur['client_id'] ) {
					if ( ! array_key_exists( 'client_secret', $g ) || null === $g['client_secret'] ) {
						$cur['client_secret'] = '';
					}
					$cur['refresh_token'] = '';
					$cur['email']         = '';
					self::forget_gmail_access();
				}
				$out['gmail'] = $cur;
			}

			return $out;
		}

		/* ── Sending ──────────────────────────────────────────────────────── */

		/**
		 * phpmailer_init: route wp_mail() through the configured server.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
		 * @return void
		 */
		public static function configure( $mailer ) {
			$s = self::settings();
			if ( empty( $s['enabled'] ) ) {
				return;
			}
			if ( 'gmail' === $s['mailer'] ) {
				self::configure_gmail( $mailer, $s );
			} else {
				self::configure_custom( $mailer, $s['custom'] );
				self::apply_from( $mailer, $s, $s['custom'] );
			}
		}

		/**
		 * The From address SMTP sends as, or '' to leave WordPress's.
		 *
		 * @param array $s Settings.
		 * @return string
		 */
		public static function from_email( $s ) {
			if ( empty( $s['enabled'] ) ) {
				return '';
			}
			if ( 'gmail' === $s['mailer'] ) {
				// Gmail sends as the connected account.
				return '' !== $s['gmail']['refresh_token'] && is_email( $s['gmail']['email'] ) ? $s['gmail']['email'] : '';
			}
			if ( '' === $s['custom']['host'] ) {
				return '';
			}
			if ( is_email( $s['from_email'] ) ) {
				return $s['from_email'];
			}
			// Most providers reject a From that is not the signed-in account.
			return ! empty( $s['custom']['auth'] ) && is_email( $s['custom']['username'] ) ? $s['custom']['username'] : '';
		}

		/**
		 * wp_mail_from.
		 *
		 * @param string $from Current From address.
		 * @return string
		 */
		public static function filter_from( $from ) {
			$email = self::from_email( self::settings() );
			return '' !== $email ? $email : $from;
		}

		/**
		 * wp_mail_from_name.
		 *
		 * @param string $name Current From name.
		 * @return string
		 */
		public static function filter_from_name( $name ) {
			return self::from_name( self::settings(), $name );
		}

		/**
		 * The From name: the one the email set itself, else the one set here.
		 *
		 * The name here is a default, not an override. A form's own From name, or
		 * the store name WooCommerce sends with, is kept; only WordPress's built-in
		 * "WordPress" (or no name) is replaced.
		 *
		 * @param array  $s    Settings.
		 * @param string $name The name the email has now.
		 * @return string
		 */
		public static function from_name( $s, $name ) {
			$name = (string) $name;
			if ( empty( $s['enabled'] ) || '' === $s['from_name'] ) {
				return $name;
			}
			return ( '' === trim( $name ) || 'WordPress' === $name ) ? $s['from_name'] : $name;
		}

		/**
		 * Point a mailer at the custom server.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
		 * @param array                          $c      Custom settings (password encrypted).
		 * @return bool False when there is no host.
		 */
		public static function configure_custom( $mailer, $c ) {
			if ( '' === (string) $c['host'] ) {
				return false;
			}
			$mailer->isSMTP();
			$mailer->Host    = $c['host'];
			$mailer->Port    = (int) $c['port'];
			$mailer->Timeout = 15;

			// 465 is implicit TLS (SMTPS) whatever was picked; 587 and 25 upgrade with STARTTLS.
			$enc                  = 465 === (int) $c['port'] ? 'ssl' : ( 'none' === $c['encryption'] ? '' : $c['encryption'] );
			$mailer->SMTPSecure  = $enc;
			$mailer->SMTPAutoTLS = 'ssl' === $enc ? false : ! empty( $c['auto_tls'] );

			$mailer->SMTPAuth = ! empty( $c['auth'] );
			if ( $mailer->SMTPAuth ) {
				$mailer->Username = (string) $c['username'];
				$mailer->Password = self::password( $c );
			}

			$mailer->SMTPOptions = self::tls_options( $c );
			return true;
		}

		/**
		 * The certificate is verified unless the site owner allowed self-signed ones.
		 *
		 * @param array $c Custom settings.
		 * @return array
		 */
		public static function tls_options( $c ) {
			$allow = ! empty( $c['allow_self_signed'] );
			/**
			 * Allow a self-signed SMTP certificate.
			 *
			 * @param bool  $allow Current choice.
			 * @param array $c     Custom settings.
			 */
			$allow = (bool) apply_filters( 'uichemy_smtp_allow_self_signed', $allow, $c );
			return array(
				'ssl' => array(
					'verify_peer'       => ! $allow,
					'verify_peer_name'  => ! $allow,
					'allow_self_signed' => $allow,
				),
			);
		}

		/**
		 * The From address: the one set here, else the login when it is an address.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
		 * @param array                          $s      Settings.
		 * @param array                          $c      Custom settings.
		 * @return void
		 */
		private static function apply_from( $mailer, $s, $c ) {
			$name  = self::from_name( $s, $mailer->FromName );
			$email = self::from_email( $s );
			if ( '' !== $email ) {
				try {
					$mailer->setFrom( $email, $name, false );
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- keep WordPress's From.
				}
			} else {
				$mailer->FromName = $name;
			}
		}

		/**
		 * Send through Gmail with XOAUTH2.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
		 * @param array                          $s      Settings.
		 * @return void
		 */
		private static function configure_gmail( $mailer, $s ) {
			$g = $s['gmail'];
			if ( '' === $g['refresh_token'] || '' === $g['email'] || '' === $g['client_id'] ) {
				return;
			}
			require_once __DIR__ . '/class-uich-smtp-gmail-token.php';
			if ( ! class_exists( 'Uich_SMTP_Gmail_Token' ) || ! method_exists( $mailer, 'setOAuth' ) ) {
				return;
			}
			$mailer->isSMTP();
			$mailer->Host        = 'smtp.gmail.com';
			$mailer->Port        = 587;
			$mailer->SMTPSecure  = 'tls';
			$mailer->SMTPAuth    = true;
			$mailer->AuthType    = 'XOAUTH2';
			$mailer->Timeout     = 15;
			$mailer->SMTPOptions = self::tls_options( array() );
			$mailer->setOAuth( new Uich_SMTP_Gmail_Token( $g['email'] ) );
			try {
				// Gmail sends as the connected account; only the name is free.
				$mailer->setFrom( $g['email'], self::from_name( $s, $mailer->FromName ), false );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		/* ── Screen (admin-ajax) ──────────────────────────────────────────── */

		/** Router for the settings screen. */
		public static function ajax() {
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to change SMTP settings.', 'uichemy' ) ), 403 );
			}
			$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$draft = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, each field sanitized in merge().

			switch ( $type ) {
				case 'get':
					wp_send_json_success( self::view() );
					break;
				case 'save':
					$next = self::merge( $draft, self::settings() );
					if ( is_wp_error( $next ) ) {
						wp_send_json_error( array( 'message' => $next->get_error_message() ), 400 );
					}
					update_option( self::OPTION, $next, false );
					wp_send_json_success( self::view() );
					break;
				case 'test_connection':
					self::ajax_test_connection( $draft );
					break;
				case 'send_test':
					self::ajax_send_test();
					break;
				case 'gmail_connect':
					self::ajax_gmail_connect( $draft );
					break;
				case 'gmail_disconnect':
					$s                            = self::settings();
					$s['gmail']['refresh_token']  = '';
					$s['gmail']['email']          = '';
					update_option( self::OPTION, $s, false );
					self::forget_gmail_access();
					// A sign-in started before Disconnect must not connect the account again.
					self::revoke_pending_oauth();
					wp_send_json_success( self::view() );
					break;
				case 'import_nexter':
					self::ajax_import_nexter();
					break;
				default:
					wp_send_json_error( array( 'message' => 'unknown type' ), 400 );
			}
		}

		/**
		 * Connect and sign in to the custom server with the form's values (saved or not).
		 *
		 * @param mixed $draft Posted settings.
		 * @return void
		 */
		private static function ajax_test_connection( $draft ) {
			$s = self::merge( $draft, self::settings() );
			if ( is_wp_error( $s ) ) {
				wp_send_json_error( array( 'message' => $s->get_error_message() ), 400 );
			}
			if ( '' === $s['custom']['host'] ) {
				wp_send_json_error( array( 'message' => __( 'Enter the SMTP host first.', 'uichemy' ) ), 400 );
			}
			if ( ! empty( $s['custom']['auth'] ) && '' !== self::constant_password() && ! self::constant_allowed( $s['custom'] ) && '' === self::password( $s['custom'] ) ) {
				wp_send_json_error( array( 'message' => __( 'The password in wp-config.php (UICHEMY_SMTP_PASSWORD) is used only with the server it was set up for. To use it with this server, also define UICHEMY_SMTP_HOST in wp-config.php.', 'uichemy' ) ), 400 );
			}
			if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}
			$mail = new \PHPMailer\PHPMailer\PHPMailer( true );
			try {
				self::configure_custom( $mail, $s['custom'] );
				$mail->Timeout = 10;
				// TCP, TLS, EHLO and, with auth on, the login. ErrorInfo says which step failed.
				if ( ! $mail->smtpConnect() ) {
					wp_send_json_error( array( 'message' => self::friendly_error( $mail->ErrorInfo ) ), 400 );
				}
				$mail->smtpClose();
			} catch ( \Exception $e ) {
				wp_send_json_error( array( 'message' => self::friendly_error( $e->getMessage() ) ), 400 );
			}
			wp_send_json_success(
				array(
					/* translators: %s: SMTP host name */
					'message' => sprintf( __( 'Connected to %s and signed in.', 'uichemy' ), $s['custom']['host'] ),
				)
			);
		}

		/** Send a test message through the SAVED settings, exactly as wp_mail() will. */
		private static function ajax_send_test() {
			$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ajax().
			if ( ! is_email( $to ) ) {
				wp_send_json_error( array( 'message' => __( 'Enter a valid email address to send the test to.', 'uichemy' ) ), 400 );
			}
			$s = self::settings();
			if ( empty( $s['enabled'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Turn on SMTP and save first.', 'uichemy' ) ), 400 );
			}

			$error   = '';
			$capture = function ( $wp_error ) use ( &$error ) {
				$error = $wp_error->get_error_message();
			};
			add_action( 'wp_mail_failed', $capture );
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$sent = wp_mail(
				$to,
				/* translators: %s: site name */
				sprintf( __( 'SMTP test from %s', 'uichemy' ), $site ),
				sprintf(
					'<p>%s</p><p>%s</p>',
					esc_html__( 'This test email was sent through your SMTP settings in UiChemy.', 'uichemy' ),
					/* translators: %s: mailer name */
					esc_html( sprintf( __( 'Mailer: %s', 'uichemy' ), 'gmail' === $s['mailer'] ? 'Gmail' : $s['custom']['host'] ) )
				),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
			remove_action( 'wp_mail_failed', $capture );

			if ( ! $sent ) {
				wp_send_json_error( array( 'message' => self::friendly_error( '' !== $error ? $error : __( 'The mail server did not accept the message.', 'uichemy' ) ) ), 400 );
			}
			/* translators: %s: email address */
			wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s.', 'uichemy' ), $to ) ) );
		}

		/**
		 * Plain words for PHPMailer's errors, original kept at the end.
		 *
		 * @param string $raw PHPMailer / server message.
		 * @return string
		 */
		private static function friendly_error( $raw ) {
			$raw  = trim( wp_strip_all_tags( (string) $raw ) );
			$hint = '';
			if ( preg_match( '/authenticat|535|534|username and password/i', $raw ) ) {
				$hint = __( 'The server refused the username or password.', 'uichemy' );
			} elseif ( preg_match( '/certificate|ssl3|SSL operation failed|verify/i', $raw ) ) {
				$hint = __( 'The server’s security certificate could not be verified.', 'uichemy' );
			} elseif ( preg_match( '/connect\(\)|Connection refused|timed out|getaddrinfo|Could not connect/i', $raw ) ) {
				$hint = __( 'Could not reach the server. Check the host and port, and that your host allows outgoing SMTP.', 'uichemy' );
			}
			return '' !== $hint ? $hint . ' (' . $raw . ')' : $raw;
		}

		/* ── Gmail OAuth ──────────────────────────────────────────────────── */

		/** @return string The redirect URI to register in Google Cloud. */
		public static function redirect_uri() {
			return admin_url( 'admin-post.php?action=' . self::OAUTH_ACTION );
		}

		/**
		 * Save the client, then hand back Google's consent URL.
		 *
		 * @param mixed $draft Posted settings.
		 * @return void
		 */
		private static function ajax_gmail_connect( $draft ) {
			$s = self::merge( $draft, self::settings() );
			if ( is_wp_error( $s ) ) {
				wp_send_json_error( array( 'message' => $s->get_error_message() ), 400 );
			}
			if ( '' === $s['gmail']['client_id'] || '' === $s['gmail']['client_secret'] ) {
				wp_send_json_error( array( 'message' => __( 'Enter the Client ID and Client secret first.', 'uichemy' ) ), 400 );
			}
			update_option( self::OPTION, $s, false );

			// One-use state bound to this user and this client, checked in the callback.
			$state = self::issue_oauth_state( $s['gmail']['client_id'] );

			$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(
				array(
					'client_id'     => $s['gmail']['client_id'],
					'redirect_uri'  => self::redirect_uri(),
					'response_type' => 'code',
					'scope'         => 'https://mail.google.com/ email',
					'access_type'   => 'offline',
					'prompt'        => 'consent',
					'state'         => $state,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
			wp_send_json_success( array( 'url' => $url ) );
		}

		/** admin-post: Google sends the browser back here with ?code&state. */
		public static function oauth_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				self::oauth_done( false, __( 'You are not allowed to change SMTP settings.', 'uichemy' ) );
			}
			$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is the CSRF check.
			$pending = self::consume_oauth_state( $state );
			if ( ! $pending ) {
				self::oauth_done( false, __( 'This sign-in link has expired. Start again from the SMTP settings.', 'uichemy' ) );
			}
			if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				self::oauth_done( false, __( 'Google sign-in was cancelled.', 'uichemy' ) );
			}
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $code ) {
				self::oauth_done( false, __( 'Google did not return an authorization code.', 'uichemy' ) );
			}

			$s   = self::settings();
			$res = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $s['gmail']['client_id'],
						'client_secret' => Uich_SMTP_Secret::decrypt( $s['gmail']['client_secret'] ),
						'redirect_uri'  => self::redirect_uri(),
						'grant_type'    => 'authorization_code',
					),
				)
			);
			$body = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
				$why = is_wp_error( $res ) ? $res->get_error_message() : ( isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google did not return a token.', 'uichemy' ) );
				self::oauth_done( false, $why );
			}

			$email = '';
			$info  = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ) ) );
			if ( ! is_wp_error( $info ) ) {
				$data  = json_decode( wp_remote_retrieve_body( $info ), true );
				$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			}
			if ( ! is_email( $email ) ) {
				self::oauth_done( false, __( 'Could not read the Google account’s email address.', 'uichemy' ) );
			}

			// Disconnected, or switched to another client, while Google answered: keep it that way.
			if ( ! self::oauth_still_pending( $pending ) ) {
				self::oauth_done( false, __( 'This sign-in link has expired. Start again from the SMTP settings.', 'uichemy' ) );
			}

			$s                           = self::settings();
			$s['gmail']['refresh_token'] = Uich_SMTP_Secret::encrypt( (string) $body['refresh_token'] );
			$s['gmail']['email']         = $email;
			update_option( self::OPTION, $s, false );
			$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3000;
			self::cache_gmail_access( (string) $body['access_token'], $ttl, $s['gmail'] );

			/* translators: %s: Google account email */
			self::oauth_done( true, sprintf( __( 'Connected as %s.', 'uichemy' ), $email ) );
		}

		/**
		 * The current sign-in generation ('' until the first Disconnect).
		 *
		 * @return string
		 */
		private static function oauth_epoch() {
			return (string) get_option( self::OAUTH_EPOCH, '' );
		}

		/**
		 * Make every Google sign-in started so far, by any user, unusable.
		 *
		 * @return void
		 */
		public static function revoke_pending_oauth() {
			update_option( self::OAUTH_EPOCH, wp_generate_password( 20, false ), false );
		}

		/**
		 * A new one-use sign-in state for the current user.
		 *
		 * @param string $client_id OAuth client the sign-in is for.
		 * @return string State to send to Google.
		 */
		public static function issue_oauth_state( $client_id ) {
			$state = wp_generate_password( 32, false );
			set_transient(
				self::OAUTH_STATE . '_' . get_current_user_id(),
				array(
					'state'  => $state,
					'epoch'  => self::oauth_epoch(),
					'client' => (string) $client_id,
					'at'     => time(),
				),
				self::OAUTH_TTL
			);
			return $state;
		}

		/**
		 * Check the state Google sent back and use it up, whatever the result.
		 *
		 * @param string $state State from the callback URL.
		 * @return array|false The pending sign-in, or false when it is unknown,
		 *                     expired, revoked or for another client.
		 */
		public static function consume_oauth_state( $state ) {
			$key      = self::OAUTH_STATE . '_' . get_current_user_id();
			$expected = get_transient( $key );
			delete_transient( $key );
			if ( is_string( $expected ) ) {
				// Started before this version: valid while nothing revoked it since.
				$expected = array(
					'state'  => $expected,
					'epoch'  => '',
					'client' => self::settings()['gmail']['client_id'],
					'at'     => time(),
				);
			}
			$state = (string) $state;
			if ( ! is_array( $expected ) || '' === $state || empty( $expected['state'] ) || ! hash_equals( (string) $expected['state'], $state ) ) {
				return false;
			}
			$expected = array_merge( array( 'epoch' => '', 'client' => '', 'at' => 0 ), $expected );
			if ( (int) $expected['at'] + self::OAUTH_TTL < time() ) {
				return false;
			}
			return self::oauth_still_pending( $expected ) ? $expected : false;
		}

		/**
		 * Whether a pending sign-in still belongs to the current connection.
		 *
		 * @param array $pending From consume_oauth_state().
		 * @return bool
		 */
		private static function oauth_still_pending( $pending ) {
			return hash_equals( self::oauth_epoch(), (string) $pending['epoch'] )
				&& '' !== (string) $pending['client']
				&& hash_equals( (string) self::settings()['gmail']['client_id'], (string) $pending['client'] );
		}

		/**
		 * Which Gmail account an access token belongs to.
		 *
		 * @param array $g Gmail settings.
		 * @return string
		 */
		private static function gmail_identity( $g ) {
			return md5( (string) $g['client_id'] . '|' . strtolower( trim( (string) $g['email'] ) ) );
		}

		/**
		 * Cache an access token for the account in $g.
		 *
		 * @param string $token Access token.
		 * @param int    $ttl   Seconds.
		 * @param array  $g     Gmail settings it was issued for.
		 * @return void
		 */
		public static function cache_gmail_access( $token, $ttl, $g ) {
			set_transient(
				self::GMAIL_ACCESS,
				array(
					'for'   => self::gmail_identity( $g ),
					'token' => Uich_SMTP_Secret::encrypt( (string) $token ),
				),
				(int) $ttl
			);
		}

		/**
		 * The cached access token, only when it was issued for the account in $g.
		 *
		 * @param array $g Gmail settings.
		 * @return string '' when there is none for this account (a fresh one is then fetched).
		 */
		public static function cached_gmail_access( $g ) {
			$c = get_transient( self::GMAIL_ACCESS );
			if ( ! is_array( $c ) || empty( $c['for'] ) || empty( $c['token'] ) || ! hash_equals( self::gmail_identity( $g ), (string) $c['for'] ) ) {
				return '';
			}
			return Uich_SMTP_Secret::decrypt( (string) $c['token'] );
		}

		/**
		 * Drop the cached access token.
		 *
		 * @return void
		 */
		public static function forget_gmail_access() {
			delete_transient( self::GMAIL_ACCESS );
		}

		/**
		 * End the popup: tell the settings screen (same origin only) and close.
		 *
		 * @param bool   $ok      Success.
		 * @param string $message What happened.
		 * @return void
		 */
		private static function oauth_done( $ok, $message ) {
			$payload = wp_json_encode( array( 'type' => 'uichemy-smtp-oauth', 'ok' => (bool) $ok, 'message' => (string) $message ) );
			$back    = admin_url( 'admin.php?page=uichemy#/settings' );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output inside <script>; it escapes "</" as "<\/".
			echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Gmail', 'uichemy' ) . '</title>'
				. '<p style="font:14px system-ui;margin:2em">' . esc_html( $message ) . '</p>'
				. '<script>(function(){var m=' . $payload . ';'
				. 'if(window.opener&&!window.opener.closed){window.opener.postMessage(m,window.location.origin);window.close();}'
				. 'else{window.location.replace(' . wp_json_encode( $back ) . ');}})();</script>';
			// phpcs:enable
			exit;
		}

		/* ── Nexter Extension ─────────────────────────────────────────────── */

		/**
		 * Nexter Extension's SMTP settings, if this site has them.
		 *
		 * @return array{found:bool,on:bool,mailer:string}
		 */
		public static function nexter_state() {
			$opt = get_option( 'nexter_extra_ext_options', array() );
			$opt = json_decode( wp_json_encode( $opt ), true );
			$v   = isset( $opt['smtp-email']['values'] ) && is_array( $opt['smtp-email']['values'] ) ? $opt['smtp-email']['values'] : array();
			$t   = isset( $v['type'] ) ? (string) $v['type'] : '';
			return array(
				'found'  => in_array( $t, array( 'custom', 'gmail' ), true ),
				'on'     => ! empty( $opt['smtp-email']['switch'] ) && in_array( $t, array( 'custom', 'gmail' ), true ) && class_exists( 'Nexter_Ext_SMTP_Email' ),
				'mailer' => $t,
			);
		}

		/** Copy Nexter's SMTP settings in. Saved straight away; the on/off switch is left as it is. */
		private static function ajax_import_nexter() {
			$opt = get_option( 'nexter_extra_ext_options', array() );
			$opt = json_decode( wp_json_encode( $opt ), true );
			$v   = isset( $opt['smtp-email']['values'] ) && is_array( $opt['smtp-email']['values'] ) ? $opt['smtp-email']['values'] : array();
			$t   = isset( $v['type'] ) ? (string) $v['type'] : '';
			if ( ! in_array( $t, array( 'custom', 'gmail' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'No Nexter SMTP settings were found.', 'uichemy' ) ), 400 );
			}
			// Nexter encrypts its secrets with its own key; read them through its class when it is active.
			$secret = function ( $bag, $key ) {
				$raw = isset( $bag[ $key ] ) ? (string) $bag[ $key ] : '';
				if ( 0 === strpos( $raw, 'nxtenc1:' ) ) {
					return class_exists( 'Nxt_Secret' ) ? (string) Nxt_Secret::decrypt( $raw ) : '';
				}
				return $raw;
			};

			$s           = self::settings();
			$s['mailer'] = $t;
			$missing     = false;
			if ( 'custom' === $t ) {
				$c          = isset( $v['custom'] ) && is_array( $v['custom'] ) ? $v['custom'] : array();
				$pw         = $secret( $c, 'password' );
				$missing    = ! empty( $c['auth'] ) && '' === $pw;
				$draft      = array(
					'from_email' => isset( $c['from_email'] ) ? $c['from_email'] : '',
					'from_name'  => isset( $c['from_name'] ) ? $c['from_name'] : '',
					'custom'     => array(
						'host'              => isset( $c['host'] ) ? $c['host'] : '',
						'port'              => isset( $c['port'] ) ? (int) $c['port'] : 587,
						'encryption'        => isset( $c['encryption'] ) ? $c['encryption'] : 'tls',
						'auto_tls'          => ! empty( $c['autoTLS'] ),
						'auth'              => ! empty( $c['auth'] ),
						'username'          => isset( $c['username'] ) ? $c['username'] : '',
						'password'          => '' !== $pw ? $pw : null,
						'allow_self_signed' => ! empty( $c['allow_self_signed'] ),
					),
				);
			} else {
				$csecret = isset( $v['gsecret_key'] ) ? (string) $v['gsecret_key'] : '';
				$draft   = array(
					'from_name' => isset( $v['name'] ) ? $v['name'] : '',
					'gmail'     => array(
						'client_id'     => isset( $v['gclient_id'] ) ? $v['gclient_id'] : '',
						'client_secret' => '' !== $csecret ? $csecret : null,
					),
				);
			}
			$next = self::merge( $draft, $s );
			if ( is_wp_error( $next ) ) {
				wp_send_json_error( array( 'message' => $next->get_error_message() ), 400 );
			}
			if ( 'gmail' === $t ) {
				// The refresh token keeps working with a new redirect URI: only the code exchange checks it.
				$refresh  = $secret( $v, 'refresh_token' );
				$in_email = isset( $v['email'] ) ? sanitize_email( (string) $v['email'] ) : '';
				$in_email = is_email( $in_email ) ? $in_email : '';
				if ( '' !== $refresh && '' !== $in_email ) {
					$next['gmail']['refresh_token'] = Uich_SMTP_Secret::encrypt( $refresh );
					$next['gmail']['email']         = $in_email;
				} else {
					$missing = true;
					// Keep the saved connection only when Nexter names the same mailbox or
					// has no Gmail connection of its own. Otherwise the saved credentials
					// belong to another account: Gmail is connected again instead.
					$nexter_connected = '' !== $in_email || ( isset( $v['refresh_token'] ) && '' !== (string) $v['refresh_token'] );
					$same_mailbox     = '' !== $in_email && strtolower( $in_email ) === strtolower( (string) $next['gmail']['email'] );
					if ( $nexter_connected && ! $same_mailbox ) {
						$next['gmail']['refresh_token'] = '';
						$next['gmail']['email']         = '';
					}
				}
				// The cached access token and any sign-in still open belong to the previous setup.
				self::forget_gmail_access();
				self::revoke_pending_oauth();
			}
			update_option( self::OPTION, $next, false );
			$view            = self::view();
			$view['message'] = $missing
				? __( 'Imported. Some secrets could not be read from Nexter: enter the password again (or reconnect Gmail).', 'uichemy' )
				: ( empty( $next['enabled'] )
					? __( 'Imported from Nexter Extension. Check the details, then turn on SMTP.', 'uichemy' )
					: __( 'Imported from Nexter Extension. Send a test email to check it.', 'uichemy' ) );
			wp_send_json_success( $view );
		}
	}
}
