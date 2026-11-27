<?php
/**
 * Requests inbox: bookings and enquiries sent by AI agents.
 *
 * One built-in write tool, send_contact_request, switched off until the owner turns
 * the inbox on. Every request is a pending record the owner confirms or
 * rejects; nothing is booked, charged or published automatically. Fields are
 * typed and capped, HTML is stripped, and the owner's notification email has
 * fixed headers (no caller-controlled To, From, Reply-To or line breaks).
 *
 * Records are a private post type (never public, not searchable, no REST), so
 * they live with the site's other data, appear in WordPress's personal data
 * export and erase tools, and are not files on the public path.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Inbox' ) ) {

	/**
	 * Requests inbox.
	 */
	class Uich_Agent_Inbox {

		/**
		 * Private post type.
		 */
		const TYPE = 'uich_agent_request';

		/**
		 * Most pending requests one client may have open at once.
		 */
		const PENDING_PER_CLIENT = 3;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register' ) );
			add_filter( 'uichemy_agent_ready_builtin_tools', array( __CLASS__, 'tools' ) );
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
		}

		/**
		 * Register the private post type.
		 *
		 * @return void
		 */
		public static function register() {
			register_post_type(
				self::TYPE,
				array(
					'label'               => __( 'Agent requests', 'uichemy' ),
					'public'              => false,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_ui'             => false,
					'show_in_rest'        => false,
					'show_in_nav_menus'   => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'supports'            => array( 'title' ),
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
				)
			);
		}

		/**
		 * Whether the inbox is switched on.
		 *
		 * @return bool
		 */
		public static function enabled() {
			return (bool) Uich_Agent_Settings::value( 'mcp.inbox.enabled', false );
		}

		/**
		 * Add send_contact_request when the inbox is on.
		 *
		 * @param array $tools Built-in tools.
		 * @return array
		 */
		public static function tools( array $tools ) {
			if ( ! self::enabled() || ! Uich_Agent_Settings::writes_enabled() ) {
				return $tools;
			}
			// Every kind is always allowed; 'enquiry' is the catch-all for anything else.
			$kinds = array( 'booking', 'enquiry', 'quote', 'callback' );
			$tools['send_contact_request'] = array(
				'name'         => 'send_contact_request',
				'title'        => 'Send a contact request',
				'description'  => 'Send the business a booking request or an enquiry on the person\'s behalf. It is a REQUEST: the business confirms by phone or email. Ask the person for their name and a way to contact them, and tell them it is not confirmed yet.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'kind'    => array(
							'type' => 'string',
							'enum' => array_values( $kinds ),
						),
						'name'    => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'email'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'phone'   => array(
							'type'      => 'string',
							'maxLength' => 40,
						),
						'date'    => array(
							'type'        => 'string',
							'maxLength'   => 10,
							'description' => 'Preferred date, YYYY-MM-DD (bookings).',
						),
						'time'    => array(
							'type'        => 'string',
							'maxLength'   => 5,
							'description' => 'Preferred time, HH:MM 24-hour (bookings).',
						),
						'people'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'service' => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'message' => array(
							'type'      => 'string',
							'maxLength' => 1500,
						),
					),
					'required'   => array( 'kind', 'name' ),
				),
				'annotations'  => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				),
				'source'       => 'builtin',
				'write'        => true,
				'run'          => array( __CLASS__, 'run' ),
			);
			return $tools;
		}

		/**
		 * Tool: send_contact_request.
		 *
		 * @param array $args Checked arguments.
		 * @return array|WP_Error
		 */
		public static function run( array $args ) {
			// The pending-cap check and the insert run under one lock, so parallel
			// calls cannot all pass the check before any of them is saved.
			$result = Uich_Agent_Limiter::with_lock(
				'inbox',
				function () use ( $args ) {
					return self::run_locked( $args );
				}
			);
			// The owner email goes out after the lock is released, so a slow mailer
			// never holds up other visitors.
			if ( is_array( $result ) && isset( $result['_notify'] ) ) {
				self::notify( $result['_notify'][0], $result['_notify'][1] );
				unset( $result['_notify'] );
			}
			return $result;
		}

		/**
		 * send_contact_request, with the inbox lock held.
		 *
		 * @param array $args Checked arguments.
		 * @return array|WP_Error
		 */
		private static function run_locked( array $args ) {
			$email = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
			$phone = isset( $args['phone'] ) ? substr( preg_replace( '/[^\d+()\-\s]/', '', $args['phone'] ), 0, 40 ) : '';
			if ( '' === $email && '' === $phone ) {
				return new WP_Error( 'uich_agent_bad_args', 'Give an email address or a phone number so the business can reply.' );
			}
			if ( isset( $args['email'] ) && '' !== $args['email'] && ! is_email( $email ) ) {
				return new WP_Error( 'uich_agent_bad_args', '"email" is not a valid email address.' );
			}
			$date = isset( $args['date'] ) ? (string) $args['date'] : '';
			if ( '' !== $date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < wp_date( 'Y-m-d' ) ) ) {
				return new WP_Error( 'uich_agent_bad_args', '"date" must be today or later, as YYYY-MM-DD.' );
			}
			$time = isset( $args['time'] ) ? (string) $args['time'] : '';
			if ( '' !== $time && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
				return new WP_Error( 'uich_agent_bad_args', '"time" must be HH:MM, 24-hour.' );
			}

			$client = substr( hash_hmac( 'sha256', Uich_Agent_Urls::client_ip( (string) Uich_Agent_Settings::value( 'mcp.trusted_proxy', '' ) ), wp_salt( 'auth' ) ), 0, 16 );
			$open   = get_posts(
				array(
					'post_type'   => self::TYPE,
					'post_status' => 'private',
					'numberposts' => self::PENDING_PER_CLIENT + 1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- abuse cap.
						array(
							'key'   => '_client',
							'value' => $client,
						),
						array(
							'key'   => '_status',
							'value' => 'pending',
						),
					),
				)
			);
			if ( count( $open ) >= self::PENDING_PER_CLIENT ) {
				return new WP_Error( 'uich_agent_busy', 'There are already requests waiting from here. The business will reply to those first.' );
			}
			// A name made only of HTML (e.g. "<b></b>") is empty once cleaned.
			if ( '' === trim( sanitize_text_field( (string) $args['name'] ) ) ) {
				return new WP_Error( 'uich_agent_bad_name', 'Please give the name of the person making the request, as plain text.' );
			}
			/**
			 * Requests the whole site accepts per hour, from all visitors together.
			 *
			 * @param int $limit Limit (default 20).
			 */
			if ( ! Uich_Agent_Limiter::hit( 'inbox-site', '', (int) apply_filters( 'uichemy_agent_ready_inbox_hourly_limit', 20 ), 'hour' ) ) {
				return new WP_Error( 'uich_agent_busy', 'The business has received many requests this hour. Please try again later or contact them directly.' );
			}

			$fields = array(
				'kind'    => sanitize_key( $args['kind'] ),
				'name'    => mb_substr( sanitize_text_field( $args['name'] ), 0, 100 ),
				'email'   => $email,
				'phone'   => $phone,
				'date'    => $date,
				'time'    => $time,
				'people'  => isset( $args['people'] ) ? (int) $args['people'] : 0,
				'service' => isset( $args['service'] ) ? mb_substr( sanitize_text_field( $args['service'] ), 0, 120 ) : '',
				'message' => isset( $args['message'] ) ? mb_substr( sanitize_textarea_field( wp_strip_all_tags( $args['message'] ) ), 0, 1500 ) : '',
			);

			$id = wp_insert_post(
				array(
					'post_type'   => self::TYPE,
					'post_status' => 'private',
					'post_title'  => ucfirst( $fields['kind'] ) . ': ' . $fields['name'],
					'post_author' => 0,
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'uich_agent_inbox', 'The request could not be saved. Please contact the business directly.' );
			}
			foreach ( $fields as $key => $value ) {
				update_post_meta( $id, '_' . $key, $value );
			}
			update_post_meta( $id, '_status', 'pending' );
			update_post_meta( $id, '_client', $client );
			update_post_meta( $id, '_agent', isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '' );

			$p = class_exists( 'Uich_Agent_Profile' ) ? Uich_Agent_Profile::get() : array();
			return array(
				'received'  => true,
				'reference' => 'R' . $id,
				'status'    => 'pending',
				'note'      => 'Not confirmed yet: the business will reply by email or phone.' . ( ! empty( $p['contacts']['phone'] ) ? ' Urgent? Call ' . $p['contacts']['phone'] . '.' : '' ),
				'_notify'   => array( $id, $fields ),
			);
		}

		/**
		 * Email the owner. Fixed recipient and headers; caller data only in the body.
		 *
		 * @param int   $id     Request ID.
		 * @param array $fields Fields.
		 * @return void
		 */
		private static function notify( $id, array $fields ) {
			// The owner can turn the email off; requests still wait in the dashboard.
			if ( ! Uich_Agent_Settings::value( 'mcp.inbox.notify', true ) ) {
				return;
			}
			$to = sanitize_email( (string) Uich_Agent_Settings::value( 'mcp.inbox.notify_email', '' ) );
			if ( '' === $to ) {
				$to = get_option( 'admin_email' );
			}
			$subject = sprintf( '[%s] New %s request from an AI agent (R%d)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $fields['kind'], $id );
			$lines   = array( 'A visitor\'s AI assistant sent this request through your site\'s Agent Ready inbox. It is not confirmed until you reply.', '' );
			foreach ( $fields as $key => $value ) {
				if ( '' !== (string) $value && 0 !== $value ) {
					$lines[] = ucfirst( $key ) . ': ' . str_replace( array( "\r", "\n" ), ' ', (string) $value );
				}
			}
			$lines[] = '';
			$lines[] = 'Reference: R' . $id;
			wp_mail( $to, str_replace( array( "\r", "\n" ), ' ', $subject ), implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		}

		/* -------------------------------------------------------------
		 * Ability actions
		 * ----------------------------------------------------------- */

		/**
		 * Add inbox actions.
		 *
		 * @param array $actions Actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['list-requests']  = array(
				'description' => 'Requests agents sent through the inbox, newest first, filtered by kind and/or status.',
				'params'      => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'pending', 'confirmed', 'rejected', 'all' ),
					),
					'kind'   => array(
						'type' => 'string',
						'enum' => array( 'booking', 'enquiry', 'quote', 'callback', 'all' ),
					),
					'page'   => array( 'type' => 'integer' ),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_list' ),
			);
			$actions['get-request']    = array(
				'description' => 'One request with all its fields.',
				'params'      => array( 'id' => array( 'type' => 'integer' ) ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_get' ),
			);
			$actions['update-request'] = array(
				'description' => 'Mark a request confirmed or rejected and add a private note. This only records the decision; reply to the person by email or phone.',
				'params'      => array(
					'id'     => array( 'type' => 'integer' ),
					'status' => array(
						'type' => 'string',
						'enum' => array( 'pending', 'confirmed', 'rejected' ),
					),
					'note'   => array( 'type' => 'string' ),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'act_update' ),
			);
			return $actions;
		}

		/**
		 * Action: list-requests.
		 *
		 * @param array $params Params.
		 * @return array
		 */
		public static function act_list( array $params ) {
			// Filtering by kind shows every status unless one is asked for.
			$kind   = isset( $params['kind'] ) ? sanitize_key( (string) $params['kind'] ) : '';
			$status = isset( $params['status'] ) ? (string) $params['status'] : ( '' !== $kind ? 'all' : 'pending' );
			$query  = array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				'numberposts' => 50,
				'paged'       => isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1,
			);
			$meta = array();
			if ( 'all' !== $status ) {
				$meta[] = array(
					'key'   => '_status',
					'value' => $status,
				);
			}
			if ( in_array( $kind, array( 'booking', 'enquiry', 'quote', 'callback' ), true ) ) {
				$meta[] = array(
					'key'   => '_kind',
					'value' => $kind,
				);
			}
			if ( $meta ) {
				$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list.
			}
			$query['posts_per_page'] = 50;
			unset( $query['numberposts'] );
			$q   = new WP_Query( $query );
			$out = array();
			foreach ( $q->posts as $post ) {
				$out[] = self::row( $post, false );
			}
			return array(
				'inbox_enabled' => self::enabled(),
				'items'         => $out,
				'total'         => (int) $q->found_posts,
				'pages'         => (int) $q->max_num_pages,
				'page'          => (int) $query['paged'],
			);
		}

		/**
		 * Action: get-request.
		 *
		 * @param array $params { id }.
		 * @return array|WP_Error
		 */
		public static function act_get( array $params ) {
			$post = get_post( isset( $params['id'] ) ? absint( $params['id'] ) : 0 );
			if ( ! $post || self::TYPE !== $post->post_type ) {
				return new WP_Error( 'uich_agent_request', 'No such request.' );
			}
			return self::row( $post, true );
		}

		/**
		 * Action: update-request.
		 *
		 * @param array $params { id, status, note }.
		 * @return array|WP_Error
		 */
		public static function act_update( array $params ) {
			$post = get_post( isset( $params['id'] ) ? absint( $params['id'] ) : 0 );
			if ( ! $post || self::TYPE !== $post->post_type ) {
				return new WP_Error( 'uich_agent_request', 'No such request.' );
			}
			if ( isset( $params['status'] ) && in_array( $params['status'], array( 'pending', 'confirmed', 'rejected' ), true ) ) {
				update_post_meta( $post->ID, '_status', $params['status'] );
			}
			if ( isset( $params['note'] ) ) {
				update_post_meta( $post->ID, '_note', mb_substr( sanitize_textarea_field( (string) $params['note'] ), 0, 1000 ) );
			}
			return self::row( get_post( $post->ID ), true );
		}

		/**
		 * A request as data.
		 *
		 * @param WP_Post $post   Post.
		 * @param bool    $detail All fields.
		 * @return array
		 */
		private static function row( WP_Post $post, $detail ) {
			$row = array(
				'id'      => $post->ID,
				'ref'     => 'R' . $post->ID,
				'kind'    => get_post_meta( $post->ID, '_kind', true ),
				'name'    => get_post_meta( $post->ID, '_name', true ),
				'date'    => get_post_meta( $post->ID, '_date', true ),
				'time'    => get_post_meta( $post->ID, '_time', true ),
				'status'  => get_post_meta( $post->ID, '_status', true ),
				'created' => gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) ),
			);
			if ( $detail ) {
				foreach ( array( 'email', 'phone', 'people', 'service', 'message', 'note', 'agent' ) as $key ) {
					$row[ $key ] = get_post_meta( $post->ID, '_' . $key, true );
				}
			}
			return $row;
		}

		/* -------------------------------------------------------------
		 * Personal data export and erase
		 * ----------------------------------------------------------- */

		/**
		 * Register the exporter.
		 *
		 * @param array $exporters Exporters.
		 * @return array
		 */
		public static function exporter( $exporters ) {
			$exporters['uichemy-agent-requests'] = array(
				'exporter_friendly_name' => __( 'Agent Ready requests', 'uichemy' ),
				'callback'               => array( __CLASS__, 'export' ),
			);
			return $exporters;
		}

		/**
		 * Register the eraser.
		 *
		 * @param array $erasers Erasers.
		 * @return array
		 */
		public static function eraser( $erasers ) {
			$erasers['uichemy-agent-requests'] = array(
				'eraser_friendly_name' => __( 'Agent Ready requests', 'uichemy' ),
				'callback'             => array( __CLASS__, 'erase' ),
			);
			return $erasers;
		}

		/**
		 * Requests for an email address.
		 *
		 * @param string $email Email.
		 * @return int[]
		 */
		private static function by_email( $email ) {
			return get_posts(
				array(
					'post_type'   => self::TYPE,
					'post_status' => 'private',
					'numberposts' => 500,
					'fields'      => 'ids',
					'meta_key'    => '_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy request.
					'meta_value'  => sanitize_email( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- privacy request.
				)
			);
		}

		/**
		 * Export callback.
		 *
		 * @param string $email Email.
		 * @return array
		 */
		public static function export( $email ) {
			$data = array();
			foreach ( self::by_email( $email ) as $id ) {
				$row  = self::row( get_post( $id ), true );
				$item = array();
				foreach ( $row as $key => $value ) {
					$item[] = array(
						'name'  => $key,
						'value' => (string) $value,
					);
				}
				$data[] = array(
					'group_id'    => 'uichemy-agent-requests',
					'group_label' => __( 'Agent Ready requests', 'uichemy' ),
					'item_id'     => 'agent-request-' . $id,
					'data'        => $item,
				);
			}
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		/**
		 * Erase callback.
		 *
		 * @param string $email Email.
		 * @return array
		 */
		public static function erase( $email ) {
			$removed = 0;
			foreach ( self::by_email( $email ) as $id ) {
				if ( wp_delete_post( $id, true ) ) {
					++$removed;
				}
			}
			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
	}
}
