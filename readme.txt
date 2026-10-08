=== SafeSpot – Plugin Restore Points ===
Contributors: safespot
Tags: rollback, restore, backup, plugin updates, version control
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Save a restore point of any plugin or theme before you update it, and roll back in one click if the update breaks something.

== Description ==

Updates break sites. SafeSpot keeps an exact copy of a plugin or theme – a **SafeSpot** – so you can put the previous version back in seconds, even for premium plugins that are not on WordPress.org.

SafeSpot is lightweight and focused: it backs up and restores plugin and theme **files** (plus, optionally, their settings). It is not a full-site backup tool and never touches your posts, media or core files.

= Save a SafeSpot =

* One click on the SafeSpot screen, a row link on the Plugins screen, a button in the theme details window, or `wp safespot save`.
* Works for folder plugins, single-file plugins (like Hello Dolly), parent themes and child themes.
* Each SafeSpot stores the version, date, size, file count, the user who created it, an optional note and a SHA-256 checksum.

= Automatic SafeSpots before every update =

* Manual updates, bulk updates, background auto-updates and "Replace current with uploaded" zip installs – including `wp plugin install --force` and `wp plugin update`.
* Turn automatic SafeSpots on or off per plugin or theme.
* Optionally cancel an update when the SafeSpot cannot be saved, and optionally save a SafeSpot before a plugin or theme is deleted.

= One-click restore with safety nets =

* A confirmation window shows exactly what changes: version X → Y, files that will be restored, removed or replaced, and every pre-flight check (checksum, disk space, write permissions, PHP/WordPress requirements, active/child theme warnings, multisite notes).
* Before restoring, SafeSpot automatically saves the current version ("Auto – before restore"), so every restore can be undone.
* The archive is verified against its checksum and scanned for unsafe paths. A corrupt or tampered SafeSpot is refused and nothing changes.
* Files are replaced with WordPress's own upgrader and Filesystem API, inside maintenance mode.
* Active, inactive and network-activated states are preserved.
* After restoring, SafeSpot loads the site in the background. If the restored version causes a fatal error, it **rolls back automatically**.
* "Roll back this restore" is available for 24 hours, from the SafeSpot screen, the admin notice, WordPress recovery-mode emails and WP-CLI.
* Optionally pause updates for the item so the restored version is not immediately updated again: auto-updates skip it and manual, bulk and WP-CLI updates are refused until you resume them.

= Manage your SafeSpots =

* Dashboard with protection status, storage use, recent activity and updates that are waiting – save SafeSpots for all of them in one go.
* Plugins and Themes tabs with search, filters, bulk save and a full history per item: restore, download, edit note, lock and delete.
* Retention: keep the last N SafeSpots per item (default 3), optionally delete SafeSpots older than X days. Locked SafeSpots are never removed automatically.
* Activity log of every save, restore, rollback, deletion and settings change, with filters.
* Email notification when an automatic SafeSpot or a restore fails.

= Optional settings snapshot =

* Include the plugin's or theme's rows from `wp_options` (matched by an editable option-name prefix) and, for themes, the Customizer settings (`theme_mods`).
* When restoring, choose files only or files + settings.
* Custom database tables are **not** included – see the FAQ.

= Built for developers and agencies =

* Full WP-CLI support.
* Actions and filters for every step.
* Multisite: network-wide management from Network Admin.
* REST API (`safespot/v1`) used by the admin screens.

= Security =

* Only users who can install and activate plugins/themes can save or restore. On multisite, only network administrators.
* Every action is nonce-protected and capability-checked.
* SafeSpots are stored in `wp-content/uploads/safespot-backups/{random}/`, protected with `.htaccess`, `web.config` and `index.php`, and can only be downloaded through an authenticated, nonce-protected handler.
* Archives are validated before restoring: no path traversal, absolute paths, symlinks or files outside the expected folder.

== Installation ==

1. Upload the `tbsh-safespot-restore-points` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. On multisite, network-activate it.
3. Go to **SafeSpot** in the admin menu (Network Admin on multisite).
4. Save a SafeSpot of anything you are about to update – or simply update: automatic SafeSpots are on by default.

**Nginx users:** `.htaccess` rules are ignored by Nginx. Add this to your server block to block direct access to the storage folder:

`location ~* /wp-content/uploads/safespot-backups/ { deny all; }`

== Frequently Asked Questions ==

= Is this a full backup plugin? =

No. SafeSpot backs up and restores individual plugins and themes. Use a full-site backup solution for your database, media and core files.

= Are database changes undone when I restore? =

Only if you enable the settings snapshot and choose "Files + settings" when restoring – and only for `wp_options` rows that match the item's prefix (plus `theme_mods` for themes). Database migrations that a plugin ran on its own custom tables (for example WooCommerce order tables) are **not** reverted. Downgrading a plugin whose update changed its database schema can cause problems; check the plugin's changelog first.

= Can I restore a plugin I deleted? =

Yes. Its SafeSpots stay in the list. Restoring reinstalls it (inactive).

