# UiChemy Custom Fields — Developer API

Template functions for reading and writing field values. They live in
`includes/fields/content-types/uich-fields-field-api.php` and are available once the plugin has
loaded (`plugins_loaded`), when **Settings › Features › Custom Fields** is on. With the feature off
none of these functions is declared, so guard optional calls with `function_exists()`.

---

## 1. The `$object_id` argument — read this first

Every function takes the same `$object_id`. It is **not** always a post ID: a short prefix chooses
which kind of object you are addressing.

| You pass | Reads from | Example |
|---|---|---|
| *omitted* / `false` / `null` | the current post in the loop | `uich_field_value( 'subtitle' )` |
| `123` (int or numeric string) | post `123` | `uich_field_value( 'subtitle', 123 )` |
| `'user_5'` | user `5` | `uich_field_value( 'twitter', 'user_5' )` |
| `'term_12'` | term `12` | `uich_field_value( 'icon', 'term_12' )` |
| `'comment_9'` | comment `9` | `uich_field_value( 'rating', 'comment_9' )` |
| `'option'` or `'options'` | site-wide option storage (no admin screen, see below) | `uich_field_value( 'ga_id', 'option' )` |

`'option'` has **no options page and no location rule**: any field of any active group resolves for it, and
values can only be written from code (`uich_save_field_value( ..., 'option' )`), stored as `uich_gf_option_{group_key}__{field_name}`.

Anything unrecognised falls back to post `0`, which reads nothing. Outside the loop, `false` also
resolves to `0` — pass an explicit ID in widgets, blocks, REST callbacks and CLI.

## 2. Reading a value

### `uich_field_value( $selector, $object_id = false, $format = true )`

The one you will use most. Returns the value, or `null` when the field does not resolve.

| Arg | Type | Default | Meaning |
|---|---|---|---|
| `$selector` | string | — | field **name** (or field key) |
| `$object_id` | mixed | `false` | see §1 |
| `$format` | bool | `true` | `true` returns the ready-to-use shape (§4); `false` returns raw storage |

```php
$subtitle = uich_field_value( 'subtitle' );             // current post
$isbn     = uich_field_value( 'isbn', 42 );             // post 42
$ga       = uich_field_value( 'ga_id', 'option' );      // options page
$raw_img  = uich_field_value( 'cover', 42, false );     // attachment ID, unformatted
```

**How it resolves, in order:**

1. Per-request cache (same selector + object + format is computed once).
2. Locate the field — first among groups whose **location rules match** the object, then across
   all groups, so a value stored before the rules changed stays readable.
3. **Conditional logic** (Pro). If the field has conditional logic that does not currently pass, the
   call returns `null` even though a value is stored: a hidden field reads as absent. On Free the
   rules are ignored and the field always passes.
4. **Tier lock.** A Pro field type on a Free build reads as `null`; its stored value is untouched.
5. Read the stored value (the `uich/pre_load_field` filter can supply it instead).
6. **Default value** — applied only if no value is stored *and* the object matched at step 2.
7. Format (unless `$format` is `false`).

### `uich_print_field( $selector, $object_id = false )`

Echoes the value, escaped. A WYSIWYG field goes through `wp_kses_post()` so its markup survives;
everything else is printed per type: `image`/`file`/`link` print the URL, `user` the display name
(never the email), `post_object`/`relationship` the post title(s), `taxonomy` the term name(s),
`true_false` Yes/No, a `repeater` its rows (cells joined by `, `, rows by `; `). Other arrays print as a
comma-separated list.

### `uich_field_values( $object_id = false, $format = true )`

Every field on one object as `[ field_name => value ]`. Fields hidden by conditional logic (Pro) are
**omitted**, not returned as `null`. When two groups define the same field name for one object, the first
group wins, exactly as in `uich_field_value()`.

### `uich_field_value_raw( $selector, $object_id = false )` / `uich_field_values_raw( $object_id = false )`

The stored value(s) with no formatting. Same as passing `$format = false`.

## 3. Asking about a field

| Function | Returns |
|---|---|
| `uich_has_field( $selector, $object_id = false )` | `bool` — resolves **and** has a non-empty value |
| `uich_field_exists( $selector, $object_id = false )` | `bool` — the field resolves for this object |
| `uich_is_field_empty( $selector, $object_id = false )` | `bool` |
| `uich_field_label( $selector, $object_id = false )` | `string` — `''` if unknown |
| `uich_field_type( $selector, $object_id = false )` | `string` — `text`, `image`, `repeater`, … |
| `uich_get_field_key( $selector, $object_id = false )` | `string` — the field's stable key |
| `uich_get_field_settings( $selector, $object_id = false )` | `array` — instructions, default_value, required, wrapper, … |
| `uich_field_definition( $selector, $object_id = false, $format = true )` | `array\|null` — the field config plus a `value` key (and `pro_locked => true` for a locked Pro type) |
| `uich_field_definitions( $object_id = false, $format = true )` | `array\|false` — `[ name => field config with value ]` |

