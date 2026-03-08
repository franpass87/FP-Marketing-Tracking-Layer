<?php

namespace FPTracking\DataLayer;

/**
 * Normalizes event payloads to GA4-compatible schema before pushing to dataLayer.
 *
 * Event catalogue:
 *
 * --- FP Plugin Events ---
 * purchase, booking_confirmed, booking_submitted, waitlist_joined,
 * event_ticket_purchase, generate_lead, form_view, form_start,
 * form_submit, form_abandon, page_view
 *
 * --- Contact / Micro-conversion Events ---
 * click_phone, click_whatsapp, click_email, click_map,
 * click_social, click_cta, click_external_link
 *
 * --- WooCommerce Events (GA4 Ecommerce) ---
 * view_item_list, view_item, add_to_cart, remove_from_cart,
 * view_cart, begin_checkout, add_payment_info, add_shipping_info,
 * purchase (woo), refund
 *
 * --- WordPress / Content Events ---
 * scroll_depth, video_start, video_progress, video_complete,
 * file_download, site_search, search_results_click,
 * contact_form_submit (CF7 / Gravity Forms / WPForms),
 * newsletter_signup, login, register
 *
 * --- Salient / WPBakery Engagement Events ---
 * accordion_open, tab_switch, popup_open, popup_close,
 * slider_swipe, counter_reached, sticky_header_visible,
 * mega_menu_open, lightbox_open
 */
final class EventSchema {

    /**
     * GA4 standard event names (pass-through — no remapping needed).
     * Custom events are passed as-is.
     */
    private const GA4_STANDARD = [
        // Ecommerce
        'view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart',
        'view_cart', 'begin_checkout', 'add_payment_info', 'add_shipping_info',
        'purchase', 'refund',
        // Engagement
        'generate_lead', 'page_view', 'scroll', 'video_start', 'video_progress',
        'video_complete', 'file_download', 'search', 'login', 'sign_up',
        'select_content', 'share',
    ];

    public function build(string $event_name, array $params): array {
        $payload = array_merge(
            ['event' => $event_name],
            $this->normalize_params($event_name, $params)
        );

        return apply_filters('fp_tracking_event_payload', $payload, $event_name, $params);
    }

    private function normalize_params(string $event_name, array $params): array {
        $normalized = [];

        // --- Common scalar fields ---
        foreach (['value', 'currency', 'transaction_id', 'event_id'] as $f) {
            if (isset($params[$f]) && $params[$f] !== '') {
                $normalized[$f] = $f === 'value' ? (float) $params[$f]
                    : ($f === 'currency' ? strtoupper((string) $params[$f]) : (string) $params[$f]);
            }
        }

        // --- Ecommerce items → GA4 ecommerce object ---
        if (!empty($params['items']) && is_array($params['items'])) {
            $normalized['ecommerce'] = ['items' => $params['items']];
        }

        // --- Contact click fields ---
        foreach (['link_type', 'link_url', 'link_text', 'link_domain', 'phone_number', 'email_address', 'whatsapp_number', 'social_network', 'map_address'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- Form / Lead fields ---
        foreach (['form_id', 'form_title', 'form_type', 'submission_id', 'time_spent_seconds'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- Booking fields ---
        foreach (['reservation_id', 'reservation_party', 'reservation_date', 'reservation_time', 'reservation_location', 'meal_type'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- Scroll / engagement fields ---
        foreach (['percent_scrolled', 'scroll_threshold'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = (int) $params[$f];
            }
        }

        // --- Video fields ---
        foreach (['video_title', 'video_url', 'video_provider', 'video_duration', 'video_percent', 'video_current_time'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- File download ---
        foreach (['file_name', 'file_extension', 'file_url'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- Search ---
        foreach (['search_term', 'search_results_count'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // --- Salient / WPBakery engagement ---
        foreach (['element_type', 'element_id', 'element_title', 'tab_name', 'accordion_title', 'popup_id', 'slider_name', 'direction'] as $f) {
            if (isset($params[$f])) {
                $normalized[$f] = $params[$f];
            }
        }

        // user_data is kept for server-side dispatch (GA4 MP / Meta CAPI) but excluded from dataLayer push
        if (isset($params['user_data']) && is_array($params['user_data'])) {
            $normalized['user_data'] = $params['user_data'];
        }

        // --- Pass-through: any extra param not already mapped ---
        $known = array_keys($normalized);
        $known = array_merge($known, ['value', 'currency', 'transaction_id', 'event_id', 'items']);
        foreach ($params as $key => $val) {
            if (!in_array($key, $known, true)) {
                $normalized[$key] = $val;
            }
        }

        return $normalized;
    }
}
