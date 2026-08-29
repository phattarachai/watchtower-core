<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts\Contracts;

use DateTimeImmutable;

interface NotificationLog
{
    /**
     * Has this rule ever notified for this group? Backs lifetime milestone dedup.
     */
    public function hasNotified(int $ruleId, int $groupId): bool;

    /**
     * Has this rule notified for this group at or after $since? Backs cooldown.
     */
    public function hasNotifiedSince(int $ruleId, int $groupId, DateTimeImmutable $since): bool;
}
