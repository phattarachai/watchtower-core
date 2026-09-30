<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

class EventScrubber
{
    /**
     * Driver messages that quote the offending row value, per dialect. Group 1
     * is kept, group 2 is the value.
     *
     * @var list<string>
     */
    private const array SQL_VALUE_PATTERNS = [
        "/(Duplicate entry ')(.*?)(?=' for key)/s",
        "/(Incorrect \\w+ value: ')(.*?)(?=')/s",
        '/(Key \\([^)]*\\)=\\()(.*?)(?=\\) )/s',
        '/(invalid input syntax for type [\\w ]+: ")(.*?)(?=")/s',
        "/(parameter \\$\\d+ = ')(.*?)(?=')/s",
    ];

    /** @var list<string> */
    private array $headerKeys;

    /** @var list<string> */
    private array $bodyKeys;

    /**
     * @param  list<string>  $headerKeys
     * @param  list<string>  $bodyKeys
     * @param  bool  $redactSqlValues  also strip row values out of SQL error messages and query
     *                                 breadcrumbs — Laravel's QueryException inlines every binding
     *                                 into its message, which is exactly the data that
     *                                 SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false keeps out
     */
    public function __construct(
        array $headerKeys,
        array $bodyKeys,
        private readonly string $placeholder = '[Filtered]',
        private readonly bool $redactSqlValues = false,
    ) {
        $this->headerKeys = array_map('strtolower', $headerKeys);
        $this->bodyKeys = array_map('strtolower', $bodyKeys);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function scrub(array $event): array
    {
        if (isset($event['request']) && is_array($event['request'])) {
            $event['request'] = $this->scrubRequest($event['request']);
        }

        foreach (['extra', 'tags'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                $event[$key] = $this->scrubKeys($event[$key]);
            }
        }

        if (isset($event['breadcrumbs']) && is_array($event['breadcrumbs'])) {
            $event['breadcrumbs'] = $this->scrubBreadcrumbs($event['breadcrumbs']);
        }

        return $this->redactSqlValues ? $this->redactSql($event) : $event;
    }

    /**
     * Breadcrumbs arrive as `{values: [...]}` or as a bare list.
     *
     * @param  array<mixed, mixed>  $breadcrumbs
     * @return array<mixed, mixed>
     */
    private function scrubBreadcrumbs(array $breadcrumbs): array
    {
        if (isset($breadcrumbs['values']) && is_array($breadcrumbs['values'])) {
            $breadcrumbs['values'] = array_map($this->scrubBreadcrumb(...), $breadcrumbs['values']);

            return $breadcrumbs;
        }

        return array_is_list($breadcrumbs) ? array_map($this->scrubBreadcrumb(...), $breadcrumbs) : $breadcrumbs;
    }

    private function scrubBreadcrumb(mixed $crumb): mixed
    {
        if (! is_array($crumb) || ! isset($crumb['data']) || ! is_array($crumb['data'])) {
            return $crumb;
        }

        $crumb['data'] = $this->scrubKeys($crumb['data']);

        if ($this->redactSqlValues && str_starts_with((string) ($crumb['category'] ?? ''), 'db.')) {
            unset($crumb['data']['bindings']);
        }

        return $crumb;
    }

    /**
     * Laravel's `(Connection: …, SQL: …)` tail is dropped whole — the bindings
     * are substituted unquoted, so they cannot be told apart from the query. The
     * query template still arrives in the SQL breadcrumbs.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function redactSql(array $event): array
    {
        $values = $event['exception']['values'] ?? null;

        if (is_array($values)) {
            foreach ($values as $i => $exception) {
                if (is_array($exception) && is_string($exception['value'] ?? null)) {
                    $event['exception']['values'][$i]['value'] = $this->redactSqlMessage($exception['value']);
                }
            }
        }

        if (is_string($event['message'] ?? null)) {
            $event['message'] = $this->redactSqlMessage($event['message']);
        }

        if (is_string($event['message']['formatted'] ?? null)) {
            $event['message']['formatted'] = $this->redactSqlMessage($event['message']['formatted']);
        }

        return $event;
    }

    private function redactSqlMessage(string $message): string
    {
        if (! str_contains($message, 'SQLSTATE[')) {
            return $message;
        }

        $out = preg_replace('/(\(Connection: [^)]*?), SQL: .*\)\s*$/s', '$1, SQL: '.$this->placeholder.')', $message) ?? $message;

        foreach (self::SQL_VALUE_PATTERNS as $pattern) {
            $out = preg_replace($pattern, '${1}'.$this->placeholder, $out) ?? $out;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = $this->scrubHeaders($request['headers']);
        }
        foreach (['data', 'query_string', 'cookies'] as $key) {
            if (isset($request[$key]) && is_array($request[$key])) {
                $request[$key] = $this->scrubKeys($request[$key]);
            }
        }

        return $request;
    }

    /**
     * @param  array<mixed, mixed>  $headers
     * @return array<mixed, mixed>
     */
    private function scrubHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), $this->headerKeys, strict: true)) {
                $headers[$name] = $this->placeholder;
            }
        }

        return $headers;
    }

    /**
     * @param  array<mixed, mixed>  $items
     * @return array<mixed, mixed>
     */
    private function scrubKeys(array $items): array
    {
        foreach ($items as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $this->bodyKeys, strict: true)) {
                $items[$key] = $this->placeholder;

                continue;
            }
            if (is_array($value)) {
                $items[$key] = $this->scrubKeys($value);
            }
        }

        return $items;
    }
}
