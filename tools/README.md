# Backend tools

These PHP tools support repeatable development checks. Run commands from this
repository root (`backend/`). WordPress loads the MU-plugin independently;
these commands are not required to edit content or serve REST responses.

## Isolated contract tests

```sh
php tools/run-tests.php
```

`run-tests.php` runs every contract file in the MU-plugin's `tests/` directory
in a separate PHP process. It reports each result and exits with a nonzero code
if any test file fails. It requires command-line PHP, without WordPress, DDEV,
private content or a database.

The contracts cover ACF definitions, Technology registration, structured project
fields, legacy-media compatibility, multilingual projection/storage, ordering
and technology categories. They use WordPress/ACF test doubles and do not replace
a live editor/save or frontend integration check.

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
