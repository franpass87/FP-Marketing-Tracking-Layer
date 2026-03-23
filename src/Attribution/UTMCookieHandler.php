<?php

namespace FPTracking\Attribution;

use FPTracking\Admin\Settings;

/**
 * Unified UTM attribution handler.
 *
 * Replaces:
 * - FP-Experiences: TrackingHelper::readUtmCookie() + fp_exp_utm cookie
 * - FP-Restaurant-Reservations: UTMAttributionHandler + DataLayer::storeAttribution()
 *
 * Uses a single cookie: fp_tracking_utm
 * All FP plugins read attribution data via get_current_attribution().
 */
final class UTMCookieHandler {

    private const COOKIE_NAME = 'fp_tracking_utm';

    /** Standard UTM + click IDs + GA4 extended + Meta. */
    private const UTM_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
        'msclkid',
        'ttclid',
        'wbraid',
        'gbraid',
        'utm_id',
        'utm_source_platform',
        'utm_campaign_id',
        'utm_creative_format',
        'utm_marketing_tactic',
        '_fbc',
        '_fbp',
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * Captures UTM parameters from the current request and stores them in a cookie.
     * Hooked to 'init'.
     */
    public function capture(): void {
        if (is_admin()) {
            return;
        }

        $has_utm = false;
        foreach (self::UTM_PARAMS as $param) {
            if (!empty($_GET[$param])) {
                $has_utm = true;
                break;
            }
        }

        if (!$has_utm) {
            return;
        }

        $attribution = [];
        foreach (self::UTM_PARAMS as $param) {
            if (!empty($_GET[$param])) {
                $attribution[$param] = sanitize_text_field(wp_unslash($_GET[$param]));
            }
        }

        $attribution['captured_at'] = time();
        $attribution['landing_page'] = home_url(add_query_arg(null, null));

        $days    = (int) $this->settings->get('utm_cookie_days', 90);
        $expires = time() + ($days * DAY_IN_SECONDS);

        setcookie(
            self::COOKIE_NAME,
            wp_json_encode($attribution),
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );

        // Also store in $_COOKIE for current request
        $_COOKIE[self::COOKIE_NAME] = wp_json_encode($attribution);
    }

    /**
     * Returns the current attribution data from the cookie.
     * Used by ServerSideDispatcher and other FP plugins.
     *
     * @return array<string, string>
     */
    public static function get_current_attribution(): array {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return [];
        }

        $data = json_decode(wp_unslash($_COOKIE[self::COOKIE_NAME]), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Returns a specific UTM parameter from the stored attribution.
     */
    public static function get(string $param, string $default = ''): string {
        $attribution = self::get_current_attribution();
        return $attribution[$param] ?? $default;
    }
}
