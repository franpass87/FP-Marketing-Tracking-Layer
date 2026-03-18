<?php

declare(strict_types=1);

namespace FPTracking\Rules;

final class EventRuleEngine {

    private const OPTION_KEY = 'fp_tracking_event_rules';

    /**
     * @return array{disabled_events:array<int,string>,renames:array<string,string>,enrich:array<string,mixed>}
     */
    public function get_rules(): array {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $disabled = array_values(array_filter(array_map(
            static fn($v): string => sanitize_key((string) $v),
            (array) ($saved['disabled_events'] ?? [])
        )));

        $renames = [];
        foreach ((array) ($saved['renames'] ?? []) as $from => $to) {
            $k = sanitize_key((string) $from);
            $v = sanitize_key((string) $to);
            if ($k !== '' && $v !== '') {
                $renames[$k] = $v;
            }
        }

        $enrich = [];
        foreach ((array) ($saved['enrich'] ?? []) as $key => $value) {
            $k = sanitize_key((string) $key);
            if ($k === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $enrich[$k] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }

        return [
            'disabled_events' => $disabled,
            'renames' => $renames,
            'enrich' => $enrich,
        ];
    }

    public function save_rules(array $rules): void {
        update_option(self::OPTION_KEY, [
            'disabled_events' => array_values((array) ($rules['disabled_events'] ?? [])),
            'renames' => (array) ($rules['renames'] ?? []),
            'enrich' => (array) ($rules['enrich'] ?? []),
        ]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{drop:bool,event_name:string,params:array<string,mixed>}
     */
    public function apply(string $event_name, array $params): array {
        $rules = $this->get_rules();
        $normalizedName = sanitize_key($event_name);

        if (in_array($normalizedName, $rules['disabled_events'], true)) {
            return [
                'drop' => true,
                'event_name' => $normalizedName,
                'params' => $params,
            ];
        }

        $renamed = $rules['renames'][$normalizedName] ?? $normalizedName;
        $merged = array_merge($params, $rules['enrich']);

        return [
            'drop' => false,
            'event_name' => $renamed,
            'params' => $merged,
        ];
    }
}

