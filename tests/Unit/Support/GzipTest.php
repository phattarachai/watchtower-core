<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Support\Gzip;

it('inflates a gzip-encoded body', function () {
    $body = 'the quick brown fox';

    expect(Gzip::decodeBody(gzencode($body), 'gzip'))->toBe($body);
});

it('accepts the content encoding case-insensitively', function () {
    $body = 'the quick brown fox';

    expect(Gzip::decodeBody(gzencode($body), 'GZIP'))->toBe($body);
});

it('returns the body untouched when the encoding is absent or not gzip', function () {
    expect(Gzip::decodeBody('plain', null))->toBe('plain')
        ->and(Gzip::decodeBody('plain', ''))->toBe('plain')
        ->and(Gzip::decodeBody('plain', 'deflate'))->toBe('plain');
});

it('falls back to the raw body when a gzip-labelled payload does not inflate', function () {
    expect(Gzip::decodeBody('not actually gzip', 'gzip'))->toBe('not actually gzip');
});
