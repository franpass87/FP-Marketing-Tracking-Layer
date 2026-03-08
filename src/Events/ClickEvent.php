<?php

namespace FPTracking\Events;

/**
 * Represents a click/interaction event.
 * Used by FP-Bio-Standalone and FP-CTA-Bar.
 *
 * Usage:
 *   do_action('fp_tracking_event', 'click', [
 *       'element'  => 'cta_bar',
 *       'label'    => 'Prenota ora',
 *       'url'      => 'https://...',
 *       'position' => 'bottom_right',
 *   ]);
 */
final class ClickEvent implements BaseEvent {

    public function __construct(
        private readonly string $element,
        private readonly string $label,
        private readonly string $url = '',
        private readonly array  $extra = []
    ) {}

    public function event_name(): string {
        return 'click';
    }

    public function params(): array {
        return array_merge([
            'element' => $this->element,
            'label'   => $this->label,
            'url'     => $this->url,
        ], $this->extra);
    }

    public function fire(): void {
        do_action('fp_tracking_event', $this->event_name(), $this->params());
    }
}
