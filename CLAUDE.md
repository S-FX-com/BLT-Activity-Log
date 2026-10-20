# BLT Activity Log

Repo instructions for AI coding sessions and for people. Read `DESIGN.md`, `CONVENTIONS.md` and this file before writing code.

This file states only what is specific to BLT Activity Log. It does not restate family rules. Where it is silent, `CONVENTIONS.md` governs. Where it seems to conflict with `CONVENTIONS.md` or `DESIGN.md`, stop and flag it (CONVENTIONS 16.3, rule 9). Open conflicts are listed in section 12.

| | |
| --- | --- |
| Status | Built through phase 6 of `tasks/todo.md` (release phase still open); version 0.1.0 in the repo, the first release is 1.0.0 (CI bumps on merge to `main`; use `#major`). October 16, 2026. |
| Repository | `S-FX-com/BLT-Activity-Log` |
| Slug / text domain | `blt-activity-log` |
| Floors | PHP 7.4, WordPress 6.0 (family default, no reason to raise) |
| Cloudflare footprint | None |
| External services | None |
| Public surface | None (no shortcode, block, Bricks element or REST route). One action hook for other plugins to write entries. |

---

## 1. What it is

BLT Activity Log records changes made to the WordPress site it runs on: content created, edited, published or deleted; plugins and themes installed, updated, activated or removed; core updates; settings changes; user account changes; and sign-ins. Each entry records who did it, when, from where (admin screen, REST, cron, WP-CLI) and, for edits, exactly which fields changed. The log lives under **Tools > BLT Activity Log**, where it can be filtered by activity, user and date, and each entry opens to a detail view summarizing what changed.

**Keep the plugin narrow.** One table, one admin page, no front end, no REST API, no runtime dependencies, nothing that needs JavaScript to work. Every feature request is tested against section 11 before it is accepted.

---

## 2. Pre-flight answers (CONVENTIONS 16.2)

| Question | Answer |
| --- | --- |
| External service needed? | No. Everything runs inside WordPress. |
| Credentials held? | None. The repository is public, so the updater needs no token; a private fork can define `BLT_ACTIVITY_LOG_GITHUB_TOKEN`. |
| Capabilities and roles | New capability `blt_view_activity_log`, granted to Administrator on activation. Settings, export and clearing the log require `manage_options`. No new role. |
| Uninstall | Deletes nothing unless "Delete all plugin data" is enabled (section 8.3). |
| Multisite | Deferred. Per-site activation logs that site only. Network activation is refused with a message. |
| Long-running jobs | Retention pruning and the daily version snapshot. Both WP-Cron, time-boxed, and naturally resumable (each batch is complete on its own). Nothing to cancel in the UI. |
| Public surface | None. Other plugins write entries through `do_action( 'blt_activity_log_record', ... )`. |
| v1 non-goals | Section 11. |

---

## 3. Identity

The naming matrix from CONVENTIONS 4.1, filled in.

| Thing | Value |
| --- | --- |
| Display name | BLT Activity Log |
| Main file | `blt-activity-log.php` |
| Constants | `BLT_ACTIVITY_LOG_VERSION`, `_FILE`, `_DIR`, `_URL`, `_BASENAME`. No `_REST_NS`. |
| Optional wp-config constants | `BLT_ACTIVITY_LOG_GITHUB_TOKEN`, `BLT_ACTIVITY_LOG_RETENTION_DAYS` (locks retention to 1 to 365 days; the setting shows as read-only) |
| Classes | `BLT_Activity_Log_<Thing>`, no namespace |
| Class files | `includes/class-blt-activity-log-<thing>.php`, admin classes in `admin/`. **Modules stay flat in `includes/`** (`class-blt-activity-log-module-posts.php`) because the computed autoloader probes only `includes/` and `admin/`. |
| Functions, hooks, filters | `blt_activity_log_*` |
| Options | `blt_activity_log_settings` (autoload yes), `blt_activity_log_db_version` (autoload yes), `blt_activity_log_snapshot` (autoload no), `blt_activity_log_state` (autoload no: last prune time, counters) |
| Table | `{prefix}blt_activity_log_entries` |
| Capability | `blt_view_activity_log` |
| Cron hooks | `blt_activity_log_prune`, `blt_activity_log_snapshot` (both daily) |
| Admin page | `tools.php?page=blt-activity-log`, wrapper `<div class="wrap blt-ui blt-activity-log">` |
| Admin stylesheet handle | `blt-activity-log-design-system` |
| Family registration | `blt_family_register()` with `'menu' => 'tools.php?page=blt-activity-log'` (the layer accepts a relative admin URL, so the Tools submenu needs no change to it) and `'groups' => array()` (no shared credential) |

---

## 4. Architecture

### 4.1 Flow

```
WordPress hook fires (save, update, activate, login ...)
        |
        v
Module listener (cheap: compares before/after it already has, no extra queries where avoidable)
        |
        v
BLT_Activity_Log_Logger::record( $entry )   -> in-memory buffer, merged per object
        |
        v   on `shutdown`
Coalesce with a recent matching row (section 4.3) -> one multi-row INSERT (or an UPDATE for a merge)
        |
        v
{prefix}blt_activity_log_entries  <-  BLT_Activity_Log_Query  <-  Tools screen, CSV export
```

### 4.2 Classes

