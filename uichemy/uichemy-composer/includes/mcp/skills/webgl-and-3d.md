# WebGL and 3D

You were called because a section needs **WebGL** — a shader background, a 3D
object, a scroll-driven scene, a loaded model. three.js ships with UiChemy and
loads itself the moment a section's `js` mentions `THREE`. No CDN tag, no
enqueue, no import map.

There is one rule that matters more than everything else on this page:

> **Never build your own `WebGLRenderer`. Mount through `UiChemyThree.mount()`.**

A browser hands out somewhere between 8 and 16 WebGL contexts per page, total.
When it runs out it silently kills the oldest one — an earlier section on the
page just goes black, with no error anywhere. `renderer.dispose()` does **not**
give a context back; only `forceContextLoss()` does, and almost nobody
remembers to call it. The Composer also re-runs a section's JS on every edit, so
a section that makes its own renderer makes a **new one on every keystroke**.
`mount()` owns all of that for you.

---

## The shape of a WebGL section

```js
const uichemy_controller_bridge = {
  "spin":   { "value": 0.4, "label": "Spin speed", "min": 0, "max": 3, "step": 0.05 },
  "colour": { "value": "#22d3ee", "label": "Colour" }
};

(function () {
  'use strict';

  var root = document.querySelector('.uichemy-scene-1');
  if (!root) { return; }

  var host = root.querySelector('[data-gl]');

  /* three.js is enqueued as a dependency of this script, but a page-level tag
     can still land after it — so wait for the ready event either way. */
  function whenThree(cb) {
    if (window.UiChemyThree && typeof THREE !== 'undefined') { cb(); return; }
    window.addEventListener('uichemy:three-ready', cb, { once: true });
  }

  whenThree(function () {
    window.UiChemyThree.mount(host, function (ctx) {
      var T = ctx.THREE;

      var mesh = new T.Mesh(
        new T.IcosahedronGeometry(1, 12),
        new T.MeshBasicMaterial({ color: new T.Color(uichemy_controller_bridge.colour), wireframe: true })
      );
      ctx.scene.add(mesh);

      return {
        update: function (dt, t) { mesh.rotation.y = t * uichemy_controller_bridge.spin; },
        dispose: function () { mesh.geometry.dispose(); mesh.material.dispose(); }
      };
    }, { fov: 45, distance: 4 });
  });
})();
```

The host element must have a size of its own — `mount()` appends a canvas at
`position:absolute; inset:0` and measures the host, so give it an
`aspect-ratio`, a `height`, or `inset:0` inside a sized parent. A host with zero
height renders nothing and reports no error.

---

## `UiChemyThree.mount(host, setup, opts)`

`setup(ctx)` receives:

| key | what it is |
|---|---|
| `ctx.THREE` | the three namespace, **core plus add-ons** — the same object as `window.THREE` |
| `ctx.scene` | a `Scene`, empty |
| `ctx.camera` | a `PerspectiveCamera`, already aspect-correct and resized for you |
| `ctx.renderer` | the `WebGLRenderer`. Configure it; never replace it |
| `ctx.canvas` | the canvas element, already in the DOM |
| `ctx.size` | `{ width, height }`, live — it is mutated on resize |
| `ctx.root` | the host element |

`opts` — every one is optional:

`alpha` (default true) · `antialias` (default true) · `dpr` (cap, default 2) ·
`clearColor` + `clearAlpha` · `fov` (50) · `near` (0.1) · `far` (100) ·
`distance` (camera z, 3) · `autoRender` (set false only if you return `render`).

`setup` returns either a bare `update` function, or an object:

```js
return {
  update: function (dt, elapsed) { /* per frame, before the draw */ },
  render: function () { composer.render(); },   // replaces the default draw
  resize: function (size) { composer.setSize(size.width, size.height); },
  dispose: function () { /* your geometries, materials, listeners */ }
};
```

Return `render` **only** when you have a post-processing chain. The moment you
do, the default `renderer.render()` stops running — so if you return `render`
and forget to draw in it, the canvas stays blank.

