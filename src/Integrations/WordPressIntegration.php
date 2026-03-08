<?php

namespace FPTracking\Integrations;

use FPTracking\Admin\Settings;

/**
 * WordPress core events integration.
 *
 * Fires server-side do_action('fp_tracking_event') for:
 *   - site_search     — WordPress search queries
 *   - contact_form_submit — Contact Form 7, Gravity Forms, WPForms
 *   - login / register — user authentication
 *
 * Client-side events (scroll_depth, video, file_download, click_*)
 * are handled entirely in fp-tracking.js via DOM listeners.
 */
final class WordPressIntegration {

    public function __construct(private readonly Settings $settings) {}

    public function register_hooks(): void {
        // WordPress search
        add_action('pre_get_posts', [$this, 'track_search']);

        // Contact Form 7
        add_action('wpcf7_mail_sent', [$this, 'track_cf7_submit']);

        // Gravity Forms
        add_action('gform_after_submission', [$this, 'track_gravity_submit'], 10, 2);

        // WPForms
        add_action('wpforms_process_complete', [$this, 'track_wpforms_submit'], 10, 4);

        // Ninja Forms
        add_action('ninja_forms_after_submission', [$this, 'track_ninjaforms_submit']);

        // User login / register
        add_action('wp_login', [$this, 'track_login'], 10, 2);
        add_action('user_register', [$this, 'track_register']);

        // FP-Experiences bridge (only if plugin is active)
        if (defined('FP_EXP_VERSION') || class_exists('FP_Exp\Core\Plugin')) {
            add_action('fp_exp_rtb_submitted',       [$this, 'track_exp_rtb_submitted'],  10, 3);
            add_action('fp_exp_rtb_request_approved',[$this, 'track_exp_rtb_approved'],   10, 3);
            add_action('fp_exp_gift_purchased',      [$this, 'track_exp_gift_purchased'], 10, 2);
            add_action('fp_exp_reservation_paid',    [$this, 'track_exp_reservation_paid'], 10, 2);
            add_action('fp_exp_shortcode_rendered',  [$this, 'track_exp_shortcode_rendered'], 10, 2);
        }
    }

    // -----------------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------------

    public function track_search(\WP_Query $query): void {
        if (!$query->is_main_query() || !$query->is_search() || is_admin()) {
            return;
        }

        $term = get_search_query();
        if (empty($term)) {
            return;
        }

        do_action('fp_tracking_event', 'search', [
            'search_term' => sanitize_text_field($term),
        ]);
    }

    // -----------------------------------------------------------------------
    // Contact Form 7
    // -----------------------------------------------------------------------

