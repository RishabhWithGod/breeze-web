<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            // What the estimate covers and what it does not — read by whoever approves it.
            $table->text('scope_of_work')->nullable()->after('notes');
            $table->json('exclusions')->nullable()->after('scope_of_work');

            // Who approved it, when, and which revision — so the approved one is never in doubt.
            $table->foreignId('approved_by')->nullable()->after('exclusions')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedInteger('approved_revision')->nullable()->after('approved_at');

            // The last decision on it, and what the reviewer wrote: an approval or a return.
            $table->text('review_notes')->nullable()->after('approved_revision');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        // One row each time an estimate is sent for approval: what version it was, when,
        // by whom, what changed and what it came to.
        Schema::create('estimate_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('changes');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['estimate_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_revisions');
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['scope_of_work', 'exclusions', 'approved_at', 'approved_revision', 'review_notes', 'reviewed_at']);
        });
    }
};
