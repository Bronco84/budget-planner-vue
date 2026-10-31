<?php

namespace Tooling\Journeys\Catalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Tooling\Journeys\Config;
use Tooling\Journeys\Git\Snapshot;

/**
 * The set of journey definitions in a snapshot plus any load/schema errors.
 */
final class Catalog
{
    /**
     * @param  array<string, Journey>  $journeys  id => journey
     * @param  list<array{file:string, message:string}>  $errors
     */
    public function __construct(
        public readonly array $journeys,
        public readonly array $errors,
    ) {}

    public static function load(Snapshot $snapshot): self
    {
        $journeys = [];
        $errors = [];

        foreach (array_keys($snapshot->files()) as $path) {
            if (! str_starts_with($path, Config::JOURNEY_DIR) || ! preg_match('/\.ya?ml$/', $path)) {
                continue;
            }

            try {
                $data = Yaml::parse((string) $snapshot->read($path));
            } catch (ParseException $e) {
                $errors[] = ['file' => $path, 'message' => 'YAML parse error: '.$e->getMessage()];

                continue;
            }

            if (! is_array($data)) {
                $errors[] = ['file' => $path, 'message' => 'journey file must contain a mapping'];

                continue;
            }

            // Symfony YAML turns unquoted dates into timestamps.
            if (is_int($data['last_verified'] ?? null)) {
                $data['last_verified'] = gmdate('Y-m-d', $data['last_verified']);
            }

            foreach (Schema::validate($data) as $message) {
                $errors[] = ['file' => $path, 'message' => $message];
            }

            $id = is_string($data['id'] ?? null) ? $data['id'] : null;
            if ($id === null) {
                continue;
            }

            if (! str_starts_with(basename($path), $id)) {
                $errors[] = ['file' => $path, 'message' => "file name must start with the journey id ({$id})"];
            }

            if (isset($journeys[$id])) {
                $errors[] = ['file' => $path, 'message' => "duplicate journey id {$id} (also in {$journeys[$id]->file})"];

                continue;
            }

            $journeys[$id] = Journey::fromArray($path, $data);
        }

        ksort($journeys, SORT_NATURAL);

        return new self($journeys, $errors);
    }

    public function get(string $id): ?Journey
    {
        return $this->journeys[strtoupper($id)] ?? null;
    }

    /**
     * Journey file path => id, used to map changed definition files to journeys.
     *
     * @return array<string, string>
     */
    public function byFile(): array
    {
        $map = [];
        foreach ($this->journeys as $journey) {
            $map[$journey->file] = $journey->id;
        }

        return $map;
    }
}
