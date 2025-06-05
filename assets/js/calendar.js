/**
 * Facebook Post Scheduler Calendar Scripts
 */
(function($) {
    'use strict';
    
    // Globale variabler
    var currentYear, currentMonth, currentWeek, currentView;
    var initAttempts = 0;
    var maxAttempts = 50; // Try for 5 seconds (50 * 100ms)
    
    // Når dokumentet er klar
    $(document).ready(function() {
        console.log('Calendar script starting - Document ready');
        console.log('jQuery available:', typeof $ !== 'undefined');
        console.log('Initial fbPostSchedulerData check:', typeof fbPostSchedulerData !== 'undefined');
        
        // Initialiser kalenderen med retry logic
        tryInitCalendar();
    });
    
    /**
     * Try to initialize calendar with retry logic
     */
    function tryInitCalendar() {
        initAttempts++;
        console.log('Calendar init attempt:', initAttempts);
        
        // Check for data in multiple locations
        var data = null;
        if (typeof fbPostSchedulerData !== 'undefined') {
            data = fbPostSchedulerData;
            console.log('✓ Found fbPostSchedulerData (global)');
        } else if (typeof window.fbPostSchedulerData !== 'undefined') {
            data = window.fbPostSchedulerData;
            // Make it globally accessible
            window.fbPostSchedulerData = data;
            console.log('✓ Found window.fbPostSchedulerData (fallback)');
        }
        
        if (data) {
            console.log('✓ Data found on attempt', initAttempts, ':', data);
            // Set the global variable for use in initCalendar
            if (typeof fbPostSchedulerData === 'undefined') {
                window.fbPostSchedulerData = data;
            }
            initCalendar();
        } else if (initAttempts < maxAttempts) {
            console.log('fbPostSchedulerData not ready, retrying in 100ms...');
            setTimeout(tryInitCalendar, 100);
        } else {
            console.error('Failed to load fbPostSchedulerData after', maxAttempts, 'attempts');
            if ($('#fb-post-calendar').length) {
                $('#fb-post-calendar').html('<div class="notice notice-error"><p>Kalender kunne ikke indlæses. Konfigurationsdata ikke tilgængelig.</p></div>');
            }
        }
    }
    
    /**
     * Initialiserer kalenderen
     */
    function initCalendar() {
        console.log('=== Facebook Post Scheduler Calendar Debug ===');
        
        // Get data from either location
        var data = (typeof fbPostSchedulerData !== 'undefined') ? fbPostSchedulerData : window.fbPostSchedulerData;
        console.log('Using data:', data);
        
        // Check if we have the required data
        if (!data || !data.ajaxurl || !data.nonce) {
            console.error('Data missing required properties:', data);
            $('#fb-post-calendar').html('<div class="notice notice-error"><p>Kalender kunne ikke indlæses. Manglende AJAX konfiguration.</p></div>');
            return;
        }
        
        // Set global reference for other functions
        if (typeof fbPostSchedulerData === 'undefined') {
            window.fbPostSchedulerData = data;
        }
        
        console.log('✓ Calendar data is properly configured');
        
        // Check if calendar container exists
        if ($('#fb-post-calendar').length === 0) {
            console.error('Calendar container #fb-post-calendar not found on page');
            return;
        }
        
        console.log('✓ Calendar container found');
        
        // Sæt nuværende dato som standard
        var today = new Date();
        currentYear = today.getFullYear();
        currentMonth = today.getMonth();
        currentWeek = getWeekNumber(today);
        currentView = 'month'; // Standard visning
        
        console.log('Current date:', today);
        console.log('Current year:', currentYear);
        console.log('Current month:', currentMonth);
        console.log('Current view:', currentView);
        
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
            days = getDaysInWeek(currentYear, currentWeek);
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
                view_type: currentView
            },
            success: function(response) {
                console.log('AJAX Success Response:', response);
                if (response.success) {
                    console.log('Events received:', response.data);
                    // Opdater kalenderen med hændelser
                    renderCalendar(days, response.data);
                } else {
                    console.error('Fejl ved indlæsning af begivenheder:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX-fejl ved indlæsning af begivenheder:', {xhr, status, error});
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
            var firstDay = getFirstDayOfWeek(currentYear, currentWeek);
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
    function getDaysInWeek(year, week) {
        var firstDay = getFirstDayOfWeek(year, week);
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
     * Få første dag i en uge
     */
    function getFirstDayOfWeek(year, week) {
        var date = new Date(year, 0, 1);
        var daysOffset = date.getDay(); // 0 = Søndag, 1 = Mandag, etc.
        
        // Find mandagen i første uge
        var firstMonday = new Date(year, 0, (daysOffset <= 1) ? (2 - daysOffset) : (9 - daysOffset));
        
        if (week > 1) {
            return new Date(firstMonday.getTime() + (week - 1) * 7 * 24 * 60 * 60 * 1000);
        }
        
        return firstMonday;
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
        
        // Skjul overflødige rækker i ugevisning
        if (currentView === 'week') {
            $('.calendar-week').slice(1).hide();
        } else {
            $('.calendar-week').show();
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
                
                dayEvents.forEach(function(event, eventIndex) {
                    var $event = $('<div class="calendar-event draggable" data-post-id="' + event.post_id + '" data-id="' + event.post_id + '" draggable="true">' + 
                        '<span class="drag-handle dashicons dashicons-move"></span>' +
                        '<div class="event-title">' + event.title + '</div>' +
                        '<div class="event-actions">' +
                            '<button class="event-action event-edit" title="Rediger opslag"><span class="dashicons dashicons-edit"></span></button>' +
                            '<button class="event-action event-copy" title="Kopier opslag"><span class="dashicons dashicons-admin-page"></span></button>' +
                            '<button class="event-action event-delete" title="Slet opslag"><span class="dashicons dashicons-trash"></span></button>' +
                        '</div>' +
                    '</div>');
                    
                    // Tilføj tooltip med flere detaljer
                    $event.attr('title', event.time + ' - ' + event.text);
                    
                    // Tilføj statusklasse
                    if (event.status === 'posted') {
                        $event.addClass('event-posted');
                    }
                    
                    // Add event to events container
                    $eventsContainer.append($event);
                    
                    // Håndter klik på titel for at redigere
                    $event.find('.event-title').on('click', function(e) {
                        e.stopPropagation();
                        window.location.href = 'post.php?post=' + event.linked_post_id + '&action=edit';
                    });
                    
                    // Håndter rediger knap
                    $event.find('.event-edit').on('click', function(e) {
                        e.stopPropagation();
                        window.location.href = 'post.php?post=' + event.linked_post_id + '&action=edit';
                    });
                    
                    // Håndter kopier knap
                    $event.find('.event-copy').on('click', function(e) {
                        e.stopPropagation();
                        copyPost(event.post_id);
                    });
                    
                    // Håndter slet knap
                    $event.find('.event-delete').on('click', function(e) {
                        e.stopPropagation();
                        deletePost(event.post_id, event.title);
                    });
                });
                
                // Gør dagen til et drop-target
                $day.attr('data-date', formatDate(dayDate));
                setupDropTarget($day);
                
            } catch (error) {
                console.error('Error processing calendar day:', error);
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
            
            // Set drag image
            e.originalEvent.dataTransfer.setDragImage(dragPreview[0], 0, 0);
            
            // Gem post data
            var postId = $(this).data('post-id');
            e.originalEvent.dataTransfer.setData('text/plain', postId);
            
            // Tilføj visual feedback
            $(this).addClass('dragging');
            $('.calendar-event').not(this).addClass('drag-disabled');
            
            // Vis alle drop targets
            $('.calendar-day').addClass('drop-target');
        });
        
        // Cleanup efter drag
        $(document).off('dragend.fbcalendar').on('dragend.fbcalendar', '.calendar-event[draggable="true"]', function(e) {
            // Fjern visual feedback
            $('.calendar-event').removeClass('dragging drag-disabled');
            $('.calendar-day').removeClass('drop-target drag-over');
            
            // Fjern drag preview
            if (dragPreview) {
                dragPreview.remove();
                dragPreview = null;
            }
            
            draggedElement = null;
            originalParent = null;
        });
    }
    
    /**
     * Sætter drop target op for en kalenderdag
     */
    function setupDropTarget($day) {
        $day.off('dragover.fbcalendar drop.fbcalendar dragenter.fbcalendar dragleave.fbcalendar');
        
        // Tillad drop
        $day.on('dragover.fbcalendar', function(e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
        });
        
        // Visual feedback når man kommer ind over dagen
        $day.on('dragenter.fbcalendar', function(e) {
            e.preventDefault();
            $(this).addClass('drag-over');
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
            
            var postId = e.originalEvent.dataTransfer.getData('text/plain');
            var newDate = $(this).data('date');
            var $draggedEvent = $('.calendar-event[data-post-id="' + postId + '"]');
            
            // Tjek om vi dropper på samme dag
            var originalDate = $draggedEvent.closest('.calendar-day').data('date');
            if (originalDate === newDate) {
                $(this).removeClass('drag-over');
                return;
            }
            
            // Flyt post via AJAX
            movePostToDate(postId, newDate, $draggedEvent, $(this));
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
                
                console.error('AJAX Error:', status, error);
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
        var $notification = $('<div class="notice ' + className + ' is-dismissible fb-notification">' +
            '<p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss">' +
                '<span class="screen-reader-text">Luk denne meddelelse.</span>' +
            '</button>' +
        '</div>');
        
        // Tilføj til toppen af kalenderen
        $('#fb-post-calendar').prepend($notification);
        
        // Auto-hide efter 5 sekunder
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
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
            console.log('getEventsForDay: No events or not array:', events);
            return [];
        }
        
        var dateStr = formatDate(date);
        console.log('getEventsForDay: Looking for events on', dateStr);
        
        return events.filter(function(event) {
            // Sammenlign datoer (ignorer tid)
            var eventDate = new Date(event.date);
            var eventDateStr = formatDate(eventDate);
            
            console.log('Comparing event date', eventDateStr, 'with', dateStr, '=', eventDateStr === dateStr);
            
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
            currentWeek += direction;
            
            // Håndter årsskifte
            var weeksInYear = getWeeksInYear(currentYear);
            if (currentWeek < 1) {
                currentYear--;
                currentWeek = getWeeksInYear(currentYear);
            } else if (currentWeek > weeksInYear) {
                currentYear++;
                currentWeek = 1;
            }
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
     * Får antal uger i et år
     */
    function getWeeksInYear(year) {
        var lastDay = new Date(year, 11, 31);
        var weekNumber = getWeekNumber(lastDay);
        
        // Hvis den sidste dag i året er i uge 1, så har året 52 uger
        return weekNumber === 1 ? 52 : weekNumber;
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
