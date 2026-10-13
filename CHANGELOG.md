# Changelog

All notable changes to Editrix will be documented in this file.

## [1.2.0-beta.1] - 2026-10-12

This release brings the work of the Craft 5 line (2.0.0-beta.2 to 2.0.0-beta.6) to Craft 4. It needs Craft 4.0 or later and PHP 8.0.2 or later.

### Changed
- Editrix is now a paid plugin with two editions, Standard (USD 19) and Pro (USD 99). The free edition no longer exists; both editions can be tried in development and staging. The license is now the Craft License (`LICENSE.md`)
- The README is shorter, and the full documentation is in the `docs` folder
- Results show one row per field instead of one per match: a word that appears four times in an article body is one row marked "4 matches", with a list of each occurrence, and selecting the row replaces every occurrence in that field. Results carry a short context and a fingerprint instead of the full text of every field
- Replace saves entries without validation or propagation (`validateOnReplace` and `createRevisionsOnReplace` settings), so old content that no longer satisfies a required field can still be corrected and each site's row is replaced on its own
- Rich text fields are searched, replaced and reverted using their stored HTML
- Switching between General and Segmented Search clears the previous results

### Added
- Searches run as background jobs on Craft's queue, with a progress bar, a "Cancel search" button, warnings when the queue does not start or stops reporting, a "Recent searches" list, and limits by edition (Standard 3,000 results, Pro 25,000). A search that reaches the server's memory limit stops with the matches found so far and says why
- Replace runs in the queue above 25 rows, with progress and Cancel; the limit per replace is 200 rows on Standard and 5,000 on Pro
- Every value of a replace is written to its log before its entry is saved, so a replace that is cancelled or killed can still be reverted. Revert restores values one by one and never overwrites an edit made since. A log file in `storage/logs/editrix-*.log` records every replace and revert
- A match whose field was edited after the search is skipped and marked "Changed since search"
- Entries that could not be saved are listed with the reason, and a replace that would leave a required field empty is refused
- Leave single occurrences out of a replace, with a preview that shows the result without them
- Dry Run Mode: runs a replace without saving, reports how many matches would change and which rows would be skipped, writes no log and checks at most 100 rows
- A before and after preview that marks each changed word
- "Include link addresses" (and `--links` on the command line) also searches and replaces the `href` and `src` of links and images in rich text fields
- Preserve case (Pro): "widget" to "gadget" also turns "Widget" into "Gadget"
- Command line (Pro): `editrix/run/search`, `replace` and `revert`, all dry runs unless `--dry-run=0`
- `protectedFields` and `protectedSections` are enforced; searching only some entries by id (`--elements`)
- Each save a replace or a revert makes leaves a revision whose note says where it came from and which user made it
- Max concurrent searches (`maxConcurrentSearches`) and results limit (`maxSearchResults`) settings

### Security
- A search and a replace follow Craft's own permissions: a user who may use Editrix but cannot open an entry's section does not see that entry in results, cannot read its text through the preview and cannot change it. Matrix and Neo blocks follow the entry that owns them
- The text around a match is escaped before it is shown, and exported CSV files no longer let a cell that starts with `=`, `+`, `-` or `@` run as a formula
- The section and entry type lists in the search form leave out sections the user cannot open

### Fixed
- Replacing the same field on two sites of a multi-site install only changed the first site
- A regular expression with a `/` in it was refused as invalid; one that cannot finish on some text is now reported instead of read as "no match"
- A single entry that fails to load no longer aborts the whole search
- "Replace Selected" appeared in Search mode, where nothing can be replaced
- The settings page showed "Requires Pro edition" under the Matrix and Categories boxes even on the Pro edition

### Notes for Craft 4
- The database prefilter that makes a rare-word search fast on very large sites is not available on Craft 4: a search reads every entry in scope. Use a section or entry type to narrow it
- CKEditor entries embedded in a field and per-layout field handles are Craft 5 features and do not apply

