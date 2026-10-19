# Custom fields, post types and taxonomies

Read this before creating a content type or writing a custom-field value on a
WordPress site through UiChemy.

Two abilities, and the split is the first thing to get right:

| | |
|---|---|
| **`uichemy-composer/cpt`** | the SHAPE - post types, taxonomies, field groups, field definitions |
| **`uichemy-composer/custom-fields`** | the VALUES in those fields, on one object or many |

Both work across three **providers** - whoever owns the model:

| provider | what it is |
|---|---|
| `uichemy` | **UiChemy Custom Fields**, built into UiChemy (dashboard > Custom Fields). Present unless the user switched it off under Settings > Features. |
| `acf` | Advanced Custom Fields, free or Pro |
| `jetengine` | JetEngine |

Plain registered meta also shows up as provider `meta` for values, never for
modelling.

Everything about how any of it *looks* is elsewhere: `uichemy-composer/dynamic`
for the tokens, `uichemy-composer/page` and `uichemy-composer/theme-builder`
for the layout.

**Free or Pro - check before you promise anything.** On a site without UiChemy
Pro, reading is free (`cpt describe`, `custom-fields list` / `get`), but every
model write (`register-*`, `add-fields`, `update-field`) and every value write
is refused with a message naming the upgrade. Printing is gated too:
`{{ post.meta('...') }}` renders **empty** on Free and `dynamic` `list-fields`
lists no custom fields, so a binding built on Free looks finished and shows
nothing. Tell the user before you design around custom fields.

---

## The five rules

Every failure this document exists to prevent is silent. Nothing errors, nothing
logs, and the read-back looks right. Hold these five in mind and almost all of
them become impossible.

### 1. Discover before you bind

Never guess a field, post type, taxonomy or term name. An unknown name renders
**empty** rather than failing, so a guess produces a page that looks built and
is blank - with nothing in the output to say so.

```
uichemy-composer/cpt            action="describe"     # what exists, and who owns it
uichemy-composer/custom-fields  action="list"         # the fields on one object type
```

On the write side the same guess is **refused**, with the list of real names.
That is deliberate: a generic `update_post_meta()` fallback would store the
value, return success, and leave the page it was meant for blank forever.

**A taxonomy is not usable until it has terms, and registering one does not
create any.** This is the trap that costs the most, because it is discovered
last: three independent builds each modelled a taxonomy, added a taxonomy field
for it, wrote content around it, and only then found there were no terms to
assign. Terms are created and assigned here:

```
uichemy-composer/cpt  action="ensure-term"   { taxonomy, terms: ["Residential"] }
uichemy-composer/cpt  action="set-terms"     { post_id, taxonomy, terms: ["residential"] }
```

Do both in the same session as `register-taxonomy`, before anything depends on
it. An empty taxonomy is silent everywhere - an empty archive, an empty filter,
a term field with no options, a `{% for %}` that never runs.

**And ask whether you need a taxonomy at all.** If the values are a fixed list
nobody will ever browse or filter by - a status, a tier, a size - a `select`
field is the simpler model and needs no terms. Choosing this *after* the content
is written is expensive: the fallback at that point is a parallel select field
driving the front end off a shadow copy, leaving the real taxonomy decorative.

### 2. Token or tag - decide by what it varies with

A `{{ }}` token resolves once, server-side, at render. Anything that varies by
viewer, session, request or locale must be a placeholder element instead. Under
page caching a token serves one visitor's data to everyone.

### 3. Never write back what you rendered

`action="get"` returns **both** `value` and `formatted`. Send `value` back.

Read output is formatted; storage is not. Write a rendered ACF date back and 7
September becomes today - and the corrupted value reads back looking like a
date, so verification passes.

### 4. Verify shape, not echo

A value that reads back is not necessarily stored correctly. UiChemy asserts the
shape the owning plugin expects after every write and reports `problems` when it
does not match. **A response with `problems` is not a success** - the values are
in storage and the shape is wrong.

### 5. A repeater write replaces every row

To append: `get` the field, add your row to the array you were handed, send the
whole set. Sending one row deletes the others, silently.

---

## Who owns what - choosing the provider

Every model write goes through exactly one provider, and what it creates is
edited in that provider's own screens afterwards. Read `cpt` action="describe"
first: `providers` says what is active, `owner` on each post type and taxonomy
says who made it, and `provider` on each field group says where the fields live.

```
one provider active    => "provider" optional, writes go there
two or more active     => every register-* / add-fields / update-field REFUSES
                          until you pass "provider". ASK THE USER.
none active            => REFUSED, naming the ways forward
```

**When more than one is active, ask - and inform the question.** Do not pick. A
model split across plugins ends up half in each one's admin screens, and moving
it later means rewriting every template that binds it. Tell the user where the
existing model already lives: *"Your Case Studies are in JetEngine and your
Movies are in ACF. Should the new Books type go in UiChemy Custom Fields, ACF or
JetEngine?"* Extending an existing type or group belongs with its owner -
`add-fields` to an ACF group is `provider="acf"`.

