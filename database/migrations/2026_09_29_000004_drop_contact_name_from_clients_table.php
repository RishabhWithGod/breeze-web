<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's own name is who to ask for — a second name just for the contact
 * was never a different answer, so it goes rather than sitting unused beside
 * the one that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_name', 160)->nullable()->after('name');
        });
    }
};
