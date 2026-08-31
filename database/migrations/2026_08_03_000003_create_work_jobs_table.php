<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Electrical jobs. Named `work_jobs` because Laravel reserves `jobs` for
     * the queue driver; the Eloquent model is still App\Models\Job.
     */
    public function up(): void
    {
        Schema::create('work_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('budget', 12, 2);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_jobs');
    }
};
