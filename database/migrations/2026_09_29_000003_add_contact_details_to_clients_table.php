<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who to call at a client — a name, an email and a phone number, all optional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_name', 160)->nullable()->after('name');
            $table->string('contact_email', 255)->nullable()->after('contact_name');
            $table->string('contact_phone', 40)->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_email', 'contact_phone']);
        });
    }
};
