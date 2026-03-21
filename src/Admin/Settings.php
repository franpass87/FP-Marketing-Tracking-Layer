<?php

namespace FPTracking\Admin;

use FPTracking\Audit\ConsentAuditService;
use FPTracking\Catalog\EventCatalog;
use FPTracking\Health\EventHealthService;
use FPTracking\Inspector\EventInspector;
use FPTracking\Queue\EventQueueRepository;
use FPTracking\Rules\EventRuleEngine;
use FPTracking\Validation\EventValidator;

/**
 * Manages plugin settings stored in wp_options under 'fp_tracking_settings'.
 * Registers the admin page under the FP suite menu.
 */
final class Settings {

    // GTMExporter is in the same namespace — no use statement needed

    private const OPTION_KEY = 'fp_tracking_settings';

    private const DEFAULTS = [
        'gtm_id'             => '',
        'ga4_measurement_id' => '',
        'ga4_api_secret'     => '',
        'google_ads_id'      => '',
        'meta_pixel_id'      => '',
        'meta_access_token'  => '',
        'clarity_project_id' => '',
        'debug_mode'         => false,
        'server_side_ga4'    => true,
        'server_side_meta'   => true,
        'utm_cookie_days'    => 90,
        'consent_default'    => 'denied',
        'inspector_sample_rate' => 10,
        'brevo_enabled'      => false,
        'brevo_api_key'      => '',
        'brevo_endpoint'     => 'https://api.brevo.com/v3/events',
        'ads_labels'         => [],
    ];

    /** Option key for Google Ads conversion labels (stored separately for clarity) */
    private const ADS_LABELS_KEY = 'fp_tracking_ads_labels';

    /**
     * Events that get a Google Ads Conversion tag in the GTM export.
     * Key = FP event name, value = human label shown in admin.
     */
    public const ADS_EVENTS = [
        // Revenue diretta
        'purchase'                  => 'Purchase (WooCommerce)',
        'event_ticket_purchase'     => 'Event Ticket Purchase (Restaurant)',
        'booking_confirmed'         => 'Booking Confirmed (Restaurant)',
        'booking_payment_completed' => 'Booking Payment Completed (Restaurant)',
        'experience_paid'           => 'Experience Paid (Experiences)',
        'rtb_approved'              => 'RTB Approved (Experiences)',
        'gift_purchased'            => 'Gift Purchased (Experiences)',
        // Lead / Micro-conversioni
        'generate_lead'             => 'Generate Lead (Forms)',
        'rtb_submitted'             => 'RTB Submitted (Experiences)',
        'form_payment_started'      => 'Form Payment Started (Forms)',
    ];

    private array $data;
    private ?EventQueueRepository $queue = null;
    private ?EventHealthService $healthService = null;

    public function __construct() {
        $saved      = get_option(self::OPTION_KEY, []);
        $this->data = wp_parse_args(is_array($saved) ? $saved : [], self::DEFAULTS);

        // Merge saved ads_labels from dedicated option
        $saved_labels = get_option(self::ADS_LABELS_KEY, []);
        $this->data['ads_labels'] = is_array($saved_labels) ? $saved_labels : [];
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * Returns the saved Google Ads conversion label for a given FP event name.
     */
    public function get_ads_label(string $event_name): string {
        $labels = $this->data['ads_labels'] ?? [];
        return isset($labels[$event_name]) ? (string) $labels[$event_name] : '';
    }

    public function all(): array {
        return $this->data;
    }

    /**
     * Builds a consistency report for the central event catalog.
     *
     * @return array{
     *   healthy:bool,
     *   events_count:int,
     *   meta_map_count:int,
     *   server_side_count:int,
     *   required_rules_count:int,
     *   issues:array<int,string>
     * }
     */
    private function build_catalog_health(): array {
        $events = EventCatalog::EVENTS;
        $metaMap = EventCatalog::META_EVENT_MAP;
        $serverSideEvents = EventCatalog::SERVER_SIDE_EVENTS;
        $requiredRules = EventCatalog::REQUIRED_FIELDS;
        $issues = [];

        foreach ($events as $eventName => $meta) {
            if (!is_array($meta)) {
                $issues[] = sprintf('Evento "%s": definizione non valida.', $eventName);
                continue;
            }

            $label = (string) ($meta['label'] ?? '');
            $type = (string) ($meta['type'] ?? '');

            if ($label === '') {
                $issues[] = sprintf('Evento "%s": label mancante.', $eventName);
            }

            if ($type === '') {
                $issues[] = sprintf('Evento "%s": type mancante.', $eventName);
            }
        }

        foreach ($metaMap as $eventName => $metaEvent) {
            if (!isset($events[$eventName])) {
                $issues[] = sprintf('Meta map: evento "%s" non presente nel catalogo eventi.', $eventName);
            }

            if (!is_string($metaEvent) || trim($metaEvent) === '') {
                $issues[] = sprintf('Meta map: evento "%s" ha valore Meta non valido.', $eventName);
            }
        }

        foreach ($serverSideEvents as $eventName) {
            if (!isset($events[$eventName])) {
                $issues[] = sprintf('Server-side: evento "%s" non presente nel catalogo eventi.', $eventName);
            }
        }

        foreach ($requiredRules as $eventName => $fields) {
            if (!isset($events[$eventName])) {
                $issues[] = sprintf('Required fields: evento "%s" non presente nel catalogo eventi.', $eventName);
                continue;
            }

            if (!is_array($fields)) {
                $issues[] = sprintf('Required fields: evento "%s" ha una regola non valida.', $eventName);
                continue;
            }

            foreach ($fields as $field) {
                if (!is_string($field) || trim($field) === '') {
                    $issues[] = sprintf('Required fields: evento "%s" contiene un campo non valido.', $eventName);
                }
            }
        }

        return [
            'healthy' => $issues === [],
            'events_count' => count($events),
            'meta_map_count' => count($metaMap),
            'server_side_count' => count($serverSideEvents),
            'required_rules_count' => count($requiredRules),
            'issues' => $issues,
        ];
    }

    /**
     * Builds a deterministic fingerprint for the event catalog configuration.
     */
    private function build_catalog_fingerprint(): string {
        $snapshot = [
            'events' => EventCatalog::EVENTS,
            'meta_event_map' => EventCatalog::META_EVENT_MAP,
            'server_side_events' => EventCatalog::SERVER_SIDE_EVENTS,
            'meta_revenue_events' => EventCatalog::META_REVENUE_EVENTS,
            'required_fields' => EventCatalog::REQUIRED_FIELDS,
        ];

        $json = (string) wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $json);
    }

    public function register_admin_hooks(?EventQueueRepository $queue = null, ?EventHealthService $healthService = null): void {
        $this->queue = $queue;
        $this->healthService = $healthService;
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_fp_tracking_export_gtm', [$this, 'handle_gtm_export']);
        add_action('admin_post_fp_tracking_save_ads_labels', [$this, 'handle_save_ads_labels']);
        add_action('admin_post_fp_tracking_retry_failed', [$this, 'handle_retry_failed']);
        add_action('admin_post_fp_tracking_save_rules', [$this, 'handle_save_rules']);
        add_action('admin_post_fp_tracking_export_mapping', [$this, 'handle_export_mapping']);
        add_action('admin_post_fp_tracking_import_mapping', [$this, 'handle_import_mapping']);
        add_action('admin_post_fp_tracking_export_catalog_health', [$this, 'handle_export_catalog_health']);
    }

