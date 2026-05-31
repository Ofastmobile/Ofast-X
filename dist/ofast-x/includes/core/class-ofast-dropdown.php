<?php
/**
 * Ofast X - Unified Dropdown Component
 * Reusable custom select dropdown for consistent UI across admin modules.
 *
 * Usage:
 *   echo Ofast_X_Dropdown::render_assets(); // Include once per page
 *   <select class="ofast-dropdown-native">...</select>
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Dropdown
{
    private static $assets_included = false;

    /**
     * Render dropdown assets once per page.
     *
     * @return string
     */
    public static function render_assets()
    {
        if (self::$assets_included) {
            return '';
        }

        self::$assets_included = true;
        wp_enqueue_script('jquery');

        return self::get_styles() . self::get_script();
    }

    /**
     * Get dropdown styles.
     *
     * @return string
     */
    public static function get_styles()
    {
        return <<<'HTML'
<style id="ofast-dropdown-styles">
    .ofast-dropdown {
        position: relative;
        display: inline-block;
        vertical-align: top;
    }

    .ofast-dropdown-trigger {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        width: 100%;
        min-height: 40px;
        padding: 9px 12px;
        background: #ffffff;
        border: 1px solid #d7deea;
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        color: #1e293b;
        font-size: 14px;
        line-height: 1.35;
        cursor: pointer;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        text-align: left;
    }

    .ofast-dropdown-trigger:hover {
        border-color: #6366f1;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.14);
    }

    .ofast-dropdown-trigger:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.16);
    }

    .ofast-dropdown.open .ofast-dropdown-trigger {
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.16);
    }

    .ofast-dropdown-label {
        display: block;
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ofast-dropdown-arrow {
        width: 0;
        height: 0;
        border-left: 5px solid transparent;
        border-right: 5px solid transparent;
        border-top: 6px solid #7c82f7;
        transition: transform 0.2s ease;
        flex-shrink: 0;
    }

    .ofast-dropdown.open .ofast-dropdown-arrow {
        transform: rotate(180deg);
    }

    .ofast-dropdown-menu {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        z-index: 9999;
        width: 100%;
        min-width: 180px;
        max-height: 280px;
        overflow-y: auto;
        padding: 6px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14), 0 6px 16px rgba(15, 23, 42, 0.08);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        pointer-events: none;
        transition: opacity 0.18s ease, visibility 0.18s ease, transform 0.18s ease;
    }

    .ofast-dropdown.open .ofast-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        pointer-events: auto;
    }

    .ofast-dropdown-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        width: 100%;
        padding: 11px 14px;
        border: 0;
        border-radius: 12px;
        background: transparent;
        color: #243144;
        font-size: 14px;
        line-height: 1.35;
        text-align: left;
        cursor: pointer;
        transition: background 0.15s ease, color 0.15s ease;
    }

    .ofast-dropdown-option:hover,
    .ofast-dropdown-option:focus {
        background: #f1efff;
        color: #4f46e5;
        outline: none;
    }

    .ofast-dropdown-option.is-selected {
        background: linear-gradient(135deg, #6d6ff4 0%, #5a5de9 100%);
        color: #ffffff;
        font-weight: 600;
    }

    .ofast-dropdown-option.is-selected:hover,
    .ofast-dropdown-option.is-selected:focus {
        background: linear-gradient(135deg, #5a5de9 0%, #4b4fd6 100%);
        color: #ffffff;
    }

    .ofast-dropdown-option:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .ofast-dropdown-check {
        font-size: 12px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .ofast-dropdown-menu::-webkit-scrollbar {
        width: 10px;
    }

    .ofast-dropdown-menu::-webkit-scrollbar-track {
        background: transparent;
    }

    .ofast-dropdown-menu::-webkit-scrollbar-thumb {
        background: #b9beca;
        border-radius: 999px;
        border: 2px solid #ffffff;
    }

    .ofast-dropdown-menu::-webkit-scrollbar-thumb:hover {
        background: #949aa8;
    }

    select.ofast-dropdown-native.ofast-dropdown-hidden {
        display: none !important;
    }
</style>
HTML;
    }

    /**
     * Get dropdown script.
     *
     * @return string
     */
    public static function get_script()
    {
        return <<<'HTML'
<script id="ofast-dropdown-script">
    (function ($) {
        'use strict';

        if (!$ || window.OfastDropdownComponentInitialized) {
            return;
        }

        window.OfastDropdownComponentInitialized = true;

        function closeAllDropdowns(exceptWrap) {
            $('.ofast-dropdown.open').each(function () {
                var $wrap = $(this);
                if (exceptWrap && $wrap.is(exceptWrap)) {
                    return;
                }

                $wrap.removeClass('open');
                $wrap.find('.ofast-dropdown-trigger').attr('aria-expanded', 'false');
            });
        }

        function syncDropdownWidth($select, $wrap) {
            if (!$select.length || !$wrap.length) {
                return;
            }

            if ($select.hasClass('ofast-dropdown-auto')) {
                $wrap.css('width', 'auto');
                return;
            }

            var width = $select.outerWidth();
            if (!width || width < 120) {
                width = 220;
            }

            $wrap.css('width', width + 'px');
        }

        function syncDropdownState($select) {
            var $wrap = $select.data('ofastDropdownWrap');
            if (!$wrap || !$wrap.length) {
                return;
            }

            var value = $select.val();
            var $selectedOption = $select.find('option:selected').first();
            var selectedText = $selectedOption.length ? $selectedOption.text() : $select.find('option').first().text();

            $wrap.find('.ofast-dropdown-label').text(selectedText);
            $wrap.find('.ofast-dropdown-option').removeClass('is-selected').attr('aria-selected', 'false').find('.ofast-dropdown-check').remove();

            var $selectedItem = $wrap.find('.ofast-dropdown-option').filter(function() {
                return $(this).attr('data-value') === value;
            });
            if ($selectedItem.length) {
                $selectedItem.addClass('is-selected').attr('aria-selected', 'true').append('<span class="ofast-dropdown-check">✓</span>');
            }

            var isDisabled = $select.is(':disabled');
            $wrap.find('.ofast-dropdown-trigger').prop('disabled', isDisabled);
        }

        function enhanceSelect($select) {
            if (!$select.length || $select.data('ofastDropdownInitialized') || $select.prop('multiple')) {
                return;
            }

            $select.data('ofastDropdownInitialized', true);

            var $wrap = $('<div class="ofast-dropdown"></div>');
            var triggerId = 'ofast-dropdown-' + Math.random().toString(36).slice(2);
            var $trigger = $('<button type="button" class="ofast-dropdown-trigger" aria-haspopup="listbox" aria-expanded="false"></button>');
            var $label = $('<span class="ofast-dropdown-label"></span>');
            var $arrow = $('<span class="ofast-dropdown-arrow"></span>');
            var $menu = $('<div class="ofast-dropdown-menu" role="listbox"></div>');

            $trigger.attr('id', triggerId);
            $menu.attr('aria-labelledby', triggerId);
            $trigger.append($label).append($arrow);

            $select.find('option').each(function () {
                var $option = $(this);
                var value = $option.val();
                var text = $option.text();
                var disabled = $option.is(':disabled');
                var $item = $('<button type="button" class="ofast-dropdown-option" role="option"></button>');

                $item.attr('data-value', value).append($('<span></span>').text(text));
                if (disabled) {
                    $item.prop('disabled', true);
                }

                $menu.append($item);
            });

            $select.after($wrap);
            $wrap.append($trigger).append($menu);
            $select.addClass('ofast-dropdown-hidden');

            $select.data('ofastDropdownWrap', $wrap);
            syncDropdownWidth($select, $wrap);
            syncDropdownState($select);

            $trigger.on('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                if ($trigger.prop('disabled')) {
                    return;
                }

                var isOpen = $wrap.hasClass('open');
                closeAllDropdowns($wrap);
                $wrap.toggleClass('open', !isOpen);
                $trigger.attr('aria-expanded', !isOpen ? 'true' : 'false');
            });

            $trigger.on('keydown', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    $trigger.trigger('click');
                } else if (event.key === 'Escape') {
                    closeAllDropdowns();
                }
            });

            $menu.on('click', '.ofast-dropdown-option', function (event) {
                event.preventDefault();
                var $item = $(this);

                if ($item.prop('disabled')) {
                    return;
                }

                $select.val($item.attr('data-value')).trigger('change');
                closeAllDropdowns();
                $trigger.focus();
            });
        }

        function initDropdowns(context) {
            $(context || document).find('select.ofast-dropdown-native').each(function () {
                enhanceSelect($(this));
            });
        }

        $(function () {
            initDropdowns(document);
        });

        $(document).on('click', function () {
            closeAllDropdowns();
        });

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllDropdowns();
            }
        });

        $(document).on('change', 'select.ofast-dropdown-native', function () {
            syncDropdownState($(this));
        });

        $(window).on('resize', function () {
            $('select.ofast-dropdown-native').each(function () {
                var $select = $(this);
                syncDropdownWidth($select, $select.data('ofastDropdownWrap'));
            });
        });

        window.OfastInitDropdowns = initDropdowns;
    })(window.jQuery);
</script>
HTML;
    }
}
