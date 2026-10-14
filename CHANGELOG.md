# Changelog

All notable changes to Editrix will be documented in this file.

## [2.0.0-beta.6] - 2026-10-12

### Security
- A search and a replace now follow Craft's own permissions. A user who may use Editrix but cannot open an entry's section no longer sees that entry in results, cannot read its text through the match preview, and cannot change it with Replace or Revert: those rows are skipped with the reason "you can't edit this entry in Craft". Matrix and Neo blocks follow the entry that owns them; globals and categories follow their own permissions; the category and tag assignment lists leave out entries the user cannot open. Admins, and the command line (which acts as the `--user` it logs under), are unchanged
- The text around a match is now escaped before it is shown. Before, markup inside a field (such as an `<img onerror>` typed by an editor) ran in the browser of whoever opened the results
- Exported files (the logs CSV and the results CSV) no longer let a cell that starts with `=`, `+`, `-` or `@` run as a spreadsheet formula
- The section and entry type lists in the search form leave out sections the user cannot open in Craft, and the settings handed to the page are encoded so that markup in a site or section name cannot close the script tag
- The revert check no longer shows the current text of an entry the user cannot open in Craft, and the log list caps how many rows one request can ask for

### Fixed
- The settings page showed "Requires Pro edition" under the Matrix and Categories boxes even on the Pro edition. It now shows it only when the edition is not Pro
- Dry Run Mode did nothing in the control panel: Replace Selected saved the changes as usual. It now runs the replace without saving, reports how many matches would change and which rows would be skipped, writes no log and needs no confirmation. It checks at most 100 rows and never uses the queue
- The before and after preview marked everything between the first and the last change as changed. It now marks each changed word on its own
- A regular expression with a `/` in it, such as `https?://`, was refused as invalid; it now works
- A regular expression that cannot finish on some text used to be read as "no match", so a search quietly missed entries. Patterns are now limited to 100,000 steps per text and fail with a message saying the pattern is too complex; in a replace, only the rows it fails on are reported as not saved

### Added
- Leave single occurrences out of a replace: in a row with several matches, each one has a box (Show all matches). The row says "2 of 3 will change", the preview shows the result without the ones left out, and the log counts only what changed. Works for plain text, regular expressions with groups (`$1`), and rich text
- "Include link addresses" (and `--links` on the command line) also searches and replaces the `href` and `src` of links and images in rich text fields, where a changed domain lives. Only the text between the quotes is touched, never the markup
- Each save a replace or a revert makes leaves a revision whose note says where it came from ("Editrix replace #12") and which user made it

## [2.0.0-beta.5] - 2026-10-08

### Changed
- Editrix is now a paid plugin with two editions, Standard (USD 19) and Pro (USD 99). The free edition no longer exists; both editions can be tried in development and staging. The license is now the Craft License (`LICENSE.md`)
- The README is shorter, and the full documentation is in the `docs` folder

### Added
- Searches now run as background jobs on Craft's queue, with a progress bar and a "Cancel search" button, so large sites no longer time out or tie up PHP workers
- A warning appears when a search is still waiting to start after about 15 seconds, since Craft's queue may not be running (see "Searches and the Craft queue" in the README)
- A warning appears when a running search stops reporting progress for two minutes, which usually means the server killed the worker; the search can be cancelled from the same screen
- Searches stop at a limit by edition (Standard 3,000 results, Pro 25,000) and show a notice asking the user to narrow the search, so a broad query can't exhaust memory on a large site; on Standard the notice says what Pro allows. The `maxSearchResults` setting can lower the limit

- Only one search per user runs at a time, and `maxConcurrentSearches` (default 2) caps searches across all users, so a few broad searches can't overload a large site
- While a search runs, the search screen shows a moving progress bar and the matches found so far; reloading or leaving and coming back lands on that same search, and the Text card on the home screen shows that a search is in progress
- The wait now says what is happening at each stage (sending, waiting for the queue, counting entries, scanning, preparing results) with a small animation per stage, the time elapsed, an estimate of the time left once the pace is known, and a rotating tip
- A search keeps running when the user leaves the page: reloading returns to it, and a "Recent searches" list reopens any of the last ten (kept for a day) with its results and form as they were
- A search that reaches the server's memory limit now stops with the matches found so far and says why, instead of being killed

- After a replace, the replaced rows are marked in place instead of the whole search running again, and a summary shows how many were replaced with a link to Logs & History; reopening a saved search shows those rows as replaced
- A match whose field was edited after the search is skipped and marked "Changed since search" instead of being overwritten with the older content

