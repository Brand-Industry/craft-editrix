# Editrix tests

Two suites. Neither runs in CI yet; run them by hand before tagging a release.

## Frontend (Vitest)

Covers the search polling in `useSearch.js`: progress, the result-cap notice, cancel, stale responses and the "queue isn't running" warning.

```bash
cd buildchain
npm test
```

## Backend (PHPUnit, against a real Craft site)

These are integration tests. They boot a Craft project with Editrix installed and use its database and real entries, so they need a project to run in. They create and delete their own rows in `editrix_searches` and never touch other jobs in the project's queue. Don't point them at production; the bootstrap refuses when `CRAFT_ENVIRONMENT` is `production`.

The replace/revert tests edit a real entry title and restore it afterwards. Craft keeps a revision for each save, so a few revision rows named "EditrixQA…" stay behind; the live entry is unchanged.

Each test limits its scan to one section, picking the smallest one that fits, so they stay fast on a large site. A test skips itself when the site's data can't exercise it (for example, the mid-scan cancel test needs a section with 800 or more entries).

1. Install PHPUnit once. It lives in `tests/`, apart from the plugin's `composer.json`, so it never reaches a client's `vendor/`:

   ```bash
   composer install -d tests
   ```

2. From the Craft project's root (inside the DDEV container if the project uses one), run:

   ```bash
   php vendor/brand-industry/craft-editrix/tests/vendor/bin/phpunit \
     -c vendor/brand-industry/craft-editrix/phpunit.xml.dist
   ```

   If the plugin isn't under `vendor/`, set `EDITRIX_TEST_CRAFT_PATH` to the project root and adjust the paths.

### Not covered

The controller actions (`search-start`, `search-status`, `search-cancel`) need a logged-in control panel session, so they are checked by hand in the browser.
