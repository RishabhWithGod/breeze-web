<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->timestamp('ready_for_review_at')->nullable()->after('delay_reason');
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn('ready_for_review_at');
        });
    }
};
