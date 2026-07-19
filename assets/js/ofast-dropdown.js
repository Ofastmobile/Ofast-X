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