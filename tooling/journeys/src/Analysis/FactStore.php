<?php

namespace Tooling\Journeys\Analysis;

use Tooling\Journeys\Config;
use Tooling\Journeys\Git\Snapshot;

/**
 * Produces per-file facts for a snapshot. Facts are content-addressed by git
 * blob hash, so the cache never needs invalidation: changed content simply
 * hashes to a new key. Bump VERSION whenever extraction logic changes.
 */
final class FactStore
{
    public const VERSION = 5;

    private PhpAnalyzer $php;

    /** @var array<string, array<string, mixed>> */
    private array $memory = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $cacheDir,
    ) {
        $this->php = new PhpAnalyzer;
    }

    public static function kindOf(string $path): ?string
    {
        return match (true) {
            str_ends_with($path, '.blade.php') => 'blade',
            str_ends_with($path, '.php') => 'php',
            str_ends_with($path, '.vue'), str_ends_with($path, '.js'), str_ends_with($path, '.mjs'), str_ends_with($path, '.ts') => 'js',
            str_ends_with($path, '.yaml'), str_ends_with($path, '.yml') => 'yaml',
            default => null,
        };
    }

    /**
     * @return array<string, array<string, mixed>> path => facts
     */
    public function factsFor(Snapshot $snapshot, Config $config): array
    {
        $facts = [];
        $pendingJs = [];

        foreach ($snapshot->files() as $path => $sha) {
            $kind = self::kindOf($path);
            if ($kind === null || $kind === 'yaml' || ! $config->inAnalysisRoots($path) || $config->isIgnored($path)) {
                continue;
            }
            if ($kind === 'blade') {
                $facts[$path] = ['kind' => 'blade'];

                continue;
            }

            $routeFile = $config->routeFiles[$path] ?? null;
            $key = $kind.'-'.$sha.($routeFile !== null ? '-routes:'.$path.':'.implode(':', $routeFile) : '');
            $cached = $this->get($key);
            if ($cached !== null) {
                $facts[$path] = $cached;

                continue;
            }

            if ($kind === 'php') {
                $facts[$path] = $this->put($key, $this->php->analyze($path, (string) $snapshot->read($path), $routeFile));
            } else {
                $pendingJs[$path] = $key;
            }
        }

        if ($pendingJs !== []) {
            $sources = [];
            foreach (array_keys($pendingJs) as $path) {
                $sources[$path] = (string) $snapshot->read($path);
            }
            foreach ($this->runJs($sources) as $path => $result) {
                // Tool failures (node missing) are not cached so they retry next run.
                $facts[$path] = isset($result['tool_error']) ? $result : $this->put($pendingJs[$path], $result);
            }
        }

        ksort($facts);

        return $facts;
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, array<string, mixed>>
     */
    private function runJs(array $sources): array
    {
        $script = __DIR__.'/../../js-facts.mjs';
        $proc = @proc_open(['node', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot);

        $failure = function (string $reason) use ($sources): array {
            return array_map(fn () => ['kind' => 'js', 'error' => $reason, 'tool_error' => true], $sources);
        };

        if (! is_resource($proc)) {
            return $failure('node is not available; JS/Vue dependencies unresolved');
        }

        // Write and read concurrently would be ideal; payloads are small enough
        // (a few MB at most) that writing first stays within pipe buffering
        // once we drain stdout before waiting.
        stream_set_blocking($pipes[1], false);
        $payload = (string) json_encode($sources);
        $output = '';
        $written = 0;
        while ($written < strlen($payload)) {
            $n = fwrite($pipes[0], substr($payload, $written, 65536));
            if ($n === false) {
                break;
            }
            $written += $n;
            $output .= (string) stream_get_contents($pipes[1]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], true);
        $output .= (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $decoded = json_decode($output, true);
        if ($code !== 0 || ! is_array($decoded)) {
            return $failure('JS analyzer failed: '.trim(strtok($stderr, "\n") ?: 'no output'));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $key): ?array
    {
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }
        if ($this->cacheDir === null) {
            return null;
        }
        $file = $this->file($key);
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $this->memory[$key] = $data : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function put(string $key, array $facts): array
    {
        $this->memory[$key] = $facts;
        if ($this->cacheDir !== null) {
            $file = $this->file($key);
            is_dir(dirname($file)) || @mkdir(dirname($file), 0777, true);
            @file_put_contents($file, json_encode($facts));
        }

        return $facts;
    }

    private function file(string $key): string
    {
        $hash = sha1('v'.self::VERSION.':'.$key);

        return $this->cacheDir.'/'.substr($hash, 0, 2).'/'.$hash.'.json';
    }
}
