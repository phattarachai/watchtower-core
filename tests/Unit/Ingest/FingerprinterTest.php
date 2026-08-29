<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\Fingerprinter;
use Phattarachai\WatchtowerCore\Ingest\MessageNormalizer;

function makeEvent(array $overrides = []): array
{
    return array_replace_recursive([
        'level' => 'error',
        'platform' => 'php',
        'exception' => [
            'values' => [[
                'type' => 'App\\Exceptions\\UserNotFound',
                'value' => 'User abc123def4567890 not found',
                'stacktrace' => ['frames' => [
                    ['filename' => 'vendor/laravel/framework/src/X.php', 'function' => 'handle', 'lineno' => 5, 'in_app' => false],
                    ['filename' => '/Users/me/app/app/Services/UserLookup.php', 'function' => 'find', 'lineno' => 23, 'in_app' => true],
                ]],
            ]],
        ],
    ], $overrides);
}

it('groups events that differ only by dynamic IDs', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeEvent();
    $b = makeEvent(['exception' => ['values' => [[
        'value' => 'User 8a8a8a8a8a8a not found',
    ]]]]);

    expect($fp->compute($a))->toBe($fp->compute($b));
});

it('separates events from different exception classes', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeEvent();
    $b = makeEvent(['exception' => ['values' => [['type' => 'OtherException']]]]);

    expect($fp->compute($a))->not->toBe($fp->compute($b));
});

it('respects fingerprint override from SDK', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeEvent(['fingerprint' => ['custom-bucket']]);
    $b = makeEvent(['fingerprint' => ['custom-bucket']]);

    expect($fp->compute($a))->toBe($fp->compute($b));
});

it('skips vendor frames when picking the top app frame', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeEvent();
    $b = makeEvent(['exception' => ['values' => [[
        'stacktrace' => ['frames' => [
            ['filename' => 'vendor/laravel/framework/src/Y.php', 'function' => 'fire', 'lineno' => 99, 'in_app' => false],
            ['filename' => '/Users/me/app/app/Services/UserLookup.php', 'function' => 'find', 'lineno' => 23, 'in_app' => true],
        ]],
    ]]]]);

    expect($fp->compute($a))->toBe($fp->compute($b));
});

it('normalizes SQL bind values for QueryException messages', function () {
    $normalizer = new MessageNormalizer;

    $msg = 'SQLSTATE[42S22]: ...: select * from "users" where "id" = ? (Connection: pgsql, Bindings: [123])';
    $other = 'SQLSTATE[42S22]: ...: select * from "users" where "id" = ? (Connection: pgsql, Bindings: [456])';

    expect($normalizer->normalizeSql($msg))->toBe($normalizer->normalizeSql($other));
});

function makeErrorException(string $value): array
{
    // Mirror real Sentry SDK shape for engine-trapped E_DEPRECATED:
    // top frame is Sentry's handler under /vendor/, so it's not in_app.
    return makeEvent([
        'exception' => ['values' => [[
            'type' => 'ErrorException',
            'value' => $value,
            'stacktrace' => ['frames' => [
                ['filename' => '/wp-content/plugins/admin-columns-pro/admin-columns-pro.php', 'function' => 'require_once', 'lineno' => 37, 'in_app' => true],
                ['filename' => '/vendor/composer/ClassLoader.php', 'function' => 'loadClass', 'lineno' => 576, 'in_app' => true],
                ['filename' => '/vendor/sentry/sentry/src/ErrorHandler.php', 'function' => 'Sentry\\ErrorHandler::handleError', 'lineno' => 240, 'in_app' => false],
            ]],
        ]]],
    ]);
}

it('collapses ACP-style deprecation variants to one fingerprint', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $variants = [
        'Deprecated: AC\\Dependencies::add_missing_plugin(): Implicitly marking parameter $version as nullable is deprecated, the explicit nullable type must be used instead',
        'Deprecated: AC\\Asset\\Script::add_inline(): Implicitly marking parameter $position as nullable is deprecated, the explicit nullable type must be used instead',
        'Deprecated: ACP\\Search\\SegmentRepository\\File::find_all_shared(): Implicitly marking parameter $sort as nullable is deprecated, the explicit nullable type must be used instead',
        'Deprecated: ACP\\Search\\SegmentRepository::find_all_personal(): Implicitly marking parameter $list_screen_id as nullable is deprecated, the explicit nullable type must be used instead',
    ];

    $hashes = array_map(fn (string $v) => $fp->compute(makeErrorException($v)), $variants);

    expect(array_unique($hashes))->toHaveCount(1);
});

it('collapses Undefined-variable warnings across different variable names', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeErrorException('Warning: Undefined variable $image_alt');
    $b = makeErrorException('Warning: Undefined variable $page_title');

    expect($fp->compute($a))->toBe($fp->compute($b));
});

it('does not collapse PHP errors of different severity classes together', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $deprecated = makeErrorException('Deprecated: Foo\\Bar::baz(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead');
    $warning = makeErrorException('Warning: Foo\\Bar::baz(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead');

    expect($fp->compute($deprecated))->not->toBe($fp->compute($warning));
});

it('still separates a user-thrown exception that mentions Deprecated:', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    // Same words, different exception class — must stay distinct from engine errors
    // so a hand-written exception isn't lumped with PHP's auto-generated ones.
    $engine = makeErrorException('Deprecated: Foo\\Bar::baz(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead');
    $userThrown = makeEvent(['exception' => ['values' => [[
        'type' => 'RuntimeException',
        'value' => 'Deprecated: Foo\\Bar::baz() is removed',
    ]]]]);

    expect($fp->compute($engine))->not->toBe($fp->compute($userThrown));
});

it('produces a stable normalized title for ACP deprecations', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $title = $fp->title(makeErrorException(
        'Deprecated: AC\\Dependencies::add_missing_plugin(): Implicitly marking parameter $version as nullable is deprecated, the explicit nullable type must be used instead'
    ));

    expect($title)->toBe('ErrorException: Deprecated: <METHOD>(): Implicitly marking parameter $<VAR> as nullable is deprecated, the explicit nullable type must be used instead');
});

it('leaves Class::method() unchanged for non-engine exceptions', function () {
    $normalizer = new MessageNormalizer;

    // We only collapse method names when the message is an engine error. A regular
    // RuntimeException mentioning a method should pass through normalize() unchanged.
    $msg = 'Call to undefined method App\\Models\\User::nonexistent()';

    expect($normalizer->normalizePhpError($msg))->toBe($msg)
        ->and($normalizer->normalize($msg))->toBe($msg);
});
