<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detected_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            /** Legend code, e.g. "L1". */
            $table->string('code');
            $table->string('name');
            $table->string('category');
            $table->unsignedInteger('count');
            /** 0–1 detection confidence for this symbol class. */
            $table->decimal('confidence', 4, 3);
            $table->string('unit', 8)->default('EA');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detected_symbols');
    }
};
