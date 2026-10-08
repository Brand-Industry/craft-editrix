# Troubleshooting

## "This search is still waiting to start"

Craft's queue may not be running. A search waits for a queue worker to pick it up, and Editrix shows this warning after about 15 seconds. Cancel the search, then set up a worker or a cron job as described in [Large sites and the queue](large-sites.md).

## "This search has stopped reporting progress"

The search reported nothing for two minutes, which usually means the server killed the worker. Cancel it and run a narrower search, for example limited to one section.

## "Search stopped early to protect the server (memory)"

The search reached the server's memory limit. It shows the matches found so far. Narrow it to a section or field to cover everything.

## "Showing the first N results"

The search reached the limit of your edition (3,000 rows on Standard, 25,000 on Pro) or the `maxSearchResults` you set. Use a more specific phrase, or narrow the scope.

## "You already have a search running"

Each user can run one search at a time. Open the running search from **Recent searches**, or cancel it before starting another.

## "Other searches are using the server right now"

The `maxConcurrentSearches` limit is reached. Try again in a few minutes, or raise the setting if your server can take it.

## A row is marked "Changed since search"

The field was edited after your search, so Editrix skipped it rather than overwrite newer content. Search again to see the current text.

## A replace is refused

- **Too many rows.** You selected more rows than your edition allows in one replace (200 on Standard, 5,000 on Pro). Replace in several batches.
- **This field cannot be replaced.** The field's type is not one Editrix writes to, or the field is no longer in the entry's layout.
- **Protected.** The field or its section is in `protectedFields` or `protectedSections`. Those rows are skipped and listed as protected.
- **Read-only match.** The text runs across an HTML tag in a rich text field. See [Replacing](replacing.md).
- **A required field would be left empty.** The entry is skipped and listed with the reason.

## A search does not find a word that is on the page

A word split by formatting, such as `Fea<b>tured</b>`, is not found when the database prefilter is on. Set `'sqlPrefilter' => false` in `config/editrix.php`. Also check that the field's type is in `searchableFieldTypes`, and that the entry is not a draft or revision.

## "Your project config files were created for different versions of Craft and/or plugins"

Craft shows this after an update that raised the plugin's schema version. It does not affect your content. See [Installation and updating](installation.md).

## An entry is missing from the results

If one entry throws an error while it is loaded, Editrix skips it and continues. The error is in `storage/logs/web.log` or `console.log`, with the entry's id. Fix the template or field that throws and search again.
