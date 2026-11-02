<?php
/** WordPress admin UI, OAuth callback, and manual retry actions. */
if (!defined('ABSPATH')) {
    exit;
}

class FBGM_Admin
{
    private $google;

    public function __construct($google)
    {
        $this->google = $google;
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_fbgm_save', [$this, 'save']);
        add_action('admin_post_fbgm_connect', [$this, 'connect']);
        add_action('admin_post_fbgm_oauth_callback', [$this, 'callback']);
        add_action('admin_post_fbgm_disconnect', [$this, 'disconnect']);
        add_action('admin_post_fbgm_retry', [$this, 'retry']);
    }

    private function require_admin($nonce_action = '')
    {
        if (!current_user_can('manage_options')) {
            wp_die('Only WordPress administrators can manage Google Meet authentication.', '', ['response' => 403]);
        }
        if ($nonce_action !== '') {
            check_admin_referer($nonce_action);
        }
    }

    private function url($notice = '')
    {
        $url = admin_url('options-general.php?page=fbgm-settings');
        return $notice !== '' ? add_query_arg('fbgm_notice', $notice, $url) : $url;
    }

    private function finish($notice)
    {
        wp_safe_redirect($this->url($notice));
        exit;
    }

    public function menu()
    {
        add_options_page('FluentBooking Google Meet', 'FluentBooking Google Meet', 'manage_options', 'fbgm-settings', [$this, 'page']);
    }

    public function save()
    {
        $this->require_admin('fbgm_save');
        $settings = FBGM_Storage::settings();
        $old_id = FBGM_Storage::client_id();
        $new_id = defined('FBGM_GOOGLE_CLIENT_ID') ? $old_id : sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $settings['client_id'] = trim($new_id);
        $ids = sanitize_text_field(wp_unslash($_POST['event_ids'] ?? ''));
        $settings['event_ids'] = array_values(array_unique(array_filter(array_map('absint', preg_split('/[\s,]+/', $ids)))));
        $settings['enabled'] = isset($_POST['enabled']) ? 1 : 0;
        $changed = $old_id !== $new_id;
        $secret = isset($_POST['client_secret']) ? trim((string) wp_unslash($_POST['client_secret'])) : '';
        if (!defined('FBGM_GOOGLE_CLIENT_SECRET') && $secret !== '') {
            $ciphertext = FBGM_Storage::encrypt($secret);
            if ($ciphertext === '') {
                $this->finish('encryption_failed');
            }
            $settings['client_secret'] = $ciphertext;
            $changed = true;
        }
        update_option(FBGM_Storage::SETTINGS_KEY, $settings, false);
        if ($changed) {
            FBGM_Storage::disconnect();
        }
        $this->finish($changed ? 'saved_reconnect' : 'saved');
    }

    public function connect()
    {
        $this->require_admin('fbgm_connect');
        if (!FBGM_Storage::has_credentials()) {
            $this->finish('credentials_missing');
        }
        try {
            $state = bin2hex(random_bytes(24));
        } catch (Exception $e) {
            $this->finish('random_failed');
        }
        set_transient('fbgm_oauth_' . hash('sha256', $state), get_current_user_id(), 10 * MINUTE_IN_SECONDS);
        wp_redirect(esc_url_raw($this->google->authorization_url($state)));
        exit;
    }

