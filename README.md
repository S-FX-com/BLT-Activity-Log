# BLT Activity Log

BLT Activity Log records the changes made to a WordPress site: content created, edited, published or deleted; plugins and themes installed, updated, activated or removed; core updates; settings changes; user account changes; and sign-ins. Each entry says who did it, when, from where (admin screen, REST, cron, WP-CLI) and, for edits, exactly which fields changed. The log lives under **Tools > BLT Activity Log**, where it can be filtered by activity, user and date, and every entry opens to a detail screen that summarizes what changed. It stores no secrets, no copies of post content, and sends nothing off the site.

Part of the BLT family of WordPress plugins by S-FX.com. It is deliberately narrow: one table, one admin page, no front end, no REST API, no runtime dependencies, and nothing that needs JavaScript to work.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- Single-site install. Network activation on multisite is refused with a message; activating per site logs that site only.

## Install

1. Download `blt-activity-log-X.Y.Z.zip` from the [latest GitHub release](https://github.com/S-FX-com/BLT-Activity-Log/releases/latest). Use the release asset, not the "Source code" archive.
2. In WordPress go to **Plugins > Add New Plugin > Upload Plugin**, upload the zip and activate it.
3. Open **Tools > BLT Activity Log**.

Updates arrive through the normal Plugins screen. The plugin checks GitHub once a day, and **Settings > Updates > Check for Updates** checks on demand.

## What works out of the box

| Area | Default behavior |
| --- | --- |
| Posts and pages | Created, edited, published, scheduled, trashed, restored, deleted. Field-level changes, a summary of block changes (Bricks element changes when Bricks is active), category and tag changes, and the custom fields that matter. Autosaves, revisions and auto-drafts are never logged. |
| Media | Uploads, edits to title, caption, description and alt text, deletions. |
| Categories and tags | Created, edited, deleted, with name, slug, parent and description changes. |
| Menus, widgets and Customizer | Menus created, renamed, edited and deleted; menu locations; widget areas; theme settings saved in the Customizer. |
| Plugins and themes | Installed, updated (from and to versions, and whether it was manual or automatic), activated, deactivated, switched, deleted, auto-updates turned on or off. |
| WordPress updates | Core updated, with from and to versions. |
| Settings | The options on the General, Writing, Reading, Discussion, Media, Permalinks and Privacy screens, with the old and new value of each. Passwords and keys show as changed but hidden. |
| Users | Accounts created, edited and deleted; role changes; password changes and resets; application passwords created and revoked. No secret value is ever stored. |
| Sign-ins | Signed in and signed out. Failed sign-ins are off by default (a brute-force attack would flood the log). |
| Out-of-band changes | A daily snapshot notices a plugin, theme or core version that changed outside WordPress (for example by FTP) and logs it as "changed outside WordPress". |
| Retention | Entries older than 90 days are removed once a day, in small batches that cannot time out. Adjustable per site, from 1 to 365 days. |
| IP address | Stored in full. Switch to anonymized or off in Settings. Cloudflare's `CF-Connecting-IP` is trusted only when the request really comes from a Cloudflare address. |
| Access | Administrators can read the log. Settings, export and clearing the log need `manage_options`. |
| Export and dump | **Export CSV** on the Log tab streams the filtered log, up to 50,000 rows. **Download a full dump** on the Settings tab streams every entry, unfiltered and uncapped, with every column including the full details, for keeping a copy before entries age out. Cells that could be read as spreadsheet formulas are neutralized in both. |
| Privacy | A suggested privacy policy paragraph, a personal data exporter, and an eraser that removes the IP address and browser from a person's entries (the entries stay, as a security record). |

Entries that change nothing are not logged: a save that altered no tracked field produces no row. Rapid repeat saves by the same person (the block editor saves a post and then its meta boxes in a second request) merge into one entry within a 60-second window.

## Customising

### Settings

**Tools > BLT Activity Log > Settings** (needs `manage_options`):

| Setting | Default | Notes |
| --- | --- | --- |
| What to log | Everything except failed sign-ins | One toggle per category. Changes to this plugin's own settings, clearing the log and exporting it are always recorded. |
| Skip these post types | none | Added to the built-in exclusions. WooCommerce orders and ACF field definitions are already excluded; the `blt_activity_log_post_types` filter adds them back. |
| Settings changes | WordPress settings screens | Or **any option changed by a signed-in user**, which also catches other plugins' settings forms. Noisier. Changes from cron and from visitors are never logged. |
| IP address | Store in full | Or anonymized, or off. Applies to new entries. |
| Browser | Stored | The user agent, shortened to 255 characters. |
| Keep entries for | 90 days | 1 to 365. There is no "forever": download a dump before entries age out if you need a longer record. |
| Dump | | **Download a full dump** (also offered before you clear the log). |
| On uninstall | Keep everything | See [Data](#data). |

### Constants

Define these in `wp-config.php`.

| Constant | Effect |
| --- | --- |
| `BLT_ACTIVITY_LOG_RETENTION_DAYS` | Fixes retention (for compliance), from 1 to 365; anything outside that range is clamped, and 0 reads as the 90-day default. The setting then shows as locked. |
| `BLT_ACTIVITY_LOG_GITHUB_TOKEN` | Only for a private fork of the repository: a read-only GitHub token for the updater. Not needed for the public release. |

### Writing entries from another plugin

Other plugins write entries with an action, so they never need to check whether BLT Activity Log is installed:

```php
do_action(
	'blt_activity_log_record',
	array(
		'category'     => 'blt_secure',
		'action'       => 'waf_rule_deployed',
		'object_type'  => 'cloudflare_rule',
		'object_key'   => 'blt-secure-block-xmlrpc',
		'object_label' => 'Block XML-RPC',
		'details'      => array( 'summary' => __( 'Deployed WAF rule "Block XML-RPC".', 'blt-secure' ) ),
	)
);
```

Register the category with the `blt_activity_log_categories` filter, otherwise the entry is filed under "Other". The entry goes through the same redaction, size caps and `blt_activity_log_should_log` filter as the built-in ones, and a plugin cannot write into a built-in category.

### Hooks

Every public action and filter is documented in [HOOKS.md](HOOKS.md).

### Roles and capabilities

| Capability | Granted to | Allows |
| --- | --- | --- |
| `blt_view_activity_log` | Administrator, on activation | Opening the log and entry details. Grant it to another role to let them read the log. |
| `manage_options` | Administrator (core) | Settings, CSV export, clearing the log. |

There is no new role.

## Development

```
composer install      # dev tools only: phpcs, WPCS, PHPUnit
composer lint         # php -l on every file
composer phpcs        # WordPress-Extra, PHPCompatibilityWP 7.4+
composer test         # PHPUnit, no WordPress install required
node bin/make-pot.js  # regenerate languages/blt-activity-log.pot
```

Pure logic (diffs, redaction, status mapping, coalescing, CIDR matching, query building, CSV escaping, retention math) lives in classes with no WordPress dependency and is tested against known vectors. The WordPress-coupled behavior is exercised against a real WordPress install; the checklist is in [tasks/todo.md](tasks/todo.md).

CI (`.github/workflows/ci.yml`) runs the syntax check on PHP 7.4 to 8.3, phpcs, the test suite, the version-sync check and a POT drift check. A merge to `main` publishes the release (`.github/workflows/release.yml`): the version is bumped in the plugin header, `BLT_ACTIVITY_LOG_VERSION` and the `readme.txt` stable tag, and `blt-activity-log-X.Y.Z.zip` is built with one top-level `blt-activity-log/` folder and the update checker inside.

Repository conventions for contributors and AI sessions are in [CLAUDE.md](CLAUDE.md), the operational checklist in [AGENTS.md](AGENTS.md), and the family rules in [CONVENTIONS.md](CONVENTIONS.md) and [DESIGN.md](DESIGN.md).

## Architecture notes and decisions

- **Buffer, then write once.** Listeners buffer entries; the buffer is merged per request and written on `shutdown` in one multi-row insert. A failed write never reaches the visitor: it costs one `error_log()` line.
- **A page view costs nothing.** Nothing queries the database at boot (settings are autoloaded) and no listener does work unless its hook fires. A front-end page view runs zero queries from this plugin.
- **Redaction is by key.** Option names, meta keys and array keys matching the redaction pattern are recorded as changed with the value hidden. Keys are normalized first (`accessToken`, `smtp.password` and `Client Secret` match like `access_token`). A short list (password hashes, session tokens, application password values, `post_password`) is always hidden.
- **A year at most, and no "forever".** The log is a working record, so retention runs from 1 to 365 days and a value of 0 or text is refused rather than read as "keep everything" or "delete everything". A site that needs a longer record downloads a full dump before entries age out; the dump is the stored data itself (including each entry's details), not the on-screen export.
- **Content is summarized, not stored.** WordPress revisions already keep post content. An entry stores character counts and a block histogram, and links to the revision comparison when revisions exist.
- **Tools submenu.** The log is a submenu of Tools, not a top-level menu. The BLT family layer accepts a relative menu URL (`tools.php?page=blt-activity-log`), so no change to the shared layer was needed.
- **`WP_List_Table` inside a `.blt-card`.** The design system has no table component. The log uses core's list table, styled with the design tokens in `admin/assets/blt-activity-log-admin.css`, so keyboard behavior, sorting, pagination and Screen Options are core's.
- **Public repository.** The updater reads public releases, so no GitHub token is declared and the family `groups` list is empty.
- **WordPress's own bookkeeping is not a setting change.** Rewrite rules, cron, transients, theme-switch bookkeeping, caches, upgrade flags and role definitions are never logged, even in the "any option" scope. Filter `blt_activity_log_ignored_options` to change the list.
- **One writer at a time per entry.** Two requests saving the same object within the merge window update one row. The update names the version it read, a request that loses re-reads and merges on top (three tries), and a save that keeps losing becomes its own row instead of being lost.
- **Revert detection is exact.** A content summary carries a short hash of the content before and after, so a save that puts the content back drops the field (nothing to report), while a character count alone would have mistaken "Monday" to "Friday" to "Sunday" for a revert.
- **The snapshot moves only what a logged change touched.** An update logs one plugin and refreshes only that plugin, so an unrelated change made earlier outside WordPress is still reported at the next daily check.
- **Clearing the log is bound to its preview.** The preview records the newest entry it counted and the delete stops there, so what is removed is what was shown.
- **Local copy of Cloudflare range matching.** `BLT_Activity_Log_Ip` duplicates the matching in BLT Secure rather than adding to the shared layer. A candidate for `includes/blt-family/` later.

## Known limitations

- **Menu items edited in the Customizer or through REST.** The menu entry is written when WordPress saves the menu itself (the Appearance > Menus screen). An edit that changes only menu items through the Customizer or the REST endpoints may not produce an entry.
- **A failed sign-in records what was typed.** With the optional failed sign-in log on, the attempted username is stored (cut to 60 characters). People sometimes type a password into that box, so the setting says so. Leave it off unless you need it.
- **Single site.** Multisite is not supported yet (see Roadmap).
- **Not tamper-proof.** See Non-goals.

## Data

- **Table:** `{prefix}blt_activity_log_entries`.
- **Options:** `blt_activity_log_settings`, `blt_activity_log_db_version` (autoloaded), `blt_activity_log_snapshot`, `blt_activity_log_state`.
- **Capability:** `blt_view_activity_log`. **Cron:** `blt_activity_log_prune` and `blt_activity_log_snapshot`, both daily, plus a one-off continuation when a prune runs out of time.
- **Deactivation** unschedules the cron events and keeps all data.
- **Dump** is a download, not a stored file: nothing is written to the server.
- **Uninstall** deletes nothing unless **Delete all plugin data when the plugin is deleted** is on. When it is, the table, the four options, the capability, the screen-option setting of each person and the cron events are removed. The shared BLT family store is never touched.
- **Multisite:** deferred. Network activation is refused.

## Non-goals

- Email, Slack or Teams alerts (the `blt_activity_log_logged` action exists so this can be added later).
- A dashboard widget, charts or a live view.
- Undo or rollback of any change.
- Tamper-proof logging. An administrator with database access, or who deactivates the plugin, can affect the log. Deactivation and clearing are themselves logged.
- File integrity monitoring, failed-login lockouts or firewalling (see BLT Secure).
- Comments, WooCommerce orders, form submissions, ACF field-group changes.
- Storing full copies of post content.
- Shipping logs to an external SIEM, syslog or Cloudflare.
- A REST API, front-end output, shortcode, block or Bricks element.
- A multisite network-wide view.

Keep the plugin narrow.

## Roadmap

- Other BLT plugins (Optimized, Secure) emit `blt_activity_log_record` for their own events.
- A hash chain over entries, for tamper evidence.
- Alerts, built on `blt_activity_log_logged`.
- Multisite support.

## License

GPL-2.0-or-later. Copyright S-FX.com. See [LICENSE](LICENSE).
