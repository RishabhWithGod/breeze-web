<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->text('addendum_reason')->nullable()->after('addendum_for_estimate_id');
            $table->string('affected_sheets', 500)->nullable()->after('addendum_reason');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['addendum_reason', 'affected_sheets']);
        });
    }
};
