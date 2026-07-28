<?php
defined('ABSPATH') || exit;

/**
 * Scoped request diagnostics for Destiny Manage page-health monitoring.
 *
 * When the Destiny Manage platform fetches a page of this site during a
 * page-health crawl or a post-update check, it attaches a signed
 * `X-Dm-Diagnostics: <requestId>.<hmac>` header. For that request ONLY, this
 * class records PHP warnings/notices/fatals (with file + line, so the culprit
 * plugin can be identified) into a short-lived transient that the platform
 * then collects over REST with a second signed request.
 *
 * Deliberate constraints:
 *  - Never touches WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, ini settings, or
 *    the site's debug.log. Capture is additive and scoped to one request.
 *  - The error handler chains to any previously registered handler and
 *    otherwise returns false, so PHP's normal error behaviour (display,
 *    logging, fatal handling) is completely unchanged.
 *  - Uncaught exceptions are NOT intercepted with set_exception_handler —
 *    they surface as fatals via error_get_last() in the shutdown hook, which
 *    avoids altering exception flow entirely.
 *  - The HMAC secret is sha256(api key): the platform stores exactly that
 *    hash, this site holds the raw key, so both sides can derive it and the
 *    raw key never travels. No key => diagnostics silently disabled.
 *  - Captured data is capped (10 entries, 500 chars each), stripped of
 *    ABSPATH prefixes, stored 10 minutes, and deleted after first read.
 */
class DM_Diagnostics {

    private const TRANSIENT_PREFIX = 'dm_diag_';
    private const TRANSIENT_TTL    = 600; // seconds
    private const MAX_ENTRIES      = 10;
    private const MAX_MESSAGE_LEN  = 500;

    /** @var array<int, array{type: string, message: string, file: string, line: int}> */
    private static array $entries = [];
    private static string $request_id = '';
    private static bool $active = false;

    /** Call once from the main plugin file. Cheap no-op unless the signed header is present and valid. */
    public static function boot(): void {
        $header = $_SERVER['HTTP_X_DM_DIAGNOSTICS'] ?? '';
        if ($header === '' || !is_string($header) || strlen($header) > 200) {
            return;
        }

        $parts = explode('.', $header, 2);
        if (count($parts) !== 2) {
            return;
        }
        [$request_id, $sig] = $parts;
        if (!preg_match('/^[a-f0-9]{16,64}$/', $request_id)) {
            return;
        }

        $secret = self::shared_secret();
        if ($secret === '') {
            return;
        }
        if (!hash_equals(hash_hmac('sha256', $request_id, $secret), $sig)) {
            return;
        }

        self::$request_id = $request_id;
        self::$active     = true;

        // Chain to whatever handler was already registered so behaviour is
        // unchanged; we only observe.
        $prev = set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$prev) {
            DM_Diagnostics::record(DM_Diagnostics::errno_name($errno), $errstr, $errfile, $errline);
            if (is_callable($prev)) {
                return (bool) call_user_func($prev, $errno, $errstr, $errfile, $errline);
            }
            return false; // let PHP's default handling proceed exactly as before
        });

        // Fatals (including uncaught exceptions, OOM, parse errors in included
        // files) never reach the error handler; error_get_last() at shutdown
        // does see them. This also fires on clean requests to persist warnings.
        register_shutdown_function([__CLASS__, 'persist']);
    }

    public static function record(string $type, string $message, string $file, int $line): void {
        if (!self::$active || count(self::$entries) >= self::MAX_ENTRIES) {
            return;
        }
        self::$entries[] = [
            'type'    => $type,
            'message' => substr($message, 0, self::MAX_MESSAGE_LEN),
            'file'    => self::relative_path($file),
            'line'    => $line,
        ];
    }

    /** Shutdown hook: fold in any fatal, then persist the capture. */
    public static function persist(): void {
        if (!self::$active) {
            return;
        }

        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
            // Fatals bypass the streaming cap: they are the headline finding.
            if (count(self::$entries) >= self::MAX_ENTRIES) {
                array_pop(self::$entries);
            }
            self::$entries[] = [
                'type'    => 'FATAL',
                'message' => substr($last['message'], 0, self::MAX_MESSAGE_LEN),
                'file'    => self::relative_path($last['file']),
                'line'    => (int) $last['line'],
            ];
        }

        if (count(self::$entries) === 0) {
            return; // clean request: store nothing, the read returns empty
        }

        set_transient(self::TRANSIENT_PREFIX . self::$request_id, [
            'entries' => self::$entries,
            'culprit' => self::guess_culprit(self::$entries),
        ], self::TRANSIENT_TTL);
    }

    // -------------------------------------------------------------------------
    // REST: the platform collects a capture with a second signed request
    // -------------------------------------------------------------------------

    public static function register_rest_routes(): void {
        register_rest_route('destiny-manage/v1', '/diagnostics', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_read'],
            'permission_callback' => [__CLASS__, 'verify_read'],
            'args'                => [
                'request_id' => ['type' => 'string', 'required' => true],
                'sig'        => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public static function verify_read(\WP_REST_Request $req): bool {
        $request_id = (string) $req->get_param('request_id');
        $sig        = (string) $req->get_param('sig');
        if (!preg_match('/^[a-f0-9]{16,64}$/', $request_id)) {
            return false;
        }
        $secret = self::shared_secret();
        if ($secret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', 'read:' . $request_id, $secret), $sig);
    }

    public static function handle_read(\WP_REST_Request $req): \WP_REST_Response {
        $request_id = (string) $req->get_param('request_id');
        $key        = self::TRANSIENT_PREFIX . $request_id;
        $capture    = get_transient($key);
        delete_transient($key); // single-read: collected evidence doesn't linger

        return new \WP_REST_Response([
            'ok'      => true,
            'entries' => is_array($capture) ? ($capture['entries'] ?? []) : [],
            'culprit' => is_array($capture) ? ($capture['culprit'] ?? null) : null,
        ], 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function shared_secret(): string {
        $api_key = get_option('dm_api_key', '');
        return is_string($api_key) && $api_key !== '' ? hash('sha256', $api_key) : '';
    }

    private static function relative_path(string $file): string {
        if ($file === '') {
            return '';
        }
        $relative = str_replace(ABSPATH, '', $file);
        return substr($relative, 0, 200);
    }

    /** First plugin/theme (other than this plugin) implicated by an entry's file path. */
    private static function guess_culprit(array $entries): ?array {
        foreach ($entries as $entry) {
            if (preg_match('#wp-content/plugins/([^/]+)/#', $entry['file'], $m) && $m[1] !== DM_SLUG) {
                return ['kind' => 'plugin', 'slug' => $m[1]];
            }
            if (preg_match('#wp-content/themes/([^/]+)/#', $entry['file'], $m)) {
                return ['kind' => 'theme', 'slug' => $m[1]];
            }
        }
        return null;
    }

    public static function errno_name(int $errno): string {
        return match ($errno) {
            E_WARNING, E_USER_WARNING       => 'WARNING',
            E_NOTICE, E_USER_NOTICE         => 'NOTICE',
            E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
            E_USER_ERROR                    => 'USER_ERROR',
            E_RECOVERABLE_ERROR             => 'RECOVERABLE',
            default                         => 'E' . $errno,
        };
    }
}
