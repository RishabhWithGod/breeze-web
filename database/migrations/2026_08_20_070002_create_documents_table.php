<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The canonical file/document record for the Documents module. Distinct
     * from `job_attachments` and `job_task_attachments` (older, narrower
     * per-entity attachment lists that already ship their own panels) and
     * from `uploads` (the AI Takeoff engine's own working file, tied to a
     * Project's processing pipeline) — this table is the one new, cross-cutting
     * store the Documents screen reads and writes. Where a document represents
     * a drawing already tracked by AI Takeoff, `upload_id` points at that
     * existing `uploads` row instead of a second copy of the bytes.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('document_type')->default('Other')->index();

            $table->foreignId('job_id')->nullable()->constrained('work_jobs')->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();
            // Set when this row represents a drawing the AI Takeoff engine already
            // tracks — the file lives once, under `uploads`, and this just points at it.
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Version chain: every revision after the first points `version_root_id`
            // at the original row's id, so the whole family is one query away.
            $table->unsignedBigInteger('version_root_id')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_latest')->default(true);

            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->string('visibility')->default('team');
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_id', 'is_archived']);
            $table->index(['is_archived', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
