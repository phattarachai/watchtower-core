<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Replay;

/**
 * The metadata half of a replay segment (`replay_event`). It is not an error
 * event: no pipeline, no fingerprint. Only the fields a replay list and player
 * need are kept, each capped; `trace_ids` and `user.ip_address` are dropped.
 * Timestamps stay epoch seconds — the host converts them into its own zone.
 */
final readonly class ReplayEventMeta
{
    public const int MAX_URLS = 100;

    public const int MAX_ERROR_IDS = 100;

    public const int MAX_URL_BYTES = 2_048;

    public const int MAX_STRING_BYTES = 200;

    /**
     * @param  list<string>  $urls
     * @param  list<string>  $errorIds
     * @param  array{id?: string, email?: string, username?: string}  $user
     */
    public function __construct(
        public string $replayId,
        public int $segmentId,
        public ReplayType $replayType,
        public ?float $replayStartedAt,
        public ?float $timestamp,
        public array $urls,
        public array $errorIds,
        public ?string $environment,
        public ?string $release,
        public ?string $platform,
        public ?string $sdkName,
        public ?string $sdkVersion,
        public array $user,
        public ?string $requestUrl,
        public ?string $userAgent,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  a decoded `replay_event` item payload
     * @return self|null null when the replay id, segment id or replay type is missing or malformed
     */
    public static function fromPayload(array $payload): ?self
    {
        $replayId = self::hexId($payload['replay_id'] ?? null);
        $segmentId = $payload['segment_id'] ?? null;
        $replayType = is_string($payload['replay_type'] ?? null) ? ReplayType::tryFrom($payload['replay_type']) : null;

        if ($replayId === null || ! is_int($segmentId) || $segmentId < 0 || $replayType === null) {
            return null;
        }

        $sdk = is_array($payload['sdk'] ?? null) ? $payload['sdk'] : [];
        $request = is_array($payload['request'] ?? null) ? $payload['request'] : [];
        $headers = is_array($request['headers'] ?? null) ? array_change_key_case($request['headers']) : [];

        return new self(
            replayId: $replayId,
            segmentId: $segmentId,
            replayType: $replayType,
            replayStartedAt: self::epoch($payload['replay_start_timestamp'] ?? null),
            timestamp: self::epoch($payload['timestamp'] ?? null),
            urls: self::urls($payload['urls'] ?? null),
            errorIds: self::errorIds($payload['error_ids'] ?? null),
            environment: self::string($payload['environment'] ?? null),
            release: self::string($payload['release'] ?? null),
            platform: self::string($payload['platform'] ?? null),
            sdkName: self::string($sdk['name'] ?? null),
            sdkVersion: self::string($sdk['version'] ?? null),
            user: self::user($payload['user'] ?? null),
            requestUrl: self::string($request['url'] ?? null, self::MAX_URL_BYTES),
            userAgent: self::string($headers['user-agent'] ?? null, 512),
        );
    }

    private static function hexId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $id = strtolower(str_replace('-', '', $value));

        return preg_match('/\A[0-9a-f]{32}\z/', $id) === 1 ? $id : null;
    }

    private static function epoch(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function string(mixed $value, int $maxBytes = self::MAX_STRING_BYTES): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $string = trim(str_replace("\0", '', (string) $value));

        return $string === '' ? null : mb_strcut($string, 0, $maxBytes);
    }

    /**
     * @return list<string>
     */
    private static function urls(mixed $value): array
    {
        $urls = array_map(fn (mixed $url): ?string => self::string($url, self::MAX_URL_BYTES), is_array($value) ? $value : []);

        return array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_URLS);
    }

    /**
     * @return list<string>
     */
    private static function errorIds(mixed $value): array
    {
        $ids = array_map(self::hexId(...), is_array($value) ? $value : []);

        return array_slice(array_values(array_unique(array_filter($ids))), 0, self::MAX_ERROR_IDS);
    }

    /**
     * @return array{id?: string, email?: string, username?: string}
     */
    private static function user(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fields = array_map(self::string(...), array_intersect_key($value, array_flip(['id', 'email', 'username'])));

        return array_filter($fields, fn (?string $field): bool => $field !== null);
    }
}
