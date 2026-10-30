<?php

namespace App\Support;

use App\Models\AiResult;

/**
 * Where a notification's web link leads *inside the phone app*. Web hrefs are web paths
 * (`/jobs/50`, `/reviews/106`); the app opens its own screens, so each one is turned into a
 * `{screen, id}` pair here. A link with no phone screen (Breeze Bucks, a document preview…)
 * resolves to null and the app simply does not offer it.
 */
class NotificationTarget
{
    /** @return array{screen: string, id: string|null}|null */
    public static function for(?string $href): ?array
    {
        if ($href === null || $href === '') {
            return null;
        }

        $path = parse_url($href, PHP_URL_PATH) ?: '';

        return match (true) {
            // A job's scope changed — straight to its Job Costing, ahead of the plain job match below.
            (bool) preg_match('#^/jobs/(\d+)/costing#', $path, $m) => ['screen' => 'job_costing', 'id' => $m[1]],
            (bool) preg_match('#^/jobs/(\d+)#', $path, $m) => ['screen' => 'job', 'id' => $m[1]],
            (bool) preg_match('#^/estimates/(\d+)#', $path, $m) => ['screen' => 'estimate', 'id' => $m[1]],
            (bool) preg_match('#^/reviews/(\d+)#', $path, $m) => self::review($m[1]),
            (bool) preg_match('#^/invoices/(\d+)#', $path, $m) => ['screen' => 'invoice', 'id' => $m[1]],
            (bool) preg_match('#^/change-orders/(\d+)#', $path, $m) => ['screen' => 'change_order', 'id' => $m[1]],
            str_starts_with($path, '/ai-takeoff'), str_starts_with($path, '/takeoffs') => ['screen' => 'takeoffs', 'id' => null],
            str_starts_with($path, '/estimates') => ['screen' => 'estimates', 'id' => null],
            str_starts_with($path, '/invoices'), str_starts_with($path, '/billing') => ['screen' => 'invoices', 'id' => null],
            str_starts_with($path, '/change-orders') => ['screen' => 'change_orders', 'id' => null],
            str_starts_with($path, '/security') => ['screen' => 'security', 'id' => null],
            str_starts_with($path, '/time-tracking') => ['screen' => 'time', 'id' => null],
            str_starts_with($path, '/leave') => ['screen' => 'leave', 'id' => null],
            str_starts_with($path, '/teams'), str_starts_with($path, '/technicians') => ['screen' => 'teams', 'id' => null],
            str_starts_with($path, '/scheduling'), str_starts_with($path, '/schedule') => ['screen' => 'schedule', 'id' => null],
            default => null,
        };
    }

    /** @return array{screen: string, id: string}|null */
    private static function review(string $resultId): ?array
    {
        $projectId = AiResult::query()->whereKey($resultId)->value('project_id');

        return $projectId === null ? null : ['screen' => 'review', 'id' => (string) $projectId];
    }
}
