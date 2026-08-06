<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable estimate lines, grouped by category (material, fixture, labor,
 * equipment). Generated from the final symbol counts and freely editable after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('final_symbol_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category');
            $table->string('description');
            $table->string('unit')->default('ea');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // 'ai' lines came from the takeoff; 'manual' lines were added by hand.
            $table->string('source')->default('ai');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
    }
};
