<?php
/**
 * Plugin Name:       Email List Cleaner for AcyMailing
 * Description:       Bulk remove email addresses from AcyMailing subscriber lists. This is an unofficial plugin and is not endorsed by, affiliated with, or supported by AcyMailing or its developers.
 * Version:           1.0.0
 * Author:            Oddly Digital
 * Author URI:        https://oddly.digital/
 * License:           GPL-2.0-or-later
 * Text Domain:       email-list-cleaner-for-acymailing
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Tested up to:      6.9.4
 */

defined( 'ABSPATH' ) || exit;

define( 'ACYM_BC_VERSION',   '1.0.0' );
define( 'ACYM_BC_LOG_TABLE', 'acym_bc_log' );

// ---------------------------------------------------------------------------
// 1. Activation – create audit log table and set default options
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, 'acym_bc_activate' );

function acym_bc_activate(): void {
    global $wpdb;

    $table           = $wpdb->prefix . ACYM_BC_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      BIGINT(20) UNSIGNED NOT NULL,
        user_login   VARCHAR(60)  NOT NULL,
        emails_json  LONGTEXT     NOT NULL,
        removed_json LONGTEXT     NOT NULL,
        rows_list    INT UNSIGNED NOT NULL DEFAULT 0,
        rows_stat    INT UNSIGNED NOT NULL DEFAULT 0,
        rows_user    INT UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME     NOT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    add_option( 'acym_bc_db_version',      ACYM_BC_VERSION );
    add_option( 'acym_bc_logging_enabled', '1' );
    add_option( 'acym_bc_log_retention',   '0' );

    acym_bc_schedule_prune();
}

// ---------------------------------------------------------------------------
// 2. DB upgrade path (retained for anyone migrating from a pre-release build)
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'acym_bc_maybe_upgrade' );

function acym_bc_maybe_upgrade(): void {
    if ( get_option( 'acym_bc_db_version' ) === ACYM_BC_VERSION ) {
        return;
    }
    global $wpdb;
    $table   = $wpdb->prefix . ACYM_BC_LOG_TABLE;
    $columns = $wpdb->get_col( "DESC {$table}", 0 ); // phpcs:ignore
    if ( ! in_array( 'removed_json', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN removed_json LONGTEXT NOT NULL DEFAULT '[]' AFTER emails_json" ); // phpcs:ignore
    }
    update_option( 'acym_bc_db_version', ACYM_BC_VERSION );
}

// ---------------------------------------------------------------------------
// 3. Admin menu
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function (): void {
    add_management_page(
        __( 'Email List Cleaner for AcyMailing', 'email-list-cleaner-for-acymailing' ),
        __( 'Email List Cleaner for AcyMailing', 'email-list-cleaner-for-acymailing' ),
        'manage_options',
        'email-list-cleaner-for-acymailing',
        'acym_bc_render_page'
    );
} );

// ---------------------------------------------------------------------------
// 4. Settings link on the Plugins page – links directly to Settings tab
// ---------------------------------------------------------------------------
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'acym_bc_plugin_action_links' );

function acym_bc_plugin_action_links( array $links ): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'tools.php?page=email-list-cleaner-for-acymailing&tab=settings' ) ),
        esc_html__( 'Settings', 'email-list-cleaner-for-acymailing' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}

// ---------------------------------------------------------------------------
// 5. Inline CSS for the two-column layout
// ---------------------------------------------------------------------------
add_action( 'admin_head', 'acym_bc_admin_styles' );

