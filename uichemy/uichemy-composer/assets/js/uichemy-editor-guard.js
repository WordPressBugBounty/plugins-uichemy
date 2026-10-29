/**
 * UiChemy editor guard — printed inline as the FIRST script in <head>, only on a
 * request where the live-page editor (or the Elementor preview it draws into) is
 * loading. Never on a visitor's page.
 *
 * The editor's UI lives in the same document as the page being edited, so the
 * page's own scripts see every key, wheel turn and right-click made in it. A
 * design's carousel that eats Space and the arrow keys, a full-page slider that
 * cancels every wheel event, a "disable right-click" snippet, a page-transition
 * script that hijacks link clicks: each made part of the editor unusable, and
 * none of them is wrong on the page itself.
 *
 * 1. Event listeners. Listeners that page code (or any other plugin) puts on
 *    window, document, <html> or <body> are wrapped; when an event comes from
 *    the editor's own UI, or from the text being edited on the canvas, the
 *    wrapped listener is skipped. Everything else reaches the page untouched.
 *    "Page code" is read from the registering call stack: a listener whose
 *    stack holds only UiChemy and WordPress core (wp-includes) frames is ours
 *    and never wrapped, which keeps React, Radix and the editor's own shortcuts
 *    working. jQuery is covered at jQuery.event.add, because jQuery attaches one
 *    native dispatcher per element and type, so the native call alone cannot
 *    tell whose handler sits behind it. `document.onkeydown = …` style handlers,
 *    including inline attribute handlers on <html>/<body>, are wrapped too.
 *
 * 2. Fixed positioning. A transform, filter, perspective, backdrop-filter,
 *    containment or will-change on <html> or <body> turns them into the
 *    containing block for every position:fixed descendant, so the docked panel
 *    and the canvas toolbar scrolled away with the page and grew to the page's
 *    full height. While the editor is open those properties are neutralised on
 *    <html> and <body> only.
 *
 * 3. Forced styles. A page that sets `* { font-family … !important }` or
 *    `* { transition … !important }` is detected after load, and a class on
 *    <html> turns on the counter-rules in composer-isolation.css.
 */
