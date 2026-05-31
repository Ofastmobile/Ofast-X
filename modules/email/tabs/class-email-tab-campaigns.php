<?php

/**
 * Email Tab: Campaigns
 *
 * Displays the active queue of email campaigns with live progress bars,
 * and allows admins to pause, resume, or cancel campaigns.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ofast_Email_Tab_Campaigns {

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'ofast-x' ) );
        }

        require_once OFAST_X_PLUGIN_DIR . 'modules/email/class-ofast-email-campaign.php';

        $result = Ofast_Email_Campaign::get_all( array( 'per_page' => 20 ) );
        $campaigns = $result['items'];
        $total     = $result['total'];

        // Run table upgrade inline to catch old-schema tables immediately.
        // Uses a version-keyed transient so it runs once per plugin version.
        $schema_key = 'ofast_campaigns_schema_v' . str_replace( '.', '_', OFAST_X_VERSION );
        if ( ! get_transient( $schema_key ) ) {
            global $wpdb;
            $t = $wpdb->prefix . 'ofast_email_campaigns';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) ) {
                $cols    = $wpdb->get_col( "DESCRIBE {$t}", 0 );
                $needed  = array( 'strategy', 'total', 'sent', 'failed', 'position', 'recipient_ids', 'lock_expires', 'next_run', 'created_by', 'started_at', 'completed_at' );
                $missing = array_diff( $needed, $cols );
                if ( ! empty( $missing ) ) {
                    require_once OFAST_X_PLUGIN_DIR . 'includes/core/class-ofast-activator.php';
                    Ofast_X_Activator::run_upgrade_tables();
                }
            }
            set_transient( $schema_key, 1, WEEK_IN_SECONDS );
        }


        $progress_nonce = wp_create_nonce( 'ofast_campaign_progress' );
        $action_nonce   = wp_create_nonce( 'ofast_campaign_action' );
        ?>

        <div id="ofast-campaigns-tab">

            <!-- Header bar -->
            <div class="ofast-campaigns-header">
                <div class="ofast-campaigns-header-left">
                    <span class="dashicons dashicons-rss" style="font-size:22px;width:22px;height:22px;color:#6366f1;"></span>
                    <div>
                        <h2 style="margin:0;font-size:16px;font-weight:700;color:#1e293b;">Active Campaigns</h2>
                        <p style="margin:0;font-size:13px;color:#64748b;"><?php echo esc_html( $total ); ?> campaign<?php echo $total !== 1 ? 's' : ''; ?> total</p>
                    </div>
                </div>
                <button type="button" id="ofast-refresh-campaigns" class="button button-secondary" style="display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-top:3px;"></span>
                    Refresh
                </button>
            </div>

            <?php if ( empty( $campaigns ) ) : ?>
                <!-- Empty state -->
                <div class="ofast-campaigns-empty">
                    <div class="ofast-campaigns-empty-icon">📭</div>
                    <h3>No campaigns yet</h3>
                    <p>When you send to more than 50 recipients, your campaign will appear here with a live progress bar.</p>
                    <a href="<?php echo admin_url( 'admin.php?page=ofast-emailer' ); ?>" class="button button-primary">
                        <span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;margin-top:3px;"></span>
                        Compose Email
                    </a>
                </div>
            <?php else : ?>

                <div class="ofast-campaigns-list" id="ofast-campaigns-list">
                    <?php foreach ( $campaigns as $campaign ) :
                        $camp_status   = $campaign->status   ?? 'queued';
                        $camp_strategy = property_exists( $campaign, 'strategy' ) ? $campaign->strategy : 'rapid';
                        $camp_sent     = (int) ( $campaign->sent   ?? 0 );
                        $camp_total    = (int) ( property_exists( $campaign, 'total' ) ? $campaign->total : ( $campaign->total_recipients ?? 0 ) );
                        $camp_failed   = (int) ( $campaign->failed  ?? 0 );
                        $camp_next     = $campaign->next_run ?? '';
                        $progress      = Ofast_Email_Campaign::get_progress( $campaign );
                        $is_active     = in_array( $camp_status, array( 'queued', 'processing' ), true );
                        $status_class  = 'ofast-status-' . esc_attr( $camp_status );
                        $strategy_label = $camp_strategy === 'rapid' ? '⚡ SMTP Rapid' : '🐢 PHP Mail (Slow)';
                        $strategy_class = $camp_strategy === 'rapid' ? 'ofast-strategy-rapid' : 'ofast-strategy-slow';
                    ?>
                        <div class="ofast-campaign-card <?php echo $status_class; ?>" id="ofast-campaign-<?php echo esc_attr( $campaign->id ); ?>" data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>" data-active="<?php echo $is_active ? '1' : '0'; ?>">

                        <!-- Card Top Row -->
                        <div class="ofast-campaign-top">
                            <div class="ofast-campaign-info">
                                <div class="ofast-campaign-subject"><?php echo esc_html( wp_trim_words( $campaign->subject, 10, '…' ) ); ?></div>
                                <div class="ofast-campaign-meta">
                                    <span class="ofast-campaign-date"><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $campaign->created_at ) ) ); ?></span>
                                    <span class="ofast-campaign-strategy <?php echo esc_attr( $strategy_class ); ?>"><?php echo esc_html( $strategy_label ); ?></span>
                                </div>
                            </div>
                            <div class="ofast-campaign-status-badge <?php echo esc_attr( $status_class ); ?>">
                                <?php echo esc_html( ucfirst( $camp_status ) ); ?>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="ofast-campaign-progress-wrap">
                            <div class="ofast-campaign-progress-bar">
                                <div class="ofast-campaign-progress-fill <?php echo $is_active ? 'ofast-progress-animated' : ''; ?>"
                                     id="ofast-progress-fill-<?php echo esc_attr( $campaign->id ); ?>"
                                     style="width: <?php echo esc_attr( $progress ); ?>%;">
                                </div>
                            </div>
                            <div class="ofast-campaign-progress-labels">
                                <span class="ofast-progress-count" id="ofast-progress-count-<?php echo esc_attr( $campaign->id ); ?>">
                                    <?php echo esc_html( $camp_sent ); ?> / <?php echo esc_html( $camp_total ); ?> sent
                                    <?php if ( $camp_failed > 0 ) : ?>
                                        <span class="ofast-failed-badge"><?php echo esc_html( $camp_failed ); ?> failed</span>
                                    <?php endif; ?>
                                </span>
                                <span class="ofast-progress-pct" id="ofast-progress-pct-<?php echo esc_attr( $campaign->id ); ?>">
                                    <?php echo esc_html( $progress ); ?>%
                                </span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="ofast-campaign-actions">
                            <?php if ( $camp_status === 'queued' || $camp_status === 'processing' ) : ?>
                                <button type="button"
                                    class="button button-secondary ofast-campaign-btn"
                                    data-action="pause"
                                    data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>"
                                    data-nonce="<?php echo esc_attr( $action_nonce ); ?>">
                                    <span class="dashicons dashicons-controls-pause" style="font-size:13px;width:13px;height:13px;"></span> Pause
                                </button>
                            <?php elseif ( $camp_status === 'paused' ) : ?>
                                <button type="button"
                                    class="button button-primary ofast-campaign-btn"
                                    data-action="resume"
                                    data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>"
                                    data-nonce="<?php echo esc_attr( $action_nonce ); ?>">
                                    <span class="dashicons dashicons-controls-play" style="font-size:13px;width:13px;height:13px;"></span> Resume
                                </button>
                            <?php endif; ?>

                            <?php if ( ! in_array( $camp_status, array( 'completed', 'cancelled' ), true ) ) : ?>
                                <button type="button"
                                    class="button ofast-campaign-btn ofast-btn-cancel"
                                    data-action="cancel"
                                    data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>"
                                    data-nonce="<?php echo esc_attr( $action_nonce ); ?>"
                                    onclick="return confirm('Cancel this campaign? Emails already sent will not be recalled.');">
                                    <span class="dashicons dashicons-no-alt" style="font-size:13px;width:13px;height:13px;"></span> Cancel
                                </button>
                            <?php endif; ?>

                            <?php if ( $camp_status === 'completed' ) : ?>
                                <span class="ofast-campaign-done-badge">✅ Completed &mdash; <?php echo esc_html( $camp_sent ); ?> sent</span>
                            <?php elseif ( $camp_status === 'cancelled' ) : ?>
                                <span class="ofast-campaign-done-badge ofast-cancelled-badge">❌ Cancelled</span>
                            <?php elseif ( $camp_status === 'failed' ) : ?>
                                <span class="ofast-campaign-done-badge ofast-failed-text">⚠️ Failed &mdash; check SMTP logs</span>
                            <?php endif; ?>

                            <?php if ( in_array( $camp_status, array( 'completed', 'cancelled', 'failed' ), true ) ) : ?>
                                <button type="button"
                                    class="button ofast-campaign-btn ofast-btn-delete"
                                    data-action="delete"
                                    data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>"
                                    data-nonce="<?php echo esc_attr( $action_nonce ); ?>"
                                    onclick="return confirm('Permanently delete this campaign record?');">
                                    <span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;"></span> Delete
                                </button>
                            <?php endif; ?>

                            <?php if ( $camp_strategy === 'slow' && in_array( $camp_status, array( 'queued', 'paused' ), true ) && ! empty( $camp_next ) ) : ?>
                                <span class="ofast-next-run-info">
                                    ⏱ Next batch: <?php echo esc_html( date_i18n( 'M j g:i a', strtotime( $camp_next ) ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

        <script type="text/javascript">
        (function($) {
            var ajaxUrl        = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var progressNonce  = '<?php echo esc_js( $progress_nonce ); ?>';
            var pollIntervals  = {};

            // ── Auto-poll active campaigns ──────────────────────────────
            function pollCampaign(campaignId) {
                if (pollIntervals[campaignId]) return; // already polling

                pollIntervals[campaignId] = setInterval(function() {
                    $.post(ajaxUrl, {
                        action:      'ofast_campaign_progress',
                        nonce:       progressNonce,
                        campaign_id: campaignId
                    }, function(response) {
                        if (!response.success) return;

                        var data      = response.data;
                        var pct       = data.progress;
                        var $card     = $('#ofast-campaign-' + campaignId);
                        var isActive  = data.status === 'queued' || data.status === 'processing';

                        // Update progress bar
                        $('#ofast-progress-fill-'  + campaignId).css('width', pct + '%');
                        $('#ofast-progress-pct-'   + campaignId).text(pct + '%');
                        $('#ofast-progress-count-' + campaignId).text(data.sent + ' / ' + data.total + ' sent');

                        // Update status badge
                        $card.find('.ofast-campaign-status-badge')
                            .text(data.status.charAt(0).toUpperCase() + data.status.slice(1))
                            .attr('class', 'ofast-campaign-status-badge ofast-status-' + data.status);

                        // Stop polling when done
                        if (!isActive) {
                            clearInterval(pollIntervals[campaignId]);
                            delete pollIntervals[campaignId];
                            $('#ofast-progress-fill-' + campaignId).removeClass('ofast-progress-animated');

                            // Reload list after 2s so action buttons update
                            setTimeout(function() { location.reload(); }, 2000);
                        }
                    });
                }, 4000); // poll every 4 seconds
            }

            // Start polling all active campaigns on page load
            $('.ofast-campaign-card[data-active="1"]').each(function() {
                pollCampaign($(this).data('campaign-id'));
            });

            // ── Pause / Resume / Cancel buttons ─────────────────────────
            $(document).on('click', '.ofast-campaign-btn', function() {
                var $btn       = $(this);
                var action     = $btn.data('action');
                var campaignId = $btn.data('campaign-id');
                var nonce      = $btn.data('nonce');

                $btn.prop('disabled', true).addClass('ofast-btn-loading');

                // Delete uses a separate AJAX action
                var ajaxAction = action === 'delete' ? 'ofast_campaign_delete' : 'ofast_campaign_action';

                $.post(ajaxUrl, {
                    action:          ajaxAction,
                    nonce:           nonce,
                    campaign_id:     campaignId,
                    campaign_action: action
                }, function(response) {
                    if (response.success) {
                        if (action === 'delete') {
                            // Animate card out then remove
                            $('#ofast-campaign-' + campaignId).animate({ opacity: 0, height: 0 }, 300, function() {
                                $(this).remove();
                                // Show empty state if none left
                                if ($('.ofast-campaign-card').length === 0) {
                                    location.reload();
                                }
                            });
                        } else {
                            location.reload();
                        }
                    } else {
                        alert('Action failed: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).removeClass('ofast-btn-loading');
                    }
                }).fail(function() {
                    alert('Request failed. Please try again.');
                    $btn.prop('disabled', false).removeClass('ofast-btn-loading');
                });
            });

            // ── Manual refresh button ────────────────────────────────────
            $('#ofast-refresh-campaigns').on('click', function() {
                location.reload();
            });

        })(jQuery);
        </script>
        <?php
    }
}
