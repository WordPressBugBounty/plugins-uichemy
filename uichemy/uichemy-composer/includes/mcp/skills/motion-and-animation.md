# Motion and Animation

You were called because a section needs **animation** — a hero that rises in, cards that stagger, an element that scrubs to the scroll position. Animation in UiChemy is written as **ordinary GSAP JavaScript** in the section's `js`. There is no fixed animation schema and no list of supported effects: the whole GSAP API is yours — timelines, `ScrollTrigger`, `MotionPathPlugin`, `DrawSVGPlugin`, custom eases, keyframes, callbacks.

There is exactly one extra contract, and it exists so a **designer can tune your animation without touching code**: every value a human might want to change is declared as a **motion variable** instead of being typed inline as a literal.

Get this contract right and the Composer builds a live control panel from your JS by itself — a slider for each duration, an ease picker for each ease, only for the element the designer has selected. Get it wrong and your animation still runs, but it is a dead black box nobody can adjust.

---

## The contract in one screen

```js
const uichemy_controller_bridge = {
  "title_y":    { "value": 80,    "unit": "px", "label": "Title rise",  "target": ".hero-title" },
  "title_dur":  { "value": 1.2,   "unit": "s",  "label": "Duration",    "target": ".hero-title" },
  "title_ease": { "value": "power3.out",        "label": "Ease",        "target": ".hero-title" },
  "card_stag":  { "value": 0.12,  "unit": "s",  "label": "Card stagger","target": ".card" }
};

gsap.from('.hero-title', {
  y:        uichemy_controller_bridge.title_y,
  duration: uichemy_controller_bridge.title_dur,
  ease:     uichemy_controller_bridge.title_ease,
});

gsap.from('.card', { opacity: 0, stagger: uichemy_controller_bridge.card_stag });
```

Two parts, both required:

1. **A declaration block** — a `const uichemy_controller_bridge = { … }` declaration holding one JSON object. It is the **first thing** in the `js`, before any code.
2. **Reads in the code** — `uichemy_controller_bridge.<name>` wherever that value is used.

`uichemy_controller_bridge.<name>` is ordinary JavaScript — a property read on an object the runtime provides. That matters: your `js` stays valid JavaScript at rest, so the code formatter, syntax highlighting and linting all work on it. **Never** invent a template-style placeholder (`{{ … }}`, `%name%`, `$name`): those are not valid JS, the editor's formatter shreds them into loose braces, and the section then ships a syntax error that kills the whole script.

Everything else is plain GSAP. Write it exactly as you normally would.

---

## Part A — The declaration block

### Shape

```js
const uichemy_controller_bridge = {
  "card_stagger": { "value": 0.12, "unit": "s", "label": "Card stagger", "target": ".card" }
};
```

One entry per variable: the **name** is the key, and its object holds `value` plus any optional metadata.

- The statement is `const uichemy_controller_bridge = { … };` holding **one JSON object**.
- **It only works in a section's own `js`.** The Composer parses the block out of the widget's
  stored JS to build the panel, so a declaration that lives anywhere else is never seen: not in a
  `uichemy-composer/code-file`, not in `page_before_body`, not in site-wide code. Move animation
  into a shared file and the controls silently disappear while the animation keeps running — so
  code that carries motion variables stays inline, even when it repeats across sections.
- **One declaration per section.** Never two.
- It must be **valid JSON** — double quotes, no trailing commas, no comments inside. It is parsed with a JSON parser, not by pattern-matching, and a malformed block means the section gets **no** controls.
- Names are `snake_case`, `[a-z][a-z0-9_]*`. Make them read like what they do: `card_stagger`, not `v1`.

### `value` is the only required key

`"value"` is the animation's default — the number you would otherwise have typed inline. Everything else is optional; sensible defaults are inferred.

### Optional metadata

| Key | Meaning | When to write it |
|---|---|---|
| `label` | What the designer sees | Whenever the name alone is not obviously readable |
| `target` | CSS selector this variable animates | **Almost always** — see Part C |
| `unit` | `px` · `s` · `ms` · `%` · `deg` · `em` · `rem` | Any number that has a unit |
| `min` / `max` / `step` | Slider range | Numbers where a sane range is obvious |
| `type` | Force a control (see the table below) | Only when inference would guess wrong |
| `group` | Panel section heading | When a section has many variables |
| `options` | `["a","b","c"]`, forces a dropdown | Fixed choice sets |
| `help` | One-line tooltip | Non-obvious values |

