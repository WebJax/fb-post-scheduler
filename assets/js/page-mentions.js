/**
 * Facebook Page @-mention autocomplete for the plugin meta box textarea.
 *
 * Two modes:
 * 1) @[query  → AJAX search in locally saved pages (Indstillinger)
 * 2) @query   → AJAX search via Facebook Pages Search (min. 3 tegn)
 *
 * Selecting a result inserts @[PAGE_ID:Page Name], converted to @[PAGE_ID] on publish.
 */
(function($) {
    'use strict';

    var GRAPH_MIN_CHARS = 3;
    var DEBOUNCE_MS = 300;
    var $dropdown = null;
    var activeTextarea = null;
    var activeMention = null;
    var debounceTimer = null;
    var currentRequest = null;
    var highlightedIndex = -1;

    $(document).ready(function() {
        if (typeof fbPostScheduler === 'undefined') {
            return;
        }

        $dropdown = $('<ul>', {
            id: 'fb-page-mention-dropdown',
            class: 'fb-page-mention-dropdown',
            role: 'listbox',
            'aria-label': 'Facebook Pages'
        }).hide().appendTo('body');

        $(document).on('input', 'textarea[id^="fb_post_text_"]', onTextInput);
        $(document).on('keydown', 'textarea[id^="fb_post_text_"]', onTextKeydown);
        $(document).on('blur', 'textarea[id^="fb_post_text_"]', function() {
            setTimeout(hideDropdown, 150);
        });

        $dropdown.on('mousedown', 'li[data-page-id]', function(e) {
            e.preventDefault();
            insertMention({
                id: String($(this).data('page-id')),
                name: String($(this).data('page-name') || '')
            });
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('#fb-page-mention-dropdown, textarea[id^="fb_post_text_"]').length) {
                hideDropdown();
            }
        });
    });

    function onTextInput(e) {
        var textarea = e.target;
        var mention = getMentionAtCaret(textarea);

        activeTextarea = textarea;
        activeMention = mention;

        if (!mention) {
            hideDropdown();
            return;
        }

        if (mention.source === 'saved') {
            scheduleSearch(mention.query, 'saved');
            return;
        }

        if (mention.query.length < GRAPH_MIN_CHARS) {
            hideDropdown();
            return;
        }

        scheduleSearch(mention.query, 'graph');
    }

    function onTextKeydown(e) {
        if (!$dropdown || !$dropdown.is(':visible')) {
            return;
        }

        var $items = $dropdown.find('li[data-page-id]');
        if (!$items.length && e.key !== 'Escape') {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIndex = Math.min(highlightedIndex + 1, $items.length - 1);
            updateHighlight($items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIndex = Math.max(highlightedIndex - 1, 0);
            updateHighlight($items);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            var $selected = $items.eq(highlightedIndex);
            if ($selected.length) {
                e.preventDefault();
                insertMention({
                    id: String($selected.data('page-id')),
                    name: String($selected.data('page-name') || '')
                });
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            hideDropdown();
        }
    }

    /**
     * Detect @[saved] or @graph mention fragment before the caret.
     * @[ takes priority so typing @[Skoring does not fall through to Graph search.
     */
    function getMentionAtCaret(textarea) {
        var pos = textarea.selectionStart;
        var before = textarea.value.substring(0, pos);

        var savedMatch = before.match(/(^|[\s])@\[([^\s\]]*)$/);
        if (savedMatch) {
            return {
                source: 'saved',
                query: savedMatch[2],
                start: pos - savedMatch[2].length - 2,
                end: pos
            };
        }

        var graphMatch = before.match(/(^|[\s])@([^\s@\[]*)$/);
        if (!graphMatch) {
            return null;
        }

        return {
            source: 'graph',
            query: graphMatch[2],
            start: pos - graphMatch[2].length - 1,
            end: pos
        };
    }

    function scheduleSearch(query, source) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            if (source === 'saved') {
                searchSavedPages(query);
            } else {
                searchGraphPages(query);
            }
        }, DEBOUNCE_MS);
    }

    function searchSavedPages(query) {
        if (currentRequest && currentRequest.readyState !== 4) {
            currentRequest.abort();
        }

        showStatus(fbPostScheduler.savedMentionSearching || 'Søger gemte sider…');

        currentRequest = $.ajax({
            url: fbPostScheduler.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_search_saved_pages',
                nonce: fbPostScheduler.nonce,
                q: query
            },
            success: function(response) {
                if (!activeMention || activeMention.source !== 'saved' || activeMention.query !== query) {
                    return;
                }

                if (response.success && $.isArray(response.data) && response.data.length) {
                    renderResults(response.data);
                } else {
                    showStatus(fbPostScheduler.savedMentionNoResults || 'Ingen gemte sider fundet');
                }
            },
            error: function(xhr) {
                if (xhr.statusText === 'abort') {
                    return;
                }
                showStatus(fbPostScheduler.savedMentionError || fbPostScheduler.ajaxError);
            }
        });
    }

    function searchGraphPages(query) {
        if (currentRequest && currentRequest.readyState !== 4) {
            currentRequest.abort();
        }

        showStatus(fbPostScheduler.mentionSearching || 'Søger...');

        currentRequest = $.ajax({
            url: fbPostScheduler.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_search_pages',
                nonce: fbPostScheduler.nonce,
                q: query
            },
            success: function(response) {
                if (!activeMention || activeMention.source !== 'graph' || activeMention.query !== query) {
                    return;
                }

                if (response.success && $.isArray(response.data) && response.data.length) {
                    renderResults(response.data);
                } else {
                    var message = (response.data && response.data.message)
                        ? response.data.message
                        : (fbPostScheduler.mentionNoResults || 'Ingen sider fundet');
                    showStatus(message);
                }
            },
            error: function(xhr) {
                if (xhr.statusText === 'abort') {
                    return;
                }
                showStatus(fbPostScheduler.mentionError || fbPostScheduler.ajaxError);
            }
        });
    }

    function renderResults(pages) {
        $dropdown.empty();
        highlightedIndex = 0;

        $.each(pages, function(index, page) {
            if (!page || !page.id || !page.name) {
                return;
            }

            var $item = $('<li>', {
                role: 'option',
                'data-page-id': page.id,
                'data-page-name': page.name
            });

            $item.append($('<span>', { class: 'fb-page-mention-name', text: page.name }));
            $item.append($('<span>', { class: 'fb-page-mention-id', text: page.id }));
            $dropdown.append($item);
        });

        if (!$dropdown.children().length) {
            var emptyMsg = (activeMention && activeMention.source === 'saved')
                ? (fbPostScheduler.savedMentionNoResults || 'Ingen gemte sider fundet')
                : (fbPostScheduler.mentionNoResults || 'Ingen sider fundet');
            showStatus(emptyMsg);
            return;
        }

        updateHighlight($dropdown.find('li[data-page-id]'));
        positionDropdown();
        $dropdown.show();
    }

    function showStatus(message) {
        $dropdown.empty().append(
            $('<li>', { class: 'fb-page-mention-status', text: message })
        );
        highlightedIndex = -1;
        positionDropdown();
        $dropdown.show();
    }

    function updateHighlight($items) {
        $items.removeClass('is-active');
        if (highlightedIndex >= 0) {
            $items.eq(highlightedIndex).addClass('is-active');
        }
    }

    function positionDropdown() {
        if (!activeTextarea) {
            return;
        }

        var $textarea = $(activeTextarea);
        var offset = $textarea.offset();

        $dropdown.css({
            top: offset.top + $textarea.outerHeight() + 2,
            left: offset.left,
            width: Math.max($textarea.outerWidth(), 220)
        });
    }

    function insertMention(page) {
        if (!activeTextarea || !activeMention || !page.id) {
            hideDropdown();
            return;
        }

        var name = String(page.name || '').replace(/[\[\]]/g, '');
        // Editor keeps :name for readability; publish strips to @[PAGE_ID].
        var insertion = '@[' + page.id + (name ? ':' + name : '') + '] ';
        var value = activeTextarea.value;
        var start = activeMention.start;
        var end = activeMention.end;

        activeTextarea.value = value.substring(0, start) + insertion + value.substring(end);

        var caret = start + insertion.length;
        activeTextarea.focus();
        activeTextarea.setSelectionRange(caret, caret);

        $(activeTextarea).trigger('input').trigger('change');
        hideDropdown();
    }

    function hideDropdown() {
        if ($dropdown) {
            $dropdown.hide().empty();
        }
        highlightedIndex = -1;
        activeMention = null;
        clearTimeout(debounceTimer);
        if (currentRequest && currentRequest.readyState !== 4) {
            currentRequest.abort();
        }
    }

})(jQuery);
