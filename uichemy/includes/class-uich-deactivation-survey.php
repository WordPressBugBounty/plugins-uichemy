<?php
/**
 * "Why are you deactivating?" dialog on the Plugins screen.
 *
 * Uses the shared POSIMYTH SDK (includes/posimyth-sdk), the same dialog Nexter and The Plus
 * Blocks show, with UiChemy's own name, logo, accent and reasons. The dialog never blocks
 * deactivation:
 *  - "Skip & Deactivate", Esc or closing the dialog send nothing and just deactivate.
 *  - "Submit & Deactivate" sends the chosen reason and any typed text with the site address.
 *    The user's email is added only when they tick "I agree to be contacted via email for
 *    support with this plugin.", which is off by default.
 *  - Answers go to the UiChemy AI Website Creator app (uich_deactivation_feedback_endpoint()),
 *    over HTTPS, where the team reads them at /admin/deactivation-feedback. The email is stored
 *    there encrypted.
 *
 * Not shown while White Label is on: a site branded as another product should not ask its
 * client about UiChemy.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once UICH_PATH . 'includes/posimyth-sdk/posimyth-sdk-loader.php';
posimyth_sdk_register( UICH_PATH . 'includes/posimyth-sdk' );

if ( ! function_exists( 'uich_boot_deactivation_survey' ) ) {

	/**
	 * Hook the dialog for this request. Runs on admin_init, after the shared SDK classes and the
	 * White Label settings are available, and before the Plugins screen footer and admin-ajax.
	 *
	 * @return void
	 */
	function uich_boot_deactivation_survey() {
		if ( ! class_exists( 'Posimyth_Deactivation_Survey' ) ) {
			return;
		}
		if ( function_exists( 'uich_brand_wl' ) && uich_brand_wl() ) {
			return;
		}

		new Posimyth_Deactivation_Survey(
			array(
				'plugin_name' => 'UiChemy',
				'plugin_slug' => 'uichemy',
				'plugin_file' => plugin_basename( UICH_FILE ),
				'ajax_action' => 'posimyth_uich_deact',
				'accent'      => '#FD6A35',
				'logo_html'   => '<img src="' . esc_url( UICH_URL . 'assets/svg/uichemy-logo.svg' ) . '" width="28" height="28" alt="" decoding="async">',
				// A closure so the labels are translated when the dialog renders, not at load time.
				'reasons'     => 'uich_deactivation_survey_reasons',
				// UiChemy's answers go to UiChemy's own server, not the shared POSIMYTH hub. A plain
				// function name (not an array) so the SDK does not look for a tracker class on it.
				'tracker_cb'  => 'uich_send_deactivation_feedback',
			)
		);
	}
	add_action( 'admin_init', 'uich_boot_deactivation_survey' );

	/**
	 * UiChemy's look for the shared dialog: no coloured bar on top, and a little more width so
	 * every reason card stays on one line. Scoped to UiChemy's dialog (`.posi-deact-p-uichemy`)
	 * so other POSIMYTH products' dialogs on the same page keep the SDK's own look.
	 *
	 * @return void
	 */
	function uich_deactivation_survey_styles() {
		if ( function_exists( 'uich_brand_wl' ) && uich_brand_wl() ) {
			return;
		}
		echo '<style>'
			. '.posi-deact-p-uichemy .posi-deact-dialog{border-top:0;max-width:620px;}'
			. '.posi-deact-p-uichemy .posi-deact-cards label{white-space:nowrap;}'
			. '@media (max-width:600px){.posi-deact-p-uichemy .posi-deact-cards label{white-space:normal;}}'
			. '</style>';
	}
	add_action( 'admin_footer-plugins.php', 'uich_deactivation_survey_styles', 20 );
}

if ( ! function_exists( 'uich_deactivation_feedback_endpoint' ) ) {

	/**
	 * Where the answers go: the UiChemy AI Website Creator app, which shows them to the team at
	 * /admin/deactivation-feedback. Override with the UICH_DEACTIVATION_FEEDBACK_URL constant or the
	 * uich_deactivation_feedback_endpoint filter (for a staging app).
	 *
	 * @return string
	 */
	function uich_deactivation_feedback_endpoint() {
		$url = defined( 'UICH_DEACTIVATION_FEEDBACK_URL' )
			? (string) UICH_DEACTIVATION_FEEDBACK_URL
			: 'https://app.uichemy.com/api/uichemy/deactivation-feedback';

		return (string) apply_filters( 'uich_deactivation_feedback_endpoint', $url );
	}
}

