<?php

namespace Tooling\Journeys\Impact;

use InvalidArgumentException;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Git\Snapshot;

/**
 * Changed files between two snapshots, from one explicit diff mode:
 *   staged    HEAD   -> index          (pre-commit)
 *   unstaged  index  -> working tree
 *   working   HEAD   -> working tree   (all uncommitted work)
 *   range     merge-base(base, head) -> head  (pre-push, CI)
 *   files     HEAD   -> working tree, restricted to the given paths
 */
final class ChangeSet
{
    public const EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /**
     * @param  list<Change>  $changes
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $baseKind,
        public readonly ?string $baseRef,
        public readonly string $targetKind,
        public readonly ?string $targetRef,
        public readonly array $changes,
    ) {}

    public static function staged(Git $git): self
    {
        $hasHead = $git->hasCommit('HEAD');
        $against = $hasHead ? 'HEAD' : self::EMPTY_TREE;

        return new self(
            'staged',
            $hasHead ? Snapshot::COMMIT : Snapshot::EMPTY, $hasHead ? 'HEAD' : null,
            Snapshot::INDEX, null,
            self::parse($git, ['--cached', $against]),
        );
    }

    public static function unstaged(Git $git): self
    {
        $changes = self::parse($git, []);

        return new self('unstaged', Snapshot::INDEX, null, Snapshot::WORKTREE, null, [...$changes, ...self::untracked($git)]);
    }

    public static function working(Git $git): self
    {
        $hasHead = $git->hasCommit('HEAD');
        $changes = self::parse($git, [$hasHead ? 'HEAD' : self::EMPTY_TREE]);

        return new self(
            'working',
            $hasHead ? Snapshot::COMMIT : Snapshot::EMPTY, $hasHead ? 'HEAD' : null,
            Snapshot::WORKTREE, null,
            [...$changes, ...self::untracked($git)],
        );
    }

    public static function range(Git $git, string $base, string $head = 'HEAD'): self
    {
        if (! $git->hasCommit($base)) {
            throw new InvalidArgumentException("unknown base revision '{$base}'");
        }
        $mergeBase = $git->mergeBase($base, $head);
        $headSha = $git->revParse($head.'^{commit}');

        return new self('range', Snapshot::COMMIT, $mergeBase, Snapshot::COMMIT, $headSha, self::parse($git, [$mergeBase, $headSha]));
    }

    /**
     * @param  list<string>  $paths
     */
    public static function files(Git $git, array $paths): self
    {
        $hasHead = $git->hasCommit('HEAD');
        $changes = [];
        foreach ($paths as $path) {
            $path = ltrim($path, './');
            $inHead = $hasHead && $git->exec(['cat-file', '-e', 'HEAD:'.$path])[0] === 0;
            $onDisk = is_file($git->root().'/'.$path);
            $status = match (true) {
                $inHead && ! $onDisk => 'D',
                ! $inHead && $onDisk => 'A',
                default => 'M',
            };
            // Without a diff we cannot attribute lines; null means "whole file".
            $changes[] = new Change($status, $path, null, null, null);
        }

        return new self('files', $hasHead ? Snapshot::COMMIT : Snapshot::EMPTY, $hasHead ? 'HEAD' : null, Snapshot::WORKTREE, null, $changes);
    }

    public function describe(): string
    {
        return match ($this->mode) {
            'staged' => 'staged changes (index vs HEAD)',
            'unstaged' => 'unstaged changes (working tree vs index)',
            'working' => 'all uncommitted changes (working tree vs HEAD)',
            'range' => 'commits '.substr((string) $this->baseRef, 0, 10).'..'.substr((string) $this->targetRef, 0, 10),
            'files' => 'explicit file list',
            default => $this->mode,
        };
    }

    /**
     * @param  list<string>  $diffArgs  revision/--cached arguments shared by both diff calls
     * @return list<Change>
     */
    private static function parse(Git $git, array $diffArgs): array
    {
        $raw = Git::splitZ($git->run(['diff', '--name-status', '-M', '-z', '--no-ext-diff', ...$diffArgs]));
        $hunks = self::hunks($git, $diffArgs);

        $changes = [];
        for ($i = 0; $i < count($raw); $i++) {
            $status = $raw[$i][0];
            if ($status === 'R' || $status === 'C') {
                $old = $raw[++$i];
                $new = $raw[++$i];
                $changes[] = new Change($status, $new, $old, $hunks[$new]['old'] ?? [], $hunks[$new]['new'] ?? []);
            } else {
                $path = $raw[++$i];
                $changes[] = new Change($status === 'T' ? 'M' : $status, $path, null, $hunks[$path]['old'] ?? [], $hunks[$path]['new'] ?? []);
            }
        }

        return $changes;
    }

    /**
     * Changed line numbers per file from a zero-context diff.
     *
     * @param  list<string>  $diffArgs
     * @return array<string, array{old: list<int>, new: list<int>}>
     */
    private static function hunks(Git $git, array $diffArgs): array
    {
        $out = $git->run(['diff', '-U0', '-M', '--no-color', '--no-ext-diff', ...$diffArgs]);
        $result = [];
        $current = null;

        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $target = substr($line, 4);
                $current = $target === '/dev/null' ? $current : self::stripPrefix($target);
                $result[$current] ??= ['old' => [], 'new' => []];
            } elseif (str_starts_with($line, '--- ')) {
                $source = substr($line, 4);
                $current = $source === '/dev/null' ? null : self::stripPrefix($source);
            } elseif ($current !== null && preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                [$oldStart, $oldCount, $newStart, $newCount] = [(int) $m[1], $m[2] === '' ? 1 : (int) $m[2], (int) $m[3], ($m[4] ?? '') === '' ? 1 : (int) $m[4]];
                $result[$current] ??= ['old' => [], 'new' => []];
                $result[$current]['old'] = [...$result[$current]['old'], ...($oldCount > 0 ? range($oldStart, $oldStart + $oldCount - 1) : [])];
                $result[$current]['new'] = [...$result[$current]['new'], ...($newCount > 0 ? range($newStart, $newStart + $newCount - 1) : [$newStart])];
            }
        }

        return $result;
    }

    private static function stripPrefix(string $path): string
    {
        return preg_replace('#^[abiwco]/#', '', $path) ?? $path;
    }

    /**
     * @return list<Change>
     */
    private static function untracked(Git $git): array
    {
        return array_map(
            fn ($path) => new Change('A', $path, null, [], null),
            Git::splitZ($git->run(['ls-files', '--others', '--exclude-standard', '-z'])),
        );
    }
}
