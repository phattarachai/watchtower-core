<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

class Fingerprinter
{
    /**
     * FQCN of Laravel's query exception, matched as a string so core stays
     * framework-free (no illuminate/database dependency).
     */
    private const string QUERY_EXCEPTION = 'Illuminate\\Database\\QueryException';

    public function __construct(private readonly MessageNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $event  normalized Sentry event payload
     */
    public function compute(array $event): string
    {
        $override = $this->resolveOverride($event);
        if ($override !== null) {
            return md5($override);
        }

        $exception = $this->topException($event);
        $exceptionClass = (string) ($exception['type'] ?? 'UnknownException');
        $message = $this->resolveMessage($event, $exception);

        return md5(implode('|', $this->fingerprintParts($event, $exception, $exceptionClass, $message)));
    }

    /**
     * Pick a stable user-facing title for the issue group.
     *
     * @param  array<string, mixed>  $event
     */
    public function title(array $event): string
    {
        $exception = $this->topException($event);
        $message = $this->resolveMessage($event, $exception);
        $class = (string) ($exception['type'] ?? '');

        $message = trim($this->normalizeMessage($class, $message, readable: true));
        if ($message === '') {
            return $class !== '' ? $class : 'Unknown error';
        }

        return $class !== '' ? "{$class}: {$message}" : $message;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $exception
     * @return list<string>
     */
    private function fingerprintParts(array $event, array $exception, string $exceptionClass, string $message): array
    {
        // PHP engine errors (Deprecated/Warning/Notice) are captured at Sentry's
        // error handler, so the top in-app frame is identical across many call
        // sites — a frame-based fingerprint over-collapses or doesn't help. The
        // normalized message carries the signal; drop frame coordinates so the
        // ACP "Implicitly marking parameter $X as nullable" variants merge to one.
        if ($this->isPhpEngineError($exceptionClass, $message)) {
            return [$exceptionClass, $this->normalizeMessage($exceptionClass, $message)];
        }

        // Browser JS errors report the visited *page URL* as the frame filename,
        // the inline-<script> offset as lineno, and "?" as the function — all
        // volatile per page view, so the same bug mints a fresh group on every
        // distinct URL (and every ad-tracking query string). Group on class +
        // normalized message, which also matches the issue title.
        if ($this->isBrowserError($event)) {
            return [$exceptionClass, $this->normalizeMessage($exceptionClass, $message)];
        }

        $frame = $this->topAppFrame($exception);

        // SQL errors embed the offending value as an inline literal (e.g. a bare
        // `0000-05-31` date or a `"..."` string), which survives message
        // normalization and mints a fresh group per value. Group on the SQLSTATE
        // code instead — the same driver error at the same call site is the same
        // bug regardless of which row tripped it.
        $sqlState = $this->sqlStateFingerprint($exceptionClass, $message);

        return [
            $exceptionClass,
            $this->normalizePath((string) ($frame['filename'] ?? $frame['abs_path'] ?? '')),
            (string) ($frame['function'] ?? ''),
            (string) ($frame['lineno'] ?? ''),
            $sqlState ?? $this->normalizeMessage($exceptionClass, $message),
        ];
    }

    /**
     * The SQLSTATE code (e.g. "SQLSTATE[22008]") when the message is a driver
     * error, otherwise null so the caller falls back to the normalized message.
     */
    private function sqlStateFingerprint(string $exceptionClass, string $message): ?string
    {
        if ($exceptionClass !== self::QUERY_EXCEPTION && ! str_contains($message, 'SQLSTATE[')) {
            return null;
        }

        return $this->normalizer->sqlStateCode($message);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isBrowserError(array $event): bool
    {
        return ($event['platform'] ?? null) === 'javascript';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveOverride(array $event): ?string
    {
        $fingerprint = $event['fingerprint'] ?? null;
        if (! is_array($fingerprint) || $fingerprint === []) {
            return null;
        }

        return implode('|', array_map(strval(...), $fingerprint));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function topException(array $event): array
    {
        $exceptions = $event['exception']['values'] ?? null;
        if (! is_array($exceptions) || $exceptions === []) {
            return [];
        }

        // Sentry orders exceptions oldest-first; the top of the chain is last.
        $top = end($exceptions);

        return is_array($top) ? $top : [];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $exception
     */
    private function resolveMessage(array $event, array $exception): string
    {
        $value = $exception['value'] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $message = $event['message'] ?? null;
        if (is_array($message)) {
            return (string) ($message['formatted'] ?? $message['message'] ?? '');
        }

        return (string) ($message ?? '');
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    private function topAppFrame(array $exception): array
    {
        $frames = $exception['stacktrace']['frames'] ?? [];
        if (! is_array($frames) || $frames === []) {
            return [];
        }

        // Sentry stack frames are listed bottom-up (oldest first, current at the end).
        $reversed = array_reverse($frames);
        foreach ($reversed as $frame) {
            if (! is_array($frame)) {
                continue;
            }
            if ($this->isAppFrame($frame)) {
                return $frame;
            }
        }

        $top = $reversed[0] ?? [];

        return is_array($top) ? $top : [];
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function isAppFrame(array $frame): bool
    {
        if (array_key_exists('in_app', $frame)) {
            return (bool) $frame['in_app'];
        }
        $path = (string) ($frame['abs_path'] ?? $frame['filename'] ?? '');
        if ($path === '') {
            return true;
        }

        return ! str_contains($path, '/vendor/') && ! str_contains($path, '/node_modules/');
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Strip everything before "app/" or "src/" to make paths machine-stable
        // across deploys with different absolute roots.
        if (preg_match('#(?:^|/)((?:app|src|tests|database|routes|config|resources)/.+)$#', $path, $m)) {
            return $m[1];
        }

        return basename($path);
    }

    /**
     * @param  bool  $readable  the title's rules; the fingerprint always uses the grouping ones
     */
    private function normalizeMessage(string $exceptionClass, string $message, bool $readable = false): string
    {
        if ($exceptionClass === self::QUERY_EXCEPTION || str_contains($message, 'SQLSTATE[')) {
            return $this->normalizer->normalizeSql($message, $readable);
        }

        if ($this->isPhpEngineError($exceptionClass, $message)) {
            return $this->normalizer->normalize($this->normalizer->normalizePhpError($message), $readable);
        }

        return $this->normalizer->normalize($message, $readable);
    }

    /**
     * The Sentry SDK funnels PHP engine errors (E_DEPRECATED / E_WARNING / E_NOTICE
     * / E_*) through `set_error_handler`, surfacing them as `ErrorException` whose
     * value starts with the engine severity word. Both signals together avoid
     * over-normalizing a user-thrown exception that happens to mention "Deprecated:".
     */
    private function isPhpEngineError(string $exceptionClass, string $message): bool
    {
        return $exceptionClass === 'ErrorException'
            && $this->normalizer->looksLikePhpEngineError($message);
    }
}
