<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Tests\Support;

use DateTimeImmutable;
use Phattarachai\WatchtowerCore\Alerts\Contracts\NotificationLog;

final class FakeNotificationLog implements NotificationLog
{
    /** @var list<array{ruleId: int, groupId: int, sentAt: DateTimeImmutable}> */
    private array $sent = [];

    public function record(int $ruleId, int $groupId, DateTimeImmutable $sentAt): void
    {
        $this->sent[] = ['ruleId' => $ruleId, 'groupId' => $groupId, 'sentAt' => $sentAt];
    }

    public function hasNotified(int $ruleId, int $groupId): bool
    {
        return $this->matching($ruleId, $groupId) !== [];
    }

    public function hasNotifiedSince(int $ruleId, int $groupId, DateTimeImmutable $since): bool
    {
        foreach ($this->matching($ruleId, $groupId) as $entry) {
            if ($entry['sentAt'] >= $since) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{ruleId: int, groupId: int, sentAt: DateTimeImmutable}>
     */
    private function matching(int $ruleId, int $groupId): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (array $entry): bool => $entry['ruleId'] === $ruleId && $entry['groupId'] === $groupId,
        ));
    }
}
