<?php

/**
 * Ofast X Email Template Helper
 * Table-based, inline-styled email template for maximum email client compatibility
 * Supports Modern (gradient), Classic (solid), Minimal (no header), and Custom templates
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Template
{
    /**
     * Get email template with table-based layout and inline styles
     * Respects template style setting (modern/classic/minimal/custom)
     */
    public static function get_template($content, $options = array())
    {
        $prepared_content = self::prepare_content($content);

        // Get template style
        $style = get_option('ofast_email_template_style', 'modern');

        // Custom template — use user's raw HTML with {{content}} placeholder
        if ($style === 'custom') {
            $custom_html = get_option('ofast_email_custom_template', '');
            if (!empty($custom_html)) {
                return str_replace('{{content}}', wp_kses_post($prepared_content), $custom_html);
            }
            // Fallback to modern if custom template is empty
            $style = 'modern';
        }

        // Get customizable options from settings
        $primary_color = get_option('ofast_email_primary_color', '#6366f1');
        $accent_color = get_option('ofast_email_accent_color', '#10b981');
        $bg_color = get_option('ofast_email_bg_color', '#f8fafc');
        $text_color = get_option('ofast_email_text_color', '#1e293b');
        $logo_url = get_option('ofast_email_logo', '');
        $company_name = get_option('ofast_email_company_name', get_bloginfo('name'));
        $tagline = get_option('ofast_email_tagline', '');
        $show_header = get_option('ofast_email_show_header', true);
        $show_footer = get_option('ofast_email_show_footer', true);
        $social_links = get_option('ofast_email_social', array());
        $logo_width = absint(get_option('ofast_email_logo_width', 140));
        $font_family = get_option('ofast_email_font_family', 'system');
        $font_size = absint(get_option('ofast_email_font_size', 15));

        // Build header background based on style
        switch ($style) {
            case 'classic':
                $header_bg = $primary_color; // Solid color
                break;
            case 'minimal':
                $header_bg = 'transparent'; // No visible header
                break;
            case 'modern':
            default:
                $header_bg = $primary_color; // Fallback for email clients that don't support gradients
                break;
        }

        // For modern style, we use a gradient via inline CSS (email-safe via background shorthand)
        $header_bg_style = ($style === 'modern')
            ? "background: linear-gradient(135deg, {$primary_color}, {$accent_color}); background-color: {$primary_color};"
            : "background-color: {$header_bg};";

        // Override with passed options
        $options = wp_parse_args($options, array(
            'cta_button' => false,
            'cta_text' => '',
            'cta_link' => ''
        ));

        // Font stack
        $font_stack = 'Arial, Helvetica, sans-serif';
        if ($font_family === 'inter') $font_stack = '"Inter", Arial, sans-serif';
        if ($font_family === 'roboto') $font_stack = '"Roboto", Arial, sans-serif';
        if ($font_family === 'opensans') $font_stack = '"Open Sans", Arial, sans-serif';

        // Social platform colors
        $social_colors = array(
            'facebook' => '#1877f2',
            'x' => '#000000',
            'twitter' => '#000000',
            'instagram' => '#e1306c',
            'linkedin' => '#0a66c2',
            'youtube' => '#ff0000',
            'whatsapp' => '#25d366'
        );

        // Social platform display names
        $social_names = array(
            'facebook' => 'Facebook',
            'x' => 'X',
            'twitter' => 'X',
            'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn',
            'youtube' => 'YouTube',
            'whatsapp' => 'WhatsApp'
        );

        // Determine if header should show
        // Minimal: never show header. Others: show if setting is on and there's content.
        $render_header = ($style !== 'minimal') && $show_header && (!empty($logo_url) || !empty($company_name));

        ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($company_name); ?></title>
</head>
<body style="margin:0; padding:0; background-color:<?php echo esc_attr($bg_color); ?>; font-family:<?php echo esc_attr($font_stack); ?>;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:<?php echo esc_attr($bg_color); ?>; padding:30px 0;">
        <tr>
            <td align="center">

                <!-- MAIN CARD -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; max-width:100%;">

                    <?php if ($render_header): ?>
                    <!-- HEADER -->
                    <tr>
                        <td style="<?php echo $header_bg_style; ?> padding:24px; text-align:center;">
                            <?php if (!empty($logo_url)): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?>" 
                                style="max-width:<?php echo esc_attr($logo_width); ?>px; height:auto; display:block; margin:0 auto;">
                            <?php elseif (!empty($company_name)): ?>
                            <div style="color:#ffffff; font-size:24px; font-weight:600;"><?php echo esc_html($company_name); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <!-- CONTENT -->
                    <tr>
                        <td style="padding:32px; color:<?php echo esc_attr($text_color); ?>;">
                            <div style="font-size:<?php echo esc_attr($font_size); ?>px; line-height:1.7; color:#374151;">
                                <?php echo wp_kses_post($prepared_content); ?>
                            </div>

                            <?php if ($options['cta_button'] && !empty($options['cta_link'])): ?>
                            <!-- CTA BUTTON -->
                            <table cellpadding="0" cellspacing="0" style="margin-top:24px;">
                                <tr>
                                    <td style="background-color:<?php echo esc_attr($primary_color); ?>; border-radius:6px;">
                                        <a href="<?php echo esc_url($options['cta_link']); ?>" target="_blank" style="display:inline-block;
                                            padding:12px 24px;
                                            color:#ffffff;
                                            font-size:14px;
                                            font-weight:600;
                                            text-decoration:none;">
                                            <?php echo esc_html($options['cta_text'] ?: 'Click Here'); ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($show_footer): ?>
                    <!-- DIVIDER -->
                    <tr>
                        <td style="padding:0 32px;">
                            <hr style="border:none; border-top:1px solid #e5e7eb;">
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="padding:24px 32px; text-align:center; font-size:13px; color:#6b7280;">

                            <?php if (!empty($company_name) || !empty($tagline)): ?>
                            <p style="margin:0 0 12px;">
                                <?php 
                                if (!empty($company_name) && !empty($tagline)) {
                                    echo esc_html($company_name) . ' — ' . esc_html($tagline);
                                } elseif (!empty($company_name)) {
                                    echo esc_html($company_name);
                                } elseif (!empty($tagline)) {
                                    echo esc_html($tagline);
                                }
                                ?>
                            </p>
                            <?php endif; ?>

                            <?php if (!empty($social_links) && is_array($social_links)): ?>
                            <!-- SOCIAL BUTTONS (EMAIL SAFE - TEXT BASED) -->
                            <table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:12px;">
                                <tr>
                                    <?php foreach ($social_links as $platform => $url): 
                                        if (empty($url)) continue;
                                        $color = isset($social_colors[$platform]) ? $social_colors[$platform] : '#6b7280';
                                        $name = isset($social_names[$platform]) ? $social_names[$platform] : ucfirst($platform);
                                    ?>
                                    <td style="padding:4px;">
                                        <a href="<?php echo esc_url($url); ?>" style="display:inline-block;
                                            background-color:<?php echo esc_attr($color); ?>;
                                            color:#ffffff;
                                            font-size:12px;
                                            font-weight:600;
                                            text-decoration:none;
                                            padding:8px 14px;
                                            border-radius:999px;">
                                            <?php echo esc_html($name); ?>
                                        </a>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            </table>
                            <?php endif; ?>

                            <p style="margin:0; font-size:12px; color:#9ca3af;">
                                © <?php echo esc_html(date('Y')); ?> <?php echo esc_html($company_name ?: get_bloginfo('name')); ?>. All rights reserved.
                            </p>

                        </td>
                    </tr>
                    <?php endif; ?>

                </table>
                <!-- END CARD -->

            </td>
        </tr>
    </table>

</body>
</html>
<?php
        return ob_get_clean();
    }

    /**
     * Preserve paragraph breaks for plain-text content while leaving richer HTML intact.
     */
    private static function prepare_content($content)
    {
        $content = (string) $content;
        if ($content === '') {
            return '';
        }

        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        if (
            stripos($content, '<!DOCTYPE') !== false ||
            stripos($content, '<html') !== false ||
            stripos($content, '<body') !== false
        ) {
            return $content;
        }

        $has_html = $content !== wp_strip_all_tags($content, false);
        $has_block_markup = preg_match('/<(p|div|table|ul|ol|li|blockquote|h[1-6]|pre|br)\b/i', $content);

        if (!$has_html) {
            return wpautop(esc_html($content), false);
        }

        if (!$has_block_markup) {
            return wpautop($content, false);
        }

        return $content;
    }

    /**
     * Helper to convert hex color to RGB array
     */
    public static function hex_to_rgb($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return array(
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        );
    }
}
