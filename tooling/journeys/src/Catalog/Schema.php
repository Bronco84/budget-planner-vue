<?php

namespace Tooling\Journeys\Catalog;

/**
 * Journey schema, version 1. This class is the single source of truth for the
 * format; docs/user-journeys/README.md describes it for humans.
 */
final class Schema
{
    public const VERSION = 1;

    public const STATUSES = ['implemented', 'unverified', 'planned', 'deprecated'];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public const KINDS = ['happy_path', 'validation', 'authorization', 'authentication', 'alternate', 'edge', 'empty_state', 'integration', 'recovery'];

    public const COVERAGE = ['covered', 'partial', 'uncovered', 'not_applicable'];

    public const CONFIDENCE = ['high', 'medium', 'low'];

    private const ALLOWED_KEYS = [
        'schema', 'id', 'name', 'domain', 'status', 'priority', 'summary', 'actors', 'preconditions',
        'routes', 'depends_on', 'integrations', 'steps', 'postconditions', 'business_rules',
        'scenarios', 'known_issues', 'confidence', 'evidence', 'last_verified',
    ];

    private const SCENARIO_KEYS = ['id', 'kind', 'description', 'coverage', 'tests', 'note'];

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> error messages
     */
    public static function validate(array $data): array
    {
        $errors = [];

        foreach (array_diff(array_keys($data), self::ALLOWED_KEYS) as $key) {
            $errors[] = "unknown key '{$key}'";
        }

        if (($data['schema'] ?? null) !== self::VERSION) {
            $errors[] = 'schema must be '.self::VERSION;
        }

        if (! is_string($data['id'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9]*-\d{3}$/', $data['id'])) {
            $errors[] = 'id must match DOMAIN-000 (e.g. AUTH-001)';
        }

        foreach (['name', 'domain', 'summary'] as $key) {
            if (! is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                $errors[] = "{$key} is required";
            }
        }

        $errors = [...$errors, ...self::enum($data, 'status', self::STATUSES), ...self::enum($data, 'priority', self::PRIORITIES)];

        if (isset($data['confidence'])) {
            $errors = [...$errors, ...self::enum($data, 'confidence', self::CONFIDENCE)];
        }

        foreach (['actors', 'routes', 'preconditions', 'postconditions', 'business_rules', 'integrations', 'known_issues', 'evidence'] as $key) {
            if (isset($data[$key]) && ! self::isStringList($data[$key])) {
                $errors[] = "{$key} must be a list of strings";
            }
        }

        if (! isset($data['actors']) || $data['actors'] === []) {
            $errors[] = 'actors must list at least one actor';
        }

        foreach ((array) ($data['depends_on'] ?? []) as $i => $dep) {
            if (! is_array($dep) || ! is_string($dep['path'] ?? null) || ! is_string($dep['reason'] ?? null)) {
                $errors[] = "depends_on[{$i}] needs path and reason";
            }
        }

        foreach ((array) ($data['steps'] ?? []) as $i => $step) {
            if (! is_array($step) || ! is_string($step['action'] ?? null) || ! is_string($step['expected'] ?? null)) {
                $errors[] = "steps[{$i}] needs action and expected";
            }
        }

        if (array_key_exists('last_verified', $data) && $data['last_verified'] !== null
            && ! (is_string($data['last_verified']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['last_verified']))) {
            $errors[] = 'last_verified must be null or YYYY-MM-DD';
        }

        $status = $data['status'] ?? null;
        $scenarios = $data['scenarios'] ?? [];
        if (! is_array($scenarios)) {
            return [...$errors, 'scenarios must be a list'];
        }

        if (in_array($status, ['implemented', 'unverified'], true)) {
            if (($data['routes'] ?? []) === [] && ($data['depends_on'] ?? []) === []) {
                $errors[] = 'implemented journeys must declare routes or depends_on';
            }
            if (! in_array('happy_path', array_column(array_filter($scenarios, 'is_array'), 'kind'), true)) {
                $errors[] = 'implemented journeys must declare a happy_path scenario';
            }
        }

        $seen = [];
        foreach ($scenarios as $i => $scenario) {
            $label = 'scenarios['.(is_array($scenario) && is_string($scenario['id'] ?? null) ? $scenario['id'] : $i).']';
            if (! is_array($scenario)) {
                $errors[] = "{$label} must be a mapping";

                continue;
            }
            foreach (array_diff(array_keys($scenario), self::SCENARIO_KEYS) as $key) {
                $errors[] = "{$label}: unknown key '{$key}'";
            }
            if (! is_string($scenario['id'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]*$/', $scenario['id'])) {
                $errors[] = "{$label}: id must be snake_case";
            } elseif (isset($seen[$scenario['id']])) {
                $errors[] = "{$label}: duplicate scenario id";
            }
            $seen[$scenario['id'] ?? $i] = true;
            if (! is_string($scenario['description'] ?? null) || trim($scenario['description']) === '') {
                $errors[] = "{$label}: description is required";
            }
            foreach ([...self::enum($scenario, 'kind', self::KINDS), ...self::enum($scenario, 'coverage', self::COVERAGE)] as $message) {
                $errors[] = "{$label}: {$message}";
            }
            $tests = $scenario['tests'] ?? [];
            if (! self::isStringList($tests)) {
                $errors[] = "{$label}: tests must be a list of test references";

                continue;
            }
            $coverage = $scenario['coverage'] ?? null;
            if (in_array($coverage, ['covered', 'partial'], true) && $tests === []) {
                $errors[] = "{$label}: coverage '{$coverage}' requires at least one test reference";
            }
            if ($coverage === 'uncovered' && $tests !== []) {
                $errors[] = "{$label}: coverage 'uncovered' must not list tests (use 'partial')";
            }
            if (in_array($coverage, ['partial', 'not_applicable'], true) && trim((string) ($scenario['note'] ?? '')) === '') {
                $errors[] = "{$label}: coverage '{$coverage}' requires a note explaining why";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function enum(array $data, string $key, array $allowed): array
    {
        $value = $data[$key] ?? null;

        return in_array($value, $allowed, true) ? [] : ["{$key} must be one of: ".implode(', ', $allowed)];
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value);
    }
}
