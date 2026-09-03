<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project does not keep its own list of sites.
 *
 * It was given one when the split was designed, and then the rule turned out to
 * be simpler: a project and the job on it are at the same place, and that place
 * is the client's primary site. Nothing writes to this table any more, and a
 * table nothing writes to is a second answer waiting to disagree with the
 * first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_addresses');
    }

    public function down(): void
    {
        Schema::create('project_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('client_address_id')->constrained('client_addresses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'client_address_id']);
        });
    }
};
