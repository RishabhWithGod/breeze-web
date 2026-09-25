<?php

namespace App\Providers;

use App\Events\EstimateGenerated;
use App\Events\TakeoffProcessed;
use App\Services\Ai\AiTakeoffClient;
use App\Services\Ai\Contracts\TakeoffEngine;
use App\Services\StaticTakeoff\Listeners\AttachStaticPageSizes;
use App\Services\StaticTakeoff\Listeners\NormalizeStaticEstimateRates;
use App\Services\StaticTakeoff\StaticAwareSymbolCatalog;
use App\Services\StaticTakeoff\StaticTakeoffEngine;
use App\Services\Takeoff\SymbolCatalog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The single switch point for the isolated static takeoff module. Binds the
 * `TakeoffEngine` contract that `TakeoffOrchestrator` depends on to either the
 * real AI client or the DB-backed static resolver, based on
 * `config('static_takeoff.enabled')`.
 *
 * Removing this provider (and `app/Services/StaticTakeoff/`, the
 * `static_takeoff_datasets` migration and this registration in
 * `bootstrap/providers.php`) fully removes the static takeoff feature and
 * restores the dynamic-only wiring.
 */
class StaticTakeoffServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TakeoffEngine::class, fn ($app) => config('static_takeoff.enabled')
            ? $app->make(StaticTakeoffEngine::class)
            : $app->make(AiTakeoffClient::class));

        // Always bound: for a project whose latest run was not a static
        // dataset match, this subclass finds nothing to index and every
        // lookup falls straight through to the normal rate book/price book
        // chain — a no-op for the dynamic flow either way.
        $this->app->bind(SymbolCatalog::class, StaticAwareSymbolCatalog::class);
    }

    public function boot(): void
    {
        // Registered unconditionally — each listener checks the run/estimate
        // it receives for a static-dataset origin itself, so this stays
        // correct even if the flag changes at runtime without a reboot.
        Event::listen(TakeoffProcessed::class, AttachStaticPageSizes::class);
        Event::listen(EstimateGenerated::class, NormalizeStaticEstimateRates::class);
    }
}
