<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Takeoff projects — one row per run, backing both history and results. */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client');
            $table->string('drawing_name')->nullable();
            $table->string('discipline')->default('Electrical');
            $table->string('status')->index();
            /** Total devices counted across every sheet. */
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            /** 0–1 model confidence across all detected symbol classes. */
            $table->decimal('overall_confidence', 4, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
