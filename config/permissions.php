<?php

/*
|--------------------------------------------------------------------------
| Roles & Permissions
|--------------------------------------------------------------------------
|
| What each role can do on the web app. A project manager always has all of it; the others
| start from the defaults below and a company can change them on Roles & Permissions
| (saved per company). A role not listed here — an estimator, say — is not governed by this
| matrix and keeps the access it has always had.
*/

return [

    /** The roles the matrix covers, in the order they are drawn: key => label. */
    'roles' => [
        'manager' => 'Project Manager',
        'foreman' => 'Foreman',
        'journeyman' => 'Journeyman',
        'apprentice' => 'Apprentice',
    ],

    /** The role that can never be taken below full access, so a company cannot lock itself out. */
    'locked_role' => 'manager',

    /** Modules and what can be done in each: module => [label, icon, permissions: action => label]. */
    'modules' => [
        'clients' => ['label' => 'Clients', 'icon' => 'users', 'permissions' => [
            'view' => 'View clients', 'create' => 'Create clients', 'edit' => 'Edit clients', 'delete' => 'Delete clients',
        ]],
        'projects' => ['label' => 'Projects', 'icon' => 'folder', 'permissions' => [
            'view' => 'View projects', 'create' => 'Create projects', 'edit' => 'Edit projects', 'delete' => 'Delete projects',
        ]],
        'takeoff' => ['label' => 'AI Takeoff', 'icon' => 'sparkles', 'permissions' => [
            'view' => 'View takeoffs', 'create' => 'Run takeoffs', 'edit' => 'Review and edit takeoffs', 'delete' => 'Delete takeoffs',
        ]],
        'estimates' => ['label' => 'Estimates', 'icon' => 'file', 'permissions' => [
            'view' => 'View estimates', 'create' => 'Create estimates', 'edit' => 'Edit estimates', 'delete' => 'Delete estimates',
        ]],
        'jobs' => ['label' => 'Jobs', 'icon' => 'briefcase', 'permissions' => [
            'view' => 'View jobs', 'create' => 'Create jobs', 'edit' => 'Edit jobs', 'delete' => 'Delete jobs',
        ]],
        'tasks' => ['label' => 'Tasks', 'icon' => 'check', 'permissions' => [
            'view' => 'View tasks', 'create' => 'Create tasks', 'edit' => 'Edit tasks', 'delete' => 'Delete tasks',
        ]],
        'change_orders' => ['label' => 'Change Orders', 'icon' => 'pen', 'permissions' => [
            'view' => 'View change orders', 'create' => 'Create change orders', 'edit' => 'Edit, submit and decide change orders', 'delete' => 'Delete change orders',
        ]],
        'schedule' => ['label' => 'Schedule', 'icon' => 'calendar', 'permissions' => [
            'view' => 'View schedule', 'manage' => 'Manage schedule',
        ]],
        'time_tracking' => ['label' => 'Time Tracking', 'icon' => 'clock', 'permissions' => [
            'view' => 'View time entries', 'create' => 'Create time entries', 'edit' => 'Edit time entries', 'delete' => 'Delete time entries',
        ]],
        'billing' => ['label' => 'Billing', 'icon' => 'receipt', 'permissions' => [
            'view' => 'View invoices', 'create' => 'Create invoices', 'edit' => 'Edit invoices', 'delete' => 'Delete invoices',
        ]],
        'crew' => ['label' => 'Teams & Crew', 'icon' => 'team', 'permissions' => [
            'view' => 'View teams and crew', 'create' => 'Add crew members', 'edit' => 'Edit crew members', 'delete' => 'Remove crew members',
        ]],
        'admin' => ['label' => 'Admin', 'icon' => 'settings', 'permissions' => [
            'manage_users' => 'Manage users', 'manage_roles' => 'Manage roles', 'system_settings' => 'System settings',
        ]],
    ],

    /**
     * What a company starts with. `*` is everything; `except` takes patterns off it.
     *
     *   manager     full access
     *   foreman     full access except AI Takeoff and company setup (the Admin module)
     *   journeyman  Jobs and Tasks, read only
     *   apprentice  Tasks, read only
     */
    'defaults' => [
        'manager' => ['allow' => ['*']],
        'foreman' => ['allow' => ['*'], 'except' => ['takeoff.*', 'admin.*']],
        'journeyman' => ['allow' => ['jobs.view', 'tasks.view']],
        'apprentice' => ['allow' => ['tasks.view']],
    ],

    /** Which module a route belongs to, by the start of its name. */
    'routes' => [
        'clients' => 'clients',
        'projects' => 'projects',
        'estimates' => 'estimates', 'estimate-builder' => 'estimates', 'estimate-items' => 'estimates', 'addenda' => 'estimates',
        'commodities' => 'estimates', 'price-book' => 'estimates',
        'jobs' => 'jobs', 'sync-conflicts' => 'jobs', 'job-costing' => 'jobs', 'documents' => 'jobs', 'document-folders' => 'jobs',
        'tasks' => 'tasks',
        'change-orders' => 'change_orders',
        'scheduling' => 'schedule',
        'time-tracking' => 'time_tracking', 'time-entries' => 'time_tracking', 'timer' => 'time_tracking', 'attendance' => 'time_tracking',
        'invoices' => 'billing', 'billing' => 'billing',
        'takeoffs' => 'takeoff', 'uploads' => 'takeoff', 'processing' => 'takeoff', 'results' => 'takeoff', 'reviews' => 'takeoff',
        'finals' => 'takeoff', 'drawings' => 'takeoff', 'takeoff-flow' => 'takeoff', 'ai' => 'takeoff',
        'teams' => 'crew', 'foremen' => 'crew', 'technicians' => 'crew',
        'team-setup' => 'admin.manage_users', 'managers' => 'admin.manage_users',
        'roles' => 'admin.manage_roles',
        'settings' => 'admin.system_settings',
    ],
];
