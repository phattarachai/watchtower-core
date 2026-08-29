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
| `Alerts\LevelRanker` | Compares Sentry severity levels against a minimum threshold. |
| `Support\Gzip` | Decodes a request body that may carry `Content-Encoding: gzip`. |
| `Support\DsnParser` | Parses `scheme://public_key@host[:port]/<project id>` into its parts plus a relay URL. |

## Testing

```bash
composer test
```

## License

MIT
