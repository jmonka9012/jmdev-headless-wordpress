# Portfolio project schema — v1

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
groups now come from code. The `technology` post-type registration is separate
and still uses ACF's database configuration; this change does not yet make the
entire backend installable from an empty database.

Registration and legacy field-key contracts can be checked without WordPress:

```sh
php backend/wordpress/wp-content/mu-plugins/portfolio-projects/tests/acf-fields.php
```

## Editing

Projects remain standard WordPress `post` entries. Use the native title, manual excerpt (Hero subtitle), and content (Overview). The new **Portfolio — szczegóły projektu** field group contains:

- `project_gallery`: ordered rows of attachment `image` and optional plain-text `caption`.
- `project_challenges`: ordered rows of `title`, `challenge`, `solution`, `effect`, optional `screenshots` (ordered rows of `image` and `caption`), `link_url` (HTTP/HTTPS) and `link_label`. Screenshot and link can appear together; a missing label uses the translated “View resource” button text. A label without a URL does not create a visible section.
- `project_outcome`: optional plain-text summary.

The editing group has four tabs matching the detail page: Hero, overview/technologies, challenges, and outcome. Native title/excerpt/content remain in their existing WordPress editors, with guidance in the relevant tabs. Layout-only tabs and messages store no new project data.

Rows have numbered headers, live titles/captions, collapse/expand controls, an item count, and keyboard-accessible up/down buttons. The first row starts expanded; new rows open automatically, and validation errors reveal the affected collection. Gallery images and captions appear side by side on desktop. Solution uses the ACF WYSIWYG editor. Image fields use the WordPress media library.

ACF Free does not contain the PRO Repeater. This plugin registers its own `portfolio_rows` field type using ACF's extension API. It uses ACF inputs, validation and persistence, but stores each collection as a single ordered array in post meta. It is **not storage-compatible with PRO Repeater**; installing PRO later does not change its type or rewrite data. Nested screenshot rows use the same custom field. The `have_rows()` API is outside its scope; read collections with `get_field()`.

## Compatibility and technologies

Nothing removes or overwrites the original `photo_1`, `photo_2`, `photo_3`, link, language, order, title, excerpt, or content. A valid new gallery takes precedence. If it is empty, the old photos remain the fallback. Clearing a new gallery therefore restores old images until the old fields are cleared explicitly. The archive uses the native featured image, independently of Hero gallery ordering. Before import, the original `photo_1` remains its fallback.

The old Photo 1–3 inputs are hidden on the WordPress project editing screen via `acf/prepare_field`. Their ACF definitions, saved media, REST access, and migration support remain intact. Editors manage images through the featured image and Hero gallery.

Existing technology `relationship` / `post_object` fields targeting `technology` are discovered in applicable project groups, including nested Groups. Unrestricted fields named `technologies`, `technology` or `project_technologies` are also recognized. The first matching field is used; no reverse relation or copied technology entries are stored. Only published technology targets are exposed. If there is no matching field during ACF initialization, one Relationship field `project_technologies` is registered. If several project-specific relations exist, make their locations unambiguous; the first applicable field wins.

Cards open the project detail routes in the same tab. The original external project URL remains available on the detail page. Project templates are selected by router metadata (`template: project`), which takes precedence over the optional ACF `template` field. The route resolves to the matching exported JSON; that object is passed as `data` to `Project.vue`.

## Importing legacy photos

The next authorized WordPress admin visit imports existing `photo_1–3` into `project_gallery`, in order, reusing attachment IDs. The original archive thumbnail (normally `photo_1`) is also assigned as the native featured image. No physical media files are duplicated. Existing galleries, including deliberately empty galleries, and existing featured images are preserved. A per-project version marker prevents repeated imports. Missing attachment IDs/URLs abort that project's import without a partial gallery and allow a later retry.

To import all projects explicitly and report unresolved media:

```sh
cd backend
ddev exec wp portfolio import-legacy-media
```

Public REST/build requests do not run migration writes. Refresh the static data export after migration. This environment cannot execute the database import because Docker is unavailable.

## Structured data contract (future input, no importer)

Use the native post properties plus ACF names below. Image values for storage are **WordPress attachment IDs**, not file paths. Relationship IDs must refer to existing `technology` entries. Example only; this stage adds no importer or content generation:

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

```sh
php backend/wordpress/wp-content/mu-plugins/portfolio-projects/tests/contract.php
php backend/wordpress/wp-content/mu-plugins/portfolio-projects/tests/legacy-media.php
cd frontend
node --test tests/projectContent.test.js
```

The isolated PHP contracts use WP/ACF test doubles; they do not replace a live editor test. Verify adding two WYSIWYG rows, editing, reordering, saving, reloading, and removing the final row in WordPress when DDEV is available. This environment cannot access Docker or resolve `backend.ddev.site`, so the live admin/save/REST round trip remains to be checked there.

Challenge screenshots use the same ordered image rows as the Hero gallery. Existing `screenshot` values are read as one row until the next save, which stores `screenshots`. An explicitly empty collection clears the old screenshot. The detail renders one image without Swiper, and multiple images in a manual Swiper.


## Managed multilingual projects — schema v2

`multilingual.php` and `language-admin.php` preserve native `post` records and IDs while exposing one project editor. The root owns `_portfolio_shared` (technologies, project URL/order, gallery/Challenge IDs and media). Each translation owns native title/excerpt/content and `_portfolio_local` (SEO, outcome, Challenge copy and per-usage image alts/captions). `_portfolio_root_id`, `_portfolio_project_key`, `_portfolio_locale` and `_portfolio_public_slug` provide identity and routing. The central `portfolio_languages` option supports registering another language once and selecting it when creating a draft translation. New translated UI copy still needs to be supplied; the current frontend UI is PL/EN.

The Portfolio menu contains one row per logical project. Language buttons switch all localized fields, including nested image alts and Challenges. Shared media are edited once. Existing custom `portfolio_rows` controls remain. Save checks nonce, permissions for every translation, concurrent changes and media/URL validity before a transactional update. New translations begin as drafts. The old editing surface redirects only when `portfolio_language_ui_enabled` is enabled by passing post-import verification. Historical ACF and category data remain stored but do not control managed project languages. Managed Home pages likewise expose their registered locale through the REST adapter.

REST retains `/wp/v2/posts`, native IDs/slugs and compatible `acf.lang/link/order/project_*` fields. The v2 `portfolio` object adds locale, stable project/Challenge IDs, SEO, public_slug and published translation paths. A single image remains a single image on the frontend; multiple images use Swiper. Empty managed galleries are authoritative; historical photo fields cannot restore cleared images. Global attachment alts remain unchanged.

The import tooling and recovery instructions live in `backend/tools/README.md`. Nothing here deploys to production. Existing schema-v1 records remain supported until explicitly migrated.
