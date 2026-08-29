<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

use DateTimeImmutable;

/**
 * Framework-agnostic projection of the event that triggered an evaluation.
 */
final class EventSnapshot
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $level,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly array $payload = [],
    ) {}
}
