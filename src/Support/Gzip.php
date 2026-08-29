<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Support;

final class Gzip
{
    private const string MAGIC = "\x1f\x8b";

    /**
     * Decode a request body that may carry `Content-Encoding: gzip`.
     *
     * Falls back to the raw body when the encoding says otherwise or the payload
     * fails to inflate, so a mislabelled body still reaches the parser.
     */
    public static function decodeBody(string $body, ?string $contentEncoding): string
    {
        if (strtolower((string) $contentEncoding) !== 'gzip') {
            return $body;
        }

        if (! str_starts_with($body, self::MAGIC)) {
            return $body;
        }

        $decoded = @gzdecode($body);

        return $decoded === false ? $body : $decoded;
    }
}
