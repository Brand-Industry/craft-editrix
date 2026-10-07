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
| Results per search       |      3,000      |   25,000   |
| Rows per replace         |       200       |   5,000    |
| Preserve case            |        -        |     ✅     |
| Presets                  |        5        | Unlimited  |
| Protected Fields         |        -        |     ✅     |
| CLI Commands             |        -        |     ✅     |
| API & Webhooks           |        -        |     ✅     |

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Searches and the Craft queue

Editrix runs each search as a background job on Craft's queue, so a large site can't time out a web request or tie up PHP workers. The search only starts once Craft runs that job.

- **Default setup.** Craft runs queued jobs from control panel requests (`runQueueAutomatically`, on by default). That is enough for small sites, but the job runs inside a web request and is subject to its time limits.
- **Production and large sites.** Run a real queue worker. Set `runQueueAutomatically` to `false` in `config/general.php`, then either keep `php craft queue/listen` running under a process manager such as Supervisor, or call `php craft queue/run` from cron every minute:

  ```
  * * * * * cd /path/to/project && php craft queue/run
  ```

- **Craft Cloud.** The platform runs the queue for you, so there is nothing to set up.

If a search is still waiting after about 15 seconds, Editrix shows a warning that the queue may not be running. You can cancel it from the same screen.

A search returns up to 3,000 results on Standard and 25,000 on Pro (one result is one field of one entry, however many times it matches), and says so when it stops. Narrow the search (a section, an entry type, specific fields) to see the rest. To return fewer than your edition allows, set `maxSearchResults` in `config/editrix.php`; it can lower the limit but not raise it.

## Large sites and large replaces

**Searching.** Editrix asks the database for the rows whose text contains the words you searched for before it loads any entry, so a rare word on a site with hundreds of thousands of entries takes seconds instead of half an hour. It finds everything except a word that formatting splits in the middle (`Fea<b>tured</b>`). Turn that off with `'sqlPrefilter' => false` in `config/editrix.php` if it matters more than speed. A regex is only filtered when it contains plain words that every match must have; anything cleverer (alternation, groups, lookarounds) is scanned in full.

**Replacing.** A replace of more than 25 rows (`replaceQueueThreshold`) runs in the queue, with progress and a Cancel button, because saving an entry takes about half a second and a web request that outlasts the server's time limit is cut off partway. Every value is written to the log *before* its entry is saved, so a replace that is stopped, cancelled or killed can still be reverted. One replace changes at most 200 rows on Standard and 5,000 on Pro (`maxRowsPerReplace` can lower this, never raise it); a replace over the limit is refused rather than cut short.

**Reverting.** A revert puts values back one by one and never overwrites something edited since: those are left as they are and reported, and can be overwritten afterwards on purpose. Reverting twice, or after an interrupted replace, is safe.

**Saving.** Entries are saved without validation or propagation, so content that predates a field's "required" flag can still be corrected, and each site's row is replaced on its own. Set `'validateOnReplace' => true` to validate, and `'createRevisionsOnReplace' => false` to skip the revision each replace leaves. A replace that would leave a required field empty is refused. Entries that could not be saved are listed with the reason.

**A log file.** Every replace and revert also writes to `storage/logs/editrix-*.log` (which entries were changed, by whom), which survives restoring a database backup.

**Protecting content.** `'protectedFields' => ['stripeCustomerId']` and `'protectedSections' => ['legal']` make matches in them read-only: they show in a search but Replace refuses them, even when called directly.

## Command line (Pro)

Everything is a dry run unless you type `--dry-run=0`:

```bash
php craft editrix/run/search --find="old name" --sections=articles
php craft editrix/run/replace --find="old name" --replace="new name" --sections=articles
php craft editrix/run/replace --find="old name" --replace="new name" --dry-run=0
php craft editrix/run/revert 12
```

Options: `--sections`, `--entry-types`, `--fields`, `--sites` (ids or `all`), `--elements` (entry ids), `--regex`, `--case-insensitive`, `--whole-words`, `--preserve-case`, `--user` (who the replace is logged under; defaults to the first admin). `revert --force` also overwrites values edited since.

## Installation

From the root of your Craft project:

```bash
composer require brand-industry/craft-editrix
php craft plugin/install editrix
```

You can also install it from **Settings, Plugins** in the control panel.

### Updating

Some releases add database migrations, which raises the plugin's schema version. Craft compares that version with the one recorded in `config/project/project.yaml`, and if they differ, `php craft up` ends with "Your project config files were created for different versions of Craft and/or plugins". This is Craft's own check on every plugin; your content is not affected. To update without it:

1. In a development environment with `allowAdminChanges` on, run `composer update brand-industry/craft-editrix` and then `php craft up`.
2. Commit the changed `config/project/project.yaml`.
3. Deploy, and run `php craft up` there.

If the notice already appears because the file was left behind, run `php craft project-config/write` in development and commit the result.

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
