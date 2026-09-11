<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a foreman/supervisor has actually done the work this line prices —
 * the mobile app's per-item checklist inside a task, one step finer than the
 * task's own overall `completion_pct`. Null until checked off; set to when,
 * not just whether, since "when did we actually pull this wire" is worth
 * keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (! Schema::hasColumn('estimate_items', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('job_task_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (Schema::hasColumn('estimate_items', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
