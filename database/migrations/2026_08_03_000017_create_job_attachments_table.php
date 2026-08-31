<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Files uploaded against a job; the bytes live on the `local` disk. */
    public function up(): void
    {
        Schema::create('job_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /** Original client filename, shown in the UI. */
            $table->string('name');
            /** Path on the storage disk. */
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->string('mime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_attachments');
    }
};