    public function track_cf7_submit(\WPCF7_ContactForm $contact_form): void {
        do_action('fp_tracking_event', 'contact_form_submit', [
            'form_id'    => $contact_form->id(),
            'form_title' => $contact_form->title(),
            'form_type'  => 'cf7',
            'event_id'   => 'cf7_' . $contact_form->id() . '_' . time(),
        ]);

        // Also fire generate_lead for conversion tracking
        do_action('fp_tracking_event', 'generate_lead', [
            'form_id'    => $contact_form->id(),
            'form_title' => $contact_form->title(),
            'form_type'  => 'cf7',
            'value'      => 1.0,
            'currency'   => 'EUR',
            'event_id'   => 'cf7_lead_' . $contact_form->id() . '_' . time(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Gravity Forms
    // -----------------------------------------------------------------------

    public function track_gravity_submit(array $entry, array $form): void {
        do_action('fp_tracking_event', 'contact_form_submit', [
            'form_id'    => $form['id'],
            'form_title' => $form['title'],
            'form_type'  => 'gravity_forms',
            'event_id'   => 'gf_' . $form['id'] . '_' . $entry['id'],
        ]);

        do_action('fp_tracking_event', 'generate_lead', [
            'form_id'    => $form['id'],
            'form_title' => $form['title'],
            'form_type'  => 'gravity_forms',
            'value'      => 1.0,
            'currency'   => 'EUR',
            'event_id'   => 'gf_lead_' . $form['id'] . '_' . $entry['id'],
            'user_data'  => $this->extract_gf_user_data($entry, $form),
        ]);
    }

    // -----------------------------------------------------------------------
    // WPForms
    // -----------------------------------------------------------------------

    public function track_wpforms_submit(array $fields, array $entry, array $form_data, int $entry_id): void {
        do_action('fp_tracking_event', 'contact_form_submit', [
            'form_id'    => $form_data['id'],
            'form_title' => $form_data['settings']['form_title'] ?? '',
            'form_type'  => 'wpforms',
            'event_id'   => 'wpf_' . $form_data['id'] . '_' . $entry_id,
        ]);

        do_action('fp_tracking_event', 'generate_lead', [
            'form_id'    => $form_data['id'],
            'form_title' => $form_data['settings']['form_title'] ?? '',
            'form_type'  => 'wpforms',
            'value'      => 1.0,
            'currency'   => 'EUR',
            'event_id'   => 'wpf_lead_' . $form_data['id'] . '_' . $entry_id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Ninja Forms
    // -----------------------------------------------------------------------

    public function track_ninjaforms_submit(array $form_data): void {
        $form_id    = $form_data['form_id'] ?? 0;
        $form_title = $form_data['settings']['title'] ?? 'Ninja Form';

        do_action('fp_tracking_event', 'contact_form_submit', [
            'form_id'    => $form_id,
            'form_title' => $form_title,
            'form_type'  => 'ninja_forms',
            'event_id'   => 'nf_' . $form_id . '_' . time(),
        ]);

        do_action('fp_tracking_event', 'generate_lead', [
            'form_id'    => $form_id,
            'form_title' => $form_title,
            'form_type'  => 'ninja_forms',
            'value'      => 1.0,
            'currency'   => 'EUR',
            'event_id'   => 'nf_lead_' . $form_id . '_' . time(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function track_login(string $user_login, \WP_User $user): void {
        // Only track non-admin users to avoid polluting data with editor logins
        if (user_can($user, 'manage_options')) {
            return;
        }

        do_action('fp_tracking_event', 'login', [
            'method' => 'wordpress',
        ]);
    }

    public function track_register(int $user_id): void {
        do_action('fp_tracking_event', 'sign_up', [
            'method' => 'wordpress',
        ]);
    }

    // -----------------------------------------------------------------------
    // FP-Experiences bridge
    // -----------------------------------------------------------------------

    /**
     * fp_exp_rtb_submitted($experience_id, $reservation_id, $data)
     * $data: ['value', 'currency', 'tickets', 'contact' => ['em','fn','ln','ph']]
     */
    public function track_exp_rtb_submitted(int $experience_id, int $reservation_id, array $data): void {
        do_action('fp_tracking_event', 'rtb_submitted', [
            'experience_id'  => $experience_id,
            'reservation_id' => $reservation_id,
            'transaction_id' => 'rtb-' . $reservation_id,
            'value'          => (float) ($data['value'] ?? 0),
            'currency'       => (string) ($data['currency'] ?? 'EUR'),
            'event_id'       => 'rtb_' . $reservation_id . '_' . time(),
            'user_data'      => array_filter((array) ($data['contact'] ?? [])),
        ]);
    }

    /**
     * fp_exp_rtb_request_approved($reservation_id, $context, $mode)
     * $context: ['experience' => [...], 'reservation' => [...]]
     */
    public function track_exp_rtb_approved(int $reservation_id, array $context, string $mode): void {
        $exp    = $context['experience'] ?? [];
        $totals = $context['totals'] ?? [];
        do_action('fp_tracking_event', 'rtb_approved', [
            'experience_id'    => (int) ($exp['id'] ?? 0),
            'experience_title' => (string) ($exp['title'] ?? ''),
            'reservation_id'   => $reservation_id,
            'transaction_id'   => 'rtb-' . $reservation_id,
            'value'            => (float) ($totals['total'] ?? 0),
            'currency'         => (string) ($totals['currency'] ?? 'EUR'),
            'approval_mode'    => $mode,
            'event_id'         => 'rtb_approved_' . $reservation_id . '_' . time(),
        ]);
    }

    /**
     * fp_exp_gift_purchased($voucher_id, $order_id)
     */
    public function track_exp_gift_purchased(int $voucher_id, int $order_id): void {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $value = $order instanceof \WC_Order ? (float) $order->get_total() : 0.0;
        $currency = $order instanceof \WC_Order ? $order->get_currency() : 'EUR';

        do_action('fp_tracking_event', 'gift_purchased', [
            'voucher_id'     => $voucher_id,
            'order_id'       => $order_id,
            'transaction_id' => 'gift-' . $order_id,
            'value'          => $value,
            'currency'       => $currency,
            'event_id'       => 'gift_' . $voucher_id . '_' . time(),
        ]);
    }

    /**
     * fp_exp_reservation_paid($reservation_id, $order_id)
     * Fires when a WooCommerce experience order is paid.
     */
    public function track_exp_reservation_paid(int $reservation_id, int $order_id): void {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $value = $order instanceof \WC_Order ? (float) $order->get_total() : 0.0;
        $currency = $order instanceof \WC_Order ? $order->get_currency() : 'EUR';

        do_action('fp_tracking_event', 'experience_paid', [
            'reservation_id' => $reservation_id,
            'order_id'       => $order_id,
            'transaction_id' => 'exp-' . $order_id,
            'value'          => $value,
            'currency'       => $currency,
            'event_id'       => 'exp_paid_' . $reservation_id . '_' . time(),
        ]);
    }

    /**
     * fp_exp_shortcode_rendered($tag, $context)
     * Fires when a shortcode is rendered — used to track checkout page views.
     */
    public function track_exp_shortcode_rendered(string $tag, array $context): void {
        // Only track the checkout shortcode as experience_checkout_started
        if ($tag !== 'fp_exp_checkout' && $tag !== 'fp_experience_checkout') {
            return;
        }

        static $fired = false;
        if ($fired) {
            return;
        }
        $fired = true;

        do_action('fp_tracking_event', 'experience_checkout_started', [
            'experience_id'    => (int) ($context['experience']['id'] ?? 0),
            'experience_title' => (string) ($context['experience']['title'] ?? ''),
            'page_url'         => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
        ]);
    }

    private function extract_gf_user_data(array $entry, array $form): array {
        $user_data = [];
        foreach ($form['fields'] ?? [] as $field) {
            $type  = $field['type'] ?? '';
            $value = $entry[$field['id']] ?? '';
            if (empty($value)) {
                continue;
            }
            if ($type === 'email') {
                $user_data['em'] = sanitize_email($value);
            } elseif ($type === 'phone') {
                $user_data['ph'] = sanitize_text_field($value);
            } elseif ($type === 'name') {
                $user_data['fn'] = sanitize_text_field($field['inputs'][0]['value'] ?? $value);
                $user_data['ln'] = sanitize_text_field($field['inputs'][1]['value'] ?? '');
            }
        }
        return array_filter($user_data);
    }
}
