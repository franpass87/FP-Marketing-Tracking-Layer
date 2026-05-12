<?php
declare(strict_types=1);

namespace FPTracking\ServerSide;

use FPTracking\Admin\Settings;
use FPTracking\Catalog\EventCatalog;
use FPTracking\Brevo\BrevoClient;
use FPTracking\Brevo\BrevoDispatcher;
use FPTracking\Brevo\BrevoMapper;

/**
 * Orchestrates server-side event dispatch to GA4, Meta and Brevo.
 * Listens to the 'fp_tracking_server_side' action fired by DataLayerManager.
 */
final class ServerSideDispatcher {

    private GA4MeasurementProtocol $ga4;
    private MetaConversionsAPI $meta;
    private BrevoDispatcher $brevo;

    public function __construct(private readonly Settings $settings) {
        $this->ga4  = new GA4MeasurementProtocol($settings);
        $this->meta = new MetaConversionsAPI($settings);
        $this->brevo = new BrevoDispatcher($settings, new BrevoClient($settings), new BrevoMapper());
    }

    /**
     * Maps FP event names to their Meta standard event equivalent.
     * Source of truth: EventCatalog::META_EVENT_MAP.
     * Events not listed here are sent to GA4 only (no Meta CAPI).
     *
     * Note: click_phone, click_whatsapp, sign_up are client-side only —
     * no server-side user_data available, so excluded from CAPI.
     */
    private const META_EVENT_MAP = EventCatalog::META_EVENT_MAP;

    /**
     * Dispatches server-side events to GA4 MP, Meta CAPI and Brevo.
     * Called by add_action('fp_tracking_server_side', ...).
     *
     * @param string $event_name  FP internal event name
     * @param array  $params      Event parameters (same as passed to fp_tracking_event)
     */
    public function dispatch(string $event_name, array $params): void {
        $this->dispatch_with_result($event_name, $params);
    }

    /**
     * Dispatches a queue batch, grouping Meta CAPI events into one HTTP request.
     *
     * GA4 Measurement Protocol and Brevo remain per-job because their APIs and
     * identifiers are independent, while Meta supports multiple events in the
     * `data` array for the same Pixel.
     *
     * @param array<int,array{id:int,event_name:string,payload:array,attempts:int,max_attempts:int}> $jobs Claimed queue jobs.
     * @return array<int,array{ok:bool,attempted:int,error:string}> Results keyed by queue job ID.
     */
    public function dispatch_batch_with_result(array $jobs): array {
        $results = [];
        $meta_events = [];
        $meta_job_ids = [];

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $event_name = (string) ($job['event_name'] ?? '');
            $params = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
            $event_id = (string) ($params['event_id'] ?? uniqid('fp_', true));
            $client_id = GA4MeasurementProtocol::extract_client_id();
            $user_data = $params['user_data'] ?? [];
            $attempted = 0;
            $errors = [];

            $ga4_params = $this->build_ga4_params($event_name, $params);
            if ($this->ga4->is_enabled() && $this->channel_consent_granted('ga4', $event_name, $params)) {
                $attempted++;
                if (!$this->ga4->send($event_name, $ga4_params, $client_id, $event_id, $user_data)) {
                    $errors[] = 'GA4 send failed';
                }
            }

            if ($this->brevo->is_enabled() && $this->channel_consent_granted('brevo', $event_name, $params)) {
                $attempted++;
                if (!$this->brevo->dispatch($event_name, $params)) {
                    $errors[] = 'Brevo send failed';
                }
            }

            $meta_event = self::META_EVENT_MAP[$event_name] ?? null;
            if ($meta_event !== null && $this->meta->is_enabled() && $this->channel_consent_granted('meta', $event_name, $params)) {
                $attempted++;
                $meta_custom = $this->build_meta_custom($event_name, $params);
                $source_url = (string) ($params['page_url'] ?? $params['event_source_url'] ?? '');
                $meta_events[] = $this->meta->build_event($meta_event, $meta_custom, $user_data, $source_url, $event_id);
                $meta_job_ids[] = $id;
            }

            $results[$id] = [
                'ok' => $errors === [],
                'attempted' => $attempted,
                'error' => implode('; ', $errors),
            ];
        }

        if ($meta_events !== [] && !$this->meta->send_batch($meta_events)) {
            $meta_error = $this->meta->last_error();
            $message = $meta_error !== '' ? 'Meta batch send failed: ' . $meta_error : 'Meta batch send failed';
            foreach ($meta_job_ids as $id) {
                $current = $results[$id]['error'] ?? '';
                $results[$id]['ok'] = false;
                $results[$id]['error'] = $current !== '' ? $current . '; ' . $message : $message;
            }
        }

