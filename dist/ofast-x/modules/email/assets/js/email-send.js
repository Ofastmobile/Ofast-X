/**
 * Ofast Emailer - Send Tab JS
 * User table, search, pagination, role filtering, audience summary, video player, preview modal
 */
jQuery(document).ready(function ($) {

    /* ============================================
       Audience Summary (role checkboxes → summary bar)
       ============================================ */
    function updateAudienceSummary() {
        var tags = [];
        $('#ofast-roles-picker input[type="checkbox"]:checked').each(function () {
            var label = $(this).siblings('span').text().trim();
            tags.push(label);
        });
        var $summary = $('#ofast-audience-summary');
        var $tagsContainer = $('#ofast-audience-tags');
        if (tags.length > 0) {
            var html = '';
            for (var i = 0; i < tags.length; i++) {
                html += '<span style="display:inline-block; padding:3px 10px; background:#fff; border:1px solid #c7d2fe; border-radius:20px; font-size:12px; font-weight:500; color:#4338ca;">' + $('<span>').text(tags[i]).html() + '</span>';
            }
            $tagsContainer.html(html);
            $summary.slideDown(200);
        } else {
            $summary.slideUp(200);
        }
    }
    $('#ofast-roles-picker input[type="checkbox"]').on('change', updateAudienceSummary);
    updateAudienceSummary(); // Init on load (for drafts)

    /* ============================================
       Video Player
       ============================================ */
    var playVideo = function () {
        var videoId = $(this).data('video-id');
        var iframe = $('<iframe/>', {
            'src': 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0',
            'frameborder': '0',
            'allow': 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
            'allowfullscreen': 'true',
            'css': {
                'width': '100%',
                'height': '100%',
                'position': 'absolute',
                'top': '0',
                'left': '0',
                'z-index': '10'
            }
        });
        $(this).empty().append(iframe);
    };
    $('#ofast-emailer-video-wrapper').on('click', playVideo);
    $('#ofast-emailer-video-wrapper').on('keydown', function (e) {
        if (e.keyCode === 13 || e.keyCode === 32) {
            e.preventDefault();
            playVideo.call(this);
        }
    });

    /* ============================================
       User Table: Search, Role Filter, Pagination
       ============================================ */
    var allRows = $("#user-table tbody tr");
    if (allRows.length === 0) return; // No table on page

    var visibleRows = allRows;
    var itemsPerPage = 10;
    var currentPage = 1;

    function updateVisibleRows() {
        var searchTerm = $("#user-search").val().toLowerCase();

        // Collect selected WP roles (exclude special values like _imported_contacts)
        var selectedRoles = [];
        $("#ofast-roles-picker input[type=checkbox]:checked").each(function () {
            var val = $(this).val();
            if (val !== "_imported_contacts") {
                selectedRoles.push(val.toLowerCase());
            }
        });

        visibleRows = allRows.filter(function () {
            var $row = $(this);

            // Role filter: if roles are selected, only show matching users
            if (selectedRoles.length > 0) {
                var userRoles = ($row.attr("data-roles") || "").toLowerCase().split(",");
                var roleMatch = false;
                for (var r = 0; r < selectedRoles.length; r++) {
                    if (userRoles.indexOf(selectedRoles[r]) !== -1) {
                        roleMatch = true;
                        break;
                    }
                }
                if (!roleMatch) return false;
            }

            // Search filter
            if (searchTerm === "") return true;
            return $row.text().toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        updatePagination();
    }

    function showPage(page) {
        allRows.hide();
        var start = (page - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        visibleRows.slice(start, end).show();
    }

    function updatePagination() {
        var total = visibleRows.length;
        var numPages = Math.ceil(total / itemsPerPage);
        if (numPages < 1) numPages = 1;
        if (currentPage > numPages) currentPage = numPages;

        var start = (currentPage - 1) * itemsPerPage;
        var showStart = total > 0 ? start + 1 : 0;
        var showEnd = Math.min(start + itemsPerPage, total);

        // Build modern pagination bar
        var html = '<div class="ofast-pagination">';
        html += '<div class="ofast-pagination-info">Showing <strong>' + showStart + '–' + showEnd + '</strong> of <strong>' + total + '</strong> users</div>';

        if (numPages > 1) {
            html += '<div class="ofast-pagination-pages">';

            // Prev
            var prevClass = currentPage <= 1 ? " disabled" : "";
            html += '<a href="#" class="ofast-page-btn' + prevClass + '" data-page="' + Math.max(1, currentPage - 1) + '" title="Previous"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span></a>';

            // Page numbers with smart ellipsis
            var range = 2;
            for (var i = 1; i <= numPages; i++) {
                if (i === 1 || i === numPages || (i >= currentPage - range && i <= currentPage + range)) {
                    var active = i === currentPage ? " active" : "";
                    html += '<a href="#" class="ofast-page-btn' + active + '" data-page="' + i + '">' + i + '</a>';
                } else if (i === currentPage - range - 1 || i === currentPage + range + 1) {
                    html += '<span class="ofast-page-ellipsis">…</span>';
                }
            }

            // Next
            var nextClass = currentPage >= numPages ? " disabled" : "";
            html += '<a href="#" class="ofast-page-btn' + nextClass + '" data-page="' + Math.min(numPages, currentPage + 1) + '" title="Next"><span class="dashicons dashicons-arrow-right-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span></a>';

            html += '</div>';
        }
        html += '</div>';

        $("#user-pagination").html(html);

        // Bind page clicks (delegated)
        $("#user-pagination").off("click", ".ofast-page-btn").on("click", ".ofast-page-btn", function (e) {
            e.preventDefault();
            if ($(this).hasClass("disabled") || $(this).hasClass("active")) return;
            currentPage = parseInt($(this).data("page"));
            showPage(currentPage);
            updatePagination();
        });

        showPage(currentPage);
    }

    $("#user-search").on("input", function () {
        updateVisibleRows();
    });

    // Role checkboxes filter the user table
    $("#ofast-roles-picker input[type=checkbox]").on("change", function () {
        updateVisibleRows();
    });

    $("#rows-per-page").change(function () {
        itemsPerPage = $(this).val() === "all" ? visibleRows.length : parseInt($(this).val());
        updatePagination();
    });

    $("#check-all").change(function () {
        visibleRows.find(".user-checkbox").prop("checked", $(this).prop("checked"));
    });

    /* ============================================
       Row Range Selector
       ============================================ */
    function parseRangeInput(input) {
        var indices = [];
        var parts = input.split(/[,;]+/);
        for (var p = 0; p < parts.length; p++) {
            var part = parts[p].trim();
            if (!part) continue;
            if (part.indexOf("-") !== -1) {
                var rangeParts = part.split("-");
                var rStart = parseInt(rangeParts[0]);
                var rEnd = parseInt(rangeParts[1]);
                if (!isNaN(rStart) && !isNaN(rEnd) && rStart > 0 && rEnd > 0) {
                    if (rStart > rEnd) { var tmp = rStart; rStart = rEnd; rEnd = tmp; }
                    if (rEnd - rStart > 5000) rEnd = rStart + 5000;
                    for (var n = rStart; n <= rEnd; n++) {
                        indices.push(n);
                    }
                }
            } else {
                var num = parseInt(part);
                if (!isNaN(num) && num > 0) indices.push(num);
            }
        }
        return indices;
    }

    function showRangeFeedback(count, total) {
        var $fb = $("#row-range-feedback");
        if (count > 0) {
            $fb.css({ "background": "#ecfdf5", "color": "#065f46", "border": "1px solid #a7f3d0" });
            $fb.html("\u2705 Selected <strong>" + count + "</strong> of " + total + " users");
        } else {
            $fb.css({ "background": "#fef2f2", "color": "#991b1b", "border": "1px solid #fecaca" });
            $fb.html("\u26a0\ufe0f No matching rows found. Enter S/N numbers like <strong>1-50</strong> or <strong>1,5,10-20</strong>");
        }
        $fb.show();
        setTimeout(function () { $fb.fadeOut(400); }, 4000);
    }

    $("#row-range-select").click(function () {
        var input = $("#row-range-input").val().trim();
        if (!input) return;
        var indices = parseRangeInput(input);
        if (indices.length === 0) { showRangeFeedback(0, allRows.length); return; }

        var selected = 0;
        allRows.each(function () {
            var sn = parseInt($(this).find("td:nth-child(2)").text().trim());
            if (indices.indexOf(sn) !== -1) {
                $(this).find(".user-checkbox").prop("checked", true);
                selected++;
            }
        });
        showRangeFeedback(selected, allRows.length);
    });

    // Handle Enter key in range input
    $("#row-range-input").keypress(function (e) {
        if (e.which === 13) { e.preventDefault(); $("#row-range-select").click(); }
    });

    $("#row-range-clear").click(function () {
        allRows.find(".user-checkbox").prop("checked", false);
        $("#check-all").prop("checked", false);
        $("#row-range-input").val("");
        $("#row-range-feedback").hide();
    });

    updatePagination();

    /* ============================================
       Email Preview Modal (Send tab)
       ============================================ */
    // Device toggle buttons
    $(".device-btn").click(function () {
        var width = $(this).data("width");
        $("#preview-iframe").css("width", width + "px");
        $(".device-btn").removeClass("active").css({ "background": "transparent", "color": "#94a3b8" });
        $(this).addClass("active").css({ "background": "#334155", "color": "#fff" });
    });

    // Preview Email Button
    $("#preview-email-btn").click(function (e) {
        e.preventDefault();

        var subject = $("input[name='subject']").val();
        var message = "";

        // Get content from TinyMCE
        if (typeof tinyMCE !== "undefined" && tinyMCE.get("message")) {
            message = tinyMCE.get("message").getContent();
        } else {
            message = $("#message").val();
        }

        if (!message) {
            alert("Please enter email content first!");
            return;
        }

        // Show modal with loading
        $("#email-preview-modal").fadeIn();
        var iframe = document.getElementById("preview-iframe");
        iframe.srcdoc = "<div style='display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;color:#64748b;'>Loading preview...</div>";

        // AJAX to get preview
        $.post(ajaxurl, {
            action: "ofast_preview_email",
            nonce: ofastEmailSend.previewNonce,
            subject: subject,
            message: message
        }, function (response) {
            if (response.success) {
                iframe.srcdoc = response.data.html;
            } else {
                iframe.srcdoc = "<div style='color:red;padding:20px;'>Error loading preview</div>";
            }
        });
    });

    // Close Modal
    $("#close-preview-modal").click(function (e) {
        e.preventDefault();
        e.stopPropagation();
        $("#email-preview-modal").fadeOut();
        return false;
    });

    // Close modal when clicking background
    $("#email-preview-modal").click(function (e) {
        if (e.target === this) {
            e.preventDefault();
            $(this).fadeOut();
        }
    });

    $(document).keyup(function (e) {
        if (e.key === "Escape") {
            $("#email-preview-modal").fadeOut();
        }
    });
});
