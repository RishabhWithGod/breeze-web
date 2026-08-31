<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pre-formatted headline figures shown above the results dashboard. */
    public function up(): void
    {
        Schema::create('project_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            $table->string('delta')->nullable();
            /** up | down | flat */
            $table->string('trend')->nullable();
            $table->string('hint')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_metrics');
    }
};
