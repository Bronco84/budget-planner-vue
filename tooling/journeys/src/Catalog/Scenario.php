<?php

namespace Tooling\Journeys\Catalog;

final class Scenario
{
    /**
     * @param  list<string>  $tests
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $description,
        public readonly string $coverage,
        public readonly array $tests,
        public readonly string $note,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            description: trim((string) ($data['description'] ?? '')),
            coverage: (string) ($data['coverage'] ?? 'uncovered'),
            tests: array_values(array_filter((array) ($data['tests'] ?? []), 'is_string')),
            note: trim((string) ($data['note'] ?? '')),
        );
    }
}
