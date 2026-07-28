<?php
defined('ABSPATH') || exit;

/**
 * Detects premium plugins whose updates are blocked by a license problem.
 *
 * A revoked, expired, or missing license makes the vendor hide the update from
 * WordPress, so get_plugin_updates() reports nothing and the plugin looks
 * "up to date" in the dashboard when a newer version actually exists.
 *
 * Two layers:
 *  - Generic: an update present in the update_plugins transient but with an
 *    empty package URL (WordPress shows "auto-update unavailable") is treated
 *    as a licensing block.
 *  - Vendor-specific: detectors that read a vendor's own cached version/license
 *    data to catch updates the vendor removed from WordPress entirely. Add more
 *    vendors by registering another detector in detectors().
 *
 * Returns a map keyed by plugin file (e.g. "gravityforms/gravityforms.php"):
 *   [ 'updateBlocked' => bool, 'blockedReason' => 'license',
 *     'licenseStatus' => 'missing'|'invalid'|'valid', 'newVersion' => string|null ]
 */
class DM_Licenses {

    /**
     * @param array $all_plugins Result of get_plugins() (keyed by plugin file).
     */
    public static function detect(array $all_plugins): array {
        $blocked = [];

        // Layer 1: generic empty-package heuristic across the update transient.
        // WordPress lists the newer version but has no download URL, which is
        // the signature of a premium update the vendor will not serve without a
        // valid license.
        $transient = get_site_transient('update_plugins');
        if ($transient && !empty($transient->response) && is_array($transient->response)) {
            foreach ($transient->response as $file => $info) {
                $new_version = is_object($info) ? ($info->new_version ?? null) : ($info['new_version'] ?? null);
                $package     = is_object($info) ? ($info->package ?? '') : ($info['package'] ?? '');
                if ($new_version && empty($package)) {
                    $blocked[$file] = [
                        'updateBlocked' => true,
                        'blockedReason' => 'license',
                        'licenseStatus' => 'invalid',
                        'newVersion'    => $new_version,
                    ];
                }
            }
        }

        // Layer 2: vendor detectors refine the status/version and catch updates
        // the vendor removed from WordPress entirely.
        foreach (self::detectors() as $detector) {
            if (!is_callable($detector)) {
                continue;
            }
            $found = call_user_func($detector, $all_plugins);
            if (is_array($found)) {
                foreach ($found as $file => $entry) {
                    $blocked[$file] = array_merge($blocked[$file] ?? [], $entry);
                }
            }
        }

        return $blocked;
    }

    /**
     * Registered vendor detectors. Each is callable(array $all_plugins): array
     * returning the same per-file shape as detect(). Add new premium families
     * here (ACF Pro, Elementor Pro, WP Rocket, ...).
     */
    private static function detectors(): array {
        return [
            [self::class, 'detect_gravity_forms'],
        ];
    }

    /**
     * Gravity Forms centralises licensing for itself and every gravityforms*
     * add-on. GFCommon::get_version_info() returns the vendor's cached latest
     * versions plus is_valid_key even when the license is invalid or revoked,
     * so we can flag held-back updates WordPress no longer knows about.
     */
    private static function detect_gravity_forms(array $all_plugins): array {
        if (!class_exists('GFCommon') || !is_callable(['GFCommon', 'get_version_info'])) {
            return [];
        }

        // Default arg uses GF's cached transient (no forced network request).
        $info = GFCommon::get_version_info();
        if (!is_array($info) || !empty($info['is_valid_key'])) {
            // No data, or the license is valid and the normal update path works.
            return [];
        }

        // GF collapses expired/revoked into is_valid_key = false; distinguish a
        // missing key so the agency knows whether to add or renew it.
        $license_status = get_option('rg_gforms_key') ? 'invalid' : 'missing';

        // Latest versions the vendor advertises, keyed by add-on slug.
        $latest = [];
        if (!empty($info['version'])) {
            $latest['gravityforms'] = $info['version'];
        }
        if (!empty($info['offerings']) && is_array($info['offerings'])) {
            foreach ($info['offerings'] as $slug => $offering) {
                $ver = is_array($offering) ? ($offering['version'] ?? null) : null;
                if ($ver) {
                    $latest[$slug] = $ver;
                }
            }
        }

        $blocked = [];
        foreach ($all_plugins as $file => $data) {
            $slug = explode('/', $file)[0];
            // Only Gravity Forms core and its add-ons are gated by this license.
            if ($slug !== 'gravityforms' && strpos($slug, 'gravityforms') !== 0) {
                continue;
            }
            $installed = $data['Version'] ?? null;
            $newest    = $latest[$slug] ?? null;
            if ($newest && $installed && version_compare((string) $newest, (string) $installed, '>')) {
                $blocked[$file] = [
                    'updateBlocked' => true,
                    'blockedReason' => 'license',
                    'licenseStatus' => $license_status,
                    'newVersion'    => $newest,
                ];
            }
        }

        return $blocked;
    }
}