    public function add_menu_page(): void {
        add_menu_page(
            __('FP Tracking', 'fp-tracking'),
            __('FP Tracking', 'fp-tracking'),
            'manage_options',
            'fp-tracking',
            [$this, 'render_page'],
            'dashicons-chart-line',
            58
        );
    }

    public function register_settings(): void {
        register_setting('fp_tracking_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);

        add_settings_section('fp_tracking_gtm', __('Google Tag Manager', 'fp-tracking'), '__return_false', 'fp-tracking');
        add_settings_section('fp_tracking_server_side', __('Server-Side (optional)', 'fp-tracking'), '__return_false', 'fp-tracking');
        add_settings_section('fp_tracking_meta', __('Meta / Facebook', 'fp-tracking'), '__return_false', 'fp-tracking');
        add_settings_section('fp_tracking_other', __('Other Platforms', 'fp-tracking'), '__return_false', 'fp-tracking');
        add_settings_section('fp_tracking_advanced', __('Advanced', 'fp-tracking'), '__return_false', 'fp-tracking');

        $this->add_field('gtm_id', __('GTM Container ID', 'fp-tracking'), 'fp_tracking_gtm', 'text', 'GTM-XXXXXXX');
        $this->add_field('ga4_measurement_id', __('GA4 Measurement ID', 'fp-tracking'), 'fp_tracking_server_side', 'text', 'G-XXXXXXX');
        $this->add_field('ga4_api_secret', __('GA4 API Secret', 'fp-tracking'), 'fp_tracking_server_side', 'password', '');
        $this->add_field('google_ads_id', __('Google Ads Conversion ID/Label', 'fp-tracking'), 'fp_tracking_server_side', 'text', 'AW-XXXXXXX/label');
        $this->add_field('meta_pixel_id', __('Meta Pixel ID', 'fp-tracking'), 'fp_tracking_meta', 'text', '');
        $this->add_field('meta_access_token', __('Meta Access Token (CAPI)', 'fp-tracking'), 'fp_tracking_meta', 'password', '');
        $this->add_field('clarity_project_id', __('Microsoft Clarity Project ID', 'fp-tracking'), 'fp_tracking_other', 'text', '');
        $this->add_field('utm_cookie_days', __('UTM Cookie Duration (days)', 'fp-tracking'), 'fp_tracking_advanced', 'number', '90');
        $this->add_field('consent_default', __('Default Consent State', 'fp-tracking'), 'fp_tracking_advanced', 'select', 'denied', ['denied' => 'Denied (GDPR)', 'granted' => 'Granted']);
        $this->add_field('server_side_ga4', __('Enable GA4 Server-Side', 'fp-tracking'), 'fp_tracking_advanced', 'checkbox', '');
        $this->add_field('server_side_meta', __('Enable Meta CAPI Server-Side', 'fp-tracking'), 'fp_tracking_advanced', 'checkbox', '');
        $this->add_field('brevo_enabled', __('Enable Brevo Server-Side', 'fp-tracking'), 'fp_tracking_advanced', 'checkbox', '');
        $this->add_field('brevo_api_key', __('Brevo API Key', 'fp-tracking'), 'fp_tracking_advanced', 'password', '');
        $this->add_field('brevo_endpoint', __('Brevo Endpoint', 'fp-tracking'), 'fp_tracking_advanced', 'text', 'https://api.brevo.com/v3/events');
        $this->add_field('inspector_sample_rate', __('Inspector Sample Rate (%)', 'fp-tracking'), 'fp_tracking_advanced', 'number', '10');
        $this->add_field('debug_mode', __('Debug Mode', 'fp-tracking'), 'fp_tracking_advanced', 'checkbox', '');
    }

    private function add_field(string $key, string $label, string $section, string $type, string $placeholder = '', array $options = []): void {
        add_settings_field(
            'fp_tracking_' . $key,
            $label,
            function () use ($key, $type, $placeholder, $options): void {
                $this->render_field($key, $type, $placeholder, $options);
            },
            'fp-tracking',
            $section
        );
    }

    private function render_field(string $key, string $type, string $placeholder, array $options, bool $with_copy = false, string $input_id = ''): void {
        $value = $this->get($key);
        $name  = self::OPTION_KEY . '[' . $key . ']';

        if ($type === 'checkbox') {
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(1, $value, false) . '>';
        } elseif ($type === 'select') {
            echo '<select name="' . esc_attr($name) . '">';
            foreach ($options as $opt_val => $opt_label) {
                echo '<option value="' . esc_attr($opt_val) . '" ' . selected($opt_val, $value, false) . '>' . esc_html($opt_label) . '</option>';
            }
            echo '</select>';
        } else {
            $mono = in_array($key, ['gtm_id', 'ga4_measurement_id', 'ga4_api_secret', 'google_ads_id', 'meta_pixel_id', 'meta_access_token', 'clarity_project_id', 'brevo_api_key', 'brevo_endpoint'], true) ? ' is-monospace' : '';
            $id_attr = $input_id !== '' ? ' id="' . esc_attr($input_id) . '"' : '';

            if ($with_copy && $input_id !== '') {
                echo '<span class="fptracking-field-copy-wrap">';
            }
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text' . $mono . '"' . $id_attr . ' data-fptracking-copyable>';
            if ($with_copy && $input_id !== '') {
                echo '<button type="button" class="fptracking-btn-copy" data-fptracking-copy-for="' . esc_attr($input_id) . '" aria-label="' . esc_attr__('Copia negli appunti', 'fp-tracking') . '" title="' . esc_attr__('Copia negli appunti', 'fp-tracking') . '">';
                echo '<span class="dashicons dashicons-admin-page"></span>';
                echo '<span class="fptracking-copy-feedback" aria-live="polite"></span>';
                echo '</button></span>';
            }
        }
    }

    public function sanitize(mixed $input): array {
        if (!is_array($input)) {
            return self::DEFAULTS;
        }
        $clean = [];
        $clean['gtm_id']             = sanitize_text_field($input['gtm_id'] ?? '');
        $clean['ga4_measurement_id'] = sanitize_text_field($input['ga4_measurement_id'] ?? '');
        $clean['ga4_api_secret']     = sanitize_text_field($input['ga4_api_secret'] ?? '');
        $clean['google_ads_id']      = sanitize_text_field($input['google_ads_id'] ?? '');
        $clean['meta_pixel_id']      = sanitize_text_field($input['meta_pixel_id'] ?? '');
        $clean['meta_access_token']  = sanitize_text_field($input['meta_access_token'] ?? '');
        $clean['clarity_project_id'] = sanitize_text_field($input['clarity_project_id'] ?? '');
        $clean['utm_cookie_days']    = max(1, (int) ($input['utm_cookie_days'] ?? 90));
        $clean['consent_default']    = in_array($input['consent_default'] ?? '', ['denied', 'granted'], true) ? $input['consent_default'] : 'denied';
        $clean['server_side_ga4']    = !empty($input['server_side_ga4']);
        $clean['server_side_meta']   = !empty($input['server_side_meta']);
        $clean['brevo_enabled']      = !empty($input['brevo_enabled']);
        $clean['brevo_api_key']      = sanitize_text_field($input['brevo_api_key'] ?? '');
        $clean['brevo_endpoint']     = esc_url_raw($input['brevo_endpoint'] ?? 'https://api.brevo.com/v3/events');
        $clean['inspector_sample_rate'] = max(1, min(100, (int) ($input['inspector_sample_rate'] ?? 10)));
        $clean['debug_mode']         = !empty($input['debug_mode']);
        return $clean;
    }

    /**
     * Handles the GTM JSON export download request.
     */
    public function handle_gtm_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_export_gtm');

        $exporter = new GTMExporter($this);
        $json     = $exporter->generate();
        $filename = 'GTM-FP-Tracking-' . sanitize_title(get_bloginfo('name')) . '-' . gmdate('Ymd') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $json;
        exit;
    }

