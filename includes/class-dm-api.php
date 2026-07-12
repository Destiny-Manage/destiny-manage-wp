<?php
defined('ABSPATH') || exit;

/**
 * HTTP client for the Destiny Manage API.
 */
class DM_API {

    /**
     * Make an authenticated POST request.
     *
     * @param string $endpoint  Path after DM_API_BASE, e.g. "/wordpress/sites"
     * @param array  $body      Data to JSON-encode
     * @return array|WP_Error   Decoded response body or WP_Error
     */
    public static function post(string $endpoint, array $body): array|WP_Error {
        return self::request('POST', $endpoint, $body);
    }

    /**
     * Make an authenticated PATCH request.
     */
    public static function patch(string $endpoint, array $body): array|WP_Error {
        return self::request('PATCH', $endpoint, $body);
    }

    /**
     * Same as patch(), but retries on failure. Used for command status
     * reports specifically: these calls can land during a Destiny Manage
     * API deploy/restart (a few seconds of downtime), and losing one means
     * a command that actually finished on this site looks stuck "running"
     * forever in the dashboard with no way to recover. The update itself
     * already happened locally by the time this is called, so retrying is
     * just about making sure the result isn't lost in transit.
     */
    public static function patch_with_retry(string $endpoint, array $body, int $attempts = 3): array|WP_Error {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep(500000 * $i); // 0.5s, 1s, ... backoff between retries
            }
            $last = self::patch($endpoint, $body);
            if (!is_wp_error($last)) {
                return $last;
            }
        }
        return $last;
    }

    /**
     * Make an authenticated GET request.
     */
    public static function get(string $endpoint): array|WP_Error {
        return self::request('GET', $endpoint, []);
    }

    private static function request(string $method, string $endpoint, array $body): array|WP_Error {
        $api_key = get_option('dm_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('dm_no_key', 'No API key configured.');
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ];

        if ($method !== 'GET' && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $url      = DM_API_BASE . $endpoint;
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code >= 400) {
            $msg = $json['error']['message'] ?? "HTTP {$code}";
            return new WP_Error('dm_api_error', $msg, ['status' => $code]);
        }

        return $json ?? [];
    }

    /**
     * Make a public GET (no auth) — used for the update check.
     */
    public static function get_public(string $url): array|WP_Error {
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return $response;
        }
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        return $json ?? [];
    }
}
