<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Sentry;

final class EnvelopeItem
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>|string  $payload  parsed array if JSON, raw string otherwise
     */
    public function __construct(
        public readonly array $headers,
        public readonly array|string $payload,
    ) {}

    public function type(): string
    {
        return (string) ($this->headers['type'] ?? 'unknown');
    }
}