```php
if ( uich_has_field( 'cover' ) ) {
	$field = uich_field_definition( 'cover' );
	printf( '<img src="%s" alt="%s">', esc_url( $field['value']['url'] ), esc_attr( $field['label'] ) );
}
```

## 4. What each field type gives you back

With `$format = true`:

| Type | Returned | Setting |
|---|---|---|
| `text` `textarea` `email` `url` `password` | `string` | — |
| `number` `range` | `int`, or `float` when the value has a decimal point | — |
| `true_false` | real `bool` — `null` only when never set | — |
| `select` `radio` `checkbox` | stored value(s); multi-select gives an `array` | — |
| `wysiwyg` | `string` run through the `the_content` filter | — |
| `date` `time` `datetime` `color` `page_link` | `string`, as stored | — |
| `link` | `array{ url, title, target }` | — |
| `image` | `array{ id, url, alt, title }` · `url` string · `id` int | `return_format` (default `array`) |
| `file` | `array{ id, url, title, filename }` · `url` string · `id` int | `return_format` (default `array`) |
| `post_object` | `WP_Post` · `int` — `array` of those if Multiple is on | `post_object_return_format` (default `object`) |
| `relationship` (Pro) | **always an array** of `WP_Post` or `int` | `relationship_return_format` (default `object`) |
| `user` | `WP_User` · `int` · raw array — `array` of those if Multiple is on | `user_return_format` (default `array`) |
| `taxonomy` | term ID(s); `WP_Term` object(s) when Return Format is `object` | `taxonomy_return_format` (default `id`) |
| `repeater` (Pro) | `array` of rows, each `[ sub_name => formatted value ]` | — |

If an image/file/post/user/term has since been deleted you get a safe empty shape rather than
`false` or a fatal: references are re-resolved on every read, a deleted post/user/term reads as `null`,
and deleted IDs are dropped from `relationship` and multiple-value fields. No stored snapshot (such as a
deleted user's email) is ever returned.

## 5. Repeaters (Pro)

```php
while ( uich_repeater_has_next( 'cast_members' ) ) : uich_repeater_advance();
	echo esc_html( uich_repeater_value( 'name' ) );
	$photo = uich_repeater_value( 'photo' );   // formatted like any image field
endwhile;
```

| Function | Purpose |
|---|---|
| `uich_repeater_has_next( $selector, $object_id = false )` | `bool` — opens a loop frame |
| `uich_repeater_advance( $format = true )` | advance to the next row |
| `uich_repeater_value( $selector, $format = true )` | one sub value from the current row |
| `uich_print_repeater_value( $selector )` | echo one sub value, escaped |
| `uich_repeater_value_type( $selector )` | sub field type |
| `uich_repeater_row( $format = true )` | the whole current row as an array |
| `uich_repeater_rows( $selector, $object_id = false, $format = true )` | every row as an array |
| `uich_repeater_index()` | current index, **1-based**; `0` outside a loop |
| `uich_repeater_rewind( $hard = false )` | pop the innermost frame; `true` clears all frames |

Nesting works: pass the sub-repeater's name to an inner `uich_repeater_has_next()` (without
`$object_id` — nested calls read from the current row). A frame is released when its rows run out;
call `uich_repeater_rewind()` only if you `break` out early. A repeater is also just an array:
`foreach ( (array) uich_field_value( 'cast_members' ) as $row ) { … }`.

## 6. Writing

### `uich_save_field_value( $selector, $value, $object_id = false )`

Returns `bool`. **Capability-checked** against the target object (`edit_post`, `edit_user`,
`edit_term`, `edit_comment`, or `manage_options` for `option`), so it returns `false` in CLI/cron
until you `wp_set_current_user()`. A Pro type on a Free build is refused.

```php
uich_save_field_value( 'isbn', '978-0-00-000000-0', 42 );
uich_save_field_value( 'featured', true, 42 );
uich_save_field_value( 'ga_id', 'G-XXXX', 'option' );
```

Pass values in the stored shape (attachment **ID** for image/file, array of IDs for relationship, a
real `bool` for true_false). The value runs through the same per-type sanitiser as the editor:
numbers are clamped to `min`/`max`, unknown post/user/term IDs and out-of-choice values are
rejected, repeater rows are truncated to `repeater_max_rows`, and disallowed URL protocols are
dropped. A `page_link` also accepts a bare post ID and stores that post's permalink.

A non-empty value that the sanitiser reduces to nothing (an unknown choice, `'abc'` for a number, a
`javascript:` URL, a non-existent ID) is **refused**: the call returns `false` and the previous value is
kept. A value that is only adjusted (a number clamped to `min`/`max`) is stored. Writing an empty value
clears the field. If the sanitiser is unavailable the write is refused.

### `uich_erase_field_value( $selector, $object_id = false )` / `uich_reset_field( $selector, $object_id = false )`

Erase removes the stored value; reset writes the field's `default_value`. Both return `bool`.

## 7. Groups, export and import

