<?php
/**
 * Uich_CB_Editor — the full-screen builder screen.
 *
 * Opened by `post.php?post=<id>&action=uich_composer_builder&_wpnonce=<nonce>`.
 * wp-admin/post.php includes wp-admin/admin.php, which fires
 * `admin_action_{$action}` before post.php decides what to render — so hooking
 * that action lets the builder take the request over BEFORE the block editor is
 * bootstrapped. This is the same core hook established page builders use for
 * their own editor screens, and it costs nothing on every other request.
 *
 * The screen is NOT a wp-admin page: it prints its own minimal HTML document so
 * the builder owns the whole viewport, with no admin menu, admin bar or admin
 * stylesheet to fight. Only what Uich_CB_Enqueue enqueued is printed, via
 * wp_print_styles() / wp_print_head_scripts() / wp_print_footer_scripts().
 *
 * Right now the document it prints is an empty mount point — the React app that
 * fills it renders a blank canvas on purpose (see composer-builder/src/).
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Editor' ) ) {

	/**
	 * Renders the builder screen.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Editor {

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'admin_action_' . UICH_CB_ACTION, array( __CLASS__, 'handle_request' ) );
		}

		/**
		 * Whether the current request is the builder screen.
		 *
		 * Useful to other code that has to behave differently while the builder
		 * owns the page (an enqueue that should stand down, for instance).
		 *
		 * @return bool
		 */
		public static function is_builder_request() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; handle_request() verifies the nonce before doing anything.
			return isset( $_REQUEST['action'] ) && UICH_CB_ACTION === $_REQUEST['action'];
		}

		/**
		 * Validate the request, then render the builder and stop.
		 *
		 * Every exit path is wp_die() rather than a redirect: this URL is only
		 * ever reached from our own button, so arriving here without a post, a
		 * nonce or the capability means something is wrong and saying so is more
		 * useful than silently bouncing.
		 *
		 * @return void
		 */
		public static function handle_request() {
			// Turned off in Settings > Page builders: an old link or bookmark
			// lands here instead of opening the builder.
			if ( function_exists( 'uich_cb_builder_enabled' ) && ! uich_cb_builder_enabled() ) {
				wp_die(
					esc_html__( 'The UiChemy Builder is turned off in UiChemy Settings > Page builders.', 'uichemy' ),
					esc_html__( 'Builder', 'uichemy' ),
					array( 'response' => 403 )
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified immediately below, against an action derived from this very value.
			$post_id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;

			if ( ! $post_id ) {
				wp_die(
					esc_html__( 'No page was specified for the builder.', 'uichemy' ),
					esc_html__( 'Builder', 'uichemy' ),
					array( 'response' => 400 )
				);
			}

			// Per-post nonce (see Uich_CB_Content::nonce_action) — a link minted
			// for one page cannot be replayed against another.
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, Uich_CB_Content::nonce_action( $post_id ) ) ) {
				// An old or bookmarked link: for someone who may edit the post, a
				// fresh link rather than core's "link has expired" dead end (M-10).
				// Still nothing happens without a click, so no CSRF.
				if ( current_user_can( 'edit_post', $post_id ) ) {
					$message  = '<h1>' . esc_html__( 'Open this page in UiChemy?', 'uichemy' ) . '</h1>';
					$message .= '<p>' . esc_html__( 'The link you followed has expired.', 'uichemy' ) . '</p>';
					$message .= '<p><a class="button button-primary" href="' . esc_url( Uich_CB_Content::edit_url( $post_id ) ) . '">' . esc_html__( 'Open in UiChemy', 'uichemy' ) . '</a> ';
					$message .= '<a class="button" href="' . esc_url( Uich_CB_Content::exit_url( $post_id ) ) . '">' . esc_html__( 'Cancel', 'uichemy' ) . '</a></p>';
					wp_die( wp_kses_post( $message ), esc_html__( 'Open in UiChemy', 'uichemy' ), array( 'response' => 200 ) );
				}
				wp_nonce_ays( Uich_CB_Content::nonce_action( $post_id ) );
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				wp_die(
					esc_html__( 'That page no longer exists.', 'uichemy' ),
					esc_html__( 'Builder', 'uichemy' ),
					array( 'response' => 404 )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die(
					esc_html__( 'You are not allowed to edit this page.', 'uichemy' ),
					esc_html__( 'Builder', 'uichemy' ),
					array( 'response' => 403 )
				);
			}

			if ( ! Uich_CB_Policy::can_edit( $post ) ) {
				wp_die(
					esc_html__( 'This content type cannot be edited with the builder.', 'uichemy' ),
					esc_html__( 'Builder', 'uichemy' ),
					array( 'response' => 400 )
				);
			}

			// A page with content made elsewhere: say what opening it will do,
			// and adopt that content only once the user says so (U-01). The
			// confirmation link is this URL plus `uich_adopt`, so it carries the
			// same per-post nonce checked above.
			if ( Uich_CB_Content::has_foreign_content( $post_id ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
				if ( empty( $_GET['uich_adopt'] ) ) {
					self::confirm_adopt( $post );
				}
				if ( ! Uich_CB_Content::adopt_post_content( $post_id ) ) {
					wp_die(
						esc_html__( 'The existing content could not be imported, so the page was left as it is.', 'uichemy' ),
						esc_html__( 'Builder', 'uichemy' ),
						array( 'response' => 500 )
					);
				}
			}

			// Edited in the block editor since the builder last saved: those
			// edits are not on the site, and saving here replaces them (U-03).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			if ( empty( $_GET['uich_keep'] ) && Uich_CB_Content::edited_elsewhere( $post_id ) ) {
				self::confirm_edited_elsewhere( $post );
			}

			// Someone else has it open: say who, the way WordPress does (A-04).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			if ( empty( $_GET['uich_take_over'] ) ) {
				$locked_by = function_exists( 'wp_check_post_lock' ) ? wp_check_post_lock( $post_id ) : false;
				if ( $locked_by ) {
					self::confirm_take_over( $post, (int) $locked_by );
				}
			}
			self::refresh_lock( $post_id );

			// Opening the builder is what claims the post for it. Recorded on the
			// way in rather than on first save, so a page opened and abandoned
			// still reports itself as a builder page to the block-editor button.
			Uich_CB_Content::set_enabled( $post_id, true );

			// A post straight from "Add New" is an auto-draft, which WordPress
			// hides and then deletes. Opened here, it becomes a draft, so leaving
			// without publishing still leaves the page behind.
			Uich_CB_Content::claim_as_draft( $post_id );

			/**
			 * Fires before the builder screen is rendered.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id Post being edited.
			 */
			do_action( 'uich_cb/editor/init', $post_id );

			// The screen replaces wp-admin for this request: no admin bar, and
			// nothing about it should ever be cached.
			show_admin_bar( false );
			nocache_headers();

			Uich_CB_Enqueue::editor_assets( $post_id );

			self::render( $post_id );

			exit;
		}

		/**
		 * Ask before taking over a page that already has content, then stop.
		 *
		 * @param WP_Post $post Post being opened.
		 * @return void
		 */
		private static function confirm_adopt( WP_Post $post ) {
			$import = add_query_arg( 'uich_adopt', '1', Uich_CB_Content::edit_url( $post->ID ) );
			$cancel = Uich_CB_Content::exit_url( $post->ID );

			$elementor = 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );

			$message  = '<h1>' . esc_html__( 'This page already has content', 'uichemy' ) . '</h1>';
			$message .= '<p>' . esc_html__( 'UiChemy will import it as one HTML section, so you can keep editing it here. Blocks and shortcodes are converted to their current HTML, so dynamic blocks (latest posts, forms) stop updating on their own.', 'uichemy' ) . '</p>';
			if ( $elementor ) {
				$message .= '<p><strong>' . esc_html__( 'This page is built with Elementor. After the import, UiChemy shows it instead of the Elementor layout.', 'uichemy' ) . '</strong></p>';
			}
			$message .= '<p>' . esc_html__( 'The page as it is now is saved as a revision first, so you can restore it from its history.', 'uichemy' ) . '</p>';
			$message .= '<p><a class="button button-primary" href="' . esc_url( $import ) . '">' . esc_html__( 'Import and open in UiChemy', 'uichemy' ) . '</a> ';
			$message .= '<a class="button" href="' . esc_url( $cancel ) . '">' . esc_html__( 'Cancel', 'uichemy' ) . '</a></p>';

			wp_die( wp_kses_post( $message ), esc_html__( 'Open in UiChemy', 'uichemy' ), array( 'response' => 200 ) );
		}

		/**
		 * Mark the post as being edited by the current user (core's post lock,
		 * which the block editor and the Posts list read too).
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function refresh_lock( $post_id ) {
			if ( ! function_exists( 'wp_set_post_lock' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			if ( function_exists( 'wp_set_post_lock' ) && current_user_can( 'edit_post', (int) $post_id ) ) {
				wp_set_post_lock( (int) $post_id );
			}
		}

		/**
		 * Say who else is editing this post, then stop.
		 *
		 * @param WP_Post $post    Post being opened.
		 * @param int     $user_id User holding the lock.
		 * @return void
		 */
		private static function confirm_take_over( WP_Post $post, $user_id ) {
			$user = get_userdata( $user_id );
			$name = $user ? $user->display_name : __( 'Someone else', 'uichemy' );
			$open = add_query_arg( 'uich_take_over', '1', Uich_CB_Content::edit_url( $post->ID ) );

			/* translators: %s: user's display name. */
			$message  = '<h1>' . esc_html( sprintf( __( '%s is currently editing this page', 'uichemy' ), $name ) ) . '</h1>';
			$message .= '<p>' . esc_html__( 'If you both save, one of you will be asked to choose which version to keep. Taking over lets you edit now.', 'uichemy' ) . '</p>';
			$message .= '<p><a class="button button-primary" href="' . esc_url( $open ) . '">' . esc_html__( 'Take over', 'uichemy' ) . '</a> ';
			$message .= '<a class="button" href="' . esc_url( Uich_CB_Content::exit_url( $post->ID ) ) . '">' . esc_html__( 'Go back', 'uichemy' ) . '</a></p>';

			wp_die( wp_kses_post( $message ), esc_html__( 'Open in UiChemy', 'uichemy' ), array( 'response' => 200 ) );
		}

		/**
		 * Say that a builder page was edited elsewhere, then stop.
		 *
		 * @param WP_Post $post Post being opened.
		 * @return void
		 */
		private static function confirm_edited_elsewhere( WP_Post $post ) {
			$open      = add_query_arg( 'uich_keep', '1', Uich_CB_Content::edit_url( $post->ID ) );
			$revisions = wp_get_post_revisions_url( $post->ID );

			$message  = '<h1>' . esc_html__( 'This page was changed outside UiChemy', 'uichemy' ) . '</h1>';
			$message .= '<p>' . esc_html__( 'It was saved in another editor (for example the block editor) after its last UiChemy save. Those changes are not shown on the site, and the next save in UiChemy replaces them.', 'uichemy' ) . '</p>';
			$message .= '<p>' . esc_html__( 'They are kept in the page\'s revisions, so you can copy anything you need from there into UiChemy.', 'uichemy' ) . '</p>';
			$message .= '<p><a class="button button-primary" href="' . esc_url( $open ) . '">' . esc_html__( 'Open in UiChemy', 'uichemy' ) . '</a> ';
			if ( $revisions ) {
				$message .= '<a class="button" href="' . esc_url( $revisions ) . '">' . esc_html__( 'View revisions', 'uichemy' ) . '</a> ';
			}
			$message .= '<a class="button" href="' . esc_url( Uich_CB_Content::exit_url( $post->ID ) ) . '">' . esc_html__( 'Cancel', 'uichemy' ) . '</a></p>';

			wp_die( wp_kses_post( $message ), esc_html__( 'Open in UiChemy', 'uichemy' ), array( 'response' => 200 ) );
		}

		/**
		 * Print the builder document.
		 *
		 * @param int $post_id Post being edited.
		 * @return void
		 */
		private static function render( $post_id ) {
			$post  = get_post( $post_id );
			$title = $post instanceof WP_Post ? $post->post_title : '';

			if ( '' === trim( (string) $title ) ) {
				$title = __( '(no title)', 'uichemy' );
			}

			/* translators: 1: page title, 2: product name, e.g. "UiChemy". */
			$document_title = sprintf( __( '%1$s — %2$s', 'uichemy' ), $title, uich_brand_name() );

			?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="referrer" content="strict-origin-when-cross-origin" />
	<title><?php echo esc_html( $document_title ); ?></title>
	<script>
		/* The Composer theme (Dashboard > Settings > Composer theme) lives in this
		   browser's localStorage, so PHP cannot know it. Mark <html> before first
		   paint so the pre-mount background and the loader match it; Studio.jsx
		   keeps the class in sync after mount. */
		try { if ( window.localStorage.getItem( 'uich-composer-theme' ) === 'light' ) { document.documentElement.className += ' uipb-theme-light'; } } catch ( e ) {}
	</script>
			<?php
			wp_print_styles();
			wp_print_head_scripts();

			/**
			 * Fires inside the builder screen's <head>, after its own assets.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id Post being edited.
			 */
			do_action( 'uich_cb/editor/head', $post_id );
			?>
</head>
<body class="uich-cb-body<?php echo is_rtl() ? ' rtl' : ''; ?>">
			<?php if ( Uich_CB_Enqueue::has_build() ) : ?>
	<!--
		Left EMPTY on purpose. The vendored editor mounts React straight into
		this node (src/editor/index.jsx), and anything placed inside would sit
		underneath its tree until React's first commit removed it. The dark
		background in uich-cb-editor.css is what covers the mount delay.
	-->
	<div id="<?php echo esc_attr( UICH_CB_MOUNT ); ?>"></div>
	<?php
	// White label on: its own logo, or no mark at all - never UiChemy's.
	$uich_cb_wl      = function_exists( 'uich_brand_wl' ) ? uich_brand_wl() : array();
	$uich_cb_wl_logo = function_exists( 'uich_brand_logo_url' ) ? uich_brand_logo_url() : '';
	?>
	<?php /* translators: %s: product name, e.g. "UiChemy". */ ?>
	<div id="uich-cb-loader" class="uich-cb-loader" role="status" aria-live="polite" aria-label="<?php echo esc_attr( sprintf( __( 'Loading %s Builder...', 'uichemy' ), function_exists( 'uich_brand_name' ) ? uich_brand_name() : 'UiChemy' ) ); ?>">
		<!-- The same loading screen as app.uichemy.com: the UiChemy mark, a loading bar under
			 it, and a warm glow over a dot grid along the bottom. No sheen over the mark. -->
		<div class="uich-cb-loader__glow" aria-hidden="true"></div>
		<div class="uich-cb-loader__dots" aria-hidden="true"></div>
		<div class="uich-cb-loader__stack">
		<?php if ( ! empty( $uich_cb_wl ) ) : ?>
			<?php if ( '' !== $uich_cb_wl_logo ) : ?>
		<img class="uich-cb-loader__mark" src="<?php echo esc_url( $uich_cb_wl_logo ); ?>" width="64" height="64" alt="" />
			<?php endif; ?>
		<?php else : ?>
		<svg class="uich-cb-loader__mark" viewBox="8.8 8.8 22.4 22.4" width="64" height="64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
			<defs>
				<linearGradient id="uich-cb-loader-fill" x1="30.7" y1="9.3" x2="9.3" y2="30.7" gradientUnits="userSpaceOnUse">
					<stop offset="0%" stop-color="#FFB45C" />
					<stop offset="45%" stop-color="#F6765F" />
					<stop offset="100%" stop-color="#B24FDA" />
				</linearGradient>
				<g id="uich-cb-loader-shape">
					<path fill-rule="evenodd" d="M21.9575 25.2228C21.9575 25.2228 18.0147 25.4458 18.0147 22.4626V14.8558C18.0147 14.1305 17.8694 13.4123 17.5869 12.7423C17.3044 12.0722 16.8903 11.4634 16.3684 10.9506C15.8464 10.4378 15.2268 10.031 14.5448 9.75358C13.8629 9.47611 13.132 9.33337 12.3939 9.3335H9.32812V24.4952C9.32812 26.1242 9.98669 27.6865 11.1589 28.8384C12.3312 29.9903 13.9211 30.6374 15.5789 30.6374H15.5875C16.0116 30.6766 16.4386 30.6766 16.8627 30.6374H24.328C26.0078 30.6374 27.6187 29.9817 28.8064 28.8146C29.9942 27.6475 30.6615 26.0645 30.6615 24.4139V20.6417H21.9777L21.9575 25.2228ZM22.7268 21.3778H29.9056V24.4092C29.9033 25.8621 29.3149 27.2548 28.2694 28.2822C27.2239 29.3095 25.8066 29.8877 24.328 29.8899H20.791C21.168 29.593 21.4979 29.2427 21.77 28.8505C22.6086 27.6334 22.7163 26.2717 22.7163 25.2238L22.7268 21.3778Z" />
					<path d="M27.0284 9.3335C25.6878 9.3335 24.402 9.85673 23.454 10.7881C22.5059 11.7195 21.9732 12.9828 21.9729 14.3002V17.2786H30.6596V9.3335H27.0284Z" />
				</g>
			</defs>
			<use href="#uich-cb-loader-shape" fill="url(#uich-cb-loader-fill)" />
		</svg>
		<?php endif; ?>
		<div class="uich-cb-loader__bar" aria-hidden="true"><span></span></div>
		</div>
	</div>
	<script>
		/*
		 * The loader's bar fills to 100%. It eases towards 90% on its own while
		 * the editor loads, steps up at real milestones (the editor's script,
		 * the canvas: src/editor/index.jsx calls __uipbLoaderProgress), and
		 * fills to 100% the moment the editor is ready (__uipbLoaderDone),
		 * holds there a beat, then fades. 8s is the backstop.
		 */
		(function () {
			window.__uipbStartTime = Date.now();
			var l = document.getElementById('uich-cb-loader');
			if (!l) { return; }
			var bar = l.querySelector('.uich-cb-loader__bar span');
			var p = 0, floor = 0, done = false, timer;
			function paint() {
				if (bar) { bar.style.transform = 'scaleX(' + (p / 100) + ')'; }
			}
			timer = setInterval(function () {
				if (done) { return; }
				var next = p + Math.max(0.3, (90 - p) * 0.05);
				p = Math.min(90, Math.max(next, floor));
				paint();
			}, 60);
			window.__uipbLoaderProgress = function (n) {
				if (done) { return; }
				floor = Math.min(95, Math.max(floor, n));
				if (p < floor) { p = floor; paint(); }
			};
			window.__uipbLoaderDone = function () {
				if (done) { return; }
				done = true;
				clearInterval(timer);
				p = 100;
				paint();
				setTimeout(function () {
					l.classList.add('uich-cb-loader--fade-out');
					setTimeout(function () { if (l.parentNode) { l.parentNode.removeChild(l); } }, 450);
				}, 350);
			};
			setTimeout(window.__uipbLoaderDone, 8000);
		})();
	</script>
			<?php else : ?>
	<div class="uich-cb-boot">
		<p class="uich-cb-boot__text"><?php echo esc_html__( 'The builder assets have not been built yet.', 'uichemy' ); ?></p>
		<p class="uich-cb-boot__hint"><code>cd new-dashboard &amp;&amp; npm run build</code></p>
		<p><a class="uich-cb-boot__link" href="<?php echo esc_url( Uich_CB_Content::exit_url( $post_id ) ); ?>"><?php echo esc_html__( 'Back to WordPress', 'uichemy' ); ?></a></p>
	</div>
			<?php endif; ?>
			<?php
			wp_print_footer_scripts();

			/**
			 * Fires at the end of the builder screen, before the document closes.
			 *
			 * @since 5.2.1
			 *
			 * @param int $post_id Post being edited.
			 */
			do_action( 'uich_cb/editor/footer', $post_id );
			?>
</body>
</html>
			<?php
		}
	}
}
