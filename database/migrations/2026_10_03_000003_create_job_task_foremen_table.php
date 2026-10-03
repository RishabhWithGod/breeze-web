<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everyone a task is given to — as many journeymen and foremen as it needs.
 *
 * A task used to name exactly one person running it (`job_tasks.foreman_id`)
 * and one over it (`supervisor_id`). Those two columns stay, as the *first*
 * person in each slot, so everything reading them keeps working; this table
 * is the full list. `slot` says which side a person is on for this task:
 * `runner` (a journeyman doing the work) or `overseer` (a foreman over it).
 *
 * Existing tasks are carried over from their two columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_task_foremen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->string('slot', 12);
            $table->timestamps();

            $table->unique(['job_task_id', 'foreman_id', 'slot']);
            $table->index('foreman_id');
        });

        $now = now();

        foreach (['foreman_id' => 'runner', 'supervisor_id' => 'overseer'] as $column => $slot) {
            DB::table('job_tasks')->whereNotNull($column)->orderBy('id')->each(function ($task) use ($column, $slot, $now) {
                DB::table('job_task_foremen')->insertOrIgnore([
                    'job_task_id' => $task->id,
                    'foreman_id' => $task->{$column},
                    'slot' => $slot,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_task_foremen');
    }
};
