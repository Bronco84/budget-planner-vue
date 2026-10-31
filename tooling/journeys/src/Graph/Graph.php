<?php

namespace Tooling\Journeys\Graph;

/**
 * Directed dependency graph. Node ids are prefixed by type:
 *   file:<path>  route:<name|METHOD /uri>  method:<Class>::<method>
 * Edges point from a dependant to its dependency and carry an evidence label
 * (ast, convention, declared, lexical) describing how they were inferred.
 */
final class Graph
{
    /** @var array<string, array<string, string>> from => [to => evidence] */
    private array $edges = [];

    /** @var array<string, array<string, true>>|null */
    private ?array $reverse = null;

    /** @var array<string, true> */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> route id => route record */
    public array $routes = [];

    /** @var array<string, string> FQCN => path */
    public array $classes = [];

    /** @var array<string, list<string>> path => route ids referenced (soft links: url()/redirect) */
    public array $routeLinks = [];

    /** @var array<string, list<string>> test path => starting nodes for that test's closure */
    public array $testEntries = [];

    /** @var array<string, array<string, mixed>> path => facts */
    public array $facts = [];

    /** @var list<array{from:string, kind:string, target:string, reason:string}> */
    public array $unresolved = [];

    /** @var array<string, list<array{lines: array{int,int}, routes: list<string>}>> route file => groups */
    public array $routeGroups = [];

    public function addNode(string $node): void
    {
        $this->nodes[$node] = true;
    }

    public function addEdge(string $from, string $to, string $evidence = 'ast'): void
    {
        if ($from === $to) {
            return;
        }
        $this->nodes[$from] = true;
        $this->nodes[$to] = true;
        $this->edges[$from][$to] ??= $evidence;
        $this->reverse = null;
    }

    public function hasNode(string $node): bool
    {
        return isset($this->nodes[$node]);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * @return array<string, string> to => evidence
     */
    public function dependencies(string $node): array
    {
        return $this->edges[$node] ?? [];
    }

    /**
     * @return list<string>
     */
    public function dependants(string $node): array
    {
        if ($this->reverse === null) {
            $this->reverse = [];
            foreach ($this->edges as $from => $tos) {
                foreach ($tos as $to => $_) {
                    $this->reverse[$to][$from] = true;
                }
            }
        }

        return array_keys($this->reverse[$node] ?? []);
    }

    public function edgeCount(): int
    {
        return array_sum(array_map('count', $this->edges));
    }

    /**
     * Breadth-first transitive dependencies; maps each reached node to the
     * node it was reached from (roots map to '') so paths can be explained.
     * Roots deliberately map to a string so isset() works on every member.
     *
     * `relation` edges (model -> related model) are followed one hop: a model
     * reached through a relation contributes its convention edges (policy,
     * migrations) but not its own relations or service calls. Without this,
     * every journey touching User would transitively include the whole domain.
     *
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    public function closure(array $roots): array
    {
        $parents = [];
        $full = [];
        $queue = [];
        foreach ($roots as $root) {
            if (! isset($full[$root])) {
                $parents[$root] = '';
                $full[$root] = true;
                $queue[] = $root;
            }
        }

        for ($i = 0; $i < count($queue); $i++) {
            $from = $queue[$i];
            $fromFull = $full[$from];
            foreach ($this->edges[$from] ?? [] as $to => $evidence) {
                if (! $fromFull && $evidence !== 'convention') {
                    continue;
                }
                $toFull = $fromFull && $evidence !== 'relation';
                if (! array_key_exists($to, $parents)) {
                    $parents[$to] = $from;
                    $full[$to] = $toFull;
                    $queue[] = $to;
                } elseif ($toFull && ! $full[$to]) {
                    // Upgrade: now reached by a strong edge, so expand fully.
                    $parents[$to] = $from;
                    $full[$to] = true;
                    $queue[] = $to;
                }
            }
        }

        return $parents;
    }

    /**
     * Transitive dependants (reverse closure) of the given nodes.
     *
     * @param  list<string>  $nodes
     * @return array<string, true>
     */
    public function dependantsClosure(array $nodes): array
    {
        $seen = array_fill_keys($nodes, true);
        $queue = $nodes;
        while ($queue !== []) {
            foreach ($this->dependants(array_pop($queue)) as $from) {
                if (! isset($seen[$from])) {
                    $seen[$from] = true;
                    $queue[] = $from;
                }
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, string>  $parents  from closure()
     * @return list<string> root ... node
     */
    public static function pathTo(array $parents, string $node): array
    {
        $path = [];
        for ($cursor = $node; $cursor !== ''; $cursor = $parents[$cursor] ?? '') {
            array_unshift($path, $cursor);
            if (count($path) > 64) {
                break;
            }
        }

        return $path;
    }

    public static function fileNode(string $path): string
    {
        return 'file:'.$path;
    }

    public static function routeNode(string $id): string
    {
        return 'route:'.$id;
    }

    public static function methodNode(string $class, string $method): string
    {
        return 'method:'.$class.'::'.$method;
    }

    public static function pathOf(string $node): ?string
    {
        return str_starts_with($node, 'file:') ? substr($node, 5) : null;
    }
}
