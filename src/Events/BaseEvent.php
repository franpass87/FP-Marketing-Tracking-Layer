<?php

namespace FPTracking\Events;

/**
 * Interface for all FP Tracking events.
 * Each event class knows how to build its own params array.
 */
interface BaseEvent {

    /**
     * Returns the FP internal event name (e.g. 'purchase', 'generate_lead').
     */
    public function event_name(): string;

    /**
     * Returns the event parameters array.
     */
    public function params(): array;

    /**
     * Fires the event by calling do_action('fp_tracking_event', ...).
     */
    public function fire(): void;
}
