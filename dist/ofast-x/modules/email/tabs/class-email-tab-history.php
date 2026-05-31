<?php
/**
 * Email Tab: History
 *
 * Unified history of all sent emails.
 * - Direct sends  (≤50 recipients) → pulled from ofast_email_logs
 * - Bulk campaigns (>50 recipients) → pulled from ofast_email_campaigns
 *
 * Both are merged and sorted by date descending.
 * Each row has a "Preview" button that opens a rich modal showing:
 *   • The email body (rendered in a sandboxed iframe)
 *   • A stats summary (total / sent / failed / pending)
 *   • An expandable list of failed recipient addresses
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Ofast_Email_Tab_History {

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'ofast-x' ) );
        }

        global $wpdb;
        $logs_table      = $wpdb->prefix . 'ofast_email_logs';
        $campaigns_table = $wpdb->prefix . 'ofast_email_campaigns';

        // ── Trigger schema migration if not yet done ──────────────────────────
        // Matches the same version-keyed transient used by the Campaigns tab,
        // ensuring both tabs always agree on whether migration has run.
        $schema_key = 'ofast_campaigns_schema_v' . str_replace( '.', '_', OFAST_X_VERSION );
        if ( ! get_transient( $schema_key ) ) {
            if ( class_exists( 'Ofast_X_Activator' ) ) {
                Ofast_X_Activator::run_upgrade_tables();
            }
            set_transient( $schema_key, 1, WEEK_IN_SECONDS );
        }

        // ── Pagination ────────────────────────────────────────────────────────
        $allowed_per_page = array( 10, 20, 50, 100 );
        $per_page_input   = isset( $_GET['hist_per_page'] ) ? sanitize_text_field( $_GET['hist_per_page'] ) : '20';
        $show_all         = ( $per_page_input === 'all' );
        $per_page         = $show_all ? 999999 : intval( $per_page_input );
        if ( ! $show_all && ! in_array( $per_page, $allowed_per_page ) ) $per_page = 20;
        $current_page = max( 1, intval( $_GET['hist_paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;

        // ── Check which tables exist ──────────────────────────────────────────
        $logs_exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
        $campaigns_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $campaigns_table ) );

        // ── Inspect campaigns table columns (handle both old and new schema) ──
        // Old schema: total_recipients, no sent/failed/failed_recipients columns
        // New schema: total, sent, failed, failed_recipients
        $camp_cols              = array();
        $camp_total_expr        = '0';
        $camp_sent_expr         = '0';
        $camp_failed_expr       = '0';
        $camp_failed_recip_expr = 'NULL';

        if ( $campaigns_exists ) {
            $camp_cols = $wpdb->get_col( "DESCRIBE {$campaigns_table}", 0 );

            // Total recipients
            if ( in_array( 'total', $camp_cols, true ) ) {
                $camp_total_expr = '`total`';
            } elseif ( in_array( 'total_recipients', $camp_cols, true ) ) {
                $camp_total_expr = 'total_recipients';
            }

            // Sent count (new schema only)
            $camp_sent_expr = in_array( 'sent', $camp_cols, true ) ? 'sent' : '0';

            // Failed count (new schema only)
            $camp_failed_expr = in_array( 'failed', $camp_cols, true ) ? 'failed' : '0';

            // Failed recipient JSON (new schema only)
            $camp_failed_recip_expr = in_array( 'failed_recipients', $camp_cols, true ) ? 'failed_recipients' : 'NULL';
        }

        // ── UNION query — normalise both sources into one schema ──────────────
        $parts = array();

        if ( $logs_exists ) {
            $parts[] = "
                SELECT
                    'direct'              AS type,
                    id                    AS entry_id,
                    subject,
                    sent_at,
                    recipient_count       AS total,
                    recipient_count       AS sent,
                    0                     AS failed,
                    IFNULL(status,'sent') AS status,
                    body,
                    NULL                  AS failed_recipients
                FROM {$logs_table}
            ";
        }

        if ( $campaigns_exists ) {
            $parts[] = "
                SELECT
                    'campaign'                AS type,
                    id                        AS entry_id,
                    subject,
                    created_at                AS sent_at,
                    {$camp_total_expr}        AS total,
                    {$camp_sent_expr}         AS sent,
                    {$camp_failed_expr}       AS failed,
                    status,
                    body,
                    {$camp_failed_recip_expr} AS failed_recipients
                FROM {$campaigns_table}
            ";
        }

        if ( empty( $parts ) ) {
            echo '<p>No email tables found.</p>';
            return;
        }

        $union_sql = '(' . implode( ') UNION ALL (', $parts ) . ')';
        $count_sql = "SELECT COUNT(*) FROM ( {$union_sql} ) AS u";
        $total     = (int) $wpdb->get_var( $count_sql );

        $total_pages = $show_all ? 1 : (int) ceil( $total / $per_page );
        if ( $current_page > $total_pages && $total_pages > 0 ) {
            $current_page = $total_pages;
            $offset       = ( $current_page - 1 ) * $per_page;
        }

        $data_sql = $wpdb->prepare(
            "SELECT * FROM ( {$union_sql} ) AS u ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $entries = $wpdb->get_results( $data_sql );

        $showing_start = $total > 0 ? $offset + 1 : 0;
        $showing_end   = min( $offset + $per_page, $total );
        ?>
        <div class="ofast-card">

            <!-- Header -->
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
                <div>
                    <h2 style="margin:0 0 4px 0;">Email History</h2>
                    <p style="margin:0;color:#64748b;font-size:13px;">
                        All sent emails — direct &amp; bulk campaigns. Click <strong>Preview</strong> to see stats and the email body.
                    </p>
                </div>
                <div class="ofast-per-page-wrap">
                    <span>Show</span>
                    <select id="ofast-history-per-page" class="ofast-per-page-select">
                        <?php foreach ( array( 10, 20, 50, 100, 'all' ) as $opt ) : ?>
                            <option value="<?php echo esc_attr( $opt ); ?>"
                                <?php selected( $show_all ? 'all' : $per_page, $opt === 'all' ? 'all' : $opt ); ?>>
                                <?php echo $opt === 'all' ? 'All' : $opt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span>per page</span>
                </div>
            </div>

            <?php if ( empty( $entries ) ) : ?>
                <div class="ofast-campaigns-empty" style="padding:40px 24px;">
                    <div class="ofast-campaigns-empty-icon">📭</div>
                    <h3>No emails sent yet</h3>
                    <p>Once you send emails, every send — direct or bulk campaign — will appear here.</p>
                </div>
            <?php else : ?>

                <div style="overflow-x:auto;max-width:100%;">
                    <table class="widefat fixed striped" style="min-width:820px;">
                        <thead>
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:8%;">Type</th>
                                <th>Subject</th>
                                <th style="width:15%;">Sent At</th>
                                <th style="width:7%;text-align:center;">Total</th>
                                <th style="width:7%;text-align:center;">✅ Sent</th>
                                <th style="width:7%;text-align:center;">❌ Failed</th>
                                <th style="width:9%;">Status</th>
                                <th style="width:9%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $entries as $entry ) :
                                $is_campaign  = ( $entry->type === 'campaign' );
                                $entry_total  = (int) ( $entry->total  ?? 0 );
                                $entry_sent   = (int) ( $entry->sent   ?? 0 );
                                $entry_failed = (int) ( $entry->failed ?? 0 );
                                $entry_pending = $is_campaign ? max( 0, $entry_total - $entry_sent - $entry_failed ) : 0;
                                $status        = $entry->status ?? 'sent';

                                // Status colour
                                $st_map = array(
                                    'sent'       => array( '#d1fae5', '#065f46' ),
                                    'completed'  => array( '#d1fae5', '#065f46' ),
                                    'queued'     => array( '#ede9fe', '#6d28d9' ),
                                    'processing' => array( '#dbeafe', '#1d4ed8' ),
                                    'paused'     => array( '#fef3c7', '#92400e' ),
                                    'failed'     => array( '#fee2e2', '#991b1b' ),
                                    'cancelled'  => array( '#f1f5f9', '#64748b' ),
                                    'scheduled'  => array( '#fef3c7', '#92400e' ),
                                );
                                $st_colors = $st_map[ $status ] ?? array( '#f1f5f9', '#475569' );

                                $failed_json = $entry->failed_recipients ?? '[]';
                                $body_b64    = base64_encode( $entry->body ?? '' );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $entry->entry_id ); ?></td>
                                <td>
                                    <?php if ( $is_campaign ) : ?>
                                        <span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">Bulk</span>
                                    <?php else : ?>
                                        <span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">Direct</span>
                                    <?php endif; ?>
                                </td>
                                <td title="<?php echo esc_attr( $entry->subject ); ?>"><?php echo esc_html( wp_trim_words( $entry->subject, 10, '…' ) ); ?></td>
                                <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $entry->sent_at ) ) ); ?></td>
                                <td style="text-align:center;"><?php echo esc_html( $entry_total ); ?></td>
                                <td style="text-align:center;color:#065f46;font-weight:600;"><?php echo esc_html( $entry_sent ); ?></td>
                                <td style="text-align:center;color:<?php echo $entry_failed > 0 ? '#dc2626' : '#9ca3af'; ?>;font-weight:<?php echo $entry_failed > 0 ? '600' : '400'; ?>;">
                                    <?php echo esc_html( $entry_failed ); ?>
                                </td>
                                <td>
                                    <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $st_colors[0] ); ?>;color:<?php echo esc_attr( $st_colors[1] ); ?>;">
                                        <?php echo esc_html( ucfirst( $status ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button"
                                        class="button button-small ofast-history-preview-btn"
                                        data-body="<?php echo esc_attr( $body_b64 ); ?>"
                                        data-subject="<?php echo esc_attr( $entry->subject ); ?>"
                                        data-total="<?php echo esc_attr( $entry_total ); ?>"
                                        data-sent="<?php echo esc_attr( $entry_sent ); ?>"
                                        data-failed="<?php echo esc_attr( $entry_failed ); ?>"
                                        data-pending="<?php echo esc_attr( $entry_pending ); ?>"
                                        data-failed-emails="<?php echo esc_attr( $failed_json ); ?>"
                                        data-type="<?php echo esc_attr( $entry->type ); ?>">
                                        Preview
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="ofast-pagination">
                    <div class="ofast-pagination-info">
                        Showing <strong><?php echo esc_html( $showing_start ); ?>–<?php echo esc_html( $showing_end ); ?></strong>
                        of <strong><?php echo esc_html( $total ); ?></strong> entries
                    </div>
                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="ofast-pagination-pages">
                            <?php
                            $prev_dis = $current_page <= 1 ? ' disabled' : '';
                            echo '<a href="#" class="ofast-page-btn' . $prev_dis . '" data-page="' . max(1,$current_page-1) . '" title="Previous"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span></a>';
                            $range = 2;
                            for ( $i = 1; $i <= $total_pages; $i++ ) {
                                if ( $i === 1 || $i === $total_pages || ( $i >= $current_page - $range && $i <= $current_page + $range ) ) {
                                    $active = $i === $current_page ? ' active' : '';
                                    echo '<a href="#" class="ofast-page-btn' . $active . '" data-page="' . $i . '">' . $i . '</a>';
                                } elseif ( $i === $current_page - $range - 1 || $i === $current_page + $range + 1 ) {
                                    echo '<span class="ofast-page-ellipsis">…</span>';
                                }
                            }
                            $next_dis = $current_page >= $total_pages ? ' disabled' : '';
                            echo '<a href="#" class="ofast-page-btn' . $next_dis . '" data-page="' . min($total_pages,$current_page+1) . '" title="Next"><span class="dashicons dashicons-arrow-right-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span></a>';
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>

        <!-- ── Preview Modal ──────────────────────────────────────────────── -->
        <div id="ofast-history-modal"
            style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:100000;padding:30px 20px;overflow-y:auto;">
            <div style="background:#fff;max-width:860px;margin:0 auto;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">

                <!-- Modal Header -->
                <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <div>
                        <h3 id="ofast-modal-subject" style="margin:0 0 4px 0;font-size:16px;color:#1e293b;"></h3>
                        <span id="ofast-modal-type-badge" style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:20px;"></span>
                    </div>
                    <button type="button" id="ofast-close-modal"
                        style="background:none;border:none;cursor:pointer;font-size:24px;color:#64748b;line-height:1;padding:4px;">×</button>
                </div>

                <!-- Stats Bar -->
                <div id="ofast-modal-stats" style="display:flex;gap:0;border-bottom:1px solid #e2e8f0;">
                    <!-- filled by JS -->
                </div>

                <!-- Tabs: Body / Failed -->
                <div style="display:flex;gap:0;border-bottom:1px solid #e2e8f0;padding:0 24px;">
                    <button type="button" class="ofast-modal-tab active" data-tab="body"
                        style="padding:12px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#6366f1;border-bottom:2px solid #6366f1;margin-bottom:-1px;">
                        📧 Email Preview
                    </button>
                    <button type="button" class="ofast-modal-tab" data-tab="failed"
                        id="ofast-tab-failed-btn"
                        style="padding:12px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#94a3b8;border-bottom:2px solid transparent;margin-bottom:-1px;">
                        ❌ Failed (<span id="ofast-modal-failed-count">0</span>)
                    </button>
                    <button type="button" class="ofast-modal-tab" data-tab="pending"
                        id="ofast-tab-pending-btn"
                        style="padding:12px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#94a3b8;border-bottom:2px solid transparent;margin-bottom:-1px;">
                        ⏳ Pending (<span id="ofast-modal-pending-count">0</span>)
                    </button>
                </div>

                <!-- Tab: Body -->
                <div id="ofast-tab-body" class="ofast-modal-panel" style="padding:0;">
                    <iframe id="ofast-modal-iframe" sandbox="allow-same-origin"
                        style="width:100%;height:520px;border:none;display:block;"></iframe>
                </div>

                <!-- Tab: Failed emails -->
                <div id="ofast-tab-failed" class="ofast-modal-panel" style="display:none;padding:20px 24px;">
                    <p style="color:#64748b;font-size:13px;margin:0 0 12px 0;">These addresses did not receive the email:</p>
                    <div id="ofast-failed-list"></div>
                </div>

                <!-- Tab: Pending emails -->
                <div id="ofast-tab-pending" class="ofast-modal-panel" style="display:none;padding:20px 24px;">
                    <p style="color:#64748b;font-size:13px;margin:0 0 12px 0;">These recipients have not been processed yet (campaign still running or was cancelled):</p>
                    <div id="ofast-pending-list"></div>
                </div>

            </div>
        </div>

        <script type="text/javascript">
        (function($) {
            var $modal       = $('#ofast-history-modal');
            var $iframe      = $('#ofast-modal-iframe');
            var $statsBar    = $('#ofast-modal-stats');
            var $failedList  = $('#ofast-failed-list');
            var $pendingList = $('#ofast-pending-list');

            // ── Open modal ────────────────────────────────────────────────────
            $(document).on('click', '.ofast-history-preview-btn', function() {
                var btn     = $(this);
                var subject = btn.data('subject');
                var bodyB64 = btn.data('body');
                var total   = parseInt(btn.data('total'))   || 0;
                var sent    = parseInt(btn.data('sent'))    || 0;
                var failed  = parseInt(btn.data('failed'))  || 0;
                var pending = parseInt(btn.data('pending')) || 0;
                var type    = btn.data('type');

                var failedEmails  = [];
                try { failedEmails  = JSON.parse(btn.data('failed-emails')  || '[]'); } catch(e) {}

                // Header
                $('#ofast-modal-subject').text(subject);
                var badge = type === 'campaign'
                    ? '<span style="background:#ede9fe;color:#6d28d9;">⚡ Bulk Campaign</span>'
                    : '<span style="background:#e0e7ff;color:#3730a3;">✉ Direct Send</span>';
                $('#ofast-modal-type-badge').html(badge);

                // Stats bar
                var pct   = total > 0 ? Math.round((sent/total)*100) : 0;
                $statsBar.html(
                    '<div style="flex:1;padding:16px 20px;text-align:center;border-right:1px solid #e2e8f0;">'  +
                        '<div style="font-size:24px;font-weight:700;color:#1e293b;">' + total   + '</div>' +
                        '<div style="font-size:12px;color:#64748b;margin-top:2px;">Total</div>'             +
                    '</div>'                                                                                  +
                    '<div style="flex:1;padding:16px 20px;text-align:center;border-right:1px solid #e2e8f0;">'  +
                        '<div style="font-size:24px;font-weight:700;color:#065f46;">' + sent    + '</div>' +
                        '<div style="font-size:12px;color:#64748b;margin-top:2px;">✅ Sent (' + pct + '%)</div>' +
                    '</div>'                                                                                  +
                    '<div style="flex:1;padding:16px 20px;text-align:center;border-right:1px solid #e2e8f0;">'  +
                        '<div style="font-size:24px;font-weight:700;color:' + (failed > 0 ? '#dc2626' : '#9ca3af') + ';">' + failed  + '</div>' +
                        '<div style="font-size:12px;color:#64748b;margin-top:2px;">❌ Failed</div>'          +
                    '</div>'                                                                                  +
                    '<div style="flex:1;padding:16px 20px;text-align:center;">'                               +
                        '<div style="font-size:24px;font-weight:700;color:' + (pending > 0 ? '#f59e0b' : '#9ca3af') + ';">' + pending + '</div>' +
                        '<div style="font-size:12px;color:#64748b;margin-top:2px;">⏳ Pending</div>'         +
                    '</div>'
                );

                // Tab counts
                $('#ofast-modal-failed-count').text(failed);
                $('#ofast-modal-pending-count').text(pending);

                // Email body → iframe
                if (bodyB64) {
                    var html = atob(bodyB64);
                    $iframe[0].srcdoc = html;
                } else {
                    $iframe[0].srcdoc = '<p style="padding:20px;font-family:sans-serif;color:#64748b;">No email body stored.</p>';
                }

                // Failed list
                if (failedEmails.length > 0) {
                    $failedList.html('<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;overflow:hidden;">' +
                        failedEmails.map(function(e) {
                            return '<div style="padding:8px 14px;border-bottom:1px solid #fca5a5;font-size:13px;font-family:monospace;color:#991b1b;">' + $('<div>').text(e).html() + '</div>';
                        }).join('') +
                    '</div>');
                } else {
                    $failedList.html('<p style="color:#9ca3af;font-size:13px;">No failed recipients recorded.</p>');
                }

                // Pending list note
                if (pending > 0) {
                    $pendingList.html('<p style="color:#92400e;font-size:13px;background:#fef3c7;padding:12px 16px;border-radius:8px;">' +
                        '⏳ ' + pending + ' recipient(s) are still pending. Check the <strong>Campaigns</strong> tab for live progress.' +
                    '</p>');
                } else {
                    $pendingList.html('<p style="color:#9ca3af;font-size:13px;">No pending recipients.</p>');
                }

                // Reset to body tab
                switchTab('body');
                $modal.fadeIn(200);
            });

            // ── Tab switching ─────────────────────────────────────────────────
            function switchTab(name) {
                $('.ofast-modal-panel').hide();
                $('#ofast-tab-' + name).show();
                $('.ofast-modal-tab').each(function() {
                    var isActive = $(this).data('tab') === name;
                    $(this).css({
                        color:        isActive ? '#6366f1' : '#94a3b8',
                        borderBottom: isActive ? '2px solid #6366f1' : '2px solid transparent',
                        fontWeight:   isActive ? '600' : '400'
                    });
                });
            }

            $(document).on('click', '.ofast-modal-tab', function() {
                switchTab($(this).data('tab'));
            });

            // ── Close modal ───────────────────────────────────────────────────
            $('#ofast-close-modal').on('click', function() {
                $modal.fadeOut(150);
            });

            $modal.on('click', function(e) {
                if ($(e.target).is($modal)) $modal.fadeOut(150);
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') $modal.fadeOut(150);
            });

            // ── Per-page selector ─────────────────────────────────────────────
            $('#ofast-history-per-page').on('change', function() {
                var url = new URL(window.location.href);
                url.searchParams.set('hist_per_page', $(this).val());
                url.searchParams.set('hist_paged', '1');
                window.location.href = url.toString();
            });

            // ── AJAX pagination ───────────────────────────────────────────────
            $(document).on('click', '.ofast-page-btn:not(.disabled)', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                var url  = new URL(window.location.href);
                url.searchParams.set('hist_paged', page);
                window.location.href = url.toString();
            });

        })(jQuery);
        </script>
        <?php
    }

    /**
     * Legacy standalone renderer — now delegates to render().
     */
    public function render_standalone() {
        echo '<div class="wrap">';
        echo '<div class="ofast-header"><div class="ofast-header-icon"><span class="dashicons dashicons-clock"></span></div>';
        echo '<div class="ofast-header-content"><h1>Email History</h1><p>Unified log of all direct sends and bulk campaigns.</p></div></div>';
        $this->render();
        echo '</div>';
    }
}
