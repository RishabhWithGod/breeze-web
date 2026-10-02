<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the day, time, length or crew of a shift last changed after it was booked — what
        // makes it read "Changed" on a technician's phone until they acknowledge it.
        Schema::table('crew_shifts', function (Blueprint $table) {
            $table->timestamp('changed_at')->nullable()->after('notes');
        });

        // Who has seen and acknowledged the shift, and when.
        Schema::create('crew_shift_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_shift_id')->constrained('crew_shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['crew_shift_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_shift_acknowledgements');
        Schema::table('crew_shifts', function (Blueprint $table) {
            $table->dropColumn('changed_at');
        });
    }
};
