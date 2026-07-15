<?php
defined('ABSPATH') || exit;

/**
 * Polls for and executes update commands from Destiny Manage.
 *
 * Crash-safe update flow:
 *  1. Back up the plugin/theme directory before touching anything.
 *  2. Run the upgrader.
 *  3. Immediately fire an HTTP health-check request against the site URL.
 *     If the site returns 5xx or does not respond, restore from backup right
 *     now — within this same PHP process, before reporting anything to the API.
 *  4. Report completed (or failed + auto-rolled-back) to the API.
 *
 * This works because the new plugin code is only loaded on the *next* HTTP
 * request. The upgrader runs in its own PHP execution context, so we can test
 * that next request and restore the old files if it fails — all before this
 * script exits. WP-Cron being dead does not affect this path.
 */
class DM_Commands {

    private const BACKUP_DIR_NAME = 'dm-backups';
    private const HEALTH_CHECK_TIMEOUT = 15;

    // -------------------------------------------------------------------------
    // Polling entry-point (WP-Cron)
    // -------------------------------------------------------------------------

    public static function poll(): void {
        $site_id = get_option('dm_site_id', '');
        if (!$site_id) {
            return;
        }

        $response = DM_API::get("/wordpress/sites/{$site_id}/pending-commands");
        if (is_wp_error($response)) {
            update_option('dm_commands_last_error', $response->get_error_message());
            return;
        }

        $commands = $response['data'] ?? [];
        if (empty($commands)) {
            return;
        }

        foreach ($commands as $command) {
            try {
                self::execute($site_id, $command);
            } catch (\Throwable $e) {
                // execute() already isolates failures per-command; this is
                // just a last-resort net so a truly unexpected error here
                // still doesn't stop the remaining queued commands from
                // being attempted.
                update_option('dm_commands_last_error', $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Instant check-in (REST) — lets the Destiny Manage API ask this site to
    // check for pending commands right now instead of waiting for the next
    // 5-minute WP-Cron tick. WP-Cron is "pseudo-cron": it only fires on real
    // site traffic, so a freshly-queued command on a quiet site could
    // otherwise sit for the full interval with nobody visiting the site.
    //
    // /check-in is deliberately unauthenticated (no secret to share with the
    // API) but rate-limited to one trigger per 10 seconds — the only thing
    // it can do is make this site ask the API "any commands for me?", which
    // is the same question WP-Cron already asks every 5 minutes on its own,
    // so there is nothing meaningfully new to abuse.
    //
    // It responds immediately via a non-blocking loopback to /run, so a
    // slow plugin update never makes the triggering request hang - this is
    // the same fire-and-forget pattern WordPress's own wp-cron.php uses.
    // -------------------------------------------------------------------------

    public static function register_rest_routes(): void {
        register_rest_route('destiny-manage/v1', '/check-in', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_check_in'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('destiny-manage/v1', '/run', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_run'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_check_in(): \WP_REST_Response {
        $last = (int) get_transient('dm_checkin_last');
        if ($last && (time() - $last) < 10) {
            return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
        }
        set_transient('dm_checkin_last', time(), 60);

        wp_remote_post(rest_url('destiny-manage/v1/run'), [
            'blocking'  => false,
            'timeout'   => 0.1,
            'sslverify' => false,
        ]);

        return new \WP_REST_Response(['ok' => true], 200);
    }

    public static function handle_run(): \WP_REST_Response {
        self::poll();
        return new \WP_REST_Response(['ok' => true], 200);
    }

    // -------------------------------------------------------------------------
    // Command dispatcher
    // -------------------------------------------------------------------------

    private static function execute(string $site_id, array $command): void {
        $id   = $command['id'] ?? '';
        $type = $command['commandType'] ?? '';
        $slug = $command['slug'] ?? '';
        $ver  = $command['targetVersion'] ?? null;

        if (!$id || !$type) {
            return;
        }

        DM_API::patch("/wordpress/sites/{$site_id}/commands/{$id}", ['status' => 'running']);

        // Watchdog for true PHP fatals (memory exhaustion, uncatchable Error
        // types thrown by a badly-behaved plugin's own upgrade hooks, etc.)
        // that would otherwise kill this whole request silently: without
        // this, the command stays "running" forever and — because the next
        // poll only looks for "pending" commands — every command queued
        // after it in this same batch never even gets attempted.
        $finished = false;
        register_shutdown_function(function () use ($site_id, $id, $slug, &$finished) {
            if ($finished) {
                return;
            }
            $fatal = error_get_last();
            $message = $fatal
                ? "A fatal PHP error interrupted updating {$slug}: {$fatal['message']}. This can happen if the plugin is incompatible with this PHP version or requires a license/activation the update process can't satisfy."
                : "The update process for {$slug} stopped unexpectedly without reporting a result.";
            DM_API::patch_with_retry("/wordpress/sites/{$site_id}/commands/{$id}", [
                'status'        => 'failed',
                'resultMessage' => $message,
            ]);
        });

        $result = null;
        $error  = null;

        try {
            switch ($type) {
                case 'update_plugin':
                    [$result, $error] = self::update_plugin($slug, $ver);
                    break;
                case 'update_theme':
                    [$result, $error] = self::update_theme($slug, $ver);
                    break;
                case 'update_core':
                    [$result, $error] = self::update_core($ver);
                    break;
                case 'rollback_plugin':
                    [$result, $error] = self::rollback_plugin($slug, $ver);
                    break;
                case 'rollback_theme':
                    [$result, $error] = self::rollback_theme($slug, $ver);
                    break;
                default:
                    $error = "Unknown command type: {$type}";
            }
        } catch (\Throwable $e) {
            // A single plugin throwing (e.g. its own updater rejecting an
            // expired license) must not stop the rest of the queue from
            // running - report this one as failed and move on.
            $error = $e->getMessage();
        }

        if ($error) {
            $error = self::friendly_error_message($slug, $error);
        }

        $status  = $error ? 'failed' : 'completed';
        $message = $error ?? $result ?? 'Done.';

        // Retry the final report specifically: the update already happened
        // locally by this point, so losing this call (e.g. the Destiny
        // Manage API happens to be mid-deploy) would strand a
        // finished/failed command showing "running" forever.
        DM_API::patch_with_retry("/wordpress/sites/{$site_id}/commands/{$id}", [
            'status'        => $status,
            'resultMessage' => $message,
        ]);
        $finished = true;

        if (!$error) {
            DM_Collector::push();
        }

        self::cleanup_old_backups();
    }

    /**
     * Rewrites raw upgrader/exception messages that look like a licensing
     * problem into something an agency can actually act on, while still
     * keeping the original message for troubleshooting.
     */
    private static function friendly_error_message(string $slug, string $raw_message): string {
        $haystack = strtolower($raw_message);
        // Messages this class crafted itself are already actionable — don't
        // wrap them a second time just because they mention the word license.
        if (str_contains($haystack, 'check that its license') || str_contains($haystack, 'check the license')) {
            return $raw_message;
        }
        $license_hints = ['license', 'licence', 'expired', 'subscription', 'not activated', 'activation', 'unauthorized', 'invalid key'];
        foreach ($license_hints as $hint) {
            if (str_contains($haystack, $hint)) {
                return "Could not update {$slug} — this plugin may need an active license or subscription to receive updates. Other queued updates were not affected. Original error: {$raw_message}";
            }
        }
        return "Could not update {$slug}: {$raw_message}";
    }

    /**
     * Some licensed plugins/themes silently serve the *current* package
     * instead of failing outright when the site's license is invalid or
     * expired — WordPress's upgrader then reports success (no WP_Error,
     * result === true) even though nothing actually changed on disk. Always
     * re-read the real installed version after an upgrade instead of trusting
     * the upgrader's return value alone.
     */
    private static function installed_plugin_version(string $plugin_file): ?string {
        wp_clean_plugins_cache(true);
        $all = get_plugins();
        return $all[$plugin_file]['Version'] ?? null;
    }

    private static function installed_theme_version(string $slug): ?string {
        wp_clean_themes_cache(true);
        $themes = wp_get_themes();
        return isset($themes[$slug]) ? $themes[$slug]->get('Version') : null;
    }

    private static function version_mismatch_message(string $action, string $expected, ?string $actual): string {
        $actual_label = $actual ?? 'unknown';
        return "{$action} to v{$expected} reported success, but the installed version is still v{$actual_label}. "
            . "This usually means the update server silently served the same package instead of failing outright — "
            . "check that its license or subscription is active, then try again.";
    }

    // -------------------------------------------------------------------------
    // Plugin update — crash-safe
    // -------------------------------------------------------------------------

    private static function update_plugin(string $slug, ?string $target_version = null): array {
        self::load_upgrade_functions();

        $plugin_file = self::find_plugin_file($slug);
        if (!$plugin_file) {
            return [null, "Plugin not found: {$slug}"];
        }

        wp_clean_plugins_cache(true);
        wp_update_plugins();

        $updates = get_plugin_updates();
        if (!isset($updates[$plugin_file])) {
            // Licensed plugins with an inactive license often hide their
            // update from WordPress entirely instead of failing. If the
            // dashboard asked for a newer version than what's installed and
            // this site can't even see that update, that's a failure — not
            // "already updated".
            $installed = get_plugins()[$plugin_file]['Version'] ?? null;
            if ($target_version && $installed && version_compare($installed, $target_version, '<')) {
                return [null, "This site doesn't see an update for {$slug} (installed v{$installed}, expected v{$target_version}). "
                    . "Licensed plugins hide their updates when the license or subscription is inactive — check the license on the site, then try again."];
            }
            return ["Already at latest version" . ($installed ? " (v{$installed})" : "") . ".", null];
        }

        $prev_version = get_plugins()[$plugin_file]['Version'] ?? 'unknown';
        $new_version  = $updates[$plugin_file]->update->new_version ?? 'unknown';

        // Back up current plugin directory before touching anything
        $plugin_dir = WP_PLUGIN_DIR . '/' . explode('/', $plugin_file)[0];
        $backup     = self::backup_directory($plugin_dir, 'plugin', $slug, $prev_version);

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            self::remove_backup($backup);
            return [null, $result->get_error_message()];
        }
        if ($result === false) {
            self::remove_backup($backup);
            return [null, "Plugin update failed (no error returned)."];
        }

        // Health check: does the site still respond after the update?
        $health = self::site_health_check();
        if (!$health['ok']) {
            // Site is broken — restore immediately from backup
            self::restore_from_backup($backup, $plugin_dir);
            // Re-activate plugin if it was active
            activate_plugin($plugin_file);
            return [
                null,
                "Update to v{$new_version} caused site to return HTTP {$health['code']} — auto-rolled back to v{$prev_version}. Original error: {$health['message']}"
            ];
        }

        // version_compare instead of strict inequality: fail only when the
        // site is still behind the expected version (e.g. the update server
        // silently re-served the current package), but accept the upgrader
        // having delivered something even newer than the transient promised.
        $installed_version = self::installed_plugin_version($plugin_file);
        if (!$installed_version || version_compare($installed_version, $new_version, '<')) {
            return [null, self::version_mismatch_message('Update', $new_version, $installed_version)];
        }

        $backup_note = $backup ? "Backed up v{$prev_version} before updating. " : "";
        return ["{$backup_note}Updated {$slug} from v{$prev_version} to v{$installed_version}.", null];
    }

    // -------------------------------------------------------------------------
    // Theme update — crash-safe
    // -------------------------------------------------------------------------

    private static function update_theme(string $slug, ?string $target_version = null): array {
        self::load_upgrade_functions();

        wp_clean_themes_cache(true);
        wp_update_themes();

        $updates = get_theme_updates();
        if (!isset($updates[$slug])) {
            // Same license-hidden-update detection as plugins: commercial
            // themes drop their update entry when the license lapses.
            $all_themes = wp_get_themes();
            $installed  = isset($all_themes[$slug]) ? $all_themes[$slug]->get('Version') : null;
            if ($target_version && $installed && version_compare($installed, $target_version, '<')) {
                return [null, "This site doesn't see an update for theme {$slug} (installed v{$installed}, expected v{$target_version}). "
                    . "Licensed themes hide their updates when the license or subscription is inactive — check the license on the site, then try again."];
            }
            return ["Already at latest version" . ($installed ? " (v{$installed})" : "") . ".", null];
        }

        $themes      = wp_get_themes();
        $prev_version = isset($themes[$slug]) ? $themes[$slug]->get('Version') : 'unknown';
        $new_version  = $updates[$slug]->update['new_version'] ?? 'unknown';

        $theme_dir = get_theme_root() . '/' . $slug;
        $backup    = self::backup_directory($theme_dir, 'theme', $slug, $prev_version);

        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($slug);

        if (is_wp_error($result)) {
            self::remove_backup($backup);
            return [null, $result->get_error_message()];
        }

        $health = self::site_health_check();
        if (!$health['ok']) {
            self::restore_from_backup($backup, $theme_dir);
            return [
                null,
                "Update to v{$new_version} caused site to return HTTP {$health['code']} — auto-rolled back to v{$prev_version}. Original error: {$health['message']}"
            ];
        }

        $installed_version = self::installed_theme_version($slug);
        if (!$installed_version || version_compare($installed_version, $new_version, '<')) {
            return [null, self::version_mismatch_message('Update', $new_version, $installed_version)];
        }

        $backup_note = $backup ? "Backed up v{$prev_version} before updating. " : "";
        return ["{$backup_note}Updated theme {$slug} from v{$prev_version} to v{$installed_version}.", null];
    }

    // -------------------------------------------------------------------------
    // WordPress core update
    // -------------------------------------------------------------------------

    private static function update_core(?string $target_version): array {
        self::load_upgrade_functions();
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $updates = get_core_updates();
        if (empty($updates)) {
            return ["WordPress core is already up to date.", null];
        }

        $update = reset($updates);
        if ($target_version) {
            foreach ($updates as $u) {
                if ($u->version === $target_version) {
                    $update = $u;
                    break;
                }
            }
        }

        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($update, ['attempt_rollback' => false]);

        if (is_wp_error($result)) {
            return [null, $result->get_error_message()];
        }

        // Core updates have their own rollback if they cause a fatal (WP 6.3+);
        // we do a health check as an extra safety net.
        $health = self::site_health_check();
        if (!$health['ok']) {
            return [
                null,
                "WordPress core updated to v{$update->version} but site health check returned HTTP {$health['code']}. Manual intervention may be needed. Original error: {$health['message']}"
            ];
        }

        global $wp_version;
        wp_version_check([], true);
        require ABSPATH . WPINC . '/version.php'; // refreshes $wp_version to the on-disk value
        if ($wp_version !== $update->version) {
            return [null, self::version_mismatch_message('WordPress core update', $update->version, $wp_version)];
        }

        return ["WordPress updated to v{$update->version}.", null];
    }

    // -------------------------------------------------------------------------
    // Plugin rollback — crash-safe
    // -------------------------------------------------------------------------

    private static function rollback_plugin(string $slug, ?string $version): array {
        if (!$version) {
            return [null, "A target version is required for rollback."];
        }

        self::load_upgrade_functions();

        $plugin_file  = self::find_plugin_file($slug);
        $prev_version = $plugin_file ? (get_plugins()[$plugin_file]['Version'] ?? 'unknown') : 'unknown';
        $plugin_dir   = WP_PLUGIN_DIR . '/' . $slug;
        $backup       = is_dir($plugin_dir) ? self::backup_directory($plugin_dir, 'plugin', $slug, $prev_version) : null;

        $download_url = "https://downloads.wordpress.org/plugin/{$slug}.{$version}.zip";
        $upgrader     = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result       = $upgrader->install($download_url, ['overwrite_package' => true]);

        if (is_wp_error($result)) {
            if ($backup) self::remove_backup($backup);
            return [null, $result->get_error_message()];
        }
        if ($result === false) {
            if ($backup) self::remove_backup($backup);
            return [null, "Rollback failed. Version {$version} may not be on WordPress.org."];
        }

        if ($plugin_file) {
            activate_plugin($plugin_file);
        }

        $health = self::site_health_check();
        if (!$health['ok']) {
            // Rolled-back version also broken — restore the pre-rollback state
            if ($backup) {
                self::restore_from_backup($backup, WP_PLUGIN_DIR . '/' . $slug);
            }
            return [
                null,
                "Rollback to v{$version} also caused HTTP {$health['code']} — restored v{$prev_version}. Manual intervention required."
            ];
        }

        $installed_file    = self::find_plugin_file($slug);
        $installed_version = $installed_file ? self::installed_plugin_version($installed_file) : null;
        if ($installed_version !== $version) {
            return [null, self::version_mismatch_message('Rollback', $version, $installed_version)];
        }

        $backup_note = $backup ? "Backed up v{$prev_version} before rolling back. " : "";
        return ["{$backup_note}Rolled back {$slug} to v{$version}.", null];
    }

    // -------------------------------------------------------------------------
    // Theme rollback
    // -------------------------------------------------------------------------

    private static function rollback_theme(string $slug, ?string $version): array {
        if (!$version) {
            return [null, "A target version is required for rollback."];
        }

        self::load_upgrade_functions();

        $themes       = wp_get_themes();
        $prev_version = isset($themes[$slug]) ? $themes[$slug]->get('Version') : 'unknown';
        $theme_dir    = get_theme_root() . '/' . $slug;
        $backup       = is_dir($theme_dir) ? self::backup_directory($theme_dir, 'theme', $slug, $prev_version) : null;

        $download_url = "https://downloads.wordpress.org/theme/{$slug}.{$version}.zip";
        $upgrader     = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result       = $upgrader->install($download_url, ['overwrite_package' => true]);

        if (is_wp_error($result)) {
            if ($backup) self::remove_backup($backup);
            return [null, $result->get_error_message()];
        }

        $health = self::site_health_check();
        if (!$health['ok']) {
            if ($backup) {
                self::restore_from_backup($backup, $theme_dir);
            }
            return [
                null,
                "Rollback to v{$version} also caused HTTP {$health['code']} — restored v{$prev_version}."
            ];
        }

        $installed_version = self::installed_theme_version($slug);
        if ($installed_version !== $version) {
            return [null, self::version_mismatch_message('Rollback', $version, $installed_version)];
        }

        $backup_note = $backup ? "Backed up v{$prev_version} before rolling back. " : "";
        return ["{$backup_note}Rolled back theme {$slug} to v{$version}.", null];
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    /**
     * Make an HTTP request to the site front-end and check the response code.
     * A 2xx or 3xx response means the site is alive.
     * 5xx, connection errors, or timeouts mean the update broke something.
     */
    private static function site_health_check(): array {
        $url = get_site_url();

        // Use a fresh uncached request; skip SSL verify for local/staging sites
        $response = wp_remote_get($url, [
            'timeout'   => self::HEALTH_CHECK_TIMEOUT,
            'sslverify' => false,
            'headers'   => ['Cache-Control' => 'no-cache'],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok'      => false,
                'code'    => 0,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $ok   = $code >= 200 && $code < 500;

        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $ok ? '' : wp_remote_retrieve_response_message($response),
        ];
    }

    // -------------------------------------------------------------------------
    // Backup helpers
    // -------------------------------------------------------------------------

    private static function backup_root(): string {
        $dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Prevent direct web access to backups
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        return $dir;
    }

    private static function backup_directory(string $source, string $type, string $slug, string $version): ?string {
        if (!is_dir($source)) {
            return null;
        }

        $backup_name = "{$type}-{$slug}-{$version}-" . time();
        $dest        = self::backup_root() . '/' . $backup_name;

        if (!self::copy_dir($source, $dest)) {
            return null;
        }

        return $dest;
    }

    private static function restore_from_backup(?string $backup, string $dest): void {
        if (!$backup || !is_dir($backup)) {
            return;
        }

        // Remove the broken installation
        if (is_dir($dest)) {
            self::remove_dir($dest);
        }

        self::copy_dir($backup, $dest);
        self::remove_backup($backup);
    }

    private static function remove_backup(?string $path): void {
        if ($path && is_dir($path)) {
            self::remove_dir($path);
        }
    }

    /** Remove backups older than 7 days, keeping at most 5 per slug. */
    private static function cleanup_old_backups(): void {
        $root = self::backup_root();
        if (!is_dir($root)) {
            return;
        }

        $entries = array_filter(scandir($root), fn($e) => $e !== '.' && $e !== '..' && $e !== '.htaccess');
        $by_slug = [];
        foreach ($entries as $name) {
            if (preg_match('/^(plugin|theme)-(.+?)-[\d.]+-(\d+)$/', $name, $m)) {
                $by_slug[$m[2]][] = ['name' => $name, 'ts' => (int)$m[3]];
            }
        }

        foreach ($by_slug as $slug => $list) {
            usort($list, fn($a, $b) => $b['ts'] - $a['ts']);
            foreach (array_slice($list, 5) as $old) {
                self::remove_dir($root . '/' . $old['name']);
            }
        }

        // Also purge anything older than 7 days regardless of count
        $cutoff = time() - 7 * 86400;
        foreach ($entries as $name) {
            $path = $root . '/' . $name;
            if (is_dir($path) && filemtime($path) < $cutoff) {
                self::remove_dir($path);
            }
        }
    }

    private static function copy_dir(string $src, string $dst): bool {
        if (!is_dir($src)) {
            return false;
        }
        wp_mkdir_p($dst);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $rel  = substr($item->getPathname(), strlen($src) + 1);
            $dest = $dst . '/' . $rel;
            if ($item->isDir()) {
                wp_mkdir_p($dest);
            } else {
                copy($item->getPathname(), $dest);
            }
        }
        return true;
    }

    private static function remove_dir(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    // -------------------------------------------------------------------------
    // WordPress helpers
    // -------------------------------------------------------------------------

    private static function load_upgrade_functions(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        }
    }

    private static function find_plugin_file(string $slug): ?string {
        $all = get_plugins();
        foreach (array_keys($all) as $file) {
            if (explode('/', $file)[0] === $slug) {
                return $file;
            }
        }
        return null;
    }
}
