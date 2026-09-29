<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superseded by `client_contacts` — a client can now have several people to
 * call, so one email/phone pair on the client itself is no longer the whole
 * answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_email', 255)->nullable()->after('name');
            $table->string('contact_phone', 40)->nullable()->after('contact_email');
        });
    }
};
