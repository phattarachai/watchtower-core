<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Tests\Support;

use DateTimeImmutable;
use Phattarachai\WatchtowerCore\Alerts\Contracts\EventCounter;

final class FakeEventCounter implements EventCounter
{
    /** @var list<array{groupId: int, receivedAt: DateTimeImmutable}> */
    private array $events = [];

    public function record(int $groupId, DateTimeImmutable $receivedAt): void
    {
        $this->events[] = ['groupId' => $groupId, 'receivedAt' => $receivedAt];
    }

    public function recordMany(int $groupId, int $times, DateTimeImmutable $receivedAt): void
    {
        foreach (range(1, $times) as $ignored) {
            $this->record($groupId, $receivedAt);
        }
    }

    public function countForGroupSince(int $groupId, DateTimeImmutable $since): int
    {
        $matching = array_filter(
            $this->events,
            fn (array $event): bool => $event['groupId'] === $groupId && $event['receivedAt'] >= $since,
        );

        return count($matching);
    }
}
