<?php

namespace Tooling\Journeys\Validation;

use Tooling\Journeys\Catalog\Journey;
use Tooling\Journeys\Catalog\TestRef;
use Tooling\Journeys\Config;
use Tooling\Journeys\Coverage\Coverage;
use Tooling\Journeys\Graph\Graph;
use Tooling\Journeys\Index\IndexWriter;
use Tooling\Journeys\Support\Glob;
use Tooling\Journeys\Workspace;

/**
 * Checks a snapshot's catalog against its code. Errors are mandatory
 * failures (broken references, invalid schema, stale generated files);
 * warnings flag gaps that should be addressed but do not block.
 */
final class Validator
{
    /** Warnings promoted to errors in --strict mode (CI, pre-push). */
    public const STRICT_CODES = ['unmapped_route', 'stale_exclusion'];

    /** @var list<Issue> */
    private array $issues = [];

    public function __construct(private readonly Workspace $ws) {}

    /**
     * @return list<Issue>
     */
    public function validate(bool $strict = false): array
    {
        $this->issues = [];
        $catalog = $this->ws->catalog();

        foreach ($catalog->errors as $error) {
            $this->error('schema', $error['message'], null, $error['file']);
        }

        foreach ($catalog->journeys as $journey) {
            $this->journey($journey);
        }

        $this->inventory();
        $this->unresolved();
        $this->generatedFiles();

        if ($strict) {
            $this->issues = array_map(
                fn (Issue $i) => in_array($i->code, self::STRICT_CODES, true) && $i->severity === Issue::WARNING
                    ? new Issue(Issue::ERROR, $i->code, $i->message, $i->journey, $i->file)
                    : $i,
                $this->issues,
            );
        }

        return $this->issues;
    }

    /**
     * Compare statically extracted routes with the booted application's routes.
     *
     * @param  list<array<string, mixed>>  $appRoutes  rows of `route:list --json --except-vendor`
     * @return list<Issue>
     */
    public function compareWithApplication(array $appRoutes): array
    {
        $issues = [];
        $static = [];
        foreach ($this->ws->graph()->routes as $id => $route) {
            $uri = ltrim($route['uri'], '/');
            foreach ($route['methods'] as $method) {
                $static[$method.' '.($uri === '' ? '/' : $uri)] = $id;
            }
        }

        $seen = [];
        foreach ($appRoutes as $row) {
            $uri = ltrim((string) $row['uri'], '/');
            $uri = $uri === '' ? '/' : $uri;
            if (Glob::matchesAny($this->ws->config->routeCheckIgnore, $uri)) {
                continue;
            }
            foreach (explode('|', (string) $row['method']) as $method) {
                $key = $method.' '.$uri;
                $seen[$key] = true;
                if (! isset($static[$key])) {
                    $issues[] = new Issue(Issue::ERROR, 'route_extraction_mismatch', "application route {$key} (".($row['name'] ?? 'unnamed').') was not found by static route analysis');
                } elseif (($row['name'] ?? null) !== null && $static[$key] !== $row['name']) {
                    $issues[] = new Issue(Issue::ERROR, 'route_extraction_mismatch', "route {$key} is named {$row['name']} but static analysis produced {$static[$key]}");
                }
            }
        }
        foreach ($static as $key => $id) {
            if (! isset($seen[$key])) {
                $issues[] = new Issue(Issue::ERROR, 'route_extraction_mismatch', "static analysis found {$key} ({$id}) which the application does not register");
            }
        }

        return $issues;
    }

    private function journey(Journey $journey): void
    {
        $graph = $this->ws->graph();
        $excluded = $this->ws->config->excludedRoutes;

        foreach ($journey->routes as $route) {
            if (! isset($graph->routes[$route])) {
                $this->error('unknown_route', "route '{$route}' is not defined in any route file", $journey);
            } elseif (isset($excluded[$route])) {
                $this->warning('excluded_route_in_journey', "route '{$route}' is listed in config excluded_routes but used by this journey", $journey);
            }
        }

        foreach ($journey->dependsOn as $dep) {
            if ($this->ws->expand($dep['path']) === []) {
                $this->error('unknown_dependency', "depends_on path '{$dep['path']}' does not exist", $journey);
            }
        }

        foreach ($journey->testRefs() as $ref) {
            $this->testRef($journey, TestRef::parse($ref), $ref);
        }

        $this->staleTests($journey);
    }

    private function testRef(Journey $journey, TestRef $ref, string $raw): void
    {
        if (! $this->ws->config->isTestFile($ref->path)) {
            $this->error('broken_test_ref', "'{$raw}' is not inside a configured test root", $journey);

            return;
        }

        $facts = $this->ws->graph()->facts[$ref->path] ?? null;
        if ($facts === null) {
            $this->error('broken_test_ref', "test file '{$ref->path}' does not exist", $journey);

            return;
        }

        if (! empty($facts['error'])) {
            $this->warning('unverifiable_test_ref', "cannot verify '{$raw}': {$facts['error']}", $journey);

            return;
        }

        $tests = $facts['tests'] ?? [];
        $names = $facts['kind'] === 'js' ? $tests : array_column($tests, 'name');

        if ($names === []) {
            $this->error('broken_test_ref', "'{$ref->path}' contains no tests", $journey);

            return;
        }

        if ($ref->name !== null && ! $ref->matchesAny($names)) {
            $this->error('broken_test_ref', "no test named '{$ref->name}' in {$ref->path}", $journey);

            return;
        }

        if ($facts['kind'] === 'php') {
            foreach ($tests as $test) {
                if (($ref->name === null || $ref->matchesAny([$test['name']])) && ! $test['assertions']) {
                    $this->error('test_without_assertions', "test '{$test['name']}' in {$ref->path} makes no assertions", $journey);
                }
            }
        }
    }

