<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the Projects module collects up front.
 *
 * A project created from the AI Takeoff upload screen is named after its drawing
 * and knows nothing else. The Projects module creates one deliberately — a
 * number, a site, a type and a due date — before any drawing is analysed, so the
 * intake details live on the project rather than being inferred later.
 *
 * `uploads.title` is the label a reviewer gives a drawing ("Ground floor
 * lighting"), kept separate from `name`, which stays the file's own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            /** The client's own project number, when they use one. */
            $table->string('code', 60)->nullable()->after('name');
            $table->string('location')->nullable()->after('client');
            /** residential | commercial | industrial — same taxonomy as jobs. */
            $table->string('project_type')->nullable()->after('discipline');
            $table->date('due_date')->nullable()->after('started_at');
        });

        Schema::table('uploads', function (Blueprint $table) {
            $table->string('title')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['code', 'location', 'project_type', 'due_date']);
        });

        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
