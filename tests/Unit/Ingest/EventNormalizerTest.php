<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\EventNormalizer;

function makeNormalizer(): EventNormalizer
{
    return new EventNormalizer(
        [
            'event_id', 'timestamp', 'platform', 'level', 'logger',
            'transaction', 'server_name', 'release', 'environment',
            'message', 'exception', 'request', 'user', 'contexts',
            'tags', 'extra', 'breadcrumbs', 'sdk', 'fingerprint',
        ],
        [
            'os', 'runtime', 'browser', 'device',
            'laravel', 'livewire', 'job', 'trace',
        ],
    );
}

it('strips disallowed top-level fields', function () {
    $event = [
        'event_id' => 'a', 'message' => 'hello',
        'modules' => ['composer/foo' => '1.0'],   // disallowed
        'spans' => [['op' => 'db']],              // disallowed
        'replay_id' => 'rep',                     // disallowed
        'debug_meta' => ['foo' => 'bar'],         // disallowed
    ];

    $kept = makeNormalizer()->normalize($event);

    expect($kept)->toHaveKeys(['event_id', 'message'])
        ->and($kept)->not->toHaveKeys(['modules', 'spans', 'replay_id', 'debug_meta']);
});

it('strips NUL bytes from string values so jsonb inserts do not fail', function () {
    $event = [
        'message' => "ok\0bad",
        'exception' => [
            'values' => [[
                'type' => 'RuntimeException',
                'value' => "value with NUL\0 inside",
                'stacktrace' => ['frames' => [
                    ['function' => "Livewire\\Component@anonymous\0/path/file.php:23\$abc"],
                ]],
            ]],
        ],
    ];

    $kept = makeNormalizer()->normalize($event);

    expect($kept['message'])->toBe('okbad')
        ->and($kept['exception']['values'][0]['value'])->toBe('value with NUL inside')
        ->and($kept['exception']['values'][0]['stacktrace']['frames'][0]['function'])
        ->toBe('Livewire\\Component@anonymous/path/file.php:23$abc');
});

it('keeps only allow-listed contexts', function () {
    $event = [
        'contexts' => [
            'os' => ['name' => 'macOS'],
            'runtime' => ['name' => 'php', 'version' => '8.5'],
            'profile' => ['profile_id' => 'p'],   // disallowed
            'feature_flags' => ['x' => true],     // disallowed
        ],
    ];

    $kept = makeNormalizer()->normalize($event);

    expect($kept['contexts'])->toHaveKeys(['os', 'runtime'])
        ->and($kept['contexts'])->not->toHaveKeys(['profile', 'feature_flags']);
});
