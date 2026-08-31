<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client invoices — Billing's first real table, replacing the sidebar's
 * placeholder module.
 *
 * There is no `Client` model anywhere in this app; `client` is a plain string
 * column here for the same reason it already is on `work_jobs` and
 * `estimates` — a name typed once, matched by string, exactly like those two.
 *
 * `status` only ever stores a real, explicitly-set workflow state — draft,
 * sent or paid — never "overdue". Overdue is a fact about the calendar
 * (`due_date` has passed while money is still owed), not a decision anyone
 * makes, so it is derived at read time (`Invoice::displayStatus()`) rather
 * than stored — nothing has to notice the due date passing and go flip a
 * column, and the value can never go stale.
 *
 * `paid_amount`/`paid_at` are the entire "payment history" this schema has:
 * there is no payments ledger anywhere in the app to build on, and one is not
 * warranted for a single "mark paid" action. Both are only ever set by that
 * real action, never guessed — the number they show is the number that
 * button recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('job_id')->nullable()->constrained('work_jobs')->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->string('client')->index();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->string('status')->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
