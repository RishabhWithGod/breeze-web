<?php

use App\Models\Foreman;
use App\Services\Team\MemberIdentitySync;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time catch-up: the register row is what a manager edits on the web, so
 * for every linked person push it onto their account, crew record and job
 * assignments. From here on `MemberIdentitySync` keeps them together.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sync = app(MemberIdentitySync::class);

        Foreman::query()->withoutGlobalScopes()->whereNotNull('user_id')->each(
            fn (Foreman $foreman) => $sync->fromForeman($foreman),
        );
    }

    public function down(): void
    {
        // Data-only catch-up; nothing to undo.
    }
};
