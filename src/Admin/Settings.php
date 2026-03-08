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
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text">';
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('FP Marketing Tracking Layer', 'fp-tracking'); ?></h1>
            <p class="description">
                <?php esc_html_e('Configure your GTM container and server-side credentials. All FP plugins route their events through this layer.', 'fp-tracking'); ?>
            </p>

            <?php settings_errors('fp_tracking_settings_group'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('fp_tracking_settings_group');
                do_settings_sections('fp-tracking');
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Google Ads — Conversion Labels', 'fp-tracking'); ?></h2>
            <p class="description">
                <?php esc_html_e('For each event below, enter the Google Ads Conversion Label (found in Google Ads → Goals → Conversions → select conversion → Tag setup). These labels will be included in the GTM export automatically.', 'fp-tracking'); ?>
            </p>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'ads_labels'): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Conversion labels saved.', 'fp-tracking'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="fp_tracking_save_ads_labels">
                <?php wp_nonce_field('fp_tracking_save_ads_labels'); ?>

                <table class="widefat fp-ads-labels-table" style="max-width:780px;margin-top:12px">
                    <thead>
                        <tr>
                            <th style="width:28px"></th>
                            <th><?php esc_html_e('Event', 'fp-tracking'); ?></th>
                            <th><?php esc_html_e('FP Event Name', 'fp-tracking'); ?></th>
                            <th><?php esc_html_e('Google Ads Conversion Label', 'fp-tracking'); ?></th>
                            <th><?php esc_html_e('Status', 'fp-tracking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (self::ADS_EVENTS as $event_name => $event_label):
                        $saved_label = $this->get_ads_label($event_name);
                        $has_label   = $saved_label !== '';
                        $row_style   = $has_label ? 'background:#f0fff4' : '';
                    ?>
                        <tr style="<?php echo esc_attr($row_style); ?>">
                            <td style="text-align:center;font-size:18px">
                                <?php echo $has_label ? '✅' : '⬜'; ?>
                            </td>
                            <td><strong><?php echo esc_html($event_label); ?></strong></td>
                            <td><code style="font-size:11px;background:#f6f7f7;padding:2px 6px;border-radius:3px"><?php echo esc_html($event_name); ?></code></td>
                            <td>
                                <input
                                    type="text"
                                    name="fp_ads_labels[<?php echo esc_attr($event_name); ?>]"
                                    value="<?php echo esc_attr($saved_label); ?>"
                                    placeholder="es. AbCdEfGhIjKlMnOpQrSt"
                                    class="regular-text"
                                    style="font-family:monospace;font-size:12px"
                                >
                            </td>
                            <td>
                                <?php if ($has_label): ?>
                                    <span style="color:#2e7d32;font-weight:600">&#10003; <?php esc_html_e('Configured', 'fp-tracking'); ?></span>
                                <?php else: ?>
                                    <span style="color:#999">&#8212; <?php esc_html_e('Not set', 'fp-tracking'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="padding:12px 10px">
                                <p class="description" style="margin:0 0 10px">
                                    <?php esc_html_e('Where to find the label: Google Ads → Goals → Conversions → click a conversion → Tag setup → Use Google Tag Manager → copy the "Conversion label" value.', 'fp-tracking'); ?>
                                </p>
                                <?php submit_button(
                                    __('Save Conversion Labels', 'fp-tracking'),
                                    'secondary',
                                    'submit',
                                    false
                                ); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </form>

            <hr>
            <h2><?php esc_html_e('Export GTM Container', 'fp-tracking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Download a ready-to-import GTM container JSON with all tags, triggers and variables pre-configured for this site. Import it in GTM → Admin → Import Container.', 'fp-tracking'); ?>
            </p>
            <table class="form-table" style="max-width:700px">
                <tr>
                    <th scope="row"><?php esc_html_e('Included in export', 'fp-tracking'); ?></th>
                    <td>
                        <ul style="margin:0;list-style:disc;padding-left:1.4em;line-height:1.8">
                            <li><?php esc_html_e('GA4 Configuration tag (fires on All Pages)', 'fp-tracking'); ?></li>
                            <li><?php printf(esc_html__('%d GA4 Event tags (one per tracked event)', 'fp-tracking'), count(GTMExporter::EVENTS)); ?></li>
                            <li><?php esc_html_e('Google Ads Conversion tags for purchase, booking, lead, add_to_cart', 'fp-tracking'); ?></li>
                            <?php if ($this->get('meta_pixel_id')): ?>
                            <li><?php esc_html_e('Meta Pixel base code + event tags (Purchase, Lead, Contact…)', 'fp-tracking'); ?></li>
                            <?php endif; ?>
                            <li><?php esc_html_e('Consent Mode v2 initialization tag', 'fp-tracking'); ?></li>
                            <li><?php esc_html_e('dataLayer variables for all event parameters', 'fp-tracking'); ?></li>
                            <li><?php esc_html_e('Custom Event triggers for every FP event', 'fp-tracking'); ?></li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Download', 'fp-tracking'); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="fp_tracking_export_gtm">
                            <?php wp_nonce_field('fp_tracking_export_gtm'); ?>
                            <?php submit_button(
                                __('⬇ Download GTM Container JSON', 'fp-tracking'),
                                'primary',
                                'submit',
                                false
                            ); ?>
                        </form>
                        <?php
                        $labels_count = count(array_filter(
                            array_map(fn(string $e) => $this->get_ads_label($e), array_keys(self::ADS_EVENTS))
                        ));
                        $total_ads = count(self::ADS_EVENTS);
                        ?>
                        <p class="description" style="margin-top:8px">
                            <?php if ($labels_count === $total_ads): ?>
                                <span style="color:#2e7d32">&#10003; <?php esc_html_e('All Google Ads conversion labels are configured — the export is fully ready.', 'fp-tracking'); ?></span>
                            <?php elseif ($labels_count > 0): ?>
                                <span style="color:#e65100">&#9888; <?php printf(
                                    esc_html__('%1$d of %2$d Google Ads labels configured. Configure the remaining ones above before exporting.', 'fp-tracking'),
                                    $labels_count,
                                    $total_ads
                                ); ?></span>
                            <?php else: ?>
                                <span style="color:#c62828">&#9888; <?php esc_html_e('No Google Ads conversion labels configured yet. Set them above to include them in the export.', 'fp-tracking'); ?></span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>
            <h2><?php esc_html_e('Active Integrations', 'fp-tracking'); ?></h2>
            <table class="widefat" style="max-width:600px">
                <thead><tr><th><?php esc_html_e('Plugin', 'fp-tracking'); ?></th><th><?php esc_html_e('Status', 'fp-tracking'); ?></th></tr></thead>
                <tbody>
                <?php
                $integrations = apply_filters('fp_tracking_registered_integrations', []);
                if (empty($integrations)) {
                    echo '<tr><td colspan="2">' . esc_html__('No integrations registered yet.', 'fp-tracking') . '</td></tr>';
                } else {
                    foreach ($integrations as $name => $active) {
                        $badge = $active
                            ? '<span style="color:green">&#10003; ' . esc_html__('Active', 'fp-tracking') . '</span>'
                            : '<span style="color:#aaa">&#8212; ' . esc_html__('Inactive', 'fp-tracking') . '</span>';
                        echo '<tr><td>' . esc_html($name) . '</td><td>' . $badge . '</td></tr>';
                    }
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
