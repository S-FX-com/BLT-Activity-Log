# Changelog

Release notes for BLT Activity Log. Versions are bumped by CI when a change merges to `main`; the version in the plugin header, `BLT_ACTIVITY_LOG_VERSION` and the `readme.txt` stable tag always match.

## 1.0.0

First release.

### Recording

- Posts and pages, media, categories and tags, menus, widgets and the Customizer, plugins, themes, WordPress core updates, settings, users and sign-ins, each with who did it, when, from where, and for edits which fields changed.
- Field-level diffs, a block histogram and revision link for post content, term changes, and Bricks element deltas when Bricks is active.
- A daily snapshot notices plugin, theme and core versions that changed outside WordPress.
- Redaction by key: passwords, tokens, API keys and salts are recorded as changed, with the value hidden. Values are truncated at 1,000 characters and an entry at 64 KB.
- Entries are buffered, merged within the request and across requests (60-second window), and written once on `shutdown`. A logging fault never reaches the visitor. A page view runs no queries from the plugin.
- `blt_activity_log_record` lets other plugins write entries.

### Admin

- **Tools > BLT Activity Log**: Log and Settings tabs, a filterable and sortable list (activity, user, date range in site time, search), entry detail screens, Screen Options, and CSV export (up to 50,000 rows, formula-safe).
- Settings: what to log, post types to skip, settings scope, IP handling, browser storage, retention (1 to 365 days, 90 by default, adjustable per site), delete-on-uninstall, and a two-step Clear log.
- **Download a full dump**: every entry, unfiltered and uncapped, with every column including the full details, as a CSV. Also offered before clearing the log.

### Lifecycle

- Retention pruning in time-boxed batches with a cron continuation; a 100,000-row prune finishes in one pass.
- Privacy policy text, personal data exporter and eraser.
- Deactivation keeps everything; uninstall deletes nothing unless the owner opted in, and never touches the shared BLT family store.
- GitHub-release updates through plugin-update-checker, with a manual check.
