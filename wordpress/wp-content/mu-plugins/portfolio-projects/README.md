# Portfolio Projects MU-plugin

The `portfolio-projects.php` MU plugin loads automatically with WordPress. It requires the existing ACF plugin; no activation is needed. The legacy media import runs on authorized admin requests, in batches of 50 projects. Definitions are versioned in PHP, independent of the theme.

## Field definitions

`acf-fields.php` registers the original ACF groups on `acf/init` at priority 5,
before the project schema resolves existing relationships:

- `fields/content.php`: homepage content, including nested section fields.
- `fields/meta.php`: template and language fields.
- `fields/project.php`: legacy project order, link and image fields.
- `fields/technology.php`: the existing technology order field.

These four groups contain 42 fields, including nested fields. Their original
group keys, field keys, names, locations, validation settings and return formats
are preserved. Edit their definitions in these PHP files. ACF continues to render,
validate, format and store their values. Database definition records are retained
as a fallback; local PHP definitions take precedence. An admin notice identifies
the groups managed in code.

The structured project fields in `schema.php` and the category field in
`technology-categories.php` were already registered in PHP. All six active field
groups now come from code.

## Technology post type

`post-types.php` registers `technology` on `init` at priority 0, before ACF
processes its retained database record. The arguments in
`post-types/technology.php` preserve the exported ACF configuration, including
labels, capabilities, supports, rewrite settings and REST exposure. Existing
technology IDs, slugs, images, categories and values remain unchanged. An empty
database no longer needs an ACF post-type definition for Technology to exist.

The old ACF record is kept as a fallback, with a notice identifying the PHP
source. WordPress core, ACF installation, site setup and sample content are
separate requirements; code registration alone does not install the whole site.

Registration and legacy field-key contracts can be checked without WordPress,
from the backend repository root:

```sh
php wordpress/wp-content/mu-plugins/portfolio-projects/tests/acf-fields.php
```

## Editing

Custom admin labels, instructions and validation messages are in English.
Project content remains localized; language names configured in WordPress are
preserved. Native WordPress and external plugin controls follow their own locale.

Projects remain standard WordPress `post` entries. Use the native title, manual excerpt (Hero subtitle), and content (Overview). The structured project field group contains:

- `project_gallery`: ordered rows of attachment `image` and optional plain-text `caption`.
- `project_challenges`: ordered rows of `title`, `challenge`, `solution`, `effect`, optional `screenshots` (ordered rows of `image` and `caption`), `link_url` (HTTP/HTTPS) and `link_label`. Screenshot and link can appear together; a missing label uses the translated “View resource” button text. A label without a URL does not create a visible section.
- `project_outcome`: optional plain-text summary.

The editing group has four tabs matching the detail page: Hero, overview/technologies, challenges, and outcome. Native title/excerpt/content remain in their existing WordPress editors, with guidance in the relevant tabs. Layout-only tabs and messages store no new project data.

Rows have numbered headers, live titles/captions, collapse/expand controls, an item count, and keyboard-accessible up/down buttons. The first row starts expanded; new rows open automatically, and validation errors reveal the affected collection. Gallery images and captions appear side by side on desktop. Solution uses the ACF WYSIWYG editor. Image fields use the WordPress media library.

ACF Free does not contain the PRO Repeater. This plugin registers its own `portfolio_rows` field type using ACF's extension API. It uses ACF inputs, validation and persistence, but stores each collection as a single ordered array in post meta. It is **not storage-compatible with PRO Repeater**; installing PRO later does not change its type or rewrite data. Nested screenshot rows use the same custom field. The `have_rows()` API is outside its scope; read collections with `get_field()`.

Use **Portfolio → Order** to reorder projects and technologies. Projects share
one order across translations; technologies are ordered within their category.
Drag rows or use the move buttons, then click **Save order**. **Discard unsaved
changes** restores the order from page load. Category choices in the admin use
English labels independently of the localized labels exposed to the frontend.

## Technology category labels

`technology-categories.php` defines category identifiers, order and English
defaults in PHP. Technology entries store only the selected `technology_category`
identifier; changing a label does not reassign technologies or change their order.

