<?php
/**
 * Email Tab: History
 * Renders email history log with pagination and preview modal
 */

if (!defined('ABSPATH')) exit;

class Ofast_Email_Tab_History
{
    /**
     * Render history tab (content only, used inside tabs)
     */
    public function render()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';

        // Pagination params
        $allowed_per_page = array(10, 20, 50, 100);
        $per_page_input = isset($_GET['hist_per_page']) ? sanitize_text_field($_GET['hist_per_page']) : '20';
        $show_all = ($per_page_input === 'all');
        $per_page = $show_all ? 999999 : intval($per_page_input);
        if (!$show_all && !in_array($per_page, $allowed_per_page)) {
            $per_page = 20;
        }
        $current_page = max(1, intval($_GET['hist_paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));
        $total_pages = $show_all ? 1 : (int) ceil($total / $per_page);
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = $total_pages;
            $offset = ($current_page - 1) * $per_page;
        }

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $showing_start = $total > 0 ? $offset + 1 : 0;
        $showing_end = min($offset + $per_page, $total);
        ?>
        <div class="ofast-card">
            <div
                style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0 0 4px 0;">Email History</h2>
                    <p style="margin: 0; color: #64748b; font-size: 13px;">View sent emails and preview their content.</p>
                </div>
                <!-- Per-page selector -->
                <div class="ofast-per-page-wrap">
                    <span>Show</span>
                    <select id="ofast-history-per-page" class="ofast-per-page-select">
                        <?php foreach (array(10, 20, 50, 100, 'all') as $opt): ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected($show_all ? 'all' : $per_page, $opt === 'all' ? 'all' : $opt); ?>>
                                <?php echo $opt === 'all' ? 'All' : $opt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span>per page</span>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <p>No emails have been logged yet.</p>
            <?php else: ?>
                <div style="overflow-x: auto; max-width: 100%;">
                    <table class="widefat fixed striped" style="min-width: 800px;">
                        <thead>
                            <tr>
                                <th style="width:5%;">ID</th>
                                <th>Subject</th>
                                <th style="width:18%;">Audience</th>
                                <th style="width:13%;">Sent At</th>
                                <th style="width:8%;">Recipients</th>
                                <th style="width:8%;">Status</th>
                                <th style="width:10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log):
                                // Decode stored target roles for display
                                $log_roles = !empty($log->target_roles) ? json_decode($log->target_roles, true) : array();
                                $log_roles = is_array($log_roles) ? $log_roles : array();
                            ?>
                                <tr>
                                    <td><?php echo esc_html($log->id); ?></td>
                                    <td><?php echo esc_html($log->subject); ?></td>
                                    <td>
                                        <?php if (!empty($log_roles)): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                            <?php
                                            global $wp_roles;
                                            foreach ($log_roles as $lr) {
                                                if ($lr === '_imported_contacts') {
                                                    $role_label = 'CRM Contacts';
                                                    $badge_bg = '#fef3c7'; $badge_color = '#92400e';
                                                } elseif ($lr === '_manual_emails') {
                                                    $role_label = 'External';
                                                    $badge_bg = '#e0e7ff'; $badge_color = '#3730a3';
                                                } else {
                                                    $role_label = isset($wp_roles->roles[$lr]) ? translate_user_role($wp_roles->roles[$lr]['name']) : ucfirst($lr);
                                                    $badge_bg = '#ede9fe'; $badge_color = '#5b21b6';
                                                }
                                                echo '<span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:500; background:' . esc_attr($badge_bg) . '; color:' . esc_attr($badge_color) . ';">' . esc_html($role_label) . '</span>';
                                            }
                                            ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #9ca3af; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($log->sent_at); ?></td>
                                    <td><?php echo esc_html($log->recipient_count); ?></td>
                                    <td>
                                        <span
                                            style="padding: 2px 8px; border-radius: 4px; font-size: 11px; background: <?php echo $log->status === 'sent' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $log->status === 'sent' ? '#166534' : '#991b1b'; ?>;">
                                            <?php echo esc_html(ucfirst($log->status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log->body)): ?>
                                            <button type="button" class="button button-small preview-log-btn"
                                                data-body="<?php echo esc_attr($log->body); ?>"
                                                data-subject="<?php echo esc_attr($log->subject); ?>">Preview</button>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">No preview</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar -->
                <div class="ofast-pagination">
                    <div class="ofast-pagination-info">
                        Showing <strong><?php echo esc_html($showing_start); ?>–<?php echo esc_html($showing_end); ?></strong> of
                        <strong><?php echo esc_html($total); ?></strong> emails
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="ofast-pagination-pages">
                            <?php
                            // Prev
                            $prev_disabled = $current_page <= 1 ? ' disabled' : '';
                            echo '<a href="#" class="ofast-page-btn' . $prev_disabled . '" data-page="' . max(1, $current_page - 1) . '" title="Previous">';
                            echo '<span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>';
                            echo '</a>';

                            // Page numbers with smart ellipsis
                            $range = 2;
                            for ($i = 1; $i <= $total_pages; $i++) {
                                if ($i === 1 || $i === $total_pages || ($i >= $current_page - $range && $i <= $current_page + $range)) {
                                    $active = $i === $current_page ? ' active' : '';
                                    echo '<a href="#" class="ofast-page-btn' . $active . '" data-page="' . $i . '">' . $i . '</a>';
                                } elseif ($i === $current_page - $range - 1 || $i === $current_page + $range + 1) {
                                    echo '<span class="ofast-page-ellipsis">…</span>';
                                }
                            }

                            // Next
                            $next_disabled = $current_page >= $total_pages ? ' disabled' : '';
                            echo '<a href="#" class="ofast-page-btn' . $next_disabled . '" data-page="' . min($total_pages, $current_page + 1) . '" title="Next">';
                            echo '<span class="dashicons dashicons-arrow-right-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>';
                            echo '</a>';
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Preview Modal -->
        <div id="history-preview-modal"
            style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000; padding:50px;">
            <div class="ofast-modal-body"
                style="background:#fff; max-width:800px; margin:0 auto; border-radius:12px; padding:20px; max-height:80vh; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 id="modal-subject" style="margin:0;">Preview</h3>
                    <button type="button" id="close-history-modal" class="button">Close</button>
                </div>
                <iframe id="modal-content"
                    style="width:100%; height:500px; border:1px solid #e5e7eb; border-radius:8px;"></iframe>
            </div>
        </div>
<?php
    }

    /**
     * Render standalone history page (with header wrapper)
     */
    public function render_standalone()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_email_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY sent_at DESC LIMIT 100");

        ?>
<div class="wrap">
                                    <!-- Header -->
                                    <div class="ofast-header">
                                        <div class="ofast-header-icon">
                                            <span class="dashicons dashicons-clock"></span>
                                        </div>
                                        <div class="ofast-header-content">
                                            <h1>Email History</h1>
                                            <p>View sent emails and preview their content. Showing the last 100 entries.</p>
                                        </div>
                                    </div>

                                    <div class="ofast-card">
                                        <?php if (empty($logs)): ?>
                                            <p>No emails have been logged yet.</p>
                                        <?php else: ?>
                                            <div style="overflow-x: auto; max-width: 100%;">
                                                <table class="widefat fixed striped" style="min-width: 800px;">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:5%;">ID</th>
                                                            <th>Subject</th>
                                                            <th style="width:15%;">Sent At</th>
                                                            <th style="width:8%;">Recipients</th>
                                                            <th style="width:10%;">Status</th>
                                                            <th style="width:15%;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($logs as $log): ?>
                                                            <tr>
                                                                <td><?php echo esc_html($log->id); ?></td>
                                                                <td><?php echo esc_html(wp_trim_words($log->subject, 12, '...')); ?>
                                                                </td>
                                                                <td><?php echo esc_html($log->sent_at); ?></td>
                                                                <td><?php echo esc_html($log->recipient_count); ?></td>
                                                                <td>
                                                                    <?php
                                                                    $status = $log->status ?? 'sent';
                                                                    $status_class = $status === 'failed' ? 'color: #dc2626;' : ($status === 'scheduled' ? 'color: #f59e0b;' : 'color: #10b981;');
                                                                    ?>
                                                                    <span style="<?php echo $status_class; ?> font-weight: 500;">
                                                                        <?php echo esc_html(ucfirst($status)); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($log->body)): ?>
                                                                        <button type="button" class="button button-small preview-email-btn"
                                                                            data-content="<?php echo esc_attr(base64_encode($log->body)); ?>">
                                                                            Preview
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <span style="color: #9ca3af;">No preview</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Email Preview Modal -->
                                    <div id="emailer-preview-modal"
                                        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
                                        <div
                                            style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; overflow: hidden;">
                                            <div
                                                style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                                                <h3 style="margin: 0;">Email Preview</h3>
                                                <button type="button" id="close-emailer-preview"
                                                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                                            </div>
                                            <iframe id="emailer-preview-frame" sandbox
                                                style="width: 100%; height: 60vh; border: none;"></iframe>
                                        </div>
                                    </div>
</div>
<?php
    }
}
