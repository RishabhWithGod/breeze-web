<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's own vendor rate list — nothing shared, nothing pooled across a
 * manager's whole account.
 *
 * Mirrors the shape of the price book (`2026_09_07_100000_create_the_price_
 * book.php`) for the same reason that one does: a rate is only trustworthy
 * when it can be traced back to the line it came from, and the same item can
 * appear more than once in one workbook. But an estimate no longer reads the
 * price book at all — it reads this, scoped to the one project the workbook
 * was uploaded for, and nothing else.
 *
 *   `project_rate_imports` — which workbook, uploaded for which project.
 *   `project_rate_lines`   — every line of it, exactly as written.
 *   `project_rate_items`   — the rate an estimate actually quotes, one row
 *                            per item per unit, derived from the lines above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_rate_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('file_name', 255);
            // Re-uploading the same file for the same project replaces its
            // lines rather than doubling them.
            $table->string('file_hash', 64);
            $table->string('project_name', 255)->nullable();

            // The Bid Recap sheet's rates — read off this project's own
            // workbook, not a company-wide default.
            $table->decimal('material_tax_pct', 6, 3)->nullable();
            $table->decimal('overhead_pct', 6, 3)->nullable();
            $table->decimal('profit_pct', 6, 3)->nullable();
            $table->decimal('electrician_rate', 10, 2)->nullable();
            $table->decimal('supervisor_rate', 10, 2)->nullable();
            $table->decimal('unskilled_rate', 10, 2)->nullable();
            $table->decimal('composite_labor_rate', 10, 2)->nullable();
            $table->decimal('total_manhours', 12, 3)->nullable();
            $table->decimal('material_cost', 14, 2)->nullable();
            $table->decimal('labor_cost', 14, 2)->nullable();
            $table->decimal('material_tax', 14, 2)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->decimal('base_bid_price', 14, 2)->nullable();

            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'file_hash']);
        });

        Schema::create('project_rate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_rate_import_id')->constrained()->cascadeOnDelete();
            // Denormalised so a line can be queried straight off the project
            // without a join through its import.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

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

            $table->decimal('unit_material_cost', 14, 4)->nullable();
            $table->decimal('material_cost', 14, 4)->nullable();
            $table->decimal('manhour_rate', 10, 2)->nullable();
            $table->decimal('unit_manhours', 12, 6)->nullable();
            $table->decimal('total_manhours', 14, 4)->nullable();
            $table->decimal('manhours_cost', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 4)->nullable();

            $table->unsignedInteger('source_row')->nullable();
            $table->string('match_key', 255)->index();
        });

        Schema::create('project_rate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('match_key', 255);
            $table->string('unit', 24);
            $table->unique(['project_id', 'match_key', 'unit']);

            $table->string('description', 255);
            $table->string('section', 120)->nullable();
            $table->string('subsection', 120)->nullable();

            $table->decimal('unit_material_cost', 14, 4)->nullable();
            $table->decimal('unit_manhours', 12, 6)->nullable();

            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('min_material_cost', 14, 4)->nullable();
            $table->decimal('max_material_cost', 14, 4)->nullable();
            $table->decimal('min_manhours', 12, 6)->nullable();
            $table->decimal('max_manhours', 12, 6)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_rate_lines');
        Schema::dropIfExists('project_rate_items');
        Schema::dropIfExists('project_rate_imports');
    }
};
