/**
 * Ofast X - Redirects Manager Script
 */
(function ($) {
    'use strict';

    $(function () {
        // Toggle redirect
        $(document).on('click', '.ofast-redirect-toggle', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');
            var active = $btn.data('active');

            $btn.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'ofast_toggle_redirect',
                nonce: ofastRedirects.toggleNonce,
                id: id,
                active: active
            }, function (response) {
                if (response.success) {
                    var newActive = response.data.active;
                    $btn.data('active', newActive);
                    $btn.text(newActive ? ofastRedirects.i18n.on : ofastRedirects.i18n.off);
                    $btn.toggleClass('button-primary', newActive == 1);
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Delete redirect
        $(document).on('click', '.ofast-redirect-delete', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');

            if (!confirm(ofastRedirects.i18n.confirmDelete)) {
                return;
            }

            $.post(ajaxurl, {
                action: 'ofast_delete_redirect',
                nonce: ofastRedirects.deleteNonce,
                id: id
            }, function (response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(function () {
                        $(this).remove();
                    });
                }
            });
        });

        // Import from plugin
        $(document).on('click', '.import-from-plugin', function () {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var originalText = $btn.text();

            $btn.prop('disabled', true).text(ofastRedirects.i18n.importing);

            $.post(ajaxurl, {
                action: 'ofast_import_redirects_from_plugin',
                nonce: ofastRedirects.importNonce,
                plugin: plugin
            }, function (response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.imported > 0) {
                        location.reload();
                    }
                } else {
                    alert(ofastRedirects.i18n.importFailed + response.data);
                }
            }).always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
        });

        // Import from JSON file
        $('#import-json-file').on('change', function (e) {
            var file = e.target.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (e) {
                $.post(ajaxurl, {
                    action: 'ofast_import_redirects_from_plugin',
                    nonce: ofastRedirects.importNonce,
                    plugin: 'json',
                    json_data: e.target.result
                }, function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        if (response.data.imported > 0) {
                            location.reload();
                        }
                    } else {
                        alert(ofastRedirects.i18n.importFailed + response.data);
                    }
                });
            };
            reader.readAsText(file);
        });

        // Export redirects
        $('#export-redirects').on('click', function () {
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.prop('disabled', true).text(ofastRedirects.i18n.exporting);

            // Collect selected IDs
            var selectedIds = [];
            $('.redirect-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
            });

            $.post(ajaxurl, {
                action: 'ofast_export_redirects',
                nonce: ofastRedirects.exportNonce,
                ids: selectedIds
            }, function (response) {
                if (response.success) {
                    var blob = new Blob([JSON.stringify(response.data, null, 2)], {
                        type: 'application/json'
                    });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'ofast-redirects-' + new Date().toISOString().split('T')[0] + '.json';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            }).always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
        });

        // Select all
        $('#select-all-redirects').on('change', function () {
            $('.redirect-checkbox').prop('checked', $(this).prop('checked'));
        });
    });
})(jQuery);
