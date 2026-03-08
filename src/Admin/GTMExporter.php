<?php

declare(strict_types=1);

namespace FPTracking\Admin;

/**
 * Generates a GTM container JSON export ready to import in Google Tag Manager.
 *
 * The export includes:
 *  - Variables: dataLayer variables for all event params + GA4/Ads/Meta IDs
 *  - Triggers:  one Custom Event trigger per tracked event
 *  - Tags:      GA4 Configuration, GA4 Event tags, Google Ads Conversion,
 *               Meta Pixel base + event tags, Consent Mode initialization
 */
final class GTMExporter {

    /** All events fired by the FP tracking stack */
    public const EVENTS = [
        // ── WooCommerce ──────────────────────────────────────────────────────
        'view_item'              => ['label' => 'View Item',              'type' => 'ga4'],
        'add_to_cart'            => ['label' => 'Add to Cart',            'type' => 'ga4+meta+ads'],
        'begin_checkout'         => ['label' => 'Begin Checkout',         'type' => 'ga4+meta'],
        'purchase'               => ['label' => 'Purchase',               'type' => 'ga4+meta+ads'],

        // ── Lead / Contatti ──────────────────────────────────────────────────
        'generate_lead'          => ['label' => 'Generate Lead',          'type' => 'ga4+meta+ads'],
        'click_phone'            => ['label' => 'Phone Click',            'type' => 'ga4+meta'],
        'click_whatsapp'         => ['label' => 'WhatsApp Click',         'type' => 'ga4+meta'],
        'click_email'            => ['label' => 'Email Click',            'type' => 'ga4'],
        'click_map'              => ['label' => 'Map Click',              'type' => 'ga4'],
        'click_cta'              => ['label' => 'CTA Click',              'type' => 'ga4'],
        'click_social'           => ['label' => 'Social Click',           'type' => 'ga4'],
        'click_external_link'    => ['label' => 'External Link Click',    'type' => 'ga4'],
        'sign_up'                => ['label' => 'Sign Up',                'type' => 'ga4+meta'],

        // ── Engagement (solo eventi con valore decisionale) ──────────────────
        'scroll_depth'           => ['label' => 'Scroll Depth',          'type' => 'ga4'],
        'video_complete'         => ['label' => 'Video Complete',         'type' => 'ga4'],
        'file_download'          => ['label' => 'File Download',         'type' => 'ga4'],
        'search'                 => ['label' => 'Search',                 'type' => 'ga4'],

        // ── FP-Forms ─────────────────────────────────────────────────────────
        'form_view'              => ['label' => 'Form View',              'type' => 'ga4'],
        'form_start'             => ['label' => 'Form Start',             'type' => 'ga4'],
        'form_step_complete'     => ['label' => 'Form Step Complete',     'type' => 'ga4'],
        'form_abandon'           => ['label' => 'Form Abandon',           'type' => 'ga4'],
        'form_submit_attempt'    => ['label' => 'Form Submit Attempt',    'type' => 'ga4'],
        'form_payment_started'   => ['label' => 'Form Payment Started',   'type' => 'ga4+meta+ads'],

        // ── FP-Restaurant-Reservations ───────────────────────────────────────
        'booking_form_view'         => ['label' => 'Booking Form View',         'type' => 'ga4'],
        'booking_form_start'        => ['label' => 'Booking Form Start',        'type' => 'ga4'],
        'booking_step_complete'     => ['label' => 'Booking Step Complete',     'type' => 'ga4'],
        'booking_form_abandon'      => ['label' => 'Booking Form Abandon',      'type' => 'ga4'],
        'booking_submit_error'      => ['label' => 'Booking Submit Error',      'type' => 'ga4'],
        'booking_submitted'         => ['label' => 'Booking Submitted',         'type' => 'ga4+meta'],
        'booking_confirmed'         => ['label' => 'Booking Confirmed',         'type' => 'ga4+meta+ads'],
        'booking_payment_required'  => ['label' => 'Booking Payment Required',  'type' => 'ga4'],
        'booking_payment_completed' => ['label' => 'Booking Payment Completed', 'type' => 'ga4+meta+ads'],
        'booking_cancelled'         => ['label' => 'Booking Cancelled',         'type' => 'ga4'],
        'booking_no_show'           => ['label' => 'Booking No Show',           'type' => 'ga4'],
        'booking_visited'           => ['label' => 'Booking Visited',           'type' => 'ga4'],
        'booking_moved'             => ['label' => 'Booking Moved',             'type' => 'ga4'],
        'waitlist_joined'           => ['label' => 'Waitlist Joined',           'type' => 'ga4'],
        'waitlist_promoted'         => ['label' => 'Waitlist Promoted',         'type' => 'ga4'],
        'survey_submitted'          => ['label' => 'Survey Submitted',          'type' => 'ga4'],
        'event_ticket_purchase'     => ['label' => 'Event Ticket Purchase',     'type' => 'ga4+meta+ads'],

        // ── FP-Experiences ───────────────────────────────────────────────────
        'experience_view'             => ['label' => 'Experience View',             'type' => 'ga4'],
        'experience_checkout_view'    => ['label' => 'Experience Checkout View',    'type' => 'ga4'],
        'gift_redeem_view'            => ['label' => 'Gift Redeem View',            'type' => 'ga4'],
        'booking_start'               => ['label' => 'Booking Start',               'type' => 'ga4'],
        'booking_abandon'             => ['label' => 'Booking Abandon',             'type' => 'ga4'],
        'rtb_start'                   => ['label' => 'RTB Start',                   'type' => 'ga4'],
        'gift_start'                  => ['label' => 'Gift Start',                  'type' => 'ga4'],
        'experience_checkout_started' => ['label' => 'Experience Checkout Started', 'type' => 'ga4+meta'],
        'experience_paid'             => ['label' => 'Experience Paid',             'type' => 'ga4+meta+ads'],
        'experience_cancelled'        => ['label' => 'Experience Cancelled',        'type' => 'ga4'],
        // RTB
        'rtb_submitted'               => ['label' => 'RTB Submitted',               'type' => 'ga4+meta+ads'],
        'rtb_approved'                => ['label' => 'RTB Approved',                'type' => 'ga4+meta+ads'],
        'rtb_declined'                => ['label' => 'RTB Declined',                'type' => 'ga4'],
        'rtb_hold_expired'            => ['label' => 'RTB Hold Expired',            'type' => 'ga4'],
        // Gift
        'gift_purchased'              => ['label' => 'Gift Purchased',              'type' => 'ga4+meta+ads'],
        'gift_redeemed'               => ['label' => 'Gift Redeemed',               'type' => 'ga4'],

        // ── FP-Forms (funnel) ────────────────────────────────────────────────
        'form_submit_error'           => ['label' => 'Form Submit Error',           'type' => 'ga4'],
    ];

