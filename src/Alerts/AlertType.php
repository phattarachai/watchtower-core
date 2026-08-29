<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

enum AlertType: string
{
    case NewIssue = 'new_issue';
    case Regression = 'regression';
    case Threshold = 'threshold';
    case Milestone = 'milestone';

    public function label(): string
    {
        return match ($this) {
            self::NewIssue => 'Issue ใหม่',
            self::Regression => 'Issue ที่ resolved กลับมาอีก',
            self::Threshold => 'Threshold (N events ใน M นาที)',
            self::Milestone => 'Milestone (ครบ N events รวมทั้งหมด)',
        };
    }
}
