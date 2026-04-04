<?php

namespace FPTracking\Integrations;

use FPTracking\Admin\Settings;

/**
 * WooCommerce GA4 Ecommerce integration.
 *
 * Hooks into WooCommerce actions and fires do_action('fp_tracking_event', ...)
 * for all standard GA4 ecommerce events.
 *
 * Events covered:
 *   view_item_list   — archive / shop / category pages
 *   view_item        — single product page
 *   add_to_cart      — when product added to cart
 *   view_cart        — cart page
 *   begin_checkout   — checkout page load
 *   purchase         — thank you page (order received)
 *   refund           — when order is refunded (admin)
 */
final class WooCommerceIntegration {

    public function __construct(private readonly Settings $settings) {}

    public function register_hooks(): void {
        if (!$this->is_woocommerce_active()) {
            return;
        }

        // Product list views
        add_action('woocommerce_before_shop_loop_item', [$this, 'track_view_item_list'], 5);

        // Single product view
        add_action('woocommerce_before_single_product', [$this, 'track_view_item']);

        // Add to cart (AJAX + standard)
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);

        // Cart page
        add_action('woocommerce_before_cart', [$this, 'track_view_cart']);

        // Checkout page
        add_action('woocommerce_before_checkout_form', [$this, 'track_begin_checkout']);

        // Purchase (thank you page)
        add_action('woocommerce_thankyou', [$this, 'track_purchase'], 10, 1);

        // Refund (admin)
        add_action('woocommerce_order_refunded', [$this, 'track_refund'], 10, 2);
    }

    public function track_view_item_list(): void {
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }

        static $tracked_ids = [];
        $id = $product->get_id();
        if (isset($tracked_ids[$id])) {
            return;
        }
        $tracked_ids[$id] = true;

        do_action('fp_tracking_event', 'view_item_list', [
            'item_list_name' => $this->get_list_name(),
            'items'          => [$this->product_to_item($product)],
            'fp_source'      => 'woocommerce',
        ]);
    }

    public function track_view_item(): void {
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }

        do_action('fp_tracking_event', 'view_item', [
            'items' => [$this->product_to_item($product)],
            'fp_source' => 'woocommerce',
        ]);
    }

    public function track_add_to_cart(
        string $cart_item_key,
        int    $product_id,
        int    $quantity,
        int    $variation_id,
        array  $variation,
        array  $cart_item_data
    ): void {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product instanceof \WC_Product) {
            return;
        }

        $item = $this->product_to_item($product, $quantity);

        $params = [
            'value'    => (float) $product->get_price() * $quantity,
            'currency' => get_woocommerce_currency(),
            'items'    => [$item],
            'fp_source' => 'woocommerce',
        ];
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user instanceof \WP_User) {
                $params['user_data'] = [
                    'em' => $user->user_email,
                    'fn' => $user->first_name ?? '',
                    'ln' => $user->last_name ?? '',
                ];
            }
        }
        do_action('fp_tracking_event', 'add_to_cart', $params);
    }

    public function track_view_cart(): void {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }

        do_action('fp_tracking_event', 'view_cart', [
            'value'    => (float) $cart->get_cart_contents_total(),
            'currency' => get_woocommerce_currency(),
            'items'    => $this->cart_to_items($cart),
            'fp_source' => 'woocommerce',
        ]);
    }

    public function track_begin_checkout(): void {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }

        do_action('fp_tracking_event', 'begin_checkout', [
            'value'    => (float) $cart->get_cart_contents_total(),
            'currency' => get_woocommerce_currency(),
            'items'    => $this->cart_to_items($cart),
            'fp_source' => 'woocommerce',
        ]);
    }

    public function track_purchase(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            // Skip FP-Experiences items — WordPressIntegration bridge handles those via fp_exp_reservation_paid → experience_paid
            if ($item->get_type() === 'fp_experience_item') {
                continue;
            }
            // Skip FP-Experiences gift orders — GiftOrderHandler fires fp_exp_gift_purchased
            if ($order->get_meta('_fp_exp_is_gift_order') === 'yes') {
                return;
            }
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $items[] = [
                'item_id'   => (string) $product->get_id(),
                'item_name' => $product->get_name(),
                'price'     => (float) $item->get_total() / max(1, $item->get_quantity()),
                'quantity'  => $item->get_quantity(),
                'item_brand'    => '',
                'item_category' => $this->get_primary_category($product),
            ];
        }

        // Solo righe WooCommerce "normali": ordini FP Experiences (fp_experience_item) → bridge `experience_paid`; gift → `gift_purchased`.
        if ($items === []) {
            return;
        }

        do_action('fp_tracking_event', 'purchase', [
            'transaction_id' => (string) $order->get_id(),
            'value'          => (float) $order->get_total(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'currency'       => $order->get_currency(),
            'coupon'         => implode(',', $order->get_coupon_codes()),
            'items'          => $items,
            'event_id'       => 'woo_purchase_' . $order_id . '_' . time(),
            'fp_source'      => 'woocommerce',
            'user_data'      => [
                'em' => $order->get_billing_email(),
                'fn' => $order->get_billing_first_name(),
                'ln' => $order->get_billing_last_name(),
                'ph' => $order->get_billing_phone(),
                'ct' => $order->get_billing_city(),
                'st' => $order->get_billing_state(),
                'zp' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
        ]);
    }

    public function track_refund(int $order_id, int $refund_id): void {
        $order  = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order instanceof \WC_Order || !$refund instanceof \WC_Order_Refund) {
            return;
        }

        do_action('fp_tracking_event', 'refund', [
            'transaction_id' => (string) $order_id,
            'value'          => abs((float) $refund->get_amount()),
            'currency'       => $order->get_currency(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function product_to_item(\WC_Product $product, int $quantity = 1): array {
        return [
            'item_id'       => (string) $product->get_id(),
            'item_name'     => $product->get_name(),
            'price'         => (float) $product->get_price(),
            'quantity'      => $quantity,
            'item_category' => $this->get_primary_category($product),
            'item_brand'    => '',
        ];
    }

    private function cart_to_items(\WC_Cart $cart): array {
        $items = [];
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $items[] = $this->product_to_item($product, (int) $cart_item['quantity']);
        }
        return $items;
    }

    private function get_primary_category(\WC_Product $product): string {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!is_array($terms) || empty($terms)) {
            return '';
        }
        return $terms[0]->name ?? '';
    }

    private function get_list_name(): string {
        if (is_product_category()) {
            return single_cat_title('', false);
        }
        if (is_shop()) {
            return 'Shop';
        }
        if (is_search()) {
            return 'Search Results';
        }
        return get_the_title() ?: 'Product List';
    }

    private function is_woocommerce_active(): bool {
        return class_exists('WooCommerce');
    }
}
