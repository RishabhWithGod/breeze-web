<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Lets controllers call `$this->authorize(...)` against the policies in
     * app/Policies — used by every takeoff, review and estimate action.
     */
    use AuthorizesRequests;
}