## [1.1.0-beta.2] - 2026-09-22

### Added
- A working site selector (dropdown + "All Sites") in General Search, shown only on multi-site installs
- Rich-text matches that cross an HTML tag now show as read-only with an explanation, instead of silently failing to replace
- "Search & Replace" is hidden for users without the editrix:replace permission

### Changed
- Pro/Standard licensing now uses Craft's own plugin edition system instead of an env-var/settings workaround
- Category search now also matches a category's title, not just its custom fields
- The Entries/Globals/Matrix/Categories search-scope checkboxes are no longer hidden for Pro-edition users

### Fixed
- A non-regex replacement (whole-words or case-insensitive) could corrupt saved content if the replacement text contained "$" followed by digits, or a backslash
- CSV log export, and CSV result export, didn't check the same Pro/permission gates as their JSON/permission-gated counterparts
- Disabled categories, Matrix blocks, and Neo blocks could be found by Search but not written to by Replace
- Regex mode was missing the Unicode modifier, risking corrupted UTF-8 on multi-byte content and misaligned matches
- Plain-text field search mixed byte and character offsets, producing wrong highlighted context for multi-byte content
- Category/Tag search, and Segmented Search's sidebar, no longer leak state when switching between search modes
- Several other silent-failure and permission-check gaps found in a code audit

## [1.1.0-beta.1] - 2026-09-17

### Added
- Category & Tag Assignment Search (Pro): find which entries a category or tag is assigned to, optionally scoped by section
- New Operation landing screen with a Text/Categories/Tags picker, a Recent Activity panel, and a 14-day search/replace activity chart
- Pagination (5/10/25/50/100 per page) on the results table
- Bulk-replace confirmation threshold and Production Safe Mode, requiring a typed confirmation before a large or production replacement, enforced server-side as well as in the UI
- Brand Industry watermark on all Editrix CP pages

### Changed
- Standard is now the free edition; Matrix/Category search, multisite, scope filters, and CSV export moved to Pro; Regex, whole-words, case-insensitive, dry-run, logs (30-day retention), and revert stay free
- Segmented Search's Fields filter now shows one checkbox per Matrix/Neo field instead of one per nested sub-field (which could repeat names like "Title" once per block type)
- "Find & Replace" renamed to "Category search"/"Tag search" on those tools' screens
- Section filter in Category/Tag search is now a searchable, scrollable list instead of a checkbox grid
- Section filter in Category/Tag search now only lists sections that actually have a Categories (or Tags) field, since the rest could never match

### Fixed
- Search/replace not matching text that spans an HTML tag or an encoded entity (e.g. "Terms & Conditions" vs. the stored "Terms &amp; Conditions")
- Visual Diff Preview could show "no change" when a real change would occur, because it recomputed the diff independently in the browser instead of using the actual replace engine
- A replacement whose edit span crossed a tag boundary could corrupt markup - now left untouched instead

## [1.0.0-beta.1] - 2026-09-08

Beta release, compatible with Craft CMS 4.

### Added
- Search in Entries (Title + custom fields), Globals, Categories, Tags, Matrix and Neo fields
- Separate Search (read-only, exportable) and Search & Replace modes, so a plain search never risks overwriting content
- General Search and Segmented Search (Section → Entry Type → Field scoping)
- Match detail modal with full context, "Open in Craft" link, and inline replace-from-view
- Visual diff preview with confirmation before applying a single replacement
- Per-row and bulk CSV export of search results
- Field-type badges (Matrix / Neo / Text field) in results
- Search & replace operation history, split into Searches and Replacements, with CSV/JSON export and re-run
- Regular expressions, whole-word, and case-insensitive matching
- Multi-site support
- Environment indicator and granular permissions
- Spanish and English translations

### Fixed
- Search returning no results against Redactor/CKEditor rich text fields
- Matrix block replacements not persisting to the database
- Result cascading in Segmented Search scope filters
- Request URLs breaking on sites without pretty URLs enabled
