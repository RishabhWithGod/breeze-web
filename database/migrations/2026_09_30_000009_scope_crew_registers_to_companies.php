<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who belongs to which company. A web account's company is the one it set
        // up; a mobile technician's is the one they picked when they signed up.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('status')->constrained('company_profiles')->nullOnDelete();
        });

        // The crew registers are each company's own. Deleting a company takes its
        // registers with it rather than handing them to the accounts that have none.
        foreach (['teams', 'foremen', 'team_members'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->constrained('company_profiles')->cascadeOnDelete();
            });
        }

        // Two companies can each have a "Crew A".
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['company_id', 'name']);
        });

        // Accounts that already set a company up belong to it.
        DB::table('company_profiles')->orderBy('id')->each(
            fn ($company) => DB::table('users')->where('id', $company->user_id)->update(['company_id' => $company->id])
        );
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
            $table->unique('name');
        });

        foreach (['teams', 'foremen', 'team_members'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
    }
};
