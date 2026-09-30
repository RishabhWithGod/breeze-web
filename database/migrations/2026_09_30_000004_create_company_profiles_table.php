<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company, owned by the account that set it up. Every new account has
        // to describe its own company before it can do any work.
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('business_address');
            $table->string('primary_contact');
            $table->string('phone', 32);
            $table->string('email');
            $table->string('license_number')->nullable();
            $table->string('timezone', 64);
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        // Set at signup, cleared when that person finishes the company setup —
        // so existing accounts are never sent back through it.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('needs_company_setup')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('needs_company_setup'));
        Schema::dropIfExists('company_profiles');
    }
};
