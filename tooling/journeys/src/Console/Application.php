<?php

namespace Tooling\Journeys\Console;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tooling\Journeys\Coverage\Coverage;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Graph\Graph;
use Tooling\Journeys\Hooks\Hooks;
use Tooling\Journeys\Impact\ChangeSet;
use Tooling\Journeys\Impact\ImpactAnalyzer;
use Tooling\Journeys\Index\IndexWriter;
use Tooling\Journeys\Validation\Issue;
use Tooling\Journeys\Validation\Validator;
use Tooling\Journeys\Workspace;

final class Application extends ConsoleApplication
{
    public function __construct(private readonly Git $git)
    {
        parent::__construct('journeys', '1');

        $this->addCommands([
            $this->command('validate', 'Validate journey definitions, references and generated files', function (InputInterface $in, OutputInterface $out) {
                $ws = $this->workspace($in);
                $validator = new Validator($ws);
                $issues = $validator->validate((bool) $in->getOption('strict'));
                if ($in->getOption('with-app')) {
                    $issues = [...$issues, ...$validator->compareWithApplication($this->appRoutes())];
                }
                if ($in->getOption('json')) {
                    $out->writeln((string) json_encode(array_map(fn (Issue $i) => $i->toArray(), $issues), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $out->writeln('Validating '.$ws->snapshot->label().' ('.count($ws->catalog()->journeys).' journeys)');
                    (new Printer($out))->issues($issues, ! $in->getOption('errors-only'));
                }

                return self::hasErrors($issues) ? 1 : 0;
            }, [
                new InputOption('staged', null, InputOption::VALUE_NONE, 'Validate the staged index instead of the working tree'),
                new InputOption('ref', null, InputOption::VALUE_REQUIRED, 'Validate a commit'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Treat inventory warnings (unmapped routes) as errors'),
                new InputOption('with-app', null, InputOption::VALUE_NONE, 'Cross-check static routes against `php artisan route:list`'),
                new InputOption('errors-only', null, InputOption::VALUE_NONE, 'Hide warnings'),
                new InputOption('json', null, InputOption::VALUE_NONE),
            ]),

            $this->command('coverage', 'Scenario-level coverage report', function (InputInterface $in, OutputInterface $out) {
                $ws = $this->workspace($in);
                $coverage = Coverage::forCatalog($ws->catalog());
                if ($in->getOption('json')) {
                    $out->writeln((string) json_encode($coverage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return 0;
                }
                $filter = $in->getOption('status');
                $out->writeln(sprintf('%-12s %-9s %-8s %-8s %-8s %-9s %-11s %-4s %s', 'Journey', 'Priority', 'Happy', 'Negative', 'Authz', 'Alternate', 'Integration', 'E2E', 'Status'));
                foreach ($coverage as $id => $c) {
                    if ($filter !== null && $c['status'] !== $filter) {
                        continue;
                    }
                    $out->writeln(sprintf('%-12s %-9s %-8s %-8s %-8s %-9s %-11s %-4s %s', $id, $ws->catalog()->journeys[$id]->priority, $c['happy'], $c['negative'], $c['authorization'], $c['alternate'], $c['integration'], $c['e2e'], $c['status']));
                    if ($in->getOption('gaps')) {
                        foreach ($c['gaps'] as $gap) {
                            $out->writeln("               <fg=gray>- {$gap}</>");
                        }
                    }
                }
                $counts = array_count_values(array_column($coverage, 'status'));
                ksort($counts);
                $out->writeln('');
                $out->writeln(implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($counts), $counts)));

                return 0;
            }, [
                new InputOption('staged', null, InputOption::VALUE_NONE),
                new InputOption('ref', null, InputOption::VALUE_REQUIRED),
                new InputOption('status', null, InputOption::VALUE_REQUIRED, 'Only show journeys with this status (covered, partial, at_risk, uncovered)'),
                new InputOption('gaps', 'g', InputOption::VALUE_NONE, 'List uncovered scenarios'),
                new InputOption('json', null, InputOption::VALUE_NONE),
            ]),

            $this->command('impacted', 'Journeys and tests affected by a change set', function (InputInterface $in, OutputInterface $out) {
                $set = $this->changeSet($in);
                $report = (new ImpactAnalyzer($this->git))->analyze($set);
                if ($in->getOption('json')) {
                    $out->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } elseif ($in->getOption('tests')) {
                    foreach ($report['full_suite_recommended'] ? ['tests'] : ImpactAnalyzer::phpTestPaths($report) as $path) {
                        $out->writeln($path);
                    }
                } else {
                    (new Printer($out))->impact($report, $out->isVerbose());
                }
                if ($in->getOption('run')) {
                    return Hooks::runPhpTests($this->git, $report, $out, (bool) $in->getOption('full-on-uncertain')) ? 0 : 1;
                }

                return 0;
            }, [
                new InputOption('staged', null, InputOption::VALUE_NONE, 'Staged changes vs HEAD'),
                new InputOption('unstaged', null, InputOption::VALUE_NONE, 'Unstaged changes vs index'),
                new InputOption('working', null, InputOption::VALUE_NONE, 'All uncommitted changes vs HEAD (default)'),
                new InputOption('base', null, InputOption::VALUE_REQUIRED, 'Compare merge-base(BASE, HEAD)..HEAD, e.g. origin/main'),
                new InputOption('head', null, InputOption::VALUE_REQUIRED, 'Head revision for --base', 'HEAD'),
                new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Treat these paths as changed (same as --files)'),
                new InputOption('files', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Treat these paths as changed'),
                new InputOption('json', null, InputOption::VALUE_NONE),
                new InputOption('tests', null, InputOption::VALUE_NONE, 'Print only the PHP test paths to run'),
                new InputOption('run', null, InputOption::VALUE_NONE, 'Run the affected PHP tests'),
                new InputOption('full-on-uncertain', null, InputOption::VALUE_NONE, 'With --run, run the full suite when impact is uncertain'),
            ]),

            $this->command('show', 'Look up a journey, route, file or class (forward and reverse)', function (InputInterface $in, OutputInterface $out) {
                return (new Browser($this->workspace($in), $out))->show((string) $in->getArgument('query'), $out->isVerbose());
            }, [
                new InputArgument('query', InputArgument::REQUIRED, 'Journey id, route name, file path or class name'),
                new InputOption('staged', null, InputOption::VALUE_NONE),
            ]),

            $this->command('find', 'Search journeys by id, name, route, component or file', function (InputInterface $in, OutputInterface $out) {
                return (new Browser($this->workspace($in), $out))->find((string) $in->getArgument('term'));
            }, [new InputArgument('term', InputArgument::REQUIRED)]),

            $this->command('index', 'Regenerate INDEX.md (and optionally the coverage baseline)', function (InputInterface $in, OutputInterface $out) {
                $ws = Workspace::open($this->git, Snapshot::WORKTREE);
                $catalog = $ws->catalog();
                if ($catalog->errors !== []) {
                    $out->writeln('<fg=red>Fix schema errors first (php bin/journeys validate).</>');

                    return 1;
                }
                file_put_contents($this->git->root().'/'.IndexWriter::INDEX_PATH, IndexWriter::render($catalog, $ws->config));
                $out->writeln('Wrote '.IndexWriter::INDEX_PATH);
                $baselinePath = $this->git->root().'/'.IndexWriter::BASELINE_PATH;
                if ($in->getOption('update-baseline') || ! is_file($baselinePath)) {
                    file_put_contents($baselinePath, IndexWriter::renderBaseline($catalog));
                    $out->writeln('Wrote '.IndexWriter::BASELINE_PATH);
                }

                return 0;
            }, [new InputOption('update-baseline', null, InputOption::VALUE_NONE, 'Accept current scenario coverage as the new baseline')]),

            $this->command('graph', 'Dependency graph statistics or JSON export', function (InputInterface $in, OutputInterface $out) {
                $ws = $this->workspace($in);
                $graph = $ws->graph();
                if ($in->getOption('json')) {
                    $journeys = [];
                    foreach ($ws->catalog()->journeys as $id => $journey) {
                        $journeys[$id] = array_values(array_filter(array_map(fn ($n) => Graph::pathOf($n), array_keys($ws->closure($journey)))));
                        sort($journeys[$id]);
                    }
                    $edges = [];
                    foreach ($graph->nodes() as $node) {
                        foreach ($graph->dependencies($node) as $to => $evidence) {
                            $edges[] = [$node, $to, $evidence];
                        }
                    }
                    $out->writeln((string) json_encode(['journeys' => $journeys, 'edges' => $edges, 'routes' => $graph->routes, 'unresolved' => $graph->unresolved], JSON_UNESCAPED_SLASHES));

                    return 0;
                }
                $out->writeln(sprintf('%d files analysed, %d nodes, %d edges, %d routes, %d unresolved references', count($graph->facts), count($graph->nodes()), $graph->edgeCount(), count($graph->routes), count($graph->unresolved)));

                return 0;
            }, [new InputOption('staged', null, InputOption::VALUE_NONE), new InputOption('json', null, InputOption::VALUE_NONE)]),

            $this->command('hook', 'Run a git hook (pre-commit, pre-push)', function (InputInterface $in, OutputInterface $out) {
                $hooks = new Hooks($this->git, $out);

                return match ($in->getArgument('name')) {
                    'pre-commit' => $hooks->preCommit(),
                    'pre-push' => $hooks->prePush((string) stream_get_contents(STDIN)),
                    default => 2,
                };
            }, [new InputArgument('name', InputArgument::REQUIRED), new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL)]),
        ]);
    }

    /**
     * @param  callable(InputInterface, OutputInterface): int  $code
     * @param  list<InputArgument|InputOption>  $definition
     */
    private function command(string $name, string $description, callable $code, array $definition = []): Command
    {
        return (new Command($name))->setDescription($description)->setDefinition($definition)->setCode($code);
    }

    private function workspace(InputInterface $in): Workspace
    {
        if ($in->hasOption('ref') && $in->getOption('ref')) {
            return Workspace::open($this->git, Snapshot::COMMIT, (string) $in->getOption('ref'));
        }

        return Workspace::open($this->git, $in->hasOption('staged') && $in->getOption('staged') ? Snapshot::INDEX : Snapshot::WORKTREE);
    }

    private function changeSet(InputInterface $in): ChangeSet
    {
        return match (true) {
            $in->getOption('files') !== [] || $in->getArgument('paths') !== [] => ChangeSet::files($this->git, [...$in->getOption('files'), ...$in->getArgument('paths')]),
            $in->getOption('base') !== null => ChangeSet::range($this->git, (string) $in->getOption('base'), (string) $in->getOption('head')),
            (bool) $in->getOption('staged') => ChangeSet::staged($this->git),
            (bool) $in->getOption('unstaged') => ChangeSet::unstaged($this->git),
            default => ChangeSet::working($this->git),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appRoutes(): array
    {
        $proc = proc_open(['php', 'artisan', 'route:list', '--json', '--except-vendor'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->git->root());
        $json = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
        if (is_resource($proc)) {
            proc_close($proc);
        }
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            throw new \RuntimeException('php artisan route:list --json failed; is the application bootable (.env present)?');
        }

        return $rows;
    }

    /**
     * @param  list<Issue>  $issues
     */
    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->severity === Issue::ERROR) {
                return true;
            }
        }

        return false;
    }
}
