<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\EventNormalizer;
use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerCore\Ingest\EventScrubber;
use Phattarachai\WatchtowerCore\Ingest\EventTruncator;

function pipeline(?EventTruncator $truncator = null): EventPipeline
{
    return new EventPipeline(
        new EventScrubber(['cookie'], ['password']),
        new EventNormalizer(['event_id', 'exception', 'request', 'message', 'tags'], []),
        $truncator,
    );
}

/** @return array<string, mixed> */
function rawEvent(): array
{
    return [
        'event_id' => 'abc',
        'exception' => ['values' => [['type' => 'RuntimeException', 'value' => str_repeat('boom ', 10_000)]]],
        'request' => ['headers' => ['Cookie' => 'session=1'], 'data' => ['password' => 'hunter2']],
        'modules' => ['laravel/framework' => '12.0.0'],
    ];
}

it('scrubs, normalizes and trims in one pass', function () {
    $event = pipeline(new EventTruncator(maxEventBytes: 20_000, maxStringBytes: 1_024))->prepare(rawEvent());

    expect($event)->not->toHaveKey('modules')
        ->and($event['request']['headers']['Cookie'])->toBe('[Filtered]')
        ->and($event['request']['data']['password'])->toBe('[Filtered]')
        ->and(strlen($event['exception']['values'][0]['value']))->toBeLessThanOrEqual(1_024)
        ->and($event['tags'][EventTruncator::TAG])->toBe('true');
});

it('is idempotent, so a worker can re-run it', function () {
    $pipeline = pipeline(new EventTruncator(maxEventBytes: 20_000, maxStringBytes: 1_024));
    $once = $pipeline->prepare(rawEvent());

    expect($pipeline->prepare($once))->toBe($once)
        ->and($pipeline->fingerprint($pipeline->prepare($once)))->toBe($pipeline->fingerprint($once));
});

it('skips trimming without a truncator', function () {
    $event = pipeline()->prepare(rawEvent());

    expect(strlen($event['exception']['values'][0]['value']))->toBe(50_000);
});

it('computes the title from the prepared event', function () {
    $pipeline = pipeline();

    expect($pipeline->title($pipeline->prepare(['exception' => ['values' => [['type' => 'LogicException', 'value' => 'nope']]]])))
        ->toBe('LogicException: nope');
});
