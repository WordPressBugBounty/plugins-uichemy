// UiChemy — draws the page's Theme Builder header and footer around the Bricks builder canvas, with Edit Header / Edit Footer.
( function () {
	'use strict';

	var cfg = window.uichBricksLocations || {};
	var locations = ( cfg.locations || [] ).filter( function ( loc ) {
		return loc && loc.previewUrl && ( 'header' === loc.type || 'footer' === loc.type );
	} );
	if ( ! locations.length ) {
		return;
	}

	var HOST_ATTR = 'data-uich-bx-location';
	var INK = '#0D99FF';
	var PENCIL = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';

	/** The canvas's content area (`main#brx-content`), or null while it loads. */
	function contentArea() {
		var frame = document.getElementById( 'bricks-builder-iframe' );
		var doc = null;
		try {
			doc = frame ? frame.contentDocument : null;
		} catch ( e ) {
			doc = null;
		}
		return doc ? doc.getElementById( 'brx-content' ) : null;
	}

	function buildHost( doc, loc ) {
		var footer = 'footer' === loc.type;
		var host = doc.createElement( 'div' );
		host.setAttribute( HOST_ATTR, loc.type );
		// Inline styles: a class could be reached by the canvas's own CSS.
		host.style.cssText = 'position:relative;display:block;margin:0;padding:0;width:100%;max-width:none;';

		var frame = doc.createElement( 'iframe' );
		frame.src = loc.previewUrl;
		frame.title = loc.title || loc.type;
		frame.setAttribute( 'tabindex', '-1' );
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.style.cssText = 'display:block;width:100%;height:0;border:0;margin:0;padding:0;pointer-events:none;background:transparent;';
		host.appendChild( frame );

		// Not Bricks': a click here must not select or drop anything in the canvas.
		var shield = doc.createElement( 'div' );
		shield.style.cssText = 'position:absolute;inset:0;z-index:1;cursor:default;';
		host.appendChild( shield );
		[ 'mousedown', 'mouseup', 'click', 'dblclick', 'contextmenu' ].forEach( function ( type ) {
			shield.addEventListener( type, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
			} );
		} );

		var outline = doc.createElement( 'div' );
		outline.style.cssText = 'position:absolute;inset:0;z-index:2;display:none;pointer-events:none;'
			+ 'outline:1px solid ' + INK + ';outline-offset:-1px;box-shadow:inset 0 0 0 9999px rgba(13,153,255,0.04);';
		host.appendChild( outline );

		var btn = null;
		if ( loc.editUrl ) {
			btn = doc.createElement( 'button' );
			btn.type = 'button';
			btn.innerHTML = PENCIL + '<span></span>';
			btn.lastChild.textContent = loc.label || '';
			btn.style.cssText = 'position:absolute;left:50%;' + ( footer ? 'top:0;' : 'top:100%;' )
				+ 'transform:translate(-50%,-50%);z-index:3;display:none;align-items:center;gap:6px;'
				+ 'height:26px;padding:0 12px 0 10px;margin:0;border:0;border-radius:999px;cursor:pointer;white-space:nowrap;'
				+ 'background:' + INK + ';color:#fff;font:600 12px/1 ui-sans-serif,system-ui,-apple-system,sans-serif;letter-spacing:0;'
				+ 'box-shadow:0 0 0 2px #fff,0 4px 12px rgba(13,153,255,0.35);';
			[ 'mousedown', 'mouseup' ].forEach( function ( type ) {
				btn.addEventListener( type, function ( e ) {
					e.stopPropagation();
				} );
			} );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				// Navigate the builder window (Bricks prompts on unsaved changes).
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

		// The preview reports its height (views/preview.php) to the canvas window.
		var win = doc.defaultView || window;
		var answered = false;
		var silence = 0;
		win.addEventListener( 'message', function ( e ) {
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
		} );
		// No answer: collapse (not display:none) so a later answer can still size it.
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

		return host;
	}

	/** Put each host in its place; touches the DOM only when one is missing or moved. */
	function place() {
		var content = contentArea();
		if ( ! content || ! content.parentNode ) {
			return;
		}
		var doc = content.ownerDocument;
		var parent = content.parentNode;
		locations.forEach( function ( loc ) {
			var host = parent.querySelector( ':scope > [' + HOST_ATTR + '="' + loc.type + '"]' );
			if ( ! host ) {
				host = buildHost( doc, loc );
			}
			if ( 'header' === loc.type ) {
				if ( host.nextElementSibling !== content ) {
					parent.insertBefore( host, content );
				}
			} else if ( host.previousElementSibling !== content ) {
				parent.insertBefore( host, content.nextSibling );
			}
		} );
	}

	window.setInterval( place, 500 );
	place();
}() );
