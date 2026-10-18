<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\BotDetector;

/** @return array<string, mixed> */
function browserEvent(?string $userAgent, string $platform = 'javascript'): array
{
    return [
        'platform' => $platform,
        'request' => ['url' => 'https://example.com/', 'headers' => ['User-Agent' => $userAgent]],
    ];
}

it('recognises crawler user agents', function (string $userAgent) {
    expect((new BotDetector)->isBot($userAgent))->toBeTrue();
})->with([
    'Googlebot' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'GoogleOther' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; GoogleOther)',
    'Google-InspectionTool' => 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
    'AdsBot' => 'AdsBot-Google (+http://www.google.com/adsbot.html)',
    'bingbot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36',
    'meta-webindexer' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))',
    'AhrefsBot' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
]);

it('lets real browsers and automated tests through', function (?string $userAgent) {
    expect((new BotDetector)->isBot($userAgent))->toBeFalse();
})->with([
    'iPhone Safari' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1',
    'desktop Chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    'HeadlessChrome, used by browser tests' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/154.0.0.0 Safari/537.36',
    'a phone model containing "bot"' => 'Mozilla/5.0 (Linux; Android 12; CUBOT_X50 Build/SP1A) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'TikTok in-app' => 'Mozilla/5.0 (Linux; Android 14; SM-S908U) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/117.0.5938.60 Mobile Safari/537.36 trill_410200 Channel/googleplay',
    'empty' => '',
    'missing' => null,
]);

it('judges browser events by their User-Agent header', function () {
    $detector = new BotDetector;

    expect($detector->isBrowserEventFromBot(browserEvent('Mozilla/5.0 (compatible; GoogleOther)')))->toBeTrue()
        ->and($detector->isBrowserEventFromBot(browserEvent('Mozilla/5.0 (iPhone) Safari/604.1')))->toBeFalse()
        ->and($detector->isBrowserEventFromBot(['platform' => 'javascript']))->toBeFalse();
});

it('reads the header case-insensitively and from the [name, value] list shape', function () {
    $detector = new BotDetector;

    expect($detector->isBrowserEventFromBot([
        'platform' => 'javascript',
        'request' => ['headers' => ['user-agent' => 'Mozilla/5.0 (compatible; GoogleOther)']],
    ]))->toBeTrue()
        ->and($detector->isBrowserEventFromBot([
            'platform' => 'javascript',
            'request' => ['headers' => [['Accept', '*/*'], ['User-Agent', 'Mozilla/5.0 (compatible; GoogleOther)']]],
        ]))->toBeTrue();
});

it('never judges a server-side event, since a crawler can trigger a real server bug', function () {
    expect((new BotDetector)->isBrowserEventFromBot(browserEvent('Mozilla/5.0 (compatible; Googlebot/2.1)', 'php')))->toBeFalse();
});
