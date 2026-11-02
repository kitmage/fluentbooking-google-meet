<?php
/** Secure settings and token storage. */
if (!defined('ABSPATH')) {
    exit;
}

final class FBGM_Storage
{
    const SETTINGS_KEY = 'fbgm_settings';
    const TOKEN_KEY = 'fbgm_refresh_token';
    const ACCESS_KEY = 'fbgm_google_access_token';

    public static function settings()
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        return is_array($settings) ? $settings : [];
    }

    public static function enabled()
    {
        $settings = self::settings();
        return !empty($settings['enabled']);
    }

    public static function event_ids()
    {
        $settings = self::settings();
        return isset($settings['event_ids']) && is_array($settings['event_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', $settings['event_ids']))))
            : [];
    }

    public static function client_id()
    {
        if (defined('FBGM_GOOGLE_CLIENT_ID')) {
            return (string) FBGM_GOOGLE_CLIENT_ID;
        }
        $settings = self::settings();
        return isset($settings['client_id']) ? trim((string) $settings['client_id']) : '';
    }

    public static function client_secret()
    {
        if (defined('FBGM_GOOGLE_CLIENT_SECRET')) {
            return (string) FBGM_GOOGLE_CLIENT_SECRET;
        }
        $settings = self::settings();
        return !empty($settings['client_secret']) ? self::decrypt($settings['client_secret']) : '';
    }

    public static function has_credentials()
    {
        return self::client_id() !== '' && self::client_secret() !== '';
    }

    public static function connected()
    {
        return self::refresh_token() !== '';
    }

    public static function refresh_token()
    {
        return self::decrypt(get_option(self::TOKEN_KEY, ''));
    }

    public static function set_refresh_token($token)
    {
        $ciphertext = self::encrypt($token);
        if ($ciphertext === '') {
            return false;
        }
        update_option(self::TOKEN_KEY, $ciphertext, false);
        delete_transient(self::ACCESS_KEY);
        return true;
    }

    public static function disconnect()
    {
        delete_option(self::TOKEN_KEY);
        delete_transient(self::ACCESS_KEY);
    }

    public static function encrypt($plaintext)
    {
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes') || $plaintext === '') {
            return '';
        }
        try {
            $iv = random_bytes(12);
        } catch (Exception $e) {
            return '';
        }
        $tag = '';
        $ciphertext = openssl_encrypt((string) $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== 16) {
            return '';
        }
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt($payload)
    {
        if (!is_string($payload) || strpos($payload, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    private static function key()
    {
        // Rotating WordPress salts intentionally invalidates saved tokens; reconnect afterward.
        return hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth'), true);
    }
}