<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

class MessageNormalizer
{
    private const string PHP_SEVERITY_PREFIX = '(?:Deprecated|Warning|Notice|Strict Standards|Fatal error|Parse error|Recoverable fatal error)';

    private const array REPLACEMENTS = [
        // UUID v4-ish
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<UUID>',
        // ISO 8601 timestamp
        '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?/' => '<TIME>',
        // Email
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '<EMAIL>',
        // Long hex hash (8+ chars)
        '/\b[0-9a-f]{8,}\b/i' => '<HASH>',
        // Path segments under storage/tmp/cache (catches Laravel temp files)
        '#/(storage|tmp|cache)/[^\s\'"]+#' => '/$1/<PATH>',
        // Quoted strings > 20 chars
        '/"([^"\\\\]|\\\\.){20,}"/' => '"<STR>"',
        "/'([^'\\\\]|\\\\.){20,}'/" => "'<STR>'",
        // Integers > 2 digits — last so it doesn't eat substrings of UUIDs/hashes/timestamps
        '/\b\d{3,}\b/' => '<N>',
    ];

    public function normalize(string $message): string
    {
        $out = $message;
        foreach (self::REPLACEMENTS as $pattern => $replacement) {
            $out = (string) preg_replace($pattern, $replacement, $out);
        }

        return $out;
    }

    /**
     * Collapse the variable parts of PHP engine error/notice/deprecation messages
     * so per-call-site variants (different method or `$var` per site) hash to the
     * same fingerprint. Returns the message unchanged when it isn't an engine error.
     *
     * Example:
     *   "Deprecated: AC\\Dependencies::add_missing_plugin(): Implicitly marking parameter $version as nullable..."
     *   → "Deprecated: <METHOD>(): Implicitly marking parameter $<VAR> as nullable..."
     */
    public function normalizePhpError(string $message): string
    {
        if (! $this->looksLikePhpEngineError($message)) {
            return $message;
        }

        $out = preg_replace(
            '/^('.self::PHP_SEVERITY_PREFIX.'):\s+[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*\(\):/',
            '$1: <METHOD>():',
            $message,
        ) ?? $message;

        return preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*/', '\$<VAR>', $out) ?? $out;
    }

    public function looksLikePhpEngineError(string $message): bool
    {
        return (bool) preg_match('/^'.self::PHP_SEVERITY_PREFIX.':/', $message);
    }

    /**
     * Strip bind values from Laravel QueryException-style messages.
     *
     * Example input:
     *   SQLSTATE[42S22]: ...: select * from "users" where "id" = ? (Connection: pgsql, ..., Bindings: [123])
     */
    public function normalizeSql(string $message): string
    {
        $out = preg_replace('/\(Connection:\s*[^)]+\)/i', '', $message) ?? $message;
        $out = preg_replace('/\(Bindings:\s*\[[^\]]*\]\)/i', '', $out) ?? $out;

        return trim($this->normalize($out));
    }
}
