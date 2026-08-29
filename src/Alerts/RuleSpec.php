<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

use DateTimeImmutable;

/**
 * Framework-agnostic projection of a stored alert rule.
 */
final class RuleSpec
{
    /**
     * @param  array<string, mixed>  $targets
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly AlertType $type,
        public readonly ?string $environment,
        public readonly string $minLevel,
        public readonly ?int $thresholdCount,
        public readonly ?int $thresholdWindowSeconds,
        public readonly int $cooldownSeconds,
        public readonly ?DateTimeImmutable $mutedUntil,
        public readonly bool $isActive,
        public readonly array $targets,
    ) {}

    public function isMuted(DateTimeImmutable $now): bool
    {
        return $this->mutedUntil !== null && $this->mutedUntil > $now;
    }

    public function appliesToEnvironment(?string $environment): bool
    {
        return $this->environment === null
            || $this->environment === ''
            || $this->environment === $environment;
    }
}
