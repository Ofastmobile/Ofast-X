<?php

/**
 * Ofast X Email Template Helper
 * Table-based, inline-styled email template for maximum email client compatibility
 * Based on user's blueprint design
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Template
{
    /**
     * Get modern email template with table-based layout and inline styles
     * Compatible with all email clients (Gmail, Outlook, Yahoo, etc.)
     */
    public static function get_template($content, $options = array())
    {
        // Get customizable options from settings
        $primary_color = get_option('ofast_email_primary_color', '#2563eb'); // Blue
        $header_bg = get_option('ofast_email_header_bg', '#111827'); // Dark
        $bg_color = get_option('ofast_email_bg_color', '#f3f4f6'); // Light gray
        $text_color = get_option('ofast_email_text_color', '#111827'); // Dark text
        $logo_url = get_option('ofast_email_logo', '');
        $company_name = get_option('ofast_email_company_name', get_bloginfo('name'));
        $tagline = get_option('ofast_email_tagline', '');
        $show_header = get_option('ofast_email_show_header', true);
        $show_footer = get_option('ofast_email_show_footer', true);
        $social_links = get_option('ofast_email_social', array());
        $logo_width = absint(get_option('ofast_email_logo_width', 140));

        // Override with passed options
        $options = wp_parse_args($options, array(
            'cta_button' => false,
            'cta_text' => '',
            'cta_link' => ''
        ));

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

        ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($company_name); ?></title>
</head>
<body style="margin:0; padding:0; background-color:<?php echo esc_attr($bg_color); ?>; font-family:Arial, Helvetica, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:<?php echo esc_attr($bg_color); ?>; padding:30px 0;">
        <tr>
            <td align="center">

                <!-- MAIN CARD -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; max-width:100%;">

                    <?php if ($show_header && (!empty($logo_url) || !empty($company_name))): ?>
                    <!-- HEADER -->
                    <tr>
                        <td style="background-color:<?php echo esc_attr($header_bg); ?>; padding:24px; text-align:center;">
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
                            <div style="font-size:15px; line-height:1.7; color:#374151;">
                                <?php echo $content; ?>
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
