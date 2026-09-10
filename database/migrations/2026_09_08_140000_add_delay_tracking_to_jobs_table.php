<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->decimal('actual_hours', 8, 2)->nullable()->after('estimated_hours');
            $table->decimal('delay_hours', 8, 2)->nullable()->after('actual_hours');
            $table->text('delay_reason')->nullable()->after('delay_hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn(['actual_hours', 'delay_hours', 'delay_reason']);
        });
    }
};
