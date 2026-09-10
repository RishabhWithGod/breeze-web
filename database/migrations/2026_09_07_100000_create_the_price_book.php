<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own rates, taken from the estimates it has already priced.
 *
 * Until now every price in this system was a guess written into code: the
 * engine's `PRICE_BOOK` dict (thirteen items and a $20 default) and Laravel's
 * `ai.estimating.labor_rate`. Neither has ever seen a real job. The estimating
 * workbooks have — hundreds of lines an estimator priced by hand, each with a
 * material rate *and* the manhours it takes to install, which is the half no
 * code here has had at all.
 *
 * Three tables, because they answer three different questions:
 *
 *   `price_book_imports` — which workbook, priced when, at what rates and to
 *                          what bid total. One row per file.
 *   `price_book_lines`   — every line of every workbook, exactly as it was
 *                          written. Never summarised, never corrected: this is
 *                          the audit trail a rate can always be traced back to.
 *   `price_book_items`   — the rate this system will actually quote. One row
 *                          per item, derived from the lines above.
 *
 * The middle table is what makes the last one trustworthy. The same item is
 * priced differently on different jobs — "3/4 CONDUIT - EMT" appears 28 times
 * across ten workbooks — so a single-table design would have to pick one number
 * and silently lose the twenty-seven it did not pick. Here the chosen rate can
 * always be shown beside every rate it was chosen from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_book_imports')) {
            Schema::create('price_book_imports', function (Blueprint $table) {
                $table->id();

                $table->string('file_name', 255);
                /*
                 * The file's own contents, hashed. Re-importing the same
                 * workbook replaces its lines rather than doubling them, and a
                 * workbook that has been re-priced since is a different hash
                 * and so a genuinely new import.
                 */
                $table->string('file_hash', 64)->unique();
                $table->string('project_name', 255)->nullable();

                // The Bid Recap sheet's own totals, kept so an imported rate
                // can be checked against the bid it came from.
                $table->decimal('material_cost', 14, 2)->nullable();
                $table->decimal('labor_cost', 14, 2)->nullable();
                $table->decimal('material_tax', 14, 2)->nullable();
                $table->decimal('total_cost', 14, 2)->nullable();
                $table->decimal('base_bid_price', 14, 2)->nullable();

                // The rates that workbook was priced at. Percentages are stored
                // as percentages (7.5 means 7.5%), never as fractions — the
                // sheets mix both and one of them has to win here.
                $table->decimal('material_tax_pct', 6, 3)->nullable();
                $table->decimal('overhead_pct', 6, 3)->nullable();
                $table->decimal('profit_pct', 6, 3)->nullable();
                $table->decimal('electrician_rate', 10, 2)->nullable();
                $table->decimal('supervisor_rate', 10, 2)->nullable();
                $table->decimal('unskilled_rate', 10, 2)->nullable();
                $table->decimal('composite_labor_rate', 10, 2)->nullable();
                $table->decimal('total_manhours', 12, 3)->nullable();

                $table->unsignedInteger('line_count')->default(0);
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('price_book_lines')) {
            Schema::create('price_book_lines', function (Blueprint $table) {
                $table->id();

                // Removing an import takes its lines with it: a line has no
                // meaning apart from the workbook it was read from.
                $table->foreignId('price_book_import_id')
                    ->constrained('price_book_imports')
                    ->cascadeOnDelete();

                /*
                 * Where the line sat in the workbook. The sheets group work the
                 * way an estimator reads it — DISTRIBUTION → BREAKERS, BRANCH
                 * WIRING → CONDUITS - LIGHTING — and that grouping is the only
                 * thing telling a "3/4 CONDUIT" run for lighting apart from the
                 * identical one for power.
                 */
                $table->string('section', 120)->nullable();
                $table->string('subsection', 120)->nullable();

                $table->string('sr_no', 24)->nullable();
                $table->string('dwg_no', 60)->nullable();
                $table->string('detail_no', 60)->nullable();
                $table->text('description');

                $table->decimal('quantity', 14, 4)->nullable();
                $table->decimal('wastage', 14, 4)->nullable();
                $table->decimal('quantity_with_wastage', 14, 4)->nullable();
                $table->string('unit', 24)->nullable();

                // Four decimals throughout: conduit and conductor rates are
                // fractions of a cent per foot, and rounding them to cents on
                // the way in would change every total built from them.
                $table->decimal('unit_material_cost', 14, 4)->nullable();
                $table->decimal('material_cost', 14, 4)->nullable();
                $table->decimal('manhour_rate', 10, 2)->nullable();
                $table->decimal('unit_manhours', 12, 6)->nullable();
                $table->decimal('total_manhours', 14, 4)->nullable();
                $table->decimal('manhours_cost', 14, 4)->nullable();
                $table->decimal('total_cost', 14, 4)->nullable();

                // The row it came from, so a figure can be found in the file.
                $table->unsignedInteger('source_row')->nullable();

                // What the line is matched on: the description, upper-cased and
                // with its runs of whitespace collapsed. Stored rather than
                // computed on read, because every lookup goes through it.
                $table->string('match_key', 255)->index();
            });
        }

        if (! Schema::hasTable('price_book_items')) {
            Schema::create('price_book_items', function (Blueprint $table) {
                $table->id();

                // One row per item per unit: the same name priced per foot and
                // per each is two different rates, not one disagreeing with
                // itself.
                $table->string('match_key', 255);
                $table->string('unit', 24);
                $table->unique(['match_key', 'unit']);

                // The description as an estimator wrote it, for showing.
                $table->string('description', 255);
                $table->string('section', 120)->nullable();
                $table->string('subsection', 120)->nullable();

                /*
                 * What this system quotes. Seeded from the lines and editable
                 * afterwards — an estimator correcting a rate here must not be
                 * overwritten by the next import, which is what `is_pinned` is
                 * for.
                 */
                $table->decimal('unit_material_cost', 14, 4)->nullable();
                $table->decimal('unit_manhours', 12, 6)->nullable();

                // The spread behind that figure, so nobody has to trust it
                // blind: how many lines it was derived from, and their range.
                $table->unsignedInteger('sample_count')->default(0);
                $table->decimal('min_material_cost', 14, 4)->nullable();
                $table->decimal('max_material_cost', 14, 4)->nullable();
                $table->decimal('min_manhours', 12, 6)->nullable();
                $table->decimal('max_manhours', 12, 6)->nullable();

                // Set by hand: this rate is ours now, leave it alone.
                $table->boolean('is_pinned')->default(false);

                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_book_lines');
        Schema::dropIfExists('price_book_items');
        Schema::dropIfExists('price_book_imports');
    }
};
