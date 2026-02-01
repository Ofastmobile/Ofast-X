jQuery(document).ready(function ($) {
    var $body = $('body');
    var $toggleBtn = $('#toggle-legacy-widgets');
    var $modernDashboard = $('.ofast-dashboard-takeover');
    var $legacyDashboard = $('#dashboard-widgets-wrap');
    var $modernPlaceholder = $('#ofast-modern-widgets-placeholder');

    // IDs of widgets we want to show in Modern Mode
    var targetWidgets = [
        '#ofast_admin_users_widget',
        '#ofast_designer_details_widget',
        '#ofast_snippets_widget'
    ];

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

    function setMode(mode) {
        var $legacyDashboard = $('#dashboard-widgets-wrap');

        if (mode === 'classic') {
            // Restore Classic
            $body.removeClass('ofast-clean-dashboard ofast-dark-theme');
            $modernDashboard.hide();
            $legacyDashboard.show();

            // Move widgets back
            $modernPlaceholder.find('.postbox').each(function () {
                $originalParent.append($(this));
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

            // LOAD WIDGETS
            // Check for saved order
            var savedOrder = JSON.parse(localStorage.getItem('ofast_widget_order') || '[]');
            var widgetsToLoad = [];

            if (savedOrder.length > 0) {
                // Filter out any IDs that don't exist in DOM anymore, and add any new defaults that might be missing
                widgetsToLoad = savedOrder.filter(function (id) {
                    return $(id).length > 0;
                });

                // Add any missing defaults to the end
                targetWidgets.forEach(function (id) {
                    if (widgetsToLoad.indexOf(id) === -1 && $(id).length > 0) {
                        widgetsToLoad.push(id);
                    }
                });
            } else {
                widgetsToLoad = targetWidgets;
            }

            // Move widgets to placeholder in order
            widgetsToLoad.forEach(function (id) {
                var $widget = $(id);
                if ($widget.length) {
                    $modernPlaceholder.append($widget);
                    $widget.show();
                    // Fix WP postbox toggles if they get stuck
                    $widget.removeClass('closed');
                    $widget.find('.handlediv').attr('aria-expanded', 'true');
                }
            });

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

});
