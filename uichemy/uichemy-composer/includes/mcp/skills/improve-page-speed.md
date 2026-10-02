# UiChemy Improve Page Speed

Two parts: **A** operates Performance through its tools, **B** keeps generated sections fast.

## A. Performance operations (tools only)

Every Performance read or change goes through `uichemy-composer/performance`. Never edit settings, options or section data directly.

First call `get-ability-info` for `uichemy-composer/performance`. Its schema is the source of truth for action names, setting names and allowed values; do not assume or remember them.

Route by intent:

| The user wants to | Action |
|---|---|
| See the performance settings and their state | `list-performance-settings` |
| Turn a performance setting on or off | `set-performance-setting` (`setting`, `state`: `enable` or `disable`) |
| See where each section of a page prints its CSS and JS | `get-post-sections-code-placement` |
| Change where one section prints its CSS or JS | `set-post-sections-code-placement` (`post_id`, `section_index`, `type`: `css` or `js`, `placement`) |

Rules:
- Call `list-performance-settings` to learn the setting names and their state. Map the user's words to one of them; if none fits, ask. Never invent a name, and never use `set-performance-setting` just to read a state.
- Change a setting only when the user asks. If something the task depends on is off, say so and ask first. Report the state returned by the tool instead of guessing it.
- Call `get-post-sections-code-placement` before changing a placement, to get the `section_index`. Each row says whether a value is the default.
- `placement` is `before-head-end`, `before-body-end`, or `default` to put it back to the default. Leave placement alone unless the user asks to move it.
- If a requested placement works against part B (for example moving the CSS of one of the first three sections to `before-body-end`), do it when the user asks, then tell them the trade-off: that section's styles will load later than the rest of the first screen.
- The master switch only turns the optimizations on or off as a whole; each setting keeps its own state. After switching it back on, report any setting that is still off instead of turning it on yourself, unless the user asked for every setting.

## B. Building sections

1. **First three sections.** They are picked by position only (labels do not matter). Their CSS is inlined in `<head>`, and the header and hero images load first. Keep them light, put heavy content below.
2. **No image preloads.** Never write `<link rel="preload" as="image">` in section HTML. React/Next exports ship one per `<img>`; remove them, because a preload defeats `loading="lazy"`.
3. **Visible at load.** The first three sections must not start hidden: no `opacity:0` entrance start (use `.01`), no `data-reveal`, no JS-toggled `.opacity-0`.
4. **Stylesheet links use preload.** External stylesheets (Google Fonts, icon CSS, vendor CSS) use `rel="preload"`, never plain `rel="stylesheet"`. Keep `display=swap` and the two `preconnect` links:
   ```html
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="preload" as="style" href="…&display=swap" onload="this.onload=null;this.rel='stylesheet'">
   <noscript><link rel="stylesheet" href="…&display=swap"></noscript>
   ```
5. **Avoid Elementor and jQuery dependencies where you can.** Prefer no jQuery, `elementorFrontend`, `.elementor-*` / `.e-con` classes or `--e-global-*` variables in section code, and only Composer widgets on the page. If section code does use jQuery, that is allowed: the page keeps jQuery loaded (Elementor's scripts are still dropped unless the code uses `elementorFrontend`). The same goes for a script added by URL that is not a known library, because it may need jQuery.
6. **Icons.** Inline SVG; no `eicon-*` classes.
7. **Choose how each `<script src>` loads.** Use `defer`, `async` or no attribute, depending on what the script does and what depends on it:
   - `defer` for libraries and anything that uses the DOM or another script (GSAP and its plugins, Lenis, three.js, sliders). Deferred scripts run in document order after parsing, so a library and the code that uses it stay in order.
   - `async` only for standalone scripts that nothing else calls (analytics, tracking pixels, chat widgets). They run as soon as they download, in any order.
   - No attribute only when the script must run before the page paints: setting the theme or color mode to avoid a flash, an anti-flicker A/B test snippet, a consent manager the vendor says must block, or a polyfill later scripts need right away. Keep these small and few, because each one delays first paint.
   - Never `async` a library another script uses, and never put both attributes on one tag. Inline code that uses a deferred library runs on `DOMContentLoaded`. `defer`/`async` do nothing on inline scripts, and `type="module"` is already deferred.
   - To load a script only when it is needed, bind it to the element that uses it ("Run with"). Write an inert carrier instead of the real tag; UiChemy fetches the file only when that element comes within about 300px of the screen:
     ```html
     <script type="uich/deferred" data-uich-run-with="#hero-3d" data-uich-kind="script" data-uich-src="https://…/three.min.js" data-uich-attrs=""></script>
     ```
     Use it for heavy libraries that only a below-the-fold section needs (three.js, a slider, a map), never for the first three sections. Carriers bound to the same element load one after another in the order written, so a library and its plugins can share a selector. Put `module` in `data-uich-attrs` for a module script.

## Tests (final page, logged out, browser in the foreground)

1. **Lighthouse, mobile and desktop** (DevTools, or `npx lighthouse <url> --only-categories=performance`, plus `--preset=desktop`). Run each twice. FCP, LCP, TBT and CLS must all show a number. `NO_LCP` means something above the fold starts at `opacity:0` (rule 3); `NO_FCP` or `Error!` means a hidden tab or an extension, so rerun in incognito. Report before and after.
2. **Page source:** no image preloads except the first image, no render-blocking `<script src>` in `<head>` other than the must-run-first ones from rule 7, no unused Elementor CSS/JS or jQuery.
3. **Network (Img):** reload, then scroll. Only the top sections' images load first.
4. **Functionality:** menus, animations, accordions, sliders and forms work on desktop and at 375px, with no console errors.
5. **Layout:** unchanged, except Elementor's gap between sections disappearing on Composer-only pages.
6. After editing page data outside the Elementor editor, clear Elementor's cache (Elementor → Tools → Clear Files & Data).
