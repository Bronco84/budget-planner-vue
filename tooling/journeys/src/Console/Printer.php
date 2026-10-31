<?php

namespace Tooling\Journeys\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Tooling\Journeys\Validation\Issue;

/**
 * Human-readable rendering shared by commands and hooks.
 */
final class Printer
{
    public function __construct(private readonly OutputInterface $out) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function impact(array $report, bool $verbose = false): void
    {
        $o = $this->out;
        $o->writeln("<info>Impact of {$report['description']}</info> ({$report['base']} → {$report['target']})");
        $o->writeln('');

        $o->writeln('<comment>Changed files:</comment>');
        if ($report['files'] === []) {
            $o->writeln('  (none)');
        }
        foreach ($report['files'] as $file) {
            $from = $file['old_path'] !== null ? "{$file['old_path']} → " : '';
            $detail = $file['detail'] !== null ? " — {$file['detail']}" : '';
            $o->writeln(sprintf('  %s %s%s  <fg=gray>[%s]%s</>', $file['status'], $from, $file['path'], $file['classification'], $detail));
        }
        $o->writeln('');

        $o->writeln('<comment>Potentially affected journeys:</comment>');
        if ($report['journeys'] === []) {
            $o->writeln('  (none)');
        }
        $sharedOnly = [];
        foreach ($report['journeys'] as $j) {
            if (! $verbose && array_unique(array_column($j['reasons'], 'kind')) === ['shared']) {
                $sharedOnly[] = $j['id'];

                continue;
            }
            $o->writeln(sprintf('  %-12s %-9s %-11s %s  <fg=gray>[%s; coverage: %s]</>', $j['id'], $j['priority'], $j['impact'], $j['name'], $j['status'], $j['coverage'] ?? '-'));
            foreach ($verbose ? $j['reasons'] : array_slice($j['reasons'], 0, 1) as $reason) {
                $o->writeln('      <fg=gray>via '.implode(' → ', $reason['path']).'</>');
            }
            if (! $verbose && $j['reason_count'] > 1) {
                $o->writeln('      <fg=gray>(+'.($j['reason_count'] - 1).' more; use -v)</>');
            }
        }
        if ($sharedOnly !== []) {
            sort($sharedOnly, SORT_NATURAL);
            $o->writeln('  <fg=gray>Via shared infrastructure only ('.count($sharedOnly).'; -v for details):</>');
            $o->writeln('  '.wordwrap(implode(', ', $sharedOnly), 96, "\n  "));
        }
        $o->writeln('');

        $o->writeln('<comment>Relevant tests:</comment>');
        if ($report['tests'] === [] && $report['e2e_tests'] === []) {
            $o->writeln('  (none identified)');
        }
        foreach ($report['tests'] as $test) {
            $o->writeln("  {$test['path']}  <fg=gray>({$test['reason']})</>");
        }
        foreach ($report['e2e_tests'] as $test) {
            $o->writeln("  {$test}  <fg=gray>(e2e)</>");
        }

        if ($report['full_suite_recommended']) {
            $o->writeln('');
            $o->writeln('<fg=yellow>Full test suite recommended:</>');
            foreach (array_slice($report['full_suite_reasons'], 0, 8) as $reason) {
                $o->writeln("  - {$reason}");
            }
        }

        if ($report['coverage_warnings'] !== []) {
            $o->writeln('');
            $o->writeln('<fg=yellow>Coverage warnings:</>');
            foreach ($report['coverage_warnings'] as $id => $gaps) {
                foreach (array_slice($gaps, 0, $verbose ? 99 : 3) as $gap) {
                    $o->writeln("  {$id}: {$gap}");
                }
                if (! $verbose && count($gaps) > 3) {
                    $o->writeln("  {$id}: (+".(count($gaps) - 3).' more)');
                }
            }
        }

        if ($report['new_unmapped_routes'] !== []) {
            $o->writeln('');
            $o->writeln('<fg=yellow>New routes not covered by any journey:</>');
            foreach ($report['new_unmapped_routes'] as $route) {
                $o->writeln("  {$route}");
            }
        }

        if ($report['coverage_regressions'] !== []) {
            $o->writeln('');
            $o->writeln('<fg=red>Coverage regressions vs. base:</>');
            foreach ($report['coverage_regressions'] as $message) {
                $o->writeln("  {$message}");
            }
        }
    }

    /**
     * @param  list<Issue>  $issues
     */
    public function issues(array $issues, bool $showWarnings = true, int $warningLimit = PHP_INT_MAX): void
    {
        $errors = array_filter($issues, fn (Issue $i) => $i->severity === Issue::ERROR);
        $warnings = array_filter($issues, fn (Issue $i) => $i->severity === Issue::WARNING);

        foreach ($errors as $issue) {
            $this->out->writeln('<fg=red>✗ ['.$issue->code.']</> '.self::where($issue).$issue->message);
        }

        if ($showWarnings) {
            $shown = 0;
            foreach ($warnings as $issue) {
                if ($shown++ >= $warningLimit) {
                    break;
                }
                $this->out->writeln('<fg=yellow>! ['.$issue->code.']</> '.self::where($issue).$issue->message);
            }
            if (count($warnings) > $warningLimit) {
                $this->out->writeln('<fg=gray>  … '.(count($warnings) - $warningLimit).' more warnings (php bin/journeys validate)</>');
            }
        }

        $byCode = array_count_values(array_map(fn (Issue $i) => $i->code, $warnings));
        ksort($byCode);
        $summary = implode(', ', array_map(fn ($code, $n) => "{$n} {$code}", array_keys($byCode), $byCode));
        $this->out->writeln(sprintf('%s %d error(s), %d warning(s)%s', $errors === [] ? '<info>✓</info>' : '<fg=red>✗</>', count($errors), count($warnings), $summary !== '' ? " ({$summary})" : ''));
    }

    private static function where(Issue $issue): string
    {
        return $issue->journey !== null ? "{$issue->journey}: " : ($issue->file !== null ? "{$issue->file}: " : '');
    }
}
