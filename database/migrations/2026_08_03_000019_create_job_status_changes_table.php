<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dedicated status trail, so history survives independently of activity. */
    public function up(): void
    {
        Schema::create('job_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /** Null on the row written when the job is first created. */
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_status_changes');
    }
};
