# Local portfolio migration tools

## Ordering projects and technologies

W panelu **Portfolio → Kolejność** dostępne są zakładki **Projekty** oraz
**Technologie**. Projekty mają jedną wspólną kolejność dla wszystkich języków.
Technologie układa się osobno w każdej kategorii; pozycje bez kategorii
znajdują się pod „Pozostałe”.

Przeciągnij wiersz za uchwyt lub użyj przycisków ↑ / ↓, następnie kliknij
**Zapisz kolejność**. **Cofnij niezapisane zmiany** przywraca układ z otwarcia
strony. Przy opuszczaniu strony z niezapisanymi zmianami przeglądarka poprosi
o potwierdzenie. Dostęp wymaga uprawnienia do edycji cudzych wpisów oraz
wszystkich pozycji z danej listy.

Zapis wykorzystuje istniejące `_portfolio_shared.order` dla projektów i pole
ACF `order` dla technologii. Numery ustalane są automatycznie. Pierwszy zapis
nowej technologii oraz import nowego projektu dopisuje pozycję na końcu;
otwarcie panelu nie zmienia istniejących danych. Osobne ręczne pola kolejności
w edytorach zostały zastąpione odnośnikiem do wspólnego panelu.

Zapis sprawdza nonce, uprawnienia, kompletną listę identyfikatorów i wersję
danych. Blokady edytora oraz transakcja chronią przed częściowym zapisem.
Jeżeli lista lub dane zmieniły się od jej otwarcia, trzeba ją odświeżyć.
Zmiany dotyczą tylko kolejności; publikacja i treść wpisów pozostają niezależne.

Po zapisaniu kolejności odśwież dane i manifest z katalogu `frontend`,
a następnie przebuduj stronę lokalnie:

```sh
node scripts/fetch-wp-data.js
npm run optimize:images
npx vite-ssg build
```

## Technology categories

W edycji technologii pole **Portfolio — kategoria technologii → Kategoria**
określa jedną z pięciu grup. Definicje, kolejność i etykiety PL/EN znajdują się
w `portfolio-projects/technology-categories.php`. REST zwraca `portfolio_category`,
a eksport frontendu zachowuje te dane w polu `category`. Brak przypisania trafia
na stronie głównej do grupy „Pozostałe” / „Other”.

Jednorazowe przypisanie 34 obecnych technologii według zatwierdzonej referencji,
z katalogu `backend`:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-technology-categories.php /var/www/html/.local-audit/technology-categories
```

Skrypt zapisuje kopię pól, sprawdza kompletność listy i wykonuje zapis
w transakcji. Nowe technologie wymagają wyboru kategorii w WP. Po zmianach
odśwież dane i manifest obrazów z katalogu `frontend`:

```sh
node scripts/fetch-wp-data.js
npm run optimize:images
```

## Project type

Pole **Typ projektu** znajduje się w **Portfolio → projekt → Dane wspólne**.
Wartości `commercial` i `personal` są wspólne dla wszystkich języków,
zapisywane w `_portfolio_shared.project_type` i zwracane jako
`portfolio.project_type` w REST API. Nowe projekty wymagają jawnego wyboru.
Starszy edytor ACF ma odpowiadające pole `project_type`.

Jednorazowe uzupełnienie 11 obecnych projektów jako komercyjnych, z katalogu
`backend`:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-project-types.php /var/www/html/.local-audit/project-types
```

Skrypt działa tylko lokalnie w DDEV, zapisuje kopię danych przed zmianą,
korzysta z blokad edytora i transakcji, sprawdza typ w wersjach językowych
i nie zmienia treści projektów. Kolejne wywołanie nie klasyfikuje nowych
projektów. Następnie z katalogu `frontend` odśwież dane z WP:

```sh
node scripts/fetch-wp-data.js
```

## Read-only preflight

Run from `backend` on the host after creating the database backup:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-audit.php /var/www/html/.local-audit/before-migration
```

The script runs in the existing WordPress environment and writes local JSON reports only. It does not import, update projects or attachments, invoke admin_init, or change language settings. It collects actual REST responses (including headers, pagination and embedded media), ACF field definitions, selected project metadata, attachment hashes, and explicit PL/EN record candidates. It excludes users and credential-bearing options. Keep output private: it includes draft/private project metadata. Existing report directories are never overwritten; choose another directory name to repeat.

`report.json` lists conflicts that must be resolved before import. Import apply is available only through the explicit command below, against an unchanged reviewed dry-run. Compare runtime definitions and REST with the saved database inventory before implementing migration writes.

## Recovery baseline

`zaplecze/.local-backups/` in the project root contains the original database dump and a tar.gz snapshot of the original custom MU plugin, frontend source, scripts and build configuration. Internal audit reports live in `zaplecze/.local-migration/` and frontend `.codex-notes/portfolio-migration-audit/`. These directories are excluded from Git.

To restore the original database, stop migration work and run from `backend`:

```sh
ddev import-db --file=../zaplecze/.local-backups/before-language-migration.sql.gz
```

This replaces the local database. Restore matching original code from the code snapshot too. Restorability has not yet been tested in a separate database. Do not use the command against production.

## Prepare and check the approved source package

On the host, from the Portfolio root:

```sh
python3 backend/tools/prepare-portfolio-import.py
```

This resolves Windows source paths against `zaplecze/portfolio-output/` and `zaplecze/projekty-zdjecia.zip`, preferring `asset_path`. It preserves the approved JSON text, localized alts, provenance, stable Challenge IDs and source checksums. It stages only validated raster files and the 17 approved SVG icons into the private `backend/.local-import/` directory. Source TXT/code documentation is not staged as publishable media. Source checksum keys retain their canonical `portfolio-output/` prefix for compatibility with existing packages. An identical package can be prepared again; different existing packages are never overwritten.

From `backend`, run the live read-only import plan:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-import.php /var/www/html/.local-import /var/www/html/.local-audit/import-dry-run dry-run
```