`mount()` returns `{ scene, camera, renderer, canvas, dispose }`, or `null` if
the browser refused a context. It handles for you: pausing off screen, pausing
on a hidden tab, honouring `prefers-reduced-motion`, resizing with the host,
surviving a context loss, and disposing geometries, materials and textures on
teardown.

---

## Values belong in the motion block

A shader uniform is a plain `{ value }` object, which is exactly what GSAP
tweens and exactly what the Animation panel writes. **A uniform is not a special
case — declare it like any other value:**

```js
var uniforms = { uProgress: { value: 0 }, uScale: { value: uichemy_controller_bridge.scale } };
```

Read `uichemy_controller_bridge.<name>` wherever a literal would have gone. The panel does not care
that the number ends up in GLSL. The full contract — the JSON block, the naming
rules, `target`, `group`, control inference — is in the
**motion-and-animation** skill; read it before writing the block, everything
there applies here unchanged — including that the block must live in THIS
section's own `js`. The panel is built from the widget's stored JS, so a bridge
declaration moved into a code-file is never parsed and the controls vanish.

Declare what a designer would actually tune: displacement, speed, colours, bloom
strength, camera distance. Leave scaffolding (a `0` start, a geometry segment
count nobody will touch) as literals.

---

## Driving the scene from GSAP

GSAP and three are already friends — a uniform is a tweenable object:

```js
gsap.fromTo(uniforms.uProgress, { value: 0 }, {
  value: 1,
  ease: 'none',
  scrollTrigger: { trigger: root, start: 'top 75%', end: '+=1400', scrub: 1 }
});
```

**Use `fromTo`, not `from`.** A `.from()` tween reads its destination off the
element's current state, and any pinned ScrollTrigger anywhere on the page
forces a `ScrollTrigger.refresh()` — at which point `.from()` re-reads a
destination that is by then the already-animated value and strands the tween
animating `0 → 0`. This is the single most common way a correct-looking scene
ends up frozen.

If the mount happens after layout, call `ScrollTrigger.refresh()` once after
creating triggers inside `setup`.

Two inputs on one object — scroll and pointer, say — must not both tween the
same property. Tween two plain objects and add them in one writer:

```js
var spin = { y: 0 }, drag = { y: 0 };
var apply = function () { gsap.set(mesh.rotation, { y: spin.y + drag.y }); };
```

Tweening the mesh from both places means the pointer tween needs `overwrite` to
win, and overwriting kills the scrubbed tween outright — the scene stops
responding to scroll the moment the visitor first drags it.

---

## Add-ons you already have

On `ctx.THREE`, no import needed:

`OrbitControls` · `GLTFLoader` · `EffectComposer` · `RenderPass` · `ShaderPass` ·
`OutputPass` · `UnrealBloomPass` · `AfterimagePass` · `FilmPass` · `GlitchPass` ·
`CopyShader` · `FXAAShader`

DRACO and KTX2 decoders are deliberately **not** bundled — they are separate WASM
payloads only compressed assets need. `GLTFLoader` reads a plain `.glb`.

---

## Loading a model

`.glb` and `.gltf` upload through `media (request-upload)` like any other asset,
up to 64 MB. The upload verifies the bytes, not the name — a `.glb` must carry
the glTF magic header and a `.gltf` must be JSON with an `asset` member — so a
renamed file is rejected rather than stored. Use the returned URL.

Two things make an imported model look broken when it is merely unprepared:
every artist exports at a different scale (0.01 units tall, or 400), and the
origin is as often a corner or the floor as the middle. `fitToSize` settles both
before your own numbers apply.

