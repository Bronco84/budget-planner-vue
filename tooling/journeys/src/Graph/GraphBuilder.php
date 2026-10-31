<?php

namespace Tooling\Journeys\Graph;

use Illuminate\Support\Str;
use Tooling\Journeys\Analysis\FactStore;
use Tooling\Journeys\Config;
use Tooling\Journeys\Git\Snapshot;

/**
 * Assembles the dependency graph for a snapshot from per-file facts.
 *
 * Controllers are modelled per action (method node) so a route only pulls in
 * the pages, services and requests its own action uses. Other PHP classes are
 * modelled per file. Unresolvable references are recorded, never dropped.
 */
final class GraphBuilder
{
    private const CONTROLLER_DIR = 'app/Http/Controllers/';

    private const MODEL_DIR = 'app/Models/';

    public function __construct(private readonly FactStore $facts) {}

    public function build(Snapshot $snapshot, Config $config): Graph
    {
        $graph = new Graph;
        $graph->facts = $this->facts->factsFor($snapshot, $config);

        foreach ($graph->facts as $path => $facts) {
            $graph->addNode(Graph::fileNode($path));
            foreach ($facts['classes'] ?? [] as $class) {
                $graph->classes[$class] = $path;
            }
            if (! empty($facts['error'])) {
                $graph->unresolved[] = ['from' => $path, 'kind' => 'analysis', 'target' => $path, 'reason' => (string) $facts['error']];
            }
        }

        $tables = $this->migrationTables($graph);

        foreach ($graph->facts as $path => $facts) {
            match ($facts['kind']) {
                'php' => $this->php($graph, $config, $path, $facts, $tables),
                'js' => $this->js($graph, $config, $snapshot, $path, $facts),
                default => null,
            };
        }

        $this->testUris($graph);

        return $graph;
    }

