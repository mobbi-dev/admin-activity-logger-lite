<?php
/**
 * Plugin Name: Admin Activity Logger Lite
 * Description: Logs administrator logins, post edits, and deletions. Displays them in the admin panel. Includes automatic cleanup.
 * Version: 1.0
 * Author: Mobbi
 * Author URI: https://mobbi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */


defined('ABSPATH') || exit;

register_activation_hook(__FILE__, 'aal_create_log_table');

/**
 * Creates database table for logging admin activities
 */
function aal_create_log_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aal_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_login VARCHAR(60) NOT NULL,
        action TEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Checks if the current user is logged in and has administrative capabilities
 * (can edit posts), ensuring logging only occurs for admin actions
 *
 * @return bool True if the user is an admin, false otherwise
 */
function aal_is_admin_user()
{
    return is_user_logged_in() && current_user_can('edit_posts');
}

/**
 * Logs an activity to the database
 *
 * @param string $action The description of the action performed
 */
function aal_log_activity($action)
{
    global $wpdb;

    // Only log if the current user is an admin
    if (!aal_is_admin_user()) {
        return;
    }

    global $wpdb;
    $user = wp_get_current_user();

    // Insert the log entry into the database
    $wpdb->insert(
        $wpdb->prefix . 'aal_logs',
        [
            'user_login' => sanitize_text_field($user->user_login),
            'action'     => sanitize_text_field($action),
        ],
        ['%s', '%s']
    );
    wp_cache_delete('aal_logs_dashboard_widget');
}

// Hook into post save to log when post created
add_action('save_post', function ($post_ID, $post, $update) {
    // Only log if it's a new post and not a revision or autosave
    if (!$update && $post->post_status !== 'auto-draft' && !wp_is_post_revision($post_ID) && !wp_is_post_autosave($post_ID)) {
        $title = get_the_title($post_ID);
        aal_log_activity("created new post: \"" . esc_html($title) . "\"");
    }
}, 10, 3);

// Hook into post updates to log post modifications
add_action('post_updated', function ($post_ID, $post_after, $post_before) {
    if (
        $post_after->post_status === 'publish' && $post_before->ID === $post_after->ID &&
        ($post_after->post_content !== $post_before->post_content || $post_after->post_title !== $post_before->post_title)
    ) {
        $title = get_the_title($post_ID);
        aal_log_activity("edited post: \"" . esc_html($title) . "\"");
    }
}, 10, 3);

// Hook into post moved to trash
add_action('wp_trash_post', function ($post_ID) {
    $title = get_the_title($post_ID);
    aal_log_activity("moved post to trash: \"" . esc_html($title) . "\"");
});

// Hook into post deletion to log post removals
add_action('before_delete_post', function ($postid) {
    // permanent deletion
    if (get_post_status($postid) === 'trash') {
        $title = get_the_title($postid);
        aal_log_activity("permanently deleted post: \"" . esc_html($title) . "\"");
    }
});

// Hook into media deletion to log when an attachment is deleted
add_action('delete_attachment', function ($post_ID) {
    $title = get_the_title($post_ID);
    aal_log_activity("deleted media: \"" . esc_html($title) . "\"");
});

// Hook into user deletion to log when a user is deleted
add_action('delete_user', function ($user_id) {
    $user_data = get_userdata($user_id);
    if ($user_data) {
        aal_log_activity("deleted user: \"" . esc_html($user_data->user_login) . "\"");
    }
}, 10, 2);

// Hook into user role changes to log the modification
add_action('set_user_role', function ($user_id, $role, $old_roles) {
    $user_data = get_userdata($user_id);
    if ($user_data) {
        $old_role_name = !empty($old_roles) ? implode(', ', array_map('esc_html', $old_roles)) : 'none';
        $new_role_name = esc_html($role);
        aal_log_activity("changed role of user \"" . esc_html($user_data->user_login) . "\" from [{$old_role_name}] to [{$new_role_name}]");
    }
}, 10, 3);

// Add the dashboard widget for displaying activity logs
add_action('wp_dashboard_setup', function () {
    // Only users with 'manage_options' capability can see the widget
    if (current_user_can('manage_options')) {
        wp_add_dashboard_widget(
            'aal_widget', // Slug
            'Admin Activity Logs', // Title
            'aal_dashboard_widget_display' // Callback
        );
    }
});

/**
 * Displays the Admin Activity Logger widget content on the dashboard
 */
