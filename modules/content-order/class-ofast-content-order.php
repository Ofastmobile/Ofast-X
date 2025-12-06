<?php

/**
 * Ofast X - Content Ordering Module
 * Drag-and-drop reorder for posts and pages
 */
if (!defined('ABSPATH')) exit;

class Ofast_X_Content_Order
{
    public function init()
    {
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['content-order'])) {
            return;
        }

        // TODO: Implement content ordering functionality
    }
}
