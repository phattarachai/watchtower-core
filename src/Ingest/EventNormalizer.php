<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Ingest;

class EventNormalizer
{
    /** @var list<string> */
    private array $allowedEventFields;

    /** @var list<string> */
    private array $allowedContextKeys;

    /**
     * @param  list<string>  $allowedEventFields
     * @param  list<string>  $allowedContextKeys
     */
    public function __construct(array $allowedEventFields, array $allowedContextKeys)
    {
        $this->allowedEventFields = $allowedEventFields;
        $this->allowedContextKeys = $allowedContextKeys;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function normalize(array $event): array
    {
        $kept = $this->keepAllowedFields($event);

        if (isset($kept['contexts']) && is_array($kept['contexts'])) {
            $kept['contexts'] = $this->filterContexts($kept['contexts']);
        }

        return $this->stripNulBytes($kept);
    }

    /**
     * Postgres jsonb cannot store strings containing the NUL byte. PHP 8
     * anonymous-class names embed one as an internal separator, so stack-frame
     * `function` values like `Livewire\Component@anonymous<NUL>/path/file.php:23`
     * arrive here with a literal NUL. Strip it everywhere — it's an invisible
     * separator with no meaning to any human reader.
     */
    private function stripNulBytes(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        if (is_array($value)) {
            return array_map($this->stripNulBytes(...), $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function keepAllowedFields(array $event): array
    {
        return array_intersect_key($event, array_flip($this->allowedEventFields));
    }

    /**
     * @param  array<string, mixed>  $contexts
     * @return array<string, mixed>
     */
    private function filterContexts(array $contexts): array
    {
        return array_intersect_key($contexts, array_flip($this->allowedContextKeys));
    }
}
