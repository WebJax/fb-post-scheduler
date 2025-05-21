<?php
/**
 * Process manual post check
 * 
 * Denne fil håndterer manual tjek af planlagte Facebook-opslag
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasse til håndtering af manual tjek
 */
class FB_Post_Scheduler_Manual_Check {
    /**
     * Constructor
     */
    public function __construct() {
        // Vi bruger direkte FB_Post_Scheduler::process_manual_post_check metoden
        // Denne klasse er kun inkluderet for fremtidig udvidelse af funktionaliteten
    }
}
