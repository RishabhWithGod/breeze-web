<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of its client's sites a project is at.
 *
 * The same shape as `job_addresses`: the client keeps one address book, and
 * the work picks from it rather than retyping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_addresses')) {
            Schema::create('project_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('client_address_id')->constrained('client_addresses')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                // A project is at a site once, not twice.
                $table->unique(['project_id', 'client_address_id']);
            });
        }

        /*
         * Existing projects point at whichever of their client's sites matches
         * the location they already recorded. A project whose location was
         * typed before the client had that site keeps its snapshot and simply
         * has no link — the same fallback the rest of this split uses.
         */
        DB::table('projects')
            ->join('client_addresses', function ($join) {
                $join->on('client_addresses.client_id', '=', 'projects.client_id')
                    ->on('client_addresses.address', '=', 'projects.location');
            })
            ->whereNull('projects.deleted_at')
            ->select('projects.id as project_id', 'client_addresses.id as client_address_id')
            ->orderBy('projects.id')
            ->chunk(200, function ($rows) {
                $now = now();

                DB::table('project_addresses')->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'project_id' => $row->project_id,
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
        Schema::dropIfExists('project_addresses');
    }
};
