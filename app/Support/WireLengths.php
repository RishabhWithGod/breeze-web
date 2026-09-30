<?php

namespace App\Support;

/**
 * Puts a total length on each conductor size the engine read off a drawing.
 *
 * A wire-size row only says how many times a size was seen — "2" — which means
 * nothing for something priced by the foot. The length is already on the
 * estimate's (or the BOQ's) lines, e.g. "#10 THHN Solid" at 1,020 ft, so it is
 * matched back onto the size by name. A size with no such line keeps its count
 * and simply gets no length; nothing is estimated here.
 */
class WireLengths
{
    private const LENGTH_UNITS = ['ft', 'lf', 'feet', 'foot', "'", 'linear ft', 'linear feet', 'lin ft'];

    /**
     * @param  iterable<array{0: string|null, 1: string|null, 2: float|int|string|null}>  $lines  [description, unit, quantity]
     * @param  array<int, array<string, mixed>>  $wireSizes  rows with a `size`
     * @return array<int, array<string, mixed>> the same rows plus `length` and `lengthUnit`
     */
    public static function attach(array $wireSizes, iterable $lines): array
    {
        $lengthLines = [];

        foreach ($lines as [$description, $unit, $quantity]) {
            if (in_array(mb_strtolower(trim((string) $unit)), self::LENGTH_UNITS, true)) {
                $lengthLines[] = [(string) $description, (float) $quantity];
            }
        }

        return array_map(function (array $wire) use ($lengthLines) {
            $needle = self::key((string) ($wire['size'] ?? ''));
            $length = $needle === '' ? null : self::lengthFor($needle, $lengthLines);

            return $wire + [
                'length' => $length === null ? null : round($length, 2),
                'lengthUnit' => $length === null ? null : 'ft',
            ];
        }, $wireSizes);
    }

    /**
     * Exact matches first: a line is "CATEGORY — SIZE", and the size after the
     * dash has to *be* this size. Only when no line is named exactly that does
     * a looser "contains" match apply — otherwise "#10 THHN" would quietly
     * collect the length of "#10 THHN SOLID" as well.
     *
     * @param  array<int, array{0: string, 1: float}>  $lengthLines
     */
    private static function lengthFor(string $needle, array $lengthLines): ?float
    {
        $exact = null;
        $loose = null;

        foreach ($lengthLines as [$description, $quantity]) {
            $segments = preg_split('/\s[—–-]\s/u', $description) ?: [$description];

            if (self::key((string) end($segments)) === $needle || self::key($description) === $needle) {
                $exact = ($exact ?? 0) + $quantity;
            } elseif (str_contains(self::key($description), $needle)) {
                $loose = ($loose ?? 0) + $quantity;
            }
        }

        return $exact ?? $loose;
    }

    /** Case, spacing and punctuation carry no meaning in "#10 THHN SOLID" vs "#10 thhn-solid". */
    private static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9\/]/', '', mb_strtolower($value)) ?? '';
    }
}
