<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_takeoff_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('file_hash', 64)->unique();
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('takeoff_payload');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_takeoff_datasets');
    }
};
