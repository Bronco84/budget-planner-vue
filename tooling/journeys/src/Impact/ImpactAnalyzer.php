<?php

namespace Tooling\Journeys\Impact;

use Tooling\Journeys\Analysis\FactStore;
use Tooling\Journeys\Catalog\Journey;
use Tooling\Journeys\Catalog\TestRef;
use Tooling\Journeys\Config;
use Tooling\Journeys\Coverage\Coverage;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Graph\Graph;
use Tooling\Journeys\Index\IndexWriter;
use Tooling\Journeys\Workspace;

/**
 * Maps a change set to affected journeys and tests.
 *
 * Every changed file receives exactly one classification. Anything the graph
 * cannot place is reported as uncertain and triggers a full-suite
 * recommendation; absence of a mapping is never treated as "no impact".
 */
final class ImpactAnalyzer
{
    public const TOOLING_PATHS = ['tooling/journeys/', 'bin/journeys'];

    public const TOOLING_TESTS = 'tests/Unit/Journeys/';

    private ?Workspace $base = null;

    private ?Workspace $target = null;

    public function __construct(
        private readonly Git $git,
        private readonly ?FactStore $store = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(ChangeSet $set): array
    {
        $store = $this->store ?? Workspace::defaultStore($this->git);
        $this->target = Workspace::open($this->git, $set->targetKind, $set->targetRef, $store);
        $this->base = Workspace::open($this->git, $set->baseKind, $set->baseRef, $store);
        $config = $this->target->config;

        $journeys = [];
        $changedNodes = ['target' => [], 'base' => []];
        $tests = [];
        $fullSuite = [];
        $uncertain = [];
        $files = [];

        $definitionFiles = $this->target->catalog()->byFile() + $this->base->catalog()->byFile();

        foreach ($set->changes as $change) {
            $entry = ['status' => $change->status, 'path' => $change->path, 'old_path' => $change->oldPath, 'classification' => null, 'detail' => null];
            $path = $change->path;

            if ($this->isTooling($path) || ($change->oldPath !== null && $this->isTooling($change->oldPath))) {
                $entry['classification'] = 'journey_tooling';
                $entry['detail'] = 'journey analyzer source; run its tests';
                $tests[self::TOOLING_TESTS] = 'journey tooling changed';
            } elseif (isset($definitionFiles[$path]) || ($change->oldPath !== null && isset($definitionFiles[$change->oldPath]))) {
                $id = $definitionFiles[$path] ?? $definitionFiles[(string) $change->oldPath];
                $entry['classification'] = 'journey_definition';
                $entry['detail'] = $id;
                $this->hit($journeys, $id, 'definition', $path, [$path]);
            } elseif (in_array($path, [Config::PATH, IndexWriter::INDEX_PATH, IndexWriter::BASELINE_PATH], true) || str_starts_with($path, Config::JOURNEY_DIR)) {
                $entry['classification'] = 'journey_catalog';
                $entry['detail'] = 'catalog metadata; validated, no behaviour change';
            } elseif ($config->isIgnored($path)) {
                $entry['classification'] = 'ignored';
            } elseif (($shared = $config->sharedMatches($path)) !== []) {
                $entry['classification'] = 'shared_infrastructure';
                $entry['detail'] = $shared[0]['reason'];
                foreach ($this->journeysInScope($shared[0]['scope']) as $journey) {
                    $this->hit($journeys, $journey->id, 'shared', $path, [$path]);
                }
                if ($shared[0]['scope'] !== 'frontend') {
                    $fullSuite[] = "{$path}: shared infrastructure ({$shared[0]['reason']})";
                }
                $changedNodes['target'][] = Graph::fileNode($path);
            } elseif ($config->isTestFile($path)) {
                $entry['classification'] = 'test';
                if ($change->existsInTarget() && FactStore::kindOf($path) !== null) {
                    $tests[$path] = 'test file changed';
                }
                foreach ($this->target->catalog()->journeys as $journey) {
                    if (in_array($path, $journey->testFiles(), true) || ($change->oldPath !== null && in_array($change->oldPath, $journey->testFiles(), true))) {
                        $this->hit($journeys, $journey->id, 'test', $path, [$path]);
                    }
                }
                if (! $change->existsInTarget() || $change->status === 'R') {
                    foreach ($this->target->catalog()->journeys as $journey) {
                        if (in_array($change->basePath(), $journey->testFiles(), true)) {
                            $uncertain[] = "{$change->basePath()}: removed/renamed test is still referenced by {$journey->id}";
                        }
                    }
                }
                // Test files can also be test *support* (helpers, base classes).
                $changedNodes['target'][] = Graph::fileNode($path);
            } elseif ($config->isRouteFile($path) || ($change->oldPath !== null && $config->isRouteFile($change->oldPath))) {
                $entry['classification'] = 'routes';
                $routes = $this->changedRoutes($change);
                $entry['detail'] = $routes === [] ? 'no route definitions touched' : implode(', ', array_map(fn ($n) => substr($n, 6), array_values(array_unique([...$routes['target'], ...$routes['base']]))));
                $changedNodes['target'] = [...$changedNodes['target'], ...$routes['target']];
                $changedNodes['base'] = [...$changedNodes['base'], ...$routes['base']];
            } else {
                [$classification, $detail] = $this->classifySource($change, $config);
                $entry['classification'] = $classification;
                $entry['detail'] = $detail;
                if ($change->existsInTarget()) {
                    $changedNodes['target'][] = Graph::fileNode($path);
                }
                if (($old = $change->basePath()) !== null) {
                    $changedNodes['base'][] = Graph::fileNode($old);
                }
                if (in_array($classification, ['unmapped', 'unreached', 'unanalyzable'], true)) {
                    $uncertain[] = "{$path}: {$detail}";
                }
            }

            $files[] = $entry;
        }

        // Graph traversal: which journeys transitively depend on changed nodes?
        foreach (['target' => $this->target, 'base' => $this->base] as $side => $ws) {
            $nodes = array_fill_keys($changedNodes[$side], true);
            if ($nodes === []) {
                continue;
            }
            foreach ($ws->catalog()->journeys as $journey) {
                $closure = $ws->closure($journey);
                foreach (array_keys($nodes) as $node) {
                    if (isset($closure[$node])) {
                        $path = Graph::pathTo($closure, $node);
                        // Direct: the node is a root, or only route/action nodes sit
                        // between a root and it (the journey's own controller/page).
                        $between = array_slice($path, 0, -1);
                        $kind = array_filter($between, fn ($n) => Graph::pathOf($n) !== null) === [] ? 'direct' : 'transitive';
                        $this->hit($journeys, $journey->id, $kind, $this->label($node), array_map(fn ($n) => $this->label($n), $path));
                    }
                }
            }
            // Tests that reach a changed node, whether or not a journey maps them.
            foreach (array_keys($ws->graph()->testEntries) as $testPath) {
                if (! $this->target->snapshot->has($testPath)) {
                    continue;
                }
                $closure = $ws->testClosure($testPath);
                foreach (array_keys($nodes) as $node) {
                    if (isset($closure[$node])) {
                        $tests[$testPath] ??= 'reaches '.$this->label($node);
                        break;
                    }
                }
            }
        }

        return $this->report($set, $files, $journeys, $tests, $fullSuite, $uncertain);
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function classifySource(Change $change, Config $config): array
    {
        $path = $change->path;
        $ws = $change->existsInTarget() ? $this->target : $this->base;
        $lookup = $change->existsInTarget() ? $path : (string) $change->basePath();
        $node = Graph::fileNode($lookup);
        $graph = $ws->graph();

        if (! $graph->hasNode($node)) {
            if (($nonJourney = $config->nonJourneyMatch($path)) !== null) {
                return ['non_journey', $nonJourney['reason']];
            }

            return FactStore::kindOf($path) === null
                ? ['unmapped', 'not a file type the analyzer understands and not covered by config']
                : ['unmapped', 'outside the analysis roots'];
        }

        if (! empty($graph->facts[$lookup]['error'])) {
            return ['unanalyzable', (string) $graph->facts[$lookup]['error']];
        }

        $candidates = [];
        if ($change->existsInTarget()) {
            $candidates[] = [$this->target, $path];
        }
        if (($old = $change->basePath()) !== null) {
            $candidates[] = [$this->base, $old];
        }

        $reached = false;
        foreach ($candidates as [$side, $candidate]) {
            foreach ($side->catalog()->journeys as $journey) {
                if (isset($side->closure($journey)[Graph::fileNode($candidate)])) {
                    $reached = true;
                    break 2;
                }
            }
        }

        if ($reached) {
            return ['mapped', null];
        }

        if (($nonJourney = $config->nonJourneyMatch($path)) !== null) {
            return ['non_journey', $nonJourney['reason']];
        }

        $dependants = array_filter(array_keys($graph->dependantsClosure([$node])), fn ($n) => $n !== $node);

        return ['unreached', $dependants === []
            ? 'no journey depends on this file and nothing references it (new code, dead code, or a missing mapping)'
            : 'referenced only by code that no journey reaches'];
    }

    /**
     * Attribute changed lines in a route file to the routes defined there.
     *
     * @return array{target: list<string>, base: list<string>}
     */
    private function changedRoutes(Change $change): array
    {
        $out = ['target' => [], 'base' => []];
        $sides = [
            'target' => [$this->target, $change->existsInTarget() ? $change->path : null, $change->newLines],
            'base' => [$this->base, $change->basePath(), $change->oldLines],
        ];

        foreach ($sides as $side => [$ws, $path, $lines]) {
            if ($path === null) {
                continue;
            }
            $routes = array_filter($ws->graph()->routes, fn ($r) => $r['file'] === $path);
            $groups = $ws->graph()->routeGroups[$path] ?? [];

            if ($lines === null) {
                $out[$side] = array_map(fn ($id) => Graph::routeNode($id), array_keys($routes));

                continue;
            }

            foreach ($lines as $line) {
                $hit = [];
                foreach ($routes as $id => $route) {
                    if ($line >= $route['lines'][0] && $line <= $route['lines'][1]) {
                        $hit[] = $id;
                    }
                }
                if ($hit === []) {
                    // Innermost enclosing group: group attributes changed.
                    $best = null;
                    foreach ($groups as $group) {
                        if ($line >= $group['lines'][0] && $line <= $group['lines'][1]
                            && ($best === null || $group['lines'][1] - $group['lines'][0] < $best['lines'][1] - $best['lines'][0])) {
                            $best = $group;
                        }
                    }
                    // Outside any route or group (imports, top-level code): whole file.
                    $hit = $best['routes'] ?? array_keys($routes);
                }
                foreach ($hit as $id) {
                    $out[$side][] = Graph::routeNode($id);
                }
            }
            $out[$side] = array_values(array_unique($out[$side]));
        }

        return $out;
    }

    /**
     * @return list<Journey>
     */
    private function journeysInScope(string $scope): array
    {
        $out = [];
        foreach ($this->target->catalog()->journeys as $journey) {
            if ($scope === 'all') {
                $out[] = $journey;

                continue;
            }
            foreach (array_keys($this->target->closure($journey)) as $node) {
                $path = Graph::pathOf($node);
                if ($path !== null && match ($scope) {
                    'frontend' => str_starts_with($path, 'resources/js/'),
                    'backend' => str_ends_with($path, '.php'),
                    default => false,
                }) {
                    $out[] = $journey;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $journeys
     * @param  list<string>  $path
     */
    private function hit(array &$journeys, string $id, string $kind, string $changed, array $path): void
    {
        $journeys[$id] ??= ['reasons' => []];
        $key = $kind.'|'.$changed;
        $journeys[$id]['reasons'][$key] ??= ['kind' => $kind, 'changed' => $changed, 'path' => $path];
    }

    private function label(string $node): string
    {
        return Graph::pathOf($node) ?? $node;
    }

    private function isTooling(string $path): bool
    {
        foreach (self::TOOLING_PATHS as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return str_starts_with($path, self::TOOLING_TESTS);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, array<string, mixed>>  $journeys
     * @param  array<string, string>  $tests
     * @param  list<string>  $fullSuite
     * @param  list<string>  $uncertain
     * @return array<string, mixed>
     */
    private function report(ChangeSet $set, array $files, array $journeys, array $tests, array $fullSuite, array $uncertain): array
    {
        $catalog = $this->target->catalog();
        $baseCatalog = $this->base->catalog();
        $impacted = [];
        $e2e = [];
        $warnings = [];

        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        foreach ($journeys as $id => $data) {
            $journey = $catalog->get($id) ?? $baseCatalog->get($id);
            if ($journey === null) {
                continue;
            }
            $reasons = array_values($data['reasons']);
            $kinds = array_column($reasons, 'kind');
            $coverage = $catalog->get($id) !== null ? Coverage::forJourney($journey) : null;
            $impacted[$id] = [
                'id' => $id,
                'name' => $journey->name,
                'priority' => $journey->priority,
                'status' => $catalog->get($id) === null ? 'removed' : $journey->status,
                'impact' => in_array('direct', $kinds, true) || in_array('definition', $kinds, true) ? 'direct' : (in_array('transitive', $kinds, true) ? 'transitive' : $kinds[0]),
                'coverage' => $coverage === null ? null : $coverage['status'],
                'reasons' => array_slice($reasons, 0, 5),
                'reason_count' => count($reasons),
            ];

            // Only behavioural impact pulls the journey's tests in.
            if (array_diff($kinds, ['test']) !== []) {
                foreach ($journey->testFiles() as $testFile) {
                    if (str_starts_with($testFile, Coverage::E2E_PREFIX)) {
                        $e2e[$testFile] = true;
                    } elseif ($this->target->snapshot->has($testFile)) {
                        $tests[$testFile] ??= 'covers '.$id;
                    }
                }
            }

            if ($coverage !== null && $coverage['gaps'] !== []) {
                $warnings[$id] = $coverage['gaps'];
            }
        }
        uasort($impacted, fn ($a, $b) => [$order[$a['priority']] ?? 9, $a['id']] <=> [$order[$b['priority']] ?? 9, $b['id']]);

        // New routes without a journey, and coverage that regressed vs. base.
        $newRoutes = [];
        $claimed = [];
        foreach ($catalog->journeys as $journey) {
            foreach ($journey->routes as $route) {
                $claimed[$route] = true;
            }
        }
        foreach (array_diff_key($this->target->graph()->routes, $this->base->graph()->routes) as $id => $route) {
            if (! isset($claimed[$id]) && ! isset($this->target->config->excludedRoutes[$id])) {
                $newRoutes[] = $id;
            }
        }
        $regressions = Coverage::regressions(Coverage::baseline($baseCatalog), $catalog);

        foreach (array_keys($tests) as $testPath) {
            if (str_starts_with($testPath, Coverage::E2E_PREFIX)) {
                $e2e[$testPath] = true;
                unset($tests[$testPath]);
            }
        }
        ksort($tests);
        ksort($e2e);
        $fullSuite = array_values(array_unique([...$fullSuite, ...array_map(fn ($u) => 'uncertain: '.$u, $uncertain)]));

        return [
            'mode' => $set->mode,
            'description' => $set->describe(),
            'base' => $this->base->snapshot->label(),
            'target' => $this->target->snapshot->label(),
            'files' => $files,
            'journeys' => array_values($impacted),
            'tests' => array_map(fn ($path, $why) => ['path' => $path, 'reason' => $why], array_keys($tests), array_values($tests)),
            'e2e_tests' => array_keys($e2e),
            'full_suite_recommended' => $fullSuite !== [],
            'full_suite_reasons' => $fullSuite,
            'uncertain' => $uncertain,
            'coverage_warnings' => $warnings,
            'new_unmapped_routes' => $newRoutes,
            'coverage_regressions' => $regressions,
        ];
    }

    /**
     * Resolve the PHP test paths to run, collapsing directories.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public static function phpTestPaths(array $report): array
    {
        $paths = [];
        foreach ($report['tests'] as $test) {
            $path = TestRef::parse($test['path'])->path;
            if (str_ends_with($path, '.php') || str_ends_with($path, '/')) {
                $paths[] = rtrim($path, '/');
            }
        }

        return array_values(array_unique($paths));
    }
}
