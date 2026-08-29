<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Sentry;

/**
 * Parses Sentry's X-Sentry-Auth header.
 *
 * Format: `Sentry sentry_version=7, sentry_key=abc123, sentry_client=sentry.php.laravel/4.0.0`
 */
final class AuthHeader
{
    public function __construct(
        public readonly ?string $version,
        public readonly ?string $key,
        public readonly ?string $client,
        public readonly ?string $secret = null,
    ) {}

    public static function fromHeader(?string $header): ?self
    {
        if ($header === null || $header === '') {
            return null;
        }

        $body = preg_replace('/^Sentry\s+/i', '', $header);
        if ($body === null) {
            return null;
        }

        $params = [];
        foreach (explode(',', $body) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || ! str_contains($piece, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $piece, 2);
            $params[strtolower(trim($k))] = trim($v);
        }

        if (! isset($params['sentry_key'])) {
            return null;
        }

        return new self(
            version: $params['sentry_version'] ?? null,
            key: $params['sentry_key'],
            client: $params['sentry_client'] ?? null,
            secret: $params['sentry_secret'] ?? null,
        );
    }
}
