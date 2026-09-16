<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // What an hour of this client's labor is billed at. Null means
            // "whatever the usual rate resolves to" — the project's own rate
            // list, then the price book, then the configured default; set,
            // it overrides all of that for every estimate raised on this
            // client's projects.
            $table->decimal('labor_rate', 8, 2)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('labor_rate');
        });
    }
};
