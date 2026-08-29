<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Alerts;

/**
 * One rule that fired. Recipient resolution, delivery and bookkeeping are the
 * caller's business — this only says what matched.
 */
final class AlertDecision
{
    public function __construct(
        public readonly int $ruleId,
        public readonly RuleSpec $rule,
        public readonly AlertType $kind,
    ) {}
}
