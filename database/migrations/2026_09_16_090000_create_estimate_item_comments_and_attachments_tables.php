<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note or photo about ONE material line, not the whole task — mirrors
 * `job_task_comments`/`job_task_attachments` exactly, just scoped one level
 * finer. Deliberately its own pair of tables rather than a nullable
 * `estimate_item_id` bolted onto the task-scoped ones: the mobile Materials
 * screen moved entirely to per-material notes/photos (there is no longer a
 * task-wide note/photo composer), so there is no case where one row needs to
 * mean both "about this task" and "about this material" at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('estimate_item_comments')) {
            Schema::create('estimate_item_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('estimate_item_id')->constrained('estimate_items')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('body');
                $table->timestamps();

                $table->index(['estimate_item_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('estimate_item_attachments')) {
            Schema::create('estimate_item_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('estimate_item_id')->constrained('estimate_items')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('path');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_item_attachments');
        Schema::dropIfExists('estimate_item_comments');
    }
};
