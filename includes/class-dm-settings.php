<?php
defined('ABSPATH') || exit;

/**
 * Admin settings page and option registration.
 */
class DM_Settings {

    public static function init(): void {}

    public static function add_menu(): void {
        add_options_page(
            __('Destiny Manage', 'destiny-manage'),
            __('Destiny Manage', 'destiny-manage'),
            'manage_options',
            'destiny-manage',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('dm_settings_group', 'dm_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('dm_settings_group', 'dm_site_name', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public static function admin_notice(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id === 'settings_page_destiny-manage') {
            return;
        }
        $api_key = get_option('dm_api_key', '');
        if (!empty($api_key)) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . sprintf(
                /* translators: %s: settings page URL */
                __('<strong>Destiny Manage</strong>: Enter your API key on the <a href="%s">settings page</a> to start syncing.', 'destiny-manage'),
                esc_url(admin_url('options-general.php?page=destiny-manage'))
            )
            . '</p></div>';
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key   = get_option('dm_api_key', '');
        $site_name = get_option('dm_site_name', get_bloginfo('name'));
        $site_id   = get_option('dm_site_id', '');
        $last_push = get_option('dm_last_push', '');
        $last_error = get_option('dm_last_error', '');

        // Handle manual "Sync now" action
        if (
            isset($_POST['dm_sync_now'], $_POST['dm_sync_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dm_sync_nonce'])), 'dm_sync_now')
            && current_user_can('manage_options')
        ) {
            $result = DM_Collector::push();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__('Data synced successfully.', 'destiny-manage') . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Destiny Manage', 'destiny-manage'); ?></h1>
            <p><?php esc_html_e('Connect this WordPress site to your Destiny Manage account for monitoring, plugin tracking, and client management.', 'destiny-manage'); ?></p>

            <?php if ($site_id): ?>
            <div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 14px;border-radius:6px;margin-bottom:16px;">
                <strong><?php esc_html_e('Connected', 'destiny-manage'); ?></strong>
                &nbsp;–&nbsp;<?php esc_html_e('Site ID:', 'destiny-manage'); ?> <code><?php echo esc_html($site_id); ?></code>
                <?php if ($last_push): ?>
                &nbsp;·&nbsp;<?php echo esc_html(sprintf(__('Last sync: %s', 'destiny-manage'), $last_push)); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($last_error): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;padding:10px 14px;border-radius:6px;margin-bottom:16px;">
                <strong><?php esc_html_e('Last error:', 'destiny-manage'); ?></strong> <?php echo esc_html($last_error); ?>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('dm_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dm_api_key"><?php esc_html_e('API Key', 'destiny-manage'); ?></label></th>
                        <td>
                            <input
                                type="password"
                                id="dm_api_key"
                                name="dm_api_key"
                                value="<?php echo esc_attr($api_key); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="dm_••••••••••••••••••••••••••••••••••••••••"
                            />
                            <p class="description">
                                <?php
                                echo wp_kses(
                                    sprintf(
                                        /* translators: %s: Destiny Manage dashboard URL */
                                        __('Generate a key in your <a href="%s" target="_blank" rel="noopener">Destiny Manage dashboard → API Keys</a>.', 'destiny-manage'),
                                        'https://www.destinymanage.com/account/api-keys'
                                    ),
                                    ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dm_site_name"><?php esc_html_e('Site display name', 'destiny-manage'); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="dm_site_name"
                                name="dm_site_name"
                                value="<?php echo esc_attr($site_name); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php esc_html_e('How this site appears in your Destiny Manage dashboard.', 'destiny-manage'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'destiny-manage')); ?>
            </form>

            <?php if ($api_key): ?>
            <hr />
            <h2><?php esc_html_e('Manual sync', 'destiny-manage'); ?></h2>
            <p><?php esc_html_e('Data syncs automatically every hour. Use this to push immediately.', 'destiny-manage'); ?></p>
            <form method="post">
                <?php wp_nonce_field('dm_sync_now', 'dm_sync_nonce'); ?>
                <input type="hidden" name="dm_sync_now" value="1" />
                <?php submit_button(__('Sync now', 'destiny-manage'), 'secondary'); ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
