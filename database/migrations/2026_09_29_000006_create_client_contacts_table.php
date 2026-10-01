<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's own people — as many as they have, each with what they do and
 * how to reach them. Replaces the single `contact_email`/`contact_phone`
 * pair the client used to carry: a real client is rarely one person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            /** Their role at the client — "Owner", "Project Manager" — free text, not a fixed set. */
            $table->string('role', 80)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            /** The one a form defaults to — same idea as `client_addresses.is_primary`. */
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
