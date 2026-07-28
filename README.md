# Destiny Manage for WordPress

The official WordPress connector plugin for [Destiny Manage](https://www.destinymanage.com), the white-label platform web agencies use to run client care plans.

Install it on a client's site to connect that site to your Destiny Manage workspace for uptime monitoring, plugin and theme tracking, activity logging, backups, health reporting, and safe updates with automatic rollback.

## What it does

- **Site inventory**: reports WordPress core, plugin, and theme versions so you can see what every client site is running from one dashboard.
- **Health reporting**: pushes a periodic health snapshot to your workspace, so the monthly client report writes itself.
- **Update tracking**: surfaces available core, plugin, and theme updates across all your sites, including updates applied by the host, WP-CLI, or WordPress itself.
- **Safe updates with rollback**: applies approved updates and automatically rolls back if a site stops responding afterward.
- **Activity log**: records logins and failed attempts, user changes, content edits with compact before/after detail, media, plugin and theme actions, and important settings changes.
- **Backups**: creates a full file and database backup on demand or on a schedule and streams it to a cloud drive you own, so retention is yours to set rather than the host's.
- **Cache purging**: clears the WordPress object cache, common page caches, and host-level caches after updates or on demand.
- **Page diagnostics**: captures the PHP warning or fatal error behind a failing page and names the plugin responsible. Capture only activates on cryptographically signed requests from your own workspace and never alters `WP_DEBUG` or your `debug.log`.
- **License-blocked updates**: detects plugins whose updates are withheld by an inactive or expired license instead of reporting them as up to date.

The plugin is a lightweight client: it talks only to the Destiny Manage API over HTTPS using an API key you generate in your dashboard.

## Requirements

- WordPress 6.0 or newer
- PHP 8.0 or newer
- A [Destiny Manage](https://www.destinymanage.com) account (free for your first 3 clients)

## Installation

1. Download the latest release `.zip` from the [Releases](../../releases) page.
2. In the site's WordPress admin, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **Destiny Manage**.
4. Go to **Settings → Destiny Manage**, paste the API key from your Destiny Manage dashboard, and save.

The site registers itself and appears in your workspace within a few minutes.

## Building from source

The plugin is plain PHP with no build step. To produce an installable zip, place
the files in a folder named `destiny-manage` and archive it:

```bash
mkdir -p build/destiny-manage
cp -R destiny-manage.php includes build/destiny-manage/
cd build && zip -r destiny-manage.zip destiny-manage
```

The folder name matters: WordPress installs the plugin to `wp-content/plugins/destiny-manage`,
and the connector's own updates depend on that path staying the same.

## About Destiny Manage

Destiny Manage brings client portals, WordPress maintenance, SLA tracking, monthly reports, and billing together under your own brand. [Start free with 3 clients →](https://www.destinymanage.com)

- Website: https://www.destinymanage.com
- Guides: https://www.destinymanage.com/help
- Blog: https://www.destinymanage.com/blog

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