### Types are inferred from `value`

Do not write `type` unless you must — the control is chosen from the default:

| `value` looks like | Control the designer gets |
|---|---|
| `1.2`, `80` | number field with drag handle (slider when `min`+`max` given) |
| `"power3.out"`, `"expo.inOut"` | ease picker |
| `"#ff0055"`, `"rgba(0,0,0,.4)"` | colour picker |
| `"top 80%"`, `"center bottom"` | ScrollTrigger position field |
| `true` / `false` | toggle |
| `[0, 0.5, 1]` | list editor |
| any other string | text field |

Force one with `"type"` when inference cannot know, e.g. a plain string that is really a selector: `"type": "text"`.

**A type we did not anticipate is never a failure.** An unrecognised value falls back to a text field, so a brand-new GSAP feature is editable on day one. Never avoid a GSAP capability because you think the panel will not understand it.

---

## Part B — Reading a value in the code

Write `uichemy_controller_bridge.<name>` exactly where the literal would have gone:

```js
duration: uichemy_controller_bridge.title_dur          // ✅
duration: 1.2                      // ❌ dead value, no control
```

**Rules:**

- `uichemy_controller_bridge` is **reserved** inside a section's `js`. Do not declare your own variable called `uichemy_controller_bridge` — the runtime binds it, and yours would be shadowed.
- A read stands for a **whole value**, never part of one. `y: uichemy_controller_bridge.title_y` ✅ — `y: uichemy_controller_bridge.title_y + "px"` ❌ (put the unit in the declaration's `unit` instead). If you need `"top 80%"`, declare that entire string as one variable and use the `scroll` control:
  ```js
  "start_at": { "value": "top 80%", "target": ".hero" }
  …
  scrollTrigger: { trigger: '.hero', start: uichemy_controller_bridge.start_at }
  ```
- Never put a read inside a string literal — `"uichemy_controller_bridge.x"` is the text, not the value.
- A variable may be read repeatedly — the same one used in three tweens gives the designer a single control driving all three. This is good; do it deliberately for things like a shared ease.
- Every read must have a declaration, and every declaration should be read. An undeclared read is `undefined`; an unused declaration shows a control that does nothing.

---

## Part C — `target`: why the designer sees the right options

The Composer's panel is **contextual**. When a designer clicks the hero title, they must see the hero title's animation options — not all 14 variables in the section.

`target` is what makes that work. It is the CSS selector the variable animates, normally the same selector as the `gsap` call it feeds:

```js
gsap.from('.card', { stagger: uichemy_controller_bridge.card_stag });
//         ^^^^^^                    "target": ".card"
```

- Write `target` for every variable that belongs to a specific element.
- Omit it only for variables that genuinely govern the whole section (a master timeline speed, a global ease). Those show under the section itself.
- Use the **same selector string** as the `gsap` call. Do not invent a different-but-equivalent one.

---

## Part D — What good output looks like

### Hero — one element, load animation

```js
const uichemy_controller_bridge = {
  "rise":     { "value": 60,  "unit": "px", "label": "Rise distance", "target": ".hero-copy", "min": 0, "max": 200 },
  "duration": { "value": 0.9, "unit": "s",  "label": "Duration",      "target": ".hero-copy", "min": 0.1, "max": 3, "step": 0.05 },
  "ease":     { "value": "power3.out",      "label": "Ease",          "target": ".hero-copy" }
};

gsap.from('.hero-copy > *', {
  y:        uichemy_controller_bridge.rise,
  opacity:  0,
  duration: uichemy_controller_bridge.duration,
  ease:     uichemy_controller_bridge.ease,
  stagger:  0.08,
});
```

### Cards — stagger on scroll

```js
const uichemy_controller_bridge = {
  "start_at": { "value": "top 80%", "label": "Starts at",    "target": ".card", "help": "Where in the viewport the reveal begins" },
  "stagger":  { "value": 0.12, "unit": "s", "label": "Stagger", "target": ".card", "min": 0, "max": 0.6, "step": 0.01 },
  "rise":     { "value": 40,   "unit": "px", "label": "Rise",   "target": ".card", "min": 0, "max": 160 },
  "ease":     { "value": "power2.out", "label": "Ease", "target": ".card" }
};

gsap.from('.card', {
  y:       uichemy_controller_bridge.rise,
  opacity: 0,
  stagger: uichemy_controller_bridge.stagger,
  ease:    uichemy_controller_bridge.ease,
  scrollTrigger: { trigger: '.cards', start: uichemy_controller_bridge.start_at },
});
```

### Scroll-scrubbed timeline — full GSAP, still fully tunable

```js
const uichemy_controller_bridge = {
  "scrub":     { "value": 1,   "unit": "s", "label": "Smoothing",  "group": "Scroll", "min": 0, "max": 3, "step": 0.1 },
  "distance":  { "value": 600, "unit": "px","label": "Scroll length","group": "Scroll", "min": 100, "max": 2000 },
  "pin":       { "value": true,             "label": "Pin section", "group": "Scroll" },
  "img_scale": { "value": 1.25,             "label": "Image zoom",  "target": ".panel-img", "min": 1, "max": 2, "step": 0.05 },
  "text_x":    { "value": -120,"unit": "px","label": "Text drift",  "target": ".panel-text" }
};

const tl = gsap.timeline({
  scrollTrigger: {
    trigger: '.panel',
    start:   'top top',
    end:     '+=' + uichemy_controller_bridge.distance,
    scrub:   uichemy_controller_bridge.scrub,
    pin:     uichemy_controller_bridge.pin,
  },
});

tl.to('.panel-img',  { scale: uichemy_controller_bridge.img_scale, ease: 'none' }, 0)
  .to('.panel-text', { x:     uichemy_controller_bridge.text_x,    ease: 'none' }, 0);
```

Note the third example: a pinned, scrubbed, multi-track timeline — nothing a fixed animation schema could express — and every knob a designer would reach for is still a control in the panel.

---

## Part E — Rules and traps

**Do**

- Declare **only what a human would actually tune.** A `y: 0` end state or an `opacity: 0` start is structural — leave it a literal. Roughly 3–6 variables per animated element is right; 20 controls on one card is noise.
- Give numbers a `unit`, and a `min`/`max` when an obvious sane range exists. That turns a bare field into a slider.
- Reuse one `ease` variable across the section's tweens when the motion should feel unified.
- Use `group` once a section passes ~8 variables.
- Scope your selectors to the section's own wrapper class. Two copies of the same section on one page each run their own JS, and each gets its own values.

**Do not**

- ❌ Do not write a second `uichemy_controller_bridge` block.
- ❌ Do not move a block, or the code reading it, into a code-file or page/site code. The parser
  only reads section JS; the panel goes empty and nothing reports it.
- ❌ Do not use a template placeholder (`{{ … }}`, `%x%`) in place of `uichemy_controller_bridge.x` — it is not valid JS and the formatter destroys it.
- ❌ Do not put a read inside a string: `"uichemy_controller_bridge.x"` is text, not a value.
- ❌ Do not declare your own variable named `uichemy_controller_bridge`.
- ❌ Do not declare a variable you never read, or read one you never declared.
- ❌ Do not hand-roll `requestAnimationFrame` scroll maths when `ScrollTrigger` is loaded and does it better.
- ❌ Do not add a GSAP `<script>` tag or a CDN dependency. GSAP core, ScrollTrigger, MotionPathPlugin and DrawSVGPlugin are enqueued automatically the moment your `js` mentions `gsap` or `ScrollTrigger`.
- ❌ Do not restyle or restructure the markup to suit an animation the user did not ask for.

**Accessibility** — the Composer honours `prefers-reduced-motion` for you. Do not add your own check, and do not build an animation that hides content permanently if it never runs: animate **from** a hidden state (`gsap.from`) rather than setting `opacity: 0` in CSS and animating to visible. If the script never executes, the content must still be readable.

---

## Part F — Editing an existing section

When asked to change an animation in a section that already has a motion block:

1. Read the current `js` first. Never overwrite a block you have not read — a designer's tuned values live in it.
2. Changing a **default**: edit that variable's `"value"`.
3. Adding a knob: add the declaration **and** replace the literal in the code with `uichemy_controller_bridge.<name>`.
4. Removing an element's animation: delete its tweens **and** its now-unused declarations.
5. Renaming a variable: update the declaration and every `uichemy_controller_bridge.<name>` read in one pass. A half-rename leaves the animation reading `undefined`.

If the section has animation but **no** motion block (older or hand-written code), you may convert it: pull each tunable literal into a declaration and swap in `uichemy_controller_bridge.<name>` reads. Keep the behaviour identical — same numbers, same result — this is a refactor, not a redesign.
