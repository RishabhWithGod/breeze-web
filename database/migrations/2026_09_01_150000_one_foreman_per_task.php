<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task is run by one foreman.
 *
 * It was modelled as a set, which let the schema hold two while the rule says
 * one — a column says what is actually true. Whoever was first on a task keeps
 * it; nothing is lost that the rule allowed in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('job_tasks', 'foreman_id')) {
            Schema::table('job_tasks', function (Blueprint $table) {
                $table->foreignId('foreman_id')->nullable()->after('job_id')
                    ->constrained('foremen')->nullOnDelete();
            });
        }

        if (Schema::hasTable('job_task_foremen')) {
            DB::table('job_tasks')
                ->join('job_task_foremen', 'job_task_foremen.job_task_id', '=', 'job_tasks.id')
                ->whereNull('job_tasks.foreman_id')
                ->update(['job_tasks.foreman_id' => DB::raw('job_task_foremen.foreman_id')]);

            Schema::drop('job_task_foremen');
        }
    }

    public function down(): void
    {
        Schema::create('job_task_foremen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['job_task_id', 'foreman_id']);
        });

        if (Schema::hasColumn('job_tasks', 'foreman_id')) {
            Schema::table('job_tasks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('foreman_id');
            });
        }
    }
};
