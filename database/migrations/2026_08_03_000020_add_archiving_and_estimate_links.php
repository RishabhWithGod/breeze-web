<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving (distinct from the soft delete already on jobs) plus the real
     * job → estimate → project chain.
     */
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index()->after('notify_client');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('job_id')->nullable()->after('id')
                ->constrained('work_jobs')->nullOnDelete();
            /** Set when the estimate is converted into a takeoff project. */
            $table->foreignId('converted_project_id')->nullable()->after('status')
                ->constrained('projects')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_id');
            $table->dropConstrainedForeignId('converted_project_id');
            $table->dropColumn('converted_at');
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
