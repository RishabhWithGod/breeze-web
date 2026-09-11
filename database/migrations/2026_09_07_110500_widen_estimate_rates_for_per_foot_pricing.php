<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a rate that is a fraction of a cent.
 *
 * Conduit and conductor are priced per foot, and the estimating workbooks price
 * them to four decimals: 3/4" EMT is $0.8296/ft, #12 THHN is $0.1899/ft.
 * Rounded to cents on the way in, a three-thousand-foot run is quoted against a
 * rate that is out by up to half a percent — and the estimate then disagrees
 * with the workbook it was priced from, which is the one thing this must never
 * do.
 *
 * Quantities move for the same reason: a conduit run measured off a drawing is
 * 1019.11 feet, not 1019.11 rounded to anything.
 *
 * Widening only. Every existing value keeps its exact figure — two decimals of
 * a four-decimal column is the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('quantity', 14, 4)->change();
            $table->decimal('unit_cost', 14, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->change();
            $table->decimal('unit_cost', 12, 2)->change();
        });
    }
};
