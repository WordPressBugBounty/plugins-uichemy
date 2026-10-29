# Improve SEO

You were called because the user wants a site (or a page) to do better in search and in AI answers: titles and descriptions, social previews, structured data, site structure, redirects, crawling. This skill is the order to do it in and the rules that keep it clean.

Everything below uses two abilities: **`uichemy-composer/seo`** (audits, site settings, schema templates, redirects, crawling, permalinks) and **`uichemy-composer/page`** (the page's own head code, where its SEO tags live). Check exact parameters with `get-ability-info` before calling.

---

## 1. How UiChemy SEO works — read this first

**SEO values are real tags in head code.** There is no separate SEO database to fill in. A page's title is the `<title>` tag in that page's before-`</head>` code; the site-wide fallbacks are tags in the site's before-`</head>` code. UiChemy reads those tags, works out everything that is missing from the page content, and prints **each tag exactly once** — so writing a `<title>` into head code never produces a second title.

What UiChemy SEO gives you:

| Area | What it does | Where you change it |
|---|---|---|
| Titles & descriptions | Worked out from each page's H1 and first paragraph; a tag in head code overrides | page head code · site title template |
| Social previews | og:* and twitter:* for every page, with a site-wide fallback image | page head code · `update-site-settings` |
| Robots | index / noindex / nofollow per page; rules per content type and for archives | page head code (`<meta name="robots">`) · Sitemap settings |
| Canonical | The page's own URL unless head code says otherwise | page head code |
| Structured data | One JSON-LD `@graph` per page: Organization/Person, WebSite, WebPage, BreadcrumbList, Article, Product automatically, plus templates and page/section JSON-LD | see §5 |
| Sitemap & robots.txt | Keeps WordPress's `/wp-sitemap.xml` in step; robots.txt through WordPress | `update-site-settings` · `get-crawl-status` |
| Redirects & 404s | 301/302/307/410/451 redirects; automatic redirects on slug change and trash; a log of missing pages | `add-redirect` · `list-404s` |
| AEO | `/llms.txt` and a Markdown copy of each page for AI tools | `update-site-settings` (`llms_*`, `md_*`) |
| Audits | ~45 checks per page plus a site-wide inbox | `audit-site-seo` · `audit-page-seo` |

**Precedence — highest wins, per value:**

1. **This page's head code** (`<title>`, `<meta name="description">`, `<link rel="canonical">`, `<meta name="robots">`, `og:*`, `twitter:*`, `<script type="application/ld+json">`)
2. **The theme-builder template's head code** (Twig, filled per post — e.g. `<title>{{ post.title }} – Journal</title>` on the single-post template)
3. **The page's content** (main heading → title, excerpt or first paragraph → description, featured or first image → share image)
4. **The site head code** (fallback tags; its `<title>` is the **title template**, with `{{ title }}` where the page title goes)
5. **WordPress** (post title, permalink, site language)

Remove a tag from a page's head code and that value follows the page content again. A page `<title>` is used exactly as written — the site title template is not added to it.

When another SEO plugin (Yoast, Rank Math, …) owns the head, UiChemy prints no SEO tags. The tags stay in the code and come back if that plugin is removed. `audit-page-seo` → `ownership` tells you which case you are in; if another plugin owns it, tell the user and stop.

---

## 2. Finalise the site level first

Site settings are fallbacks for every page, so settle them before touching pages — otherwise you fix 40 pages and then change the template under them.

1. `list-site-settings` — see what is set. `head_code_keys` are stored as tags in the site head code; the rest are switches.
2. **Site title & tagline** — `site_title` / `site_tagline` are WordPress's Site Title and Tagline (Settings → General), used as `{{ site.title }}` / `{{ site.tagline }}` in page titles. Three names are kept apart on purpose: the **site title** (page titles), `site_name_override` (link previews only, og:site_name — set it only when it should differ) and `entity_name` (structured data only). E.g. `update-site-settings {values: {site_title: "Nordform", site_tagline: "Handmade oak furniture"}}`.
3. **Title template** — `update-site-settings {values: {title_template: "{{ title }} {{ site.separator }} {{ site.title }}", title_separator: "|"}}`. Keep the brand at the end and the whole thing short: page titles should land at 50–60 characters.
4. **Social fallbacks** — a default share image (`default_share_image`: a media-library id, 1200×630) used by any page with none of its own, `default_social_title` (a link-preview title template, e.g. `{{ title }} — Brand`, for pages without their own og:title) and `default_social_description` (link-preview text for pages whose own text gives none) — both sharing only, never search — plus `twitter_handle`, `twitter_card: "large"`. Ask the user for a brand image; if they have none, offer to generate one that matches the site, upload it with `uichemy-composer/media`, and use its id.
5. **Identity** — `entity_type` (organization | person), `entity_name`, `entity_logo`, `entity_description`, `social_profiles` (https links). This becomes the Organization/Person every page's schema points to.
6. **Default description** — `home_description` is the fallback for any page that has no description and no paragraph to take one from. Leave it empty unless the home page needs it: one description on every page is worse than none.
7. **Permalinks** — `get-crawl-status` → `permalinks`. If the structure is not `/%postname%/`, recommend it and, with the user's OK, `update-permalinks {structure: "postname"}`. On a live site this moves every URL: afterwards check `list-404s` and redirect what WordPress does not.
8. **Verification** — if the user uses Google Search Console / Bing Webmaster Tools, set `verify_google` / `verify_bing` (paste the code or the whole meta tag).
9. **Search engine visibility** — WordPress's "Discourage search engines from indexing this site" (Settings → Reading) noindexes every page and drops the sitemap; UiChemy honours it and stops serving llms.txt too. `audit-page-seo` → `search_engines` and the `site_hidden` blocker tell you when it is on. It is normal while a site is being built: ask the user whether the site is live, and only then `update-site-settings {values: {blog_public: true}}`.
10. **Sitemap & crawling** — `get-crawl-status`: robots.txt must not block the site and the sitemap must resolve. Content types that are not real pages (landing-page variants, internal CPTs) get `types: {slug: {index: false, sitemap: false}}`.

---

## 3. Audit and finalise every page

Work like an inbox: one list, worst first, clear it top to bottom.

1. `audit-site-seo` — the inbox: findings across every published page with the pages each affects (duplicate titles, duplicate descriptions, noindex pages still in the sitemap, broken links, missing titles…). Blockers first, then warnings.
2. For each page: `audit-page-seo {post_id}`. Read:
   - `values` — every value, `from` (in words) and `lives_in` (`page_head` | `template_head` | `content` | `site_head` | `wordpress`). Fix a value **where it lives**.
   - `page_head_tags` — the SEO tags already in this page's head code.
   - `findings` — each with the exact call that fixes it.
   - `siblings` — other pages' titles and descriptions, so yours is distinct.
<!-- mode:sections -->
3. Write the page's tags into its head code with `uichemy-composer/page`, in `page_before_head` (there is no `page_css` parameter): `update-page-code {post_id, page_before_head}` appends, `set-page-code {post_id, page_before_head}` replaces the whole block:
<!-- /mode -->
<!-- mode:composer -->
3. Write the page's tags into its `head_code` with `uichemy-composer/page`. First `get-code {post_id, fields: ["head_code"]}` - it returns the current block and a `hash`. Then either `patch-code {post_id, field: "head_code", expected_hash, edits: [{find, replace}]}` to change one tag (to add tags, anchor on a line you keep, or on the existing `<title>`), or `set-code {post_id, head_code, expected_hash}` to replace the whole block. There is no append:
<!-- /mode -->

```html
<title>Handmade Oak Dining Tables | Nordform</title>
<meta name="description" content="Solid oak dining tables made to order in Oslo, from 4 to 12 seats. Free delivery in Norway and a 10-year guarantee.">
<meta property="og:image" content="https://example.com/wp-content/uploads/oak-table-1200x630.jpg">
```

<!-- mode:sections -->
`set-page-code` replaces **everything** in the page's head code, including its `<style>`, font `<link>` and script lines, so read the current block first and send it back with your tags changed. Appending a second `<title>` with `update-page-code` works (the last tag of a kind wins and it prints once), but the old one stays in the code; replace instead of piling tags up.
<!-- /mode -->
<!-- mode:composer -->
`set-code` with `head_code` replaces **everything** in the page's head code, including its font `<link>` and script lines, so send back the block `get-code` returned with your tags changed. A second `<title>` would work (the last tag of a kind wins and it prints once), but the old one stays in the code; replace the tag with `patch-code` instead of piling tags up.
<!-- /mode -->

Rules for the values:

- **One H1 per page**: exactly one, saying what the page is about. It is where the title is worked out from, and `audit-page-seo` flags `multiple_h1`. Turn extra H1s into H2s in the section HTML.
- **Title**: what the page is about, in the words people search, then the brand; 50–60 characters; unique on the site. Only add a `<title>` when the H1-derived one is wrong or weak — the H1 is often already right.
- **Description**: 70–160 characters, a real sentence that answers "why click this", unique.
- **Share image**: every important page (home, services, products, posts) should have its own `og:image`, 1200×630. **Ask the user for the image, or ask whether you may generate one relevant to the page**, upload it with `uichemy-composer/media`, and use the returned URL.
- **noindex** only for pages that must not appear in search (thank-you pages, internal tools, thin duplicates): `<meta name="robots" content="noindex">`. It also drops the page from the sitemap.
- **canonical** only when this page duplicates another one.
- Do not write `og:title` / `og:description` unless the social text should differ from the search text — they follow the title and description by themselves.

- **Text must be in the HTML**: search engines read the HTML the server sends. Headings, copy and links that a section's JS adds after load (text injected by script, content behind a client-side fetch) may never be indexed. Put the text in the section HTML and let JS only animate or reveal it.

Fix content problems in the content, not with tags: heading order, a missing H1, alt text (`set-image-alt`), link text, `rel="noopener"` — in the section HTML with `uichemy-composer/page patch-section-code`.

Re-run `audit-page-seo` after each page; finish with `audit-site-seo` again.

---

## 4. Silo architecture — organise the site for search

A site whose pages sit in clear topic groups ranks better and is easier to crawl than a flat pile. Check the structure as a whole before polishing pages:

1. **List the pages** (`uichemy-composer/page list`) and group them by topic: one **hub** page per topic (e.g. /services/), with its **sub-pages** under it (/services/kitchen-design/, /services/installation/).
2. **Parent / child**: create sub-pages under their hub with `parent` — `uichemy-composer/page create` / `create-with-sections {…, parent: <hub id>}` — or move an existing page with `update {post_id, parent: <hub id>, menu_order}`. With `/%postname%/` permalinks the URL then nests (`/services/kitchen-design/`). Changing the parent of a published page changes its URL — UiChemy adds the redirect from the old address automatically; confirm with `list-redirects`.
3. **Link inside the silo**: the hub links to every sub-page, each sub-page links back to the hub and to its siblings where it helps the reader. Links between silos only where they make sense.
4. **No orphan pages**: every indexable page needs at least one link from another page or from a menu, or crawlers may never find it. Go through the page list, check that each page is linked from its hub (or a menu), and add the link from the right hub where it is missing. A page that should not be linked anywhere probably should not be indexed either (`noindex`) - ask the user.
5. **Breadcrumbs**: a "Home › Services › Kitchen design" trail on sub-pages (`<nav aria-label="Breadcrumb">` with an ordered list) — UiChemy reads it and publishes BreadcrumbList by itself.
6. **One page per intent**: two pages competing for the same search (same topic, similar titles) should be merged — keep the stronger one and `add-redirect` the other to it.
7. Posts belong to categories the same way: a category archive is the hub for its posts. Keep category archives indexable when they are real topic hubs.

Propose the structure to the user before moving pages: re-parenting changes URLs.

---

## 5. Schema (structured data)

Three places, chosen by what the data describes. Refer to schema.org for every type and add schema for **every** object on the site that Google can show as a rich result or that helps AI tools understand the page — FAQPage, HowTo, Product, Review/AggregateRating, Event, Recipe, VideoObject, LocalBusiness, Course, JobPosting, BreadcrumbList.

| What it describes | Where it goes | How |
|---|---|---|
| The organisation / person behind the site | Site Settings → identity | `update-site-settings` (`entity_*`) — never a template |
| Every post of one kind (all recipes, all events, all job posts) | **Schema template** | `add-schema-template {name, type, conditions, template}` — JSON with Twig filled per post: `{{ post.title }}`, `{{ post.url }}`, `{{ post.thumbnail.src }}`, `{{ post.meta('<key>') }}` (any custom field - UiChemy Custom Fields, ACF or JetEngine - by its name from `dynamic` `list-fields`; dot syntax `post.meta.key` renders empty), `{{ site.title }}`. Dates must be ISO 8601: `{{ post.date('c') }}` / `{{ post.modified('c') }}`. Plain `{{ post.date }}` prints "October 12, 2026" and `{{ post.date|date('c') }}` drops the time to midnight; Google rejects or misreads both. Start from a preset in `list-schema-templates`. |
<!-- mode:sections -->
| One whole page (a single service, a single product landing page) | **Page head code** | a `<script type="application/ld+json">` in `page_before_head` via `update-page-code` |
| One part of one page (the FAQ accordion, the steps, a review block) | **The section itself** | a `<script type="application/ld+json">` inside that section's HTML, next to the content it describes (the **Schema** panel in the UiChemy editor edits it) |
<!-- /mode -->
<!-- mode:composer -->
| One whole page (a single service, a single product landing page) | **Page head code** | a `<script type="application/ld+json">` in the page's `head_code` (`get-code`, then `patch-code` with `field: "head_code"`, or `set-code`) |
| One part of one page (the FAQ accordion, the steps, a review block) | **Page head code too** | its own `<script type="application/ld+json">` in the same `head_code`, beside the page's other JSON-LD - a Composer Builder page has no sections, and JSON-LD in `head_code` is part of the graph `preview-page-schema` checks |
<!-- /mode -->

- **Section-level types are never templates**: FAQPage, HowTo, Review, ClaimReview, QAPage, AggregateRating, BreadcrumbList describe one part of one page, so `add-schema-template` refuses them. Put them on that page (see the table above).
- Do not describe the same thing twice (a template Product and a page Product for the same page). `preview-page-schema {post_id}` shows the page's graph with each entity's source; Google's required properties are checked and an incomplete entity is left out and reported.
- Values in schema must match what the page shows: FAQ answers word for word, real prices, real ratings. Never invent ratings or reviews.
- Test with the `test_url` from `preview-page-schema` (Google Rich Results Test) once the page is live.

---

## 6. Redirects and missing pages

Lost URLs lose rankings and visitors.

1. `list-404s` — addresses visitors and crawlers hit that do not exist, most frequent first. For each real one (not bot noise like `/wp-login.php`, `.env`, random strings): `add-redirect {source: "/old-path", target: "/best-replacement/"}` — 301 by default.
2. **Replacing a page**: when a new page takes over from an old one, redirect the old address to the new one (301) before or as you unpublish it. A page removed for good with no replacement: `type: 410`.
3. Slug changes, parent changes and trashing published pages add redirects automatically (origin `slug-change` / `trash`); check them with `list-redirects`.
4. Never redirect everything to the home page — send each old URL to its closest real equivalent.
5. Avoid chains: if A → B and B → C, `update-redirect` A to point straight at C.

---

## 7. Speed is SEO

Load time, layout shifts (CLS) and image weight are ranking signals. They are not this skill's job: read `improve-performance` (`uichemy-composer/read-skill {name: "improve-performance"}`) for the Performance settings, image optimisation to WebP / AVIF (UiChemy Pro), fast-section rules and the Lighthouse tests. Run it after the SEO pass, on the most important pages.

---

## 8. Trust — real people, real content (E-E-A-T)

Search engines and AI answers favour content that clearly comes from people who know the subject.

- **Author bio**: posts and expert pages need a real author. Ask the user for the facts - name, role, experience, credentials, photo, profile links - then add an author box section on posts (or on the single-post theme-builder template) and the author as a Person in schema. Never invent names, credentials, experience or photos.
- **About and contact**: the site should have an About page and real contact details; check they exist and link them from the footer.
- **Generic AI copy**: flag text that sounds generic or padded ("in today's fast-paced world", "unlock", "elevate", empty superlatives, the same sentence shape on every page). Propose plainer, specific rewrites with real facts from the user, and change copy only with the user's OK.

---

## 9. AEO — answer engines

`update-site-settings {values: {llms_enabled: true, md_enabled: true}}` publishes `/llms.txt` and a clean Markdown copy of each page for AI tools. Clear titles, descriptions and schema help AI answers more than these files do — do sections 2–8 first. Keep a page out with `<meta name="uichemy:ai" content="exclude">` in its head code.

---

## Finish

Before telling the user it is done:

- `audit-site-seo` has no blockers and no warnings you have not explained.
- Every important page has its own title, description and share image, distinct from its siblings.
- `get-crawl-status`: sitemap resolves, robots.txt does not block the site, permalinks are post-name.
- `list-404s` has nothing real left unredirected.

- Every indexable page is linked from at least one other page or menu, and has exactly one H1.

Report to the user what you changed, what you left and why, and hand over what only they can do:

- **Search Console and Bing Webmaster Tools**: verify the site and submit `/wp-sitemap.xml`, then watch the indexing reports.
- **Backlinks**: links from other reputable sites (partners, directories, press, guest articles) are built off the site; no tool here can do it.
- **Real content and facts**: author details, images, and a final read of any copy you rewrote.
- **Confirm a permalink change** on a live site before you make it.
