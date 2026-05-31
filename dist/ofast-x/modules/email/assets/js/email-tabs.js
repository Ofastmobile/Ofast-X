/**
 * Ofast Emailer - Tab Switching
 */
jQuery(document).ready(function ($) {
    // Tab Switching (only tabs with data-tab, not external links)
    $('.ofast-tabs-nav .ofast-tab[data-tab]').on('click', function (e) {
        e.preventDefault();
        var target = $(this).data('tab');

        // Update tab classes
        $('.ofast-tabs-nav .ofast-tab').removeClass('active');
        $(this).addClass('active');

        // Update content visibility
        $('.ofast-tab-content').removeClass('active');
        $('#tab-' + target).addClass('active');

        // Update URL without page reload
        var url = new URL(window.location);
        url.searchParams.set('tab', target);
        window.history.pushState({}, '', url);
    });

    // Handle browser back/forward buttons
    window.onpopstate = function () {
        var urlParams = new URLSearchParams(window.location.search);
        var tab = urlParams.get('tab') || 'send';
        $('.ofast-tabs-nav .ofast-tab[data-tab="' + tab + '"]').click();
    };
});
