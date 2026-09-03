<?php

namespace App\Services\Places;

/**
 * One address Google matched, and the id needed to look it up properly.
 *
 * Deliberately carries no coordinates: Autocomplete (New) does not return
 * them, and asking Place Details for every suggestion would bill a details
 * call per keystroke. The point arrives once, when a person picks one.
 */
final readonly class PlaceSuggestion
{
    public function __construct(
        public string $placeId,
        /** The whole address on one line — what the list shows. */
        public string $label,
        /** The part that matched, bolded by nothing but read first. */
        public string $primary,
        public string $secondary,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'placeId' => $this->placeId,
            'label' => $this->label,
            'primary' => $this->primary,
            'secondary' => $this->secondary,
        ];
    }
}
