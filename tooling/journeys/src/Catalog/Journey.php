<?php

namespace Tooling\Journeys\Catalog;

final class Journey
{
    /**
     * @param  list<string>  $actors
     * @param  list<string>  $routes
     * @param  list<array{path:string, reason:string}>  $dependsOn
     * @param  list<Scenario>  $scenarios
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $file,
        public readonly string $id,
        public readonly string $name,
        public readonly string $domain,
        public readonly string $status,
        public readonly string $priority,
        public readonly string $summary,
        public readonly array $actors,
        public readonly array $routes,
        public readonly array $dependsOn,
        public readonly array $scenarios,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data  schema-validated data (may still be partial)
     */
    public static function fromArray(string $file, array $data): self
    {
        $strings = fn ($v) => array_values(array_filter((array) $v, 'is_string'));

        return new self(
            file: $file,
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            status: (string) ($data['status'] ?? 'unverified'),
            priority: (string) ($data['priority'] ?? 'medium'),
            summary: trim((string) ($data['summary'] ?? '')),
            actors: $strings($data['actors'] ?? []),
            routes: $strings($data['routes'] ?? []),
            dependsOn: array_values(array_map(
                fn ($d) => ['path' => (string) ($d['path'] ?? ''), 'reason' => (string) ($d['reason'] ?? '')],
                array_filter((array) ($data['depends_on'] ?? []), 'is_array'),
            )),
            scenarios: array_values(array_map(
                fn ($s) => Scenario::fromArray($s),
                array_filter((array) ($data['scenarios'] ?? []), 'is_array'),
            )),
            raw: $data,
        );
    }

    public function isImplemented(): bool
    {
        return in_array($this->status, ['implemented', 'unverified'], true);
    }

    /**
     * @return list<string> every test reference across scenarios
     */
    public function testRefs(): array
    {
        $refs = [];
        foreach ($this->scenarios as $scenario) {
            $refs = [...$refs, ...$scenario->tests];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return list<string> distinct test file paths
     */
    public function testFiles(): array
    {
        return array_values(array_unique(array_map(fn ($ref) => TestRef::parse($ref)->path, $this->testRefs())));
    }
}
