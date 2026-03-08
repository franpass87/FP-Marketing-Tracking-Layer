<?php

namespace FPTracking\ServerSide;

use FPTracking\Admin\Settings;

/**
 * Orchestrates server-side event dispatch to GA4 and Meta.
 * Listens to the 'fp_tracking_server_side' action fired by DataLayerManager.
 */
final class ServerSideDispatcher {

    private GA4MeasurementProtocol $ga4;
    private MetaConversionsAPI $meta;

    public function __construct(private readonly Settings $settings) {
        $this->ga4  = new GA4MeasurementProtocol($settings);
        $this->meta = new MetaConversionsAPI($settings);
    }

    /**
     * Maps FP event names to their Meta standard event equivalent.
     * Events not listed here are sent to GA4 only (no Meta CAPI).
     */
    private const META_EVENT_MAP = [
        // Revenue
        'purchase'                  => 'Purchase',
        'booking_confirmed'         => 'Purchase',
        'booking_payment_completed' => 'Purchase',
        'event_ticket_purchase'     => 'Purchase',
        'experience_paid'           => 'Purchase',
        'rtb_approved'              => 'Purchase',
        'gift_purchased'            => 'Purchase',
        // Checkout / Funnel
        'form_payment_started'      => 'InitiateCheckout',
        'rtb_submitted'             => 'Lead',
        // Lead
        'generate_lead'             => 'Lead',
    ];

    /**
     * Dispatches server-side events to GA4 MP and Meta CAPI.
     * Called by add_action('fp_tracking_server_side', ...).
     *
     * @param string $event_name  FP internal event name
     * @param array  $params      Event parameters (same as passed to fp_tracking_event)
     */
    public function dispatch(string $event_name, array $params): void {
        $event_id  = $params['event_id'] ?? uniqid('ss_', true);
        $client_id = GA4MeasurementProtocol::extract_client_id();
        $user_data = $params['user_data'] ?? [];

        // ── GA4 Measurement Protocol ─────────────────────────────────────────
        $ga4_params = $this->build_ga4_params($event_name, $params);
        $this->ga4->send($event_name, $ga4_params, $client_id, $event_id, $user_data);

        // ── Meta Conversions API ─────────────────────────────────────────────
        $meta_event = self::META_EVENT_MAP[$event_name] ?? null;
        if ($meta_event !== null) {
            $meta_custom = $this->build_meta_custom($event_name, $params);
            $this->meta->send($meta_event, $meta_custom, $user_data, '', $event_id);
        }
    }

    /**
     * Builds GA4 Measurement Protocol params from the FP event payload.
     * Strips internal-only fields (user_data, event_id) and maps to GA4 schema.
     */
    private function build_ga4_params(string $event_name, array $params): array {
        // Fields that are handled separately or are internal
        $exclude = ['user_data', 'event_id'];

        $ga4 = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $ga4[$key] = $value;
        }

        return $ga4;
    }

    /**
     * Builds Meta CAPI custom_data from the FP event payload.
     */
    private function build_meta_custom(string $event_name, array $params): array {
        $custom = [
            'value'    => (float) ($params['value'] ?? 0),
            'currency' => (string) ($params['currency'] ?? 'EUR'),
        ];

        // Revenue events: include contents for Meta product catalog matching
        $revenue_events = [
            'purchase', 'booking_confirmed', 'booking_payment_completed',
            'event_ticket_purchase', 'experience_paid', 'rtb_approved', 'gift_purchased',
        ];

        if (in_array($event_name, $revenue_events, true)) {
            if (!empty($params['items'])) {
                $custom['contents'] = array_map(static function (array $item): array {
                    return [
                        'id'       => (string) ($item['item_id'] ?? ''),
                        'quantity' => (int) ($item['quantity'] ?? 1),
                    ];
                }, $params['items']);
                $custom['content_type'] = 'product';
            } elseif (!empty($params['transaction_id'])) {
                $custom['contents']     = [['id' => (string) $params['transaction_id'], 'quantity' => 1]];
                $custom['content_type'] = 'product';
            } elseif (!empty($params['reservation_id'])) {
                $custom['contents']     = [['id' => 'resv-' . $params['reservation_id'], 'quantity' => 1]];
                $custom['content_type'] = 'product';
            }
        }

        // Lead events: include content name for segmentation
        if ($event_name === 'generate_lead') {
            $custom['content_name']     = (string) ($params['form_title'] ?? '');
            $custom['content_category'] = 'form_submission';
        }

        if ($event_name === 'rtb_submitted') {
            $custom['content_name']     = (string) ($params['experience_title'] ?? '');
            $custom['content_category'] = 'rtb_request';
        }

        if ($event_name === 'form_payment_started') {
            $custom['content_name']     = (string) ($params['form_title'] ?? '');
            $custom['content_category'] = 'form_payment';
        }

        return $custom;
    }
}
