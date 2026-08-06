<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    /**
     * Sidebar modules that are part of the product but not built yet.
     *
     * They get real routes rather than dead links so the drawer matches the
     * reference application and navigation never 404s.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const MODULES = [
        'time-tracking' => [
            'label' => 'Time Tracking',
            'description' => 'Clock-ins, timesheets and payroll-ready hour exports.',
        ],
        'billing' => [
            'label' => 'Billing',
            'description' => 'Progress invoicing, retainage and payment tracking.',
        ],
        'job-costing' => [
            'label' => 'Job Costing',
            'description' => 'Budget vs actual by phase, with labour and material burn-down.',
        ],
        'documents' => [
            'label' => 'Documents',
            'description' => 'Drawings, submittals, RFIs and close-out packages.',
        ],
        'notifications' => [
            'label' => 'Notifications',
            'description' => 'Every alert across jobs, estimates and takeoffs in one feed.',
        ],
        'settings' => [
            'label' => 'Settings',
            'description' => 'Workspace preferences, pricing catalogues and integrations.',
        ],
        'security' => [
            'label' => 'Security',
            'description' => 'Roles, permissions, audit log and sign-in policy.',
        ],
        'breeze-bucks' => [
            'label' => 'Breeze Bucks',
            'description' => 'Rewards earned on completed takeoffs and referrals.',
        ],
    ];

    public function show(string $module): Response
    {
        $meta = self::MODULES[$module];

        return Inertia::render('ModulePlaceholder', [
            'module' => [
                'slug' => $module,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ],
        ]);
    }
}
