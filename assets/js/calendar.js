/**
 * Facebook Post Scheduler Calendar Scripts
 */
(function($) {
    'use strict';
    
    // Globale variabler
    var currentYear, currentMonth, currentWeek, currentWeekStart, currentView;
    var initAttempts = 0;
    var maxAttempts = 50; // Try for 5 seconds (50 * 100ms)
    var shiftHeld = false;
    var draggedPostId = null;
    var pendingPostId = null;
    var hoveredDay = null;
    var dropHandled = false;
    
    // Når dokumentet er klar
    $(document).ready(function() {
        // Initialiser kalenderen med retry logic
        tryInitCalendar();
    });
    
    /**
     * Try to initialize calendar with retry logic
     */
    function tryInitCalendar() {
        initAttempts++;
        
        // Check for data in multiple locations
        var data = null;
        if (typeof fbPostSchedulerData !== 'undefined') {
            data = fbPostSchedulerData;
        } else if (typeof window.fbPostSchedulerData !== 'undefined') {
            data = window.fbPostSchedulerData;
            // Make it globally accessible
            window.fbPostSchedulerData = data;
        }
        
        if (data) {
            // Set the global variable for use in initCalendar
            if (typeof fbPostSchedulerData === 'undefined') {
                window.fbPostSchedulerData = data;
            }
            initCalendar();
        } else if (initAttempts < maxAttempts) {
            setTimeout(tryInitCalendar, 100);
        } else {
            if ($('#fb-post-calendar').length) {
                $('#fb-post-calendar').html('<div class="notice notice-error"><p>Kalender kunne ikke indlæses. Konfigurationsdata ikke tilgængelig.</p></div>');
            }
        }
    }
    
    /**
     * Initialiserer kalenderen
     */
    function initCalendar() {
        // Get data from either location
        var data = (typeof fbPostSchedulerData !== 'undefined') ? fbPostSchedulerData : window.fbPostSchedulerData;
        
        // Check if we have the required data
        if (!data || !data.ajaxurl || !data.nonce) {
            $('#fb-post-calendar').html('<div class="notice notice-error"><p>Kalender kunne ikke indlæses. Manglende AJAX konfiguration.</p></div>');
            return;
        }
        
        // Set global reference for other functions
        if (typeof fbPostSchedulerData === 'undefined') {
            window.fbPostSchedulerData = data;
        }
        
        // Check if calendar container exists
        if ($('#fb-post-calendar').length === 0) {
            return;
        }
        
        // Sæt nuværende dato som standard
        var today = new Date();
        currentYear = today.getFullYear();
        currentMonth = today.getMonth();
        currentWeekStart = startOfWeek(today);
        currentWeek = getWeekNumber(today);
        currentView = 'month'; // Standard visning
        
        // Opret kalender UI
        createCalendarUI();
        
        // Indlæs data til kalenderen
        loadCalendarData();
        
        // Knapper til skift af måned
        $('.prev-period').on('click', function(e) {
            e.preventDefault();
            changePeriod(-1);
        });
        
        $('.next-period').on('click', function(e) {
            e.preventDefault();
            changePeriod(1);
        });
        
        $('.current-period').on('click', function(e) {
            e.preventDefault();
            var today = new Date();
            currentYear = today.getFullYear();
            currentMonth = today.getMonth();
            currentWeekStart = startOfWeek(today);
            currentWeek = getWeekNumber(today);
            refreshCalendar();
        });
        
        // Skift mellem visninger
        $('.view-month').on('click', function(e) {
            e.preventDefault();
            if (currentView !== 'month') {
                currentView = 'month';
                $(this).addClass('nav-tab-active').siblings().removeClass('nav-tab-active');
                refreshCalendar();
            }
        });
        
        $('.view-week').on('click', function(e) {
            e.preventDefault();
            if (currentView !== 'week') {
                currentView = 'week';
                $(this).addClass('nav-tab-active').siblings().removeClass('nav-tab-active');
                refreshCalendar();
            }
        });

        $(document).on('keydown.fbcalendarShift', function(e) {
            if (e.key !== 'Shift') {
                return;
            }
            shiftHeld = true;
            if (currentView === 'week') {
                $('.calendar-event.dragging').addClass('is-copy-drag');
            }
        });

        $(document).on('keyup.fbcalendarShift', function(e) {
            if (e.key !== 'Shift') {
                return;
            }
            shiftHeld = false;
            $('.calendar-event').removeClass('is-copy-drag');
        });

        $(document).on('click.fbcalendarMenu', function(e) {
            if ($(e.target).closest('.calendar-event__menu, .calendar-event__menu-toggle').length) {
                return;
            }
            closeEventMenus();
        });

        $(document).on('keydown.fbcalendarMenu', function(e) {
            if (e.key !== 'Escape') {
                return;
            }
            if (isScheduleDialogOpen()) {
                e.preventDefault();
                closeScheduleDialog();
                return;
            }
            var $open = $('.calendar-event__menu.is-open');
            if (!$open.length) {
                return;
            }
            var $event = $open.data('originEvent');
            closeEventMenus();
            if ($event && $event.length) {
                $event.find('.calendar-event__menu-toggle').trigger('focus');
            }
        });

        $(window).on('scroll.fbcalendarMenu resize.fbcalendarMenu', closeEventMenus);

        // Drop skal virke selv når markøren er over et barn af dagen.
        $(document).on('dragover.fbcalendarRoot', function(e) {
            if (!draggedPostId && !pendingPostId) {
                return;
            }
            var $day = dayUnderPointer(e);
            if ($day.length) {
                e.preventDefault();
                hoveredDay = $day[0];
                markDropDay($day);
                return;
            }
            hoveredDay = null;
            markDropDay($());
            if ($(e.target).closest('#fb-post-calendar').length) {
                e.preventDefault();
            }
        });

        $(document).on('drop.fbcalendarRoot', function(e) {
            if (dropHandled || !(draggedPostId || pendingPostId)) {
                return;
            }
            var $day = $(e.target).closest('.calendar-day');
            if (!$day.length) {
                return;
            }
            e.preventDefault();
            handleCalendarDrop($day, e);
        });
    }
    
    /**
     * Opretter kalender UI
     */
    function createCalendarUI() {
        var $calendar = $('#fb-post-calendar');
        
        // Fjern loading besked
        $calendar.empty();
        
        // Tilføj visningsfaner
        var $viewTabs = $('<div class="calendar-view-tabs nav-tab-wrapper"></div>');
        $viewTabs.append('<a href="#" class="nav-tab nav-tab-active view-month">Måned</a>');
        $viewTabs.append('<a href="#" class="nav-tab view-week">Uge</a>');
        $calendar.append($viewTabs);
        
        // Tilføj navigation
        var $nav = $('<div class="calendar-nav"></div>');
        var $navButtons = $('<div class="calendar-nav-buttons"></div>');
        $navButtons.append('<button class="button prev-period"><span class="dashicons dashicons-arrow-left-alt2"></span> Forrige</button>');
        $navButtons.append('<button class="button current-period">I dag</button>');
        $navButtons.append('<button class="button next-period">Næste <span class="dashicons dashicons-arrow-right-alt2"></span></button>');
        
        var $navMonth = $('<div class="calendar-nav-month"></div>');
        
        $nav.append($navMonth);
        $nav.append($navButtons);
        $calendar.append($nav);
        
        // Tilføj kalendergrid
        var $grid = $('<div class="calendar-grid"></div>');
        
        // Tilføj ugedage
        var weekdays = ['Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag'];
        var $header = $('<div class="calendar-header"></div>');
        
        weekdays.forEach(function(day) {
            $header.append('<div class="calendar-day-header">' + day + '</div>');
        });
        
        $grid.append($header);
        
        // Tilføj tomme rækker til kalenderen
        for (var i = 0; i < 6; i++) {
            var $week = $('<div class="calendar-week"></div>');
            
            for (var j = 0; j < 7; j++) {
                $week.append('<div class="calendar-day"><div class="calendar-date"></div><div class="calendar-events"></div></div>');
            }
            
            $grid.append($week);
        }
        
        $calendar.append($grid);
    }
    
    /**
     * Indlæser data til kalenderen
     */
    function loadCalendarData() {
        // Opdater overskrift ud fra aktuel visning
        updatePeriodHeader();
        
        // Få relevante datoer for visningen
        var days;
        
        if (currentView === 'week') {
            days = getDaysInWeek();
        } else {
            days = getDaysInMonth(currentYear, currentMonth);
        }
        
        // Få data fra serveren
        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_get_events',
                nonce: fbPostSchedulerData.nonce,
                year: currentYear,
                month: currentMonth + 1, // API forventer 1-12, JS har 0-11
                week: currentWeek,
                view_type: currentView,
                start_date: formatDate(days[0].date),
                end_date: formatDate(days[days.length - 1].date)
            },
            success: function(response) {
                if (response.success) {
                    // Opdater kalenderen med hændelser
                    renderCalendar(days, response.data);
                } else {
                    // Display error message to user instead of console
                    $('#fb-post-calendar').html('<div class="notice notice-error"><p>Fejl ved indlæsning af begivenheder. Prøv at genindlæse siden.</p></div>');
                }
            },
            error: function(xhr, status, error) {
                // Display error message to user instead of console
                $('#fb-post-calendar').html('<div class="notice notice-error"><p>Kunne ikke forbinde til serveren. Prøv igen senere.</p></div>');
            }
        });
    }
    
    /**
     * Opdaterer overskrift med måned/uge og år i navigationen
     */
    function updatePeriodHeader() {
        var monthNames = [
            'Januar', 'Februar', 'Marts', 'April', 'Maj', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'December'
        ];
        
        if (currentView === 'week') {
            // Få første og sidste dag i ugen
            var firstDay = new Date(currentWeekStart.getTime());
            var lastDay = new Date(firstDay);
            lastDay.setDate(lastDay.getDate() + 6);
            
            // Vis datoer for ugen
            var dateFormat = function(date) {
                return date.getDate() + '. ' + 
                    monthNames[date.getMonth()] + 
                    (date.getFullYear() !== firstDay.getFullYear() ? ' ' + date.getFullYear() : '');
            };
            
            if (firstDay.getMonth() === lastDay.getMonth()) {
                // Samme måned
                $('.calendar-nav-month').text(dateFormat(firstDay) + ' - ' + lastDay.getDate() + '. ' + monthNames[lastDay.getMonth()] + ' ' + lastDay.getFullYear());
            } else {
                // Forskellige måneder
                $('.calendar-nav-month').text(dateFormat(firstDay) + ' - ' + dateFormat(lastDay));
            }
        } else {
            // Månedlig visning
            $('.calendar-nav-month').text(monthNames[currentMonth] + ' ' + currentYear);
        }
    }
    
    /**
     * Få alle dage i en måned inkl. start- og slutdage fra tilstødende måneder
     */
    function getDaysInMonth(year, month) {
        var date = new Date(year, month, 1);
        var days = [];
        
        // Find den første dag i ugen for den første dag i måneden
        var firstDay = date.getDay();
        
        // Tilføj dage fra forrige måned
        var prevMonth = month - 1;
        var prevYear = year;
        
        if (prevMonth < 0) {
            prevMonth = 11;
            prevYear--;
        }
        
        var prevMonthDays = new Date(prevYear, prevMonth + 1, 0).getDate();
        
        for (var i = firstDay - 1; i >= 0; i--) {
            days.push({
                date: new Date(prevYear, prevMonth, prevMonthDays - i),
                currentMonth: false
            });
        }
        
        // Tilføj dage fra nuværende måned
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        
        for (var j = 1; j <= daysInMonth; j++) {
            days.push({
                date: new Date(year, month, j),
                currentMonth: true
            });
        }
        
        // Tilføj dage fra næste måned
        var nextMonth = month + 1;
        var nextYear = year;
        
        if (nextMonth > 11) {
            nextMonth = 0;
            nextYear++;
        }
        
        var daysNeeded = 42 - days.length; // 6 uger * 7 dage = 42
        
        for (var k = 1; k <= daysNeeded; k++) {
            days.push({
                date: new Date(nextYear, nextMonth, k),
                currentMonth: false
            });
        }
        
        return days;
    }
    
    /**
     * Få alle dage i en uge
     */
    function getDaysInWeek() {
        var firstDay = new Date(currentWeekStart.getTime());
        var days = [];
        
        // Tilføj alle 7 dage i ugen
        for (var i = 0; i < 7; i++) {
            var day = new Date(firstDay);
            day.setDate(firstDay.getDate() + i);
            
            days.push({
                date: day,
                currentMonth: true // I uge-visningen betragter vi alle dage som aktuelle
            });
        }
        
        return days;
    }
    
    /**
     * Søndag i den uge datoen ligger i. Matcher kolonnerne, som starter med søndag.
     */
    function startOfWeek(date) {
        var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        d.setDate(d.getDate() - d.getDay());
        return d;
    }

    /**
     * Få ugenummeret for en given dato
     */
    function getWeekNumber(date) {
        var d = new Date(date);
        d.setHours(0, 0, 0, 0);
        d.setDate(d.getDate() + 4 - (d.getDay() || 7));
        var yearStart = new Date(d.getFullYear(), 0, 1);
        return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    }
    
    /**
     * Renderer kalenderen med hændelser
     */
    function renderCalendar(days, events) {
        var $days = $('.calendar-day');
        
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        
        closeEventMenus();

        // Skjul overflødige rækker i ugevisning
        $('.calendar-grid').toggleClass('calendar-grid--week', currentView === 'week');
        if (currentView === 'week') {
            $('.calendar-week').addClass('is-hidden').first().removeClass('is-hidden');
        } else {
            $('.calendar-week').removeClass('is-hidden');
        }
        
        // Fjern tidligere indhold
        $days.removeClass('today other-month');
        $days.find('.calendar-date').empty();
        $days.find('.calendar-events').empty();
        
        // Tilføj ny data
        days.forEach(function(day, index) {
            try {
                var $day = $($days[index]);
                var dayDate = day.date;
                
                // Check if day element exists
                if ($day.length === 0) {
                    return;
                }
                
                // Check if date containers exist
                var $dateContainer = $day.find('.calendar-date');
                var $eventsContainer = $day.find('.calendar-events');
                
                if ($dateContainer.length === 0 || $eventsContainer.length === 0) {
                    return;
                }
                
                // Sæt dato
                $dateContainer.text(dayDate.getDate());
                
                // Markér i dag
                if (dayDate.getTime() === today.getTime()) {
                    $day.addClass('today');
                }
                
                // Markér dage udenfor nuværende måned
                if (!day.currentMonth) {
                    $day.addClass('other-month');
                }
                
                // Tilføj hændelser for denne dag
                var dayEvents = getEventsForDay(events, dayDate);
                
                dayEvents.forEach(function(event) {
                    $eventsContainer.append(buildCalendarEvent(event));
                });
                
                // Gør dagen til et drop-target
                $day.attr('data-date', formatDate(dayDate)).removeData('date');
                setupDropTarget($day);
                
            } catch (error) {
                // Skip this day silently if there's an error processing it
                return;
            }
        });
        
        // Setup drag handlers for alle events
        setupDragHandlers();
    }
    
    /**
     * Sætter drag-and-drop handlers op for calendar events
     */
    function setupDragHandlers() {
        var draggedElement = null;
        var dragPreview = null;
        var originalParent = null;
        
        // Setup drag start
        $(document).off('dragstart.fbcalendar').on('dragstart.fbcalendar', '.calendar-event[draggable="true"]', function(e) {
            if ($(e.target).closest('button, .calendar-event__menu').length) {
                e.preventDefault();
                return;
            }

            closeEventMenus();
            dropHandled = false;
            draggedPostId = String($(this).attr('data-post-id') || '');
            pendingPostId = draggedPostId;
            draggedElement = this;
            originalParent = $(this).parent();
            
            // Opret drag preview
            dragPreview = $(this).clone();
            dragPreview.addClass('drag-preview');
            dragPreview.css({
                position: 'absolute',
                top: '-1000px',
                left: '-1000px',
                zIndex: 10000
            });
            $('body').append(dragPreview);
            
            // Gem id før drag-billedet. Nogle browsere dropper dataTransfer hvis setDragImage fejler.
            e.originalEvent.dataTransfer.setData('text/plain', draggedPostId);
            e.originalEvent.dataTransfer.effectAllowed = 'copyMove';
            try {
                e.originalEvent.dataTransfer.setDragImage(dragPreview[0], 0, 0);
            } catch (dragImageError) {
                dragPreview.remove();
                dragPreview = null;
            }

            if (isShiftCopy(e)) {
                $(this).addClass('is-copy-drag');
            }
            
            // Tilføj visual feedback
            $(this).addClass('dragging');
            $('.calendar-event').not(this).addClass('drag-disabled');
            
            // Vis alle drop targets
            $('.calendar-day').addClass('drop-target');
        });
        
        // Cleanup efter drag. Hvis drop-eventet ikke fyrede, flyttes opslaget ud fra markørens position.
        $(document).off('dragend.fbcalendar').on('dragend.fbcalendar', '.calendar-event[draggable="true"]', function(e) {
            var dayEl = hoveredDay;
            var id = pendingPostId || draggedPostId;
            var shift = shiftHeld || !!(e.originalEvent && e.originalEvent.shiftKey);
            var releasedOnDay = pointerIsInside(dayEl, e);

            $('.calendar-event').removeClass('dragging drag-disabled is-copy-drag');
            $('.calendar-day').removeClass('drop-target drag-over');

            if (dragPreview) {
                dragPreview.remove();
                dragPreview = null;
            }

            draggedElement = null;
            originalParent = null;

            window.setTimeout(function() {
                if (!dropHandled && releasedOnDay && id && dayEl && document.contains(dayEl)) {
                    handleCalendarDrop($(dayEl), {
                        originalEvent: {
                            shiftKey: !!shift,
                            dataTransfer: {
                                getData: function() {
                                    return String(id);
                                }
                            }
                        }
                    });
                }
                draggedPostId = null;
                pendingPostId = null;
                hoveredDay = null;
                dropHandled = false;
            }, 0);
        });
    }

    /**
     * Markér den dag opslaget lander på, så datoen er synlig under træk.
     */
    function markDropDay($day) {
        var $current = $('.calendar-day.drag-over');
        if ($day && $day.length) {
            $current.not($day).removeClass('drag-over');
            $day.addClass('drag-over');
            return;
        }
        $current.removeClass('drag-over');
    }

    /**
     * Dagen under markøren. elementFromPoint bruges når dragover rammer selve kortet.
     */
    function dayUnderPointer(e) {
        var nativeEvent = e && e.originalEvent ? e.originalEvent : e;
        var $day = $(e.target).closest('.calendar-day');

        if ((!$day.length || $day.find('.calendar-event.dragging').length) && nativeEvent && typeof nativeEvent.clientX === 'number') {
            var under = document.elementFromPoint(nativeEvent.clientX, nativeEvent.clientY);
            if (under && under.closest) {
                var found = under.closest('.calendar-day');
                if (found) {
                    $day = $(found);
                }
            }
        }

        return $day;
    }

    /**
     * Om musen blev sluppet inden for dagen.
     */
    function pointerIsInside(el, e) {
        var nativeEvent = e && e.originalEvent ? e.originalEvent : e;
        if (!el || !nativeEvent || typeof nativeEvent.clientX !== 'number') {
            return false;
        }
        var rect = el.getBoundingClientRect();
        return nativeEvent.clientX >= rect.left && nativeEvent.clientX < rect.right && nativeEvent.clientY >= rect.top && nativeEvent.clientY < rect.bottom;
    }

    /**
     * Flyt eller kopiér det trukne opslag til dagen under markøren.
     */
    function handleCalendarDrop($day, e) {
        if (dropHandled) {
            return;
        }
        dropHandled = true;
        $('.drag-preview').remove();

        var postId = '';
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            postId = e.originalEvent.dataTransfer.getData('text/plain');
        }
        if (!postId) {
            postId = draggedPostId || pendingPostId;
        }

        var newDate = $day.attr('data-date');
        var $draggedEvent = $('.calendar-event[data-post-id="' + postId + '"]').first();
        var copying = isShiftCopy(e);

        if (!postId || !newDate) {
            $day.removeClass('drag-over');
            showNotification('Opslaget kunne ikke flyttes. Prøv at trække i kortet igen.', 'error');
            return;
        }

        var originalDate = $draggedEvent.closest('.calendar-day').attr('data-date');
        if (originalDate === newDate && !copying) {
            $day.removeClass('drag-over');
            return;
        }

        if (copying) {
            copyPostToDate(postId, newDate, $day);
            return;
        }

        movePostToDate(postId, newDate, $draggedEvent, $day);
    }
    
    /**
     * Sætter drop target op for en kalenderdag
     */
    function setupDropTarget($day) {
        $day.off('dragover.fbcalendar drop.fbcalendar dragenter.fbcalendar dragleave.fbcalendar');
        
        // Tillad drop
        $day.on('dragover.fbcalendar', function(e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = isShiftCopy(e) ? 'copy' : 'move';
        });
        
        // Visual feedback når man kommer ind over dagen
        $day.on('dragenter.fbcalendar', function(e) {
            e.preventDefault();
            hoveredDay = this;
            markDropDay($(this));
        });
        
        // Fjern visual feedback når man forlader dagen
        $day.on('dragleave.fbcalendar', function(e) {
            // Kun fjern feedback hvis vi forlader hele dagen (ikke bare et child element)
            var rect = this.getBoundingClientRect();
            var x = e.originalEvent.clientX;
            var y = e.originalEvent.clientY;
            
            if (x < rect.left || x >= rect.right || y < rect.top || y >= rect.bottom) {
                $(this).removeClass('drag-over');
            }
        });
        
        // Håndter drop
        $day.on('drop.fbcalendar', function(e) {
            e.preventDefault();
            e.stopPropagation();
            handleCalendarDrop($(this), e);
        });
    }

    /**
     * Shift under træk i ugevisning kopierer kortet i stedet for at flytte det.
     */
    function isShiftCopy(e) {
        if (currentView !== 'week') {
            return false;
        }
        var nativeEvent = e && e.originalEvent ? e.originalEvent : e;
        return shiftHeld || !!(nativeEvent && nativeEvent.shiftKey);
    }

    /**
     * Bygger et kalenderkort med udvalgt billede og handlingsmenu.
     */
    function buildCalendarEvent(event) {
        var postId = parseInt(event.post_id, 10);
        var linkedId = parseInt(event.linked_post_id, 10);
        var title = event.title || '';
        var time = event.time || '';
        var imageUrl = event.image_url || '';
        var imageAlt = event.image_alt || '';

        if (imageAlt === title) {
            imageAlt = '';
        }

        var $event = $('<div>', {
            'class': 'calendar-event draggable',
            title: (time ? time + ' – ' : '') + (event.text || title)
        });
        // wp-admin har jQuery UI, så { draggable: true } kalder .draggable() i stedet for HTML-attributten.
        $event[0].setAttribute('draggable', 'true');
        $event.attr('data-post-id', String(postId));
        $event.attr('data-id', String(postId));
        $event.attr('role', 'group');
        $event.attr('aria-label', title + (time ? ', ' + time : ''));
        $event.data('fbSchedule', {
            date: event.date || '',
            time: event.time || '',
            message: event.message != null ? String(event.message) : (event.text || ''),
            status: event.status || 'scheduled'
        });

        if (currentView === 'week') {
            $event.addClass('calendar-event--week');
        }

        if (event.status === 'posted') {
            $event.addClass('event-posted');
        }

        var $media = $('<div>', { 'class': 'calendar-event__media' });

        if (imageUrl) {
            var $image = $('<img>', {
                'class': 'calendar-event__image',
                src: imageUrl,
                alt: imageAlt
            });
            $image[0].setAttribute('draggable', 'false');
            $media.append($image);
        } else {
            $media.append(
                $('<div>', {
                    'class': 'calendar-event__image calendar-event__image--empty',
                    'aria-hidden': 'true'
                }).append($('<span>', {
                    'class': 'dashicons dashicons-format-image',
                    'aria-hidden': 'true'
                }))
            );
        }

        var $toggle = $('<button>', {
            type: 'button',
            'class': 'calendar-event__menu-toggle',
            'aria-haspopup': 'menu',
            'aria-expanded': 'false',
            'aria-label': 'Handlinger for ' + title
        }).append($('<span>', {
            'class': 'dashicons dashicons-ellipsis',
            'aria-hidden': 'true'
        }));

        var $menu = $('<div>', {
            'class': 'calendar-event__menu',
            role: 'menu',
            hidden: true
        });
        $menu.append(buildMenuAction('event-edit', 'dashicons-edit', 'Rediger'));
        $menu.append(buildMenuAction('event-schedule', 'dashicons-clock', 'Tid og tekst'));
        $menu.append(buildMenuAction('event-copy', 'dashicons-admin-page', 'Kopier'));
        $menu.append(buildMenuAction('event-delete', 'dashicons-trash', 'Slet'));
        $media.append($toggle, $menu);

        var $body = $('<div>', { 'class': 'calendar-event__body' });
        $body.append($('<div>', { 'class': 'event-title', text: title }));

        if (currentView === 'week') {
            $body.append(
                $('<div>', { 'class': 'calendar-event__time' })
                    .append($('<span>', { 'class': 'dashicons dashicons-clock', 'aria-hidden': 'true' }))
                    .append($('<span>', { text: time }))
            );
        }

        $event.append($media, $body);

        $toggle.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var isOpen = $menu.hasClass('is-open');
            closeEventMenus();
            if (!isOpen) {
                openEventMenu($event, $toggle, $menu);
            }
        });

        var pressPoint = null;
        $event.on('pointerdown', function(e) {
            if (e.button !== 0) {
                return;
            }
            pressPoint = { x: e.clientX, y: e.clientY };
        });

        $event.find('.event-title').on('click', function(e) {
            var moved = pressPoint && (Math.abs(e.clientX - pressPoint.x) > 4 || Math.abs(e.clientY - pressPoint.y) > 4);
            if (moved || dropHandled) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            window.location.href = 'post.php?post=' + linkedId + '&action=edit';
        });

        $menu.find('.event-edit').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeEventMenus();
            window.location.href = 'post.php?post=' + linkedId + '&action=edit';
        });

        $menu.find('.event-schedule').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeEventMenus();
            openScheduleDialog($event, title);
        });

        $menu.find('.event-copy').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeEventMenus();
            copyPost(postId);
        });

        $menu.find('.event-delete').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeEventMenus();
            deletePost(postId, title);
        });

        return $event;
    }

    /**
     * Menupunkt med ikon til venstre for titlen.
     */
    function buildMenuAction(actionClass, iconClass, label) {
        return $('<button>', {
            type: 'button',
            'class': 'event-action ' + actionClass,
            role: 'menuitem'
        })
            .append($('<span>', { 'class': 'dashicons ' + iconClass, 'aria-hidden': 'true' }))
            .append($('<span>', { 'class': 'event-action__label', text: label }));
    }

    var scheduleDialog = null;
    var scheduleDialogTrigger = null;

    /**
     * Om dialogen til tid og tekst er åben.
     */
    function isScheduleDialogOpen() {
        return scheduleDialog && !scheduleDialog.prop('hidden');
    }

    /**
     * Bygger dialogen én gang.
     */
    function ensureScheduleDialog() {
        if (scheduleDialog) {
            return scheduleDialog;
        }

        scheduleDialog = $('<div>', {
            'class': 'fb-calendar-dialog',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'fb-calendar-dialog-title',
            hidden: true
        });

        var $backdrop = $('<div>', { 'class': 'fb-calendar-dialog__backdrop' });
        var $panel = $('<div>', { 'class': 'fb-calendar-dialog__panel' });
        $panel.append($('<h2>', { id: 'fb-calendar-dialog-title', text: 'Tid og tekst' }));
        $panel.append($('<p>', { 'class': 'fb-calendar-dialog__subject' }));
        $panel.append($('<p>', { 'class': 'fb-calendar-dialog__note', hidden: true }));

        var $when = $('<div>', { 'class': 'fb-calendar-dialog__when' });
        var $dateLabel = $('<label>', { text: 'Dato' });
        $dateLabel.append($('<input>', { type: 'date', 'class': 'fb-calendar-dialog__date' }));
        var $timeLabel = $('<label>', { text: 'Tidspunkt' });
        $timeLabel.append($('<input>', { type: 'time', 'class': 'fb-calendar-dialog__time' }));
        $when.append($dateLabel, $timeLabel);

        var $textLabel = $('<label>', { 'class': 'fb-calendar-dialog__text-label', text: 'Opslagstekst' });
        $textLabel.append($('<textarea>', { 'class': 'fb-calendar-dialog__text', rows: 8 }));

        var $error = $('<p>', { 'class': 'fb-calendar-dialog__error', role: 'alert', hidden: true });
        var $actions = $('<div>', { 'class': 'fb-calendar-dialog__actions' });
        $actions.append($('<button>', { type: 'button', 'class': 'button button-primary fb-calendar-dialog__save', text: 'Gem' }));
        $actions.append($('<button>', { type: 'button', 'class': 'button fb-calendar-dialog__cancel', text: 'Annuller' }));

        $panel.append($when, $textLabel, $error, $actions);
        scheduleDialog.append($backdrop, $panel);
        $('body').append(scheduleDialog);

        $backdrop.on('click', closeScheduleDialog);
        scheduleDialog.find('.fb-calendar-dialog__cancel').on('click', closeScheduleDialog);
        scheduleDialog.find('.fb-calendar-dialog__save').on('click', saveScheduleDialog);

        return scheduleDialog;
    }

    /**
     * Åbn popup med tidspunkt og opslagstekst.
     */
    function openScheduleDialog($event, title) {
        var data = $event.data('fbSchedule') || {};
        var $dialog = ensureScheduleDialog();
        var locked = data.status === 'posted' || data.status === 'posting';

        scheduleDialogTrigger = $event.find('.calendar-event__menu-toggle');
        $dialog.data('postId', String($event.attr('data-post-id') || ''));
        $dialog.find('.fb-calendar-dialog__subject').text(title || '');
        $dialog.find('.fb-calendar-dialog__date').val(data.date || '').prop('disabled', locked);
        $dialog.find('.fb-calendar-dialog__time').val(data.time || '').prop('disabled', locked);
        $dialog.find('.fb-calendar-dialog__text').val(data.message || '').prop('disabled', locked);
        $dialog.find('.fb-calendar-dialog__error').prop('hidden', true).text('');
        $dialog.find('.fb-calendar-dialog__save').prop('hidden', locked).prop('disabled', false).text('Gem');
        $dialog.find('.fb-calendar-dialog__note')
            .prop('hidden', !locked)
            .text('Opslaget er allerede sendt til Facebook og kan ikke ændres her.');
        $dialog.prop('hidden', false);

        var focusTarget = locked ? '.fb-calendar-dialog__cancel' : '.fb-calendar-dialog__time';
        $dialog.find(focusTarget).trigger('focus');
    }

    /**
     * Luk popup og læg fokus tilbage på kortets menu.
     */
    function closeScheduleDialog() {
        if (!scheduleDialog) {
            return;
        }
        scheduleDialog.prop('hidden', true);
        scheduleDialog.removeData('postId');
        if (scheduleDialogTrigger && scheduleDialogTrigger.length) {
            scheduleDialogTrigger.trigger('focus');
        }
        scheduleDialogTrigger = null;
    }

    /**
     * Gem tidspunkt og tekst for det åbne opslag.
     */
    function saveScheduleDialog() {
        var $dialog = scheduleDialog;
        if (!$dialog || $dialog.prop('hidden')) {
            return;
        }

        var postId = $dialog.data('postId');
        var newDate = $dialog.find('.fb-calendar-dialog__date').val();
        var newTime = $dialog.find('.fb-calendar-dialog__time').val();
        var message = $.trim($dialog.find('.fb-calendar-dialog__text').val());
        var $error = $dialog.find('.fb-calendar-dialog__error');
        var $save = $dialog.find('.fb-calendar-dialog__save');

        if (!newDate || !newTime) {
            $error.text('Angiv både dato og tidspunkt.').prop('hidden', false);
            return;
        }
        if (!message) {
            $error.text('Skriv en tekst til opslaget.').prop('hidden', false);
            return;
        }

        $error.prop('hidden', true).text('');
        $save.prop('disabled', true).text('Gemmer…');

        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_update_schedule',
                nonce: fbPostSchedulerData.nonce,
                id: postId,
                new_date: newDate,
                new_time: newTime,
                message: message
            },
            success: function(response) {
                $save.prop('disabled', false).text('Gem');
                if (!response.success) {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Opslaget kunne ikke gemmes.';
                    $error.text(errorMsg).prop('hidden', false);
                    return;
                }
                closeScheduleDialog();
                refreshCalendar();
                showNotification(response.data && response.data.message ? response.data.message : 'Opslaget blev gemt', 'success');
            },
            error: function() {
                $save.prop('disabled', false).text('Gem');
                $error.text('Der opstod en fejl ved kommunikation med serveren. Prøv igen.').prop('hidden', false);
            }
        });
    }

    /**
     * Åbner handlingsmenuen ud fra kortet, så tabelcellen ikke klipper den.
     */
    function openEventMenu($event, $toggle, $menu) {
        $menu.data('originEvent', $event);
        $('body').append($menu);
        $menu.prop('hidden', false).addClass('is-open');
        $toggle.attr('aria-expanded', 'true');
        $menu.css({
            position: 'fixed',
            top: '0',
            left: '0',
            right: 'auto',
            width: 'max-content',
            zIndex: 100000
        });

        var rect = $toggle[0].getBoundingClientRect();
        var menuWidth = $menu.outerWidth();
        var menuHeight = $menu.outerHeight();
        var left = rect.right - menuWidth;
        var top = rect.bottom + 4;

        if (left < 8) {
            left = 8;
        }
        if (left + menuWidth > window.innerWidth - 8) {
            left = window.innerWidth - menuWidth - 8;
        }
        if (top + menuHeight > window.innerHeight - 8) {
            top = Math.max(8, rect.top - menuHeight - 4);
        }

        $menu.css({
            position: 'fixed',
            top: top + 'px',
            left: left + 'px',
            right: 'auto',
            zIndex: 100000
        });

        $menu.find('.event-action').first().trigger('focus');
    }

    /**
     * Lukker åbne handlingsmenuer og lægger dem tilbage på kortet.
     */
    function closeEventMenus() {
        $('.calendar-event__menu').each(function() {
            var $menu = $(this);
            var isPortaled = $menu.parent().is('body');
            if (!$menu.hasClass('is-open') && !isPortaled) {
                return;
            }

            var $event = $menu.data('originEvent');
            $menu.prop('hidden', true).removeClass('is-open').css({
                position: '',
                top: '',
                left: '',
                right: '',
                width: '',
                zIndex: ''
            });

            if ($event && $event.length && document.body.contains($event[0])) {
                $event.find('.calendar-event__menu-toggle').attr('aria-expanded', 'false');
                $event.find('.calendar-event__media').append($menu);
            } else if (isPortaled) {
                $menu.remove();
            }
        });
    }
    
    /**
     * Flytter et post til en ny dato via AJAX
     */
    function movePostToDate(postId, newDate, $event, $targetDay) {
        // Vis loading state
        $event.addClass('drop-loading');
        $targetDay.removeClass('drag-over').addClass('drop-target');
        
        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_move_post',
                nonce: fbPostSchedulerData.nonce,
                id: postId,
                new_date: newDate
            },
            success: function(response) {
                $event.removeClass('drop-loading');
                
                if (response.success) {
                    // Vis success feedback
                    $targetDay.removeClass('drop-target').addClass('drop-success');
                    
                    // Fjern success class efter animation
                    setTimeout(function() {
                        $targetDay.removeClass('drop-success');
                    }, 1500);
                    
                    // Genindlæs kalenderen for at vise ændringerne
                    refreshCalendar();
                    
                    // Vis succes besked
                    var message = response.data && response.data.message ? 
                        response.data.message : 'Opslaget blev flyttet succesfuldt!';
                    showNotification(message, 'success');
                    
                } else {
                    // Vis fejl feedback
                    $targetDay.removeClass('drop-target').addClass('drop-error');
                    
                    // Fjern error class efter animation
                    setTimeout(function() {
                        $targetDay.removeClass('drop-error');
                    }, 1500);
                    
                    var errorMsg = response.data && response.data.message ? 
                        response.data.message : 'Der opstod en fejl ved flytning af opslaget';
                    showNotification('Fejl: ' + errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                $event.removeClass('drop-loading');
                $targetDay.removeClass('drop-target').addClass('drop-error');
                
                setTimeout(function() {
                    $targetDay.removeClass('drop-error');
                }, 1500);
                
                showNotification('Der opstod en fejl ved kommunikation med serveren. Prøv igen senere.', 'error');
            }
        });
    }
    
    /**
     * Viser en notification til brugeren
     */
    function showNotification(message, type) {
        // Fjern tidligere notifications
        $('.fb-notification').remove();
        
        var className = type === 'success' ? 'notice-success' : 'notice-error';
        var $notification = $('<div>', {
            'class': 'notice ' + className + ' is-dismissible fb-notification'
        });
        $notification.append($('<p>', { text: message }));
        $notification.append(
            $('<button>', {
                type: 'button',
                'class': 'notice-dismiss',
                'aria-label': 'Luk denne meddelelse'
            })
        );
        
        // Tilføj til toppen af kalenderen
        $('#fb-post-calendar').prepend($notification);
        
        if (type === 'success') {
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
        
        // Håndter manuel lukning
        $notification.find('.notice-dismiss').on('click', function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    /**
     * Får events for en specifik dag
     */
    function getEventsForDay(events, date) {
        if (!events || !Array.isArray(events)) {
            return [];
        }
        
        var dateStr = formatDate(date);
        
        return events.filter(function(event) {
            // Sammenlign datoer (ignorer tid)
            var eventDate = new Date(event.date);
            var eventDateStr = formatDate(eventDate);
            
            return eventDateStr === dateStr;
        });
    }
    
    /**
     * Formaterer en dato til YYYY-MM-DD format
     */
    function formatDate(date) {
        var year = date.getFullYear();
        var month = (date.getMonth() + 1).toString().padStart(2, '0');
        var day = date.getDate().toString().padStart(2, '0');
        
        return year + '-' + month + '-' + day;
    }
    
    /**
     * Opdaterer kalenderen
     */
    function refreshCalendar() {
        loadCalendarData();
    }
    
    /**
     * Skifter periode (måned eller uge)
     */
    function changePeriod(direction) {
        if (currentView === 'week') {
            currentWeekStart = new Date(currentWeekStart.getTime());
            currentWeekStart.setDate(currentWeekStart.getDate() + (direction * 7));
            currentYear = currentWeekStart.getFullYear();
            currentMonth = currentWeekStart.getMonth();
            currentWeek = getWeekNumber(currentWeekStart);
        } else {
            currentMonth += direction;
            
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            } else if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
        }
        
        refreshCalendar();
    }
    
    /**
     * Kopierer et opslag til en valgt dag og beholder klokkeslættet.
     */
    function copyPostToDate(postId, newDate, $targetDay) {
        $targetDay.removeClass('drag-over').addClass('drop-target');

        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_copy_post',
                nonce: fbPostSchedulerData.nonce,
                id: postId,
                new_date: newDate
            },
            success: function(response) {
                if (response.success) {
                    $targetDay.removeClass('drop-target').addClass('drop-success');
                    setTimeout(function() {
                        $targetDay.removeClass('drop-success');
                    }, 1500);
                    refreshCalendar();
                    var message = response.data && response.data.message ?
                        response.data.message : 'Opslaget blev kopieret succesfuldt!';
                    showNotification(message, 'success');
                } else {
                    $targetDay.removeClass('drop-target').addClass('drop-error');
                    setTimeout(function() {
                        $targetDay.removeClass('drop-error');
                    }, 1500);
                    var errorMsg = response.data && response.data.message ?
                        response.data.message : 'Der opstod en fejl ved kopiering af opslaget';
                    showNotification('Fejl: ' + errorMsg, 'error');
                }
            },
            error: function() {
                $targetDay.removeClass('drop-target').addClass('drop-error');
                setTimeout(function() {
                    $targetDay.removeClass('drop-error');
                }, 1500);
                showNotification('Der opstod en fejl ved kommunikation med serveren. Prøv igen senere.', 'error');
            }
        });
    }

    /**
     * Kopierer et post
     */
    function copyPost(postId) {
        if (!confirm('Er du sikker på, at du vil kopiere dette opslag?')) {
            return;
        }
        
        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_copy_post',
                nonce: fbPostSchedulerData.nonce,
                id: postId
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Opslaget blev kopieret succesfuldt!', 'success');
                    refreshCalendar();
                } else {
                    var errorMsg = response.data && response.data.message ? 
                        response.data.message : 'Der opstod en fejl ved kopiering af opslaget';
                    showNotification('Fejl: ' + errorMsg, 'error');
                }
            },
            error: function() {
                showNotification('Der opstod en fejl ved kommunikation med serveren.', 'error');
            }
        });
    }
    
    /**
     * Sletter et post
     */
    function deletePost(postId, title) {
        if (!confirm('Er du sikker på, at du vil slette opslaget "' + title + '"? Denne handling kan ikke fortrydes.')) {
            return;
        }
        
        $.ajax({
            url: fbPostSchedulerData.ajaxurl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_delete_post',
                nonce: fbPostSchedulerData.nonce,
                id: postId
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Opslaget blev slettet succesfuldt!', 'success');
                    refreshCalendar();
                } else {
                    var errorMsg = response.data && response.data.message ? 
                        response.data.message : 'Der opstod en fejl ved sletning af opslaget';
                    showNotification('Fejl: ' + errorMsg, 'error');
                }
            },
            error: function() {
                showNotification('Der opstod en fejl ved kommunikation med serveren.', 'error');
            }
        });
    }

})(jQuery);
