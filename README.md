# Destiny Manage for WordPress

The official WordPress connector plugin for [Destiny Manage](https://www.destinymanage.com), the white-label platform web agencies use to run client care plans.

Install it on a client's site to connect that site to your Destiny Manage workspace for uptime monitoring, plugin and theme tracking, health reporting, and safe updates with automatic rollback.

## What it does

- **Site inventory**: reports WordPress core, plugin, and theme versions so you can see what every client site is running from one dashboard.
- **Health reporting**: pushes a periodic health snapshot to your workspace, so the monthly client report writes itself.
- **Update tracking**: surfaces available core, plugin, and theme updates across all your sites.
- **Safe updates with rollback**: applies approved updates and automatically rolls back if a site stops responding afterward.

The plugin is a lightweight client: it talks only to the Destiny Manage API over HTTPS using an API key you generate in your dashboard. No data leaves the site except the inventory and health information described above.

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

```bash
./build.sh
```

This produces a distributable `destiny-manage.zip`.

## About Destiny Manage

Destiny Manage brings client portals, WordPress maintenance, SLA tracking, monthly reports, and billing together under your own brand. [Start free with 3 clients →](https://www.destinymanage.com)

- Website: https://www.destinymanage.com
- Guides: https://www.destinymanage.com/help
- Blog: https://www.destinymanage.com/blog

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
