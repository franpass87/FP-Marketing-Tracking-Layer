<?php

namespace FPTracking\Consent;

use FPTracking\Admin\Settings;

/**
 * Bridges FP-Privacy-and-Cookie-Policy with GTM/GA4 Consent Mode v2.
 *
 * - If FP-Privacy is active: output_defaults() is a no-op (FP-Privacy handles it).
 *   on_consent_update() pushes fp_consent_persisted to dataLayer for GTM re-firing.
 *
 * - If FP-Privacy is NOT active: output_defaults() outputs gtag consent default
 *   using the tracking layer's own consent_default setting (denied by default).
 */
final class ConsentBridge {

    /**
     * Maps FP-Privacy category keys to Google Consent Mode v2 storage types.
     * FP-Privacy categories: necessary, statistics, marketing, preferences.
     */
    private const CONSENT_MAP = [
        'statistics'   => ['analytics_storage'],
        'marketing'    => ['ad_storage', 'ad_user_data', 'ad_personalization'],
        'preferences'  => ['functionality_storage', 'personalization_storage'],
        'necessary'    => ['security_storage'],
    ];

    private ?array $pending_update = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Outputs gtag consent default before GTM loads.
     * Hooked to wp_head priority 0.
     *
     * Skipped if FP-Privacy is active: it already outputs consent defaults via
     * its own ConsentMode::print_defaults() at priority 1, using the user-configured
     * consent_mode_defaults from its settings. Duplicating the call would cause
     * the GTM Consent Mode initialization tag to fire twice.
     */
    public function output_defaults(): void {
        if (class_exists('FP\Privacy\Integrations\ConsentMode')) {
            // FP-Privacy is active and will handle consent defaults itself.
            return;
        }

        $defaults = $this->get_consent_defaults();
        $json     = wp_json_encode($defaults, JSON_UNESCAPED_SLASHES);

        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "function gtag(){dataLayer.push(arguments);}\n";
        echo "gtag('consent','default'," . $json . ");\n";
        echo "window.dataLayer.push({event:'gtm.init_consent',consentDefaults:" . $json . "});\n";
        echo "</script>\n";
    }

    /**
     * Called when FP-Privacy fires fp_consent_update (server-side, on REST save).
     *
     * FP-Privacy's banner.js already calls window.fpPrivacyConsent.update() in real-time
     * when the user interacts with the banner, which in turn calls gtag('consent','update').
     * This hook fires on the *next* page load (server-side rendering), so we only push
     * a dataLayer event for GTM to pick up — we do NOT re-call gtag consent update
     * to avoid double-firing on the subsequent page.
     *
     * @param array  $states   FP-Privacy category states e.g. ['statistics'=>true, 'marketing'=>false]
     * @param string $event    'accept_all'|'reject_all'|'consent'|'reset'
     * @param int    $revision Policy revision number
     */
    public function on_consent_update(array $states, string $event, int $revision): void {
        $update = $this->map_states_to_consent_mode($states);
        if (empty($update)) {
            return;
        }

        // Push a dataLayer event so GTM can react (e.g. re-fire tags after consent granted).
        // gtag consent update is already handled client-side by FP-Privacy's consent-mode.js.
        $json = wp_json_encode($update, JSON_UNESCAPED_SLASHES);
        add_action('wp_footer', static function () use ($json, $event, $revision): void {
            echo "<script>\n";
            echo "window.dataLayer = window.dataLayer || [];\n";
            echo "window.dataLayer.push({event:'fp_consent_persisted',consentUpdate:" . $json . ",consentEvent:" . wp_json_encode($event) . ",revision:" . (int) $revision . "});\n";
            echo "</script>\n";
        }, 1);
    }

    /**
     * Returns the default consent state based on plugin settings.
     */
    private function get_consent_defaults(): array {
        $default_state = $this->settings->get('consent_default', 'denied');

        return [
            'analytics_storage'     => $default_state,
            'ad_storage'            => $default_state,
            'ad_user_data'          => $default_state,
            'ad_personalization'    => $default_state,
            'functionality_storage' => 'granted',
            'security_storage'      => 'granted',
            'wait_for_update'       => 500,
        ];
    }

    /**
     * Maps FP-Privacy category states to Consent Mode v2 storage types.
     *
     * @param array $states e.g. ['statistics' => true, 'marketing' => false, 'preferences' => false]
     */
    private function map_states_to_consent_mode(array $states): array {
        $update = [];
        foreach ($states as $purpose => $granted) {
            $storage_types = self::CONSENT_MAP[$purpose] ?? [];
            foreach ($storage_types as $type) {
                $update[$type] = $granted ? 'granted' : 'denied';
            }
        }
        return $update;
    }
}