    /**
     * Resolve literal URIs requested by tests ($this->get('/profile')) to routes.
     */
    private function testUris(Graph $graph): void
    {
        $patterns = [];
        foreach ($graph->routes as $id => $route) {
            $segments = array_map(
                fn ($segment) => preg_match('/^\{.+\}$/', $segment) ? '[^/]+' : preg_quote($segment, '#'),
                explode('/', ltrim($route['uri'], '/')),
            );
            $regex = implode('/', $segments);
            $patterns[$id] = ['#^'.$regex.'$#', $route['methods']];
        }

        foreach ($graph->facts as $path => $facts) {
            if (! isset($graph->testEntries[$path])) {
                continue;
            }
            foreach ($facts['uri_refs'] ?? [] as $ref) {
                [$verb, $uri] = explode(' ', $ref, 2);
                $uri = ltrim((string) parse_url($uri, PHP_URL_PATH), '/');
                foreach ($patterns as $id => [$regex, $methods]) {
                    if (preg_match($regex, $uri) && ($verb === 'ANY' || in_array($verb, $methods, true) || in_array('ANY', $methods, true))) {
                        $graph->testEntries[$path][] = Graph::routeNode($id);
                    }
                }
            }
            $graph->testEntries[$path] = array_values(array_unique($graph->testEntries[$path]));
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, list<string>>  $tables
     */
    private function php(Graph $graph, Config $config, string $path, array $facts, array $tables): void
    {
        if (! empty($facts['error'])) {
            return;
        }

        $node = Graph::fileNode($path);

        if (isset($facts['routes'])) {
            $this->routes($graph, $config, $path, $facts);

            return;
        }

        if ($config->isTestFile($path)) {
            $entries = [];
            foreach ($facts['refs'] as $ref) {
                if (($target = $this->classFile($graph, $config, $path, $ref)) !== null) {
                    $entries[] = $target;
                }
            }
            foreach ($facts['route_refs'] as $route) {
                $entries[] = Graph::routeNode($route);
            }
            $graph->testEntries[$path] = array_values(array_unique($entries));

            return;
        }

        $isController = str_starts_with($path, self::CONTROLLER_DIR);
        $fileRefs = $isController ? $facts['class_refs'] : $facts['refs'];

        $isModel = str_starts_with($path, self::MODEL_DIR);
        foreach ($fileRefs as $ref) {
            if (($target = $this->classFile($graph, $config, $path, $ref)) !== null) {
                // Model-to-model references are (almost always) relationships.
                $relation = $isModel && str_starts_with((string) Graph::pathOf($target), self::MODEL_DIR);
                $graph->addEdge($node, $target, $relation ? 'relation' : 'ast');
            }
        }

        if ($isController) {
            foreach ($facts['classes'] as $class) {
                foreach ($facts['methods'] as $method => $bucket) {
                    $methodNode = Graph::methodNode($class, $method);
                    $graph->addEdge($methodNode, $node);
                    foreach ($bucket['refs'] as $ref) {
                        if (($target = $this->classFile($graph, $config, $path, $ref)) !== null) {
                            $graph->addEdge($methodNode, $target);
                        }
                    }
                    foreach ($bucket['calls'] as $called) {
                        if (isset($facts['methods'][$called])) {
                            $graph->addEdge($methodNode, Graph::methodNode($class, $called));
                        }
                    }
                    $this->renders($graph, $config, $path, $methodNode, $bucket['renders'], $bucket['views']);
                }
            }
        } else {
            $this->renders($graph, $config, $path, $node, $facts['renders'], $facts['views']);
        }

        foreach ($facts['unresolved_renders'] as $line) {
            $graph->unresolved[] = ['from' => $path, 'kind' => 'page', 'target' => 'line '.$line, 'reason' => 'Inertia render with non-literal component name'];
        }

        if ($facts['route_refs'] !== []) {
            $graph->routeLinks[$path] = $facts['route_refs'];
        }

        if ($isModel) {
            $this->modelConventions($graph, $facts, $node, $tables);
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function routes(Graph $graph, Config $config, string $path, array $facts): void
    {
        $graph->routeGroups[$path] = $facts['groups'];

        foreach ($facts['unresolved'] as $item) {
            $graph->unresolved[] = ['from' => $path, 'kind' => 'route', 'target' => 'line '.$item['line'], 'reason' => $item['reason']];
        }

        foreach ($facts['routes'] as $route) {
            $graph->routes[$route['id']] = $route;
            $node = Graph::routeNode($route['id']);
            $graph->addNode($node);
            $action = $route['action'];

            if ($action['type'] === 'controller') {
                $file = $graph->classes[$action['class']] ?? null;
                if ($file === null) {
                    $graph->unresolved[] = ['from' => $node, 'kind' => 'class', 'target' => $action['class'], 'reason' => 'route controller not found'];

                    continue;
                }
                $methods = $graph->facts[$file]['methods'] ?? [];
                $method = $action['method'];
                if (! isset($methods[$method])) {
                    $graph->unresolved[] = ['from' => $node, 'kind' => 'method', 'target' => $action['class'].'::'.$method, 'reason' => 'route action method not found on controller'];
                    $graph->addEdge($node, Graph::fileNode($file));

                    continue;
                }
                $graph->addEdge($node, Graph::methodNode($action['class'], $method));
            } elseif ($action['type'] === 'closure') {
                $closure = $action['closure'];
                foreach ($closure['refs'] as $ref) {
                    if (($target = $this->classFile($graph, $config, $path, $ref)) !== null) {
                        $graph->addEdge($node, $target);
                    }
                }
                $this->renders($graph, $config, $path, $node, $closure['renders'], $closure['views']);
                if ($closure['route_refs'] !== []) {
                    $graph->routeLinks[$node] = $closure['route_refs'];
                }
            } elseif ($action['type'] === 'view') {
                $this->renders($graph, $config, $path, $node, [], [$action['target']]);
            } elseif ($action['type'] === 'unresolved') {
                $graph->unresolved[] = ['from' => $node, 'kind' => 'route', 'target' => $route['id'], 'reason' => 'route action could not be resolved statically'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function js(Graph $graph, Config $config, Snapshot $snapshot, string $path, array $facts): void
    {
        if (! empty($facts['error'])) {
            return;
        }

        $node = Graph::fileNode($path);

        foreach ([...$facts['imports'], ...$facts['dynamic_imports']] as $specifier) {
            $resolved = $this->resolveImport($config, $snapshot, $path, $specifier);
            if ($resolved === false) {
                continue; // package import
            }
            if ($resolved === null) {
                $graph->unresolved[] = ['from' => $path, 'kind' => 'import', 'target' => $specifier, 'reason' => 'import does not resolve to a file in the snapshot'];

                continue;
            }
            $graph->addEdge($node, Graph::fileNode($resolved));
        }

        foreach ($facts['unresolved'] as $reason) {
            $graph->unresolved[] = ['from' => $path, 'kind' => 'import', 'target' => '?', 'reason' => $reason];
        }

        if ($facts['route_refs'] !== []) {
            $graph->routeLinks[$path] = $facts['route_refs'];
        }
    }

    /**
     * @return string|false|null resolved path, false for packages, null when unresolved
     */
    private function resolveImport(Config $config, Snapshot $snapshot, string $from, string $specifier): string|false|null
    {
        $specifier = (string) preg_replace('/[?#].*$/', '', $specifier);
        if (preg_match('/\.(css|scss|sass|less|svg|png|jpe?g|gif|webp|woff2?|json)$/i', $specifier)) {
            return false; // assets are handled by Vite, not part of the module graph
        }
        $base = null;
        foreach ($config->jsAliases as $alias => $target) {
            if (str_starts_with($specifier, $alias)) {
                $base = $target.substr($specifier, strlen($alias));
                break;
            }
        }
        if ($base === null) {
            if (! str_starts_with($specifier, '.') && ! str_starts_with($specifier, '/')) {
                return false;
            }
            $base = dirname($from).'/'.$specifier;
        }

        $normalized = self::normalize($base);
        if ($normalized === null || str_starts_with($normalized, 'vendor/') || str_starts_with($normalized, 'node_modules/')) {
            return false;
        }

        foreach (['', '.vue', '.js', '.mjs', '.ts', '/index.js', '/index.ts'] as $suffix) {
            if ($snapshot->has($normalized.$suffix)) {
                return $normalized.$suffix;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $pages
     * @param  list<string>  $views
     */
    private function renders(Graph $graph, Config $config, string $path, string $from, array $pages, array $views): void
    {
        foreach ($pages as $page) {
            $file = $config->pageRoot.'/'.$page.'.vue';
            if (isset($graph->facts[$file])) {
                $graph->addEdge($from, Graph::fileNode($file));
            } else {
                $graph->unresolved[] = ['from' => $from, 'kind' => 'page', 'target' => $page, 'reason' => 'Inertia page component does not exist: '.$file];
            }
        }

        foreach ($views as $view) {
            if (str_contains($view, '::')) {
                continue; // package view namespace
            }
            $file = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            if (isset($graph->facts[$file])) {
                $graph->addEdge($from, Graph::fileNode($file));
            } else {
                $graph->unresolved[] = ['from' => $from, 'kind' => 'view', 'target' => $view, 'reason' => 'Blade view does not exist: '.$file];
            }
        }
    }

    private function classFile(Graph $graph, Config $config, string $from, string $fqcn): ?string
    {
        if (isset($graph->classes[$fqcn])) {
            return Graph::fileNode($graph->classes[$fqcn]);
        }

        if ($config->isAppClass($fqcn)) {
            $graph->unresolved[] = ['from' => $from, 'kind' => 'class', 'target' => $fqcn, 'reason' => 'application class not found in snapshot'];
        }

        return null;
    }

    /**
     * Eloquent conventions that static references don't capture: a model
     * depends on its policy and on the migrations that shape its table.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, list<string>>  $tables
     */
    private function modelConventions(Graph $graph, array $facts, string $node, array $tables): void
    {
        foreach ($facts['classes'] as $class) {
            $base = class_basename($class);
            $policy = 'App\\Policies\\'.$base.'Policy';
            if (isset($graph->classes[$policy])) {
                $graph->addEdge($node, Graph::fileNode($graph->classes[$policy]), 'convention');
            }

            $table = $facts['table'] ?? Str::snake(Str::pluralStudly($base));
            foreach ($tables[$table] ?? [] as $migration) {
                $graph->addEdge($node, Graph::fileNode($migration), 'convention');
            }
        }
    }

    /**
     * @return array<string, list<string>> table => migration paths
     */
    private function migrationTables(Graph $graph): array
    {
        $tables = [];
        foreach ($graph->facts as $path => $facts) {
            if (str_starts_with($path, 'database/migrations/')) {
                foreach ($facts['migration_tables'] ?? [] as $table) {
                    $tables[$table][] = $path;
                }
            }
        }

        return $tables;
    }

    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