**Starting fresh with nothing else installed**, UiChemy Custom Fields is the only
provider and is used automatically. It needs no extra plugin, and its fields are
edited in the same dashboard as the rest of UiChemy.

**When none is active, say so plainly** - *"UiChemy Custom Fields is switched off
on this site and there is no ACF or JetEngine. You can switch it back on under
UiChemy > Settings > Features, install ACF or JetEngine, or I can model this
with plain posts, categories and tags, which I can bind and lay out today."*
Never switch the feature on yourself: a user who turned it off usually did so
because another plugin owns their content model. Never report an empty field
list as though the site had no data.

**A field name in two providers is a write you have to pin.** `list` flags it as
`also_claimed_by`, and `update` / `delete` on that name refuse until you pass
`provider`. The two are different storage rows; the one you did not write keeps
rendering wherever it is bound.

---

## UiChemy Custom Fields - what differs

Same calls and the same five rules. These are the differences that bite:

- **Values live under internal keys**, not the field name. `update_post_meta( $id,
  'subtitle', ... )` writes a row nothing reads. Only `custom-fields` `update`
  writes these fields; `{{ post.meta('subtitle') }}` reads them like any other.
- **Conditional logic hides values.** A field whose condition is not met renders
  EMPTY and reads back with `formatted: null` while `value` is still stored. Set
  the field it depends on first; the definition says `"conditional": true`.
- **Dates are stored in the field's return format** (`d/m/Y` by default for a
  group built in the dashboard; ISO for one built through `cpt`). Send ISO on a
  write. Echoing `value` back is also safe - it is parsed in the field's own
  format, not guessed.
- **No options pages and no gallery type.** Put site-wide values on a dedicated
  page or a single "settings" post. Model a gallery as a `repeater` with one
  `image` sub-field.
- **One name, one field.** If two native groups both reach an object with the
  same field name, the first group wins on every read and write - `describe`
  shows the groups; pick names that do not collide.
- **Registration is on the next request**, like JetEngine. Create the post type,
  then make the field group in a separate call.
- **Free vs Pro.** Writing through these abilities needs UiChemy Pro (see the
  note at the top). In the dashboard every field type is free except `repeater`
  and `relationship`; on an unlicensed site those two read back empty and refuse
  writes, and the stored value survives until Pro is active.
- **Changing identity** - renaming or retyping a field, deleting a group, trashing
  a post type - is done by the user in UiChemy > Custom Fields, which shows what
  will break and keeps a Trash to restore from.

---

## ACF free vs ACF Pro

`repeater`, `flexible_content`, `gallery` and `clone` **do not exist as field
types in ACF free.**

ACF does not refuse them. It creates the field and treats its value as a scalar,
storing one serialised array. That reads back intact - so a write passes, a read
passes, and a preview passes. **ACF Pro reads that same key as a row-count
integer** and looks for `name_0_*` rows, so it sees one empty row.

UiChemy refuses those types on a free install rather than creating the trap.
`cpt` action="describe" reports the edition under `providers`, so check it before
promising a repeater. On ACF free, model repeating data as a separate post type
with a relation.

The refusal comes **after** `register-field-group` has created the group: the
error says the group "WAS created" and no fields were added. Do not create a
second group - fill that one with `add-fields` using supported types.

---

## Storage differs between the providers

Same concept, different bytes. This is why a field must be written through its
own owner, and why UiChemy's adapters share no code.

| | UiChemy Custom Fields | ACF | JetEngine |
|---|---|---|---|
| where | internal key per group + field | the field name + a `_name` key row | the field name |
| boolean | `"1"` / `""` | `"1"` / `"0"` | `'true'` / `''` |
| repeater | one array of rows | flat `name_0_sub` rows + a count | one serialised array |
| checkbox | a list of values | a list of values | a `{ value: true }` map |
| media | attachment ID | attachment ID | ID **or** URL, per field |
| date | the field's return format | `Ymd` string | Unix timestamp |
| relations | post / term / user IDs | plain meta | **its own database tables** |

You never write these by hand: send the neutral value (`true`, `"2026-09-07"`,
an attachment id, an array of rows) and the owning provider stores its own form.

**ACF has a second row per value.** `price` holds the value and `_price` holds
the field key. `update_post_meta()` writes the first and not the second, so the
value renders on the front end, shows **blank in the editor**, and is erased by
the next human save. UiChemy writes through `update_field()` **by name** - never
by key, which stores repeater rows under a prefix the reader never looks at.

**JetEngine relations are not meta at all.** A field write aimed at one goes
nowhere and returns success. Use `get-relations` / `update-relations`, which are
present only when a relation-capable provider is active.

