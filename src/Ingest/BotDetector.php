<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

/**
 * Recognises browser events reported from a crawler's renderer.
 *
 * Googlebot, GoogleOther and friends execute a site's JavaScript with a frozen
 * clock and often a stale asset cache, so they throw errors no visitor sees,
 * such as chunk loads for a hash from an earlier crawl. Those events are noise.
 * Only browser events are judged: a PHP error a crawler triggers is a real
 * server bug, and it still reports.
 *
 * HeadlessChrome is deliberately not matched. Automated browser tests use it,
 * and the errors they find are real.
 *
 * Keep BOT_USER_AGENT in step with watchtower-laravel's resources/js/noise.js.
 */
final class BotDetector
{
    /**
     * A `+http://` contact link is the crawler convention ("+http://www.google.com/bot.html");
     * a `…bot/2.1` version token and a `compatible; …bot` comment cover the rest of the
     * generic ones. The named tokens are crawlers that follow neither convention. A bare
     * "bot" is not enough on its own: phone models such as Cubot contain it.
     */
    public const string BOT_USER_AGENT = '~\+https?://|(?:bot|crawler|spider)/\d|compatible;\s*[\w.-]*(?:bot|crawler|spider)\b|GoogleOther|Google-InspectionTool|AdsBot-Google|Mediapartners-Google|bingpreview|facebookexternalhit|meta-externalagent|Bytespider|PetalBot~i';

    public function isBot(?string $userAgent): bool
    {
        return $userAgent !== null && $userAgent !== '' && preg_match(self::BOT_USER_AGENT, $userAgent) === 1;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function isBrowserEventFromBot(array $event): bool
    {
        return ($event['platform'] ?? null) === 'javascript' && $this->isBot($this->userAgent($event));
    }

    /**
     * The User-Agent request header, from either shape the Sentry protocol allows:
     * a name → value map, or a list of [name, value] pairs.
     *
     * @param  array<string, mixed>  $event
     */
    private function userAgent(array $event): ?string
    {
        $headers = $event['request']['headers'] ?? null;
        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_array($value) && array_is_list($value) && count($value) === 2) {
                [$name, $value] = $value;
            }

            if (is_string($name) && strcasecmp($name, 'User-Agent') === 0 && is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
