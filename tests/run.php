<?php
/** Minimal regression tests, no WordPress installation required. */
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['options'] = [];
    $GLOBALS['transients'] = [];
    $GLOBALS['mail'] = [];
    $GLOBALS['hooks'] = [];
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
    function add_option($key, $value, $deprecated = '', $autoload = null) { if (array_key_exists($key, $GLOBALS['options'])) return false; $GLOBALS['options'][$key] = $value; return true; }
    function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
    function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
    function wp_salt($name) { return 'unit-test-only-stable-salt-' . $name; }
    function absint($number) { return abs((int) $number); }
    function sanitize_text_field($str) { return trim(strip_tags($str)); }
    function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
    function wp_mail($to, $subject, $body) { $GLOBALS['mail'][] = [$to, $subject, $body]; return true; }
    function add_action($name, $fn, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $fn; }
    function do_action($name, ...$args) { /* ignored */ }
    function wp_parse_url($url) { return parse_url($url); }
    function wp_remote_post($url, $args) {
        $GLOBALS['remote_calls'][] = [$url, $args];
        if (strpos($url, 'oauth2.googleapis.com') !== false) {
            return ['response' => ['code' => 200], 'body' => json_encode(['access_token' => 'test-access', 'expires_in' => 3600])];
        }
        return ['response' => ['code' => 200], 'body' => json_encode(['name' => 'spaces/test', 'meetingUri' => 'https://meet.google.com/mno-pqrs-tuv'])];
    }
    function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
    function wp_remote_retrieve_body($response) { return $response['body']; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WP_Error {
        private $code; private $message;
        function __construct($code, $message) { $this->code = $code; $this->message = $message; }
        function get_error_code() { return $this->code; }
        function get_error_message() { return $this->message; }
    }
}
namespace FluentBooking\App\Models {
    class Booking {
        public static $bookings = [];
        public $id; public $event_id; public $status; public $location_details = []; public $event_type = 'single';
        private $metas = []; public $saves = 0;
        public function __construct($id, $event_id, $status = 'scheduled', $location = []) {
            $this->id = $id; $this->event_id = $event_id; $this->status = $status; $this->location_details = $location;
            self::$bookings[$id] = $this;
        }
        public static function find($id) { return self::$bookings[$id] ?? null; }
        public function getMeta($key, $default = '') { return $this->metas[$key] ?? $default; }
        public function updateMeta($key, $value) { $this->metas[$key] = $value; }
        public function deleteMeta($key) { unset($this->metas[$key]); }
        public function save() { $this->saves++; return true; }
    }
}
namespace {
    require_once dirname(__DIR__) . '/includes/class-fbgm-storage.php';
    require_once dirname(__DIR__) . '/includes/class-fbgm-google.php';
    require_once dirname(__DIR__) . '/includes/class-fbgm-bookings.php';
    class FakeGoogle extends FBGM_Google {
        public $calls = 0; public $error = false;
        public function create_meeting() {
            $this->calls++;
            if ($this->error) return new WP_Error('google_error', 'Google Meet temporarily unavailable');
            return ['url' => 'https://meet.google.com/abc-defg-' . sprintf('%03d', $this->calls), 'space' => 'spaces/space123'];
        }
    }
    $checks = 0;
    function check($condition, $message) { global $checks; $checks++; if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); exit(1); } }
    $secret = FBGM_Storage::encrypt('sensitive-token');
    check(strpos($secret, 'sensitive-token') === false, 'token encrypted');
    check(FBGM_Storage::decrypt($secret) === 'sensitive-token', 'token decrypts');
    check(FBGM_Storage::decrypt('notencrypted') === '', 'reject plaintext token');
    check(FBGM_Google::valid_meet_url('https://meet.google.com/abc-defg-hij'), 'valid Meet URL');
    check(!FBGM_Google::valid_meet_url('https://meet.google.com.evil.example/abc-defg-hij'), 'reject fake host');
    check(!FBGM_Google::valid_meet_url('http://meet.google.com/abc-defg-hij'), 'HTTPS required');
    update_option(FBGM_Storage::SETTINGS_KEY, ['enabled' => 1, 'event_ids' => [12], 'client_id' => 'test-client', 'client_secret' => FBGM_Storage::encrypt('test-secret')]);
    check(FBGM_Storage::set_refresh_token('test-refresh'), 'refresh token saves');
    check(FBGM_Storage::connected(), 'connected state');
    $google = new FakeGoogle(); $plugin = new FBGM_Bookings($google);
    $booking = new \FluentBooking\App\Models\Booking(101, 12);
    $url = $plugin->ensure_link($booking);
    check($url === 'https://meet.google.com/abc-defg-001', 'created first URL');
    check($booking->location_details['type'] === 'google_meet' && $booking->location_details['online_platform_link'] === $url, 'location is Meet link');
    check($booking->getMeta('fbgm_meet_url') === $url, 'metadata saved');
    check($plugin->ensure_link($booking) === $url, 'second call is idempotent');
    check($google->calls === 1, 'no second API call');
    $pending = new \FluentBooking\App\Models\Booking(102, 12, 'pending');
    check(is_wp_error($plugin->ensure_link($pending)), 'pending not processed');
    $other = new \FluentBooking\App\Models\Booking(103, 99);
    check(is_wp_error($plugin->ensure_link($other)), 'unselected event not processed');
    $physical = new \FluentBooking\App\Models\Booking(104, 12, 'scheduled', ['type' => 'in_person_organizer', 'description' => '123 Main']);
    check(is_wp_error($plugin->ensure_link($physical)), 'physical location protected');
    $remote = new \FluentBooking\App\Models\Booking(105, 12, 'scheduled', ['type' => 'online_meeting', 'online_platform_link' => 'https://zoom.us/other']);
    check(is_wp_error($plugin->ensure_link($remote)), 'existing link protected');
    $group = new \FluentBooking\App\Models\Booking(107, 12);
    $group->event_type = 'group';
    check(is_wp_error($plugin->ensure_link($group)), 'group booking skipped rather than giving separate links to group guests');
    $failed = new \FluentBooking\App\Models\Booking(106, 12);
    $google->error = true;
    check(is_wp_error($plugin->ensure_link($failed)), 'API errors returned');
    check($failed->getMeta('fbgm_last_error') !== '', 'failure recorded');
    check(count($GLOBALS['mail']) === 0, 'no email without configured admin address');
    $google->error = false;
    $fixed = $plugin->ensure_link($failed);
    check(FBGM_Google::valid_meet_url($fixed), 'manual retry succeeds');
    check($failed->getMeta('fbgm_last_error') === '', 'retry clears old error');
    check(!get_option('fbgm_booking_lock_106'), 'lock released');
    $GLOBALS['remote_calls'] = [];
    $real_google = new FBGM_Google();
    $api = $real_google->create_meeting();
    check($api['url'] === 'https://meet.google.com/mno-pqrs-tuv', 'OAuth refresh and Meet creation');
    check(count($GLOBALS['remote_calls']) === 2, 'one token request and one Meet request');
    check(strpos($GLOBALS['remote_calls'][0][1]['body'], 'refresh_token=test-refresh') !== false, 'refresh uses expected token');
    check($GLOBALS['remote_calls'][1][1]['headers']['Authorization'] === 'Bearer test-access', 'Meet request authorized');
    $api2 = $real_google->create_meeting();
    check($api2['url'] === $api['url'] && count($GLOBALS['remote_calls']) === 3, 'cached access token reused');
    FBGM_Storage::disconnect();
    check(!FBGM_Storage::connected(), 'disconnect clears refresh token');
    echo "Passed $checks checks.\n";
}