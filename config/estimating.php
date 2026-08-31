<?php

use App\Models\EstimateItem;

return [

    /*
    |--------------------------------------------------------------------------
    | Rate catalog
    |--------------------------------------------------------------------------
    |
    | Default rates used to turn reviewed symbol counts into a bill of
    | quantities and a first-pass estimate. This is a starting price book, not a
    | source of truth: every generated line, rate and quantity stays editable on
    | the estimate, and a customer is expected to replace these numbers with
    | their own.
    |
    | `match` entries are matched against the reviewed symbol name — the first
    | keyword found wins, otherwise `default` applies.
    |
    |   unit_cost    — material cost per device
    |   labor_hours  — install hours per device
    |   category     — which estimate section the device lands in
    |   materials    — consumables per device: [description, unit, per_each cost]
    |
    */

    'catalog' => [
        [
            'match' => ['light fixture', 'luminaire', 'pendant', 'downlight', 'troffer'],
            'category' => EstimateItem::CATEGORY_FIXTURE,
            'unit' => 'ea',
            'unit_cost' => 142.00,
            'labor_hours' => 1.1,
            'materials' => [
                ['Fixture whip, 6 ft', 'ea', 14.50],
                ['Fixture support hardware', 'set', 6.25],
            ],
        ],
        [
            'match' => ['duplex outlet', 'receptacle', 'data outlet', 'telephone outlet', 'outlet'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ea',
            'unit_cost' => 18.40,
            'labor_hours' => 0.55,
            'materials' => [
                ['Device box, 4 in square', 'ea', 4.80],
                ['Device plate', 'ea', 2.10],
                ['12 AWG THHN branch wire', 'ft', 0.62, 34],
            ],
        ],
        [
            'match' => ['gfci'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ea',
            'unit_cost' => 32.75,
            'labor_hours' => 0.6,
            'materials' => [
                ['Device box, 4 in square', 'ea', 4.80],
                ['Weather-resistant plate', 'ea', 5.40],
            ],
        ],
        [
            'match' => ['switch', 'dimmer'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ea',
            'unit_cost' => 16.20,
            'labor_hours' => 0.5,
            'materials' => [
                ['Device box, single gang', 'ea', 3.95],
                ['Switch plate', 'ea', 1.85],
                ['12 AWG THHN switch leg', 'ft', 0.62, 22],
            ],
        ],
        [
            'match' => ['panel', 'switchboard', 'distribution board'],
            'category' => EstimateItem::CATEGORY_EQUIPMENT,
            'unit' => 'ea',
            'unit_cost' => 1850.00,
            'labor_hours' => 8.0,
            'materials' => [
                ['Panel mounting hardware', 'set', 64.00],
                ['Circuit directory and labels', 'set', 18.00],
            ],
        ],
        [
            'match' => ['junction box', 'pull box'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ea',
            'unit_cost' => 11.90,
            'labor_hours' => 0.4,
            'materials' => [
                ['Box cover', 'ea', 2.60],
                ['Connectors', 'set', 3.15],
            ],
        ],
        [
            'match' => ['smoke detector', 'door contact', 'glass break', 'occupancy sensor',
                'daylight sensor', 'sensor', 'thermostat', 'detector'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ea',
            'unit_cost' => 68.00,
            'labor_hours' => 0.75,
            'materials' => [
                ['Low-voltage cable, 18/2', 'ft', 0.48, 40],
                ['Mounting plate', 'ea', 4.20],
            ],
        ],
        [
            'match' => ['conduit', 'homerun', 'feeder'],
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'unit' => 'ft',
            'unit_cost' => 3.85,
            'labor_hours' => 0.08,
            'materials' => [],
        ],
    ],

    /* Applied to any symbol the catalog does not recognise. */
    'default' => [
        'category' => EstimateItem::CATEGORY_MATERIAL,
        'unit' => 'ea',
        'unit_cost' => 45.00,
        'labor_hours' => 0.65,
        'materials' => [
            ['Rough-in materials allowance', 'ea', 8.50],
        ],
    ],

];
