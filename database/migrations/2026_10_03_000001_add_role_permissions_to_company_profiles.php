<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each role may do in this company, once it has changed the defaults
        // (see config/permissions.php). Null means "the defaults".
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->json('role_permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('role_permissions');
        });
    }
};
