<?php

namespace Tooling\Journeys\Impact;

final class Change
{
    /**
     * @param  string  $status  A (added), M (modified), D (deleted), R (renamed), C (copied)
     * @param  list<int>|null  $oldLines  changed lines in the base version; null = unknown/whole file
     * @param  list<int>|null  $newLines  changed lines in the target version; null = unknown/whole file
     */
    public function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly ?string $oldPath,
        public readonly ?array $oldLines,
        public readonly ?array $newLines,
    ) {}

    public function existsInTarget(): bool
    {
        return $this->status !== 'D';
    }

    public function basePath(): ?string
    {
        return match ($this->status) {
            'A', 'C' => null,
            'R' => $this->oldPath,
            default => $this->path,
        };
    }
}
