<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job's scope timeline: every change that moves what the job is priced
 * or billed at — an estimate edit, an approved change order, a logged cost,
 * an adjustment made on an invoice — remembered with who made it, from where,
 * and what it did. `version` counts a job's changes, so any screen or phone
 * can tell cheaply whether it is looking at the latest.
 *
 * `job_id` is null for an estimate that is not on a job yet; its `version`
 * then counts that estimate's own changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_scope_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            /* estimate | change_order | cost_entry | invoice | hours */
            $table->string('kind', 20);
            /* web | builder | mobile | field | invoice | system */
            $table->string('source', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /* Money-free, so it can be shown and broadcast to anyone: "3 lines added, 1 changed". */
            $table->string('summary', 255);
            /* [{action: added|removed|changed, category, description, unit, qty_before, qty_after, total_before, total_after}] */
            $table->json('item_changes')->nullable();
            $table->json('totals_before')->nullable();
            $table->json('totals_after')->nullable();
            /* The signed effect on the job's priced scope or cost. Dollar figure: redacted for roles without cost access. */
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_id', 'version']);
            $table->index(['estimate_id', 'created_at']);
        });

        Schema::table('job_cost_entries', function (Blueprint $table) {
            /* manual | invoice — an `invoice` entry is a change a user made on an invoice, kept so the job's actual agrees with it. */
            $table->string('source', 20)->default('manual');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_item_id')->nullable()->index();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            /* How much of this line the job's scope already accounts for; anything above or below it is an invoice-side change. */
            $table->decimal('scope_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('scope_amount'));
        Schema::table('job_cost_entries', fn (Blueprint $t) => $t->dropColumn(['source', 'invoice_id', 'invoice_item_id']));
        Schema::dropIfExists('job_scope_changes');
    }
};
