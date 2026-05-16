/**
 * Wix Blog Importer — Admin JS
 */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------
    var state = {
        feedUrl:         '',
        totalPosts:      0,
        selectedIndices: [],
        offset:          0,
        running:         false,
        imported:        0,
        skipped:         0,
        errors:          0,
    };

    // -------------------------------------------------------------------
    // DOM refs
    // -------------------------------------------------------------------
    var $feedUrl       = $('#mwi-feed-url');
    var $testBtn       = $('#mwi-test-feed');
    var $feedStatus    = $('#mwi-feed-status');
    var $selectionCard = $('#mwi-selection-card');
    var $selectionList = $('#mwi-selection-list');
    var $selectAll     = $('#mwi-select-all');
    var $selCount      = $('#mwi-sel-count');
    var $confirmSelBtn = $('#mwi-confirm-selection');
    var $optionsCard   = $('#mwi-options-card');
    var $postCount     = $('#mwi-post-count');
    var $startBtn      = $('#mwi-start-import');
    var $progressCard  = $('#mwi-progress-card');
    var $progressBar   = $('#mwi-progress-bar');
    var $progressLabel = $('#mwi-progress-label');
    var $log           = $('#mwi-log');
    var $summaryCard   = $('#mwi-summary-card');
    var $summaryContent= $('#mwi-summary-content');

    // -------------------------------------------------------------------
    // Saved feeds — Load button
    // -------------------------------------------------------------------
    $(document).on('click', '.mwi-load-feed', function () {
        var url = $(this).data('url');
        $feedUrl.val(url);
        $('#mwi-save-url').val(url);
        $feedStatus.hide();
        $selectionCard.hide();
        $optionsCard.hide();
        $('html, body').animate({ scrollTop: $feedUrl.offset().top - 40 }, 300);
        $feedUrl.focus();
    });

    // Keep the "save URL" field in sync with the main URL input
    $feedUrl.on('input', function () {
        $('#mwi-save-url').val($(this).val());
    });

    // -------------------------------------------------------------------
    // Test Feed
    // -------------------------------------------------------------------
    $testBtn.on('click', function () {
        var url = $feedUrl.val().trim();
        if (!url) {
            showStatus('error', '⚠️ Please enter a feed URL first.');
            return;
        }

        showStatus('loading', '<span class="mwi-spinner"></span> Testing feed, please wait…');
        $testBtn.prop('disabled', true).text('Testing…');
        $selectionCard.hide();
        $optionsCard.hide();

        $.post(MWI.ajax_url, {
            action:   'mwi_test_feed',
            nonce:    MWI.nonce,
            feed_url: url,
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data;
                showStatus('success', '✅ Feed found! <strong>' + d.count + ' posts</strong> detected. Select the ones you want to import below.');
                state.feedUrl = url;
                renderSelectionList(d.items);
                $selectionCard.slideDown(200);
            } else {
                showStatus('error', '❌ ' + (res.data && res.data.error ? res.data.error : 'Unknown error. Please check the URL.'));
            }
        })
        .fail(function () {
            showStatus('error', '❌ Network error. Please try again.');
        })
        .always(function () {
            $testBtn.prop('disabled', false).text('Test Feed');
        });
    });

    // Allow Enter key in URL field
    $feedUrl.on('keypress', function (e) {
        if (e.which === 13) $testBtn.trigger('click');
    });

    // -------------------------------------------------------------------
    // Selection list — render & interact
    // -------------------------------------------------------------------
    function renderSelectionList(items) {
        $selectionList.empty();
        items.forEach(function (item) {
            var date = item.date ? item.date.substring(0, 10) : '';
            var row  = $(
                '<label class="mwi-sel-row">' +
                '<input type="checkbox" class="mwi-sel-cb" value="' + item.index + '" checked /> ' +
                '<span class="mwi-sel-title">' + escHtml(item.title) + '</span>' +
                (date ? '<span class="mwi-sel-date">' + escHtml(date) + '</span>' : '') +
                '</label>'
            );
            $selectionList.append(row);
        });
        updateSelCount();
    }

    function updateSelCount() {
        var n = $selectionList.find('.mwi-sel-cb:checked').length;
        $selCount.text(n + ' post' + (n !== 1 ? 's' : '') + ' selected');
        $confirmSelBtn.prop('disabled', n === 0);
    }

    $selectionList.on('change', '.mwi-sel-cb', updateSelCount);

    $selectAll.on('change', function () {
        $selectionList.find('.mwi-sel-cb').prop('checked', $(this).is(':checked'));
        updateSelCount();
    });

    $confirmSelBtn.on('click', function () {
        var indices = [];
        $selectionList.find('.mwi-sel-cb:checked').each(function () {
            indices.push(parseInt($(this).val(), 10));
        });
        state.selectedIndices = indices;
        state.totalPosts      = indices.length;
        $postCount.text(indices.length + ' post' + (indices.length !== 1 ? 's' : '') + ' will be imported');
        $optionsCard.slideDown(200);
        $('html, body').animate({ scrollTop: $optionsCard.offset().top - 40 }, 300);
    });

    // -------------------------------------------------------------------
    // Start Import
    // -------------------------------------------------------------------
    $startBtn.on('click', function () {
        if (state.running) return;

        var opts = collectOptions();

        state.offset   = 0;
        state.imported = 0;
        state.skipped  = 0;
        state.errors   = 0;
        state.running  = true;

        $progressBar.css('width', '0%');
        $progressLabel.text('0 / ' + state.totalPosts + ' posts processed');
        $log.html('');
        $progressCard.slideDown(200);
        $summaryCard.hide();
        $startBtn.prop('disabled', true).text('Importing…');

        appendLog('info', '🚀 Import started — ' + state.totalPosts + ' posts to process');

        runBatch(opts);
    });

    // -------------------------------------------------------------------
    // Batch runner (recursive)
    // -------------------------------------------------------------------
    function runBatch(opts, retries) {
        retries = retries || 0;

        var postData = $.extend({}, opts, {
            action:    'mwi_import_batch',
            nonce:     MWI.nonce,
            feed_url:  state.feedUrl,
            offset:    state.offset,
        });

        // Use traditional serialisation so selected_indices[] posts correctly
        var formData = $.param(postData) + '&' + $.param({ 'selected_indices': state.selectedIndices });

        $.ajax({
            url:  MWI.ajax_url,
            type: 'POST',
            data: formData,
        })
        .done(function (res) {
            if (!res.success) {
                appendLog('error', '❌ Fatal error: ' + (res.data && res.data.error ? res.data.error : 'Unknown'));
                finishImport();
                return;
            }

            var d = res.data;

            state.imported += d.imported || 0;
            state.skipped  += d.skipped  || 0;
            state.errors   += d.errors   || 0;
            state.offset    = d.processed || state.offset;

            if (d.log && d.log.length) {
                d.log.forEach(function (entry) {
                    appendLog(entry.type, entry.msg);
                });
            }

            var pct = state.totalPosts > 0 ? Math.round((state.offset / state.totalPosts) * 100) : 0;
            $progressBar.css('width', Math.min(pct, 100) + '%');
            $progressLabel.text(state.offset + ' / ' + state.totalPosts + ' posts processed');

            if (d.done) {
                finishImport();
            } else {
                setTimeout(function () { runBatch(opts, 0); }, 600);
            }
        })
        .fail(function () {
            if (retries < 3) {
                appendLog('error', '❌ Network error during batch. Retrying in 5s… (' + (retries + 1) + '/3)');
                setTimeout(function () { runBatch(opts, retries + 1); }, 5000);
            } else {
                appendLog('error', '❌ Batch failed after 3 retries. The server may have timed out while downloading images. Try disabling "Import Images" or check your server\'s PHP error log.');
                finishImport();
            }
        });
    }

    // -------------------------------------------------------------------
    // Finish
    // -------------------------------------------------------------------
    function finishImport() {
        state.running = false;
        $progressBar.css('width', '100%');
        $progressLabel.text('Import complete!');
        $startBtn.prop('disabled', false).text('🚀 Start Import');

        appendLog('success', '🎉 All done! Imported: ' + state.imported + ' | Skipped: ' + state.skipped + ' | Errors: ' + state.errors);

        $summaryContent.html(
            '<div class="mwi-stat"><span class="mwi-stat-number">' + state.imported + '</span><span class="mwi-stat-label">Imported</span></div>' +
            '<div class="mwi-stat"><span class="mwi-stat-number" style="color:#888;">' + state.skipped + '</span><span class="mwi-stat-label">Skipped</span></div>' +
            '<div class="mwi-stat"><span class="mwi-stat-number" style="color:#c0392b;">' + state.errors + '</span><span class="mwi-stat-label">Errors</span></div>'
        );
        $summaryCard.slideDown(300);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------
    function collectOptions() {
        return {
            post_status:   $('#mwi-post-status').val(),
            import_images: $('#mwi-import-images').is(':checked') ? '1' : '0',
            set_thumbnail: $('#mwi-set-thumbnail').is(':checked') ? '1' : '0',
            import_cats:   $('#mwi-import-cats').is(':checked')   ? '1' : '0',
            import_tags:   $('#mwi-import-tags').is(':checked')   ? '1' : '0',
            skip_existing: $('#mwi-skip-existing').is(':checked') ? '1' : '0',
        };
    }

    function showStatus(type, html) {
        $feedStatus.removeClass('success error loading').addClass(type).html(html).show();
    }

    function appendLog(type, msg) {
        var cls = { success: 'log-success', info: 'log-info', skip: 'log-skip', warn: 'log-warn', error: 'log-error' }[type] || '';
        $log.append('<div class="' + cls + '">' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function escHtml(str) {
        return $('<div>').text(str).html();
    }

}(jQuery));
