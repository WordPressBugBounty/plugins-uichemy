# UiChemy Improve Performance

Three parts: **A** operates the Performance settings through their tools, **B** keeps generated sections fast, **C** optimises images and Google Fonts (UiChemy Pro).

## A. Performance operations (tools only)

Every Performance read or change goes through `uichemy-composer/performance`. Never edit settings, options or section data directly.

First call `get-ability-info` for `uichemy-composer/performance`. Its schema is the source of truth for action names, setting names and allowed values; do not assume or remember them.

Route by intent:

| The user wants to | Action |
|---|---|
| See the performance settings and their state | `list-performance-settings` |
| Change a performance setting | `set-performance-setting` (`setting`, plus `state`: `enable` / `disable` for a toggle, or `value` for any other type) |
| See where each section of a page prints its CSS and JS | `get-post-sections-code-placement` |
| Change where one section prints its CSS or JS | `set-post-sections-code-placement` (`post_id`, `section_index`, `type`: `css` or `js`, `placement`) |
| Optimise images, or change how Google Fonts load | Part **C** below (UiChemy Pro) |

Rules:
- Call `list-performance-settings` to learn the setting names, their `type` (`toggle`, `choice`, `number`, `list`) and their state; a choice lists its `choices`. Map the user's words to one of them; if none fits, ask. Never invent a name, and never use `set-performance-setting` just to read a state.
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

## C. Images and fonts (UiChemy Pro)

With UiChemy Pro, the image optimisation settings (group `images`) and how Google Fonts load (group `fonts`) are rows of `list-performance-settings`, changed with `set-performance-setting` like every other setting. They do not depend on `performance-optimization`. Working on images that are already in the library is three actions on the same ability: `list-images-optimisation`, `optimise-images`, `restore-unoptimised-images`. If neither the rows nor the actions are there, say the feature is on the Performance screen's **Images** / **Fonts** pages with UiChemy Pro and stop. Never imitate either in section code: no hand-written WebP copies, no inlined Google Fonts CSS to "self-host" it.

### Image optimisation

Converts the media library's JPEG, PNG and GIF images to a lighter copy (stored in `wp-content/uichemy-optimizer/`) and serves that copy wherever WordPress outputs the image. The original file is never changed, so it can always be restored.

| The user wants to | Call |
|---|---|
| See whether it is on, the settings, what the server can encode, library totals | `list-performance-settings`: the `images` rows; the `image-optimisation` row carries `server` and `library` |
| Switch it on or change a setting | `set-performance-setting`, one setting per call |
| See which images are optimised or not | `list-images-optimisation` (`status`: `unoptimized`, `optimized`, `all`) |
| Optimise images that are already uploaded | `optimise-images` (`attachment_ids`, or `count` for the next unoptimised ones) |
| Undo it | `restore-unoptimised-images` (`attachment_ids`, or `all: true`) |

Order that works: `list-performance-settings` → `set-performance-setting` `image-optimisation` `enable` → `optimise-images`, called again while `remaining` is above 0. `optimise-images` refuses while `image-optimisation` is off; `restore-unoptimised-images` works either way.

- **Format (`image-format`):** `webp` works in every browser and is the safe default. `avif` and `smart` (AVIF or WebP per browser) need `server.avif: true` on the `image-optimisation` row; without it images are saved as WebP. `original` compresses but keeps JPEG / PNG.
- **Compression (`image-compression`):** `balanced` for most sites, `lossless` for logos and sharp graphics, `aggressive` for large photos and backgrounds.
- **Resize:** enable `image-resize-large` and set `image-max-width` / `image-max-height` (1920 is typical) when uploads are bigger than the site ever shows.
- **New vs existing images:** `image-auto-convert` only affects images uploaded after it is on. Existing images need `optimise-images`, or `image-background` to let WP-Cron work through them a few at a time.
- **One call works for up to 20 seconds**, inside the request. If the response has `not_processed`, call again with those ids. Report `optimized`, `failed` (with each image's message) and `remaining`.
- **Skipped on purpose:** excluded paths (`image-exclude-paths`), files already WebP / AVIF, animated GIFs on servers without Imagick (converting them would lose the animation), and results larger than the original when `image-avoid-larger` is on.
- **Metadata:** `image-metadata` `keep` only keeps camera data on servers with Imagick; with GD it is always removed.
- **Restore:** `restore-unoptimised-images` with `all: true` is two-step: the first call returns a `confirm_token`, the second (with it) restores. Restore only when the user asks.

### Google Fonts

Changes how Google Fonts `<link>` tags load on the front end, from any theme, plugin or page code.

| The user wants to | Call |
|---|---|
| See how they load now | `list-performance-settings`: `google-fonts-load`, `google-fonts-swap` |
| Self-host them or remove them | `set-performance-setting` `google-fonts-load` with `value` |
| Add or remove display swap | `set-performance-setting` `google-fonts-swap` with `state` |

- **`google-fonts-load`:** `google` loads them as the page asks. `self-hosted` downloads the CSS and font files once into `uploads/uichemy/fonts/google/` and serves them from the site, with no request to Google (faster, GDPR-friendly). `off` removes every Google Fonts link, so the theme's fallback fonts show.
- **`google-fonts-swap`:** shows text in a fallback font while the web font loads. Recommended with `google` and `self-hosted`; ignored with `off`.
- **Not the same as `elementor-fonts` / `elementor-font-display`:** those are Elementor's own options and only cover the fonts Elementor adds. The `fonts` rows cover every Google Fonts link on the page.
- **Self-hosted files download on the next front-end page view**, not when the setting is saved. View a page once, then check.
- **Only `<link>` tags are handled.** A Google Fonts `@import` inside CSS, or fonts loaded by JavaScript, are left alone. Put font links in page or site code as `<link>` tags (rule B4), never `@import`.
- After a page or theme changes its Google Fonts, tell the user to press **Delete** next to the saved font files on Performance › Fonts, so the new ones are fetched. No tool does this.

### Custom fonts

The site's own font files (woff2, woff, ttf, otf) are `uichemy-composer/custom-font`, not this ability: `request-upload` → run its curl line → `add` with the returned attachment IDs (required, or the font never appears in the admin or the pickers). Its `@font-face` prints on every page by itself; only add the preload `<link>` tags it returns, before `</head>`, for fonts used above the fold.

## Tests (final page, logged out, browser in the foreground)

1. **Lighthouse, mobile and desktop** (DevTools, or `npx lighthouse <url> --only-categories=performance`, plus `--preset=desktop`). Run each twice. FCP, LCP, TBT and CLS must all show a number. `NO_LCP` means something above the fold starts at `opacity:0` (rule 3); `NO_FCP` or `Error!` means a hidden tab or an extension, so rerun in incognito. Report before and after.
2. **Page source:** no image preloads except the first image, no render-blocking `<script src>` in `<head>` other than the must-run-first ones from rule 7, no unused Elementor CSS/JS or jQuery.
3. **Network (Img):** reload, then scroll. Only the top sections' images load first.
4. **Functionality:** menus, animations, accordions, sliders and forms work on desktop and at 375px, with no console errors.
5. **Layout:** unchanged, except Elementor's gap between sections disappearing on Composer-only pages.
6. After editing page data outside the Elementor editor, clear Elementor's cache (Elementor → Tools → Clear Files & Data).
7. **Images (if optimised):** in Network (Img), the page's images come from `wp-content/uichemy-optimizer/` as `.webp` / `.avif`.
8. **Google Fonts (if self-hosted):** the page source has no `fonts.googleapis.com` or `fonts.gstatic.com` links; the font CSS comes from `uploads/uichemy/fonts/google/`.
