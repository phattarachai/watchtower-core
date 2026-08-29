<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Support\DsnParser;

it('parses a DSN into its parts', function () {
    $parsed = DsnParser::parse('https://abc123@watchtower.test/42');

    expect($parsed)->toBe([
        'scheme' => 'https',
        'host_with_port' => 'watchtower.test',
        'host' => 'watchtower.test',
        'port' => null,
        'public_key' => 'abc123',
        'project_id' => 42,
        'relay_url' => 'https://watchtower.test/api/watchtower-relay',
    ]);
});

it('keeps an explicit port in the host and relay url', function () {
    $parsed = DsnParser::parse('http://abc123@localhost:8000/7');

    expect($parsed['port'])->toBe(8000)
        ->and($parsed['host_with_port'])->toBe('localhost:8000')
        ->and($parsed['relay_url'])->toBe('http://localhost:8000/api/watchtower-relay');
});

it('honours a custom relay path', function () {
    $parsed = DsnParser::parse('https://abc123@watchtower.test/42', '/relay');

    expect($parsed['relay_url'])->toBe('https://watchtower.test/relay');
});

it('rejects DSNs that are unusable for ingest', function (mixed $dsn) {
    expect(DsnParser::parse($dsn))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'non-string' => [42],
    'no public key' => ['https://watchtower.test/42'],
    'non-numeric project id' => ['https://abc123@watchtower.test/some-slug'],
    'no project id' => ['https://abc123@watchtower.test'],
    'no scheme or host' => ['abc123@/42'],
]);
