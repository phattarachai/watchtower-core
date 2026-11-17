<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Replay;

enum ReplayEncoding: string
{
    case Zlib = 'zlib';
    case Gzip = 'gzip';
    case None = 'none';
    case Unknown = 'unknown';

    /**
     * Identify a recording body by its leading bytes. The browser SDK sends zlib
     * (`78 9c`); the protocol also allows gzip and plain JSON.
     */
    public static function sniff(string $body): self
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            return self::Gzip;
        }

        if (self::isZlibHeader($body)) {
            return self::Zlib;
        }

        if (str_starts_with(ltrim(substr($body, 0, 64)), '[')) {
            return self::None;
        }

        return self::Unknown;
    }

    private static function isZlibHeader(string $body): bool
    {
        if (strlen($body) < 2) {
            return false;
        }

        $compressionMethod = ord($body[0]);
        $flags = ord($body[1]);

        return ($compressionMethod & 0x0F) === 8 && (($compressionMethod << 8) | $flags) % 31 === 0;
    }
}
