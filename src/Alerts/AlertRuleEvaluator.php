<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

use DateTimeImmutable;
use Phattarachai\WatchtowerCore\Alerts\Contracts\EventCounter;
use Phattarachai\WatchtowerCore\Alerts\Contracts\NotificationLog;

/**
 * Decides which alert rules fire for an incoming event. Pure decision logic —
 * no delivery, no persistence, no rule loading.
 */
class AlertRuleEvaluator
{
    public function __construct(
        private readonly LevelRanker $levels,
        private readonly EventCounter $events,
        private readonly NotificationLog $notifications,
    ) {}

    /**
     * @param  list<RuleSpec>  $rules
     * @return list<AlertDecision>
     */
    public function evaluate(
        IssueSnapshot $issue,
        EventSnapshot $event,
        bool $isNewGroup,
        bool $isRegression,
        array $rules,
        DateTimeImmutable $now,
    ): array {
        if ($issue->suppressesAlerts($now)) {
            return [];
        }

        $decisions = [];
        foreach ($rules as $rule) {
            if (! $this->shouldFire($rule, $issue, $event, $isNewGroup, $isRegression, $now)) {
                continue;
            }
            $decisions[] = new AlertDecision($rule->id, $rule, $rule->type);
        }

        return $decisions;
    }

    private function shouldFire(
        RuleSpec $rule,
        IssueSnapshot $issue,
        EventSnapshot $event,
        bool $isNewGroup,
        bool $isRegression,
        DateTimeImmutable $now,
    ): bool {
        if (! $rule->isActive) {
            return false;
        }
        if ($rule->isMuted($now)) {
            return false;
        }
        if (! $rule->appliesToEnvironment($event->environment)) {
            return false;
        }
        if (! $this->levels->meetsThreshold($event->level, $rule->minLevel)) {
            return false;
        }
        if (! $this->matchesType($rule, $issue, $isNewGroup, $isRegression, $now)) {
            return false;
        }

        return ! $this->isWithinCooldown($rule, $issue, $now);
    }

    private function matchesType(
        RuleSpec $rule,
        IssueSnapshot $issue,
        bool $isNewGroup,
        bool $isRegression,
        DateTimeImmutable $now,
    ): bool {
        return match ($rule->type) {
            AlertType::NewIssue => $isNewGroup,
            AlertType::Regression => $isRegression,
            AlertType::Threshold => $this->thresholdMet($rule, $issue, $now),
            AlertType::Milestone => $this->milestoneReached($rule, $issue),
        };
    }

    private function thresholdMet(RuleSpec $rule, IssueSnapshot $issue, DateTimeImmutable $now): bool
    {
        $count = $rule->thresholdCount ?? 0;
        $window = $rule->thresholdWindowSeconds ?? 0;
        if ($count <= 0 || $window <= 0) {
            return false;
        }

        return $this->events->countForGroupSince($issue->id, $this->secondsBefore($now, $window)) >= $count;
    }

    private function milestoneReached(RuleSpec $rule, IssueSnapshot $issue): bool
    {
        $milestone = $rule->thresholdCount ?? 0;

        return $milestone > 0 && $issue->eventCount >= $milestone;
    }

    /**
     * Milestones fire exactly once per (rule, group) — lifetime dedup, ignoring cooldownSeconds.
     */
    private function isWithinCooldown(RuleSpec $rule, IssueSnapshot $issue, DateTimeImmutable $now): bool
    {
        if ($rule->type === AlertType::Milestone) {
            return $this->notifications->hasNotified($rule->id, $issue->id);
        }

        if ($rule->cooldownSeconds <= 0) {
            return false;
        }

        return $this->notifications->hasNotifiedSince(
            $rule->id,
            $issue->id,
            $this->secondsBefore($now, $rule->cooldownSeconds),
        );
    }

    private function secondsBefore(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        return $now->modify("-{$seconds} seconds");
    }
}
