<?php

namespace Tooling\Journeys\Hooks;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Tooling\Journeys\Console\Application;
use Tooling\Journeys\Console\Printer;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Impact\ChangeSet;
use Tooling\Journeys\Impact\ImpactAnalyzer;
use Tooling\Journeys\Validation\Issue;
use Tooling\Journeys\Validation\Validator;
use Tooling\Journeys\Workspace;

/**
 * Git hook entry points. Mandatory failures return 1 and block; everything
 * else is printed as a warning. Hooks never modify the index or work tree.
 *
 * Environment switches:
 *   JOURNEYS_SKIP=1        skip the hook entirely (same as --no-verify)
 *   JOURNEYS_SKIP_TESTS=1  analyse and validate, but do not run tests
 *   JOURNEYS_E2E=1         pre-push also runs affected Playwright specs
 */
final class Hooks
{
    private const ZERO = '0000000000000000000000000000000000000000';

    private Printer $printer;

    public function __construct(private readonly Git $git, private readonly OutputInterface $out)
    {
        $this->printer = new Printer($out);
    }

    public function preCommit(): int
    {
        $set = ChangeSet::staged($this->git);
        if ($set->changes === []) {
            return 0;
        }

        $this->out->writeln('<info>journeys:</info> pre-commit (staged snapshot)');

        // 1. Validate the staged snapshot. Show errors, and only the warnings
        //    that concern what is being committed.
        $ws = Workspace::open($this->git, Snapshot::INDEX);
        $issues = (new Validator($ws))->validate();

        // 2. Impact of the staged changes.
        $report = (new ImpactAnalyzer($this->git))->analyze($set);
        $this->printer->impact($report);
        $this->out->writeln('');

        $this->printer->issues($this->relevant($issues, $report), true, 15);

        if (Application::hasErrors($issues)) {
            $this->out->writeln('<fg=red>Commit blocked: fix the errors above (or bypass with --no-verify).</>');

            return 1;
        }

        // 3. Fast affected tests. Tests execute against the working tree, so
        //    warn when the staged snapshot and disk differ for relevant files.
        if (getenv('JOURNEYS_SKIP_TESTS')) {
            $this->out->writeln('<fg=gray>JOURNEYS_SKIP_TESTS set; not running tests.</>');

            return 0;
        }
        $this->warnAboutUnstaged($report);

        if (! self::runPhpTests($this->git, $report, $this->out, true)) {
            $this->out->writeln('<fg=red>Commit blocked: affected tests failed.</>');

            return 1;
        }

        return 0;
    }

