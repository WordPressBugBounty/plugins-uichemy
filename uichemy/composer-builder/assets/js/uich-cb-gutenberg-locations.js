/**
 * UiChemy Composer Builder — the page's header and footer in the block editor.
 *
 * The builder's canvas is the page itself, so a Theme Builder header and footer
 * render there, and hovering one offers "Edit Header" / "Edit Footer"
 * (src/editor/canvas-locations.js). The block editor only draws the post's
 * content, so the page was edited out of context and the way to its header
 * was the admin bar. This draws the same templates in the same places — the
 * header above the title, the footer under the content — with the same hover
 * outline and the same Edit tab.
 *
 * Which templates, and their URLs, come from PHP
 * (UiChemy_Locations::editor_locations, resolved against this post's display
 * conditions). Each one is drawn by its signed isolated preview in an iframe:
 * the template with its own styles, which the editor canvas does not carry,
 * and none of the canvas's editor styles leaking into it. It is a picture of
 * the template, not part of this document — not selectable, its links inert;
 * editing it means opening it, which Edit does.
 *
 * Plain DOM against the global `wp.*`, no build step, like uich-cb-gutenberg.js.
 * The canvas is React's, so the two hosts are re-placed whenever it re-renders
 * or the canvas iframe itself is rebuilt (device preview, template mode).
 */
