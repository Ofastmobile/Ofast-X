/**
 * Ofast X - Reusable Tabs Component
 */
(function ($) {
    'use strict';

    function getPanelSelector(tabKey) {
        return '.ofast-tab-content[data-tab-panel="' + tabKey + '"]';
    }

    function activateTab($shell, tabKey, updateUrl) {
        if (!$shell.length || !tabKey) {
            return;
        }

        var $tabs = $shell.find('.ofast-tab[data-tab]');
        var $targetTab = $tabs.filter('[data-tab="' + tabKey + '"]').first();

        if (!$targetTab.length) {
            $targetTab = $tabs.first();
            tabKey = $targetTab.data('tab');
        }

        $tabs.removeClass('active').attr('aria-selected', 'false');
        $targetTab.addClass('active').attr('aria-selected', 'true');

        $shell.find('.ofast-tab-content').removeClass('active');
        $shell.find(getPanelSelector(tabKey)).addClass('active');

        $shell.find('.ofast-active-tab').val(tabKey);

        if (updateUrl && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tabKey);
            window.history.replaceState({}, '', url.toString());
        }
    }

    function initTabs(context) {
        $(context || document).find('.ofast-tabs-shell').each(function () {
            var $shell = $(this);
            var $activeTab = $shell.find('.ofast-tab.active[data-tab]').first();
            var initialTab = $activeTab.length ? $activeTab.data('tab') : $shell.find('.ofast-tab[data-tab]').first().data('tab');

            activateTab($shell, initialTab, false);
        });
    }

    $(function () {
        initTabs(document);
    });

    $(document).on('click', '.ofast-tabs-shell .ofast-tab[data-tab]', function (event) {
        event.preventDefault();
        activateTab($(this).closest('.ofast-tabs-shell'), $(this).data('tab'), true);
    });

    window.OfastInitTabs = initTabs;
})(jQuery);
