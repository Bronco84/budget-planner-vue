<?php

namespace Tooling\Journeys\Git;

use RuntimeException;

/**
 * Reads blob contents through one long-lived `git cat-file --batch` process,
 * avoiding a process spawn per file when reading the index or a commit.
 */
final class BlobReader
{
    /** @var resource|null */
    private $proc = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param  array<string, string>|null  $env
     */
    public function __construct(
        private readonly string $root,
        private readonly ?array $env = null,
    ) {}

    public function read(string $sha): ?string
    {
        $this->start();

        fwrite($this->pipes[0], $sha."\n");
        fflush($this->pipes[0]);

        $header = fgets($this->pipes[1]);
        if ($header === false) {
            throw new RuntimeException('git cat-file terminated unexpectedly');
        }

        if (str_ends_with(trim($header), 'missing')) {
            return null;
        }

        $size = (int) explode(' ', trim($header))[2];
        $content = '';
        while (strlen($content) < $size) {
            $chunk = fread($this->pipes[1], $size - strlen($content));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Short read from git cat-file');
            }
            $content .= $chunk;
        }
        fread($this->pipes[1], 1); // trailing newline

        return $content;
    }

    public function __destruct()
    {
        if ($this->proc !== null) {
            fclose($this->pipes[0]);
            fclose($this->pipes[1]);
            proc_close($this->proc);
        }
    }

    private function start(): void
    {
        if ($this->proc !== null) {
            return;
        }

        $proc = proc_open(['git', 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, $this->root, $this->env);
        if (! is_resource($proc)) {
            throw new RuntimeException('Unable to start git cat-file');
        }

        $this->proc = $proc;
        $this->pipes = $pipes;
    }
}
