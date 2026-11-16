<?php
/**
 * The `uichemy-composer/uichemy-team` ability: bug reports and feature
 * requests from an AI agent to the UiChemy team, sent only on the user's yes.
 *
 * In Code Mode it is the class UiChemyTeam:
 *
 *   $draft = UiChemyTeam::reportBug(title: ..., steps: [...], expected: ..., actual: ...);
 *   // -> { confirm_token, sent: false, adds, ask_user }   nothing has left the site
 *   // the agent shows the user the report and asks; on a yes, in a NEW execute call:
 *   UiChemyTeam::send(confirm_token: "...");
 *
 * Consent is the design. Nothing leaves the site until the person has seen
 * the report and said yes in the chat:
 *
 *   - report-bug and request-feature only keep a draft on this site, for 24
 *     hours. They are read-only to the Code Mode gate (meta.actions[].readonly),
 *     so they also work in a dry run.
 *   - send is a write, so a dry run only previews it, and the confirm_token it
 *     needs is refused in the run that made it (UiChemy_Code_Mode_Dispatcher):
 *     a draft and its send are always two calls, with the answer, and the
 *     person, in between.
 *   - The draft returns only what is ADDED to the agent's own fields (the
 *     environment, and any field that was cleaned), never the report echoed
 *     back, so asking costs a handful of tokens and sending costs one line.
 *   - What is added is small and named: UiChemy, UiChemy Pro, WordPress and PHP
 *     versions, the builder and the locale. No site URL, no user, no content.
 *     Server paths in the agent's text are replaced before the draft is kept.
 *   - A draft is sent by the user who made it, once; at most MAX_PER_DAY a day.
 *
 * Where it is sent is ENDPOINT (the AI Website Creator's intake,
 * app/api/uichemy/reports), or the uichemy_team_report_endpoint filter. With
 * the filter returning '', send says so and keeps the draft.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Team_Ability' ) ) {

	/**
	 * Registers and routes uichemy-composer/uichemy-team.
	 */
	final class UiChemy_Team_Ability {

		const ABILITY_NAME = 'uichemy-composer/uichemy-team';

		const CATEGORY = 'uichemy-composer';

		/** Where reports go: the AI Website Creator's intake. The filter can change it per site. */
		const ENDPOINT = 'https://app.uichemy.com/api/uichemy/reports';

		/** Reports one site may send in a day. */
		const MAX_PER_DAY = 10;

		/** How long a draft waits for the user's answer. */
		const DRAFT_TTL = DAY_IN_SECONDS;

		const DRAFT_PREFIX = 'uich_team_draft_';

		const SENT_OPTION = 'uich_team_sent_today';

		/** Drafts one user keeps at a time: a new one past this drops the oldest. */
		const MAX_DRAFTS = 5;

		/** Longest text per field, in characters. */
		const MAX_TEXT = 4000;

		/** Longest script, in characters. */
		const MAX_SCRIPT = 20000;

		/** Most steps. */
		const MAX_STEPS = 30;

		/**
		 * Wire registration onto the Abilities API.
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
		}

		/**
		 * Register the ability.
		 */
		public static function register_ability() {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY_NAME ) ) {
				return;
			}

			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => 'UiChemy Team: report a bug or request a feature',
					'description'         => 'Tell the UiChemy team about a UiChemy bug or a missing feature (UiChemy is in beta, and these reports are how it gets fixed). report-bug or request-feature drafts it here and sends nothing. Show the user the report and ask; only if they say yes, call send with the confirm_token, in a new call.',
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'            => array(
								'type'        => 'string',
								'enum'        => wp_list_pluck( self::actions(), 'name' ),
								'description' => 'Which operation to run.',
							),
							'action_parameters' => array(
								'type'        => 'object',
								'description' => 'Parameters for the chosen action. See meta.actions.',
							),
						),
						'required'   => array( 'action' ),
					),
					'execute_callback'    => array( __CLASS__, 'execute' ),
					'permission_callback' => array( __CLASS__, 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'code_mode'    => array( 'class' => 'UiChemyTeam' ),
						'annotations'  => array(
							'title'       => 'UiChemy Team',
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'actions'      => self::actions(),
					),
				)
			);
		}

		/**
		 * Per-action schemas.
		 *
		 * @return array<int,array>
		 */
		private static function actions() {
			$client  = array(
				'type'        => 'string',
				'description' => 'The AI client and model you are, e.g. "Claude Code, claude-opus-5-5".',
			);
			return array(
				array(
					'name'        => 'report-bug',
					'readonly'    => true,
					'description' => 'Draft a bug report (feedback on an issue) for the UiChemy team: a UiChemy method that returned something wrong, failed with an unclear error, or crashed, when your call was right. Not for the user\'s own mistakes or yours. Never ask for or include the user\'s contact details. Sends nothing: show the user the report and ask, then send.',
					'parameters'  => array(
						'title'    => array(
							'type'        => 'string',
							'description' => 'One line: what is broken, e.g. "Page::grep misses matches inside Elementor buttons".',
						),
						'steps'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Exact steps to reproduce, in order: the calls with their arguments, so the team can rerun them.',
						),
						'expected' => array(
							'type'        => 'string',
							'description' => 'What should have happened.',
						),
						'actual'   => array(
							'type'        => 'string',
							'description' => 'What happened instead, with the exact error message or the wrong part of the result.',
						),
						'script'   => array(
							'type'        => 'string',
							'description' => 'The smallest Code Mode script that shows it, if you have one. Leave out site content the user would not want shared.',
						),
						'area'     => array(
							'type'        => 'string',
							'description' => 'Where it is, e.g. "Page::grep", "Code Mode interpreter", "Seo::auditPageSeo".',
						),
						'severity' => array(
							'type'        => 'string',
							'enum'        => array( 'blocker', 'major', 'minor' ),
							'description' => 'blocker: the task cannot be done. major: done only with a workaround. minor: everything else.',
						),
						'client'   => $client,
					),
					'required'    => array( 'title', 'steps', 'expected', 'actual' ),
				),
				array(
					'name'        => 'request-feature',
					'readonly'    => true,
					'description' => 'Draft a feature request (feedback, an idea) for the UiChemy team: something the user wanted that UiChemy cannot do, or does only the long way round. Never ask for or include the user\'s contact details. Sends nothing: show the user the request and ask, then send.',
					'parameters'  => array(
						'title'      => array(
							'type'        => 'string',
							'description' => 'One line: what UiChemy should be able to do.',
						),
						'need'       => array(
							'type'        => 'string',
							'description' => 'What exactly it would do: the method or setting you looked for, and what it would take and return.',
						),
						'why'        => array(
							'type'        => 'string',
							'description' => 'What the user was trying to get done when it was missing.',
						),
						'workaround' => array(
							'type'        => 'string',
							'description' => 'What you did instead, if anything, and what it cost (extra calls, manual steps).',
						),
						'client'     => $client,
					),
					'required'    => array( 'title', 'need', 'why' ),
				),
				array(
					'name'        => 'send',
					'description' => 'Send a drafted report to the UiChemy team. ONLY after the user has seen it and said yes in the chat; never in the call or script that made the draft.',
					'parameters'  => array(
						'confirm_token' => array(
							'type'        => 'string',
							'description' => 'The confirm_token report-bug or request-feature returned.',
						),
						'dry_run'       => array(
							'type'        => 'boolean',
							'description' => 'Check the draft, the destination and the daily limit without sending.',
						),
					),
					'required'    => array( 'confirm_token' ),
				),
			);
		}

		/**
		 * Same rule as every UiChemy ability.
		 *
		 * @return bool
		 */
		public static function permission_check() {
			if ( class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'permission_check' ) ) {
				return (bool) UiChemy_Abilities::permission_check();
			}
			return current_user_can( 'manage_options' );
		}

		/**
		 * Route one call.
		 *
		 * @param array $arguments { action, action_parameters? }.
		 * @return array|WP_Error
		 */
		public static function execute( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$action    = isset( $arguments['action'] ) ? (string) $arguments['action'] : '';
			$params    = isset( $arguments['action_parameters'] ) && is_array( $arguments['action_parameters'] ) ? $arguments['action_parameters'] : array();

			switch ( $action ) {
				case 'report-bug':
					return self::draft( 'bug', $params, array( 'title', 'steps', 'expected', 'actual' ), array( 'script', 'area', 'severity', 'client' ) );
				case 'request-feature':
					return self::draft( 'feature', $params, array( 'title', 'need', 'why' ), array( 'workaround', 'client' ) );
				case 'send':
					return self::send( $params );
			}
			return new WP_Error( 'uichemy_team_unknown_action', sprintf( 'Unknown action "%s". Use report-bug, request-feature or send.', $action ) );
		}

		/**
		 * Keep a draft and tell the agent what to ask.
		 *
		 * @param string $type     'bug' or 'feature'.
		 * @param array  $params   The agent's fields.
		 * @param array  $required Fields that must be there.
		 * @param array  $optional Fields that may be.
		 * @return array|WP_Error
		 */
		private static function draft( $type, array $params, array $required, array $optional ) {
			$report  = array( 'type' => $type );
			$changed = array();

			foreach ( array_merge( $required, $optional ) as $field ) {
				if ( ! array_key_exists( $field, $params ) || null === $params[ $field ] || '' === $params[ $field ] || array() === $params[ $field ] ) {
					if ( in_array( $field, $required, true ) ) {
						return new WP_Error( 'uichemy_team_missing', sprintf( '%s is required.', $field ) );
					}
					continue;
				}
				$value = self::clean_field( $field, $params[ $field ] );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				if ( $value !== $params[ $field ] ) {
					$changed[ $field ] = $value;
				}
				$report[ $field ] = $value;
			}
			$report['environment'] = self::environment();

			$token = wp_generate_password( 20, false, false );
			$key   = self::DRAFT_PREFIX . self::token_key( $token );
			set_transient(
				$key,
				array(
					'user'   => get_current_user_id(),
					'report' => $report,
				),
				self::DRAFT_TTL
			);
			// Drafting is not a write, so it is not budgeted: a loop could pile up
			// drafts. Each user keeps the latest few; older ones are dropped.
			$index_key = self::DRAFT_PREFIX . 'index_' . get_current_user_id();
			$index     = get_transient( $index_key );
			$index     = is_array( $index ) ? $index : array();
			$index[]   = $key;
			while ( count( $index ) > self::MAX_DRAFTS ) {
				delete_transient( array_shift( $index ) );
			}
			set_transient( $index_key, $index, self::DRAFT_TTL );

			$answer = array(
				'sent'          => false,
				'confirm_token' => $token,
				// Only what the agent did not write itself.
				'adds'          => array( 'environment' => $report['environment'] ),
				'ask_user'      => sprintf(
					'Nothing has been sent. Show the user this %s, what it adds and any field under "changed" (server paths and email addresses taken out), and ask whether to send it to the UiChemy team. Only if they say yes: UiChemyTeam::send(confirm_token: "%s") in a new execute call. If they want changes, draft it again. The draft expires in 24 hours.',
					'bug' === $type ? 'bug report' : 'feature request',
					$token
				),
			);
			if ( $changed ) {
				$answer['changed'] = $changed;
			}
			return $answer;
		}

		/**
		 * Send a draft the user agreed to.
		 *
		 * @param array $params { confirm_token }.
		 * @return array|WP_Error
		 */
		private static function send( array $params ) {
			$token = isset( $params['confirm_token'] ) && is_string( $params['confirm_token'] ) ? trim( $params['confirm_token'] ) : '';
			$key   = self::DRAFT_PREFIX . self::token_key( $token );
			$draft = '' === $token ? false : get_transient( $key );
			if ( ! is_array( $draft ) || (int) ( $draft['user'] ?? 0 ) !== get_current_user_id() ) {
				return new WP_Error( 'uichemy_team_no_draft', 'There is no draft with that confirm_token (it expired after 24 hours, was sent already, or was made by another user). Draft it again.' );
			}
			// No email ever leaves this site: drop the contact field older versions
			// kept, and take any address out of the text of drafts made before this rule.
			unset( $draft['report']['contact'] );
			$draft['report'] = self::redact_emails( $draft['report'] );

			$endpoint = (string) apply_filters( 'uichemy_team_report_endpoint', self::ENDPOINT );
			if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) || 0 !== strpos( $endpoint, 'https://' ) ) {
				return new WP_Error( 'uichemy_team_not_set_up', 'Sending reports is not set up in this version of UiChemy yet, so nothing was sent. Tell the user; the draft is kept for 24 hours.' );
			}

			$today = gmdate( 'Y-m-d' );
			$sent  = get_option( self::SENT_OPTION );
			$count = is_array( $sent ) && ( $sent['day'] ?? '' ) === $today ? (int) $sent['count'] : 0;
			if ( $count >= self::MAX_PER_DAY ) {
				return new WP_Error( 'uichemy_team_limit', sprintf( 'This site has sent %d reports today, the most it can. Nothing was sent; try again tomorrow.', self::MAX_PER_DAY ) );
			}
			if ( ! empty( $params['dry_run'] ) ) {
				return array(
					'dry_run'    => true,
					'would_send' => array(
						'type'  => $draft['report']['type'],
						'title' => $draft['report']['title'],
					),
				);
			}

			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					// WordPress's default User-Agent carries the site's URL; a report promises none.
					'user-agent'  => 'UiChemy/' . ( defined( 'UICH_VERSION' ) ? UICH_VERSION : '' ),
					'headers'     => array( 'Content-Type' => 'application/json' ),
					'body'        => wp_json_encode( $draft['report'] ),
				)
			);
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error(
					'uichemy_team_send_failed',
					sprintf( 'The UiChemy team\'s server did not take the report (%s). Nothing was sent; the draft is kept, so send can be tried again later.', is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $status )
				);
			}

			delete_transient( $key );
			update_option(
				self::SENT_OPTION,
				array(
					'day'   => $today,
					'count' => $count + 1,
				),
				false
			);

			$answer = array(
				'sent'  => true,
				'type'  => $draft['report']['type'],
				'title' => $draft['report']['title'],
			);
			$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && isset( $body['id'] ) && is_scalar( $body['id'] ) ) {
				$answer['reference'] = (string) $body['id'];
			}
			return $answer;
		}

		/**
		 * One field, checked and with server paths taken out.
		 *
		 * @param string $field Name.
		 * @param mixed  $value Value.
		 * @return string|array|WP_Error
		 */
		private static function clean_field( $field, $value ) {
			if ( 'steps' === $field ) {
				if ( is_string( $value ) ) {
					$value = array( $value );
				}
				if ( ! is_array( $value ) || count( $value ) > self::MAX_STEPS ) {
					return new WP_Error( 'uichemy_team_bad_steps', sprintf( 'steps must be a list of at most %d strings.', self::MAX_STEPS ) );
				}
				$steps = array();
				foreach ( $value as $step ) {
					if ( ! is_string( $step ) ) {
						return new WP_Error( 'uichemy_team_bad_steps', 'Each step must be a string.' );
					}
					$step = self::clean_text( $field, $step, 1000 );
					if ( is_wp_error( $step ) ) {
						return $step;
					}
					$steps[] = $step;
				}
				return $steps;
			}
			if ( ! is_string( $value ) ) {
				return new WP_Error( 'uichemy_team_bad_field', sprintf( '%s must be a string.', $field ) );
			}
			if ( 'severity' === $field && ! in_array( $value, array( 'blocker', 'major', 'minor' ), true ) ) {
				return new WP_Error( 'uichemy_team_bad_field', 'severity must be blocker, major or minor.' );
			}
			$max = 'title' === $field ? 200 : ( 'script' === $field ? self::MAX_SCRIPT : self::MAX_TEXT );
			return self::clean_text( $field, $value, $max );
		}

		/**
		 * @param string $field Name, for the message.
		 * @param string $text  Text.
		 * @param int    $max   Most characters.
		 * @return string|WP_Error
		 */
		private static function clean_text( $field, $text, $max ) {
			$text = wp_check_invalid_utf8( $text, true );
			if ( mb_strlen( $text ) > $max ) {
				return new WP_Error( 'uichemy_team_too_long', sprintf( '%s is longer than %d characters. Keep only what reproduces it.', $field, $max ) );
			}
			// The server's own paths say where WordPress lives; the team needs none of them.
			$paths = array_filter( array_unique( array( WP_CONTENT_DIR, untrailingslashit( ABSPATH ) ) ) );
			usort(
				$paths,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$names = array();
			foreach ( $paths as $path ) {
				$names[] = WP_CONTENT_DIR === $path ? '<wp-content>' : '<wordpress>';
			}
			return self::redact_emails( str_replace( $paths, $names, $text ) );
		}

		/**
		 * Email addresses out: reports never carry one, even inside the text.
		 *
		 * @param mixed $value A string, or a list of them.
		 * @return mixed
		 */
		private static function redact_emails( $value ) {
			if ( is_array( $value ) ) {
				return array_map( array( __CLASS__, 'redact_emails' ), $value );
			}
			if ( ! is_string( $value ) || false === strpos( $value, '@' ) ) {
				return $value;
			}
			return (string) preg_replace( '/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '<email>', $value );
		}

		/**
		 * What a report adds: versions only, nothing that names the site.
		 *
		 * @return array
		 */
		private static function environment() {
			$builder = class_exists( 'Uich_ND_Settings' ) && method_exists( 'Uich_ND_Settings', 'get_builder' ) ? Uich_ND_Settings::get_builder() : '';
			$env     = array(
				'uichemy'   => defined( 'UICH_VERSION' ) ? UICH_VERSION : '',
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'locale'    => get_locale(),
			);
			if ( defined( 'UICH_PRO_VERSION' ) ) {
				$env['uichemy_pro'] = UICH_PRO_VERSION;
			}
			if ( '' !== $builder ) {
				$env['builder'] = $builder;
			}
			return $env;
		}

		/**
		 * The transient key for a token: its hash, so the token is not stored.
		 *
		 * @param string $token Token.
		 * @return string
		 */
		private static function token_key( $token ) {
			return substr( hash( 'sha256', (string) $token ), 0, 40 );
		}
	}
}
