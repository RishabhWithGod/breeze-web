<?php

use App\Models\Estimate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An estimate with a job raised against it is not a draft.
 *
 * Four places raised estimates and each decided the status for itself, so one
 * generated from a signed-off takeoff stayed "draft" even after a job was
 * created from it — the job screen then showed the work as unstarted paperwork
 * while a crew was being scheduled against it.
 *
 * The rule now lives on the model (`Estimate::statusFor()`); this brings the
 * rows already on record into line with it. Only drafts are touched: an
 * estimate someone has since sent, approved or rejected keeps what they set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('estimates')
            ->whereNotNull('job_id')
            ->where('status', 'draft')
            ->update(['status' => Estimate::STATUS_FOR_A_LIVE_JOB]);
    }

    public function down(): void
    {
        // Deliberately not reversed: which of these were drafts before is not
        // recorded anywhere, so "undoing" it would be guessing.
    }
};
