<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Sentry\AuthHeader;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeItem;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeParser;
use Phattarachai\WatchtowerCore\Tests\Support\SentryEnvelope;

it('parses Sentry auth header', function () {
    $auth = AuthHeader::fromHeader('Sentry sentry_version=7, sentry_key=abc123, sentry_client=sentry.php.laravel/4.0.0');

    expect($auth)->not->toBeNull()
        ->and($auth->version)->toBe('7')
        ->and($auth->key)->toBe('abc123')
        ->and($auth->client)->toBe('sentry.php.laravel/4.0.0');
});

it('returns null if X-Sentry-Auth has no key', function () {
    expect(AuthHeader::fromHeader('Sentry sentry_version=7'))->toBeNull();
    expect(AuthHeader::fromHeader(''))->toBeNull();
    expect(AuthHeader::fromHeader(null))->toBeNull();
});

it('parses an envelope with one event item', function () {
    $body = SentryEnvelope::build();

    $envelope = (new EnvelopeParser)->parse($body);

    expect($envelope['items'])->toHaveCount(1)
        ->and($envelope['items'][0]->type())->toBe('event')
        ->and($envelope['items'][0]->payload)->toBeArray()
        ->and($envelope['items'][0]->payload['exception']['values'][0]['type'])->toBe('RuntimeException');
});

it('parses an envelope with mixed item types', function () {
    $body = SentryEnvelope::build(extraItems: [
        ['type' => 'transaction', 'payload' => ['type' => 'transaction', 'event_id' => 'abc']],
        ['type' => 'session', 'payload' => ['sid' => 'xyz']],
    ]);

    $envelope = (new EnvelopeParser)->parse($body);

    $types = array_map(fn (EnvelopeItem $item) => $item->type(), $envelope['items']);

    expect($envelope['items'])->toHaveCount(3)
        ->and($types)->toBe(['event', 'transaction', 'session']);
});

it('decodes gzip-compressed item payloads', function () {
    $event = ['event_id' => str_repeat('a', 32), 'message' => 'gz test'];
    $compressed = gzencode(json_encode($event, JSON_THROW_ON_ERROR));

    $itemHeader = json_encode([
        'type' => 'event',
        'length' => strlen($compressed),
        'content_type' => 'application/json',
        'content_encoding' => 'gzip',
    ], JSON_THROW_ON_ERROR);
    $envelopeHeader = json_encode(['event_id' => $event['event_id']], JSON_THROW_ON_ERROR);
    $body = $envelopeHeader."\n".$itemHeader."\n".$compressed;

    $envelope = (new EnvelopeParser)->parse($body);

    expect($envelope['items'][0]->payload['message'])->toBe('gz test');
});
