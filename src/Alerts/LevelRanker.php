<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

class LevelRanker
{
    private const array RANK = [
        'debug' => 0,
        'info' => 1,
        'log' => 1,
        'warning' => 2,
        'error' => 3,
        'fatal' => 4,
    ];

    public function meetsThreshold(string $eventLevel, string $minLevel): bool
    {
        return $this->rankOf($eventLevel) >= $this->rankOf($minLevel);
    }

    private function rankOf(string $level): int
    {
        return self::RANK[strtolower($level)] ?? 3;
    }
}