---

## The order things have to happen in

```
 1  cpt   describe                 # who owns modelling here; which edition
 2  cpt   register-post-type       # slug <= 20 chars, lowercase, not reserved
 3  cpt   register-taxonomy        # object_types must already exist
 4  cpt   ensure-term              # a taxonomy with no terms is invisible everywhere
 5  cpt   register-field-group     # the location rule is what makes fields reachable
 6  cpt   add-fields               # or pass "fields" to step 5 and skip this
 7  post  create                   # the content (title, body, sections)
 8  custom-fields update           # the values
 9  cpt   set-terms                # put the terms on each post
10  post  update                   # status="publish" - a draft is invisible to every loop
11  dynamic + theme-builder        # print it, lay it out
```

Four silent traps in that sequence:

- **A taxonomy with no terms** (step 4) renders as an empty archive and an empty
  filter, and never errors.
- **Content left as a draft** (step 10) is skipped by `get_posts()`, which
  defaults to `post_status: publish` - so every listing you build returns zero
  rows and every URL 404s while each tool call reports success.

- **A post type with no field group has no custom fields.** Step 4 is not
  optional.
- **A field group with no location rule shows nowhere**, so its fields can never
  be filled in. `register-field-group` requires `object_subtype` for exactly
  this reason.

**UiChemy Custom Fields and JetEngine register a post type or taxonomy on the
*next* request.** Anything that has to *see* it - a loop, a template condition -
belongs in a separate call. ACF registers immediately. (A field group for a
UiChemy Custom Fields type it has just defined is accepted straight away.)

---

## Call shapes

Every call is `{ action, action_parameters }`. Use these names exactly. On
`cpt`, a parameter the action does not define is refused with the list of valid
ones and nothing is changed; on `custom-fields`, a missing required name is
refused and named. `get-ability-info` has the full schema, with types and enums.

```
cpt  register-post-type    { slug, label (plural), singular_label, supports, has_archive,
                             hierarchical, public, rewrite_slug, menu_icon, taxonomies, provider }
cpt  register-taxonomy     { slug, label, singular_label, object_types: ["book"],
                             hierarchical, rewrite_slug, provider }
cpt  ensure-term           { taxonomy, terms: ["Residential", "Commercial"] }
cpt  set-terms             { post_id, taxonomy, terms: ["residential"], append }
cpt  register-field-group  { title, object_type: "post", object_subtype: "book", fields: [...], provider }
cpt  add-fields            { group_key, fields: [...], provider }
cpt  update-field          { name, group_key, label, instructions, placeholder, required, default, choices }

one field   { name: "price", label, type, choices (select/radio/checkbox),
              sub_fields: [ { name, type } ] (repeater) }
field types text textarea wysiwyg number email url boolean select radio checkbox
            image file gallery date datetime time color link post_relation
            taxonomy repeater

custom-fields list    { object_type: "post", object_subtype: "book" }
custom-fields get     { object_id, names }
custom-fields update  { object_id | object_ids: [...], fields: { price: 49 }, dry_run }
custom-fields delete  { object_id, names }
custom-fields revert  { revert_token }
```

Names that are easy to get wrong:

- always pass `menu_icon` with a Dashicon that matches what the type holds (`dashicons-groups` for team, `dashicons-calendar-alt` for events, `dashicons-editor-help` for FAQs). Never leave the default pushpin;
- the plural label is `label`, not `plural` or `name`;
- values go in `fields`, not `values`;
- the object is `object_id`, not `post_id` (only `set-terms` takes `post_id`);
- a select value is the choice **value** (`online`), not its label (`Online`).

**A post type is never reconfigured here.** Registering an existing slug
again is refused, so labels, supports and archive settings are fixed at
creation. Get them right in the first call; afterwards the user changes them in
the provider's own screen.

---

## What is refused, and why the refusal is the useful part

`cpt` makes **additive** changes only. Allowed:

- create a type, create a group, add a field
- change a field's label, instructions, required, choices or default

Refused, and each refusal names what to do instead:

- **rename a field** - every template binds by *name*. The key survives a rename
  and the name does not, so every binding silently blanks while the page still
  looks built. Add a new field, copy the values across, retire the old one by
  hand.
- **change a field's type** - the storage shape changes and existing data may
  become unreadable.
- **delete a field** - the values are orphaned invisibly: still in the database,
  unreachable.
- **change or delete a post type** - breaks permalinks and every stored
  reference, or orphans all its content.

Identity-changing work goes through the owning provider's own screen (UiChemy >
Custom Fields, ACF's or JetEngine's), where
the user is shown what they are about to break. Every refusal here names what to
do instead - pass that on rather than working around it.

`custom-fields` refuses these keys, and each refusal names the right door:

