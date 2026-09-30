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

it('scrubs sensitive keys inside breadcrumb data', function () {
    $scrubbed = makeScrubber()->scrub(['breadcrumbs' => ['values' => [
        ['category' => 'http', 'data' => ['url' => '/login', 'password' => 'hunter2']],
        ['category' => 'log', 'message' => 'no data'],
    ]]]);

    expect($scrubbed['breadcrumbs']['values'][0]['data'])->toBe(['url' => '/login', 'password' => '[Filtered]'])
        ->and($scrubbed['breadcrumbs']['values'][1])->toBe(['category' => 'log', 'message' => 'no data']);
});

function sqlScrubber(): EventScrubber
{
    return new EventScrubber([], ['password'], redactSqlValues: true);
}

/** @return array<string, mixed> */
function queryExceptionEvent(string $message): array
{
    return ['exception' => ['values' => [['type' => 'Illuminate\\Database\\UniqueConstraintViolationException', 'value' => $message]]]];
}

it('drops the SQL tail Laravel inlines bindings into', function () {
    $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.test' for key 'users.users_email_unique' "
        .'(Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: app, SQL: insert into `users` (`email`, `name`) values (a@b.test, Alice (admin)))';

    $value = sqlScrubber()->scrub(queryExceptionEvent($message))['exception']['values'][0]['value'];

    expect($value)->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '[Filtered]' for key 'users.users_email_unique' "
        .'(Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: app, SQL: [Filtered])');
});

it('redacts the row value in Postgres key and parameter details', function () {
    $message = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\n"
        ."DETAIL:  Key (email)=(a@b.test) already exists.\nCONTEXT:  unnamed portal parameter \$1 = 'a@b.test' (Connection: pgsql, SQL: insert into users (email) values (a@b.test))";

    $value = sqlScrubber()->scrub(queryExceptionEvent($message))['exception']['values'][0]['value'];

    expect($value)->not->toContain('a@b.test')
        ->and($value)->toContain('Key (email)=([Filtered]) already exists')
        ->and($value)->toContain('users_email_unique');
});

it('drops bindings from query breadcrumbs when redacting SQL', function () {
    $crumb = ['category' => 'db.sql.query', 'message' => 'insert into users (email) values (?)', 'data' => ['bindings' => ['a@b.test'], 'connectionName' => 'mysql']];

    expect(sqlScrubber()->scrub(['breadcrumbs' => [$crumb]])['breadcrumbs'][0]['data'])->toBe(['connectionName' => 'mysql']);
});

it('leaves SQL alone by default and non-SQL messages always', function () {
    $message = "SQLSTATE[23000]: Duplicate entry 'x' for key 'k' (Connection: mysql, SQL: insert into t values (x))";

    expect(makeScrubber()->scrub(queryExceptionEvent($message))['exception']['values'][0]['value'])->toBe($message)
        ->and(sqlScrubber()->scrub(queryExceptionEvent("Duplicate entry 'x'"))['exception']['values'][0]['value'])->toBe("Duplicate entry 'x'");
});

it('redacts SQL idempotently', function () {
    $once = sqlScrubber()->scrub(queryExceptionEvent("SQLSTATE[23000]: Duplicate entry 'x' for key 'k' (Connection: mysql, SQL: insert into t values (x))"));

    expect(sqlScrubber()->scrub($once))->toBe($once);
});
