=== Destiny Manage ===
Contributors: destinymanage
Tags: agency, client management, maintenance, monitoring, updates
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Destiny Manage for automated monitoring, plugin tracking, health reporting, and safe updates with rollback.

== Description ==

The official WordPress connector for Destiny Manage, the white-label platform web agencies use to run client care plans.

Install it on a client site to connect that site to your Destiny Manage workspace for:

* Site inventory: WordPress core, plugin, and theme versions
* Health reporting for automated monthly client reports
* Update tracking across all your client sites
* Safe updates with automatic rollback if a site stops responding

The plugin talks only to the Destiny Manage API over HTTPS using an API key you generate in your dashboard.

Learn more at https://www.destinymanage.com

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings, Destiny Manage.
3. Paste the API key from your Destiny Manage dashboard and save.

The site registers itself and appears in your workspace within a few minutes.

== Changelog ==

= 1.1.6 =
* Fixed the self-update download link so updating the plugin from the WordPress updates screen no longer fails.
* "Check Again" on the Updates screen now refreshes the plugin's own version check, so a new release appears immediately instead of after up to 6 hours.

= 1.1.5 =
* Licensed plugin and theme updates no longer report success when the license is inactive and the vendor hides the update; the update now fails with a clear message naming the installed and expected versions.
* Post-update validation compares versions numerically to avoid false mismatches.

= 1.1.4 =
* Connector for uptime monitoring, inventory, health reporting, and safe updates with rollback.
