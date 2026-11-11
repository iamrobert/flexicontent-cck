# Frontend file-manager runtime validation

PR: https://github.com/FLEXIcontent/flexicontent-cck/pull/1354

The proposed solution is in `site/controllers/filemanager.php` upstream and `components/com_flexicontent/controllers/filemanager.php` in the installed site. It requires a signed-in file manager or content author for direct frontend file-manager dispatch. Category-scoped authors are included. Internal item-field loads keep the existing item/file checks, and the existing direct `addurl`/`addlocal` restriction remains.

## Actual application results

These are real HTTP/database/file checks in a disposable local Joomla fixture, with dummy users and inert text files. Joomla identity, CSRF handling, FLEXIcontent controllers/models, field plugins and database writes were used. The earlier standalone regression fixture does not substitute for these checks.

| Result | Operation |
| --- | --- |
| Passed | Original upstream wrapper: guest upload created a file row with `uploaded_by=0`; stored bytes matched the submitted text. |
| Passed | Original upstream wrapper: Registered user upload created a file row with `uploaded_by=101`; stored bytes matched. |
| Passed | Exact patched wrapper: guest and Registered multipart uploads returned HTTP 403; no matching row or file was created. |
| Passed | Component Author, category-only author and dedicated file manager each uploaded successfully, with correct owner and file SHA-256. |
| Passed | The same three roles saved file title/description through `filemanager.apply_ajax`; database values changed and file bytes remained unchanged. |
| Passed | Missing-CSRF-token saves left all three records unchanged. This legacy handler returned 200 with an error body, so HTTP status alone was not used to infer success. |
| Passed | A category author could not change the component author's file. The stored title remained unchanged. |
| Passed | Component Author and category-only author submitted a multipart article form with a new file attachment. The real file plugin called the file-manager controller internally; article titles and field relations persisted. Reloaded forms contained the saved title and attachment ID. |
| Passed | The PR's 31 standalone boundary regressions passed again against the published wrapper. |

The two article attachments were independently read back from the database as field `100`: item `1102` references file `12`, and item `1103` references file `13`. Their SHA-256 values were `fa3b2e0ad0e611da86449ab4c1b1ce245b7e34501ca8e4380544e471ca05fe7e` and `5a092f5fa4cefc03d9c4d204c2abbb949234c2ed6244f48ecc0677c1ec6b1218`.

## Scope and evidence

Base: `5943b610c06d9d5da5b97c130575bc5d51d819ac`.
Tested PR source commit: `41b9fca89589de249d1baaa713a1a399101fd0f7`.
Wrapper SHA-256: `04a032d8484dada87e05e134a96de9be2a48d20953a2b3c066b3aec56e7f1d7b`.

Runtime: Joomla 6.2.0, PHP 8.3.26, installed MariaDB image and Chromium/Playwright. The baseline/initial direct-upload matrix ran on the installed Alpine PHP image. The article-form and metadata-save checks ran on the installed Debian PHP image because Alpine lacks `GLOB_BRACE`, which the existing form renderer uses. No compatibility shim or application-code change was made for that environment difference.

The fixture copied public application source and schema/package metadata only. Users, authored content, sessions, site configuration, uploaded media and integration secrets were excluded; new dummy accounts, articles and configuration were generated. Containers had no external network, read-only source/toolchains, empty allowlisted environments, scratch-only writes, and CPU/memory/process/file-size/disk/time limits. Screenshots were captured from genuine Joomla pages and safely promoted after the browser stopped.

Screenshots:

- [Original upload records in Atum](before.png). The screenshot supports the visible file records; the separate database/file checks establish identity and stored bytes.
- [Patched guest request denied](after.png). Cassiopeia's standard error page displays the guard's HTTP 403 message.

The article checks submitted multipart data derived from the real rendered forms and reloaded the forms afterward. They do not claim complete mouse-driven testing of every editor/uploader widget. Live authenticated editing, advanced AJAX upload integrations, guest submission integrations and other Joomla/PHP versions remain untested. The original fixture's unrelated post-install warnings were not treated as release certification.

The upstream change remains separate from the site's namespace refactor. This validation did not change or redeploy production files.

## Reproducing the narrow checks

The included request-boundary regression runs from the PR checkout with `php tests/filemanager-frontend-guard.php`. It requires no Joomla database.

For application integration, use a disposable Joomla installation with the pinned FLEXIcontent source and dummy data only, inside an isolated loopback network. Keep the installer-provided Public/Registered upload and own-file grants. Create a Registered user, Author, category-only author and dedicated file manager. Give the latter `flexicontent.managefiles`, and give the category author `core.create`/`core.edit.own` only on its content category. Create one article for each author and assign a File field to their content type. Restrict the uploaded fixture to inert `.txt`.

Obtain each client's genuine Joomla session/form token; log in through `com_users` for authenticated clients. POST multipart data to `index.php?option=com_flexicontent&view=filemanager&task=filemanager.upload`, with `Filedata`, `secure=0` and the session's form token. Before the patch, query the uploaded file row and verify owner and SHA-256 against the submitted bytes. Apply the exact wrapper above and repeat: guests and Registered readers must receive 403 with no row/file, while the authorized roles still create their own file. Do not treat a redirect as proof of a successful upload.

Save an owned record through `filemanager.apply_ajax`, supplying its id, filename/original filename, title/description, existing storage/state values and valid token. Read back title/description and the unchanged byte hash. Repeat without a token and as another owner; require unchanged data. Finally submit each author's real rendered article form as multipart, adding `custom[FIELDNAME][0][file-data]` with inert text, the matching field's posted data, and `task=items.apply`. Verify the saved article title, file row/bytes and `flexicontent_fields_item_relations`, then reload the article form and verify the attachment ID.

The sanitized `results.json` records the observed outcomes. The application fixture's read-only observation helper queried its real dummy database and file hashes; controllers/models/identity and field saves were not mocked.
