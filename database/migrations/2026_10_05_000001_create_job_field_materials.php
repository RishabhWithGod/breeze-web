<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the crew actually used or added on site, kept apart from the planned estimate
        // lines and from approved change orders. A row with an `estimate_item_id` is the actual
        // quantity used against that planned line (one per line); a row without one is a material
        // the crew added that was never in the plan.
        Schema::create('job_field_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('job_task_id')->nullable()->constrained('job_tasks')->nullOnDelete();
            $table->foreignId('estimate_item_id')->nullable()->unique()->constrained('estimate_items')->cascadeOnDelete();
            $table->unsignedBigInteger('price_book_item_id')->nullable();
            // Set by the phone for an added material, so a replayed submit never adds it twice.
            $table->string('client_key', 64)->nullable();
            $table->string('description');
            $table->string('unit', 24)->nullable();
            $table->decimal('actual_quantity', 14, 4);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'client_key']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_field_materials');
    }
};
