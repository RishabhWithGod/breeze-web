<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links the existing projects / uploads / jobs / estimates tables into the
 * takeoff workflow, and gives uploads somewhere to record every artefact
 * derived from the drawing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->unsignedSmallInteger('page_count')->default(0)->after('format');
            // Artefacts: rendered previews, the thumbnail shown in lists, the
            // annotated copy and the signed-off final PDF.
            $table->string('thumbnail_path')->nullable()->after('path');
            $table->json('preview_paths')->nullable()->after('thumbnail_path');
            $table->string('annotated_path')->nullable()->after('preview_paths');
            $table->string('final_path')->nullable()->after('annotated_path');
        });

        Schema::table('projects', function (Blueprint $table) {
            // Where the run has reached in the review workflow, so history rows
            // can link straight to the right screen.
            $table->string('review_status')->default('none')->after('status');
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('foreman_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('ai_result_id')->nullable()->after('project_id');
            // Quantities and bill of quantities copied from the final JSON, so a
            // job keeps the numbers it was created from.
            $table->json('symbol_counts')->nullable()->after('description');
            $table->json('boq')->nullable()->after('symbol_counts');
            $table->json('metadata')->nullable()->after('boq');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('job_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('ai_result_id')->nullable()->after('project_id');

            $table->decimal('material_total', 14, 2)->default(0)->after('amount');
            $table->decimal('labor_total', 14, 2)->default(0)->after('material_total');
            $table->decimal('equipment_total', 14, 2)->default(0)->after('labor_total');
            $table->decimal('subtotal', 14, 2)->default(0)->after('equipment_total');
            $table->decimal('markup_pct', 6, 2)->default(0)->after('subtotal');
            $table->decimal('markup_total', 14, 2)->default(0)->after('markup_pct');
            $table->decimal('tax_pct', 6, 2)->default(0)->after('markup_total');
            $table->decimal('tax_total', 14, 2)->default(0)->after('tax_pct');
            $table->decimal('grand_total', 14, 2)->default(0)->after('tax_total');
            $table->text('notes')->nullable()->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn([
                'page_count', 'thumbnail_path', 'preview_paths', 'annotated_path', 'final_path',
            ]);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('review_status');
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['ai_result_id', 'symbol_counts', 'boq', 'metadata']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn([
                'ai_result_id', 'material_total', 'labor_total', 'equipment_total',
                'subtotal', 'markup_pct', 'markup_total', 'tax_pct', 'tax_total',
                'grand_total', 'notes',
            ]);
        });
    }
};
