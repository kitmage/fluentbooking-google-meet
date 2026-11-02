<?php
/** Google OAuth web-server flow and Google Meet REST API client. */
if (!defined('ABSPATH')) {
    exit;
}

class FBGM_Google
{
    const SCOPE = 'https://www.googleapis.com/auth/meetings.space.created';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const CREATE_URL = 'https://meet.googleapis.com/v2/spaces';

    public function redirect_uri()
    {
        return admin_url('admin-post.php?action=fbgm_oauth_callback');
    }

    public function authorization_url($state)
    {
        return add_query_arg([
            'client_id' => FBGM_Storage::client_id(),
            'redirect_uri' => $this->redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $state,
        ], self::AUTH_URL);
    }

    public function exchange_code($code)
    {
        $data = $this->token_request([
            'code' => $code,
            'client_id' => FBGM_Storage::client_id(),
            'client_secret' => FBGM_Storage::client_secret(),
            'redirect_uri' => $this->redirect_uri(),
            'grant_type' => 'authorization_code',
        ]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data['refresh_token']) || !is_string($data['refresh_token'])) {
            return new WP_Error('fbgm_missing_refresh_token', 'Google did not return an offline refresh token. Reconnect and approve consent.');
        }
        if (!FBGM_Storage::set_refresh_token($data['refresh_token'])) {
            return new WP_Error('fbgm_token_storage', 'Could not encrypt the Google refresh token. Make sure PHP OpenSSL is enabled.');
        }
        if (!empty($data['access_token']) && is_string($data['access_token'])) {
            $this->cache_access_token($data);
        }
        return true;
    }

    public function create_meeting()
    {
        $access = $this->get_access_token();
        if (is_wp_error($access)) {
            return $access;
        }
        $response = $this->create_request($access);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
            delete_transient(FBGM_Storage::ACCESS_KEY);
            $access = $this->get_access_token();
            if (is_wp_error($access)) {
                return $access;
            }
            $response = $this->create_request($access);
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return self::meeting_http_error($code, $data);
        }
        if (!is_array($data) || empty($data['meetingUri']) || !self::valid_meet_url($data['meetingUri'])) {
            return new WP_Error('fbgm_meet_missing_url', 'Google Meet did not return a valid meeting URL.');
        }
        return [
            'url' => $data['meetingUri'],
            'space' => !empty($data['name']) && is_string($data['name']) ? sanitize_text_field($data['name']) : '',
        ];
    }

    /**
     * Extract only machine-readable Google error reasons; never expose raw API payloads.
     */
    private static function meeting_http_error($code, $data)
    {
        $reason = '';
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $error = $data['error'];
            $candidates = [];
            if (!empty($error['details']) && is_array($error['details'])) {
                foreach ($error['details'] as $detail) {
                    if (is_array($detail) && isset($detail['reason'])) {
                        $candidates[] = $detail['reason'];
                    }
                }
            }
            if (!empty($error['errors']) && is_array($error['errors'])) {
                foreach ($error['errors'] as $detail) {
                    if (is_array($detail) && isset($detail['reason'])) {
                        $candidates[] = $detail['reason'];
                    }
                }
            }
            $candidates[] = $error['reason'] ?? '';
            $candidates[] = $error['status'] ?? '';
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,79}$/D', $candidate)) {
                    $reason = strtoupper($candidate);
                    break;
                }
            }
        }

        $hint = 'Verify Google Meet API is enabled in the OAuth client project and that the connected account has permission to create Meet spaces.';
        if (in_array($reason, ['SERVICE_DISABLED', 'API_DISABLED', 'ACCESSNOTCONFIGURED', 'ACCESS_NOT_CONFIGURED'], true)) {
            $hint = 'Enable the Google Meet API in the Google Cloud project containing your OAuth client ID. Then retry the booking.';
        } else if (in_array($reason, ['ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'INSUFFICIENTPERMISSIONS', 'INSUFFICIENT_AUTHENTICATION_SCOPES'], true)) {
            $hint = 'Reconnect Google in plugin settings and approve the meetings.space.created permission.';
        } else if (in_array($reason, ['ORG_RESTRICTION', 'ORG_RESTRICTED', 'DOMAIN_POLICY'], true)) {
            $hint = 'A Google Workspace policy may block this app. Ask your Workspace administrator to authorize the required Meet API scope.';
        }

        $message = 'Google Meet API returned HTTP ' . absint($code);
        if ($reason !== '') {
            $message .= ' (Google reason: ' . $reason . ')';
        }
        return new WP_Error('fbgm_meet_http', $message . '. ' . $hint);
    }

    public static function valid_meet_url($url)
    {
        if (!is_string($url) || strlen($url) > 256) {
            return false;
        }
        $parts = wp_parse_url($url);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'], $parts['path'])
            && strtolower($parts['scheme']) === 'https'
            && strtolower($parts['host']) === 'meet.google.com'
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('~^/[a-zA-Z0-9-]{6,90}$~', $parts['path']) === 1;
    }

    private function create_request($access)
    {
        return wp_remote_post(self::CREATE_URL, [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $access,
                'Content-Type' => 'application/json',
            ],
            'body' => '{}',
        ]);
    }

    private function get_access_token()
    {
        if (!FBGM_Storage::has_credentials() || !FBGM_Storage::connected()) {
            return new WP_Error('fbgm_not_connected', 'Google Meet is not connected in plugin settings.');
        }
        $cached = get_transient(FBGM_Storage::ACCESS_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $data = $this->token_request([
            'client_id' => FBGM_Storage::client_id(),
            'client_secret' => FBGM_Storage::client_secret(),
            'refresh_token' => FBGM_Storage::refresh_token(),
            'grant_type' => 'refresh_token',
        ]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            return new WP_Error('fbgm_missing_access_token', 'Google did not return an access token. Reconnect Google.');
        }
        if (!empty($data['refresh_token']) && is_string($data['refresh_token'])) {
            FBGM_Storage::set_refresh_token($data['refresh_token']);
        }
        $this->cache_access_token($data);
        return $data['access_token'];
    }

    private function cache_access_token($data)
    {
        $expires = isset($data['expires_in']) ? absint($data['expires_in']) : 3600;
        set_transient(FBGM_Storage::ACCESS_KEY, $data['access_token'], max(60, $expires - 120));
    }

    private function token_request($fields)
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('fbgm_google_unavailable', 'Could not contact Google. Check outbound HTTPS requests and retry.');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return new WP_Error('fbgm_google_token', 'Google OAuth returned HTTP ' . $status . '. The authorization may have expired or been revoked; reconnect Google.');
        }
        return $data;
    }
}