        return $results;
    }

    /**
     * Dispatches and returns structured outcome for queue worker.
     *
     * @return array{ok:bool,attempted:int,error:string}
     */
    public function dispatch_with_result(string $event_name, array $params): array {
        // event_id is always set by DataLayerManager before this action fires,
        // ensuring the same ID is used in both the dataLayer push and the CAPI call.
        $event_id  = (string) ($params['event_id'] ?? uniqid('fp_', true));
        $client_id = GA4MeasurementProtocol::extract_client_id();
        $user_data = $params['user_data'] ?? [];
        $attempted = 0;
        $errors = [];

        // ── GA4 Measurement Protocol ─────────────────────────────────────────
        $ga4_params = $this->build_ga4_params($event_name, $params);
        if ($this->ga4->is_enabled() && $this->channel_consent_granted('ga4', $event_name, $params)) {
            $attempted++;
            if (!$this->ga4->send($event_name, $ga4_params, $client_id, $event_id, $user_data)) {
                $errors[] = 'GA4 send failed';
            }
        }

        // ── Meta Conversions API ─────────────────────────────────────────────
        $meta_event = self::META_EVENT_MAP[$event_name] ?? null;
        if ($meta_event !== null && $this->meta->is_enabled() && $this->channel_consent_granted('meta', $event_name, $params)) {
            $attempted++;
            $meta_custom    = $this->build_meta_custom($event_name, $params);
            $source_url     = (string) ($params['page_url'] ?? $params['event_source_url'] ?? '');
            if (!$this->meta->send($meta_event, $meta_custom, $user_data, $source_url, $event_id)) {
                $meta_error = $this->meta->last_error();
                $errors[] = $meta_error !== '' ? 'Meta send failed: ' . $meta_error : 'Meta send failed';
            }
        }

        // ── Brevo Events API ─────────────────────────────────────────────────
        if ($this->brevo->is_enabled() && $this->channel_consent_granted('brevo', $event_name, $params)) {
            $attempted++;
            if (!$this->brevo->dispatch($event_name, $params)) {
                $errors[] = 'Brevo send failed';
            }
        }

        return [
            'ok' => $errors === [],
            'attempted' => $attempted,
            'error' => implode('; ', $errors),
        ];
    }

    /**
     * Builds GA4 Measurement Protocol params from the FP event payload.
     * Strips internal-only fields (user_data, event_id) and maps to GA4 schema.
     */
    private function build_ga4_params(string $event_name, array $params): array {
        // Fields that are handled separately or are internal
        $exclude = ['user_data', 'event_id', 'fp_server_side_consent'];

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
     * Checks consent for a specific server-side destination.
     *
     * @param array<string, mixed> $params Event payload with consent snapshot.
     * @return bool True when the destination has the required consent category.
     */
    private function channel_consent_granted(string $channel, string $event_name, array $params): bool {
        $required = (bool) apply_filters('fp_tracking_server_side_consent_required', true, $event_name, $channel);
        if (!$required) {
            return true;
        }

        $purpose = match ($channel) {
            'ga4' => 'statistics',
            'meta' => 'marketing',
            'brevo' => (string) $this->settings->get('brevo_consent_purpose', 'marketing'),
            default => 'marketing',
        };
        $purpose = (string) apply_filters('fp_tracking_server_side_consent_purpose', $purpose, $channel, $event_name, $params);

        if ($purpose === 'none') {
            return (bool) apply_filters('fp_tracking_server_side_has_consent', true, $event_name, $channel, $params);
        }

        $state = isset($params['fp_server_side_consent']) && is_array($params['fp_server_side_consent'])
            ? $params['fp_server_side_consent']
            : [];
        $allowed = !empty($state[$purpose]);

        return (bool) apply_filters('fp_tracking_server_side_has_consent', $allowed, $event_name, $channel, $params);
    }

    /**
     * Builds Meta CAPI custom_data from the FP event payload.
     */
    private function build_meta_custom(string $event_name, array $params): array {
        $custom = [
            'value'    => (float) ($params['value'] ?? 0),
            'currency' => strtoupper((string) ($params['currency'] ?? 'EUR')),
        ];

        // Revenue events: include contents for Meta product catalog matching
        $revenue_events = EventCatalog::META_REVENUE_EVENTS;

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
            } elseif (!empty($params['order_id'])) {
                $custom['contents']     = [['id' => 'order-' . $params['order_id'], 'quantity' => 1]];
                $custom['content_type'] = 'product';
            }
        }

        // Checkout / funnel events
        $checkout_events = ['begin_checkout', 'booking_submitted', 'experience_checkout_started'];
        if (in_array($event_name, $checkout_events, true) && !empty($params['items'])) {
            $custom['contents'] = array_map(static function (array $item): array {
                return [
                    'id'       => (string) ($item['item_id'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];
            }, $params['items']);
            $custom['content_type'] = 'product';
        }

        // Cart events (add_to_cart, cart_abandoned)
        if (in_array($event_name, ['add_to_cart', 'cart_abandoned'], true) && !empty($params['items'])) {
            $custom['contents'] = array_map(static function (array $item): array {
                return [
                    'id'       => (string) ($item['item_id'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];
            }, $params['items']);
            $custom['content_type'] = 'product';
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

        if ($event_name === 'form_payment_completed') {
            $custom['content_name']     = (string) ($params['form_title'] ?? '');
            $custom['content_category'] = 'form_payment';
        }

        if ($event_name === 'dmk_registration_submitted') {
            $custom['content_name']      = (string) ($params['segment'] ?? '');
            $custom['content_category'] = 'distributor_registration';
        }

        if ($event_name === 'dmk_user_approved') {
            $custom['content_name']      = 'distributor_approved';
            $custom['content_category'] = 'distributor_media_kit';
        }

        if ($event_name === 'dmk_login_success') {
            $custom['content_category'] = 'distributor_login';
        }

        if ($event_name === 'dmk_asset_downloaded') {
            $custom['content_name']      = (string) ($params['asset_title'] ?? '');
            $custom['content_category'] = 'media_kit_download';
        }

        return $custom;
    }
}
