/**
 * Ofast X Spam Protection Admin Scripts
 * Extracted from class-ofast-spam-protection.php
 */
jQuery(document).ready(function($) {
    // Tab Switching
    $('.ofast-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('tab');
        
        // Update classes
        $('.ofast-tab').removeClass('active');
        $(this).addClass('active');
        
        $('.ofast-tab-content').removeClass('active');
        $('#tab-' + target).addClass('active');
        
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
        $('.ofast-tab[data-tab="' + tab + '"]').click();
    };
});
