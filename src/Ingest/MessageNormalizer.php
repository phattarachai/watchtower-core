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

    /**
     * The issue title's rules. They mask the same per-event values as
     * REPLACEMENTS, but read better: a bare date becomes `<DATE>` rather than
     * `<N>-10-04`, and a hash needs both a digit and a letter, so a pure
     * decimal such as a memory size falls through to `<N>`. The title is
     * rewritten on every event, so it still has to be stable.
     */
    private const array READABLE_REPLACEMENTS = [
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<UUID>',
        '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?/' => '<TIME>',
        '/\b\d{4}-\d{2}-\d{2}\b/' => '<DATE>',
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '<EMAIL>',
        '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i' => '<HASH>',
        '#/(storage|tmp|cache)/[^\s\'"]+#' => '/$1/<PATH>',
        '/"([^"\\\\]|\\\\.){20,}"/' => '"<STR>"',
        "/'([^'\\\\]|\\\\.){20,}'/" => "'<STR>'",
        '/\b\d{3,}\b/' => '<N>',
    ];

    /**
     * @param  bool  $readable  use the issue-title rules instead of the grouping ones. The
     *                          grouping rules never change, since a change would regroup
     *                          every stored issue.
     */
    public function normalize(string $message, bool $readable = false): string
    {
        $out = $message;
        foreach ($readable ? self::READABLE_REPLACEMENTS : self::REPLACEMENTS as $pattern => $replacement) {
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

        return preg_replace('/\$[A-Za-z_]\w*/', '\$<VAR>', $out) ?? $out;
    }

    public function looksLikePhpEngineError(string $message): bool
    {
        return (bool) preg_match('/^'.self::PHP_SEVERITY_PREFIX.':/', $message);
    }

    /**
     * Strip the volatile tail from Laravel/PDO QueryException messages so the
     * stable "SQLSTATE[code]: category" prefix survives.
     *
     * Example input:
     *   SQLSTATE[42S22]: ...: select * from "users" where "id" = ? (Connection: pgsql, ..., Bindings: [123])
     */
    public function normalizeSql(string $message, bool $readable = false): string
    {
        // Postgres appends a "CONTEXT: ..." detail line carrying the offending
        // parameter value, and Laravel appends a "(Connection: ..., SQL: ...)" /
        // "(Bindings: [...])" suffix. Both are the message tail; nested ")" inside
        // SQL functions like COALESCE(...) make a lazy strip stop short, so cut
        // from the marker to end of string.
        $out = preg_replace('/\R+\s*CONTEXT:.*$/is', '', $message) ?? $message;
        $out = preg_replace('/\s*\((?:Connection|Bindings):.*$/is', '', $out) ?? $out;

        return trim($this->preserveSqlState($out, fn (string $s): string => $this->normalize($s, $readable)));
    }

    /**
     * Extract the SQLSTATE code (e.g. "SQLSTATE[22008]") from a driver message,
     * or null when the message carries none.
     */
    public function sqlStateCode(string $message): ?string
    {
        if (preg_match('/SQLSTATE\[[0-9A-Za-z]+\]/', $message, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * Run $transform over the message with the SQLSTATE code masked, so the
     * integer collapse rule keeps "SQLSTATE[22008]" intact instead of mangling
     * the numeric code to "SQLSTATE[<N>]".
     *
     * @param  callable(string): string  $transform
     */
    private function preserveSqlState(string $message, callable $transform): string
    {
        $code = $this->sqlStateCode($message);
        if ($code === null) {
            return $transform($message);
        }

        $placeholder = '<<SQLSTATE>>';

        return str_replace($placeholder, $code, $transform(str_replace($code, $placeholder, $message)));
    }
}
