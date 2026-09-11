<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The budget the estimate is meant to land on, set once at intake.
            // Optional: most projects still price off the takeoff alone.
            $table->decimal('estimate_target_total', 12, 2)->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('estimate_target_total');
        });
    }
};
