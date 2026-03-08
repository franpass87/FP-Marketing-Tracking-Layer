<?php

namespace FPTracking\Events;

/**
 * Represents a lead generation event (form submission).
 * Used by FP-Forms.
 *
 * Usage in FP-Forms:
 *   do_action('fp_tracking_event', 'generate_lead', [...]);
 */
final class LeadEvent implements BaseEvent {

    private string $event_id;

    public function __construct(
        private readonly int    $form_id,
        private readonly string $form_title,
        private readonly int    $submission_id,
        private readonly float  $value = 1.0,
        private readonly string $currency = 'EUR',
        private readonly array  $user_data = [],
        private readonly array  $extra = []
    ) {
        $this->event_id = 'fp_forms_' . $submission_id . '_' . time();
    }

    public function event_name(): string {
        return 'generate_lead';
    }

    public function params(): array {
        return array_merge([
            'form_id'       => $this->form_id,
            'form_title'    => $this->form_title,
            'submission_id' => $this->submission_id,
            'value'         => $this->value,
            'currency'      => $this->currency,
            'event_id'      => $this->event_id,
            'user_data'     => $this->user_data,
        ], $this->extra);
    }

    public function fire(): void {
        do_action('fp_tracking_event', $this->event_name(), $this->params());
    }
}
