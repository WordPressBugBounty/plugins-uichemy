/**
 * UiChemy Composer Builder — block-editor integration.
 *
 * Adds "Edit with UiChemy" to the block editor in the two places a page-builder
 * integration is expected to put it:
 *
 *   1. a button in the editor header, at the top of the screen next to Save /
 *      Preview / Publish — the primary entry point;
 *   2. a "UiChemy" panel in the document sidebar — the stable fallback, because
 *      the header is plain DOM (WordPress exposes no header slot for plugins)
 *      and its class names have changed across releases.
 *
 * Written against the global `wp.*` APIs so it needs no build step, matching the
 * merged runtime's uichemy-tb-editor.js. Enqueued only on supported post edit
 * screens — see Uich_CB_Gutenberg.
 *
 * Before navigating it makes sure the post actually exists on the server: a
 * brand-new page is an auto-draft whose content has never been saved, and
 * handing the builder an unsaved post would lose whatever is in the editor.
 *
 * And it makes sure coming BACK lands on this post, showing what the builder
 * saved — see markLeavingForBuilder() and refreshOnReturn().
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.data ) {
		return;
	}

	var cfg = window.uichComposerBuilderEditor || {};
	var i18n = cfg.i18n || {};

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Button = wp.components && wp.components.Button;

	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in newer
	// WordPress releases. Read wp.editor FIRST: on a current WordPress the
	// wp.editPost copy still resolves but logs a deprecation warning on every
	// editor load, and preferring it would emit that warning forever.
	var editPost = wp.editPost || {};
	var editor = wp.editor || {};
	var DocumentPanel = editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;

	/* ------------------------------------------------------------------ *
	 * Coming back from the builder
	 * ------------------------------------------------------------------ */

	var RETURN_KEY = 'uich-cb-left-for-builder';

	/**
	 * Get this screen ready to be come back to from the builder.
	 *
	 * Two things, both needed for Back to land on THIS post, up to date:
	 *
	 * 1. The history entry points at the post's own edit screen. A new page is
	 *    still on post-new.php here: WordPress only rewrites the address once a
	 *    save takes the post out of auto-draft, and savePost() does not save an
	 *    empty page at all (it returns early when !isEditedPostSaveable()). The
	 *    builder names and saves it (Uich_CB_Content::claim_as_draft), but Back
	 *    to post-new.php makes WordPress create a brand-new empty page — "Add
	 *    title" — instead of showing the one just named.
	 * 2. A note that the editor was left for the builder — see refreshOnReturn().
	 */
	function markLeavingForBuilder() {
		if ( cfg.exitUrl && /\/post-new\.php$/.test( window.location.pathname ) ) {
			try {
				window.history.replaceState( window.history.state, '', cfg.exitUrl );
			} catch ( e ) {
				// A browser refusing replaceState keeps the old behaviour.
			}
		}
		try {
			window.sessionStorage.setItem( RETURN_KEY, '1' );
		} catch ( e ) {
			// Storage blocked: the return is simply not refreshed.
		}
	}

	/**
	 * Go to the builder, ready to come back.
	 *
	 * @param {string} url The builder URL.
	 */
	function leaveForBuilder( url ) {
		markLeavingForBuilder();
		window.location.href = url;
	}

	/**
	 * Reload the editor when the browser brings it back from its page cache
	 * after a visit to the builder.
	 *
	 * The builder's Back is `history.back()`, and so is the browser's own back
	 * button. Both can restore this screen from the back/forward cache: the
	 * page exactly as it was left, editor store included — so it shows the
	 * title and content from BEFORE the builder saved, until a manual refresh.
	 *
	 * That is worse than a stale label. The builder writes post_title and the
	 * post_content mirror (Uich_CB_WPContent); pressing Update in the restored
	 * editor would save the old title and content back over them. Patching the
	 * title into the store would leave the content half of that in place, so
	 * the editor is reloaded instead — from the address markLeavingForBuilder()
	 * left, which is this post's edit screen.
	 *
	 * Only after leaving for the builder: a cache restore from anywhere else
	 * keeps the page as the browser saved it. Nothing unsaved is lost, because
	 * openBuilder() saves a dirty post before it leaves.
	 */
	function refreshOnReturn() {
		// The admin bar's "Edit with UiChemy" is a plain link that never goes
		// through openBuilder(), so mark the way out for it here too. Capture
		// phase, so it runs whatever the link's own handlers do.
		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a[href*="action=uich_composer_builder"]' ) : null;
			if ( link ) {
				markLeavingForBuilder();
			}
		}, true );

		window.addEventListener( 'pageshow', function ( event ) {
			var left = null;
			try {
				left = window.sessionStorage.getItem( RETURN_KEY );
				window.sessionStorage.removeItem( RETURN_KEY );
			} catch ( e ) {
				return;
			}

			// `persisted` is the cache restore. A normal load (the browser did
			// not cache the page) already came from the server and is current.
			if ( ! event.persisted || null === left ) {
				return;
			}

			// Hidden while it reloads, so the stale title does not flash up.
			document.documentElement.style.visibility = 'hidden';
			window.location.reload();
		} );
	}

	/**
	 * Open the builder, saving first when the post is not safely on the server.
	 *
	 * @param {Function} setBusy Receives a status string, or '' when idle.
	 */
	function openBuilder( setBusy ) {
		var url = cfg.editUrl;

		if ( ! url ) {
			window.alert( i18n.failed || 'Could not open the builder.' ); // eslint-disable-line no-alert
			return;
		}

		var core = wp.data.select( 'core/editor' );

		// No editor store (an unusual screen) — just go; the server revalidates
		// everything this check is guessing at.
		if ( ! core ) {
			setBusy( i18n.opening || '' );
			leaveForBuilder( url );
			return;
		}

		var status = core.getEditedPostAttribute ? core.getEditedPostAttribute( 'status' ) : '';
		var dirty = core.isEditedPostDirty ? core.isEditedPostDirty() : false;
		var isNew = core.isCleanNewPost ? core.isCleanNewPost() : false;
		var needsSave = dirty || isNew || 'auto-draft' === status;

		if ( ! needsSave ) {
			setBusy( i18n.opening || '' );
			leaveForBuilder( url );
			return;
		}

		setBusy( i18n.saving || '' );

		var dispatch = wp.data.dispatch( 'core/editor' );
		var saving = dispatch && dispatch.savePost ? dispatch.savePost() : null;

		if ( ! saving || ! saving.then ) {
			setBusy( '' );
			window.alert( i18n.failed || 'Could not open the builder.' ); // eslint-disable-line no-alert
			return;
		}

		saving
			.then( function () {
				// The post id never changes for an auto-draft that gets saved —
				// WordPress created the row before the editor loaded — so the
				// URL localized at enqueue time, nonce included, stays valid.
				setBusy( i18n.opening || '' );
				leaveForBuilder( url );
			} )
			.catch( function () {
				setBusy( '' );
				window.alert( i18n.failed || 'Could not open the builder.' ); // eslint-disable-line no-alert
			} );
	}

	/* ------------------------------------------------------------------ *
	 * 1. Header button
	 * ------------------------------------------------------------------ */

	var BUTTON_ID = 'uich-cb-header-button';

	/**
	 * UiChemy's Paste button (assets/js/uich-copy-button.js), when it is on
	 * this screen. The two UiChemy actions sit side by side, Paste first.
	 */
	var PASTE_SELECTOR = '.uich-paste-clipboard-wrap';

	/**
	 * The left-hand document tools (inserter, undo/redo …), where Paste goes.
	 */
	var LEFT_TOOLS_SELECTOR = '.editor-document-tools__left';

	/**
	 * Where the button belongs right now.
	 *
	 * Beside Paste when Paste is there; in the left tools when it is not (a
	 * screen that does not load the paste script, or before it has mounted);
	 * on the right, beside Save/Preview/Publish, only when neither exists — an
	 * older header this was not written against.
	 *
	 * @return {{parent: Element, after: (Element|null)}|null} Parent and the
	 *     node to follow (null = first child on the right, last child on the
	 *     left), or null while the header is still mounting.
	 */
	function placement() {
		var paste = document.querySelector( PASTE_SELECTOR );
		if ( paste && paste.parentNode ) {
			return { parent: paste.parentNode, after: paste };
		}

		var left = document.querySelector( LEFT_TOOLS_SELECTOR );
		if ( left ) {
			return { parent: left, after: left.lastElementChild };
		}

		var right = headerTarget();
		return right ? { parent: right, after: null } : null;
	}

	/**
	 * Is the button already where placement() says?
	 *
	 * @param {Element} button The header button.
	 * @param {{parent: Element, after: (Element|null)}} spot Target.
	 * @return {boolean} Whether it is.
	 */
	function isPlaced( button, spot ) {
		if ( button.parentNode !== spot.parent ) {
			return false;
		}

		// Beside Paste means directly after it. Anywhere else, being in the
		// right container is enough — requiring "last" in the left tools would
		// move the button every time WordPress appends a tool.
		if ( spot.after && spot.after.matches( PASTE_SELECTOR ) ) {
			return button.previousElementSibling === spot.after;
		}

		return true;
	}

	/**
	 * Put the button at a spot.
	 *
	 * @param {Element} button The header button.
	 * @param {{parent: Element, after: (Element|null)}} spot Target.
	 */
	function place( button, spot ) {
		if ( spot.after ) {
			spot.parent.insertBefore( button, spot.after.nextSibling );
			return;
		}

		// Right-hand fallback: first child, so it reads before Save/Preview
		// rather than after the publish control, where the eye goes last.
		spot.parent.insertBefore( button, spot.parent.firstChild );
	}

	/**
	 * The right-hand header container, across WordPress versions — only the
	 * fallback now; see placement().
	 *
	 * 6.6+ unified the post and site editors on `.editor-header__settings`;
	 * older releases used the edit-post prefix.
	 *
	 * @return {Element|null} The container, or null while the header is still mounting.
	 */
	function headerTarget() {
		var selectors = [
			'.editor-header__settings',
			'.edit-post-header__settings',
			'.edit-post-header__toolbar',
			'.editor-header__toolbar',
		];

		for ( var i = 0; i < selectors.length; i++ ) {
			var node = document.querySelector( selectors[ i ] );
			if ( node ) {
				return node;
			}
		}

		return null;
	}

	/**
	 * Insert the header button once, and keep it beside Paste.
	 *
	 * Paste is added by a separate script on its own schedule, so it can land
	 * after this button has already mounted in the left tools. Checking the
	 * position on every call — not only whether the button exists — is what
	 * moves it to Paste's right when that happens. Moving it fires the
	 * observer again, and the second call finds it placed and does nothing.
	 */
	function mountHeaderButton() {
		var spot = placement();
		var existing = document.getElementById( BUTTON_ID );

		if ( existing ) {
			if ( spot && ! isPlaced( existing, spot ) ) {
				place( existing, spot );
			}
			return true;
		}

		if ( ! spot ) {
			return false;
		}

		var button = document.createElement( 'button' );
		button.id = BUTTON_ID;
		button.type = 'button';
		button.className = 'uich-cb-header-button';

		if ( cfg.logoUrl ) {
			var img = document.createElement( 'img' );
			img.src = cfg.logoUrl;
			img.alt = '';
			img.setAttribute( 'aria-hidden', 'true' );
			// The `--mono` modifier is what carries the white-out filter, and it
			// is added only for OUR single-colour mark. A white-labelled logo is
			// the customer's own artwork and is drawn as uploaded.
			img.className = 'uich-cb-header-button__logo'
				+ ( cfg.logoMono ? ' uich-cb-header-button__logo--mono' : '' );
			button.appendChild( img );
		}

		var label = document.createElement( 'span' );
		label.className = 'uich-cb-header-button__label';
		label.textContent = i18n.edit || 'Edit with UiChemy';
		button.appendChild( label );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			openBuilder( function ( status ) {
				if ( status ) {
					label.textContent = status;
				} else {
					label.textContent = i18n.edit || 'Edit with UiChemy';
					button.disabled = false;
				}
			} );
		} );

		place( button, spot );

		return true;
	}

	/**
	 * Keep the button present.
	 *
	 * The header is a React tree that remounts (entering/leaving fullscreen,
	 * switching to the code editor, opening the site editor frame), and each
	 * remount drops anything inserted by hand. Observing the body and
	 * re-inserting is cheaper and more robust than guessing at the right moment.
	 */
	function watchHeader() {
		if ( typeof MutationObserver === 'undefined' ) {
			mountHeaderButton();
			return;
		}

		var observer = new MutationObserver( function () {
			mountHeaderButton();
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		mountHeaderButton();
	}

	/* ------------------------------------------------------------------ *
	 * 2. Document sidebar panel (fallback)
	 * ------------------------------------------------------------------ */

	function BuilderPanel() {
		var busyState = useState( '' );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		// Only worth showing when the header button is not there — otherwise the
		// same action appears twice on one screen.
		var visibleState = useState( false );
		var visible = visibleState[ 0 ];
		var setVisible = visibleState[ 1 ];

		useEffect( function () {
			var timer = window.setTimeout( function () {
				setVisible( ! document.getElementById( BUTTON_ID ) );
			}, 1500 );

			return function () {
				window.clearTimeout( timer );
			};
		}, [] );

		if ( ! visible ) {
			return null;
		}

		return el(
			DocumentPanel,
			{
				name: 'uich-composer-builder',
				title: i18n.panel || cfg.brand || 'UiChemy',
				className: 'uich-cb-panel',
			},
			el( 'p', { className: 'uich-cb-panel__intro' }, i18n.intro || '' ),
			el(
				Button,
				{
					variant: 'primary',
					isBusy: !! busy,
					disabled: !! busy,
					onClick: function () {
						openBuilder( setBusy );
					},
				},
				busy || i18n.edit || 'Edit with UiChemy'
			)
		);
	}

	if ( wp.plugins && wp.plugins.registerPlugin && DocumentPanel && Button ) {
		wp.plugins.registerPlugin( 'uich-composer-builder', { render: BuilderPanel } );
	}

	refreshOnReturn();

	/*
	 * A page built with the builder shows its builder document on the site,
	 * not what this editor saves, and the next builder save replaces
	 * post_content. Say so, where it cannot be missed, with the way back.
	 */
	if ( cfg.managed && i18n.managed && wp.data.dispatch( 'core/notices' ) ) {
		wp.data.dispatch( 'core/notices' ).createWarningNotice( i18n.managed, {
			id: 'uich-cb-managed',
			isDismissible: false,
			actions: [
				{
					label: i18n.edit || 'Edit with UiChemy',
					onClick: function () {
						openBuilder( function () {} );
					},
				},
			],
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', watchHeader );
	} else {
		watchHeader();
	}
}( window.wp ) );
