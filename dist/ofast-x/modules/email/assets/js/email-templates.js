/**
 * Ofast Emailer - Templates Tab JS
 * Color picker, style selector, live preview, media uploader
 */
jQuery(document).ready(function ($) {
    // Only run if template elements exist
    if ($('.ofast-color-picker').length === 0) return;

    // Color Picker
    $('.ofast-color-picker').wpColorPicker({
        change: function (event, ui) {
            setTimeout(updatePreview, 10);
        }
    });

    // Style selector active toggle
    $('input[name="template_style"]').on('change', function () {
        $('.ofast-style-label').css({ 'border-color': '#e2e8f0', 'background': '#fff' });
        $(this).closest('.ofast-style-label').css({ 'border-color': '#6366f1', 'background': '#eff6ff' });
        // Toggle custom template editor
        if ($(this).val() === 'custom') {
            $('#ofast-custom-template-wrap').slideDown(200);
        } else {
            $('#ofast-custom-template-wrap').slideUp(200);
        }
    });

    // Media Uploader
    $('#upload_logo_btn').click(function (e) {
        e.preventDefault();
        var image = wp.media({
            title: 'Upload Logo',
            multiple: false
        }).open()
            .on('select', function (e) {
                var uploaded_image = image.state().get('selection').first();
                var image_url = uploaded_image.toJSON().url;
                $('#logo_url').val(image_url);
                updatePreview();
            });
    });

    // Live Preview Updates
    $('input, select, #ofast_custom_template').on('change input', updatePreview);

    function updatePreview() {
        // Gather settings
        var style = $('input[name="template_style"]:checked').val();
        var primary = $('input[name="primary_color"]').val();
        var accent = $('input[name="accent_color"]').val();
        var bgColor = $('input[name="bg_color"]').val();
        var textColor = $('input[name="text_color"]').val();
        var logo = $('#logo_url').val();
        var logoWidth = $('#logo_width').val();
        var company = $('input[name="company_name"]').val();
        var tagline = $('input[name="tagline"]').val();
        var showHeader = $('input[name="show_header"]').is(':checked');
        var showFooter = $('input[name="show_footer"]').is(':checked');
        var font = $('#font_family').val();
        var fontSize = $('#font_size').val();

        // Font settings
        var fontStack = 'Arial, sans-serif';
        if (font === 'inter') fontStack = '"Inter", sans-serif';
        if (font === 'roboto') fontStack = '"Roboto", sans-serif';
        if (font === 'opensans') fontStack = '"Open Sans", sans-serif';

        var headerBg = (style === 'classic') ? primary : 'linear-gradient(135deg, ' + primary + ', ' + accent + ')';
        if (style === 'minimal') headerBg = 'transparent';

        // Gather social links
        var socialLinks = {};
        var socialColors = {
            'facebook': '#1877f2', 'x': '#000000', 'instagram': '#e4405f',
            'linkedin': '#0a66c2', 'youtube': '#ff0000', 'whatsapp': '#25d366', 'telegram': '#26a5e4'
        };
        var socialNames = {
            'facebook': 'Facebook', 'x': 'X', 'instagram': 'Instagram',
            'linkedin': 'LinkedIn', 'youtube': 'YouTube', 'whatsapp': 'WhatsApp', 'telegram': 'Telegram'
        };
        $('input[name^="social["]').each(function () {
            var platform = $(this).attr('name').match(/social\[(\w+)\]/)[1];
            var url = $(this).val();
            if (url) socialLinks[platform] = url;
        });

        // Escape helpers (prevent XSS in preview)
        function escapeHtml(value) {
            if (!value) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(value));
            return div.innerHTML;
        }
        function escapeAttribute(value) {
            return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // Safe escaped values
        var safeLogo = escapeAttribute(logo);
        var safeCompany = escapeHtml(company);
        var safeTagline = escapeHtml(tagline);

        // Custom template: render user's HTML with {{content}} replaced
        if (style === 'custom') {
            var customHtml = $('#ofast_custom_template').val() || '';
            if (customHtml.trim()) {
                var sampleContent = '<p style="margin:0 0 16px;"><strong>Hello John,</strong></p>'
                    + '<p style="margin:0 0 16px;">This is a sample email to preview your custom template.</p>'
                    + '<p style="margin:0;">Thank you for using Ofast Emailer!</p>';
                var renderedHtml = customHtml.replace(/\{\{content\}\}/gi, sampleContent);
                document.getElementById('template-preview').srcdoc = renderedHtml;
                return;
            }
            // Empty custom template placeholder
            var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
            html += '<body style="margin:0; padding:40px; font-family:Arial,sans-serif; background:#f8fafc; text-align:center; color:#64748b;">';
            html += '<div style="padding:40px; background:#fff; border-radius:12px; border:2px dashed #e2e8f0; max-width:500px; margin:0 auto;">';
            html += '<p style="font-size:32px; margin:0 0 12px;">✏️</p>';
            html += '<p style="font-size:16px; font-weight:600; color:#1e293b; margin:0 0 8px;">No Custom Template Yet</p>';
            html += '<p style="font-size:13px; margin:0;">Paste your HTML in the editor above. Use <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">{{content}}</code> where the email body should appear.</p>';
            html += '</div></body></html>';
            document.getElementById('template-preview').srcdoc = html;
            return;
        }

        // Build table-based HTML with inline styles
        var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Email Preview</title></head>';
        html += '<body style="margin:0; padding:0; background-color:' + bgColor + '; font-family:' + fontStack + '; font-size:' + fontSize + 'px;">';
        html += '<table width="100%" cellpadding="0" cellspacing="0" style="background-color:' + bgColor + '; padding:30px 0;"><tr><td align="center">';

        // Main card
        html += '<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; max-width:100%; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">';

        // Header
        if (showHeader && style !== 'minimal' && (logo || company)) {
            html += '<tr><td style="background:' + headerBg + '; padding:24px; text-align:center;">';
            if (logo) {
                html += '<img src="' + safeLogo + '" alt="' + safeCompany + '" style="max-width:' + logoWidth + 'px; height:auto; display:block; margin:0 auto;">';
            } else if (company) {
                html += '<div style="color:#ffffff; font-size:24px; font-weight:600;">' + safeCompany + '</div>';
            }
            html += '</td></tr>';
        }

        // Content
        html += '<tr><td style="padding:32px; color:' + textColor + ';">';
        html += '<div style="line-height:1.7; color:#374151;">';
        html += '<p style="margin:0 0 16px;"><strong>Hello John,</strong></p>';
        html += '<p style="margin:0 0 16px;">This is a sample email to preview your template design. The content you write in your emails will appear here, with your branding and colors applied.</p>';
        html += '<p style="margin:0;">Thank you for using Ofast Emailer!</p>';
        html += '</div></td></tr>';

        // Footer
        if (showFooter) {
            html += '<tr><td style="padding:0 32px;"><hr style="border:none; border-top:1px solid #e5e7eb;"></td></tr>';
            html += '<tr><td style="padding:24px 32px; text-align:center; font-size:13px; color:#6b7280;">';

            if (company || tagline) {
                html += '<p style="margin:0 0 12px;">';
                if (company && tagline) { html += safeCompany + ' - ' + safeTagline; }
                else { html += safeCompany || safeTagline; }
                html += '</p>';
            }

            var hasSocial = Object.keys(socialLinks).length > 0;
            if (hasSocial) {
                html += '<table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:12px;"><tr>';
                for (var platform in socialLinks) {
                    var color = socialColors[platform] || '#6b7280';
                    var name = socialNames[platform] || platform;
                    html += '<td style="padding:4px;">';
                    html += '<a href="' + escapeAttribute(socialLinks[platform]) + '" style="display:inline-block; background-color:' + color + '; color:#ffffff; font-size:12px; font-weight:600; text-decoration:none; padding:8px 14px; border-radius:999px; font-family:sans-serif;">' + escapeHtml(name) + '</a>';
                    html += '</td>';
                }
                html += '</tr></table>';
            }

            var footerCompany = safeCompany || 'Your Site';
            html += '<p style="margin:0; font-size:12px; color:#9ca3af;">&copy; ' + new Date().getFullYear() + ' ' + footerCompany + '. All rights reserved.</p>';
            html += '</td></tr>';
        }

        html += '</table></td></tr></table></body></html>';
        document.getElementById('template-preview').srcdoc = html;
    }

    // Initial preview
    updatePreview();
});
