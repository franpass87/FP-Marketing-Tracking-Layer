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
    // Helpers
    // -----------------------------------------------------------------------

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
