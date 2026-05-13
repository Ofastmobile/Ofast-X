/**
 * Ofast X SMTP Admin JavaScript
 * Handles provider presets and test email
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Provider selection - auto-fill settings
        $('#smtp_provider').on('change', function () {
            var provider = $(this).val();
            var presets = ofastSMTP.presets;

            if (presets[provider]) {
                var preset = presets[provider];

                // Only fill if switching to a preset (not custom)
                if (provider !== 'custom') {
                    $('#smtp_host').val(preset.host);
                    $('#smtp_port').val(preset.port);
                    $('input[name="smtp_encryption"][value="' + preset.encryption + '"]').prop('checked', true);
                }

                // Update provider note
                $('#provider_note').text(preset.note);
            }
        });

        // Test SMTP button
        $('#test-smtp-btn').on('click', function () {
            var $btn = $(this);
            var $result = $('#test-result');
            var $details = $('#test-details');

            // Get mailer type (defaults to 'default' if not found)
            var mailerType = $('#smtp_mailer_type').val() || 'default';

            // Gather form data
            var formData = {
                action: 'ofast_test_smtp',
                nonce: ofastSMTP.nonce,
                mailer_type: mailerType,
                from_email: $('#smtp_from_email').val(),
                from_name: $('#smtp_from_name').val()
            };

            // For SMTP mode, add and validate SMTP fields
            if (mailerType !== 'default') {
                formData.host = $('#smtp_host').val();
                formData.port = $('#smtp_port').val();
                formData.encryption = $('input[name="smtp_encryption"]:checked').val();
                formData.username = $('#smtp_username').val();
                formData.password = $('#smtp_password').val();

                // Validate SMTP fields
                if (!formData.host || !formData.username || !formData.password || !formData.from_email) {
                    $result.html('<span style="color: #dc2626;">Please fill in all required SMTP fields first.</span>');
                    return;
                }
            }

            // UI feedback
            $btn.prop('disabled', true).text('Sending test email...');
            var statusMsg = mailerType === 'default' ? 'Sending via PHP Mail...' : 'Connecting to SMTP server...';
            $result.html('<span style="color: #6366f1;">' + statusMsg + '</span>');
            $details.hide();

            $.post(ofastSMTP.ajaxurl, formData, function (response) {
                if (response.success) {
                    $result.html('<span style="color: #10b981; font-weight: bold;">✓ ' + response.data.message + '</span>');

                    // Show details based on response
                    var detailsText = 'Connection: SUCCESS\n';
                    if (response.data.details.mailer) {
                        // PHP Mail Default mode
                        detailsText += 'Mailer: ' + response.data.details.mailer + '\n';
                        detailsText += 'From: ' + response.data.details.from;
                    } else {
                        // SMTP mode
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

        // Store admin email on button for later use
        $('#test-smtp-btn').data('email', $('#test-smtp-btn').text().replace('Send Test Email to ', ''));
    });

})(jQuery);
