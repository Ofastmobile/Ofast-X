jQuery(document).ready(function($) {
// Module toggle AJAX
            $('.module-toggle').on('change', function() {
                var isChecked = $(this).is(':checked');
                var module = $(this).data('module');
                var badge = $(this).closest('.module-card').find('.status-badge');
                
                // Update UI optimistically
                if (isChecked) {
                    badge.removeClass('disabled').addClass('enabled').text('Enabled');
                    $(this).closest('.module-card').addClass('enabled');
                } else {
                    badge.removeClass('enabled').addClass('disabled').text('Disabled');
                    $(this).closest('.module-card').removeClass('enabled');
                }
                
                $.post(ofastSettingsAjax.url, {
                    action: 'ofast_save_module_toggle',
                    nonce: ofastSettingsAjax.nonce,
                    module: module,
                    enabled: isChecked
                });
            });

            // Filtering
            $('.filter-pills .pill').on('click', function() {
                $('.filter-pills .pill').removeClass('active');
                $(this).addClass('active');
                
                var filter = $(this).data('filter');
                if (filter === 'all') {
                    $('.module-card:not(.coming-soon-card)').show();
                } else if (filter === 'status-enabled') {
                    $('.module-card:not(.coming-soon-card)').hide();
                    $('.module-card.enabled').show();
                } else if (filter === 'status-disabled') {
                    $('.module-card:not(.coming-soon-card)').hide();
                    $('.module-card:not(.enabled):not(.coming-soon-card)').show();
                }
            });
            
            // Search
            $('#module-search').on('input', function() {
                var term = $(this).val().toLowerCase();
                if (term) {
                    $('.filter-pills .pill').removeClass('active');
                } else {
                    $('.filter-pills .pill[data-filter="all"]').addClass('active');
                }
                
                $('.module-card:not(.coming-soon-card)').each(function() {
                    var title = $(this).find('h3').text().toLowerCase();
                    var desc = $(this).find('.card-desc').text().toLowerCase();
                    if (title.indexOf(term) > -1 || desc.indexOf(term) > -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Modal
            $('.ofast-open-data-modal').on('click', function(e) {
                e.preventDefault();
                $('#data-management-modal').addClass('active');
            });
            $('.close-modal').on('click', function() {
                $('#data-management-modal').removeClass('active');
            });
            
            // Data options selection
            $('input[name="delete_data_choice"]').on('change', function() {
                $('.data-option').removeClass('selected').removeClass('danger-selected');
                var isDanger = $(this).closest('.data-option').hasClass('danger');
                if(isDanger) {
                    $(this).closest('.data-option').addClass('selected');
                } else {
                    $(this).closest('.data-option').addClass('selected');
                }
            });
            
            // Save Data Management
            $('#save-data-management').on('click', function() {
                var choice = $('input[name="delete_data_choice"]:checked').val();
                var btn = $(this);
                var originalText = btn.text();
                
                btn.text('Saving...').prop('disabled', true);
                
                $.post(ofastSettingsAjax.url, {
                    action: 'ofast_save_data_management',
                    nonce: ofastSettingsAjax.nonce,
                    delete_data: choice
                }, function(response) {
                    btn.text('Saved!');
                    setTimeout(function() {
                        btn.text(originalText).prop('disabled', false);
                        $('#data-management-modal').removeClass('active');
                    }, 1000);
                });
            });

            // Tab Switching (no page reload)
            $('.nav-item[data-tab]').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.ofast-tab-panel').hide();
                $('#ofast-tab-' + tab).show();
                $('.nav-item[data-tab]').removeClass('active');
                $(this).addClass('active');
            });
});