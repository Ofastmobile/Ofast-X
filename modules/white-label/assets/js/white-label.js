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
    // Toggle plugin selector visibility
    $('#ofast_disable_plugin_updates').on('change', function () {
        if ($(this).is(':checked')) {
            $('#ofast-plugin-selector').slideDown(200);
        } else {
            $('#ofast-plugin-selector').slideUp(200);
        }
    });

    // Sub-tab switching via sidebar nav
    $('.ofast-nav .ofast-subtab').on('click', function (e) {
        e.preventDefault();
        var target = $(this).data('subtab');
        $('.ofast-nav .ofast-subtab').removeClass('active');
        $(this).addClass('active');
        $('.ofast-subtab-panel').removeClass('active');
        $('.ofast-subtab-panel[data-subtab-panel="' + target + '"]').addClass('active');
        $('.ofast-active-tab').val(target);
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

    // Header arrow toggle for Admin URL card body
    $('#ofast-admin-url-header').on('click', function (e) {
        if ($(e.target).closest('.ofast-toggle, .ofast-toggle-switch, input, label, button, a').length) return;
        var $body = $('#ofast-admin-url-body');
        var $arrow = $('#ofast-admin-url-arrow');
        $body.stop(true, true).slideToggle(200, function () {
            if ($body.is(':visible')) {
                $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            } else {
                $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            }
        });
    });

    // Toggle Admin URL settings visibility
    $('#ofast_enable_admin_url').on('change', function () {
        if ($(this).is(':checked')) {
            $('#ofast-admin-url-body').stop(true, true).slideDown(200);
            $('#ofast-admin-url-arrow').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            $('#ofast-admin-url-settings').slideDown(200);
        } else {
            $('#ofast-admin-url-settings').slideUp(200);
        }
    });

});
