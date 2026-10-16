=== BLT Activity Log ===
Contributors: sfxdotcom
Tags: activity log, audit log, security, change log, history
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A record of what changed on your site: content, plugins, themes, settings, users and sign-ins, with who did it, when, and exactly which fields changed.

== Description ==

BLT Activity Log records changes made to the WordPress site it runs on. Each entry says who did it, when, from where (an admin screen, the REST API, cron or WP-CLI) and, for edits, exactly which fields changed.

**What it records**

* Posts, pages and other content: created, edited, published, scheduled, made private, trashed, restored and deleted, with the fields that changed and a summary of what changed inside the content (for example "Added 2 Paragraph and 1 Image, removed 1 Heading"). Bricks element changes are summarized the same way.
* Media: uploads, edits to a file's details, deletions.
* Categories, tags and other terms.
* Menus, menu locations, widgets and Customizer settings.
* Plugins and themes: installed, updated (with the version it came from and went to), activated, deactivated, switched, deleted, and auto-updates turned on or off.
* WordPress core updates.
* Site settings, as a field-level diff. Secrets such as the mail server password are recorded as changed but never stored.
* Users: accounts created, edited and deleted, role changes, password changes and resets, application passwords, and sign-ins.
* Changes made outside WordPress (a deploy or an upload over FTP) are noticed by a daily check and recorded too.

**Where to find it**

Open **Tools > BLT Activity Log**. Filter by activity, user and date, search by item name, open any entry for the full detail, and export the filtered log as CSV.

**Built to stay out of the way**

* Entries are written once, at the end of the request, in a single query. Front-end page views run no queries from this plugin.
* A logging fault can never break the save, update or sign-in being logged.
* Old entries are pruned automatically (90 days by default).
* No external service, no account, nothing that needs JavaScript.

**Privacy**

IP addresses can be stored in full, anonymized, or not at all. The plugin adds suggested text to your privacy policy and supports WordPress's personal-data export and erase tools.

== External services ==

This plugin does not contact any external service. Updates are delivered from the plugin's GitHub releases through the WordPress update system.

== Installation ==

1. Upload the `blt-activity-log` folder to `/wp-content/plugins/`, or install the release zip from the Plugins screen.
2. Activate the plugin on the site you want to log. It cannot be network activated.
3. Open **Tools > BLT Activity Log**.

== Frequently Asked Questions ==

= Does it work on multisite? =

Not yet. Activate it on each site you want to log; each site keeps its own log. Network activation is refused.

= How long are entries kept? =

90 days by default. Change it under Settings, or add `define( 'BLT_ACTIVITY_LOG_RETENTION_DAYS', 365 );` to `wp-config.php` (0 keeps entries forever). The constant locks the setting.

= Can someone delete the log to hide what they did? =

Anyone who can reach the database, or who can deactivate the plugin, can affect the log. Deactivating, changing the settings and clearing the log are all recorded, but the log is not tamper-proof.

= Does it record failed sign-ins? =

Only if you turn that on under Settings: a brute-force run would flood the log. Lockouts and rate limiting are the job of a security plugin.

= What happens when I delete the plugin? =

Nothing is deleted unless you turn on "Delete all plugin data on uninstall" under Settings.

== Changelog ==

= 0.1.0 =
* First release.
