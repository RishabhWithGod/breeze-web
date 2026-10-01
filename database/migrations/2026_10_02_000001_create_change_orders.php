<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work added to a job after it began, and what it costs and sells for. `owner_id` is the
        // account the company's books are kept under, so a company numbers its change orders as one.
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description');
            $table->text('reason')->nullable();
            // field (raised on site) or office
            $table->string('source', 10)->default('office');
            // draft → submitted → approved | rejected
            $table->string('status', 12)->default('draft');
            $table->decimal('markup_pct', 6, 2)->default(0);
            $table->decimal('labor_hours', 10, 2)->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('material_cost', 12, 2)->default(0);
            $table->decimal('cost_total', 12, 2)->default(0);
            $table->decimal('sell_total', 12, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'number']);
            $table->index(['job_id', 'status']);
        });

        Schema::create('change_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // material | labor
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 16)->nullable();
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total', 12, 2);
            $table->unsignedInteger('position')->default(0);
        });

        Schema::create('change_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 120)->nullable();
            $table->timestamps();
        });

        // The full history: every save, submission and decision, with what it came to at the time.
        Schema::create('change_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->text('note')->nullable();
            $table->decimal('sell_total', 12, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('change_order_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('change_order_id');
        });
        Schema::dropIfExists('change_order_events');
        Schema::dropIfExists('change_order_attachments');
        Schema::dropIfExists('change_order_lines');
        Schema::dropIfExists('change_orders');
    }
};
