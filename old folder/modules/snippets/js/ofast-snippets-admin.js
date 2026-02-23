/**
 * Ofast X Snippets Admin JavaScript
 * Extracted from inline JS for WordPress.org compliance
 * Uses ofastSnippets object for localized data/nonces
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Initialize CodeMirror on the code textarea
        var cmEditor = null;
        var $codeTextarea = $('#snippet_code');

        if ($codeTextarea.length && typeof wp !== 'undefined' && wp.codeEditor) {
            // Get language-specific settings
            var language = $('#snippet_language').val() || 'php';
            var mimeTypes = {
                'php': 'application/x-httpd-php',
                'javascript': 'text/javascript',
                'css': 'text/css',
                'html': 'text/html'
            };

            // Initialize CodeMirror
            cmEditor = wp.codeEditor.initialize($codeTextarea, {
                codemirror: {
                    mode: mimeTypes[language] || 'application/x-httpd-php',
                    lineNumbers: true,
                    lineWrapping: true,
                    indentUnit: 4,
                    tabSize: 4,
                    indentWithTabs: false,
                    autoCloseBrackets: true,
                    matchBrackets: true,
                    autoCloseTags: true,
                    extraKeys: {
                        'Ctrl-/': 'toggleComment',
                        'Cmd-/': 'toggleComment',
                        'Tab': function (cm) {
                            cm.replaceSelection('    ', 'end');
                        }
                    }
                }
            });

            // Switch CodeMirror mode when language changes
            $('#snippet_language').on('change', function () {
                var newLang = $(this).val();
                var newMode = mimeTypes[newLang] || 'application/x-httpd-php';
                if (cmEditor && cmEditor.codemirror) {
                    cmEditor.codemirror.setOption('mode', newMode);
                }
            });

            // Make sure CodeMirror content syncs back to textarea before form submit
            $('.ofast-snippet-form').on('submit', function () {
                if (cmEditor && cmEditor.codemirror) {
                    cmEditor.codemirror.save();
                    // Extra safety: also set the value directly
                    $codeTextarea.val(cmEditor.codemirror.getValue());
                }
            });

            // Also sync on any CodeMirror change (backup for form submit)
            if (cmEditor && cmEditor.codemirror) {
                cmEditor.codemirror.on('change', function () {
                    cmEditor.codemirror.save();
                });
            }
        }

        // Language filter
        $('#ofast-language-filter').on('change', function () {
            var selected = $(this).val();
            $('.snippet-row').each(function () {
                var lang = $(this).data('language') || 'php';
                if (selected === 'all' || lang === selected) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Toggle snippet (from table)
        $(document).on('click', '.ofast-snippet-toggle', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');
            var active = $btn.data('active');

            $btn.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'ofast_toggle_snippet',
                nonce: ofastSnippets.nonces.toggle,
                id: id,
                active: active
            }, function (response) {
                if (response.success) {
                    var newActive = response.data.active;
                    $btn.data('active', newActive);
                    $btn.html(newActive ? 'Deactivate' : 'Activate');
                    $btn.toggleClass('button-primary', newActive);
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Delete snippet
        $(document).on('click', '.ofast-snippet-delete', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');
            var active = $btn.data('active');
            var name = $btn.data('name');

            // Stronger warning for active snippets
            var message = active == 1 ?
                'WARNING: "' + name + '" is ACTIVE and currently running!\n\nDeleting it will stop it from running.\n\nAre you sure you want to delete this active snippet?' :
                'Are you sure you want to delete "' + name + '"?';

            if (!confirm(message)) {
                return;
            }

            $.post(ajaxurl, {
                action: 'ofast_delete_snippet',
                nonce: ofastSnippets.nonces.delete,
                id: id
            }, function (response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(function () {
                        $(this).remove();
                    });
                    // Refresh page to show updated trash
                    setTimeout(function () { location.reload(); }, 500);
                }
            });
        });

        // Open trash modal
        $('#ofast-open-trash-modal').on('click', function () {
            $('#ofast-trash-modal').css('display', 'flex');
        });

        // Close trash modal
        $('#ofast-close-trash-modal, #ofast-close-trash-modal-btn').on('click', function () {
            $('#ofast-trash-modal').hide();
        });

        // Close modal on backdrop click
        $('#ofast-trash-modal').on('click', function (e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Restore snippet from trash
        $(document).on('click', '.ofast-snippet-restore', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var id = $btn.data('id');

            $btn.prop('disabled', true).text('Restoring...');

            $.post(ajaxurl, {
                action: 'ofast_restore_snippet',
                nonce: ofastSnippets.nonces.restore,
                id: id
            }, function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    $btn.prop('disabled', false).text('Restore');
                    showToast('Error: ' + (response.data || 'Unknown error'), 'error');
                }
            });
        });

        // Permanently delete snippet
        $(document).on('click', '.ofast-snippet-permanently-delete', function (e) {
            e.preventDefault();
            if (!confirm('This will PERMANENTLY delete this snippet. This action cannot be undone. Continue?')) {
                return;
            }

            var $btn = $(this);
            var id = $btn.data('id');

            $.post(ajaxurl, {
                action: 'ofast_delete_snippet',
                nonce: ofastSnippets.nonces.delete,
                id: id,
                permanent: 'true'
            }, function (response) {
                if (response.success) {
                    location.reload();
                }
            });
        });

        // Run Now - On-demand snippet execution
        $(document).on('click', '.ofast-run-now', function (e) {
            e.preventDefault();
            var $link = $(this);
            var id = $link.data('id');
            var name = $link.data('name');

            if (!confirm('Run snippet "' + name + '" now?\n\nThis will execute the PHP code immediately.')) {
                return;
            }

            var originalText = $link.text();
            $link.text('Running...').css('pointer-events', 'none');

            $.post(ajaxurl, {
                action: 'ofast_run_snippet_now',
                nonce: ofastSnippets.nonces.runNow,
                id: id
            }, function (response) {
                if (response.success) {
                    $link.text('✓ Done').css('color', '#46b450');
                    setTimeout(function () {
                        $link.text(originalText).css({ 'pointer-events': 'auto', 'color': '#2271b1' });
                    }, 2000);
                } else {
                    showToast('Error: ' + (response.data || 'Unknown error'), 'error');
                    $link.text(originalText).css('pointer-events', 'auto');
                }
            }).fail(function () {
                showToast('Request failed. Check console for details.', 'error');
                $link.text(originalText).css('pointer-events', 'auto');
            });
        });

        // Empty all trash
        $('#empty-all-trash').on('click', function (e) {
            e.preventDefault();
            var count = $('.trash-row').length;
            if (!confirm('Are you sure you want to PERMANENTLY delete all ' + count + ' items in trash? This cannot be undone.')) {
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text('Deleting all...');

            // Delete each item sequentially
            var ids = [];
            $('.trash-row').each(function () {
                ids.push($(this).data('id'));
            });

            var deleteNext = function (index) {
                if (index >= ids.length) {
                    location.reload();
                    return;
                }
                $.post(ajaxurl, {
                    action: 'ofast_delete_snippet',
                    nonce: ofastSnippets.nonces.delete,
                    id: ids[index],
                    permanent: 'true'
                }, function () {
                    deleteNext(index + 1);
                });
            };

            deleteNext(0);
        });

        // Inline rename (edit mode)
        $(document).on('click', '.ofast-inline-edit', function (e) {
            e.preventDefault();
            var $display = $(this).closest('.snippet-name-display');
            var $input = $display.siblings('.snippet-name-edit');
            $display.hide();
            $input.show().focus().select();
        });

        // Save inline rename on blur
        $(document).on('blur', '.snippet-name-edit', function () {
            var $input = $(this);
            var $display = $input.siblings('.snippet-name-display');
            var id = $input.data('id');
            var newName = $input.val().trim();

            if (!newName) {
                $input.hide();
                $display.show();
                return;
            }

            // Save via AJAX
            $.post(ajaxurl, {
                action: 'ofast_rename_snippet',
                nonce: ofastSnippets.nonces.rename,
                id: id,
                name: newName
            }, function (response) {
                if (response.success) {
                    $display.find('strong').text(newName);
                    $input.val(newName);
                }
                $input.hide();
                $display.show();
            });
        });

        $(document).on('keypress', '.snippet-name-edit', function (e) {
            if (e.which === 13) { // Enter key
                $(this).blur();
            }
        });

        // Language selector - toggle help text AND injection location visibility
        $('#snippet_language').on('change', function () {
            var lang = $(this).val();

            // Toggle help text
            $('.php-help, .js-help, .css-help, .html-help').hide();
            $('.' + lang.replace('javascript', 'js') + '-help').show();

            // Show/hide injection location row (only relevant for JS/CSS/HTML, not PHP)
            if (lang === 'php') {
                $('.snippet-location-row').hide();
            } else {
                $('.snippet-location-row').show();

                // Auto-select best default injection location based on language
                var $location = $('#snippet_location');
                if (!$location.data('user-set')) {
                    if (lang === 'css') {
                        $location.val('header'); // CSS best in header to prevent FOUC
                    } else {
                        $location.val('footer'); // JS/HTML best in footer
                    }
                }
            }
        }).trigger('change');

        // Mark location as user-set when manually changed
        $('#snippet_location').on('change', function () {
            $(this).data('user-set', true);
        });

        // Page Targeting - show/hide target value field
        $('#snippet_target_type').on('change', function () {
            var type = $(this).val();
            var $valueRow = $('.snippet-target-value-row');
            var $input = $('#snippet_target_value');

            // Hide all help texts
            $('.post-type-help, .page-ids-help, .url-contains-help').hide();

            if (type === 'all' || type === 'homepage') {
                $valueRow.hide();
                $input.val('');
            } else {
                $valueRow.show();

                // Show appropriate help and placeholder
                if (type === 'post_type') {
                    $input.attr('placeholder', 'e.g., post, page, product');
                    $('.post-type-help').show();
                } else if (type === 'page_ids') {
                    $input.attr('placeholder', 'e.g., 1, 42, 123');
                    $('.page-ids-help').show();
                } else if (type === 'url_contains') {
                    $input.attr('placeholder', 'e.g., /checkout, /cart');
                    $('.url-contains-help').show();
                }
            }
        }).trigger('change');

        // Export snippets
        $('#ofast-export-snippets').on('click', function () {
            var $btn = $(this);
            var exportType = $('#export-type-select').val() || 'json';
            $btn.prop('disabled', true).html('Exporting...');

            var selectedIds = [];
            $('.snippet-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
            });

            $.post(ajaxurl, {
                action: 'ofast_export_snippets',
                nonce: ofastSnippets.nonces.export,
                ids: selectedIds
            }, function (response) {
                if (response.success) {
                    var content, filename, mimeType;
                    var date = new Date().toISOString().split('T')[0];

                    if (exportType === 'code') {
                        // Export as readable code file
                        var codeOutput = [];
                        codeOutput.push('/*');
                        codeOutput.push(' * Ofast X Code Snippets Export');
                        codeOutput.push(' * Exported: ' + date);
                        codeOutput.push(' * Site: ' + response.data.site_url);
                        codeOutput.push(' * Total Snippets: ' + response.data.snippets.length);
                        codeOutput.push(' */\n');

                        response.data.snippets.forEach(function (snippet, index) {
                            codeOutput.push('/* ========================================');
                            codeOutput.push(' * Snippet #' + (index + 1) + ': ' + snippet.name);
                            codeOutput.push(' * Language: ' + (snippet.language || 'php').toUpperCase());
                            codeOutput.push(' * Scope: ' + (snippet.scope || 'global'));
                            codeOutput.push(' * Status: ' + (snippet.active == 1 ? 'Active' : 'Inactive'));
                            if (snippet.description) {
                                codeOutput.push(' * Description: ' + snippet.description);
                            }
                            codeOutput.push(' * ======================================== */\n');
                            codeOutput.push(snippet.code);
                            codeOutput.push('\n\n');
                        });

                        content = codeOutput.join('\n');
                        filename = 'ofast-snippets-code-' + date + '.txt';
                        mimeType = 'text/plain';
                    } else {
                        // Export as JSON
                        content = JSON.stringify(response.data, null, 2);
                        filename = 'ofast-snippets-' + date + '.json';
                        mimeType = 'application/json';
                    }

                    var blob = new Blob([content], {
                        type: mimeType
                    });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    a.click();
                    URL.revokeObjectURL(url);
                } else {
                    showToast('Export failed: ' + response.data, 'error');
                }
                $btn.prop('disabled', false).html('Export');
            });
        });

        // Import snippets - trigger file input
        $('#ofast-import-snippets-btn').on('click', function () {
            $('#ofast-import-file').click();
        });

        // Handle file selection for import
        $('#ofast-import-file').on('change', function () {
            var file = this.files[0];
            if (!file) return;

            if (!file.name.endsWith('.json')) {
                showToast('Please select a valid JSON file', 'error');
                return;
            }

            var reader = new FileReader();
            reader.onload = function (e) {
                try {
                    var data = JSON.parse(e.target.result);

                    if (!data.snippets || !Array.isArray(data.snippets)) {
                        showToast('Invalid export file format', 'error');
                        return;
                    }

                    if (!confirm('Import ' + data.snippets.length + ' snippets?\n\nNote: All imported snippets will be set to INACTIVE for safety.')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'ofast_import_snippets',
                        nonce: ofastSnippets.nonces.import,
                        snippets: JSON.stringify(data.snippets)
                    }, function (response) {
                        if (response.success) {
                            showToast(response.data.message, 'success');
                            location.reload();
                        } else {
                            showToast('Import failed: ' + response.data, 'error');
                        }
                    });
                } catch (err) {
                    showToast('Invalid JSON file: ' + err.message, 'error');
                }
            };
            reader.readAsText(file);

            // Reset file input
            $(this).val('');
        });

        // Search filter
        $('#snippet-search').on('keyup', function () {
            filterSnippets();
        });

        // Category filter
        $('#category-filter').on('change', function () {
            filterSnippets();
        });

        // Combined filter function
        function filterSnippets() {
            var query = $('#snippet-search').val();
            query = query ? query.toLowerCase() : '';

            var categoryFilter = $('#category-filter');
            var category = categoryFilter.length ? categoryFilter.val() : '';

            $('.snippet-row').each(function () {
                var $row = $(this);
                var name = String($row.attr('data-name') || '').toLowerCase();
                var desc = String($row.attr('data-description') || '').toLowerCase();
                var cat = String($row.attr('data-category') || '');
                var code = String($row.attr('data-code') || '').toLowerCase();
                var tags = String($row.attr('data-tags') || '').toLowerCase();

                var matchesText = (query === '' || name.indexOf(query) > -1 || desc.indexOf(query) > -1 || code.indexOf(query) > -1 || tags.indexOf(query) > -1);
                var matchesCategory = (category === '' || category === undefined || cat === category);

                if (matchesText && matchesCategory) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        }

        // Select all checkbox
        $('#select-all-snippets').on('change', function () {
            var checked = $(this).is(':checked');
            $('.snippet-checkbox:visible').prop('checked', checked);
        });

        // Bulk actions
        $('#apply-bulk-action').on('click', function () {
            var action = $('#bulk-action-select').val();
            if (!action) {
                showToast('Please select a bulk action', 'warning');
                return;
            }

            var ids = [];
            $('.snippet-checkbox:checked').each(function () {
                ids.push($(this).val());
            });

            if (ids.length === 0) {
                showToast('Please select at least one snippet', 'warning');
                return;
            }

            var confirmMsg = 'Are you sure you want to ' + action + ' ' + ids.length + ' snippet(s)?';
            if (action === 'delete') {
                confirmMsg = '⚠️ WARNING: This will permanently delete ' + ids.length + ' snippet(s). Continue?';
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            $.post(ajaxurl, {
                action: 'ofast_bulk_action',
                nonce: ofastSnippets.nonces.bulk,
                bulk_action: action,
                ids: ids
            }, function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    location.reload();
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
            });
        });

        // Import from other plugins
        $(document).on('click', '.ofast-import-plugin-snippets', function () {
            var $btn = $(this);
            var plugin = $btn.data('plugin');

            if (!confirm('Import all snippets from ' + plugin + '?\n\nDuplicates will be skipped, all imported snippets will be INACTIVE for safety.')) {
                return;
            }

            $btn.prop('disabled', true).text('Importing...');

            $.post(ajaxurl, {
                action: 'ofast_import_from_plugin',
                nonce: ofastSnippets.nonces.importPlugin,
                plugin: plugin
            }, function (response) {
                if (response.success) {
                    // Show success message
                    showToast(response.data.message, 'success');

                    // Show warning about code duplication (if present)
                    if (response.data.warning) {
                        setTimeout(function () {
                            alert(response.data.warning);
                            location.reload();
                        }, 500);
                    } else {
                        location.reload();
                    }
                } else {
                    showToast('Import failed: ' + response.data, 'error');
                    $btn.prop('disabled', false).text('Import All');
                }
            });
        });

        // Preview & Import from other plugin
        $(document).on('click', '.ofast-preview-plugin-snippets', function () {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var pluginName = $btn.data('plugin-name');

            $btn.prop('disabled', true).text('Loading...');

            $.post(ajaxurl, {
                action: 'ofast_preview_plugin_snippets',
                nonce: ofastSnippets.nonces.preview,
                plugin: plugin
            }, function (response) {
                $btn.prop('disabled', false).text('Preview & Import');

                if (!response.success) {
                    showToast('Error: ' + response.data, 'error');
                    return;
                }

                var snippets = response.data.snippets;
                var validCount = snippets.filter(function (s) { return s.status !== 'duplicate' && s.is_safe !== false; }).length;
                var unsafeCount = snippets.filter(function (s) { return s.is_safe === false; }).length;

                // Build modal HTML
                var modalHtml = buildPreviewModal(snippets, pluginName, validCount, unsafeCount, plugin);
                $('body').append(modalHtml);

                var $modal = $('#ofast-preview-import-modal');

                // Close modal
                $modal.on('click', '.close-preview-modal', function () {
                    $modal.remove();
                });

                $modal.on('click', function (e) {
                    if (e.target === this) {
                        $modal.remove();
                    }
                });

                // Select all safe
                $modal.on('change', '#preview-select-all', function () {
                    $modal.find('.preview-snippet-checkbox:not(:disabled)').prop('checked', $(this).is(':checked'));
                    updateSelectedCount($modal);
                });

                // Update count on individual change
                $modal.on('change', '.preview-snippet-checkbox', function () {
                    updateSelectedCount($modal);
                });

                // Import selected
                $modal.on('click', '.import-selected-snippets', function () {
                    var $importBtn = $(this);
                    var selectedIds = [];
                    $modal.find('.preview-snippet-checkbox:checked').each(function () {
                        selectedIds.push($(this).val());
                    });

                    if (selectedIds.length === 0) {
                        showToast('Please select at least one snippet to import', 'warning');
                        return;
                    }

                    $importBtn.prop('disabled', true).text('Importing...');

                    $.post(ajaxurl, {
                        action: 'ofast_selective_import_snippets',
                        nonce: ofastSnippets.nonces.selectiveImport,
                        plugin: $importBtn.data('plugin'),
                        ids: selectedIds
                    }, function (resp) {
                        if (resp.success) {
                            showToast(resp.data.message, 'success');

                            // Show warning about code duplication (if present)
                            if (resp.data.warning) {
                                setTimeout(function () {
                                    alert(resp.data.warning);
                                    location.reload();
                                }, 500);
                            } else {
                                location.reload();
                            }
                        } else {
                            showToast('Import failed: ' + resp.data, 'error');
                            $importBtn.prop('disabled', false).html('Import Selected (<span class="selected-count">' + selectedIds.length + '</span>)');
                        }
                    });
                });
            });
        });

        // Toggle Library visibility
        $('#toggle-library').on('click', function () {
            var $lib = $('#snippet-library');
            var $btn = $(this);
            if ($lib.is(':visible')) {
                $lib.slideUp();
                $btn.text('Show Templates');
            } else {
                $lib.slideDown();
                $btn.text('Hide Templates');
            }
        });

        // Toggle Import from Other Plugins visibility
        $('#toggle-import-plugins').on('click', function () {
            var $content = $('#import-plugins-content');
            var $btn = $(this);
            if ($content.is(':visible')) {
                $content.slideUp();
                $btn.text('Show Plugins');
            } else {
                $content.slideDown();
                $btn.text('Hide Plugins');
            }
        });

        // Library category filter
        $('.library-cat-filter').on('click', function () {
            var cat = $(this).data('cat');
            $('.library-cat-filter').removeClass('button-primary active');
            $(this).addClass('button-primary active');

            if (cat === 'all') {
                $('.library-template').show();
            } else {
                $('.library-template').each(function () {
                    if ($(this).data('category') === cat) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            }
        });

        // Use Template button
        $(document).on('click', '.use-template-btn', function () {
            var $btn = $(this);
            var index = $btn.data('index');
            useTemplate($btn, index, false);
        });

        // Template usage function
        function useTemplate($btn, index, forceCopy) {
            $btn.prop('disabled', true).text('Creating...');

            $.post(ajaxurl, {
                action: 'ofast_use_template',
                nonce: ofastSnippets.nonces.useTemplate,
                template_index: index,
                force_copy: forceCopy ? '1' : '0'
            }, function (response) {
                if (response.success) {
                    if (response.data.duplicate) {
                        // Has duplicate - show choice modal
                        showDuplicateModal(response.data, $btn, index);
                    } else {
                        // Normal success
                        showToast(response.data.message, 'success');
                        location.reload();
                    }
                } else {
                    showToast('Failed: ' + response.data, 'error');
                    $btn.prop('disabled', false).text('Use Template');
                }
            });
        }

        // Custom modal for duplicate template choice
        function showDuplicateModal(data, $btn, index) {
            // Remove existing modal if any
            $('#ofast-duplicate-modal').remove();

            var modalHtml = '<div id="ofast-duplicate-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000; display:flex; align-items:center; justify-content:center;">' +
                '<div style="background:#fff; border-radius:12px; padding:0; max-width:450px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
                '<div style="padding:20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">' +
                '<h3 style="margin:0; color:#1d2327;">Template Already Exists</h3>' +
                '<button type="button" class="close-duplicate-modal" style="background:none; border:none; font-size:24px; cursor:pointer; color:#999;">&times;</button>' +
                '</div>' +
                '<div style="padding:25px;">' +
                '<p style="margin:0 0 20px; color:#50575e;">"<strong>' + data.existing_name + '</strong>" already exists in your snippets.</p>' +
                '<p style="margin:0 0 25px; color:#666;">What would you like to do?</p>' +
                '<div style="display:flex; gap:10px; flex-wrap:wrap;">' +
                '<button type="button" class="button button-primary edit-existing-btn" style="flex:1; min-width:120px;">Edit Existing</button>' +
                '<button type="button" class="button create-copy-btn" style="flex:1; min-width:120px;">Create Copy</button>' +
                '</div></div></div></div>';

            $('body').append(modalHtml);

            var $modal = $('#ofast-duplicate-modal');

            // Close modal on X click or outside click
            $modal.on('click', '.close-duplicate-modal', function () {
                $modal.remove();
                $btn.prop('disabled', false).text('Use Template');
            });

            $modal.on('click', function (e) {
                if (e.target === this) {
                    $modal.remove();
                    $btn.prop('disabled', false).text('Use Template');
                }
            });

            // Edit existing
            $modal.on('click', '.edit-existing-btn', function () {
                window.location.href = '?page=ofast-snippets&edit=' + data.existing_id;
            });

            // Create copy
            $modal.on('click', '.create-copy-btn', function () {
                $modal.remove();
                useTemplate($btn, index, true);
            });
        }

        // View History Button
        $('#view-history-btn').on('click', function () {
            var snippetId = $(this).data('snippet-id');

            // Show loading modal
            var loadingModal = '<div id="revision-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000; display:flex; align-items:center; justify-content:center;">' +
                '<div style="background:#fff; border-radius:12px; max-width:700px; width:90%; max-height:80vh; overflow:auto;">' +
                '<div style="padding:15px 20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">' +
                '<h3 style="margin:0;">Revision History</h3>' +
                '<button type="button" class="close-revision-modal" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>' +
                '</div>' +
                '<div id="revision-content" style="padding:20px;">' +
                '<p style="text-align:center;"><span class="spinner is-active" style="float:none;"></span> Loading revisions...</p>' +
                '</div></div></div>';

            $('body').append(loadingModal);

            var $modal = $('#revision-modal');
            $modal.on('click', '.close-revision-modal', function () { $modal.remove(); });
            $modal.on('click', function (e) { if (e.target === this) $modal.remove(); });

            // Fetch revisions
            $.post(ajaxurl, {
                action: 'ofast_get_revisions',
                nonce: ofastSnippets.nonces.getRevisions,
                snippet_id: snippetId
            }, function (response) {
                if (response.success) {
                    var revisions = response.data.revisions;
                    var html = '';

                    if (revisions.length === 0) {
                        html = '<p style="text-align:center; color:#666; padding:40px;">No revisions yet. Revisions are created when you edit and save code.</p>';
                    } else {
                        html = '<p style="color:#666; margin-bottom:15px;">Click "Preview" to view code, "Restore" to revert to that version.</p>';
                        html += '<table class="widefat striped">';
                        html += '<thead><tr><th>Date</th><th>Changed By</th><th style="width:200px;">Actions</th></tr></thead>';
                        html += '<tbody>';

                        revisions.forEach(function (rev) {
                            html += '<tr>';
                            html += '<td>' + rev.changed_at + '</td>';
                            html += '<td>' + (rev.user_name || 'Unknown') + '</td>';
                            html += '<td>';
                            html += '<button type="button" class="button button-small preview-revision" data-code="' + encodeURIComponent(rev.code) + '">Preview</button> ';
                            html += '<button type="button" class="button button-small restore-revision" data-id="' + rev.id + '">Restore</button>';
                            html += '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                    }

                    $('#revision-content').html(html);
                } else {
                    $('#revision-content').html('<p style="color:red;">Error loading revisions</p>');
                }
            });
        });

        // Preview revision
        $(document).on('click', '.preview-revision', function () {
            var code = decodeURIComponent($(this).data('code'));
            alert('=== REVISION CODE ===\n\n' + code.substring(0, 2000) + (code.length > 2000 ? '\n\n... (truncated)' : ''));
        });

        // Restore revision
        $(document).on('click', '.restore-revision', function () {
            if (!confirm('Restore this revision? Current code will be saved as a new revision and snippet will be set to INACTIVE for safety.')) {
                return;
            }

            var $btn = $(this);
            var revisionId = $btn.data('id');

            $btn.prop('disabled', true).text('Restoring...');

            $.post(ajaxurl, {
                action: 'ofast_restore_revision',
                nonce: ofastSnippets.nonces.restoreRevision,
                revision_id: revisionId
            }, function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    location.reload();
                } else {
                    showToast('Failed: ' + response.data, 'error');
                    $btn.prop('disabled', false).text('Restore');
                }
            });
        });

        // Helper function for toast notifications
        function showToast(message, type) {
            if (typeof Ofast_X_Toast !== 'undefined') {
                Ofast_X_Toast.show(message, type);
            } else {
                alert(message);
            }
        }

        // Helper function to build preview modal HTML
        function buildPreviewModal(snippets, pluginName, validCount, unsafeCount, plugin) {
            var html = '<div id="ofast-preview-import-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:100000; overflow-y:auto; padding:20px;">' +
                '<div style="max-width:900px; margin:30px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
                '<div style="padding:20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border-radius:12px 12px 0 0;">' +
                '<div><h2 style="margin:0; color:#1e293b;">Import from ' + pluginName + '</h2>' +
                '<p style="margin:5px 0 0; color:#64748b; font-size:13px;">' + snippets.length + ' snippets found, <strong style="color:#10b981;">' + validCount + ' safe to import</strong>' + (unsafeCount > 0 ? ', <span style="color:#ef4444;">' + unsafeCount + ' unsafe</span>' : '') + '</p></div>' +
                '<button type="button" class="close-preview-modal" style="background:none; border:none; font-size:28px; cursor:pointer; color:#64748b; line-height:1;">&times;</button></div>' +
                '<div style="padding:15px 20px; background:#f1f5f9; border-bottom:1px solid #e5e7eb; display:flex; gap:15px; flex-wrap:wrap; font-size:12px; align-items:center;">' +
                '<span><span style="background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span> Syntax OK</span>' +
                '<span><span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600;">UNSAFE</span> Has errors</span>' +
                '<label style="margin-left:auto;"><input type="checkbox" id="preview-select-all"' + (validCount === 0 ? ' disabled' : '') + '> Select All Safe</label></div>' +
                '<div style="max-height:400px; overflow-y:auto; padding:10px 20px;">';

            snippets.forEach(function (s) {
                var isDuplicate = s.status === 'duplicate';
                var isUnsafe = s.is_safe === false;
                var borderColor = isDuplicate ? '#fecaca' : (isUnsafe ? '#fed7aa' : '#e5e7eb');
                var bgColor = isDuplicate ? '#fef2f2' : (isUnsafe ? '#fffbeb' : '#fff');
                var opacity = isDuplicate ? 'opacity:0.7;' : '';
                var disabled = isDuplicate || isUnsafe ? ' disabled' : '';
                var statusColor = s.status === 'active' ? '#10b981' : (isDuplicate ? '#ef4444' : '#6b7280');

                html += '<div class="preview-snippet-item" style="display:flex; gap:12px; padding:12px; border:1px solid ' + borderColor + '; border-radius:8px; margin-bottom:10px; background:' + bgColor + '; ' + opacity + '">' +
                    '<input type="checkbox" class="preview-snippet-checkbox" name="import_snippets[]" value="' + s.id + '"' + disabled + ' style="margin-top:4px;">' +
                    '<div style="flex:1; min-width:0;">' +
                    '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">' +
                    '<span style="color:' + statusColor + '; font-size:16px;">●</span>' +
                    '<strong style="color:#1e293b;">' + s.name + '</strong>' +
                    (isDuplicate ? '<span style="background:#fecaca; color:#991b1b; padding:2px 6px; border-radius:3px; font-size:10px;">DUPLICATE</span>' : '') +
                    (s.language !== 'php' ? '<span style="background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:3px; font-size:10px;">' + s.language.toUpperCase() + '</span>' : '') +
                    '<span style="margin-left:auto;">' + (isUnsafe ? '<span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">UNSAFE</span>' : '<span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600;">SAFE</span>') + '</span>' +
                    '</div>' +
                    (s.description ? '<p style="margin:0 0 8px; color:#64748b; font-size:12px;">' + s.description + '</p>' : '') +
                    (isDuplicate ? '<p style="margin:0; color:#ef4444; font-size:11px;">Already exists in Ofast X (ID: ' + s.existing_id + ')</p>' : '') +
                    (isUnsafe ? '<p style="margin:0 0 8px; color:#ea580c; font-size:11px; background:#fffbeb; padding:4px 8px; border-radius:4px;"><strong>Syntax Error:</strong> ' + (s.error_message || 'Invalid PHP code') + '</p>' : '') +
                    '</div></div>';
            });

            html += '</div>' +
                '<div style="padding:15px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc; border-radius:0 0 12px 12px;">' +
                '<button type="button" class="button close-preview-modal">Cancel</button>' +
                '<button type="button" class="button button-primary import-selected-snippets" data-plugin="' + plugin + '">Import Selected (<span class="selected-count">0</span>)</button>' +
                '</div></div></div>';

            return html;
        }

        // Helper function to update selected count
        function updateSelectedCount($modal) {
            var count = $modal.find('.preview-snippet-checkbox:checked').length;
            $modal.find('.selected-count').text(count);
        }
    });

})(jQuery);
