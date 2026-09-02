<?php

namespace App\Services\Geocoding;

/**
 * One address the geocoder matched, with the point behind it.
 *
 * A value object rather than a loose array so the coordinates cannot drift
 * apart from the label they belong to on their way to the screen.
 */
final readonly class AddressSuggestion
{
    public function __construct(
        public string $label,
        public float $latitude,
        public float $longitude,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
