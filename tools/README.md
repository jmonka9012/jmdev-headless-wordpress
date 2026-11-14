# Backend tools

These are ordinary PHP/Python command-line scripts. They do not require an AI
agent. Run commands below from `backend/` unless stated otherwise. WordPress
loads the MU-plugin independently; none of these tools is needed for normal
editing or serving REST responses.

## Routine checks

```sh
php tools/run-tests.php
php tools/tests/import-post.php
```

`run-tests.php` runs every isolated MU-plugin contract in a separate PHP process
and returns a nonzero exit code on failure. `tests/import-post.php` checks that
import updates preserve native post properties. Neither command needs WordPress,
DDEV or a database. These checks do not replace a live editor/save test.

With the existing local DDEV site running:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/inspect.php
```

`inspect.php` prints registered ACF groups and their sources, Technology
registration, languages and project/technology counts. It reads the current
WordPress installation without updating content or exporting credentials or
project text. Project counts include managed roots only; counts include drafts
and other non-trash statuses. No report files are written.

## Editing and refreshing the frontend

Use **Portfolio → Kolejność** to reorder projects or technologies. Projects share
one order across translations; technologies are ordered within each category.
Drag rows or use the arrow buttons, then save. The editor checks permissions,
concurrent changes and the complete list before a transactional update.

Project type is selected in the shared project fields. A technology's category
is selected in its ACF editor. These values belong to WordPress; the frontend
reads them from REST.

After editing content, run from the sibling `frontend/` repository:

```sh
node scripts/fetch-wp-data.js
npm run optimize:images
npx vite-ssg build
```

This refreshes the local static site. It does not deploy it.

## Optional private-dataset importer

This is the retained importer for the original approved 11-project PL/EN
migration. It is intentionally coupled to that dataset, its source hashes and
existing record mappings. It is not a general content importer or a clean-site
bootstrap. The private inputs and captured inventory are not in this repository.
It is unnecessary for everyday editing and cannot run from a public clone alone.

| File | Purpose | Run directly? |
| --- | --- | --- |
| `prepare-portfolio-import.py` | Validate original source files and stage a package | Yes, Python on the host |
| `portfolio-import.php` | Plan or explicitly apply the approved import | Yes, WP-CLI in local DDEV |
| `portfolio-import-package.php` | Package, file hash and media validation | No, importer module |
| `portfolio-import-plan.php` | Match records and plan changes | No, importer module |
| `portfolio-import-inventory.php` | Read live records and relationships | No, importer module |
| `portfolio-import-apply.php` | Transactional writes, locks and journal | No, importer module |
| `portfolio-import-verify.php` | Verify imported values and REST | No, importer module |
| `portfolio-import-post.php` | Preserve native fields during updates | No, importer module |
| `tests/import-plan.php` | Regressions using private package and captured inventory | Yes, when inputs exist |

The source preparation expects the original sibling `zaplecze/portfolio-output/`,
`zaplecze/projekty-zdjecia.zip` and private
`backend/.local-audit/before-migration/` reports. It writes to `.local-import/`.
From `backend/`:

```sh
python3 tools/prepare-portfolio-import.py
php tools/tests/import-plan.php
```

Generate a read-only plan using a new output directory:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-import.php /var/www/html/.local-import /var/www/html/.local-audit/import-review dry-run
```

Review `plan.json` and preserve a database backup before applying. The following
command **changes project data and may copy media files**; it is not a routine
check. Use a new output directory and the reviewed plan:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-import.php /var/www/html/.local-import /var/www/html/.local-audit/import-reviewed-apply apply /var/www/html/.local-audit/import-review
```

Apply rejects changed mappings or source hashes. It uses transactions and locks,
preserves existing IDs, and journals copied files. Reports contain private data
and stay in ignored directories. After interruption, inspect the journal before
retrying: a database rollback does not necessarily remove copied files. Never
clear uploads globally to recover an import.

## Retired one-time scripts

The completed initial audit, project-type/category backfills and first-import
repair scripts have been removed from the maintained tools directory. Their
original source remains in Git history. A private workspace archive at
`../zaplecze/historyczne-tools/` contains exact copies, hashes and the previous
instructions. It is not part of a public clone and is not required by the
MU-plugin or importer. Historical repair commands are not ongoing maintenance.
