<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The canonical empty and error screens.
 *
 * Both are reachable directly so the states stay reviewable without having to
 * reproduce the conditions that trigger them.
 */
class StatePageController extends Controller
{
    public function empty(): Response
    {
        return Inertia::render('EmptyState');
    }

    public function error(): Response
    {
        return Inertia::render('ErrorState');
    }
}
