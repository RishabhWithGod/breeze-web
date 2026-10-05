<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A detection is approved unless the reviewer rejects it — nothing sits
 * undecided. Reviews still pending on a takeoff that has not been signed off
 * become approved; signed-off ones keep the record they were finalised with.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('symbol_reviews')
            ->where('status', 'pending')
            ->whereIn('ai_result_id', DB::table('ai_results')->where('review_status', '!=', 'finalised')->select('id'))
            ->update(['status' => 'approved']);

        Schema::table('symbol_reviews', function (Blueprint $table) {
            $table->string('status')->default('approved')->change();
        });
    }

    public function down(): void
    {
        Schema::table('symbol_reviews', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }
};
