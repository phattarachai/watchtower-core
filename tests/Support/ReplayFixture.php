<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Tests\Support;

use Phattarachai\WatchtowerCore\Sentry\EnvelopeItem;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeParser;

/**
 * Real `@sentry/browser` 11.1.0 replay envelopes captured from a demo page.
 */
final class ReplayFixture
{
    /**
     * @return array<string, EnvelopeItem> items keyed by type
     */
    public static function items(string $name): array
    {
        $envelope = (new EnvelopeParser)->parse(self::contents("{$name}.envelope"));

        $byType = [];
        foreach ($envelope['items'] as $item) {
            $byType[$item->type()] = $item;
        }

        return $byType;
    }

    public static function contents(string $file): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/replay/{$file}");
    }
}
