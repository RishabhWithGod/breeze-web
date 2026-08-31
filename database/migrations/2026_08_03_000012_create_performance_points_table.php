<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Monthly performance series behind the dashboard column chart. */
    public function up(): void
    {
        Schema::create('performance_points', function (Blueprint $table) {
            $table->id();
            $table->string('month', 12);
            /** Indexed score, 0–100. */
            $table->unsignedTinyInteger('value');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_points');
    }
};
