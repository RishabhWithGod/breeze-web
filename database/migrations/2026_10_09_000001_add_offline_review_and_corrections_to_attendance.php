<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_attendances', function (Blueprint $table) {
            // When the server heard about each event. `check_in_at` / `check_out_at` are when it
            // happened on the phone — a check-in saved offline keeps its own time and arrives later.
            $table->timestamp('check_in_received_at')->nullable()->after('check_in_at');
            $table->timestamp('check_out_received_at')->nullable()->after('check_out_at');
            // Set when the record is one a person should look at: a weak GPS fix, a point near the
            // edge of the site, a long-delayed upload, a checkout with no GPS at all.
            $table->string('review_flag', 32)->nullable();
            $table->string('review_reason')->nullable();
        });

        // The technician's own word on a record: a note, a reported problem, or an
        // acknowledgement of an automatic check-in or checkout. A manager resolves the reports.
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_attendance_id')->constrained('job_attendances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16); // note | correction | ack
            $table->text('message')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['job_attendance_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::table('job_attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in_received_at', 'check_out_received_at', 'review_flag', 'review_reason']);
        });
    }
};
