<?php
defined('ABSPATH') || exit;

/**
 * Hooks into WordPress's update mechanism to deliver plugin updates
 * directly from the Destiny Manage server.
 */
class DM_Updater {

    private static ?object $remote_data = null;

    /**
     * Fetch version info from the Destiny Manage API (cached per request).
     */
    private static function get_remote(): ?object {
        if (self::$remote_data !== null) {
            return self::$remote_data;
        }

        $transient_key = 'dm_plugin_update_check';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            self::$remote_data = $cached;
            return self::$remote_data;
        }

        $response = DM_API::get_public(DM_UPDATE_URL);
        if (is_wp_error($response) || empty($response['data'])) {
            return null;
        }

        $data = (object) $response['data'];
        set_transient($transient_key, $data, 6 * HOUR_IN_SECONDS);
        self::$remote_data = $data;
        return $data;
    }

    /**
     * Injected into the update_plugins transient so WordPress shows
     * an update notification when a new version is available.
     *
     * @param object $transient
     * @return object
     */
    public static function check_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = self::get_remote();
        if (!$remote) {
            return $transient;
        }

        $plugin_basename = plugin_basename(DM_PLUGIN_FILE);
        $current_version = $transient->checked[$plugin_basename] ?? DM_VERSION;

        if (version_compare($remote->version, $current_version, '>')) {
            $obj              = new stdClass();
            $obj->slug        = DM_SLUG;
            $obj->plugin      = $plugin_basename;
            $obj->new_version = $remote->version;
            $obj->tested      = $remote->tested ?? '';
            $obj->package     = $remote->download_url ?? '';
            $obj->url         = 'https://destinymanage.com';

            $transient->response[$plugin_basename] = $obj;
        }

        return $transient;
    }

    /**
     * Provides plugin details for the "View details" popup in wp-admin.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public static function plugin_info(false|object|array $result, string $action, object $args): false|object {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (($args->slug ?? '') !== DM_SLUG) {
            return $result;
        }

        $remote = self::get_remote();
        if (!$remote) {
            return $result;
        }

        $info                = new stdClass();
        $info->name          = 'Destiny Manage';
        $info->slug          = DM_SLUG;
        $info->version       = $remote->version ?? DM_VERSION;
        $info->tested        = $remote->tested ?? '';
        $info->requires      = $remote->requires ?? '6.0';
        $info->requires_php  = $remote->requires_php ?? '8.0';
        $info->author        = '<a href="https://destinymanage.com">Destiny Manage</a>';
        $info->download_link = $remote->download_url ?? '';
        $info->sections      = (array) ($remote->sections ?? [
            'description' => 'Connect your WordPress site to Destiny Manage.',
        ]);

        return $info;
    }
}
