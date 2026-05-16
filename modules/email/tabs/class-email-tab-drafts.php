<?php
/**
 * Email Tab: Drafts
 * Renders drafts listing with CRUD operations
 */

if (!defined('ABSPATH')) exit;

class Ofast_Email_Tab_Drafts
{
    /**
     * Render drafts tab (content only, used inside tabs)
     */
    public function render()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_drafts';
        $current_user_id = get_current_user_id();

        // Create table if needed
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id BIGINT(20) UNSIGNED NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT,
                roles TEXT,
                user_ids TEXT,
                manual_emails LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_id (admin_id)
            ) $charset;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            // Check for missing manual_emails column and add it
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
            if ($columns && !in_array('manual_emails', $columns)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN manual_emails LONGTEXT AFTER user_ids");
            }
        }

        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['draft_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_draft_' . $_GET['draft_id'])) {
                $draft_id = intval($_GET['draft_id']);

                $draft = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, admin_id FROM $table WHERE id = %d",
                    $draft_id
                ));

                if (!$draft) {
                    echo Ofast_X_Toast::render('Draft not found.', 'error', true);
                } elseif ((int) $draft->admin_id !== $current_user_id) {
                    error_log(sprintf(
                        'SECURITY: User %d attempted unauthorized deletion of draft %d (owned by user %d)',
                        $current_user_id,
                        $draft_id,
                        $draft->admin_id
                    ));
                    echo Ofast_X_Toast::render('Draft not found.', 'error', true);
                } else {
                    $wpdb->delete($table, array('id' => $draft_id));
                    echo Ofast_X_Toast::render('Draft deleted successfully!', 'success', true);
                }
            }
        }

        // Handle send now action
        if (isset($_GET['action']) && $_GET['action'] === 'send' && isset($_GET['draft_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'send_draft_' . $_GET['draft_id'])) {
                $draft_id = intval($_GET['draft_id']);

                $draft = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d",
                    $draft_id
                ));

                if (!$draft) {
                    echo Ofast_X_Toast::render('Draft not found.', 'error', true);
                } elseif ((int) $draft->admin_id !== $current_user_id) {
                    error_log(sprintf(
                        'SECURITY: User %d attempted unauthorized send of draft %d (owned by user %d)',
                        $current_user_id,
                        $draft_id,
                        $draft->admin_id
                    ));
                    echo Ofast_X_Toast::render('Draft not found.', 'error', true);
                } else {
                    echo '<script>window.location.href="' . admin_url('admin.php?page=ofast-emailer&draft_id=' . $draft->id) . '";</script>';
                    return;
                }
            }
        }

        $drafts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE admin_id = %d ORDER BY updated_at DESC",
            $current_user_id
        ));
        ?>
<div class="ofast-card">
            <?php if (empty($drafts)): ?>
                <div class="notice notice-info inline" style="margin: 0;">
                    <p>No drafts yet. <a href="<?php echo admin_url('admin.php?page=ofast-emailer'); ?>">Create an email</a> and
                        save it as draft.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:30%">Subject</th>
                                <th style="width:20%">Recipients</th>
                                <th style="width:20%">Last Modified</th>
                                <th style="width:30%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drafts as $draft):
                                $roles = json_decode($draft->roles, true) ?: array();
                                $user_ids = json_decode($draft->user_ids, true) ?: array();
                                $manual_emails = isset($draft->manual_emails) ? (json_decode($draft->manual_emails, true) ?: array()) : array();
                                $recipients = array();
                                if (!empty($roles)) {
                                    global $wp_roles;
                                    $role_names = array();
                                    foreach ($roles as $role) {
                                        if ($role === '_imported_contacts') {
                                            $role_names[] = 'Imported Contacts';
                                        } else {
                                            $role_names[] = isset($wp_roles->roles[$role]) ? translate_user_role($wp_roles->roles[$role]['name']) : $role;
                                        }
                                    }
                                    $recipients[] = implode(', ', $role_names);
                                }
                                if (!empty($user_ids))
                                    $recipients[] = count($user_ids) . ' user(s)';
                                if (!empty($manual_emails))
                                    $recipients[] = count($manual_emails) . ' external email(s)';
                                
                                $recipients_text = !empty($recipients) ? implode(' | ', $recipients) : 'Admin only';

                                $edit_url = admin_url('admin.php?page=ofast-emailer&draft_id=' . $draft->id);
                                $send_url = wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=drafts&action=send&draft_id=' . $draft->id), 'send_draft_' . $draft->id);
                                $delete_url = wp_nonce_url(admin_url('admin.php?page=ofast-emailer&tab=drafts&action=delete&draft_id=' . $draft->id), 'delete_draft_' . $draft->id);
                                ?>
                                <tr>
                                    <td><strong><a
                                                href="<?php echo $edit_url; ?>"><?php echo esc_html($draft->subject ?: '(No Subject)'); ?></a></strong>
                                    </td>
                                    <td><?php echo esc_html($recipients_text); ?></td>
                                    <td><?php echo esc_html($draft->updated_at); ?></td>
                                    <td>
                                        <a href="<?php echo $edit_url; ?>" class="ofast-draft-action">Edit</a>
                                        <a href="<?php echo $send_url; ?>" class="ofast-draft-action"
                                            onclick="return confirm('Load this draft to send?');">Send</a>
                                        <a href="<?php echo $delete_url; ?>" class="ofast-draft-action delete"
                                            onclick="return confirm('Delete this draft permanently?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
