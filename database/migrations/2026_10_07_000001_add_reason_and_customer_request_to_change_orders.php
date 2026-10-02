<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            // Why the work was added — one of ChangeOrder::REASONS. `reason` stays the free-text detail.
            $table->string('reason_code', 24)->nullable()->after('reason');
            // The customer asked for it, as opposed to the crew or the office finding it.
            $table->boolean('customer_requested')->default(false)->after('reason_code');
            // Set by the phone so a change order saved offline and replayed is never created twice.
            $table->string('client_key', 64)->nullable()->after('customer_requested');

            $table->unique(['owner_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'client_key']);
            $table->dropColumn(['reason_code', 'customer_requested', 'client_key']);
        });
    }
};
