<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_takeoff_datasets', function (Blueprint $table) {
            // Filled lazily from the marked PDF itself; a marked copy with
            // fewer pages than the original limits what the review shows.
            $table->unsignedInteger('marked_page_count')->nullable()->after('marked_file_size');
        });
    }

    public function down(): void
    {
        Schema::table('static_takeoff_datasets', function (Blueprint $table) {
            $table->dropColumn('marked_page_count');
        });
    }
};
