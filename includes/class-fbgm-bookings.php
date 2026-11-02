<?php
/** FluentBooking lifecycle hooks and safe, idempotent meeting persistence. */
if (!defined('ABSPATH')) {
    exit;
}

class FBGM_Bookings
{
    private $google;

    public function __construct($google)
    {
        $this->google = $google;
        // FluentBooking fires the "pre" hook before calendar sync and notifications.
        add_action('fluent_booking/pre_after_booking_scheduled', [$this, 'on_scheduled'], 1, 3);
        // Also handle confirmations from payment/approval integrations and other scheduling paths.
        add_action('fluent_booking/after_booking_scheduled', [$this, 'on_scheduled'], 1, 3);
        add_action('fluent_booking/after_booking_rescheduled', [$this, 'on_rescheduled'], 1, 3);
    }

    public function on_scheduled($booking, $calendar_slot = null, $booking_data = null)
    {
        $this->ensure_link($booking);
    }

    public function on_rescheduled($booking, $other = null, $third = null)
    {
        $this->ensure_link($booking);
    }

    /** Safe to call multiple times for one appointment. Only explicitly enabled event IDs are eligible. */
    public function ensure_link($booking)
    {
        if (!FBGM_Storage::enabled() || !FBGM_Storage::connected() || !FBGM_Storage::has_credentials()) {
            return new WP_Error('fbgm_not_ready', 'Configure and enable the Google Meet integration first.');
        }
        if (!is_object($booking) || empty($booking->id) || empty($booking->event_id) || !method_exists($booking, 'getMeta')) {
            return new WP_Error('fbgm_booking_invalid', 'A valid FluentBooking booking is required.');
        }
        $event_ids = FBGM_Storage::event_ids();
        if (!$event_ids || !in_array(absint($booking->event_id), $event_ids, true)) {
            return new WP_Error('fbgm_event_not_enabled', 'This event type is not enabled for Meet links.');
        }
        if ($booking->status !== 'scheduled') {
            return new WP_Error('fbgm_booking_not_scheduled', 'Only confirmed scheduled bookings receive Meet links.');
        }
        if (isset($booking->event_type) && $booking->event_type === 'group') {
            return new WP_Error('fbgm_group_not_supported', 'Group event types are not supported; this integration is for one-to-one bookings.');
        }
        $existing = (string) $booking->getMeta('fbgm_meet_url', '');
        if ($existing !== '' && FBGM_Google::valid_meet_url($existing)) {
            return $this->save_location($booking, $existing);
        }
        if (!$this->can_replace_location($booking)) {
            return new WP_Error('fbgm_location_conflict', 'This booking already has a different location; it was not overwritten.');
        }

        $lock = 'fbgm_booking_lock_' . absint($booking->id);
        $now = time();
        if (!add_option($lock, (string) $now, '', false)) {
            $created = (int) get_option($lock, 0);
            if ($created > $now - 120) {
                return new WP_Error('fbgm_booking_locked', 'This booking is already being processed.');
            }
            // Recover from a crashed process. The old lock may have become stale.
            delete_option($lock);
            if (!add_option($lock, (string) $now, '', false)) {
                return new WP_Error('fbgm_booking_locked', 'This booking is already being processed.');
            }
        }

        try {
            // A preceding request might have finished while we were obtaining the lock.
            $fresh = \FluentBooking\App\Models\Booking::find(absint($booking->id));
            if (!$fresh || $fresh->status !== 'scheduled') {
                return new WP_Error('fbgm_booking_unavailable', 'The booking no longer exists or is not scheduled.');
            }
            if (isset($fresh->event_type) && $fresh->event_type === 'group') {
                return new WP_Error('fbgm_group_not_supported', 'Group event types are not supported.');
            }
            $stored = (string) $fresh->getMeta('fbgm_meet_url', '');
            if ($stored !== '' && FBGM_Google::valid_meet_url($stored)) {
                return $this->save_location($fresh, $stored);
            }
            if (!$this->can_replace_location($fresh)) {
                return new WP_Error('fbgm_location_conflict', 'This booking already has a different location; it was not overwritten.');
            }
            $result = $this->google->create_meeting();
            if (is_wp_error($result)) {
                $this->record_failure($fresh, $result->get_error_message());
                return $result;
            }
            if (!is_array($result) || empty($result['url']) || !FBGM_Google::valid_meet_url($result['url'])) {
                $error = new WP_Error('fbgm_meeting_invalid', 'The Meet API did not return a usable meeting URL.');
                $this->record_failure($fresh, $error->get_error_message());
                return $error;
            }
            // Persist URL before updating location. Recovery can reuse this URL without making another space.
            $fresh->updateMeta('fbgm_meet_url', $result['url']);
            if (!empty($result['space'])) {
                $fresh->updateMeta('fbgm_space_name', $result['space']);
            }
            $location_result = $this->save_location($fresh, $result['url']);
            if (is_wp_error($location_result)) {
                $this->record_failure($fresh, $location_result->get_error_message());
                return $location_result;
            }
            $fresh->deleteMeta('fbgm_last_error');
            // Update original reference used in the current FluentBooking hook pipeline.
            $booking->location_details = $fresh->location_details;
            do_action('fbgm/meeting_created', $fresh, $result['url']);
            return $result['url'];
        } finally {
            delete_option($lock);
        }
    }

    private function save_location($booking, $url)
    {
        $existing = is_array($booking->location_details) ? $booking->location_details : [];
        if (isset($existing['type'], $existing['online_platform_link'])
            && $existing['type'] === 'google_meet' && $existing['online_platform_link'] === $url) {
            return $url;
        }
        if (!$this->can_replace_location($booking, $url)) {
            return new WP_Error('fbgm_location_conflict', 'Another location was set on this booking; it was not overwritten.');
        }
        $booking->location_details = [
            'type' => 'google_meet',
            'online_platform_link' => $url,
            'description' => '',
        ];
        if ($booking->save() === false) {
            return new WP_Error('fbgm_location_save_failed', 'Google Meet link was created but could not be saved as the booking location. Retry this booking from plugin settings.');
        }
        return $url;
    }

    private function can_replace_location($booking, $own_url = '')
    {
        $details = is_array($booking->location_details) ? $booking->location_details : [];
        $type = isset($details['type']) ? (string) $details['type'] : '';
        $link = isset($details['online_platform_link']) ? trim((string) $details['online_platform_link']) : '';
        if ($type === '' || $type === 'custom') {
            // Do not override a physical address or deliberately entered custom location.
            return empty($details['description']);
        }
        if ($type === 'online_meeting' || $type === 'google_meet') {
            return $link === '' || ($own_url !== '' && hash_equals($own_url, $link));
        }
        return false;
    }

    private function record_failure($booking, $message)
    {
        $booking->updateMeta('fbgm_last_error', sanitize_text_field($message));
        if ((string) $booking->getMeta('fbgm_alert_sent', '') !== 'yes') {
            $booking->updateMeta('fbgm_alert_sent', 'yes');
            $admin = get_option('admin_email');
            if (is_email($admin)) {
                wp_mail(
                    $admin,
                    'Google Meet link could not be generated for FluentBooking #' . absint($booking->id),
                    'The appointment is still booked. Open Settings > FluentBooking Google Meet and retry booking #' . absint($booking->id) . '. Error: ' . sanitize_text_field($message)
                );
            }
        }
    }
}