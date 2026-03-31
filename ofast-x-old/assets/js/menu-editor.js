/**
 * Ofast X - Menu Editor Script
 * Drag-and-drop menu reordering and icon picker
 */
(function ($) {
    'use strict';

    $(function () {
        // Make table sortable
        $('#menu-items-list').sortable({
            handle: '.drag-handle',
            placeholder: 'ui-sortable-placeholder',
            axis: 'y',
            helper: function (e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function (index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            update: function (event, ui) {
                // Update hidden order inputs after drag
                var order = 10;
                $('#menu-items-list tr').each(function () {
                    $(this).find('.order-input').val(order);
                    order += 10;
                });
            }
        });

        // Icon picker toggle
        $(document).on('click', '.icon-picker-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var dropdown = $(this).siblings('.icon-picker-dropdown');
            $('.icon-picker-dropdown').not(dropdown).hide();
            dropdown.toggle();
        });

        // Close dropdown when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.icon-picker-wrapper').length) {
                $('.icon-picker-dropdown').hide();
            }
        });

        // Icon search filter
        $(document).on('input', '.icon-search-input', function () {
            var term = $(this).val().toLowerCase();
            $(this).closest('.icon-picker-dropdown').find('.icon-option').each(function () {
                var title = $(this).attr('title').toLowerCase();
                $(this).toggle(title.includes(term) || term === '');
            });
        });

        // Icon selection
        $(document).on('click', '.icon-option', function () {
            var wrapper = $(this).closest('.icon-picker-wrapper');
            var icon = $(this).data('icon');
            var iconClass = icon || 'dashicons-admin-generic';

            wrapper.find('.icon-value').val(icon);
            wrapper.find('.icon-picker-btn .dashicons').attr('class', 'dashicons ' + iconClass);
            wrapper.find('.icon-label').text(icon ? ofastMenuEditor.i18n.custom : ofastMenuEditor.i18n.default);
            wrapper.find('.icon-picker-dropdown').hide();

            // Update icon in menu name column
            wrapper.closest('tr').find('td:eq(1) .dashicons').attr('class', 'dashicons ' + iconClass);
        });
    });
})(jQuery);
