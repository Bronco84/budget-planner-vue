<?php

namespace Tooling\Journeys\Git;

use RuntimeException;

/**
 * Thin wrapper over the git CLI. Every command runs with an explicit argument
 * vector (no shell interpolation) and never writes to the index or work tree.
 */
final class Git
{
    private ?BlobReader $blobReader = null;

    /**
     * @param  bool  $isolated  ignore inherited GIT_* variables (GIT_INDEX_FILE, GIT_DIR, ...).
     *                          Git exports these to hooks; anything operating on a *different*
     *                          repository from inside a hook (e.g. analyzer test fixtures) must
     *                          not inherit them or it would write into the outer repository.
     */
    public function __construct(
        private readonly string $root,
        private readonly bool $isolated = false,
    ) {}

    /**
     * @return array<string, string>|null environment for child processes (null = inherit)
     */
    public function env(): ?array
    {
        if (! $this->isolated) {
            return null;
        }

        return array_filter(getenv(), fn ($key) => ! str_starts_with((string) $key, 'GIT_'), ARRAY_FILTER_USE_KEY);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param  list<string>  $args
     */
    public function run(array $args, bool $allowFailure = false): string
    {
        [$code, $out, $err] = $this->exec($args);

        if ($code !== 0 && ! $allowFailure) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.trim($err));
        }

        return $code === 0 ? $out : '';
    }

    /**
     * @param  list<string>  $args
     * @return array{0:int,1:string,2:string}
     */
    public function exec(array $args): array
    {
        $proc = proc_open(
            array_merge(['git', '-c', 'core.quotepath=off'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $this->env(),
        );

        if (! is_resource($proc)) {
            throw new RuntimeException('Unable to start git');
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), (string) $out, (string) $err];
    }

    public function hasCommit(string $ref): bool
    {
        return $this->exec(['rev-parse', '--verify', '--quiet', $ref.'^{commit}'])[0] === 0;
    }

    public function revParse(string $ref): string
    {
        return trim($this->run(['rev-parse', '--verify', $ref]));
    }

    public function mergeBase(string $a, string $b): string
    {
        return trim($this->run(['merge-base', $a, $b]));
    }

    public function blobs(): BlobReader
    {
        return $this->blobReader ??= new BlobReader($this->root, $this->env());
    }

    /**
     * Split NUL-delimited git output, dropping the trailing empty element.
     *
     * @return list<string>
     */
    public static function splitZ(string $output): array
    {
        $parts = explode("\0", $output);
        if (end($parts) === '') {
            array_pop($parts);
        }

        return $parts;
    }

    /**
     * Compute git's blob hash for in-memory content (matches `git hash-object`).
     */
    public static function blobHash(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }
}
