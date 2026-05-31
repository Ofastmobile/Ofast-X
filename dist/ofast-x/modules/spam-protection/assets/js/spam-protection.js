/**
 * Ofast X Spam Protection Admin Scripts
 * Extracted from class-ofast-spam-protection.php
 */
jQuery(document).ready(function($) {
    var $page = $('.ofast-spam-protection-page');

    if (!$page.length) {
        return;
    }

    // Tab Switching
    $page.find('.ofast-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('tab');
        
        // Update classes
        $page.find('.ofast-tab').removeClass('active');
        $(this).addClass('active');
        
        $page.find('.ofast-tab-content').removeClass('active');
        $page.find('#tab-' + target).addClass('active');
        
        // Update URL
        var url = new URL(window.location);
        url.searchParams.set('tab', target);
        window.history.pushState({}, '', url);

        if (typeof window.OfastInitDropdowns === 'function') {
            window.OfastInitDropdowns('#tab-' + target);
        }
    });

    // Handle back button
    window.onpopstate = function() {
        var urlParams = new URLSearchParams(window.location.search);
        var tab = urlParams.get('tab') || 'general';
        $page.find('.ofast-tab[data-tab="' + tab + '"]').click();
    };
});
