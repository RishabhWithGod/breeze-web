<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sites a client has work at.
 *
 * A client used to carry one address on `projects.location`, which is only true
 * of the smallest ones — a client with three buildings had nowhere to put the
 * other two. Their addresses live here now, and a job picks the ones it is
 * actually at.
 *
 * `projects.location` stays as the primary address's snapshot: it is what every
 * list, search and existing screen already reads, and the backfill below keeps
 * the two in step from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            /** What to call this site — "Main building", "Warehouse". Optional. */
            $table->string('label')->nullable();
            $table->string('address');
            // Set only when the address was picked from the lookup — see
            // the address lookup. Never half of a pair.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            /** The one a job defaults to, and the one mirrored onto the client. */
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        // Every client that already had an address keeps it, as its primary.
        DB::table('projects')
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($clients) {
                $now = now();

                DB::table('client_addresses')->insert(
                    $clients->map(fn ($client) => [
                        'project_id' => $client->id,
                        'label' => null,
                        'address' => $client->location,
                        'latitude' => $client->latitude,
                        'longitude' => $client->longitude,
                        'is_primary' => true,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
