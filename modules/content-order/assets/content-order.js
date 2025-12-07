jQuery(document).ready(function($) {
    var saveTimeout;
    var $status = $('.save-status');

    // Initialize sortable
    $('#sortable-posts').sortable({
        handle: '.drag-handle',
        placeholder: 'ofast-post-placeholder',
        cursor: 'move',
        opacity: 0.8,
        update: function(event, ui) {
            // Clear previous timeout
            clearTimeout(saveTimeout);

            // Show saving status
            $status.text('💾 Saving...').css('color', '#2271b1');

            // Save after 500ms of no changes
            saveTimeout = setTimeout(function() {
                saveOrder();
            }, 500);
        }
    });

    function saveOrder() {
        var order = [];
        $('#sortable-posts .ofast-post-item').each(function() {
            order.push($(this).data('id'));
        });

        $.post(ofastContentOrder.ajaxurl, {
            action: 'ofast_save_order',
            nonce: ofastContentOrder.nonce,
            order: order
        }, function(response) {
            if (response.success) {
                $status.text('✓ Saved!').css('color', '#46b450');
                setTimeout(function() {
                    $status.fadeOut(function() {
                        $(this).text('').show();
                    });
                }, 2000);
            } else {
                $status.text('✗ Error saving').css('color', '#dc3545');
            }
        }).fail(function() {
            $status.text('✗ Error saving').css('color', '#dc3545');
        });
    }
});
