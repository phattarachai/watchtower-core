<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Support;

final class Gzip
{
    private const string MAGIC = "\x1f\x8b";

    /**
     * Decode a request body that may carry `Content-Encoding: gzip`.
     *
     * Falls back to the raw body when the encoding says otherwise or the body
     * is not gzip at all, so a mislabelled body still reaches the parser.
     *
     * Size limits on the wire only see the compressed body — a 5 KB gzip body
     * can inflate to 5 MB. Pass `$maxBytes` to cap the inflated size: a gzip body
     * that would exceed it (or is corrupt) decodes to an empty string, which
     * parses as an envelope with no items.
     */
    public static function decodeBody(string $body, ?string $contentEncoding, ?int $maxBytes = null): string
    {
        if (strtolower((string) $contentEncoding) !== 'gzip') {
            return $body;
        }

        if (! str_starts_with($body, self::MAGIC)) {
            return $body;
        }

        if ($maxBytes !== null && $maxBytes > 0) {
            return self::inflate($body, $maxBytes) ?? '';
        }

        return self::inflate($body, 0) ?? $body;
    }

    /**
     * gzdecode() warns on corrupt or oversized input; both are expected here.
     */
    private static function inflate(string $body, int $maxBytes): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $decoded = gzdecode($body, $maxBytes);
        } finally {
            restore_error_handler();
        }

        return $decoded === false ? null : $decoded;
    }
}