    public function callback()
    {
        $this->require_admin();
        $state = isset($_GET['state']) && is_string($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (strlen($state) !== 48 || !ctype_xdigit($state)) {
            $this->finish('bad_state');
        }
        $key = 'fbgm_oauth_' . hash('sha256', $state);
        $user_id = get_transient($key);
        delete_transient($key);
        if ((int) $user_id !== get_current_user_id()) {
            $this->finish('bad_state');
        }
        if (isset($_GET['error'])) {
            $this->finish('consent_denied');
        }
        $code = isset($_GET['code']) && is_string($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if ($code === '') {
            $this->finish('code_missing');
        }
        $result = $this->google->exchange_code($code);
        $this->finish(is_wp_error($result) ? 'connect_failed' : 'connected');
    }

    public function disconnect()
    {
        $this->require_admin('fbgm_disconnect');
        FBGM_Storage::disconnect();
        $this->finish('disconnected');
    }

    public function retry()
    {
        $this->require_admin('fbgm_retry');
        $id = absint($_POST['booking_id'] ?? 0);
        if (!$id || !class_exists('FluentBooking\\App\\Models\\Booking')) {
            $this->finish('booking_missing');
        }
        $booking = \FluentBooking\App\Models\Booking::find($id);
        if (!$booking) {
            $this->finish('booking_missing');
        }
        $service = new FBGM_Bookings($this->google);
        $result = $service->ensure_link($booking);
        $this->finish(is_wp_error($result) ? 'retry_failed' : 'retry_success');
    }

    public function page()
    {
        $this->require_admin();
        $settings = FBGM_Storage::settings();
        $enabled = FBGM_Storage::enabled();
        $connected = FBGM_Storage::connected();
        $ids = implode(', ', FBGM_Storage::event_ids());
        $notices = [
            'saved' => 'Settings saved.',
            'saved_reconnect' => 'Settings saved. Credentials changed; reconnect your Google account.',
            'connected' => 'Google account connected. You can now enable selected events.',
            'disconnected' => 'Google account disconnected. Previously created Meet URLs remain on bookings.',
            'retry_success' => 'Booking has a Google Meet link.',
            'retry_failed' => 'Could not create a link for that booking. Check its fbgm_last_error booking metadata and event/location selection.',
            'booking_missing' => 'Booking not found or FluentBooking is inactive.',
            'credentials_missing' => 'Save your Google OAuth client ID and secret first.',
            'encryption_failed' => 'Secret could not be encrypted. Enable PHP OpenSSL or configure credentials in wp-config.php.',
            'connect_failed' => 'Google authorization failed. Verify client credentials, HTTPS redirect URI, API access and OAuth consent configuration.',
            'consent_denied' => 'Google consent was denied.',
            'bad_state' => 'OAuth state validation failed or expired. Try connecting again.',
            'code_missing' => 'No OAuth code was returned. Try connecting again.',
            'random_failed' => 'Could not generate a secure OAuth state.',
        ];
        $notice = isset($_GET['fbgm_notice']) ? sanitize_key(wp_unslash($_GET['fbgm_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>FluentBooking Google Meet Links</h1>
            <p>Creates a unique Google Meet space for each confirmed booking, without creating a Google Calendar event or changing Apple/iCloud availability.</p>
            <?php if ($notice && isset($notices[$notice])) : ?>
                <div class="notice <?php echo in_array($notice, ['saved','connected','retry_success'], true) ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo esc_html($notices[$notice]); ?></p></div>
            <?php endif; ?>
            <?php if (!class_exists('FluentBooking\\App\\Models\\Booking')) : ?>
                <div class="notice notice-error"><p>FluentBooking must be active for booking hooks to work.</p></div>
            <?php endif; ?>
            <h2>1. Google Cloud setup</h2>
            <p>Enable <strong>Google Meet API</strong> in your Google Cloud project. Create an <strong>OAuth 2.0 Web application</strong> client and add this exact redirect URI:</p>
            <p><code><?php echo esc_html($this->google->redirect_uri()); ?></code></p>
            <p>The OAuth consent screen must include the <code>meetings.space.created</code> scope. External apps in Google's Testing mode can have refresh tokens expire after 7 days.</p>
            <h2>2. Save settings</h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="fbgm_save" />
                <?php wp_nonce_field('fbgm_save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="fbgm-client-id">OAuth client ID</label></th><td><input class="regular-text" type="text" id="fbgm-client-id" name="client_id" value="<?php echo esc_attr(FBGM_Storage::client_id()); ?>" <?php disabled(defined('FBGM_GOOGLE_CLIENT_ID')); ?> autocomplete="off" /></td></tr>
                    <tr><th scope="row"><label for="fbgm-secret">OAuth client secret</label></th><td><input class="regular-text" type="password" id="fbgm-secret" name="client_secret" value="" <?php disabled(defined('FBGM_GOOGLE_CLIENT_SECRET')); ?> autocomplete="new-password" /> <p class="description"><?php echo FBGM_Storage::client_secret() !== '' ? 'Secret is configured. Leave blank to keep it.' : 'Not configured yet.'; ?> Encrypted at rest using WordPress salts, or use wp-config.php constants.</p></td></tr>
                    <tr><th scope="row"><label for="fbgm-events">FluentBooking event IDs</label></th><td><input class="regular-text" type="text" id="fbgm-events" name="event_ids" value="<?php echo esc_attr($ids); ?>" placeholder="12, 15" /><p class="description">Only these event types get Meet links. Use a blank Custom/Online Meeting location; existing phone, address and other links are never overwritten.</p></td></tr>
                    <tr><th scope="row">Automatic generation</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?> /> Enable Google Meet links for the selected event IDs</label></td></tr>
                </table>
                <?php submit_button('Save Google Meet Settings'); ?>
            </form>
            <h2>3. Connect Google account</h2>
            <p>Status: <strong><?php echo $connected ? 'Connected (refresh token stored)' : 'Not connected'; ?></strong>. All Meet rooms are created under the connected Google account.</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin-right:12px">
                <input type="hidden" name="action" value="fbgm_connect" /><?php wp_nonce_field('fbgm_connect'); ?>
                <?php submit_button($connected ? 'Reconnect Google' : 'Connect Google', 'secondary', 'submit', false, ['disabled' => !FBGM_Storage::has_credentials()]); ?>
            </form>
            <?php if ($connected) : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block">
                    <input type="hidden" name="action" value="fbgm_disconnect" /><?php wp_nonce_field('fbgm_disconnect'); ?>
                    <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <h2>4. Repair an appointment</h2>
            <p>If Google was unavailable when a booking was confirmed, enter its numeric booking ID to generate the missing URL. Existing links are reused; they are not replaced.</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="fbgm_retry" /><?php wp_nonce_field('fbgm_retry'); ?>
                <input type="number" min="1" name="booking_id" required placeholder="Booking ID" />
                <?php submit_button('Generate / Repair Link', 'secondary', 'submit', false); ?>
            </form>
            <p><strong>Notifications:</strong> Add <code>{{booking.location_details_text}}</code> to FluentBooking confirmation and reminder email templates. The plugin populates that normal FluentBooking location field before standard scheduling notifications.</p>
        </div>
        <?php
    }
}