<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Replay;

use JsonException;

/**
 * A `replay_recording` envelope item: a `{"segment_id":N}` JSON line followed by
 * the rrweb event body, compressed or plain. The body is kept as received —
 * callers store it verbatim and let the player inflate it.
 */
final readonly class ReplayRecording
{
    public function __construct(
        public int $segmentId,
        public ReplayEncoding $encoding,
        public string $body,
    ) {}

    /**
     * @param  array<string, mixed>|string  $payload  the item payload as `EnvelopeParser` returns it
     */
    public static function fromPayload(array|string $payload): ?self
    {
        if (! is_string($payload)) {
            return null;
        }

        $newline = strpos($payload, "\n");
        if ($newline === false) {
            return null;
        }

        $segmentId = self::segmentIdFrom(substr($payload, 0, $newline));
        $body = substr($payload, $newline + 1);

        if ($segmentId === null || $body === '') {
            return null;
        }

        return new self($segmentId, ReplayEncoding::sniff($body), $body);
    }

    public function bytes(): int
    {
        return strlen($this->body);
    }

    private static function segmentIdFrom(string $headerLine): ?int
    {
        try {
            $header = json_decode($headerLine, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $segmentId = is_array($header) ? ($header['segment_id'] ?? null) : null;

        return is_int($segmentId) && $segmentId >= 0 ? $segmentId : null;
    }
}