function acym_bc_admin_styles(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'tools_page_email-list-cleaner-for-acymailing' ) {
        return;
    }
    ?>
    <style>
        .acym-bc-layout {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            margin-top: 12px;
        }
        .acym-bc-main {
            flex: 0 0 70%;
            min-width: 0;
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 20px 24px;
            box-sizing: border-box;
        }
        .acym-bc-sidebar {
            flex: 0 0 calc(30% - 24px);
            min-width: 0;
            box-sizing: border-box;
        }
        .acym-bc-help {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-left: 4px solid #72aee6;
            padding: 14px 16px;
        }
        .acym-bc-help p,
        .acym-bc-help ul {
            margin-top: 0;
            margin-bottom: 8px;
        }
        .acym-bc-help ul {
            margin-left: 16px;
            list-style: disc;
        }
        .acym-bc-help p:last-child,
        .acym-bc-help ul:last-child {
            margin-bottom: 0;
        }
        @media ( max-width: 782px ) {
            .acym-bc-layout {
                flex-direction: column;
            }
            .acym-bc-main,
            .acym-bc-sidebar {
                flex: 1 1 100%;
                width: 100%;
            }
        }
    </style>
    <?php
}

// ---------------------------------------------------------------------------
// 6. Scheduled log pruning
// ---------------------------------------------------------------------------
add_action( 'acym_bc_prune_logs', 'acym_bc_do_prune_logs' );

function acym_bc_do_prune_logs(): void {
    $retention = (int) get_option( 'acym_bc_log_retention', 0 );
    if ( $retention <= 0 ) {
        return;
    }
    global $wpdb;
    $table     = $wpdb->prefix . ACYM_BC_LOG_TABLE;
    $threshold = gmdate( 'Y-m-d H:i:s', time() - $retention );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) ); // phpcs:ignore
}

register_deactivation_hook( __FILE__, 'acym_bc_unschedule_prune' );

function acym_bc_schedule_prune(): void {
    if ( ! wp_next_scheduled( 'acym_bc_prune_logs' ) ) {
        wp_schedule_event( time(), 'daily', 'acym_bc_prune_logs' );
    }
}

function acym_bc_unschedule_prune(): void {
    $timestamp = wp_next_scheduled( 'acym_bc_prune_logs' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'acym_bc_prune_logs' );
    }
}

// ---------------------------------------------------------------------------
// 7. Page callback – tab routing
// ---------------------------------------------------------------------------
function acym_bc_render_page(): void {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'email-list-cleaner-for-acymailing' ) );
    }

    $allowed_tabs = [ 'cleaner', 'log', 'settings' ];
    $tab          = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $allowed_tabs, true )
                    ? sanitize_key( $_GET['tab'] )
                    : 'cleaner';

    $page_url = menu_page_url( 'email-list-cleaner-for-acymailing', false );

    // Handle settings save.
    $settings_notice = null;
    if ( $tab === 'settings' && isset( $_POST['acym_bc_save_settings'] ) ) {
        check_admin_referer( 'acym_bc_settings_action', 'acym_bc_settings_nonce' );
        update_option( 'acym_bc_logging_enabled', isset( $_POST['acym_bc_logging_enabled'] ) ? '1' : '0' );
        update_option( 'acym_bc_log_retention',   (int) ( $_POST['acym_bc_log_retention'] ?? 0 ) );
        $settings_notice = 'saved';
    }

    // Handle log deletion.
    $log_notice = null;
    if ( $tab === 'log' && isset( $_POST['acym_bc_delete_logs'] ) ) {
        check_admin_referer( 'acym_bc_log_action', 'acym_bc_log_nonce' );
        global $wpdb;
        $table = $wpdb->prefix . ACYM_BC_LOG_TABLE;
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
        $log_notice = 'deleted';
    }

    // Handle cleaner submission.
    $result   = null;
    $textarea = '';
    if ( $tab === 'cleaner' && isset( $_POST['acym_bc_submit'] ) ) {
        check_admin_referer( 'acym_bc_action', 'acym_bc_nonce' );
        $raw      = sanitize_textarea_field( wp_unslash( $_POST['acym_bc_emails'] ?? '' ) );
        $textarea = $raw;
        $result   = acym_bc_handle_submission( $raw );
        if ( $result['success'] ) {
            $textarea = '';
        }
    }
    ?>
    <div class="wrap">

        <h1><?php esc_html_e( 'Email List Cleaner for AcyMailing', 'email-list-cleaner-for-acymailing' ); ?></h1>

        <div class="notice notice-warning inline" style="padding:10px 12px;margin:8px 0 0;">
            <strong><?php esc_html_e( 'Email List Cleaner for AcyMailing is not affiliated with AcyMailing:', 'email-list-cleaner-for-acymailing' ); ?></strong>
            <?php esc_html_e(
                'This plugin is an unofficial, independent tool and is not endorsed by, affiliated with, or supported by AcyMailing or its developers.',
                'email-list-cleaner-for-acymailing'
            ); ?>
        </div>

        <nav class="nav-tab-wrapper" style="margin-top:12px;margin-bottom:0;">
            <?php
            $tabs = [
                'cleaner'  => __( 'Remove Addresses', 'email-list-cleaner-for-acymailing' ),
                'log'      => __( 'Audit Log',        'email-list-cleaner-for-acymailing' ),
                'settings' => __( 'Settings',         'email-list-cleaner-for-acymailing' ),
            ];
            foreach ( $tabs as $slug => $label ) :
                $url = add_query_arg( 'tab', $slug, $page_url );
            ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="acym-bc-layout">

            <div class="acym-bc-main">
                <?php
                if ( $tab === 'cleaner' )  acym_bc_render_cleaner_tab( $result, $textarea );
                if ( $tab === 'log' )      acym_bc_render_log_tab( $log_notice );
                if ( $tab === 'settings' ) acym_bc_render_settings_tab( $settings_notice );
                ?>
            </div>

            <div class="acym-bc-sidebar">
                <?php acym_bc_render_help_box(); ?>
            </div>

        </div>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 8. Help and Resources sidebar box