    public function prePush(string $stdin): int
    {
        $status = 0;
        $lines = array_filter(array_map('trim', explode("\n", $stdin)));

        foreach ($lines as $line) {
            [$localRef, $localSha, , $remoteSha] = array_pad(preg_split('/\s+/', $line) ?: [], 4, '');
            if ($localSha === '' || $localSha === self::ZERO) {
                continue; // deleting a remote branch
            }

            $base = $this->pushBase($localSha, $remoteSha);
            $this->out->writeln("<info>journeys:</info> pre-push {$localRef} (".($base === null ? 'new history' : substr($base, 0, 10)).'..'.substr($localSha, 0, 10).')');

            // 1. Strict validation of the commit being pushed.
            $ws = Workspace::open($this->git, Snapshot::COMMIT, $localSha);
            $issues = (new Validator($ws))->validate(strict: true);

            // 2. Impact of the outgoing range.
            $set = $base === null ? null : ChangeSet::range($this->git, $base, $localSha);
            $report = $set === null ? null : (new ImpactAnalyzer($this->git))->analyze($set);

            if ($report !== null) {
                $this->printer->impact($report);
                $this->out->writeln('');
            }
            $this->printer->issues($report === null ? $issues : $this->relevant($issues, $report), true, 20);

            $blocked = Application::hasErrors($issues);
            if ($report !== null && $report['new_unmapped_routes'] !== []) {
                $this->out->writeln('<fg=red>New routes need a journey (or an excluded_routes entry with a reason).</>');
                $blocked = true;
            }
            if ($report !== null && $report['coverage_regressions'] !== []) {
                $blocked = true;
            }

            // 3. Affected tests (full suite when impact is uncertain or there is no base).
            if (! $blocked && ! getenv('JOURNEYS_SKIP_TESTS')) {
                if (trim($this->git->run(['status', '--porcelain', '--untracked-files=no'])) !== '') {
                    $this->out->writeln('<fg=yellow>! The working tree has uncommitted changes; tests run against the working tree, not the pushed commit.</>');
                }
                $testReport = $report ?? ['tests' => [], 'full_suite_recommended' => true, 'full_suite_reasons' => ['no remote base to diff against'], 'e2e_tests' => []];
                if (! self::runPhpTests($this->git, $testReport, $this->out, true)) {
                    $blocked = true;
                }
                $blocked = ! $this->runE2e($testReport) || $blocked;
            }

            if ($blocked) {
                $this->out->writeln('<fg=red>Push blocked: fix the errors above (or bypass with --no-verify).</>');
                $status = 1;
            }
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function runPhpTests(Git $git, array $report, OutputInterface $out, bool $fullOnUncertain): bool
    {
        $paths = ImpactAnalyzer::phpTestPaths($report);
        $full = $fullOnUncertain && $report['full_suite_recommended'];

        if (! $full && $paths === []) {
            $out->writeln('<fg=gray>No affected PHP tests to run.</>');

            return true;
        }

        $config = Workspace::open($git, Snapshot::WORKTREE)->config;
        $command = $config->phpTestCommand.($full ? '' : ' '.implode(' ', array_map('escapeshellarg', $paths)));
        $out->writeln('<info>Running:</info> '.$command.($full ? '  <fg=gray>(full suite: impact could not be narrowed safely)</>' : ''));

        return self::passthru($git->root(), $command);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function runE2e(array $report): bool
    {
        if ($report['e2e_tests'] === []) {
            return true;
        }
        if (! getenv('JOURNEYS_E2E')) {
            $this->out->writeln('<fg=gray>Affected E2E specs not run (set JOURNEYS_E2E=1; they need the local Herd site): '.implode(', ', $report['e2e_tests']).'</>');

            return true;
        }
        $config = Workspace::open($this->git, Snapshot::WORKTREE)->config;

        return self::passthru($this->git->root(), $config->e2eTestCommand.' '.implode(' ', array_map('escapeshellarg', $report['e2e_tests'])));
    }

    private function pushBase(string $localSha, string $remoteSha): ?string
    {
        if ($remoteSha !== '' && $remoteSha !== self::ZERO && $this->git->hasCommit($remoteSha)) {
            return $remoteSha;
        }

        // New branch: diff against the remote default branch if we know it.
        foreach (['origin/HEAD', 'origin/main', 'origin/master'] as $candidate) {
            if ($this->git->hasCommit($candidate)) {
                try {
                    return $this->git->mergeBase($candidate, $localSha);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, mixed>  $report
     * @return list<Issue>
     */
    private function relevant(array $issues, array $report): array
    {
        $journeys = array_column($report['journeys'], 'id');
        $files = array_column($report['files'], 'path');

        return array_values(array_filter($issues, fn (Issue $i) => $i->severity === Issue::ERROR
            || ($i->journey !== null && in_array($i->journey, $journeys, true))
            || ($i->file !== null && in_array($i->file, $files, true))));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function warnAboutUnstaged(array $report): void
    {
        $dirty = Git::splitZ($this->git->run(['diff', '--name-only', '-z']));
        $relevant = array_intersect($dirty, [...array_column($report['files'], 'path'), ...ImpactAnalyzer::phpTestPaths($report)]);
        if ($relevant !== []) {
            $this->out->writeln('<fg=yellow>! Tests run against the working tree, which has unstaged edits to: '.implode(', ', $relevant).'</>');
        }
    }

    private static function passthru(string $cwd, string $command): bool
    {
        $proc = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

        return is_resource($proc) && proc_close($proc) === 0;
    }
}
