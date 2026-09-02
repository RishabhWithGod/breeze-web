<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;

/**
 * The estimating components a takeoff is expected to produce, and which of them
 * this run actually has.
 *
 * The engine returns symbol counts and, on some runs, the wiring and equipment
 * it read off the drawing. The rest — labour, wire lengths, conduit and its
 * sizing, bends and fittings — is still being built into the pipeline. Rather
 * than leave the reviewer guessing which of those they will get, every
 * component is listed either way: with its real figures when the run returned
 * them, and as still to come when it did not.
 *
 * Nothing here invents a quantity. A component with no data says so; a number
 * on this screen is always one the engine actually returned.
 */
class EstimatingComponents
{
    /**
     * @return list<array<string, mixed>>
     */
    public function for(AiResult $result): array
    {
        $result->loadMissing(['wireSizes', 'equipment', 'circuits', 'panelSchedules', 'boqLines']);

        return [
            $this->labour($result),
            $this->pending('wire-length', 'Wire length', 'Run lengths measured off the drawing.'),
            $this->wireSizes($result),
            $this->pending('conduit', 'Conduit and conduit sizing', 'Runs, types and the sizing that follows the fill.'),
            $this->pending('bends', '90° and 45° bends and fittings', 'Counted per run, with the fittings each one needs.'),
            $this->equipment($result),
            $this->circuits($result),
            $this->panels($result),
        ];
    }

    /**
     * Labour is priced from the reviewed bill of quantities, which only exists
     * once the review is signed off — so before that there is genuinely nothing
     * to show, and this says so rather than showing a zero.
     *
     * @return array<string, mixed>
     */
    private function labour(AiResult $result): array
    {
        $hours = data_get($result->final_payload, 'boq.totals.labor_hours');

        if (! is_numeric($hours)) {
            return $this->pending('labor', 'Labor', 'Hours priced from the reviewed bill of quantities.');
        }

        return $this->available(
            'labor',
            'Labor',
            number_format((float) $hours, 1).' hours',
            [],
        );
    }

    /** @return array<string, mixed> */
    private function wireSizes(AiResult $result): array
    {
        if ($result->wireSizes->isEmpty()) {
            return $this->pending('wire-size', 'Wire size', 'Sizes read off the drawing and its schedules.');
        }

        return $this->available(
            'wire-size',
            'Wire size',
            $result->wireSizes->count().' '.str('size')->plural($result->wireSizes->count()).' found',
            $result->wireSizes->map(fn ($wire) => [
                'label' => $wire->size,
                'detail' => $wire->context,
                'value' => $wire->count === null ? null : $wire->count.'×',
                'page' => $wire->page,
            ])->all(),
        );
    }

    /** @return array<string, mixed> */
    private function equipment(AiResult $result): array
    {
        if ($result->equipment->isEmpty()) {
            return $this->pending('equipment', 'Equipment', 'Tagged equipment and its ratings.');
        }

        return $this->available(
            'equipment',
            'Equipment',
            $result->equipment->count().' items found',
            $result->equipment->map(fn ($item) => [
                'label' => $item->tag ?? $item->description,
                'detail' => $item->tag === null ? $item->rating : $item->description,
                'value' => $item->quantity === null ? null : $item->quantity.'×',
                'page' => $item->page,
            ])->all(),
        );
    }

    /** @return array<string, mixed> */
    private function circuits(AiResult $result): array
    {
        if ($result->circuits->isEmpty()) {
            return $this->pending('circuits', 'Circuits', 'Circuit numbers, breakers and the panels they land on.');
        }

        return $this->available(
            'circuits',
            'Circuits',
            $result->circuits->count().' circuits found',
            $result->circuits->map(fn ($circuit) => [
                'label' => $circuit->number ?? $circuit->description,
                'detail' => $circuit->panel,
                'value' => $circuit->breaker,
                'page' => $circuit->page,
            ])->all(),
        );
    }

    /** @return array<string, mixed> */
    private function panels(AiResult $result): array
    {
        if ($result->panelSchedules->isEmpty()) {
            return $this->pending('panels', 'Panel schedules', 'Schedules read off the drawing set.');
        }

        return $this->available(
            'panels',
            'Panel schedules',
            $result->panelSchedules->count().' schedules found',
            $result->panelSchedules->map(fn ($panel) => [
                'label' => $panel->panel_name,
                'detail' => count($panel->rows ?? []).' rows',
                'value' => null,
                'page' => $panel->page,
            ])->all(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function available(string $key, string $label, string $summary, array $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'available',
            'summary' => $summary,
            // Capped: this is a summary beside the review, not the full table.
            'items' => array_slice($items, 0, 8),
            'moreCount' => max(0, count($items) - 8),
        ];
    }

    /** @return array<string, mixed> */
    private function pending(string $key, string $label, string $summary): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'pending',
            'summary' => $summary,
            'items' => [],
            'moreCount' => 0,
        ];
    }
}
