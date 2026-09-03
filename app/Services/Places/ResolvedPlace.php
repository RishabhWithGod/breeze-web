<?php

namespace App\Services\Places;

/**
 * A place a person actually chose, with everything worth storing.
 *
 * A value object rather than a loose array so the coordinates cannot drift
 * apart from the address they belong to on their way to the screen — and so
 * `place_id` always travels with the pair it identifies.
 */
final readonly class ResolvedPlace
{
    public function __construct(
        public string $placeId,
        public string $address,
        public float $latitude,
        public float $longitude,
        /** Locality, region, postcode and country, where Google returned them. */
        public array $components = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'placeId' => $this->placeId,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'components' => $this->components,
        ];
    }
}
