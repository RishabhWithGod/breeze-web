<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Estimate Builder's worksheet: one row per thing being priced, with its
        // material and its labor side by side, before it becomes lines on the estimate.
        Schema::create('estimate_builder_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->string('commodity')->nullable();
            $table->string('unit', 20)->default('EA');
            $table->decimal('material_qty', 14, 4)->default(0);
            $table->decimal('material_unit_price', 14, 4)->default(0);
            $table->decimal('labor_hours', 14, 4)->default(0);
            $table->decimal('labor_rate', 14, 2)->default(0);
            $table->decimal('markup_pct', 6, 2)->default(0);
            // Where the row came from, so the takeoff and the price list it was priced
            // from are never lost: typed in, copied from a takeoff, or picked from the list.
            $table->string('source', 20)->default('manual');
            $table->foreignId('source_estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->foreignId('price_book_item_id')->nullable()->constrained('price_book_items')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('estimates', function (Blueprint $table) {
            // An estimate the builder owns: its lines come from the worksheet.
            $table->boolean('builder_managed')->default(false)->after('kind');
            // The takeoff (another estimate) its lines were imported from.
            $table->foreignId('takeoff_source_estimate_id')->nullable()->after('builder_managed')
                ->constrained('estimates')->nullOnDelete();
            // Which price list it was priced from — the book as it stood when it was built.
            $table->string('commodity_version')->nullable()->after('takeoff_source_estimate_id');
            $table->decimal('builder_labor_rate', 8, 2)->default(65)->after('commodity_version');
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            // A markup of this line's own; empty means the estimate's.
            $table->decimal('markup_pct', 6, 2)->nullable()->after('total');
            // The builder row this line was made from.
            $table->foreignId('builder_line_id')->nullable()->after('markup_pct')
                ->constrained('estimate_builder_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('builder_line_id');
            $table->dropColumn('markup_pct');
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('takeoff_source_estimate_id');
            $table->dropColumn(['builder_managed', 'commodity_version', 'builder_labor_rate']);
        });
        Schema::dropIfExists('estimate_builder_lines');
    }
};