| Class | Responsibility | WordPress-free? |
| --- | --- | --- |
| `BLT_Activity_Log` | Boot on `plugins_loaded`, wire modules, admin, cron | No |
| `BLT_Activity_Log_Install` | Activation, `dbDelta`, `maybe_upgrade` on `init`, capability grant, cron scheduling, network-activation refusal | No |
| `BLT_Activity_Log_Settings` | One array option merged over defaults, hand-sanitized `save()` | No |
| `BLT_Activity_Log_Module` (interface) | `id()`, `label()`, `description()`, `default_enabled()`, `register()` | n/a |
| `BLT_Activity_Log_Modules` | Registry, booted on `plugins_loaded` priority 1, filterable via `blt_activity_log_modules` | No |
| `BLT_Activity_Log_Module_Base` | Abstract base: `listen()` (an action hook that can never throw; not for filters), `is_front_request()`, `update_method()` (screen, upload, automatic, cli) | No |
| `BLT_Activity_Log_Module_*` | One per category in section 6 (`Posts`, `Media`, `Terms`, `Appearance`, `Plugins`, `Themes`, `Core`, `Settings`, `Users`, `Sessions`, `System`) | No |
| `BLT_Activity_Log_Logger` | Buffer, per-request merge, cross-request coalescing, flush on `shutdown` | No |
| `BLT_Activity_Log_Context` | Actor, role snapshot, request context, IP, user agent | No |
| `BLT_Activity_Log_Diff` | Field diffs, array key-path diffs, block and Bricks element histograms | **Yes** |
| `BLT_Activity_Log_Redactor` | Sensitive-key detection, value truncation | **Yes** |
| `BLT_Activity_Log_Ip` | CIDR matching (IPv4 and IPv6), Cloudflare range list | **Yes** |
| `BLT_Activity_Log_Core_Options` | The `core` scope allowlist and the always-ignored option patterns | **Yes** |
| `BLT_Activity_Log_Status_Map` | `(status before, status after, is_update)` to action key | **Yes** |
| `BLT_Activity_Log_Coalescer` | Merge rule: earliest "before", latest "after", drop reverted fields | **Yes** |
| `BLT_Activity_Log_Query` | Filter args to prepared SQL, whitelisted `ORDER BY`, site-time to UTC date ranges | Mostly (shim `$wpdb`) |
| `BLT_Activity_Log_Formatter` | Entry to translated one-line summary and detail rows (render time, never stored); `details()` normalizes any stored structure into the shape in 5.2 | No |
| `BLT_Activity_Log_Csv` | Cell escaping, streamed export | Escaping is pure |
| `BLT_Activity_Log_Pruner` | Retention deletes in time-boxed batches | No |
| `BLT_Activity_Log_Snapshot` | Plugin, theme and core version snapshot; out-of-band change detection | No |
| `BLT_Activity_Log_Privacy` | Policy text, personal-data exporter and eraser | No |
| `BLT_Activity_Log_Admin` | Singleton controller: menu, page-gated enqueue, PRG handlers on `admin_init` | No |
| `BLT_Activity_Log_List_Table` | Log table: core's `WP_List_Table` inside a `.blt-card`, styled with tokens | No |

Views live in `admin/views/` (`log.php`, `entry.php`, `settings.php`).

### 4.3 Recording rules

1. **Never break the action being logged.** Every listener and the flush are wrapped in `try { } catch ( \Throwable $e )`. On a database error, write one `error_log()` line per request and move on. A failed log write must never surface to the user or stop a save, update or login.
2. **No work on requests that change nothing.** Listeners only run when their hook fires. No queries at boot (settings and db_version are autoloaded). Acceptance check: a front-end page view runs zero queries from this plugin.
3. **Buffer, then write once.** Entries are buffered during the request and written on `shutdown` in one multi-row `INSERT`. `shutdown` also fires after `exit` and fatal errors.
4. **Merge within a request.** Buffered entries are keyed by `(category, action, object_type, object_id|object_key)`. A post save that also fires meta and term hooks produces one entry, not five.
5. **Coalesce across requests.** The block editor saves a post, then saves legacy meta boxes in a second request; Bricks saves via AJAX. Before inserting an `updated` entry for a post, option or user, look up the newest entry for the same user, object and action whose `last_at` is within the coalesce window (default 60 s, filter `blt_activity_log_coalesce_window`). If found, merge into it (`UPDATE`), keep the earliest "before" and latest "after" per field, drop fields that ended up unchanged, bump `occurrences` and `last_at`. The `UPDATE` names the `occurrences` and `last_at` it read, so two requests merging into one row cannot overwrite each other: the loser re-reads the winner's row and merges on top (three tries), and a save that keeps losing is written as its own row rather than lost. Merging is by chain, not by arrival order: a later change that ends where an earlier one began came first (WordPress fires the hook for a nested save before the outer one), and an identical content event counts once (the Site Editor's REST controllers fire it twice). An `updated` within the window after a `created` by the same user merges into the `created` entry.
6. **An empty diff is not an entry.** If a save changed no tracked field, nothing is logged. (Plugins call `wp_update_post()` for many reasons.)
7. **Snapshot labels, render text later.** Store the object's title or name and the actor's login and role as they were at the time, because they may be renamed or deleted. Do **not** store summary sentences; the Formatter builds them at render time so they translate.
8. **Redact by key, never by guesswork.** Any option name, meta key or array key matching the redaction pattern (section 10) is recorded as changed with the value replaced by `[redacted]`. Always redacted regardless of the pattern: `user_pass`, `user_activation_key`, `session_tokens`, application password values, `post_password`.
9. **Cap sizes.** Each stored value is truncated at 1,000 characters (filter `blt_activity_log_value_max_length`) with the original length recorded. `details` is capped at 64 KB per entry. Full post content is never stored; it is summarized (section 6.2).
10. **Times are UTC** in the database and rendered in site time with `wp_date()`.
11. **Ignore our own noise.** Writes to this plugin's own snapshot and state options are never logged. Changes to `blt_activity_log_settings` are **always** logged, whatever the settings scope, so turning logging off is itself on the record.

