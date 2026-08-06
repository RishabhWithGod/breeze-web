<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for a takeoff: every approve, reject, rename, count
 * change, merge, split, note, finalisation, job/estimate creation and
 * assignment. Nothing in this table is ever updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_review_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action');
            $table->string('subject')->nullable();
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ai_result_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_histories');
    }
};
