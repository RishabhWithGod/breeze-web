<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a line as the one that mirrors a job's actual Labor/Material/
 * Equipment/Other cost — see `ActualCostInvoiceSync`. Null for every other
 * line (copied from an estimate, or typed by hand), which behave exactly as
 * they always have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('source_category')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('source_category');
        });
    }
};
