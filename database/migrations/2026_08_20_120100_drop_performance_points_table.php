<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's Monthly Performance chart used to read a fixed,
 * hand-seeded Jan–Dec series from this table — the same twelve numbers
 * forever, regardless of what actually happened. It now computes a rolling
 * on-time job completion rate from real job data (see
 * JobPerformanceCalculator), so nothing reads this table any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('performance_points');
    }

    public function down(): void
    {
        Schema::create('performance_points', function (Blueprint $table) {
            $table->id();
            $table->string('month', 12);
            $table->unsignedTinyInteger('value');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }
};
