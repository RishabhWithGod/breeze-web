<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (user, event type). Both channels default false — a user
     * only ever sees a channel as "on" because they actually turned it on.
     */
    public function up(): void
    {
        Schema::create('security_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_notification_preferences');
    }
};
