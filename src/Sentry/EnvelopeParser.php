<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Sentry;

use JsonException;

/**
 * Parses Sentry envelope v7 binary format.
 *
 * Layout (newline-separated):
 *   <envelope-header-json>\n
 *   <item-header-json>\n
 *   <item-payload>\n        ← `length` bytes per item header (or until next \n if absent)
 *   <item-header-json>\n
 *   <item-payload>\n
 *   ...
 *
 * Body may be gzip-compressed (caller handles Content-Encoding: gzip first).
 */
final class EnvelopeParser
{
    /**
     * @return array{header: array<string, mixed>, items: array<int, EnvelopeItem>}
     */
    public function parse(string $body): array
    {
        if ($body === '') {
            return ['header' => [], 'items' => []];
        }

        $offset = 0;
        $header = $this->readJsonLine($body, $offset);
        $items = [];

        while ($offset < strlen($body)) {
            $itemHeader = $this->readJsonLine($body, $offset);
            if ($itemHeader === null) {
                break;
            }
            $payload = $this->readItemPayload($body, $offset, $itemHeader);
            $items[] = new EnvelopeItem($itemHeader, $payload);
        }

        return ['header' => $header ?? [], 'items' => $items];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonLine(string $body, int &$offset): ?array
    {
        $newline = strpos($body, "\n", $offset);
        $line = $newline === false
            ? substr($body, $offset)
            : substr($body, $offset, $newline - $offset);
        $offset = $newline === false ? strlen($body) : $newline + 1;

        if (trim($line) === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $itemHeader
     * @return array<string, mixed>|string
     */
    private function readItemPayload(string $body, int &$offset, array $itemHeader): array|string
    {
        $length = isset($itemHeader['length']) ? (int) $itemHeader['length'] : null;

        if ($length !== null) {
            $payload = substr($body, $offset, $length);
            $offset += $length;
            if (isset($body[$offset]) && $body[$offset] === "\n") {
                $offset++;
            }
        } else {
            $newline = strpos($body, "\n", $offset);
            $payload = $newline === false
                ? substr($body, $offset)
                : substr($body, $offset, $newline - $offset);
            $offset = $newline === false ? strlen($body) : $newline + 1;
        }

        if (($itemHeader['content_encoding'] ?? null) === 'gzip') {
            $payload = (string) gzdecode($payload);
        }

        $contentType = $itemHeader['content_type'] ?? 'application/json';
        if (str_contains((string) $contentType, 'json')) {
            try {
                $decoded = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
                return $payload;
            }
        }

        return $payload;
    }
}
