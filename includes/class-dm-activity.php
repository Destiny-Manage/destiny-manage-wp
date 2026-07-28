<?php
defined('ABSPATH') || exit;

/**
 * Captures user-attributable WordPress activity and delivers it to Destiny
 * Manage without putting the remote API on the critical path of a page save,
 * login, upload, or settings change.
 */
class DM_Activity {
    private const DB_VERSION = '1';
    private const QUEUE_LIMIT = 5000;
    private const BATCH_SIZE = 100;
    private const FLUSH_HOOK = 'dm_activity_flush';

    private static bool $booted = false;
    private static bool $table_ready = false;
    private static array $pending_posts = [];
    private static array $pending_meta_before = [];
    private static array $pending_meta_changes = [];
    private static array $pending_options = [];

    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [self::class, 'ensure_table'], 1);
        add_action('shutdown', [self::class, 'shutdown'], PHP_INT_MAX);
        add_action(self::FLUSH_HOOK, [self::class, 'flush']);
        if (defined('DM_COMMANDS_CRON_HOOK')) {
            add_action(DM_COMMANDS_CRON_HOOK, [self::class, 'flush'], 20);
        }

        // Authentication.
        add_action('wp_login', [self::class, 'login_succeeded'], 10, 2);
        add_action('wp_login_failed', [self::class, 'login_failed'], 10, 2);
        add_action('wp_logout', [self::class, 'logout'], 10, 1);
        add_action('password_reset', [self::class, 'password_reset'], 10, 2);

        // Users.
        add_action('user_register', [self::class, 'user_created'], 10, 2);
        add_action('profile_update', [self::class, 'user_updated'], 10, 3);
        add_action('delete_user', [self::class, 'user_deleted'], 10, 3);
        add_action('set_user_role', [self::class, 'user_role_changed'], 10, 3);

        // Posts, pages, custom post types, ACF, and builder metadata.
        add_action('wp_after_insert_post', [self::class, 'post_saved'], 10, 4);
        add_action('before_delete_post', [self::class, 'post_deleted'], 10, 2);
        add_filter('acf/update_value', [self::class, 'acf_value_updated'], 5, 4);
        add_filter('add_post_metadata', [self::class, 'before_meta_add'], 10, 5);
        add_filter('update_post_metadata', [self::class, 'before_meta_update'], 10, 5);
        add_filter('delete_post_metadata', [self::class, 'before_meta_delete'], 10, 5);
        add_action('added_post_meta', [self::class, 'after_meta_change'], 10, 4);
        add_action('updated_post_meta', [self::class, 'after_meta_change'], 10, 4);
        add_action('deleted_post_meta', [self::class, 'after_meta_delete'], 10, 4);

        // Media.
        add_action('add_attachment', [self::class, 'attachment_added']);
        add_action('attachment_updated', [self::class, 'attachment_updated'], 10, 3);
        add_action('delete_attachment', [self::class, 'attachment_deleted'], 10, 2);

        // Taxonomies, comments, and navigation menus.
        add_action('created_term', [self::class, 'term_created'], 10, 4);
        add_action('edited_term', [self::class, 'term_updated'], 10, 4);
        add_action('delete_term', [self::class, 'term_deleted'], 10, 5);
        add_action('wp_insert_comment', [self::class, 'comment_created'], 10, 2);
        add_action('edit_comment', [self::class, 'comment_updated'], 10, 2);
        add_action('transition_comment_status', [self::class, 'comment_status_changed'], 10, 3);
        add_action('delete_comment', [self::class, 'comment_deleted'], 10, 2);
        add_action('wp_update_nav_menu', [self::class, 'menu_updated'], 10, 2);
        add_action('wp_delete_nav_menu', [self::class, 'menu_deleted'], 10, 1);
        add_filter('widget_update_callback', [self::class, 'widget_updated'], PHP_INT_MAX, 4);

        // Plugins, themes, core updates, and selected WordPress settings.
        add_action('activated_plugin', [self::class, 'plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [self::class, 'plugin_deactivated'], 10, 2);
        add_action('deleted_plugin', [self::class, 'plugin_deleted'], 10, 2);
        add_action('switch_theme', [self::class, 'theme_switched'], 10, 3);
        add_action('deleted_theme', [self::class, 'theme_deleted'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'upgrade_completed'], 10, 2);
        add_action('updated_option', [self::class, 'option_updated'], 10, 3);
    }

    public static function activate(): void {
        self::ensure_table();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::FLUSH_HOOK);
    }

    public static function ensure_table(): void {
        if (self::$table_ready) {
            return;
        }
        if (get_option('dm_activity_db_version') === self::DB_VERSION) {
            self::$table_ready = true;
            return;
        }
        global $wpdb;
        $table = self::table_name();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(128) NOT NULL,
            payload longtext NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY available_at (available_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option('dm_activity_db_version', self::DB_VERSION, false);
        self::$table_ready = true;
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'dm_activity_queue';
    }

    private static function should_capture_user_action(): bool {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_doing_cron()) {
            return false;
        }
        return get_current_user_id() > 0;
    }

    private static function source(): string {
        if (defined('WP_CLI') && WP_CLI) {
            return 'wp_cli';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        return is_admin() ? 'admin' : 'frontend';
    }

    private static function client_ip(): ?string {
        $candidate = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        /**
         * Hosts with a verified reverse proxy may replace REMOTE_ADDR through
         * this filter. Forwarded headers are not trusted by default because a
         * public origin lets visitors spoof them.
         */
        $candidate = (string) apply_filters('dm_activity_client_ip', $candidate);
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }

    private static function actor(?WP_User $user = null): array {
        $user = $user ?: wp_get_current_user();
        if ($user && $user->exists()) {
            $roles = array_values((array) $user->roles);
            return [
                'actorType'        => 'user',
                'actorWpUserId'    => (string) $user->ID,
                'actorLogin'       => $user->user_login,
                'actorDisplayName' => $user->display_name ?: $user->user_login,
                'actorRole'        => $roles[0] ?? null,
            ];
        }
        return ['actorType' => 'anonymous'];
    }

    private static function queue_event(array $event, ?WP_User $actor = null): void {
        $occurred_at = $event['occurredAt'] ?? gmdate('c');
        $event = array_merge([
            'externalId'      => wp_generate_uuid4(),
            'severity'        => 'info',
            'source'          => self::source(),
            'occurrenceCount' => 1,
            'occurredAt'      => $occurred_at,
            'lastOccurredAt'  => $event['lastOccurredAt'] ?? $occurred_at,
            'ipAddress'       => self::client_ip(),
        ], self::actor($actor), $event);
        $event = self::redact($event);
        $payload = wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload && strlen($payload) > 65536 && !empty($event['changes']) && is_array($event['changes'])) {
            $event['changes'] = array_slice($event['changes'], 0, 20);
            $event['details'] = ['truncated' => true];
            $payload = wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!$payload || strlen($payload) > 65536) {
            return;
        }

        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (event_id, payload, attempts, available_at, created_at)
             VALUES (%s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), attempts = 0, available_at = UTC_TIMESTAMP()",
            (string) $event['externalId'],
            $payload
        );
        $wpdb->query($sql);
        self::schedule_flush(60);

        // Keep an unreachable API or a distributed login attack from growing
        // the site's own database without bound.
        if (wp_rand(1, 25) === 1) {
            $wpdb->query("DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)");
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            if ($count > self::QUEUE_LIMIT) {
                $remove = $count - self::QUEUE_LIMIT;
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $remove));
            }
        }
    }

    private static function schedule_flush(int $delay): void {
        if (!wp_next_scheduled(self::FLUSH_HOOK)) {
            wp_schedule_single_event(time() + max(10, $delay), self::FLUSH_HOOK);
        }
    }

    public static function shutdown(): void {
        self::queue_pending_content();
        self::dispatch(true);
    }

    public static function flush(): void {
        self::queue_pending_content();
        self::dispatch(false);
    }

    private static function dispatch(bool $async): void {
        $site_id = (string) get_option('dm_site_id', '');
        $api_key = (string) get_option('dm_api_key', '');
        if (!$site_id || !$api_key) {
            return;
        }
        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        $limit = $async ? 50 : self::BATCH_SIZE;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, payload FROM {$table} WHERE available_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        if (!$rows) {
            return;
        }
        $events = [];
        $ids = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $events[] = $decoded;
                $ids[] = (int) $row['id'];
            }
        }
        if (!$events) {
            return;
        }

        $endpoint = "/wordpress/sites/{$site_id}/activity";
        if ($async) {
            $queued = DM_API::post_async($endpoint, ['events' => $events]);
            if ($queued) {
                // The non-blocking HTTP request has no response to confirm, so
                // leave the rows durable but hold them until the scheduled
                // confirmed flush. This prevents a busy site from resending
                // the same oldest batch on every front-end request.
                $id_list = implode(',', array_map('intval', $ids));
                if ($id_list) {
                    $wpdb->query(
                        "UPDATE {$table}
                         SET available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
                         WHERE id IN ({$id_list})"
                    );
                }
            }
            return;
        }

        $result = DM_API::post($endpoint, ['events' => $events]);
        if (is_wp_error($result)) {
            $id_list = implode(',', array_map('intval', $ids));
            if ($id_list) {
                $wpdb->query("UPDATE {$table} SET attempts = attempts + 1, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id IN ({$id_list})");
            }
            self::schedule_flush(300);
            return;
        }

        $id_list = implode(',', array_map('intval', $ids));
        if ($id_list) {
            $wpdb->query("DELETE FROM {$table} WHERE id IN ({$id_list})");
        }
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($remaining > 0) {
            self::schedule_flush(10);
        }
    }

    // ---------------------------------------------------------------------
    // Authentication and users
    // ---------------------------------------------------------------------

    public static function login_succeeded(string $login, WP_User $user): void {
        self::queue_event([
            'category'    => 'authentication',
            'action'      => 'login.succeeded',
            'summary'     => "{$user->display_name} logged in",
            'objectType'  => 'user',
            'objectId'    => (string) $user->ID,
            'objectLabel' => $user->display_name ?: $login,
            'objectUrl'   => get_edit_user_link($user->ID),
        ], $user);
    }

    public static function login_failed(string $username, ?WP_Error $error = null): void {
        $ip = self::client_ip() ?: 'unknown';
        $bucket = (int) floor(time() / 300);
        $key = 'dm_act_fail_' . substr(hash('sha256', strtolower($username) . '|' . $ip . '|' . $bucket), 0, 32);
        $state = get_transient($key);
        if (!is_array($state)) {
            $state = ['count' => 0, 'first' => gmdate('c')];
        }
        $state['count'] = ((int) ($state['count'] ?? 0)) + 1;
        set_transient($key, $state, 10 * MINUTE_IN_SECONDS);
        $count = (int) $state['count'];
        $suffix = $count === 1 ? '' : " ({$count} attempts in 5 minutes)";
        self::queue_event([
            'externalId'      => 'failed:' . substr(hash('sha256', strtolower($username) . '|' . $ip . '|' . $bucket), 0, 40),
            'category'        => 'authentication',
            'action'          => 'login.failed',
            'severity'        => 'warning',
            'actorType'       => 'anonymous',
            'actorLogin'      => sanitize_user($username),
            'summary'         => "Failed login for {$username}{$suffix}",
            'details'         => ['reason' => $error instanceof WP_Error ? $error->get_error_code() : 'invalid_credentials'],
            'occurrenceCount' => $count,
            'occurredAt'      => (string) $state['first'],
            'lastOccurredAt'  => gmdate('c'),
            'ipAddress'       => self::client_ip(),
        ]);
    }

    public static function logout(int $user_id = 0): void {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        $label = $user instanceof WP_User && $user->exists() ? ($user->display_name ?: $user->user_login) : 'A user';
        self::queue_event([
            'category'    => 'authentication',
            'action'      => 'logout',
            'summary'     => "{$label} logged out",
            'objectType'  => 'user',
            'objectId'    => $user instanceof WP_User ? (string) $user->ID : null,
            'objectLabel' => $label,
        ], $user instanceof WP_User ? $user : null);
    }

    public static function password_reset(WP_User $user, string $new_pass): void {
        unset($new_pass);
        self::queue_event([
            'category'    => 'users',
            'action'      => 'user.password_reset',
            'severity'    => 'notice',
            'summary'     => "Password reset for {$user->display_name}",
            'objectType'  => 'user',
            'objectId'    => (string) $user->ID,
            'objectLabel' => $user->display_name ?: $user->user_login,
            'objectUrl'   => get_edit_user_link($user->ID),
        ]);
    }

    public static function user_created(int $user_id, array $userdata = []): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        self::queue_event([
            'category'    => 'users',
            'action'      => 'user.created',
            'summary'     => "Created user {$user->display_name}",
            'objectType'  => 'user',
            'objectId'    => (string) $user_id,
            'objectLabel' => $user->display_name ?: $user->user_login,
            'objectUrl'   => get_edit_user_link($user_id),
            'details'     => ['roles' => array_values((array) $user->roles)],
        ]);
    }

    public static function user_updated(int $user_id, WP_User $old_user, array $userdata = []): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        $changes = [];
        foreach ([
            'display_name' => 'Display name',
            'first_name'   => 'First name',
            'last_name'    => 'Last name',
            'nickname'     => 'Nickname',
            'user_email'   => 'Email',
            'user_url'     => 'Website',
            'description'  => 'Biography',
            'locale'       => 'Locale',
        ] as $field => $label) {
            if ((string) $old_user->{$field} !== (string) $user->{$field}) {
                $changes[] = self::change($label, (string) $old_user->{$field}, (string) $user->{$field});
            }
        }
        if ($changes) {
            self::queue_event([
                'category'    => 'users',
                'action'      => 'user.updated',
                'summary'     => "Updated user {$user->display_name}",
                'objectType'  => 'user',
                'objectId'    => (string) $user_id,
                'objectLabel' => $user->display_name ?: $user->user_login,
                'objectUrl'   => get_edit_user_link($user_id),
                'changes'     => $changes,
            ]);
        }
    }

    public static function user_deleted(int $user_id, ?int $reassign = null, ?WP_User $user = null): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $label = $user instanceof WP_User ? ($user->display_name ?: $user->user_login) : "User #{$user_id}";
        self::queue_event([
            'category'    => 'users',
            'action'      => 'user.deleted',
            'severity'    => 'warning',
            'summary'     => "Deleted user {$label}",
            'objectType'  => 'user',
            'objectId'    => (string) $user_id,
            'objectLabel' => $label,
            'details'     => ['contentReassignedTo' => $reassign],
        ]);
    }

    public static function user_role_changed(int $user_id, string $role, array $old_roles): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $user = get_userdata($user_id);
        $label = $user ? ($user->display_name ?: $user->user_login) : "User #{$user_id}";
        self::queue_event([
            'category'    => 'users',
            'action'      => 'user.role_changed',
            'severity'    => 'notice',
            'summary'     => "Changed {$label}'s role",
            'objectType'  => 'user',
            'objectId'    => (string) $user_id,
            'objectLabel' => $label,
            'objectUrl'   => get_edit_user_link($user_id),
            'changes'     => [self::change('Role', implode(', ', $old_roles), $role)],
        ]);
    }

    // ---------------------------------------------------------------------
    // Content, ACF, and builder metadata
    // ---------------------------------------------------------------------

    public static function post_saved(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void {
        if (!self::should_capture_user_action() || self::skip_post($post)) {
            return;
        }
        $changes = [];
        $before = $post_before;
        foreach ([
            'post_title' => 'Title',
            'post_status' => 'Status',
            'post_name' => 'Slug',
            'post_excerpt' => 'Excerpt',
            'post_author' => 'Author',
            'post_parent' => 'Parent',
            'menu_order' => 'Order',
            'comment_status' => 'Comments',
        ] as $field => $label) {
            $old = $before ? $before->{$field} : null;
            $new = $post->{$field};
            if ((string) $old !== (string) $new) {
                $changes[] = self::change($label, $old, $new);
            }
        }
        $old_content = $before ? (string) $before->post_content : '';
        if ($old_content !== (string) $post->post_content) {
            $changes[] = self::content_change('Content', $old_content, (string) $post->post_content);
        }
        if (!$changes && $update) {
            return;
        }
        $type = get_post_type_object($post->post_type);
        $type_label = $type?->labels?->singular_name ?: ucfirst($post->post_type);
        $action = $update ? 'content.updated' : 'content.created';
        $summary_verb = $update ? 'Updated' : 'Created';
        $severity = 'info';
        if ($update && $before && $before->post_status !== $post->post_status) {
            if ($post->post_status === 'trash') {
                $action = 'content.trashed';
                $summary_verb = 'Moved to trash';
                $severity = 'notice';
            } elseif ($before->post_status === 'trash') {
                $action = 'content.restored';
                $summary_verb = 'Restored';
            } elseif ($post->post_status === 'publish') {
                $action = 'content.published';
                $summary_verb = 'Published';
            }
        }
        self::$pending_posts[$post_id] = [
            'category'    => 'content',
            'action'      => $action,
            'severity'    => $severity,
            'summary'     => $summary_verb . ' ' . strtolower($type_label) . " “{$post->post_title}”",
            'objectType'  => $post->post_type,
            'objectId'    => (string) $post_id,
            'objectLabel' => $post->post_title ?: "{$type_label} #{$post_id}",
            'objectUrl'   => self::post_url($post_id),
            'changes'     => $changes,
        ];
    }

    public static function post_deleted(int $post_id, WP_Post $post): void {
        if (!self::should_capture_user_action() || self::skip_post($post)) {
            return;
        }
        $type = get_post_type_object($post->post_type);
        $type_label = $type?->labels?->singular_name ?: ucfirst($post->post_type);
        self::queue_event([
            'category'    => 'content',
            'action'      => 'content.deleted',
            'severity'    => 'warning',
            'summary'     => 'Permanently deleted ' . strtolower($type_label) . " “{$post->post_title}”",
            'objectType'  => $post->post_type,
            'objectId'    => (string) $post_id,
            'objectLabel' => $post->post_title ?: "{$type_label} #{$post_id}",
        ]);
    }

    private static function skip_post(WP_Post $post): bool {
        if ($post->post_type === 'attachment' || wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return true;
        }
        if (in_array($post->post_status, ['auto-draft', 'inherit'], true)) {
            return true;
        }
        $type = get_post_type_object($post->post_type);
        $editor_types = ['custom_css', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part'];
        return !$type || (!in_array($post->post_type, $editor_types, true) && !$type->show_ui && !$type->public);
    }

    private static function post_url(int $post_id): ?string {
        $url = get_edit_post_link($post_id, 'raw');
        if (!$url) {
            $url = get_permalink($post_id);
        }
        return is_string($url) ? $url : null;
    }

    public static function acf_value_updated(mixed $value, mixed $post_id, array $field, mixed $original): mixed {
        if (!self::should_capture_user_action()) {
            return $value;
        }
        $name = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? $name ?: 'ACF field');
        if (!$name || self::sensitive_name($name)) {
            return $value;
        }
        if (!is_numeric($post_id)) {
            if (in_array((string) $post_id, ['option', 'options'], true)) {
                $old = function_exists('get_field') ? get_field($field['key'] ?? $name, $post_id, false) : null;
                if (maybe_serialize($old) !== maybe_serialize($value)) {
                    self::$pending_options["acf:{$name}"] = self::value_change("ACF: {$label}", $old, $value);
                }
            }
            return $value;
        }
        $post_id = (int) $post_id;
        $old = get_post_meta($post_id, $name, true);
        if (maybe_serialize($old) !== maybe_serialize($value)) {
            self::$pending_meta_changes[$post_id][$name] = self::value_change($label, $old, $value);
        }
        return $value;
    }

    public static function before_meta_add(mixed $check, int $post_id, string $meta_key, mixed $meta_value, bool $unique): mixed {
        self::remember_meta_before($post_id, $meta_key, null);
        return $check;
    }

    public static function before_meta_update(mixed $check, int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value): mixed {
        self::remember_meta_before($post_id, $meta_key, get_post_meta($post_id, $meta_key, true));
        return $check;
    }

    public static function before_meta_delete(mixed $check, int $post_id, string $meta_key, mixed $meta_value, bool $delete_all): mixed {
        self::remember_meta_before($post_id, $meta_key, get_post_meta($post_id, $meta_key, true));
        return $check;
    }

    private static function remember_meta_before(int $post_id, string $meta_key, mixed $value): void {
        if (!self::should_capture_user_action() || !self::tracked_meta_key($meta_key) || isset(self::$pending_meta_before[$post_id][$meta_key])) {
            return;
        }
        self::$pending_meta_before[$post_id][$meta_key] = $value;
    }

    public static function after_meta_change(int $meta_id, int $post_id, string $meta_key, mixed $meta_value): void {
        self::finish_meta_change($post_id, $meta_key, get_post_meta($post_id, $meta_key, true));
    }

    public static function after_meta_delete(array $meta_ids, int $post_id, string $meta_key, mixed $meta_value): void {
        self::finish_meta_change($post_id, $meta_key, null);
    }

    private static function finish_meta_change(int $post_id, string $meta_key, mixed $after): void {
        if (!array_key_exists($meta_key, self::$pending_meta_before[$post_id] ?? [])) {
            return;
        }
        $before = self::$pending_meta_before[$post_id][$meta_key];
        unset(self::$pending_meta_before[$post_id][$meta_key]);
        if (maybe_serialize($before) === maybe_serialize($after)) {
            return;
        }
        self::$pending_meta_changes[$post_id][$meta_key] = self::value_change(self::meta_label($meta_key), $before, $after);
    }

    private static function tracked_meta_key(string $key): bool {
        if (self::sensitive_name($key)) {
            return false;
        }
        return $key === '_thumbnail_id'
            || $key === '_wp_page_template'
            || (bool) preg_match('/^_(bricks|elementor|fusion|fl_builder|et_pb|oxygen|ct_builder)/i', $key);
    }

    private static function meta_label(string $key): string {
        if ($key === '_thumbnail_id') {
            return 'Featured image';
        }
        if ($key === '_wp_page_template') {
            return 'Page template';
        }
        if (preg_match('/^_bricks/i', $key)) {
            return 'Bricks content';
        }
        if (preg_match('/^_fusion/i', $key)) {
            return 'Fusion Builder content';
        }
        if (preg_match('/^_elementor/i', $key)) {
            return 'Elementor content';
        }
        if (preg_match('/^_fl_builder/i', $key)) {
            return 'Beaver Builder content';
        }
        if (preg_match('/^_et_pb/i', $key)) {
            return 'Divi content';
        }
        return trim(ucwords(str_replace(['_', '-'], ' ', $key)));
    }

    private static function queue_pending_content(): void {
        $post_ids = array_unique(array_merge(array_keys(self::$pending_posts), array_keys(self::$pending_meta_changes)));
        foreach ($post_ids as $post_id) {
            $event = self::$pending_posts[$post_id] ?? null;
            $post = get_post((int) $post_id);
            if (!$event && $post instanceof WP_Post && !self::skip_post($post)) {
                $type = get_post_type_object($post->post_type);
                $type_label = $type?->labels?->singular_name ?: ucfirst($post->post_type);
                $event = [
                    'category'    => 'content',
                    'action'      => 'content.updated',
                    'summary'     => 'Updated ' . strtolower($type_label) . " “{$post->post_title}”",
                    'objectType'  => $post->post_type,
                    'objectId'    => (string) $post_id,
                    'objectLabel' => $post->post_title ?: "{$type_label} #{$post_id}",
                    'objectUrl'   => self::post_url((int) $post_id),
                    'changes'     => [],
                ];
            }
            if (!$event) {
                continue;
            }
            $meta_changes = array_values(self::$pending_meta_changes[$post_id] ?? []);
            $event['changes'] = array_slice(array_merge($event['changes'] ?? [], $meta_changes), 0, 50);
            if ($event['changes']) {
                self::queue_event($event);
            }
        }
        self::$pending_posts = [];
        self::$pending_meta_changes = [];

        if (self::$pending_options) {
            $changes = array_values(self::$pending_options);
            self::queue_event([
                'category'    => 'settings',
                'action'      => 'settings.updated',
                'severity'    => 'notice',
                'summary'     => count($changes) === 1 ? 'Updated a WordPress setting' : 'Updated WordPress settings',
                'objectType'  => 'settings',
                'objectLabel' => 'WordPress settings',
                'objectUrl'   => admin_url('options-general.php'),
                'changes'     => array_slice($changes, 0, 50),
            ]);
            self::$pending_options = [];
        }
    }

    // ---------------------------------------------------------------------
    // Media
    // ---------------------------------------------------------------------

    public static function attachment_added(int $attachment_id): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $post = get_post($attachment_id);
        if (!$post) {
            return;
        }
        self::queue_event(array_merge(self::media_context($attachment_id), [
            'category' => 'media',
            'action'   => 'media.uploaded',
            'summary'  => "Uploaded media “{$post->post_title}”",
        ]));
    }

    public static function attachment_updated(int $attachment_id, WP_Post $after, WP_Post $before): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $changes = [];
        foreach (['post_title' => 'Title', 'post_excerpt' => 'Caption', 'post_content' => 'Description', 'post_name' => 'Slug'] as $field => $label) {
            if ((string) $before->{$field} !== (string) $after->{$field}) {
                $changes[] = self::change($label, $before->{$field}, $after->{$field});
            }
        }
        if (!$changes) {
            return;
        }
        self::queue_event(array_merge(self::media_context($attachment_id), [
            'category' => 'media',
            'action'   => 'media.updated',
            'summary'  => "Updated media “{$after->post_title}”",
            'changes'  => $changes,
        ]));
    }

    public static function attachment_deleted(int $attachment_id, ?WP_Post $post = null): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $context = self::media_context($attachment_id);
        $label = $post?->post_title ?: ($context['objectLabel'] ?? "Attachment #{$attachment_id}");
        self::queue_event(array_merge($context, [
            'category' => 'media',
            'action'   => 'media.deleted',
            'severity' => 'warning',
            'summary'  => "Deleted media “{$label}”",
        ]));
    }

    private static function media_context(int $attachment_id): array {
        $post = get_post($attachment_id);
        $file = get_attached_file($attachment_id);
        $url = wp_get_attachment_url($attachment_id);
        $relative = null;
        if (is_string($file) && $file !== '') {
            $uploads = wp_get_upload_dir();
            $base = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
            $normalized = wp_normalize_path($file);
            $relative = $base && str_starts_with($normalized, trailingslashit($base))
                ? ltrim(substr($normalized, strlen($base)), '/')
                : wp_basename($normalized);
        }
        return [
            'objectType'  => 'attachment',
            'objectId'    => (string) $attachment_id,
            'objectLabel' => $post?->post_title ?: ($relative ?: "Attachment #{$attachment_id}"),
            'objectUrl'   => is_string($url) ? $url : null,
            'details'     => [
                'fileName'     => $file ? wp_basename($file) : null,
                'relativePath' => $relative,
                'mimeType'     => get_post_mime_type($attachment_id) ?: null,
                'sizeBytes'    => $file && is_file($file) ? filesize($file) : null,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Taxonomies, comments, and menus
    // ---------------------------------------------------------------------

    public static function term_created(int $term_id, int $tt_id, string $taxonomy, array $args = []): void {
        if (!self::should_capture_user_action() || $taxonomy === 'nav_menu') {
            return;
        }
        self::term_event('taxonomy.created', 'Created', $term_id, $taxonomy);
    }

    public static function term_updated(int $term_id, int $tt_id, string $taxonomy, array $args = []): void {
        if (!self::should_capture_user_action() || $taxonomy === 'nav_menu') {
            return;
        }
        self::term_event('taxonomy.updated', 'Updated', $term_id, $taxonomy);
    }

    public static function term_deleted(int $term_id, int $tt_id, string $taxonomy, ?WP_Term $deleted_term = null, array $object_ids = []): void {
        if (!self::should_capture_user_action() || $taxonomy === 'nav_menu') {
            return;
        }
        $label = $deleted_term?->name ?: "Term #{$term_id}";
        self::queue_event([
            'category'    => 'taxonomy',
            'action'      => 'taxonomy.deleted',
            'severity'    => 'warning',
            'summary'     => "Deleted {$taxonomy} “{$label}”",
            'objectType'  => $taxonomy,
            'objectId'    => (string) $term_id,
            'objectLabel' => $label,
            'details'     => ['affectedObjects' => count($object_ids)],
        ]);
    }

    private static function term_event(string $action, string $verb, int $term_id, string $taxonomy): void {
        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof WP_Term) {
            return;
        }
        $url = get_edit_term_link($term_id, $taxonomy);
        self::queue_event([
            'category'    => 'taxonomy',
            'action'      => $action,
            'summary'     => "{$verb} {$taxonomy} “{$term->name}”",
            'objectType'  => $taxonomy,
            'objectId'    => (string) $term_id,
            'objectLabel' => $term->name,
            'objectUrl'   => is_string($url) ? $url : null,
            'details'     => ['slug' => $term->slug],
        ]);
    }

    public static function comment_created(int $comment_id, WP_Comment $comment): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        self::queue_event(array_merge(self::comment_context($comment), [
            'category' => 'comments',
            'action'   => 'comment.created',
            'summary'  => "Added comment on “" . get_the_title($comment->comment_post_ID) . '”',
        ]));
    }

    public static function comment_updated(int $comment_id, array $data = []): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $comment = get_comment($comment_id);
        if (!$comment instanceof WP_Comment) {
            return;
        }
        self::queue_event(array_merge(self::comment_context($comment), [
            'category' => 'comments',
            'action'   => 'comment.updated',
            'summary'  => "Updated comment on “" . get_the_title($comment->comment_post_ID) . '”',
        ]));
    }

    public static function comment_status_changed(string $new_status, string $old_status, WP_Comment $comment): void {
        if (!self::should_capture_user_action() || $new_status === $old_status) {
            return;
        }
        self::queue_event(array_merge(self::comment_context($comment), [
            'category' => 'comments',
            'action'   => 'comment.status_changed',
            'severity' => $new_status === 'spam' || $new_status === 'trash' ? 'notice' : 'info',
            'summary'  => "Changed comment status on “" . get_the_title($comment->comment_post_ID) . '”',
            'changes'  => [self::change('Status', $old_status, $new_status)],
        ]));
    }

    public static function comment_deleted(int $comment_id, ?WP_Comment $comment = null): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $comment = $comment ?: get_comment($comment_id);
        if (!$comment instanceof WP_Comment) {
            return;
        }
        self::queue_event(array_merge(self::comment_context($comment), [
            'category' => 'comments',
            'action'   => 'comment.deleted',
            'severity' => 'warning',
            'summary'  => "Deleted comment on “" . get_the_title($comment->comment_post_ID) . '”',
            'objectUrl' => null,
        ]));
    }

    private static function comment_context(WP_Comment $comment): array {
        return [
            'objectType'  => 'comment',
            'objectId'    => (string) $comment->comment_ID,
            'objectLabel' => 'Comment by ' . ($comment->comment_author ?: 'Anonymous'),
            'objectUrl'   => admin_url("comment.php?action=editcomment&c={$comment->comment_ID}"),
            'details'     => [
                'author'  => $comment->comment_author,
                'excerpt' => self::clip(self::visible_text((string) $comment->comment_content), 300),
                'postId'  => (int) $comment->comment_post_ID,
            ],
        ];
    }

    public static function menu_updated(int $menu_id, array $menu_data = []): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $menu = wp_get_nav_menu_object($menu_id);
        $label = $menu instanceof WP_Term ? $menu->name : "Menu #{$menu_id}";
        self::queue_event([
            'category'    => 'menus',
            'action'      => 'menu.updated',
            'summary'     => "Updated navigation menu “{$label}”",
            'objectType'  => 'nav_menu',
            'objectId'    => (string) $menu_id,
            'objectLabel' => $label,
            'objectUrl'   => admin_url("nav-menus.php?action=edit&menu={$menu_id}"),
        ]);
    }

    public static function menu_deleted(WP_Term|int $menu): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        $menu_id = $menu instanceof WP_Term ? $menu->term_id : (int) $menu;
        $label = $menu instanceof WP_Term ? $menu->name : "Menu #{$menu_id}";
        self::queue_event([
            'category'    => 'menus',
            'action'      => 'menu.deleted',
            'severity'    => 'warning',
            'summary'     => "Deleted navigation menu “{$label}”",
            'objectType'  => 'nav_menu',
            'objectId'    => (string) $menu_id,
            'objectLabel' => $label,
        ]);
    }

    public static function widget_updated(mixed $instance, array $new_instance, array $old_instance, WP_Widget $widget): mixed {
        if ($instance === false || !self::should_capture_user_action()) {
            return $instance;
        }
        $saved_instance = is_array($instance) ? $instance : $new_instance;
        if (maybe_serialize($old_instance) === maybe_serialize($saved_instance)) {
            return $instance;
        }
        $label = $widget->name ?: $widget->id_base;
        self::queue_event([
            'category'    => 'settings',
            'action'      => 'widget.updated',
            'summary'     => "Updated widget “{$label}”",
            'objectType'  => 'widget',
            'objectId'    => $widget->id,
            'objectLabel' => $label,
            'objectUrl'   => admin_url('widgets.php'),
            'changes'     => [self::value_change('Widget settings', $old_instance, $saved_instance)],
        ]);
        return $instance;
    }

    // ---------------------------------------------------------------------
    // Software and settings
    // ---------------------------------------------------------------------

    public static function plugin_activated(string $plugin, bool $network_wide): void {
        self::software_event('plugin.activated', 'Activated', 'plugin', $plugin, ['networkWide' => $network_wide]);
    }

    public static function plugin_deactivated(string $plugin, bool $network_deactivating): void {
        self::software_event('plugin.deactivated', 'Deactivated', 'plugin', $plugin, ['networkWide' => $network_deactivating]);
    }

    public static function plugin_deleted(string $plugin, bool $deleted): void {
        if ($deleted) {
            self::software_event('plugin.deleted', 'Deleted', 'plugin', $plugin, [], 'warning');
        }
    }

    public static function theme_switched(string $new_name, WP_Theme $new_theme, WP_Theme $old_theme): void {
        if (!self::should_capture_user_action()) {
            return;
        }
        self::queue_event([
            'category'    => 'software',
            'action'      => 'theme.switched',
            'severity'    => 'notice',
            'summary'     => "Switched theme from {$old_theme->get('Name')} to {$new_name}",
            'objectType'  => 'theme',
            'objectId'    => $new_theme->get_stylesheet(),
            'objectLabel' => $new_name,
            'objectUrl'   => admin_url('themes.php'),
            'changes'     => [self::change('Active theme', $old_theme->get('Name'), $new_name)],
        ]);
    }

    public static function theme_deleted(string $stylesheet, bool $deleted): void {
        if ($deleted) {
            self::software_event('theme.deleted', 'Deleted', 'theme', $stylesheet, [], 'warning');
        }
    }

    public static function upgrade_completed(WP_Upgrader $upgrader, array $options): void {
        $type = (string) ($options['type'] ?? '');
        $action = (string) ($options['action'] ?? 'update');
        if (!in_array($type, ['plugin', 'theme', 'core'], true) || !in_array($action, ['install', 'update'], true)) {
            return;
        }
        $items = $type === 'plugin'
            ? (array) ($options['plugins'] ?? (isset($options['plugin']) ? [$options['plugin']] : []))
            : ($type === 'theme'
                ? (array) ($options['themes'] ?? (isset($options['theme']) ? [$options['theme']] : []))
                : ['wordpress']);
        foreach ($items as $item) {
            $verb = $action === 'install' ? 'Installed' : 'Updated';
            $event_action = $action === 'install' ? 'installed' : 'updated';
            self::software_event(
                "{$type}.{$event_action}",
                $verb,
                $type,
                (string) $item,
                [
                    'automatic' => !empty($options['automatic']),
                    'bulk'      => !empty($options['bulk']),
                ],
                'info',
                true
            );
        }
        if (defined('DM_UPDATE_SYNC_HOOK') && !wp_next_scheduled(DM_UPDATE_SYNC_HOOK)) {
            wp_schedule_single_event(time() + 60, DM_UPDATE_SYNC_HOOK);
        }
    }

    private static function software_event(
        string $action,
        string $verb,
        string $type,
        string $slug,
        array $details = [],
        string $severity = 'info',
        bool $allow_system = false
    ): void {
        $user_action = self::should_capture_user_action();
        // Upgrader events can legitimately run without a logged-in user:
        // WordPress auto-updates, host update systems, WP-CLI, and Destiny
        // Manage REST check-ins all use that path. Keep the stricter gate for
        // activation/deactivation events so unrelated background hooks do not
        // create misleading records.
        if (!$user_action && !$allow_system && !wp_doing_cron()) {
            return;
        }
        $label = self::software_label($type, $slug);
        self::queue_event([
            'category'    => 'software',
            'action'      => $action,
            'severity'    => $severity,
            'actorType'   => $user_action ? 'user' : 'system',
            'summary'     => "{$verb} {$type} {$label}",
            'objectType'  => $type,
            'objectId'    => $slug,
            'objectLabel' => $label,
            'objectUrl'   => $type === 'theme'
                ? admin_url('themes.php')
                : ($type === 'core' ? admin_url('update-core.php') : admin_url('plugins.php')),
            'details'     => $details,
        ]);
    }

    private static function software_label(string $type, string $slug): string {
        if ($type === 'plugin' && file_exists(WP_PLUGIN_DIR . '/' . $slug)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $slug, false, false);
            return (string) ($data['Name'] ?? $slug);
        }
        if ($type === 'theme') {
            $theme = wp_get_theme($slug);
            return $theme->exists() ? $theme->get('Name') : $slug;
        }
        return $type === 'core' ? 'WordPress core' : $slug;
    }

    public static function option_updated(string $option, mixed $old_value, mixed $value): void {
        if (!self::should_capture_user_action() || !self::tracked_option($option)) {
            return;
        }
        if (maybe_serialize($old_value) === maybe_serialize($value)) {
            return;
        }
        $label = ucwords(str_replace('_', ' ', $option));
        self::$pending_options[$option] = self::value_change($label, $old_value, $value);
    }

    private static function tracked_options(): array {
        return [
            'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
            'users_can_register', 'default_role', 'timezone_string', 'date_format',
            'time_format', 'start_of_week', 'show_on_front', 'page_on_front',
            'page_for_posts', 'permalink_structure', 'comment_registration',
            'default_comment_status', 'thumbnail_size_w', 'thumbnail_size_h',
            'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
            'sidebars_widgets',
        ];
    }

    private static function tracked_option(string $option): bool {
        return in_array($option, self::tracked_options(), true)
            || str_starts_with($option, 'theme_mods_');
    }

    // ---------------------------------------------------------------------
    // Compact, bounded change values
    // ---------------------------------------------------------------------

    private static function change(string $field, mixed $before, mixed $after): array {
        return [
            'field'  => $field,
            'before' => self::scalar_text($before),
            'after'  => self::scalar_text($after),
        ];
    }

    private static function value_change(string $field, mixed $before, mixed $after): array {
        // Redact nested ACF/builder keys before flattening structured values to
        // text; once serialized, a password-like sub-field name would otherwise
        // no longer be available to the final recursive redactor.
        $before_text = self::value_text(self::redact(self::structured_value($before)));
        $after_text = self::value_text(self::redact(self::structured_value($after)));
        [$before_excerpt, $after_excerpt] = self::changed_excerpt($before_text, $after_text);
        return [
            'field'        => $field,
            'before'       => $before_excerpt,
            'after'        => $after_excerpt,
            'beforeLength' => self::length($before_text),
            'afterLength'  => self::length($after_text),
        ];
    }

    private static function content_change(string $field, string $before, string $after): array {
        $before_text = self::visible_text($before);
        $after_text = self::visible_text($after);
        [$before_excerpt, $after_excerpt] = self::changed_excerpt($before_text, $after_text);
        return [
            'field'        => $field,
            'before'       => $before_excerpt,
            'after'        => $after_excerpt,
            'beforeLength' => self::length($before_text),
            'afterLength'  => self::length($after_text),
        ];
    }

    private static function visible_text(string $value): string {
        $value = preg_replace('/<!--\s*\/?wp:.*?-->/s', ' ', $value) ?? $value;
        $value = strip_shortcodes($value);
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function changed_excerpt(string $before, string $after): array {
        if ($before === $after) {
            return [self::clip($before, 500), self::clip($after, 500)];
        }
        $before_len = self::length($before);
        $after_len = self::length($after);
        $prefix = 0;
        $max_prefix = min($before_len, $after_len);
        while ($prefix < $max_prefix && self::substring($before, $prefix, 1) === self::substring($after, $prefix, 1)) {
            $prefix++;
        }
        $suffix = 0;
        while (
            $suffix < ($before_len - $prefix)
            && $suffix < ($after_len - $prefix)
            && self::substring($before, $before_len - $suffix - 1, 1) === self::substring($after, $after_len - $suffix - 1, 1)
        ) {
            $suffix++;
        }
        $start = max(0, $prefix - 120);
        $before_size = min($before_len - $start, ($before_len - $prefix - $suffix) + 240);
        $after_size = min($after_len - $start, ($after_len - $prefix - $suffix) + 240);
        $before_excerpt = self::substring($before, $start, max(0, $before_size));
        $after_excerpt = self::substring($after, $start, max(0, $after_size));
        if ($start > 0) {
            $before_excerpt = '…' . $before_excerpt;
            $after_excerpt = '…' . $after_excerpt;
        }
        if ($start + $before_size < $before_len) {
            $before_excerpt .= '…';
        }
        if ($start + $after_size < $after_len) {
            $after_excerpt .= '…';
        }
        return [self::clip($before_excerpt, 700), self::clip($after_excerpt, 700)];
    }

    private static function scalar_text(mixed $value): string|int|float|bool|null {
        if ($value === null || is_scalar($value)) {
            return is_string($value) ? self::clip($value, 700) : $value;
        }
        return self::clip(self::value_text($value), 700);
    }

    private static function value_text(mixed $value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Enabled' : 'Disabled';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded ? self::visible_text($encoded) : '[structured value]';
    }

    private static function structured_value(mixed $value): mixed {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $unserialized = maybe_unserialize($value);
        if ($unserialized !== $value) {
            return $unserialized;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }

    private static function sensitive_name(string $name): bool {
        return (bool) preg_match('/(?:^|[_-])(pass(?:word)?|secret|token|api[_-]?key|auth|cookie|nonce|private[_-]?key|license[_-]?key)(?:$|[_-])/i', $name);
    }

    private static function redact(mixed $value, string $key = '', int $depth = 0): mixed {
        if (self::sensitive_name($key)) {
            return '[redacted]';
        }
        if ($depth > 5 || !is_array($value)) {
            return is_string($value) ? self::clip($value, 2000) : $value;
        }
        $result = [];
        foreach (array_slice($value, 0, 100, true) as $child_key => $child_value) {
            $result[$child_key] = self::redact($child_value, (string) $child_key, $depth + 1);
        }
        return $result;
    }

    private static function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function substring(string $value, int $start, int $length): string {
        return function_exists('mb_substr')
            ? mb_substr($value, $start, $length, 'UTF-8')
            : substr($value, $start, $length);
    }

    private static function clip(string $value, int $limit): string {
        if (self::length($value) <= $limit) {
            return $value;
        }
        return rtrim(self::substring($value, 0, max(0, $limit - 1))) . '…';
    }
}
