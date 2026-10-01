<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Where a line came from: copied off the estimate, or typed in.
            $table->string('source', 16)->nullable()->after('source_category');
        });

        // Until now a line with a category was one copied from an estimate, and one
        // without was typed — so that is what the existing ones are.
        DB::table('invoice_items')->whereNotNull('source_category')->update(['source' => 'estimate']);
        DB::table('invoice_items')->whereNull('source_category')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
