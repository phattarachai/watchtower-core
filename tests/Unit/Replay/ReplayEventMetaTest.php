<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Replay\ReplayEventMeta;
use Phattarachai\WatchtowerCore\Replay\ReplayType;
use Phattarachai\WatchtowerCore\Tests\Support\ReplayFixture;

function replayEventPayload(string $fixture): array
{
    return ReplayFixture::items($fixture)['replay_event']->payload;
}

it('reads the metadata of a session-mode segment', function () {
    $meta = ReplayEventMeta::fromPayload(replayEventPayload('session-seg0'));

    expect($meta->replayId)->toBe('c37e209ec7e54a269e303bcae94e043b')
        ->and($meta->segmentId)->toBe(0)
        ->and($meta->replayType)->toBe(ReplayType::Session)
        ->and($meta->replayStartedAt)->toBe(1794903627.8003001)
        ->and($meta->urls)->toBe(['http://localhost:8765/'])
        ->and($meta->environment)->toBe('capture')
        ->and($meta->release)->toBe('replay-demo@1.0.0')
        ->and($meta->platform)->toBe('javascript')
        ->and($meta->sdkName)->toBe('sentry.javascript.browser')
        ->and($meta->sdkVersion)->toBe('11.1.0')
        ->and($meta->user)->toBe(['id' => '42', 'email' => 'demo@example.test'])
        ->and($meta->requestUrl)->toBe('http://localhost:8765/')
        ->and($meta->userAgent)->toContain('Mozilla/5.0');
});

it('reads a buffer-mode segment and the error ids a later segment carries', function () {
    expect(ReplayEventMeta::fromPayload(replayEventPayload('buffer-seg1'))->replayType)->toBe(ReplayType::Buffer)
        ->and(ReplayEventMeta::fromPayload(replayEventPayload('session-seg3-with-error-id'))->errorIds)->not->toBeEmpty();
});

it('rejects a segment without a valid id, segment number or type', function (array $override) {
    expect(ReplayEventMeta::fromPayload(array_replace(replayEventPayload('session-seg0'), $override)))->toBeNull();
})->with([
    'short id' => [['replay_id' => 'abc']],
    'missing segment' => [['segment_id' => null]],
    'string segment' => [['segment_id' => '0']],
    'unknown type' => [['replay_type' => 'video']],
]);

it('normalises a dashed uppercase replay id', function () {
    $payload = array_replace(replayEventPayload('session-seg0'), ['replay_id' => 'C37E209E-C7E5-4A26-9E30-3BCAE94E043B']);

    expect(ReplayEventMeta::fromPayload($payload)->replayId)->toBe('c37e209ec7e54a269e303bcae94e043b');
});

it('caps lists and drops what a replay never needs', function () {
    $payload = array_replace(replayEventPayload('session-seg0'), [
        'urls' => array_map(fn (int $i): string => "https://app.test/page/{$i}", range(1, 150)),
        'error_ids' => [...array_fill(0, 3, str_repeat('a', 32)), 'not-an-id'],
        'user' => ['id' => 7, 'email' => 'a@b.test', 'ip_address' => '{{auto}}', 'segment' => 'vip'],
    ]);

    $meta = ReplayEventMeta::fromPayload($payload);

    expect($meta->urls)->toHaveCount(ReplayEventMeta::MAX_URLS)
        ->and($meta->errorIds)->toBe([str_repeat('a', 32)])
        ->and($meta->user)->toBe(['id' => '7', 'email' => 'a@b.test']);
});
