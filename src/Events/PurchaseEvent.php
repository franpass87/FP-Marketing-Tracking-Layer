<?php

namespace FPTracking\Events;

/**
 * Represents a purchase/ecommerce conversion event.
 * Used by FP-Experiences (woocommerce_thankyou).
 *
 * Usage in FP-Experiences:
 *   (new PurchaseEvent($order_id, $value, $currency, $items))->fire();
 *   // or directly:
 *   do_action('fp_tracking_event', 'purchase', [...]);
 */
final class PurchaseEvent implements BaseEvent {

    private string $event_id;

    public function __construct(
        private readonly string $transaction_id,
        private readonly float  $value,
        private readonly string $currency,
        private readonly array  $items = [],
        private readonly array  $extra = []
    ) {
        $this->event_id = 'purchase_' . $transaction_id . '_' . time();
    }

    public function event_name(): string {
        return 'purchase';
    }

    public function params(): array {
        return array_merge([
            'transaction_id' => $this->transaction_id,
            'value'          => $this->value,
            'currency'       => $this->currency,
            'items'          => $this->items,
            'event_id'       => $this->event_id,
        ], $this->extra);
    }

    public function fire(): void {
        do_action('fp_tracking_event', $this->event_name(), $this->params());
    }
}
