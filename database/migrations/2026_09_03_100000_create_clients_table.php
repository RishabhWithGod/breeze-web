<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client, as a record of its own.
 *
 * Clients and projects were the same row until now. They are not the same
 * thing: a client is who the work is for, and they have several projects over
 * the years. `projects.client` already held the client's name — several
 * projects already shared one — so the relationship existed in the data long
 * before it existed in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients')) {
            return;
        }

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A client is named once per workspace.
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
