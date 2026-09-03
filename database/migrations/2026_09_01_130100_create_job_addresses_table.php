<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of its client's sites a job is actually at.
 *
 * A job can run across more than one — a fit-out over two floors of the same
 * campus is one job at two addresses — so this is a set rather than a column.
 *
 * `work_jobs.location` stays as the first one's snapshot, because scheduling,
 * time tracking and every job list already read it and a job's address on a
 * printed sheet must not change when someone edits the client's record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('client_address_id')->constrained('client_addresses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // A job is at a site once, not twice.
            $table->unique(['job_id', 'client_address_id']);
        });

        /*
         * Existing jobs point at whichever of their client's addresses matches
         * the location they already recorded. A job whose location was typed
         * before the client had that site keeps its snapshot and simply has no
         * link — the same fallback the rest of this merge uses.
         */
        DB::table('work_jobs')
            ->join('client_addresses', function ($join) {
                $join->on('client_addresses.project_id', '=', 'work_jobs.project_id')
                    ->on('client_addresses.address', '=', 'work_jobs.location');
            })
            ->whereNull('work_jobs.deleted_at')
            ->select('work_jobs.id as job_id', 'client_addresses.id as client_address_id')
            ->orderBy('work_jobs.id')
            ->chunk(200, function ($rows) {
                $now = now();

                DB::table('job_addresses')->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'job_id' => $row->job_id,
                        'client_address_id' => $row->client_address_id,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_addresses');
    }
};
