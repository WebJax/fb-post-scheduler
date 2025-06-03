<?php
/**
 * Facebook API Wrapper for Facebook Post Scheduler
 * 
 * Dette wrapper-script afgør om der skal bruges den rigtige API-hjælper
 * eller testversionen, baseret på om FB_POST_SCHEDULER_TEST_MODE er defineret.
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

// Altid inkluder den rigtige API helper først, så klassen er tilgængelig
require_once dirname(__FILE__) . '/api-helper.php';

// Hvis testmode er aktiveret, inkluder test API helper
if (defined('FB_POST_SCHEDULER_TEST_MODE') && FB_POST_SCHEDULER_TEST_MODE) {
    require_once dirname(__FILE__) . '/api-helper-test.php';
    
    // Når testmode er aktiveret, overskriver vi den globale API funktion
    // med en ny funktion, der returnerer test API-objektet
    add_filter('fb_post_scheduler_api_instance', function($api) {
        return new FB_Post_Scheduler_API_Test();
    });
}
