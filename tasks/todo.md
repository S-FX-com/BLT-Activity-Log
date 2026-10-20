# Build checklist

Phase checklists with acceptance criteria (moved here from `CLAUDE.md` Appendix A, CONVENTIONS 16.1 step 4). Each phase ends with `composer lint`, `composer phpcs` and `composer test` clean.

Legend: `[x]` done and verified; `[ ]` open. Where a check could not be made in the build environment, it says what was done instead and what is still owed.

**How things were verified.** Unit tests (`composer test`, no WordPress needed) cover the pure classes against known vectors. The WordPress-coupled behavior was exercised against a real WordPress 7.1.3 install (SQLite, one PHP process per request, real auth cookies, real `admin.php`, `admin-post.php` and `index.php` entry points, and Chromium for the screens) by scripts that are not part of the repository (fourteen suites, about 600 assertions at the last full run, all passing; the scripts and the site live in the build session's scratch space, so a fresh checkout cannot re-run them until they are moved under `tests/integration/`). What that setup cannot show, and so remains owed on a staging site: real MySQL or MariaDB, multisite, the WordPress 6.0 floor at run time, a real WordPress.org update download, and a shared host.

## Phase 0: Scaffold
- [x] Layout per CONVENTIONS 5; `DESIGN.md`, `CONVENTIONS.md`, `includes/blt-family/` and plugin-update-checker v5p7 vendored byte-identical (checked with `cmp` against BLT Secure).
- [x] Main file per CONVENTIONS 5.1: constants, family registration, update checker with `24` (admin, cron and WP-CLI only), autoloader, boot.
- [x] `release.yml` ported from BLT Secure, `.distignore`, `phpcs.xml.dist`, `phpunit.xml.dist`, `ci.yml`, `check-version-sync.sh`, `build-zip.sh`, `verify-zip.sh`.
- [x] Section 12 item 1 resolved: the family layer accepts a relative `menu` URL, so the Tools submenu needs no shared-layer change.
- [x] Version-sync check and local zip build pass (one top-level `blt-activity-log/` folder, update checker inside, dev files out).
- [ ] CI publishes the first release as **1.0.0** (decided). The repo version stays 0.1.0 and CI bumps it: put `#major` in the merge commit message or PR title (a squash merge counts the title), or run the Release workflow by hand with bump = major. The last commit on this branch carries `#major` in its subject.

## Phase 1: Core
- [x] Install (table by `dbDelta`, `maybe_upgrade` on `init`, capability, cron, network-activation refusal, `system:logging_started`), Settings, Context, Logger, Coalescer, Diff, Redactor, Ip, the `blt_activity_log_record` action.
- [x] An external `do_action` writes a row; an unknown or built-in category is filed under Other; the `should_log` filter vetoes; malformed calls are ignored.
- [x] A forced database error during flush (table missing) does not interrupt the request or show anything to the visitor; one `error_log()` line at most; the next request logs normally.
- [x] A request that calls `exit` or ends in `wp_die` still writes its entry on `shutdown`.
- [x] A page view runs zero queries from this plugin: home, search, single post, feed, REST index, `wp-login.php`, another admin screen, as visitor and as administrator (measured with a `query` filter rather than Query Monitor).
- [x] All pure-class tests in CLAUDE.md section 10 pass.

## Phase 2: Content
- [x] Modules `post`, `media`, `term`, `appearance`, including the content summary, terms, tracked meta and Bricks histogram.
- [x] One block-editor save changing title, content and a category, followed by the legacy meta-box save in a second request, produces **one** entry with all the changes; the earliest "before" is kept.
- [x] Autosaves and revisions produce nothing; a save that changes nothing produces nothing.
- [x] Trash, restore and permanent delete produce one entry each (the `__trashed` slug rename is not reported as an edit).
- [x] Bulk Edit of 20 posts produces 20 entries.
- [x] A Bricks save produces one entry with an element delta and never the JSON. (Exercised with Bricks' meta keys and constants, not against the real Bricks theme: owed on a site that has it.)
- [ ] Quick Edit on a live site (the code path is the same `wp_after_insert_post` as Bulk Edit).

## Phase 3: Code
- [x] Modules `plugin`, `theme`, `core`, and the snapshot.
- [x] A plugin update from the Plugins screen, in bulk, automatic, by uploading a replacement zip and by WP-CLI each produce one entry with correct from and to versions and method. (Driven by firing `upgrader_process_complete` with the real skin classes and real plugin files on disk; owed: one real WordPress.org update on a staging site.)
- [x] Replacing a plugin folder by hand, adding one and removing one appear as `changed_externally` after one snapshot run, and a second run does not repeat them.
- [x] This plugin's own deactivation is logged; reactivation records `logging_started` again; both cron jobs are unscheduled and the data kept.
- [x] A theme switch, update, delete and auto-update toggle are logged; the menu and widget remapping WordPress does on the next request is not logged as edits.
- [x] A core update is one entry with the version from the snapshot.

## Phase 4: Settings, users, sign-ins
- [x] Modules `setting`, `user`, `session`.
- [x] Changing Reading settings logs a field-level diff; `mailserver_pass` shows as changed but hidden; a Customizer publish logs with `via = customizer`.
- [x] No ignored option is logged in either scope; the "any option" scope catches a third-party plugin's settings form, hides a secret two levels down, and ignores cron and anonymous changes. WordPress's own bookkeeping (theme switch, caches, upgrade flags, role definitions) is ignored too.
- [x] Role change (set, add, remove), password change and reset, application password create and revoke, account create and delete are logged; a table-wide search finds none of the secrets used.
- [x] Sign-in, sign-out and failed sign-in (when switched on, username cut to 60 characters) are logged with the right actor and context.

## Phase 5: Admin
- [x] Tools page, filters, list table, entry detail, Settings tab, Clear log, CSV export.
- [x] Every filter is a plain GET and so a shareable URL; the page works with JavaScript disabled (checked in Chromium with scripting off).
- [x] The date filter is correct across a DST change: the 23-hour spring-forward day in `America/New_York` covers exactly that local day.
- [x] Compare revisions links to the revision that edit made, not the newest one; when the post or revision is gone the screen says so.
- [x] The export row count matches the on-screen count; headers are `text/csv`, attachment and `nosniff` (checked over real HTTP); a formula cell is neutralized; above the cap the export is refused with a notice, not truncated; the export is itself logged.
- [x] Nonce and capability on every action: a bad nonce or a viewer without `manage_options` gets a 403 on save, clear and export, and nothing changes. Clear log is two steps (preview, then a confirm box); a cleared log is never silently empty.
- [x] Every kind of entry that real events produce opens on its detail screen, and so does a row with an odd stored `details` structure. Labels, summaries and values are escaped; no secret appears on any screen or in the CSV.
- [x] Looks right at 1280 px and at 420 px (screenshots reviewed).
- [x] CONVENTIONS 14.4 checklist: `.blt-ui` wrapper; stylesheet on this page's hook only; composed from the catalog, `WP_List_Table` in a card (no `form-table`); toggles and badges; tokens; nonces and capabilities; text domain; "BLT" all caps. The last line (shared-store credentials) does not apply: no credentials.
- [ ] Keyboard-only pass of all admin screens (Phase 7).

## Phase 6: Lifecycle and docs
- [x] Pruner: expired rows go in time-boxed batches; a pass that runs out of time schedules a continuation with its arguments; bad continuation arguments delete nothing; retention 0 keeps everything; an overdue prune is requested by opening the log, never done in the page request.
- [x] 100,000 expired rows prune in one cron pass (about one second on SQLite). Owed: the same on MySQL on a shared host.
- [x] Retention is adjustable per site from 1 to 365 days (90 default). There is no "keep forever": 0 and text are refused and the value stays; above 365 is saved as 365; a stored or configured 0 reads as 90.
- [x] **Download a full dump** on the Retention card (and in the clear preview): every entry, unfiltered, no row cap, every column including full details, streamed in batches. Round-trips cell for cell against the table (quotes, commas, newlines, unicode, formula cells), is logged as `system:exported` scope `full dump`, and is refused with a bad nonce, for a visitor, and for a viewer without `manage_options`.
- [x] Privacy: exporter pages in batches of 100; eraser blanks IP and browser, keeps the entries, leaves other people's alone; an unknown email is a clean no-op.
- [x] Default uninstall leaves everything; uninstall with the toggle on removes the table, the four options, the capability, each person's per-page setting and every cron event, and leaves `blt_family_shared` and `blt_family_opt_in` untouched; a plugin left active after that rebuilds its table instead of failing.
- [x] `README.md`, `readme.txt`, `HOOKS.md`, `CHANGELOG.md`, `AGENTS.md`, `languages/blt-activity-log.pot`.

## Phase 7: Release
- [ ] Clean install from the release zip, then a second release to prove the update path.
- [ ] Shared-host pass (no `exec()`, WP-Cron only), a `DISALLOW_FILE_MODS` / Bedrock site, keyboard-only pass of all admin screens.
- [ ] CONVENTIONS 14.5 checklist complete; release published by CI.

## Review rounds
- [x] Round 2: four independent reviewers read the committed code with no knowledge of each other's findings. Each report was traced or reproduced before anything was changed; the fixes and their tests are summarized in `tasks/lessons.md` ("Third review round") and `CHANGELOG.md`. Unit suite and all fourteen integration suites pass.
- [x] Declined, with reasons:
  - **Migrating a stored retention of 0.** This is an unreleased plugin: nothing has stored one. A configured or stored 0 already reads as the 90-day default.
  - **A size limit on the full dump.** It is `manage_options` only, streamed in batches of 250 and bounded to the newest id at the start, so memory stays flat; a cap would defeat its purpose (keeping a complete copy).
  - **A failed-login username that is really a password.** The spec records the attempted username; the setting is off by default and says so beside the toggle.
  - **Multisite `update_site_option` misses.** Multisite is deferred and network activation refused.
- [ ] Known limitation, documented in `README.md` and `CLAUDE.md` 6.3: a Customizer or REST edit that changes only menu items may not produce a `menu_updated` entry (it fires only when the menu itself is saved).
- [ ] Move the integration scripts into `tests/integration/` so they run from the repository (offered to the owner; not done without asking).

## Open decisions (also in CLAUDE.md section 12)
- [ ] Add BLT Activity Log to the family list in `DESIGN.md` and port that change to every repo in the same pass (CONVENTIONS 16.1 step 7). `DESIGN.md` is vendored, so it is not edited here alone.
- [ ] Emit `blt_activity_log_record` from BLT Optimized and BLT Secure, after this ships.
