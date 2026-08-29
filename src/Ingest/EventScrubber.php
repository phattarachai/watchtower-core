<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

class EventScrubber
{
    /** @var list<string> */
    private array $headerKeys;

    /** @var list<string> */
    private array $bodyKeys;

    /**
     * @param  list<string>  $headerKeys
     * @param  list<string>  $bodyKeys
     */
    public function __construct(
        array $headerKeys,
        array $bodyKeys,
        private readonly string $placeholder = '[Filtered]',
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

        return $event;
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
