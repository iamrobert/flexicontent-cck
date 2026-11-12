# Before-and-after evidence for three separate security ports

Application base: FLEXIcontent/flexicontent-cck master at `1f2d3aacac59e03ef83a4b2b2676ab3ae2f82215`.

The application branches contain code changes only. These screenshots are held on a separate evidence branch and are not part of those pull-request diffs.

## What the screenshots show

- `pr1-before.png` / `pr1-after.png`: the exact translated-title input snippet rendered with the same inert quote-containing value. Before, the browser truncates the value at the quote; after, it preserves the complete text.
- `pr2-before.png` / `pr2-after.png`: the actual duplicate-dialog hidden input and source-validation block, exercised with an inert invalid source name. The browser reports an unintended attribute before the fix and an empty, correctly contained source after it.
- `pr3-before.png` / `pr3-after.png`: summaries of actual controller/helper policy assertions with dummy identities and in-memory SQLite. The original source fails eight batch/search assertions, three cache assertions and three download assertions. The patch passes all 36 assertions. The model's invalid-category positive control becomes runnable after the fix, so the patched administrator suite has one additional assertion.

These are isolated source-rendering and regression-result screenshots, not full Joomla administrator pages or live-site evidence. No executable browser payload, real account, site configuration or real database was used.

## Validation

- All 15 changed PHP files pass PHP 8.1 and 8.3 syntax checks; the changed JavaScript passes Node syntax checking. Whitespace checks pass for each branch.
- The translated-title and invalid-template-source browser controls fail on the original source and pass after their respective patches.
- Seven browser checks exercise the shipped Select2/Choices libraries: three unsafe Select2 label/tooltip assertions fail before the fix; all seven pass afterward. Normal Select2 selection and basic Choices selection/text controls pass on both variants. This is not a full Joomla filter-editor integration test.
- Cache/batch/search/download fixtures: 36 assertions pass after the patch; 14 original-source policy assertions fail as intended.
- Address International: 21 save/render policy assertions pass after the patch. These changes are hardening, not a claim that every rejected scheme was previously executable.

Target-controlled checks ran offline in read-only Docker containers with empty allowlisted environments, nonroot users, scratch-only tmpfs writes, and CPU/memory/process/file-size/time limits. Existing local toolchains were used without downloads. Fixtures and logs remain local and are not included here or in the application PRs.

Full Joomla installation/update, complete translated item workflows, filesystem template-copy workflows and Joomla 4/5/6 browser integration were not rerun. Bundled legacy com_media layouts are Joomla 3 routes; they received source hardening without a new Joomla 3 deployment or runtime claim.
