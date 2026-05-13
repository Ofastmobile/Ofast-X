jQuery(document).ready(function ($) {
    // 1. Move User Profile to Bottom of Sidebar
    // This creates that "SaaS" look where the user is at the bottom left

    // Create container if not exists
    if ($('#ofast-sidebar-footer').length === 0) {
        $('#adminmenuwrap').append('<div id="ofast-sidebar-footer"></div>');
    }

    // Clone the logout link or profile link from top bar
    var profileLink = $('#wp-admin-bar-my-account > a').attr('href');
    var avatarHTML = $('#wp-admin-bar-my-account img').prop('outerHTML');

    // Inject into footer
    $('#ofast-sidebar-footer').html(`
        <a href="${profileLink}" class="ofast-mini-profile">
            ${avatarHTML}
        </a>
    `);

    // Add styles dynamically for this JS-injected element
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            #ofast-sidebar-footer {
                position: absolute;
                bottom: 20px;
                left: 0;
                width: 100%;
                display: flex;
                justify-content: center;
            }
            .ofast-mini-profile img {
                width: 40px;
                height: 40px;
                border-radius: 12px;
                border: 2px solid #e2e8f0;
                transition: transform 0.2s;
            }
            .ofast-mini-profile:hover img {
                border-color: #6366f1;
                transform: scale(1.05);
            }
        `)
        .appendTo('head');

    // 2. Add "Active" indicator bubble
    $('#adminmenu li.current').append('<div class="ofast-active-indicator"></div>');
});
