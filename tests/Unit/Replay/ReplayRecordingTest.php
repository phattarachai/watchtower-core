<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Replay\ReplayEncoding;
use Phattarachai\WatchtowerCore\Replay\ReplayRecording;
use Phattarachai\WatchtowerCore\Tests\Support\ReplayFixture;

it('hands the binary recording payload back as a string', function () {
    $items = ReplayFixture::items('session-seg0');

    expect(array_keys($items))->toBe(['replay_event', 'replay_recording'])
        ->and($items['replay_event']->payload)->toBeArray()
        ->and($items['replay_recording']->payload)->toBeString();
});

it('splits a real SDK recording into its segment id and zlib body', function () {
    $item = ReplayFixture::items('session-seg1')['replay_recording'];

    $recording = ReplayRecording::fromPayload($item->payload);

    expect($recording->segmentId)->toBe(1)
        ->and($recording->encoding)->toBe(ReplayEncoding::Zlib)
        ->and($recording->bytes())->toBe($item->headers['length'] - strlen("{\"segment_id\":1}\n"));
});

it('keeps the body verbatim so it inflates to the rrweb events', function () {
    $recording = ReplayRecording::fromPayload(ReplayFixture::items('buffer-seg0')['replay_recording']->payload);

    $events = json_decode(zlib_decode($recording->body), true);
    $expected = json_decode(ReplayFixture::contents('buffer-seg0.recording.json'), true);

    expect($events)->toBe($expected)
        ->and(array_column($events, 'type'))->toContain(2, 4);
});

it('recognises gzip and plain JSON bodies', function (string $body, ReplayEncoding $encoding) {
    expect(ReplayRecording::fromPayload("{\"segment_id\":0}\n{$body}")->encoding)->toBe($encoding);
})->with([
    'gzip' => [gzencode('[{"type":4}]'), ReplayEncoding::Gzip],
    'plain' => ['[{"type":4,"timestamp":1}]', ReplayEncoding::None],
    'unknown' => ['not a recording', ReplayEncoding::Unknown],
]);

it('rejects a recording without a usable segment header or body', function (array|string $payload) {
    expect(ReplayRecording::fromPayload($payload))->toBeNull();
})->with([
    'no newline' => ['{"segment_id":0}'],
    'empty body' => ["{\"segment_id\":0}\n"],
    'bad header' => ["nope\n[]"],
    'negative segment' => ["{\"segment_id\":-1}\n[]"],
    'string segment' => ["{\"segment_id\":\"1\"}\n[]"],
    'decoded array' => [['segment_id' => 0]],
]);
