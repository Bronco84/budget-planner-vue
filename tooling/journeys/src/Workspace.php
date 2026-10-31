<?php

namespace Tooling\Journeys;

use Symfony\Component\Yaml\Yaml;
use Tooling\Journeys\Analysis\FactStore;
use Tooling\Journeys\Catalog\Catalog;
use Tooling\Journeys\Catalog\Journey;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Graph\Graph;
use Tooling\Journeys\Graph\GraphBuilder;
use Tooling\Journeys\Support\Glob;

/**
 * Everything known about one snapshot: its config, catalog and graph.
 */
final class Workspace
{
    private ?Graph $graph = null;

    private ?Catalog $catalog = null;

    /** @var array<string, array<string, string>> */
    private array $closures = [];

    private function __construct(
        public readonly Git $git,
        public readonly Snapshot $snapshot,
        public readonly Config $config,
        private readonly FactStore $store,
    ) {}

    /**
     * @param  string  $kind  Snapshot::COMMIT|INDEX|WORKTREE|EMPTY
     */
    public static function open(Git $git, string $kind, ?string $ref = null, ?FactStore $store = null): self
    {
        $config = Config::fromArray(self::readConfig($git, $kind, $ref));
        $filter = fn (string $path) => $config->inAnalysisRoots($path);

        $snapshot = match ($kind) {
            Snapshot::COMMIT => Snapshot::commit($git, (string) $ref, $filter),
            Snapshot::INDEX => Snapshot::index($git, $filter),
            Snapshot::WORKTREE => Snapshot::worktree($git, $filter),
            default => Snapshot::empty($git),
        };

        return new self($git, $snapshot, $config, $store ?? self::defaultStore($git));
    }

    public static function defaultStore(Git $git): FactStore
    {
        $cache = getenv('JOURNEYS_CACHE_DIR') ?: $git->root().'/storage/framework/cache/journeys';

        return new FactStore($git->root(), getenv('JOURNEYS_NO_CACHE') ? null : $cache);
    }

    public function graph(): Graph
    {
        return $this->graph ??= (new GraphBuilder($this->store))->build($this->snapshot, $this->config);
    }

    public function catalog(): Catalog
    {
        return $this->catalog ??= Catalog::load($this->snapshot);
    }

    /**
     * Root nodes for a journey: its routes plus declared extra dependencies.
     *
     * @return list<string>
     */
    public function roots(Journey $journey): array
    {
        $roots = array_map(fn ($route) => Graph::routeNode($route), $journey->routes);

        foreach ($journey->dependsOn as $dep) {
            foreach ($this->expand($dep['path']) as $path) {
                $roots[] = Graph::fileNode($path);
            }
        }

        $roots[] = Graph::fileNode($journey->file);

        return array_values(array_unique($roots));
    }

    /**
     * @return array<string, string> node => parent
     */
    public function closure(Journey $journey): array
    {
        return $this->closures[$journey->id] ??= $this->graph()->closure($this->roots($journey));
    }

    /**
     * Transitive closure of the nodes a test file reaches (classes, routes, URIs).
     *
     * @return array<string, string>
     */
    public function testClosure(string $path): array
    {
        $entries = $this->graph()->testEntries[$path] ?? [];

        return $this->closures['test:'.$path] ??= $this->graph()->closure([Graph::fileNode($path), ...$entries]);
    }

    /**
     * Expand a path or glob against the snapshot.
     *
     * @return list<string>
     */
    public function expand(string $pattern): array
    {
        if (! Glob::isPattern($pattern)) {
            return $this->snapshot->has($pattern) ? [$pattern] : [];
        }

        return array_values(array_filter(array_keys($this->snapshot->files()), fn ($path) => Glob::matches($pattern, $path)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function readConfig(Git $git, string $kind, ?string $ref): array
    {
        $raw = match ($kind) {
            Snapshot::WORKTREE => is_file($git->root().'/'.Config::PATH) ? (string) file_get_contents($git->root().'/'.Config::PATH) : null,
            Snapshot::INDEX => $git->exec(['show', ':'.Config::PATH])[0] === 0 ? $git->run(['show', ':'.Config::PATH]) : null,
            Snapshot::COMMIT => $git->exec(['show', $ref.':'.Config::PATH])[0] === 0 ? $git->run(['show', $ref.':'.Config::PATH]) : null,
            default => null,
        };

        return $raw === null ? [] : (array) Yaml::parse($raw);
    }
}
