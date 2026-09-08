# Changelog

All notable changes to Editrix will be documented in this file.

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
