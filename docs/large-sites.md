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

## Keeping searches fast

**The database prefilter.** Before loading any entry, Editrix asks the database which rows contain the words you searched for. On a site with hundreds of thousands of entries, a rare word then takes seconds or under a minute instead of scanning every entry. In our tests on a site with about 845,000 entries, a rare word across the whole site finished in under a minute, and a search limited to one section took a few seconds.

The prefilter finds everything except a word that formatting splits in the middle (`Fea<b>tured</b>`). Set `'sqlPrefilter' => false` in `config/editrix.php` to turn it off. A regular expression is only prefiltered when it contains plain words that every match must have. Anything more complex, such as alternation, groups or lookarounds, is scanned in full.

**Narrow the scope.** On Pro, limit a search to a section, an entry type or specific fields.

## Limits that protect the server

- A search returns at most 3,000 rows on Standard and 25,000 on Pro. See [Searching](searching.md).
- If a search reaches the server's memory limit, it stops with the matches found so far and says why. Narrow it to a section or field to cover everything.
- Each user runs one search at a time, and `maxConcurrentSearches` caps the total.
- A replace changes at most 200 rows on Standard and 5,000 on Pro, and runs in the queue above 25 rows. See [Replacing](replacing.md).

## Entries that fail to load

If one entry throws an error while it is loaded, for example because a template used by one of its fields has a bug, Editrix logs the error with Craft's logger, skips that entry, and continues with the rest.
