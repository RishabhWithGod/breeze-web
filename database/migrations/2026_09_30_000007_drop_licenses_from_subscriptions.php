<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A plan is one fixed price with a cap on users; there is no count of
        // licenses to buy.
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('licenses'));
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->unsignedInteger('licenses')->default(1)->after('plan'));
    }
};
