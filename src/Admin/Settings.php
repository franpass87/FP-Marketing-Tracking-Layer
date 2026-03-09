<?php

namespace FPTracking\Admin;

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

    public function register_admin_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_fp_tracking_export_gtm', [$this, 'handle_gtm_export']);
        add_action('admin_post_fp_tracking_save_ads_labels', [$this, 'handle_save_ads_labels']);
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

    private function render_field(string $key, string $type, string $placeholder, array $options): void {
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
            $mono = in_array($key, ['gtm_id', 'ga4_measurement_id', 'ga4_api_secret', 'google_ads_id', 'meta_pixel_id', 'meta_access_token', 'clarity_project_id'], true) ? ' is-monospace' : '';
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text' . $mono . '">';
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

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_fp-tracking') {
            return;
        }
        wp_enqueue_style('fp-tracking-admin', FP_TRACKING_URL . 'assets/css/admin.css', [], FP_TRACKING_VERSION);
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
        $ads_ok       = !empty($this->get('google_ads_id'));
        $integrations = apply_filters('fp_tracking_registered_integrations', []);
        ?>
        <div class="wrap fptracking-admin-page">

            <!-- ══ PAGE HEADER ══════════════════════════════════════════ -->
            <div class="fptracking-page-header">
                <div class="fptracking-page-header-content">
                    <h1>
                        <span class="dashicons dashicons-chart-line"></span>
                        <?php esc_html_e('FP Marketing Tracking Layer', 'fp-tracking'); ?>
                    </h1>
                    <p><?php esc_html_e('Centralizza tutto il tracking: GTM, GA4, Google Ads, Meta Pixel e server-side CAPI. Tutti i plugin FP instradano gli eventi attraverso questo layer.', 'fp-tracking'); ?></p>
                </div>
                <span class="fptracking-page-header-badge">v<?php echo esc_html(FP_TRACKING_VERSION); ?></span>
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

            <?php settings_errors('fp_tracking_settings_group'); ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'ads_labels'): ?>
            <div class="fptracking-alert fptracking-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e('Conversion labels salvati correttamente.', 'fp-tracking'); ?>
            </div>
            <?php endif; ?>

            <!-- ══ FORM IMPOSTAZIONI ══════════════════════════════════════ -->
            <form method="post" action="options.php">
                <?php settings_fields('fp_tracking_settings_group'); ?>

                <!-- Card: Google Tag Manager -->
                <div class="fptracking-card">
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
                                <label for="fp_gtm_id"><?php esc_html_e('GTM Container ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('gtm_id', 'text', 'GTM-XXXXXXX', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Formato: GTM-XXXXXXX — trovalo in GTM → Admin → Container Settings', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: GA4 Measurement Protocol -->
                <div class="fptracking-card">
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
                        <p class="description"><?php esc_html_e('Il Measurement Protocol invia gli eventi di conversione direttamente a GA4 lato server, come backup al tag GTM client-side.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('GA4 Measurement ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('ga4_measurement_id', 'text', 'G-XXXXXXXXXX', []); ?>
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
                <div class="fptracking-card">
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
                        <p class="description"><?php esc_html_e('Il Pixel viene iniettato via GTM (client-side). Il CAPI invia gli stessi eventi da server per recuperare le conversioni perse da iOS/ad-blocker. La deduplicazione è automatica via event_id.', 'fp-tracking'); ?></p>
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Meta Pixel ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('meta_pixel_id', 'text', '1234567890', []); ?>
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
                <div class="fptracking-card">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-megaphone"></span>
                            <h2><?php esc_html_e('Google Ads & Altre Piattaforme', 'fp-tracking'); ?></h2>
                        </div>
                    </div>
                    <div class="fptracking-card-body">
                        <div class="fptracking-fields-grid">
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Google Ads Conversion ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('google_ads_id', 'text', 'AW-XXXXXXXXX', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('Google Ads → Goals → Conversions → Tag setup → Conversion ID', 'fp-tracking'); ?></span>
                            </div>
                            <div class="fptracking-field">
                                <label><?php esc_html_e('Microsoft Clarity Project ID', 'fp-tracking'); ?></label>
                                <?php $this->render_field('clarity_project_id', 'text', 'xxxxxxxxxx', []); ?>
                                <span class="fptracking-hint"><?php esc_html_e('clarity.microsoft.com → il tuo progetto → Setup → Get tracking code', 'fp-tracking'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Impostazioni avanzate -->
                <div class="fptracking-card">
                    <div class="fptracking-card-header">
                        <div class="fptracking-card-header-left">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <h2><?php esc_html_e('Impostazioni Avanzate', 'fp-tracking'); ?></h2>
                        </div>
                    </div>
                    <div class="fptracking-card-body">
                        <div class="fptracking-fields-grid" style="margin-bottom:24px">
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
                                <strong><?php esc_html_e('Debug Mode', 'fp-tracking'); ?></strong>
                                <span><?php esc_html_e('Mostra console.log per ogni evento dataLayer e usa l\'endpoint debug di GA4 MP. Da disattivare in produzione.', 'fp-tracking'); ?></span>
                            </div>
                            <label class="fptracking-toggle">
                                <?php $this->render_field('debug_mode', 'checkbox', '', []); ?>
                                <span class="fptracking-toggle-slider"></span>
                            </label>
                        </div>

                        <div style="margin-top:24px">
                            <button type="submit" class="fptracking-btn fptracking-btn-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Salva Impostazioni', 'fp-tracking'); ?>
                            </button>
                        </div>
                    </div>
                </div>

            </form>

            <!-- ══ GOOGLE ADS CONVERSION LABELS ═════════════════════════ -->
            <div class="fptracking-card">
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
                    <p class="description"><?php esc_html_e('Per ogni evento inserisci il Conversion Label di Google Ads. Verrà incluso automaticamente nel JSON GTM esportato. Trovalo in: Google Ads → Goals → Conversions → seleziona conversione → Tag setup → Use Google Tag Manager → copia "Conversion label".', 'fp-tracking'); ?></p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fp_tracking_save_ads_labels">
                        <?php wp_nonce_field('fp_tracking_save_ads_labels'); ?>

                        <table class="fptracking-table">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th><?php esc_html_e('Evento', 'fp-tracking'); ?></th>
                                    <th><?php esc_html_e('Nome evento FP', 'fp-tracking'); ?></th>
                                    <th><?php esc_html_e('Conversion Label', 'fp-tracking'); ?></th>
                                    <th style="width:110px"><?php esc_html_e('Stato', 'fp-tracking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (self::ADS_EVENTS as $event_name => $event_label):
                                $saved_label = $this->get_ads_label($event_name);
                                $has_label   = $saved_label !== '';
                            ?>
                                <tr class="<?php echo $has_label ? 'is-configured' : ''; ?>">
                                    <td style="text-align:center;font-size:16px;padding:10px 8px">
                                        <?php if ($has_label): ?>
                                            <span class="dashicons dashicons-yes-alt" style="color:#10b981"></span>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-minus" style="color:#d1d5db"></span>
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
            <div class="fptracking-card">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-download"></span>
                        <h2><?php esc_html_e('Esporta Container GTM', 'fp-tracking'); ?></h2>
                    </div>
                </div>
                <div class="fptracking-card-body">
                    <p class="description"><?php esc_html_e('Scarica il container GTM pronto all\'importazione con tutti i tag, trigger e variabili pre-configurati per questo sito. Importalo in GTM → Admin → Import Container.', 'fp-tracking'); ?></p>

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
                    <div class="fptracking-alert fptracking-alert-warning" style="margin-bottom:16px">
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
                    <div class="fptracking-alert fptracking-alert-success" style="margin-bottom:16px">
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

            <!-- ══ INTEGRAZIONI ATTIVE ═══════════════════════════════════ -->
            <div class="fptracking-card">
                <div class="fptracking-card-header">
                    <div class="fptracking-card-header-left">
                        <span class="dashicons dashicons-networking"></span>
                        <h2><?php esc_html_e('Integrazioni Plugin FP', 'fp-tracking'); ?></h2>
                    </div>
                    <?php if (!empty($integrations)): ?>
                    <span class="fptracking-badge fptracking-badge-info"><?php echo count(array_filter($integrations)); ?> <?php esc_html_e('attive', 'fp-tracking'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fptracking-card-body">
                    <?php if (empty($integrations)): ?>
                        <p style="color:var(--fpdms-text-muted);font-size:13px;margin:0"><?php esc_html_e('Nessuna integrazione registrata. Attiva i plugin FP (Forms, Restaurant, Experiences, CTA Bar, Bio) per vederle comparire qui.', 'fp-tracking'); ?></p>
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

        </div><!-- .fptracking-admin-page -->
        <?php
    }
}
