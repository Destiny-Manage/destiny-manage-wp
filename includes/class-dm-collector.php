<?php
defined('ABSPATH') || exit;

/**
 * Collects WordPress site data and pushes it to Destiny Manage.
 */
class DM_Collector {

    /**
     * Register this site with the Destiny Manage API.
     * Called on activation if an API key is set, or after saving settings.
     */
    public static function register_site(): array|WP_Error {
        $site_name = get_option('dm_site_name', get_bloginfo('name'));
        $site_url  = get_site_url();

        $result = DM_API::post('/wordpress/sites', [
            'siteUrl'  => $site_url,
            'siteName' => $site_name ?: $site_url,
        ]);

        if (is_wp_error($result)) {
            update_option('dm_last_error', $result->get_error_message());
            return $result;
        }

        $site_id = $result['data']['id'] ?? '';
        if ($site_id) {
            update_option('dm_site_id', $site_id);
            delete_option('dm_last_error');
        }

        return $result;
    }

    /**
     * Collect all site data and push it to the API.
     * Called hourly via WP-Cron.
     */
    public static function push(): array|WP_Error {
        // Ensure site is registered first
        $site_id = get_option('dm_site_id', '');
        if (!$site_id) {
            $reg = self::register_site();
            if (is_wp_error($reg)) {
                return $reg;
            }
            $site_id = get_option('dm_site_id', '');
        }

        if (!$site_id) {
            return new WP_Error('dm_no_site_id', 'Site registration failed.');
        }

        $data   = self::collect();
        $result = DM_API::post("/wordpress/sites/{$site_id}/push", $data);

        if (is_wp_error($result)) {
            update_option('dm_last_error', $result->get_error_message());
            return $result;
        }

        update_option('dm_last_push', current_time('mysql'));
        delete_option('dm_last_error');
        return $result;
    }

    /**
     * Collect WP version, PHP version, plugins, and themes.
     */
    public static function collect(): array {
        return [
            'wpVersion'  => self::wp_version(),
            'phpVersion' => PHP_VERSION,
            'plugins'    => self::plugins(),
            'themes'     => self::themes(),
        ];
    }

    private static function wp_version(): string {
        global $wp_version;
        return $wp_version ?? get_bloginfo('version');
    }

    private static function plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $updates        = get_plugin_updates();

        $result = [];
        foreach ($all_plugins as $file => $data) {
            $slug = explode('/', $file)[0];
            $result[] = [
                'slug'            => $slug,
                'name'            => $data['Name'],
                'version'         => $data['Version'],
                'active'          => in_array($file, $active_plugins, true),
                'updateAvailable' => isset($updates[$file]),
                'newVersion'      => $updates[$file]->update->new_version ?? null,
            ];
        }

        return $result;
    }

    private static function themes(): array {
        $themes       = wp_get_themes();
        $active_theme = wp_get_theme();
        $updates      = get_theme_updates();

        $result = [];
        foreach ($themes as $slug => $theme) {
            $result[] = [
                'slug'            => $slug,
                'name'            => $theme->get('Name'),
                'version'         => $theme->get('Version'),
                'active'          => ($slug === $active_theme->get_stylesheet()),
                'updateAvailable' => isset($updates[$slug]),
                'newVersion'      => $updates[$slug]->update['new_version'] ?? null,
            ];
        }

        return $result;
    }
}

// Re-register site when API key is saved
add_action('update_option_dm_api_key', function ($old, $new) {
    if ($new) {
        DM_Collector::register_site();
    }
}, 10, 2);
