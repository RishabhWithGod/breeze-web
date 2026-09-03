<?php

namespace App\Http\Controllers;

use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\RedirectResponse;

/**
 * Putting the resume button away.
 *
 * The takeoff is untouched — only the reminder goes. Opening any of the flow's
 * screens again brings it back.
 */
class TakeoffFlowController extends Controller
{
    public function destroy(TakeoffFlow $flow): RedirectResponse
    {
        $flow->forget();

        return back();
    }
}
