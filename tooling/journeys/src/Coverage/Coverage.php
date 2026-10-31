<?php

namespace Tooling\Journeys\Coverage;

use Tooling\Journeys\Catalog\Catalog;
use Tooling\Journeys\Catalog\Journey;
use Tooling\Journeys\Catalog\TestRef;

/**
 * Scenario-level coverage. A dimension is only "yes" when every scenario of
 * that kind is backed by referenced tests; line coverage is never consulted.
 */
final class Coverage
{
    public const DIMENSIONS = [
        'happy' => ['happy_path'],
        'negative' => ['validation', 'edge', 'empty_state'],
        'authorization' => ['authorization', 'authentication'],
        'alternate' => ['alternate'],
        'integration' => ['integration', 'recovery'],
    ];

    /** Dimensions every implemented journey must address (or mark not_applicable). */
    public const REQUIRED = ['happy', 'negative', 'authorization'];

    public const E2E_PREFIX = 'tests/playwright/';

    /**
     * @return array{happy:string, negative:string, authorization:string, alternate:string, integration:string, e2e:string, status:string, gaps:list<string>}
     */
    public static function forJourney(Journey $journey): array
    {
        $result = [];
        $gaps = [];

        foreach (self::DIMENSIONS as $dimension => $kinds) {
            $values = [];
            foreach ($journey->scenarios as $scenario) {
                if (in_array($scenario->kind, $kinds, true)) {
                    $values[] = $scenario->coverage;
                    if (in_array($scenario->coverage, ['uncovered', 'partial'], true)) {
                        $gaps[] = "{$scenario->id} ({$scenario->kind}) is {$scenario->coverage}".($scenario->note !== '' ? ": {$scenario->note}" : '');
                    }
                }
            }
            $result[$dimension] = self::dimension($values);
            if ($result[$dimension] === 'missing' && in_array($dimension, self::REQUIRED, true) && $journey->isImplemented()) {
                $gaps[] = "no {$dimension} scenario declared";
            }
        }

        $e2e = false;
        foreach ($journey->scenarios as $scenario) {
            foreach ($scenario->tests as $ref) {
                if (str_starts_with(TestRef::parse($ref)->path, self::E2E_PREFIX)) {
                    $e2e = true;
                }
            }
        }
        $result['e2e'] = $e2e ? 'yes' : 'no';
        $result['status'] = self::status($journey, $result);
        $result['gaps'] = $gaps;

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forCatalog(Catalog $catalog): array
    {
        $out = [];
        foreach ($catalog->journeys as $id => $journey) {
            $out[$id] = self::forJourney($journey);
        }

        return $out;
    }

    /**
     * Snapshot of scenario coverage used to detect regressions in CI.
     *
     * @return array<string, array<string, string>>
     */
    public static function baseline(Catalog $catalog): array
    {
        $baseline = [];
        foreach ($catalog->journeys as $id => $journey) {
            foreach ($journey->scenarios as $scenario) {
                $baseline[$id][$scenario->id] = $scenario->coverage;
            }
        }

        return $baseline;
    }

    /**
     * Scenarios that were covered in the baseline but no longer are.
     *
     * @param  array<string, array<string, string>>  $baseline
     * @return list<string>
     */
    public static function regressions(array $baseline, Catalog $catalog): array
    {
        $current = self::baseline($catalog);
        $rank = ['covered' => 3, 'partial' => 2, 'not_applicable' => 2, 'uncovered' => 1];
        $out = [];

        foreach ($baseline as $id => $scenarios) {
            $journey = $catalog->journeys[$id] ?? null;
            if ($journey === null) {
                $out[] = "{$id}: journey removed (mark it deprecated instead, or update the baseline intentionally)";

                continue;
            }
            if ($journey->status === 'deprecated') {
                continue;
            }
            foreach ($scenarios as $scenario => $was) {
                $now = $current[$id][$scenario] ?? 'missing';
                if (($rank[$was] ?? 0) >= 2 && ($rank[$now] ?? 0) < ($rank[$was] ?? 0) && ! ($was === 'not_applicable' && $now === 'partial')) {
                    $out[] = "{$id}.{$scenario}: coverage regressed from {$was} to {$now}";
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $values
     */
    private static function dimension(array $values): string
    {
        if ($values === []) {
            return 'missing';
        }
        $applicable = array_values(array_filter($values, fn ($v) => $v !== 'not_applicable'));
        if ($applicable === []) {
            return 'n/a';
        }
        if (count(array_filter($applicable, fn ($v) => $v === 'covered')) === count($applicable)) {
            return 'yes';
        }
        if (count(array_filter($applicable, fn ($v) => $v === 'uncovered')) === count($applicable)) {
            return 'no';
        }

        return 'partial';
    }

    /**
     * @param  array<string, string>  $dims
     */
    private static function status(Journey $journey, array $dims): string
    {
        if (! $journey->isImplemented()) {
            return $journey->status;
        }

        $scenarios = $journey->scenarios;
        $tested = array_filter($scenarios, fn ($s) => in_array($s->coverage, ['covered', 'partial'], true));
        if ($tested === []) {
            return 'uncovered';
        }

        $ok = fn (string $d, array $accept) => in_array($dims[$d], $accept, true);
        $allDone = count(array_filter($scenarios, fn ($s) => in_array($s->coverage, ['covered', 'not_applicable'], true))) === count($scenarios);

        if ($allDone && $ok('happy', ['yes']) && $ok('negative', ['yes', 'n/a']) && $ok('authorization', ['yes', 'n/a'])) {
            return 'covered';
        }

        if (in_array($journey->priority, ['critical', 'high'], true)
            && (! $ok('happy', ['yes']) || $ok('authorization', ['no', 'missing']))) {
            return 'at_risk';
        }

        return 'partial';
    }
}
