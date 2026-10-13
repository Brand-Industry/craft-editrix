# Editions

Editrix has two paid editions. There is no free edition. Either one can be tried without charge in a development or staging environment. A license covers one production site.

| | Standard (USD 19) | Pro (USD 99) |
| --- | :---: | :---: |
| **Searching** | | |
| Entries and globals | Yes | Yes |
| Regular expressions, case insensitive, whole words | Yes | Yes |
| Matrix and Neo blocks | No | Yes |
| Categories | No | Yes |
| Category and tag assignment search | No | Yes |
| Several sites in one search | No | Yes |
| Segmented search by section, entry type and field | No | Yes |
| Rows returned per search | 3,000 | 25,000 |
| **Replacing** | | |
| Preview and dry run (up to 100 rows) | Yes | Yes |
| Leave single matches out of a replace | Yes | Yes |
| Search and replace link addresses in rich text | Yes | Yes |
| Replace, with the queue for large replaces | Yes | Yes |
| Rows changed per replace | 200 | 5,000 |
| Preserve case | No | Yes |
| Production Safe Mode | No | Yes |
| Protected fields and sections (`config/editrix.php`) | Yes | Yes |
| **History** | | |
| Logs and revert, kept for | 30 days | 90 days |
| Export logs and search results (CSV, JSON) | No | Yes |
| **Other** | | |
| Command line | No | Yes |

The limits are per operation, and `maxSearchResults` and `maxRowsPerReplace` can lower them further. They cannot raise them. See [Settings](configuration.md).

To compare editions or buy a license, open the plugin's page in the Plugin Store or switch editions in **Settings, Plugins**.
