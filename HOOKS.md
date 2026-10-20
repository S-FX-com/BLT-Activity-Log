# Hooks

Every public action and filter in BLT Activity Log. A shipped hook name is never renamed.

| Hook | Type | Purpose |
| --- | --- | --- |
| [`blt_activity_log_record`](#blt_activity_log_record) | action | Write an entry from another plugin |
| [`blt_activity_log_logged`](#blt_activity_log_logged) | action | After an entry is written |
| [`blt_activity_log_should_log`](#blt_activity_log_should_log) | filter | Veto an entry |
| [`blt_activity_log_entry`](#blt_activity_log_entry) | filter | Modify an entry before it is written |
| [`blt_activity_log_modules`](#blt_activity_log_modules) | filter | Add or remove modules |
| [`blt_activity_log_categories`](#blt_activity_log_categories) | filter | Register categories for outside entries |
| [`blt_activity_log_post_types`](#blt_activity_log_post_types) | filter | Post types that are logged |
| [`blt_activity_log_tracked_meta_keys`](#blt_activity_log_tracked_meta_keys) | filter | Private meta keys diffed on posts |
| [`blt_activity_log_core_options`](#blt_activity_log_core_options) | filter | Options logged in the "WordPress settings screens" scope |
| [`blt_activity_log_ignored_options`](#blt_activity_log_ignored_options) | filter | Options that are never logged |
| [`blt_activity_log_redact_pattern`](#blt_activity_log_redact_pattern) | filter | The sensitive-key pattern |
| [`blt_activity_log_value_max_length`](#blt_activity_log_value_max_length) | filter | Per-value truncation |
| [`blt_activity_log_coalesce_window`](#blt_activity_log_coalesce_window) | filter | Cross-request merge window |
| [`blt_activity_log_tick_budget`](#blt_activity_log_tick_budget) | filter | Seconds one prune pass may take |
| [`blt_activity_log_export_limit`](#blt_activity_log_export_limit) | filter | CSV row cap |
| [`blt_activity_log_client_ip`](#blt_activity_log_client_ip) | filter | Override IP detection |

## The entry array

Entries passed to `blt_activity_log_should_log`, `blt_activity_log_entry` and `blt_activity_log_logged` have these keys:

| Key | Type | Notes |
| --- | --- | --- |
| `category` | string | Module id (`post`, `media`, `term`, `appearance`, `plugin`, `theme`, `core`, `setting`, `user`, `session`, `system`) or a registered outside category. Up to 32 characters. |
| `action` | string | For example `created`, `updated`, `published`, `activated`. Up to 48 characters. |
| `object_type` | string | Post type, taxonomy, `plugin`, `theme`, `option`, `user`, and so on. |
| `object_id` | int\|null | For numeric objects. |
| `object_key` | string\|null | For non-numeric objects: a plugin basename, a theme stylesheet, an option name. |
| `object_label` | string | The title or name as it was at the time. |
| `details` | array | `changes`, `terms`, `content`, `versions`, `summary`, `method`, `via`. Field keys, not labels: labels are produced when the entry is displayed so they translate. |
| `user_id`, `user_login`, `user_role`, `context`, `ip`, `user_agent` | | Detected for the request. Not settable by a caller of `blt_activity_log_record`. |
| `created_at`, `last_at`, `occurrences` | | Times are UTC. |

Redaction and size limits are applied **after** the filters run, so nothing a filter adds can reach the table unredacted.

---

## Actions

### `blt_activity_log_record`

Write an entry on behalf of another plugin. Callers use `do_action()`, so they never have to check whether BLT Activity Log is installed.

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

| Argument | Type | Description |
| --- | --- | --- |
| `$args` | array | `category`, `action`, `object_type`, `object_id`, `object_key`, `object_label`, `details`. |

- The actor, role, context, IP and browser are the request's own; a caller cannot choose them.
- A category that is not registered with `blt_activity_log_categories`, or that is one of the built-in ones, is filed under **Other**, and the name the caller used is kept in `details.external_category`. A plugin cannot write into a built-in category.
- Anything that is not an array, or has no `category` and `action`, is ignored without an error.
- The entry goes through `blt_activity_log_should_log`, `blt_activity_log_entry`, redaction and the size limits like every other entry.

### `blt_activity_log_logged`

Fires after an entry is written, once per entry. This is the hook alerting would be built on.

| Argument | Type | Description |
| --- | --- | --- |
| `$entry` | array | The stored entry. |
| `$id` | int | The row id. |

An entry that is merged into an earlier one (see `blt_activity_log_coalesce_window`) does not fire this hook again.

---

## Filters

### `blt_activity_log_should_log`

Veto an entry. Return `false` to drop it.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$log` | bool | `true` | Whether to write it. |
| `$entry` | array | | The entry. |

```php
add_filter(
	'blt_activity_log_should_log',
	function ( $log, $entry ) {
		return ( 'post' === $entry['category'] && 'shop_order' === $entry['object_type'] ) ? false : $log;
	},
	10,
	2
);
```

### `blt_activity_log_entry`

Modify an entry before it is written. Only `category`, `action`, `object_type`, `object_id`, `object_key`, `object_label` and `details` are read back; the actor and times cannot be changed.

| Argument | Type | Description |
| --- | --- | --- |
| `$entry` | array | The entry. Return it, changed or not. |

### `blt_activity_log_modules`

Add or remove modules. Each class must implement `BLT_Activity_Log_Module` (`id()`, `label()`, `description()`, `default_enabled()`, `register()`) and be loadable. A module with a toggle appears on the Settings tab.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$classes` | string[] | The built-in module classes | Class names. |

### `blt_activity_log_categories`

Register categories for entries written through `blt_activity_log_record`, so they are listed under their own name in the Activity filter instead of "Other".

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$categories` | array | The built-in `key => label` map | Add `key => label`. |

```php
add_filter(
	'blt_activity_log_categories',
	function ( $categories ) {
		$categories['blt_secure'] = 'BLT Secure';
		return $categories;
	}
);
```

### `blt_activity_log_post_types`

The post types that are logged: every type with a UI, plus `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_block`, `wp_navigation` and `bricks_template`, minus attachments, nav menu items, revisions, `customize_changeset`, `oembed_cache`, `user_request` and the types skipped in Settings.

| Argument | Type | Description |
| --- | --- | --- |
| `$types` | string[] | Post type slugs. |

### `blt_activity_log_tracked_meta_keys`

Every public meta key on a post is tracked. This filter chooses which **private** keys (those starting with an underscore) are tracked as well.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$keys` | string[] | `_thumbnail_id`, `_wp_page_template` | Private meta keys. |
| `$post_id` | int | | The post. |

### `blt_activity_log_core_options`

The options logged in the **WordPress settings screens** scope.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$options` | string[] | The options on the General, Writing, Reading, Discussion, Media, Permalinks and Privacy screens | Option names. |

### `blt_activity_log_ignored_options`

Options that are never logged, in either scope. Exact names, or patterns with `*` as a wildcard.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$patterns` | string[] | Transients, `cron`, `rewrite_rules`, `active_plugins`, `recently_activated`, `uninstall_plugins`, locks, `db_upgraded`, `recovery_keys`, `can_compress_scripts`, this plugin's snapshot and state, options another module already covers (`sidebars_widgets`, `theme_mods_*`, the auto-update lists, `external_updates-*`, theme-switch bookkeeping), WordPress caches and upgrade flags, role definitions and `adminhash` | Patterns. |

### `blt_activity_log_redact_pattern`

The regular expression a key must match for its value to be hidden. A matching option name, meta key or array key is recorded as changed, with the value replaced by "hidden". Password hashes, session tokens, application password values and `post_password` are hidden whatever this returns.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$pattern` | string | `/(^\|[_\-\[])(pass(word)?\|pwd\|secret\|token\|api[_-]?key\|private[_-]?key\|auth[_-]?key\|license[_-]?key\|salt\|credentials?)($\|[_\-\]])/i` | A PCRE pattern. An invalid one is ignored. |

### `blt_activity_log_value_max_length`

How many characters of a value are stored. The original length is recorded when a value is cut.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$length` | int | `1000` | Characters. |

### `blt_activity_log_coalesce_window`

Within this many seconds, a new `updated` entry by the same person for the same object is merged into the previous one (the earliest "before" and latest "after" are kept, and fields that ended up unchanged are dropped). An `updated` right after a `created` by the same person merges into the `created` entry.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$seconds` | int | `60` | Window in seconds. `0` turns cross-request merging off. |

### `blt_activity_log_tick_budget`

Seconds one prune pass may spend deleting before it stops and schedules a continuation 60 seconds later.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$seconds` | int | `15` | Seconds. Never less than 1. |

### `blt_activity_log_export_limit`

The most rows one filtered CSV export may contain. Above it the export is refused with a notice asking for narrower filters, not truncated. The full dump on the Settings tab is not an export and has no row cap.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$limit` | int | `50000` | Rows. |

### `blt_activity_log_client_ip`

Override how the client IP is detected, for sites behind a proxy the plugin does not know about. The result is validated, and anonymized when that setting is on.

| Argument | Type | Description |
| --- | --- | --- |
| `$ip` | string | The detected address (`''` when none). Return the address to store. |
| `$remote` | string | `REMOTE_ADDR`. |
| `$cf_ip` | string | The `CF-Connecting-IP` header value (`''` when absent). |

`CF-Connecting-IP` is trusted before this filter runs only when `REMOTE_ADDR` is inside Cloudflare's published ranges.
