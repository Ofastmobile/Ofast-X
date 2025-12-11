<?php

/**
 * Ofast X Email Template Helper
 * Modern, aesthetic email template inspired by professional certificate emails
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Template
{

    /**
     * Get modern email template with customizable colors and branding
     */
    public static function get_template($content, $options = array())
    {
        // Get customizable options from settings (using same keys as settings page)
        $template_style = get_option('ofast_email_template_style', 'modern');
        $primary_color = get_option('ofast_email_primary_color', '#6366f1'); // Indigo
        $accent_color = get_option('ofast_email_accent_color', '#10b981'); // Emerald
        $bg_color = get_option('ofast_email_bg_color', '#f8fafc'); // Light gray
        $text_color = get_option('ofast_email_text_color', '#1e293b'); // Dark slate
        $logo_url = get_option('ofast_email_logo', '');
        $company_name = get_option('ofast_email_company_name', get_option('ofast_email_from_name', 'Your Company'));
        $tagline = get_option('ofast_email_tagline', '');
        $show_header = get_option('ofast_email_show_header', true);
        $show_footer = get_option('ofast_email_show_footer', true);
        $footer_text = get_option('ofast_email_footer_text', '');
        $social_links = get_option('ofast_email_social', array());

        // Override with passed options
        $options = wp_parse_args($options, array(
            'highlight_box' => false,
            'highlight_content' => '',
            'cta_button' => false,
            'cta_text' => '',
            'cta_link' => ''
        ));

        ob_start();
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($company_name); ?></title>
            <style>
                body {
                    margin: 0;
                    padding: 0;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background-color: <?php echo esc_attr($bg_color); ?>;
                    color: <?php echo esc_attr($text_color); ?>;
                    line-height: 1.6;
                }

                .email-wrapper {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }

                .email-container {
                    background: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
                    overflow: hidden;
                }

                .email-header {
                    background: linear-gradient(135deg, <?php echo esc_attr($primary_color); ?> 0%, <?php echo esc_attr($accent_color); ?> 100%);
                    padding: 15px;
                    text-align: center;
                    color: #ffffff;
                }

                .email-logo {
                    max-width: 120px;
                    height: auto;
                    margin-bottom: 5px;
                }

                .email-company-name {
                    font-size: 16px;
                    font-weight: 600;
                    margin: 0;
                    color: #ffffff;
                }

                .email-tagline {
                    font-size: 11px;
                    opacity: 0.9;
                    margin: 3px 0 0 0;
                    font-weight: 400;
                }

                .email-body {
                    padding: 30px 25px;
                    font-size: 15px;
                    color: #334155;
                    text-align: justify;
                }



                .highlight-box {
                    background: linear-gradient(135deg, rgba(<?php echo self::hex_to_rgb($accent_color); ?>, 0.1) 0%, rgba(<?php echo self::hex_to_rgb($primary_color); ?>, 0.1) 100%);
                    border-left: 4px solid <?php echo esc_attr($accent_color); ?>;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 8px;
                }

                .cta-button {
                    display: inline-block;
                    background: <?php echo esc_attr($primary_color); ?>;
                    color: #ffffff !important;
                    text-decoration: none;
                    padding: 12px 28px;
                    border-radius: 8px;
                    font-weight: 600;
                    margin: 20px 0;
                    transition: all 0.3s ease;
                }

                .cta-button:hover {
                    background: <?php echo esc_attr($accent_color); ?>;
                    transform: translateY(-2px);
                }

                .email-footer {
                    background: #f1f5f9;
                    padding: 12px 15px;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                }

                .social-links {
                    margin: 5px 0;
                }

                .social-icon {
                    display: inline-block;
                    width: 24px;
                    height: 24px;
                    margin: 0 5px;
                    vertical-align: middle;
                }

                .social-icon:hover {
                    opacity: 0.8;
                }

                .social-icon img {
                    width: 24px;
                    height: 24px;
                    display: block;
                }

                .divider {
                    height: 1px;
                    background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
                    margin: 30px 0;
                }

                @media screen and (max-width: 600px) {
                    .email-wrapper {
                        padding: 10px;
                    }

                    .email-header {
                        padding: 30px 20px;
                    }

                    .email-body {
                        padding: 30px 20px;
                    }

                    .email-company-name {
                        font-size: 20px;
                    }
                }
            </style>
        </head>

        <body>
            <div class="email-wrapper">
                <div class="email-container">

                    <?php if ($show_header): ?>
                        <!-- Header -->
                        <div class="email-header">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?>" class="email-logo">
                            <?php endif; ?>
                            <h1 class="email-company-name"><?php echo esc_html($company_name); ?></h1>
                            <?php if ($tagline): ?>
                                <p class="email-tagline"><?php echo esc_html($tagline); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Body -->
                    <div class="email-body">


                        <div class="email-content">
                            <?php echo wp_kses_post(wpautop($content)); ?>
                        </div>

                        <?php if ($options['highlight_box'] && $options['highlight_content']): ?>
                            <div class="highlight-box">
                                <?php echo wp_kses_post(wpautop($options['highlight_content'])); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($options['cta_button'] && $options['cta_link']): ?>
                            <div style="text-align: center;">
                                <a href="<?php echo esc_url($options['cta_link']); ?>" class="cta-button">
                                    <?php echo esc_html($options['cta_text']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($show_footer): ?>
                        <!-- Footer -->
                        <div class="email-footer">
                            <?php if (!empty($social_links)): ?>
                                <div class="social-links">
                                    <?php foreach ($social_links as $platform => $url): ?>
                                        <?php if ($url): ?>
                                            <a href="<?php echo esc_url($url); ?>" class="social-icon">
                                                <?php echo self::get_social_icon($platform); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="divider"></div>

                            <?php if ($footer_text): ?>
                                <p style="margin: 15px 0;"><?php echo wp_kses_post($footer_text); ?></p>
                            <?php endif; ?>

                            <p style="margin: 10px 0; font-size: 12px;">
                                © <?php echo date('Y'); ?> <?php echo esc_html($company_name); ?>. All rights reserved.
                            </p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </body>

        </html>
<?php
        return ob_get_clean();
    }

    /**
     * Convert hex color to RGB
     */
    private static function hex_to_rgb($hex)
    {
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }

    /**
     * Get social media icon SVG
     */
    private static function get_social_icon($platform)
    {
        $icons = array(
            'facebook' => '<img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="Facebook">',
            'x' => '<img src="https://cdn-icons-png.flaticon.com/512/5968/5968830.png" alt="X">',
            'twitter' => '<img src="https://cdn-icons-png.flaticon.com/512/5968/5968830.png" alt="Twitter">',
            'youtube' => '<img src="https://cdn-icons-png.flaticon.com/512/1384/1384060.png" alt="YouTube">',
            'instagram' => '<img src="https://cdn-icons-png.flaticon.com/512/2111/2111463.png" alt="Instagram">',
            'linkedin' => '<img src="https://cdn-icons-png.flaticon.com/512/174/174857.png" alt="LinkedIn">',
            'whatsapp' => '<img src="https://cdn-icons-png.flaticon.com/512/733/733585.png" alt="WhatsApp">'
        );

        return isset($icons[$platform]) ? $icons[$platform] : '';
    }
}