    /**
     * A referenced PHP test should reach the journey: request one of its
     * routes or use a non-model class from its dependency graph.
     */
    private function staleTests(Journey $journey): void
    {
        $graph = $this->ws->graph();
        $closure = $this->ws->closure($journey);
        $routeNodes = array_map(fn ($r) => Graph::routeNode($r), $journey->routes);

        foreach ($journey->testFiles() as $path) {
            if (! isset($graph->testEntries[$path])) {
                continue; // not a PHP test (or missing, reported elsewhere)
            }
            $touches = false;
            foreach ($graph->testEntries[$path] as $entry) {
                if (in_array($entry, $routeNodes, true)) {
                    $touches = true;
                    break;
                }
                $file = Graph::pathOf($entry);
                if ($file !== null && isset($closure[$entry]) && ! str_starts_with($file, 'app/Models/') && ! str_starts_with($file, 'database/')) {
                    $touches = true;
                    break;
                }
            }
            if (! $touches) {
                $this->warning('stale_test_mapping', "{$path} does not request any of this journey's routes or use its controllers/services; the mapping may be stale", $journey);
            }
        }
    }

    private function inventory(): void
    {
        $graph = $this->ws->graph();
        $catalog = $this->ws->catalog();
        $config = $this->ws->config;

        $claimed = [];
        $reached = [];
        foreach ($catalog->journeys as $journey) {
            if ($journey->status === 'planned') {
                continue;
            }
            foreach ($journey->routes as $route) {
                $claimed[$route] = true;
            }
            $reached += $this->ws->closure($journey);
        }

        foreach ($graph->routes as $id => $route) {
            if (! isset($claimed[$id]) && ! isset($config->excludedRoutes[$id])) {
                $this->warning('unmapped_route', "route '{$id}' ({$route['file']}:{$route['lines'][0]}) is not part of any journey; add it to a journey or to excluded_routes with a reason", null, $route['file']);
            }
        }

        foreach (array_keys($config->excludedRoutes) as $id) {
            if (! isset($graph->routes[$id])) {
                $this->warning('stale_exclusion', "excluded route '{$id}' no longer exists", null, 'docs/user-journeys/config.yaml');
            }
        }

        foreach (array_keys($graph->facts) as $path) {
            if (str_starts_with($path, $config->pageRoot.'/') && str_ends_with($path, '.vue')
                && ! isset($reached[Graph::fileNode($path)])) {
                // Partials are reached through their parent page; only flag pages
                // that no journey reaches by any path.
                $this->warning('orphan_page', "{$path} is not reachable from any journey (unused, or rendered dynamically)", null, $path);
            }
        }
    }

    private function unresolved(): void
    {
        $catalog = $this->ws->catalog();
        $byItem = [];

        foreach ($this->ws->graph()->unresolved as $item) {
            $isNode = str_starts_with($item['from'], 'route:') || str_starts_with($item['from'], 'method:');
            $fromNode = $isNode ? $item['from'] : Graph::fileNode($item['from']);
            $journeys = [];
            foreach ($catalog->journeys as $journey) {
                if (isset($this->ws->closure($journey)[$fromNode])) {
                    $journeys[] = $journey->id;
                }
            }
            $key = $item['from'].'|'.$item['target'];
            $byItem[$key] ??= [$item + ['file' => $isNode ? null : $item['from']], []];
            $byItem[$key][1] = array_values(array_unique([...$byItem[$key][1], ...$journeys]));
        }

        foreach ($byItem as [$item, $journeys]) {
            $scope = $journeys === [] ? 'not reached by any journey' : 'affects '.implode(', ', $journeys);
            $this->warning('unresolved_dependency', "{$item['from']}: {$item['reason']} ({$item['target']}); {$scope}", null, $item['file']);
        }
    }

    private function generatedFiles(): void
    {
        $snapshot = $this->ws->snapshot;
        $catalog = $this->ws->catalog();

        // A snapshot without a catalog (e.g. before it was introduced) has nothing to check.
        if ($catalog->journeys === [] && $catalog->errors === [] && ! $snapshot->has(Config::PATH)) {
            return;
        }

        $expected = IndexWriter::render($catalog, $this->ws->config);
        if ($snapshot->read(IndexWriter::INDEX_PATH) !== $expected) {
            $this->error('index_out_of_date', IndexWriter::INDEX_PATH.' does not match the journey definitions; run `php bin/journeys index`', null, IndexWriter::INDEX_PATH);
        }

        $raw = $snapshot->read(IndexWriter::BASELINE_PATH);
        if ($raw === null) {
            $this->warning('missing_baseline', IndexWriter::BASELINE_PATH.' is missing; run `php bin/journeys index`');

            return;
        }

        $baseline = (array) json_decode($raw, true);
        foreach (Coverage::regressions($baseline['scenarios'] ?? [], $catalog) as $message) {
            $this->error('coverage_regression', $message.'; if intentional, run `php bin/journeys index --update-baseline`', null, IndexWriter::BASELINE_PATH);
        }
    }

    private function error(string $code, string $message, ?Journey $journey = null, ?string $file = null): void
    {
        $this->issues[] = new Issue(Issue::ERROR, $code, $message, $journey?->id, $file ?? $journey?->file);
    }

    private function warning(string $code, string $message, ?Journey $journey = null, ?string $file = null): void
    {
        $this->issues[] = new Issue(Issue::WARNING, $code, $message, $journey?->id, $file ?? $journey?->file);
    }
}