( function ( wp ) {
	'use strict';

	var cfg = window.uichComposerBuilderLocations || {};
	var locations = ( cfg.locations || [] ).filter( function ( loc ) {
		return loc && loc.previewUrl && ( 'header' === loc.type || 'footer' === loc.type );
	} );

	if ( ! wp || ! wp.data || ! locations.length ) {
		return;
	}

	var HOST_ATTR = 'data-uich-gb-location';
	var INK = '#0D99FF'; // --uich-select-ink, the builder's selection colour
	var PENCIL = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';

	/**
	 * Where the post is drawn: the canvas iframe's body, or — when WordPress
	 * renders the canvas without an iframe — the styles wrapper in this document.
	 * Its children are the title wrapper and the block list.
	 *
	 * @return {Element|null}
	 */
	function canvasRoot() {
		var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		var doc = null;
		try {
			doc = frame ? frame.contentDocument : null;
		} catch ( e ) {
			doc = null;
		}
		if ( doc && doc.body && doc.body.querySelector( ':scope > .is-root-container' ) ) {
			return doc.body;
		}
		var wrapper = document.querySelector( '.editor-styles-wrapper' );
		return wrapper && wrapper.querySelector( ':scope > .is-root-container' ) ? wrapper : null;
	}

	/**
	 * One location's host: the preview, a shield over it, and the hover outline
	 * with its Edit tab — the builder canvas's look (canvas-locations.js).
	 *
	 * @param {Document} doc Canvas document.
	 * @param {Object}   loc Location from PHP.
	 * @return {Element}
	 */
	function buildHost( doc, loc ) {
		var footer = 'footer' === loc.type;

		var host = doc.createElement( 'div' );
		host.setAttribute( HOST_ATTR, loc.type );
		host.setAttribute( 'contenteditable', 'false' );
		// Inline, not a stylesheet: the canvas carries the theme's editor
		// styles, and a class of ours is one they could reach.
		host.style.cssText = 'position:relative;display:block;margin:0;padding:0;width:100%;max-width:none;';

		var frame = doc.createElement( 'iframe' );
		frame.src = loc.previewUrl;
		frame.title = loc.title || loc.type;
		frame.setAttribute( 'tabindex', '-1' );
		frame.setAttribute( 'aria-hidden', 'true' );
		// pointer-events:none — a picture of the template: its links must not
		// navigate the editor, and a sticky header must not trap the wheel.
		frame.style.cssText = 'display:block;width:100%;height:0;border:0;margin:0;padding:0;pointer-events:none;background:transparent;';
		host.appendChild( frame );

		var shield = doc.createElement( 'div' );
		shield.style.cssText = 'position:absolute;inset:0;z-index:1;cursor:default;';
		host.appendChild( shield );

		var outline = doc.createElement( 'div' );
		outline.style.cssText = 'position:absolute;inset:0;z-index:2;display:none;pointer-events:none;'
			+ 'outline:1px solid ' + INK + ';outline-offset:-1px;box-shadow:inset 0 0 0 9999px rgba(13,153,255,0.04);';
		host.appendChild( outline );

		if ( loc.editUrl ) {
			var btn = doc.createElement( 'button' );
			btn.type = 'button';
			btn.innerHTML = PENCIL + '<span></span>';
			btn.lastChild.textContent = loc.label || '';
			// On the edge that faces the page, centred like a tab: a header's
			// bottom, a footer's top — the builder canvas's placement.
			btn.style.cssText = 'position:absolute;left:50%;' + ( footer ? 'top:0;' : 'top:100%;' )
				+ 'transform:translate(-50%,-50%);z-index:3;display:none;align-items:center;gap:6px;'
				+ 'height:26px;padding:0 12px 0 10px;margin:0;border:0;border-radius:999px;cursor:pointer;white-space:nowrap;'
				+ 'background:' + INK + ';color:#fff;font:600 12px/1 ui-sans-serif,system-ui,-apple-system,sans-serif;letter-spacing:0;'
				+ 'box-shadow:0 0 0 2px #fff,0 4px 12px rgba(13,153,255,0.35);';
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				// The EDITOR window — this script's own, not the canvas frame and
				// not whatever may frame wp-admin. Leaving it this way still gets
				// WordPress's own unsaved-changes prompt.
				window.location.assign( loc.editUrl );
			} );
			host.appendChild( btn );
		}

		function show( on ) {
			outline.style.display = on ? 'block' : 'none';
			if ( btn ) {
				btn.style.display = on ? 'inline-flex' : 'none';
			}
		}
		host.addEventListener( 'mouseenter', function () { show( true ); } );
		host.addEventListener( 'mouseleave', function () { show( false ); } );

		// Not the editor's: a click here must not select, deselect or insert
		// blocks in the canvas underneath.
		[ 'mousedown', 'click' ].forEach( function ( type ) {
			shield.addEventListener( type, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
			} );
		} );

		// The preview reports its own height (views/preview.php), on load and
		// whenever it changes — late fonts, images, the template's scripts. Read
		// from a message, not the frame's document: that is out of reach when the
		// site and wp-admin are different origins. The message lands on the
		// window holding the frame, the canvas iframe's, not this one.
		var win = doc.defaultView || window;
		var answered = false;
		var silence = 0;
		function onMessage( e ) {
			var data = e.data;
			if ( e.source !== frame.contentWindow || ! data || 'uichemy-tb-preview-size' !== data.type ) {
				return;
			}
			answered = true;
			host.style.visibility = '';
			var h = Math.ceil( Number( data.height ) || 0 );
			if ( h > 0 && Math.abs( h - frame.offsetHeight ) > 1 ) {
				frame.style.height = h + 'px';
			}
		}
		win.addEventListener( 'message', onMessage );
		// And ask, once it has loaded: its own first report can land before
		// this listener exists (the canvas document is still being built), and
		// moving the frame in the DOM reloads it.
		//
		// No answer means the frame is not showing the template: the signed
		// link expired (nonces last a day, an editor tab can stay open longer)
		// or the session cookie did not reach the site. It then holds an
		// ordinary page, so it is collapsed and hidden rather than left showing
		// that page. Not display:none: the template inside must still lay out,
		// or it would measure 0 and never answer a later ask.
		frame.addEventListener( 'load', function () {
			answered = false;
			try {
				frame.contentWindow.postMessage( { type: 'uichemy-tb-preview-measure' }, '*' );
			} catch ( e ) { /* frame gone */ }
			window.clearTimeout( silence );
			silence = window.setTimeout( function () {
				if ( ! answered ) {
					host.style.visibility = 'hidden';
					frame.style.height = '0px';
				}
			}, 4000 );
		} );

		// Taken down with the host (the rendering-mode switch below): the
		// listener lives on a window that outlives it when the canvas is not
		// an iframe.
		host.uichDispose = function () {
			win.removeEventListener( 'message', onMessage );
			window.clearTimeout( silence );
		};

		return host;
	}

	/**
	 * Put each host in its place: the header right before the title (before
	 * the content when there is no title), the footer right after the content.
	 * Idempotent — called whenever the editor changes, it only touches the DOM
	 * when a host is missing or has been moved.
	 */
	function place() {
		var root = canvasRoot();
		if ( ! root ) {
			return;
		}
		// Only around the post alone. With "Show template" on (a block theme's
		// default for pages) the editor draws the whole template, its own
		// header and footer included, and the block list at the top is the
		// template's, not the post's: drawing ours too would put two headers on
		// the page. Taken down again if the mode switches while they are up.
		var editorStore = wp.data.select( 'core/editor' );
		var mode = editorStore && typeof editorStore.getRenderingMode === 'function' ? editorStore.getRenderingMode() : 'post-only';
		if ( mode && 'post-only' !== mode ) {
			Array.prototype.forEach.call( root.querySelectorAll( ':scope > [' + HOST_ATTR + ']' ), function ( host ) {
				if ( typeof host.uichDispose === 'function' ) {
					host.uichDispose();
				}
				host.remove();
			} );
			return;
		}
		var doc = root.ownerDocument;
		var content = root.querySelector( ':scope > .is-root-container' );
		var title = root.querySelector( ':scope > .editor-visual-editor__post-title-wrapper' );
		if ( ! content ) {
			return;
		}

		// Edge to edge, as on the page: the canvas body is padded (8px from the
		// editor's own styles), which would inset the header and footer like a
		// block. Read live, since a theme's editor styles can change it.
		var pad = ( doc.defaultView || window ).getComputedStyle( root );
		var padL = parseFloat( pad.paddingLeft ) || 0;
		var padR = parseFloat( pad.paddingRight ) || 0;
		var padT = parseFloat( pad.paddingTop ) || 0;

		locations.forEach( function ( loc ) {
			var host = root.querySelector( ':scope > [' + HOST_ATTR + '="' + loc.type + '"]' );
			if ( ! host ) {
				host = buildHost( doc, loc );
			}
			host.style.marginLeft = -padL + 'px';
			host.style.marginRight = -padR + 'px';
			host.style.width = 'calc(100% + ' + ( padL + padR ) + 'px)';
			if ( 'header' === loc.type ) {
				host.style.marginTop = -padT + 'px';
			}
			if ( 'header' === loc.type ) {
				var first = title || content;
				if ( host.nextElementSibling !== first ) {
					root.insertBefore( host, first );
				}
			} else if ( host.previousElementSibling !== content ) {
				root.insertBefore( host, content.nextSibling );
			}
		} );
	}

	// A timer, not requestAnimationFrame: a tab in the background runs no
	// frames, and the canvas must be right when it is brought back.
	var queued = 0;
	function schedule() {
		if ( queued ) {
			return;
		}
		queued = window.setTimeout( function () {
			queued = 0;
			place();
		}, 30 );
	}

	// The editor's store changes on every render that could rebuild the canvas
	// (mode switches, device preview, template view); place() is a no-op when
	// nothing moved, so following it is cheap.
	wp.data.subscribe( schedule );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}
	// The canvas iframe loads after the store settles on a fresh editor.
	document.addEventListener( 'load', function ( e ) {
		if ( e.target && 'IFRAME' === e.target.tagName ) {
			schedule();
		}
	}, true );
}( window.wp ) );
