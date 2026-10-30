<?php

namespace App\Services\JobScope;

/**
 * Where a change to a job's scope came from — the web app, the Estimate
 * Builder, the phone, the field crew, or an invoice. Resolved from the
 * request unless a caller states it, because the writers (items, change
 * orders, cost entries) are shared by every one of those surfaces.
 */
class ChangeSource
{
    public const WEB = 'web';

    public const BUILDER = 'builder';

    public const MOBILE = 'mobile';

    public const FIELD = 'field';

    public const INVOICE = 'invoice';

    public const SYSTEM = 'system';

    private static ?string $override = null;

    /** Runs `$callback` with this source stated, restoring whatever was set before. */
    public static function as(string $source, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = $source;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    public static function current(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::SYSTEM;
        }

        return request()->is('api/*') ? self::MOBILE : self::WEB;
    }
}