function aal_dashboard_widget_display()
{
    global $wpdb;
    $cache_key = 'aal_logs_dashboard_widget';

    if (!current_user_can('manage_options')) {
        echo '<p>You do not have sufficient permissions to view this content.</p>';
        return;
    }

    $logs = wp_cache_get($cache_key);

    if (false === $logs) {
        $logs = $wpdb->get_results("SELECT time, user_login, action FROM " . $wpdb->prefix . "aal_logs ORDER BY time DESC LIMIT 20");
        wp_cache_set($cache_key, $logs, '', 300);
    }

    // Check for cleanup request
    if (isset($_POST['aal_clear_logs']) && check_admin_referer('aal_clear_logs_action', 'aal_clear_logs_nonce')) {
        $wpdb->query("DELETE FROM " . $wpdb->prefix . "aal_logs"); // Delete
        wp_cache_delete($cache_key);
        echo '<div class="notice notice-success is-dismissible"><p>Logs cleared successfully.</p></div>';
    }

    // Cleanup button
    echo '<form method="post">';
    wp_nonce_field('aal_clear_logs_action', 'aal_clear_logs_nonce');
    submit_button('Clear Logs', 'delete', 'aal_clear_logs', false, [
        'onclick' => "return confirm('Are you sure you want to clear all logs?');"
    ]);
    echo '</form>';

    // Fetch the latest X log entries, ordered by time descending
    $log_limit = get_option('aal_log_items_count', 20);
    $log_limit = absint($log_limit);

    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT time, user_login, action FROM " . $wpdb->prefix . "aal_logs ORDER BY time DESC LIMIT %d",
            $log_limit
        )
    );

    if (!$logs) {
        echo "<p>No activities logged yet.</p>";
        return;
    }

    echo '<ul>';
    foreach ($logs as $log) {
        $time = esc_html(gmdate('d.m.Y H:i', strtotime($log->time)));
        $user = esc_html($log->user_login);
        $action = esc_html($log->action);
        echo wp_kses_post("<li><strong>[$time]</strong> <code>$user</code> $action</li>");
    }
    echo '</ul>';
}

// Settings page
add_action('admin_menu', function () {
    add_options_page(
        'AAL Logger Settings',
        'AAL Logger',
        'manage_options',      // Required capability
        'aal-logger-settings', // Slug
        'aal_settings_page_display' // Callback
    );
});

// Register settings
add_action('admin_init', function () {
    // Register settings
    register_setting('aal_logger_settings_group', 'aal_log_retention_days', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 0,
    ]);
    register_setting('aal_logger_settings_group', 'aal_log_items_count', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 20,
    ]);

    // Settings section (same section for both fields)
    add_settings_section('aal_main_section', '', null, 'aal-logger-settings');

    // Field: automatic log deletion days
    add_settings_field(
        'aal_log_retention_days',
        'Automatically delete logs older than (days)',
        'aal_retention_field_callback',
        'aal-logger-settings',
        'aal_main_section'
    );

    // Field: number of log items count dropdown
    add_settings_field(
        'aal_log_items_count',
        'Number of log items',
        'aal_log_items_count_field_callback',
        'aal-logger-settings',
        'aal_main_section'
    );
});

/**
 * Renders the input field for log retention days on the settings page
 */
function aal_retention_field_callback()
{
    $value = get_option('aal_log_retention_days', 0);
    echo "<input type='number' name='aal_log_retention_days' value='" . esc_attr($value) . "' min='0' />";
    echo "<p class='description'>0 = No automatic deletion</p>";
}

/**
 * Renders a dropdown select field for log items count
 */
function aal_log_items_count_field_callback()
{
    $value = get_option('aal_log_items_count', 20);
    echo '<select name="aal_log_items_count">';
    $options = [20, 30, 50];
    foreach ($options as $option) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($option),
            selected($value, $option, false),
            esc_html($option)
        );
    }
    echo '</select>';
}

/**
 * Displays the content of the AAL Logger settings page
 */
function aal_settings_page_display()
{
?>
    <div class="wrap">
        <h1>AAL Logger Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('aal_logger_settings_group');
            do_settings_sections('aal-logger-settings');
            submit_button();
            ?>
        </form>
    </div>
<?php
}

add_action('aal_daily_cleanup_hook', 'aal_cleanup_old_logs');

/**
 * Cleans up old log entries based on the retention setting
 */
function aal_cleanup_old_logs()
{
    $days = get_option('aal_log_retention_days', 0);
    if ($days <= 0) return; // If retention is 0 or less, do nothing

    global $wpdb;
    $threshold = gmdate('Y-m-d H:i:s', strtotime("-$days days")); // Calculate the threshold date

    $wpdb->query($wpdb->prepare("DELETE FROM " . $wpdb->prefix . "aal_logs WHERE time < %s", $threshold));
    wp_cache_delete('aal_logs_dashboard_widget');
}

// Schedule the daily cleanup event if it's not already scheduled
if (!wp_next_scheduled('aal_daily_cleanup_hook')) {
    wp_schedule_event(time(), 'daily', 'aal_daily_cleanup_hook');
}

// Clear the scheduled cron event when the plugin is deactivated
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('aal_daily_cleanup_hook');
});