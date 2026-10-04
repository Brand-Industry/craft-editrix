# Editrix for Craft CMS

**The safest way to update content at scale in Craft CMS.**

Editrix is an advanced search & replace plugin that lets you find and update content across your entire Craft site with visual previews, detailed logs, and full rollback capability.

## Features

- **Powerful Search** - Find text across entries, globals, Matrix fields, and more
- **Visual Diff Preview** - See exactly what will change before you commit
- **Safe by Default** - Dry-run mode, confirmation dialogs, and environment warnings
- **Complete History** - Every operation is logged with full rollback capability
- **Multi-Site Ready** - Search across all sites or filter by specific ones
- **Scope Filters** - Target specific sections, fields, or entry types
- **Fast & Efficient** - Update thousands of entries in seconds

## Editions

| Feature                  | Standard (free) |    Pro     |
| ------------------------ | :-------------: | :--------: |
| Search Entries & Globals |       ✅        |     ✅     |
| Basic Replace            |       ✅        |     ✅     |
| Case Insensitive         |       ✅        |     ✅     |
| Regex Support            |       ✅        |     ✅     |
| Whole Words              |       ✅        |     ✅     |
| Dry Run Mode             |       ✅        |     ✅     |
| Logs & Revert            |     30 days     |  90 days   |
| Diff Preview             |      Text       |   Visual   |
| Matrix Fields            |        -        |     ✅     |
| Categories & Tags        |        -        |     ✅     |
| Multi-Site               |        -        |     ✅     |
| Scope Filters            |        -        |    Full    |
| Export Logs              |        -        | CSV + JSON |
| Presets                  |        5        | Unlimited  |
| Protected Fields         |        -        |     ✅     |
| CLI Commands             |        -        |     ✅     |
| API & Webhooks           |        -        |     ✅     |

## Requirements

- Craft CMS 4.0+
- PHP 8.0.2+

## Searches and the Craft queue

Editrix runs each search as a background job on Craft's queue, so a large site can't time out a web request or tie up PHP workers. The search only starts once Craft runs that job.

- **Default setup.** Craft runs queued jobs from control panel requests (`runQueueAutomatically`, on by default). That is enough for small sites, but the job runs inside a web request and is subject to its time limits.
- **Production and large sites.** Run a real queue worker. Set `runQueueAutomatically` to `false` in `config/general.php`, then either keep `php craft queue/listen` running under a process manager such as Supervisor, or call `php craft queue/run` from cron every minute:

  ```
  * * * * * cd /path/to/project && php craft queue/run
  ```

- **Craft Cloud.** The platform runs the queue for you, so there is nothing to set up.

If a search is still waiting after about 15 seconds, Editrix shows a warning that the queue may not be running. You can cancel it from the same screen.

Searches stop at 5,000 matches and say so in the results. Narrow the search (a section, an entry type, specific fields) to see the rest.

## Installation

## Usage

1. Navigate to **Editrix** in the control panel sidebar
2. Enter your search term and replacement text
3. Configure options (regex, case sensitivity, scope)
4. Click **Preview Changes** to see what will be affected
5. Review the results and click **Replace Selected**
6. Done! View the operation in History to revert if needed.

## Support

- Documentation: [editrix.dev/docs](https://editrix.dev/docs)
- Issues: [GitHub Issues](https://github.com/brand-industry/craft-editrix/issues)
