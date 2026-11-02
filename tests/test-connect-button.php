<?php
/** Standalone Connect Google button regression: php tests/test-connect-button.php */
define('ABSPATH', __DIR__ . '/');
class FBGM_Storage {
    public static $configured = false;
    public static function settings() { return []; }
    public static function enabled() { return true; }
    public static function connected() { return false; }
    public static function event_ids() { return [3]; }
    public static function client_id() { return self::$configured ? 'test.apps.googleusercontent.com' : ''; }
    public static function client_secret() { return self::$configured ? 'test-secret' : ''; }
    public static function has_credentials() { return self::$configured; }
}
class Mock_Google {
    public function redirect_uri() { return 'https://example.org/wp-admin/admin-post.php?action=fbgm_oauth_callback'; }
}
function add_action() {}
function current_user_can() { return true; }
function admin_url($path = '') { return 'https://example.org/wp-admin/' . $path; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return esc_attr($s); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" />'; }
function checked($v) { if ($v) echo 'checked="checked"'; }
function disabled($v) { if ($v) echo 'disabled="disabled"'; }
// Mirrors WordPress get_submit_button() behavior: even false attribute values are emitted.
function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true, $attributes = '') {
    $attrs = '';
    if (is_array($attributes)) {
        foreach ($attributes as $attribute => $value) {
            $attrs .= $attribute . '="' . esc_attr($value) . '" ';
        }
    }
    echo '<input type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($text) . '" ' . $attrs . '/>';
}
require_once dirname(__DIR__) . '/includes/class-fbgm-admin.php';
$admin = new FBGM_Admin(new Mock_Google());
foreach ([false, true] as $credentials) {
    FBGM_Storage::$configured = $credentials;
    ob_start();
    $admin->page();
    $html = ob_get_clean();
    if (!preg_match('~<input[^>]*value="Connect Google"[^>]*>~', $html, $match)) {
        fwrite(STDERR, "FAIL: Connect Google button missing\n");
        exit(1);
    }
    $disabled = preg_match('~\sdisabled(?:\s|=|>)~', $match[0]) === 1;
    if ($disabled !== !$credentials) {
        fwrite(STDERR, "FAIL: disabled state incorrect for credentials=" . (int)$credentials . "\n");
        exit(1);
    }
}
echo "PASS: Connect Google is enabled exactly when OAuth credentials are present.\n";
