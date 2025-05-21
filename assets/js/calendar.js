/**
 * Facebook Post Scheduler Calendar Scripts
 */
(function($) {
    'use strict';
    
    // Globale variabler
    var currentYear, currentMonth, currentWeek, currentView;
    
    // Når dokumentet er klar
    $(document).ready(function() {
        // Initialiser kalenderen
        initCalendar();
    });
    
    /**
     * Initialiserer kalenderen
     */
    function initCalendar() {
        // Sæt nuværende dato som standard
        var today = new Date();
        currentYear = today.getFullYear();
        currentMonth = today.getMonth();
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
                if (response.success) {
                    // Opdater kalenderen med hændelser
                    renderCalendar(days, response.data);
                } else {
                    console.error('Fejl ved indlæsning af begivenheder');
                }
            },
            error: function() {
                console.error('AJAX-fejl ved indlæsning af begivenheder');
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
            var $day = $($days[index]);
            var dayDate = day.date;
            
            // Sæt dato
            $day.find('.calendar-date').text(dayDate.getDate());
            
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
                var $event = $('<div class="calendar-event" data-post-id="' + event.post_id + '">' + event.title + '</div>');
                
                // Tilføj tooltip med flere detaljer
                $event.attr('title', event.time + ' - ' + event.text);
                
                // Tilføj statusklasse
                if (event.status === 'posted') {
                    $event.addClass('event-posted');
                }
                
                // Klik på hændelse
                $event.on('click', function() {
                    window.location.href = 'post.php?post=' + event.post_id + '&action=edit';
                });
                
                $day.find('.calendar-events').append($event);
            });
        });
    }
    
    /**
     * Få hændelser for en bestemt dag
     */
    function getEventsForDay(events, date) {
        var dateString = formatDate(date);
        return events.filter(function(event) {
            return event.date === dateString;
        });
    }
    
    /**
     * Formatér dato til YYYY-MM-DD
     */
    function formatDate(date) {
        var d = new Date(date),
            month = '' + (d.getMonth() + 1),
            day = '' + d.getDate(),
            year = d.getFullYear();
        
        if (month.length < 2) month = '0' + month;
        if (day.length < 2) day = '0' + day;
        
        return [year, month, day].join('-');
    }
    
    /**
     * Skift periode (måned eller uge)
     */
    function changePeriod(offset) {
        if (currentView === 'week') {
            // Skift uge
            var date = getFirstDayOfWeek(currentYear, currentWeek);
            date.setDate(date.getDate() + (offset * 7));
            
            currentYear = date.getFullYear();
            currentWeek = getWeekNumber(date);
        } else {
            // Skift måned
            currentMonth += offset;
            
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            } else if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
        }
        
        refreshCalendar();
    }
    
    /**
     * Genindlæs kalenderen
     */
    function refreshCalendar() {
        loadCalendarData();
    }
    
})(jQuery);
