<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A takeoff prices a device and its install labor on two lines; the builder keeps
        // them as one row. This remembers the labor line that was folded in, so a second
        // import never copies it again.
        Schema::table('estimate_builder_lines', function (Blueprint $table) {
            $table->foreignId('source_labor_item_id')->nullable()->after('source_estimate_item_id')
                ->constrained('estimate_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_builder_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_labor_item_id');
        });
    }
};
