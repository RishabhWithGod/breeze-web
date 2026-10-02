<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A field entry is now a priced line — material (quantity at a unit price) or labor
        // (hours at an hourly rate). It stays "pending" until the office approves it; approval
        // is what puts it on the job's estimate, and from there on its billing.
        Schema::table('job_field_materials', function (Blueprint $table) {
            $table->string('kind', 16)->default('material')->after('job_task_id');
            $table->decimal('unit_price', 12, 2)->default(0)->after('actual_quantity');
            $table->decimal('total', 14, 2)->default(0)->after('unit_price');
            $table->string('status', 16)->default('pending')->after('total');
            $table->unsignedBigInteger('added_estimate_item_id')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('added_estimate_item_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('job_field_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['kind', 'unit_price', 'total', 'status', 'added_estimate_item_id', 'approved_at']);
        });
    }
};
