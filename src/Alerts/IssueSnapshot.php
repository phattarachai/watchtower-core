<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

use DateTimeImmutable;

/**
 * Framework-agnostic projection of an issue group at alert-evaluation time.
 */
final class IssueSnapshot
{
    public const string STATUS_IGNORED = 'ignored';

    public const string STATUS_SNOOZED = 'snoozed';

    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly string $title,
        public readonly string $level,
        public readonly string $status,
        public readonly string $fingerprint,
        public readonly int $eventCount,
        public readonly int $userCount,
        public readonly ?DateTimeImmutable $firstSeenAt,
        public readonly ?DateTimeImmutable $lastSeenAt,
        public readonly ?DateTimeImmutable $snoozedUntil,
    ) {}

    /**
     * Ignored issues never alert; snoozed ones stay quiet until their window lapses.
     */
    public function suppressesAlerts(DateTimeImmutable $now): bool
    {
        return match ($this->status) {
            self::STATUS_IGNORED => true,
            self::STATUS_SNOOZED => $this->snoozedUntil !== null && $this->snoozedUntil > $now,
            default => false,
        };
    }
}
