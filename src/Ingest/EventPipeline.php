<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

/**
 * The scrub → normalize → truncate steps every stored event goes through, plus
 * the fingerprint and title computed from the result. Run it before an event is
 * queued so secrets and unbounded payloads never reach the queue backend; the
 * worker may run it again — every step is idempotent.
 */
final class EventPipeline
{
    private readonly Fingerprinter $fingerprinter;

    public function __construct(
        private readonly EventScrubber $scrubber,
        private readonly EventNormalizer $normalizer,
        private readonly ?EventTruncator $truncator = null,
        ?Fingerprinter $fingerprinter = null,
    ) {
        $this->fingerprinter = $fingerprinter ?? new Fingerprinter(new MessageNormalizer);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function prepare(array $raw): array
    {
        $event = $this->normalizer->normalize($this->scrubber->scrub($raw));

        return $this->truncator?->truncate($event) ?? $event;
    }

    /**
     * @param  array<string, mixed>  $event  a prepared event
     */
    public function fingerprint(array $event): string
    {
        return $this->fingerprinter->compute($event);
    }

    /**
     * @param  array<string, mixed>  $event  a prepared event
     */
    public function title(array $event): string
    {
        return $this->fingerprinter->title($event);
    }
}
