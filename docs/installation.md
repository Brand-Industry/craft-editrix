# Installation and updating

## Requirements

- Craft CMS 4.0 or later
- PHP 8.0.2 or later

## Install

From the root of your Craft project:

```bash
composer require brand-industry/craft-editrix
php craft plugin/install editrix
```

You can also install it from **Settings, Plugins** in the control panel.

An **Editrix** item appears in the control panel sidebar with three pages: **New Operation**, **Logs & History** and **Settings**.

## Choose an edition

Editrix has two paid editions, Standard and Pro. Craft lets you try either one in a development or staging environment before you buy a license. Switch editions in **Settings, Plugins**. See [Editions](editions.md) for what each one includes.

A license covers one production site.

## Permissions

Admins can use everything. For other users, turn on the permissions they need under **Settings, Users, User Groups** (or on the user's own permissions page), in the Editrix section:

| Permission | Allows |
| --- | --- |
| Search content | Run searches |
| Search and replace content | Run searches and replace |
| Revert changes from logs | Revert an operation from Logs & History |
| Delete logs | Delete log entries |
| Export logs | Export logs and search results (Pro) |

Editrix's permissions say who may use the tool. Craft's own permissions still decide which entries a person can reach with it: someone who cannot view or save a section in Craft cannot see or change its entries through Editrix either.

## Updating

```bash
composer update brand-industry/craft-editrix
php craft up
```

Some releases add database migrations, which raises the plugin's schema version. Craft compares that version with the one recorded in `config/project/project.yaml`. If they differ, `php craft up` ends with this notice:

> Your project config files were created for different versions of Craft and/or plugins

This is Craft's own check, the same one it runs for every plugin. Your content is not affected. To update without the notice:

1. In a development environment with `allowAdminChanges` on, run `composer update brand-industry/craft-editrix` and then `php craft up`.
2. Commit the changed `config/project/project.yaml`.
3. Deploy, and run `php craft up` there.

If the notice already appears because the file was left behind, run `php craft project-config/write` in development and commit the result.

## The queue

Searches and large replaces run as jobs on Craft's queue. A default Craft install runs the queue from control panel requests, which is enough to try the plugin. For a production site, set up a real queue worker. See [Large sites and the queue](large-sites.md).
