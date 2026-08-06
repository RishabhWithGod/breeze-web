<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit descriptions outgrew `varchar(255)`.
 *
 * Some entries quote a failure verbatim — a database error, an engine rejection —
 * and those run well past 255 characters. SQLite truncated them silently; MySQL
 * refuses the insert, which would drop the audit row exactly when something went
 * wrong and the record matters most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_histories', function (Blueprint $table) {
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_histories', function (Blueprint $table) {
            $table->string('description')->change();
        });
    }
};
