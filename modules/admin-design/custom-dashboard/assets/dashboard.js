jQuery(document).ready(function ($) {
    var $body = $('body');
    var $toggleBtn = $('#toggle-legacy-widgets');
    var $modernDashboard = $('.ofast-dashboard-takeover');
    var $legacyDashboard = $('#dashboard-widgets-wrap');
    var $modernPlaceholder = $('#ofast-modern-widgets-placeholder');

    // Store original parent to try and restore closer to home
    var $originalParent = $('#normal-sortables').length ? $('#normal-sortables') : $('#dashboard-widgets');

    // Private scope for Chart instance
    var dashboardChart = null;

    // --- CHART INITIALIZATION ---
    function initChart() {
        var ctx = document.getElementById('ofastSubmissionChart');
        if (!ctx) return;

        // Destroy existing if any
        if (dashboardChart) dashboardChart.destroy();

        var gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(99, 102, 241, 0.5)');
        gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

        var data = ofast_dashboard.analytics;

        dashboardChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Submissions',
                        data: data.submissions,
                        borderColor: '#6366f1',
                        backgroundColor: 'transparent',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#6366f1',
                        pointRadius: 4
                    },
                    {
                        label: 'Emails Sent',
                        data: data.smtp,
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#10b981',
                        pointRadius: 3
                    },
                    {
                        label: 'Subscribers',
                        data: data.newsletter,
                        borderColor: '#a855f7',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#a855f7',
                        pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#1e293b',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        borderColor: '#334155',
                        borderWidth: 1
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: { color: '#64748b' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748b' }
                    }
                }
            }
        });
    }

    function getDashboardPostboxes() {
        return $('#dashboard-widgets .postbox[id], #ofast-modern-widgets-placeholder .postbox[id]');
    }

    function cacheOriginalParents() {
        getDashboardPostboxes().each(function () {
            var $widget = $(this);

            if ($widget.attr('data-ofast-original-parent')) {
                return;
            }

            var $parent = $widget.parent();
            if ($parent.length && $parent.attr('id')) {
                $widget.attr('data-ofast-original-parent', '#' + $parent.attr('id'));
            }
        });
    }

    function restoreWidget($widget) {
        var parentSelector = $widget.attr('data-ofast-original-parent');

        if (parentSelector && $(parentSelector).length) {
            $(parentSelector).append($widget);
            return;
        }

        if ($originalParent.length) {
            $originalParent.append($widget);
        }
    }

    function getAllWidgetIds() {
        var ids = [];

        getDashboardPostboxes().each(function () {
            var id = $(this).attr('id');

            if (id) {
                ids.push('#' + id);
            }
        });

        return ids;
    }

    function getSelectedWidgetIds() {
        var selected = [];

        $('#screen-options-wrap input.hide-postbox-tog:checked').each(function () {
            var widgetId = $(this).val();

            if (widgetId) {
                selected.push('#' + widgetId);
            }
        });

        if (!selected.length) {
            selected = getAllWidgetIds();
        }

        return selected.filter(function (id, index, arr) {
            return arr.indexOf(id) === index && $(id).length > 0;
        });
    }

    function getWidgetsToLoad() {
        var savedOrder = JSON.parse(localStorage.getItem('ofast_widget_order') || '[]');
        var selectedWidgets = getSelectedWidgetIds();
        var widgetsToLoad = [];

        savedOrder.forEach(function (id) {
            if (selectedWidgets.indexOf(id) !== -1 && $(id).length > 0) {
                widgetsToLoad.push(id);
            }
        });

        selectedWidgets.forEach(function (id) {
            if (widgetsToLoad.indexOf(id) === -1 && $(id).length > 0) {
                widgetsToLoad.push(id);
            }
        });

        return widgetsToLoad;
    }

    function renderModernWidgets() {
        var widgetsToLoad;

        cacheOriginalParents();
        widgetsToLoad = getWidgetsToLoad();

        getDashboardPostboxes().each(function () {
            var $widget = $(this);
            var widgetSelector = '#' + $widget.attr('id');

            if (widgetsToLoad.indexOf(widgetSelector) === -1) {
                restoreWidget($widget);
            }
        });

        widgetsToLoad.forEach(function (id) {
            var $widget = $(id);

            if (!$widget.length) {
                return;
            }

            $modernPlaceholder.append($widget);
            $widget.show();
            $widget.removeClass('closed');
            $widget.find('.handlediv').attr('aria-expanded', 'true');
        });
    }

    function setMode(mode) {
        var $legacyDashboard = $('#dashboard-widgets-wrap');

        cacheOriginalParents();

        if (mode === 'classic') {
            // Restore Classic
            $body.removeClass('ofast-clean-dashboard ofast-dark-theme');
            $modernDashboard.hide();
            $legacyDashboard.show();

            // Move widgets back
            $modernPlaceholder.find('.postbox').each(function () {
                restoreWidget($(this));
            });

            $toggleBtn.text('Switch to Classic Dashboard'); // Actually this button is hidden in classic

            // Re-inject toggle button into classic dash
            if ($('#ofast-switch-btn-classic').length === 0) {
                var $h1 = $('.wrap h1').first();
                if ($h1.length) {
                    $h1.after('<button id="ofast-switch-btn-classic">Switch to Modern Dashboard</button>');
                } else {
                    $('.wrap').prepend('<button id="ofast-switch-btn-classic">Switch to Modern Dashboard</button>');
                }

                $('#ofast-switch-btn-classic').on('click', function (e) {
                    e.preventDefault();
                    setMode('modern');
                    localStorage.setItem('ofast_dashboard_mode', 'modern');
                });
            }

        } else {
            // Set Modern
            $body.addClass('ofast-clean-dashboard ofast-dark-theme');
            $modernDashboard.show();

            // Load the widgets chosen through WordPress Screen Options.
            renderModernWidgets();

            // Initialize Chart
            initChart();

            // Initialize Sortable
            if ($modernPlaceholder.hasClass('ui-sortable')) {
                $modernPlaceholder.sortable('refresh');
            } else {
                $modernPlaceholder.sortable({
                    items: '.postbox',
                    handle: '.hndle, .handlediv', // Standard WP headers
                    placeholder: 'ofast-sortable-placeholder',
                    forcePlaceholderSize: true,
                    tolerance: 'pointer',
                    update: function (event, ui) {
                        saveWidgetOrder();
                    }
                });
            }

            $toggleBtn.text('Switch to Classic Dashboard');
        }
    }

    function saveWidgetOrder() {
        var order = [];
        $modernPlaceholder.find('.postbox').each(function () {
            var id = $(this).attr('id');
            if (id) {
                order.push('#' + id);
            }
        });
        localStorage.setItem('ofast_widget_order', JSON.stringify(order));
    }

    // Initial Load
    var currentMode = localStorage.getItem('ofast_dashboard_mode') || 'modern';
    // Use requestAnimationFrame or a direct call instead of hardcoded setTimeout
    if (document.readyState === 'complete') {
        setMode(currentMode);
    } else {
        $(window).on('load', function () {
            setMode(currentMode);
        });
    }

    // Toggle Button Click
    $toggleBtn.on('click', function (e) {
        e.preventDefault();
        var newMode = $body.hasClass('ofast-clean-dashboard') ? 'classic' : 'modern';
        setMode(newMode);
        localStorage.setItem('ofast_dashboard_mode', newMode);
    });

    $(document).on('change', '#screen-options-wrap input.hide-postbox-tog', function () {
        if ($body.hasClass('ofast-clean-dashboard')) {
            renderModernWidgets();
        }
    });

    // --- SMART SEARCH FUNCTIONALITY ---
    var searchTimeout;
    var $searchInput = $('.ofast-search-bar input');
    var $searchContainer = $('.ofast-search-bar');

    // Create Results Dropdown
    var $searchResults = $('<div class="ofast-search-results"></div>');
    $searchContainer.append($searchResults);

    $searchInput.on('input', function () {
        var query = $(this).val().trim();

        clearTimeout(searchTimeout);

        if (query.length < 2) {
            $searchResults.hide().empty();
            return;
        }

        searchTimeout = setTimeout(function () {
            $searchResults.show().html('<div class="ofast-search-loading">Searching...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ofast_global_search',
                    query: query,
                    nonce: ofast_dashboard.nonce
                },
                success: function (response) {
                    if (response.success && response.data.length > 0) {
                        renderSearchResults(response.data);
                    } else {
                        $searchResults.html('<div class="ofast-search-empty">No results found</div>');
                    }
                }
            });
        }, 300); // 300ms debounce
    });

    // HTML Escaping Function
    function escapeHtml(unsafe) {
        return $('<div>').text(unsafe).html();
    }

    // Escape HTML Attributes
    function escapeAttr(unsafe) {
        return unsafe.replace(/[&<>"']/g, function(match) {
            var escapeMap = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#x27;'
            };
            return escapeMap[match];
        });
    }

    // Render Results
    function renderSearchResults(data) {
        var html = '';
        var currentType = '';

        data.forEach(function (item) {
            // Add Section Header if type changes
            if (item.type !== currentType) {
                var headerLabel = item.type === 'post' ? 'Content' : (escapeHtml(item.type.charAt(0).toUpperCase() + item.type.slice(1)) + 's');
                html += '<div class="ofast-result-header">' + headerLabel + '</div>';
                currentType = item.type;
            }

            var iconClass = 'dashicons-admin-post';
            if (item.type === 'user') iconClass = 'dashicons-admin-users';
            if (item.type === 'plugin') iconClass = 'dashicons-admin-plugins';
            if (item.subtype === 'page') iconClass = 'dashicons-admin-page';
            if (item.subtype === 'product') iconClass = 'dashicons-cart';

            html += '<a href="' + escapeAttr(item.url) + '" class="ofast-search-item">';
            if (item.avatar) {
                html += '<img src="' + escapeAttr(item.avatar) + '" class="ofast-result-avatar">';
            } else {
                html += '<span class="dashicons ' + iconClass + '"></span>';
            }
            html += '<div class="ofast-result-content">';
            html += '<span class="ofast-result-title">' + escapeHtml(item.title) + '</span>';
            if (item.label) {
                html += '<span class="ofast-result-meta">' + escapeHtml(item.label) + '</span>';
            }
            html += '</div>';
            html += '</a>';
        });

        $searchResults.html(html);
    }

    // Close on click outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.ofast-search-bar').length) {
            $searchResults.hide();
        }
    });

    // Enter key callback (optional: go to first result)
    $searchInput.on('keypress', function (e) {
        if (e.which === 13) {
            var $firstLink = $searchResults.find('a').first();
            if ($firstLink.length) {
                window.location.href = $firstLink.attr('href');
            }
        }
    });

    // --- PROFILE DROPDOWN TOGGLE ---
    var $profilePill = $('.ofast-profile-pill');
    var $profileDropdown = $('.ofast-profile-dropdown');

    $profilePill.on('click', function (e) {
        e.stopPropagation();
        $profileDropdown.fadeToggle(200);
        $(this).toggleClass('active');
    });

    // Close dropdown when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.ofast-profile-pill').length) {
            $profileDropdown.fadeOut(200);
            $profilePill.removeClass('active');
        }
    });

    // Prevent closing when clicking inside the dropdown
    $profileDropdown.on('click', function (e) {
        e.stopPropagation();
    });

    // --- LIVE CLOCK UPDATE ---
    function updateLiveClock() {
        var $clock = $('#ofast-live-clock');
        if ($clock.length === 0) return;

        var now = new Date();
        var options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        $clock.text(now.toLocaleDateString('en-US', options));
    }

    // Update every second
    setInterval(updateLiveClock, 1000);

});
