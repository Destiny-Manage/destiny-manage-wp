=== Destiny Manage ===
Contributors: destinymanage
Tags: agency, client management, maintenance, monitoring, updates
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Destiny Manage for activity logging, monitoring, backups, updates, health reporting, and client management.

== Description ==

The official WordPress connector for Destiny Manage, the white-label platform web agencies use to run client care plans.

Install it on a client site to connect that site to your Destiny Manage workspace for:

* Site inventory: WordPress core, plugin, and theme versions
* Health reporting for automated monthly client reports
* Update tracking across all your client sites
* Safe updates with automatic rollback if a site stops responding
* Activity logging: logins, user changes, content edits, media, plugin and theme actions, and settings changes
* Full site and database backups streamed to a cloud drive you own
* Cache purging across common WordPress and host caching layers
* Scoped page diagnostics that name the plugin behind a broken page

The plugin talks only to the Destiny Manage API over HTTPS using an API key you generate in your dashboard.

Learn more at https://www.destinymanage.com

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings, Destiny Manage.
3. Paste the API key from your Destiny Manage dashboard and save.

The site registers itself and appears in your workspace within a few minutes.

== Changelog ==

= 1.8.0 =
* Cloud backups now report live preparation stages, file and database sizes, archive compression, and byte-level upload progress to the configured drive.
* The dashboard shows exactly whether a backup is scanning files, exporting the database, compressing the archive, uploading, or waiting for the cloud provider to finalize it.

= 1.7.0 =
* Paid plans can download the connector with a custom agency plugin name throughout the WordPress Plugins screen, settings, update details, and future update packages.
* The internal plugin folder and update identity stay unchanged so white-labelling does not interrupt connections or safe updates; workspaces returning to Free continue on the standard connector identity.

= 1.6.1 =
* WordPress, hosting-provider, WP-CLI, and other system-run plugin, theme, and core updates are now captured even when no WordPress user is signed in, with a fresh inventory sync scheduled within about a minute.
* Update history and maintenance reports distinguish Destiny Manage safe updates from externally detected updates.

= 1.6.0 =
* New WordPress Activity: capture user logins and failed attempts, user changes, posts and pages, compact before/after content changes, ACF and page-builder fields, media uploads, plugin and theme actions, core updates, and important settings changes in Destiny Manage within seconds.
* Activity is queued locally before delivery and retried safely if the API is temporarily unavailable, without slowing or blocking the WordPress action.

= 1.5.0 =
* New Backups: create a full backup of the site's files and database from the Destiny Manage dashboard, on demand or on a daily/weekly schedule, and stream it straight to a cloud drive you own (Google Drive to start). Because the backup lives in your own drive, it is kept for as long as you want rather than being auto-deleted after a fixed window like host snapshots. The backup destination and schedule are configured per client, with an optional per-site override.

= 1.4.1 =
* Connecting now needs only the API key: the site name and address come from WordPress automatically, and the Site display name field is gone from settings. Renaming the site in WordPress settings updates it in Destiny Manage on the next sync.
* API keys generated for a specific client in Destiny Manage now link the site to that client automatically on connect.
* Tidied update result messages: they no longer mention routine plugin re-activation, which is still logged on the site.

= 1.4.0 =
* New Clear Cache command: purge the site's caches from the Destiny Manage dashboard with one click, and automatically after successful updates. Purges SiteGround Optimizer dynamic and object caches when the site runs on SiteGround, the WordPress object cache, and common page caches when present (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize). The result reports exactly which caches were cleared.
* Sites hosted on SiteGround are now recognised automatically and labelled in the dashboard.

= 1.3.1 =
* Fixed "Check Again" on the WordPress Updates screen not refreshing this connector's own cached version check. WordPress re-sets its update list on a forced check rather than deleting it, so the cache was not cleared and a new connector release could take up to six hours to appear; a forced check now clears the cache and shows a new version immediately.
* Rollback now restores from the local backup captured when a plugin or theme was last updated, rather than only re-downloading from WordPress.org. This makes rollback work for plugins and themes that are not in the WordPress.org directory, including this connector and licensed plugins, as long as a recent backup exists (backups are kept for seven days). WordPress.org remains the fallback when no local backup is available.

= 1.3.0 =
* Plugins whose updates are held back by an inactive, expired, revoked, or missing license (for example Gravity Forms and its add-ons) are now detected and reported to Destiny Manage instead of showing as up to date. The dashboard shows a clear license-blocked status and the version being held back, and leaves those plugins out of one-click and bulk updates since they would fail until the license is renewed or re-entered in WordPress.

= 1.2.2 =
* Fixed updates leaving a plugin switched off afterwards, including this connector when it updates itself. WordPress deactivates a plugin while it updates it and does not always turn it back on, so the update now re-enables it automatically: it keeps the new version when the site stays healthy, or rolls back to the previous version if the post-update health check fails, and never leaves the plugin disabled either way. Each correction is written to an on-site activity log and reported back in the update result.

= 1.2.1 =
* Added site title synchronization so when you change the website name in WordPress settings, it automatically updates in Destiny Manage.

= 1.2.0 =
* New scoped diagnostics for Destiny Manage page-health monitoring: when the platform checks a page of your site, PHP warnings and fatal errors on that request (with the file and plugin that caused them) are captured and reported back, so a broken page names its culprit. Capture only activates on cryptographically signed requests from your own Destiny Manage account, observes without changing any error behaviour, and never touches WP_DEBUG or your debug.log.

= 1.1.6 =
* Fixed the self-update download link so updating the plugin from the WordPress updates screen no longer fails.
* "Check Again" on the Updates screen now refreshes the plugin's own version check, so a new release appears immediately instead of after up to 6 hours.

= 1.1.5 =
* Licensed plugin and theme updates no longer report success when the license is inactive and the vendor hides the update; the update now fails with a clear message naming the installed and expected versions.
* Post-update validation compares versions numerically to avoid false mismatches.

= 1.1.4 =
* Connector for uptime monitoring, inventory, health reporting, and safe updates with rollback.
