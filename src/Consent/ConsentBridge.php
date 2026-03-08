<?php

namespace FPTracking\Consent;

use FPTracking\Admin\Settings;

/**
 * Bridges FP-Privacy-and-Cookie-Policy with GTM/GA4 Consent Mode v2.
 *
 * On wp_head (priority 0) outputs the gtag consent default command so that
 * GTM and GA4 receive consent state before any tag fires.
 *
 * When FP-Privacy fires fp_consent_update, outputs a gtag consent update
 * via an inline script (appended to footer).
 */
final class ConsentBridge {

    /**
     * Maps FP-Privacy service keys to Google Consent Mode v2 storage types.
     */
    private const CONSENT_MAP = [
        'analytics' => ['analytics_storage'],
        'marketing' => ['ad_storage', 'ad_user_data', 'ad_personalization'],
        'functional' => ['functionality_storage', 'personalization_storage'],
        'security'  => ['security_storage'],
    ];

    private ?array $pending_update = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Outputs gtag consent default before GTM loads.
     * Hooked to wp_head priority 0.
     */
    public function output_defaults(): void {
        $defaults = $this->get_consent_defaults();

        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "function gtag(){dataLayer.push(arguments);}\n";
        echo "gtag('consent','default'," . wp_json_encode($defaults, JSON_UNESCAPED_SLASHES) . ");\n";
        echo "window.dataLayer.push({event:'gtm.init_consent',consentDefaults:" . wp_json_encode($defaults, JSON_UNESCAPED_SLASHES) . "});\n";
        echo "</script>\n";
    }

    /**
     * Called when FP-Privacy fires fp_consent_update.
     * Queues a consent update to be output in wp_footer.
     *
     * @param array  $states   Associative array of consent states
     * @param string $event    'accept'|'reject'|'reset'
     * @param int    $revision Policy revision number
     */
    public function on_consent_update(array $states, string $event, int $revision): void {
        $update = $this->map_states_to_consent_mode($states);
        if (empty($update)) {
            return;
        }

        $json = wp_json_encode($update, JSON_UNESCAPED_SLASHES);
        add_action('wp_footer', static function () use ($json): void {
            echo "<script>\n";
            echo "if(typeof gtag==='function'){gtag('consent','update'," . $json . ");}\n";
            echo "window.dataLayer = window.dataLayer || [];\n";
            echo "window.dataLayer.push({event:'fp_consent_update',consentUpdate:" . $json . "});\n";
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
     * Maps FP-Privacy state array to Consent Mode v2 storage types.
     *
     * @param array $states e.g. ['analytics' => true, 'marketing' => false]
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
