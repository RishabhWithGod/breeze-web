<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real actual material/equipment/other costs logged against a job.
 *
 * There is no purchasing, inventory or PO system anywhere in this app, so
 * "actual material cost" has nowhere to come from unless a person records
 * it — this table is that record, the minimum needed rather than a full
 * costing ledger. Labor's actual cost already has a real source
 * (`time_entries.labor_cost` on approved rows via `JobLaborSummary`), so it
 * is deliberately not duplicated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->string('category');
            $table->string('description');
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('incurred_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_cost_entries');
    }
};
