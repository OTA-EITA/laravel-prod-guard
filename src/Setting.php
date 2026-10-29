<?php

declare(strict_types=1);

namespace LaravelProdGuard;

final readonly class Setting
{
    public function __construct(
        /** Normalised value: 'true'/'false' for booleans, null when unset. */
        public ?string $value,
        /** Where the value came from; '' for the framework default. */
        public string $file,
        public int $line,
        /** Whether the value was written explicitly (env file or a literal in config). */
        public bool $explicit,
    ) {
    }

    public function isTrue(): bool
    {
        return $this->value === 'true' || $this->value === '1';
    }

    public function isFalse(): bool
    {
        return $this->value === 'false' || $this->value === '0' || $this->value === '';
    }
}
