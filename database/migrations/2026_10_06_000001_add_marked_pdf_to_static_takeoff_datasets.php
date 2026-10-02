<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_takeoff_datasets', function (Blueprint $table) {
            // The annotated ("marked") copy of the reference drawing, shown on
            // the review screen in place of the plain upload when present.
            $table->string('marked_pdf_path')->nullable()->after('pdf_path');
            $table->string('marked_original_filename')->nullable()->after('marked_pdf_path');
            $table->unsignedBigInteger('marked_file_size')->nullable()->after('marked_original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('static_takeoff_datasets', function (Blueprint $table) {
            $table->dropColumn(['marked_pdf_path', 'marked_original_filename', 'marked_file_size']);
        });
    }
};
