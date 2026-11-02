<?php
/** Regression: failed repair shows a readable error and non-sensitive booking context. */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['fbgm_test_transients'] = [];
function add_action() {}
function current_user_can() { return true; }
function get_current_user_id() { return 7; }
function get_transient($key) { return $GLOBALS['fbgm_test_transients'][$key] ?? false; }
function set_transient($key, $value, $expiry = 0) { $GLOBALS['fbgm_test_transients'][$key] = $value; }
function admin_url($path = '') { return 'https://example.org/wp-admin/' . $path; }
function sanitize_key($str) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $str)); }
function wp_unslash($str) { return $str; }
function esc_html($str) { return htmlspecialchars((string) $str, ENT_QUOTES); }
function esc_attr($str) { return esc_html($str); }
function esc_url($str) { return esc_html($str); }
function wp_nonce_field() {}
function checked() {}
function disabled() {}
function submit_button() {}
class FBGM_Storage {
    public static function settings() { return []; }
    public static function enabled() { return true; }
    public static function connected() { return true; }
    public static function has_credentials() { return true; }
    public static function event_ids() { return [3]; }
    public static function client_id() { return 'test.apps.googleusercontent.com'; }
    public static function client_secret() { return 'must-not-be-rendered'; }
}
class FakeGoogle {
    public function redirect_uri() { return 'https://example.org/wp-admin/admin-post.php?action=fbgm_oauth_callback'; }
}
require dirname(__DIR__) . '/includes/class-fbgm-admin.php';
$_GET['fbgm_notice'] = 'retry_failed';
set_transient('fbgm_retry_diagnostic_7', [
    'booking_id' => 18,
    'event_id' => 5,
    'selected_event_ids' => '3',
    'status' => 'scheduled',
    'location_type' => 'online_meeting',
    'code' => 'fbgm_event_not_enabled',
    'message' => 'This event type is not enabled for Meet links.',
], 600);
$admin = new FBGM_Admin(new FakeGoogle());
ob_start();
$admin->page();
$html = ob_get_clean();
foreach (['Booking #18', 'fbgm_event_not_enabled', 'Selected event IDs:', 'This event type is not enabled'] as $needle) {
    if (strpos($html, $needle) === false) { fwrite(STDERR, "FAIL: missing " . $needle . "\n"); exit(1); }
}
if (strpos($html, 'must-not-be-rendered') !== false) {
    fwrite(STDERR, "FAIL: credential exposed in diagnostic\n"); exit(1);
}
echo "PASS: failed-repair details displayed without revealing OAuth secret.\n";
