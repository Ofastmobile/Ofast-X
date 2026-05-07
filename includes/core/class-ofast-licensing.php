<?php

/**
 * Ofast Toolkit Licensing & Pro-Feature Gatekeeper
 * 
 * This file contains the central helper function to check if the user has an active Pro license.
 * It wraps the Freemius SDK so that the rest of the plugin doesn't need to interact directly with Freemius.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if the user has a valid Pro license.
 *
 * This is the "Master Switch" for all Pro features.
 *
 * @return boolean True if Pro, False if Free.
 */
function ofast_toolkit_is_pro()
{
    // If the Freemius SDK isn't loaded for some reason, default to false to protect pro features.
    if (!function_exists('ofast_x_fs')) {
        return false;
    }

    // Use Freemius to check if they have a valid premium license or trial.
    return ofast_x_fs()->can_use_premium_code();
}

/**
 * (Optional) Check if a specific module/plan is active, if you add different tiers later.
 * Currently defaults to the master switch.
 * 
 * @param string $feature The feature to check.
 * @return boolean
 */
function ofast_toolkit_can_use_feature($feature = '')
{
    return ofast_toolkit_is_pro();
}

/**
 * Output the Pro Lock Badge HTML if the user is not Pro.
 * Call this next to the label of a premium feature.
 */
function ofast_toolkit_pro_badge()
{
    if ( ! ofast_toolkit_is_pro() ) {
        echo '<span class="dashicons dashicons-lock ofast-pro-badge" title="This is a Pro feature" style="color: #f59e0b; margin-left: 6px; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>';
    }
}

/**
 * Output the 'disabled' attribute if the user is not Pro.
 * Add this inside input/select tags for premium features.
 */
function ofast_toolkit_pro_disabled()
{
    if ( ! ofast_toolkit_is_pro() ) {
        echo ' disabled="disabled" ';
    }
}
