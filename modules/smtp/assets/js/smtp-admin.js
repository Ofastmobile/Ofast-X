/**
 * Ofast X SMTP Admin JavaScript
 * Handles provider presets, test email, tab switching, log AJAX, port test, and settings UI.
 * Extracted and consolidated from class-ofast-smtp-admin.php inline <script> blocks.
 *
 * Localised data (ofastSMTP):
 *   ajaxurl        – wp-admin AJAX endpoint
 *   nonce          – ofast_test_smtp nonce
 *   port_test_nonce – ofast_port_test nonce
 *   presets        – SMTP provider presets object
 *   logPage        – current log page number (integer)
 *   logPerPage     – current per-page setting ('all' or integer string)
 *   logNonce       – ofast_smtp_logs_nonce
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        /* ── PROVIDER PRESETS ─────────────────────────────────── */
        $('#smtp_provider').on('change', function () {
            var provider = $(this).val();
            var presets = ofastSMTP.presets;

            if (presets[provider]) {
                var preset = presets[provider];

                if (provider !== 'custom') {
                    $('#smtp_host').val(preset.host);
                    $('#smtp_port').val(preset.port);
                    $('input[name="smtp_encryption"][value="' + preset.encryption + '"]').prop('checked', true);
                }

                $('#provider_note').text(preset.note);
            }
        });

        /* ── TEST SMTP BUTTON ──────────────────────────────────── */
        $('#test-smtp-btn').on('click', function () {
            var $btn = $(this);
            var $result = $('#test-result');
            var $details = $('#test-details');

            var mailerType = $('#smtp_mailer_type').val() || 'default';

            var formData = {
                action: 'ofast_test_smtp',
                nonce: ofastSMTP.nonce,
                mailer_type: mailerType,
                from_email: $('#smtp_from_email').val(),
                from_name: $('#smtp_from_name').val()
            };

            if (mailerType !== 'default') {
                formData.host = $('#smtp_host').val();
                formData.port = $('#smtp_port').val();
                formData.encryption = $('input[name="smtp_encryption"]:checked').val();
                formData.username = $('#smtp_username').val();
                formData.password = $('#smtp_password').val();

                if (!formData.host || !formData.username || !formData.password || !formData.from_email) {
                    $result.html('<span style="color: #dc2626;">Please fill in all required SMTP fields first.</span>');
                    return;
                }
            }

            $btn.prop('disabled', true).text('Sending test email...');
            var statusMsg = mailerType === 'default' ? 'Sending via PHP Mail...' : 'Connecting to SMTP server...';
            $result.html('<span style="color: #6366f1;">' + statusMsg + '</span>');
            $details.hide();

            $.post(ofastSMTP.ajaxurl, formData, function (response) {
                if (response.success) {
                    $result.html('<span style="color: #10b981; font-weight: bold;">✓ ' + response.data.message + '</span>');

                    var detailsText = 'Connection: SUCCESS\n';
                    if (response.data.details.mailer) {
                        detailsText += 'Mailer: ' + response.data.details.mailer + '\n';
                        detailsText += 'From: ' + response.data.details.from;
                    } else {
                        detailsText += 'Host: ' + response.data.details.host + '\n';
                        detailsText += 'Port: ' + response.data.details.port + '\n';
                        detailsText += 'Encryption: ' + response.data.details.encryption.toUpperCase();
                    }
                    $details.show().find('pre').css('color', '#10b981').text(detailsText);
                } else {
                    var errorMsg = typeof response.data === 'object' ? response.data.message : response.data;
                    var suggestion = response.data.suggestion || '';
                    var errorDetail = response.data.error || '';

                    $result.html('<span style="color: #dc2626; font-weight: bold;">✗ ' + errorMsg + '</span>');
                    $details.show().find('pre').css('color', '#ef4444').text(
                        'Error: ' + errorDetail + '\n\n' +
                        'Suggestion: ' + suggestion
                    );
                }
            }).fail(function () {
                $result.html('<span style="color: #dc2626;">Network error. Please try again.</span>');
            }).always(function () {
                $btn.prop('disabled', false).text('Send Test Email to ' + $btn.data('email'));
            });
        });

        $('#test-smtp-btn').data('email', $('#test-smtp-btn').text().replace('Send Test Email to ', ''));

        /* ── TAB SWITCHING (no page reload) ───────────────────── */
        $('#smtp-tabs-nav .ofast-tab').on('click', function (e) {
            e.preventDefault();

            var tabId = $(this).data('tab');

            $('#smtp-tabs-nav .ofast-tab').removeClass('active');
            $(this).addClass('active');

            $('.ofast-tab-content').removeClass('active').hide();
            $('#smtp-tab-' + tabId).addClass('active').show();

            if (history.pushState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tabId);
                history.pushState({ tab: tabId }, '', url);
            }
        });

        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.tab) {
                var tabId = e.state.tab;
                $('#smtp-tabs-nav .ofast-tab').removeClass('active');
                $('#smtp-tabs-nav .ofast-tab[data-tab="' + tabId + '"]').addClass('active');
                $('.ofast-tab-content').removeClass('active').hide();
                $('#smtp-tab-' + tabId).addClass('active').show();
            }
        });

        /* ── VIDEO EMBED (click to play) ───────────────────────── */
        $('#ofast-inline-video-wrapper').on('click', function () {
            var videoId = $(this).data('video-id');
            
            var $modal = $('<div/>', {
                id: 'ofast-video-modal',
                css: {
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    backgroundColor: 'rgba(0,0,0,0.85)',
                    zIndex: 999999,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer'
                }
            }).appendTo('body');
            
            var $videoContainer = $('<div/>', {
                css: {
                    position: 'relative',
                    width: '90%',
                    maxWidth: '960px',
                    aspectRatio: '16/9',
                    backgroundColor: '#000',
                    boxShadow: '0 25px 50px -12px rgba(0,0,0,0.5)',
                    borderRadius: '8px',
                    overflow: 'hidden',
                    cursor: 'default'
                }
            }).appendTo($modal);

            if (!CSS.supports('aspect-ratio', '16/9')) {
                $videoContainer.css({
                    height: 0,
                    paddingBottom: '56.25%'
                });
            }

            $('<iframe/>', {
                src: 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0',
                frameborder: '0',
                allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
                allowfullscreen: 'true',
                css: {
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%'
                }
            }).appendTo($videoContainer);
            
            var $closeBtn = $('<div/>', {
                html: '&times;',
                css: {
                    position: 'absolute',
                    top: '20px',
                    right: '30px',
                    color: '#fff',
                    fontSize: '40px',
                    fontWeight: 'bold',
                    cursor: 'pointer',
                    zIndex: 10
                }
            }).appendTo($modal);

            $modal.on('click', function(e) {
                if (e.target === this || e.target === $closeBtn[0]) {
                    $modal.fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            });
            
            $modal.hide().fadeIn(300);
        });

        /* ── LOG PAGE – AJAX PAGINATION ────────────────────────── */
        if (typeof ofastSMTP !== 'undefined' && ofastSMTP.logPage !== undefined) {
            var smtpState = {
                page: ofastSMTP.logPage,
                perPage: ofastSMTP.logPerPage,
                nonce: ofastSMTP.logNonce
            };

            function smtpFetchPage(page, perPage) {
                var $tbody = $('#ofast-smtp-log-tbody');
                var $paginationWrap = $('#ofast-smtp-pagination-wrap');
                $tbody.closest('table').addClass('ofast-smtp-loading');
                $paginationWrap.addClass('ofast-smtp-loading');

                $.post(ajaxurl, {
                    action: 'ofast_smtp_fetch_logs',
                    nonce: smtpState.nonce,
                    paged: page,
                    per_page: perPage
                }, function (response) {
                    if (response.success) {
                        $tbody.html(response.data.rows_html);
                        $paginationWrap.html(response.data.pagination_html);
                        smtpState.page = response.data.current_page;
                        smtpState.perPage = perPage;

                        $('#ofast-smtp-per-page').val(perPage);

                        var url = new URL(window.location);
                        url.searchParams.set('paged', response.data.current_page);
                        url.searchParams.set('per_page', perPage);
                        url.searchParams.set('tab', 'log');
                        history.replaceState(null, '', url.toString());

                        smtpBindPreview();
                        smtpBindPagination();
                    }
                    $tbody.closest('table').removeClass('ofast-smtp-loading');
                    $paginationWrap.removeClass('ofast-smtp-loading');
                }).fail(function () {
                    $tbody.closest('table').removeClass('ofast-smtp-loading');
                    $paginationWrap.removeClass('ofast-smtp-loading');
                });
            }

            function smtpBindPagination() {
                $('#ofast-smtp-pagination-wrap').off('click', '.ofast-page-btn').on('click', '.ofast-page-btn', function (e) {
                    e.preventDefault();
                    if ($(this).hasClass('disabled') || $(this).hasClass('active')) return;
                    var page = $(this).data('page');
                    if (page) smtpFetchPage(page, smtpState.perPage);
                });
            }

            function smtpBindPreview() {
                $('#ofast-smtp-log-tbody').off('click', '.preview-email').on('click', '.preview-email', function () {
                    var content = atob($(this).data('content'));
                    $('#email-preview-frame').remove();
                    var iframe = $('<iframe id="email-preview-frame" style="width: 100%; height: 60vh; border: none;"></iframe>');
                    iframe.attr('srcdoc', content);
                    $('#email-preview-modal .ofast-smtp-modal-body').append(iframe);
                    $('#email-preview-modal').fadeIn(200);
                });
            }

            $('#ofast-smtp-per-page').on('change', function () {
                smtpFetchPage(1, $(this).val());
            });

            smtpBindPagination();
            smtpBindPreview();

            $('#close-preview, #email-preview-modal').on('click', function (e) {
                if (e.target === this || $(this).attr('id') === 'close-preview') {
                    $('#email-preview-modal').fadeOut(200);
                    $('#email-preview-frame').remove();
                }
            });
        }

        /* ── SETTINGS – ENCRYPTION SEGMENTED CONTROL ──────────── */
        $('.ofast-encryption-group label').on('click', function () {
            $(this).closest('.ofast-encryption-group').find('label').removeClass('active');
            $(this).addClass('active');
        });

        /* ── SETTINGS – MAILER TYPE TOGGLE ────────────────────── */
        $('#smtp_mailer_type').on('change', function () {
            var isSmtp = $(this).val() === 'smtp';
            $('#smtp-credentials-section').toggle(isSmtp);
            $('#rate-limit-section').toggle(isSmtp);
            $('#mailer_note').text(isSmtp
                ? 'Requires SMTP server credentials. Better deliverability with providers like SendGrid, Mailgun.'
                : 'Uses your server\'s built-in mail function. Only From Email/Name needed. Best for most hosts.');
        });

        $('#fallback_enabled').on('change', function () {
            $('#fallback-smtp-fields').toggle(this.checked);
        });

        $('input[name="health_report_enabled"]').on('change', function () {
            $('select[name="health_report_interval"]').closest('tr').toggle(this.checked);
        });

        /* ── SETTINGS – PORT CONNECTIVITY TEST ─────────────────── */
        $('#ofast-run-port-test').on('click', function () {
            var hostname = $('#port-test-hostname').val().trim();
            if (!hostname) { alert('Enter a hostname first'); return; }

            var $btn = $(this);
            var $spinner = $('#port-test-spinner');
            var $results = $('#port-test-results');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $results.hide();

            $.post(ajaxurl, {
                action: 'ofast_smtp_port_test',
                nonce: ofastSMTP.port_test_nonce,
                hostname: hostname,
                ports: [25, 465, 587]
            }, function (response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                if (!response.success) {
                    $results.html('<div style="padding:12px;background:#fee2e2;border-radius:8px;color:#991b1b;">' + response.data + '</div>').show();
                    return;
                }

                var portLabels = { 25: 'Port 25 (Plain)', 465: 'Port 465 (SSL)', 587: 'Port 587 (TLS)' };
                var portOrder = [587, 465, 25];
                var tabs = '';
                var panels = {};
                var firstPort = null;

                $.each(portOrder, function (i, port) {
                    var r = response.data.results[port];
                    if (!r) return;
                    if (!firstPort) firstPort = port;

                    var statusDot = r.open
                        ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:6px;"></span>'
                        : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-right:6px;"></span>';
                    var isActive = (port === firstPort);

                    tabs += '<button type="button" class="port-tab" data-port="' + port + '" style="padding: 10px 20px; border: none; background: ' + (isActive ? '#f8fafc' : 'transparent') + '; cursor: pointer; font-weight: ' + (isActive ? '600' : '400') + '; font-size: 13px; color: ' + (isActive ? '#6366f1' : '#64748b') + '; border-bottom: 2px solid ' + (isActive ? '#6366f1' : 'transparent') + '; margin-bottom: -2px; transition: all 0.2s;">' + statusDot + (portLabels[port] || 'Port ' + port) + '</button>';

                    var panel = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
                    panel += '<div style="background: ' + (r.open ? '#f0fdf4' : '#fef2f2') + '; border: 1px solid ' + (r.open ? '#bbf7d0' : '#fecaca') + '; border-radius: 10px; padding: 16px;">';
                    panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Status</div>';
                    panel += '<div style="font-size: 18px; font-weight: 700; color: ' + (r.open ? '#059669' : '#dc2626') + ';">' + (r.open ? 'Open' : 'Closed') + '</div>';
                    panel += '</div>';

                    if (r.open) {
                        panel += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">';
                        panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Security</div>';
                        var secItems = [];
                        if (r.secure) secItems.push('🔒 Encrypted');
                        if (r.starttls) secItems.push('↗ STARTTLS');
                        if (!r.secure && !r.starttls) secItems.push('⚠ No encryption');
                        panel += '<div style="font-weight: 600;">' + secItems.join(' &nbsp;·&nbsp; ') + '</div>';
                        panel += '</div>';

                        var auths = [];
                        if (r.auth_login) auths.push('LOGIN');
                        if (r.auth_plain) auths.push('PLAIN');
                        if (r.auth_crammd5) auths.push('CRAM-MD5');
                        if (r.auth_xoauth) auths.push('XOAUTH2');
                        if (auths.length) {
                            panel += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">';
                            panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Auth Methods</div>';
                            panel += '<div style="display: flex; gap: 6px; flex-wrap: wrap;">';
                            $.each(auths, function (j, a) {
                                panel += '<span style="background: #eef2ff; color: #4338ca; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;">' + a + '</span>';
                            });
                            panel += '</div></div>';
                        }

                        if (r.mitm) {
                            panel += '<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 16px;">';
                            panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #92400e; margin-bottom: 8px;">⚠ MITM Warning</div>';
                            panel += '<div style="color: #92400e; font-weight: 500;">' + (r.mitm_detail || 'Certificate hostname mismatch detected') + '</div>';
                            panel += '</div>';
                        }
                    } else if (r.error) {
                        panel += '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 16px;">';
                        panel += '<div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">Error</div>';
                        panel += '<div style="color: #991b1b; font-size: 13px;">' + r.error.substring(0, 120) + '</div>';
                        panel += '</div>';
                    }
                    panel += '</div>';
                    panels[port] = panel;
                });

                $('#port-tabs').html(tabs);
                $('#port-tab-content').html(panels[firstPort] || '');
                $results.show();

                $('#port-tabs').off('click', '.port-tab').on('click', '.port-tab', function () {
                    var port = $(this).data('port');
                    $('#port-tabs .port-tab').css({ background: 'transparent', fontWeight: '400', color: '#64748b', borderBottom: '2px solid transparent' });
                    $(this).css({ background: '#f8fafc', fontWeight: '600', color: '#6366f1', borderBottom: '2px solid #6366f1' });
                    $('#port-tab-content').html(panels[port] || '');
                });
            });
        });

    });

})(jQuery);
