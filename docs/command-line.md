# Command line (Pro)

Searching, replacing and reverting from the terminal, for work that belongs in a deployment or in cron. Every command is a dry run unless you type `--dry-run=0`.

```bash
php craft editrix/run/search --find="old name" --sections=articles
php craft editrix/run/replace --find="old name" --replace="new name" --sections=articles
php craft editrix/run/replace --find="old name" --replace="new name" --dry-run=0
php craft editrix/run/revert 12
```

## Options

| Option | Effect |
| --- | --- |
| `--find` | The text to find |
| `--replace` | The replacement. Empty deletes the matched text. |
| `--sections` | Comma-separated section handles |
| `--entry-types` | Comma-separated entry type handles |
| `--fields` | Comma-separated field handles (`title` counts) |
| `--sites` | Comma-separated site ids, or `all`. Defaults to the primary site. |
| `--elements` | Comma-separated entry ids, to search only those |
| `--regex` | Treat `--find` as a regular expression |
| `--case-insensitive` | Ignore case when matching |
| `--whole-words` | Match whole words only |
| `--preserve-case` | Give the replacement the capitalisation of what it replaces |
| `--dry-run` | `1` (default) reports only. `0` changes content. |
| `--user` | The user a replace is logged under: id, username or email. Defaults to the first admin. |
| `--limit` | How many matches to list. Default 20. |
| `--force` | On `revert`, also overwrite values edited since |

The same limits apply as in the control panel: the rows per replace of your edition, and `protectedFields` and `protectedSections`. A replace from the command line is logged and can be reverted from **Logs & History**.
