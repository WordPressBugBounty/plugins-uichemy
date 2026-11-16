<?php
/**
 * Black Friday sale banner on the Plugins screen.
 *
 * One slim, responsive notice shown only on wp-admin/plugins.php, only to users who can
 * `manage_options`, until the sale ends. Closing it saves a user meta flag (nonce + capability
 * checked), so it never comes back for that user. The flag carries the campaign id, so a later
 * campaign can use a new id and show again. uninstall.php removes the flag.
 *
 * Everything (CSS, SVG, a few lines of JS) is printed inline inside the notice, and nothing is
 * loaded on any other admin page or on the front end.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    uichemy
 * @subpackage uichemy/includes/notices
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Bf_Banner' ) ) {

	/**
	 * Uich_Bf_Banner
	 *
	 * @since 5.6.0
	 */
	class Uich_Bf_Banner {

		/**
		 * Campaign id. Change it for a new campaign so users who closed this one see the next.
		 */
		const CAMPAIGN = 'bfcm2026';

		/**
		 * User meta key that records the dismissal. Also removed in uninstall.php.
		 */
		const META_KEY = 'uich_bf_banner_bfcm2026_dismissed';

		/**
		 * Last moment the banner shows, in the site's timezone (Settings > General).
		 */
		const ENDS_AT = '2026-12-02 23:59:59';

		/**
		 * AJAX / admin-post action and nonce action.
		 */
		const ACTION = 'uich_bf_banner_dismiss';

		/**
		 * Singleton instance variable.
		 *
		 * @var Uich_Bf_Banner|null
		 */
		private static $instance;

		/**
		 * Singleton instance getter method.
		 *
		 * @return self
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register hooks. Only the Plugins screen loads the notice; the dismiss handlers are
		 * registered for admin-ajax.php (JS) and admin-post.php (no-JS fallback).
		 */
		public function __construct() {
			if ( ! is_admin() ) {
				return;
			}

			add_action( 'load-plugins.php', array( $this, 'maybe_hook_notice' ) );
			add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_dismiss' ) );
			add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_dismiss' ) );
		}

		/**
		 * Has the sale ended?
		 *
		 * @return bool
		 */
		private function has_ended() {
			try {
				$end = new DateTimeImmutable( self::ENDS_AT, wp_timezone() );
			} catch ( Exception $e ) {
				return true;
			}

			/**
			 * Filters the Unix timestamp after which the Black Friday banner stops showing.
			 *
			 * @param int $ends_at Unix timestamp.
			 */
			$ends_at = (int) apply_filters( 'uich_bf_banner_ends_at', $end->getTimestamp() );

			return time() > $ends_at;
		}

		/**
		 * Should the current user see the banner on this request?
		 *
		 * @return bool
		 */
		private function should_show() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			if ( $this->has_ended() ) {
				return false;
			}

			if ( get_user_meta( get_current_user_id(), self::META_KEY, true ) ) {
				return false;
			}

			// Pro users already have the product, and a white-labelled site must not show UiChemy ads.
			$show = ! defined( 'UICH_PRO_VERSION' );
			if ( function_exists( 'uich_brand_wl' ) && uich_brand_wl() ) {
				$show = false;
			}

			/**
			 * Filters whether the Black Friday banner shows for the current user.
			 *
			 * @param bool $show Whether to show it.
			 */
			return (bool) apply_filters( 'uich_bf_banner_show', $show );
		}

		/**
		 * Runs on `load-plugins.php`: hook the notice only when it should show.
		 *
		 * @return void
		 */
		public function maybe_hook_notice() {
			// Printed on `pre_current_active_plugins`, which runs right after `.wp-header-end`, so the
			// banner is born in its final place. Through `admin_notices` it would print above the
			// heading and then be moved by common.js, which makes it jump on every page load.
			// Not in the Network Admin, where `admin_notices` never showed it either.
			if ( ! is_network_admin() && $this->should_show() ) {
				add_action( 'pre_current_active_plugins', array( $this, 'render' ) );
			}
		}

		/**
		 * Save the dismissal for the current user.
		 *
		 * Answers JSON on admin-ajax.php and redirects back on admin-post.php.
		 *
		 * @return void
		 */
		public function handle_dismiss() {
			$is_ajax = wp_doing_ajax();

			if ( ! current_user_can( 'manage_options' ) ) {
				if ( $is_ajax ) {
					wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'uichemy' ) ), 403 );
				}
				wp_die( esc_html__( 'You are not allowed to do this.', 'uichemy' ), '', array( 'response' => 403 ) );
			}

			if ( $is_ajax ) {
				check_ajax_referer( self::ACTION, 'nonce' );
			} else {
				check_admin_referer( self::ACTION, 'nonce' );
			}

			update_user_meta( get_current_user_id(), self::META_KEY, time() );

			if ( $is_ajax ) {
				wp_send_json_success();
			}

			wp_safe_redirect( self_admin_url( 'plugins.php' ) );
			exit;
		}

		/**
		 * Print the banner.
		 *
		 * @return void
		 */
		public function render() {
			$nonce       = wp_create_nonce( self::ACTION );
			$dismiss_url = add_query_arg(
				array(
					'action' => self::ACTION,
					'nonce'  => $nonce,
				),
				admin_url( 'admin-post.php' )
			);

			/**
			 * Filters the "Grab the Deal" link of the Black Friday banner.
			 *
			 * @param string $url Deal URL.
			 */
			$deal_url = apply_filters(
				'uich_bf_banner_url',
				'https://uichemy.com/pricing/?utm_source=wpbackend&utm_medium=plugins&utm_campaign=' . self::CAMPAIGN
			);

			/* translators: %d: discount percentage, e.g. 30. */
			$off = sprintf( __( '%d%% OFF', 'uichemy' ), 30 );
			?>
			<div class="uich-bf" id="uich-bf-banner" role="region" aria-label="<?php esc_attr_e( 'UiChemy Black Friday sale', 'uichemy' ); ?>">
				<style>
					div.uich-bf{margin:5px 0 15px;container-type:inline-size}
					.uich-bf__in{position:relative;overflow:hidden;border-radius:8px;background:radial-gradient(120% 140% at 70% 0%,#1c1c48,#141436 60%);color:#ffeec8;display:grid;gap:12px;padding:18px 18px 44px;grid-template-areas:"brand" "offer" "copy" "cta";font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
					.uich-bf__in::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.45;background-image:radial-gradient(1.5px 1.5px at 12% 22%,#fff 50%,transparent 51%),radial-gradient(1.5px 1.5px at 46% 14%,#fff 50%,transparent 51%),radial-gradient(1.5px 1.5px at 63% 40%,#fff 50%,transparent 51%),radial-gradient(1.5px 1.5px at 88% 18%,#fff 50%,transparent 51%),radial-gradient(1.5px 1.5px at 30% 60%,#fff 50%,transparent 51%)}
					.uich-bf__city{position:absolute;left:0;bottom:0;width:100%;height:32px;pointer-events:none}
					.uich-bf__brand{grid-area:brand;display:flex;align-items:center;gap:7px;font-weight:700;font-size:14px;color:#fff;position:relative}
					.uich-bf__brand svg{width:20px;height:20px;flex:none}
					.uich-bf__copy{grid-area:copy;position:relative;min-width:0}
					div.uich-bf .uich-bf__h{margin:0;padding:0;font-weight:800;text-transform:uppercase;letter-spacing:.02em;font-size:17px;line-height:1.2;color:#ffeec8}
					div.uich-bf .uich-bf__h em{font-style:normal;color:#f2636a;display:block}
					.uich-bf__offer{grid-area:offer;display:flex;align-items:flex-end;gap:8px;position:relative;line-height:.9}
					.uich-bf__upto{font-size:13px;font-weight:700;color:#f2636a;padding-bottom:4px}
					.uich-bf__pct{font-weight:900;font-size:38px;color:#ffeec8;text-shadow:3px 3px 0 #e5484d;white-space:nowrap}
					.uich-bf__cta{grid-area:cta;display:grid;gap:6px;position:relative}
					.uich-bf .uich-bf__btn{display:inline-flex;justify-content:center;align-items:center;background:#e5484d;color:#fff;font-weight:700;font-size:14px;line-height:1.2;text-decoration:none;padding:10px 18px;border-radius:6px;box-shadow:0 3px 0 #a8282d;white-space:nowrap}
					.uich-bf .uich-bf__btn:hover,.uich-bf .uich-bf__btn:focus{background:#d63c41;color:#fff}
					.uich-bf .uich-bf__btn:focus-visible,.uich-bf .uich-bf__x:focus-visible{outline:2px solid #fff;outline-offset:2px;box-shadow:none}
					.uich-bf__g{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:#c9c6e6;text-align:center}
					.uich-bf__art{grid-area:art;display:none;position:relative}
					.uich-bf__art svg{height:40px;width:auto;max-width:100%}
					.uich-bf .uich-bf__x{position:absolute;top:8px;right:8px;z-index:2;display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;text-decoration:none}
					.uich-bf .uich-bf__x:hover{background:rgba(255,255,255,.25);color:#fff}
					.uich-bf__x svg{width:12px;height:12px}
					@container (min-width:600px){
						.uich-bf__in{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"brand offer" "copy offer" "cta offer";padding:18px 56px 42px 24px;gap:10px 28px;align-items:center}
						.uich-bf__cta{justify-items:start;grid-auto-flow:column;justify-content:start;align-items:center;gap:14px}
						.uich-bf__g{text-align:left}
						div.uich-bf .uich-bf__h{font-size:19px}
						.uich-bf__pct{font-size:52px}
						.uich-bf__offer{flex-direction:column;align-items:flex-start;gap:2px}
					}
					@container (min-width:1000px){
						.uich-bf__in{grid-template-columns:auto minmax(0,1fr) auto auto auto;grid-template-areas:"brand copy art offer cta";height:84px;box-sizing:border-box;padding:0 52px 0 24px;gap:0 28px;align-items:center}
						div.uich-bf .uich-bf__h{font-size:16px}
						.uich-bf__h .uich-bf__l1{display:none}
						div.uich-bf .uich-bf__h em{display:inline}
						.uich-bf__art{display:flex;align-items:center}
						.uich-bf__offer{flex-direction:row;align-items:flex-end;gap:6px}
						.uich-bf__pct{font-size:36px;text-shadow:2px 2px 0 #e5484d}
						.uich-bf__g{display:none}
						.uich-bf__city{opacity:.55;height:22px}
						.uich-bf .uich-bf__x{top:50%;margin-top:-13px}
					}
				</style>
				<div class="uich-bf__in">
					<svg class="uich-bf__city" viewBox="0 0 400 34" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect y="22" width="400" height="12" fill="#7a2a52" opacity=".55"/><g fill="#2b1f52" shape-rendering="crispEdges"><rect x="0" y="16" width="22" height="18"/><rect x="24" y="8" width="14" height="26"/><rect x="40" y="18" width="20" height="16"/><rect x="62" y="4" width="12" height="30"/><rect x="76" y="14" width="24" height="20"/><rect x="102" y="10" width="16" height="24"/><rect x="120" y="20" width="22" height="14"/><rect x="144" y="2" width="12" height="32"/><rect x="158" y="12" width="26" height="22"/><rect x="186" y="18" width="14" height="16"/><rect x="202" y="6" width="18" height="28"/><rect x="222" y="16" width="24" height="18"/><rect x="248" y="9" width="12" height="25"/><rect x="262" y="19" width="22" height="15"/><rect x="286" y="3" width="14" height="31"/><rect x="302" y="13" width="24" height="21"/><rect x="328" y="17" width="16" height="17"/><rect x="346" y="7" width="14" height="27"/><rect x="362" y="15" width="22" height="19"/><rect x="386" y="11" width="14" height="23"/></g><g fill="#ff9b63" shape-rendering="crispEdges"><rect x="28" y="12" width="2" height="2"/><rect x="66" y="9" width="2" height="2"/><rect x="66" y="16" width="2" height="2"/><rect x="106" y="15" width="2" height="2"/><rect x="148" y="7" width="2" height="2"/><rect x="148" y="14" width="2" height="2"/><rect x="208" y="11" width="2" height="2"/><rect x="252" y="14" width="2" height="2"/><rect x="290" y="8" width="2" height="2"/><rect x="290" y="16" width="2" height="2"/><rect x="350" y="12" width="2" height="2"/><rect x="390" y="16" width="2" height="2"/></g></svg>

					<div class="uich-bf__brand">
						<svg viewBox="0 0 26 26" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" fill="#fff" d="M15.2602 19.0192L15.2806 13.7359L25.2869 13.7342V18.0826C25.2869 19.0243 25.0982 19.9568 24.7314 20.8268C24.3646 21.6968 23.827 22.4873 23.1493 23.1532C22.4716 23.819 21.6671 24.3471 20.7816 24.7074C19.8962 25.0677 18.9472 25.2531 17.9888 25.253H9.38794C8.8993 25.2982 8.40741 25.2982 7.91877 25.253H7.90814C5.99933 25.253 4.16869 24.5079 2.81895 23.1816C1.46921 21.8553 0.710938 20.0565 0.710938 18.1808V0.710938H4.24316C5.96068 0.710938 7.60784 1.38133 8.82231 2.5747C10.0368 3.76806 10.7191 5.38663 10.7191 7.0743V15.8388C10.7191 19.2758 15.2602 19.0192 15.2602 19.0192ZM22.5325 22.5491C23.7377 21.3648 24.4157 19.7591 24.4178 18.0843V14.5891H16.1462L16.1285 19.0244C16.1285 20.2315 16.0046 21.8013 15.0381 23.2032C14.7248 23.6556 14.3444 24.0596 13.9097 24.4016H17.9888C19.6933 24.3995 21.3273 23.7333 22.5325 22.5491ZM16.9843 2.38775C18.0765 1.31462 19.5579 0.711687 21.1025 0.711688H25.287V9.8658H15.278V6.43409C15.2782 4.91633 15.892 3.46089 16.9843 2.38775Z"/></svg>
						<?php echo esc_html( function_exists( 'uich_brand_name' ) ? uich_brand_name() : __( 'UiChemy', 'uichemy' ) ); ?>
					</div>

					<div class="uich-bf__copy">
						<div class="uich-bf__h"><span class="uich-bf__l1"><?php esc_html_e( 'Build anywhere, own it in WordPress with AI', 'uichemy' ); ?></span> <em><?php esc_html_e( 'First visual page builder', 'uichemy' ); ?></em></div>
					</div>

					<div class="uich-bf__art" aria-hidden="true">
						<svg viewBox="0 0 300 60" focusable="false"><g shape-rendering="crispEdges"><rect x="8" y="26" width="40" height="12" fill="#d8434a"/><rect x="16" y="20" width="24" height="6" fill="#ff8d8f"/><rect x="0" y="22" width="8" height="20" fill="#7d1f25"/><rect x="18" y="38" width="20" height="4" fill="#7d1f25"/><rect x="44" y="18" width="4" height="28" fill="#ffeec8"/><rect x="24" y="16" width="8" height="6" fill="#2b1f52"/><rect x="50" y="31" width="22" height="2" fill="#ffeec8" opacity=".7"/></g><path d="M72 18h196l-10 13 10 13H72z" fill="#ffeec8"/><path d="M268 18h22l-12 13 12 13h-22l10-13z" fill="#d8434a"/><text x="170" y="38" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif" font-weight="900" font-size="20" fill="#141436" letter-spacing="1" style="text-transform:uppercase"><?php esc_html_e( 'Black Friday', 'uichemy' ); ?></text></svg>
					</div>

					<div class="uich-bf__offer">
						<span class="uich-bf__upto"><?php esc_html_e( 'Up to', 'uichemy' ); ?></span>
						<span class="uich-bf__pct"><?php echo esc_html( $off ); ?></span>
					</div>

					<div class="uich-bf__cta">
						<a class="uich-bf__btn" href="<?php echo esc_url( $deal_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Grab the Deal', 'uichemy' ); ?></a>
						<span class="uich-bf__g"><?php esc_html_e( '60 days money back guarantee', 'uichemy' ); ?></span>
					</div>

					<a class="uich-bf__x" href="<?php echo esc_url( $dismiss_url ); ?>" role="button" aria-label="<?php esc_attr_e( 'Close this offer', 'uichemy' ); ?>" title="<?php esc_attr_e( 'Close', 'uichemy' ); ?>"><svg viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></a>
				</div>
				<script>
					( function () {
						var box = document.getElementById( 'uich-bf-banner' );
						var x = box && box.querySelector( '.uich-bf__x' );
						if ( ! x || ! window.fetch || ! window.FormData ) {
							return; // The link itself still closes it through admin-post.php.
						}
						x.addEventListener( 'click', function ( e ) {
							e.preventDefault();
							box.style.display = 'none';
							var data = new FormData();
							data.append( 'action', <?php echo wp_json_encode( self::ACTION, JSON_HEX_TAG | JSON_HEX_AMP ); ?> );
							data.append( 'nonce', <?php echo wp_json_encode( $nonce, JSON_HEX_TAG | JSON_HEX_AMP ); ?> );
							fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ), JSON_HEX_TAG | JSON_HEX_AMP ); ?>, { method: 'POST', credentials: 'same-origin', body: data } )
								.then( function ( r ) { if ( ! r.ok ) { throw new Error( r.status ); } } )
								.catch( function () { window.location.href = x.href; } );
						} );
					} )();
				</script>
			</div>
			<?php
		}
	}

	Uich_Bf_Banner::get_instance();
}
