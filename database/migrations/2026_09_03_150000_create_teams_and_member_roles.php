<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crews, and what each person does on one.
 *
 * The register used to be a flat list of foremen. Work is actually staffed by
 * crew: a team with a supervisor over it and foremen under them. So a team is a
 * record of its own, and every person on the register belongs to one and has a
 * role on it.
 *
 * Both are nullable and nothing is backfilled. Everyone already on the register
 * was added before teams existed, and inventing a crew for them would be
 * making up who works with whom. They sit outside any team until somebody says
 * otherwise, and the register shows them as exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                // One crew per name: two "North Crew" rows would be a register
                // nobody can read.
                $table->string('name', 120)->unique();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('foremen', function (Blueprint $table) {
            if (! Schema::hasColumn('foremen', 'team_id')) {
                /*
                 * Disbanding a crew does not sack anyone. The people stay on
                 * the register with no team, which is a state the list already
                 * has to draw for everyone added before teams existed.
                 */
                $table->foreignId('team_id')->nullable()->after('initials')
                    ->constrained('teams')->nullOnDelete();
            }

            if (! Schema::hasColumn('foremen', 'role')) {
                // A string rather than an enum, so adding a third role later is
                // a code change and not a table rebuild.
                $table->string('role', 24)->default('foreman')->after('team_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foremen', function (Blueprint $table) {
            if (Schema::hasColumn('foremen', 'team_id')) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            }

            if (Schema::hasColumn('foremen', 'role')) {
                $table->dropColumn('role');
            }
        });

        Schema::dropIfExists('teams');
    }
};