- The database prefilter: a search asks for the rows whose text contains the searched words before loading any entry (about 400 entries/s for a full scan becomes seconds for a rare word); the `sqlPrefilter` setting turns it off. Verified to return exactly what a full scan returns on a production site's 14,421 articles
- The prefilter no longer reads revisions, drafts or deleted elements, which are over half the rows on a site that keeps revisions (1.4 of 2.5 million rows on a production site), and a search with a section or type chosen lists that scope's ids once instead of walking every window of the site's id range, and uses that list as the total. Measured on a site with 845,000 entries: a 27,000-entry section 22 s to 2-4 s; a 208,000-entry section 52 s to 30 s; a rare word over the whole site 54 s to under 40 s (to be measured again)
- Neo 5 support in the prefilter: Neo 5 names the owner column of its block table `primaryOwnerId`, and the search read `ownerId`, which failed on PostgreSQL sites with Neo 5 (found by running the suite on a PostgreSQL site)
- Replace runs in the queue above 25 rows, with progress and Cancel; the limit per replace is 200 rows on Standard and 5,000 on Pro, and a replace over the limit is refused rather than cut short
- Every value of a replace is written to its log before its entry is saved, so a replace that is cancelled or killed can still be reverted
- Revert restores values one by one and never overwrites an edit made since: those are left alone and reported, with an explicit second step to overwrite them; reverting twice is safe. A log file in `storage/logs/editrix-*.log` records every replace and revert
- Reloading the page, or leaving and coming back, during a queued replace brings its progress panel back, with Cancel, and ends with the usual summary
- Entries that could not be saved are listed with the reason instead of being skipped silently, and a replace that would leave a required field empty is refused
- An empty search, or a replacement identical to the search, is refused before anything is read
- Preserve case (Pro): "widget" to "gadget" also turns "Widget" into "Gadget" and "WIDGET" into "GADGET"
- Command line (Pro): `editrix/run/search`, `replace` and `revert`, all dry runs unless `--dry-run=0`
- `protectedFields` and the new `protectedSections` are enforced: their matches are read-only in a search and refused by Replace
- Searching only some entries by id (`--elements` on the command line)

### Changed
- Replace saves entries without validation or propagation (`validateOnReplace` and `createRevisionsOnReplace` settings), so old content that no longer satisfies a required field can still be corrected and each site's row is replaced on its own
- Results show one row per field instead of one per match: a word that appears four times in an article body is one row marked "4 matches", with a list of each occurrence, and selecting the row replaces every occurrence in that field, as Replace always did. The 5,000 limit now counts rows, so a field with many matches no longer uses it up, and the field's text is stored once per row
- Results no longer carry the full text of every matched field, only a short context and a fingerprint; the text is read again when a row is opened, previewed or replaced. A 1,500-row search went from about 14 MB to 1.5 MB, so a 5,000-row search is about 5 MB. Replace uses the field's current text and refuses it if the fingerprint shows it changed since the search
- A search no longer keeps the full text of every matched field in memory, only its fingerprint: on a site with 845,000 entries, a 3,000-row search peaks at about 41 MB instead of 96 MB, and each extra row costs about 3 KB
- The match preview wraps instead of being cut at the edge of the cell, which used to hide the highlighted word
- The success message after a replace counts occurrences, not fields
- Rich text fields are now searched, replaced and reverted using their stored HTML, and entries embedded in a CKEditor field are searched as their own results
- Switching between General and Segmented Search now clears the previous results

### Fixed
- Replacing the same field on two sites of a multi-site install only changed the first site
- A new search waited in the queue until the user opened another control panel page; Editrix now asks Craft to start the queue as soon as the search is queued
- Replacing in a CKEditor field that contains nested entries flattened those entries into static HTML; they are now kept intact, and Revert restores them
- A fresh install did not create the table that background searches need, so every search failed on a new site
- Replacing inside a Matrix block failed on Craft 5
- A field that has its own handle in a layout (Craft 5 lets the same field carry a different handle in each layout) was found by a search but refused by Replace with "this field cannot be replaced"; on a test site that was more than half of the matches for one word. The field is now looked up through the element's layout
- "Replace Selected" appeared in Search mode, where nothing can be replaced
- A single entry that fails to load (for example, a template error from another plugin) no longer aborts the whole search; it is logged and skipped

## [2.0.0-beta.4] - 2026-09-22

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
- Disabled categories and Matrix/Neo blocks could be found by Search but not written to by Replace
- Regex mode was missing the Unicode modifier, risking corrupted UTF-8 on multi-byte content and misaligned matches
- Plain-text field search mixed byte and character offsets, producing wrong highlighted context for multi-byte content
- Category/Tag search, and Segmented Search's sidebar, no longer leak state when switching between search modes
- Several other silent-failure and permission-check gaps found in a code audit

## [2.0.0-beta.3] - 2026-09-21

### Changed
- Section filter in Category/Tag search now only lists sections that actually have a Categories (or Tags) field, since the rest could never match

## [2.0.0-beta.2] - 2026-09-15

Craft 5 release. Standard is now the free edition; Pro adds Category & Tag Assignment Search, Matrix/Category search, multisite, scope filters, and CSV export.

### Added
- Category & Tag Assignment Search (Pro): find which entries a category or tag is assigned to, optionally scoped by section
- New Operation landing screen with a Text/Categories/Tags picker, a Recent Activity panel, and a 14-day search/replace activity chart
- Pagination (5/10/25/50/100 per page) on the results table
- Bulk-replace confirmation threshold and Production Safe Mode, requiring a typed confirmation before a large or production replacement, enforced server-side as well as in the UI
- Brand Industry watermark on all Editrix CP pages

### Changed
- Segmented Search's Fields filter now shows one checkbox per Matrix/Neo field instead of one per nested sub-field (which could repeat names like "Title" once per block type)
- "Find & Replace" renamed to "Category search"/"Tag search" on those tools' screens
- Section filter in Category/Tag search is now a searchable, scrollable list instead of a checkbox grid

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
