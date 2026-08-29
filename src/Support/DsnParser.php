<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Support;

/**
 * Parses a Sentry-shaped DSN: `scheme://public_key@host[:port]/<numeric project id>`.
 *
 * @phpstan-type ParsedDsn array{
 *     scheme: string,
 *     host_with_port: string,
 *     host: string,
 *     port: int|null,
 *     public_key: string,
 *     project_id: int,
 *     relay_url: string
 * }
 */
final class DsnParser
{
    public const string DEFAULT_RELAY_PATH = '/api/watchtower-relay';

    /**
     * @return ParsedDsn|null
     */
    public static function parse(mixed $dsn, string $relayPath = self::DEFAULT_RELAY_PATH): ?array
    {
        if (! is_string($dsn) || $dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : null;
        $host = isset($parts['host']) ? (string) $parts['host'] : null;
        $publicKey = isset($parts['user']) ? (string) $parts['user'] : '';
        $path = ltrim((string) ($parts['path'] ?? ''), '/');

        if ($scheme === null || $host === null || $publicKey === '' || ! ctype_digit($path)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $hostWithPort = $port === null ? $host : "{$host}:{$port}";

        return [
            'scheme' => $scheme,
            'host_with_port' => $hostWithPort,
            'host' => $host,
            'port' => $port,
            'public_key' => $publicKey,
            'project_id' => (int) $path,
            'relay_url' => "{$scheme}://{$hostWithPort}{$relayPath}",
        ];
    }
}
