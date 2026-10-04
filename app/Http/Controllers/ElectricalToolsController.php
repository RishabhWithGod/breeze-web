<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A preview of the electrical calculation tools, shown ahead of the real
 * calculation engine. The catalog (name, description, icon, saved-calculation
 * counts) is static on the frontend — see `constants/electricalTools.ts` —
 * only the project list behind "Project Context" is real.
 */
class ElectricalToolsController extends Controller
{
    /** Every slug the preview knows about — mirrors `ELECTRICAL_TOOLS` on the frontend. */
    public const TOOLS = ['circuit-colors', 'conduit-fill', 'voltage-drop', 'measurement-calculator'];

    public function index(Request $request): Response
    {
        return Inertia::render('ElectricalTools', [
            'projects' => Project::query()
                ->ownedBy($request->user())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, string $tool): Response
    {
        abort_unless(in_array($tool, self::TOOLS, true), 404);

        return Inertia::render('ElectricalToolShow', [
            'tool' => $tool,
            'projects' => Project::query()
                ->ownedBy($request->user())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
