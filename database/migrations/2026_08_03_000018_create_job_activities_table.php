<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail behind the job activity timeline. Every mutating
     * action on a job writes one row here.
     */
    public function up(): void
    {
        Schema::create('job_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /** created | updated | status_changed | archived | … — see Job::ACTIVITY_*. */
            $table->string('type')->index();
            $table->string('description');
            /** Extra context, e.g. {"from":"planning","to":"scheduled"}. */
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_activities');
    }
};