### 4.4 Actor and context

| Field | Rule |
| --- | --- |
| User | `get_current_user_id()` when pluggable functions are loaded; otherwise 0. WP-CLI with `--user` records that user; without it, 0. |
| Context | `cli` (`WP_CLI`), `cron` (`wp_doing_cron()`), `rest` (`REST_REQUEST`), `ajax` (`wp_doing_ajax()`), `xmlrpc` (`XMLRPC_REQUEST`), `login` (`wp-login.php`), `admin` (`is_admin()`), else `front`. Automatic updates are `cron` with `details.method = automatic`. |
| IP | `REMOTE_ADDR`. Use `CF-Connecting-IP` only when `REMOTE_ADDR` is inside Cloudflare's published ranges (bundled list, refreshed each release). Filter `blt_activity_log_client_ip`. Stored per the IP setting: full (default), anonymized (`wp_privacy_anonymize_ip()`), or off. |
| User agent | Truncated to 255 characters. Setting to turn off. |

---

## 5. Data model

### 5.1 Table `{prefix}blt_activity_log_entries`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` | Primary key |
| `created_at` | `DATETIME NOT NULL` | UTC, first occurrence |
| `last_at` | `DATETIME NOT NULL` | UTC, last merged occurrence (equals `created_at` when not coalesced) |
| `occurrences` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` | Saves merged into this entry |
| `user_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | 0 = no signed-in user |
| `user_login` | `VARCHAR(60) NOT NULL DEFAULT ''` | Snapshot |
| `user_role` | `VARCHAR(64) NOT NULL DEFAULT ''` | Snapshot of the first role |
| `category` | `VARCHAR(32) NOT NULL` | Module id (section 6) or an external category |
| `action` | `VARCHAR(48) NOT NULL` | Action key (section 6) |
| `object_type` | `VARCHAR(64) NOT NULL DEFAULT ''` | Post type, taxonomy, `plugin`, `theme`, `option`, `user`, ... |
| `object_id` | `BIGINT UNSIGNED NULL` | Numeric objects |
| `object_key` | `VARCHAR(191) NULL` | Non-numeric objects: plugin basename, theme stylesheet, option name |
| `object_label` | `VARCHAR(255) NOT NULL DEFAULT ''` | Snapshot of the title or name |
| `context` | `VARCHAR(16) NOT NULL` | Section 4.4 |
| `ip` | `VARCHAR(45) NULL` | Per IP setting |
| `user_agent` | `VARCHAR(255) NULL` | Per setting |
| `details` | `LONGTEXT NULL` | JSON, section 5.2 |

Indexes: `PRIMARY KEY  (id)`, `KEY created_at (created_at)`, `KEY user_created (user_id,created_at)`, `KEY cat_action_created (category,action,created_at)`, `KEY object_num (object_type,object_id)`, `KEY object_key (object_key)`.

Follow `dbDelta`'s formatting rules exactly: one column per line, `KEY` not `INDEX`, two spaces after `PRIMARY KEY`, no backticks. Create with `$wpdb->get_charset_collate()`. Gate on `blt_activity_log_db_version` and re-check in `maybe_upgrade` on `init`.

### 5.2 `details` JSON shape

Store field **keys**, not labels; the Formatter maps keys to translated labels.

```json
{
  "changes": [
    { "field": "post_title", "before": "About", "after": "About Us" },
    { "field": "mailserver_pass", "redacted": true },
    { "field": "post_excerpt", "before": "...", "after": "...", "truncated": { "after": 4210 } }
  ],
  "content": {
    "chars": [1240, 1388],
    "blocks_added": { "core/paragraph": 2, "core/image": 1 },
    "blocks_removed": { "core/heading": 1 },
    "revision_id": 4567,
    "hashes": ["3f2a9c1e", "b81d07aa"]
  },
  "bricks": { "elements_added": { "section": 1, "heading": 2 }, "elements_removed": {} },
  "terms": { "category": { "added": ["News"], "removed": ["Uncategorized"] } },
  "versions": { "from": "1.4.2", "to": "1.4.3" },
  "method": "screen | upload | automatic | cli | external",
  "via": "customizer",
  "summary": "Only for external entries: a caller-supplied, already-translated sentence"
}
```

`content.hashes` is a short fingerprint of the content before and after, so a save that returns the content to what it was is recognized exactly (character count alone cannot tell "Monday" to "Friday" to "Sunday" from a revert). An entry without hashes is never dropped on a guess. Only the keys that apply are present. Also possible: `changes[].order_changed` and `changes[].edited` (flags that replace before and after), `changes_omitted` (how many changes were cut to keep the entry under the cap), `network_wide`, and `external_category` (the name an outside caller used for a category that was filed under Other). `BLT_Activity_Log_Formatter::details()` normalizes whatever is stored into this shape, so a screen never has to guard against another plugin's idea of it.

### 5.3 Settings defaults (`blt_activity_log_settings`)

| Key | Default | Notes |
| --- | --- | --- |
| `modules` | every module on except `session_failed` | Map of module id to bool |
| `settings_scope` | `core` | `core` or `all` (section 6.4) |
| `excluded_post_types` | `array()` | Added to the built-in exclusions |
| `retention_days` | `90` | 1 to 365, adjustable per site. There is no "keep forever": 0 or text is refused, and a stored or configured value outside the range reads as 90 (above 365 reads as 365). Overridden by `BLT_ACTIVITY_LOG_RETENTION_DAYS`. |
| `ip_mode` | `full` | `full`, `anonymized`, `off` |
| `store_user_agent` | `true` | |
| `delete_data_on_uninstall` | `false` | |