| Function | Returns |
|---|---|
| `uich_group_definition( $group_id )` | one group config, or `null` (`$group_id` is the definition ID shown in the dashboard) |
| `uich_group_definitions( $context = array() )` | every active group matching `$context` |
| `uich_get_group_fields( $group_id )` | that group's field configs |
| `uich_get_all_fields()` | every field across every group, flat, each carrying `group_key` / `group_id` |
| `uich_export_fields( $group_ids = array() )` | JSON string of the groups (all when empty) |
| `uich_import_fields( $json )` | `array{ success, ids, errors }` — needs `manage_options`; runs the same checks as a dashboard save (Pro types, Pro location rules and conditional logic are refused on Free) |

## 8. Storage

Definitions are JSON files under `wp-content/uichemy-fields/sites/{blog_id}/` (`post-types/`,
`taxonomies/`, `field-groups/`, plus `.trash/`). Values are ordinary meta rows:

| Object | Table | Key |
|---|---|---|
| post | `postmeta` | `_uichemy_gf_{group_key}__{field_name}` |
| term | `termmeta` | `_uichemy_gf_term_{group_key}__{field_name}` |
| comment | `commentmeta` | `_uichemy_gf_comment_{group_key}__{field_name}` |
| user | `usermeta` | `_uichemy_gf_user_{blog_id}_{group_key}__{field_name}` (blog-scoped, single site too) |
| option | `options` | `uich_gf_option_{group_key}__{field_name}` (autoload off) |

The separator between group key and field name is a **double underscore**. Group keys and field
names may not contain `__` or start/end with `_` (names are normalised on save), so a key always
maps back to exactly one group and field. Build keys with `uich_build_meta_key( $object_type,
$group_key, $field_name )` rather than by hand.

Values are only exposed in the REST API / block editor when the group's **Show in REST API**
setting is on (off by default) — an exposed value on published content is readable by anyone.

## 9. Hooks

| Hook | Type | Arguments |
|---|---|---|
| `uich/pre_load_field` | filter | `( null, $selector, $object_id )` — return non-`null` to supply the stored value |
| `uich/load_field` | filter | `( $value, $selector, $object_id )` — after reading, before formatting |
| `uich/format_value` | filter | `( $value, $field, $object_id )` — after formatting |
| `uich/update_value` | filter | `( $value, $field, $object_id )` — before a write is sanitised and stored |
| `uich/register_field_meta` | filter | `( true, $field, $meta_key, $object_type )` — return `false` to skip `register_meta()` |
| `uich/register_field_meta_args` | filter | `( $args, $field, $meta_key, $object_type )` |
| `uich/register_post_type_args` / `uich/register_taxonomy_args` | filter | `( $args, $key, $definition )` |
| `uich/registered_post_type` / `uich/registered_taxonomy` | action | `( $key, $object, $definition )` |
| `uich/definition_saved` | action | `( $id, $kind, $definition )` — `$kind` is `cpt`, `taxonomy` or `group_fields` |
| `uich/definition_changed` | action | `( 0, $kind )` — after a trash, restore or permanent delete |
| `uich/definition_deleted` | action | `( $id, $kind, $definition )` — after a definition is moved to the Trash |
| `uich/definition_restored` | action | `( $id, $kind, $definition )` — after a restore from the Trash |
| `uich/definition_deleted_permanently` | action | `( $id, $kind, $definition )` — after a permanent delete |

```php
add_filter( 'uich/format_value', function ( $value, $field, $object_id ) {
	if ( 'price' === $field['name'] ) {
		return '$' . number_format( (float) $value, 2 );
	}
	return $value;
}, 10, 3 );
```

## 10. Recipes

```php
// Option-scoped value (written from code; there is no options page).
$ga = uich_field_value( 'ga_id', 'option' );

// Term field on an archive.
$icon = uich_field_value( 'icon', 'term_' . get_queried_object_id() );

// Author field on a single post.
$twitter = uich_field_value( 'twitter', 'user_' . get_post_field( 'post_author', get_the_ID() ) );

// true_false: tell "off" from "never answered".
$v = uich_field_value( 'featured' );
if ( null === $v ) { /* never answered */ } elseif ( false === $v ) { /* explicitly off */ }
```

## 11. Escaping

Values are **not** pre-escaped by `uich_field_value()`. Escape at output with `esc_html()` for
text, `esc_attr()` for attributes, and always `esc_url()` for anything that goes into `href` or
`src` (url, link, page_link, file and image URLs): `esc_attr()` does not strip a `javascript:`
URL. A WYSIWYG value has been through `the_content`, wrap it in
`wp_kses_post()` if you echo it yourself; `uich_escape_field_value()` escapes a whole array.
`uich_print_field()` escapes for you.

## 12. When a field returns `null`

1. **Wrong `$object_id`** — a term field read with a bare integer looks at a post. Use `term_12`.
2. **Conditional logic is not passing** (Pro) — the field is hidden, so it reads as absent.
3. **Pro field type on a Free build** — reads as `null`; `uich_field_definition()` sets `pro_locked`.
4. **Outside the loop with `$object_id` omitted** — `false` resolves to post `0`.
5. **Selector is the label, not the name** — pass the field *name* (`movie_title`).
6. **Never saved and no default** — `null` is correct.
7. **Group is inactive** — an inactive group does not resolve.
