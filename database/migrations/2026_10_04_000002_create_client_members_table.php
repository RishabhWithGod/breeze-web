<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who on the crew register is this client's own people — independent of any
 * team, so picking a client's crew on Add Client no longer has to assign
 * anyone onto a team to mean something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'foreman_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_members');
    }
};
