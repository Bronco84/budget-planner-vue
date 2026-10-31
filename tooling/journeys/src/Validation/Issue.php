<?php

namespace Tooling\Journeys\Validation;

final class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $journey = null,
        public readonly ?string $file = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity, 'code' => $this->code, 'journey' => $this->journey, 'file' => $this->file, 'message' => $this->message];
    }
}
