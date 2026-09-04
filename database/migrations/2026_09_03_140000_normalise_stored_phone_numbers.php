<?php

use App\Support\UsPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every stored phone number, written the one way the app now writes them.
 *
 * Numbers are formatted on the way in from here on, but the ones already on
 * record were saved as typed. Leaving them would mean every screen deciding for
 * itself how to draw a number, which is the thing formatting on save exists to
 * avoid.
 *
 * Only numbers this can recognise are touched. Anything that is not a US number
 * — an extension, a note, a foreign line — is left exactly as it was: it is
 * somebody's data, and rewriting it into a shape it does not fit would lose it.
 */
return new class extends Migration
{
    private const TABLES = ['foremen', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNotNull('phone')->orderBy('id')
                ->chunkById(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $formatted = UsPhone::format($row->phone);

                        if ($formatted === $row->phone) {
                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update(['phone' => $formatted]);
                    }
                });
        }
    }

    /**
     * Irreversible, deliberately.
     *
     * The old value was "9837645221" or "983-764-5221" or "+1 983 764 5221" —
     * the same number, and which of those a given row held is not recorded
     * anywhere. Reversing would mean inventing an original.
     */
    public function down(): void {}
};
