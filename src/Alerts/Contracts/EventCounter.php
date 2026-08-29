<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts\Contracts;

use DateTimeImmutable;

interface EventCounter
{
    /**
     * Events recorded for the group whose received_at is at or after $since.
     */
    public function countForGroupSince(int $groupId, DateTimeImmutable $since): int;
}
