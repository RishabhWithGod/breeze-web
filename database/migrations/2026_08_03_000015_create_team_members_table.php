<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The crew pool, plus the job ↔ member assignment pivot. */
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('initials', 4);
            /** Trade or job title, e.g. "Journeyman Electrician". */
            $table->string('role');
            $table->timestamps();
        });

        Schema::create('job_team_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            /** Optional override of the member's default role for this job. */
            $table->string('role_on_job')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'team_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_team_member');
        Schema::dropIfExists('team_members');
    }
};
