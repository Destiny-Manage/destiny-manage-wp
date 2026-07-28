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
     * Fire-and-forget JSON delivery. Activity events are already durable in the
     * local queue before this runs, so the response is deliberately ignored;
     * the next confirmed flush retries the same idempotent event IDs.
     */
    public static function post_async(string $endpoint, array $body): bool {
        $api_key = get_option('dm_api_key', '');
        if (empty($api_key)) {
            return false;
        }
        $response = wp_remote_post(DM_API_BASE . $endpoint, [
            'headers'  => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'     => wp_json_encode($body),
            'blocking' => false,
            'timeout'  => 1,
        ]);
        return !is_wp_error($response);
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
     * POST a raw binary body (a backup archive chunk) with custom headers.
     * Used only by the backup uploader, which streams the archive to the API
     * in sequential chunks; the API forwards each chunk into the destination
     * drive's resumable-upload session.
     *
     * @param string $endpoint  Path after DM_API_BASE
     * @param string $data      Raw bytes for this chunk
     * @param array  $headers   Extra headers (offset / total size / final flag)
     */
    public static function post_binary(string $endpoint, string $data, array $headers = []): array|WP_Error {
        $api_key = get_option('dm_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('dm_no_key', 'No API key configured.');
        }

        $args = [
            'method'  => 'POST',
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/octet-stream',
                'Accept'        => 'application/json',
            ], $headers),
            'body'    => $data,
            'timeout' => 120,
        ];

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
