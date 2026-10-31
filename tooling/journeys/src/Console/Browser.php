<?php

namespace Tooling\Journeys\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Tooling\Journeys\Catalog\Journey;
use Tooling\Journeys\Coverage\Coverage;
use Tooling\Journeys\Graph\Graph;
use Tooling\Journeys\Workspace;

/**
 * `journeys show` / `journeys find`: forward and reverse lookups.
 */
final class Browser
{
    private const GROUPS = [
        'Pages' => 'resources/js/Pages/',
        'Components & composables' => 'resources/js/',
        'Controllers' => 'app/Http/Controllers/',
        'Requests & middleware' => 'app/Http/',
        'Services' => 'app/Services/',
        'Models' => 'app/Models/',
        'Policies' => 'app/Policies/',
        'Mail & views' => 'app/Mail/',
        'Migrations' => 'database/migrations/',
        'Blade views' => 'resources/views/',
    ];

    public function __construct(private readonly Workspace $ws, private readonly OutputInterface $out) {}

    public function show(string $query, bool $verbose): int
    {
        $graph = $this->ws->graph();

        if (($journey = $this->ws->catalog()->get($query)) !== null) {
            $this->journey($journey, $verbose);

            return 0;
        }

        if (isset($graph->routes[$query])) {
            $this->node(Graph::routeNode($query), "Route {$query}");

            return 0;
        }

        $path = ltrim($query, './');
        if (! isset($graph->facts[$path])) {
            $path = $graph->classes[ltrim($query, '\\')] ?? null;
            if ($path === null) {
                $matches = array_filter($graph->classes, fn ($file, $class) => class_basename($class) === $query, ARRAY_FILTER_USE_BOTH);
                $path = count($matches) === 1 ? reset($matches) : null;
            }
        }

        if ($path !== null && isset($graph->facts[$path])) {
            $this->node(Graph::fileNode($path), $path);

            return 0;
        }

        $this->out->writeln("No journey, route, file or class matches '{$query}'. Searching instead:");

        return $this->find($query);
    }

    public function find(string $term): int
    {
        $needle = strtolower($term);
        $found = 0;

        foreach ($this->ws->catalog()->journeys as $journey) {
            $why = [];
            foreach (['id' => $journey->id, 'name' => $journey->name, 'summary' => $journey->summary, 'domain' => $journey->domain] as $field => $value) {
                if (str_contains(strtolower($value), $needle)) {
                    $why[] = $field;
                }
            }
            foreach ($journey->routes as $route) {
                if (str_contains(strtolower($route), $needle)) {
                    $why[] = "route {$route}";
                }
            }
            foreach (array_keys($this->ws->closure($journey)) as $node) {
                $path = Graph::pathOf($node);
                if ($path !== null && str_contains(strtolower($path), $needle)) {
                    $why[] = "depends on {$path}";
                    if (count($why) > 4) {
                        break;
                    }
                }
            }
            if ($why !== []) {
                $found++;
                $this->out->writeln(sprintf('%-12s %-9s %s', $journey->id, $journey->priority, $journey->name));
                $this->out->writeln('             <fg=gray>'.implode('; ', array_slice(array_unique($why), 0, 4)).'</>');
            }
        }

        if ($found === 0) {
            $this->out->writeln('No journeys found.');
        }

        return $found === 0 ? 1 : 0;
    }

    private function journey(Journey $journey, bool $verbose): void
    {
        $o = $this->out;
        $graph = $this->ws->graph();
        $coverage = Coverage::forJourney($journey);

        $o->writeln("<info>{$journey->id}</info> {$journey->name}");
        $o->writeln("  {$journey->status} · {$journey->priority} · domain {$journey->domain} · actors: ".implode(', ', $journey->actors));
        $o->writeln("  {$journey->file}");
        $o->writeln('');
        $o->writeln(wordwrap('  '.$journey->summary, 100, "\n  "));
        $o->writeln('');

        $o->writeln('<comment>Routes</comment>');
        foreach ($journey->routes as $id) {
            $route = $graph->routes[$id] ?? null;
            $action = $route === null ? '<fg=red>missing</>' : match ($route['action']['type']) {
                'controller' => class_basename($route['action']['class']).'@'.$route['action']['method'],
                default => $route['action']['type'],
            };
            $o->writeln(sprintf('  %-45s %-28s %s', $id, $route === null ? '' : implode('|', array_diff($route['methods'], ['HEAD'])).' /'.ltrim($route['uri'], '/'), $action));
        }
        $o->writeln('');

        $closure = $this->ws->closure($journey);
        $files = array_filter(array_map(fn ($n) => Graph::pathOf($n), array_keys($closure)));
        $o->writeln('<comment>Derived dependencies</comment> ('.count($files).' files)');
        $grouped = [];
        foreach ($files as $file) {
            $group = 'Other';
            foreach (self::GROUPS as $label => $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $group = $label;
                    break;
                }
            }
            $grouped[$group][] = $file;
        }
        foreach ([...array_keys(self::GROUPS), 'Other'] as $group) {
            if (! isset($grouped[$group])) {
                continue;
            }
            sort($grouped[$group]);
            $list = $verbose ? $grouped[$group] : array_slice($grouped[$group], 0, 8);
            $o->writeln("  {$group}: ".implode(', ', array_map(fn ($f) => basename($f), $list)).(count($grouped[$group]) > count($list) ? ' (+'.(count($grouped[$group]) - count($list)).')' : ''));
        }
        $o->writeln('');

