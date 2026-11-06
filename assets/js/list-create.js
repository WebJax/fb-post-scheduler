/**
 * Opret et Facebook-opslag fra indlægsoversigten.
 */
(function () {
    'use strict';

    /**
     * @param {HTMLElement} dialog
     * @param {HTMLButtonElement} okButton
     * @param {HTMLButtonElement} cancelButton
     * @param {HTMLInputElement} dateInput
     * @param {HTMLInputElement} timeInput
     * @param {boolean} busy
     */
    function setBusy(dialog, okButton, cancelButton, dateInput, timeInput, busy) {
        dialog.dataset.busy = busy ? '1' : '0';
        okButton.disabled = busy;
        cancelButton.disabled = busy;
        dateInput.disabled = busy;
        timeInput.disabled = busy;
        okButton.setAttribute('aria-busy', busy ? 'true' : 'false');

        const spinner = okButton.querySelector('.spinner');
        if (spinner) {
            spinner.classList.toggle('is-active', busy);
        }
    }

    /**
     * @param {HTMLElement} errorEl
     * @param {string} message
     */
    function setError(errorEl, message) {
        if (!message) {
            errorEl.hidden = true;
            errorEl.textContent = '';
            return;
        }

        errorEl.hidden = false;
        errorEl.textContent = message;
    }

    /**
     * @param {string} message
     * @param {string} type
     */
    function showNotice(message, type) {
        const existing = document.querySelector('.fb-list-create-notice');
        if (existing) {
            existing.remove();
        }

        const notice = document.createElement('div');
        notice.className = `notice notice-${type} is-dismissible fb-list-create-notice`;
        const paragraph = document.createElement('p');
        paragraph.textContent = message;
        notice.appendChild(paragraph);

        const headerEnd = document.querySelector('.wp-header-end');
        if (headerEnd) {
            headerEnd.insertAdjacentElement('afterend', notice);
        }

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(message);
        }
    }

    /**
     * @param {string} postId
     * @param {string} html
     */
    function updateColumn(postId, html) {
        if (!html) {
            return;
        }

        const row = document.getElementById(`post-${postId}`);
        const cell = row ? row.querySelector('.column-fb_schedule_count') : null;
        if (cell) {
            cell.innerHTML = html;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const config = window.fbListCreate;
        const dialog = document.getElementById('fb-list-create-dialog');
        const form = document.getElementById('fb-list-create-form');
        const titleEl = document.getElementById('fb-list-create-post-title');
        const dateInput = document.getElementById('fb-list-create-date');
        const timeInput = document.getElementById('fb-list-create-time');
        const errorEl = document.getElementById('fb-list-create-error');
        const okButton = document.getElementById('fb-list-create-ok');
        const cancelButton = document.getElementById('fb-list-create-cancel');

        if (!config || !(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) {
            return;
        }

        if (!(dateInput instanceof HTMLInputElement) || !(timeInput instanceof HTMLInputElement)) {
            return;
        }

        if (!(okButton instanceof HTMLButtonElement) || !(cancelButton instanceof HTMLButtonElement) || !(errorEl instanceof HTMLElement) || !(titleEl instanceof HTMLElement)) {
            return;
        }

        /**
         * @param {HTMLButtonElement} button
         */
        function openDialog(button) {
            dialog.dataset.postId = button.dataset.postId || '';
            titleEl.textContent = button.dataset.postTitle || '';
            dateInput.value = config.defaultDate || '';
            timeInput.value = config.defaultTime || '12:00';
            setError(errorEl, '');
            setBusy(dialog, okButton, cancelButton, dateInput, timeInput, false);

            if (!dialog.open) {
                dialog.showModal();
            }

            dateInput.focus();
        }

        document.addEventListener('click', function (event) {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const button = target.closest('.fb-create-list-post');
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            event.preventDefault();
            openDialog(button);
        });

        cancelButton.addEventListener('click', function () {
            if (dialog.dataset.busy === '1') {
                return;
            }

            dialog.close();
        });

        dialog.addEventListener('cancel', function (event) {
            if (dialog.dataset.busy === '1') {
                event.preventDefault();
            }
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (dialog.dataset.busy === '1') {
                return;
            }

            const postId = dialog.dataset.postId || '';
            const date = dateInput.value;
            const time = timeInput.value;

            if (!postId || !date || !time) {
                setError(errorEl, config.missingDate);
                return;
            }

            const scheduled = new Date(`${date}T${time}`);
            if (!Number.isNaN(scheduled.getTime()) && scheduled.getTime() <= Date.now()) {
                if (!window.confirm(config.pastDateWarning)) {
                    return;
                }
            }

            setError(errorEl, '');
            setBusy(dialog, okButton, cancelButton, dateInput, timeInput, true);

            try {
                const body = new URLSearchParams({
                    action: 'fb_post_scheduler_create_list_post',
                    nonce: config.nonce,
                    post_id: postId,
                    date: date,
                    time: time,
                });
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body,
                });
                const payload = await response.json();

                if (!payload || payload.success !== true) {
                    const message = payload && payload.data && payload.data.message
                        ? payload.data.message
                        : config.ajaxError;
                    setError(errorEl, message);
                    return;
                }

                updateColumn(postId, payload.data.columnHtml || '');
                showNotice(payload.data.message || '', 'success');
                dialog.close();
            } catch (error) {
                setError(errorEl, config.ajaxError);
            } finally {
                setBusy(dialog, okButton, cancelButton, dateInput, timeInput, false);
            }
        });
    });
}());