The planner verifies all package/file hashes, SVG allowlists and raster MIME types, matches exact existing project IDs and slugs, resolves technology aliases, and reuses identical attachment content. It writes `plan.json` and `inventory.json`. Existing report directories are never overwritten. Ambiguous matches, changed mappings and changed source media block the plan. The apply command below is the only path that changes project data. The new runtime model activates only for linked managed records; old records keep their existing behavior before import.

Local regression tests (after staging; reads the existing private before-migration audit):

```sh
php backend/tools/tests/import-plan.php
```

These tests use the actual approved package and captured pre-migration inventory, exercise conflicts, unsafe SVG and a simulated repeat import. They do not constitute a live save/import or browser test.

## Apply the reviewed migration locally

`zaplecze/.local-backups/` contains the original SQL dump and code snapshot. Keep them. From `backend`:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-import.php /var/www/html/.local-import /var/www/html/.local-audit/import-apply apply /var/www/html/.local-audit/import-dry-run
```

Apply rejects changed source checksums, changed project fingerprints or operations that differ from the approved dry-run. It uses InnoDB transactions, a global import lock, project editor locks, and row locks. Existing IDs/slugs remain; only the explicitly mapped W Pełni Zdrowia pair becomes drafts. Attachments are reused by source/content hash; global attachment alts are never overwritten. New PNGs remain PNG, including code screenshots. SVGs receive strict XML validation and dimensions, without enabling unrestricted SVG uploads.

The output contains `before-state.json`, `journal.json`, `verification.json`, `repeat-plan.json`, full REST snapshots and `languages.json`. Verification checks raw text, SEO, media order/alt/link assignments, technology relations, original IDs/slugs, API compatibility and a real repeat plan with zero create operations. The old editing surface redirects to **Portfolio → project → PL/EN** only after verification passes. Browser interactions and desktop/mobile visuals still require a live check; the script does not claim those passed.

After interruption/failure, inspect `journal.json` before retrying. A database rollback preserves existing rows, but newly copied files may remain; their exact paths/hashes are journaled. Do not globally delete uploads. If restore is needed, restore the original database AND original MU plugin/frontend code from the root code snapshot; SQL restore alone does not revert code. For a repeat import after success, produce a fresh dry-run under a new output directory and pass that report to apply. It updates mapped content without creating duplicate records or attachments. The source package intentionally includes only PL/EN; additional registered languages are preserved.

## Build frontend from the captured API (no Docker access needed)

From `frontend` after successful apply:

```sh
PORTFOLIO_API_SNAPSHOT=../backend/.local-audit/import-apply node scripts/fetch-wp-data.js
npm run optimize:images
./node_modules/.bin/vite-ssg build
```

This copies original uploads from the shared local filesystem, uses unique exported filenames, and atomically replaces the generated JSON directory. Old project routes disappear with the old export. No deployment command is run.


## Recovery of the first import's publication defaults

The first apply completed data import, but its update branch used `wp_insert_post` without merging existing native fields. Verification caught the resulting draft status of 20 existing translations. The update path now uses `wp_update_post` and verifies unchanged native fields before commit. Do not rerun the initial import to recover.

From `backend`, run the targeted transaction:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/portfolio-repair-publication.php /var/www/html/.local-import /var/www/html/.local-audit/import-apply /var/www/html/.local-audit/import-repair
```

It requires the matching completed journal, original before-state and pre-migration REST snapshot. It refuses changed project text/mappings, restores publication and native settings/categories/tags only for the original 20 mapped records, and preserves imported text, SEO, relations and media. Both W Pełni Zdrowia records remain archived and Polak Iron remains unchanged. Verification runs before commit; no project/technology/media creation occurs. Use `import-repair` as `PORTFOLIO_API_SNAPSHOT` for subsequent frontend checks, because the initial failed verification's public snapshot contains only Polak Iron.


### Recovery date correction

The first targeted recovery rolled back at `290/post_date` because WordPress clears dates for undated drafts unless `edit_date` is explicitly set. The repair now sets that flag; the imported source content remains untouched. `tests/repair-dates.php` exercises the actual locally installed `wp_update_post` implementation and all 20 captured recovery records without executing database writes. Repeat the recovery command with a NEW report directory, e.g. `/var/www/html/.local-audit/import-repair-2`. Keep `import-repair` as evidence of the failed, rolled-back attempt.
