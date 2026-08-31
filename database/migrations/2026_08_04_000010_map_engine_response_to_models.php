<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every field the AI engine returns gets a home.
 *
 * `AnalysisResult` carries far more than symbol counts — panel schedules,
 * equipment, wire sizes, circuits, its own bill of quantities and estimate,
 * warnings and per-stage pipeline status. Scalars and maps land on `ai_results`;
 * the repeated structures get their own tables so they can be queried, filtered
 * and rendered without unpacking JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_results', function (Blueprint $table) {
            // The engine's own identifier for the run, resolved from its
            // lifecycle endpoint. Needed to fetch crop images.
            $table->string('run_id')->nullable()->after('upload_id')->index();
            $table->string('project_name')->nullable()->after('run_id');

            $table->float('processing_time')->nullable()->after('overall_confidence');
            // { legend: parsed, template: ok, vector: ok, vision: ok, tables: ok }
            $table->json('pipeline_status')->nullable()->after('processing_time');
            $table->json('warnings')->nullable()->after('pipeline_status');
            // { "switch": 27, … } exactly as returned.
            $table->json('symbol_counts')->nullable()->after('warnings');
            // The engine's estimate block: subtotal, tax_rate, tax, grand_total…
            $table->json('ai_estimate')->nullable()->after('symbol_counts');
            // Crop tallies from the lifecycle endpoint.
            $table->json('lifecycle_statistics')->nullable()->after('ai_estimate');
        });

        Schema::table('symbol_reviews', function (Blueprint $table) {
            // Which part of the response this card came from.
            $table->string('origin')->default('symbol')->after('external_id');
            // The engine's own classification: known | unknown | rejected | needs-review
            $table->string('ai_category')->nullable()->after('origin');
            // Why the engine flagged it (discovery, disagreement, …).
            $table->string('reason')->nullable()->after('ai_category');
            // Corroborating signals: legend, template, vector, vision, tables.
            $table->json('evidence')->nullable()->after('pipeline');

            // Representative crop, from the lifecycle endpoint.
            $table->string('crop_id')->nullable()->after('crop_url');
            $table->string('image_id')->nullable()->after('crop_id');
            $table->string('image_path')->nullable()->after('image_id');
            $table->unsignedInteger('crop_count')->default(0)->after('image_path');
            $table->string('final_decision')->nullable()->after('crop_count');
            $table->string('detection_source')->nullable()->after('final_decision');
            // Stage trail as the engine reports it: [{name, status}, …]
            $table->json('stages')->nullable()->after('detection_source');
        });

        Schema::create('panel_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('panel_name')->default('');
            $table->json('rows')->nullable();
            $table->json('raw_headers')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('tag')->default('');
            $table->string('description')->default('');
            $table->string('rating')->default('');
            $table->unsignedInteger('quantity')->default(1);
            $table->json('extra')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('wire_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('size');
            $table->string('context')->default('');
            $table->unsignedInteger('count')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['ai_result_id', 'size']);
        });

        Schema::create('circuits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('number');
            $table->string('description')->default('');
            $table->string('breaker')->default('');
            $table->string('panel')->default('');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        /* The engine's priced bill of quantities, kept as returned. */
        Schema::create('boq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            $table->string('description')->default('');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->string('unit')->default('ea');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            // Set when the line could be matched to a reviewed symbol, so the
            // estimate can follow the reviewed quantity instead of the AI's.
            $table->foreignId('final_symbol_id')->nullable();
            $table->string('matched_symbol')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_lines');
        Schema::dropIfExists('circuits');
        Schema::dropIfExists('wire_sizes');
        Schema::dropIfExists('equipment_items');
        Schema::dropIfExists('panel_schedules');

        Schema::table('symbol_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'origin', 'ai_category', 'reason', 'evidence', 'crop_id', 'image_id',
                'image_path', 'crop_count', 'final_decision', 'detection_source', 'stages',
            ]);
        });

        Schema::table('ai_results', function (Blueprint $table) {
            $table->dropColumn([
                'run_id', 'project_name', 'processing_time', 'pipeline_status',
                'warnings', 'symbol_counts', 'ai_estimate', 'lifecycle_statistics',
            ]);
        });
    }
};
