<?php
/**
 * Uich_CB_Library — the starter sections the Add Section dialog offers.
 *
 * The library endpoint (Uich_CB_Rest_Page::library()) only answers whatever the
 * `uich_cb/library_sections` filter returns; this class is the builder's own
 * contribution to it. Anything else can still add to or replace the list
 * through the same filter.
 *
 * Every section lives in this one file, in manifest(): its name, category
 * and its HTML, CSS and JS side by side. Its settings are exactly the map the
 * document stores (raw_html / raw_css / raw_js), so inserting one needs no
 * conversion. A new starter is one more entry there.
 *
 * CSS is written unscoped: the renderer scopes it to the section on output.
 * JS runs as a plain inline script, so each script finds its own roots by a
 * `data-uich-lib` attribute and marks them bound — a canvas re-render or two
 * copies of one section on a page must not bind twice.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Library' ) ) {

	/**
	 * Registers the built-in starter sections.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Library {

		/**
		 * The built-in sections, in the order the shelf shows them.
		 *
		 * The key is the section's library id. `html`, `css` and `js` are the
		 * section's own code — exactly what lands in raw_html / raw_css / raw_js
		 * when it is inserted. Nowdoc, so nothing inside is interpolated.
		 *
		 * `slot` (header | footer | single | archive | error_404) marks a section built for one kind of
		 * Theme Builder template: editing such a template, the shelf offers only
		 * its own slot's sections (LibraryTab). `preview_html`, when set, is
		 * shown on the shelf instead of `html` — the Single sections print the
		 * post's own values ({{ post.title }}…), which the shelf cannot resolve.
		 * The Single sections are grouped (Post, Post lists, Categories) so the
		 * shelf can filter them on a single template.
		 * `also_page` on a slot section offers a copy of it to pages as well,
		 * under Blog (a post list with page numbers works on either).
		 *
		 * @return array<string, array{name: string, category: string, html: string, css: string, js: string, slot?: string, preview_html?: string}>
		 */
		private static function manifest() {
			return array(
				'nav' => array(
					'name'     => __( 'Header with menu', 'uichemy' ),
					'category' => __( 'Header', 'uichemy' ),
					'slot'     => 'header',
					'html'     => <<<'HTML'
					<header class="site-header" data-uich-lib="nav">
					  <div class="site-header__inner">
					    <a class="site-header__brand" href="#">
					      <svg class="site-header__mark" viewBox="0 0 100 100" aria-hidden="true"><g fill="currentColor"><rect x="10" y="43" width="80" height="14" rx="3"/><rect x="43" y="10" width="14" height="42" rx="3"/><rect x="43" y="10" width="14" height="42" rx="3" transform="rotate(-45 50 50)"/><rect x="43" y="10" width="14" height="42" rx="3" transform="rotate(45 50 50)"/></g><g fill="none" stroke-linecap="round" stroke-linejoin="round"><g stroke="#ffffff" stroke-width="26"><path d="M27 72 50 49 73 72"/><path d="M50 52V88"/></g><g stroke="#2454e6" stroke-width="14"><path d="M27 72 50 49 73 72"/><path d="M50 52V88"/></g></g></svg>
					      Northwind
					    </a>
					    <div class="site-header__menu">
					      <nav class="site-header__links" aria-label="Main">
					        <a href="#">Product</a>
					        <a href="#">Solutions</a>
					        <a href="#">Pricing</a>
					        <a href="#">Customers</a>
					        <a href="#">Resources</a>
					      </nav>
					      <div class="site-header__actions">
					        <a class="site-header__login" href="#">Log in</a>
					        <a class="site-header__btn" href="#">Book a demo</a>
					      </div>
					    </div>
					    <button type="button" class="site-header__toggle" aria-expanded="false" aria-label="Open menu">
					      <span></span>
					      <span></span>
					    </button>
					  </div>
					</header>
					HTML
					,
					'css'      => <<<'CSS'
					.site-header {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  position: relative;
					  border-bottom: 1px solid var(--line);
					  background: #ffffff;
					}
					.site-header *,
					.site-header *::before,
					.site-header *::after {
					  box-sizing: border-box;
					}
					.site-header h1,
					.site-header h2,
					.site-header h3,
					.site-header p,
					.site-header a,
					.site-header button,
					.site-header input,
					.site-header textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.site-header h1,
					.site-header h2,
					.site-header h3 {
					  color: inherit;
					}
					.site-header__inner {
					  display: flex;
					  align-items: center;
					  gap: 40px;
					  max-width: 1200px;
					  height: 72px;
					  margin: 0 auto;
					  padding: 0 24px;
					}
					.site-header__brand {
					  display: inline-flex;
					  align-items: center;
					  gap: 10px;
					  color: var(--ink);
					  font-size: 18px;
					  font-weight: 650;
					  letter-spacing: -0.02em;
					  text-decoration: none;
					}
					.site-header__mark {
					  flex: none;
					  display: block;
					  width: 28px;
					  height: 28px;
					}
					.site-header__menu {
					  display: flex;
					  flex: 1;
					  align-items: center;
					  justify-content: space-between;
					  gap: 24px;
					}
					.site-header__links {
					  display: flex;
					  gap: 32px;
					}
					.site-header__links a,
					.site-header__login {
					  color: var(--text);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: color 0.15s ease;
					}
					.site-header__links a:hover,
					.site-header__login:hover {
					  color: var(--ink);
					}
					.site-header__actions {
					  display: flex;
					  align-items: center;
					  gap: 24px;
					}
					.site-header__btn {
					  display: inline-flex;
					  align-items: center;
					  height: 40px;
					  padding: 0 18px;
					  border-radius: 8px;
					  background: var(--ink);
					  color: #ffffff;
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: background 0.15s ease;
					}
					.site-header__btn:hover {
					  background: #2a2f38;
					}
					.site-header__toggle {
					  display: none;
					  flex-direction: column;
					  justify-content: center;
					  gap: 6px;
					  width: 40px;
					  height: 40px;
					  margin-left: auto;
					  padding: 0 10px;
					  border: 1px solid var(--line);
					  border-radius: 8px;
					  background: #ffffff;
					  cursor: pointer;
					}
					.site-header__toggle span {
					  display: block;
					  height: 1.5px;
					  background: var(--ink);
					  transition: transform 0.2s ease;
					}
					.site-header[data-open] .site-header__toggle span:first-child {
					  transform: translateY(3.75px) rotate(45deg);
					}
					.site-header[data-open] .site-header__toggle span:last-child {
					  transform: translateY(-3.75px) rotate(-45deg);
					}
					@media (max-width: 1024px) {
					  .site-header__links {
					    gap: 24px;
					  }
					}
					@media (max-width: 767px) {
					  .site-header__inner {
					    height: 64px;
					  }
					  .site-header__toggle {
					    display: flex;
					  }
					  .site-header__menu {
					    display: none;
					    position: absolute;
					    top: 100%;
					    left: 0;
					    right: 0;
					    z-index: 20;
					    flex-direction: column;
					    align-items: stretch;
					    gap: 16px;
					    padding: 8px 24px 24px;
					    border-bottom: 1px solid var(--line);
					    background: #ffffff;
					  }
					  .site-header[data-open] .site-header__menu {
					    display: flex;
					  }
					  .site-header__links {
					    flex-direction: column;
					    gap: 0;
					  }
					  .site-header__links a {
					    padding: 14px 0;
					    border-bottom: 1px solid var(--line);
					    font-size: 16px;
					  }
					  .site-header__actions {
					    flex-direction: column;
					    align-items: stretch;
					    gap: 12px;
					  }
					  .site-header__login {
					    padding: 8px 0;
					  }
					  .site-header__btn {
					    justify-content: center;
					    height: 44px;
					  }
					}
					CSS
					,
					'js'       => <<<'JS'
					/* Phone menu: the button opens and closes the links panel. */
					(function () {
					  document.querySelectorAll('[data-uich-lib="nav"]:not([data-uich-ready])').forEach(function (root) {
					    root.setAttribute('data-uich-ready', '');
					    var toggle = root.querySelector('.site-header__toggle');
					    if (!toggle) return;
					    toggle.addEventListener('click', function () {
					      var open = !root.hasAttribute('data-open');
					      if (open) root.setAttribute('data-open', ''); else root.removeAttribute('data-open');
					      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
					      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
					    });
					  });
					})();
					JS
					,
				),
				'hero' => array(
					'name'     => __( 'Centred hero', 'uichemy' ),
					'category' => __( 'Hero', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="hero-centered">
					  <div class="hero-centered__inner">
					    <a class="hero-centered__notice" href="#">
					      <span class="hero-centered__tag">New</span>
					      Client portals are now live
					      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					    </a>
					    <h1 class="hero-centered__title">Run every client project from one calm workspace</h1>
					    <p class="hero-centered__lead">Plan the work, share progress and get approvals without the email chains. Northwind keeps your team and your clients on the same page.</p>
					    <div class="hero-centered__actions">
					      <a class="hero-centered__btn hero-centered__btn--primary" href="#">Start free trial</a>
					      <a class="hero-centered__btn hero-centered__btn--secondary" href="#">Book a demo</a>
					    </div>
					    <div class="hero-centered__proof">
					      <div class="hero-centered__faces">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=96&h=96&q=80&auto=format&fit=crop" alt="">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=96&h=96&q=80&auto=format&fit=crop" alt="">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=96&h=96&q=80&auto=format&fit=crop" alt="">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=96&h=96&q=80&auto=format&fit=crop" alt="">
					      </div>
					      <p>Used by <strong>4,000+</strong> agencies and studios</p>
					    </div>
					  </div>
					  <div class="hero-centered__media">
					    <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1551434678-e076c223a692?w=2000&h=1000&q=80&auto=format&fit=crop" alt="Team working together at their desks">
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.hero-centered {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 104px 24px 0;
					  background: #ffffff;
					  text-align: center;
					}
					.hero-centered *,
					.hero-centered *::before,
					.hero-centered *::after {
					  box-sizing: border-box;
					}
					.hero-centered h1,
					.hero-centered h2,
					.hero-centered h3,
					.hero-centered p,
					.hero-centered a,
					.hero-centered button,
					.hero-centered input,
					.hero-centered textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.hero-centered h1,
					.hero-centered h2,
					.hero-centered h3 {
					  color: inherit;
					}
					.hero-centered__inner {
					  max-width: 800px;
					  margin: 0 auto;
					}
					.hero-centered__notice {
					  display: inline-flex;
					  align-items: center;
					  gap: 10px;
					  padding: 5px 14px 5px 5px;
					  border: 1px solid var(--line);
					  border-radius: 999px;
					  color: var(--text);
					  font-size: 14px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease;
					}
					.hero-centered__notice:hover {
					  border-color: #c9ccd3;
					}
					.hero-centered__tag {
					  padding: 3px 10px;
					  border-radius: 999px;
					  background: var(--soft);
					  color: var(--ink);
					  font-size: 12px;
					  font-weight: 600;
					}
					.hero-centered__title {
					  margin: 28px 0 20px;
					  font-size: clamp(40px, 5.4vw, 64px);
					  font-weight: 600;
					  line-height: 1.05;
					  letter-spacing: -0.035em;
					  text-wrap: balance;
					}
					.hero-centered__lead {
					  max-width: 620px;
					  margin: 0 auto;
					  color: var(--text);
					  font-size: 19px;
					  line-height: 1.6;
					}
					.hero-centered__actions {
					  display: flex;
					  flex-wrap: wrap;
					  justify-content: center;
					  gap: 12px;
					  margin-top: 36px;
					}
					.hero-centered__btn {
					  display: inline-flex;
					  align-items: center;
					  height: 48px;
					  padding: 0 24px;
					  border-radius: 8px;
					  font-size: 16px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: background 0.15s ease, border-color 0.15s ease;
					}
					.hero-centered__btn--primary {
					  background: var(--ink);
					  color: #ffffff;
					}
					.hero-centered__btn--primary:hover {
					  background: #2a2f38;
					}
					.hero-centered__btn--secondary {
					  border: 1px solid var(--line);
					  background: #ffffff;
					  color: var(--ink);
					}
					.hero-centered__btn--secondary:hover {
					  border-color: #c9ccd3;
					}
					.hero-centered__proof {
					  display: flex;
					  align-items: center;
					  justify-content: center;
					  gap: 12px;
					  margin-top: 32px;
					}
					.hero-centered__faces {
					  display: flex;
					}
					.hero-centered__faces img {
					  width: 32px;
					  height: 32px;
					  margin-left: -8px;
					  border: 2px solid #ffffff;
					  border-radius: 50%;
					  object-fit: cover;
					}
					.hero-centered__faces img:first-child {
					  margin-left: 0;
					}
					.hero-centered__proof p {
					  margin: 0;
					  color: var(--muted);
					  font-size: 14px;
					}
					.hero-centered__proof strong {
					  color: var(--ink);
					  font-weight: 600;
					}
					.hero-centered__media {
					  max-width: 1152px;
					  margin: 72px auto 0;
					}
					.hero-centered__media img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 2 / 1;
					  border-radius: 16px 16px 0 0;
					  object-fit: cover;
					}
					@media (max-width: 767px) {
					  .hero-centered {
					    padding-top: 72px;
					  }
					  .hero-centered__lead {
					    font-size: 17px;
					  }
					  .hero-centered__btn {
					    flex: 1 1 100%;
					    justify-content: center;
					  }
					  .hero-centered__media {
					    margin-top: 56px;
					  }
					  .hero-centered__media img {
					    aspect-ratio: 4 / 3;
					    border-radius: 12px 12px 0 0;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'hero-split' => array(
					'name'     => __( 'Split hero', 'uichemy' ),
					'category' => __( 'Hero', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="hero-split">
					  <div class="hero-split__inner">
					    <div class="hero-split__copy">
					      <p class="hero-split__eyebrow">Project management for agencies</p>
					      <h1 class="hero-split__title">Deliver client work on time, every time</h1>
					      <p class="hero-split__lead">Timelines, files, feedback and invoices in one shared space. Your clients always know where things stand, so your team can get on with the work.</p>
					      <ul class="hero-split__list">
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Set up in under ten minutes</li>
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Unlimited clients on every plan</li>
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Free for 14 days, no card needed</li>
					      </ul>
					      <div class="hero-split__actions">
					        <a class="hero-split__btn hero-split__btn--primary" href="#">Get started</a>
					        <a class="hero-split__link" href="#">
					          Watch a 2-min tour
					          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					        </a>
					      </div>
					    </div>
					    <div class="hero-split__media">
					      <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1600880292203-757bb62b4baf?w=1100&h=1300&q=80&auto=format&fit=crop" alt="Two colleagues celebrating at a meeting table">
					      <div class="hero-split__card">
					        <span class="hero-split__stars" aria-label="Rated 4.9 out of 5">★★★★★</span>
					        <p><strong>4.9 / 5</strong> from 1,200+ reviews</p>
					      </div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.hero-split {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px;
					  background: #ffffff;
					}
					.hero-split *,
					.hero-split *::before,
					.hero-split *::after {
					  box-sizing: border-box;
					}
					.hero-split h1,
					.hero-split h2,
					.hero-split h3,
					.hero-split p,
					.hero-split a,
					.hero-split button,
					.hero-split input,
					.hero-split textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.hero-split h1,
					.hero-split h2,
					.hero-split h3 {
					  color: inherit;
					}
					.hero-split__inner {
					  display: grid;
					  grid-template-columns: 1.05fr 1fr;
					  align-items: center;
					  gap: 72px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.hero-split__eyebrow {
					  margin: 0;
					  color: var(--accent);
					  font-size: 15px;
					  font-weight: 600;
					}
					.hero-split__title {
					  margin: 16px 0 20px;
					  font-size: clamp(38px, 4.8vw, 60px);
					  font-weight: 600;
					  line-height: 1.06;
					  letter-spacing: -0.035em;
					  text-wrap: balance;
					}
					.hero-split__lead {
					  margin: 0;
					  max-width: 520px;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.hero-split__list {
					  display: grid;
					  gap: 12px;
					  margin: 28px 0 0;
					  padding: 0;
					  list-style: none;
					}
					.hero-split__list li {
					  display: flex;
					  align-items: center;
					  gap: 10px;
					  color: var(--ink);
					  font-size: 16px;
					}
					.hero-split__list svg {
					  flex: none;
					  color: var(--accent);
					}
					.hero-split__actions {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 28px;
					  margin-top: 40px;
					}
					.hero-split__btn {
					  display: inline-flex;
					  align-items: center;
					  height: 48px;
					  padding: 0 24px;
					  border-radius: 8px;
					  font-size: 16px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: background 0.15s ease;
					}
					.hero-split__btn--primary {
					  background: var(--ink);
					  color: #ffffff;
					}
					.hero-split__btn--primary:hover {
					  background: #2a2f38;
					}
					.hero-split__link {
					  display: inline-flex;
					  align-items: center;
					  gap: 8px;
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 500;
					  text-decoration: none;
					}
					.hero-split__link svg {
					  transition: transform 0.15s ease;
					}
					.hero-split__link:hover svg {
					  transform: translateX(3px);
					}
					.hero-split__media {
					  position: relative;
					}
					.hero-split__media img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 5 / 6;
					  border-radius: 16px;
					  object-fit: cover;
					}
					.hero-split__card {
					  position: absolute;
					  left: -28px;
					  bottom: 32px;
					  padding: 16px 20px;
					  border: 1px solid var(--line);
					  border-radius: 12px;
					  background: #ffffff;
					  box-shadow: 0 12px 32px rgba(14, 17, 22, 0.08);
					}
					.hero-split__stars {
					  color: #f2a91d;
					  font-size: 15px;
					  letter-spacing: 2px;
					}
					.hero-split__card p {
					  margin: 4px 0 0;
					  color: var(--text);
					  font-size: 14px;
					}
					.hero-split__card strong {
					  color: var(--ink);
					  font-weight: 600;
					}
					@media (max-width: 1024px) {
					  .hero-split__inner {
					    grid-template-columns: 1fr;
					    gap: 56px;
					  }
					  .hero-split__media img {
					    aspect-ratio: 4 / 3;
					  }
					  .hero-split__card {
					    left: 20px;
					    bottom: 20px;
					  }
					}
					@media (max-width: 767px) {
					  .hero-split {
					    padding: 64px 24px;
					  }
					  .hero-split__lead {
					    font-size: 17px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'logos' => array(
					'name'     => __( 'Logo strip', 'uichemy' ),
					'category' => __( 'Logos', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="logo-strip">
					  <div class="logo-strip__inner">
					    <p class="logo-strip__label">Trusted by fast-moving teams at</p>
					    <ul class="logo-strip__row">
					      <li class="logo-strip__item logo-strip__item--1">Lumen</li>
					      <li class="logo-strip__item logo-strip__item--2">ARCADIA</li>
					      <li class="logo-strip__item logo-strip__item--3">Fieldwork</li>
					      <li class="logo-strip__item logo-strip__item--4">northstar</li>
					      <li class="logo-strip__item logo-strip__item--5">Kettle&amp;Co</li>
					      <li class="logo-strip__item logo-strip__item--6">VERTEX</li>
					    </ul>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.logo-strip {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 56px 24px;
					  background: #ffffff;
					}
					.logo-strip *,
					.logo-strip *::before,
					.logo-strip *::after {
					  box-sizing: border-box;
					}
					.logo-strip h1,
					.logo-strip h2,
					.logo-strip h3,
					.logo-strip p,
					.logo-strip a,
					.logo-strip button,
					.logo-strip input,
					.logo-strip textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.logo-strip h1,
					.logo-strip h2,
					.logo-strip h3 {
					  color: inherit;
					}
					.logo-strip__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					  text-align: center;
					}
					.logo-strip__label {
					  margin: 0 0 28px;
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 500;
					}
					.logo-strip__row {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: space-between;
					  gap: 24px 40px;
					  margin: 0;
					  padding: 0;
					  list-style: none;
					}
					/* Word marks stand in for logos. Swap each <li> for an <img> of the real one. */
					.logo-strip__item {
					  color: #9aa0ab;
					  font-size: 22px;
					  line-height: 1;
					  white-space: nowrap;
					}
					.logo-strip__item--1 {
					  font-weight: 700;
					  letter-spacing: -0.04em;
					}
					.logo-strip__item--2 {
					  font-family: Georgia, "Times New Roman", serif;
					  font-size: 19px;
					  letter-spacing: 0.18em;
					}
					.logo-strip__item--3 {
					  font-weight: 500;
					  font-style: italic;
					  letter-spacing: -0.02em;
					}
					.logo-strip__item--4 {
					  font-weight: 800;
					  letter-spacing: -0.05em;
					}
					.logo-strip__item--5 {
					  font-family: Georgia, "Times New Roman", serif;
					  font-weight: 700;
					}
					.logo-strip__item--6 {
					  font-size: 18px;
					  font-weight: 600;
					  letter-spacing: 0.24em;
					}
					@media (max-width: 767px) {
					  .logo-strip__row {
					    justify-content: center;
					    gap: 24px 32px;
					  }
					  .logo-strip__item {
					    font-size: 19px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'features' => array(
					'name'     => __( 'Feature grid', 'uichemy' ),
					'category' => __( 'Features', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="feature-grid">
					  <div class="feature-grid__inner">
					    <header class="feature-grid__head">
					      <p class="feature-grid__eyebrow">Features</p>
					      <h2 class="feature-grid__title">Built around the way client work really happens</h2>
					      <p class="feature-grid__lead">No bloated menus or features you will never use. Just the tools that keep projects moving and clients in the loop.</p>
					    </header>
					    <div class="feature-grid__grid">
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></span>
					        <h3>Shared timelines</h3>
					        <p>One plan your team and clients can both see, with dates that update the moment work moves.</p>
					      </article>
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>
					        <h3>One-click approvals</h3>
					        <p>Send a design or document for sign-off and get a clear yes, with a record of who approved what.</p>
					      </article>
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
					        <h3>Client portals</h3>
					        <p>Give every client a branded space for files, updates and invoices. No logins to remember.</p>
					      </article>
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
					        <h3>Time tracking</h3>
					        <p>Log hours against tasks as you go, then turn them into an invoice in a couple of clicks.</p>
					      </article>
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20V10M18 20V4M6 20v-4"/></svg></span>
					        <h3>Reports that make sense</h3>
					        <p>See budget, hours and progress per project at a glance, ready to share with a client.</p>
					      </article>
					      <article class="feature-grid__item">
					        <span class="feature-grid__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
					        <h3>Private by default</h3>
					        <p>Internal notes stay internal. You choose exactly what each client can see, task by task.</p>
					      </article>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.feature-grid {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.feature-grid *,
					.feature-grid *::before,
					.feature-grid *::after {
					  box-sizing: border-box;
					}
					.feature-grid h1,
					.feature-grid h2,
					.feature-grid h3,
					.feature-grid p,
					.feature-grid a,
					.feature-grid button,
					.feature-grid input,
					.feature-grid textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.feature-grid h1,
					.feature-grid h2,
					.feature-grid h3 {
					  color: inherit;
					}
					.feature-grid__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.feature-grid__head {
					  max-width: 640px;
					  margin-bottom: 64px;
					}
					.feature-grid__eyebrow {
					  margin: 0;
					  color: var(--accent);
					  font-size: 15px;
					  font-weight: 600;
					}
					.feature-grid__title {
					  margin: 12px 0 16px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					  text-wrap: balance;
					}
					.feature-grid__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.feature-grid__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 56px 48px;
					}
					.feature-grid__icon {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  width: 40px;
					  height: 40px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					}
					.feature-grid__item h3 {
					  margin: 20px 0 8px;
					  font-size: 18px;
					  font-weight: 600;
					  letter-spacing: -0.01em;
					}
					.feature-grid__item p {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					@media (max-width: 1024px) {
					  .feature-grid__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .feature-grid {
					    padding: 72px 24px;
					  }
					  .feature-grid__head {
					    margin-bottom: 48px;
					  }
					  .feature-grid__grid {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'media-text' => array(
					'name'     => __( 'Image and text', 'uichemy' ),
					'category' => __( 'Features', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="image-text">
					  <div class="image-text__inner">
					    <div class="image-text__image">
					      <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=1200&h=1000&q=80&auto=format&fit=crop" alt="A small team working on laptops around a table">
					    </div>
					    <div class="image-text__copy">
					      <p class="image-text__eyebrow">For growing teams</p>
					      <h2 class="image-text__title">Spend less time on admin and more on the work clients pay for</h2>
					      <p class="image-text__text">Status updates write themselves from the work you are already doing. Clients get a clear weekly summary, and you get your Friday afternoons back.</p>
					      <ul class="image-text__list">
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Automatic weekly client summaries</li>
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Templates for your repeat projects</li>
					        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Works with Slack, Google Drive and Figma</li>
					      </ul>
					      <a class="image-text__link" href="#">
					        See how it works
					        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					      </a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.image-text {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: var(--soft);
					}
					.image-text *,
					.image-text *::before,
					.image-text *::after {
					  box-sizing: border-box;
					}
					.image-text h1,
					.image-text h2,
					.image-text h3,
					.image-text p,
					.image-text a,
					.image-text button,
					.image-text input,
					.image-text textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.image-text h1,
					.image-text h2,
					.image-text h3 {
					  color: inherit;
					}
					.image-text__inner {
					  display: grid;
					  grid-template-columns: 1fr 1fr;
					  align-items: center;
					  gap: 80px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.image-text__image img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 6 / 5;
					  border-radius: 16px;
					  object-fit: cover;
					}
					.image-text__eyebrow {
					  margin: 0;
					  color: var(--accent);
					  font-size: 15px;
					  font-weight: 600;
					}
					.image-text__title {
					  margin: 12px 0 20px;
					  font-size: clamp(28px, 3.2vw, 40px);
					  font-weight: 600;
					  line-height: 1.15;
					  letter-spacing: -0.03em;
					  text-wrap: balance;
					}
					.image-text__text {
					  margin: 0;
					  color: var(--text);
					  font-size: 17px;
					  line-height: 1.65;
					}
					.image-text__list {
					  display: grid;
					  gap: 14px;
					  margin: 28px 0 0;
					  padding: 28px 0 0;
					  border-top: 1px solid var(--line);
					  list-style: none;
					}
					.image-text__list li {
					  display: flex;
					  align-items: center;
					  gap: 10px;
					  color: var(--ink);
					  font-size: 16px;
					}
					.image-text__list svg {
					  flex: none;
					  color: var(--accent);
					}
					.image-text__link {
					  display: inline-flex;
					  align-items: center;
					  gap: 8px;
					  margin-top: 32px;
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 600;
					  text-decoration: none;
					}
					.image-text__link svg {
					  transition: transform 0.15s ease;
					}
					.image-text__link:hover svg {
					  transform: translateX(3px);
					}
					@media (max-width: 1024px) {
					  .image-text__inner {
					    grid-template-columns: 1fr;
					    gap: 48px;
					  }
					}
					@media (max-width: 767px) {
					  .image-text {
					    padding: 72px 24px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'steps' => array(
					'name'     => __( 'How it works in 3 steps', 'uichemy' ),
					'category' => __( 'Process', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="process-steps">
					  <div class="process-steps__inner">
					    <header class="process-steps__head">
					      <p class="process-steps__eyebrow">How it works</p>
					      <h2 class="process-steps__title">Up and running in an afternoon</h2>
					      <p class="process-steps__lead">No migration project, no training days. Most teams run their first client project the same week they sign up.</p>
					    </header>
					    <ol class="process-steps__list">
					      <li class="process-steps__item">
					        <span class="process-steps__num">01</span>
					        <h3>Import your projects</h3>
					        <p>Bring tasks over from a spreadsheet, Trello or Asana. Dates, owners and files come with them.</p>
					      </li>
					      <li class="process-steps__item">
					        <span class="process-steps__num">02</span>
					        <h3>Invite your clients</h3>
					        <p>Send each client a link to their own portal. They see their projects and nothing else.</p>
					      </li>
					      <li class="process-steps__item">
					        <span class="process-steps__num">03</span>
					        <h3>Work, share, get paid</h3>
					        <p>Post updates, collect approvals and send invoices from the same place you do the work.</p>
					      </li>
					    </ol>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.process-steps {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.process-steps *,
					.process-steps *::before,
					.process-steps *::after {
					  box-sizing: border-box;
					}
					.process-steps h1,
					.process-steps h2,
					.process-steps h3,
					.process-steps p,
					.process-steps a,
					.process-steps button,
					.process-steps input,
					.process-steps textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.process-steps h1,
					.process-steps h2,
					.process-steps h3 {
					  color: inherit;
					}
					.process-steps__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.process-steps__head {
					  max-width: 640px;
					  margin: 0 auto 64px;
					  text-align: center;
					}
					.process-steps__eyebrow {
					  margin: 0;
					  color: var(--accent);
					  font-size: 15px;
					  font-weight: 600;
					}
					.process-steps__title {
					  margin: 12px 0 16px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.process-steps__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.process-steps__list {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 32px;
					  margin: 0;
					  padding: 0;
					  list-style: none;
					}
					.process-steps__item {
					  padding-top: 28px;
					  border-top: 2px solid var(--line);
					}
					.process-steps__item:first-child {
					  border-top-color: var(--ink);
					}
					.process-steps__num {
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 600;
					  font-variant-numeric: tabular-nums;
					}
					.process-steps__item h3 {
					  margin: 16px 0 8px;
					  font-size: 20px;
					  font-weight: 600;
					  letter-spacing: -0.01em;
					}
					.process-steps__item p {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					@media (max-width: 767px) {
					  .process-steps {
					    padding: 72px 24px;
					  }
					  .process-steps__head {
					    margin-bottom: 48px;
					  }
					  .process-steps__list {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'stats' => array(
					'name'     => __( 'Stats with numbers', 'uichemy' ),
					'category' => __( 'Stats', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="stats-grid" data-uich-lib="stats">
					  <div class="stats-grid__inner">
					    <div class="stats-grid__copy">
					      <h2 class="stats-grid__title">The numbers our customers report back</h2>
					      <p class="stats-grid__text">Averages from 600 agencies after their first six months, measured against how they worked before.</p>
					    </div>
					    <dl class="stats-grid__grid">
					      <div class="stats-grid__item">
					        <dt class="stats-grid__label">Hours saved per person, each week</dt>
					        <dd class="stats-grid__num" data-count="6" data-suffix=" hrs">6 hrs</dd>
					      </div>
					      <div class="stats-grid__item">
					        <dt class="stats-grid__label">Fewer status emails sent</dt>
					        <dd class="stats-grid__num" data-count="72" data-suffix="%">72%</dd>
					      </div>
					      <div class="stats-grid__item">
					        <dt class="stats-grid__label">Faster client approvals</dt>
					        <dd class="stats-grid__num" data-count="3" data-suffix="×">3×</dd>
					      </div>
					      <div class="stats-grid__item">
					        <dt class="stats-grid__label">Projects delivered last year</dt>
					        <dd class="stats-grid__num" data-count="48000" data-suffix="+">48,000+</dd>
					      </div>
					    </dl>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.stats-grid {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.stats-grid *,
					.stats-grid *::before,
					.stats-grid *::after {
					  box-sizing: border-box;
					}
					.stats-grid h1,
					.stats-grid h2,
					.stats-grid h3,
					.stats-grid p,
					.stats-grid a,
					.stats-grid button,
					.stats-grid input,
					.stats-grid textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.stats-grid h1,
					.stats-grid h2,
					.stats-grid h3 {
					  color: inherit;
					}
					.stats-grid__inner {
					  display: grid;
					  grid-template-columns: 1fr 1.4fr;
					  gap: 80px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.stats-grid__title {
					  margin: 0 0 16px;
					  font-size: clamp(30px, 3.4vw, 42px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					  text-wrap: balance;
					}
					.stats-grid__text {
					  margin: 0;
					  color: var(--text);
					  font-size: 17px;
					  line-height: 1.6;
					}
					.stats-grid__grid {
					  display: grid;
					  grid-template-columns: repeat(2, minmax(0, 1fr));
					  gap: 48px 40px;
					  margin: 0;
					}
					.stats-grid__item {
					  display: flex;
					  flex-direction: column-reverse;
					  justify-content: flex-end;
					  gap: 8px;
					  padding-top: 24px;
					  border-top: 1px solid var(--line);
					}
					.stats-grid__num {
					  margin: 0;
					  font-size: clamp(40px, 4.4vw, 56px);
					  font-weight: 600;
					  line-height: 1;
					  letter-spacing: -0.04em;
					  font-variant-numeric: tabular-nums;
					}
					.stats-grid__label {
					  color: var(--text);
					  font-size: 15px;
					  line-height: 1.5;
					}
					@media (max-width: 1024px) {
					  .stats-grid__inner {
					    grid-template-columns: 1fr;
					    gap: 48px;
					  }
					}
					@media (max-width: 767px) {
					  .stats-grid {
					    padding: 72px 24px;
					  }
					  .stats-grid__grid {
					    gap: 36px 24px;
					  }
					}
					CSS
					,
					'js'       => <<<'JS'
					/* Count each number up from zero the first time it scrolls into view.
					   The HTML already holds the final value, so without JS (or with reduced
					   motion) the numbers simply show as written. */
					(function () {
					  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
					  function run(el) {
					    var target = parseFloat(el.getAttribute('data-count')) || 0;
					    var suffix = el.getAttribute('data-suffix') || '';
					    var start = null;
					    var duration = 1400;
					    function step(now) {
					      if (start === null) start = now;
					      var t = Math.min((now - start) / duration, 1);
					      var eased = 1 - Math.pow(1 - t, 3);
					      el.textContent = Math.round(target * eased).toLocaleString() + suffix;
					      if (t < 1) window.requestAnimationFrame(step);
					    }
					    window.requestAnimationFrame(step);
					  }
					  document.querySelectorAll('[data-uich-lib="stats"]:not([data-uich-ready])').forEach(function (root) {
					    root.setAttribute('data-uich-ready', '');
					    var nums = root.querySelectorAll('[data-count]');
					    if (reduce || !('IntersectionObserver' in window)) return;
					    var io = new IntersectionObserver(function (entries) {
					      entries.forEach(function (entry) {
					        if (!entry.isIntersecting) return;
					        io.unobserve(entry.target);
					        run(entry.target);
					      });
					    }, { threshold: 0.4 });
					    nums.forEach(function (el) {
					      // Start from zero only once we know it will count up.
					      el.textContent = '0' + (el.getAttribute('data-suffix') || '');
					      io.observe(el);
					    });
					  });
					})();
					JS
					,
				),
				'quote' => array(
					'name'     => __( 'Single testimonial', 'uichemy' ),
					'category' => __( 'Testimonials', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="testimonial-quote">
					  <figure class="testimonial-quote__inner">
					    <svg class="testimonial-quote__mark" width="40" height="32" viewBox="0 0 40 32" fill="currentColor" aria-hidden="true"><path d="M0 32V19.2C0 8.6 5.6 2.2 16.8 0l2 4.4C12.4 6.2 9.4 9.6 9 14.4h8.6V32H0zm22.4 0V19.2C22.4 8.6 28 2.2 39.2 0l2 4.4c-6.4 1.8-9.4 5.2-9.8 10H40V32H22.4z"/></svg>
					    <blockquote class="testimonial-quote__text">
					      <p>We used to lose a full day each week chasing feedback. Now clients approve work in the portal, and our project managers finally have time to manage projects.</p>
					    </blockquote>
					    <figcaption class="testimonial-quote__person">
					      <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=112&h=112&q=80&auto=format&fit=crop" alt="Maya Lindqvist">
					      <span>
					        <strong>Maya Lindqvist</strong>
					        Operations Director, Fieldwork Studio
					      </span>
					    </figcaption>
					  </figure>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.testimonial-quote {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: var(--soft);
					}
					.testimonial-quote *,
					.testimonial-quote *::before,
					.testimonial-quote *::after {
					  box-sizing: border-box;
					}
					.testimonial-quote h1,
					.testimonial-quote h2,
					.testimonial-quote h3,
					.testimonial-quote p,
					.testimonial-quote a,
					.testimonial-quote button,
					.testimonial-quote input,
					.testimonial-quote textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.testimonial-quote h1,
					.testimonial-quote h2,
					.testimonial-quote h3 {
					  color: inherit;
					}
					.testimonial-quote__inner {
					  max-width: 880px;
					  margin: 0 auto;
					  text-align: center;
					}
					.testimonial-quote__mark {
					  color: #c9ccd3;
					}
					.testimonial-quote__text {
					  margin: 32px 0 40px;
					}
					.testimonial-quote__text p {
					  margin: 0;
					  font-size: clamp(24px, 2.8vw, 32px);
					  font-weight: 500;
					  line-height: 1.4;
					  letter-spacing: -0.02em;
					  text-wrap: balance;
					}
					.testimonial-quote__person {
					  display: inline-flex;
					  align-items: center;
					  gap: 14px;
					  text-align: left;
					}
					.testimonial-quote__person img {
					  width: 56px;
					  height: 56px;
					  border-radius: 50%;
					  object-fit: cover;
					}
					.testimonial-quote__person span {
					  display: flex;
					  flex-direction: column;
					  gap: 2px;
					  color: var(--muted);
					  font-size: 15px;
					}
					.testimonial-quote__person strong {
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 600;
					}
					@media (max-width: 767px) {
					  .testimonial-quote {
					    padding: 72px 24px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'reviews' => array(
					'name'     => __( 'Testimonial cards', 'uichemy' ),
					'category' => __( 'Testimonials', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="testimonial-cards">
					  <div class="testimonial-cards__inner">
					    <header class="testimonial-cards__head">
					      <h2 class="testimonial-cards__title">Loved by the teams who use it every day</h2>
					      <p class="testimonial-cards__lead">Rated 4.9 out of 5 from more than 1,200 reviews.</p>
					    </header>
					    <div class="testimonial-cards__grid">
					      <figure class="testimonial-cards__card">
					        <span class="testimonial-cards__stars" aria-label="5 out of 5">★★★★★</span>
					        <blockquote>“Our clients actually read the weekly updates now. That alone has cut our meeting time in half.”</blockquote>
					        <figcaption>
					          <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=96&h=96&q=80&auto=format&fit=crop" alt="Hannah Okafor">
					          <span><strong>Hannah Okafor</strong>Founder, Lumen Design</span>
					        </figcaption>
					      </figure>
					      <figure class="testimonial-cards__card">
					        <span class="testimonial-cards__stars" aria-label="5 out of 5">★★★★★</span>
					        <blockquote>“We moved twelve people over in one afternoon. Nobody asked for the old tool back, which says it all.”</blockquote>
					        <figcaption>
					          <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=96&h=96&q=80&auto=format&fit=crop" alt="Daniel Reyes">
					          <span><strong>Daniel Reyes</strong>Head of Delivery, Arcadia</span>
					        </figcaption>
					      </figure>
					      <figure class="testimonial-cards__card">
					        <span class="testimonial-cards__stars" aria-label="5 out of 5">★★★★★</span>
					        <blockquote>“Approvals used to sit in inboxes for days. Now they come back the same afternoon, with a paper trail.”</blockquote>
					        <figcaption>
					          <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=96&h=96&q=80&auto=format&fit=crop" alt="Sofia Marino">
					          <span><strong>Sofia Marino</strong>Account Lead, Kettle &amp; Co</span>
					        </figcaption>
					      </figure>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.testimonial-cards {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: var(--soft);
					}
					.testimonial-cards *,
					.testimonial-cards *::before,
					.testimonial-cards *::after {
					  box-sizing: border-box;
					}
					.testimonial-cards h1,
					.testimonial-cards h2,
					.testimonial-cards h3,
					.testimonial-cards p,
					.testimonial-cards a,
					.testimonial-cards button,
					.testimonial-cards input,
					.testimonial-cards textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.testimonial-cards h1,
					.testimonial-cards h2,
					.testimonial-cards h3 {
					  color: inherit;
					}
					.testimonial-cards__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.testimonial-cards__head {
					  max-width: 640px;
					  margin: 0 auto 56px;
					  text-align: center;
					}
					.testimonial-cards__title {
					  margin: 0 0 12px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					  text-wrap: balance;
					}
					.testimonial-cards__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					}
					.testimonial-cards__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 24px;
					}
					.testimonial-cards__card {
					  display: flex;
					  flex-direction: column;
					  margin: 0;
					  padding: 32px;
					  border: 1px solid var(--line);
					  border-radius: 14px;
					  background: #ffffff;
					}
					.testimonial-cards__stars {
					  color: #f2a91d;
					  font-size: 15px;
					  letter-spacing: 2px;
					}
					.testimonial-cards__card blockquote {
					  flex: 1;
					  margin: 16px 0 28px;
					  color: var(--ink);
					  font-size: 17px;
					  line-height: 1.6;
					}
					.testimonial-cards__card figcaption {
					  display: flex;
					  align-items: center;
					  gap: 12px;
					}
					.testimonial-cards__card img {
					  width: 44px;
					  height: 44px;
					  border-radius: 50%;
					  object-fit: cover;
					}
					.testimonial-cards__card figcaption span {
					  display: flex;
					  flex-direction: column;
					  color: var(--muted);
					  font-size: 14px;
					}
					.testimonial-cards__card strong {
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 600;
					}
					@media (max-width: 1024px) {
					  .testimonial-cards__grid {
					    grid-template-columns: 1fr;
					    max-width: 560px;
					    margin: 0 auto;
					  }
					}
					@media (max-width: 767px) {
					  .testimonial-cards {
					    padding: 72px 24px;
					  }
					  .testimonial-cards__head {
					    margin-bottom: 40px;
					  }
					  .testimonial-cards__card {
					    padding: 24px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'pricing' => array(
					'name'     => __( 'Pricing with yearly switch', 'uichemy' ),
					'category' => __( 'Pricing', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="pricing-table" data-uich-lib="pricing">
					  <div class="pricing-table__inner">
					    <header class="pricing-table__head">
					      <h2 class="pricing-table__title">Plans that grow with your team</h2>
					      <p class="pricing-table__lead">Start free for 14 days. Change plans or cancel at any time.</p>
					      <div class="pricing-table__toggle" role="group" aria-label="Billing period">
					        <button type="button" class="pricing-table__period" data-period="monthly" aria-pressed="true">Monthly</button>
					        <button type="button" class="pricing-table__period" data-period="yearly" aria-pressed="false">Yearly <span class="pricing-table__save">Save 20%</span></button>
					      </div>
					    </header>
					    <div class="pricing-table__grid">
					      <article class="pricing-table__card">
					        <h3 class="pricing-table__name">Starter</h3>
					        <p class="pricing-table__desc">For freelancers and very small teams.</p>
					        <p class="pricing-table__price"><span data-monthly="$19" data-yearly="$15">$19</span><small>per user / month</small></p>
					        <a class="pricing-table__btn" href="#">Start free trial</a>
					        <ul class="pricing-table__features">
					          <li>Up to 5 active projects</li>
					          <li>Client portals</li>
					          <li>Time tracking</li>
					          <li>Email support</li>
					        </ul>
					      </article>
					      <article class="pricing-table__card pricing-table__card--featured">
					        <h3 class="pricing-table__name">Team <span class="pricing-table__badge">Most popular</span></h3>
					        <p class="pricing-table__desc">For agencies running several clients at once.</p>
					        <p class="pricing-table__price"><span data-monthly="$39" data-yearly="$31">$39</span><small>per user / month</small></p>
					        <a class="pricing-table__btn" href="#">Start free trial</a>
					        <ul class="pricing-table__features">
					          <li>Unlimited projects</li>
					          <li>Approvals and sign-off</li>
					          <li>Invoicing and reports</li>
					          <li>Slack, Drive and Figma</li>
					          <li>Priority support</li>
					        </ul>
					      </article>
					      <article class="pricing-table__card">
					        <h3 class="pricing-table__name">Business</h3>
					        <p class="pricing-table__desc">For larger studios with extra security needs.</p>
					        <p class="pricing-table__price"><span data-monthly="$69" data-yearly="$55">$69</span><small>per user / month</small></p>
					        <a class="pricing-table__btn" href="#">Talk to sales</a>
					        <ul class="pricing-table__features">
					          <li>Everything in Team</li>
					          <li>Single sign-on (SSO)</li>
					          <li>Custom roles</li>
					          <li>Dedicated success manager</li>
					        </ul>
					      </article>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.pricing-table {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.pricing-table *,
					.pricing-table *::before,
					.pricing-table *::after {
					  box-sizing: border-box;
					}
					.pricing-table h1,
					.pricing-table h2,
					.pricing-table h3,
					.pricing-table p,
					.pricing-table a,
					.pricing-table button,
					.pricing-table input,
					.pricing-table textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.pricing-table h1,
					.pricing-table h2,
					.pricing-table h3 {
					  color: inherit;
					}
					.pricing-table__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.pricing-table__head {
					  max-width: 640px;
					  margin: 0 auto 56px;
					  text-align: center;
					}
					.pricing-table__title {
					  margin: 0 0 12px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.pricing-table__lead {
					  margin: 0 0 32px;
					  color: var(--text);
					  font-size: 18px;
					}
					.pricing-table__toggle {
					  display: inline-flex;
					  padding: 4px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  background: var(--soft);
					}
					.pricing-table__period {
					  display: inline-flex;
					  align-items: center;
					  gap: 8px;
					  height: 36px;
					  padding: 0 16px;
					  border: 0;
					  border-radius: 7px;
					  background: transparent;
					  color: var(--text);
					  font: inherit;
					  font-size: 14px;
					  font-weight: 500;
					  cursor: pointer;
					}
					.pricing-table__period[aria-pressed="true"] {
					  background: #ffffff;
					  color: var(--ink);
					  box-shadow: 0 1px 2px rgba(14, 17, 22, 0.08), 0 0 0 1px var(--line);
					}
					.pricing-table__save {
					  color: #15803d;
					  font-size: 13px;
					}
					.pricing-table__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 24px;
					  align-items: start;
					}
					.pricing-table__card {
					  padding: 32px;
					  border: 1px solid var(--line);
					  border-radius: 16px;
					  background: #ffffff;
					}
					.pricing-table__card--featured {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.pricing-table__name {
					  display: flex;
					  align-items: center;
					  justify-content: space-between;
					  min-height: 26px;
					  margin: 0;
					  font-size: 18px;
					  font-weight: 600;
					}
					.pricing-table__badge {
					  padding: 4px 10px;
					  border-radius: 999px;
					  background: rgba(255, 255, 255, 0.12);
					  font-size: 12px;
					  font-weight: 500;
					}
					.pricing-table__desc {
					  margin: 8px 0 0;
					  color: var(--text);
					  font-size: 15px;
					  line-height: 1.5;
					}
					.pricing-table__card--featured .pricing-table__desc {
					  color: #b4b9c3;
					}
					.pricing-table__price {
					  display: flex;
					  align-items: baseline;
					  gap: 8px;
					  margin: 28px 0;
					}
					.pricing-table__price span {
					  font-size: 48px;
					  font-weight: 600;
					  line-height: 1;
					  letter-spacing: -0.04em;
					  font-variant-numeric: tabular-nums;
					}
					.pricing-table__price small {
					  color: var(--muted);
					  font-size: 14px;
					}
					.pricing-table__card--featured .pricing-table__price small {
					  color: #b4b9c3;
					}
					.pricing-table__btn {
					  display: flex;
					  align-items: center;
					  justify-content: center;
					  height: 44px;
					  border: 1px solid var(--line);
					  border-radius: 8px;
					  background: #ffffff;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background 0.15s ease;
					}
					.pricing-table__btn:hover {
					  border-color: #c9ccd3;
					}
					.pricing-table__card--featured .pricing-table__btn {
					  border-color: #ffffff;
					}
					.pricing-table__card--featured .pricing-table__btn:hover {
					  background: #eceef1;
					}
					.pricing-table__features {
					  display: grid;
					  gap: 14px;
					  margin: 28px 0 0;
					  padding: 28px 0 0;
					  border-top: 1px solid var(--line);
					  list-style: none;
					}
					.pricing-table__card--featured .pricing-table__features {
					  border-top-color: rgba(255, 255, 255, 0.14);
					}
					.pricing-table__features li {
					  position: relative;
					  padding-left: 28px;
					  font-size: 15px;
					  line-height: 1.5;
					}
					/* The tick is drawn in CSS so editing a line never means touching an icon. */
					.pricing-table__features li::before {
					  content: "";
					  position: absolute;
					  top: 5px;
					  left: 2px;
					  width: 11px;
					  height: 6px;
					  border-left: 2px solid var(--accent);
					  border-bottom: 2px solid var(--accent);
					  transform: rotate(-45deg);
					}
					.pricing-table__card--featured .pricing-table__features li::before {
					  border-color: #ffffff;
					}
					@media (max-width: 1024px) {
					  .pricing-table__grid {
					    grid-template-columns: 1fr;
					    max-width: 480px;
					    margin: 0 auto;
					  }
					}
					@media (max-width: 767px) {
					  .pricing-table {
					    padding: 72px 24px;
					  }
					  .pricing-table__card {
					    padding: 24px;
					  }
					}
					CSS
					,
					'js'       => <<<'JS'
					/* Monthly / yearly switch. Each price carries both values; the toggle picks one. */
					(function () {
					  document.querySelectorAll('[data-uich-lib="pricing"]:not([data-uich-ready])').forEach(function (root) {
					    root.setAttribute('data-uich-ready', '');
					    var buttons = root.querySelectorAll('.pricing-table__period');

					    buttons.forEach(function (btn) {
					      btn.addEventListener('click', function () {
					        var period = btn.getAttribute('data-period');
					        buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
					        root.querySelectorAll('[data-' + period + ']').forEach(function (price) {
					          price.textContent = price.getAttribute('data-' + period);
					        });
					      });
					    });
					  });
					})();
					JS
					,
				),
				'team' => array(
					'name'     => __( 'Team grid', 'uichemy' ),
					'category' => __( 'Team', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="team-grid">
					  <div class="team-grid__inner">
					    <header class="team-grid__head">
					      <h2 class="team-grid__title">The people behind the work</h2>
					      <p class="team-grid__lead">A small, senior team that has run agencies of its own. We build the tool we always wanted.</p>
					    </header>
					    <ul class="team-grid__grid">
					      <li class="team-grid__person">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=600&h=720&q=80&auto=format&fit=crop" alt="Claire Whitman">
					        <h3>Claire Whitman</h3>
					        <p>Co-founder &amp; CEO</p>
					      </li>
					      <li class="team-grid__person">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=600&h=720&q=80&auto=format&fit=crop" alt="Marco Diaz">
					        <h3>Marco Diaz</h3>
					        <p>Co-founder &amp; CTO</p>
					      </li>
					      <li class="team-grid__person">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=600&h=720&q=80&auto=format&fit=crop" alt="Emily Carter">
					        <h3>Emily Carter</h3>
					        <p>Head of Design</p>
					      </li>
					      <li class="team-grid__person">
					        <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=600&h=720&q=80&auto=format&fit=crop" alt="James Park">
					        <h3>James Park</h3>
					        <p>Head of Customer Success</p>
					      </li>
					    </ul>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.team-grid {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.team-grid *,
					.team-grid *::before,
					.team-grid *::after {
					  box-sizing: border-box;
					}
					.team-grid h1,
					.team-grid h2,
					.team-grid h3,
					.team-grid p,
					.team-grid a,
					.team-grid button,
					.team-grid input,
					.team-grid textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.team-grid h1,
					.team-grid h2,
					.team-grid h3 {
					  color: inherit;
					}
					.team-grid__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.team-grid__head {
					  max-width: 600px;
					  margin-bottom: 56px;
					}
					.team-grid__title {
					  margin: 0 0 12px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.team-grid__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.team-grid__grid {
					  display: grid;
					  grid-template-columns: repeat(4, minmax(0, 1fr));
					  gap: 32px 24px;
					  margin: 0;
					  padding: 0;
					  list-style: none;
					}
					.team-grid__person img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 5 / 6;
					  margin-bottom: 20px;
					  border-radius: 14px;
					  background: var(--soft);
					  object-fit: cover;
					}
					.team-grid__person h3 {
					  margin: 0 0 4px;
					  font-size: 17px;
					  font-weight: 600;
					}
					.team-grid__person p {
					  margin: 0;
					  color: var(--muted);
					  font-size: 15px;
					}
					@media (max-width: 1024px) {
					  .team-grid__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .team-grid {
					    padding: 72px 24px;
					  }
					  .team-grid__head {
					    margin-bottom: 40px;
					  }
					  .team-grid__grid {
					    gap: 28px 16px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'faq' => array(
					'name'     => __( 'FAQ accordion', 'uichemy' ),
					'category' => __( 'FAQ', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="faq-accordion" data-uich-lib="faq">
					  <div class="faq-accordion__inner">
					    <div class="faq-accordion__intro">
					      <h2 class="faq-accordion__title">Questions, answered</h2>
					      <p class="faq-accordion__text">Can’t find what you are looking for? Our support team replies within a few hours, seven days a week.</p>
					      <a class="faq-accordion__link" href="#">Contact support</a>
					    </div>
					    <div class="faq-accordion__list">
					      <div class="faq-accordion__item" data-open>
					        <button type="button" class="faq-accordion__q" aria-expanded="true">
					          How does the free trial work?
					          <span class="faq-accordion__icon" aria-hidden="true"></span>
					        </button>
					        <div class="faq-accordion__a">
					          <p>You get every feature of the Team plan for 14 days. We don’t ask for a card up front, and nothing is charged unless you choose a plan.</p>
					        </div>
					      </div>
					      <div class="faq-accordion__item">
					        <button type="button" class="faq-accordion__q" aria-expanded="false">
					          Do my clients need to pay for an account?
					          <span class="faq-accordion__icon" aria-hidden="true"></span>
					        </button>
					        <div class="faq-accordion__a" hidden>
					          <p>No. Clients join for free on every plan. You only pay for the people on your own team.</p>
					        </div>
					      </div>
					      <div class="faq-accordion__item">
					        <button type="button" class="faq-accordion__q" aria-expanded="false">
					          Can I import projects from another tool?
					          <span class="faq-accordion__icon" aria-hidden="true"></span>
					        </button>
					        <div class="faq-accordion__a" hidden>
					          <p>Yes. We import from Asana, Trello, Monday and any CSV file. Tasks, dates, owners and attachments all come across.</p>
					        </div>
					      </div>
					      <div class="faq-accordion__item">
					        <button type="button" class="faq-accordion__q" aria-expanded="false">
					          Is my data secure?
					          <span class="faq-accordion__icon" aria-hidden="true"></span>
					        </button>
					        <div class="faq-accordion__a" hidden>
					          <p>Data is encrypted in transit and at rest, backed up daily and hosted in the EU or the US, whichever you choose. Business plans add SSO and audit logs.</p>
					        </div>
					      </div>
					      <div class="faq-accordion__item">
					        <button type="button" class="faq-accordion__q" aria-expanded="false">
					          What happens if I cancel?
					          <span class="faq-accordion__icon" aria-hidden="true"></span>
					        </button>
					        <div class="faq-accordion__a" hidden>
					          <p>Your account stays read-only for 90 days so you can export everything. After that, your data is permanently deleted.</p>
					        </div>
					      </div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.faq-accordion {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.faq-accordion *,
					.faq-accordion *::before,
					.faq-accordion *::after {
					  box-sizing: border-box;
					}
					.faq-accordion h1,
					.faq-accordion h2,
					.faq-accordion h3,
					.faq-accordion p,
					.faq-accordion a,
					.faq-accordion button,
					.faq-accordion input,
					.faq-accordion textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.faq-accordion h1,
					.faq-accordion h2,
					.faq-accordion h3 {
					  color: inherit;
					}
					.faq-accordion__inner {
					  display: grid;
					  grid-template-columns: 1fr 1.6fr;
					  gap: 80px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.faq-accordion__title {
					  margin: 0 0 16px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.faq-accordion__text {
					  margin: 0 0 24px;
					  color: var(--text);
					  font-size: 17px;
					  line-height: 1.6;
					}
					.faq-accordion__link {
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 600;
					  text-decoration: underline;
					  text-decoration-color: var(--line);
					  text-decoration-thickness: 2px;
					  text-underline-offset: 5px;
					  transition: text-decoration-color 0.15s ease;
					}
					.faq-accordion__link:hover {
					  text-decoration-color: var(--ink);
					}
					.faq-accordion__list {
					  border-top: 1px solid var(--line);
					}
					.faq-accordion__item {
					  border-bottom: 1px solid var(--line);
					}
					.faq-accordion__q {
					  display: flex;
					  align-items: center;
					  justify-content: space-between;
					  gap: 24px;
					  width: 100%;
					  padding: 24px 0;
					  border: 0;
					  background: none;
					  color: var(--ink);
					  font: inherit;
					  font-size: 18px;
					  font-weight: 500;
					  text-align: left;
					  cursor: pointer;
					}
					.faq-accordion__icon {
					  position: relative;
					  flex: none;
					  width: 14px;
					  height: 14px;
					}
					.faq-accordion__icon::before,
					.faq-accordion__icon::after {
					  content: "";
					  position: absolute;
					  top: 6px;
					  left: 0;
					  width: 14px;
					  height: 2px;
					  border-radius: 1px;
					  background: var(--ink);
					  transition: transform 0.2s ease;
					}
					.faq-accordion__icon::after {
					  transform: rotate(90deg);
					}
					.faq-accordion__item[data-open] .faq-accordion__icon::after {
					  transform: rotate(0deg);
					}
					.faq-accordion__a {
					  padding: 0 48px 24px 0;
					}
					.faq-accordion__a p {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.7;
					}
					@media (max-width: 1024px) {
					  .faq-accordion__inner {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					}
					@media (max-width: 767px) {
					  .faq-accordion {
					    padding: 72px 24px;
					  }
					  .faq-accordion__q {
					    padding: 20px 0;
					    font-size: 17px;
					  }
					  .faq-accordion__a {
					    padding-right: 0;
					  }
					}
					CSS
					,
					'js'       => <<<'JS'
					/* One answer open at a time. Opening a question closes the one before it. */
					(function () {
					  document.querySelectorAll('[data-uich-lib="faq"]:not([data-uich-ready])').forEach(function (root) {
					    root.setAttribute('data-uich-ready', '');
					    var items = Array.prototype.slice.call(root.querySelectorAll('.faq-accordion__item'));

					    function set(item, open) {
					      var q = item.querySelector('.faq-accordion__q');
					      var a = item.querySelector('.faq-accordion__a');
					      if (open) item.setAttribute('data-open', ''); else item.removeAttribute('data-open');
					      if (q) q.setAttribute('aria-expanded', open ? 'true' : 'false');
					      if (a) a.hidden = !open;
					    }

					    items.forEach(function (item) {
					      var q = item.querySelector('.faq-accordion__q');
					      if (!q) return;
					      q.addEventListener('click', function () {
					        var opening = !item.hasAttribute('data-open');
					        items.forEach(function (other) { set(other, false); });
					        if (opening) set(item, true);
					      });
					    });
					  });
					})();
					JS
					,
				),
				'posts' => array(
					'name'     => __( 'Latest blog posts', 'uichemy' ),
					'category' => __( 'Blog', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="blog-grid">
					  <div class="blog-grid__inner">
					    <header class="blog-grid__head">
					      <div>
					        <h2 class="blog-grid__title">From the blog</h2>
					        <p class="blog-grid__lead">Practical advice on running a calmer, more profitable studio.</p>
					      </div>
					      <a class="blog-grid__all" href="#">
					        View all posts
					        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					      </a>
					    </header>
					    <div class="blog-grid__grid">
					      <article class="blog-grid__card">
					        <a class="blog-grid__image" href="#" aria-hidden="true" tabindex="-1"><img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&h=500&q=80&auto=format&fit=crop" alt=""></a>
					        <p class="blog-grid__meta"><span>Guides</span> · 6 min read</p>
					        <h3><a href="#">How to write a project brief clients actually sign</a></h3>
					        <p class="blog-grid__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					      </article>
					      <article class="blog-grid__card">
					        <a class="blog-grid__image" href="#" aria-hidden="true" tabindex="-1"><img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=800&h=500&q=80&auto=format&fit=crop" alt=""></a>
					        <p class="blog-grid__meta"><span>Operations</span> · 4 min read</p>
					        <h3><a href="#">The weekly client update, in five lines</a></h3>
					        <p class="blog-grid__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					      </article>
					      <article class="blog-grid__card">
					        <a class="blog-grid__image" href="#" aria-hidden="true" tabindex="-1"><img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&h=500&q=80&auto=format&fit=crop" alt=""></a>
					        <p class="blog-grid__meta"><span>Pricing</span> · 8 min read</p>
					        <h3><a href="#">Moving from hourly billing to fixed-price projects</a></h3>
					        <p class="blog-grid__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					      </article>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.blog-grid {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.blog-grid *,
					.blog-grid *::before,
					.blog-grid *::after {
					  box-sizing: border-box;
					}
					.blog-grid h1,
					.blog-grid h2,
					.blog-grid h3,
					.blog-grid p,
					.blog-grid a,
					.blog-grid button,
					.blog-grid input,
					.blog-grid textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.blog-grid h1,
					.blog-grid h2,
					.blog-grid h3 {
					  color: inherit;
					}
					.blog-grid__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.blog-grid__head {
					  display: flex;
					  align-items: flex-end;
					  justify-content: space-between;
					  gap: 24px;
					  margin-bottom: 48px;
					}
					.blog-grid__title {
					  margin: 0 0 12px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.blog-grid__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					}
					.blog-grid__all {
					  display: inline-flex;
					  flex: none;
					  align-items: center;
					  gap: 8px;
					  height: 44px;
					  padding: 0 18px;
					  border: 1px solid var(--line);
					  border-radius: 8px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease;
					}
					.blog-grid__all:hover {
					  border-color: #c9ccd3;
					}
					.blog-grid__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 40px 32px;
					}
					.blog-grid__image {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					}
					.blog-grid__image img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 16 / 10;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.blog-grid__card:hover .blog-grid__image img {
					  transform: scale(1.03);
					}
					.blog-grid__meta {
					  margin: 20px 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.blog-grid__meta span {
					  color: var(--accent);
					  font-weight: 600;
					}
					.blog-grid__card h3 {
					  margin: 0 0 10px;
					  font-size: 20px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.015em;
					}
					.blog-grid__card h3 a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.blog-grid__card h3 a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.blog-grid__excerpt {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					@media (max-width: 1024px) {
					  .blog-grid__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					  .blog-grid__card:last-child {
					    display: none;
					  }
					}
					@media (max-width: 767px) {
					  .blog-grid {
					    padding: 72px 24px;
					  }
					  .blog-grid__head {
					    flex-direction: column;
					    align-items: flex-start;
					    margin-bottom: 40px;
					  }
					  .blog-grid__grid {
					    grid-template-columns: 1fr;
					  }
					  .blog-grid__card:last-child {
					    display: block;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'cta' => array(
					'name'     => __( 'Call to action', 'uichemy' ),
					'category' => __( 'Call to action', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="cta-panel">
					  <div class="cta-panel__panel">
					    <div class="cta-panel__copy">
					      <h2 class="cta-panel__title">Ready to give your clients a better experience?</h2>
					      <p class="cta-panel__text">Try Northwind free for 14 days. Set up takes minutes, and you can bring your whole team.</p>
					    </div>
					    <div class="cta-panel__actions">
					      <a class="cta-panel__btn cta-panel__btn--primary" href="#">Start free trial</a>
					      <a class="cta-panel__btn cta-panel__btn--secondary" href="#">Talk to sales</a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.cta-panel {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px;
					  background: #ffffff;
					}
					.cta-panel *,
					.cta-panel *::before,
					.cta-panel *::after {
					  box-sizing: border-box;
					}
					.cta-panel h1,
					.cta-panel h2,
					.cta-panel h3,
					.cta-panel p,
					.cta-panel a,
					.cta-panel button,
					.cta-panel input,
					.cta-panel textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.cta-panel h1,
					.cta-panel h2,
					.cta-panel h3 {
					  color: inherit;
					}
					.cta-panel__panel {
					  display: flex;
					  align-items: center;
					  justify-content: space-between;
					  gap: 48px;
					  max-width: 1200px;
					  margin: 0 auto;
					  padding: 64px;
					  border-radius: 20px;
					  background: var(--ink);
					  color: #ffffff;
					}
					.cta-panel__copy {
					  max-width: 620px;
					}
					.cta-panel__title {
					  margin: 0 0 14px;
					  font-size: clamp(28px, 3.2vw, 40px);
					  font-weight: 600;
					  line-height: 1.15;
					  letter-spacing: -0.03em;
					  text-wrap: balance;
					}
					.cta-panel__text {
					  margin: 0;
					  color: #b4b9c3;
					  font-size: 18px;
					  line-height: 1.6;
					}
					.cta-panel__actions {
					  display: flex;
					  flex: none;
					  flex-wrap: wrap;
					  gap: 12px;
					}
					.cta-panel__btn {
					  display: inline-flex;
					  align-items: center;
					  height: 48px;
					  padding: 0 24px;
					  border-radius: 8px;
					  font-size: 16px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: background 0.15s ease, border-color 0.15s ease;
					}
					.cta-panel__btn--primary {
					  background: #ffffff;
					  color: var(--ink);
					}
					.cta-panel__btn--primary:hover {
					  background: #eceef1;
					}
					.cta-panel__btn--secondary {
					  border: 1px solid rgba(255, 255, 255, 0.24);
					  color: #ffffff;
					}
					.cta-panel__btn--secondary:hover {
					  border-color: rgba(255, 255, 255, 0.5);
					}
					@media (max-width: 1024px) {
					  .cta-panel__panel {
					    flex-direction: column;
					    align-items: flex-start;
					    padding: 48px;
					  }
					}
					@media (max-width: 767px) {
					  .cta-panel {
					    padding: 64px 16px;
					  }
					  .cta-panel__panel {
					    gap: 32px;
					    padding: 40px 24px;
					    border-radius: 16px;
					  }
					  .cta-panel__actions {
					    width: 100%;
					  }
					  .cta-panel__btn {
					    flex: 1 1 100%;
					    justify-content: center;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'newsletter' => array(
					'name'     => __( 'Newsletter signup', 'uichemy' ),
					'category' => __( 'Call to action', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="newsletter-signup">
					  <div class="newsletter-signup__inner">
					    <div class="newsletter-signup__copy">
					      <h2 class="newsletter-signup__title">Get one useful email a month</h2>
					      <p class="newsletter-signup__text">Templates, pricing advice and lessons from the agencies we work with. No spam, unsubscribe any time.</p>
					    </div>
					    <form class="newsletter-signup__form" data-atom-form="newsletter">
					      <label class="newsletter-signup__label" for="newsletter-email">Email address</label>
					      <div class="newsletter-signup__row">
					        <input id="newsletter-email" type="email" name="email" placeholder="you@company.com" autocomplete="email" required>
					        <button type="submit">Subscribe</button>
					      </div>
					      <p class="newsletter-signup__note">Join 18,000 studio owners. Read our <a href="#">privacy policy</a>.</p>
					    </form>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.newsletter-signup {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 80px 24px;
					  border-top: 1px solid var(--line);
					  border-bottom: 1px solid var(--line);
					  background: var(--soft);
					}
					.newsletter-signup *,
					.newsletter-signup *::before,
					.newsletter-signup *::after {
					  box-sizing: border-box;
					}
					.newsletter-signup h1,
					.newsletter-signup h2,
					.newsletter-signup h3,
					.newsletter-signup p,
					.newsletter-signup a,
					.newsletter-signup button,
					.newsletter-signup input,
					.newsletter-signup textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.newsletter-signup h1,
					.newsletter-signup h2,
					.newsletter-signup h3 {
					  color: inherit;
					}
					.newsletter-signup__inner {
					  display: grid;
					  grid-template-columns: 1fr 1fr;
					  align-items: center;
					  gap: 64px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.newsletter-signup__title {
					  margin: 0 0 10px;
					  font-size: clamp(26px, 2.8vw, 34px);
					  font-weight: 600;
					  line-height: 1.2;
					  letter-spacing: -0.025em;
					}
					.newsletter-signup__text {
					  margin: 0;
					  color: var(--text);
					  font-size: 17px;
					  line-height: 1.6;
					}
					.newsletter-signup__label {
					  position: absolute;
					  width: 1px;
					  height: 1px;
					  overflow: hidden;
					  clip: rect(0 0 0 0);
					  white-space: nowrap;
					}
					.newsletter-signup__row {
					  display: flex;
					  gap: 10px;
					}
					.newsletter-signup__row input {
					  flex: 1;
					  min-width: 0;
					  height: 48px;
					  padding: 0 16px;
					  border: 1px solid var(--line);
					  border-radius: 8px;
					  background: #ffffff;
					  color: var(--ink);
					  font: inherit;
					  font-size: 16px;
					  outline: none;
					  transition: border-color 0.15s ease, box-shadow 0.15s ease;
					}
					.newsletter-signup__row input:focus {
					  border-color: var(--accent);
					  box-shadow: 0 0 0 3px rgba(36, 84, 230, 0.15);
					}
					.newsletter-signup__row button {
					  height: 48px;
					  padding: 0 22px;
					  border: 0;
					  border-radius: 8px;
					  background: var(--ink);
					  color: #ffffff;
					  font: inherit;
					  font-size: 16px;
					  font-weight: 500;
					  cursor: pointer;
					  transition: background 0.15s ease;
					}
					.newsletter-signup__row button:hover {
					  background: #2a2f38;
					}
					.newsletter-signup__note {
					  margin: 12px 0 0;
					  color: var(--muted);
					  font-size: 14px;
					}
					.newsletter-signup__note a {
					  color: var(--ink);
					}
					@media (max-width: 1024px) {
					  .newsletter-signup__inner {
					    grid-template-columns: 1fr;
					    gap: 28px;
					  }
					}
					@media (max-width: 767px) {
					  .newsletter-signup {
					    padding: 64px 24px;
					  }
					  .newsletter-signup__row {
					    flex-direction: column;
					  }
					  .newsletter-signup__row input {
					    flex: none;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'contact' => array(
					'name'     => __( 'Contact form', 'uichemy' ),
					'category' => __( 'Contact', 'uichemy' ),
					'html'     => <<<'HTML'
					<section class="contact-split">
					  <div class="contact-split__inner">
					    <div class="contact-split__info">
					      <h2 class="contact-split__title">Talk to our team</h2>
					      <p class="contact-split__text">Questions about plans, a demo for your team, or help moving from another tool? We’re happy to help. We reply within one working day.</p>
					      <ul class="contact-split__details">
					        <li>
					          <span class="contact-split__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg></span>
					          <span><strong>Email</strong><a href="mailto:hello@example.com">hello@example.com</a></span>
					        </li>
					        <li>
					          <span class="contact-split__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
					          <span><strong>Phone</strong><a href="tel:+15550142020">+1 (555) 014-2020</a></span>
					        </li>
					        <li>
					          <span class="contact-split__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
					          <span><strong>Office</strong>120 Market Street, San Francisco, CA</span>
					        </li>
					      </ul>
					    </div>
					    <form class="contact-split__form" data-atom-form="contact">
					      <div class="contact-split__row">
					        <label>
					          <span>First name</span>
					          <input type="text" name="first_name" autocomplete="given-name" required>
					        </label>
					        <label>
					          <span>Last name</span>
					          <input type="text" name="last_name" autocomplete="family-name" required>
					        </label>
					      </div>
					      <label>
					        <span>Work email</span>
					        <input type="email" name="email" autocomplete="email" required>
					      </label>
					      <label>
					        <span>Company</span>
					        <input type="text" name="company" autocomplete="organization">
					      </label>
					      <label>
					        <span>How can we help?</span>
					        <textarea name="message" rows="5" required></textarea>
					      </label>
					      <button type="submit">Send message</button>
					    </form>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.contact-split {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.contact-split *,
					.contact-split *::before,
					.contact-split *::after {
					  box-sizing: border-box;
					}
					.contact-split h1,
					.contact-split h2,
					.contact-split h3,
					.contact-split p,
					.contact-split a,
					.contact-split button,
					.contact-split input,
					.contact-split textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.contact-split h1,
					.contact-split h2,
					.contact-split h3 {
					  color: inherit;
					}
					.contact-split__inner {
					  display: grid;
					  grid-template-columns: 1fr 1.15fr;
					  gap: 80px;
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.contact-split__title {
					  margin: 0 0 16px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.contact-split__text {
					  margin: 0;
					  color: var(--text);
					  font-size: 17px;
					  line-height: 1.6;
					}
					.contact-split__details {
					  display: grid;
					  gap: 24px;
					  margin: 40px 0 0;
					  padding: 0;
					  list-style: none;
					}
					.contact-split__details li {
					  display: flex;
					  gap: 16px;
					}
					.contact-split__icon {
					  display: inline-flex;
					  flex: none;
					  align-items: center;
					  justify-content: center;
					  width: 40px;
					  height: 40px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					}
					.contact-split__details li > span:last-child {
					  display: flex;
					  flex-direction: column;
					  gap: 2px;
					  color: var(--text);
					  font-size: 15px;
					}
					.contact-split__details strong {
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 600;
					}
					.contact-split__details a {
					  color: var(--text);
					  text-decoration: none;
					}
					.contact-split__details a:hover {
					  color: var(--ink);
					}
					.contact-split__form {
					  display: grid;
					  gap: 20px;
					  padding: 40px;
					  border: 1px solid var(--line);
					  border-radius: 16px;
					  background: var(--soft);
					}
					.contact-split__row {
					  display: grid;
					  grid-template-columns: 1fr 1fr;
					  gap: 16px;
					}
					.contact-split__form label {
					  display: grid;
					  gap: 8px;
					}
					.contact-split__form label > span {
					  color: var(--ink);
					  font-size: 14px;
					  font-weight: 500;
					}
					.contact-split__form input,
					.contact-split__form textarea {
					  width: 100%;
					  padding: 12px 14px;
					  border: 1px solid var(--line);
					  border-radius: 8px;
					  background: #ffffff;
					  color: var(--ink);
					  font: inherit;
					  font-size: 16px;
					  outline: none;
					  transition: border-color 0.15s ease, box-shadow 0.15s ease;
					}
					.contact-split__form textarea {
					  resize: vertical;
					}
					.contact-split__form input:focus,
					.contact-split__form textarea:focus {
					  border-color: var(--accent);
					  box-shadow: 0 0 0 3px rgba(36, 84, 230, 0.15);
					}
					.contact-split__form button {
					  justify-self: start;
					  height: 48px;
					  padding: 0 28px;
					  border: 0;
					  border-radius: 8px;
					  background: var(--ink);
					  color: #ffffff;
					  font: inherit;
					  font-size: 16px;
					  font-weight: 500;
					  cursor: pointer;
					  transition: background 0.15s ease;
					}
					.contact-split__form button:hover {
					  background: #2a2f38;
					}
					@media (max-width: 1024px) {
					  .contact-split__inner {
					    grid-template-columns: 1fr;
					    gap: 48px;
					  }
					}
					@media (max-width: 767px) {
					  .contact-split {
					    padding: 72px 24px;
					  }
					  .contact-split__form {
					    padding: 24px;
					  }
					  .contact-split__row {
					    grid-template-columns: 1fr;
					  }
					  .contact-split__form button {
					    justify-self: stretch;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'footer' => array(
					'name'     => __( 'Footer with links', 'uichemy' ),
					'category' => __( 'Footer', 'uichemy' ),
					'slot'     => 'footer',
					'html'     => <<<'HTML'
					<footer class="site-footer">
					  <div class="site-footer__inner">
					    <div class="site-footer__top">
					      <div class="site-footer__brand">
					        <a class="site-footer__logo" href="#">
					          <svg class="site-footer__mark" viewBox="0 0 100 100" aria-hidden="true"><g fill="currentColor"><rect x="10" y="43" width="80" height="14" rx="3"/><rect x="43" y="10" width="14" height="42" rx="3"/><rect x="43" y="10" width="14" height="42" rx="3" transform="rotate(-45 50 50)"/><rect x="43" y="10" width="14" height="42" rx="3" transform="rotate(45 50 50)"/></g><g fill="none" stroke-linecap="round" stroke-linejoin="round"><g stroke="#ffffff" stroke-width="26"><path d="M27 72 50 49 73 72"/><path d="M50 52V88"/></g><g stroke="#2454e6" stroke-width="14"><path d="M27 72 50 49 73 72"/><path d="M50 52V88"/></g></g></svg>
					          Northwind
					        </a>
					        <p>Project management for agencies and studios that care about their clients.</p>
					      </div>
					      <nav class="site-footer__col" aria-label="Product">
					        <h3>Product</h3>
					        <a href="#">Features</a>
					        <a href="#">Pricing</a>
					        <a href="#">Integrations</a>
					        <a href="#">Changelog</a>
					      </nav>
					      <nav class="site-footer__col" aria-label="Company">
					        <h3>Company</h3>
					        <a href="#">About</a>
					        <a href="#">Customers</a>
					        <a href="#">Careers</a>
					        <a href="#">Contact</a>
					      </nav>
					      <nav class="site-footer__col" aria-label="Resources">
					        <h3>Resources</h3>
					        <a href="#">Blog</a>
					        <a href="#">Help centre</a>
					        <a href="#">Templates</a>
					        <a href="#">Status</a>
					      </nav>
					    </div>
					    <div class="site-footer__bottom">
					      <p>© 2026 Northwind Inc. All rights reserved.</p>
					      <div class="site-footer__legal">
					        <a href="#">Privacy</a>
					        <a href="#">Terms</a>
					        <a href="#">Cookies</a>
					      </div>
					    </div>
					  </div>
					</footer>
					HTML
					,
					'css'      => <<<'CSS'
					.site-footer {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 80px 24px 40px;
					  border-top: 1px solid var(--line);
					  background: #ffffff;
					}
					.site-footer *,
					.site-footer *::before,
					.site-footer *::after {
					  box-sizing: border-box;
					}
					.site-footer h1,
					.site-footer h2,
					.site-footer h3,
					.site-footer p,
					.site-footer a,
					.site-footer button,
					.site-footer input,
					.site-footer textarea {
					  font-family: inherit;
					  text-transform: none;
					}
					.site-footer h1,
					.site-footer h2,
					.site-footer h3 {
					  color: inherit;
					}
					.site-footer__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.site-footer__top {
					  display: grid;
					  grid-template-columns: 2fr 1fr 1fr 1fr;
					  gap: 48px;
					  padding-bottom: 64px;
					}
					.site-footer__logo {
					  display: inline-flex;
					  align-items: center;
					  gap: 10px;
					  color: var(--ink);
					  font-size: 18px;
					  font-weight: 650;
					  letter-spacing: -0.02em;
					  text-decoration: none;
					}
					.site-footer__mark {
					  flex: none;
					  display: block;
					  width: 28px;
					  height: 28px;
					}
					.site-footer__brand p {
					  max-width: 300px;
					  margin: 16px 0 0;
					  color: var(--text);
					  font-size: 15px;
					  line-height: 1.6;
					}
					.site-footer__col {
					  display: flex;
					  flex-direction: column;
					  gap: 12px;
					}
					.site-footer__col h3 {
					  margin: 0 0 4px;
					  color: var(--ink);
					  font-size: 14px;
					  font-weight: 600;
					}
					.site-footer__col a,
					.site-footer__legal a {
					  color: var(--text);
					  font-size: 15px;
					  text-decoration: none;
					  transition: color 0.15s ease;
					}
					.site-footer__col a:hover,
					.site-footer__legal a:hover {
					  color: var(--ink);
					}
					.site-footer__bottom {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: space-between;
					  gap: 16px;
					  padding-top: 32px;
					  border-top: 1px solid var(--line);
					}
					.site-footer__bottom p {
					  margin: 0;
					  color: var(--muted);
					  font-size: 14px;
					}
					.site-footer__legal {
					  display: flex;
					  gap: 24px;
					}
					.site-footer__legal a {
					  font-size: 14px;
					}
					@media (max-width: 1024px) {
					  .site-footer__top {
					    grid-template-columns: repeat(3, 1fr);
					  }
					  .site-footer__brand {
					    grid-column: 1 / -1;
					  }
					}
					@media (max-width: 767px) {
					  .site-footer {
					    padding: 64px 24px 32px;
					  }
					  .site-footer__top {
					    grid-template-columns: repeat(2, 1fr);
					    gap: 40px 24px;
					    padding-bottom: 48px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// Single (post / page) templates: the post's own title, content
				// and author, printed through {{ post.* }}.
				'single-hero' => array(
					'name'     => __( 'Post header', 'uichemy' ),
					'category' => __( 'Post', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<article class="post-hero">
					  <div class="post-hero__inner">
					    <p class="post-hero__meta">
					      <span>{{ post.date('F j, Y') }}</span>
					      <span class="post-hero__dot" aria-hidden="true"></span>
					      <span>{{ post.author.name }}</span>
					    </p>
					    <h1 class="post-hero__title">{{ post.title }}</h1>
					    {% if post.thumbnail.src %}
					    <figure class="post-hero__media">
					      <img src="{{ post.thumbnail.src }}" alt="{{ post.thumbnail.alt }}">
					    </figure>
					    {% endif %}
					  </div>
					</article>
					HTML
					,
					'preview_html' => <<<'HTML'
					<article class="post-hero">
					  <div class="post-hero__inner">
					    <p class="post-hero__meta">
					      <span>October 27, 2026</span>
					      <span class="post-hero__dot" aria-hidden="true"></span>
					      <span>Alex Morgan</span>
					    </p>
					    <h1 class="post-hero__title">How small teams ship big projects without the chaos</h1>
					    <figure class="post-hero__media">
					      <img loading="lazy" decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=1600&amp;q=80" alt="">
					    </figure>
					  </div>
					</article>
					HTML
					,
					'css'      => <<<'CSS'
					.post-hero {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 80px 24px 40px;
					  background: #ffffff;
					}
					.post-hero *,
					.post-hero *::before,
					.post-hero *::after {
					  box-sizing: border-box;
					}
					.post-hero h1,
					.post-hero h2,
					.post-hero h3,
					.post-hero p,
					.post-hero a {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-hero__inner {
					  max-width: 880px;
					  margin: 0 auto;
					  text-align: center;
					}
					.post-hero__meta {
					  display: inline-flex;
					  align-items: center;
					  gap: 10px;
					  margin: 0 0 20px;
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 500;
					}
					.post-hero__dot {
					  width: 4px;
					  height: 4px;
					  border-radius: 50%;
					  background: var(--line);
					}
					.post-hero__title {
					  margin: 0 auto;
					  max-width: 760px;
					  font-size: 48px;
					  font-weight: 700;
					  line-height: 1.12;
					  letter-spacing: -0.025em;
					}
					.post-hero__media {
					  margin: 48px 0 0;
					  overflow: hidden;
					  border-radius: 20px;
					  background: var(--soft);
					}
					.post-hero__media img {
					  display: block;
					  width: 100%;
					  aspect-ratio: 16 / 9;
					  object-fit: cover;
					}
					@media (max-width: 767px) {
					  .post-hero {
					    padding: 56px 20px 24px;
					  }
					  .post-hero__title {
					    font-size: 34px;
					  }
					  .post-hero__media {
					    margin-top: 32px;
					    border-radius: 14px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'single-content' => array(
					'name'     => __( 'Post content', 'uichemy' ),
					'category' => __( 'Post', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<section class="post-body">
					  <div class="post-body__inner">
					    {{ post.content|raw }}
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-body">
					  <div class="post-body__inner">
					    <p>Most projects do not fail on the big decisions. They fail in the small gaps between them: a file nobody sent, a comment that was never answered, an approval that sat in someone's inbox for a week.</p>
					    <h2>Start with one place for everything</h2>
					    <p>When the brief, the files and the feedback live together, the whole team can see where things stand without asking.</p>
					    <blockquote>Clear beats clever, every time.</blockquote>
					    <p>Keep updates short, decisions written down, and the next step obvious.</p>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-body {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 24px 24px 80px;
					  background: #ffffff;
					}
					.post-body *,
					.post-body *::before,
					.post-body *::after {
					  box-sizing: border-box;
					}
					.post-body h1,
					.post-body h2,
					.post-body h3,
					.post-body p,
					.post-body a {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-body__inner {
					  max-width: 720px;
					  margin: 0 auto;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.75;
					}
					.post-body__inner > * + * {
					  margin-top: 1.25em;
					}
					.post-body__inner p {
					  margin: 0;
					}
					.post-body__inner h2,
					.post-body__inner h3 {
					  margin: 2em 0 0;
					  color: var(--ink);
					  line-height: 1.25;
					  letter-spacing: -0.015em;
					}
					.post-body__inner h2 {
					  font-size: 30px;
					}
					.post-body__inner h3 {
					  font-size: 23px;
					}
					.post-body__inner a {
					  color: var(--accent);
					  text-underline-offset: 3px;
					}
					.post-body__inner img {
					  display: block;
					  max-width: 100%;
					  height: auto;
					  border-radius: 14px;
					}
					.post-body__inner blockquote {
					  margin: 2em 0;
					  padding: 4px 0 4px 24px;
					  border-left: 3px solid var(--accent);
					  color: var(--ink);
					  font-size: 22px;
					  font-weight: 500;
					  line-height: 1.5;
					}
					.post-body__inner ul,
					.post-body__inner ol {
					  padding-left: 1.4em;
					}
					@media (max-width: 767px) {
					  .post-body {
					    padding: 16px 20px 56px;
					  }
					  .post-body__inner {
					    font-size: 17px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'single-author' => array(
					'name'     => __( 'Author box', 'uichemy' ),
					'category' => __( 'Post', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<section class="post-author">
					  <div class="post-author__card">
					    <img class="post-author__avatar" src="{{ post.author.avatar }}" alt="">
					    <div class="post-author__text">
					      <p class="post-author__label">Written by</p>
					      <h3 class="post-author__name">{{ post.author.name }}</h3>
					      <p class="post-author__bio">{{ post.author.bio }}</p>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-author">
					  <div class="post-author__card">
					    <img class="post-author__avatar" src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=200&amp;q=80" alt="">
					    <div class="post-author__text">
					      <p class="post-author__label">Written by</p>
					      <h3 class="post-author__name">Alex Morgan</h3>
					      <p class="post-author__bio">Product lead at Northwind. Writes about running calm, well-organised client work.</p>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-author {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 0 24px 80px;
					  background: #ffffff;
					}
					.post-author *,
					.post-author *::before,
					.post-author *::after {
					  box-sizing: border-box;
					}
					.post-author h1,
					.post-author h2,
					.post-author h3,
					.post-author p,
					.post-author a {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-author__card {
					  display: flex;
					  align-items: center;
					  gap: 20px;
					  max-width: 720px;
					  margin: 0 auto;
					  padding: 24px;
					  border: 1px solid var(--line);
					  border-radius: 18px;
					  background: var(--soft);
					}
					.post-author__avatar {
					  flex: none;
					  width: 64px;
					  height: 64px;
					  border-radius: 50%;
					  object-fit: cover;
					  background: var(--line);
					}
					.post-author__label {
					  margin: 0;
					  color: var(--muted);
					  font-size: 13px;
					  font-weight: 500;
					}
					.post-author__name {
					  margin: 2px 0 0;
					  font-size: 18px;
					  font-weight: 650;
					}
					.post-author__bio {
					  margin: 6px 0 0;
					  color: var(--text);
					  font-size: 15px;
					  line-height: 1.6;
					}
					@media (max-width: 767px) {
					  .post-author {
					    padding: 0 20px 56px;
					  }
					  .post-author__card {
					    flex-direction: column;
					    align-items: flex-start;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// More Single sections: other posts, as loops over get_posts(). Excerpt,
				// date, category and author are post fields Pro resolves (as in Post header).
				'single-related' => array(
					'name'     => __( 'Related posts, 3 columns', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<section class="post-related">
					  <div class="post-related__inner">
					    <h2 class="post-related__title">Keep reading</h2>
					    <div class="post-related__grid">
					      {% for item in get_posts({ post_type: 'post', posts_per_page: 3, post__not_in: [ post.id ] }) %}
					      <article class="post-related__card">
					        <a class="post-related__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <p class="post-related__meta">{% for cat in item.categories|slice(0, 1) %}<span class="post-related__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('M j, Y') }}</time></p>
					        <h3 class="post-related__name"><a href="{{ item.url }}">{{ item.title }}</a></h3>
					        <p class="post-related__excerpt">{{ item.excerpt|truncate(120) }}</p>
					      </article>
					      {% else %}
					      <p class="post-related__empty">More articles are on their way.</p>
					      {% endfor %}
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-related">
					  <div class="post-related__inner">
					    <h2 class="post-related__title">Keep reading</h2>
					    <div class="post-related__grid">
					      <article class="post-related__card">
					        <a class="post-related__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-related__meta"><span class="post-related__cat">Guides</span><time>Oct 24, 2026</time></p>
					        <h3 class="post-related__name"><a href="#">How to write a project brief clients actually sign</a></h3>
					        <p class="post-related__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					      </article>
					      <article class="post-related__card">
					        <a class="post-related__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-related__meta"><span class="post-related__cat">Operations</span><time>Oct 18, 2026</time></p>
					        <h3 class="post-related__name"><a href="#">The weekly client update, in five lines</a></h3>
					        <p class="post-related__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					      </article>
					      <article class="post-related__card">
					        <a class="post-related__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-related__meta"><span class="post-related__cat">Pricing</span><time>Oct 11, 2026</time></p>
					        <h3 class="post-related__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h3>
					        <p class="post-related__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					      </article>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-related {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 72px 24px 96px;
					  background: #ffffff;
					}
					.post-related *,
					.post-related *::before,
					.post-related *::after {
					  box-sizing: border-box;
					}
					.post-related h1,
					.post-related h2,
					.post-related h3,
					.post-related p,
					.post-related a,
					.post-related time {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-related h2,
					.post-related h3 {
					  color: inherit;
					}

					.post-related__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					  padding-top: 56px;
					  border-top: 1px solid var(--line);
					}
					.post-related__title {
					  margin: 0 0 32px;
					  font-size: 28px;
					  font-weight: 600;
					  line-height: 1.2;
					  letter-spacing: -0.02em;
					}
					.post-related__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 40px 32px;
					}

					.post-related__card {
					  display: flex;
					  flex-direction: column;
					  min-width: 0;
					}
					.post-related__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 16 / 10;
					}
					.post-related__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.post-related__card:hover .post-related__media img {
					  transform: scale(1.03);
					}
					.post-related__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 20px 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.post-related__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.post-related__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.post-related__name {
					  margin: 0 0 10px;
					  font-size: 20px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.015em;
					}
					.post-related__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.post-related__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.post-related__excerpt {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.post-related__empty {
					  grid-column: 1 / -1;
					  margin: 0;
					  padding: 32px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}

					@media (max-width: 1024px) {
					  .post-related__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .post-related {
					    padding: 48px 20px 72px;
					  }
					  .post-related__grid {
					    grid-template-columns: 1fr;
					    gap: 36px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// Numbered pages: `paged: current_page()` in the query and the
				// loop_pagination() slot after {% endfor %}, exactly as the Loop panel's
				// Pagination › Numbers writes them. `also_page` offers it to pages too (Blog).
				'single-posts-grid' => array(
					'name'     => __( 'Posts grid with page numbers', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'single',
					'also_page' => true,
					'html'     => <<<'HTML'
					<section class="post-archive">
					  <div class="post-archive__inner">
					    <header class="post-archive__head">
					      <h2 class="post-archive__title">Latest articles</h2>
					      <p class="post-archive__lead">Ideas, guides and news from our team.</p>
					    </header>
					    <div class="post-archive__grid">
					      {% for item in get_posts({ post_type: 'post', posts_per_page: 6, paged: current_page() }) %}
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <p class="post-archive__meta">{% for cat in item.categories|slice(0, 1) %}<span class="post-archive__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('M j, Y') }}</time></p>
					        <h3 class="post-archive__name"><a href="{{ item.url }}">{{ item.title }}</a></h3>
					        <p class="post-archive__excerpt">{{ item.excerpt|truncate(140) }}</p>
					        <a class="post-archive__more" href="{{ item.url }}">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      {% else %}
					      <p class="post-archive__empty">No articles yet. Check back soon.</p>
					      {% endfor %}
					      <nav class="uich-loop-pagination-slot">{{ loop_pagination()|raw }}</nav>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-archive">
					  <div class="post-archive__inner">
					    <header class="post-archive__head">
					      <h2 class="post-archive__title">Latest articles</h2>
					      <p class="post-archive__lead">Ideas, guides and news from our team.</p>
					    </header>
					    <div class="post-archive__grid">
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Guides</span><time>Oct 24, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">How to write a project brief clients actually sign</a></h3>
					        <p class="post-archive__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Operations</span><time>Oct 18, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">The weekly client update, in five lines</a></h3>
					        <p class="post-archive__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Pricing</span><time>Oct 11, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h3>
					        <p class="post-archive__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Design</span><time>Oct 4, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">A design review checklist your whole team can use</a></h3>
					        <p class="post-archive__excerpt">Twelve questions that catch most problems before a client ever sees the work.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Hiring</span><time>Sep 27, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">Onboarding a new designer in their first week</a></h3>
					        <p class="post-archive__excerpt">A day-by-day plan that gets new hires shipping real work by Friday.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					      <article class="post-archive__card">
					        <a class="post-archive__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-archive__meta"><span class="post-archive__cat">Tools</span><time>Sep 20, 2026</time></p>
					        <h3 class="post-archive__name"><a href="#">The five tools we would keep if we could only keep five</a></h3>
					        <p class="post-archive__excerpt">Everything else on our stack, and why we could live without it.</p>
					        <a class="post-archive__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					      </article>
					<div class="uich-loop-pagination-slot"><nav class="uich-loop-pagination">
					  <span aria-current="page" class="page-numbers current">1</span>
					  <a class="page-numbers" href="#">2</a>
					  <a class="page-numbers" href="#">3</a>
					  <span class="page-numbers dots">…</span>
					  <a class="page-numbers" href="#">8</a>
					  <a class="next page-numbers" href="#">›</a>
					</nav></div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-archive {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.post-archive *,
					.post-archive *::before,
					.post-archive *::after {
					  box-sizing: border-box;
					}
					.post-archive h1,
					.post-archive h2,
					.post-archive h3,
					.post-archive p,
					.post-archive a,
					.post-archive time {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-archive h2,
					.post-archive h3 {
					  color: inherit;
					}

					.post-archive__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.post-archive__head {
					  max-width: 640px;
					  margin: 0 auto 56px;
					  text-align: center;
					}
					.post-archive__title {
					  margin: 0 0 12px;
					  font-size: clamp(30px, 3.6vw, 44px);
					  font-weight: 600;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.post-archive__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.post-archive__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 48px 32px;
					}

					.post-archive__card {
					  display: flex;
					  flex-direction: column;
					  min-width: 0;
					}
					.post-archive__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 16 / 10;
					}
					.post-archive__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.post-archive__card:hover .post-archive__media img {
					  transform: scale(1.03);
					}
					.post-archive__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 20px 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.post-archive__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.post-archive__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.post-archive__name {
					  margin: 0 0 10px;
					  font-size: 20px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.015em;
					}
					.post-archive__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.post-archive__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.post-archive__excerpt {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.post-archive__empty {
					  grid-column: 1 / -1;
					  margin: 0;
					  padding: 32px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}

					.post-archive__more {
					  display: inline-flex;
					  align-items: center;
					  gap: 6px;
					  margin-top: 18px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 600;
					  text-decoration: none;
					}
					.post-archive__more svg {
					  transition: transform 0.15s ease;
					}
					.post-archive__more:hover svg {
					  transform: translateX(3px);
					}

					.post-archive .uich-loop-pagination-slot {
					  grid-column: 1 / -1;
					}
					.post-archive .uich-loop-pagination {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: center;
					  gap: 8px;
					  margin-top: 56px;
					}
					.post-archive .uich-loop-pagination .page-numbers {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  min-width: 40px;
					  height: 40px;
					  padding: 0 12px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.post-archive .uich-loop-pagination a.page-numbers:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.post-archive .uich-loop-pagination .page-numbers.current {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.post-archive .uich-loop-pagination .page-numbers.dots {
					  border-color: transparent;
					  color: var(--muted);
					}

					@media (max-width: 1024px) {
					  .post-archive__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .post-archive {
					    padding: 72px 20px;
					  }
					  .post-archive__head {
					    margin-bottom: 40px;
					  }
					  .post-archive__grid {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'single-posts-list' => array(
					'name'     => __( 'Posts list with page numbers', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'single',
					'also_page' => true,
					'html'     => <<<'HTML'
					<section class="post-list">
					  <div class="post-list__inner">
					    <h2 class="post-list__title">All articles</h2>
					    <div class="post-list__items">
					      {% for item in get_posts({ post_type: 'post', posts_per_page: 4, paged: current_page() }) %}
					      <article class="post-list__item">
					        <a class="post-list__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <div class="post-list__body">
					          <p class="post-list__meta">{% for cat in item.categories|slice(0, 1) %}<span class="post-list__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('F j, Y') }}</time></p>
					          <h3 class="post-list__name"><a href="{{ item.url }}">{{ item.title }}</a></h3>
					          <p class="post-list__excerpt">{{ item.excerpt|truncate(180) }}</p>
					          <p class="post-list__author">By {{ item.author.name }}</p>
					        </div>
					      </article>
					      {% else %}
					      <p class="post-list__empty">No articles yet. Check back soon.</p>
					      {% endfor %}
					      <nav class="uich-loop-pagination-slot">{{ loop_pagination()|raw }}</nav>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-list">
					  <div class="post-list__inner">
					    <h2 class="post-list__title">All articles</h2>
					    <div class="post-list__items">
					      <article class="post-list__item">
					        <a class="post-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="post-list__body">
					          <p class="post-list__meta"><span class="post-list__cat">Guides</span><time>Oct 24, 2026</time></p>
					          <h3 class="post-list__name"><a href="#">How to write a project brief clients actually sign</a></h3>
					          <p class="post-list__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					          <p class="post-list__author">By Alex Morgan</p>
					        </div>
					      </article>
					      <article class="post-list__item">
					        <a class="post-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="post-list__body">
					          <p class="post-list__meta"><span class="post-list__cat">Operations</span><time>Oct 18, 2026</time></p>
					          <h3 class="post-list__name"><a href="#">The weekly client update, in five lines</a></h3>
					          <p class="post-list__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					          <p class="post-list__author">By Priya Shah</p>
					        </div>
					      </article>
					      <article class="post-list__item">
					        <a class="post-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="post-list__body">
					          <p class="post-list__meta"><span class="post-list__cat">Pricing</span><time>Oct 11, 2026</time></p>
					          <h3 class="post-list__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h3>
					          <p class="post-list__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					          <p class="post-list__author">By Daniel Kim</p>
					        </div>
					      </article>
					      <article class="post-list__item">
					        <a class="post-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="post-list__body">
					          <p class="post-list__meta"><span class="post-list__cat">Design</span><time>Oct 4, 2026</time></p>
					          <h3 class="post-list__name"><a href="#">A design review checklist your whole team can use</a></h3>
					          <p class="post-list__excerpt">Twelve questions that catch most problems before a client ever sees the work.</p>
					          <p class="post-list__author">By Sara Lopez</p>
					        </div>
					      </article>
					<div class="uich-loop-pagination-slot"><nav class="uich-loop-pagination">
					  <span aria-current="page" class="page-numbers current">1</span>
					  <a class="page-numbers" href="#">2</a>
					  <a class="page-numbers" href="#">3</a>
					  <span class="page-numbers dots">…</span>
					  <a class="page-numbers" href="#">8</a>
					  <a class="next page-numbers" href="#">›</a>
					</nav></div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-list {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px;
					  background: #ffffff;
					}
					.post-list *,
					.post-list *::before,
					.post-list *::after {
					  box-sizing: border-box;
					}
					.post-list h1,
					.post-list h2,
					.post-list h3,
					.post-list p,
					.post-list a,
					.post-list time {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-list h2,
					.post-list h3 {
					  color: inherit;
					}

					.post-list__inner {
					  max-width: 960px;
					  margin: 0 auto;
					}
					.post-list__title {
					  margin: 0 0 40px;
					  font-size: clamp(28px, 3.2vw, 40px);
					  font-weight: 600;
					  line-height: 1.15;
					  letter-spacing: -0.025em;
					}
					.post-list__items {
					  display: grid;
					  gap: 0;
					}
					.post-list__item {
					  display: grid;
					  grid-template-columns: 300px minmax(0, 1fr);
					  align-items: center;
					  gap: 32px;
					  padding: 32px 0;
					  border-top: 1px solid var(--line);
					}
					.post-list__item:first-child {
					  padding-top: 0;
					  border-top: 0;
					}
					.post-list__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 4 / 3;
					}
					.post-list__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.post-list__item:hover .post-list__media img {
					  transform: scale(1.03);
					}
					.post-list__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 0 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.post-list__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.post-list__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.post-list__name {
					  margin: 0 0 10px;
					  font-size: 24px;
					  font-weight: 600;
					  line-height: 1.3;
					  letter-spacing: -0.02em;
					}
					.post-list__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.post-list__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.post-list__excerpt {
					  margin: 0 0 14px;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.post-list__author {
					  margin: 0;
					  color: var(--ink);
					  font-size: 14px;
					  font-weight: 500;
					}
					.post-list__empty {
					  margin: 0;
					  padding: 32px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}

					.post-list .uich-loop-pagination-slot {
					  grid-column: 1 / -1;
					}
					.post-list .uich-loop-pagination {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: center;
					  gap: 8px;
					  margin-top: 56px;
					}
					.post-list .uich-loop-pagination .page-numbers {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  min-width: 40px;
					  height: 40px;
					  padding: 0 12px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.post-list .uich-loop-pagination a.page-numbers:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.post-list .uich-loop-pagination .page-numbers.current {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.post-list .uich-loop-pagination .page-numbers.dots {
					  border-color: transparent;
					  color: var(--muted);
					}

					@media (max-width: 767px) {
					  .post-list {
					    padding: 64px 20px;
					  }
					  .post-list__item {
					    grid-template-columns: 1fr;
					    gap: 18px;
					  }
					  .post-list__name {
					    font-size: 21px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// Topic chips over a post grid, filtered in the page: All, then the site's
				// categories (get_terms), every one of them. Every post is loaded (the engine caps
				// a loop at 100) so a chip shows all of its posts. Category fields are resolved by Pro.
				'single-posts-filter' => array(
					'name'     => __( 'Posts with category filter', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'single',
					'also_page' => true,
					'html'     => <<<'HTML'
					<section class="post-filter" data-uich-lib="post-filter">
					  <div class="post-filter__inner">
					    <header class="post-filter__head">
					      <h2 class="post-filter__title">Explore articles</h2>
					      <p class="post-filter__lead">Pick a topic to see just the posts you need.</p>
					    </header>
					    <div class="post-filter__chips" role="group" aria-label="Filter by category">
					      <button type="button" class="post-filter__chip" data-filter="all" aria-pressed="true">All</button>
					      {% for cat in get_terms({ taxonomy: 'category', hide_empty: true, exclude: [ 1 ], orderby: 'count', order: 'DESC' }) %}<button type="button" class="post-filter__chip" data-filter="{{ cat.slug }}" aria-pressed="false">{{ cat.name }}</button>{% endfor %}
					    </div>
					    <p class="post-filter__count" aria-live="polite">All</p>
					    <div class="post-filter__grid">
					      {% for item in get_posts({ post_type: 'post', posts_per_page: -1 }) %}
					      <article class="post-filter__card" data-cats="{% for cat in item.categories %}{{ cat.slug }} {% endfor %}">
					        <a class="post-filter__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <p class="post-filter__meta">{% for cat in item.categories|slice(0, 1) %}<span class="post-filter__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('M j, Y') }}</time></p>
					        <h3 class="post-filter__name"><a href="{{ item.url }}">{{ item.title }}</a></h3>
					        <p class="post-filter__excerpt">{{ item.excerpt|truncate(120) }}</p>
					      </article>
					      {% else %}
					      <p class="post-filter__empty">No articles yet. Check back soon.</p>
					      {% endfor %}
					    </div>
					    <p class="post-filter__none" hidden>No articles in this topic yet.</p>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-filter">
					  <div class="post-filter__inner">
					    <header class="post-filter__head">
					      <h2 class="post-filter__title">Explore articles</h2>
					      <p class="post-filter__lead">Pick a topic to see just the posts you need.</p>
					    </header>
					    <div class="post-filter__chips" role="group" aria-label="Filter by category">
					      <button type="button" class="post-filter__chip" data-filter="all" aria-pressed="true">All</button><button type="button" class="post-filter__chip" data-filter="guides" aria-pressed="false">Guides</button><button type="button" class="post-filter__chip" data-filter="operations" aria-pressed="false">Operations</button><button type="button" class="post-filter__chip" data-filter="design" aria-pressed="false">Design</button><button type="button" class="post-filter__chip" data-filter="pricing" aria-pressed="false">Pricing</button>
					    </div>
					    <p class="post-filter__count">All · 6</p>
					    <div class="post-filter__grid">
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Guides</span><time>Oct 24, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">How to write a project brief clients actually sign</a></h3>
					        <p class="post-filter__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					      </article>
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Operations</span><time>Oct 18, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">The weekly client update, in five lines</a></h3>
					        <p class="post-filter__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					      </article>
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Pricing</span><time>Oct 11, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h3>
					        <p class="post-filter__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					      </article>
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Design</span><time>Oct 4, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">A design review checklist your whole team can use</a></h3>
					        <p class="post-filter__excerpt">Twelve questions that catch most problems before a client ever sees the work.</p>
					      </article>
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Guides</span><time>Sep 27, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">Onboarding a new designer in their first week</a></h3>
					        <p class="post-filter__excerpt">A day-by-day plan that gets new hires shipping real work by Friday.</p>
					      </article>
					      <article class="post-filter__card">
					        <a class="post-filter__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="post-filter__meta"><span class="post-filter__cat">Operations</span><time>Sep 20, 2026</time></p>
					        <h3 class="post-filter__name"><a href="#">The five tools we would keep if we could only keep five</a></h3>
					        <p class="post-filter__excerpt">Everything else on our stack, and why we could live without it.</p>
					      </article>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-filter {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px;
					  background: #ffffff;
					}
					.post-filter *,
					.post-filter *::before,
					.post-filter *::after {
					  box-sizing: border-box;
					}
					.post-filter h2,
					.post-filter h3,
					.post-filter p,
					.post-filter a,
					.post-filter button,
					.post-filter time {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-filter h2,
					.post-filter h3 {
					  color: inherit;
					}
					.post-filter__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.post-filter__head {
					  max-width: 640px;
					  margin: 0 auto 32px;
					  text-align: center;
					}
					.post-filter__title {
					  margin: 0 0 12px;
					  font-size: clamp(28px, 3.2vw, 40px);
					  font-weight: 600;
					  line-height: 1.15;
					  letter-spacing: -0.025em;
					}
					.post-filter__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					/* The topic chips: pills on one row, the picked one filled. On a narrow
					   screen the row scrolls sideways instead of wrapping into a block. */
					.post-filter__chips {
					  display: flex;
					  justify-content: center;
					  gap: 8px;
					  margin: 0 auto;
					  padding: 2px;
					  overflow-x: auto;
					  scrollbar-width: none;
					}
					.post-filter__chips::-webkit-scrollbar {
					  display: none;
					}
					.post-filter__chip {
					  flex: none;
					  height: 40px;
					  padding: 0 18px;
					  border: 1px solid var(--line);
					  border-radius: 999px;
					  background: #ffffff;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  line-height: 1;
					  cursor: pointer;
					  transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
					}
					.post-filter__chip:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.post-filter__chip[aria-pressed="true"] {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.post-filter__chip:focus-visible {
					  outline: 2px solid var(--accent);
					  outline-offset: 2px;
					}
					.post-filter__count {
					  margin: 28px 0 20px;
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 500;
					}
					.post-filter__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 40px 32px;
					}
					.post-filter__card {
					  display: flex;
					  flex-direction: column;
					  min-width: 0;
					}
					.post-filter__card[hidden] {
					  display: none;
					}
					.post-filter__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 16 / 10;
					}
					.post-filter__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.post-filter__card:hover .post-filter__media img {
					  transform: scale(1.03);
					}
					.post-filter__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 20px 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.post-filter__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.post-filter__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.post-filter__name {
					  margin: 0 0 10px;
					  font-size: 20px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.015em;
					}
					.post-filter__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.post-filter__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.post-filter__excerpt {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.post-filter__empty,
					.post-filter__none {
					  grid-column: 1 / -1;
					  margin: 0;
					  padding: 32px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}
					.post-filter__none[hidden] {
					  display: none;
					}
					@media (max-width: 1024px) {
					  .post-filter__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .post-filter {
					    padding: 64px 20px;
					  }
					  .post-filter__chips {
					    justify-content: flex-start;
					    margin: 0 -20px;
					    padding: 2px 20px;
					  }
					  .post-filter__grid {
					    grid-template-columns: 1fr;
					    gap: 36px;
					  }
					}
					CSS
					,
					'js'       => <<<'JS'
					/* Topic chips: show only the posts filed under the picked category (each card
					   lists its category slugs in data-cats), and say how many in the count line. */
					(function () {
					  document.querySelectorAll('[data-uich-lib="post-filter"]:not([data-uich-ready])').forEach(function (root) {
					    root.setAttribute('data-uich-ready', '');
					    var chips = Array.prototype.slice.call(root.querySelectorAll('.post-filter__chip'));
					    var cards = Array.prototype.slice.call(root.querySelectorAll('.post-filter__card'));
					    var count = root.querySelector('.post-filter__count');
					    var none = root.querySelector('.post-filter__none');

					    function show(filter, label) {
					      var shown = 0;
					      cards.forEach(function (card) {
					        var cats = (card.getAttribute('data-cats') || '').split(/\s+/);
					        var on = filter === 'all' || cats.indexOf(filter) !== -1;
					        card.hidden = !on;
					        if (on) shown++;
					      });
					      chips.forEach(function (chip) {
					        chip.setAttribute('aria-pressed', chip.getAttribute('data-filter') === filter ? 'true' : 'false');
					      });
					      if (count) count.textContent = label + ' · ' + shown;
					      if (none) none.hidden = shown > 0 || !cards.length;
					    }

					    chips.forEach(function (chip) {
					      chip.addEventListener('click', function () {
					        show(chip.getAttribute('data-filter'), chip.textContent.trim());
					      });
					    });
					    show('all', 'All');
					  });
					})();
					JS
					,
				),
				'single-prev-next' => array(
					'name'     => __( 'Previous and next post', 'uichemy' ),
					'category' => __( 'Post', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<nav class="post-pager" aria-label="More articles">
					  <div class="post-pager__inner">
					    {% if post.prev %}
					    <a class="post-pager__link post-pager__link--prev" href="{{ post.prev.url }}">
					      <span class="post-pager__label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Previous article</span>
					      <span class="post-pager__name">{{ post.prev.title }}</span>
					    </a>
					    {% endif %}
					    {% if post.next %}
					    <a class="post-pager__link post-pager__link--next" href="{{ post.next.url }}">
					      <span class="post-pager__label">Next article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></span>
					      <span class="post-pager__name">{{ post.next.title }}</span>
					    </a>
					    {% endif %}
					  </div>
					</nav>
					HTML
					,
					'preview_html' => <<<'HTML'
					<nav class="post-pager" aria-label="More articles">
					  <div class="post-pager__inner">
					    <a class="post-pager__link post-pager__link--prev" href="#">
					      <span class="post-pager__label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Previous article</span>
					      <span class="post-pager__name">The weekly client update, in five lines</span>
					    </a>
					    <a class="post-pager__link post-pager__link--next" href="#">
					      <span class="post-pager__label">Next article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></span>
					      <span class="post-pager__name">Moving from hourly billing to fixed-price projects</span>
					    </a>
					  </div>
					</nav>
					HTML
					,
					'css'      => <<<'CSS'
					.post-pager {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 24px 24px 72px;
					  background: #ffffff;
					}
					.post-pager *,
					.post-pager *::before,
					.post-pager *::after {
					  box-sizing: border-box;
					}
					.post-pager h1,
					.post-pager h2,
					.post-pager h3,
					.post-pager p,
					.post-pager a,
					.post-pager time {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-pager h2,
					.post-pager h3 {
					  color: inherit;
					}

					.post-pager__inner {
					  display: grid;
					  grid-template-columns: repeat(2, minmax(0, 1fr));
					  gap: 20px;
					  max-width: 880px;
					  margin: 0 auto;
					}
					.post-pager__link {
					  display: flex;
					  flex-direction: column;
					  gap: 8px;
					  padding: 22px 24px;
					  border: 1px solid var(--line);
					  border-radius: 14px;
					  color: var(--ink);
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.post-pager__link:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.post-pager__link--next {
					  grid-column: 2;
					  align-items: flex-end;
					  text-align: right;
					}
					.post-pager__label {
					  display: inline-flex;
					  align-items: center;
					  gap: 6px;
					  color: var(--muted);
					  font-size: 13px;
					  font-weight: 600;
					  letter-spacing: 0.02em;
					  text-transform: uppercase;
					}
					.post-pager__name {
					  font-size: 18px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.01em;
					}
					@media (max-width: 767px) {
					  .post-pager {
					    padding: 16px 20px 56px;
					  }
					  .post-pager__inner {
					    grid-template-columns: 1fr;
					  }
					  .post-pager__link--next {
					    grid-column: auto;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// Categories: the site's own (get_terms), and the post's own (post.categories,
				// post.tags). Term fields and get_terms() are resolved by Pro. `exclude: [ 1 ]`
				// leaves out WordPress's default "Uncategorized" (term 1 on a fresh install).
				'single-categories' => array(
					'name'     => __( 'Browse by category', 'uichemy' ),
					'category' => __( 'Categories', 'uichemy' ),
					'slot'     => 'single',
					'also_page' => true,
					'html'     => <<<'HTML'
					<section class="post-topics">
					  <div class="post-topics__inner">
					    <header class="post-topics__head">
					      <h2 class="post-topics__title">Browse by topic</h2>
					      <p class="post-topics__lead">Find the articles that matter most to you.</p>
					    </header>
					    <div class="post-topics__grid">
					      {% for cat in get_terms({ taxonomy: 'category', hide_empty: true, exclude: [ 1 ], orderby: 'count', order: 'DESC', number: 6 }) %}
					      <a class="post-topics__card" href="{{ cat.url }}">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">{{ cat.name }}</span>
					        <span class="post-topics__count">{{ cat.count }} {% if cat.count == 1 %}article{% else %}articles{% endif %}</span>
					        {% if cat.description %}<span class="post-topics__desc">{{ cat.description|truncate(90) }}</span>{% endif %}
					      </a>
					      {% else %}
					      <p class="post-topics__empty">Topics will appear here once articles are filed under them.</p>
					      {% endfor %}
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-topics">
					  <div class="post-topics__inner">
					    <header class="post-topics__head">
					      <h2 class="post-topics__title">Browse by topic</h2>
					      <p class="post-topics__lead">Find the articles that matter most to you.</p>
					    </header>
					    <div class="post-topics__grid">
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Guides</span>
					        <span class="post-topics__count">12 articles</span>
					        <span class="post-topics__desc">Step-by-step help for the jobs that come up every week.</span>
					      </a>
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Operations</span>
					        <span class="post-topics__count">9 articles</span>
					        <span class="post-topics__desc">Running projects, people and money without the chaos.</span>
					      </a>
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Design</span>
					        <span class="post-topics__count">8 articles</span>
					        <span class="post-topics__desc">Reviews, systems and the craft behind good work.</span>
					      </a>
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Pricing</span>
					        <span class="post-topics__count">6 articles</span>
					        <span class="post-topics__desc">Quotes, retainers and getting paid what the work is worth.</span>
					      </a>
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Hiring</span>
					        <span class="post-topics__count">4 articles</span>
					        <span class="post-topics__desc">Finding, onboarding and keeping great people.</span>
					      </a>
					      <a class="post-topics__card" href="#">
					        <span class="post-topics__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6l2 2.2h7.4A2.5 2.5 0 0 1 21 9.7v7.8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-10Z"/></svg></span>
					        <span class="post-topics__arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg></span>
					        <span class="post-topics__name">Tools</span>
					        <span class="post-topics__count">3 articles</span>
					        <span class="post-topics__desc">The software we use, and what we would drop.</span>
					      </a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-topics {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px;
					  background: #ffffff;
					}
					.post-topics *,
					.post-topics *::before,
					.post-topics *::after {
					  box-sizing: border-box;
					}
					.post-topics h2,
					.post-topics h3,
					.post-topics p,
					.post-topics a,
					.post-topics span {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-topics h2 {
					  color: inherit;
					}

					.post-topics__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.post-topics__head {
					  max-width: 640px;
					  margin: 0 auto 48px;
					  text-align: center;
					}
					.post-topics__title {
					  margin: 0 0 12px;
					  font-size: clamp(28px, 3.2vw, 40px);
					  font-weight: 600;
					  line-height: 1.15;
					  letter-spacing: -0.025em;
					}
					.post-topics__lead {
					  margin: 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.post-topics__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 20px;
					}
					.post-topics__card {
					  position: relative;
					  display: flex;
					  flex-direction: column;
					  gap: 6px;
					  min-width: 0;
					  padding: 24px;
					  border: 1px solid var(--line);
					  border-radius: 16px;
					  color: var(--ink);
					  text-decoration: none;
					  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
					}
					.post-topics__card:hover {
					  border-color: #c9ccd3;
					  box-shadow: 0 10px 30px -18px rgba(14, 17, 22, 0.35);
					  transform: translateY(-2px);
					}
					.post-topics__icon {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  width: 44px;
					  height: 44px;
					  margin-bottom: 14px;
					  border-radius: 12px;
					  background: var(--accent-soft);
					  color: var(--accent);
					}
					.post-topics__arrow {
					  position: absolute;
					  top: 24px;
					  right: 24px;
					  color: var(--muted);
					  transition: color 0.15s ease, transform 0.15s ease;
					}
					.post-topics__card:hover .post-topics__arrow {
					  color: var(--ink);
					  transform: translate(2px, -2px);
					}
					.post-topics__name {
					  font-size: 19px;
					  font-weight: 600;
					  line-height: 1.3;
					  letter-spacing: -0.015em;
					}
					.post-topics__count {
					  color: var(--accent);
					  font-size: 14px;
					  font-weight: 600;
					}
					.post-topics__desc {
					  margin-top: 6px;
					  color: var(--text);
					  font-size: 15px;
					  line-height: 1.55;
					}
					.post-topics__empty {
					  grid-column: 1 / -1;
					  margin: 0;
					  padding: 32px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}
					@media (max-width: 1024px) {
					  .post-topics__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .post-topics {
					    padding: 64px 20px;
					  }
					  .post-topics__head {
					    margin-bottom: 36px;
					  }
					  .post-topics__grid {
					    grid-template-columns: 1fr;
					    gap: 14px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'single-post-terms' => array(
					'name'     => __( 'Post categories and tags', 'uichemy' ),
					'category' => __( 'Categories', 'uichemy' ),
					'slot'     => 'single',
					'html'     => <<<'HTML'
					<section class="post-terms">
					  <div class="post-terms__inner">
					    {% if post.categories %}
					    <div class="post-terms__row">
					      <span class="post-terms__label">Filed under</span>
					      <div class="post-terms__chips">
					        {% for cat in post.categories %}<a class="post-terms__chip post-terms__chip--cat" href="{{ cat.url }}">{{ cat.name }}</a>{% endfor %}
					      </div>
					    </div>
					    {% endif %}
					    {% if post.tags %}
					    <div class="post-terms__row">
					      <span class="post-terms__label">Tags</span>
					      <div class="post-terms__chips">
					        {% for tag in post.tags %}<a class="post-terms__chip" href="{{ tag.url }}">#{{ tag.name }}</a>{% endfor %}
					      </div>
					    </div>
					    {% endif %}
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="post-terms">
					  <div class="post-terms__inner">
					    <div class="post-terms__row">
					      <span class="post-terms__label">Filed under</span>
					      <div class="post-terms__chips">
					        <a class="post-terms__chip post-terms__chip--cat" href="#">Guides</a><a class="post-terms__chip post-terms__chip--cat" href="#">Operations</a>
					      </div>
					    </div>
					    <div class="post-terms__row">
					      <span class="post-terms__label">Tags</span>
					      <div class="post-terms__chips">
					        <a class="post-terms__chip" href="#">#clients</a><a class="post-terms__chip" href="#">#planning</a><a class="post-terms__chip" href="#">#templates</a>
					      </div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.post-terms {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 8px 24px 48px;
					  background: #ffffff;
					}
					.post-terms *,
					.post-terms *::before,
					.post-terms *::after {
					  box-sizing: border-box;
					}
					.post-terms h2,
					.post-terms h3,
					.post-terms p,
					.post-terms a,
					.post-terms span {
					  font-family: inherit;
					  text-transform: none;
					}
					.post-terms h2 {
					  color: inherit;
					}

					.post-terms__inner {
					  display: flex;
					  flex-direction: column;
					  gap: 14px;
					  max-width: 720px;
					  margin: 0 auto;
					  padding-top: 28px;
					  border-top: 1px solid var(--line);
					}
					.post-terms__row {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 10px 14px;
					}
					.post-terms__label {
					  min-width: 92px;
					  color: var(--muted);
					  font-size: 13px;
					  font-weight: 600;
					  letter-spacing: 0.02em;
					  text-transform: uppercase;
					}
					.post-terms__chips {
					  display: flex;
					  flex-wrap: wrap;
					  gap: 8px;
					}
					.post-terms__chip {
					  display: inline-flex;
					  align-items: center;
					  height: 32px;
					  padding: 0 14px;
					  border: 1px solid var(--line);
					  border-radius: 999px;
					  color: var(--text);
					  font-size: 14px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
					}
					.post-terms__chip:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					  color: var(--ink);
					}
					.post-terms__chip--cat {
					  border-color: transparent;
					  background: var(--accent-soft);
					  color: var(--accent);
					}
					.post-terms__chip--cat:hover {
					  border-color: transparent;
					  background: var(--accent);
					  color: #ffffff;
					}
					@media (max-width: 767px) {
					  .post-terms {
					    padding: 8px 20px 40px;
					  }
					  .post-terms__label {
					    min-width: 0;
					    width: 100%;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// Archive templates (category, tag, author, date, search): `archive` is the list
				// being shown (title, label, description, count, posts_url) and `inherit: true`
				// loops over that list's own posts. Both are resolved by Pro; on the builder
				// canvas they fall back to a plain title and the latest posts.
				'archive-title' => array(
					'name'     => __( 'Archive title', 'uichemy' ),
					'category' => __( 'Archive header', 'uichemy' ),
					'slot'     => 'archive',
					'html'     => <<<'HTML'
					<section class="arc-title">
					  <div class="arc-title__inner">
					    <p class="arc-title__label">{{ archive.label ?? 'Blog' }}</p>
					    <h1 class="arc-title__name">{{ archive.title ?? 'All articles' }}</h1>
					    {% if archive.description %}<p class="arc-title__desc">{{ archive.description }}</p>{% endif %}
					    {% if archive.count %}<p class="arc-title__count">{{ archive.count }} {% if archive.count == 1 %}article{% else %}articles{% endif %}</p>{% endif %}
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="arc-title">
					  <div class="arc-title__inner">
					    <p class="arc-title__label">Category</p>
					    <h1 class="arc-title__name">Guides</h1>
					    <p class="arc-title__desc">Step-by-step help for the jobs that come up every week, from briefs to invoices.</p>
					    <p class="arc-title__count">12 articles</p>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.arc-title {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 96px 24px 72px;
					  background: var(--soft);
					}
					.arc-title *,
					.arc-title *::before,
					.arc-title *::after {
					  box-sizing: border-box;
					}
					.arc-title h1,
					.arc-title h2,
					.arc-title h3,
					.arc-title p,
					.arc-title a,
					.arc-title button,
					.arc-title input,
					.arc-title time {
					  font-family: inherit;
					  text-transform: none;
					}
					.arc-title h1,
					.arc-title h2,
					.arc-title h3 {
					  color: inherit;
					}

					.arc-title__inner {
					  max-width: 760px;
					  margin: 0 auto;
					  text-align: center;
					}
					.arc-title__label {
					  display: inline-flex;
					  align-items: center;
					  height: 28px;
					  margin: 0 0 18px;
					  padding: 0 12px;
					  border-radius: 999px;
					  background: var(--accent-soft);
					  color: var(--accent);
					  font-size: 13px;
					  font-weight: 600;
					  letter-spacing: 0.02em;
					}
					.arc-title__name {
					  margin: 0;
					  font-size: clamp(36px, 5vw, 56px);
					  font-weight: 700;
					  line-height: 1.08;
					  letter-spacing: -0.03em;
					}
					.arc-title__desc {
					  max-width: 600px;
					  margin: 18px auto 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.arc-title__count {
					  margin: 18px 0 0;
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 500;
					}
					@media (max-width: 767px) {
					  .arc-title {
					    padding: 64px 20px 48px;
					  }
					  .arc-title__desc {
					    font-size: 16px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'archive-topics' => array(
					'name'     => __( 'Archive title with category chips', 'uichemy' ),
					'category' => __( 'Archive header', 'uichemy' ),
					'slot'     => 'archive',
					'html'     => <<<'HTML'
					<section class="arc-topics">
					  <div class="arc-topics__inner">
					    <h1 class="arc-topics__name">{{ archive.title ?? 'All articles' }}</h1>
					    {% if archive.description %}<p class="arc-topics__desc">{{ archive.description }}</p>{% endif %}
					    <nav class="arc-topics__chips" aria-label="Categories">
					      <a class="arc-topics__chip{% if not term %} is-active{% endif %}" href="{{ archive.posts_url ?? '/' }}">All</a>
					      {% for cat in get_terms({ taxonomy: 'category', hide_empty: true, exclude: [ 1 ], orderby: 'count', order: 'DESC' }) %}<a class="arc-topics__chip{% if term and term.id == cat.id %} is-active{% endif %}" href="{{ cat.url }}">{{ cat.name }}</a>{% endfor %}
					    </nav>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="arc-topics">
					  <div class="arc-topics__inner">
					    <h1 class="arc-topics__name">Guides</h1>
					    <p class="arc-topics__desc">Step-by-step help for the jobs that come up every week.</p>
					    <nav class="arc-topics__chips" aria-label="Categories">
					      <a class="arc-topics__chip" href="#">All</a><a class="arc-topics__chip is-active" href="#">Guides</a><a class="arc-topics__chip" href="#">Operations</a><a class="arc-topics__chip" href="#">Design</a><a class="arc-topics__chip" href="#">Pricing</a><a class="arc-topics__chip" href="#">Hiring</a>
					    </nav>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.arc-topics {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 88px 24px 40px;
					  background: #ffffff;
					}
					.arc-topics *,
					.arc-topics *::before,
					.arc-topics *::after {
					  box-sizing: border-box;
					}
					.arc-topics h1,
					.arc-topics h2,
					.arc-topics h3,
					.arc-topics p,
					.arc-topics a,
					.arc-topics button,
					.arc-topics input,
					.arc-topics time {
					  font-family: inherit;
					  text-transform: none;
					}
					.arc-topics h1,
					.arc-topics h2,
					.arc-topics h3 {
					  color: inherit;
					}

					.arc-topics__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					  text-align: center;
					}
					.arc-topics__name {
					  margin: 0;
					  font-size: clamp(34px, 4.6vw, 52px);
					  font-weight: 700;
					  line-height: 1.1;
					  letter-spacing: -0.03em;
					}
					.arc-topics__desc {
					  max-width: 600px;
					  margin: 16px auto 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.arc-topics__chips {
					  display: flex;
					  justify-content: center;
					  gap: 8px;
					  margin-top: 36px;
					  padding: 2px;
					  overflow-x: auto;
					  scrollbar-width: none;
					}
					.arc-topics__chips::-webkit-scrollbar {
					  display: none;
					}
					.arc-topics__chip {
					  display: inline-flex;
					  flex: none;
					  align-items: center;
					  height: 40px;
					  padding: 0 18px;
					  border: 1px solid var(--line);
					  border-radius: 999px;
					  background: #ffffff;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
					}
					.arc-topics__chip:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.arc-topics__chip.is-active {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					@media (max-width: 767px) {
					  .arc-topics {
					    padding: 56px 20px 28px;
					  }
					  .arc-topics__chips {
					    justify-content: flex-start;
					    margin: 28px -20px 0;
					    padding: 2px 20px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'archive-grid' => array(
					'name'     => __( 'Archive posts grid with page numbers', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'archive',
					'html'     => <<<'HTML'
					<section class="arc-grid">
					  <div class="arc-grid__inner">
					    <div class="arc-grid__grid">
					      {% for item in get_posts({ inherit: true, post_type: 'post', posts_per_page: 9, paged: current_page() }) %}
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <p class="arc-grid__meta">{% for cat in item.categories|slice(0, 1) %}<span class="arc-grid__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('M j, Y') }}</time></p>
					        <h2 class="arc-grid__name"><a href="{{ item.url }}">{{ item.title }}</a></h2>
					        <p class="arc-grid__excerpt">{{ item.excerpt|truncate(130) }}</p>
					      </article>
					      {% else %}
					      <p class="arc-grid__empty">Nothing here yet. Try another topic, or search for what you need.</p>
					      {% endfor %}
					      <nav class="uich-loop-pagination-slot">{{ loop_pagination()|raw }}</nav>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="arc-grid">
					  <div class="arc-grid__inner">
					    <div class="arc-grid__grid">
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Oct 24, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">How to write a project brief clients actually sign</a></h2>
					        <p class="arc-grid__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					      </article>
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Oct 18, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">The weekly client update, in five lines</a></h2>
					        <p class="arc-grid__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					      </article>
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Oct 11, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h2>
					        <p class="arc-grid__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					      </article>
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Oct 4, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">A design review checklist your whole team can use</a></h2>
					        <p class="arc-grid__excerpt">Twelve questions that catch most problems before a client ever sees the work.</p>
					      </article>
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Sep 27, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">Onboarding a new designer in their first week</a></h2>
					        <p class="arc-grid__excerpt">A day-by-day plan that gets new hires shipping real work by Friday.</p>
					      </article>
					      <article class="arc-grid__card">
					        <a class="arc-grid__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <p class="arc-grid__meta"><span class="arc-grid__cat">Guides</span><time>Sep 20, 2026</time></p>
					        <h2 class="arc-grid__name"><a href="#">The five tools we would keep if we could only keep five</a></h2>
					        <p class="arc-grid__excerpt">Everything else on our stack, and why we could live without it.</p>
					      </article>
					<div class="uich-loop-pagination-slot"><nav class="uich-loop-pagination">
					  <span aria-current="page" class="page-numbers current">1</span>
					  <a class="page-numbers" href="#">2</a>
					  <a class="page-numbers" href="#">3</a>
					  <a class="next page-numbers" href="#">›</a>
					</nav></div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.arc-grid {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 64px 24px 112px;
					  background: #ffffff;
					}
					.arc-grid *,
					.arc-grid *::before,
					.arc-grid *::after {
					  box-sizing: border-box;
					}
					.arc-grid h1,
					.arc-grid h2,
					.arc-grid h3,
					.arc-grid p,
					.arc-grid a,
					.arc-grid button,
					.arc-grid input,
					.arc-grid time {
					  font-family: inherit;
					  text-transform: none;
					}
					.arc-grid h1,
					.arc-grid h2,
					.arc-grid h3 {
					  color: inherit;
					}

					.arc-grid__inner {
					  max-width: 1200px;
					  margin: 0 auto;
					}
					.arc-grid__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 48px 32px;
					}

					.arc-grid__card {
					  display: flex;
					  flex-direction: column;
					  min-width: 0;
					}
					.arc-grid__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 16 / 10;
					}
					.arc-grid__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.arc-grid__card:hover .arc-grid__media img {
					  transform: scale(1.03);
					}
					.arc-grid__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 20px 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.arc-grid__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.arc-grid__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.arc-grid__name {
					  margin: 0 0 10px;
					  font-size: 20px;
					  font-weight: 600;
					  line-height: 1.35;
					  letter-spacing: -0.015em;
					}
					.arc-grid__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.arc-grid__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.arc-grid__excerpt {
					  margin: 0;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.arc-grid__empty {
					  grid-column: 1 / -1;
					  margin: 0;
					  padding: 40px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}

					.arc-grid .uich-loop-pagination-slot {
					  grid-column: 1 / -1;
					}
					.arc-grid .uich-loop-pagination {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: center;
					  gap: 8px;
					  margin-top: 56px;
					}
					.arc-grid .uich-loop-pagination .page-numbers {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  min-width: 40px;
					  height: 40px;
					  padding: 0 12px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.arc-grid .uich-loop-pagination a.page-numbers:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.arc-grid .uich-loop-pagination .page-numbers.current {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.arc-grid .uich-loop-pagination .page-numbers.dots {
					  border-color: transparent;
					  color: var(--muted);
					}

					@media (max-width: 1024px) {
					  .arc-grid__grid {
					    grid-template-columns: repeat(2, minmax(0, 1fr));
					  }
					}
					@media (max-width: 767px) {
					  .arc-grid {
					    padding: 40px 20px 72px;
					  }
					  .arc-grid__grid {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'archive-list' => array(
					'name'     => __( 'Archive posts list with page numbers', 'uichemy' ),
					'category' => __( 'Post lists', 'uichemy' ),
					'slot'     => 'archive',
					'html'     => <<<'HTML'
					<section class="arc-list">
					  <div class="arc-list__inner">
					    <div class="arc-list__items">
					      {% for item in get_posts({ inherit: true, post_type: 'post', posts_per_page: 6, paged: current_page() }) %}
					      <article class="arc-list__item">
					        <a class="arc-list__media" href="{{ item.url }}" aria-hidden="true" tabindex="-1">
					          {% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}
					        </a>
					        <div class="arc-list__body">
					          <p class="arc-list__meta">{% for cat in item.categories|slice(0, 1) %}<span class="arc-list__cat">{{ cat.name }}</span>{% endfor %}<time>{{ item.date('F j, Y') }}</time></p>
					          <h2 class="arc-list__name"><a href="{{ item.url }}">{{ item.title }}</a></h2>
					          <p class="arc-list__excerpt">{{ item.excerpt|truncate(180) }}</p>
					          <a class="arc-list__more" href="{{ item.url }}">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        </div>
					      </article>
					      {% else %}
					      <p class="arc-list__empty">Nothing here yet. Try another topic, or search for what you need.</p>
					      {% endfor %}
					      <nav class="uich-loop-pagination-slot">{{ loop_pagination()|raw }}</nav>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="arc-list">
					  <div class="arc-list__inner">
					    <div class="arc-list__items">
					      <article class="arc-list__item">
					        <a class="arc-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="arc-list__body">
					          <p class="arc-list__meta"><span class="arc-list__cat">Guides</span><time>Oct 24, 2026</time></p>
					          <h2 class="arc-list__name"><a href="#">How to write a project brief clients actually sign</a></h2>
					          <p class="arc-list__excerpt">A one-page template that sets scope, budget and deadlines before anyone starts work.</p>
					          <a class="arc-list__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        </div>
					      </article>
					      <article class="arc-list__item">
					        <a class="arc-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="arc-list__body">
					          <p class="arc-list__meta"><span class="arc-list__cat">Guides</span><time>Oct 18, 2026</time></p>
					          <h2 class="arc-list__name"><a href="#">The weekly client update, in five lines</a></h2>
					          <p class="arc-list__excerpt">What to include, what to leave out, and why shorter updates get faster replies.</p>
					          <a class="arc-list__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        </div>
					      </article>
					      <article class="arc-list__item">
					        <a class="arc-list__media" href="#" aria-hidden="true" tabindex="-1"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></a>
					        <div class="arc-list__body">
					          <p class="arc-list__meta"><span class="arc-list__cat">Guides</span><time>Oct 11, 2026</time></p>
					          <h2 class="arc-list__name"><a href="#">Moving from hourly billing to fixed-price projects</a></h2>
					          <p class="arc-list__excerpt">How three agencies made the switch without losing money on the first few jobs.</p>
					          <a class="arc-list__more" href="#">Read article <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        </div>
					      </article>
					<div class="uich-loop-pagination-slot"><nav class="uich-loop-pagination">
					  <span aria-current="page" class="page-numbers current">1</span>
					  <a class="page-numbers" href="#">2</a>
					  <a class="page-numbers" href="#">3</a>
					  <a class="next page-numbers" href="#">›</a>
					</nav></div>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.arc-list {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 56px 24px 104px;
					  background: #ffffff;
					}
					.arc-list *,
					.arc-list *::before,
					.arc-list *::after {
					  box-sizing: border-box;
					}
					.arc-list h1,
					.arc-list h2,
					.arc-list h3,
					.arc-list p,
					.arc-list a,
					.arc-list button,
					.arc-list input,
					.arc-list time {
					  font-family: inherit;
					  text-transform: none;
					}
					.arc-list h1,
					.arc-list h2,
					.arc-list h3 {
					  color: inherit;
					}

					.arc-list__inner {
					  max-width: 960px;
					  margin: 0 auto;
					}
					.arc-list__item {
					  display: grid;
					  grid-template-columns: 320px minmax(0, 1fr);
					  align-items: center;
					  gap: 36px;
					  padding: 32px 0;
					  border-top: 1px solid var(--line);
					}
					.arc-list__item:first-child {
					  padding-top: 0;
					  border-top: 0;
					}
					.arc-list__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 14px;
					  background: var(--soft);
					  aspect-ratio: 4 / 3;
					}
					.arc-list__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.arc-list__item:hover .arc-list__media img {
					  transform: scale(1.03);
					}
					.arc-list__meta {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  gap: 8px;
					  margin: 0 0 10px;
					  color: var(--muted);
					  font-size: 14px;
					}
					.arc-list__cat {
					  color: var(--accent);
					  font-weight: 600;
					}
					.arc-list__cat + time::before {
					  content: "·";
					  margin-right: 8px;
					  color: var(--muted);
					}
					.arc-list__name {
					  margin: 0 0 10px;
					  font-size: 24px;
					  font-weight: 600;
					  line-height: 1.3;
					  letter-spacing: -0.02em;
					}
					.arc-list__name a {
					  color: var(--ink);
					  text-decoration: none;
					}
					.arc-list__name a:hover {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					.arc-list__excerpt {
					  margin: 0 0 16px;
					  color: var(--text);
					  font-size: 16px;
					  line-height: 1.6;
					}
					.arc-list__more {
					  display: inline-flex;
					  align-items: center;
					  gap: 6px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 600;
					  text-decoration: none;
					}
					.arc-list__more svg {
					  transition: transform 0.15s ease;
					}
					.arc-list__more:hover svg {
					  transform: translateX(3px);
					}
					.arc-list__empty {
					  margin: 0;
					  padding: 40px;
					  border: 1px dashed var(--line);
					  border-radius: 14px;
					  color: var(--muted);
					  text-align: center;
					}

					.arc-list .uich-loop-pagination-slot {
					  grid-column: 1 / -1;
					}
					.arc-list .uich-loop-pagination {
					  display: flex;
					  flex-wrap: wrap;
					  align-items: center;
					  justify-content: center;
					  gap: 8px;
					  margin-top: 56px;
					}
					.arc-list .uich-loop-pagination .page-numbers {
					  display: inline-flex;
					  align-items: center;
					  justify-content: center;
					  min-width: 40px;
					  height: 40px;
					  padding: 0 12px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  color: var(--ink);
					  font-size: 15px;
					  font-weight: 500;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.arc-list .uich-loop-pagination a.page-numbers:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.arc-list .uich-loop-pagination .page-numbers.current {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.arc-list .uich-loop-pagination .page-numbers.dots {
					  border-color: transparent;
					  color: var(--muted);
					}

					@media (max-width: 767px) {
					  .arc-list {
					    padding: 40px 20px 64px;
					  }
					  .arc-list__item {
					    grid-template-columns: 1fr;
					    gap: 18px;
					  }
					  .arc-list__name {
					    font-size: 21px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				// 404 templates. `site.url` is Pro; a Free site falls back to "/".
				'error-404-big' => array(
					'name'     => __( '404 with big number', 'uichemy' ),
					'category' => __( '404', 'uichemy' ),
					'slot'     => 'error_404',
					'html'     => <<<'HTML'
					<section class="err-big">
					  <div class="err-big__inner">
					    <p class="err-big__code" aria-hidden="true">404</p>
					    <h1 class="err-big__title">This page could not be found</h1>
					    <p class="err-big__text">The link may be broken, or the page may have moved. Let’s get you back on track.</p>
					    <div class="err-big__actions">
					      <a class="err-big__btn err-big__btn--primary" href="{{ site.url ?? '/' }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z"/></svg> Back to home</a>
					      <a class="err-big__btn" href="{{ site.url ?? '/' }}?s="><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> Search the site</a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="err-big">
					  <div class="err-big__inner">
					    <p class="err-big__code" aria-hidden="true">404</p>
					    <h1 class="err-big__title">This page could not be found</h1>
					    <p class="err-big__text">The link may be broken, or the page may have moved. Let’s get you back on track.</p>
					    <div class="err-big__actions">
					      <a class="err-big__btn err-big__btn--primary" href="#"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z"/></svg> Back to home</a>
					      <a class="err-big__btn" href="#"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> Search the site</a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.err-big {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 120px 24px;
					  background: #ffffff;
					}
					.err-big *,
					.err-big *::before,
					.err-big *::after {
					  box-sizing: border-box;
					}
					.err-big h1,
					.err-big h2,
					.err-big h3,
					.err-big p,
					.err-big a,
					.err-big button,
					.err-big input,
					.err-big time {
					  font-family: inherit;
					  text-transform: none;
					}
					.err-big h1,
					.err-big h2,
					.err-big h3 {
					  color: inherit;
					}

					.err-big {
					  display: flex;
					  align-items: center;
					  min-height: 70vh;
					}
					.err-big__inner {
					  width: 100%;
					  max-width: 640px;
					  margin: 0 auto;
					  text-align: center;
					}
					.err-big__code {
					  margin: 0;
					  background: linear-gradient(180deg, var(--ink) 0%, #9aa1ad 100%);
					  -webkit-background-clip: text;
					  background-clip: text;
					  color: transparent;
					  font-size: clamp(120px, 22vw, 220px);
					  font-weight: 800;
					  line-height: 0.9;
					  letter-spacing: -0.06em;
					}
					.err-big__title {
					  margin: 24px 0 0;
					  font-size: clamp(26px, 3.4vw, 36px);
					  font-weight: 700;
					  line-height: 1.2;
					  letter-spacing: -0.02em;
					}
					.err-big__text {
					  margin: 14px auto 0;
					  max-width: 480px;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.err-big__actions {
					  display: flex;
					  flex-wrap: wrap;
					  justify-content: center;
					  gap: 12px;
					  margin-top: 36px;
					}
					.err-big__btn {
					  display: inline-flex;
					  align-items: center;
					  gap: 8px;
					  height: 48px;
					  padding: 0 22px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  background: #ffffff;
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 600;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.err-big__btn:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.err-big__btn--primary {
					  border-color: var(--ink);
					  background: var(--ink);
					  color: #ffffff;
					}
					.err-big__btn--primary:hover {
					  border-color: #2a2f38;
					  background: #2a2f38;
					}
					@media (max-width: 767px) {
					  .err-big {
					    min-height: 0;
					    padding: 80px 20px;
					  }
					  .err-big__text {
					    font-size: 16px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'error-404-search' => array(
					'name'     => __( '404 with search and recent posts', 'uichemy' ),
					'category' => __( '404', 'uichemy' ),
					'slot'     => 'error_404',
					'html'     => <<<'HTML'
					<section class="err-search">
					  <div class="err-search__inner">
					    <p class="err-search__label">Error 404</p>
					    <h1 class="err-search__title">We couldn’t find that page</h1>
					    <p class="err-search__text">Try searching for it, or start with one of our latest articles below.</p>
					    <form class="err-search__form" role="search" method="get" action="{{ site.url ?? '/' }}">
					      <span class="err-search__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
					      <input class="err-search__input" type="search" name="s" placeholder="Search articles…" aria-label="Search articles">
					      <button class="err-search__submit" type="submit">Search</button>
					    </form>
					    <h2 class="err-search__sub">Latest articles</h2>
					    <div class="err-search__grid">
					      {% for item in get_posts({ post_type: 'post', posts_per_page: 3 }) %}
					      <a class="err-search__card" href="{{ item.url }}">
					        <span class="err-search__media">{% if item.thumbnail.src %}<img loading="lazy" decoding="async" src="{{ item.thumbnail.src }}" alt="{{ item.thumbnail.alt }}">{% endif %}</span>
					        <span class="err-search__name">{{ item.title }}</span>
					      </a>
					      {% endfor %}
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="err-search">
					  <div class="err-search__inner">
					    <p class="err-search__label">Error 404</p>
					    <h1 class="err-search__title">We couldn’t find that page</h1>
					    <p class="err-search__text">Try searching for it, or start with one of our latest articles below.</p>
					    <form class="err-search__form" role="search" method="get" action="#">
					      <span class="err-search__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
					      <input class="err-search__input" type="search" name="s" placeholder="Search articles…" aria-label="Search articles">
					      <button class="err-search__submit" type="submit">Search</button>
					    </form>
					    <h2 class="err-search__sub">Latest articles</h2>
					    <div class="err-search__grid">
					      <a class="err-search__card" href="#">
					        <span class="err-search__media"><img decoding="async" src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></span>
					        <span class="err-search__name">How to write a project brief clients actually sign</span>
					      </a>
					      <a class="err-search__card" href="#">
					        <span class="err-search__media"><img decoding="async" src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></span>
					        <span class="err-search__name">The weekly client update, in five lines</span>
					      </a>
					      <a class="err-search__card" href="#">
					        <span class="err-search__media"><img decoding="async" src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7?w=800&amp;h=500&amp;q=80&amp;auto=format&amp;fit=crop" alt=""></span>
					        <span class="err-search__name">Moving from hourly billing to fixed-price projects</span>
					      </a>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.err-search {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 112px 24px;
					  background: #ffffff;
					}
					.err-search *,
					.err-search *::before,
					.err-search *::after {
					  box-sizing: border-box;
					}
					.err-search h1,
					.err-search h2,
					.err-search h3,
					.err-search p,
					.err-search a,
					.err-search button,
					.err-search input,
					.err-search time {
					  font-family: inherit;
					  text-transform: none;
					}
					.err-search h1,
					.err-search h2,
					.err-search h3 {
					  color: inherit;
					}

					.err-search__inner {
					  max-width: 960px;
					  margin: 0 auto;
					  text-align: center;
					}
					.err-search__label {
					  display: inline-flex;
					  align-items: center;
					  height: 28px;
					  margin: 0 0 18px;
					  padding: 0 12px;
					  border-radius: 999px;
					  background: var(--accent-soft);
					  color: var(--accent);
					  font-size: 13px;
					  font-weight: 600;
					  letter-spacing: 0.02em;
					}
					.err-search__title {
					  margin: 0;
					  font-size: clamp(32px, 4.4vw, 48px);
					  font-weight: 700;
					  line-height: 1.12;
					  letter-spacing: -0.03em;
					}
					.err-search__text {
					  max-width: 520px;
					  margin: 16px auto 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.err-search__form {
					  position: relative;
					  display: flex;
					  align-items: center;
					  gap: 8px;
					  max-width: 560px;
					  margin: 36px auto 0;
					  padding: 6px 6px 6px 46px;
					  border: 1px solid var(--line);
					  border-radius: 14px;
					  background: #ffffff;
					  box-shadow: 0 12px 32px -20px rgba(14, 17, 22, 0.35);
					}
					.err-search__form:focus-within {
					  border-color: var(--accent);
					}
					.err-search__icon {
					  position: absolute;
					  left: 16px;
					  display: inline-flex;
					  color: var(--muted);
					}
					.err-search__input {
					  flex: 1 1 auto;
					  min-width: 0;
					  height: 44px;
					  border: 0;
					  outline: 0;
					  background: transparent;
					  color: var(--ink);
					  font-size: 16px;
					}
					.err-search__submit {
					  flex: none;
					  height: 44px;
					  padding: 0 20px;
					  border: 0;
					  border-radius: 10px;
					  background: var(--ink);
					  color: #ffffff;
					  font-size: 15px;
					  font-weight: 600;
					  cursor: pointer;
					}
					.err-search__submit:hover {
					  background: #2a2f38;
					}
					.err-search__sub {
					  margin: 72px 0 24px;
					  color: var(--muted);
					  font-size: 14px;
					  font-weight: 600;
					  letter-spacing: 0.04em;
					  text-transform: uppercase;
					}
					.err-search__grid {
					  display: grid;
					  grid-template-columns: repeat(3, minmax(0, 1fr));
					  gap: 24px;
					  text-align: left;
					}
					.err-search__card {
					  display: flex;
					  flex-direction: column;
					  gap: 14px;
					  color: var(--ink);
					  text-decoration: none;
					}
					.err-search__media {
					  display: block;
					  overflow: hidden;
					  border-radius: 12px;
					  background: var(--soft);
					  aspect-ratio: 16 / 10;
					}
					.err-search__media img {
					  display: block;
					  width: 100%;
					  height: 100%;
					  object-fit: cover;
					  transition: transform 0.4s ease;
					}
					.err-search__card:hover .err-search__media img {
					  transform: scale(1.03);
					}
					.err-search__name {
					  font-size: 17px;
					  font-weight: 600;
					  line-height: 1.35;
					}
					.err-search__card:hover .err-search__name {
					  text-decoration: underline;
					  text-underline-offset: 3px;
					}
					@media (max-width: 767px) {
					  .err-search {
					    padding: 72px 20px;
					  }
					  .err-search__grid {
					    grid-template-columns: 1fr;
					  }
					  .err-search__submit {
					    padding: 0 14px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
				'error-404-split' => array(
					'name'     => __( '404 split with illustration', 'uichemy' ),
					'category' => __( '404', 'uichemy' ),
					'slot'     => 'error_404',
					'html'     => <<<'HTML'
					<section class="err-split">
					  <div class="err-split__inner">
					    <div class="err-split__copy">
					      <p class="err-split__label">Error 404</p>
					      <h1 class="err-split__title">Lost your way?</h1>
					      <p class="err-split__text">We looked everywhere, but this page isn’t here. It may have moved, or the address may be mistyped.</p>
					      <div class="err-split__actions">
					        <a class="err-split__btn err-split__btn--primary" href="{{ site.url ?? '/' }}">Back to home <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        <a class="err-split__btn" href="{{ site.url ?? '/' }}?s=">Search the site</a>
					      </div>
					    </div>
					    <div class="err-split__art">
					      <svg viewBox="0 0 320 320" fill="none" aria-hidden="true">
					          <circle cx="160" cy="160" r="150" fill="#eef2fe"/>
					          <circle cx="160" cy="160" r="104" fill="#ffffff" stroke="#d5defc" stroke-width="2"/>
					          <circle cx="160" cy="160" r="104" stroke="#2454e6" stroke-width="2" stroke-dasharray="4 10" opacity="0.45"/>
					          <path d="M160 72l26 88-26 88-26-88 26-88Z" fill="#2454e6" opacity="0.14"/>
					          <path d="M160 72l26 88h-52l26-88Z" fill="#2454e6"/>
					          <path d="M160 248l-26-88h52l-26 88Z" fill="#0e1116" opacity="0.82"/>
					          <circle cx="160" cy="160" r="10" fill="#ffffff" stroke="#0e1116" stroke-width="3"/>
					          <text x="160" y="44" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#2454e6">N</text>
					        </svg>
					    </div>
					  </div>
					</section>
					HTML
					,
					'preview_html' => <<<'HTML'
					<section class="err-split">
					  <div class="err-split__inner">
					    <div class="err-split__copy">
					      <p class="err-split__label">Error 404</p>
					      <h1 class="err-split__title">Lost your way?</h1>
					      <p class="err-split__text">We looked everywhere, but this page isn’t here. It may have moved, or the address may be mistyped.</p>
					      <div class="err-split__actions">
					        <a class="err-split__btn err-split__btn--primary" href="#">Back to home <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
					        <a class="err-split__btn" href="#">Search the site</a>
					      </div>
					    </div>
					    <div class="err-split__art">
					      <svg viewBox="0 0 320 320" fill="none" aria-hidden="true">
					          <circle cx="160" cy="160" r="150" fill="#eef2fe"/>
					          <circle cx="160" cy="160" r="104" fill="#ffffff" stroke="#d5defc" stroke-width="2"/>
					          <circle cx="160" cy="160" r="104" stroke="#2454e6" stroke-width="2" stroke-dasharray="4 10" opacity="0.45"/>
					          <path d="M160 72l26 88-26 88-26-88 26-88Z" fill="#2454e6" opacity="0.14"/>
					          <path d="M160 72l26 88h-52l26-88Z" fill="#2454e6"/>
					          <path d="M160 248l-26-88h52l-26 88Z" fill="#0e1116" opacity="0.82"/>
					          <circle cx="160" cy="160" r="10" fill="#ffffff" stroke="#0e1116" stroke-width="3"/>
					          <text x="160" y="44" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#2454e6">N</text>
					        </svg>
					    </div>
					  </div>
					</section>
					HTML
					,
					'css'      => <<<'CSS'
					.err-split {
					  --ink: #0e1116;
					  --text: #4b5260;
					  --muted: #6b7280;
					  --line: #e7e8ec;
					  --soft: #f6f7f9;
					  --accent: #2454e6;
					  --accent-soft: #eef2fe;
					  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
					  color: var(--ink);
					  -webkit-font-smoothing: antialiased;
					  padding: 104px 24px;
					  background: #ffffff;
					}
					.err-split *,
					.err-split *::before,
					.err-split *::after {
					  box-sizing: border-box;
					}
					.err-split h1,
					.err-split h2,
					.err-split h3,
					.err-split p,
					.err-split a,
					.err-split button,
					.err-split input,
					.err-split time {
					  font-family: inherit;
					  text-transform: none;
					}
					.err-split h1,
					.err-split h2,
					.err-split h3 {
					  color: inherit;
					}

					.err-split__inner {
					  display: grid;
					  grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
					  align-items: center;
					  gap: 64px;
					  max-width: 1120px;
					  margin: 0 auto;
					}
					.err-split__label {
					  margin: 0 0 16px;
					  color: var(--accent);
					  font-size: 14px;
					  font-weight: 700;
					  letter-spacing: 0.06em;
					  text-transform: uppercase;
					}
					.err-split__title {
					  margin: 0;
					  font-size: clamp(36px, 5vw, 60px);
					  font-weight: 700;
					  line-height: 1.05;
					  letter-spacing: -0.035em;
					}
					.err-split__text {
					  max-width: 480px;
					  margin: 20px 0 0;
					  color: var(--text);
					  font-size: 18px;
					  line-height: 1.6;
					}
					.err-split__actions {
					  display: flex;
					  flex-wrap: wrap;
					  gap: 12px;
					  margin-top: 36px;
					}
					.err-split__btn {
					  display: inline-flex;
					  align-items: center;
					  gap: 8px;
					  height: 48px;
					  padding: 0 22px;
					  border: 1px solid var(--line);
					  border-radius: 10px;
					  background: #ffffff;
					  color: var(--ink);
					  font-size: 16px;
					  font-weight: 600;
					  text-decoration: none;
					  transition: border-color 0.15s ease, background-color 0.15s ease;
					}
					.err-split__btn:hover {
					  border-color: #c9ccd3;
					  background: var(--soft);
					}
					.err-split__btn--primary {
					  border-color: var(--accent);
					  background: var(--accent);
					  color: #ffffff;
					}
					.err-split__btn--primary:hover {
					  border-color: #1d45c4;
					  background: #1d45c4;
					}
					.err-split__art svg {
					  display: block;
					  width: 100%;
					  max-width: 380px;
					  height: auto;
					  margin-left: auto;
					}
					@media (max-width: 860px) {
					  .err-split__inner {
					    grid-template-columns: 1fr;
					    gap: 40px;
					  }
					  .err-split__art {
					    order: -1;
					  }
					  .err-split__art svg {
					    max-width: 220px;
					    margin: 0;
					  }
					}
					@media (max-width: 767px) {
					  .err-split {
					    padding: 64px 20px;
					  }
					  .err-split__text {
					    font-size: 16px;
					  }
					}
					CSS
					,
					'js'       => '',
				),
			);
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function boot() {
			// Early priority: ours come first, and a later filter can still
			// reorder, extend or drop them.
			add_filter( 'uich_cb/library_sections', array( __CLASS__, 'sections' ), 5 );
		}

		/**
		 * Append the built-in sections to the library.
		 *
		 * @param array $sections Sections already offered.
		 * @return array
		 */
		public static function sections( $sections ) {
			$sections = is_array( $sections ) ? $sections : array();

			foreach ( self::manifest() as $slug => $meta ) {
				$html = $meta['html'];
				$css  = $meta['css'];
				$js   = $meta['js'];

				$entry = array(
					'id'       => 'uich-' . $slug,
					'name'     => $meta['name'],
					'category' => $meta['category'],
					'settings' => array(
						'raw_html' => $html,
						'raw_css'  => $css,
						'raw_js'   => $js,
					),
					// The shelf renders this in a sandboxed, script-less iframe,
					// so the preview is the markup and its CSS, unscoped — the
					// iframe is the scope.
					'preview'  => ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . ( isset( $meta['preview_html'] ) ? $meta['preview_html'] : $html ),
					// header | footer | single | archive | error_404, or '' for a section for any page.
					'slot'     => isset( $meta['slot'] ) ? $meta['slot'] : '',
				);
				$sections[] = $entry;

				// A Single section that works on any page too (a post list with
				// page numbers): offered to pages as well, under Blog.
				if ( ! empty( $meta['also_page'] ) ) {
					$sections[] = array_merge(
						$entry,
						array(
							'id'       => 'uich-' . $slug . '-page',
							'category' => __( 'Blog', 'uichemy' ),
							'slot'     => '',
						)
					);
				}
			}

			return $sections;
		}
	}
}