```js
var loader = new T.GLTFLoader();
loader.load(uichemy_controller_bridge.model_url, function (gltf) {
  var model = gltf.scene;

  // Normalise first: whatever units it was exported in, it is now 2 units
  // across and centred on the origin.
  window.UiChemyThree.fitToSize(T, model, uichemy_controller_bridge.fit_size);

  // Then the author's placement, on top.
  window.UiChemyThree.transform(model, {
    x: uichemy_controller_bridge.pos_x, y: uichemy_controller_bridge.pos_y, z: uichemy_controller_bridge.pos_z,
    rotX: uichemy_controller_bridge.rot_x, rotY: uichemy_controller_bridge.rot_y, rotZ: uichemy_controller_bridge.rot_z,
    scale: uichemy_controller_bridge.scale
  });

  ctx.scene.add(model);
});
```

A model needs light. `MeshBasicMaterial` ignores lights, but a `.glb` arrives
with real materials — add at least an ambient and one directional light, or the
model renders black and reads as a failed load.

Loading is asynchronous and the section can be torn down before it finishes.
Guard the callback, or a re-render leaves a model attached to a dead scene:

```js
var alive = true;
loader.load(url, function (gltf) { if (alive) { ctx.scene.add(gltf.scene); } });
return { dispose: function () { alive = false; } };
```

### Placing an object from the panel

**Never write `mesh.rotation.y = uichemy_controller_bridge.rot_y` directly.** three.js rotates in
radians; a designer types degrees. A slider labelled "Rotate Y" running 0 to
6.28 is not a control anyone can use.

`UiChemyThree.transform(object, values)` takes **degrees** and applies radians.
Every key is optional and anything omitted is left untouched — which is what
lets you place the object once at setup and then drive one axis per frame
without the second call resetting the first:

```js
const uichemy_controller_bridge = {
  "pos_x": { "value": 0,   "unit": "px", "label": "X",        "group": "Placement", "min": -8, "max": 8, "step": 0.1 },
  "pos_y": { "value": 0,   "unit": "px", "label": "Y",        "group": "Placement", "min": -8, "max": 8, "step": 0.1 },
  "pos_z": { "value": 0,   "unit": "px", "label": "Z",        "group": "Placement", "min": -8, "max": 8, "step": 0.1 },
  "rot_x": { "value": 0,   "unit": "deg", "label": "Rotate X", "group": "Placement", "min": -180, "max": 180 },
  "rot_y": { "value": -25, "unit": "deg", "label": "Rotate Y", "group": "Placement", "min": -180, "max": 180 },
  "rot_z": { "value": 0,   "unit": "deg", "label": "Rotate Z", "group": "Placement", "min": -180, "max": 180 },
  "scale": { "value": 1,   "label": "Scale", "group": "Placement", "min": 0.1, "max": 4, "step": 0.05 },
  "spin":  { "value": 0.3, "label": "Spin speed", "group": "Motion", "min": 0, "max": 3, "step": 0.05 }
};
```

```js
var TF = window.UiChemyThree.transform;

TF(model, {
  x: uichemy_controller_bridge.pos_x, y: uichemy_controller_bridge.pos_y, z: uichemy_controller_bridge.pos_z,
  rotX: uichemy_controller_bridge.rot_x, rotY: uichemy_controller_bridge.rot_y, rotZ: uichemy_controller_bridge.rot_z,
  scale: uichemy_controller_bridge.scale
});

return {
  update: function (dt, t) {
    // Only the axis that animates; placement above survives untouched.
    TF(model, { rotY: uichemy_controller_bridge.rot_y + t * uichemy_controller_bridge.spin * 60 });
  }
};
```

Use these names — `pos_x`, `rot_y`, `scale` — for every placeable object. A
designer who has tuned one 3D section then finds the next one laid out the same
way, and the panel groups them together under **Placement**.

There is no click-and-drag gizmo on the canvas: a mesh is pixels, not a DOM
element, so the element picker cannot select it. The panel numbers are how a 3D
object is positioned.

### Post-processing

