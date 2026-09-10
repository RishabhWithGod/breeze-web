<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line of one workbook, exactly as the estimator wrote it.
 *
 * Never edited and never summarised. Everything the price book quotes is
 * derived from these rows, so they are the record the derivation can always be
 * checked against.
 */
class PriceBookLine extends Model
{
    // Read-only history: a line is written once, at import.
    public $timestamps = false;

    protected $fillable = [
        'price_book_import_id', 'section', 'subsection',
        'sr_no', 'dwg_no', 'detail_no', 'description',
        'quantity', 'wastage', 'quantity_with_wastage', 'unit',
        'unit_material_cost', 'material_cost', 'manhour_rate',
        'unit_manhours', 'total_manhours', 'manhours_cost', 'total_cost',
        'source_row', 'match_key',
    ];

    /**
     * How an item's name becomes the key everything is matched on.
     *
     * Upper-cased, whitespace collapsed, and the stray punctuation the sheets
     * carry trimmed off the ends — the same conduit is written `  3/4" CONDUIT
     * - EMT` on one job and `3/4" Conduit - EMT ` on the next, and they have to
     * land on one rate.
     */
    public static function keyFor(string $description): string
    {
        return trim(mb_strtoupper(self::clean($description)), " \t\n\r\0\x0B-–—:.");
    }

    /**
     * A description as it should be stored and read.
     *
     * Excel writes a line break inside a cell as the literal text `_x000D_`,
     * and the fixture schedules are full of them — left alone they end up in
     * the middle of an item name and in the key it is matched on, so the same
     * fixture on two drawings would never meet.
     */
    public static function clean(string $description): string
    {
        $decoded = preg_replace('/_x([0-9A-Fa-f]{4})_/', ' ', $description);

        return trim(preg_replace('/\s+/', ' ', $decoded));
    }

    /** @return BelongsTo<PriceBookImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceBookImport::class, 'price_book_import_id');
    }
}
