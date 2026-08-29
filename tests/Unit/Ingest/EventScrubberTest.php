<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\EventScrubber;

function makeScrubber(): EventScrubber
{
    return new EventScrubber(
        ['cookie', 'authorization', 'x-csrf-token', 'x-api-key', 'x-auth-token'],
        ['password', 'pwd', 'passwd', 'token', 'secret', 'api_key', 'access_token', 'refresh_token', 'authorization'],
    );
}

it('redacts sensitive headers and body keys', function () {
    $event = [
        'request' => [
            'headers' => [
                'Cookie' => 'session=secret',
                'Authorization' => 'Bearer xxx',
                'User-Agent' => 'curl/8',
            ],
            'data' => [
                'username' => 'alice',
                'password' => 'hunter2',
                'nested' => ['secret' => 'shhh'],
            ],
        ],
        'extra' => ['api_key' => 'k', 'debug' => true],
    ];

    $scrubbed = makeScrubber()->scrub($event);

    expect($scrubbed['request']['headers']['Cookie'])->toBe('[Filtered]')
        ->and($scrubbed['request']['headers']['Authorization'])->toBe('[Filtered]')
        ->and($scrubbed['request']['headers']['User-Agent'])->toBe('curl/8')
        ->and($scrubbed['request']['data']['password'])->toBe('[Filtered]')
        ->and($scrubbed['request']['data']['nested']['secret'])->toBe('[Filtered]')
        ->and($scrubbed['extra']['api_key'])->toBe('[Filtered]')
        ->and($scrubbed['extra']['debug'])->toBeTrue();
});

it('uses a custom placeholder when configured', function () {
    $scrubber = new EventScrubber(['x-api-key'], ['token'], '***');

    $scrubbed = $scrubber->scrub([
        'request' => ['headers' => ['X-Api-Key' => 'k'], 'data' => ['token' => 't']],
    ]);

    expect($scrubbed['request']['headers']['X-Api-Key'])->toBe('***')
        ->and($scrubbed['request']['data']['token'])->toBe('***');
});
