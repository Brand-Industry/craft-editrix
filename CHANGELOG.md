# Changelog

All notable changes to Editrix will be documented in this file.

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
