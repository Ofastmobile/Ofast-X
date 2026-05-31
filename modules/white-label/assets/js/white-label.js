/* White Label admin page interactions. */
(function () {
    if (!window.URL || !window.history || !window.history.replaceState) {
        return;
    }

    var url = new URL(window.location);
    if (url.searchParams.has('settings_saved') || url.searchParams.has('settings_reset')) {
        url.searchParams.delete('settings_saved');
        url.searchParams.delete('settings_reset');
        window.history.replaceState({}, '', url.toString());
    }
})();
jQuery(document).ready(function ($) {
    // Live preview updates for Designer Details
    $('#designer_name').on('input', function () {
        $('#preview-name').text($(this).val() || 'Your Name');
    });
    $('#designer_email').on('input', function () {
        var val = $(this).val() || 'hello@example.com';
        $('#preview-email').text(val).attr('href', 'mailto:' + val);
    });
    $('#designer_website').on('input', function () {
        var val = $(this).val() || 'https://example.com';
        $('#preview-website').text(val).attr('href', val);
    });

    // Live preview for Footer
    $('#footer_left_text').on('input', function () {
        var text = $(this).val() || '<em>Thank you for creating with WordPress.</em>';
        $('#preview-left').html(text);
    });
    $('#footer_right_text').on('input', function () {
        var defaultRight = (window.ofastWhiteLabel && window.ofastWhiteLabel.defaultRight) || '';
        var text = $(this).val() || defaultRight;
        $('#preview-right').html(text || '<em>Version X.X</em>');
    });
    $('input[name="hide_wp_version"]').on('change', function () {
        if ($(this).is(':checked') && !$('#footer_right_text').val()) {
            $('#preview-right').html('');
        } else if (!$('#footer_right_text').val()) {
            $('#preview-right').html('<em>Version X.X</em>');
        }
    });
    // Toggle plugin selector visibility
    $('#ofast_disable_plugin_updates').on('change', function () {
        if ($(this).is(':checked')) {
            $('#ofast-plugin-selector').slideDown(200);
        } else {
            $('#ofast-plugin-selector').slideUp(200);
        }
    });

    // Sub-tab switching for Updates tab
    $('.ofast-subtab-nav .ofast-subtab').on('click', function () {
        var target = $(this).data('subtab');
        $('.ofast-subtab-nav .ofast-subtab').removeClass('active');
        $(this).addClass('active');
        $('.ofast-subtab-panel').removeClass('active');
        $('.ofast-subtab-panel[data-subtab-panel="' + target + '"]').addClass('active');
    });

    // Header arrow toggle for Menu Editor card body
    $('#ofast-menu-editor-header').on('click', function (e) {
        if ($(e.target).closest('.ofast-toggle, .ofast-toggle-switch, input').length) return;
        var $body = $('#ofast-menu-editor-body');
        var $arrow = $('#ofast-menu-editor-arrow');
        $body.slideToggle(200, function () {
            if ($body.is(':visible')) {
                $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            } else {
                $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            }
        });
    });

    // Header arrow toggle for Page Protection card body
    $('#ofast-page-protection-header').on('click', function (e) {
        if ($(e.target).closest('.ofast-toggle, .ofast-toggle-switch, input, label, button, a').length) return;
        var $body = $('#ofast-page-protection-body');
        var $arrow = $('#ofast-page-protection-arrow');
        $body.stop(true, true).slideToggle(200, function () {
            if ($body.is(':visible')) {
                $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            } else {
                $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            }
        });
    });

    // Plugin search filter
    $('#ofast-plugin-search').on('input', function () {
        var query = $(this).val().toLowerCase();
        $('.ofast-plugin-item').each(function () {
            var name = $(this).data('plugin-name');
            $(this).toggleClass('ofast-hidden', name.indexOf(query) === -1);
        });
    });

    // Update selected count
    function updateSelectedCount() {
        var count = $('.ofast-plugin-item input:checked').length;
        $('#ofast-selected-num').text(count);
    }
    $('.ofast-plugin-item input').on('change', updateSelectedCount);

    // Select all / deselect all
    $('#ofast-select-all').on('click', function () {
        $('.ofast-plugin-item:not(.ofast-hidden) input').prop('checked', true);
        updateSelectedCount();
    });
    $('#ofast-deselect-all').on('click', function () {
        $('.ofast-plugin-item:not(.ofast-hidden) input').prop('checked', false);
        updateSelectedCount();
    });

    // Toggle page protection settings visibility
    $('#ofast_page_protection_enabled').on('change', function () {
        if ($(this).is(':checked')) {
            $('#ofast-page-protection-body').stop(true, true).slideDown(200);
            $('#ofast-page-protection-arrow').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            $('#ofast-page-protection-settings').slideDown(200);
        } else {
            $('#ofast-page-protection-settings').slideUp(200);
        }
    });

    // Page search filter
    $('#ofast-page-search').on('input', function () {
        var query = $(this).val().toLowerCase();
        $('.ofast-page-item').each(function () {
            var name = $(this).data('plugin-name');
            $(this).toggleClass('ofast-hidden', name.indexOf(query) === -1);
        });
    });

    // Page selected count
    function updatePageSelectedCount() {
        var count = $('.ofast-page-item input:checked').length;
        $('#ofast-page-selected-num').text(count);
    }
    $('.ofast-page-item input').on('change', updatePageSelectedCount);

    // Page select all / deselect all
    $('#ofast-page-select-all').on('click', function () {
        $('.ofast-page-item:not(.ofast-hidden) input').prop('checked', true);
        updatePageSelectedCount();
    });
    $('#ofast-page-deselect-all').on('click', function () {
        $('.ofast-page-item:not(.ofast-hidden) input').prop('checked', false);
        updatePageSelectedCount();
    });
});