    /**
     * Handles saving of Google Ads conversion labels (separate form).
     */
    public function handle_save_ads_labels(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_save_ads_labels');

        $raw    = $_POST['fp_ads_labels'] ?? [];
        $labels = [];

        if (is_array($raw)) {
            foreach (self::ADS_EVENTS as $event_name => $event_label) {
                $val = isset($raw[$event_name]) ? sanitize_text_field((string) $raw[$event_name]) : '';
                if ($val !== '') {
                    $labels[$event_name] = $val;
                }
            }
        }

        update_option(self::ADS_LABELS_KEY, $labels);

        wp_redirect(add_query_arg([
            'page'    => 'fp-tracking',
            'updated' => 'ads_labels',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_retry_failed(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_retry_failed');

        $retried = 0;
        if ($this->queue instanceof EventQueueRepository) {
            $retried = $this->queue->retry_failed(500);
        }

        wp_redirect(add_query_arg([
            'page' => 'fp-tracking',
            'updated' => 'retry_failed',
            'retried' => $retried,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_rules(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_save_rules');

        $disabledRaw = isset($_POST['fp_tracking_disabled_events']) ? sanitize_text_field(wp_unslash((string) $_POST['fp_tracking_disabled_events'])) : '';
        $renamesRaw = isset($_POST['fp_tracking_renames_json']) ? wp_unslash((string) $_POST['fp_tracking_renames_json']) : '{}';
        $enrichRaw = isset($_POST['fp_tracking_enrich_json']) ? wp_unslash((string) $_POST['fp_tracking_enrich_json']) : '{}';
        $brevoMapRaw = isset($_POST['fp_tracking_brevo_mapping_json']) ? wp_unslash((string) $_POST['fp_tracking_brevo_mapping_json']) : '{}';
        $brevoEventsRaw = isset($_POST['fp_tracking_brevo_enabled_events']) ? sanitize_text_field(wp_unslash((string) $_POST['fp_tracking_brevo_enabled_events'])) : '';

        $disabled = array_values(array_filter(array_map(
            static fn(string $v): string => sanitize_key(trim($v)),
            explode(',', $disabledRaw)
        )));

        $renames = json_decode($renamesRaw, true);
        if (!is_array($renames)) {
            $renames = [];
        }
        $enrich = json_decode($enrichRaw, true);
        if (!is_array($enrich)) {
            $enrich = [];
        }
        $brevoMapping = json_decode($brevoMapRaw, true);
        if (!is_array($brevoMapping)) {
            $brevoMapping = [];
        }

        $rules = new EventRuleEngine();
        $rules->save_rules([
            'disabled_events' => $disabled,
            'renames' => $renames,
            'enrich' => $enrich,
        ]);

        update_option('fp_tracking_brevo_mapping', $brevoMapping);
        $brevoEvents = array_values(array_filter(array_map(
            static fn(string $v): string => sanitize_key(trim($v)),
            explode(',', $brevoEventsRaw)
        )));
        update_option('fp_tracking_brevo_enabled_events', $brevoEvents);

        wp_redirect(add_query_arg([
            'page' => 'fp-tracking',
            'updated' => 'rules_saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_export_mapping(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_export_mapping');

        $manager = new MappingManager();
        $json = $manager->export_json();

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="fp-tracking-mapping-' . gmdate('Ymd-His') . '.json"');
        echo $json;
        exit;
    }

    public function handle_import_mapping(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_import_mapping');

        $json = isset($_POST['fp_tracking_mapping_json']) ? wp_unslash((string) $_POST['fp_tracking_mapping_json']) : '';
        $ok = false;
        if ($json !== '') {
            $manager = new MappingManager();
            $ok = $manager->import_json($json);
        }

        wp_redirect(add_query_arg([
            'page' => 'fp-tracking',
            'updated' => $ok ? 'mapping_imported' : 'mapping_import_failed',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Exports the Catalog Health report as JSON for QA/release checks.
     */
    public function handle_export_catalog_health(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('fp_tracking_export_catalog_health');

        $report = $this->build_catalog_health();
        $payload = [
            'generated_at' => gmdate('c'),
            'plugin_version' => defined('FP_TRACKING_VERSION') ? FP_TRACKING_VERSION : '',
            'catalog_fingerprint_sha256' => $this->build_catalog_fingerprint(),
            'site' => [
                'url' => get_bloginfo('url'),
                'name' => get_bloginfo('name'),
            ],
            'catalog_health' => $report,
        ];
        $json = (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = 'fp-tracking-catalog-health-' . gmdate('Ymd-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $json;
        exit;
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_fp-tracking') {
            return;
        }
        wp_enqueue_style('fp-tracking-admin', FP_TRACKING_URL . 'assets/css/admin.css', [], FP_TRACKING_VERSION);
        wp_enqueue_script('fp-tracking-admin', FP_TRACKING_URL . 'assets/js/admin.js', [], FP_TRACKING_VERSION, true);
        wp_localize_script('fp-tracking-admin', 'fpTrackingAdmin', [
            'collapse' => __('Comprimi', 'fp-tracking'),
            'expand'   => __('Espandi', 'fp-tracking'),
        ]);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $labels_count = count(array_filter(
            array_map(fn(string $e) => $this->get_ads_label($e), array_keys(self::ADS_EVENTS))
        ));
        $total_ads    = count(self::ADS_EVENTS);
        $gtm_ok       = !empty($this->get('gtm_id'));
        $ga4_ok       = !empty($this->get('ga4_measurement_id')) && !empty($this->get('ga4_api_secret'));
        $meta_ok      = !empty($this->get('meta_pixel_id')) && !empty($this->get('meta_access_token'));
        $brevo_ok     = !empty($this->get('brevo_enabled')) && !empty($this->get('brevo_api_key'));
        $ads_ok       = !empty($this->get('google_ads_id'));
        $integrations = apply_filters('fp_tracking_registered_integrations', []);
        $validator    = new EventValidator();
        $warnings     = $validator->get_recent_warnings(10);
        $inspector    = new EventInspector();
        $inspectorEvents = $inspector->recent(10);
        $ruleEngine   = new EventRuleEngine();
        $rulesData    = $ruleEngine->get_rules();
        $consentAudit = new ConsentAuditService();
        $consentStats = $consentAudit->stats();
        $brevoMapping = get_option('fp_tracking_brevo_mapping', []);
        if (!is_array($brevoMapping)) {
            $brevoMapping = [];
        }
        $brevoEnabledEvents = get_option('fp_tracking_brevo_enabled_events', []);
        if (!is_array($brevoEnabledEvents)) {
            $brevoEnabledEvents = [];
        }
        $queue_stats  = $this->healthService instanceof EventHealthService ? $this->healthService->get_queue_stats() : [
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'dead' => 0,
            'sent_24h' => 0,
            'failed_24h' => 0,
            'sent_7d' => 0,
            'failed_7d' => 0,
        ];
        $catalogHealth = $this->build_catalog_health();

        // Setup progress: GTM (obbligatorio), GA4, Meta, Brevo, Google Ads, Ads Labels
        $setup_items = [
            $gtm_ok,
            $ga4_ok,
            $meta_ok,
            $brevo_ok,
            $ads_ok,
            $labels_count === $total_ads && $total_ads > 0,
        ];
        $setup_done   = (int) array_sum($setup_items);
        $setup_total  = 6;
        $setup_percent = $setup_total > 0 ? (int) round(100 * $setup_done / $setup_total) : 0;
        ?>
        <div class="wrap fptracking-admin-page">
            <?php /* h1 primo nel .wrap: compat notice JS (jQuery('.wrap h1').after). Titolo visibile = h2 nel banner. */ ?>
            <h1 class="screen-reader-text"><?php esc_html_e('FP Marketing Tracking Layer', 'fp-tracking'); ?></h1>

            <!-- ══ PAGE HEADER ══════════════════════════════════════════ -->
            <div class="fptracking-page-header">
                <div class="fptracking-page-header-content">
                    <h2 class="fptracking-page-header-title" aria-hidden="true">
                        <span class="dashicons dashicons-chart-line"></span>
                        <?php esc_html_e('FP Marketing Tracking Layer', 'fp-tracking'); ?>
                    </h2>
                    <p><?php esc_html_e('Centralizza tutto il tracking: GTM, GA4, Google Ads, Meta Pixel e server-side CAPI. Tutti i plugin FP instradano gli eventi attraverso questo layer.', 'fp-tracking'); ?></p>
                </div>
                <div class="fptracking-page-header-actions">
                    <a href="https://github.com/franpass87/FP-Marketing-Tracking-Layer#readme" target="_blank" rel="noopener noreferrer" class="fptracking-header-link">
                        <span class="dashicons dashicons-book-alt"></span>
                        <?php esc_html_e('Documentazione', 'fp-tracking'); ?>
                    </a>
                    <span class="fptracking-page-header-badge">v<?php echo esc_html(FP_TRACKING_VERSION); ?></span>
                </div>
            </div>

            <!-- ══ STATUS BAR ════════════════════════════════════════════ -->
            <div class="fptracking-status-bar">
                <span class="fptracking-status-pill <?php echo $gtm_ok ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span> GTM <?php echo $gtm_ok ? esc_html($this->get('gtm_id')) : esc_html__('Non configurato', 'fp-tracking'); ?>
                </span>
                <span class="fptracking-status-pill <?php echo $ga4_ok ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span> GA4 MP <?php echo $ga4_ok ? esc_html__('Attivo', 'fp-tracking') : esc_html__('Credenziali mancanti', 'fp-tracking'); ?>
                </span>
                <span class="fptracking-status-pill <?php echo $meta_ok ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span> Meta CAPI <?php echo $meta_ok ? esc_html__('Attivo', 'fp-tracking') : esc_html__('Credenziali mancanti', 'fp-tracking'); ?>
                </span>
                <span class="fptracking-status-pill <?php echo $brevo_ok ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span> Brevo <?php echo $brevo_ok ? esc_html__('Attivo', 'fp-tracking') : esc_html__('Non configurato', 'fp-tracking'); ?>
                </span>
                <span class="fptracking-status-pill <?php echo $ads_ok ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span> Google Ads <?php echo $ads_ok ? esc_html__('Configurato', 'fp-tracking') : esc_html__('Non configurato', 'fp-tracking'); ?>
                </span>
                <?php if ($labels_count > 0): ?>
                <span class="fptracking-status-pill <?php echo $labels_count === $total_ads ? 'is-active' : 'is-missing'; ?>">
                    <span class="dot"></span>
                    <?php printf(esc_html__('Ads Labels: %1$d/%2$d', 'fp-tracking'), $labels_count, $total_ads); ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Setup progress -->
            <div class="fptracking-setup-progress" role="status" aria-live="polite">
                <span class="fptracking-setup-label"><?php printf(esc_html__('Setup: %1$d/%2$d', 'fp-tracking'), $setup_done, $setup_total); ?></span>
                <div class="fptracking-setup-bar" aria-hidden="true">
                    <div class="fptracking-setup-fill" style="width: <?php echo esc_attr((string) $setup_percent); ?>%"></div>
                </div>
            </div>

            <?php if (!$gtm_ok): ?>
            <div class="fptracking-alert fptracking-alert-info">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e('Inserisci il GTM Container ID nella sezione Configurazione per abilitare il tracking. È l\'unico campo obbligatorio.', 'fp-tracking'); ?>
            </div>
            <?php elseif (!$ga4_ok || !$meta_ok): ?>
            <div class="fptracking-alert fptracking-alert-info">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e('Per inviare eventi server-side (GA4 Measurement Protocol, Meta CAPI) e recuperare conversioni perse, configura i relativi campi nella sezione Configurazione.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>

            <?php settings_errors('fp_tracking_settings_group'); ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'ads_labels'): ?>
            <div class="fptracking-alert fptracking-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e('Conversion labels salvati correttamente.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'retry_failed'): ?>
            <div class="fptracking-alert fptracking-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php printf(esc_html__('%d eventi falliti rimessi in coda.', 'fp-tracking'), (int) ($_GET['retried'] ?? 0)); ?>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'rules_saved'): ?>
            <div class="fptracking-alert fptracking-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e('Regole eventi e mapping Brevo salvati.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'mapping_imported'): ?>
            <div class="fptracking-alert fptracking-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e('Configurazione mapping importata correttamente.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'mapping_import_failed'): ?>
            <div class="fptracking-alert fptracking-alert-warning">
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e('Import mapping non riuscito: JSON non valido.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>

            <!-- ══ NAV RAPIDA SEZIONI ═══════════════════════════════════ -->
            <nav class="fptracking-nav-sections" aria-label="<?php esc_attr_e('Navigazione rapida', 'fp-tracking'); ?>">
                <a href="#fptracking-heading-monitoraggio"><?php esc_html_e('Monitoraggio', 'fp-tracking'); ?></a>
                <span class="fptracking-nav-sep">|</span>
                <a href="#fptracking-heading-configurazione"><?php esc_html_e('Configurazione', 'fp-tracking'); ?></a>
                <span class="fptracking-nav-sep">|</span>
                <a href="#fptracking-heading-export"><?php esc_html_e('Export', 'fp-tracking'); ?></a>
                <span class="fptracking-nav-sep">|</span>
                <a href="#fptracking-heading-regole"><?php esc_html_e('Regole', 'fp-tracking'); ?></a>
                <span class="fptracking-nav-sep">|</span>
                <a href="#fptracking-heading-integrazioni"><?php esc_html_e('Integrazioni', 'fp-tracking'); ?></a>
            </nav>

            <!-- ══ SEZIONE: Monitoraggio ═══════════════════════════════════ -->
            <section class="fptracking-section" aria-labelledby="fptracking-heading-monitoraggio">
                <h2 id="fptracking-heading-monitoraggio" class="fptracking-section-header">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e('Monitoraggio e salute', 'fp-tracking'); ?>
                </h2>
                <p class="fptracking-section-intro"><?php esc_html_e('Stato del catalogo eventi e della coda server-side. Verifica che tutto sia coerente prima di rilasciare.', 'fp-tracking'); ?></p>
                <div class="fptracking-cards-grid">
            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-analytics"></span>
                        <h2><?php esc_html_e('Catalog Health', 'fp-tracking'); ?></h2>
                    </div>
                    <?php if (!empty($catalogHealth['healthy'])): ?>
                        <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('Coerente', 'fp-tracking'); ?></span>
                    <?php else: ?>
                        <span class="fptracking-badge fptracking-badge-warning"><?php esc_html_e('Con anomalie', 'fp-tracking'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Verifica che il catalogo eventi sia coerente con mapping Meta, eventi server-side e campi obbligatori. Esporta il report JSON per QA e confronto tra ambienti.', 'fp-tracking'); ?></p>
                    <div class="fptracking-status-bar">
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span>
                            <?php printf(esc_html__('Eventi: %d', 'fp-tracking'), (int) ($catalogHealth['events_count'] ?? 0)); ?>
                        </span>
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span>
                            <?php printf(esc_html__('Meta map: %d', 'fp-tracking'), (int) ($catalogHealth['meta_map_count'] ?? 0)); ?>
                        </span>
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span>
                            <?php printf(esc_html__('Server-side: %d', 'fp-tracking'), (int) ($catalogHealth['server_side_count'] ?? 0)); ?>
                        </span>
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span>
                            <?php printf(esc_html__('Required rules: %d', 'fp-tracking'), (int) ($catalogHealth['required_rules_count'] ?? 0)); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo empty($catalogHealth['healthy']) ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span>
                            <?php printf(esc_html__('Anomalie: %d', 'fp-tracking'), count((array) ($catalogHealth['issues'] ?? []))); ?>
                        </span>
                    </div>

                    <?php if (empty($catalogHealth['issues'])): ?>
                        <div class="fptracking-alert fptracking-alert-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e('Nessuna anomalia rilevata nel catalogo eventi.', 'fp-tracking'); ?>
                        </div>
                    <?php else: ?>
                        <div class="fptracking-alert fptracking-alert-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e('Sono state rilevate incoerenze nel catalogo. Verifica e correggi prima del rilascio.', 'fp-tracking'); ?>
                        </div>
                        <table class="fptracking-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Dettaglio anomalia', 'fp-tracking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ((array) $catalogHealth['issues'] as $issue): ?>
                                <tr>
                                    <td><?php echo esc_html((string) $issue); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="fptracking-form-top-gap">
                        <input type="hidden" name="action" value="fp_tracking_export_catalog_health">
                        <?php wp_nonce_field('fp_tracking_export_catalog_health'); ?>
                        <button type="submit" class="fptracking-btn fptracking-btn-secondary">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Esporta Catalog Health JSON', 'fp-tracking'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-database-view"></span>
                        <h2><?php esc_html_e('Queue Health (Server-Side)', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Stato della coda eventi inviati a GA4/Meta/Brevo. Pending = in attesa; Failed/Dead = errori da verificare; Sent = inviati con successo. Usa il pulsante per rimettere in coda gli eventi falliti.', 'fp-tracking'); ?></p>
                    <div class="fptracking-status-bar">
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['pending'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Pending: %d', 'fp-tracking'), (int) $queue_stats['pending']); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['processing'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Processing: %d', 'fp-tracking'), (int) $queue_stats['processing']); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['failed'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Failed: %d', 'fp-tracking'), (int) $queue_stats['failed']); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['dead'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Dead: %d', 'fp-tracking'), (int) $queue_stats['dead']); ?>
                        </span>
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span> <?php printf(esc_html__('Sent 24h: %d', 'fp-tracking'), (int) $queue_stats['sent_24h']); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['failed_24h'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Failed 24h: %d', 'fp-tracking'), (int) $queue_stats['failed_24h']); ?>
                        </span>
                        <span class="fptracking-status-pill is-active">
                            <span class="dot"></span> <?php printf(esc_html__('Sent 7d: %d', 'fp-tracking'), (int) $queue_stats['sent_7d']); ?>
                        </span>
                        <span class="fptracking-status-pill <?php echo (int) $queue_stats['failed_7d'] > 0 ? 'is-missing' : 'is-active'; ?>">
                            <span class="dot"></span> <?php printf(esc_html__('Failed 7d: %d', 'fp-tracking'), (int) $queue_stats['failed_7d']); ?>
                        </span>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fp_tracking_retry_failed">
                        <?php wp_nonce_field('fp_tracking_retry_failed'); ?>
                        <button type="submit" class="fptracking-btn fptracking-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Rimetti in coda eventi falliti/dead', 'fp-tracking'); ?>
                        </button>
                    </form>
                </div>
            </div>
                </div><!-- .fptracking-cards-grid -->
            </section><!-- .fptracking-section Monitoraggio -->

            <!-- ══ SEZIONE: Configurazione ═══════════════════════════════════ -->
            <section class="fptracking-section" aria-labelledby="fptracking-heading-configurazione">
                <h2 id="fptracking-heading-configurazione" class="fptracking-section-header">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php esc_html_e('Configurazione', 'fp-tracking'); ?>
                </h2>
                <p class="fptracking-section-intro"><?php esc_html_e('Impostazioni principali per GTM, GA4, Meta e altre piattaforme. Usa "Salva Impostazioni" alla fine della sezione.', 'fp-tracking'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('fp_tracking_settings_group'); ?>

                <!-- Card: Google Tag Manager -->
                <div class="fptracking-card fptracking-card-collapsible">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <h2><?php esc_html_e('Google Tag Manager', 'fp-tracking'); ?></h2>
                        </div>
                        <?php if ($gtm_ok): ?>
                        <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('Configurato', 'fp-tracking'); ?></span>
                        <?php else: ?>
                        <span class="fptracking-badge fptracking-badge-warning"><?php esc_html_e('Richiesto', 'fp-tracking'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="fptracking-card-body">
                        <p class="description"><?php esc_html_e('Il container GTM viene iniettato automaticamente su tutte le pagine del sito. Inserisci il tuo Container ID.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label for="fp_tracking_gtm_id"><?php esc_html_e('GTM Container ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('gtm_id', 'text', 'GTM-XXXXXXX', [], true, 'fp_tracking_gtm_id'); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Formato: GTM-XXXXXXX — trovalo in GTM → Admin → Container Settings', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: GA4 Measurement Protocol -->
                <div class="fptracking-card fptracking-card-collapsible">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-chart-area"></span>
                            <h2><?php esc_html_e('Google Analytics 4 — Server-Side', 'fp-tracking'); ?></h2>
                        </div>
                        <?php if ($ga4_ok): ?>
                        <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('Attivo', 'fp-tracking'); ?></span>
                        <?php else: ?>
                        <span class="fptracking-badge fptracking-badge-neutral"><?php esc_html_e('Opzionale', 'fp-tracking'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Eventi nel browser vanno sempre da dataLayer a GTM (client-side). Qui configuri l\'invio server-side a GA4: Measurement ID e API Secret. Il JSON GTM esportato usa automaticamente l\'ID inserito.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label for="fp_tracking_ga4_id"><?php esc_html_e('GA4 Measurement ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('ga4_measurement_id', 'text', 'G-XXXXXXXXXX', [], true, 'fp_tracking_ga4_id'); ?>
                                <span class="fptracking-hint"><?php esc_html_e('GA4 → Admin → Data Streams → scegli stream', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('GA4 API Secret', 'fp-tracking'); ?></label>
                                <?php $this->render_field('ga4_api_secret', 'password', '', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('GA4 → Admin → Data Streams → Measurement Protocol API secrets', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Meta -->
                <div class="fptracking-card fptracking-card-collapsible">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-share"></span>
                            <h2><?php esc_html_e('Meta Pixel + Conversions API', 'fp-tracking'); ?></h2>
                        </div>
                        <?php if ($meta_ok): ?>
                        <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('Attivo', 'fp-tracking'); ?></span>
                        <?php else: ?>
                        <span class="fptracking-badge fptracking-badge-neutral"><?php esc_html_e('Opzionale', 'fp-tracking'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Eventi Meta nel browser passano da GTM (client-side). Qui configuri l\'invio server-side (CAPI): Pixel ID e Access Token. Utile per recuperare conversioni perse (iOS, ad-blocker). Deduplicazione automatica con event_id.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label for="fp_tracking_meta_pixel_id"><?php esc_html_e('Meta Pixel ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('meta_pixel_id', 'text', '1234567890', [], true, 'fp_tracking_meta_pixel_id'); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Meta Business Manager → Events Manager → il tuo Pixel', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Meta Access Token (CAPI)', 'fp-tracking'); ?></label>
                                <?php $this->render_field('meta_access_token', 'password', '', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Events Manager → Pixel → Settings → Conversions API → genera token', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Google Ads + Altre piattaforme -->
                <div class="fptracking-card fptracking-card-collapsible">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-megaphone"></span>
                            <h2><?php esc_html_e('Google Ads & Altre Piattaforme', 'fp-tracking'); ?></h2>
                        </div>
                    </div>
                    <div class="fptracking-card-body">
                        <p class="description"><?php esc_html_e('Google Ads per le conversioni (richiede anche i Conversion Label nella sezione Export). Microsoft Clarity per registrare le sessioni utente. Entrambi opzionali.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label for="fp_tracking_google_ads_id"><?php esc_html_e('Google Ads Conversion ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('google_ads_id', 'text', 'AW-XXXXXXXXX', [], true, 'fp_tracking_google_ads_id'); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Google Ads → Goals → Conversions → Tag setup → Conversion ID', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label for="fp_tracking_clarity_id"><?php esc_html_e('Microsoft Clarity Project ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('clarity_project_id', 'text', 'xxxxxxxxxx', [], true, 'fp_tracking_clarity_id'); ?>
                                <span class="fptracking-hint"><?php esc_html_e('clarity.microsoft.com → il tuo progetto → Setup → Get tracking code', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Impostazioni avanzate -->
                <div class="fptracking-card fptracking-card-collapsible">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <h2><?php esc_html_e('Impostazioni Avanzate', 'fp-tracking'); ?></h2>
                        </div>
                    </div>
                    <div class="fptracking-card-body">
                        <p class="description"><?php esc_html_e('Cookie UTM per attribuzione, stato default del consenso (GDPR), attivazione canali server-side (GA4, Meta, Brevo) e opzioni di debug.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid fptracking-fields-grid-bottom-gap">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Durata cookie UTM (giorni)', 'fp-tracking'); ?></label>
                                <?php $this->render_field('utm_cookie_days', 'number', '90', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Per quanto tempo mantenere i parametri UTM in cookie per l\'attribuzione', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Consent Mode — stato default', 'fp-tracking'); ?></label>
                                <?php $this->render_field('consent_default', 'select', '', ['denied' => 'Denied (GDPR — raccomandato)', 'granted' => 'Granted']); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Stato iniziale prima che l\'utente esprima il consenso. Usa "Denied" per conformità GDPR.', 'fp-tracking'); ?></span>
                            </div>
                        </div>

                        <p class="fptracking-section-title"><?php esc_html_e('Canali Server-Side', 'fp-tracking'); ?></p>
                        <div class="fptracking-toggle-row">
                            <div class="fptracking-toggle-info">
                                <strong><?php esc_html_e('GA4 Measurement Protocol', 'fp-tracking'); ?></strong>
                                <span><?php esc_html_e('Invia eventi di conversione a GA4 lato server (richiede Measurement ID + API Secret)', 'fp-tracking'); ?></span>
                            </div>
                            <label class="fptracking-toggle">
                                <?php $this->render_field('server_side_ga4', 'checkbox', '', []); ?>
                                <span class="fptracking-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="fptracking-toggle-row">
                            <div class="fptracking-toggle-info">
                                <strong><?php esc_html_e('Meta Conversions API (CAPI)', 'fp-tracking'); ?></strong>
                                <span><?php esc_html_e('Invia eventi a Meta lato server per recuperare conversioni perse da iOS/ad-blocker (richiede Pixel ID + Access Token)', 'fp-tracking'); ?></span>
                            </div>
                            <label class="fptracking-toggle">
                                <?php $this->render_field('server_side_meta', 'checkbox', '', []); ?>
                                <span class="fptracking-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="fptracking-toggle-row">
                            <div class="fptracking-toggle-info">
                                <strong><?php esc_html_e('Brevo Server-Side', 'fp-tracking'); ?></strong>
                                <span><?php esc_html_e('Invia eventi a Brevo Events API v3 (`/v3/events`) usando `api-key` e payload ufficiale (event_name, identifiers, event_properties).', 'fp-tracking'); ?></span>
                            </div>
                            <label class="fptracking-toggle">
                                <?php $this->render_field('brevo_enabled', 'checkbox', '', []); ?>
                                <span class="fptracking-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="fptracking-fields-grid fptracking-fields-grid-top-gap">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Brevo API Key', 'fp-tracking'); ?></label>
                                <?php $this->render_field('brevo_api_key', 'password', '', []); ?>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Brevo Endpoint', 'fp-tracking'); ?></label>
                                <?php $this->render_field('brevo_endpoint', 'text', 'https://api.brevo.com/v3/events', []); ?>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Inspector Sample Rate (%)', 'fp-tracking'); ?></label>
                                <?php $this->render_field('inspector_sample_rate', 'number', '10', []); ?>
                            </div>
                        </div>
                        <div class="fptracking-toggle-row">
                            <div class="fptracking-toggle-info">
                                <strong><?php esc_html_e('Debug Mode', 'fp-tracking'); ?></strong>
                                <span><?php esc_html_e('Mostra console.log per ogni evento dataLayer e usa l\'endpoint debug di GA4 MP. Da disattivare in produzione.', 'fp-tracking'); ?></span>
                            </div>
                            <label class="fptracking-toggle">
                                <?php $this->render_field('debug_mode', 'checkbox', '', []); ?>
                                <span class="fptracking-toggle-slider"></span>
                            </label>
                        </div>

                        <div class="fptracking-actions-top-gap-lg">
                            <button type="submit" class="fptracking-btn fptracking-btn-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Salva Impostazioni', 'fp-tracking'); ?>
                            </button>
                        </div>
                    </div>
                </div>

            </form>
            </section><!-- .fptracking-section Configurazione -->

            <!-- ══ SEZIONE: Export e Conversioni ═══════════════════════════════════ -->
            <section class="fptracking-section" aria-labelledby="fptracking-heading-export">
                <h2 id="fptracking-heading-export" class="fptracking-section-header">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Export e conversioni', 'fp-tracking'); ?>
                </h2>
                <p class="fptracking-section-intro"><?php esc_html_e('Conversion Labels per Google Ads e download del container GTM da importare nel tuo account.', 'fp-tracking'); ?></p>

            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-tag"></span>
                        <h2><?php esc_html_e('Google Ads — Conversion Labels', 'fp-tracking'); ?></h2>
                    </div>
                    <?php if ($labels_count === $total_ads): ?>
                    <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('Tutti configurati', 'fp-tracking'); ?></span>
                    <?php elseif ($labels_count > 0): ?>
                    <span class="fptracking-badge fptracking-badge-warning"><?php printf(esc_html__('%1$d/%2$d configurati', 'fp-tracking'), $labels_count, $total_ads); ?></span>
                    <?php else: ?>
                    <span class="fptracking-badge fptracking-badge-neutral"><?php esc_html_e('Nessuno configurato', 'fp-tracking'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Collega ogni evento FP alla conversione Google Ads corrispondente. Il label inserito qui viene incluso automaticamente nell\'export GTM. Dove trovarlo: Google Ads → Obiettivi → Conversioni → Tag setup → "Conversion label".', 'fp-tracking'); ?></p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fp_tracking_save_ads_labels">
                        <?php wp_nonce_field('fp_tracking_save_ads_labels'); ?>

                        <table class="fptracking-table">
                            <thead>
                                <tr>
                                    <th class="fptracking-col-icon"></th>
                                    <th><?php esc_html_e('Evento', 'fp-tracking'); ?></th>
                                    <th><?php esc_html_e('Nome evento FP', 'fp-tracking'); ?></th>
                                    <th><?php esc_html_e('Conversion Label', 'fp-tracking'); ?></th>
                                    <th class="fptracking-col-status"><?php esc_html_e('Stato', 'fp-tracking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (self::ADS_EVENTS as $event_name => $event_label):
                                $saved_label = $this->get_ads_label($event_name);
                                $has_label   = $saved_label !== '';
                            ?>
                                <tr class="<?php echo $has_label ? 'is-configured' : ''; ?>">
                                    <td class="fptracking-cell-icon">
                                        <?php if ($has_label): ?>
                                            <span class="dashicons dashicons-yes-alt fptracking-icon-success"></span>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-minus fptracking-icon-muted"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html($event_label); ?></strong></td>
                                    <td><code><?php echo esc_html($event_name); ?></code></td>
                                    <td>
                                        <input
                                            type="text"
                                            name="fp_ads_labels[<?php echo esc_attr($event_name); ?>]"
                                            value="<?php echo esc_attr($saved_label); ?>"
                                            placeholder="AbCdEfGhIjKlMnOpQrSt"
                                        >
                                    </td>
                                    <td>
                                        <?php if ($has_label): ?>
                                            <span class="fptracking-badge fptracking-badge-success">&#10003; <?php esc_html_e('OK', 'fp-tracking'); ?></span>
                                        <?php else: ?>
                                            <span class="fptracking-badge fptracking-badge-neutral">&#8212; <?php esc_html_e('Mancante', 'fp-tracking'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5">
                                        <button type="submit" class="fptracking-btn fptracking-btn-secondary">
                                            <span class="dashicons dashicons-saved"></span>
                                            <?php esc_html_e('Salva Conversion Labels', 'fp-tracking'); ?>
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </form>
                </div>
            </div>

            <!-- ══ EXPORT GTM ════════════════════════════════════════════ -->
            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-download"></span>
                        <h2><?php esc_html_e('Esporta Container GTM', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Scarica un container GTM preconfigurato da importare nel tuo account. Include tag GA4, Meta, Google Ads e Consent Mode. I valori (Measurement ID, Pixel ID, Conversion Labels) inseriti sopra vengono usati automaticamente.', 'fp-tracking'); ?></p>

                    <ul class="fptracking-export-checklist">
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Tag GA4 Configuration (All Pages)', 'fp-tracking'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <strong><?php echo esc_html(count(GTMExporter::EVENTS)); ?></strong> <?php esc_html_e('GA4 Event tag (uno per evento tracciato)', 'fp-tracking'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Google Ads Conversion tag per acquisti, prenotazioni, lead', 'fp-tracking'); ?></li>
                        <?php if ($this->get('meta_pixel_id')): ?>
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Meta Pixel base code + event tag (Purchase, Lead, Contact…)', 'fp-tracking'); ?></li>
                        <?php endif; ?>
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Consent Mode v2 initialization tag', 'fp-tracking'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Variabili dataLayer per tutti i parametri evento', 'fp-tracking'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Custom Event trigger per ogni evento FP', 'fp-tracking'); ?></li>
                    </ul>

                    <?php if ($labels_count < $total_ads): ?>
                    <div class="fptracking-alert fptracking-alert-warning fptracking-alert-bottom-gap">
                        <span class="dashicons dashicons-warning"></span>
                        <?php if ($labels_count === 0): ?>
                            <?php esc_html_e('Nessun Conversion Label Google Ads configurato. I tag Ads nel JSON avranno il campo label vuoto — configurali nella sezione sopra prima di esportare.', 'fp-tracking'); ?>
                        <?php else: ?>
                            <?php printf(
                                esc_html__('%1$d di %2$d Conversion Label configurati. Configura i rimanenti sopra per un export completo.', 'fp-tracking'),
                                $labels_count,
                                $total_ads
                            ); ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="fptracking-alert fptracking-alert-success fptracking-alert-bottom-gap">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Tutti i Conversion Label sono configurati — il container è pronto per l\'esportazione.', 'fp-tracking'); ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fp_tracking_export_gtm">
                        <?php wp_nonce_field('fp_tracking_export_gtm'); ?>
                        <button type="submit" class="fptracking-btn fptracking-btn-primary">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Scarica GTM Container JSON', 'fp-tracking'); ?>
                        </button>
                    </form>
                </div>
            </div>
            </section><!-- .fptracking-section Export -->

            <!-- ══ SEZIONE: Regole, debug e mapping ═══════════════════════════════════ -->
            <section class="fptracking-section" aria-labelledby="fptracking-heading-regole">
                <h2 id="fptracking-heading-regole" class="fptracking-section-header">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e('Regole, debug e mapping', 'fp-tracking'); ?>
                </h2>
                <p class="fptracking-section-intro"><?php esc_html_e('Regole eventi, validator, ispettore e backup/restore della configurazione.', 'fp-tracking'); ?></p>

                <div class="fptracking-cards-grid">
            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <h2><?php esc_html_e('Rule Engine + Brevo Mapping', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Modifica il flusso eventi senza codice: disabilita, rinomina o arricchisci gli eventi prima dell\'invio. Configura anche il mapping verso Brevo per i nomi evento.', 'fp-tracking'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fp_tracking_save_rules">
                        <?php wp_nonce_field('fp_tracking_save_rules'); ?>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Disabilita eventi (CSV)', 'fp-tracking'); ?></label>
                                <input type="text" name="fp_tracking_disabled_events" value="<?php echo esc_attr(implode(',', (array) ($rulesData['disabled_events'] ?? []))); ?>" class="regular-text is-monospace" placeholder="purchase,generate_lead">
                                <span class="fptracking-hint" title="<?php esc_attr_e('Separati da virgola, senza spazi. Gli eventi elencati non verranno inviati a dataLayer né server-side.', 'fp-tracking'); ?>"><?php esc_html_e('Elenco eventi da bloccare prima del dispatch.', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Rename eventi (JSON)', 'fp-tracking'); ?></label>
                                <textarea name="fp_tracking_renames_json" rows="4" class="large-text code"><?php echo esc_textarea((string) wp_json_encode((array) ($rulesData['renames'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                                <span class="fptracking-hint" title="<?php esc_attr_e('Chiave = nome evento FP, valore = nome in output. Es: generate_lead diventa lead_submit.', 'fp-tracking'); ?>"><?php esc_html_e('Esempio: {"generate_lead":"lead_submit"}, {"purchase":"purchase_complete"}', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Enrich payload globale (JSON)', 'fp-tracking'); ?></label>
                                <textarea name="fp_tracking_enrich_json" rows="4" class="large-text code"><?php echo esc_textarea((string) wp_json_encode((array) ($rulesData['enrich'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                                <span class="fptracking-hint" title="<?php esc_attr_e('Ogni evento inviato avrà queste coppie chiave-valore nel payload. Utile per campi fissi (es. campaign, source).', 'fp-tracking'); ?>"><?php esc_html_e('Esempio: {"campaign":"summer2024","source":"website"}. Chiavi aggiunte a ogni evento.', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Brevo mapping eventi (JSON)', 'fp-tracking'); ?></label>
                                <textarea name="fp_tracking_brevo_mapping_json" rows="4" class="large-text code"><?php echo esc_textarea((string) wp_json_encode($brevoMapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                                <span class="fptracking-hint" title="<?php esc_attr_e('Chiave = nome evento FP, valore = nome evento Brevo. Gli eventi non mappati usano il nome originale.', 'fp-tracking'); ?>"><?php esc_html_e('Esempio: {"generate_lead":"Lead","purchase":"Purchase"}. Mappa FP -> Brevo.', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Eventi Brevo abilitati (CSV)', 'fp-tracking'); ?></label>
                                <input type="text" name="fp_tracking_brevo_enabled_events" value="<?php echo esc_attr(implode(',', array_map('strval', $brevoEnabledEvents))); ?>" class="regular-text is-monospace" placeholder="purchase,generate_lead">
                                <span class="fptracking-hint" title="<?php esc_attr_e('Se vuoto, tutti gli eventi vengono inviati a Brevo. Altrimenti solo gli eventi elencati.', 'fp-tracking'); ?>"><?php esc_html_e('Vuoto = tutti. Es: purchase,generate_lead per solo acquisti e lead.', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                        <div class="fptracking-actions-top-gap-sm">
                            <button type="submit" class="fptracking-btn fptracking-btn-secondary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Salva regole e mapping', 'fp-tracking'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-search"></span>
                        <h2><?php esc_html_e('Validator + Event Inspector', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Mostra eventuali errori di validazione (campi mancanti o non conformi) e un campione degli ultimi eventi inviati. I dati sensibili sono mascherati.', 'fp-tracking'); ?></p>
                    <?php if ($warnings === []): ?>
                        <div class="fptracking-empty-ok">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <p><?php esc_html_e('Nessun warning di validazione recente. Gli eventi inviati rispettano gli schemi attesi.', 'fp-tracking'); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="fptracking-table">
                            <thead><tr><th><?php esc_html_e('Quando', 'fp-tracking'); ?></th><th><?php esc_html_e('Evento', 'fp-tracking'); ?></th><th><?php esc_html_e('Warning', 'fp-tracking'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($warnings as $w): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($w['timestamp'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($w['event'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html(implode(' | ', array_map('strval', (array) ($w['warnings'] ?? [])))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($inspectorEvents !== []): ?>
                        <p class="fptracking-section-title"><?php esc_html_e('Ultimi eventi campionati', 'fp-tracking'); ?></p>
                        <textarea class="large-text code" rows="8" readonly><?php echo esc_textarea((string) wp_json_encode($inspectorEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                    <?php endif; ?>
                </div>
            </div>
                </div><!-- .fptracking-cards-grid -->

            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-shield-alt"></span>
                        <h2><?php esc_html_e('Consent Audit + Mapping Export/Import', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Cronologia aggiornamenti del consenso (da FP-Privacy) e import/export della configurazione mapping per backup o migrazione tra ambienti.', 'fp-tracking'); ?></p>
                    <div class="fptracking-status-bar">
                        <span class="fptracking-status-pill is-active"><span class="dot"></span> <?php printf(esc_html__('Consent updates: %d', 'fp-tracking'), (int) ($consentStats['total_updates'] ?? 0)); ?></span>
                        <span class="fptracking-status-pill is-active"><span class="dot"></span> <?php printf(esc_html__('Last revision: %d', 'fp-tracking'), (int) ($consentStats['last_revision'] ?? 0)); ?></span>
                        <span class="fptracking-status-pill is-active"><span class="dot"></span> <?php printf(esc_html__('Last update: %s', 'fp-tracking'), esc_html((string) ($consentStats['last_update_at'] ?? '-'))); ?></span>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="fptracking-form-top-gap">
                        <input type="hidden" name="action" value="fp_tracking_export_mapping">
                        <?php wp_nonce_field('fp_tracking_export_mapping'); ?>
                        <button type="submit" class="fptracking-btn fptracking-btn-secondary">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Esporta mapping/config JSON', 'fp-tracking'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="fptracking-form-top-gap">
                        <input type="hidden" name="action" value="fp_tracking_import_mapping">
                        <?php wp_nonce_field('fp_tracking_import_mapping'); ?>
                        <label for="fp-tracking-import-json"><strong><?php esc_html_e('Importa mapping/config JSON', 'fp-tracking'); ?></strong></label>
                        <textarea id="fp-tracking-import-json" name="fp_tracking_mapping_json" rows="7" class="large-text code" placeholder="{...}"></textarea>
                        <div class="fptracking-actions-top-gap-xs">
                            <button type="submit" class="fptracking-btn fptracking-btn-primary">
                                <span class="dashicons dashicons-upload"></span>
                                <?php esc_html_e('Importa JSON', 'fp-tracking'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            </section><!-- .fptracking-section Regole -->

            <!-- ══ SEZIONE: Integrazioni ═══════════════════════════════════ -->
            <section class="fptracking-section" aria-labelledby="fptracking-heading-integrazioni">
                <h2 id="fptracking-heading-integrazioni" class="fptracking-section-header">
                    <span class="dashicons dashicons-networking"></span>
                    <?php esc_html_e('Integrazioni', 'fp-tracking'); ?>
                </h2>
                <p class="fptracking-section-intro"><?php esc_html_e('Plugin FP che inviano eventi a questo layer.', 'fp-tracking'); ?></p>

            <div class="fptracking-card fptracking-card-collapsible">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <h2><?php esc_html_e('Plugin FP attivi', 'fp-tracking'); ?></h2>
                    </div>
                    <?php if (!empty($integrations)): ?>
                    <span class="fptracking-badge fptracking-badge-info"><?php echo count(array_filter($integrations)); ?> <?php esc_html_e('attive', 'fp-tracking'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Plugin FP che inviano eventi al tracking layer. Attivi = rilevati e connessi. Attiva Forms, Restaurant, Experiences, CTA Bar o Bio per vederli qui.', 'fp-tracking'); ?></p>
                    <?php if (empty($integrations)): ?>
                        <p class="fptracking-empty-state"><?php esc_html_e('Nessuna integrazione registrata. Attiva i plugin FP (Forms, Restaurant, Experiences, CTA Bar, Bio) per vederle comparire qui.', 'fp-tracking'); ?></p>
                    <?php else: ?>
                        <div class="fptracking-integrations-grid">
                            <?php foreach ($integrations as $name => $active): ?>
                            <div class="fptracking-integration-item <?php echo $active ? 'is-active' : ''; ?>">
                                <span class="dashicons <?php echo $active ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
                                <?php echo esc_html($name); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </section><!-- .fptracking-section Integrazioni -->

        </div><!-- .fptracking-admin-page -->
        <?php
    }
}
