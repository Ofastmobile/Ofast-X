/**
 * Ofast X - Content Ordering Script
 * Drag-and-drop reordering interface
 */
(function ($) {
    'use strict';

    $(function () {
        var $container = $('#ofast-sortable-items');
        var isSaving = false;

        if (!$container.length) {
            return;
        }

        $container.sortable({
            items: '.ofast-sortable-item',
            placeholder: 'ofast-sortable-placeholder',
            cursor: 'grabbing',
            axis: 'y',
            tolerance: 'pointer',

            update: function (event, ui) {
                updateOrderNumbers();
                saveOrder();
            }
        });

        function updateOrderNumbers() {
            $('.ofast-sortable-item').each(function (index) {
                $(this).find('.ofast-item-order').text(index + 1);
            });
        }

        function saveOrder() {
            if (isSaving) return;
            isSaving = true;

            showStatus(ofastOrdering.i18n.saving, 'saving');

            var order = [];
            $('.ofast-sortable-item').each(function () {
                order.push($(this).data('id'));
            });

            $.ajax({
                url: ofastOrdering.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ofast_save_post_order',
                    nonce: ofastOrdering.nonce,
                    order: order,
                    post_type: $container.data('post-type')
                },
                success: function (response) {
                    isSaving = false;
                    if (response.success) {
                        showStatus(ofastOrdering.i18n.saved, 'success');
                        setTimeout(function () {
                            $('#ofast-order-status').fadeOut();
                        }, 2000);
                    } else {
                        showStatus(ofastOrdering.i18n.error + (response.data || ofastOrdering.i18n.failed), 'error');
                    }
                },
                error: function () {
                    isSaving = false;
                    showStatus(ofastOrdering.i18n.connectionError, 'error');
                }
            });
        }

        function showStatus(message, type) {
            $('#ofast-order-status')
                .removeClass('success error saving')
                .addClass(type)
                .text(message)
                .show();
        }
    });
})(jQuery);
