<?php

namespace FPTracking\Events;

/**
 * Represents a standard page view event.
 * Fired automatically on every frontend page load.
 *
 * Note: GTM fires its own page_view via the gtm.js container.
 * This event is available for custom dataLayer listeners.
 */
final class PageViewEvent implements BaseEvent {

    public function event_name(): string {
        return 'page_view';
    }

    public function params(): array {
        return [
            'page_title'    => get_the_title(),
            'page_location' => home_url(add_query_arg(null, null)),
            'page_path'     => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/',
        ];
    }

    public function fire(): void {
        do_action('fp_tracking_event', $this->event_name(), $this->params());
    }
}
