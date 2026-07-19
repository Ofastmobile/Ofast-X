jQuery(document).ready(function ($) {

    // Tab Switching
    $('.ofast-studio-tab').on('click', function () {
        // Ignore click if searching
        if ($('.ofast-studio-wrapper').hasClass('searching')) return;

        var target = $(this).data('target');

        // Update Sidebar
        $('.ofast-studio-tab').removeClass('active');
        $(this).addClass('active');

        // Update Content
        $('.ofast-tab-content').removeClass('active');
        $('#' + target).addClass('active');

        // Save active tab to localStorage (optional)
        localStorage.setItem('ofast_admin_tweaks_tab', target);
    });

    // Restore active tab
    var savedTab = localStorage.getItem('ofast_admin_tweaks_tab');
    if (savedTab && $('#' + savedTab).length > 0) {
        $('.ofast-studio-tab[data-target="' + savedTab + '"]').click();
    }

    // Toggle Admin Design settings visibility
    $('#ofast_enable_admin_design').on('change', function () {
        if ($(this).is(':checked')) {
            $('#admin-design-settings').slideDown(300);
        } else {
            $('#admin-design-settings').slideUp(300);
        }
    });

    // Toggle custom greeting input visibility
    $('#ofast_custom_greeting_enabled').on('change', function () {
        if ($(this).is(':checked')) {
            $('#ofast-custom-greeting-row').slideDown(200);
        } else {
            $('#ofast-custom-greeting-row').slideUp(200);
        }
    });

    // Search functionality
    $('#ofast-tweaks-search').on('input', function () {
        var searchTerm = $(this).val().toLowerCase().trim();
        var wrapper = $('.ofast-studio-wrapper');

        if (searchTerm === '') {
            // CLEAR SEARCH
            wrapper.removeClass('searching');
            $('.ofast-tweak-row, .ofast-section-title, .ofast-card, .ofast-tab-content').removeClass('hidden-by-search');
            // Restore active tab
            $('.ofast-studio-tab.active').click();
            return;
        }

        // ACTIVE SEARCH MODE
        wrapper.addClass('searching');

        // Search through all cards and rows
        $('.ofast-card').each(function () {
            var card = $(this);

            card.find('.ofast-tweak-row').each(function () {
                var labelText = $(this).find('label').first().text().toLowerCase();
                var descText = $(this).find('.description').text().toLowerCase();

                if (labelText.includes(searchTerm) || descText.includes(searchTerm)) {
                    $(this).removeClass('hidden-by-search');
                } else {
                    $(this).addClass('hidden-by-search');
                }
            });

            // Hide section titles if needed
            card.find('.ofast-section-title').each(function () {
                var $nextRows = $(this).nextUntil('.ofast-section-title, .ofast-card-header');
                var visibleRows = $nextRows.filter('.ofast-tweak-row:not(.hidden-by-search)').length;
                $(this).toggleClass('hidden-by-search', visibleRows === 0);
            });

            // Hide card if no visible rows
            var visibleRowsInCard = card.find('.ofast-tweak-row:not(.hidden-by-search)').length;
            card.toggleClass('hidden-by-search', visibleRowsInCard === 0);
        });

        // Hide tabs if no visible cards
        $('.ofast-tab-content').each(function () {
            var visibleCards = $(this).find('.ofast-card:not(.hidden-by-search)').length;
            $(this).toggleClass('hidden-by-search', visibleCards === 0);
        });
    });
});

// Collapsible toggle function
function toggleCollapsible(contentId) {
    var content = document.getElementById(contentId);
    var arrow = document.getElementById(contentId + '-arrow');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        arrow.style.transform = 'rotate(0deg)';
    } else {
        content.style.display = 'none';
        arrow.style.transform = 'rotate(-90deg)';
    }
}
