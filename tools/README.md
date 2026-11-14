# Backend tools

These PHP tools support repeatable development checks. Run commands from this
repository root (`backend/`). WordPress loads the MU-plugin independently;
these commands are not required to edit content or serve REST responses.

## Isolated contract tests

```sh
php tools/run-tests.php
```

`run-tests.php` runs every contract file in the MU-plugin's `tests/` directory
and `tools/tests/` in a separate PHP process. It reports each result and exits
with a nonzero code if any test file fails. It requires command-line PHP,
without WordPress, DDEV, private content or a database.

The contracts cover ACF definitions, Technology registration, structured project
fields, legacy-media compatibility, multilingual projection/storage, ordering
and technology categories. Tool tests cover response validation, malformed data,
pagination and empty collections. They use synthetic fixtures and WordPress/ACF
test doubles and do not replace a live editor/save or frontend integration check.

## Read-only installation summary

With the local DDEV WordPress installation running and ACF active:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/inspect.php
```

`inspect.php` prints the WordPress/ACF versions, registered ACF groups and their
sources, Technology registration, language identifiers and project/technology
counts. Projects include managed roots only; counts include non-trash statuses
such as published, draft and private. Technologies are grouped by category,
including an uncategorized group.

It reads configuration and counts without updating content, exporting project
text or credentials, or writing report files. This is a diagnostic summary,
not a comprehensive integrity validator.

## Read-only runtime checks

With the local WordPress installation and ACF running:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/check.php
```

`check.php` checks the effective PHP ACF groups and required field keys/types,
the custom row field type, Technology REST registration/media support and language
configuration. It then dispatches anonymous GET/OPTIONS requests through the loaded
WordPress REST server for `/portfolio/v1/languages` and the `/wp/v2/posts`,
`/wp/v2/pages` and `/wp/v2/technology` collections. The response format matches
the frontend exporter, including ACF standard formatting and embedded media.

Required ACF/portfolio response fields must be registered in the REST schemas,
including when collections are empty. Every page is checked. Missing pagination headers, duplicate records and
inconsistent totals fail the check. Project responses are checked for the
supported portfolio schemas, image/Challenge collections, technology ID types
and managed identity/language fields. Technology category labels and ranks must
match current configuration; an unassigned category is valid. Legacy projects
retain their supported schema. Empty collections pass and report that record
response shapes could not be exercised.

Failures identify the route, record ID or configuration key without printing
content or credentials. Exit code `0` means the checks passed; `1` means a
configuration, response or request failure. Correct the reported problem and
rerun the command. The command does not repair data, save content or write
reports, and has no dependency on production record counts or private snapshots.

`runtime-contracts.php` is the response-validation module loaded by `check.php`
and the isolated tool tests; it is not a separate command. `inspect.php` remains
the short configuration summary, while `check.php` is the pass/fail check.

These checks exercise the in-process REST handlers. They do not test external
HTTP routing, TLS, media downloads, browser editing, all stored relationships
or the final static build. Run the frontend export/build separately when the
change affects that flow. If content changes during pagination, retry the check.

## Refreshing the frontend

Project order, technology categories and project types are maintained in
WordPress. After content changes, run from the sibling `frontend/` repository:

```sh
node scripts/fetch-wp-data.js
npm run optimize:images
npx vite-ssg build
```

This refreshes the exported REST data, images and local static site. It does
not deploy the site.
