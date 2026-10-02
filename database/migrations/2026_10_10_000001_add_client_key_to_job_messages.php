<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set by the phone, so a message written offline and sent when the signal returns lands
        // once, however many times the queue sends it.
        Schema::table('job_messages', function (Blueprint $table) {
            $table->string('client_key', 64)->nullable()->after('body');
            $table->unique(['job_id', 'user_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('job_messages', function (Blueprint $table) {
            $table->dropUnique(['job_id', 'user_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
