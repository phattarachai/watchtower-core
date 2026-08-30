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

function makeQueryException(string $value, string $file = '/app/Http/Controllers/CalendarController.php', int $line = 27): array
{
    return makeEvent([
        'exception' => ['values' => [[
            'type' => 'Illuminate\\Database\\QueryException',
            'value' => $value,
            'stacktrace' => ['frames' => [
                ['filename' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'function' => 'runQueryCallback', 'lineno' => 830, 'in_app' => false],
                ['filename' => $file, 'function' => 'App\\Http\\Controllers\\CalendarController::show', 'lineno' => $line, 'in_app' => true],
            ]],
        ]]],
    ]);
}

it('groups QueryExceptions that differ only by the inline date literal', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $may = makeQueryException('SQLSTATE[22008]: Datetime field overflow: 7 ERROR:  date/time field value out of range: "0000-05-31"'."\n".'CONTEXT:  unnamed portal parameter $1 (Connection: pgsql, Host: 127.0.0.1, Database: swu_fofa_db, SQL: select * from "calendar_events" where "start_date" <= 0000-05-31 and COALESCE(end_date, start_date) >= 0000-05-01)');
    $jul = makeQueryException('SQLSTATE[22008]: Datetime field overflow: 7 ERROR:  date/time field value out of range: "0000-07-31"'."\n".'CONTEXT:  unnamed portal parameter $1 (Connection: pgsql, Host: 127.0.0.1, Database: swu_fofa_db, SQL: select * from "calendar_events" where "start_date" <= 0000-07-31 and COALESCE(end_date, start_date) >= 0000-07-01)');

    expect($fp->compute($may))->toBe($fp->compute($jul));
});

it('separates QueryExceptions with different SQLSTATE codes at the same call site', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $overflow = makeQueryException('SQLSTATE[22008]: Datetime field overflow: 7 ERROR:  date/time field value out of range: "0000-05-31" (Connection: pgsql, SQL: select * from "calendar_events")');
    $badColumn = makeQueryException('SQLSTATE[42703]: Undefined column: 7 ERROR:  column "foo" does not exist (Connection: pgsql, SQL: select * from "calendar_events")');

    expect($fp->compute($overflow))->not->toBe($fp->compute($badColumn));
});

it('separates the same SQLSTATE code raised from different call sites', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $calendar = makeQueryException('SQLSTATE[22008]: Datetime field overflow (Connection: pgsql, SQL: select 1)', '/app/Http/Controllers/CalendarController.php', 27);
    $report = makeQueryException('SQLSTATE[22008]: Datetime field overflow (Connection: pgsql, SQL: select 1)', '/app/Http/Controllers/ReportController.php', 88);

    expect($fp->compute($calendar))->not->toBe($fp->compute($report));
});

it('keeps a clean title with the real SQLSTATE code and no inline values', function () {
    $normalizer = new MessageNormalizer;

    $title = $normalizer->normalizeSql('SQLSTATE[22008]: Datetime field overflow: 7 ERROR:  date/time field value out of range: "row"'."\n".'CONTEXT:  unnamed portal parameter $1 (Connection: pgsql, Host: 127.0.0.1, Database: swu_fofa_db, SQL: select * from "calendar_events" where "start_date" <= 0000-05-31 and COALESCE(end_date, start_date) >= 0000-05-01)');

    expect($title)->toBe('SQLSTATE[22008]: Datetime field overflow: 7 ERROR:  date/time field value out of range: "row"');
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

function makeBrowserEvent(string $value, string $filename, int $lineno, string $type = 'ReferenceError'): array
{
    // Mirror real @sentry/browser shape: the error fires in an inline <script>, so
    // the frame "filename" is the visited page URL, the function is anonymous ("?"),
    // and lineno is the script's offset within that page's HTML.
    return [
        'level' => 'error',
        'platform' => 'javascript',
        'exception' => ['values' => [[
            'type' => $type,
            'value' => $value,
            'stacktrace' => ['frames' => [
                ['filename' => $filename, 'function' => '?', 'lineno' => $lineno, 'colno' => 28, 'in_app' => true],
            ]],
        ]]],
    ];
}

it('groups browser errors regardless of page URL and inline-script line number', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeBrowserEvent('Sticksy is not defined', 'https://site.com/categories/dull-skin', 543);
    $b = makeBrowserEvent('Sticksy is not defined', 'https://site.com/account/?ok=Profile%20saved.', 528);
    $c = makeBrowserEvent('Sticksy is not defined', 'https://site.com/products?gad_source=1&gclid=xyz123', 536);

    expect($fp->compute($a))->toBe($fp->compute($b))
        ->and($fp->compute($b))->toBe($fp->compute($c));
});

it('separates browser errors with different messages', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeBrowserEvent('Sticksy is not defined', 'https://site.com/a', 10);
    $b = makeBrowserEvent('Swiper is not defined', 'https://site.com/a', 10);

    expect($fp->compute($a))->not->toBe($fp->compute($b));
});

it('separates browser errors with different exception classes', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    $a = makeBrowserEvent('boom', 'https://site.com/a', 10, 'TypeError');
    $b = makeBrowserEvent('boom', 'https://site.com/a', 10, 'ReferenceError');

    expect($fp->compute($a))->not->toBe($fp->compute($b));
});

it('keeps server-side errors separated by frame location', function () {
    $fp = new Fingerprinter(new MessageNormalizer);

    // Same class + message, different file/line — server errors must NOT collapse
    // the way browser errors do (frame coordinates are stable for PHP).
    $base = fn (string $file, int $line): array => [
        'level' => 'error',
        'platform' => 'php',
        'exception' => ['values' => [[
            'type' => 'RuntimeException',
            'value' => 'something failed',
            'stacktrace' => ['frames' => [
                ['filename' => $file, 'function' => 'handle', 'lineno' => $line, 'in_app' => true],
            ]],
        ]]],
    ];

    expect($fp->compute($base('app/Services/UserLookup.php', 23)))
        ->not->toBe($fp->compute($base('app/Services/OrderLookup.php', 99)));
});

it('leaves Class::method() unchanged for non-engine exceptions', function () {
    $normalizer = new MessageNormalizer;

    // We only collapse method names when the message is an engine error. A regular
    // RuntimeException mentioning a method should pass through normalize() unchanged.
    $msg = 'Call to undefined method App\\Models\\User::nonexistent()';

    expect($normalizer->normalizePhpError($msg))->toBe($msg)
        ->and($normalizer->normalize($msg))->toBe($msg);
});