---

## 6. What gets logged

**Verify every hook name and signature below against WordPress core at the 6.0 floor before building on it**, and record any correction in `tasks/lessons.md`. Bricks meta keys must be read from Bricks' own constants (`BRICKS_DB_PAGE_CONTENT` and its siblings) when defined, not hard-coded.

### 6.1 Categories

| Category (module id) | Label | Default |
| --- | --- | --- |
| `post` | Posts & pages | On |
| `media` | Media | On |
| `term` | Categories & tags | On |
| `appearance` | Menus, widgets & Customizer | On |
| `plugin` | Plugins | On |
| `theme` | Themes | On |
| `core` | WordPress updates | On |
| `setting` | Settings | On |
| `user` | Users | On |
| `session` | Sign-ins | On (failed sign-ins off, separate toggle `session_failed`) |
| `system` | BLT Activity Log | Always on, no toggle |
| external | Registered by other plugins | On when the caller is active |

### 6.2 Posts and pages (`post`)

**Post types covered:** every type with `show_ui`, plus `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_block`, `wp_navigation` and `bricks_template`. **Excluded:** `attachment` (Media module), `nav_menu_item` (Appearance), `revision`, `customize_changeset`, `oembed_cache`, `user_request`, WooCommerce orders (`shop_order`, `shop_order_placehold`, `shop_order_refund`) and ACF definitions (`acf-field-group`, `acf-field`, `acf-post-type`, `acf-taxonomy`, `acf-ui-options-page`), both non-goals in section 11, plus `excluded_post_types`. A site that wants orders or ACF back adds the slugs with `blt_activity_log_post_types`. Filter: `blt_activity_log_post_types`. Autosaves (`DOING_AUTOSAVE`, `wp_is_post_autosave()`) and `auto-draft` saves are never logged.

| Action | Source | Captured |
| --- | --- | --- |
| `created` | `wp_after_insert_post` (priority 20) with `$update` false or the previous status `auto-draft` / `new` | Status, author, type |
| `updated` | `wp_after_insert_post` with a non-empty diff and no status change | Field changes, content summary, terms, tracked meta |
| `published`, `unpublished`, `scheduled`, `made_private`, `trashed`, `restored` | Status change detected in `wp_after_insert_post`, mapped by `BLT_Activity_Log_Status_Map` | Status before and after, plus any field changes in the same save |
| `deleted` | `before_delete_post` (title and type captured before removal) | Title, type, ID |

Status changes come **only** from `wp_after_insert_post`. Do not also hook `trashed_post` or `transition_post_status`, or trash and publish events will be logged twice.

**Fields compared:** `post_title`, `post_name`, `post_status`, `post_author`, `post_parent`, `menu_order`, `post_excerpt`, `comment_status`, `ping_status`, `post_password` (recorded as set / changed / removed, never the value), `post_date` (only when the post was already published or scheduled; WordPress sets it automatically on first publish), and `post_content` (summarized, below).

**Terms:** `set_object_terms` gives old and new term-taxonomy IDs. Record names added and removed per taxonomy, folded into the buffered entry for that post.

**Meta:** read the old value in the pre-update hooks (`update_post_meta`, `delete_post_meta`) and the new value in `added_post_meta` / `updated_post_meta`. Track public keys (not starting with `_`) plus `_thumbnail_id` and `_wp_page_template`. Filter: `blt_activity_log_tracked_meta_keys`. Fold into the buffered entry for the post.

**Content summary (the clickthrough):**

- Character count before and after.
- Block histogram diff: `parse_blocks()` on both versions, count blocks by name recursively (ignore whitespace-only freeform blocks), record what was added and removed. This is what lets the detail screen say "Added 2 Paragraph and 1 Image, removed 1 Heading".
- The latest revision ID at flush time, so the detail screen can link to core's **Compare revisions** screen. If revisions are disabled, record counts only.
- **Bricks:** when Bricks is active and its content meta changes, compute the same histogram over Bricks elements by `name` and store it under `bricks`. Never store the Bricks JSON. A Bricks save that touches only meta still produces an `updated` entry.

### 6.3 Media, terms and appearance

