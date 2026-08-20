<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Single-row company-wide billing preferences — the same singleton shape `time_tracking_settings` already uses. */
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_send_invoices')->default(false);
            $table->boolean('include_payment_instructions')->default(false);
            $table->boolean('send_payment_reminders')->default(false);
            $table->boolean('apply_late_fees_automatically')->default(false);
            $table->string('default_payment_terms')->default('due-on-receipt');
            $table->string('default_currency', 3)->default('USD');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