Administrators can edit public labels in **Portfolio → Category labels**. Values
are stored in the `portfolio_category_labels` WordPress option as
`{category_key: {locale: label}}`. The screen includes PL/EN and any additional
languages registered for the portfolio. Definitions remain in code; translated
display values belong to site configuration and are not shipped with the plugin.

The screen uses the WordPress Settings API for nonce and capability checks.
Labels are sanitized as plain text. Incomplete or malformed submissions preserve
the previous settings and report an error. Unknown categories/locales are ignored.
An empty or missing translation uses the configured English label; an empty or
invalid English label uses the PHP default. A clean installation therefore works
with English labels without a content import.

REST retains `portfolio_category: {key, labels, order}`. Both the frontend and
the API consume the same saved labels; admin category choices remain the stable
English definitions. Existing installations should copy their current localized
labels into the option before replacing old source-defined labels. Refresh the
frontend data export and rebuild the static site after editing labels.

## Compatibility and technologies

Nothing removes or overwrites the original `photo_1`, `photo_2`, `photo_3`, link, language, order, title, excerpt, or content. A valid new gallery takes precedence. If it is empty, the old photos remain the fallback. Clearing a new gallery therefore restores old images until the old fields are cleared explicitly. The archive uses the native featured image, independently of Hero gallery ordering. Before import, the original `photo_1` remains its fallback.

The old Photo 1–3 inputs are hidden on the WordPress project editing screen via `acf/prepare_field`. Their ACF definitions, saved media, REST access, and migration support remain intact. Editors manage images through the featured image and Hero gallery.

Existing technology `relationship` / `post_object` fields targeting `technology` are discovered in applicable project groups, including nested Groups. Unrestricted fields named `technologies`, `technology` or `project_technologies` are also recognized. The first matching field is used; no reverse relation or copied technology entries are stored. Only published technology targets are exposed. If there is no matching field during ACF initialization, one Relationship field `project_technologies` is registered. If several project-specific relations exist, make their locations unambiguous; the first applicable field wins.

Cards open the project detail routes in the same tab. The original external project URL remains available on the detail page. Project templates are selected by router metadata (`template: project`), which takes precedence over the optional ACF `template` field. The route resolves to the matching exported JSON; that object is passed as `data` to `Project.vue`.

## Importing legacy photos

The next authorized WordPress admin visit imports existing `photo_1–3` into `project_gallery`, in order, reusing attachment IDs. The original archive thumbnail (normally `photo_1`) is also assigned as the native featured image. No physical media files are duplicated. Existing galleries, including deliberately empty galleries, and existing featured images are preserved. A per-project version marker prevents repeated imports. Missing attachment IDs/URLs abort that project's import without a partial gallery and allow a later retry.

To import legacy photos for all projects explicitly and report unresolved media,
run from the backend repository root:

```sh
ddev exec wp --path=/var/www/html/wordpress portfolio import-legacy-media
```

Public REST/build requests do not run migration writes. Refresh the static data export after migration. Run migration commands only when deliberately migrating legacy records; they can update saved content.

## Legacy structured data contract

Use the native post properties plus ACF names below. Image values for storage are **WordPress attachment IDs**, not file paths. Relationship IDs must refer to existing `technology` entries. This documents stored field values, not an import format. Example of the schema-v1 fields; managed multilingual records use the schema-v2 model below:

```json
{
  "schema_version": 1,
  "post_title": "Project title",
  "post_excerpt": "Short subtitle",
  "post_content": "<p>Project overview.</p>",
  "acf": {
    "project_gallery": [{ "image": 123, "caption": "Home page" }],
    "project_challenges": [{
      "title": "Performance",
      "challenge": "A concrete problem.",
      "solution": "<p>The implemented solution.</p>",
      "effect": "The observed result.",
      "screenshots": [{ "image": 124, "caption": "Result" }],
      "link_url": "https://example.com/demo",
      "link_label": "View demo"
    }],
    "project_outcome": "Optional summary"
  }
}
```

