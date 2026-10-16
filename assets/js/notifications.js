/**
 * Handlinger på notifikationssiden og i admin-baren.
 */
(function ($) {
    'use strict';

    if (typeof fbPostSchedulerNotifications === 'undefined') {
        return;
    }

    /**
     * Bekræft sletning og massehandlinger før formularen sendes.
     *
     * @param {JQuery.TriggeredEvent} event Submit.
     */
    function confirmSubmit(event) {
        var submitter = event.originalEvent && event.originalEvent.submitter;
        if (!submitter) {
            return;
        }

        var message = submitter.getAttribute('data-confirm');
        if (submitter.classList.contains('fb-notifications-bulk-apply')) {
            var form = this;
            var actionField = form.querySelector('.fb-notifications-bulk-action');
            var selected = actionField ? actionField.value : '';
            if (!selected) {
                event.preventDefault();
                window.alert(fbPostSchedulerNotifications.bulkNoAction);
                return;
            }

            var checked = form.querySelectorAll('input[name="notification_ids[]"]:checked').length;
            if (!checked) {
                event.preventDefault();
                window.alert(fbPostSchedulerNotifications.bulkNone);
                return;
            }

            if (selected === 'delete') {
                message = fbPostSchedulerNotifications.bulkDeleteConfirm;
            }
        }

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    }

    /**
     * Vælg alle rækker i notifikationslisten.
     *
     * @param {JQuery.TriggeredEvent} event Change.
     */
    function toggleAll() {
        var form = this.closest('form');
        if (!form) {
            return;
        }
        form.querySelectorAll('input[name="notification_ids[]"]').forEach(function (box) {
            box.checked = this.checked;
        }, this);
    }

    $(document).on('submit', '.fb-notifications-form', confirmSubmit);
    $(document).on('change', '.fb-notifications-select-all', toggleAll);

    $(document).on('click', '#wp-admin-bar-fb-post-scheduler-notifications-mark-all > a', function (event) {
        event.preventDefault();

        $.ajax({
            url: fbPostSchedulerNotifications.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_mark_all_notifications_read',
                nonce: fbPostSchedulerNotifications.nonce
            }
        }).done(function (response) {
            if (response.success) {
                window.location.reload();
                return;
            }
            window.alert(fbPostSchedulerNotifications.ajaxError);
        }).fail(function () {
            window.alert(fbPostSchedulerNotifications.ajaxError);
        });
    });
}(jQuery));
