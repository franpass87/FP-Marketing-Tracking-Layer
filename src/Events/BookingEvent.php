<?php

namespace FPTracking\Events;

/**
 * Represents a booking/reservation conversion event.
 * Used by FP-Restaurant-Reservations and FP-Experiences booking flows.
 *
 * Usage in FP-Restaurant-Reservations:
 *   do_action('fp_tracking_event', 'booking_confirmed', [...]);
 */
final class BookingEvent implements BaseEvent {

    private string $event_id;

    public function __construct(
        private readonly string $event_name_key,  // 'booking_confirmed' | 'booking_submitted' | 'event_ticket_purchase' | 'waitlist_joined'
        private readonly int    $reservation_id,
        private readonly float  $value,
        private readonly string $currency,
        private readonly array  $extra = []
    ) {
        $this->event_id = uniqid('bk_' . $reservation_id . '_', true);
    }

    public function event_name(): string {
        return $this->event_name_key;
    }

    public function params(): array {
        return array_merge([
            'reservation_id' => $this->reservation_id,
            'transaction_id' => 'resv-' . $this->reservation_id,
            'value'          => $this->value,
            'currency'       => $this->currency,
            'event_id'       => $this->event_id,
        ], $this->extra);
    }

    public function fire(): void {
        do_action('fp_tracking_event', $this->event_name(), $this->params());
    }
}
