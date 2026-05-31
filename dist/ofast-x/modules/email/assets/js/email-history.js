/**
 * Ofast Emailer - History Tab JS
 * Preview modal and pagination
 */
jQuery(document).ready(function ($) {
    // Preview button
    $('.preview-log-btn').on('click', function () {
        var body = $(this).attr('data-body');
        var subject = $(this).attr('data-subject');
        $('#modal-subject').text(subject);
        $('#modal-content').remove();
        var iframe = $('<iframe id="modal-content" style="width:100%; height:500px; border:1px solid #e5e7eb; border-radius:8px;"></iframe>');
        iframe.attr('srcdoc', body);
        $('#history-preview-modal .ofast-modal-body').append(iframe);
        $('#history-preview-modal').fadeIn();
    });
    $('#close-history-modal').on('click', function () {
        $('#history-preview-modal').fadeOut();
        $('#modal-content').remove();
    });

    // Per-page selector → reload via URL
    $('#ofast-history-per-page').on('change', function () {
        var url = new URL(window.location);
        url.searchParams.set('tab', 'history');
        url.searchParams.set('hist_per_page', $(this).val());
        url.searchParams.delete('hist_paged');
        window.location.href = url.toString();
    });

    // Pagination page clicks → reload via URL
    $('#tab-history .ofast-pagination').on('click', '.ofast-page-btn', function (e) {
        e.preventDefault();
        if ($(this).hasClass('disabled') || $(this).hasClass('active')) return;
        var page = $(this).data('page');
        var url = new URL(window.location);
        url.searchParams.set('tab', 'history');
        url.searchParams.set('hist_paged', page);
        window.location.href = url.toString();
    });
});
