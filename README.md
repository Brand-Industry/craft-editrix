# Editrix for Craft CMS

**The safest way to update content at scale in Craft CMS.**

Editrix is an advanced search & replace plugin that lets you find and update content across your entire Craft site with visual previews, detailed logs, and full rollback capability.

## Features

- 🔍 **Powerful Search** - Find text across entries, globals, Matrix fields, and more
- 👁️ **Visual Diff Preview** - See exactly what will change before you commit
- 🔒 **Safe by Default** - Dry-run mode, confirmation dialogs, and environment warnings
- 📝 **Complete History** - Every operation is logged with full rollback capability
- 🌐 **Multi-Site Ready** - Search across all sites or filter by specific ones
- 🎯 **Scope Filters** - Target specific sections, fields, or entry types
- ⚡ **Fast & Efficient** - Update thousands of entries in seconds

## Editions

| Feature | Lite (Free) | Standard | Pro |
|---------|:-----------:|:--------:|:---:|
| Search Entries & Globals | ✅ | ✅ | ✅ |
| Basic Replace | ✅ | ✅ | ✅ |
| Case Insensitive | ✅ | ✅ | ✅ |
| Logs & Revert | 7 days | 30 days | 90 days |
| Matrix Fields | - | ✅ | ✅ |
| Categories | - | ✅ | ✅ |
| Regex Support | - | ✅ | ✅ |
| Whole Words | - | ✅ | ✅ |
| Multi-Site | - | ✅ | ✅ |
| Scope Filters | - | Sections, Sites | Full |
| Diff Preview | - | Text | Visual |
| Dry Run Mode | - | ✅ | ✅ |
| Export Logs | - | CSV | CSV + JSON |
| Presets | - | 5 | Unlimited |
| Protected Fields | - | - | ✅ |
| CLI Commands | - | - | ✅ |
| API & Webhooks | - | - | ✅ |
| Monthly Operations | 50 | Unlimited | Unlimited |

## Requirements

- Craft CMS 4.0+
- PHP 8.0.2+

## Installation

```bash
composer require brand-industry/craft-editrix
```

Then install the plugin from **Settings → Plugins** or run:

```bash
./craft plugin/install editrix
```

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

## License

Editrix Lite is free to use. Standard and Pro editions require a license.

- Standard: $59/project
- Pro: $149/project

Purchase at [editrix.dev](https://editrix.dev)


