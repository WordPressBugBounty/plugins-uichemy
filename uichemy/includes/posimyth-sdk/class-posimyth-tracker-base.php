<?php
/**
 * POSIMYTH Analytics — shared client tracker base.
 *
 * Ship this file UNCHANGED inside every POSIMYTH plugin's `include/posimyth-sdk/`
 * folder. Each plugin provides a tiny subclass (Posimyth_Tracker_NE / _TPAE / _TPGB)
 * that only declares plugin-specific keys. All collection, scanning, consent-gating
 * and transport lives here so the three plugins never drift apart.
 *
 * Privacy: this payload carries no email, no IP, no user names and no post content.
 *
 * It DOES carry the site URL, and the hub stores that URL in plaintext so support can tell which
 * install a report came from. Earlier revisions of this comment said the hub only kept a one-way
 * SHA-256 hash of it; that was wrong — the hub's hash is a row-dedup key that sits alongside the
 * raw value, not a substitute for it. Do not describe this data as anonymous.
 *
 * The one exception to "no email" is the deactivation dialog's "I agree to be contacted" opt-in,
 * which attaches the admin email — and only when the user ticks that box. See
 * Posimyth_Deactivation_Survey::handle_ajax().
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Posimyth_Tracker_Base' ) ) {

	/**
	 * Shared analytics tracker. Owns the hooks, the payload, the environment/content scanners and
	 * the transport; each product subclasses it to supply its own identity and feature toggles.
	 */
	abstract class Posimyth_Tracker_Base {

		/**
		 * Guard so each concrete subclass boots its hooks only once.
		 *
		 * @var array<string,bool>
		 */
		protected static $booted = array();

		// Per-plugin configuration — implemented by each subclass.

		/** Short unique id, used for cron + option names. e.g. 'ne', 'tpae', 'tpgb'. */
		abstract protected static function id(): string;

		/** WordPress plugin folder slug, e.g. 'uichemy'. */
		abstract protected static function slug(): string;

		/** Option key that stores the user's opt-in (true = consent given). */
		abstract protected static function opt_in_option(): string;

		/** Current plugin version string. */
		abstract protected static function version(): string;

		/** Whether the Pro/premium build is active. */
		abstract protected static function is_pro(): bool;

		/**
		 * Map of feature/widget/block slug => bool, representing which features
		 * are ENABLED in settings (toggled on). Counts of actual usage come from
		 * used_features().
		 *
		 * @return array<string,bool>
		 */
		abstract protected static function enabled_features(): array;

		/* ----- Optional config: subclasses override only when relevant ----- */

		/**
		 * Map of feature slug => integer usage count, scanned from real content.
		 * Default empty (e.g. Nexter Extension: extensions are toggle-only).
		 *
		 * @return array<string,int>
		 */
		protected static function used_features(): array {
			return array();
		}

		/** License status + plan. Default empty for free-only products. */
		protected static function license(): array {
			return array(
				'status' => '',
				'plan'   => '',
			);
		}

		/** Onboarding state string ('' when the plugin has no onboarding flow). */
		protected static function onboarding_status(): string {
			return '';
		}

		/** Human-readable product name, used in the privacy-policy suggestion. */
		protected static function display_name(): string {
			return static::slug();
		}

		// Bootstrap + WordPress hooks.

		/**
		 * Registers this product's hooks. Safe to call more than once.
		 */
		public static function init(): void {
			$class = static::class;
			if ( ! empty( self::$booted[ $class ] ) ) {
				return;
			}
			self::$booted[ $class ] = true;

			add_action( 'activated_plugin', array( static::class, 'on_activate' ), 10, 2 );
			add_action( 'deactivated_plugin', array( static::class, 'on_deactivate' ), 10, 2 );
			add_action( static::cron_hook(), array( static::class, 'heartbeat' ) );
			add_action( 'admin_init', array( static::class, 'register_privacy_policy_content' ) );
			add_action( 'admin_init', array( static::class, 'maybe_catchup_heartbeat' ) );

			// One heartbeat per INSTALL, not per blog (A6): on a network-activated multisite this
			// scheduled a weekly event on every single blog — N crons and N pings for one install.
			// The network reports once, from the main site.
			if ( is_multisite() && ! is_main_site() ) {
				return;
			}

			if ( ! wp_next_scheduled( static::cron_hook() ) ) {
				wp_schedule_event( time(), 'weekly', static::cron_hook() );
			}
		}

		/**
		 * Runs an overdue heartbeat from an admin visit when WP-Cron is not firing (E7).
		 *
		 * On DISABLE_WP_CRON hosts, aggressive page caches or dormant sites, the weekly event can sit
		 * past due forever and the install goes silent. A day past due means cron is not coming; an
		 * admin page load then sends the ping (still consent-gated, still deferred to shutdown) and
		 * reschedules. The transient stops this from probing more than once a day.
		 */
		public static function maybe_catchup_heartbeat(): void {
			if ( is_multisite() && ! is_main_site() ) {
				return;
			}
			if ( ! static::has_consent() ) {
				return;
			}

			$next = wp_next_scheduled( static::cron_hook() );
			if ( $next && $next > time() - DAY_IN_SECONDS ) {
				return; // Scheduled and not meaningfully overdue.
			}

			$lock = 'posimyth_' . static::id() . '_hb_catchup';
			if ( get_transient( $lock ) ) {
				return;
			}
			set_transient( $lock, 1, DAY_IN_SECONDS );

			wp_clear_scheduled_hook( static::cron_hook() );
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', static::cron_hook() );

			static::heartbeat();
		}

		/**
		 * Feeds suggested text into WordPress's own Privacy Policy generator (Tools → Privacy), so a
		 * site owner writing their policy is told about this plugin's data sharing instead of having to
		 * discover it. Registered from init(), which is already behind the white-label gate — a
		 * rebranded install must not surface POSIMYTH's name here either.
		 */
		public static function register_privacy_policy_content(): void {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}

			$name = static::display_name();

			/*
			 * This paragraph used to end with "nothing is sent unless an administrator turns on Share
			 * Non-Sensitive Details", which is not true: submitting the deactivation feedback form sends
			 * that one submission regardless of the setting, because pressing Submit there IS the
			 * consent for it. The wording now states the exception instead of contradicting it.
			 */
			$content = '<p>' . sprintf(
				/* translators: %s: product name */
				esc_html__( '%s can send non-sensitive information about this website to POSIMYTH Innovation (api.posimyth.com) to help diagnose conflicts and prioritise fixes. This is off by default, and while it is off nothing is collected or sent automatically.', 'uichemy' ),
				esc_html( $name )
			) . '</p>';

			$content .= '<p>' . esc_html__( 'When enabled, the information sent is: the site URL, the plugin version, PHP / MySQL / WordPress versions, server software, timezone, locale, memory limit, maximum upload size, permalink structure, whether HTTPS / multisite / debug mode are on, the environment type, the number of registered users, the active theme and its version, the detected page builder, the list of active plugin folders and their count, which of the plugin\'s features are enabled and how often they are used, Pro/Free and licence status, and the install date. It is sent when the plugin is activated, when it is deactivated, and once a week in the background.', 'uichemy' ) . '</p>';

			$content .= '<p>' . esc_html__( 'There is one case where information is sent even while the setting is off: the feedback form shown when the plugin is deactivated. If an administrator fills it in and presses Submit, that submission is sent - the reason chosen, any comment typed, and the same non-sensitive setup information listed above - because pressing Submit is the consent for it. Choosing Skip, pressing Esc or clicking outside the form sends nothing at all.', 'uichemy' ) . '</p>';

			$content .= '<p>' . esc_html__( 'No post or page content, visitor data, user names or email addresses are included. The one exception is that same feedback form: if an administrator ticks "I agree to be contacted via email" there, the site administration email address is sent with the feedback so POSIMYTH can reply. That box is off by default.', 'uichemy' ) . '</p>';

			wp_add_privacy_policy_content( $name, wp_kses_post( $content ) );
		}

		/**
		 * Name of this product's heartbeat cron hook.
		 *
		 * @return string
		 */
		protected static function cron_hook(): string {
			return 'posimyth_heartbeat_' . static::id();
		}

		/**
		 * Stops the weekly heartbeat. Call from the plugin's deactivation hook.
		 *
		 * Init() schedules this cron but nothing ever cleared it, so a deactivated plugin left a weekly
		 * event behind in WordPress forever — firing against a hook with no listener, and reappearing in
		 * every cron listing.
		 *
		 * @return void
		 */
		public static function unschedule(): void {
			wp_clear_scheduled_hook( static::cron_hook() );
		}

		/**
		 * Removes everything this product stored. Call from uninstall, never from deactivation.
		 *
		 * Consent used to survive uninstalling: the opt-in option stayed in wp_options, so reinstalling
		 * silently resumed sending without ever asking again. Someone who removes the plugin has
		 * withdrawn from the arrangement, and reinstalling has to start from a clean slate.
		 *
		 * @param bool   $include_suite_wide Also delete the options shared across the Nexter family.
		 *                                 Pass false while a sibling product is still installed,
		 *                                 otherwise removing one plugin silently resets the other's
		 *                                 consent and re-prompts a user who already decided.
		 * @param string $suite_key          Suite key those shared options are named after.
		 * @return void
		 */
		public static function purge_state( bool $include_suite_wide = false, string $suite_key = 'nexter_suite' ): void {
			static::unschedule();

			$id = static::id();
			delete_option( 'posimyth_' . $id . '_install_time' );
			delete_option( 'posimyth_' . $id . '_usage' );
			delete_option( 'posimyth_' . $id . '_first_use_at' );
			delete_option( 'posimyth_' . $id . '_activate_reported' );
			delete_transient( 'posimyth_' . $id . '_deact_reported' );

			if ( $include_suite_wide ) {
				// These are stored as SITE options (A6: one consent per install, not per blog), so they
				// have to be removed as site options too — delete_option alone would leave the network
				// value behind and a reinstall would silently resume with the old answer.
				delete_site_option( static::opt_in_option() );
				delete_site_option( 'posi_consent_dismissed_' . $suite_key );
				delete_site_option( 'posi_consent_snoozed_until_' . $suite_key );
				// Start of the notice's post-install quiet period. Left behind, a reinstall would inherit
				// an expired window and be asked on the first admin page load instead of getting the
				// couple of quiet days a fresh install is meant to get.
				delete_site_option( 'posi_consent_grace_start_' . $suite_key );

				/*
				 * These two belong to the Nexter suite specifically, not to whichever suite is being
				 * purged, and their names are fixed rather than derived from $suite_key.
				 *
				 * That was harmless while `nexter_suite` was the only suite in existence. It stopped being
				 * harmless when The Plus Addons for Elementor moved to its own `tpae_suite`: deleting TPAE
				 * then reached across and wiped Nexter's onboarding-completed flag and its legacy-consent
				 * migration marker, so Nexter's setup wizard could reopen and the migration could run a
				 * second time — on a site where Nexter had not been touched at all.
				 */
				if ( 'nexter_suite' === $suite_key ) {
					delete_site_option( 'posimyth_ne_legacy_consent_migrated' );
					delete_option( 'nxt_onboarding_done' );
				}

				// Older builds wrote these per-blog; clear that shape too so nothing lingers.
				delete_option( static::opt_in_option() );
				delete_option( 'posi_consent_dismissed_' . $suite_key );
				delete_option( 'posi_consent_snoozed_until_' . $suite_key );
				delete_option( 'posi_consent_grace_start_' . $suite_key );
			}
		}

		/**
		 * Is this the plugin file belonging to THIS product?
		 *
		 * Using strpos( $plugin, slug() ) was wrong: `nexter-extension` is a substring of
		 * `nexter-extension-pro/...` and of `my-nexter-extension/...`, so activating or deactivating a
		 * differently-named plugin reported an event against this one. Compare the folder exactly.
		 *
		 * @param string $plugin Plugin file as WordPress passes it, e.g. "slug/slug.php".
		 * @return bool
		 */
		protected static function is_own_plugin_file( $plugin ): bool {
			$folder = ( false !== strpos( $plugin, '/' ) ) ? dirname( $plugin ) : $plugin;
			return static::slug() === $folder;
		}

		/**
		 * Reports an activation, but only for THIS product and only with consent.
		 *
		 * @param string $plugin       Plugin file that was activated.
		 * @param bool   $network_wide Unused; part of the activated_plugin hook signature.
		 *
		 * The parameters are deliberately untyped. WP-CLI before 2.12 fires this hook with null
		 * for $network_wide, and a `bool` declaration rejects it — which aborted the whole CLI
		 * run with a TypeError (exit 255) on every plugin activation while this product was
		 * active, taking deploy scripts down with it.
		 */
		public static function on_activate( $plugin, $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the hook signature.
			if ( ! static::is_own_plugin_file( $plugin ) ) {
				return;
			}
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::report_activation();
		}

		/**
		 * Reports a deactivation, but only for THIS product and only with consent.
		 *
		 * @param string $plugin       Plugin file that was deactivated.
		 * @param bool   $network_wide Unused; part of the deactivated_plugin hook signature.
		 *
		 * The parameters are deliberately untyped. WP-CLI before 2.12 fires this hook with null
		 * for $network_wide, and a `bool` declaration rejects it — which aborted the whole CLI
		 * run with a TypeError (exit 255) on every plugin activation while this product was
		 * active, taking deploy scripts down with it.
		 */
		public static function on_deactivate( $plugin, $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the hook signature.
			if ( ! static::is_own_plugin_file( $plugin ) ) {
				return;
			}
			delete_option( 'posimyth_' . static::id() . '_activate_reported' );

			if ( ! static::has_consent() ) {
				return;
			}

			/*
			 * The deactivation dialog may have just reported this same deactivation, with the reason
			 * attached. WordPress then fires `deactivated_plugin` regardless, and this listener cannot
			 * see what the user clicked — so every opted-in deactivation was counted twice on the hub:
			 * once with a reason and once without, inflating churn for precisely the opted-in cohort.
			 * The dialog leaves a short-lived marker; if it is there, the richer event has already gone.
			 */
			if ( get_transient( 'posimyth_' . static::id() . '_deact_reported' ) ) {
				delete_transient( 'posimyth_' . static::id() . '_deact_reported' );
				return;
			}

			static::do_request( 'deactivate' );
		}

		/**
		 * Marks this deactivation as already reported, so on_deactivate() does not send a second event.
		 *
		 * Called by the deactivation dialog right before it submits. Short-lived on purpose: it only
		 * has to survive the hop from the dialog's AJAX call to the deactivation request that follows.
		 *
		 * @return void
		 */
		public static function mark_deactivation_reported(): void {
			set_transient( 'posimyth_' . static::id() . '_deact_reported', 1, 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Sends `activate` at most once per active period.
		 *
		 * Four different places legitimately want to announce an activation — the notice's Allow, the
		 * Dashboard toggle, onboarding, and the plugin's own activation hook — and each called
		 * do_request('activate') directly, so one install could report several activations and the
		 * hub's activation count drifted away from the install count. The flag is cleared on
		 * deactivation, so a genuine reactivation is still counted.
		 *
		 * @return void
		 */
		protected static function report_activation(): void {
			$key = 'posimyth_' . static::id() . '_activate_reported';
			if ( get_option( $key ) ) {
				return;
			}
			update_option( $key, 1, false );
			static::do_request( 'activate' );
		}

		/**
		 * Weekly background check-in, so long-lived installs keep reporting current state.
		 */
		public static function heartbeat(): void {
			if ( ! static::has_consent() ) {
				return;
			}
			static::do_request( 'heartbeat' );
		}

		/**
		 * Activation ping for the plugin's OWN activation request.
		 *
		 * The `activated_plugin` hook in init() cannot help here: during a plugin's own activation
		 * request WordPress has already fired `plugins_loaded` before it includes the plugin file, so
		 * init() never runs and nothing is listening when `activated_plugin` fires. The result was
		 * that activations were never recorded, while deactivations (where the plugin IS already
		 * loaded) worked fine. Call this from register_activation_hook() instead.
		 *
		 * Consent-gated.
		 */
		public static function on_self_activate(): void {
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::report_activation();
		}

		/**
		 * Called by the consent notice and the Dashboard toggle immediately after the user opts in.
		 *
		 * Both callers write the consent option before calling, so this guard is normally satisfied —
		 * it is here so the function is safe on its own terms rather than relying on every present and
		 * future caller remembering to set consent first.
		 */
		public static function send_first_ping(): void {
			static::record_install_time();
			if ( ! static::has_consent() ) {
				return;
			}
			static::report_activation();
		}

		/**
		 * Whether the user has opted in to sharing. Nothing is ever sent without this.
		 *
		 * Read as a SITE option (A6): on multisite the consent is one answer for the whole network —
		 * per-blog options meant N notices and N independent consents for a single install, and no
		 * way for the network admin to answer once. On a single site, get_site_option() falls back
		 * to the ordinary option of the same name, so nothing changes there.
		 *
		 * @return bool
		 */
		protected static function has_consent(): bool {
			return (bool) get_site_option( static::opt_in_option(), false );
		}

		/**
		 * Stores the first-seen timestamp once, so install age can be reported.
		 */
		protected static function record_install_time(): void {
			$key = 'posimyth_' . static::id() . '_install_time';
			if ( ! get_option( $key ) ) {
				add_option( $key, gmdate( 'Y-m-d H:i:s' ), '', false );
			}
		}

		// Payload.

		/**
		 * Assembles the non-sensitive payload for an event. Contains no personal data.
		 *
		 * @param string $event One of activate|deactivate|heartbeat.
		 * @return array
		 */
		public static function build_payload( string $event ): array {
			global $wpdb;

			$theme  = wp_get_theme();
			$parent = $theme->parent();

			// Network-activated plugins live in the sitewide option, not in the per-blog list (B3):
			// on multisite, reading only active_plugins missed every network-activated plugin — this
			// SDK's own products included — so plugin_count, page_builder and posimyth_products were
			// all wrong there. Single site: get_site_option returns the default, merge is a no-op.
			$active_slugs = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$network      = (array) get_site_option( 'active_sitewide_plugins', array() );
				$active_slugs = array_values( array_unique( array_merge( $active_slugs, array_keys( $network ) ) ) );
			}

			$license = static::license();

			/*
			 * Persist the first-seen date instead of substituting "now".
			 *
			 * When the option was missing — legacy installs that predate it, and subsites, where
			 * record_install_time() never ran — this reported today's date and then threw it away, so
			 * every weekly heartbeat claimed the site was installed today and install age was
			 * permanently zero. Write it once, then keep reporting that same value.
			 */
			$install_time = (string) get_option( 'posimyth_' . static::id() . '_install_time', '' );
			if ( '' === $install_time ) {
				static::record_install_time();
				$install_time = (string) get_option( 'posimyth_' . static::id() . '_install_time', gmdate( 'Y-m-d H:i:s' ) );
			}

			return array(
				'plugin_slug'       => static::slug(),
				'site_url'          => home_url(),
				'event'             => $event,

				// Server & environment.
				'php_version'       => phpversion(),
				// Server version string; no WP API exposes it and caching a constant makes no sense.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				'mysql_version'     => $wpdb->get_var( 'SELECT VERSION()' ),
				'server_software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'timezone'          => wp_timezone_string(),
				'debug_mode'        => (int) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'max_upload_size'   => size_format( wp_max_upload_size() ),
				'memory_limit'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
				'permalink'         => static::permalink_label(),
				'is_ssl'            => (int) is_ssl(),
				'is_local'          => static::is_local_site(),
				'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',

				// WordPress.
				'wp_version'        => get_bloginfo( 'version' ),
				'locale'            => get_locale(),
				'is_multisite'      => (int) is_multisite(),
				'user_count'        => static::user_count(),

				// Theme.
				'theme'             => $theme->get_stylesheet(),
				'theme_version'     => (string) $theme->get( 'Version' ),
				'parent_theme'      => $parent ? $parent->get_stylesheet() : '',

				// Plugins / ecosystem.
				'page_builder'      => static::detect_page_builder( $active_slugs ),
				'theme_builder'     => static::detect_theme_builder( $active_slugs ),
				'active_plugins'    => array_values( $active_slugs ),
				'plugin_count'      => count( $active_slugs ),
				'posimyth_products' => static::detect_posimyth_products( $active_slugs ),

				// Identity (Astra-style: admin email for Free + Pro, sent only under
				// consent). Raw email is PII — the opt-in notice + readme must disclose it.
				// NOTE: no admin email / no PII is sent — the consent copy promises "no personal data,
			// ever", so the raw admin_email older builds included is intentionally omitted. Do not
			// re-add email or any personal identifier here without changing that copy + the docs.

				// This product.
				'plugin_version'    => static::version(),
				'is_pro'            => (int) static::is_pro(),
				'license_status'    => (string) ( $license['status'] ?? '' ),
				'license_plan'      => (string) ( $license['plan'] ?? '' ),
				'onboarding'        => static::onboarding_status(),
				'install_time'      => $install_time,

				// Feature adoption.
				'enabled_widgets'   => static::enabled_features(),
				'widget_usage'      => static::used_features_cached( $event ),
				'plugin_meta'       => static::plugin_meta(),
			);
		}

		/**
		 * Product-specific settings that do not deserve a column of their own.
		 *
		 * The hub has accepted a `plugin_meta` object since 1.5 (nested up to 8 levels, capped at 64 KB)
		 * and nothing has ever filled it. This is the place for the handful of settings that matter for
		 * one product only — an asset-loading mode, a compatibility toggle — without adding a shared
		 * column that stays NULL for every other product and without smuggling them into
		 * enabled_widgets, which is joined against widget_usage and must stay a widget map.
		 *
		 * Keep it small and keep it non-personal: the same rules as the rest of the payload. Report the
		 * stored value rather than the label shown in the UI, so a wording change in the dashboard does
		 * not split one setting into two series on the hub.
		 *
		 * @return array<string,mixed>
		 */
		protected static function plugin_meta(): array {
			return array();
		}

		/**
		 * Heavy content scans run only on the weekly background heartbeat and are
		 * cached. Activate / deactivate (user-facing actions) read the cache so the
		 * admin never waits on a scan.
		 *
		 * @param string $event Current event; only the heartbeat refreshes the cache.
		 * @return array<string,int>
		 */
		protected static function used_features_cached( string $event ): array {
			$key = 'posimyth_' . static::id() . '_usage';

			if ( 'heartbeat' === $event ) {
				$usage = static::used_features();
				update_option( $key, $usage, false );
				return $usage;
			}

			return (array) get_option( $key, array() );
		}

		// Environment helpers.

		/**
		 * Permalink structure as a short label rather than the raw pattern.
		 *
		 * @return string
		 */
		protected static function permalink_label(): string {
			$structure = get_option( 'permalink_structure', '' );
			if ( '' === $structure ) {
				return 'plain';
			}
			if ( '/%postname%/' === $structure ) {
				return 'post-name';
			}
			if ( false !== strpos( $structure, '%postname%' ) ) {
				return 'custom-postname';
			}
			if ( false !== strpos( $structure, '%year%' ) || false !== strpos( $structure, '%post_id%' ) ) {
				return 'date-or-numeric';
			}
			return 'custom';
		}

		/**
		 * Whether this looks like a local/dev site, so hub stats can exclude it.
		 *
		 * @return int
		 */
		protected static function is_local_site(): int {
			if ( function_exists( 'wp_get_environment_type' ) ) {
				$env = wp_get_environment_type();
				if ( in_array( $env, array( 'local', 'development' ), true ) ) {
					return 1;
				}
			}

			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' === $host ) {
				return 0;
			}
			$host = strtolower( $host );

			$exact = array( 'localhost', '127.0.0.1', '::1' );
			if ( in_array( $host, $exact, true ) ) {
				return 1;
			}

			// NOT '.dev': it has been a real, publicly registered gTLD since 2019 (with HSTS preload,
			// so these are HTTPS production sites) — treating it as local silently excluded them (E5).
			$suffixes = array( '.local', '.test', '.localhost', '.example', '.invalid' );
			foreach ( $suffixes as $suffix ) {
				if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return 1;
				}
			}
			return 0;
		}

		/**
		 * Total registered users — a size signal only, no user data.
		 *
		 * @return int
		 */
		protected static function user_count(): int {
			/*
			 * count_users() is not cached by WordPress and walks the whole user table, which is
			 * genuinely slow on a membership site with hundreds of thousands of users. This value only
			 * has to be roughly right — it is a size band, not an exact figure — so a day of cache
			 * costs nothing and stops a weekly ping from turning into a table scan.
			 */
			$cache_key = 'posimyth_' . static::id() . '_user_count';
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return (int) $cached;
			}

			$counts = count_users();
			$total  = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
			set_transient( $cache_key, $total, DAY_IN_SECONDS );

			return $total;
		}

		/**
		 * Which sibling POSIMYTH products are active alongside this one.
		 *
		 * @param array $plugins Active plugin files.
		 * @return array<int,string>
		 */
		protected static function detect_posimyth_products( array $plugins ): array {
			$known = array(
				'uichemy'                 => 'uichemy',
				'the-plus-addons-for-elementor-page-builder' => 'the-plus-addons-for-elementor',
				'the-plus-addons-for-block-editor' => 'the-plus-addons-for-block-editor',
				'nexter'                           => 'nexter-theme',
				'uichemy'                          => 'uichemy',
				'wdesignkit'                       => 'wdesignkit',
				// Sticky Header Effects for Elementor. Added to SHE's own copy in SDK 2.7.0 and merged
				// back here in 2.9.0 — while it was only in SHE's copy, any site where an older sibling
				// won the loader reported no SHE at all.
				'sticky-header-effects-for-elementor' => 'sticky-header-effects',
			);

			$found = array();
			foreach ( $plugins as $file ) {
				$dir = explode( '/', $file )[0];
				if ( isset( $known[ $dir ] ) ) {
					$found[ $known[ $dir ] ] = true;
				}
			}
			return array_keys( $found );
		}

		/**
		 * Page builder in use, so conflicts can be reproduced on the right stack.
		 *
		 * @param array $plugins Active plugin files.
		 * @return string
		 */
		protected static function detect_page_builder( array $plugins ): string {
			$known = array(
				'elementor'             => 'elementor',
				'beaver-builder-plugin' => 'beaver-builder',
				'brizy'                 => 'brizy',
				'bricks'                => 'bricks',
				'divi-builder'          => 'divi',
				'js_composer'           => 'wpbakery',
				'oxygen'                => 'oxygen',
				'siteorigin-panels'     => 'siteorigin',
			);

			foreach ( $plugins as $file ) {
				$dir = explode( '/', $file )[0];
				if ( isset( $known[ $dir ] ) ) {
					return $known[ $dir ];
				}
			}
			// Bricks ships as a theme, not a plugin.
			if ( 'bricks' === strtolower( (string) wp_get_theme()->get_template() ) ) {
				return 'bricks';
			}
			return 'gutenberg';
		}

		/**
		 * Which theme builder the site actually BUILDS with.
		 *
		 * Deliberately separate from detect_page_builder() and from active_plugins. Those answer "what
		 * is installed"; this answers "what did they build their header, footer and archive templates
		 * with", which is the question you cannot get from a plugin list — Elementor Pro being active
		 * says nothing about whether its Theme Builder was ever opened.
		 *
		 * Each candidate is proved by PUBLISHED template content, not by the plugin being present. The
		 * first match wins and the rest are skipped, so a typical site costs one or two cached counts.
		 * Order is intentional: our own builders first (a Nexter site that also has Elementor Pro is a
		 * Nexter theme-builder site), then the common third parties.
		 *
		 * Returns a single slug so the hub can chart a distribution the same way it does page_builder.
		 * A site using two builders reports the higher-priority one; co-presence is still visible in
		 * active_plugins if it ever needs cross-checking.
		 *
		 * @param array $plugins Active plugin files, used to decide who owns Elementor theme templates.
		 * @return string Slug, or 'none' when no template content exists anywhere.
		 */
		protected static function detect_theme_builder( array $plugins = array() ): string {
			/**
			 * Theme builders to test, in the order they should win.
			 *
			 * Each value is the post type whose published entries prove that builder is in use, except
			 * `elementor-pro`, whose marker is the literal 'elementor-pro' — its library is shared with
			 * page and section templates, so it needs the template-type check below rather than a count.
			 *
			 * @param array<string,string> $map slug => post type, or slug => 'elementor-pro'.
			 */
			$map = apply_filters(
				'posimyth_theme_builder_post_types',
				array(
					// Nexter Extension's Theme Builder. Nexter Blocks has NO theme builder of its own —
					// it registers no post type at all and consumes this same `nxt_builder` CPT, which is
					// why there is no 'nexter-blocks' entry here. An earlier revision listed one against
					// `tpgb_template`; nothing ever registers that post type, so the entry could only
					// ever be skipped. A Blocks-only site therefore falls through to whatever it really
					// uses, or to 'none'.
					'nexter'         => 'nxt_builder',

					/*
					 * Third-party builders below. A wrong post-type name here does not break anything —
					 * post_type_exists() simply never matches — but it does under-report that competitor
					 * for as long as it is wrong, which is worse than leaving it out, because the data
					 * looks complete. Confirm each against a real install before trusting its share; the
					 * `posimyth_theme_builder_post_types` filter is the no-deploy way to correct one.
					 */

					// Elementor ecosystem.
					'elementor-pro'  => 'elementor-pro',
					'jet-theme-core' => 'jet-theme-core',

					// Other page builders with a theme-building layer.
					'beaver-themer'  => 'fl-theme-layout',
					'divi'           => 'et_theme_builder',
					'oxygen'         => 'ct_template',
					'bricks'         => 'bricks_template',
					'breakdance'     => 'breakdance_template',
					'zion'           => 'zion_template',
					'avada'          => 'fusion_tb_section',
					'themify'        => 'tbuilder_layout',
					'toolset'        => 'dd_layouts',

					// Theme-supplied builders, mostly block based.
					'generatepress'  => 'gp_elements',
					'astra'          => 'astra-advanced-hook',
					'blocksy'        => 'ct_content_block',
					'kadence'        => 'kadence_element',
					'neve'           => 'neve_custom_layouts',

					/*
					 * WordPress's own Site Editor, last because it is the least specific answer: a site
					 * running a dedicated theme builder usually has customised block templates as well,
					 * and the dedicated tool is the more useful attribution.
					 *
					 * Note what this actually measures. A block theme's templates live as FILES until the
					 * user edits one, at which point WordPress saves a `wp_template` post. So a published
					 * row here means they opened the Site Editor and changed something — which is exactly
					 * the "in use" signal wanted, not merely "runs a block theme".
					 */
					'wp-site-editor' => 'wp_template',
				)
			);

			$dirs = array();
			foreach ( $plugins as $plugin_file ) {
				$dirs[ explode( '/', (string) $plugin_file )[0] ] = true;
			}

			foreach ( $map as $slug => $post_type ) {
				if ( 'elementor-pro' === $post_type ) {
					if ( ! static::elementor_theme_builder_in_use() ) {
						continue;
					}

					/*
					 * Theme templates exist in elementor_library — but that post type is shared, so
					 * finding them there does not say who built them. The Plus Addons registers its own
					 * theme locations through `elementor/theme/register_locations` and stores its
					 * templates in exactly the same place, so attributing them to Elementor Pro was wrong
					 * whenever Pro was not even installed. Credit whichever plugin present can actually
					 * serve them, checking Pro first because it owns the feature when both are there.
					 */
					if ( isset( $dirs['elementor-pro'] ) ) {
						return 'elementor-pro';
					}
					if ( isset( $dirs['the-plus-addons-for-elementor-page-builder'] ) || isset( $dirs['theplus_elementor_addon'] ) ) {
						return 'tpae';
					}

					// Templates are there and nothing active can render them — most likely built by a
					// plugin since removed. Say that, rather than crediting a plugin that is absent.
					return 'elementor-library-orphaned';
				}

				if ( ! post_type_exists( $post_type ) ) {
					continue;
				}

				$counts = wp_count_posts( $post_type );
				if ( isset( $counts->publish ) && (int) $counts->publish > 0 ) {
					return $slug;
				}
			}

			return 'none';
		}

		/**
		 * Whether Elementor Pro's Theme Builder has published templates.
		 *
		 * `elementor_library` is shared by page, section and container templates, none of which are
		 * theme building, so the template type has to be checked. One prepared query rather than a
		 * WP_Query with a meta clause, because this runs while a payload is being assembled.
		 *
		 * @return bool
		 */
		protected static function elementor_theme_builder_in_use(): bool {
			if ( ! post_type_exists( 'elementor_library' ) ) {
				return false;
			}

			global $wpdb;

			$types = array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded existence check, no cache layer available for it.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					WHERE p.post_type = 'elementor_library'
					AND p.post_status = 'publish'
					AND m.meta_key = '_elementor_template_type'
					AND m.meta_value IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')
					LIMIT 1',
					$types
				)
			);
			// phpcs:enable

			return ! empty( $found );
		}

		// Content scanners (reused by TPAE / TPGB subclasses).
		//
		// Bounded + batched: capped at `posimyth_scan_post_cap` rows (default
		// 2000) and run only inside the weekly cron, so large sites stay safe.

		/**
		 * Count Elementor widget usage by widgetType prefix (e.g. 'tp-').
		 *
		 * @param string $prefix Widget name prefix to match, e.g. 'tp-'.
		 * @return array<string,int>
		 */
		protected static function scan_elementor_widgets( string $prefix ): array {
			global $wpdb;

			$counts  = array();
			$cap     = (int) apply_filters( 'posimyth_scan_post_cap', 2000 );
			$batch   = 100;
			$last_id = 0;
			$scanned = 0;
			$like    = '%"widgetType":"' . $wpdb->esc_like( $prefix ) . '%';
			$regex   = '/"widgetType":"(' . preg_quote( $prefix, '/' ) . '[a-z0-9_-]+)"/';

			while ( $scanned < $cap ) {
				// Clamp the batch to what the cap still allows, so `posimyth_scan_post_cap` is an exact
				// bound rather than a between-batches check that could overshoot by a whole batch.
				$take = min( $batch, $cap - $scanned );

				// Batched scan of Elementor's own meta blob; no core API can query inside it. Runs only
				// on the weekly cron and the result is cached in an option, so per-query caching would
				// add a second cache layer for a job that already runs at most once a week.
				//
				// Keyset pagination (meta_id > last, ORDER BY meta_id), not LIMIT/OFFSET (B5): with no
				// ORDER BY the batches had no defined order at all, so rows could repeat or be skipped
				// between them; and the batch is 100 rows because these are longtext blobs — 200 of
				// them at once was a real memory spike on page-heavy sites.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s AND meta_id > %d
					 ORDER BY meta_id ASC LIMIT %d",
						$like,
						$last_id,
						$take
					),
					ARRAY_A
				);

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$last_id = (int) $row['meta_id'];
					if ( preg_match_all( $regex, $row['meta_value'], $m ) ) {
						foreach ( $m[1] as $widget ) {
							$counts[ $widget ] = ( $counts[ $widget ] ?? 0 ) + 1;
						}
					}
				}

				$scanned += count( $rows );
				if ( count( $rows ) < $take ) {
					break;
				}
			}

			return $counts;
		}

		/**
		 * Count Gutenberg block usage by namespace (e.g. 'tpgb/').
		 *
		 * @param string $block_namespace Block namespace with trailing slash, e.g. 'tpgb/'.
		 * @return array<string,int>
		 */
		protected static function scan_gutenberg_blocks( string $block_namespace ): array {
			global $wpdb;

			$counts  = array();
			$cap     = (int) apply_filters( 'posimyth_scan_post_cap', 2000 );
			$batch   = 100;
			$last_id = 0;
			$scanned = 0;
			$like    = '%wp:' . $wpdb->esc_like( $block_namespace ) . '%';
			// Negative lookbehind skips the closing "/wp:tpgb/..." comment so each
			// block instance is counted once (opening tag only).
			$regex = '#(?<!/)wp:' . preg_quote( $block_namespace, '#' ) . '([a-z0-9-]+)#';

			while ( $scanned < $cap ) {
				// Clamp to the remaining allowance — see the note in scan_elementor_widgets().
				$take = min( $batch, $cap - $scanned );

				// Batched LIKE over post_content; WP_Query has no equivalent and would load full post
				// objects for rows we only pattern-match. Weekly cron only, result cached in an option.
				// Keyset pagination and a 100-row batch for the same reasons as the Elementor scan
				// above (B5): OFFSET with no ORDER BY repeats/skips rows, and these are full posts.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_content FROM {$wpdb->posts}
					 WHERE post_status = 'publish' AND post_content LIKE %s AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
						$like,
						$last_id,
						$take
					),
					ARRAY_A
				);

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$last_id = (int) $row['ID'];
					if ( preg_match_all( $regex, $row['post_content'], $m ) ) {
						// Store the bare block slug (e.g. 'tp-accordion') — no namespace,
						// so it survives sanitize_key on the hub and matches the enabled key.
						foreach ( $m[1] as $block ) {
							$counts[ $block ] = ( $counts[ $block ] ?? 0 ) + 1;
						}
					}
				}

				$scanned += count( $rows );
				if ( count( $rows ) < $take ) {
					break;
				}
			}

			return $counts;
		}

		// Transport — fire-and-forget, never blocks the admin.

		/**
		 * Sends one event to the hub. Deferred to shutdown so the admin never waits on it.
		 *
		 * @param string $event          One of activate|deactivate|heartbeat.
		 * @param array  $extra          Extra fields merged over the payload (e.g. a deactivation reason).
		 * @param bool   $bypass_consent Only the deactivation dialog's own Submit may pass true.
		 */
		public static function do_request( string $event, array $extra = array(), bool $bypass_consent = false ): void {
			// Refuse to send without consent unless the caller explicitly says this is the one
			// legitimate exception. That exception is the deactivation dialog: pressing "Submit" there
			// IS the consent for that single submission, so it must work even when the persistent
			// sharing toggle is off. Everything else — activate, deactivate, heartbeat, first ping —
			// leaves this false and is refused if consent is missing, so a future call site cannot
			// bypass consent by simply forgetting to check it.
			if ( ! $bypass_consent && ! static::has_consent() ) {
				return;
			}

			$endpoint = defined( 'POSIMYTH_ANALYTICS_ENDPOINT' )
				? POSIMYTH_ANALYTICS_ENDPOINT
				: 'https://api.posimyth.com/wp-json/posimyth/v1/track';

			// No ingest key and no request signature.
			//
			// The hub's endpoint is intentionally open. A shared key would have to be baked
			// identically into every shipped plugin, and those plugins are publicly downloadable, so
			// the key could be read straight out of a ZIP — it authenticated nothing while making
			// rotation impossible. Abuse is bounded hub-side instead (per-IP and per-site rate
			// limits, a plugin_slug allowlist, and strict payload sanitisation).
			$headers = array(
				'Content-Type' => 'application/json',
			);

			// Send AFTER the response is flushed, with a timeout long enough to actually finish.
			//
			// This previously fired inline with 'timeout' => 0.01 and 'blocking' => false. 10ms is
			// far less than a real round trip to the hub (measured ~4s), so cURL aborted during the
			// DNS/TLS phase and the request never arrived — activate / deactivate / heartbeat all
			// silently recorded nothing, with no error anywhere because the response was discarded.
			//
			// Deferring to 'shutdown' keeps the admin request just as fast (the user never waits),
			// while giving the request the time it genuinely needs.
			//
			// An earlier version of this comment talked about an HMAC timestamp staying inside the hub's
			// replay window. There is no HMAC and no replay window — see the note above the headers.
			// Nothing here is signed or timestamped.
			$timeout = (int) apply_filters( 'posimyth_analytics_request_timeout', 15 );

			$class = static::class;

			/*
			 * Everything expensive happens INSIDE this closure.
			 *
			 * build_payload() used to run inline, before the response was flushed, which made the claim
			 * that this "never blocks the admin" untrue: it counts users, reads the plugin list, resolves
			 * the theme and (in other products) scans content, all while the admin waits. Only the HTTP
			 * call was deferred. Now the payload is assembled after the response has gone out too.
			 */
			// Resolved out here rather than inside the closure: slug() is protected, and the debug log
			// below is the only thing that needs it.
			$slug = static::slug();

			$dispatch = static function () use ( $class, $slug, $event, $extra, $endpoint, $headers, $timeout ) {
				// Flush the response first so nothing below adds latency to the page.
				$flushed = false;
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
					$flushed = true;
				}

				$body = wp_json_encode( array_merge( call_user_func( array( $class, 'build_payload' ), $event ), $extra ) );

				/*
				 * Only wait for the round trip when the response has actually been flushed.
				 *
				 * fastcgi_finish_request() does not exist under mod_php, LiteSpeed's PHP handler or
				 * plain php-cgi. There, a blocking 15-second POST held the worker — and the browser —
				 * for up to 15 seconds on activate, deactivate and every cron run. Where we cannot
				 * flush, fire and forget instead: WordPress still opens the connection and writes the
				 * request, it simply does not wait for the reply. (The timeout stays generous enough to
				 * connect and write; the 0.01s used by an earlier build aborted during DNS/TLS and
				 * nothing ever arrived.)
				 */
				$response = wp_remote_post(
					$endpoint,
					array(
						'headers'     => $headers,
						'body'        => $body,
						'timeout'     => $flushed ? $timeout : 5,
						'blocking'    => $flushed,
						'data_format' => 'body',
						'sslverify'   => true,
					)
				);

				/*
				 * Say something when the hub refuses a ping.
				 *
				 * Until this existed the response was discarded whole, so a hub that accepted the
				 * request and then failed to store it was completely invisible: every product kept
				 * sending, every ping was dropped, and the only symptom was a dashboard that quietly
				 * stopped gaining rows. That is a failure mode which can run for months. A single line
				 * in the debug log turns it into something findable in minutes.
				 *
				 * Two deliberate limits:
				 *
				 *  - WP_DEBUG only. This is a diagnostic, not a user-facing error, and a site that has
				 *    debugging off must not accumulate log lines because a remote service is unwell.
				 *  - Blocking requests only. Where fastcgi_finish_request() is unavailable the request
				 *    is fire-and-forget, so $response carries no status at all — checking it there would
				 *    report a failure on every single ping regardless of what the hub did.
				 *
				 * The body is truncated and the payload is never logged: the point is the status, not a
				 * copy of what was sent.
				 */
				if ( $flushed && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

					if ( $code < 200 || $code > 299 ) {
						$detail = is_wp_error( $response )
							? $response->get_error_message()
							: trim( (string) wp_remote_retrieve_body( $response ) );

						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic, WP_DEBUG only.
							sprintf(
								'POSIMYTH analytics: %s "%s" ping was not accepted (HTTP %d) — %s',
								$slug,
								$event,
								$code,
								substr( $detail, 0, 300 )
							)
						);
					}
				}
			};

			// If we are already inside shutdown (e.g. called from another shutdown handler), adding
			// the action would never fire — send immediately instead.
			if ( did_action( 'shutdown' ) ) {
				$dispatch();
				return;
			}
			add_action( 'shutdown', $dispatch, 99 );
		}
	}
}
