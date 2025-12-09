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

            // Gather form data
            var formData = {
                action: 'ofast_test_smtp',
                nonce: ofastSMTP.nonce,
                host: $('#smtp_host').val(),
                port: $('#smtp_port').val(),
                encryption: $('input[name="smtp_encryption"]:checked').val(),
                username: $('#smtp_username').val(),
                password: $('#smtp_password').val(),
                from_email: $('#smtp_from_email').val(),
                from_name: $('#smtp_from_name').val()
            };

            // Validate
            if (!formData.host || !formData.username || !formData.password || !formData.from_email) {
                $result.html('<span style="color: #dc2626;">Please fill in all required fields first.</span>');
                return;
            }

            // UI feedback
            $btn.prop('disabled', true).text('Sending test email...');
            $result.html('<span style="color: #6366f1;">Connecting to SMTP server...</span>');
            $details.hide();

            $.post(ofastSMTP.ajaxurl, formData, function (response) {
                if (response.success) {
                    $result.html('<span style="color: #10b981; font-weight: bold;">✓ ' + response.data.message + '</span>');
                    $details.show().find('pre').text(
                        'Connection: SUCCESS\n' +
                        'Host: ' + response.data.details.host + '\n' +
                        'Port: ' + response.data.details.port + '\n' +
                        'Encryption: ' + response.data.details.encryption.toUpperCase()
                    );
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
