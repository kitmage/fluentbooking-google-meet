<?php
/**
 * Plugin Name: FluentBooking Google Meet Links
 * Description: Create a unique Google Meet link for selected FluentBooking appointments while leaving Apple/iCloud Calendar authoritative.
 * Version: 1.0.1
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: fluentbooking-google-meet
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FBGM_VERSION', '1.0.1');
define('FBGM_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once FBGM_PLUGIN_DIR . 'includes/class-fbgm-storage.php';
require_once FBGM_PLUGIN_DIR . 'includes/class-fbgm-google.php';
require_once FBGM_PLUGIN_DIR . 'includes/class-fbgm-bookings.php';
require_once FBGM_PLUGIN_DIR . 'includes/class-fbgm-admin.php';

add_action('plugins_loaded', function () {
    $google = new FBGM_Google();
    new FBGM_Admin($google);
    new FBGM_Bookings($google);
});