| Category | Action | Source | Captured |
| --- | --- | --- | --- |
| `media` | `uploaded` | `add_attachment` | Filename, MIME type, file size |
| `media` | `updated` | `edit_attachment` | Title, alt text, caption, description diffs |
| `media` | `deleted` | `delete_attachment` (before removal) | Filename, title |
| `term` | `created`, `updated`, `deleted` | `created_term`; `edit_terms` (before) + `edited_term`; `pre_delete_term` | Name, slug, parent, description diffs. Skip the `nav_menu` taxonomy and any taxonomy with no screen of its own (`show_ui` false: a block theme's `wp_theme` and `wp_template_part_area`). |
| `appearance` | `menu_created`, `menu_updated`, `menu_deleted` | `wp_create_nav_menu`, `wp_update_nav_menu`, `wp_delete_nav_menu` | Menu name, item count before and after |
| `appearance` | `menu_locations_changed` | `theme_mods_{stylesheet}` diff on `nav_menu_locations` | Location: old menu to new menu |
| `appearance` | `widgets_changed` | `sidebars_widgets` option diff | Sidebar: widgets added and removed |
| `appearance` | `customizer_published` | `theme_mods_{stylesheet}` key diff, `via = customizer` when `customize_save` has run | Changed theme mods. Core options changed in the Customizer (site title, etc.) are logged by the Settings module with `via = customizer`. |

**Known limitation.** `wp_update_nav_menu` fires when the menu itself is saved. WordPress's Customizer and REST menu-item endpoints can change items without that action, so an edit that touches only menu items there may not produce a `menu_updated` entry. Edits on the Appearance > Menus screen are logged, with the item count before and after.

### 6.4 Settings (`setting`)

Sources: `updated_option` (old and new value), `added_option`, and `delete_option` (before, to capture the value) plus `deleted_option`.

**Scope `core` (default).** Log only an allowlist of options WordPress exposes on its own screens: General, Writing, Reading, Discussion, Media, Permalinks and Privacy. Build the list from `$allowed_options` in `wp-admin/options.php` at the 6.0 floor, plus `permalink_structure`, `category_base`, `tag_base`, `wp_page_for_privacy_policy` and `new_admin_email` (logged as "admin email change requested"). Keep it as data in one class with a test. Filter: `blt_activity_log_core_options`.

**Scope `all`.** Log any option changed during a request with a signed-in user (`user_id > 0`), minus the ignore list. Changes from cron, background jobs and anonymous requests are not logged in this mode. Options updated before `init` are ignored.

**Always ignored (both scopes):** `_transient_*`, `_site_transient_*`, `cron`, `rewrite_rules`, `active_plugins` and `recently_activated` (the Plugins module covers them), `uninstall_plugins`, `*.lock` and `*_lock`, `db_upgraded`, `recovery_keys`, `can_compress_scripts`, `blt_activity_log_snapshot`, `blt_activity_log_state`. Also ignored, because another module already covers them or WordPress writes them as bookkeeping: `sidebars_widgets`, `theme_mods_*`, `auto_update_plugins`, `auto_update_themes`, `external_updates-*`, the theme-switch options (`template`, `stylesheet`, `current_theme`, `theme_switched`, `theme_switch_menu_locations`), `*_children`, `wp_calendar_block_has_published_posts`, `db_version`, `fresh_site`, `finished_*`, `recently_edited`, `*user_roles` and `adminhash`. Never logged, because they are secrets or this plugin's own bookkeeping: `blt_activity_log_db_version` and the keys and salts WordPress generates and stores as options when `wp-config.php` does not define them (`auth_key`, `secure_auth_key`, `logged_in_key`, `nonce_key` and the four `*_salt`). Filter: `blt_activity_log_ignored_options` (exact names and `*` wildcards).

**Value diffs.** Scalars are compared as strings, so `1` and `'1'` are equal. Arrays are compared by key path to depth 3 and recorded as `option[key][subkey]`; a list whose members are unchanged but reordered is recorded as "order changed". Redaction applies at every key level.

### 6.5 Plugins, themes and core

| Category | Action | Source | Captured |
| --- | --- | --- | --- |
| `plugin` | `installed` | `upgrader_process_complete`, type `plugin`, action `install` | Name, version, method (`screen`, `upload`, `cli`) |
| `plugin` | `updated` | `upgrader_process_complete`, action `update` (single and bulk); also an upload that replaces an installed plugin | Name, version from and to, method (`automatic` under the auto-updater) |
| `plugin` | `activated`, `deactivated` | `activated_plugin`, `deactivated_plugin` | Name, version, network-wide flag |
| `plugin` | `deleted` | `delete_plugin` (capture name and version before files go), then `deleted_plugin` (log only if `$deleted` is true) | Name, version |
| `plugin` | `auto_updates_enabled`, `auto_updates_disabled` | `auto_update_plugins` option diff | Plugin name |
| `plugin` | `changed_externally` | Daily snapshot (section 6.7) | `added`, `removed` or version from and to |
| `theme` | `installed`, `updated`, `deleted`, `auto_updates_*`, `changed_externally` | Same pattern: upgrader type `theme`, `delete_theme` / `deleted_theme`, `auto_update_themes`, snapshot | Name, version |
| `theme` | `switched` | `switch_theme` | Old theme, new theme |
| `core` | `updated` | `_core_updated_successfully` | Version from (snapshot) and to, method |
| `core` | `changed_externally` | Snapshot | Version from and to |

"From" versions come from the snapshot. After any logged change, call `wp_clean_plugins_cache()` and refresh the snapshot.

**This plugin's own lifecycle.** Its activation hook runs in a sandbox before its listeners exist, so the activation hook writes a `system:logging_started` entry directly. Its own deactivation is logged normally by the Plugins module (the code is still loaded in that request).

### 6.6 Users and sign-ins

| Category | Action | Source | Captured |
| --- | --- | --- | --- |
| `user` | `created` | `user_register` | Login, email, role, created by (self-registration when no signed-in user) |
| `user` | `updated` | `profile_update` (old user object and new data) | `user_email`, `display_name`, `user_nicename`, `user_url`, first and last name, nickname. Never the hash. |
| `user` | `password_changed` | `profile_update` when the hash differs | No value |
| `user` | `password_reset` | `after_password_reset` | No value |
| `user` | `role_changed` | the capabilities user meta (all of `set_user_role`, `add_user_role`, `remove_user_role`) | Roles before and after |
| `user` | `deleted` | `delete_user` (before) | Login, email, role, content reassigned to |
| `user` | `app_password_created`, `app_password_revoked` | `wp_create_application_password`, `wp_delete_application_password` | Application name only |
| `session` | `logged_in`, `logged_out` | `wp_login`, `wp_logout` | User |
| `session` | `login_failed` | `wp_login_failed` (off by default) | Attempted username, truncated to 60 characters |

Failed sign-ins are off by default because a brute-force run would flood the table. Lockouts and rate limiting are BLT Secure's job, not this plugin's.

### 6.7 Out-of-band changes (snapshot)

`blt_activity_log_snapshot` holds `{ core, plugins: { file: version }, themes: { stylesheet: version } }`. It is refreshed after every logged plugin, theme or core change and compared once a day by `blt_activity_log_snapshot` cron. Any difference that no logged event explains becomes a `changed_externally` entry (user 0, context `cron`). This is how Composer deploys on Bedrock, FTP uploads and `DISALLOW_FILE_MODS` sites still show version history. The snapshot uses `get_plugins()`, so it runs only in cron, on activation and after upgrades, never on page load.

- **Refresh only what a logged change touched.** After a logged update, install or delete, only that plugin, theme or core version moves in the stored snapshot (`BLT_Activity_Log_Snapshot::refresh_keys()`), so an unrelated out-of-band change made earlier is still reported at the next daily run instead of being absorbed.
- **Read the disk, not a cache.** `get_plugins()` and `wp_get_themes()` cache without expiry, and a persistent object cache keeps the list from before an FTP or Composer deploy. The plugin and theme caches are cleared before the daily comparison and after a delete.
- **A failed update is not an update.** `upgrader_process_complete` also fires for an update that failed, was skipped, or never ran in a bulk update. An item is logged only when the version on disk moved; an upload that replaces a plugin with the same version is not an update.
- **A switched-off category stays quiet.** The daily run records `changed_externally` only for categories whose module is enabled, and still moves the stored baseline.

### 6.8 System entries (`system`)

`logging_started` (activation), `log_cleared` (with row count), `exported` (with row count and filters). Pruning is not logged; the last prune time and row count show on the Settings tab.

### 6.9 Entries from other plugins

Other plugins write entries with an action, so they never need to check whether this plugin is installed:

```php
do_action(
	'blt_activity_log_record',
	array(
		'category'     => 'blt_secure',               // Registered via blt_activity_log_categories.
		'action'       => 'waf_rule_deployed',
		'object_type'  => 'cloudflare_rule',
		'object_key'   => 'blt-secure-block-xmlrpc',
		'object_label' => 'Block XML-RPC',
		'details'      => array( 'summary' => __( 'Deployed WAF rule "Block XML-RPC".', 'blt-secure' ) ),
	)
);
```

Unknown categories are filed under "Other". The record runs through the same redaction, size caps and `blt_activity_log_should_log` filter as built-in entries.

---

## 7. Admin UI

Compose everything from the `.blt-ui` catalog in `DESIGN.md` section 2 and meet the CONVENTIONS 14.4 checklist. Every filter state, tab and entry is its own URL. Everything works with JavaScript off.

### 7.1 Menu and access

- `add_management_page()`: menu label "BLT Activity Log", slug `blt-activity-log`, capability `blt_view_activity_log`.
- Page header (`.blt-admin-page-header`) with the brand mark at 22px and tabs **Log** and **Settings**. The Settings tab renders only for `manage_options`.
- Plugins-row action links: "View log" and "Settings".
- Enqueue `blt-design-system.css` on this page's hook only.

### 7.2 Log tab

**Filter toolbar** (`.blt-toolbar`), a `GET` form to `tools.php` with a hidden `page` field:

| Filter | Control | Behavior |
| --- | --- | --- |
| Activity | `<select name="activity">` with one `<optgroup>` per category: "All posts & pages" (`post`), then each action (`post:updated`) | Lists categories that are enabled or already have entries |
| User | `<select name="user">`: "Anyone", "No user (system, cron, WP-CLI)", then each user seen in the log as "Display name (login)". Deleted users appear by login snapshot with "(deleted)". | Built from `SELECT DISTINCT user_id, user_login`. Above 200 distinct users, becomes a text field matching login. |
| Date | `<input type="date" name="from">` and `name="to"`, plus preset links: Today, Yesterday, Last 7 days, Last 30 days | Dates are site-local; `to` is inclusive to the end of that day; converted to a UTC range in `BLT_Activity_Log_Query` (DST-safe) |
| Search | `name="s"` | `object_label LIKE` via `$wpdb->esc_like()` |
| Item | `name="item"` (hidden; set by "All activity for this item") | One exact object: `type\|id:N` for numeric objects, `type\|key:...` for plugins, themes and options. Not a label search, so it never matches another item whose title contains the same text. |

Also: a **Filter** button, a **Reset** link, a line stating how many entries match, and **Export CSV** for `manage_options`.

**Table columns:**

| Column | Content |
| --- | --- |
| When | Site date and time; relative time ("12 min ago") in muted text. Sortable (default newest first). |
| User | Login, role in muted text, "System" or "WP-CLI" when user is 0. Sortable. |
| Activity | `.blt-badge` with the category, then the action label. Sortable. |
| Item | Object label linked to the entry detail; object type in muted text |
| Summary | Formatter one-liner, e.g. `Title, content (+2 blocks, −1 block), Categories` or `1.4.2 → 1.4.3 (automatic)` |
| Source | Context and IP |

Pagination with 50 rows per page and a Screen Options per-page setting. Sort keys are whitelisted in `BLT_Activity_Log_Query`.

**Empty states** (`.blt-empty-state`): "No activity matches these filters" with a Reset link; on a fresh install, "Logging started on {date}. Changes will appear here as they happen."

### 7.3 Entry detail (`&entry={id}`)

Opened from the Item column. Back link returns to the log with the same filters (the detail link carries them).

1. **Headline:** the Formatter sentence, e.g. *shane updated page "About Us"*. If coalesced: "Saved 3 times between 10:02 and 10:05 AM".
2. **Who and where** card: user (linked to their profile if they still exist and the viewer can edit users), role at the time, site time and UTC, context, IP, user agent.
3. **What changed** card: a `Field / Before / After` table. Redacted values show a `hidden` badge. Truncated values show "(truncated, 4,210 characters)".
4. **Content** card, for post updates: character counts, blocks added and removed, Bricks elements added and removed, and a **Compare revisions** button to `revision.php?revision={revision_id}` when that revision exists and the viewer can read it. Otherwise: "Revisions are disabled on this site, so only a summary is available."
5. **Terms** and **Versions** cards where present.
6. **Links:** Edit {item} (if it still exists and the viewer can edit it, otherwise the label with "(deleted)"), "All activity for this item" (the exact `item` filter in 7.2), "All activity by this user".
7. **Raw record** (`manage_options` only): the `details` JSON in `.blt-code-block` inside a native `<details>` element.

### 7.4 Settings tab (`manage_options`, PRG on `admin_init`)

| Card | Contents |
| --- | --- |
| What to log | One `.blt-toggle` per module with a one-line description; nested toggle for failed sign-ins |
| Settings scope | Selectable cards: "WordPress settings screens" (default) or "Any option changed by a signed-in user", with the trade-off stated (the second is noisier) |
| Privacy | IP storage: full / anonymized / off. Store browser user agent toggle. |
| Retention | Days, 1 to 365. Locked with an explanation when `BLT_ACTIVITY_LOG_RETENTION_DAYS` is defined. Stat tiles: total entries, oldest entry, last prune. **Download a full dump** (section 7.6). |
| Updates | **Check for Updates** (CONVENTIONS 9.1) |
| Advanced | "Delete all plugin data on uninstall" toggle |
| Clear log | Danger card. Choose "all entries" or "older than {date}". The first submit is a preview showing the exact count and carrying the newest entry id it counted (`clear_max_id`); a second POST with a confirm checkbox and nonce deletes, and only up to that id, so entries written between the preview and the confirm are kept and the count shown is the count removed. A confirm with no preview deletes nothing. "Older than {date}" accepts a date that has already started, because a later one would take the `log_cleared` record and everything after it. Writes `system:log_cleared` afterwards, so a cleared log is never silently empty. |

Save bar at the bottom. Saving writes a `setting:updated` entry for `blt_activity_log_settings` (rule 4.3.11).

### 7.5 CSV export

`admin-post.php?action=blt_activity_log_export` with nonce, `manage_options`, and the current filters. Streams with keyset pagination on `id` in batches of 1,000 so memory stays flat. Row cap 50,000 (filter `blt_activity_log_export_limit`); above it, show a notice asking the user to narrow the filters. Columns: ID, site time, UTC time, user, role, category, action, object type, object ID or key, label, summary, context, IP. Any cell starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with `'`. Writes `system:exported`.

### 7.6 Dump

`admin-post.php?action=blt_activity_log_dump` with its own nonce and `manage_options`. Streams **every** entry as CSV, newest first, with no filters, no row cap (`blt_activity_log_export_limit` does not apply) and no time limit, in keyset batches so memory stays flat. Columns are the stored ones: ID, created and last saved (UTC), saves merged, user ID, login, role, category, action, object type, object ID, object key, label, context, IP, browser, and the full `details` JSON. It is the stored data, not the on-screen view, so it can be kept as an archive when entries age out of the retention window. Nothing is written to the server. Writes `system:exported` with scope `full dump`. Offered on the Retention card and in the Clear log preview.

---

## 8. Retention, privacy and uninstall

### 8.1 Retention

Retention is 1 to 365 days (90 by default, adjustable per site; no "keep forever"). Daily `blt_activity_log_prune` cron deletes rows with `created_at` older than the retention cutoff: `DELETE ... ORDER BY id LIMIT 1000` in a loop until the tick budget is spent (default 15 s, filter `blt_activity_log_tick_budget`). If rows remain, schedule a single event 60 s out. If cron has not pruned in 2 days, opening the log screen schedules one and calls `spawn_cron()`; it never prunes in the page request.

### 8.2 Privacy

- `wp_add_privacy_policy_content()` with suggested text: what is logged, IP handling, retention.
- Personal-data **exporter**: entries where the user is the actor (time, activity, item, IP).
- Personal-data **eraser**: blanks `ip` and `user_agent` on that user's entries and reports the entries themselves as retained for site security. It does not delete audit records.

### 8.3 Deactivation and uninstall

- Deactivation unschedules both cron hooks and keeps all data.
- `uninstall.php` does nothing unless `delete_data_on_uninstall` is true. When it is: drop the table, delete the four options, remove `blt_view_activity_log` from every role, clear cron hooks. Never touch the shared family store.

---

## 9. Hooks (seed for `HOOKS.md`)

| Hook | Type | Default / arguments | Purpose |
| --- | --- | --- | --- |
| `blt_activity_log_record` | action | `array $args` | Write an entry from another plugin (section 6.9) |
| `blt_activity_log_logged` | action | `array $entry, int $id` | After an entry is written (future alerting) |
| `blt_activity_log_should_log` | filter | `true, array $entry` | Veto an entry |
| `blt_activity_log_entry` | filter | `array $entry` | Modify an entry before it is written |
| `blt_activity_log_modules` | filter | built-in modules | Add or remove modules |
| `blt_activity_log_categories` | filter | built-in `key => label` | Register categories for external entries |
| `blt_activity_log_post_types` | filter | section 6.2 | Post types covered |
| `blt_activity_log_tracked_meta_keys` | filter | public keys + `_thumbnail_id`, `_wp_page_template`; `int $post_id` | Meta keys diffed on posts |
| `blt_activity_log_core_options` | filter | core allowlist | Options logged in `core` scope |
| `blt_activity_log_ignored_options` | filter | section 6.4 | Options never logged |
| `blt_activity_log_redact_pattern` | filter | section 10 regex | Sensitive-key pattern |
| `blt_activity_log_value_max_length` | filter | `1000` | Per-value truncation |
| `blt_activity_log_coalesce_window` | filter | `60` (seconds) | Cross-request merge window |
| `blt_activity_log_tick_budget` | filter | `15` (seconds) | Prune batch budget |
| `blt_activity_log_export_limit` | filter | `50000` | CSV row cap |
| `blt_activity_log_client_ip` | filter | detected IP | Override IP detection |

A shipped hook name is never renamed.

---

## 10. Testing

Follow CONVENTIONS 14. Pure classes get known-vector PHPUnit tests with no WordPress install:

| Class | Vectors |
| --- | --- |
| `BLT_Activity_Log_Redactor` | Default pattern `/(^|[_\-\[])(passwords?|pass|pwd|secrets?|tokens?|api[_-]?keys?|(?:private|auth|license|nonce|logged[_-]?in|signing|encryption)[_-]?keys?|salts?|credentials?)($|[_\-\]])/i`, applied to a normalized key (camelCase split at the capital, dots and spaces to `_`). Must hit: `mailserver_pass`, `user_pass`, `api_key`, `api_keys`, `stripe_secret_key`, `blt_secure_settings[cloudflare_token]`, `nonce_salt`, `nonce_key`, `logged_in_key`, `accessToken`, `clientSecret`, `smtp.password`, `Client Secret`, `passwords`. Must miss: `keyboard_shortcuts`, `bypass_cache`, `passage`, `posts_per_page`, `tokenizer`. Truncation records original length. |
| `BLT_Activity_Log_Diff` | Scalars equal as strings (`1` vs `'1'`); nested key paths to depth 3; reordered list reports "order changed"; identical inputs give an empty diff; block histogram diff with nested blocks and whitespace-only freeform blocks ignored |
| `BLT_Activity_Log_Status_Map` | Every `(before, after, is_update)` pair in section 6.2, including `auto-draft → publish` = `created`, `trash → draft` = `restored`, `publish → future` = `scheduled` |
| `BLT_Activity_Log_Coalescer` | Earliest before and latest after kept; a field changed and changed back is dropped; `created` absorbs a following `updated` |
| `BLT_Activity_Log_Ip` | IPv4 and IPv6 in and out of range, malformed input, `CF-Connecting-IP` ignored when `REMOTE_ADDR` is outside Cloudflare ranges |
| `BLT_Activity_Log_Query` | Each filter to SQL and args; unknown sort key falls back to `created_at DESC`; `America/New_York` date ranges across 2026-03-08 and 2026-11-01 convert to the right UTC bounds |
| `BLT_Activity_Log_Csv` | `=SUM(A1)`, `+1`, `-1`, `@cmd`, leading tab and CR are prefixed; ordinary text unchanged |

WordPress-coupled behavior is exercised against a real WordPress install; the checklist and what has been verified are in `tasks/todo.md`.

---

## 11. Non-goals (v1)

- Email, Slack or Teams alerts. (`blt_activity_log_logged` exists so this can be added later.)
- A dashboard widget, charts or real-time view.
- Undo or rollback of any change.
- Tamper-proof logging. An administrator with database access, or who deactivates the plugin, can affect the log. Deactivation and clearing are themselves logged; a hash chain is a roadmap item.
- File integrity monitoring, failed-login lockouts, firewalling (BLT Secure).
- Comments, WooCommerce orders, form submissions, ACF field-group changes.
- Storing full copies of post content (core revisions already do this).
- Shipping logs to an external SIEM, syslog or Cloudflare.
- REST API, front-end output, shortcode, block or Bricks element.
- Multisite network-wide view.

---

## 12. Deviations from CONVENTIONS and open decisions

Resolve each, then delete the row. Resolved during the build and recorded in `README.md` (Architecture notes): the Tools submenu works through the family layer as it is; the log is `WP_List_Table` in a card; the repo is public, so there is no GitHub token; defaults confirmed (90 days retention, adjustable from 1 to 365; IP stored in full); the first release is 1.0.0 (merge with `#major` in the commit message, see `tasks/todo.md`).

| # | Priority | Item | Recommendation |
| --- | --- | --- | --- |
| 1 | Med | **IP range matching duplicates BLT Secure's** Cloudflare `CF-Connecting-IP` logic. | Ship a local copy for v1 (no shared-layer additions without asking, CONVENTIONS 16.3 rule 4). Candidate for `includes/blt-family/` later. |
| 2 | Low | **Other BLT plugins writing entries.** Optimized and Secure keep their own logs. Emitting `blt_activity_log_record` from them is a one-line, dependency-free addition. | Yes, but after v1 ships, as a change in those repos. |
| 3 | Med | **Family list in `DESIGN.md`.** CONVENTIONS 16.1 step 7 says to add each new plugin to it, in every repo in the same pass. `DESIGN.md` is vendored, so it was not edited here alone. | Make the change in every BLT repo together. |

---

Build phases, checklists and acceptance results are in `tasks/todo.md`.
