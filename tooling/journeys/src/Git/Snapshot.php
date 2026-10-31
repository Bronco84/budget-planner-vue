<?php

namespace Tooling\Journeys\Git;

/**
 * An immutable view of repository content: a commit, the staged index, or the
 * working tree. All analysis reads through a snapshot so the pre-commit hook
 * inspects exactly what is staged rather than whatever happens to be on disk.
 */
final class Snapshot
{
    public const COMMIT = 'commit';

    public const INDEX = 'index';

    public const WORKTREE = 'worktree';

    public const EMPTY = 'empty';

    /** @var array<string, string>|null path => blob sha */
    private ?array $files = null;

    /** @var array<string, string> worktree content read while hashing */
    private array $worktreeContent = [];

    private function __construct(
        private readonly Git $git,
        public readonly string $kind,
        public readonly ?string $ref = null,
        private readonly ?\Closure $filter = null,
    ) {}

    /**
     * @param  (\Closure(string): bool)|null  $filter  limits which paths are visible
     */
    public static function commit(Git $git, string $ref, ?\Closure $filter = null): self
    {
        return new self($git, self::COMMIT, $git->revParse($ref.'^{commit}'), $filter);
    }

    public static function index(Git $git, ?\Closure $filter = null): self
    {
        return new self($git, self::INDEX, null, $filter);
    }

    public static function worktree(Git $git, ?\Closure $filter = null): self
    {
        return new self($git, self::WORKTREE, null, $filter);
    }

    public static function empty(Git $git): self
    {
        return new self($git, self::EMPTY);
    }

    /**
     * Stable identity for caching whole-snapshot results; null when the
     * snapshot (index / working tree) has no cheap content address.
     */
    public function cacheKey(): ?string
    {
        return $this->kind === self::COMMIT ? (string) $this->ref : null;
    }

    private function visible(string $path): bool
    {
        return $this->filter === null || ($this->filter)($path);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::COMMIT => 'commit '.substr((string) $this->ref, 0, 10),
            self::INDEX => 'staged index',
            self::WORKTREE => 'working tree',
            self::EMPTY => 'empty tree',
        };
    }

    /**
     * @return array<string, string> path => blob sha
     */
    public function files(): array
    {
        return $this->files ??= $this->loadFiles();
    }

    public function has(string $path): bool
    {
        return isset($this->files()[$path]);
    }

    public function sha(string $path): ?string
    {
        return $this->files()[$path] ?? null;
    }

    public function read(string $path): ?string
    {
        $sha = $this->sha($path);
        if ($sha === null) {
            return null;
        }

        if ($this->kind === self::WORKTREE) {
            return $this->worktreeContent[$path] ??= (string) file_get_contents($this->git->root().'/'.$path);
        }

        return $this->git->blobs()->read($sha);
    }

    /**
     * @return array<string, string>
     */
    private function loadFiles(): array
    {
        $files = [];

        switch ($this->kind) {
            case self::EMPTY:
                break;

            case self::COMMIT:
                foreach (Git::splitZ($this->git->run(['ls-tree', '-r', '-z', (string) $this->ref])) as $entry) {
                    [$meta, $path] = explode("\t", $entry, 2);
                    [, $type, $sha] = explode(' ', $meta);
                    if ($type === 'blob' && $this->visible($path)) {
                        $files[$path] = $sha;
                    }
                }
                break;

            case self::INDEX:
                foreach (Git::splitZ($this->git->run(['ls-files', '-s', '-z'])) as $entry) {
                    [$meta, $path] = explode("\t", $entry, 2);
                    [, $sha, $stage] = explode(' ', $meta);
                    // During a conflicted merge keep stage 0, or the "ours" side (2).
                    if (! $this->visible($path)) {
                        continue;
                    }
                    if ($stage === '0' || ($stage === '2' && ! isset($files[$path]))) {
                        $files[$path] = $sha;
                    }
                }
                break;

            case self::WORKTREE:
                $listed = Git::splitZ($this->git->run(['ls-files', '-z', '--cached', '--others', '--exclude-standard']));
                foreach (array_unique($listed) as $path) {
                    if (! $this->visible($path)) {
                        continue;
                    }
                    $full = $this->git->root().'/'.$path;
                    if (is_file($full) && ! is_link($full)) {
                        $content = (string) file_get_contents($full);
                        $this->worktreeContent[$path] = $content;
                        $files[$path] = Git::blobHash($content);
                    }
                }
                break;
        }

        ksort($files);

        return $files;
    }
}
