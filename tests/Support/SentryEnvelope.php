<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Tests\Support;

final class SentryEnvelope
{
    /**
     * Build a valid Sentry envelope v7 body containing one `event` item
     * (and optionally extra item types the ingest side is expected to drop).
     *
     * @param  array<string, mixed>  $eventOverrides  merged into the default event payload
     * @param  list<array{type:string, payload:array<string,mixed>}>  $extraItems
     * @param  array<string, mixed>  $envelopeHeaderOverrides  merged into the envelope header
     */
    public static function build(array $eventOverrides = [], array $extraItems = [], array $envelopeHeaderOverrides = []): string
    {
        $event = array_replace_recursive([
            'event_id' => str_repeat('a', 32),
            'timestamp' => '2026-01-01T00:00:00+00:00',
            'platform' => 'php',
            'level' => 'error',
            'environment' => 'testing',
            'release' => '1.0.0',
            'sdk' => ['name' => 'sentry.php.laravel', 'version' => '4.0.0'],
            'exception' => [
                'values' => [
                    [
                        'type' => 'RuntimeException',
                        'value' => 'Something exploded',
                        'stacktrace' => [
                            'frames' => [
                                ['filename' => 'vendor/laravel/framework/src/Foundation/run.php', 'function' => 'fire', 'lineno' => 12, 'in_app' => false],
                                ['filename' => 'app/Http/Controllers/HomeController.php', 'function' => 'index', 'lineno' => 42, 'in_app' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ], $eventOverrides);

        $envelopeHeader = array_replace([
            'event_id' => $event['event_id'],
            'sent_at' => '2026-01-01T00:00:00+00:00',
            'sdk' => $event['sdk'],
        ], $envelopeHeaderOverrides);

        $lines = [json_encode($envelopeHeader, JSON_THROW_ON_ERROR)];
        $lines[] = self::itemHeader('event', $event);
        $lines[] = json_encode($event, JSON_THROW_ON_ERROR);

        foreach ($extraItems as $extra) {
            $lines[] = self::itemHeader($extra['type'], $extra['payload']);
            $lines[] = json_encode($extra['payload'], JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function itemHeader(string $type, array $payload): string
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        return json_encode([
            'type' => $type,
            'length' => strlen($encoded),
            'content_type' => 'application/json',
        ], JSON_THROW_ON_ERROR);
    }
}