// ---------------------------------------------------------------------------
function acym_bc_render_help_box(): void {
    ?>
    <div class="acym-bc-help">
        <p><strong><?php esc_html_e( 'Help and Resources', 'email-list-cleaner-for-acymailing' ); ?></strong></p>
        <p>
            <?php
            printf(
                /* translators: %s: link to oddly.digital */
                esc_html__( 'Plugin by %s.', 'email-list-cleaner-for-acymailing' ),
                '<a style="text-transform:uppercase;" href="https://oddly.digital/" target="_blank" rel="noopener noreferrer">Oddly Digital</a>'
            );
            ?>
        </p>
        <ul>
            <li>
                <?php
                printf(
                    /* translators: %s: link to contact page */
                    esc_html__( 'Need help? Have a suggestion? %s.', 'email-list-cleaner-for-acymailing' ),
                    '<a href="https://oddly.digital/contact" target="_blank" rel="noopener noreferrer">'
                        . esc_html__( 'Send us a message', 'email-list-cleaner-for-acymailing' )
                    . '</a>'
                );
                ?>
            </li>
            <li>
                <?php
                printf(
                    /* translators: %s: link to oddly.digital */
                    esc_html__( 'We also offer WordPress website design, development and support services. %s.', 'email-list-cleaner-for-acymailing' ),
                    '<a href="https://oddly.digital/" target="_blank" rel="noopener noreferrer">'
                        . esc_html__( 'Find out more', 'email-list-cleaner-for-acymailing' )
                    . '</a>'
                );
                ?>
            </li>
        </ul>
        <p>
            <a href="https://github.com/OddlyDigital/email-list-cleaner-for-acymailing"
               target="_blank"
               rel="noopener noreferrer">
                <?php esc_html_e( 'View on GitHub', 'email-list-cleaner-for-acymailing' ); ?>
            </a>
        </p>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 9. Cleaner tab
// ---------------------------------------------------------------------------
function acym_bc_render_cleaner_tab( ?array $result, string $textarea ): void {
    ?>
    <div class="notice notice-error inline" style="margin:0 0 16px;">
        <p>
            <strong><?php esc_html_e( 'Use at your own risk:', 'email-list-cleaner-for-acymailing' ); ?></strong>
            <?php esc_html_e(
                'This tool permanently deletes subscriber records from your database. The plugin author accepts no responsibility for any data loss or damage to your website resulting from its use.',
                'email-list-cleaner-for-acymailing'
            ); ?>
            <strong><?php esc_html_e( 'Always back up your database before running this tool.', 'email-list-cleaner-for-acymailing' ); ?></strong>
        </p>
    </div>

    <?php if ( $result !== null ) : ?>
        <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> inline" style="margin:0 0 16px;">
            <p><?php echo wp_kses_post( $result['message'] ); ?></p>
            <?php if ( ! empty( $result['details'] ) ) : ?>
                <ul style="margin:4px 0 8px 16px;list-style:disc;">
                    <?php foreach ( $result['details'] as $line ) : ?>
                        <li><?php echo esc_html( $line ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p><?php esc_html_e(
        'Paste bounced or stale email addresses below (one per line or comma-separated) to remove them from your subscribers list.',
        'email-list-cleaner-for-acymailing'
    ); ?></p>

    <form method="post" action="">
        <?php wp_nonce_field( 'acym_bc_action', 'acym_bc_nonce' ); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="acym_bc_emails">
                        <?php esc_html_e( 'Email Addresses', 'email-list-cleaner-for-acymailing' ); ?>
                    </label>
                </th>
                <td>
                    <textarea
                        id="acym_bc_emails"
                        name="acym_bc_emails"
                        rows="12"
                        cols="60"
                        class="large-text code"
                        placeholder="email1@example.com&#10;email2@example.com&#10;email3@example.com"
                        spellcheck="false"
                        autocomplete="off"
                    ><?php echo esc_textarea( $textarea ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One address per line, or comma-separated.', 'email-list-cleaner-for-acymailing' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(
            __( 'Remove Addresses', 'email-list-cleaner-for-acymailing' ),
            'primary',
            'acym_bc_submit',
            true,
            [
                'onclick' => "return confirm('" . esc_js(
                    __( 'Are you sure? This will permanently delete the submitted email addresses from your subscribers list. We recommend you backup your database before proceeding.', 'email-list-cleaner-for-acymailing' )
                ) . "');",
            ]
        ); ?>
    </form>
    <?php
}

// ---------------------------------------------------------------------------
// 10. Audit log tab
// ---------------------------------------------------------------------------
function acym_bc_render_log_tab( ?string $notice ): void {
    global $wpdb;

    if ( $notice === 'deleted' ) {
        echo '<div class="notice notice-success inline" style="margin:0 0 16px;"><p>'
             . esc_html__( 'All log entries have been deleted.', 'email-list-cleaner-for-acymailing' )
             . '</p></div>';
    }

    $logging_enabled = get_option( 'acym_bc_logging_enabled', '1' ) === '1';
    if ( ! $logging_enabled ) {
        echo '<div class="notice notice-warning inline" style="margin:0 0 16px;"><p>'
             . esc_html__( 'Logging is currently disabled. Enable it on the Settings tab to record future deletions.', 'email-list-cleaner-for-acymailing' )
             . '</p></div>';
    }

    $table = $wpdb->prefix . ACYM_BC_LOG_TABLE;
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore

    if ( $wpdb->last_error ) {
        echo '<p class="notice notice-error inline">'
             . esc_html__( 'Could not load the log. Try deactivating and reactivating the plugin.', 'email-list-cleaner-for-acymailing' )
             . '</p>';
        return;
    }
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <p style="margin:0;">
            <?php
            if ( empty( $rows ) ) {
                esc_html_e( 'No deletions have been logged yet.', 'email-list-cleaner-for-acymailing' );
            } else {
                echo esc_html( sprintf(
                    _n( 'Showing %d log entry.', 'Showing up to %d log entries (latest 200).', count( $rows ), 'email-list-cleaner-for-acymailing' ),
                    count( $rows )
                ) );
            }
            ?>
        </p>
        <?php if ( ! empty( $rows ) ) : ?>
            <form method="post" action="" style="margin:0;">
                <?php wp_nonce_field( 'acym_bc_log_action', 'acym_bc_log_nonce' ); ?>
                <?php submit_button(
                    __( 'Delete All Logs', 'email-list-cleaner-for-acymailing' ),
                    'delete',
                    'acym_bc_delete_logs',
                    false,
                    [ 'onclick' => "return confirm('" . esc_js( __( 'Permanently delete all log entries?', 'email-list-cleaner-for-acymailing' ) ) . "');" ]
                ); ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $rows ) ) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date / Time (UTC)',     'email-list-cleaner-for-acymailing' ); ?></th>
                    <th><?php esc_html_e( 'Admin',                 'email-list-cleaner-for-acymailing' ); ?></th>
                    <th><?php esc_html_e( 'Submitted for Removal', 'email-list-cleaner-for-acymailing' ); ?></th>
                    <th><?php esc_html_e( 'Actually Removed',      'email-list-cleaner-for-acymailing' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) :
                    $submitted = json_decode( $row->emails_json,  true ) ?? [];
                    $removed   = json_decode( $row->removed_json, true ) ?? [];
                    sort( $submitted );
                    sort( $removed );
                    $not_found = array_values( array_diff( $submitted, $removed ) );
                ?>
                <tr>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                    <td><?php echo esc_html( $row->user_login ); ?></td>
                    <td><?php acym_bc_render_email_cell( $submitted, $not_found ); ?></td>
                    <td><?php acym_bc_render_email_cell( $removed ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:8px;">
            <span style="display:inline-block;width:10px;height:10px;background:#fef3cd;border:1px solid #f0b849;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>
            <?php esc_html_e( 'Highlighted addresses in the Submitted column were not found in the database and were not removed.', 'email-list-cleaner-for-acymailing' ); ?>
        </p>
    <?php endif; ?>
    <?php
}

/**
 * Renders a collapsible email list cell.
 * Addresses in $highlight are shown with an amber background (not found in DB).
 */
function acym_bc_render_email_cell( array $emails, array $highlight = [] ): void {
    if ( empty( $emails ) ) {
        echo '<span style="color:#999;">&#8212;</span>';
        return;
    }
    $count = count( $emails );
    ?>
    <details>
        <summary style="cursor:pointer;">
            <?php echo esc_html( sprintf(
                _n( '%d address', '%d addresses', $count, 'email-list-cleaner-for-acymailing' ),
                $count
            ) ); ?>
        </summary>
        <ul style="margin:6px 0 4px 0;padding:0;list-style:none;">
            <?php foreach ( $emails as $email ) :
                $is_highlighted = in_array( $email, $highlight, true );
            ?>
                <li style="margin:2px 0;">
                    <code style="<?php echo $is_highlighted ? 'background:#fef3cd;padding:1px 4px;border-radius:3px;' : ''; ?>">
                        <?php echo esc_html( $email ); ?>
                    </code>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php
}

// ---------------------------------------------------------------------------
// 11. Settings tab
// ---------------------------------------------------------------------------
function acym_bc_render_settings_tab( ?string $notice ): void {

    $logging_enabled = get_option( 'acym_bc_logging_enabled', '1' ) === '1';
    $log_retention   = (int) get_option( 'acym_bc_log_retention', 0 );

    $retention_options = [
        0                    => __( 'Keep forever',   'email-list-cleaner-for-acymailing' ),
        7   * DAY_IN_SECONDS => __( 'After 7 days',   'email-list-cleaner-for-acymailing' ),
        14  * DAY_IN_SECONDS => __( 'After 14 days',  'email-list-cleaner-for-acymailing' ),
        30  * DAY_IN_SECONDS => __( 'After 30 days',  'email-list-cleaner-for-acymailing' ),
        60  * DAY_IN_SECONDS => __( 'After 60 days',  'email-list-cleaner-for-acymailing' ),
        90  * DAY_IN_SECONDS => __( 'After 90 days',  'email-list-cleaner-for-acymailing' ),
        180 * DAY_IN_SECONDS => __( 'After 6 months', 'email-list-cleaner-for-acymailing' ),
        365 * DAY_IN_SECONDS => __( 'After 1 year',   'email-list-cleaner-for-acymailing' ),
        730 * DAY_IN_SECONDS => __( 'After 2 years',  'email-list-cleaner-for-acymailing' ),
    ];

    if ( $notice === 'saved' ) {
        echo '<div class="notice notice-success inline" style="margin:0 0 16px;"><p>'
             . esc_html__( 'Settings saved.', 'email-list-cleaner-for-acymailing' )
             . '</p></div>';
    }
    ?>
    <form method="post" action="">
        <?php wp_nonce_field( 'acym_bc_settings_action', 'acym_bc_settings_nonce' ); ?>
        <table class="form-table" role="presentation">

            <tr>
                <th scope="row"><?php esc_html_e( 'Enable Audit Logging', 'email-list-cleaner-for-acymailing' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="acym_bc_logging_enabled"
                               value="1"
                               <?php checked( $logging_enabled ); ?>>
                        <?php esc_html_e( 'Record each deletion run in the Audit Log', 'email-list-cleaner-for-acymailing' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, each run is logged with the admin username, timestamp, submitted addresses, and actually-removed addresses.',
                            'email-list-cleaner-for-acymailing'
                        ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="acym_bc_log_retention">
                        <?php esc_html_e( 'Automatically Delete Logs', 'email-list-cleaner-for-acymailing' ); ?>
                    </label>
                </th>
                <td>
                    <select name="acym_bc_log_retention" id="acym_bc_log_retention">
                        <?php foreach ( $retention_options as $seconds => $label ) : ?>
                            <option value="<?php echo (int) $seconds; ?>"
                                    <?php selected( $log_retention, $seconds ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Log entries older than the selected period are deleted automatically once per day.', 'email-list-cleaner-for-acymailing' ); ?>
                    </p>
                </td>
            </tr>

        </table>
        <?php submit_button( __( 'Save Settings', 'email-list-cleaner-for-acymailing' ), 'primary', 'acym_bc_save_settings' ); ?>
    </form>
    <?php
}

// ---------------------------------------------------------------------------
// 12. Submission handler
// ---------------------------------------------------------------------------
function acym_bc_handle_submission( string $raw ): array {

    $lines        = preg_split( '/[\r\n,]+/', $raw );
    $valid_emails = [];
    $invalid      = [];

    foreach ( $lines as $line ) {
        $email = strtolower( trim( $line ) );
        if ( $email === '' ) {
            continue;
        }
        if ( is_email( $email ) ) {
            $valid_emails[ $email ] = true;
        } else {
            $invalid[] = $email;
        }
    }

    $valid_emails = array_keys( $valid_emails );

    if ( ! empty( $invalid ) ) {
        return [
            'success' => false,
            'message' => sprintf(
                __( 'Submission rejected — the following entries are not valid email addresses: %s', 'email-list-cleaner-for-acymailing' ),
                implode( ', ', array_map( 'esc_html', $invalid ) )
            ),
        ];
    }

    if ( empty( $valid_emails ) ) {
        return [
            'success' => false,
            'message' => __( 'Please enter at least one email address.', 'email-list-cleaner-for-acymailing' ),
        ];
    }

    return acym_bc_run_deletions( $valid_emails );
}

// ---------------------------------------------------------------------------
// 13. Core deletion logic
// ---------------------------------------------------------------------------
function acym_bc_run_deletions( array $emails ): array {
    global $wpdb;

    $count        = count( $emails );
    $placeholders = implode( ', ', array_fill( 0, $count, '%s' ) );

    if ( substr_count( $placeholders, '%s' ) !== $count ) {
        return [
            'success' => false,
            'message' => __( 'Internal error: placeholder mismatch. No changes were made.', 'email-list-cleaner-for-acymailing' ),
        ];
    }

    $wpdb->query( 'START TRANSACTION' );

    // Pre-deletion SELECT: find which submitted addresses actually exist.
    // Runs inside the transaction so the result is consistent with the DELETEs.
    $existing_rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}acym_user WHERE email IN ( $placeholders )", // phpcs:ignore
            ...$emails
        )
    );

    if ( $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return [
            'success' => false,
            'message' => __( 'DB error checking existing addresses. No changes were made.', 'email-list-cleaner-for-acymailing' )
                         . ' ' . esc_html( $wpdb->last_error ),
        ];
    }

    $actually_removed = array_map( 'strtolower', $existing_rows );

    $deleted = [ 'user_has_list' => 0, 'user_stat' => 0, 'user' => 0 ];

    // Step 1 – List associations.
    $sql  = $wpdb->prepare(
        "DELETE ul FROM {$wpdb->prefix}acym_user_has_list ul JOIN {$wpdb->prefix}acym_user u ON ul.user_id = u.id WHERE u.email IN ( $placeholders )", // phpcs:ignore
        ...$emails
    );
    $rows = $wpdb->query( $sql ); // phpcs:ignore

    if ( $rows === false || $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return [
            'success' => false,
            'message' => __( 'DB error removing list associations. No changes were made.', 'email-list-cleaner-for-acymailing' )
                         . ' ' . esc_html( $wpdb->last_error ),
        ];
    }
    $deleted['user_has_list'] = (int) $rows;

    // Step 2 – User statistics (before users so the JOIN still resolves).
    $sql  = $wpdb->prepare(
        "DELETE us FROM {$wpdb->prefix}acym_user_stat us JOIN {$wpdb->prefix}acym_user u ON us.user_id = u.id WHERE u.email IN ( $placeholders )", // phpcs:ignore
        ...$emails
    );
    $rows = $wpdb->query( $sql ); // phpcs:ignore

    if ( $rows === false || $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return [
            'success' => false,
            'message' => __( 'DB error removing user statistics. No changes were made.', 'email-list-cleaner-for-acymailing' )
                         . ' ' . esc_html( $wpdb->last_error ),
        ];
    }
    $deleted['user_stat'] = (int) $rows;

    // Step 3 – Subscriber records.
    $sql  = $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}acym_user WHERE email IN ( $placeholders )", // phpcs:ignore
        ...$emails
    );
    $rows = $wpdb->query( $sql ); // phpcs:ignore

    if ( $rows === false || $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return [
            'success' => false,
            'message' => __( 'DB error removing subscriber records. No changes were made.', 'email-list-cleaner-for-acymailing' )
                         . ' ' . esc_html( $wpdb->last_error ),
        ];
        }
    $deleted['user'] = (int) $rows;

    $wpdb->query( 'COMMIT' );

    // Audit log.
    if ( get_option( 'acym_bc_logging_enabled', '1' ) === '1' ) {
        $current_user = wp_get_current_user();
        $wpdb->insert(
            $wpdb->prefix . ACYM_BC_LOG_TABLE,
            [
                'user_id'      => $current_user->ID,
                'user_login'   => $current_user->user_login,
                'emails_json'  => wp_json_encode( $emails ),
                'removed_json' => wp_json_encode( $actually_removed ),
                'rows_list'    => $deleted['user_has_list'],
                'rows_stat'    => $deleted['user_stat'],
                'rows_user'    => $deleted['user'],
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
        );
    }

    $not_found_count = count( $emails ) - count( $actually_removed );

    $details = [
        sprintf(
            _n(
                '%d subscriber record permanently removed.',
                '%d subscriber records permanently removed.',
                $deleted['user'],
                'email-list-cleaner-for-acymailing'
            ),
            $deleted['user']
        ),
    ];

    if ( $not_found_count > 0 ) {
        $details[] = sprintf(
            _n(
                'Note: %d submitted address was not found in the database and was skipped.',
                'Note: %d submitted addresses were not found in the database and were skipped.',
                $not_found_count,
                'email-list-cleaner-for-acymailing'
            ),
            $not_found_count
        );
    }

    return [
        'success' => true,
        'message' => __( 'Done.', 'email-list-cleaner-for-acymailing' ),
        'details' => $details,
    ];
}
