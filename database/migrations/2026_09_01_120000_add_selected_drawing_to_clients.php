<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a client's drawing PDFs a takeoff should run against.
 *
 * Until now that was always the oldest one on record — a client with several
 * drawings had no way to say "this one", so the second PDF anyone added was
 * unusable. This records the choice.
 *
 * Nullable, and stays so: a client with one drawing has nothing to choose, and
 * `nullOnDelete` means deleting the chosen PDF clears the choice rather than
 * leaving it pointing at a row that is gone. Both cases fall back to the first
 * drawing on record — see `Project::takeoffDrawing()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'selected_upload_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('selected_upload_id')->nullable()->after('drawing_name')
                ->constrained('uploads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'selected_upload_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_upload_id');
        });
    }
};
