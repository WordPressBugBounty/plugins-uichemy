<?php
/**
 * POSIMYTH consent notice — the admin notice that asks for permission to share non-sensitive
 * setup information. One notice for the whole Nexter suite: the opt-in option, the dismissal
 * flag and the notice itself are shared, so answering it once answers it for every product.
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Posimyth_Consent_Notice' ) ) {

	/**
	 * Renders and handles the suite-wide analytics consent notice.
	 */
	class Posimyth_Consent_Notice {

		const VERSION = '1.2.0';

		/**
		 * Shared across every plugin instance in this suite: only one notice renders per page load.
		 *
		 * @var bool
		 */
		private static bool $rendered_this_request = false;

		/**
		 * Same idea for the inline CSS/JS (E9): every product instance hooks admin_enqueue_scripts,
		 * so without this the identical stylesheet and script were attached once per active product.
		 *
		 * @var bool
		 */
		private static bool $assets_enqueued = false;

		/**
		 * Suite_key => opt_in_option, as first registered (E2). The whole design assumes every
		 * product in a suite shares ONE opt-in option; a product passing its own option under the
		 * same suite_key would get permanently locked out — the shared dismissed flag silences its
		 * notice while its private option never gets set. Catch that in development.
		 *
		 * @var array<string,string>
		 */
		private static array $suite_contracts = array();

		/**
		 * Every product that shares this consent, keyed by plugin slug => plugin name.
		 *
		 * Populated in the constructor, so it is an exact list of the products actually participating —
		 * no guessing from version constants. That matters because sibling POSIMYTH software can be
		 * present without sharing this consent (the Nexter *theme*, for example, defines NXT_VERSION but
		 * ships no tracker), and counting those would wrongly make a single-plugin site look like a
		 * multi-product one. A product suppressed by white-label never reaches the constructor, so it
		 * correctly does not count either.
		 *
		 * @var array<string,string>
		 */
		private static array $registered_products = array();

		/**
		 * Resolved configuration for this product.
		 *
		 * @var array
		 */
		private array $config;

		/**
		 * Registers this product as a consent participant and wires the notice up.
		 *
		 * @param array $config Product configuration; see the wp_parse_args() defaults below.
		 */
		public function __construct( array $config ) {
			$this->config = wp_parse_args(
				$config,
				array(
					'plugin_name'      => '',
					'plugin_slug'      => '',
					'opt_in_option'    => '',
					'ajax_action'      => '',
					'installed_option' => '',
					'tracker_cb'       => null,
					'logo_url'         => '',
					// Fallback only — each product passes its own placement-tagged URL.
					'docs_url'         => 'https://nexterwp.com/docs/data-sharing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=datasharingnotice',
					// Shown instead of plugin_name when more than one Nexter product is active, because the
					// consent is suite-wide: naming a single plugin would imply the choice only covers that
					// one. See suite_display_name().
					'suite_name'       => 'Nexter',
					// Suite-wide dedupe: same key across all Nexter plugins so only ONE notice
					// ever shows, and dismissing it counts for the whole suite, not one plugin.
					'suite_key'        => 'nexter_suite',
					/*
					 * LEGACY HOOK ONLY. Emits `<prefix>-notice-wrap`, `<prefix>-nobtn-primary` and
					 * `<prefix>-nobtn-secondary` alongside this SDK's own `posi-*` classes, purely so a host
					 * suite stylesheet that already targets them keeps matching.
					 *
					 * Nothing in inline_css() keys off it any more, and it MUST stay that way. The stylesheet
					 * is emitted once per request behind a static latch (see enqueue()) while the markup is
					 * rendered by a possibly DIFFERENT product's instance behind a second static latch — so
					 * prefix-keyed selectors meant one product's CSS could be paired with another's markup
					 * and match nothing, leaving the notice unstyled. That stayed hidden only because every
					 * sibling inherited the same default prefix. Styling now hangs off the stable `posi-*`
					 * classes, which are identical for every product, so the once-per-request stylesheet is
					 * genuinely interchangeable again.
					 *
					 * Defaulted to this SDK's own neutral rather than any product's, so a sibling that passes
					 * nothing does not end up with another product's name in its admin markup.
					 */
					'css_prefix'       => 'posi',
					/*
					 * Accent for the left border, the buttons and the icon stroke. Same contract as the
					 * deactivation survey's `accent`: validated as a plain hex.
					 *
					 * Delivered to CSS as the inline custom property `--posi-accent` on the wrapper, exactly
					 * as the survey does it, so the colour travels with the INSTANCE rather than with the
					 * shared stylesheet. This is what makes one stylesheet safe to serve every product.
					 *
					 * The default is this SDK's own neutral, matching the survey's. It is deliberately NOT
					 * any product's brand colour: a passed value always wins, so this only ever paints a
					 * caller that supplies no accent — which on a multi-product site is a sibling whose
					 * notice is rendered by whichever copy won the loader. Defaulting to a real product's
					 * colour there is how this plugin's raspberry ended up on The Plus Addons' notice.
					 * Any product that cares sets its own; every POSIMYTH product should.
					 */
					'accent'           => '#1717cc',
				)
			);

			/*
			 * Normalised once, here, because each is interpolated into both an attribute and a CSS
			 * selector/declaration. sanitize_html_class() is what makes the prefix safe in the attribute;
			 * the trailing-dash trim means a caller passing 'she-' and one passing 'she' agree rather than
			 * producing `she--notice-wrap`. Empty or all-invalid falls back to the default rather than
			 * emitting a bare `-notice-wrap`, which is not a valid selector.
			 */
			$posi_prefix                = rtrim( sanitize_html_class( (string) $this->config['css_prefix'] ), '-' );
			$this->config['css_prefix'] = ( '' !== $posi_prefix ) ? $posi_prefix : 'posi';

			// Validated rather than trusted: it lands in a custom-property declaration and an SVG
			// attribute, so anything that is not a plain 3- or 6-digit hex falls back to the default.
			$this->config['accent'] = preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $this->config['accent'] )
				? (string) $this->config['accent']
				: '#1717cc';

			// Record this product as sharing the consent (see $registered_products).
			if ( ! empty( $this->config['plugin_slug'] ) ) {
				self::$registered_products[ $this->config['plugin_slug'] ] = (string) $this->config['plugin_name'];
			}

			// Contract check (E2): everyone under one suite_key must share ONE opt_in_option, or the
			// suite-wide dismissed flag permanently locks the odd product out of ever being asked.
			$suite = (string) $this->config['suite_key'];
			if ( ! isset( self::$suite_contracts[ $suite ] ) ) {
				self::$suite_contracts[ $suite ] = (string) $this->config['opt_in_option'];
			} elseif ( self::$suite_contracts[ $suite ] !== (string) $this->config['opt_in_option'] ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Every product sharing suite_key "%s" must pass the same opt_in_option. Got "%s", the suite already uses "%s" — this product would never be asked for consent.',
						esc_html( $suite ),
						esc_html( (string) $this->config['opt_in_option'] ),
						esc_html( self::$suite_contracts[ $suite ] )
					),
					'2.2.0'
				);
			}

			$this->hooks();
		}

		/**
		 * Registers the notice, its AJAX handler and its inline assets.
		 */
		private function hooks(): void {
			add_action( 'admin_notices', array( $this, 'render' ) );
			add_action( 'wp_ajax_' . $this->config['ajax_action'], array( $this, 'handle_ajax' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		}

		/**
		 * Prints the notice's CSS/JS inline, only on screens where the notice itself will render.
		 */
		public function enqueue(): void {
			if ( ! $this->should_show() ) {
				return;
			}
			// Once per request, not once per product (E9): each active product's instance runs this
			// hook, and the CSS/JS are identical, so they were being attached N times.
			//
			// "Identical" is load-bearing, and it is a constraint on inline_css() rather than an
			// observation about it. This latch and render()'s are separate, and this hook runs first, so
			// the instance that supplies the stylesheet may not be the instance that supplies the markup.
			// inline_css() must therefore stay free of per-product values — see the note on it.
			if ( self::$assets_enqueued ) {
				return;
			}
			self::$assets_enqueued = true;

			wp_add_inline_style( 'wp-admin', $this->inline_css() );
			wp_add_inline_script( 'jquery', $this->inline_js() );
		}

		/**
		 * Outputs the notice. No-ops when consent is already answered or another suite product
		 * already rendered it this request.
		 */
		public function render(): void {
			if ( ! $this->should_show() ) {
				return;
			}
			// Suite-wide dedupe: if TPAE/Nexter Extension/Nexter Blocks are all active and each
			// instantiates this class, only the first one to run on this page actually renders.
			if ( self::$rendered_this_request ) {
				return;
			}
			self::$rendered_this_request = true;

			// Kept raw here and escaped at each point of output — pre-escaping made it impossible to
			// tell (for a reader or for PHPCS) whether a given echo was safe.
			$slug  = $this->config['plugin_slug'];
			$name  = $this->suite_display_name();
			$docs  = $this->config['docs_url'];
			$nonce = wp_create_nonce( $this->config['ajax_action'] );
			// Legacy prefix (host-stylesheet hook only) and this product's accent — see the constructor
			// defaults. The accent is emitted as an inline custom property below, NOT baked into the
			// shared stylesheet, so each product's notice keeps its own brand colour even though one
			// stylesheet serves them all.
			$px     = $this->config['css_prefix'];
			$accent = $this->config['accent'];
			// Deliberately NOT 'is-dismissible': the notice has its own explicit Dismiss button, and
			// core's injected "x" would be a second, redundant control sitting right beside it.
			//
			// posi-consent-bar is the second STABLE class the layout rules key off. It exists to carry
			// the two-class specificity those rules need (see inline_css()) without that specificity
			// depending on the legacy prefix, which is not guaranteed to match the stylesheet's.
			?>
		<div class="notice notice-info <?php echo esc_attr( $px ); ?>-notice-wrap posi-consent-notice posi-consent-bar posi-consent--<?php echo esc_attr( $slug ); ?>" id="posi-consent-<?php echo esc_attr( $slug ); ?>" style="--posi-accent:<?php echo esc_attr( $accent ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-action="<?php echo esc_attr( $this->config['ajax_action'] ); ?>">
			<span class="posi-notice-icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24"><path stroke="<?php echo esc_attr( $accent ); ?>" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 20V12M12 20V4m7 16v-6"/></svg>
			</span>
			<span class="posi-notice-text">
				<?php
				printf(
					/* translators: %s: product name */
					esc_html__( 'Help make %s faster and more stable. Share basic, non-sensitive info so we can catch conflicts and ship fixes quicker. No personal data, ever.', 'uichemy' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( "See what's shared", 'uichemy' ); ?> &rarr;</a>
			</span>
			<span class="posi-notice-actions">
				<button type="button" class="<?php echo esc_attr( $px ); ?>-nobtn-primary posi-consent-allow" data-choice="allow"><?php esc_html_e( 'Allow', 'uichemy' ); ?></button>
				<button type="button" class="<?php echo esc_attr( $px ); ?>-nobtn-secondary posi-consent-skip" data-choice="skip"><?php esc_html_e( 'Dismiss', 'uichemy' ); ?></button>
			</span>
		</div>
			<?php
		}

		/**
		 * How long "Dismiss" hides the notice for, in seconds.
		 *
		 * Dismiss is a SNOOZE, not a permanent no. The ask has to survive being ignored, because a
		 * notice that disappears forever on the first stray click means most sites are never asked and
		 * sharing stays off by default with no decision ever made. Only "Allow", or turning the setting
		 * on from Onboarding or Dashboard → Settings, stops it for good.
		 *
		 * It is a snooze rather than "show on every page load" on purpose: re-asking an administrator
		 * on every single screen is nagging, and WordPress's own guidance is that admin notices must be
		 * dismissible. 30 days keeps the ask alive without becoming that.
		 */
		const SNOOZE_SECONDS = 30 * DAY_IN_SECONDS;

		/**
		 * How long the notice stays quiet after install, in seconds.
		 *
		 * Filterable via posimyth_consent_notice_grace_seconds — see grace_period_ends().
		 */
		const GRACE_SECONDS = 2 * DAY_IN_SECONDS;

		/**
		 * Stores the Allow/Dismiss choice, suite-wide.
		 */
		/**
		 * The capability that may see and answer this notice.
		 *
		 * On multisite the consent is ONE answer for the whole network (the options below are site
		 * options), so only someone who speaks for the network may give it — a subsite admin would
		 * otherwise be answering for every other blog (A6).
		 *
		 * @return string
		 */
		private function required_capability(): string {
			return is_multisite() ? 'manage_network_options' : 'manage_options';
		}

		/**
		 * Stores the Allow/Dismiss choice, suite-wide.
		 */
		public function handle_ajax(): void {
			// Nonce AND capability, not nonce alone. Today the nonce is only ever printed inside markup
			// that render() already gates on the same capability, so this is not exploitable — but that
			// makes the handler's safety a property of its callers rather than of itself. This flips
			// consent for the whole site (network, on multisite), so it checks directly.
			if ( ! current_user_can( $this->required_capability() ) ) {
				wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
			}
			check_ajax_referer( $this->config['ajax_action'], 'nonce' );
			$choice = sanitize_key( $_POST['choice'] ?? 'skip' );

			// Both flags are keyed on suite_key, not on the plugin slug, so Nexter Extension and Nexter
			// Blocks share one answer and the user is never asked twice. Site options on purpose (A6):
			// on multisite one install means one consent, not one per blog — per-blog options produced
			// N notices and N heartbeats for a single network-activated install. On a single site,
			// *_site_option() falls back to the plain option of the same name, so nothing changes.
			if ( 'allow' === $choice ) {
				update_site_option( $this->config['opt_in_option'], 1 );
				// An explicit yes is final: never ask again, even if sharing is later turned back off.
				update_site_option( 'posi_consent_dismissed_' . $this->config['suite_key'], 1 );
				if ( is_callable( $this->config['tracker_cb'] ) ) {
					call_user_func( $this->config['tracker_cb'], 'activate' );
				}
			} else {
				update_site_option( $this->config['opt_in_option'], 0 );
				// Dismiss only snoozes — see SNOOZE_SECONDS.
				update_site_option( 'posi_consent_snoozed_until_' . $this->config['suite_key'], time() + self::SNOOZE_SECONDS );
			}

			wp_send_json_success();
		}

		/**
		 * Whether the notice should render for the current user, screen and consent state.
		 *
		 * @return bool
		 */
		private function should_show(): bool {
			// On multisite only someone who can answer for the whole network is asked — see
			// required_capability(). All three flags below are site options for the same reason.
			if ( ! current_user_can( $this->required_capability() ) ) {
				return false;
			}
			// Answered for good: "Allow" was clicked (see handle_ajax). Never ask again.
			if ( get_site_option( 'posi_consent_dismissed_' . $this->config['suite_key'] ) ) {
				return false;
			}

			// This notice, the Dashboard toggle and the Onboarding checkbox are three surfaces for ONE
			// suite-wide setting — not independent prompts. So if sharing is already enabled there is
			// nothing left to ask, and showing an "Allow / Dismiss" notice would be asking for
			// permission the user has already granted (and could even read as though it were off).
			// This is also what stops the notice when the user says yes from Onboarding or from
			// Dashboard → Settings rather than from the notice itself.
			if ( ! empty( $this->config['opt_in_option'] ) && ! empty( get_site_option( $this->config['opt_in_option'] ) ) ) {
				return false;
			}

			// Snoozed by a previous "Dismiss" — comes back when the snooze expires.
			$snoozed_until = (int) get_site_option( 'posi_consent_snoozed_until_' . $this->config['suite_key'], 0 );
			if ( $snoozed_until > time() ) {
				return false;
			}

			// Quiet for the first couple of days after install — see grace_period_ends().
			if ( $this->grace_period_ends() > time() ) {
				return false;
			}

			// NOTE: there is deliberately no "first successful use" gate any more.
			//
			// It used to require a saved setting before asking, so a fresh install was never asked at
			// all until the user happened to save something — and plenty of sites never do. The ask now
			// starts a couple of days after activation and keeps coming back (snoozed, not silenced)
			// until the user actually decides, either here or from Onboarding / Dashboard → Settings.
			//
			// installed_option is still accepted in the config for backwards compatibility, but it is
			// no longer consulted; mark_first_use() is now a no-op for gating purposes.
			return true;
		}

		/**
		 * When the post-install quiet period ends.
		 *
		 * A consent notice that appears in the same breath as activation competes with whatever the user
		 * actually came to do, and asking someone to share diagnostics about a plugin they have not used
		 * yet is a worse question than asking once they have. So the ask is delayed — not skipped: after
		 * this window it behaves exactly as before, coming back until the user decides.
		 *
		 * The start is stamped on the first admin load that would otherwise have shown the notice,
		 * rather than read from the tracker's install timestamp. That timestamp is a per-site option
		 * holding a date string, and this notice is answered once per network (see required_capability),
		 * so it is the wrong scope and the wrong type to gate a network-wide prompt. On a fresh install
		 * the two are the same moment anyway — activation lands the user on an admin screen — and on a
		 * site upgrading into this version it starts the window now instead of treating a months-old
		 * install as already overdue, which would show the notice on the very next page load.
		 *
		 * @return int Unix timestamp at which the notice may start showing.
		 */
		private function grace_period_ends(): int {
			$key   = 'posi_consent_grace_start_' . $this->config['suite_key'];
			$start = (int) get_site_option( $key, 0 );

			if ( $start <= 0 ) {
				$start = time();
				update_site_option( $key, $start );
			}

			/**
			 * Filters how long the data-sharing notice stays quiet after install.
			 *
			 * @param int    $seconds   Quiet period in seconds. Default 2 days.
			 * @param string $suite_key Suite the notice belongs to.
			 */
			$grace = (int) apply_filters( 'posimyth_consent_notice_grace_seconds', self::GRACE_SECONDS, (string) $this->config['suite_key'] );

			return $start + max( 0, $grace );
		}

		/**
		 * Product name to show in the copy.
		 *
		 * One notice is rendered for the whole suite and the consent it stores is suite-wide, so when
		 * more than one Nexter product is active naming just one of them ("Help make Nexter Blocks…")
		 * would misrepresent what the user is agreeing to — and which notice happened to render first
		 * is arbitrary. In that case fall back to the suite name ("Nexter"). With a single product
		 * active, its own name is the clearer, more specific label.
		 *
		 * @return string
		 */
		private function suite_display_name(): string {
			if ( count( self::$registered_products ) > 1 && ! empty( $this->config['suite_name'] ) ) {
				return (string) $this->config['suite_name'];
			}

			return (string) $this->config['plugin_name'];
		}

		/**
		 * Call this from the moment that counts as "first successful use" for this plugin
		 * (not activation) — e.g. first CPT/taxonomy/field-group created for Nexter Extension,
		 * first block inserted for Nexter Blocks, first widget dropped for TPAE.
		 */
		public function mark_first_use(): void {
			if ( ! get_option( $this->config['installed_option'], 0 ) ) {
				update_option( $this->config['installed_option'], time(), false );
			}
		}

		/**
		 * Compact inline notice, styled entirely from product-neutral classes.
		 *
		 * Deliberately a single row rather than a tall promo/licence block: this is an optional,
		 * low-priority ask that appears on ordinary admin screens, so it should not push the page down.
		 *
		 * CONTAINS NO PER-PRODUCT VALUE, AND MUST NOT. This stylesheet is emitted once per request
		 * behind a static latch (see enqueue()) whereas the markup is emitted behind a SEPARATE static
		 * latch in render(), and admin_enqueue_scripts runs before admin_notices — so on a multi-product
		 * site the copy that supplies the CSS is not necessarily the copy that supplies the markup. Any
		 * selector or colour taken from config therefore risked pairing one product's stylesheet with
		 * another's markup: identical prefixes hid it (everything matched by luck), differing prefixes
		 * would have matched nothing and shipped an unstyled notice.
		 *
		 * So selectors key only on the stable `posi-*` classes, which every product emits identically,
		 * and the one value that legitimately varies — the accent — arrives as the `--posi-accent`
		 * custom property set inline per instance in render(). One stylesheet, N brand colours. The
		 * fallback in each var() is this SDK's neutral and should never be reached, since render()
		 * always sets the property.
		 *
		 * Every declaration is repeated in full because this SDK ships inside products that run with no
		 * suite stylesheet present to supply them.
		 */
		private function inline_css(): string {
			return '
        /* Compact single-row layout: this is an optional ask, so it should not occupy a tall
           block at the top of every admin screen.
           The two-class selector is deliberate. A host stylesheet may already define a rule for the
           wrapper class on its own (one suite stylesheet zeroes its left padding), which has the same
           specificity as a single class and would otherwise win on load order, leaving the icon jammed
           against the border. Both classes here are this SDK\'s own and are always emitted together,
           so the specificity no longer depends on a configured prefix matching. */
        .posi-consent-notice.posi-consent-bar {
            border-left-color: var(--posi-accent, #1717cc);
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 12px;
            padding: 12px 16px;
        }
        .posi-consent-notice .posi-notice-icon {
            flex: none;
            line-height: 0;
            display: inline-flex;
        }
        .posi-consent-notice .posi-notice-text {
            flex: 1 1 auto;
            min-width: 0;
            font-size: 13px;
            line-height: 1.5;
            color: #1c1c1c;
        }
        .posi-consent-notice .posi-notice-text a { margin-left: 4px; white-space: nowrap; }
        .posi-consent-notice .posi-notice-actions {
            flex: none;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }
        .posi-consent-notice .posi-consent-allow,
        .posi-consent-notice .posi-consent-skip {
            padding: 7px 16px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            line-height: 18px;
            text-decoration: none;
            display: inline-flex;
            cursor: pointer;
            box-shadow: none;
            font-family: inherit;
        }
        .posi-consent-notice .posi-consent-allow,
        .posi-consent-notice .posi-consent-allow:hover,
        .posi-consent-notice .posi-consent-allow:focus {
            background-color: var(--posi-accent, #1717cc);
            border: 1px solid var(--posi-accent, #1717cc);
            color: #fff;
            outline: 0;
        }
        .posi-consent-notice .posi-consent-skip,
        .posi-consent-notice .posi-consent-skip:hover,
        .posi-consent-notice .posi-consent-skip:focus {
            background-color: #fff;
            color: var(--posi-accent, #1717cc);
            border: 1px solid var(--posi-accent, #1717cc);
            margin-left: 8px;
            padding: 8px 16px;
            outline: 0;
        }
        @media (max-width: 782px) {
            .posi-consent-notice.posi-consent-bar { flex-wrap: wrap; }
            .posi-consent-notice .posi-notice-actions { margin-top: 8px; }
        }
        ';
		}

		/**
		 * Notice behaviour: removes the notice the instant a choice is clicked, then stores that choice
		 * in the background.
		 *
		 * The request deliberately does NOT gate the hide. Waiting for it meant the notice sat there
		 * for the length of a round trip after the user had already answered, which reads as an ignored
		 * click. The click is the answer, so the UI acts on it immediately; if the request somehow
		 * fails, nothing was persisted and the notice simply appears again on the next page load —
		 * which is the correct outcome, not a lost choice.
		 *
		 * @return string
		 */
		private function inline_js(): string {
			return "
        jQuery(function($){
            function posiSend(notice, choice){
                if (!notice.length || notice.data('posiSent')) { return; }
                notice.data('posiSent', true);

                // Read the request details BEFORE touching the DOM: jQuery's .remove() also discards
                // the element's data, so reading data('action') / data('nonce') afterwards would send
                // action=undefined and the choice would never be stored.
                var payload = {
                    action: notice.data('action'),
                    choice: choice,
                    nonce:  notice.data('nonce')
                };

                // Gone immediately — before the request, not after it.
                notice.remove();

                $.post(ajaxurl, payload);
            }

            // The notice is not 'is-dismissible' — its own Dismiss button is the only close control,
            // so there is no core 'x' to bind (and no risk of a close that silently fails to store
            // the choice).
            $(document).on('click', '.posi-consent-allow, .posi-consent-skip', function(){
                var btn = $(this);
                posiSend(btn.closest('.posi-consent-notice'), btn.data('choice'));
            });
        });
        ";
		}
	}
}
