<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For a line cloned into a `merged` estimate, the line it was cloned from —
 * on the original estimate or one of its addenda. Alongside `final_symbol_id`
 * (which traces an AI line back to its takeoff run), this keeps a merged
 * job's lines traceable to the PDF/Addendum they actually came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->foreignId('source_estimate_item_id')->nullable()->after('final_symbol_id')
                ->constrained('estimate_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_estimate_item_id');
        });
    }
};
