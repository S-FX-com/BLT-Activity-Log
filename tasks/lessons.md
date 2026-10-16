# Lessons

Gotchas found while building. Append only; never rewrite history. Newest at the bottom.

## Build environment

- **Composer over the agent proxy.** `composer install` fails to authenticate against `api.github.com`; `composer install --prefer-source` (git over the proxy) works. As root, set `COMPOSER_ALLOW_SUPERUSER=1`.
- **No `rsync`.** Copy a plugin into a test site with `cp -r`; a script that expects `rsync` copies nothing and the old code keeps running.
- **The CLI keeps no response headers.** `headers_list()` and `http_response_code()` are empty in PHP's CLI, so headers cannot be asserted from a script that runs WordPress as a CLI process. Capture redirects and statuses with the `wp_redirect` and `status_header` filters, and check real headers over HTTP (`php -S` plus `curl -i`).
- **A test that echoes before WordPress sends headers causes "headers already sent".** Collect markers and print them in a shutdown function.
- **Match our own queries by table and option name, not by substring.** WordPress's `cron` option contains our hook names, so any query that rewrites it matches `blt_activity_log`.
- **Integration tests leave state behind.** Make each one start from what it needs (delete the option, remove the theme mod, delete the users) or it passes once and fails on the second run.

## WordPress behavior

- **`wp_get_post_revision()` takes its argument by reference.** `wp_get_post_revision( (int) $id )` is a fatal error ("could not be passed by reference"); assign to a variable first. Found only by rendering a real entry that had a revision; a row built by hand did not reach it.
- **`WP_User::set_role()` fires `remove_user_role` and `add_user_role`, then `set_user_role`, after the roles are already changed.** Reading the user's roles inside those hooks gives the new state, so a "before" computed there is wrong (an edit from editor to author read "author, editor" to "author"). Read the capabilities user meta (`{$wpdb->get_blog_prefix()}capabilities`) before (`update_user_meta`) and after (`updated_user_meta`, `added_user_meta`), and ignore the three role hooks. A new account's role arrives the same way, before `user_register`, so the creation entry absorbs it.
- **`WP_Ajax_Upgrader_Skin` extends `Automatic_Upgrader_Skin`.** Test for the Ajax skin first or every screen update is classified as automatic.
- **A theme switch finishes on the next request.** `after_switch_theme` remaps menu locations and widgets; that must not be logged as someone editing menus. It also writes `theme_switched`, `theme_switch_menu_locations`, `template`, `stylesheet` and `current_theme`, which the "any option" scope would otherwise log.
- **Deleting a post fires an `updated`-looking save first.** Guard it (the module keeps a `$deleting` flag), and ignore the `__trashed` slug rename on trash.
- **`add_action` wrappers cannot wrap filters.** `BLT_Activity_Log_Module_Base::listen()` calls the callback and returns nothing, which would turn a filter's value into `null`. It is for actions only.
- **`added_option` fires only when the option did not exist.** A test that adds an option passes the first time and logs nothing the second.
- **`update_option()` with an unchanged value fires nothing**, so a settings test that sets the value it already has proves nothing. Reset the option first.
- **`wp_privacy_anonymize_ip()` keeps the first 64 bits of an IPv6 address** (`2001:db8:85a3:1::`), not the first three groups.
- **The sign-in context is decided by `$pagenow`**, which WordPress recomputes while loading. Set it inside the request, after load, in a test.
- **A visitor creating a tag is a real change.** Creating a new term from a public request is logged (only meta and term relationships on posts are skipped on the front end). Pre-create the term when a test means to exercise only the relationship.
- **A page view can write.** WordPress rebuilds `rewrite_rules` on the first public request after they are cleared. That write must not be logged, in either scope.
- **Hook signatures at the 6.0 floor** were read from WordPress 6.0 source before the modules were written. Of note: `wp_after_insert_post` has four arguments; `set_object_terms` has six, including the old term taxonomy ids; `delete_user` has three; `wp_create_application_password` has four; `upgrader_process_complete`'s `hook_extra` differs for install, update and bulk.

## Logging design

- **`absorb` must take every listed sibling, not the first.** A new account has profile-meta changes (`updated`) and a role change waiting when `user_register` fires; absorbing only the first match left the other as a stray entry. An earlier `updated` or `role_changed` yields its action to whatever it turns out to belong to (`BLT_Activity_Log_Coalescer::DETAIL_ACTIONS`).
- **Coalescing applies to a second test edit too.** Two edits by one person inside 60 seconds are one entry (with the later revision id); age rows between steps in a test that wants two.
- **A map that appears or disappears is walked key by key.** `values( 'o', [], [ 'nested' => [ 'x' => 1, 'client_secret' => 's' ] ] )` must report `o[nested][x]` and a hidden `o[nested][client_secret]`, not one blob; and deleting an option holding a map reports each key it had.
- **Never trust the shape of stored details.** Other plugins write entries through `blt_activity_log_record` and rows outlive the code that wrote them. The Redactor drops a non-array `changes` or non-scalar `summary` on the way in, and `Formatter::details()` normalizes everything on the way out, so no screen guards against it.
- **The ignore list needs more than the spec's.** Besides the spec's list, "any option" scope logged WordPress's own side effects until these were added: theme-switch bookkeeping, `*_children` caches, `wp_calendar_block_has_published_posts`, `db_version`, `fresh_site`, `finished_*`, `recently_edited`, `*user_roles` and `adminhash` (a confirmation token). A test asserts the core allowlist and the ignore list never overlap.

## Admin UI

- **WordPress's responsive list table hides only the columns after the primary column.** The primary column is the first by default; columns before it stay visible on a phone with their `::before` labels overlapping the content. Keep the primary column where the content you want shown first is, or hide those labels with a selector at least as specific as WordPress's (`.wp-list-table tr:not(.inline-edit-row):not(.no-items) td:not(.check-column)::before`), and do not set fixed column widths below 783 px.
- **A long category label wraps inside its pill.** Give the pill `white-space: nowrap` and put the action label on its own line.
- **List table markup:** rows have no id; pagination uses single-quoted attributes. Match with a regular expression that allows both quote styles.
- **Rendering synthetic rows hides bugs.** Build test entries from real events (create the post, update it, delete it) and open every one on the detail screen.
