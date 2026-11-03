# Large sites and the queue

## The queue

Each search runs as a background job on Craft's queue, so a large site cannot time out a web request or tie up PHP workers. A search starts once Craft runs that job.

- **Default setup.** Craft runs queued jobs from control panel requests (`runQueueAutomatically`, on by default). That is enough for small sites, but the job runs inside a web request and is subject to its time limits.
- **Production and large sites.** Run a real queue worker. Set `runQueueAutomatically` to `false` in `config/general.php`, then either keep `php craft queue/listen` running under a process manager such as Supervisor, or call `php craft queue/run` from cron every minute:

  ```
  * * * * * cd /path/to/project && php craft queue/run
  ```

- **Craft Cloud.** The platform runs the queue for you, so there is nothing to set up.

If a search is still waiting after about 15 seconds, Editrix shows a warning that the queue may not be running. You can cancel the search from the same screen.

## Before a large replace

Make a database backup before you run a large replace. The log lets you revert the values that were saved, but if the server stops halfway, a backup is the only complete copy of your content. Editrix reminds you in the confirmation window when a replace is above the `bulkConfirmationThreshold` setting (100 rows by default).

A backup is also worth having before the first replace on a site, and before any replace in production.

## Keeping searches fast

**No database prefilter on Craft 4.** Craft 4 keeps the text of each field in its own column of the `content` table, so Editrix cannot ask the database which rows contain the words and reads every entry in scope instead. On a site with hundreds of thousands of entries a search over everything takes minutes. Narrow it with a section or an entry type; the queue and the limits below still protect the server.

**Narrow the scope.** On Pro, limit a search to a section, an entry type or specific fields.

## Limits that protect the server

- A search returns at most 3,000 rows on Lite and 25,000 on Pro. See [Searching](searching.md).
- If a search reaches the server's memory limit, it stops with the matches found so far and says why. Narrow it to a section or field to cover everything.
- A search stops after `maxSearchSeconds` (300 by default) with the matches found so far, and says why. Raise it, or set `0`, if your server can take longer searches.
- Each user runs one search at a time, and `maxConcurrentSearches` caps the total.
- A replace changes at most 200 rows on Lite and 5,000 on Pro, and runs in the queue above 25 rows. See [Replacing](replacing.md).
- A revert of more than 25 values runs in the queue too. If you reload the page while it runs, Logs & History picks it up again. The dialog shows how many values are restored and how long it has been running, and has a **Stop revert** button. Values not reached stay in the log, so you can revert again later.
- Before a revert, Editrix checks which values can still be restored. On a very large log it checks for up to 20 seconds and says how many it covered. The revert checks every value again and never overwrites an edit made since.

## Entries that fail to load

If one entry throws an error while it is loaded, for example because a template used by one of its fields has a bug, Editrix logs the error with Craft's logger, skips that entry, and continues with the rest.