    /** Meta event mapping: FP event → Meta standard event */
    private const META_EVENT_MAP = [
        // Acquisti / Revenue
        'purchase'                    => 'Purchase',
        'event_ticket_purchase'       => 'Purchase',
        'booking_confirmed'           => 'Purchase',
        'booking_payment_completed'   => 'Purchase',
        'experience_paid'             => 'Purchase',
        'rtb_approved'                => 'Purchase',
        'gift_purchased'              => 'Purchase',
        // Checkout / Funnel
        'begin_checkout'              => 'InitiateCheckout',
        'booking_submitted'           => 'InitiateCheckout',
        'experience_checkout_started' => 'InitiateCheckout',
        'form_payment_started'        => 'InitiateCheckout',
        // Carrello
        'add_to_cart'                 => 'AddToCart',
        // Lead
        'generate_lead'               => 'Lead',
        'rtb_submitted'               => 'Lead',
        // Contatti
        'click_phone'                 => 'Contact',
        'click_whatsapp'              => 'Contact',
        // Registrazione
        'sign_up'                     => 'CompleteRegistration',
    ];

    /** Google Ads conversion events — driven by Settings::ADS_EVENTS */

    public function __construct(private readonly Settings $settings) {}

    /**
     * Generates the full GTM container JSON as a PHP array, then encodes it.
     */
    public function generate(): string {
        $ga4_id    = $this->settings->get('ga4_measurement_id', 'G-XXXXXXXXXX');
        $ads_id    = $this->settings->get('google_ads_id', 'AW-XXXXXXXXX');
        $meta_id   = $this->settings->get('meta_pixel_id', '');
        $site_url  = get_bloginfo('url');
        $site_name = get_bloginfo('name');

        $variables = $this->build_variables($ga4_id, $ads_id, $meta_id);
        $triggers  = $this->build_triggers();
        $tags      = $this->build_tags($ga4_id, $ads_id, $meta_id, $triggers, $variables);

        $container = [
            'exportFormatVersion' => 2,
            'exportTime'          => gmdate('Y-m-d H:i:s'),
            'containerVersion'    => [
                'path'              => 'accounts/0/containers/0/versions/0',
                'accountId'         => '0',
                'containerId'       => '0',
                'containerVersionId'=> '0',
                'name'              => 'FP Tracking — ' . $site_name,
                'description'       => 'Auto-generated by FP-Marketing-Tracking-Layer. Site: ' . $site_url,
                'container'         => [
                    'path'          => 'accounts/0/containers/0',
                    'accountId'     => '0',
                    'containerId'   => '0',
                    'name'          => 'FP Tracking — ' . $site_name,
                    'publicId'      => $this->settings->get('gtm_id', 'GTM-XXXXXXX'),
                    'usageContext'  => ['WEB'],
                ],
                'variable'          => array_values($variables),
                'trigger'           => array_values($triggers),
                'tag'               => array_values($tags),
            ],
        ];

        return (string) wp_json_encode($container, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------------------------
    // VARIABLES
    // -------------------------------------------------------------------------

    private function build_variables(string $ga4_id, string $ads_id, string $meta_id): array {
        $vars = [];
        $id   = 1;

        // Constant variables for IDs
        $vars['ga4_id'] = $this->make_constant_var($id++, 'FP - GA4 Measurement ID', $ga4_id);
        $vars['ads_id'] = $this->make_constant_var($id++, 'FP - Google Ads ID', $ads_id);
        $vars['meta_id'] = $this->make_constant_var($id++, 'FP - Meta Pixel ID', $meta_id ?: 'PIXEL_ID');

        // dataLayer variables for common event params
        $dl_params = [
            'event_id'          => 'FP - DL event_id',
            'transaction_id'    => 'FP - DL transaction_id',
            'value'             => 'FP - DL value',
            'currency'          => 'FP - DL currency',
            'items'             => 'FP - DL items',
            'form_id'           => 'FP - DL form_id',
            'form_name'         => 'FP - DL form_name',
            'click_type'        => 'FP - DL click_type',
            'click_url'         => 'FP - DL click_url',
            'click_text'        => 'FP - DL click_text',
            'scroll_depth'      => 'FP - DL scroll_depth',
            'video_url'         => 'FP - DL video_url',
            'video_percent'     => 'FP - DL video_percent',
            'search_term'       => 'FP - DL search_term',
            'reservation_id'       => 'FP - DL reservation_id',
            'reservation_location' => 'FP - DL reservation_location',
            'reservation_party'    => 'FP - DL reservation_party',
            'reservation_date'     => 'FP - DL reservation_date',
            'meal_type'            => 'FP - DL meal_type',
            'experience_id'        => 'FP - DL experience_id',
            'experience_title'     => 'FP - DL experience_title',
            'file_url'          => 'FP - DL file_url',
            'file_name'         => 'FP - DL file_name',
        ];

        foreach ($dl_params as $param => $label) {
            $vars[$param] = $this->make_datalayer_var($id++, $label, $param);
        }

        return $vars;
    }

    private function make_constant_var(int $id, string $name, string $value): array {
        return [
            'accountId'   => '0',
            'containerId' => '0',
            'variableId'  => (string) $id,
            'name'        => $name,
            'type'        => 'c',
            'parameter'   => [
                ['type' => 'template', 'key' => 'value', 'value' => $value],
            ],
        ];
    }

    private function make_datalayer_var(int $id, string $name, string $param): array {
        return [
            'accountId'   => '0',
            'containerId' => '0',
            'variableId'  => (string) $id,
            'name'        => $name,
            'type'        => 'v',
            'parameter'   => [
                ['type' => 'integer', 'key' => 'dataLayerVersion', 'value' => '2'],
                ['type' => 'boolean', 'key' => 'setDefaultValue',  'value' => 'false'],
                ['type' => 'template', 'key' => 'name',            'value' => $param],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // TRIGGERS
    // -------------------------------------------------------------------------

    private function build_triggers(): array {
        $triggers = [];
        $id       = 100;

        // All Pages trigger
        $triggers['all_pages'] = [
            'accountId'   => '0',
            'containerId' => '0',
            'triggerId'   => (string) $id++,
            'name'        => 'FP - All Pages',
            'type'        => 'PAGEVIEW',
        ];

        // One Custom Event trigger per FP event
        foreach (self::EVENTS as $event_name => $meta) {
            $triggers[$event_name] = [
                'accountId'   => '0',
                'containerId' => '0',
                'triggerId'   => (string) $id++,
                'name'        => 'FP - Event: ' . $meta['label'],
                'type'        => 'CUSTOM_EVENT',
                'customEventFilter' => [
                    [
                        'type'      => 'EQUALS',
                        'parameter' => [
                            ['type' => 'template', 'key' => 'arg0', 'value' => '{{_event}}'],
                            ['type' => 'template', 'key' => 'arg1', 'value' => $event_name],
                        ],
                    ],
                ],
            ];
        }

        // Consent initialization trigger (DOM Ready)
        $triggers['consent_init'] = [
            'accountId'   => '0',
            'containerId' => '0',
            'triggerId'   => (string) $id++,
            'name'        => 'FP - Consent Initialization',
            'type'        => 'DOM_READY',
        ];

        return $triggers;
    }

    // -------------------------------------------------------------------------
    // TAGS
    // -------------------------------------------------------------------------

    private function build_tags(string $ga4_id, string $ads_id, string $meta_id, array $triggers, array $variables): array {
        $tags = [];
        $id   = 200;

        // --- Consent Mode initialization tag (fires before everything) ---
        $tags['consent_init'] = [
            'accountId'   => '0',
            'containerId' => '0',
            'tagId'       => (string) $id++,
            'name'        => 'FP - Consent Mode v2 Init',
            'type'        => 'html',
            'priority'    => ['type' => 'integer', 'value' => '10'],
            'parameter'   => [
                [
                    'type'  => 'template',
                    'key'   => 'html',
                    'value' => "<script>\nwindow.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag('consent','default',{\n  'ad_storage':'denied',\n  'analytics_storage':'denied',\n  'ad_user_data':'denied',\n  'ad_personalization':'denied',\n  'wait_for_update': 500\n});\n</script>",
                ],
                ['type' => 'boolean', 'key' => 'supportDocumentWrite', 'value' => 'false'],
            ],
            'firingTriggerId' => [$triggers['consent_init']['triggerId']],
        ];

        // --- GA4 Configuration tag ---
        $tags['ga4_config'] = [
            'accountId'   => '0',
            'containerId' => '0',
            'tagId'       => (string) $id++,
            'name'        => 'FP - GA4 Configuration',
            'type'        => 'googtag',
            'parameter'   => [
                ['type' => 'template', 'key' => 'tagId', 'value' => '{{' . $variables['ga4_id']['name'] . '}}'],
            ],
            'firingTriggerId' => [$triggers['all_pages']['triggerId']],
        ];

        // --- GA4 Event tags ---
        foreach (self::EVENTS as $event_name => $meta) {
            if ($event_name === 'page_view') {
                continue; // handled by GA4 config tag automatically
            }

            $parameters = $this->build_ga4_event_parameters($event_name, $variables);

            $tags['ga4_event_' . $event_name] = [
                'accountId'   => '0',
                'containerId' => '0',
                'tagId'       => (string) $id++,
                'name'        => 'FP - GA4 Event: ' . $meta['label'],
                'type'        => 'gaawe',
                'parameter'   => array_merge(
                    [
                        ['type' => 'template', 'key' => 'measurementId', 'value' => '{{' . $variables['ga4_id']['name'] . '}}'],
                        ['type' => 'template', 'key' => 'eventName',     'value' => $event_name],
                    ],
                    $parameters ? [['type' => 'list', 'key' => 'eventParameters', 'list' => $parameters]] : []
                ),
                'firingTriggerId' => [$triggers[$event_name]['triggerId']],
            ];
        }

        // --- Google Ads Conversion tags ---
        if ($ads_id) {
            foreach (Settings::ADS_EVENTS as $event_name => $event_human_label) {
                if (!isset($triggers[$event_name])) {
                    continue;
                }

                // Read conversion label saved in admin (may be empty — tag still created as placeholder)
                $conversion_label = $this->settings->get_ads_label($event_name);

                // Use event label from EVENTS map if available, else fall back to ADS_EVENTS label
                $tag_label = self::EVENTS[$event_name]['label'] ?? $event_human_label;

                $tags['ads_' . $event_name] = [
                    'accountId'   => '0',
                    'containerId' => '0',
                    'tagId'       => (string) $id++,
                    'name'        => 'FP - Google Ads: ' . $tag_label . ($conversion_label ? '' : ' ⚠ label missing'),
                    'type'        => 'awct',
                    'parameter'   => [
                        ['type' => 'template', 'key' => 'conversionId',    'value' => '{{' . $variables['ads_id']['name'] . '}}'],
                        ['type' => 'template', 'key' => 'conversionLabel', 'value' => $conversion_label],
                        ['type' => 'template', 'key' => 'conversionValue', 'value' => '{{' . $variables['value']['name'] . '}}'],
                        ['type' => 'template', 'key' => 'currencyCode',    'value' => '{{' . $variables['currency']['name'] . '}}'],
                        ['type' => 'template', 'key' => 'orderId',         'value' => '{{' . $variables['transaction_id']['name'] . '}}'],
                        ['type' => 'boolean',  'key' => 'enableNewCustomerReporting', 'value' => 'false'],
                    ],
                    'firingTriggerId' => [$triggers[$event_name]['triggerId']],
                ];
            }
        }

        // --- Meta Pixel Base Code tag ---
        if ($meta_id) {
            $tags['meta_base'] = [
                'accountId'   => '0',
                'containerId' => '0',
                'tagId'       => (string) $id++,
                'name'        => 'FP - Meta Pixel Base Code',
                'type'        => 'html',
                'parameter'   => [
                    [
                        'type'  => 'template',
                        'key'   => 'html',
                        'value' => $this->meta_base_html(),
                    ],
                    ['type' => 'boolean', 'key' => 'supportDocumentWrite', 'value' => 'false'],
                ],
                'firingTriggerId' => [$triggers['all_pages']['triggerId']],
            ];

            // Meta event tags
            foreach (self::META_EVENT_MAP as $fp_event => $meta_event) {
                if (!isset($triggers[$fp_event])) {
                    continue;
                }
                $events_with_value = [
                    'purchase', 'event_ticket_purchase',
                    'booking_confirmed', 'booking_payment_completed',
                    'experience_paid', 'rtb_approved', 'gift_purchased',
                    'add_to_cart', 'begin_checkout', 'form_payment_started',
                ];
                $value_param = in_array($fp_event, $events_with_value, true)
                    ? "fbq('track', '" . $meta_event . "', {value: {{" . $variables['value']['name'] . "}}, currency: {{" . $variables['currency']['name'] . "}} || 'EUR', eventID: {{" . $variables['event_id']['name'] . "}}});"
                    : "fbq('track', '" . $meta_event . "', {eventID: {{" . $variables['event_id']['name'] . "}}});";

                $tags['meta_event_' . $fp_event] = [
                    'accountId'   => '0',
                    'containerId' => '0',
                    'tagId'       => (string) $id++,
                    'name'        => 'FP - Meta: ' . self::EVENTS[$fp_event]['label'],
                    'type'        => 'html',
                    'parameter'   => [
                        [
                            'type'  => 'template',
                            'key'   => 'html',
                            'value' => '<script>' . $value_param . '</script>',
                        ],
                        ['type' => 'boolean', 'key' => 'supportDocumentWrite', 'value' => 'false'],
                    ],
                    'firingTriggerId' => [$triggers[$fp_event]['triggerId']],
                ];
            }
        }

        return $tags;
    }

    /**
     * Builds the GA4 event parameters list for a given event.
     */
    private function build_ga4_event_parameters(string $event_name, array $variables): array {
        // Common params for all events
        $common = [
            'event_id' => $variables['event_id']['name'],
        ];

        // Event-specific params
        $specific = match (true) {
            in_array($event_name, ['purchase', 'event_ticket_purchase'], true) => [
                'transaction_id' => $variables['transaction_id']['name'],
                'value'          => $variables['value']['name'],
                'currency'       => $variables['currency']['name'],
                'items'          => $variables['items']['name'],
            ],
            in_array($event_name, ['booking_confirmed', 'booking_submitted', 'booking_payment_required', 'waitlist_joined'], true) => [
                'transaction_id'       => $variables['transaction_id']['name'],
                'value'                => $variables['value']['name'],
                'currency'             => $variables['currency']['name'],
                'reservation_id'       => $variables['reservation_id']['name'],
                'reservation_location' => $variables['reservation_location']['name'],
                'reservation_party'    => $variables['reservation_party']['name'],
                'reservation_date'     => $variables['reservation_date']['name'],
                'meal_type'            => $variables['meal_type']['name'],
            ],
            in_array($event_name, ['booking_payment_completed', 'booking_cancelled', 'booking_no_show', 'booking_visited', 'waitlist_promoted', 'booking_moved'], true) => [
                'reservation_id'       => $variables['reservation_id']['name'],
                'reservation_location' => $variables['reservation_location']['name'],
                'reservation_party'    => $variables['reservation_party']['name'],
                'reservation_date'     => $variables['reservation_date']['name'],
                'meal_type'            => $variables['meal_type']['name'],
            ],
            in_array($event_name, ['booking_step_complete', 'booking_submit_error'], true) => [
                'reservation_id'       => $variables['reservation_id']['name'],
                'reservation_location' => $variables['reservation_location']['name'],
            ],
            in_array($event_name, [
                'experience_view', 'experience_checkout_view', 'gift_redeem_view',
                'booking_start', 'booking_abandon', 'rtb_start', 'gift_start',
                'experience_checkout_started', 'experience_paid', 'experience_cancelled',
                'rtb_submitted', 'rtb_approved', 'rtb_declined', 'rtb_hold_expired',
                'gift_purchased', 'gift_redeemed',
            ], true) => [
                'experience_id'    => $variables['experience_id']['name'],
                'experience_title' => $variables['experience_title']['name'],
            ],
            $event_name === 'generate_lead' => [
                'form_id'   => $variables['form_id']['name'],
                'form_name' => $variables['form_name']['name'],
            ],
            $event_name === 'form_payment_started' => [
                'form_id'  => $variables['form_id']['name'],
                'value'    => $variables['value']['name'],
                'currency' => $variables['currency']['name'],
            ],
            in_array($event_name, ['add_to_cart', 'view_item', 'begin_checkout'], true) => [
                'value'    => $variables['value']['name'],
                'currency' => $variables['currency']['name'],
                'items'    => $variables['items']['name'],
            ],
            in_array($event_name, ['click_phone', 'click_whatsapp', 'click_email', 'click_map', 'click_cta', 'click_social', 'click_external_link'], true) => [
                'click_type' => $variables['click_type']['name'],
                'click_url'  => $variables['click_url']['name'],
                'click_text' => $variables['click_text']['name'],
            ],
            $event_name === 'scroll_depth' => [
                'scroll_depth' => $variables['scroll_depth']['name'],
            ],
            $event_name === 'video_complete' => [
                'video_url'     => $variables['video_url']['name'],
                'video_percent' => $variables['video_percent']['name'],
            ],
            $event_name === 'search' => [
                'search_term' => $variables['search_term']['name'],
            ],
            $event_name === 'file_download' => [
                'file_url'  => $variables['file_url']['name'],
                'file_name' => $variables['file_name']['name'],
            ],
            default => [],
        };

        $all_params = array_merge($common, $specific);

        return array_map(
            static fn(string $key, string $var_name): array => [
                'type' => 'map',
                'map'  => [
                    ['type' => 'template', 'key' => 'name',  'value' => $key],
                    ['type' => 'template', 'key' => 'value', 'value' => '{{' . $var_name . '}}'],
                ],
            ],
            array_keys($all_params),
            array_values($all_params)
        );
    }

    /**
     * Returns the Meta Pixel base code HTML (uses {{FP - Meta Pixel ID}} variable).
     */
    private function meta_base_html(): string {
        return <<<'HTML'
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{{FP - Meta Pixel ID}}');
fbq('track', 'PageView');
</script>
HTML;
    }
}