- protected meta, and anything starting with `_`
- `_elementor_data` and UiChemy's own template meta - a write there erases a
  page, silently
- `_price`, `_stock`, `total_sales` and friends - WooCommerce derived state,
  computed and mirrored into its own lookup table. Use
  `uichemy-composer/store`.
- `_thumbnail_id` - the featured image is protected meta and is not written
  through the field layer. Set it with `uichemy-composer/post` (or `page`)
  `action="update"`, `featured_image: <attachment id>`; on a WooCommerce product
  use `uichemy-composer/store` (`action="set-images"`). An ACF or JetEngine
  **image field** is a different thing and is writable here.

---

## Working practices

**Dry-run anything non-trivial.** `dry_run: true` resolves, coerces and
validates every field and writes nothing, reporting exactly what would land in
storage. It is the only way to see a date conversion or a choice validation
before it happens.

**Batch with `object_ids`.** Seeding twenty populated posts is one call, not
twenty. A per-object failure does not abort the rest.

**Keep the revert token.** Every `update` and `delete` that changed something
returns one. `action="revert"` restores exactly those fields for 24 hours.

**Pass `provider` when a name collides.** When two providers define a name,
`list` flags `also_claimed_by` and a write refuses until you pin one. Pin the one
the template actually reads through.

**Choices are not optional on a select.** A select without them accepts anything
and renders an empty label, and a caller writing a value has no way to know what
is allowed. `register-field-group` refuses a choice-type field with no choices.

**Media takes an attachment ID.** A remote URL is refused - upload it with
`uichemy-composer/media` first, then pass the id. A URL already in the library
is resolved for you.

**Dates go in as ISO.** `"2026-09-07"`, or `"2026-09-07 14:30:00"`. Ambiguous
forms like `07/09/2026` are refused rather than guessed, because a guess
produces a plausible wrong date that passes every check afterwards. (The one
exception is a UiChemy Custom Fields date already in its field's own return
format - the `value` a read handed you - which is parsed in that format.)

**Print HTML fields through `|kses_post`, never `|raw`.** `raw` marks output
unescaped, so a `<script>` in user-supplied meta executes. Use `raw` only for
values the engine itself produced.

---

## Where this skill ends

This document covers the SHAPE of the data and the VALUES in it. Printing those
values is `dynamic-loops-and-forms`, and two things fall in the gap between the
two documents. Both have shipped broken pages.

**`{{ post.excerpt }}` is not the post's visible summary.** It reads the
`post_excerpt` column, which is EMPTY on any post whose text lives in a Composer
widget - so it renders blank on a page that plainly has text on it, and it looks
like a broken binding. This is a direct consequence of choosing `post_content`
for a description rather than a custom field, and nothing in `cpt describe`
steers you either way. Three options, in order of preference:

- hold the summary in a **custom field** and print that - the choice this skill
  would make;
- set a real excerpt with `uichemy-composer/post` `action="update"`,
  `excerpt: "..."`;
- print `{{ post.content }}` and accept the full body.

**Never write `{{ }}` inside a query or a `{% %}` tag.** In `raw_query`,
`{% set %}` and `{% for %}` you write the bare expression - `post.id`,
`term.slug` - because `{{ }}` is print syntax and is only valid in output
position. Used in an expression it fails **silently**: the query runs with the
literal string where the value should be and returns the wrong rows. See
`dynamic-loops-and-forms`.

**A write confirmation is not a render check.** `update` reports what is in
storage, and now also what the token resolves to today (`renders_as`). Neither
is proof the page is right - the template, the conditions and the layout are not
exercised by that call. Look at the page.

---

## Declared unsupported

Say so rather than working around it. A documented "no" is handled well; a
silent empty string is not.

| Not supported | Why |
|---|---|
| **ACF Flexible Content writes** | Polymorphic - each row's shape depends on its layout name, so it needs per-layout partials the render engine does not have. Readable, not writable. |
| **Creating an options page** | It needs a menu slug and a capability: site structure, not content model. Make one in ACF's or JetEngine's screens, then its fields are readable and writable with `object_type="options"`. UiChemy Custom Fields has no options pages at all. |
| **Creating a relation** | A relation defines the content model and its two sides have to be chosen deliberately. Create it in JetEngine > Relations; UiChemy changes *membership* only. |
| **JetEngine CCT** | Items live in `wp_jet_cct_*`, not posts. Every provider is post/term/user-shaped and `WP_Query` cannot see them. |
| **Nested repeaters** | Buildable in the plugin's own screen if genuinely needed. |
| **Multilingual field values (WPML / Polylang)** | A token bound to a post or term ID is monolingual by construction. |

Terms and featured images used to be on that list. They are not any more:
`cpt` `ensure-term` / `set-terms` for terms on any registered taxonomy, and
`post`/`page` `action="update"` with `featured_image` for a thumbnail on a plain
post.
