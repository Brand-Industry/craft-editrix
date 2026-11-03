# Settings

Some settings are on **Editrix, Settings** in the control panel. All of them can be set in `config/editrix.php`, which takes precedence:

```php
<?php

return [
    'maxConcurrentSearches' => 2,
    'protectedFields' => ['stripeCustomerId'],
    'protectedSections' => ['legal'],
];
```

## Search

| Setting | Default | Effect |
| --- | --- | --- |
| `searchEntries` | `true` | Search entries by default |
| `searchGlobals` | `true` | Search globals by default |
| `searchMatrix` | `true` | Search Matrix and Neo blocks by default (Pro) |
| `searchCategories` | `false` | Search categories by default (Pro) |
| `searchableFieldTypes` | Plain Text, Textarea, Redactor, CKEditor | Field classes whose content is searched |
| `maxSearchResults` | `null` | Lowers the rows a search returns below your edition's limit. It cannot raise it. |
| `maxConcurrentSearches` | `2` | Searches that may run at once across all users |
| `sqlPrefilter` | `true` | Ask the database for candidate rows first. See [Large sites](large-sites.md). |
| `maxSearchSeconds` | `300` | Seconds one search may run. After that it stops and shows the matches found so far. Use `0` for no limit. |

## Replace

| Setting | Default | Effect |
| --- | --- | --- |
| `maxRowsPerReplace` | `null` | Lowers the rows one replace may change below your edition's limit. It cannot raise it. |
| `replaceQueueThreshold` | `25` | A replace of more rows than this runs in the queue |
| `bulkConfirmationThreshold` | `100` | A replace of more rows than this asks for the confirmation word and a reminder to back up the database |
| `productionSafeMode` | `false` | Ask for the confirmation word on every replace in production (Pro) |
| `validateOnReplace` | `false` | Validate an entry before saving it |
| `createRevisionsOnReplace` | `true` | Leave a revision on the entries a replace changes |
| `protectedFields` | `[]` | Field handles that Replace never changes |
| `protectedSections` | `[]` | Section handles whose entries Replace never changes |

Protected fields and sections are not hidden from searches. Their matches show as read-only.

## Logs and interface

| Setting | Default | Effect |
| --- | --- | --- |
| `logRetentionDays` | `30` | Days to keep logs, from 1 to 365. Your edition caps the number you can set: 30 on Lite, 90 on Pro. Logs older than this are removed when Craft runs garbage collection (`craft gc`). If you move to a smaller edition, the number you had stays until you lower it. |
| `showEnvironmentIndicator` | `true` | Show the current environment in the interface |