(function (w, d) {
	if (w.__uichEditorGuard) return;
	w.__uichEditorGuard = 1;

	var UI = '.uich-tw,.uich-portal-root,.uich-composer-host,.uich-rich-editing,.uich-it-box,'
		+ '[id^="uichemy-composer-"],[id^="uichemy-motion"],[id^="uich-"],[data-uich-ui]';
	var TYPES = {
		keydown: 1, keyup: 1, keypress: 1, beforeinput: 1, input: 1,
		wheel: 1, mousewheel: 1, DOMMouseScroll: 1,
		contextmenu: 1, copy: 1, cut: 1, paste: 1, selectstart: 1, dragstart: 1, drop: 1,
		mousedown: 1, mouseup: 1, click: 1, dblclick: 1, auxclick: 1,
		pointerdown: 1, pointerup: 1, touchstart: 1, touchmove: 1, touchend: 1
	};
	// Frames from these are ours: the UiChemy plugins and WordPress core scripts.
	// PHP prints the real paths (uichGuardOwnPaths), so a renamed plugin or
	// content folder (Bedrock's /app/plugins/) still reads as ours; the fixed
	// pattern stays as the fallback.
	var OWN = (function () {
		var parts = ['\\/wp-content\\/plugins\\/uichemy(-pro)?\\/', '\\/wp-includes\\/js\\/'];
		var paths = w.uichGuardOwnPaths;
		if (paths && paths.length) {
			for (var i = 0; i < paths.length; i++) {
				if (typeof paths[i] === 'string' && paths[i].length > 1) {
					parts.push(paths[i].replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&'));
				}
			}
		}
		return new RegExp(parts.join('|'));
	}());
	var URL_RE = /(https?:\/\/[^\s()]+?):\d+:\d+/;

	function fromUi(e) {
		var t = e && e.target;
		if (!t) return false;
		if (t.nodeType === 3) t = t.parentNode;
		if (!t || !t.closest) return false;
		try { return !!t.closest(UI); } catch (x) { return false; }
	}

	// True when the code registering a listener is not UiChemy or WordPress core.
	// Every function of this guard is named uichGuard*, so its own frames (which
	// carry the page URL, the guard being inline) are told apart from page code.
	function uichGuardForeign() {
		var s = '';
		var limit = Error.stackTraceLimit;
		try { Error.stackTraceLimit = 60; } catch (x) { /* read-only: keep default */ }
		try { throw new Error('uichGuard'); } catch (x) { s = String(x.stack || ''); }
		try { Error.stackTraceLimit = limit; } catch (x) { /* noop */ }
		var lines = s.split('\n');
		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			if (line.indexOf('uichGuard') !== -1) continue;
			var m = line.match(URL_RE);
			if (m && !OWN.test(m[1])) return true;
		}
		// Only UiChemy / core frames, or none readable: leave it alone.
		return false;
	}

	function watched(t) {
		return t === w || t === d || t === d.documentElement || (d.body && t === d.body);
	}

	function guarded(fn) {
		var g = function uichGuardWrapped(e) {
			if (fromUi(e)) return;
			return typeof fn === 'function' ? fn.apply(this, arguments) : fn.handleEvent.call(fn, e);
		};
		g.__uichGuarded = 1;
		return g;
	}

	// ── 1a · addEventListener ────────────────────────────────────────────────
	var EP = w.EventTarget && w.EventTarget.prototype;
	if (EP && EP.addEventListener && w.WeakMap) {
		var add = EP.addEventListener;
		var remove = EP.removeEventListener;
		var wrappers = new WeakMap();
		var keyOf = function (type, opt) {
			var capture = typeof opt === 'boolean' ? opt : !!(opt && opt.capture);
			return type + '|' + (capture ? 1 : 0);
		};
		EP.addEventListener = function uichGuardAdd(type, fn, opt) {
			if (fn && !fn.__uichGuarded && TYPES[type] === 1 && watched(this) && uichGuardForeign()) {
				var byKey = wrappers.get(fn);
				if (!byKey) { byKey = {}; wrappers.set(fn, byKey); }
				var k = keyOf(type, opt);
				if (!byKey[k]) byKey[k] = guarded(fn);
				return add.call(this, type, byKey[k], opt);
			}
			return add.apply(this, arguments);
		};
		EP.removeEventListener = function uichGuardRemove(type, fn, opt) {
			var byKey = fn && (typeof fn === 'function' || typeof fn === 'object') ? wrappers.get(fn) : null;
			var k = keyOf(type, opt);
			if (byKey && byKey[k]) remove.call(this, type, byKey[k], opt);
			return remove.apply(this, arguments);
		};
	}

	// ── 1b · on* handler properties ─────────────────────────────────────────
	var PROPS = [];
	for (var t in TYPES) {
		if (Object.prototype.hasOwnProperty.call(TYPES, t)) PROPS.push('on' + t);
	}
	function findDesc(obj, prop) {
		for (var o = obj; o; o = Object.getPrototypeOf(o)) {
			var desc = Object.getOwnPropertyDescriptor(o, prop);
			if (desc) return desc;
		}
		return null;
	}
	function guardProps(obj) {
		if (!obj || obj.__uichGuardProps) return;
		obj.__uichGuardProps = 1;
		PROPS.forEach(function (prop) {
			var desc = findDesc(obj, prop);
			if (!desc || !desc.set || !desc.get || !desc.configurable) return;
			var raw = null;
			// A handler already present (an inline attribute such as
			// <body oncontextmenu="return false">) is wrapped as well.
			var current = desc.get.call(obj);
			if (typeof current === 'function') { raw = current; desc.set.call(obj, guarded(current)); }
			try {
				Object.defineProperty(obj, prop, {
					configurable: true,
					enumerable: desc.enumerable,
					get: function () { return raw; },
					set: function uichGuardSet(v) {
						raw = typeof v === 'function' ? v : null;
						desc.set.call(obj, raw && uichGuardForeign() ? guarded(raw) : raw);
					}
				});
			} catch (x) { /* a locked property: leave it */ }
		});
	}
	guardProps(w);
	guardProps(d);
	guardProps(d.documentElement);

	// ── 1c · jQuery handlers ────────────────────────────────────────────────
	// jQuery attaches ONE native dispatcher per element and event type, so the
	// native call cannot say whose handler sits behind it: the handlers are
	// wrapped where jQuery receives them, in jQuery.event.add. jquery-migrate
	// replaces that function right after jQuery loads, so the property is kept
	// as an accessor and whatever is assigned to it later is wrapped too.
	function wrapJqAdd(jq, fn) {
		if (typeof fn !== 'function' || fn.__uichGuard) return fn;
		var wrappedAdd = function uichGuardJqAdd(elem, types, handler) {
			if (watched(elem) && handler && !handler.__uichGuarded && typeof types === 'string'
				&& types.split(/\s+/).some(function (x) { return TYPES[x.split('.')[0]] === 1; })
				&& uichGuardForeign()) {
				var args = Array.prototype.slice.call(arguments);
				if (typeof handler === 'function') {
					var g = guarded(handler);
					g.guid = handler.guid = handler.guid || jq.guid++;
					args[2] = g;
				} else if (typeof handler.handler === 'function') {
					var inner = handler.handler;
					var g2 = guarded(inner);
					g2.guid = inner.guid = inner.guid || jq.guid++;
					var copy = {};
					for (var k in handler) { if (Object.prototype.hasOwnProperty.call(handler, k)) copy[k] = handler[k]; }
					copy.handler = g2;
					args[2] = copy;
				}
				return fn.apply(this, args);
			}
			return fn.apply(this, arguments);
		};
		wrappedAdd.__uichGuard = 1;
		return wrappedAdd;
	}
	function patchJq(jq) {
		if (!jq || !jq.event || jq.event.__uichGuardAdd) return;
		var current = wrapJqAdd(jq, jq.event.add);
		try {
			Object.defineProperty(jq.event, 'add', {
				configurable: true,
				enumerable: true,
				get: function () { return current; },
				set: function uichGuardJqAddSet(v) { current = wrapJqAdd(jq, v); }
			});
			jq.event.__uichGuardAdd = 1;
		} catch (x) { jq.event.add = current; }
	}
	function trapJq(name) {
		var held = w[name];
		if (held) patchJq(held);
		try {
			Object.defineProperty(w, name, {
				configurable: true,
				enumerable: true,
				get: function () { return held; },
				set: function uichGuardJqSet(v) { held = v; patchJq(v); }
			});
		} catch (x) { /* already locked: patched below on load */ }
	}
	trapJq('jQuery');

	// ── 2 · Fixed positioning on <html>/<body> ──────────────────────────────
	var UNFIX_CLASS = 'uich-editor-unfix';
	function makesContainingBlock(el) {
		if (!el || !w.getComputedStyle) return false;
		var s = w.getComputedStyle(el);
		if (s.transform && s.transform !== 'none') return true;
		if (s.filter && s.filter !== 'none') return true;
		if (s.perspective && s.perspective !== 'none') return true;
		var bf = s.backdropFilter || s.webkitBackdropFilter;
		if (bf && bf !== 'none') return true;
		if (s.contain && /paint|layout|strict|content/.test(s.contain)) return true;
		if (s.willChange && /transform|filter|perspective/.test(s.willChange)) return true;
		return false;
	}
	function checkFixed() {
		var html = d.documentElement;
		if (!html || !d.body || html.classList.contains(UNFIX_CLASS)) return;
		if (!makesContainingBlock(html) && !makesContainingBlock(d.body)) return;
		if (!d.getElementById('uich-editor-unfix-style')) {
			var st = d.createElement('style');
			st.id = 'uich-editor-unfix-style';
			st.textContent = 'html.' + UNFIX_CLASS + ',html.' + UNFIX_CLASS + ' body{transform:none!important;filter:none!important;'
				+ 'perspective:none!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important;'
				+ 'contain:none!important;will-change:auto!important}';
			(d.head || html).appendChild(st);
		}
		html.classList.add(UNFIX_CLASS);
		if (w.console && w.console.info) {
			w.console.info('UiChemy: the page sets a transform/filter on <html> or <body>. It is turned off while the editor is open so the editor stays in place; the published page is unchanged.');
		}
	}

	// ── 2b · Zoom on <html>/<body> ──────────────────────────────────────────
	// `body { zoom: 1.25 }` scales everything in the body, and the editor's own
	// layers are body children: the toolbars grew, and the outline and handles
	// drawn from getBoundingClientRect() (which already includes the zoom) were
	// scaled a second time and missed the element. Each of our body-level roots
	// gets the inverse zoom instead, so the page keeps its look.
	var ROOTS = '.uich-tw,.uich-portal-root,.uich-composer-host,.uich-it-box,'
		+ '[id^="uichemy-composer-"],[id^="uichemy-motion"],[id^="uich-"],[data-uich-ui]';
	function zoomOf(el) {
		var z = parseFloat(w.getComputedStyle(el).zoom);
		return z > 0 ? z : 1;
	}
	function checkZoom() {
		var html = d.documentElement;
		if (!html || !d.body || !w.getComputedStyle) return;
		var z = zoomOf(html) * zoomOf(d.body);
		// Writes only on a change: <html>'s style is observed, and an unchanged
		// write would still re-trigger this.
		if (Math.abs(z - 1) < 0.001) {
			if (html.classList.contains('uich-guard-zoom')) html.classList.remove('uich-guard-zoom');
			return;
		}
		if (!d.getElementById('uich-guard-zoom-style')) {
			var st = d.createElement('style');
			st.id = 'uich-guard-zoom-style';
			st.textContent = 'html.uich-guard-zoom body>:is(' + ROOTS + '){zoom:var(--uich-unzoom,1)!important}';
			(d.head || html).appendChild(st);
		}
		var inv = String(1 / z);
		if (html.style.getPropertyValue('--uich-unzoom') !== inv) html.style.setProperty('--uich-unzoom', inv);
		if (!html.classList.contains('uich-guard-zoom')) html.classList.add('uich-guard-zoom');
	}

	// ── 3 · Styles the page forces on every element ─────────────────────────
	// `* { font-family: X !important }` or `* { transition: all .6s !important }`
	// beat every rule of ours. Probed with a hidden element inside a `.uich-tw`
	// box: if its font is not the one our CSS intends (mirrored into --uich-ff),
	// or a bare span inside our UI has a transition, the page is forcing it and
	// a class on <html> switches on the counter-rules in composer-isolation.css.
	function firstFamily(v) {
		return String(v || '').split(',')[0].replace(/["']/g, '').trim().toLowerCase();
	}
	function addCursorRules() {
		if (d.getElementById('uich-guard-cur-style')) return;
		var R = ':is(' + ROOTS + ')';
		var g = 'html.uich-guard-cur ';
		var st = d.createElement('style');
		st.id = 'uich-guard-cur-style';
		// Every Composer section, not only the one being picked in: a click on
		// another section switches to it.
		var W = ':is(.elementor-widget-uichemy-composer,.uich-composer-picking)';
		st.textContent = g + R + ',' + g + R + ' *,' + g + W + ',' + g + W + ' *{cursor:default!important}'
			+ g + R + ' :is(a,button,summary,label,select,[role=button],[role=tab],[role=menuitem],[role=menuitemradio],[role=menuitemcheckbox],[role=option],[role=switch],[role=checkbox],[role=radio],[data-uc-toolbar-action]){cursor:pointer!important}'
			+ g + R + ' :is(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=button]):not([type=submit]),textarea,[contenteditable=true],.CodeMirror-lines),'
			+ g + '.uich-rich-editing,' + g + '.uich-rich-editing *{cursor:text!important}'
			+ g + R + ' [role=separator]{cursor:col-resize!important}';
		(d.head || d.documentElement).appendChild(st);
	}
	function probeForced() {
		var html = d.documentElement;
		if (!d.body || !w.getComputedStyle) return;
		var box = d.createElement('div');
		box.className = 'uich-tw';
		box.setAttribute('data-uich-ui', 'probe');
		box.setAttribute('aria-hidden', 'true');
		box.style.cssText = 'position:absolute;left:-9999px;top:0;width:10px;height:10px;overflow:hidden;visibility:hidden;pointer-events:none';
		var span = d.createElement('span');
		box.appendChild(span);
		d.body.appendChild(box);
		try {
			var want = w.getComputedStyle(box).getPropertyValue('--uich-ff');
			// Our stylesheet is not on this page (or not loaded yet): nothing to compare.
			if (want && want.trim()) {
				var got = w.getComputedStyle(span).fontFamily;
				if (firstFamily(got) !== firstFamily(want)) html.classList.add('uich-guard-ff');
			}
			var tr = w.getComputedStyle(span).transitionDuration || '0s';
			if (tr.split(',').some(function (x) { return parseFloat(x) > 0; })) html.classList.add('uich-guard-tr');
			// `* { cursor: none !important }` (a custom-cursor design): the pointer
			// vanished over the panel and over the page while picking.
			if (w.getComputedStyle(span).cursor === 'none' && !html.classList.contains('uich-guard-cur')) {
				addCursorRules();
				html.classList.add('uich-guard-cur');
			}
		} catch (x) { /* best-effort */ }
		box.parentNode.removeChild(box);
	}

	// ── 4 · Properties the page adds to the built-in prototypes ─────────────
	// `Object.prototype.x = 1` (an old library extending the natives, or page
	// code doing it by hand) makes every `for (k in obj)` see `x`. webpack's
	// runtime defines each module export that way, so WordPress's own packages
	// threw "Getter must be a function" and wp.i18n, jQuery's selector engine and
	// the editor never loaded. Turning those additions non-enumerable keeps
	// their values (the page's code still works) and hides them from for-in.
	// Run again after the page's head code and before the footer scripts.
	var PROTOS = [Object.prototype, Array.prototype, Function.prototype, String.prototype, Number.prototype];
	function depollute() {
		var fixed = 0;
		for (var i = 0; i < PROTOS.length; i++) {
			var p = PROTOS[i];
			for (var k in p) {
				if (!Object.prototype.hasOwnProperty.call(p, k)) continue;
				try {
					var desc = Object.getOwnPropertyDescriptor(p, k);
					if (desc && desc.enumerable && desc.configurable) {
						desc.enumerable = false;
						Object.defineProperty(p, k, desc);
						fixed++;
					}
				} catch (x) { /* frozen by the page: leave it */ }
			}
		}
		if (fixed && w.console && w.console.info) {
			w.console.info('UiChemy: the page adds enumerable properties to built-in prototypes. They are made non-enumerable while the editor is open so WordPress scripts load; the published page is unchanged.');
		}
	}
	w.uichemyDepollute = depollute;

	// ── 5 · A page script that rewrites the whole <body> ────────────────────
	// `document.body.innerHTML = document.body.innerHTML.replace(…)` (emoji,
	// typography or "smart quote" scripts) re-creates every node in the body.
	// The editor's UI lives there too: it came back as a dead copy and the editor
	// stopped responding. The body's innerHTML setter keeps our live roots: the
	// copies the new markup brought are dropped and the originals put back.
	function isOurRoot(el) {
		try { return !!(el && el.nodeType === 1 && el.matches(UI)); } catch (x) { return false; }
	}
	function keepUiAcrossBodyHtml() {
		var body = d.body;
		if (!body || body.__uichHtmlKept) return;
		var desc = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
		if (!desc || !desc.set || !desc.get) return;
		try {
			Object.defineProperty(body, 'innerHTML', {
				configurable: true,
				enumerable: true,
				get: function () { return desc.get.call(this); },
				set: function uichGuardBodyHtml(v) {
					var ours = [];
					for (var c = this.firstElementChild; c; c = c.nextElementSibling) {
						if (isOurRoot(c)) ours.push(c);
					}
					desc.set.call(this, v);
					if (!ours.length) return;
					var copies = [];
					for (var n = this.firstElementChild; n; n = n.nextElementSibling) {
						if (isOurRoot(n)) copies.push(n);
					}
					for (var i = 0; i < copies.length; i++) copies[i].parentNode.removeChild(copies[i]);
					for (var j = 0; j < ours.length; j++) this.appendChild(ours[j]);
				}
			});
			body.__uichHtmlKept = true;
		} catch (x) { /* best-effort */ }
	}

	// ── 6 · A page script that moves body children into a wrapper ───────────
	// Smooth-scroll and page-transition libraries take every child of <body>
	// into a wrapper of their own, usually one with a transform. Our fixed
	// layers went along, and inside a transformed box `position: fixed` is
	// relative to that box: the panel ended up thousands of pixels down, 0px
	// tall. Moving our body-level roots elsewhere is refused when page code asks
	// for it; putting them back into the body, or our own code, is untouched.
	// Refusing (instead of moving them back) also keeps a library that re-wraps
	// every new body child from looping against us.
	function uichGuardPinned(node, parent) {
		if (!node || node.nodeType !== 1) return false;
		var p = node.parentNode;
		if (!p || (p !== d.body && p !== d.documentElement)) return false;
		if (parent === d.body || parent === d.documentElement) return false;
		if (!isOurRoot(node)) return false;
		return uichGuardForeign();
	}
	var NP = w.Node && w.Node.prototype;
	if (NP && NP.appendChild && NP.insertBefore) {
		var nativeAppend = NP.appendChild;
		var nativeInsert = NP.insertBefore;
		NP.appendChild = function uichGuardAppendChild(n) {
			return uichGuardPinned(n, this) ? n : nativeAppend.call(this, n);
		};
		NP.insertBefore = function uichGuardInsertBefore(n, ref) {
			return uichGuardPinned(n, this) ? n : nativeInsert.apply(this, arguments);
		};
	}
	var ElP = w.Element && w.Element.prototype;
	if (ElP) {
		['append', 'prepend'].forEach(function (name) {
			var orig = ElP[name];
			if (typeof orig !== 'function') return;
			ElP[name] = function uichGuardAppendMany() {
				var self = this;
				var args = Array.prototype.filter.call(arguments, function (n) { return !uichGuardPinned(n, self); });
				return orig.apply(this, args);
			};
		});
		// The same for the APIs that take a list: replaceChildren on the new
		// parent, before / after / replaceWith on a sibling.
		var origReplaceChildren = ElP.replaceChildren;
		if (typeof origReplaceChildren === 'function') {
			ElP.replaceChildren = function uichGuardReplaceChildren() {
				var self = this;
				var args = Array.prototype.filter.call(arguments, function (n) { return !uichGuardPinned(n, self); });
				return origReplaceChildren.apply(this, args);
			};
		}
		['before', 'after', 'replaceWith'].forEach(function (name) {
			var orig = ElP[name];
			if (typeof orig !== 'function') return;
			ElP[name] = function uichGuardSiblingMany() {
				var parent = this.parentNode;
				var args = Array.prototype.filter.call(arguments, function (n) { return !uichGuardPinned(n, parent); });
				return orig.apply(this, args);
			};
		});
		if (NP && typeof NP.replaceChild === 'function') {
			var nativeReplace = NP.replaceChild;
			NP.replaceChild = function uichGuardReplaceChild(n, old) {
				return uichGuardPinned(n, this) ? old : nativeReplace.apply(this, arguments);
			};
		}
		var nativeAdjacent = ElP.insertAdjacentElement;
		if (typeof nativeAdjacent === 'function') {
			ElP.insertAdjacentElement = function uichGuardAdjacent(pos, el) {
				var where = String(pos || '').toLowerCase();
				var parent = (where === 'beforebegin' || where === 'afterend') ? this.parentNode : this;
				return uichGuardPinned(el, parent) ? el : nativeAdjacent.apply(this, arguments);
			};
		}
	}

	function onReady() {
		depollute();
		keepUiAcrossBodyHtml();
		guardProps(d.body);
		checkFixed();
		probeForced();
		// Late stylesheets (a page script that injects its own CSS) get a second look.
		w.addEventListener('load', function () { probeForced(); setTimeout(probeForced, 2000); });
		// Page scripts can set these later (a page-load animation on <body>).
		var n = 0;
		var both = function () { checkFixed(); checkZoom(); };
		checkZoom();
		var timer = setInterval(function () { both(); if (++n >= 20) clearInterval(timer); }, 500);
		w.addEventListener('resize', checkZoom);
		if (w.MutationObserver && d.body) {
			new MutationObserver(both).observe(d.body, { attributes: true, attributeFilter: ['style', 'class'] });
			new MutationObserver(both).observe(d.documentElement, { attributes: true, attributeFilter: ['style'] });
		}
	}
	if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', onReady);
	else onReady();
}(window, document));