if ( ! function_exists( 'uich_send_deactivation_feedback' ) ) {

	/**
	 * Sends one dialog answer. Called by the shared SDK's AJAX handler as its tracker callback,
	 * after it has checked the capability and nonce, allowlisted the reason, capped the text and
	 * read the email from the submitting user only when they ticked the consent box.
	 *
	 * Sends only the documented fields plus the plugin version: no environment payload. The request
	 * goes out on shutdown, after the browser already has its answer, so deactivation never waits.
	 *
	 * @param string $event  Always 'deactivate' from the dialog.
	 * @param array  $extra  reason, other_text and, with consent only, contact_email + contact_ok.
	 * @return void
	 */
	function uich_send_deactivation_feedback( $event, $extra = array() ) {
		if ( 'deactivate' !== $event || ! is_array( $extra ) ) {
			return;
		}

		$body = array(
			'plugin_slug'    => 'uichemy',
			'plugin_version' => defined( 'UICH_VERSION' ) ? UICH_VERSION : '',
			'site_url'       => home_url(),
			'event'          => 'deactivate',
			'reason'         => isset( $extra['reason'] ) ? sanitize_key( $extra['reason'] ) : 'no-reason',
			'other_text'     => isset( $extra['other_text'] ) ? (string) $extra['other_text'] : '',
		);

		if ( ! empty( $extra['contact_ok'] ) && ! empty( $extra['contact_email'] ) && is_email( $extra['contact_email'] ) ) {
			$body['contact_email'] = sanitize_email( $extra['contact_email'] );
			$body['contact_ok']    = 1;
		}

		$endpoint = uich_deactivation_feedback_endpoint();
		// HTTPS only, so a consenting user's email never travels in clear text. A local http:// app
		// is allowed for development.
		$scheme = wp_parse_url( $endpoint, PHP_URL_SCHEME );
		$host   = wp_parse_url( $endpoint, PHP_URL_HOST );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || ( is_string( $host ) && '.localhost' === substr( $host, -10 ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local ) ) {
			return;
		}

		$json = wp_json_encode( $body );

		$dispatch = static function () use ( $endpoint, $json ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			wp_remote_post(
				$endpoint,
				array(
					'headers'     => array( 'Content-Type' => 'application/json' ),
					'body'        => $json,
					'timeout'     => 15,
					'redirection' => 0,
					'blocking'    => true,
					'data_format' => 'body',
					'sslverify'   => true,
				)
			);
		};

		if ( did_action( 'shutdown' ) ) {
			$dispatch();
			return;
		}
		add_action( 'shutdown', $dispatch, 99 );
	}
}

if ( ! function_exists( 'uich_deactivation_survey_reasons' ) ) {

	/**
	 * The reasons offered, as slug => label + icon. The slugs are what the feedback report groups
	 * by, so keep them stable when changing a label.
	 *
	 * @return array<string,array{label:string,icon:string}>
	 */
	function uich_deactivation_survey_reasons() {
		$c   = '#FD6A35';
		$svg = static function ( $paths ) use ( $c ) {
			// The stroke sits on a <g>: the dialog's icon filter keeps stroke attributes on shapes
			// and groups only, not on the <svg> itself.
			return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><g fill="none" stroke="' . $c . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</g></svg>';
		};

		return array(
			'temporary'            => array(
				'label' => __( 'Temporary deactivation', 'uichemy' ),
				'icon'  => $svg( '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>' ),
			),
			'import-not-accurate'  => array(
				'label' => __( "Import didn't match my design", 'uichemy' ),
				'icon'  => $svg( '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m3 15 5-5 4 4 3-3 6 6"/><circle cx="16" cy="8" r="1.5"/>' ),
			),
			'plugin-issues'        => array(
				'label' => __( 'Something broke', 'uichemy' ),
				'icon'  => $svg( '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>' ),
			),
			'hard-to-use'          => array(
				'label' => __( 'Too hard to use', 'uichemy' ),
				'icon'  => $svg( '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>' ),
			),
			'slow-performance'     => array(
				'label' => __( 'Slowed down my site', 'uichemy' ),
				'icon'  => $svg( '<path d="M5 17a8 8 0 1 1 14 0"/><path d="m12 13-3-4"/>' ),
			),
			'compatibility-issues' => array(
				'label' => __( 'Theme or plugin conflict', 'uichemy' ),
				'icon'  => $svg( '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>' ),
			),
			'missing-feature'      => array(
				'label' => __( 'Missing a feature I need', 'uichemy' ),
				'icon'  => $svg( '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v8M8 12h8"/>' ),
			),
			'switched-alternative' => array(
				'label' => __( 'Switched to another tool', 'uichemy' ),
				'icon'  => $svg( '<path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>' ),
			),
			'no-longer-needed'     => array(
				'label' => __( 'No longer needed', 'uichemy' ),
				'icon'  => $svg( '<path d="M20 6 9 17l-5-5"/>' ),
			),
			'other'                => array(
				'label' => __( 'Other reason', 'uichemy' ),
				'icon'  => $svg( '<circle cx="12" cy="12" r="9"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>' ),
			),
		);
	}
}
