# Watchtower Core

Framework-agnostic building blocks shared by the [Watchtower](https://github.com/phattarachai/watchtower) server and its
client packages. Pure PHP — no Laravel container, no service provider, nothing to register.

## Install

```bash
composer require phattarachai/watchtower-core
```

## What's inside

| Class | Purpose |
| --- | --- |
| `Sentry\AuthHeader` | Parses the `X-Sentry-Auth` header into version / key / client / secret. |
| `Sentry\EnvelopeParser` | Parses a Sentry envelope v7 body into a header plus `EnvelopeItem`s. |
| `Sentry\EnvelopeItem` | One envelope item: raw headers plus a decoded array or raw string payload. |
| `Ingest\MessageNormalizer` | Collapses the variable parts of exception, PHP engine error and SQL messages. |
| `Ingest\Fingerprinter` | Computes the stable issue-group fingerprint and title for an event payload. |
| `Ingest\EventScrubber` | Redacts secret headers and keys in request, `extra`, `tags` and breadcrumb `data`; optionally strips row values out of SQL error messages (`redactSqlValues: true`). |
| `Ingest\EventNormalizer` | Keeps only allow-listed top-level fields and context keys; strips NUL bytes. |
| `Ingest\EventTruncator` | Trims an event to a JSON byte budget, Sentry-style: caps strings, keeps the last 100 breadcrumbs, then drops breadcrumb data, `extra`, the request body, frame locals and deep-stack middles until it fits. Tags a trimmed event `watchtower.truncated`. |
| `Ingest\EventPipeline` | scrub → normalize → truncate, plus the fingerprint and title. Run it **before** queueing an event so secrets and unbounded payloads never reach the queue backend; it is idempotent, so the worker can run it again. |
| `Alerts\LevelRanker` | Compares Sentry severity levels against a minimum threshold. |
| `Support\Gzip` | Decodes a request body that may carry `Content-Encoding: gzip`. Pass `$maxBytes` to cap the inflated size — wire-size limits only see the compressed body. |
| `Support\DsnParser` | Parses `scheme://public_key@host[:port]/<project id>` into its parts plus a relay URL. |

## Ingest example

```php
use Phattarachai\WatchtowerCore\Ingest\{EventNormalizer, EventPipeline, EventScrubber, EventTruncator};
use Phattarachai\WatchtowerCore\Support\Gzip;

$body = Gzip::decodeBody($rawBody, $contentEncoding, maxBytes: 20 * 1_048_576);

$pipeline = new EventPipeline(
    new EventScrubber($headerKeys, $bodyKeys, redactSqlValues: true),
    new EventNormalizer($allowedEventFields, $allowedContextKeys),
    new EventTruncator(maxEventBytes: 200_000, maxStringBytes: 8_192),
);

$event = $pipeline->prepare($payload);      // queue this, not $payload
$fingerprint = $pipeline->fingerprint($event);
```

## Testing

```bash
composer test
```

## License

MIT
