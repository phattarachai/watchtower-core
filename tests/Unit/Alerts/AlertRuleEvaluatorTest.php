<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Alerts\AlertRuleEvaluator;
use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerCore\Alerts\EventSnapshot;
use Phattarachai\WatchtowerCore\Alerts\IssueSnapshot;
use Phattarachai\WatchtowerCore\Alerts\LevelRanker;
use Phattarachai\WatchtowerCore\Alerts\RuleSpec;
use Phattarachai\WatchtowerCore\Tests\Support\FakeEventCounter;
use Phattarachai\WatchtowerCore\Tests\Support\FakeNotificationLog;

function alertNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-01-01 12:00:00');
}

function issueSnapshot(array $overrides = []): IssueSnapshot
{
    $attrs = array_merge([
        'id' => 1,
        'projectId' => 7,
        'title' => 'TestException: boom',
        'level' => 'error',
        'status' => 'unresolved',
        'fingerprint' => str_repeat('a', 32),
        'eventCount' => 1,
        'userCount' => 0,
        'firstSeenAt' => alertNow(),
        'lastSeenAt' => alertNow(),
        'snoozedUntil' => null,
    ], $overrides);

    return new IssueSnapshot(...$attrs);
}

function eventSnapshot(array $overrides = []): EventSnapshot
{
    $attrs = array_merge([
        'id' => 'a1b2c3d4-0000-0000-0000-000000000000',
        'level' => 'error',
        'environment' => 'production',
        'release' => null,
        'receivedAt' => alertNow(),
        'payload' => [],
    ], $overrides);

    return new EventSnapshot(...$attrs);
}

function ruleSpec(AlertType $type, array $overrides = []): RuleSpec
{
    $attrs = array_merge([
        'id' => 100,
        'name' => 'Test rule',
        'type' => $type,
        'environment' => null,
        'minLevel' => 'error',
        'thresholdCount' => null,
        'thresholdWindowSeconds' => null,
        'cooldownSeconds' => 0,
        'mutedUntil' => null,
        'isActive' => true,
        'targets' => ['emails' => ['ops@example.com']],
    ], $overrides);

    return new RuleSpec(...$attrs);
}

function makeEvaluator(?FakeEventCounter $counter = null, ?FakeNotificationLog $log = null): AlertRuleEvaluator
{
    return new AlertRuleEvaluator(
        new LevelRanker,
        $counter ?? new FakeEventCounter,
        $log ?? new FakeNotificationLog,
    );
}

it('fires a new_issue rule on a new group', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue)],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1)
        ->and($decisions[0]->ruleId)->toBe(100)
        ->and($decisions[0]->kind)->toBe(AlertType::NewIssue);
});

it('does not fire new_issue on a subsequent event', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue)],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('fires a regression rule only on a regression', function () {
    $evaluator = makeEvaluator();
    $rules = [ruleSpec(AlertType::Regression)];

    $fired = $evaluator->evaluate(issueSnapshot(), eventSnapshot(), false, true, $rules, alertNow());
    $quiet = $evaluator->evaluate(issueSnapshot(), eventSnapshot(), false, false, $rules, alertNow());

    expect($fired)->toHaveCount(1)
        ->and($quiet)->toBeEmpty();
});

it('suppresses a second alert inside the cooldown window', function () {
    $log = new FakeNotificationLog;
    $log->record(100, 1, alertNow()->modify('-60 seconds'));

    $decisions = makeEvaluator(log: $log)->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['cooldownSeconds' => 600])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('allows an alert again once the cooldown window has lapsed', function () {
    $log = new FakeNotificationLog;
    $log->record(100, 1, alertNow()->modify('-700 seconds'));

    $decisions = makeEvaluator(log: $log)->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['cooldownSeconds' => 600])],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});

it('fires a threshold rule when N events land inside the window', function () {
    $counter = new FakeEventCounter;
    $counter->recordMany(1, 5, alertNow()->modify('-30 seconds'));

    $decisions = makeEvaluator(counter: $counter)->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Threshold, ['thresholdCount' => 5, 'thresholdWindowSeconds' => 60])],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});

it('skips a threshold rule below the count', function () {
    $counter = new FakeEventCounter;
    $counter->recordMany(1, 4, alertNow()->modify('-30 seconds'));

    $decisions = makeEvaluator(counter: $counter)->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Threshold, ['thresholdCount' => 5, 'thresholdWindowSeconds' => 60])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('ignores events that fell out of the threshold window', function () {
    $counter = new FakeEventCounter;
    $counter->recordMany(1, 5, alertNow()->modify('-600 seconds'));

    $decisions = makeEvaluator(counter: $counter)->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Threshold, ['thresholdCount' => 5, 'thresholdWindowSeconds' => 60])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('fires a milestone rule once event_count crosses N', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['eventCount' => 10]),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Milestone, ['thresholdCount' => 10])],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});

it('skips a milestone rule below N', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['eventCount' => 9]),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Milestone, ['thresholdCount' => 10])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('dedups a milestone for the lifetime of the group, ignoring cooldown', function () {
    $log = new FakeNotificationLog;
    $log->record(100, 1, alertNow()->modify('-10 years'));

    $decisions = makeEvaluator(log: $log)->evaluate(
        issueSnapshot(['eventCount' => 5_000]),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [ruleSpec(AlertType::Milestone, ['thresholdCount' => 10, 'cooldownSeconds' => 0])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('fires several milestone rules independently for the same group', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['eventCount' => 100]),
        eventSnapshot(),
        isNewGroup: false,
        isRegression: false,
        rules: [
            ruleSpec(AlertType::Milestone, ['id' => 1, 'name' => '10x', 'thresholdCount' => 10]),
            ruleSpec(AlertType::Milestone, ['id' => 2, 'name' => '100x', 'thresholdCount' => 100]),
            ruleSpec(AlertType::Milestone, ['id' => 3, 'name' => '1000x', 'thresholdCount' => 1000]),
        ],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(2)
        ->and(array_map(fn ($decision): int => $decision->ruleId, $decisions))->toBe([1, 2]);
});

it('skips a muted rule', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['mutedUntil' => alertNow()->modify('+1 hour')])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('honours a rule whose mute has expired', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['mutedUntil' => alertNow()->modify('-1 hour')])],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});

it('skips an inactive rule', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['isActive' => false])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('skips a rule scoped to a different environment', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(['environment' => 'staging']),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['environment' => 'production'])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('applies an environment-less rule to every environment', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(['environment' => 'staging']),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['environment' => null])],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});

it('gates on min_level', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(),
        eventSnapshot(['level' => 'info']),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue, ['minLevel' => 'error'])],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('stays quiet while the issue is ignored', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['status' => 'ignored']),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue)],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('stays quiet while the issue is snoozed into the future', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['status' => 'snoozed', 'snoozedUntil' => alertNow()->modify('+1 hour')]),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue)],
        now: alertNow(),
    );

    expect($decisions)->toBeEmpty();
});

it('alerts again once a snooze window has lapsed', function () {
    $decisions = makeEvaluator()->evaluate(
        issueSnapshot(['status' => 'snoozed', 'snoozedUntil' => alertNow()->modify('-1 minute')]),
        eventSnapshot(),
        isNewGroup: true,
        isRegression: false,
        rules: [ruleSpec(AlertType::NewIssue)],
        now: alertNow(),
    );

    expect($decisions)->toHaveCount(1);
});