```js
var composer = new T.EffectComposer(ctx.renderer);
composer.addPass(new T.RenderPass(ctx.scene, ctx.camera));
composer.addPass(new T.UnrealBloomPass(
  new T.Vector2(ctx.size.width, ctx.size.height),
  uichemy_controller_bridge.bloom, uichemy_controller_bridge.bloom_radius, uichemy_controller_bridge.bloom_threshold
));
composer.addPass(new T.OutputPass());

return {
  render: function () { composer.render(); },
  resize: function (size) { composer.setSize(size.width, size.height); }
};
```

**`OutputPass` last, always.** It does the colour-space conversion the renderer
would otherwise have done itself. Leave it out and a post-processed scene looks
washed out and grey, and nothing tells you why.

Bloom is the easiest thing on this page to get wrong. A too-low threshold makes
every pixel bloom and the whole frame clips to white — a bright base colour plus
an added rim light reaches 1.0 on its own. Scale light by the base colour rather
than adding to it, and keep the threshold high enough that only crests glow.

---

## Traps

**Do**

- Give the host a size of its own, and a `background` so it is not a black hole
  before the first frame.
- Dispose in `dispose`: your geometries, materials, textures, and any listener
  you added to `window` or `document`.
- Frame the subject: at `distance` d and field of view f, the visible height is
  `2 * d * tan(f / 2)`. An object bigger than that is cropped, and cropping is
  what makes a scene look broken rather than merely wrong.
- Keep the triangle count honest. `IcosahedronGeometry(1, 48)` is hundreds of
  thousands of triangles for a shape a visitor reads as a ball.

**Do not**

- ❌ `new THREE.WebGLRenderer(...)` in a section. Read the top of this page again.
- ❌ `import` anything. Section JS runs inside `new Function(body)`, which is not
  a module scope — an `import` there is a syntax error that kills the section.
- ❌ Add a three.js `<script>` tag or an import map. It is already loaded.
- ❌ Write your own `requestAnimationFrame` loop. `update` is the loop, and it is
  what pauses off screen.
- ❌ Return `render` without drawing in it.
- ❌ Read pixels with `gl.readPixels()` to check your work. `preserveDrawingBuffer`
  is false, so it returns an empty buffer even when the scene is drawing
  perfectly. Look at the canvas instead.
- ❌ Assume `ctx.size` is a snapshot — it is the live object, mutated on resize.

**Accessibility and cost.** The lifecycle already honours
`prefers-reduced-motion` and pauses off screen; do not add your own checks. Never
put content that matters only inside the canvas — a WebGL scene is decoration,
and it must be safe for it to never appear. Mark the host `aria-hidden="true"`
unless the scene genuinely conveys information, in which case describe it in
text next to it.

**Text over the canvas must stay selectable.** This has broken twice, the same
way both times, and it is invisible until someone tries to click a headline in
the editor and nothing happens.

A WebGL section usually layers copy over a pinned canvas, and the canvas must not
swallow the visitor's scroll or drag — so the overlay gets `pointer-events: none`.
That is correct. The mistake is stopping there:

- The **canvas and its host** take `pointer-events: none`. Good.
- The **copy container** takes `pointer-events: none` so scroll passes through.
  Also fine — but then every text element inside it is unhittable too, and the
  element picker works off `document.elementFromPoint`, so it cannot select what
  the browser will not report.
- Any **decorative full-bleed layer** (a cursor-trail div, a gradient scrim, a
  grain overlay) left at the default `pointer-events: auto` covers the whole
  section and eats every hit, even from elements that opted back in.

So: give the copy container `pointer-events: none`, then put
`pointer-events: auto` back on the text that should be selectable, and make every
decorative `inset: 0` layer `pointer-events: none`. A decorative layer needs no
hit-testing — if it only exists to carry a `cursor`, move that to the section
root, which is hittable anyway.

Verify it rather than assuming, in the browser:

```js
var el = document.querySelector('.your-section__title');
var r  = el.getBoundingClientRect();
var hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
el.contains(hit);   // must be true, or the picker cannot select it
```

If that is false, `hit` names the element doing the blocking.

**One scene per section.** If a page needs several, that is several sections,
each mounting once — not several renderers in one.
