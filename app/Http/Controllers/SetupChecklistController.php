<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Services\Company\ManagerRegistrar;
use App\Services\Onboarding\SetupChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Get Started: the checklist a company works through after it subscribes.
 *
 * Opened by whoever manages the company (a project manager or its administrator). The
 * page tracks itself from what exists; the only things stored are which optional steps
 * were skipped and whether setup was called finished.
 */
class SetupChecklistController extends Controller
{
    public function __construct(
        private readonly SetupChecklist $checklist,
        private readonly ManagerRegistrar $managers,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('GetStarted', $this->checklist->page($request->user()));
    }

    /** Leaves an optional step for later — or, with `undo`, brings it back. */
    public function skip(Request $request, string $step): RedirectResponse
    {
        $company = $this->company($request);
        abort_unless(in_array($step, SetupChecklist::SKIPPABLE, true), 404);

        $skipped = collect($company->onboarding_skipped ?? [])
            ->when($request->boolean('undo'), fn ($steps) => $steps->reject(fn ($key) => $key === $step))
            ->when(! $request->boolean('undo'), fn ($steps) => $steps->push($step))
            ->unique()
            ->values()
            ->all();

        $company->update(['onboarding_skipped' => $skipped]);

        return back();
    }

    /** Calls setup finished — once the steps that cannot be skipped are done. */
    public function finish(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $checklist = $this->checklist->for($request->user());

        if (! $checklist['canFinish']) {
            return back()->with('warning', 'Finish the company, client and project steps first — those cannot be skipped.');
        }

        $company->forceFill(['onboarding_finished_at' => now()])->save();

        return redirect()->route('home')->with('success', 'Setup is finished. Your workspace is ready.');
    }

    private function authorizeManager(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->company_id !== null && $this->managers->isManager($user), 403);
    }

    private function company(Request $request): CompanyProfile
    {
        $this->authorizeManager($request);

        return CompanyProfile::query()->findOrFail($request->user()->company_id);
    }
}
