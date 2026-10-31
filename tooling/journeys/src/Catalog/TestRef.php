<?php

namespace Tooling\Journeys\Catalog;

/**
 * A test reference: `tests/Feature/FooTest.php` (whole file) or
 * `tests/Feature/FooTest.php::user can do the thing` (one named test).
 */
final class TestRef
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name,
    ) {}

    public static function parse(string $ref): self
    {
        $pos = strpos($ref, '::');

        return $pos === false
            ? new self(trim($ref), null)
            : new self(trim(substr($ref, 0, $pos)), trim(substr($ref, $pos + 2)));
    }

    /**
     * Pest prefixes it() descriptions with "it "; accept either spelling.
     *
     * @param  list<string>  $names
     */
    public function matchesAny(array $names): bool
    {
        foreach ($names as $name) {
            if ($name === $this->name || $name === 'it '.$this->name) {
                return true;
            }
        }

        return false;
    }
}
