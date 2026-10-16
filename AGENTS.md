# AGENTS.md

Operational checklist for working in this repository. What the plugin is, how it is built and why is in [CLAUDE.md](CLAUDE.md); the family rules are in [CONVENTIONS.md](CONVENTIONS.md) and [DESIGN.md](DESIGN.md). Do not restate them here.

## Before you start

- Read `DESIGN.md`, `CONVENTIONS.md` and `CLAUDE.md`.
- `composer install` (dev tools only: phpcs, WPCS, PHPUnit). The plugin has no runtime Composer dependencies.
- Check `tasks/todo.md` for what is open and `tasks/lessons.md` for gotchas that have already cost time.

## Never

- Edit `DESIGN.md`, `CONVENTIONS.md`, `includes/blt-family/` or `includes/lib/plugin-update-checker/` in this repo alone. They are vendored byte-identical across the BLT repos; a change is made in every repo in the same pass.
- Rename a machine identifier (option, hook, filter, capability, table, cron hook, constant) that has shipped.
- Bump the version by hand. CI bumps it (plugin header, `BLT_ACTIVITY_LOG_VERSION`, `readme.txt` stable tag) when a change merges to `main`.
- Add a runtime dependency, a new Worker or a shared-store group without asking.
- Write "blt" in lowercase in text a person reads. "BLT" is all caps; lowercase is for machine identifiers only.
- Log a secret. Anything new that records a value goes through `BLT_Activity_Log_Redactor`, and a new place that stores or shows details goes through `BLT_Activity_Log_Formatter::details()`.
- Store post content, or let a logging fault surface to the user. Every listener is wrapped by `BLT_Activity_Log_Module_Base::listen()`, which is for actions only (it returns nothing, so it cannot wrap a filter).

## Every change

1. `composer lint`
2. `composer test`
3. `composer phpcs` (before pushing)
4. A new user-facing string is wrapped in the `blt-activity-log` text domain, in American English; then `node bin/make-pot.js` and commit `languages/blt-activity-log.pot` (CI fails on POT drift).
5. A new public hook is added to `HOOKS.md` and, if it changes behavior, `CHANGELOG.md`.
6. A new pure rule gets a known-vector test in `tests/`. A new WordPress-coupled behavior is added to the checklist in `tasks/todo.md` and exercised against a real WordPress install.
7. A gotcha that cost you time goes at the end of `tasks/lessons.md`. Append; never rewrite history.

## Adding something to the log

- A new kind of event is a module (`includes/class-blt-activity-log-module-<thing>.php`, extends `BLT_Activity_Log_Module_Base`, registered in `BLT_Activity_Log_Modules::builtin()`), with labels in `BLT_Activity_Log_Formatter` for every action it emits (a test fails if one is missing).
- Verify every hook name and argument list against WordPress at the 6.0 floor before using it.
- Compare before and after with `BLT_Activity_Log_Diff`, never by hand, and return nothing when nothing changed: an empty diff is not an entry.
- Run through `BLT_Activity_Log_Logger::record()`. Give a related hook that fires beside a status change (term, meta) an `absorb` list so the request produces one entry, not several.
- Check that a front-end page view still runs zero queries from this plugin.

## Admin screens

- Wrapper `<div class="wrap blt-ui blt-activity-log">`; stylesheet enqueued on this page's hook only; components from the `DESIGN.md` catalog; tokens, not literals.
- Nonce and capability check on every action; sanitize input, escape output. State-changing forms post, then redirect (PRG) on `admin_init`.
- Check the screen at 420 px wide and with JavaScript disabled. WordPress's responsive list table only hides the columns **after** the primary column.

## Release

- Merging to `main` publishes the release. `.github/scripts/verify-zip.sh` checks the zip: one top-level `blt-activity-log/` folder, the update checker inside, no dev files.
- Install the zip on a clean test site, then ship a second release to prove the update path.
- Uninstall behavior is tested whenever data storage changes: default removes nothing; opt-in removes the table, the four options, the capability and the cron events, and leaves `blt_family_shared` and `blt_family_opt_in` alone.