= Can SafeSpot restore itself? =

No. Restoring SafeSpot while it is running is not safe. Download the SafeSpot zip and upload it from Plugins → Add New instead.

= What happens if the restored version crashes the site? =

SafeSpot checks the site immediately after restoring. If it detects a fatal error, it puts back the version you had before. If that check cannot run (some hosts block loopback requests), WordPress's recovery mode takes over, and the recovery email and error screen include instructions to roll back.

= Where are SafeSpots stored and how big are they? =

In `wp-content/uploads/safespot-backups/` inside a randomly named folder. They are zip archives, roughly the compressed size of the plugin or theme. The Dashboard and Settings show the storage used and free disk space. Developers can change the location with the `safespot_storage_path` filter.

= Why does SafeSpot use custom database tables? =

SafeSpot metadata (version, checksum, size, source, lock…) is queried by item, sorted, summed and pruned constantly. Dedicated, indexed tables keep that fast and keep SafeSpot data out of post queries, exports, sitemaps and search. Because plugin and theme files are shared by the whole network, the tables are network-wide on multisite.

= Large plugins time out on my host. =

SafeSpot raises PHP time and memory limits where allowed and streams files into the archive. "Save SafeSpots for all" runs as a background queue (Action Scheduler if available, otherwise WP-Cron). For very large items on restrictive hosts, use WP-CLI.

= Does it work on multisite? =

Yes. SafeSpot is managed from Network Admin by network administrators, network-activated plugins stay network-activated after a restore, and the settings snapshot includes network options (`sitemeta`).

= What is deleted when I uninstall? =

Nothing, unless you enable "Delete all SafeSpots, settings and the activity log when SafeSpot is deleted" in Settings. With that option enabled, all SafeSpots, tables and settings are removed.

== Screenshots ==

1. Dashboard: protection status, storage, recent activity and updates waiting.
2. Plugins tab with SafeSpot history, auto-SafeSpot toggles and actions.
3. Restore confirmation: version comparison, file changes and pre-flight checks.
4. Activity log with filters.
5. Settings: automatic SafeSpots, retention, settings snapshot and notifications.

== WP-CLI ==

`wp safespot save <plugin-or-theme> [--type=<plugin|theme>] [--note=<note>] [--with-settings] [--porcelain]`
Save a SafeSpot. Accepts a plugin folder, plugin file (`akismet/akismet.php`) or theme folder.

`wp safespot list [--type=<type>] [--slug=<slug>] [--fields=<fields>] [--format=<table|json|csv|yaml|ids|count>]`
List SafeSpots.

`wp safespot restore <id> [--with-settings] [--pause-updates] [--skip-health-check] [--yes]`
Restore a SafeSpot (runs the same pre-flight checks, safety SafeSpot, health check and automatic rollback as the admin screen).

`wp safespot rollback [--yes]`
Undo the most recent restore.

`wp safespot delete <id>... [--yes]`
Delete one or more SafeSpots.

== Hooks ==

Actions:

* `safespot_before_save( array $item, array $args )`
* `safespot_after_save( array $point, array $item, array $args )`
* `safespot_after_delete( array $point )`
* `safespot_before_restore( array $point, array|null $item, array $args )`
* `safespot_after_restore( array $point, array|null $item, array $result )`
* `safespot_logged( string $action, string $status, string $message, array $context )`

Filters:

* `safespot_storage_path( string $path )` – absolute storage folder.
* `safespot_backup_exclude( string[] $patterns, array $item )` – relative paths or `fnmatch()` patterns to leave out of archives (for example `.git` or `node_modules`).
* `safespot_should_auto_backup( bool $should, string $type, string $slug )` – skip automatic SafeSpots programmatically.
* `safespot_user_can( bool $allowed, string $action, string $type )` – customize permissions (`view`, `save`, `restore`, `delete`, `settings`).
* `safespot_use_ziparchive( bool $use )` – return false to force the PclZip fallback.

== Privacy ==

SafeSpot does not send data anywhere. SafeSpots, the activity log (which stores WordPress user IDs) and settings stay in your database and uploads folder.

== Changelog ==

= 1.0.1 =
* Fix: The per-item "Auto" switches now show whether an automatic SafeSpot will really be saved. They are shown off and disabled when automatic SafeSpots are turned off in Settings, instead of appearing on.
* Fix: The dashboard shows the real automatic SafeSpot state (on, off, plugins only or themes only).
* Improvement: The Plugins and Themes tabs show a notice with a link to Settings when automatic SafeSpots are turned off for that type.
* Improvement: Settings that depend on automatic SafeSpots are disabled while the main option is off.
* Improvement: Including an item again warns when automatic SafeSpots are turned off in Settings.
* Developer: `GET /safespot/v1/items` returns `auto_active`; `POST /safespot/v1/exclusions` returns `auto_enabled`.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.1 =
The Auto switches and dashboard now show the real automatic SafeSpot state. Check Settings if they appear off.

= 1.0.0 =
Initial release.