Set technologies using the existing relationship's key/name, discovered at runtime; use `project_technologies` only when the fallback field was registered. Do not create another relationship to match the example.

## REST and frontend

`/wp-json/wp/v2/posts` exposes a read-only `portfolio` presentation object with `schema_version`, `gallery`, `challenges`, `outcome`, `technology_ids`, `featured_image`. Images resolve to `{id, url, alt, width, height}` (legacy URL-only images may omit dimensions and ID). This is a derived response, not duplicated persisted data. ACF's own field names remain available under `acf` for editing through its existing mechanisms.

The existing export downloads image URLs in both `acf` and `portfolio`; it preserves `portfolio` in the cards' aggregate and detail JSON files. The image optimizer includes gallery images and challenge screenshots. Re-run the existing data export/build after backend edits; the frontend reads static JSON, not live WordPress.

The detail page uses existing Swiper (manual navigation, no autoplay), optimized image sources, translated labels, shared technology entries, and hides empty sections. Rich text is parsed with an explicit HTML and attribute allowlist in SSR and the browser; textareas use escaped text and preserve newlines. Dangerous URLs and active HTML are removed. Backend saves sanitize text, rich text and media IDs and use ACF's normal nonce/capability checks.

## Validation

Run the isolated backend contracts from the backend repository root:

```sh
php tools/run-tests.php
```

Check the loaded configuration and anonymous public REST responses in DDEV:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/check.php
```

The command checks all public collection pages and returns a nonzero exit code
on a configuration or response-contract failure. It does not modify content.
Empty collections cannot exercise record response shapes. See
[tools/README.md](../../../../tools/README.md) for the exact scope and limits.

With the sibling frontend repository available, check its content sanitizer:

```sh
cd ../frontend
node --test tests/projectContent.test.js
```

The isolated PHP contracts use WP/ACF test doubles; they do not replace a live editor test. For live editor validation, add two WYSIWYG rows, edit, reorder, save, reload, and remove the final row in WordPress. Isolated contracts do not establish that the admin/save/REST round trip works.

Challenge screenshots use the same ordered image rows as the Hero gallery. Existing `screenshot` values are read as one row until the next save, which stores `screenshots`. An explicitly empty collection clears the old screenshot. The detail renders one image without Swiper, and multiple images in a manual Swiper.


## Managed multilingual projects — schema v2

`multilingual.php` and `language-admin.php` preserve native `post` records and IDs while exposing one project editor. The root owns `_portfolio_shared` (technologies, project URL/order, gallery/Challenge IDs and media). Each translation owns native title/excerpt/content and `_portfolio_local` (SEO, outcome, Challenge copy and per-usage image alts/captions). `_portfolio_root_id`, `_portfolio_project_key`, `_portfolio_locale` and `_portfolio_public_slug` provide identity and routing. The central `portfolio_languages` option supports registering another language once and selecting it when creating a draft translation. New translated UI copy still needs to be supplied; the current frontend UI is PL/EN.

The Portfolio menu contains one row per logical project. Language buttons switch all localized fields, including nested image alts and Challenges. Shared media are edited once. Existing custom `portfolio_rows` controls remain. Save checks nonce, permissions for every translation, concurrent changes and media/URL validity before a transactional update. New translations begin as drafts. The old editing surface redirects only when the `portfolio_language_ui_enabled` option is enabled. Historical ACF and category data remain stored but do not control managed project languages. Managed Home pages likewise expose their registered locale through the REST adapter.

REST retains `/wp/v2/posts`, native IDs/slugs and compatible `acf.lang/link/order/project_*` fields. The v2 `portfolio` object adds locale, stable project/Challenge IDs, SEO, public_slug and published translation paths. A single image remains a single image on the frontend; multiple images use Swiper. Empty managed galleries are authoritative; historical photo fields cannot restore cleared images. Global attachment alts remain unchanged.

Repeatable local checks are documented in [tools/README.md](../../../../tools/README.md). The completed bulk content importer is no longer part of the maintained tools. The separate legacy-photo compatibility described above remains available. Nothing here deploys to production. Existing schema-v1 records remain supported until explicitly migrated.
