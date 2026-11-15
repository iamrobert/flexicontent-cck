# Release follow-up review evidence

Application commit: [`2625d48be91d66c2df4eba784c8464ad0f141c21`](https://github.com/iamrobert/flexicontent-cck/commit/2625d48be91d66c2df4eba784c8464ad0f141c21), based directly on upstream master `fc708eaef013f4283240a3c107de295258c57caa`. This evidence branch is separate from the application PR.

**112 named Joomla browser/runtime checks pass on this exact commit.** Fresh installs and actual package upgrades run against Joomla 5.4.10 and 6.2.0 with PHP 8.4.27 and MySQL 8.0.46. Each platform runs 27 original-finding checks and 29 ACL/upgrade checks. Receipts include original-code negative controls, allowed and denied requests, real frontend saves and database preservation assertions.

| Suite | Joomla 5 | Joomla 6 |
|---|---|---|
| Purge, filename, sliders, user forms, debug filters, Translate, uninstall | [27 pass](remediation-joomla5.json) | [27 pass](remediation-joomla6.json) |
| Upgrade, word suggestions, search form, associations | [29 pass](acl-joomla5.json) | [29 pass](acl-joomla6.json) |

PHP 8.2.35, 8.3.6 and 8.4.27 each pass 868 PHP lint checks and the 23 nullable-value assertions. Six existing standalone regression programs pass on each version. PHP 8.3 also passes 53 real loopback HTTP checks and six no-cURL checks. Two unused historical JSON fallback files are excluded from lint, matching the repository workflow.

**Known baseline test failure:** `_tools/tests/category-association-propagation.php` fails its `independent secondary flag` assertion on both unchanged master and this PR. Its SQL mock returns the source main category for the target query. The correction already in [PR #1391](https://github.com/FLEXIcontent/flexicontent-cck/pull/1391/files) makes all 54 assertions pass for both sources when applied only to the disposable fixture. The application category code is not changed to accommodate the faulty mock. See [baseline control](category-test-baseline-control.json) and [unmodified-suite results](php-checks.json). This failure is retained, not reported as a passing unmodified suite; upstream CI is expected to remain blocked until that test correction lands.

**Upgrade action:** rebuild the Basic search index once. The new field-attributed autocomplete table starts empty for old items; existing merged text is never used as a fallback. Permitted basic-only fields remain searchable for suggestions, and approved saves index new values. Pending revisions preserve approved rows. The installer displays the rebuild notice.

The source and every private test-ZIP entry were compared after testing: [identity and readback](verification.json). The earlier 112-check report for combined candidate `4f9896d7a` is historical; the checks linked here were rerun on this PR commit. The two nullable batch-state casts already present in the combined candidate are explicitly included here so Translate does not depend on #1391 for that fix. Version metadata and unrelated #1391/#1289 changes are not included.

The fixtures use dummy principals and data inside offline Docker: read-only inputs/root, non-root UID, empty allowlisted environment, no external networking, bounded scratch, explicit CPU/memory/process/file/time limits, dropped capabilities and a verified loopback namespace. No installed site, shared database or live endpoint was tested or modified. Browser checks use separate headless Chromium contexts.

The [fixture sources](fixtures/) document the exact browser and database assertions. Complete replay requires the pinned offline Joomla/PHP/MySQL/browser inputs used by the local runner; no toolchain images or Joomla archives are published here. The standalone nullable test can run with `php fixtures/nullable-release-values.php /path/to/PR/source`. Normal repository regression programs are in the application checkout. The category baseline control shows the existing failure before the isolated test-only correction.

Not covered: the full original 101-row audit, every field/template policy, the whole earlier platform matrix, MariaDB, production deployment, or a claim of zero deprecations across all Joomla and extension code. Filename and slider payloads were seeded fixture values; they prove unsafe rendering, not an unprivileged form-save route.
