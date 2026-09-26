/**
 * UiChemy Composer — "Run with": load a dependency when an element needs it.
 *
 * An author points a dependency at a CSS selector in the Assets panel. Its tag
 * is then written as an inert carrier rather than a real one:
 *
 *   <script type="uich/deferred"
 *           data-uich-run-with="#hero-3d"
 *           data-uich-kind="script|style"
 *           data-uich-src="…"
 *           data-uich-attrs="defer module"></script>
 *
 * `uich/deferred` is not a script type any engine executes, so the browser never
 * fetches it. This file watches the named element and builds the real tag as it
 * approaches the screen.
 *
 * Nothing about the page's markup changes. The element and its CSS are in the
 * document from the first byte — only the file waits — so search engines,
 * in-page find and screen readers are unaffected.
 *
 * Enqueued only on a page that contains at least one carrier, so a page with no
 * binding pays nothing.
 *
 * @package Uichemy
 */

( function () {
	'use strict';

	if ( window.UichRunWith ) {
		return;
	}

	var CARRIER = 'script[type="uich/deferred"][data-uich-run-with]';

	/** Start a little before the element is visible so it is ready on arrival. */
	var ROOT_MARGIN = '300px 0px';

	/** How long to wait for a selector that matches nothing yet. */
	var RESCUE_TIMEOUT = 3000;

	function carriers() {
		try {
			return Array.prototype.slice.call( document.querySelectorAll( CARRIER ) );
		} catch ( e ) {
			return [];
		}
	}

	/**
	 * Build the real tag a carrier stands for, in its place.
	 *
	 * Created in the carrier's own position so document order is preserved, and
	 * with `async = false` so two files bound to the same element execute in the
	 * order they were written rather than the order they arrive.
	 *
	 * @param {Element} c Carrier script.
	 * @return {Promise} Resolves once the asset has loaded, or failed.
	 */
	function build( c ) {
		if ( c.__uichBuilt ) {
			return Promise.resolve();
		}
		c.__uichBuilt = true;

		return new Promise( function ( resolve ) {
			var src = c.getAttribute( 'data-uich-src' ) || '';
			if ( ! src ) {
				resolve();
				return;
			}
			var kind  = c.getAttribute( 'data-uich-kind' ) || 'script';
			var attrs = ( c.getAttribute( 'data-uich-attrs' ) || '' ).split( /\s+/ );
			var node;

			if ( 'style' === kind ) {
				node = document.createElement( 'link' );
				node.rel  = 'stylesheet';
				node.href = src;
				if ( attrs.indexOf( 'print' ) > -1 ) {
					node.media = 'print';
				} else if ( attrs.indexOf( 'all' ) > -1 ) {
					node.media = 'all';
				}
			} else {
				node = document.createElement( 'script' );
				node.src   = src;
				node.async = false;
				if ( attrs.indexOf( 'module' ) > -1 ) {
					node.type = 'module';
				}
			}

			node.onload  = function () { resolve(); };
			node.onerror = function () {
				if ( window.console && window.console.error ) {
					window.console.error( '[UiChemy Run with] failed to load', src );
				}
				resolve();
			};

			if ( c.parentNode ) {
				c.parentNode.insertBefore( node, c );
				c.parentNode.removeChild( c );
			} else {
				document.head.appendChild( node );
			}
		} );
	}

	/**
	 * Build every carrier bound to this element, one after another.
	 *
	 * Sequential on purpose: a library and the file that uses it are ordinary
	 * siblings in this list and would otherwise race. A failed load resolves
	 * rather than rejects, so one bad URL costs its own tag and not the rest.
	 *
	 * @param {Element} el Element that just came into view.
	 * @return {Promise}
	 */
	function buildFor( el ) {
		var mine = carriers().filter( function ( c ) {
			var sel = c.getAttribute( 'data-uich-run-with' ) || '';
			if ( ! sel ) {
				return false;
			}
			try {
				return el.matches( sel ) || !! el.closest( sel );
			} catch ( e ) {
				return false;
			}
		} );

		return mine.reduce( function ( chain, c ) {
			return chain.then( function () { return build( c ); } );
		}, Promise.resolve() );
	}

	var observer = null;

	function watch( el ) {
		if ( el.__uichWatched ) {
			return;
		}
		el.__uichWatched = true;

		if ( ! ( 'IntersectionObserver' in window ) ) {
			// No observer support: load now rather than never. An old browser gets
			// the pre-feature behaviour, which is the right fallback for a setting
			// whose whole purpose is an optimisation.
			buildFor( el );
			return;
		}
		if ( ! observer ) {
			observer = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}
					observer.unobserve( entry.target );
					buildFor( entry.target );
				} );
			}, { rootMargin: ROOT_MARGIN } );
		}
		observer.observe( el );
	}

	/**
	 * Point every carrier at the element it names.
	 *
	 * Idempotent, so it can be re-run after markup is added (a loop page, an AJAX
	 * render). A selector that matches several elements watches all of them: the
	 * first one to appear builds the file, and the rest find it already built.
	 *
	 * @return {number} How many carriers still have nothing to watch.
	 */
	function scan() {
		var orphans = 0;

		carriers().forEach( function ( c ) {
			var sel = ( c.getAttribute( 'data-uich-run-with' ) || '' ).trim();
			var els = [];
			if ( sel ) {
				try {
					els = Array.prototype.slice.call( document.querySelectorAll( sel ) );
				} catch ( e ) {
					els = [];
				}
			}
			if ( ! els.length ) {
				orphans++;
				return;
			}
			els.forEach( watch );
		} );

		return orphans;
	}

	/**
	 * Load anything still bound to a selector that matches nothing.
	 *
	 * A binding can be wrong — a typo, an element removed since, a file bound
	 * before its element existed. Left alone the file would simply never load,
	 * and a stylesheet or library would vanish with no error anywhere. That is
	 * the difference between a slow page and a broken one, so the file is loaded
	 * and the reason is logged.
	 *
	 * @return {void}
	 */
	function rescue() {
		carriers().forEach( function ( c ) {
			var sel = ( c.getAttribute( 'data-uich-run-with' ) || '' ).trim();
			var found = false;
			if ( sel ) {
				try {
					found = !! document.querySelector( sel );
				} catch ( e ) {
					found = false;
				}
			}
			if ( found ) {
				return;
			}
			if ( window.console && window.console.warn ) {
				window.console.warn(
					'[UiChemy Run with] nothing on this page matches "' + sel + '", so '
					+ ( c.getAttribute( 'data-uich-src' ) || 'that asset' )
					+ ' would never load. Loading it now — fix the Run with selector, or clear it.'
				);
			}
			build( c );
		} );
	}

	function start() {
		if ( scan() ) {
			// Some carrier has nothing to watch. Give the page a chance to finish
			// rendering before deciding it never will, then load what is left.
			var later = function () {
				if ( scan() ) {
					rescue();
				}
			};
			if ( window.requestIdleCallback ) {
				window.requestIdleCallback( later, { timeout: RESCUE_TIMEOUT } );
			} else {
				window.setTimeout( later, 1200 );
			}
		}
	}

	window.UichRunWith = { scan: scan, rescue: rescue };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start, { once: true } );
	} else {
		start();
	}
} )();
