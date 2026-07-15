<?php
/**
 * Plugin Name:  Destiny Manage
 * Plugin URI:   https://destinymanage.com
 * Description:  Connect your WordPress site to Destiny Manage for automated monitoring, plugin tracking, health reporting, and client management.
 * Version:      1.1.6
 * Author:       Destiny Manage
 * Author URI:   https://destinymanage.com
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  destiny-manage
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Tested up to: 6.8
 */

defined('ABSPATH') || exit;

define('DM_VERSION',           '1.1.6');
define('DM_SLUG',              'destiny-manage');
define('DM_PLUGIN_FILE',       __FILE__);
define('DM_PLUGIN_DIR',        plugin_dir_path(__FILE__));
define('DM_API_BASE',          'https://www.destinymanage.com/v1');
define('DM_CRON_HOOK',         'dm_hourly_push');
define('DM_COMMANDS_CRON_HOOK','dm_check_commands');
define('DM_UPDATE_URL',        'https://www.destinymanage.com/v1/wordpress/plugin/info');

require_once DM_PLUGIN_DIR . 'includes/class-dm-api.php';
require_once DM_PLUGIN_DIR . 'includes/class-dm-settings.php';
require_once DM_PLUGIN_DIR . 'includes/class-dm-collector.php';
require_once DM_PLUGIN_DIR . 'includes/class-dm-updater.php';
require_once DM_PLUGIN_DIR . 'includes/class-dm-commands.php';

// Boot
add_action('init', ['DM_Settings', 'init']);
add_action('admin_menu', ['DM_Settings', 'add_menu']);
add_action('admin_init', ['DM_Settings', 'register_settings']);
add_action('admin_notices', ['DM_Settings', 'admin_notice']);

// Cron: hourly data push
add_action(DM_CRON_HOOK, ['DM_Collector', 'push']);

// Cron: poll for update commands every 5 minutes (fallback; the API also
// triggers an immediate check-in over REST when a command is queued)
add_filter('cron_schedules', 'dm_add_cron_intervals');
add_action(DM_COMMANDS_CRON_HOOK, ['DM_Commands', 'poll']);
add_action('rest_api_init', ['DM_Commands', 'register_rest_routes']);

function dm_add_cron_intervals(array $schedules): array {
    if (!isset($schedules['dm_five_minutes'])) {
        $schedules['dm_five_minutes'] = [
            'interval' => 300,
            'display'  => __('Every 5 minutes', 'destiny-manage'),
        ];
    }
    return $schedules;
}

// Auto-updater
add_filter('pre_set_site_transient_update_plugins', ['DM_Updater', 'check_update']);
add_filter('plugins_api', ['DM_Updater', 'plugin_info'], 10, 3);
// "Check Again" on Dashboard → Updates deletes WP's update transient; drop
// our own cached version-check with it so a forced check is actually fresh.
add_action('delete_site_transient_update_plugins', ['DM_Updater', 'flush_cache']);

// Activation / deactivation
register_activation_hook(__FILE__, 'dm_activate');
register_deactivation_hook(__FILE__, 'dm_deactivate');

function dm_activate(): void {
    if (!wp_next_scheduled(DM_CRON_HOOK)) {
        wp_schedule_event(time(), 'hourly', DM_CRON_HOOK);
    }
    if (!wp_next_scheduled(DM_COMMANDS_CRON_HOOK)) {
        wp_schedule_event(time(), 'dm_five_minutes', DM_COMMANDS_CRON_HOOK);
    }
    // Attempt immediate registration if API key is already set
    $api_key = get_option('dm_api_key', '');
    if ($api_key) {
        DM_Collector::register_site();
    }
}

function dm_deactivate(): void {
    foreach ([DM_CRON_HOOK, DM_COMMANDS_CRON_HOOK] as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }
}
