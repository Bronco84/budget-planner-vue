<?php

namespace Tooling\Journeys;

use Symfony\Component\Yaml\Yaml;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Support\Glob;

/**
 * docs/user-journeys/config.yaml: analysis roots, shared infrastructure,
 * ignore lists and intentionally unmapped routes.
 */
final class Config
{
    public const PATH = 'docs/user-journeys/config.yaml';

    public const JOURNEY_DIR = 'docs/user-journeys/journeys/';

    /**
     * @param  list<string>  $analysisRoots
     * @param  list<string>  $appNamespaces
     * @param  array<string, string>  $jsAliases
     * @param  list<array{pattern:string, scope:string, reason:string}>  $shared
     * @param  list<string>  $ignore
     * @param  list<array{pattern:string, reason:string}>  $nonJourney
     * @param  array<string, string>  $excludedRoutes
     * @param  array<string, array{prefix:string, name:string}>  $routeFiles  path => group attributes applied by the framework
     * @param  list<string>  $routeCheckIgnore  URI globs registered outside route files (e.g. Nova)
     * @param  list<string>  $testRoots
     */
    public function __construct(
        public readonly array $analysisRoots,
        public readonly array $appNamespaces,
        public readonly string $pageRoot,
        public readonly array $jsAliases,
        public readonly array $shared,
        public readonly array $ignore,
        public readonly array $nonJourney,
        public readonly array $excludedRoutes,
        public readonly array $routeFiles,
        public readonly array $routeCheckIgnore,
        public readonly array $testRoots,
        public readonly string $phpTestCommand,
        public readonly string $e2eTestCommand,
    ) {}

    public static function fromSnapshot(Snapshot $snapshot): self
    {
        $raw = $snapshot->read(self::PATH);

        return self::fromArray($raw === null ? [] : (array) Yaml::parse($raw));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $list = fn (string $key, array $default = []) => array_values((array) ($data[$key] ?? $default));

        return new self(
            analysisRoots: $list('analysis_roots', ['app/', 'bootstrap/', 'config/', 'routes/', 'resources/', 'database/', 'tests/', 'docs/user-journeys/']),
            appNamespaces: $list('app_namespaces', ['App\\', 'Database\\', 'Tests\\']),
            pageRoot: rtrim((string) ($data['page_root'] ?? 'resources/js/Pages'), '/'),
            jsAliases: (array) ($data['js_aliases'] ?? ['@/' => 'resources/js/']),
            shared: array_map(fn ($s) => [
                'pattern' => (string) $s['pattern'],
                'scope' => (string) ($s['scope'] ?? 'all'),
                'reason' => (string) ($s['reason'] ?? ''),
            ], $list('shared_infrastructure')),
            ignore: $list('ignore'),
            nonJourney: array_map(fn ($s) => ['pattern' => (string) $s['pattern'], 'reason' => (string) ($s['reason'] ?? '')], $list('non_journey')),
            excludedRoutes: array_map('strval', (array) ($data['excluded_routes'] ?? [])),
            routeFiles: self::routeFiles($list('route_files', ['routes/web.php', 'routes/auth.php', 'routes/api.php'])),
            routeCheckIgnore: $list('route_check_ignore'),
            testRoots: $list('test_roots', ['tests/']),
            phpTestCommand: (string) ($data['commands']['php_tests'] ?? 'php artisan test'),
            e2eTestCommand: (string) ($data['commands']['e2e_tests'] ?? 'npx playwright test'),
        );
    }

    /**
     * @param  list<string|array<string, string>>  $entries
     * @return array<string, array{prefix:string, name:string}>
     */
    private static function routeFiles(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $entry = is_string($entry) ? ['path' => $entry] : $entry;
            $out[(string) $entry['path']] = ['prefix' => (string) ($entry['prefix'] ?? ''), 'name' => (string) ($entry['name'] ?? '')];
        }

        return $out;
    }

    public function inAnalysisRoots(string $path): bool
    {
        foreach ($this->analysisRoots as $root) {
            if ($path === $root || (str_ends_with($root, '/') && str_starts_with($path, $root)) || (Glob::isPattern($root) && Glob::matches($root, $path))) {
                return true;
            }
        }

        return false;
    }

    public function isIgnored(string $path): bool
    {
        return Glob::matchesAny($this->ignore, $path);
    }

    /**
     * @return list<array{pattern:string, scope:string, reason:string}>
     */
    public function sharedMatches(string $path): array
    {
        return array_values(array_filter($this->shared, fn ($s) => Glob::matches($s['pattern'], $path)));
    }

    /**
     * @return array{pattern:string, reason:string}|null
     */
    public function nonJourneyMatch(string $path): ?array
    {
        foreach ($this->nonJourney as $entry) {
            if (Glob::matches($entry['pattern'], $path)) {
                return $entry;
            }
        }

        return null;
    }

    public function isTestFile(string $path): bool
    {
        foreach ($this->testRoots as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    public function isRouteFile(string $path): bool
    {
        return isset($this->routeFiles[$path]);
    }

    public function isAppClass(string $fqcn): bool
    {
        foreach ($this->appNamespaces as $ns) {
            if (str_starts_with($fqcn, $ns)) {
                return true;
            }
        }

        return false;
    }
}
