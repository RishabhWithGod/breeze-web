<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            /** PDF | CAD | DWG | BIM — the badge shown on the upload tile. */
            $table->string('format', 8);
            $table->unsignedBigInteger('size_bytes');
            /** Path on the configured disk; null for seeded demo rows. */
            $table->string('path')->nullable();
            $table->string('status')->default('processing')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
