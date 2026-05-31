/**
 * Ofast X SMS Channel Admin Scripts
 * Extracted from class-ofast-sms.php
 * Uses ofastSMS localized object for dynamic data.
 */
jQuery(document).ready(function($) {
    // Tab switching (no reload)
    function switchTab(name) {
        $('.ofast-tab').removeClass('active');
        $('.ofast-tab[data-tab="' + name + '"]').addClass('active');
        $('.ofast-tab-content').removeClass('active');
        $('#tab-' + name).addClass('active');
        history.replaceState(null, null, '#' + name);
    }
    $('.ofast-tab[data-tab]').on('click', function(e) {
        e.preventDefault();
        switchTab($(this).data('tab'));
    });
    // Handle hash on load
    var hash = window.location.hash.replace('#', '');
    if (hash && $('#tab-' + hash).length) { switchTab(hash); }

    // Provider card selection
    $('.ofast-provider-card').on('click', function() {
        var provider = $(this).data('provider');
        $('.ofast-provider-card').removeClass('active');
        $(this).addClass('active');
        $(this).find('input[type="radio"]').prop('checked', true);
        
        $('#ofast-provider-placeholder').hide();
        $('.ofast-provider-fields').removeClass('active');
        $('#fields-' + provider).addClass('active');
        // Re-initialize Ofast dropdowns in newly visible fields
        if (typeof window.OfastInitDropdowns === 'function') {
            window.OfastInitDropdowns('#fields-' + provider);
        }
    });

    // Send SMS
    $('#ofast-sms-send-btn').on('click', function() {
        var btn = $(this);
        var recipients = $('#ofast-sms-recipients').val();
        // Get content from WP editor
        var message = '';
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('ofast_sms_message')) {
            message = tinyMCE.get('ofast_sms_message').getContent({format: 'text'});
        } else {
            message = $('#ofast_sms_message').val();
        }

        if (!recipients || !message) {
            ofastToast.error('Please enter recipients and a message.');
            return;
        }

        btn.prop('disabled', true).text('Sending...');

        $.post(ajaxurl, {
            action: 'ofast_sms_send',
            nonce: ofastSMS.nonce,
            recipients: recipients,
            message: message
        }, function(response) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
            if (response.success) {
                ofastToast.success(response.message || 'SMS sent successfully!');
            } else {
                var msg = response.message || response.data || 'Failed to send.';
                if (response.errors && response.errors.length) {
                    msg += ' ' + response.errors.join(', ');
                }
                ofastToast.error(msg);
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send SMS');
            ofastToast.error('Request failed. Please try again.');
        });
    });

    // Test connection
    $('#ofast-sms-test-conn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Testing...');

        $.post(ajaxurl, {
            action: 'ofast_sms_test_connection',
            nonce: ofastSMS.nonce,
            provider: $('input[name="ofast_sms_provider"]:checked').val() || ''
        }, function(response) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:2px;"></span> Test Connection');
            if (response.success) {
                ofastToast.success(response.message || response.data || 'Connection successful!');
            } else {
                ofastToast.error(response.message || response.data || 'Connection failed.');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:2px;"></span> Test Connection');
            ofastToast.error('Request failed. Please try again.');
        });
    });

    // Test SMS
    $('#ofast-sms-test-send').on('click', function() {
        var btn = $(this);
        var phone = $('#ofast-test-phone').val();

        if (!phone) {
            ofastToast.warning('Please enter a phone number.');
            return;
        }

        btn.prop('disabled', true).text('Sending...');

        $.post(ajaxurl, {
            action: 'ofast_sms_test',
            nonce: ofastSMS.nonce,
            phone: phone
        }, function(response) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="margin-top:2px;"></span> Send Test');
            if (response.success) {
                ofastToast.success(response.message || response.data || 'Test SMS sent!');
            } else {
                ofastToast.error(response.message || response.data || 'Failed to send test SMS.');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="margin-top:2px;"></span> Send Test');
            ofastToast.error('Request failed. Please try again.');
        });
    });
});
