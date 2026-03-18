<?php

declare(strict_types=1);

namespace FPTracking\Brevo;

final class BrevoMapper {

    private const OPTION_KEY = 'fp_tracking_brevo_mapping';

    /**
     * @param array<string,mixed> $params
     * @return array{
     *   event_name:string,
     *   identifiers:array<string,mixed>,
     *   event_properties:array<string,mixed>,
     *   contact_properties:array<string,mixed>,
     *   skip:bool
     * }
     */
    public function map(string $event_name, array $params): array {
        $mapping = get_option(self::OPTION_KEY, []);
        if (!is_array($mapping)) {
            $mapping = [];
        }

        $mappedName = $this->sanitize_event_name((string) ($mapping[$event_name] ?? $event_name));
        $userData = isset($params['user_data']) && is_array($params['user_data']) ? $params['user_data'] : [];

        $identifiers = [];
        $email = '';
        if (isset($userData['em']) && is_string($userData['em'])) {
            $email = sanitize_email($userData['em']);
        } elseif (isset($params['email'])) {
            $email = sanitize_email((string) $params['email']);
        }
        if ($email !== '') {
            $identifiers['email_id'] = $email;
        }
        if (isset($userData['external_id']) && is_scalar($userData['external_id'])) {
            $identifiers['ext_id'] = sanitize_text_field((string) $userData['external_id']);
        }
        if (isset($userData['ph']) && is_scalar($userData['ph'])) {
            $identifiers['phone_id'] = sanitize_text_field((string) $userData['ph']);
        }

        // If no contact identifier is available we skip Brevo event dispatch.
        $skip = ($identifiers === []);

        $eventProperties = $params;
        unset($eventProperties['user_data']);
        $eventProperties['event_id'] = (string) ($params['event_id'] ?? uniqid('fp_', true));
        $eventProperties['event_name_source'] = $event_name;

        $contactProperties = [];
        if (isset($userData['fn'])) {
            $contactProperties['FIRSTNAME'] = sanitize_text_field((string) $userData['fn']);
        }
        if (isset($userData['ln'])) {
            $contactProperties['LASTNAME'] = sanitize_text_field((string) $userData['ln']);
        }
        if (isset($userData['ph'])) {
            $contactProperties['SMS'] = sanitize_text_field((string) $userData['ph']);
        }

        return [
            'event_name' => $mappedName,
            'identifiers' => $identifiers,
            'event_properties' => $eventProperties,
            'contact_properties' => $contactProperties,
            'skip' => $skip,
        ];
    }

    private function sanitize_event_name(string $name): string {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
        if (!is_string($clean) || $clean === '') {
            return 'fp_event';
        }
        return substr($clean, 0, 255);
    }
}

