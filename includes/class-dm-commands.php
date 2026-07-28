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
        register_shutdown_function(function () use ($site_id, $id, $slug, $type, &$finished) {
            if ($finished) {
                return;
            }
            $what = $type === 'create_backup' ? 'backing up this site' : "updating {$slug}";
            $fatal = error_get_last();
            $message = $fatal
                ? ($type === 'create_backup'
                    ? "A fatal PHP error interrupted {$what}: {$fatal['message']}. Large sites can exhaust the server's memory or time limit while the archive is built."
                    : "A fatal PHP error interrupted {$what}: {$fatal['message']}. This can happen if the plugin is incompatible with this PHP version or requires a license/activation the update process can't satisfy.")
                : "The process for {$what} stopped unexpectedly without reporting a result.";
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
                case 'clear_cache':
                    [$result, $error] = self::clear_cache();
                    break;
                case 'create_backup':
                    [$result, $error] = self::create_backup($site_id, $command);
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

        // Backup/cache errors are already written in plain language and aren't
        // about a specific plugin, so don't wrap them in the update-oriented
        // "Could not update {slug}" phrasing.
        if ($error && $type !== 'create_backup' && $type !== 'clear_cache') {
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

    // -------------------------------------------------------------------------
    // Cache clearing — host-agnostic purge matrix
    // -------------------------------------------------------------------------

    /**
     * Purges every cache layer this site actually has, and reports which ones.
     * Runs after updates (queued by the dashboard) and on the manual Clear
     * cache button. Each purge is guarded so a missing or broken cache plugin
     * never fails the command; "nothing to purge" is a success, not an error.
     */
    private static function clear_cache(): array {
        $purged = [];

        // SiteGround Optimizer: purge_everything() flushes the dynamic
        // (NGINX) cache plus Memcached when enabled; fall back to the older
        // helper names for old Speed Optimizer versions.
        try {
            if (class_exists('\SiteGround_Optimizer\Supercacher\Supercacher')) {
                if (method_exists('\SiteGround_Optimizer\Supercacher\Supercacher', 'purge_everything')) {
                    \SiteGround_Optimizer\Supercacher\Supercacher::purge_everything();
                } else {
                    \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
                }
                $purged[] = 'SiteGround dynamic cache';
            } elseif (function_exists('sg_cachepress_purge_everything')) {
                sg_cachepress_purge_everything();
                $purged[] = 'SiteGround dynamic cache';
            } elseif (function_exists('sg_cachepress_purge_cache')) {
                sg_cachepress_purge_cache();
                $purged[] = 'SiteGround dynamic cache';
            }
        } catch (\Throwable $e) {
            // Continue with the other layers; report at the end.
        }

        // WordPress object cache (memcached/redis drop-ins included).
        try {
            if (function_exists('wp_cache_flush') && wp_cache_flush()) {
                $purged[] = 'object cache';
            }
        } catch (\Throwable $e) {
        }

        // Common page-cache plugins, each optional.
        $page_caches = [
            'WP Rocket'        => function () { if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); return true; } return false; },
            'LiteSpeed Cache'  => function () { if (has_action('litespeed_purge_all')) { do_action('litespeed_purge_all'); return true; } return false; },
            'W3 Total Cache'   => function () { if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); return true; } return false; },
            'WP Super Cache'   => function () { if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); return true; } return false; },
            'WP Fastest Cache' => function () { if (function_exists('wpfc_clear_all_cache')) { wpfc_clear_all_cache(true); return true; } return false; },
            'Autoptimize'      => function () { if (class_exists('autoptimizeCache')) { \autoptimizeCache::clearall(); return true; } return false; },
        ];
        foreach ($page_caches as $label => $purge) {
            try {
                if ($purge()) {
                    $purged[] = $label;
                }
            } catch (\Throwable $e) {
            }
        }

        if (empty($purged)) {
            return ['No purgeable cache plugin found on this site; nothing needed clearing.', null];
        }
        return ['Purged: ' . implode(', ', $purged) . '.', null];
    }

    /**
     * Rewrites raw upgrader/exception messages that look like a licensing
     * problem into something an agency can actually act on, while still
     * keeping the original message for troubleshooting.
     */
    // -------------------------------------------------------------------------
    // Full site + database backup
    // -------------------------------------------------------------------------

    /**
     * Best-effort live progress report. A temporary dashboard/API outage must
     * never abort a backup that can still finish and upload successfully.
     */
    private static function report_backup_progress(string $site_id, string $backup_id, string $stage, array $metrics = []): void {
        $payload = array_merge(['stage' => $stage], $metrics);
        DM_API::patch_with_retry(
            "/wordpress/sites/{$site_id}/backups/{$backup_id}/progress",
            $payload,
            2
        );
    }

    /**
     * Build a full archive of the site's files and database and stream it to
     * the destination drive via the Destiny Manage API (which holds the drive
     * credentials — this connector never sees them). Returns [message, error]
     * like the update handlers. The API finalizes the backup record when the
     * final chunk lands.
     */
    private static function create_backup(string $site_id, array $command): array {
        $backup_id = $command['backupId'] ?? '';
        if (!$backup_id) {
            return [null, sprintf('The dashboard did not provide a backup id. Update the %s connector and try again.', DM_PLUGIN_NAME)];
        }

        // Full-site archives are heavy; give the archiver as much room as the
        // host allows. Both are best-effort — many managed hosts lock them.
        @set_time_limit(0);
        $limit = @ini_get('memory_limit');
        if ($limit !== false && trim((string) $limit) !== '-1') {
            @ini_set('memory_limit', '512M');
        }

        $work_dir = self::create_private_backup_work_dir();
        if (is_wp_error($work_dir)) {
            return [null, $work_dir->get_error_message()];
        }

        $sql_path = $work_dir . '/database.sql';
        $zip_path = $work_dir . '/backup.zip';

        try {
            // Preflight: stop before we start writing anything if the server
            // plainly can't finish — a clear "why" up front beats a half-written
            // archive and a vague failure. Estimated size drives the disk check.
            self::report_backup_progress($site_id, $backup_id, 'scanning_files');
            [$files_size, $file_count] = self::estimate_backup_files($work_dir);
            $pre_error  = self::preflight_backup($work_dir, $files_size);
            if ($pre_error) {
                return [null, $pre_error];
            }

            self::report_backup_progress($site_id, $backup_id, 'exporting_database', [
                'sourceFilesBytes' => $files_size,
                'sourceFileCount'  => $file_count,
            ]);
            $db_error = self::dump_database($sql_path);
            if ($db_error) {
                return [null, $db_error];
            }

            // Re-check disk now that we know the database dump size: the zip needs
            // room roughly equal to the files plus the dump (media barely
            // compresses, so assume no saving — better to over-reserve).
            $db_size = (int) (@filesize($sql_path) ?: 0);
            $needed  = $files_size + $db_size;
            $free    = @disk_free_space($work_dir);
            if ($free !== false && $needed > 0 && $free < $needed * 1.05) {
                return [null, sprintf(
                    'Not enough free disk space on the server to build the backup archive: about %s is needed but only %s is free. Free up space on the hosting account and run the backup again.',
                    size_format((int) ($needed * 1.05)),
                    size_format((int) $free)
                )];
            }

            self::report_backup_progress($site_id, $backup_id, 'compressing', [
                'sourceFilesBytes' => $files_size,
                'sourceFileCount'  => $file_count,
                'databaseBytes'    => $db_size,
            ]);
            $zip_error = self::build_backup_archive($zip_path, $sql_path, $work_dir);
            if ($zip_error) {
                return [null, $zip_error];
            }

            $archive_size = (int) (@filesize($zip_path) ?: 0);
            self::report_backup_progress($site_id, $backup_id, 'uploading', [
                'sourceFilesBytes' => $files_size,
                'sourceFileCount'  => $file_count,
                'databaseBytes'    => $db_size,
                'archiveSizeBytes' => $archive_size,
            ]);
            $upload_error = self::upload_backup($site_id, $backup_id, $zip_path);
            if ($upload_error) {
                return [null, $upload_error];
            }

            $size  = $archive_size ?: @filesize($zip_path);
            $human = $size ? size_format($size) : 'unknown size';
            return ["Backup of files and database completed and uploaded to your connected drive ({$human}).", null];
        } finally {
            // Always remove the local temp artifacts, success or failure.
            @unlink($sql_path);
            @unlink($zip_path);
            @rmdir($work_dir);
        }
    }

    /**
     * Create a unique 0700 workspace outside every known web root. Backup
     * artifacts contain the database and wp-config.php, so an uploads-based
     * directory protected only by .htaccess is unsafe on Nginx/static frontends.
     */
    private static function create_private_backup_work_dir(): string|WP_Error {
        $candidates = array_filter(array_unique([
            (string) @sys_get_temp_dir(),
            (string) @ini_get('upload_tmp_dir'),
            defined('WP_TEMP_DIR') ? (string) WP_TEMP_DIR : '',
        ]));
        $public_roots = array_filter(array_unique([
            (string) ABSPATH,
            defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '',
            isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '',
        ]));

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if ($root === false || !is_dir($root) || !is_writable($root)) {
                continue;
            }
            $is_public = false;
            foreach ($public_roots as $public_root) {
                if (self::path_is_within($root, $public_root)) {
                    $is_public = true;
                    break;
                }
            }
            if ($is_public) {
                continue;
            }

            try {
                $suffix = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                continue;
            }
            $work_dir = trailingslashit($root) . 'destiny-manage-backup-' . $suffix;
            if (@mkdir($work_dir, 0700, false)) {
                @chmod($work_dir, 0700);
                return $work_dir;
            }
        }

        return new WP_Error(
            'dm_no_private_temp_dir',
            'Could not find a private temporary directory outside the public website files. Ask the host to configure PHP upload_tmp_dir or WP_TEMP_DIR to a non-public writable directory, then run the backup again.'
        );
    }

    private static function path_is_within(string $path, string $root): bool {
        $path_real = realpath($path);
        $root_real = realpath($root);
        if ($path_real === false || $root_real === false) {
            return false;
        }
        $path_normal = untrailingslashit(wp_normalize_path($path_real));
        $root_normal = untrailingslashit(wp_normalize_path($root_real));
        if (DIRECTORY_SEPARATOR === '\\') {
            $path_normal = strtolower($path_normal);
            $root_normal = strtolower($root_normal);
        }
        return $path_normal === $root_normal || str_starts_with($path_normal, trailingslashit($root_normal));
    }

    /**
     * Fail fast, with a specific reason, if the server can't complete a backup:
     * missing Zip support or not enough free disk to hold the archive. Returns
     * an error string, or null when it's safe to proceed.
     */
    private static function preflight_backup(string $work_dir, int $files_size): ?string {
        if (!class_exists('ZipArchive')) {
            return 'This server does not have the PHP Zip extension enabled, which is required to build a backup archive. Ask the host to enable it, then try again.';
        }
        // Reserve room for the file archive plus headroom for the database dump
        // (unknown yet) — a rough 10% of the files, floored at 64MB.
        $db_headroom = max(64 * 1024 * 1024, (int) ($files_size * 0.1));
        $needed      = $files_size + $db_headroom;
        $free        = @disk_free_space($work_dir);
        if ($free !== false && $files_size > 0 && $free < $needed) {
            return sprintf(
                'Not enough free disk space on the server to build the backup: about %s is needed but only %s is free. Free up space on the hosting account and run the backup again.',
                size_format($needed),
                size_format((int) $free)
            );
        }
        return null;
    }

    /** Count and sum every file that will go into the archive. */
    private static function estimate_backup_files(string $work_dir): array {
        $root      = untrailingslashit(ABSPATH);
        $work_real = realpath($work_dir);
        $excluded  = self::backup_excluded_paths();
        $total     = 0;
        $count     = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if ($work_real && strpos($path, $work_real) === 0) {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', $path), '/\\');
                if ($rel === '' || self::path_is_excluded($rel, $excluded)) {
                    continue;
                }
                $total += (int) $file->getSize();
                $count++;
            }
        } catch (\Throwable $e) {
            // If the estimate can't be computed, skip the disk guard rather than
            // block the backup — the close()/write checks below still catch a
            // genuine out-of-space condition, just later.
            return [0, 0];
        }
        return [$total, $count];
    }

    /** True when exec() is available and not disabled on this host. */
    private static function exec_available(): bool {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) @ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return false;
        }
        return !filter_var(@ini_get('safe_mode'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Dump the database to $sql_path — mysqldump when possible, else PHP. */
    private static function dump_database(string $sql_path): ?string {
        if (self::exec_available()) {
            [$host, $port, $socket] = self::parse_db_host((string) DB_HOST);
            $parts = ['mysqldump', '--no-tablespaces', '--single-transaction', '--quick', '--skip-lock-tables'];
            $parts[] = '--host=' . escapeshellarg($host);
            if ($port)   { $parts[] = '--port=' . escapeshellarg($port); }
            if ($socket) { $parts[] = '--socket=' . escapeshellarg($socket); }
            $parts[] = '--user=' . escapeshellarg(DB_USER);
            $parts[] = escapeshellarg(DB_NAME);
            $parts[] = '--result-file=' . escapeshellarg($sql_path);
            // Pass the password via the environment, not the argv, so it never
            // shows up in the process list.
            $cmd = 'MYSQL_PWD=' . escapeshellarg((string) DB_PASSWORD) . ' ' . implode(' ', $parts) . ' 2>/dev/null';
            @exec($cmd, $out, $code);
            if ($code === 0 && file_exists($sql_path) && filesize($sql_path) > 0) {
                return null;
            }
            // mysqldump missing or refused — fall through to the PHP dump.
        }
        return self::dump_database_php($sql_path);
    }

    /** Split WordPress's DB_HOST into [host, port, socket]. */
    private static function parse_db_host(string $db_host): array {
        $socket = null;
        $port   = null;
        if (strpos($db_host, ':') !== false) {
            [$host, $suffix] = explode(':', $db_host, 2);
            if (is_numeric($suffix)) {
                $port = $suffix;
            } else {
                $socket = $suffix; // unix socket path
            }
        } else {
            $host = $db_host;
        }
        return [$host ?: 'localhost', $port, $socket];
    }

    /** Portable database dump using $wpdb when mysqldump isn't available. */
    private static function dump_database_php(string $sql_path): ?string {
        global $wpdb;
        $fh = @fopen($sql_path, 'w');
        if (!$fh) {
            return 'Could not write the database dump file.';
        }
        // fwrite returns the bytes written, or false/short on a full disk. A
        // helper that throws on any short write turns "silently truncated dump"
        // into a clear out-of-space error.
        $write = static function ($data) use ($fh): void {
            $len     = strlen($data);
            $written = fwrite($fh, $data);
            if ($written === false || $written < $len) {
                throw new \RuntimeException('disk_full');
            }
        };
        try {
            $write(sprintf("-- %s backup\nSET FOREIGN_KEY_CHECKS=0;\n", DM_PLUGIN_NAME));
            $tables = $wpdb->get_col('SHOW TABLES');
            foreach ($tables as $table) {
                $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                if (!$create || !isset($create[1])) {
                    continue;
                }
                $write("\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n");
                $offset = 0;
                $batch  = 500;
                do {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch}", ARRAY_A);
                    foreach ($rows as $row) {
                        $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                        $vals = array_map(function ($v) {
                            return is_null($v) ? 'NULL' : "'" . esc_sql($v) . "'";
                        }, array_values($row));
                        $write("INSERT INTO `{$table}` (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                    }
                    $offset += $batch;
                } while (count($rows) === $batch);
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\RuntimeException $e) {
            fclose($fh);
            return 'Ran out of disk space on the server while writing the database dump. Free up space on the hosting account and run the backup again.';
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
        return null;
    }

    /** Relative path prefixes excluded from the file archive. */
    private static function backup_excluded_paths(): array {
        return [
            'wp-content/cache',
            'wp-content/upgrade',
            'wp-content/dm-backups',          // this connector's per-plugin rollback backups
            'wp-content/uploads/dm-backup-tmp' // our own in-progress archive
        ];
    }

    private static function path_is_excluded(string $rel, array $excluded): bool {
        $rel = str_replace('\\', '/', $rel);
        foreach ($excluded as $prefix) {
            if ($rel === $prefix || strpos($rel, $prefix . '/') === 0) {
                return true;
            }
        }
        // Never archive VCS or dependency dirs anywhere in the tree.
        if (preg_match('#(^|/)(\.git|node_modules)(/|$)#', $rel)) {
            return true;
        }
        return false;
    }

    /** Zip the whole install (minus excludes) plus the DB dump. */
    private static function build_backup_archive(string $zip_path, string $sql_path, string $work_dir): ?string {
        if (!class_exists('ZipArchive')) {
            return 'The PHP Zip extension is not available on this server, so a backup archive cannot be built.';
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return 'Could not create the backup archive file.';
        }
        $zip->addFile($sql_path, 'database.sql');

        $root      = untrailingslashit(ABSPATH);
        $work_real = realpath($work_dir);
        $excluded  = self::backup_excluded_paths();

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $path = $file->getPathname();
                // Skip our own temp working dir (holds the growing zip + sql).
                if ($work_real && strpos($path, $work_real) === 0) {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', $path), '/\\');
                if ($rel === '' || self::path_is_excluded($rel, $excluded)) {
                    continue;
                }
                if ($file->isDir()) {
                    $zip->addEmptyDir($rel);
                } elseif ($file->isFile() && $file->isReadable()) {
                    $zip->addFile($path, $rel);
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            return 'Failed while reading site files for the backup: ' . $e->getMessage();
        }

        // ZipArchive writes the actual file data on close(), so a full disk
        // during compression surfaces here as close() === false, not earlier.
        if ($zip->close() !== true) {
            @unlink($zip_path);
            return 'Ran out of disk space (or could not write) while compressing the backup archive on the server. Free up space on the hosting account and run the backup again.';
        }
        if (!file_exists($zip_path) || filesize($zip_path) === 0) {
            return 'The backup archive came out empty.';
        }
        return null;
    }

    /**
     * Stream the archive to the API. Two-phase: first ask the API to open the
     * destination upload session — it returns the exact chunk size this
     * provider requires (Google 256KB multiples, Microsoft Graph 320KB, Box a
     * server-dictated part size, Dropbox 8MB) — then stream chunks of that size.
     * The whole-file SHA-1 (needed only for Box's commit) is computed once and
     * sent on every chunk so the API has it when the final part lands.
     */
    private static function upload_backup(string $site_id, string $backup_id, string $zip_path): ?string {
        $total = filesize($zip_path);
        if ($total === false || $total <= 0) {
            return 'The backup archive was missing right before upload.';
        }

        // Phase 1: open the session and learn the required chunk size.
        $session = DM_API::post("/wordpress/sites/{$site_id}/backups/{$backup_id}/upload-session", [
            'sizeBytes' => $total,
        ]);
        if (is_wp_error($session)) {
            return 'Could not start the upload to the destination: ' . $session->get_error_message();
        }
        $chunk_size = (int) ($session['data']['chunkSize'] ?? 0);
        if ($chunk_size <= 0) {
            $chunk_size = 8 * 1024 * 1024; // safe default
        }

        // SHA-1 of the whole archive, base64-encoded (Box commit integrity check).
        $file_sha = @sha1_file($zip_path, true);
        $file_sha_b64 = $file_sha !== false ? base64_encode($file_sha) : '';

        // Phase 2: stream the archive in chunks of exactly $chunk_size.
        $fh = @fopen($zip_path, 'rb');
        if (!$fh) {
            return 'Could not open the backup archive for upload.';
        }
        $offset = 0;
        try {
            while (!feof($fh)) {
                $data = fread($fh, $chunk_size);
                if ($data === false) {
                    return 'Could not read the backup archive during upload.';
                }
                $len = strlen($data);
                if ($len === 0) {
                    break;
                }
                $is_final = ($offset + $len) >= $total;
                $res = DM_API::post_binary("/wordpress/sites/{$site_id}/backups/{$backup_id}/parts", $data, [
                    'X-Backup-Offset'     => (string) $offset,
                    'X-Backup-Total-Size' => (string) $total,
                    'X-Backup-Final'      => $is_final ? '1' : '0',
                    'X-Backup-File-Sha'   => $file_sha_b64,
                ]);
                if (is_wp_error($res)) {
                    return 'Upload to the destination failed: ' . $res->get_error_message();
                }
                $offset += $len;
            }
        } finally {
            fclose($fh);
        }
        return null;
    }

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

        // WordPress's own Plugin_Upgrader silently DEACTIVATES an active plugin
        // before upgrading it (deactivate_plugin_before_upgrade) on any request
        // that isn't WP-Cron — which is exactly the path the dashboard's instant
        // "update now" takes (a REST loopback, not cron). It never turns the
        // plugin back on afterwards, so without the reactivation below a healthy
        // update would leave the plugin — including this connector, when it
        // updates itself — switched off. Remember the state going in so every
        // exit path can put it back the way it found it.
        $was_active = is_plugin_active($plugin_file);

        // Back up current plugin directory before touching anything
        $plugin_dir = WP_PLUGIN_DIR . '/' . explode('/', $plugin_file)[0];
        $backup     = self::backup_directory($plugin_dir, 'plugin', $slug, $prev_version);

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after a failed update');
            self::remove_backup($backup);
            return [null, $result->get_error_message()];
        }
        if ($result === false) {
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after a failed update');
            self::remove_backup($backup);
            return [null, "Plugin update failed (no error returned)."];
        }

        // Health check: does the site still respond after the update?
        $health = self::site_health_check();
        if (!$health['ok']) {
            // Site is broken — restore the previous version and leave it active.
            self::restore_from_backup($backup, $plugin_dir);
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after rolling a broken update back');
            return [
                null,
                "Update to v{$new_version} caused the site to return HTTP {$health['code']} — automatically rolled back to v{$prev_version} and left active. Original error: {$health['message']}"
            ];
        }

        // version_compare instead of strict inequality: fail only when the
        // site is still behind the expected version (e.g. the update server
        // silently re-served the current package), but accept the upgrader
        // having delivered something even newer than the transient promised.
        $installed_version = self::installed_plugin_version($plugin_file);
        if (!$installed_version || version_compare($installed_version, $new_version, '<')) {
            // The version didn't move, but WordPress may still have deactivated
            // it on the way in — don't leave it off over a no-op update.
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after an update that did not change the version');
            return [null, self::version_mismatch_message('Update', $new_version, $installed_version)];
        }

        // Healthy update: keep the new version, but make sure WordPress didn't
        // quietly leave the plugin switched off behind our back.
        $reactivated = self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after updating it');

        // Reactivation is logged on-site (see reactivate_if_needed) but not
        // surfaced in the result — it's routine housekeeping, not news.
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
        $was_active   = $plugin_file ? is_plugin_active($plugin_file) : false;
        $prev_version = $plugin_file ? (get_plugins()[$plugin_file]['Version'] ?? 'unknown') : 'unknown';
        $plugin_dir   = WP_PLUGIN_DIR . '/' . $slug;

        // Prefer a local backup of the target version, captured when the site
        // updated away from it. This is the only way to roll back plugins that
        // are not on WordPress.org — including this connector and licensed
        // plugins — and it avoids a network download for everything else.
        $target_backup = self::find_backup('plugin', $slug, $version);

        // Safety backup of the current build so a broken rollback can be undone.
        $safety = is_dir($plugin_dir) ? self::backup_directory($plugin_dir, 'plugin', $slug, $prev_version) : null;

        if ($target_backup) {
            if (is_dir($plugin_dir)) {
                self::remove_dir($plugin_dir);
            }
            self::copy_dir($target_backup, $plugin_dir);
            $source_note = "from a local backup";
        } else {
            $download_url = "https://downloads.wordpress.org/plugin/{$slug}.{$version}.zip";
            $upgrader     = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result       = $upgrader->install($download_url, ['overwrite_package' => true]);

            if (is_wp_error($result)) {
                if ($safety) self::remove_backup($safety);
                return [null, $result->get_error_message()];
            }
            if ($result === false) {
                if ($safety) self::remove_backup($safety);
                return [null, "Rollback failed: no local backup of v{$version} exists and it may not be on WordPress.org."];
            }
            $source_note = "from WordPress.org";
        }

        // Keep it active if it was active going in (a deliberate rollback of an
        // active plugin should stay usable); a plugin the agency had switched
        // off stays off.
        self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after rolling it back');

        $health = self::site_health_check();
        if (!$health['ok']) {
            // Rolled-back version also broken — restore the pre-rollback state
            if ($safety) {
                self::restore_from_backup($safety, $plugin_dir);
            }
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after restoring it');
            return [
                null,
                "Rollback to v{$version} also caused HTTP {$health['code']} — restored v{$prev_version} and left it active. Manual intervention required."
            ];
        }

        $installed_file    = self::find_plugin_file($slug);
        $installed_version = $installed_file ? self::installed_plugin_version($installed_file) : null;
        if ($installed_version !== $version) {
            // Never strand the site: put the pre-rollback build back.
            if ($safety) self::restore_from_backup($safety, $plugin_dir);
            self::reactivate_if_needed($plugin_file, $was_active, $slug, 'after a rollback that did not change the version');
            return [null, self::version_mismatch_message('Rollback', $version, $installed_version)];
        }

        // Success: the target backup is kept for future reuse; drop the safety
        // backup of the build we just moved off.
        if ($safety) self::remove_backup($safety);
        return ["Rolled back {$slug} from v{$prev_version} to v{$version} {$source_note}.", null];
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

        // Prefer a local backup of the target version (see rollback_plugin).
        $target_backup = self::find_backup('theme', $slug, $version);
        $safety        = is_dir($theme_dir) ? self::backup_directory($theme_dir, 'theme', $slug, $prev_version) : null;

        if ($target_backup) {
            if (is_dir($theme_dir)) {
                self::remove_dir($theme_dir);
            }
            self::copy_dir($target_backup, $theme_dir);
            $source_note = "from a local backup";
        } else {
            $download_url = "https://downloads.wordpress.org/theme/{$slug}.{$version}.zip";
            $upgrader     = new Theme_Upgrader(new Automatic_Upgrader_Skin());
            $result       = $upgrader->install($download_url, ['overwrite_package' => true]);

            if (is_wp_error($result)) {
                if ($safety) self::remove_backup($safety);
                return [null, $result->get_error_message()];
            }
            if ($result === false) {
                if ($safety) self::remove_backup($safety);
                return [null, "Rollback failed: no local backup of v{$version} exists and it may not be on WordPress.org."];
            }
            $source_note = "from WordPress.org";
        }

        $health = self::site_health_check();
        if (!$health['ok']) {
            if ($safety) {
                self::restore_from_backup($safety, $theme_dir);
            }
            return [
                null,
                "Rollback to v{$version} also caused HTTP {$health['code']} — restored v{$prev_version}."
            ];
        }

        $installed_version = self::installed_theme_version($slug);
        if ($installed_version !== $version) {
            if ($safety) self::restore_from_backup($safety, $theme_dir);
            return [null, self::version_mismatch_message('Rollback', $version, $installed_version)];
        }

        if ($safety) self::remove_backup($safety);
        return ["Rolled back theme {$slug} from v{$prev_version} to v{$version} {$source_note}.", null];
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

    /**
     * Newest local backup directory matching a type/slug/version, or null.
     * Backups are named "{type}-{slug}-{version}-{timestamp}" by
     * backup_directory(), so rollback can restore an exact earlier build
     * without fetching anything from WordPress.org.
     */
    private static function find_backup(string $type, string $slug, string $version): ?string {
        $root = self::backup_root();
        if (!is_dir($root)) {
            return null;
        }

        $best    = null;
        $best_ts = -1;
        foreach (scandir($root) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!preg_match('/^(plugin|theme)-(.+?)-([\d.]+)-(\d+)$/', $name, $m)) {
                continue;
            }
            if ($m[1] !== $type || $m[2] !== $slug || $m[3] !== $version) {
                continue;
            }
            $ts   = (int) $m[4];
            $path = $root . '/' . $name;
            if ($ts > $best_ts && is_dir($path)) {
                $best    = $path;
                $best_ts = $ts;
            }
        }

        return $best;
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

    // -------------------------------------------------------------------------
    // Keep-active + on-site log
    // -------------------------------------------------------------------------

    /**
     * WordPress's Plugin_Upgrader deactivates an active plugin before upgrading
     * it (deactivate_plugin_before_upgrade) and never turns it back on. If the
     * plugin was active before we started and WordPress has since switched it
     * off, silently re-enable it. Silent (4th arg) means we only restore the
     * "active" flag and do NOT re-run the plugin's activation hook — it was
     * already set up when first activated, and re-running it during a self-
     * update could have unwanted side effects. Logs the correction either way,
     * so "why did my plugin turn itself off after an update?" has an answer.
     * Returns true only when it actually had to reactivate.
     */
    private static function reactivate_if_needed(?string $plugin_file, bool $was_active, string $slug, string $context): bool {
        if (!$plugin_file || !$was_active || is_plugin_active($plugin_file)) {
            return false;
        }
        activate_plugin($plugin_file, '', false, true); // 4th arg: silent
        self::record_log($slug, "Re-enabled {$slug} {$context}: WordPress switches a plugin off while updating it and does not turn it back on.");
        return true;
    }

    /**
     * Append to a small on-site activity log in the options table, newest first
     * and capped so it can't grow without bound. This is the local record of
     * anything the update flow had to correct (a plugin WordPress left disabled,
     * a rollback); the same note also travels to Destiny Manage in the command's
     * result message, so both the site and the dashboard have a trail.
     */
    private static function record_log(string $slug, string $message): void {
        $log = get_option('dm_activity_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            'time'    => gmdate('c'),
            'slug'    => $slug,
            'message' => $message,
        ]);
        // autoload = false: diagnostics, not needed on every page load.
        update_option('dm_activity_log', array_slice($log, 0, 50), false);
    }
}