        $o->writeln('<comment>Scenarios</comment>');
        foreach ($journey->scenarios as $scenario) {
            $mark = ['covered' => '<info>✓</info>', 'partial' => '<fg=yellow>~</>', 'uncovered' => '<fg=red>✗</>', 'not_applicable' => '<fg=gray>-</>'][$scenario->coverage] ?? '?';
            $o->writeln("  {$mark} {$scenario->id} <fg=gray>({$scenario->kind})</> {$scenario->description}");
            foreach ($scenario->tests as $test) {
                $o->writeln("      <fg=gray>{$test}</>");
            }
            if ($scenario->note !== '' && $scenario->coverage !== 'covered') {
                $o->writeln("      <fg=gray>note: {$scenario->note}</>");
            }
        }
        $o->writeln('');
        $o->writeln("Coverage: happy {$coverage['happy']}, negative {$coverage['negative']}, authz {$coverage['authorization']}, alternate {$coverage['alternate']}, integration {$coverage['integration']}, e2e {$coverage['e2e']} → <options=bold>{$coverage['status']}</>");

        $unresolved = array_filter($graph->unresolved, function ($item) use ($closure) {
            $node = str_starts_with($item['from'], 'route:') || str_starts_with($item['from'], 'method:') ? $item['from'] : Graph::fileNode($item['from']);

            return isset($closure[$node]);
        });
        if ($unresolved !== []) {
            $o->writeln('');
            $o->writeln('<fg=yellow>Unresolved dependencies in this journey</>');
            foreach ($unresolved as $item) {
                $o->writeln("  {$item['from']}: {$item['reason']} ({$item['target']})");
            }
        }
    }

    private function node(string $node, string $title): void
    {
        $o = $this->out;
        $graph = $this->ws->graph();
        $path = Graph::pathOf($node);
        $config = $this->ws->config;

        $o->writeln("<info>{$title}</info>");
        if ($path !== null) {
            if (($shared = $config->sharedMatches($path)) !== []) {
                $o->writeln("  shared infrastructure ({$shared[0]['scope']}): {$shared[0]['reason']}");
            }
            if (($nj = $config->nonJourneyMatch($path)) !== null) {
                $o->writeln("  not part of a user journey: {$nj['reason']}");
            }
        } elseif (isset($graph->routes[substr($node, 6)])) {
            $route = $graph->routes[substr($node, 6)];
            $o->writeln('  '.implode('|', $route['methods']).' /'.ltrim($route['uri'], '/')."  ({$route['file']}:{$route['lines'][0]})");
            $o->writeln('  middleware: '.(implode(', ', $route['middleware']) ?: 'none'));
        }

        $o->writeln('');
        $o->writeln('<comment>Journeys that depend on it</comment>');
        $any = false;
        foreach ($this->ws->catalog()->journeys as $journey) {
            $closure = $this->ws->closure($journey);
            if (isset($closure[$node])) {
                $any = true;
                $chain = array_map(fn ($n) => Graph::pathOf($n) ?? $n, Graph::pathTo($closure, $node));
                $o->writeln(sprintf('  %-12s %s', $journey->id, $journey->name));
                $o->writeln('               <fg=gray>'.implode(' → ', $chain).'</>');
            }
        }
        if (! $any) {
            $o->writeln('  (none)');
        }

        $o->writeln('');
        $o->writeln('<comment>Tests that reach it</comment>');
        $tests = [];
        foreach (array_keys($graph->testEntries) as $test) {
            if (isset($this->ws->testClosure($test)[$node])) {
                $tests[] = $test;
            }
        }
        $o->writeln($tests === [] ? '  (none)' : '  '.implode("\n  ", $tests));

        $o->writeln('');
        $deps = array_keys($graph->dependencies($node));
        $o->writeln('<comment>Direct dependencies</comment> ('.count($deps).')');
        foreach ($deps as $dep) {
            $o->writeln('  '.(Graph::pathOf($dep) ?? $dep).'  <fg=gray>['.$graph->dependencies($node)[$dep].']</>');
        }
        $dependants = $graph->dependants($node);
        $o->writeln('<comment>Direct dependants</comment> ('.count($dependants).')');
        foreach ($dependants as $dependant) {
            $o->writeln('  '.(Graph::pathOf($dependant) ?? $dependant));
        }
    }
}
