<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fields captured by the Create New Job screen.
     *
     * Scheduling fields become nullable: only name, client and location are
     * required at intake, and a foreman is assigned later — so a job saved as a
     * draft may have no dates, budget or foreman yet.
     */
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->string('client')->nullable()->after('name');
            $table->string('location')->nullable()->after('client');
            $table->text('description')->nullable()->after('location');
            /** residential | commercial | industrial */
            $table->string('job_type')->nullable()->after('description');
            $table->boolean('create_estimate')->default(false)->after('budget');
            $table->boolean('assign_team')->default(false)->after('create_estimate');
            $table->boolean('notify_client')->default(false)->after('assign_team');
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->foreignId('foreman_id')->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
            $table->decimal('budget', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'client',
                'location',
                'description',
                'job_type',
                'create_estimate',
                'assign_team',
                'notify_client',
            ]);
        });
    }
};
