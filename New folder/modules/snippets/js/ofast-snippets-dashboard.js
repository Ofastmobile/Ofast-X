/**
 * Ofast X Snippets Dashboard Widget JavaScript
 * Uses ofastSnippetsDashboard object for localized data/nonces
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Toggle snippet from dashboard widget
        $(document).on('click', '.ofast-snippet-toggle', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');
            var active = $btn.data('active');
            var hasError = $btn.data('has-error');

            // Prevent activation if has errors
            if (active == 0 && hasError == 1) {
                if (typeof Ofast_X_Toast !== 'undefined') {
                    Ofast_X_Toast.show('Cannot activate this snippet: it contains syntax errors. Please fix the errors first from the Code Snippets management page.', 'error');
                } else {
                    alert('Cannot activate this snippet: it contains syntax errors.\n\nPlease fix the errors first from the Code Snippets management page.');
                }
                return;
            }

            $btn.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'ofast_toggle_snippet',
                nonce: ofastSnippetsDashboard.nonces.toggle,
                id: id,
                active: active
            }, function (response) {
                if (response.success) {
                    var newActive = response.data.active;
                    $btn.data('active', newActive);
                    $btn.html(newActive ? 'ON' : 'OFF');
                    $btn.toggleClass('button-primary', newActive);
                } else {
                    if (typeof Ofast_X_Toast !== 'undefined') {
                        Ofast_X_Toast.show('Error: ' + response.data, 'error');
                    } else {
                        alert('Error: ' + response.data);
                    }
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });

})(jQuery);
