# Searching

Open **Editrix, New Operation**. The home screen offers a text search and, in Pro, a category search and a tag search.

## What is searched

A text search reads:

- Entry titles
- Plain Text and Textarea fields
- CKEditor and Redactor fields
- Globals
- Matrix and Neo blocks (Pro)
- Categories (Pro, off by default)

The field types that are searched are listed in the `searchableFieldTypes` setting. Drafts, revisions and deleted entries are not searched.

## Options

| Option | Effect |
| --- | --- |
| Regular expression | Treats the text as a PCRE pattern. An invalid pattern shows "Invalid regular expression" and nothing runs. |
| Case insensitive | "hotel" also finds "Hotel" and "HOTEL". Without it, case must match. |
| Whole words | "cat" does not match inside "concatenate". |
| Include link addresses | Also searches the `href` of links and the `src` of images in rich text fields, where a changed domain lives. Only the text between the quotes is searched, never the markup around it. The address is matched as written in the HTML, so `a&b` does not match `a&amp;b`. |
| Dry Run Mode | Replace Selected becomes **Run dry run**: it reports what a replace would change, for up to 100 rows, and saves nothing. See [Replacing](replacing.md). |

The checkboxes for Entries, Globals, Matrix fields and Categories choose where to look. Their defaults are set in [Settings](configuration.md).

## Whose permissions apply

A search shows what the person running it may open in Craft. An entry in a section they cannot view is left out of their results, as are the Matrix and Neo blocks it owns, and the globals and categories they have no access to. Admins see everything. Editrix's own permissions say who may use the tool, and Craft's say which content they may reach with it.

## Limiting the search

On Standard, a search covers every section. On Pro you can switch to **Segmented Search** and narrow it to:

1. One or more sections
2. One or more entry types of those sections
3. Specific fields (`title` counts as a field)

The side panel shows how many entries fall inside the scope. A narrower scope finishes much faster on a large site.

With several sites, Pro adds a site picker with an **All Sites** option. Results are grouped by site.

## Reading the results

Results show one row per field, per site. A field where the text appears four times is one row marked "4 matches". Open the row to see each occurrence in context, then **Show all matches** to list them.

A row shows the entry, its section, the field, and a short context with the match highlighted. Open a row to read the full text of the field.

A search stops at a limit: 3,000 rows on Standard and 25,000 on Pro. Editrix says so when it stops, and a narrower search shows the rest. You can lower the limit with the `maxSearchResults` setting but not raise it. The limit counts rows, not matches, so a field with many matches counts once.

## While a search runs

A search runs on the server, in Craft's queue. The screen shows the stage it is in, the number of entries scanned, the matches found so far, the time elapsed and an estimate of the time left.

- You can leave the page. The search keeps running, and reloading brings you back to it.
- **Recent searches** lists your last ten searches, kept for one day. Open one to see its results and form as you left them.
- Each user can run one search at a time. Across all users, `maxConcurrentSearches` (default 2) caps how many run together.
- **Cancel search** stops it.

## Category and tag assignment search (Pro)

Enter the name of a category or tag to see which entries it is assigned to, optionally limited to some sections. This is a lookup of relationships, not a text search, and it only reports: it does not add or remove assignments.

## What a search can miss

On Craft 4 a search reads every entry in scope, so it finds a word wherever it is stored, including a word that formatting splits in the middle, such as `Fea<b>tured</b>`. On a large site, limit the search to a section or an entry type to keep it fast. See [Large sites and the queue](large-sites.md).
