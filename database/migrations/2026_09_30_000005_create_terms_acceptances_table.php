<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A signed record of who agreed to which version of the terms, and when.
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->string('signer_name');
            $table->date('signed_on');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['user_id', 'version']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('needs_terms_acceptance')->default(false)->after('needs_company_setup');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('needs_terms_acceptance'));
        Schema::dropIfExists('terms_acceptances');
    }
};
