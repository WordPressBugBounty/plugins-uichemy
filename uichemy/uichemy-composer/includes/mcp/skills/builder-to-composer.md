# UiChemy Site Build — Move anything into Composer, same to same

You were called because the user wants content that already exists on a WordPress site, **built with some other page builder (or theme, or custom code)**, to end up as **UiChemy Composer** pages. The source can be **any builder** (Divi, Beaver, Brizy, Oxygen, Elementor, Bricks, WPBakery, Gutenberg, a theme's own templates, hand-written code, anything) and it can contain **anything** (dynamic data, custom fields, loops, forms, templates). You do not need a per-builder recipe. You need a method that works on whatever you find, and a checklist so nothing is missed.

The user will phrase it in one of these ways. All of them are the same job:

| User says | Source | Target |
|---|---|---|
| "Convert / rebuild this page, these pages or this site in Composer" | this site | this site |
| "Move site A to site B in Composer" | site A | site B |
| "Move my Composer site A to site B" (A already runs UiChemy Composer) | site A | site B |

**Same site:** source and target are one site. **Two sites:** you are the **middleman AI**: connected to A and to B through AI connectors (MCP servers, ability/tool layers, REST, a browser, whatever the user gave you). You read from A, you write to B, and you carry everything in between. Discover what each connector can do from its own tool list; never assume tool names, and never depend on one particular connector product.

**Goal: same to same.** What the visitor saw and what the editor could manage on the source must be what they see and manage on Composer. Same layout, text, images, fonts, colors, responsive behavior, same dynamic data, same fields, same URLs. You move it; you do not redesign it.

**The source builder is never installed on the target.** Nothing is converted by running the old builder. The page is rebuilt as Composer code (HTML/CSS/JS with real bindings and loops) from what the old page *renders* and the *data* behind it. The only extra plugin you may ever need on the target is a custom-field provider the source's data depends on (e.g. ACF, JetEngine), and only with the user's approval.

---

## Hard rules

1. **The source is read-only.** Never create, edit, delete, unpublish, re-save or "clean up" anything on the source site, its builder data, or its plugins.
2. **Content moves through tool calls, not through chat.** Do not print converted code or datasets. Show plans, counts and reports.
3. **Never guess a name** (post type, taxonomy, field, term, menu). Read it from the source, then read what exists on the target before writing. A wrong name renders blank without any error.
4. **Naming and status on the target.**
   - **Two sites:** keep the original title, slug, date and status.
   - **Same site:** the original stays untouched; the new page is titled `<Original Title> (UiChemy)`, created as `draft`, so the user can compare both.
   - Pages start as **drafts** and are published after verification or when the user says so. **Content entries** (posts / custom post type items) that were published on the source are **published** on the target, because loops and archives skip drafts and would render empty.
5. **Keep a ledger** (a small JSON file in your working area) mapping source → target for every media item, term, entry, page, template and URL, plus what is done / pending. Update it after each item. It is what lets you resume after a long run, avoid duplicates, and translate every ID and URL when writing. If you cannot write files, tell the user and keep the ledger on the **target** as a private draft page titled `UiChemy migration ledger` (JSON in its content); delete it at the end only with the user's OK.
6. **Say which site each call is for** (`[source]` / `[target]`) whenever two connectors are present.
7. **Ask only what you cannot discover.** Discover first; ask the user only for scope, a real ambiguity, or an approval a rule requires.
8. **Completeness: everything connected to the source moves.** Pages, posts, custom post types, templates, menus, dynamic data, fields, taxonomies, media, SVGs, fonts, videos, files, forms, global design, SEO, site identity, anything the inventory finds. Nothing is dropped silently. Every inventory item ends as exactly one of: **moved** (and verified), **moved with an approximation** (say what), or **not moved** (say why and what the user must do). Before the final report, reconcile the inventory against the ledger (Step 4, "Completeness check").
9. **Never create twice.** Before creating anything on the target (page, entry, term, media, template, menu), look it up: first in the ledger, then on the target by slug / type / title / file name. Ours (in the ledger) → update it. Exists but not ours (two sites, e.g. a page with the same slug already on B) → do not overwrite and do not duplicate: ask the user once per kind (replace / skip / create with a suffixed slug) and apply the answer to the rest. Re-running the job must be safe.

---

## Step 0 — Understand the request and connect

0. **Check the PHP-execution requirement first (see below). Do not continue until it is met.**
1. Decide the case (same site / two sites) from the user's words. If it is unclear which site is the source and which is the target, ask once with the two URLs.
2. List your tools. Identify how you can **read the source** (a connector, the REST API, public URLs, a browser) and how you **write the target** (the UiChemy Composer abilities `uichemy-composer/*`). Read a few tool descriptions so you know what each can and cannot do.
3. On the target call `uichemy-composer/describe-site`. If `platform.ready` is false, stop and report what it says.
4. Read one thing from the source to prove access. If you can only reach public pages and not the database, say so: layout and visible content will move, hidden data will be approximated and flagged.

### Requirement: an MCP that can execute PHP

This job needs an **MCP connector that can execute PHP on the WordPress site(s)** (run arbitrary PHP / WP-CLI-style code inside WordPress). Reading another builder's stored data, custom fields, post meta, terms, menus, options and templates, and setting things the Composer abilities do not cover (e.g. a page template, post meta, a bulk ID/URL rewrite), cannot be done reliably with read-only or fixed-purpose tools.

- **Two sites:** PHP execution is needed on **both** (read on the source, write on the target). They can be one connector per site.
- **Same site:** one connector with PHP execution is enough.

**How to check:** look at your connected MCP tools (names and descriptions) for one that runs PHP / evaluates code / executes a WordPress snippet. Do not assume; confirm from the tool list. If unsure, call the connector's discover/get-info tool.

**If no connected MCP can execute PHP, stop. Do not try to work around it, and do not start the plan.** Tell the user plainly, in their language, for example:

> "The MCP connected right now does not provide PHP execution, which this migration needs. Please connect an MCP that does (for example AI Connector, WPVibe, Novamira, or any other MCP that can execute PHP on WordPress) for [source] / [target], then ask me again."

Name which site (`[source]` / `[target]`) is missing it. Resume from Step 0 once the user connects one.

**If the user does not have one yet, share where to get it** (both are WordPress plugins that connect an AI client to the site over MCP):

| Connector | Get it from |
|---|---|
| **WPVibe** | https://wpvibe.ai (plugin on WordPress.org: https://wordpress.org/plugins/vibe-ai/) |
| **Novamira** (runs PHP on the site) | https://novamira.ai |

Whatever the user installs must pass the same tool-list check. A connector that only exposes fixed tools (REST passthrough, allow-listed commands, plugin abilities, SELECT-only queries) is **not** enough for this job; say so and ask for one that can.

**Safety when you do have it:** PHP execution is powerful. On the **source** use it **read-only** (Hard rule 1: no writes, no deletes, no option/meta updates, no cache flushes that change data). On the **target** use it only for the writes this guide describes, and check names against what exists before writing (Hard rule 3).

---

## Step 1 — Inventory everything on the source, then agree the plan

Do not start building until you know what exists. Walk this checklist and note each answer with a count. **If an item exists and you did not look for it, it will be missed.**

**Content**
- [ ] Pages, posts, every custom post type (with status: published / draft / private / scheduled)
- [ ] Taxonomies and terms (categories, tags, custom), term hierarchy
- [ ] Custom fields and field groups (UiChemy Custom Fields, ACF, JetEngine, Pods, Meta Box, or raw post meta): names, types, choices, repeaters, relations, options pages
- [ ] Featured images, galleries, attachments, alt text
- [ ] **Assets** (see "Step 2a — Assets"): every image, SVG (file or inline), video, audio, Lottie/JSON animation, PDF/download, custom font file, icon font, favicon/logo/social image, and every file referenced from CSS (`url(...)`, `@font-face`), custom field values, shortcodes, widgets, menus and builder data
- [ ] Menus and their locations, widgets/sidebars that carry content
- [ ] Site identity: title, tagline, logo, favicon, front page, posts page
- [ ] SEO titles/descriptions/social images, slugs, redirects
- [ ] Languages / translations (WPML, Polylang, etc.)

**Layout and builder**
- [ ] Which builder (or none) built each page. A site can mix several.
- [ ] Builder **templates**: single, archive, header, footer, 404, search, popups, reusable/global blocks, and their display conditions
- [ ] Global design: colors, fonts, type scale, spacing, custom CSS/JS (site-wide and per page)
- [ ] Dynamic regions: loops/queries, field bindings, conditional visibility, related-posts, filters, search, pagination
- [ ] Interactive widgets: sliders, tabs, accordions, counters, modals, sticky/parallax/scroll animations, video/embeds, maps
- [ ] Forms (and where submissions go), newsletters, comments

**Other**
- [ ] WooCommerce / membership / booking / LMS or other plugin-driven features (usually not static: flag them)
- [ ] Anything you saw on a rendered page that you cannot explain from the above

How to recognise a builder you have never seen: render a page, look at the repeating wrapper structure and class/id prefixes (`xx_section`, `xx-row`, `xx-col`, generated hash ids), and at the page's builder-marker meta if you can read it. You do not need to know the builder by name; you need to know **which wrappers are scaffolding** (Appendix A, "Clean up").

Show the user the plan and **ask for scope**:

```
📋 PLAN  [source] → [target]
   Pages 14 · Posts 28 · "project" 42 (6 fields, 2 taxonomies) · "team" 11
   Builders found: <whatever you found, e.g. 2 different ones + plain content>
   Templates: single-project, project archive, header, footer
   Media in use ~180 · Menus 2 · Forms 2 · Languages 1
   ⚠️ Not static, needs a decision: forms ×2, map embed, a third-party shortcode
→ Everything, or only some of it? Publish when done, or leave as draft?
```

Wait for the answer, then create the ledger.

---

### Route every page: already Composer, or built with something else?

Decide this **per page and per template** (a site can mix both, and even one page can). It changes how the layout is moved:

| Source page is | Path | How the layout moves |
|---|---|---|
| **Already Composer** (the source site runs UiChemy Composer and the page's sections are Composer widgets) | **Copy** | Read the stored code from the source and write it to the target. **No rendering, no rebuilding.** Exact copy. |
| **Built with any other builder / theme / code** | **Rebuild** | Render, classify, rebuild as Composer (Step 3). |

How to tell: call `uichemy-composer/describe-site` on the source. If it answers, the source has UiChemy; then `page` `get-structure` on a page tells you whether its sections are Composer widgets. If the tool does not exist on the source, or the page has no Composer sections, it is the Rebuild path. A page with some Composer sections and some builder sections: **copy** the Composer ones, **rebuild** the rest, keep the order.

Put the route next to every page in the plan and the ledger.

---

## Step 2 — Move things in this order

The order exists because later steps depend on earlier IDs. Skipping ahead is how pages end up blank.

**Same site vs two sites.**
- **Two sites:** run every step; nothing exists on the target yet.
- **Same site:** the data is already there. **Skip 2.2 (content model) and 2.4 (entries)** and reuse the existing post types, taxonomies, fields, terms, entries and media IDs. Only upload assets that must be re-homed (fonts, files, SVG you inline or convert). What you create is the **layout**: new pages (`<Title> (UiChemy)`, draft), new templates and the design-system block. New templates must **not compete with the original builder's templates**: create them inactive/draft if the ability allows it, otherwise create them without active conditions, and tell the user which templates to switch over (and which old ones to turn off) when they are ready. Never change the original's conditions yourself (Hard rule 1).

**Work in batches.** For anything with many items (entries, media, terms), process in batches of about 20–50, update the ledger after each batch, report progress (`📦 entries 60/340`), and continue from the ledger if the run is interrupted. Do not hold whole datasets in chat.

1. **Design system.** If the source is Composer, copy it exactly: `design-system` `get` on the source, `set` on the target (use `patch` if the target block already has content). Otherwise read the palette, fonts and type scale the source actually renders. Write them once to the target as the design-system block, following `code-to-wordpress` Step 3 ("Lift the palette + type scale FROM the source"). Every page uses them afterwards.
2. **Content model** (only if the source has custom types, taxonomies or fields). Read first: `uichemy-composer/read-skill` with `{ name: "custom-fields-and-cpt" }` and follow it. Describe what exists on the target, create post types, taxonomies, field groups with the **same names and shapes**, and create the terms in the same session. **Choose where the model lives on the target, and ask the user**: the plugin the source used (if it is installed on the target), or **UiChemy Custom Fields**, which is built in and needs no extra plugin. Keeping the field NAMES identical is what keeps every binding working, whichever you pick. UiChemy Custom Fields has no options pages, gallery type, flexible content or JetEngine relations/CCTs - content that depends on one of those stays in the plugin that has it. Only if the user has switched UiChemy Custom Fields off and the target has no ACF or JetEngine, ask before installing anything.
   - **Same site, moving off ACF / JetEngine / Meta Box / Pods:** do not rebuild by hand. UiChemy > Custom Fields > Migrate copies the definitions AND the values, with a dry-run scan and a rollback. Point the user to it; templates keep working because the field names do not change.
3. **Media and assets.** Follow **Step 2a — Assets** below. Find in the target's library first; download what is missing from the source; request **one** upload slot (`uichemy-composer/media` `request-upload`) and upload everything through it with `alt` set. Record old ID/URL → new ID/URL. From here on never write a source URL to the target.
4. **Content entries.** For each entry: create it (title, slug, date, excerpt, status per Rule 4), featured image, terms, custom field values (translate IDs/URLs through the ledger; send the stored value), SEO fields. After **all** entries exist, a **second pass** writes relation / post-object / parent fields and internal links, which need every target ID.
5. **Layout.** Pages and builder templates, per Step 3 below.
6. **Site level.** Menus (and locations), logo, favicon, site title, front page and posts page, header/footer, forms.
7. **Fix references.** Search all new content for the source domain and old IDs; rewrite through the ledger.
8. **Verify** (Step 4).

### Inventory lines that are not a page: where they go

Every Step 1 line needs an outcome (Hard rule 8). For the ones with no step above:

| Inventory item | What to do |
|---|---|
| Site-wide custom CSS/JS, fonts, vendor libraries | Site-level code through `code-to-wordpress` part `scope-decision` (code files + `<link>`/`<script>`), not pasted into every section |
| SEO titles, descriptions, social images | Write to the target's SEO plugin fields if one is installed; otherwise ask the user which SEO plugin to use, or keep the values in the ledger and list them under "Needs your check" |
| Redirects | Recreate with a redirect plugin/ability on the target if one exists; otherwise list every redirect in the report |
| Languages / translations | Needs the same multilingual plugin on the target (ask the user before installing). Without it, move the default language and list the rest under "Not moved" |
| Widgets / sidebars with content | Rebuild their content inside the header/footer/template where they appear |
| Comments | Ask in the plan. If yes, move approved comments (author, date, content, parent) with their entries; otherwise "Not moved" |
| WooCommerce / membership / booking / LMS | Not static: use the store capability if the target has one, otherwise flag with exact items |
| Users and accounts, plugin settings, orders | Not moved. Say so in the report |

---

## Step 2a — Assets (images, SVG, fonts, video, files)

Assets hide in more places than the media library. Build the asset list from **all** of these, not only from `<img>` tags:

- The media library (attachments, featured images, galleries) and the files used by logo, favicon, social/SEO images
- Rendered HTML: `<img>` (`src`, `srcset`, `data-src`), `<picture>`, `<video>`/`<source>`/`poster`, `<audio>`, `<object>`/`<embed>`, inline `<svg>`, `<use href>` sprites
- **CSS** (the page's, the builder's, the theme's, custom CSS): `background-image: url()`, `mask-image`, `border-image`, `content: url()`, `@font-face src`, cursors
- Custom field values, widgets, menus (menu icons), shortcodes, and the builder's own stored data (image/icon/video fields)
- Animations and downloads: Lottie / JSON, PDFs, zips, any linked file

Then handle each kind:

| Asset | What to do |
|---|---|
| **Raster images** (jpg, png, webp, avif, gif), including ones referenced from CSS `url()` | Upload to the target library with `alt`; keep dimensions/aspect ratio; map old → new in the ledger; rewrite the `url()` in CSS to the new URL. |
| **SVG, inline** (`<svg>` in the markup) | Keep as inline SVG in the Composer code (clean `fill`/`stroke` to `currentColor` only when the original did the same). Nothing to upload. |
| **SVG, file** (`<img src="x.svg">`, CSS background, logo) | WordPress blocks SVG uploads by default. **Check first** whether the target accepts SVG (try one upload, or read the platform info). If yes, upload it. If no: for icons/illustrations that are small, inline them as `<svg>` code; otherwise tell the user "the target does not allow SVG uploads, enable SVG support or approve inlining" and **wait**. Do not swap an SVG for a PNG without telling them. |
| **Fonts** | Google/Adobe/other hosted fonts: reuse the same hosted source. Self-hosted font files (`woff2`, `woff`, `ttf`, `otf`): download all weights/styles actually used, upload to the target (or host through the platform's code-file/asset ability if the media library refuses font types), and write the `@font-face` with the new URLs. Icon fonts: rebuild the used icons as inline SVG, or host the font file and keep the glyph classes. Never leave a font pointing at the source domain. |
| **Video / audio** | Self-hosted: upload (note the size limit). YouTube/Vimeo/embeds: keep the embed with the same ID and settings. Background videos: keep `autoplay muted loop playsinline` and the poster image. |
| **Lottie / JSON animations** | Download the JSON, upload or host it through the platform's file ability, and re-point the player to the new URL. If it cannot be hosted, say so in the report. |
| **PDFs / downloads** | Upload to the library; rewrite every link that points to them. |

Rules for all assets:
- **Preserve what matters:** `alt`, title, caption, focal point/`object-position`, aspect ratio, `loading`/`fetchpriority` for above-the-fold images.
- **Dedupe:** same file used in many places is uploaded once; the ledger maps every use.
- **Too large / blocked / failed:** do not skip silently. Record it, keep the page working with the best available fallback, and list it under "Not moved" with the reason.

---

## Step 3 — Rebuild each page and template as Composer

For every page and every builder template, in this order:

### Copy path — source page is already Composer

Use this instead of 3a–3d for every page/template routed **Copy**. Nothing is re-rendered or re-derived; the code is moved as stored.

1. **Read from the source:** the page's structure and each section's code (`page` `get-structure`, `get-section-code`, or `get-page-code` for page-level code), and for templates the theme-builder template list and each template's sections and conditions. Also read the shared site-level code and files the pages depend on (`platform` `get-site-code`, `code-file` assets, performance and site settings, the design-system block). Check each ability name with `get-ability-info` on that site before relying on it.
2. **Translate references** in the code before writing: media URLs and attachment IDs (through the ledger from Step 2.3), internal links, and any dynamic reference (post type, taxonomy, field, term names) which must already exist on the target from Step 2.2. Leave everything else untouched: markup, CSS, JS, animation controller blocks, loop and binding syntax.
3. **Write to the target:** create the page (`page` `create` / `create-with-sections`, then `append-section` or `set-section-code` / `set-page-code`) with the same title, slug, status and section order. Templates are recreated through `theme-builder` with the **same conditions**. Copy shared code files and site-level code before the sections that use them.
4. **Per-section progress:** `📦 [target] "Home" section 4/9 copied`. Update the ledger.
5. Then go to Step 4 (verify). A copy is still checked: a broken reference (missing field, media not mapped, template condition off) shows as a blank region, not an error.

---

### 3a. Rebuild path — get the page as the visitor sees it

Use a **browser tool if you have one** (Claude in Chrome, Playwright, a built-in browser pane, any automation). Without one, fetch the page URL and its linked stylesheets and tell the user results are less accurate. Follow **Appendix A**. For a template (a single or archive layout), load **two different real entries** that use it: what differs between them is dynamic.

### 3b. Decide for every region: static or dynamic

This decides whether you deliver a real site or a frozen copy of one.

| Region | It is | Build as |
|---|---|---|
| Same content every load, only on this page | static | plain Composer HTML |
| A list/grid of cards matching entries of a post type or a query | loop | a Composer loop over that type/query. Never paste the cards. |
| Text/image equal to a field or core value of the current entry (title, excerpt, featured image, date, author, term, custom field) | binding | a Composer binding to that field. Never paste the value. |
| Menu, logo, site title | site data | WP menu / site logo, per Step 2 |
| Form | form | rebuild with the forms capability |
| Cart, products, checkout, booking | commerce / plugin | rebuild with the store capability if it exists, else flag |

How to tell: **compare against the data** (does it equal an entry's title/field/featured image?), **compare two entries** (what differs is dynamic), use the builder's saved query/dynamic-tag settings only as a **hint** and confirm against the rendered output, and ask "if an editor changed one entry in the admin, should this region change?". Read before building any dynamic region: `uichemy-composer/read-skill` with `{ name: "dynamic-loops-and-forms" }`. Use only names that exist on the target.

### 3c. Clean code, then build through code-to-wordpress

Write clean semantic HTML, one CSS block (desktop + media queries) and minimal JS for the content area (Appendix A). Rebuild interactive widgets in a few lines of vanilla JS or GSAP, matching their initial state exactly. Then follow `uichemy-composer/read-skill` `{ name: "code-to-wordpress" }` **from Step 1.5 (split into sections) onward**, with these overrides:

- Pages → `uichemy-composer/page` (title/slug/status per Rule 4).
- Builder templates (single, archive, header, footer, 404, search) → native UiChemy theme-builder templates (`uichemy-composer/theme-builder`) with the **same display conditions**, not pages. Use its `architecture` view to see which slots are empty.
- Dynamic regions are loops/bindings (3b), not copied values.
- A builder page's header/footer usually belongs to the theme or a template, so migrate it once as a site-wide template (ask: site-wide or page-only, as `code-to-wordpress` Step 2.5, "ask the user where Header & Footer go"), not once per page.
- Per-item progress line: `🔧 [target] "About" 3/14 · 5 sections · 1 loop · 2 bindings`.
- **Media:** `code-to-wordpress` Step 5 does not run on its own. Assets were already moved in Step 2a; use the ledger to point sections at the target's media, and only handle what the ledger does not have.
- **Summary:** skip `code-to-wordpress` Step 7. This guide's **Final report** replaces it.

### 3d. If the source rendered without the theme, use Elementor Canvas

Some builders can run with the active theme switched off (Oxygen's "Disable Theme" is one example), so the page shows no theme header, footer, title or wrapper. A Composer page on the target would inherit the target theme's template and show that chrome, so it would not match.

- **Detect:** the source page you rendered shows **no theme header, footer, title or sidebar** and the body has no theme wrapper (`#page`, `#content`, `.site-header`, `.site-footer`, `.entry-header`), or the builder's own setting says so. If unclear, ask the user in one line. Do not guess.
- **If yes:** set the **new** page's template to `elementor_canvas` (stored in `_wp_page_template`). Order of options: a template field on `uichemy-composer/page` if `get-ability-info` shows one; else any tool you have that sets a page template (e.g. REST `POST /wp/v2/pages/<id>` with `{ "template": "elementor_canvas" }`); else tell the user: Elementor → Page Settings → Layout → Elementor Canvas.
- **Check** by opening the new page (draft preview is fine). If theme chrome is still there, report it.
- Canvas removes theme chrome, so the page must carry whatever the original showed on its own.
- If no: leave the template alone.

---

## Step 4 — Verify, source against target

At the end, compare the **source** and the **target** yourself, page by page and template by template, and make sure the target matches the source. Fix what does not match. Check:

1. **Layout and content:** sections, text, fonts, colors, images, buttons, interactive widgets.
2. **Dynamic regions (the most important):** loops show the **same items and count**; bindings show the right value on **two different entries**; filters, archives and pagination return results. A loop with zero rows almost always means draft entries or a wrong post-type/taxonomy/field name.
3. **Data:** spot-check at least 3 entries per post type: every field, image and relation matches.
4. **Completeness check (before the report):** go back to the Step 1 inventory and the ledger. Every line must be **moved**, **moved with an approximation**, or **not moved with a reason**. A line with no status is a miss: move it now. Compare source vs target counts per type; explain or fix any mismatch.
5. Fix, re-check, stop after two passes unless something is clearly broken.

---

## Final report

```
✅  Moved [source] → [target]   (source untouched)
    Pages 14/14 · Templates 4/4 · Entries 81/81 · Menus 2
    Assets: images 150/152 · SVG 18/18 (12 inline, 6 uploaded) · fonts 4/4 · video 2/2 · files 3/4
    Dynamic: 3 loops · 17 field bindings · 2 taxonomy filters
    Completeness: every inventory item has a status (moved / approximated / not moved)
    Template: Elementor Canvas on 6 pages (theme was disabled in source) | default
    Compared source vs target: done

    Rebuilt as code:  slider (Home), accordion (FAQ)
    Needs your check: contact form wiring, map embed, [third-party shortcode] on Pricing
    Not moved:        4 media (over size limit), users, form submissions, plugin settings, orders
    Status:           pages = draft · entries = published (so loops render)
    Ledger:           <path>
```

Be specific about anything approximated, rebuilt from output instead of data, or left as a placeholder. Never claim a match you did not verify.

---

## Appendix A — Read a rendered page and turn it into clean code

**Load.** Open at ≈1440×900, wait for load, scroll the whole page down and back up so lazy images, counters and scroll animations finish, wait ~1 s. Note popups, cookie banners and sticky bars (not content). Find the content root (`main`, `#content`, `.entry-content`, or the builder's root) and convert that subtree; header/footer are separate.

**Read structure and computed styles.** Run read-only JavaScript over the content root and return, per visible element: tag, leaf text, `href`/`src`/`alt`, bounding box and these computed styles (only values that differ from the tag's default): `display, position, flex-direction, flex-wrap, justify-content, align-items, gap, grid-template-columns, width, max-width, min-height, padding, margin, background-color, background-image, background-size, background-position, color, font-family, font-size, font-weight, line-height, letter-spacing, text-align, text-transform, text-decoration, border, border-radius, box-shadow, opacity, overflow, object-fit, aspect-ratio`. Skip hidden elements and wrappers with no styling of their own. Prefer intent-level values (`gap: 24px`). Also collect `@font-face` / Google Fonts in use, `::before`/`::after` that draw things, hover states of primary buttons, section background images/videos/overlays, SVG dividers.

**Responsive.** Resize to 768 and 375 (reload if breakpoints apply on load), repeat for sections that change, and write only the differences as `@media (max-width: 1024px)`, `(max-width: 768px)`, `(max-width: 480px)`. Reset the viewport after.

**Interactive widgets.** Record behavior from the live page: tabs/accordion (which is open, how many, labels), sliders (slides, autoplay, arrows/dots, items per view per breakpoint), counters (final values), sticky/parallax/reveal (what, direction, distance), popups (trigger, content). Rebuild each in vanilla JS or GSAP.

**Clean up (works for any builder).** Remove scaffolding: wrapper elements that only exist for the builder's grid (a div whose only child is a div, with no style of its own), generated ids and hash classes, the builder's `data-*` attributes, inline styles that mirror a class, builder JS and `<script>`/`<noscript>` boilerplate, inline JSON settings, lazy-load placeholders (`data-src` → real `src`), editor-only markup and empty placeholders, hidden duplicate desktop/mobile copies (express those as CSS). **Keep** text, heading levels, links (`href/target/rel`), buttons, lists, tables, images with `alt`, videos, SVGs, iframes, anchor ids other links point to, real structure and reading order. Rebuild layout with flex/grid matching the rendered geometry, not the old float/table/percentage hacks. Rename classes to short meaningful names; `code-to-wordpress` scopes them per section. Images keep source URLs **only until Step 2.3 / 2.7 rewrite them to the target's media**.
