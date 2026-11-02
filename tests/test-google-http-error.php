<?php
/** Regression for safe, actionable Google Meet HTTP 403 errors. */
define('ABSPATH', __DIR__);
function absint($value) { return abs((int) $value); }
class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
require dirname(__DIR__) . '/includes/class-fbgm-google.php';
$method = new ReflectionMethod('FBGM_Google', 'meeting_http_error');
$cases = [
    'SERVICE_DISABLED' => 'Enable the Google Meet API',
    'ACCESS_TOKEN_SCOPE_INSUFFICIENT' => 'Reconnect Google',
    'PERMISSION_DENIED' => 'Verify Google Meet API',
];
foreach ($cases as $reason => $hint) {
    $result = $method->invoke(null, 403, [
        'error' => [
            'status' => 'PERMISSION_DENIED',
            'message' => 'PRIVATE PROJECT DETAIL MUST NOT DISPLAY',
            'details' => [['reason' => $reason]],
        ],
    ]);
    $message = $result->get_error_message();
    if (strpos($message, $reason) === false || strpos($message, $hint) === false || strpos($message, 'PRIVATE PROJECT DETAIL') !== false) {
        fwrite(STDERR, "FAIL $reason: $message\n");
        exit(1);
    }
}
$invalid = $method->invoke(null, 403, [
    'error' => [
        'status' => 'PERMISSION_DENIED',
        'message' => 'PRIVATE PROJECT DETAIL MUST NOT DISPLAY',
        'details' => [['reason' => '<script>alert(1)</script>']],
    ],
]);
if (strpos($invalid->get_error_message(), '<script>') !== false || strpos($invalid->get_error_message(), 'PRIVATE') !== false) {
    fwrite(STDERR, "FAIL: untrusted payload appeared in the diagnostic\n");
    exit(1);
}
echo "Passed 4 Google error diagnostic checks.\n";
