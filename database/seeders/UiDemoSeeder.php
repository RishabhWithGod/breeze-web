<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A few extra demo rows so every screen of the job flow has something to show.
 * Idempotent (keyed on the "Demo " names) and insert-only: nothing existing is changed
 * except putting the unassigned demo people into Team A.
 *
 *   php artisan db:seed --class=UiDemoSeeder
 */
class UiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $user = 1;
        $company = 1;

        DB::transaction(function () use ($now, $user, $company) {
            $teamA = DB::table('teams')->value('id');
            DB::table('team_members')->whereNull('team_id')->update(['team_id' => $teamA]);
            DB::table('foremen')->whereNull('team_id')->update(['team_id' => $teamA]);

            $teamB = $this->once('teams', ['name' => 'Demo Team B'], ['company_id' => $company]);
            $extra = [];
            foreach ([['Maria Lopez', 'ML', 'Journeyman', 62, 38], ['Dan Carter', 'DC', 'Apprentice', 42, 24]] as [$n, $i, $r, $b, $c]) {
                $extra[] = $this->once('team_members', ['name' => $n], [
                    'initials' => $i, 'role' => $r, 'team_id' => $teamB, 'company_id' => $company,
                    'billable_rate' => $b, 'cost_rate' => $c,
                ]);
            }
            $foremanB = $this->once('foremen', ['name' => 'Sam Rivera'], [
                'initials' => 'SR', 'role' => 'foreman', 'team_id' => $teamB, 'company_id' => $company,
                'email' => 'sam.rivera@example.test', 'phone' => '555-0142', 'licence_number' => 'EL-48213',
                'notes' => 'Demo foreman', 'started_on' => '2022-03-01',
            ]);
            $foremanA = DB::table('foremen')->orderBy('id')->value('id');
            $members = DB::table('team_members')->orderBy('id')->limit(3)->pluck('id')->all();

            $clients = DB::table('clients')->pluck('id')->all();
            $projects = DB::table('projects')->pluck('id')->all();

            $defs = [
                ['Demo Oakridge Retrofit', 'in-progress', 'commercial', 'high', 48250, 320, 'Oak Ave, Brooklyn, NY, USA', $teamA, $foremanA, 60],
                ['Demo Riverside Lofts Rewire', 'scheduled', 'residential', 'medium', 21800, 150, 'River Rd, Jersey City, NJ, USA', $teamB, $foremanB, 15],
                ['Demo Lakeview Panel Upgrade', 'completed', 'industrial', 'low', 12400, 96, 'Lake St, Yonkers, NY, USA', $teamA, $foremanA, 100],
            ];

            foreach ($defs as $k => [$name, $status, $type, $prio, $budget, $hours, $loc, $team, $foreman, $pct]) {
                $jobId = $this->once('work_jobs', ['name' => $name], [
                    'status' => $status, 'job_type' => $type, 'priority' => $prio, 'budget' => $budget,
                    'estimated_hours' => $hours, 'actual_hours' => round($hours * $pct / 100, 2),
                    'location' => $loc, 'team_id' => $team, 'foreman_id' => $foreman, 'user_id' => $user,
                    'client_id' => $clients[$k % count($clients)] ?? null, 'project_id' => $projects[$k % count($projects)] ?? null,
                    'description' => 'Demo job for the redesigned screens.',
                    'start_date' => $now->copy()->subDays(20 - $k * 10)->toDateString(),
                    'end_date' => $now->copy()->addDays(30 + $k * 15)->toDateString(),
                ]);

                if (DB::table('job_tasks')->where('job_id', $jobId)->exists()) {
                    continue;
                }

                $scheduleId = DB::table('job_schedules')->insertGetId([
                    'job_id' => $jobId, 'starts_on' => $now->copy()->subDays(20)->toDateString(),
                    'ends_on' => $now->copy()->addDays(40)->toDateString(), 'status' => 'published',
                    'working_days' => json_encode([1, 2, 3, 4, 5]), 'progress_pct' => $pct,
                    'created_by' => $user, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $tasks = [['Rough-in conduit', 'completed', 100], ['Pull wire & terminate', 'in-progress', 55], ['Install fixtures', 'pending', 0]];
                foreach ($tasks as $pos => [$title, $tStatus, $tPct]) {
                    $taskId = DB::table('job_tasks')->insertGetId([
                        'job_id' => $jobId, 'job_schedule_id' => $scheduleId, 'title' => $title, 'status' => $status === 'completed' ? 'completed' : $tStatus,
                        'completion_pct' => $status === 'completed' ? 100 : $tPct, 'position' => $pos + 1, 'priority' => 'medium',
                        'foreman_id' => $foreman, 'estimated_hours' => 40, 'actual_hours' => $tPct * 0.4,
                        'starts_on' => $now->copy()->subDays(20 - $pos * 10)->toDateString(),
                        'ends_on' => $now->copy()->subDays(10 - $pos * 10)->toDateString(),
                        'created_by' => $user, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('job_task_foremen')->insert(['job_task_id' => $taskId, 'foreman_id' => $foreman, 'slot' => 'runner', 'created_at' => $now, 'updated_at' => $now]);
                    foreach (array_slice(array_merge($members, $extra), $pos, 2) as $memberId) {
                        DB::table('job_task_assignments')->insert([
                            'job_task_id' => $taskId, 'team_member_id' => $memberId, 'role' => 'electrician',
                            'assigned_by' => $user, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }

                DB::table('job_assignments')->insert([
                    'job_id' => $jobId, 'name' => 'Mr Coder', 'role' => 'project-manager', 'user_id' => $user,
                    'assigned_by' => $user, 'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                if ($status !== 'scheduled') {
                    $sub = round($budget * ($status === 'completed' ? 1 : 0.4), 2);
                    $this->once('invoices', ['invoice_number' => 'DEMO-'.str_pad((string) ($k + 1), 3, '0', STR_PAD_LEFT)], [
                        'client' => 'Demo Client', 'client_id' => $clients[0] ?? null, 'job_id' => $jobId, 'project_id' => $projects[0] ?? null,
                        'invoice_date' => $now->toDateString(), 'due_date' => $now->copy()->addDays(30)->toDateString(),
                        'status' => $status === 'completed' ? 'paid' : 'sent', 'subtotal' => $sub, 'tax_pct' => 0, 'tax_total' => 0,
                        'total' => $sub, 'paid_amount' => $status === 'completed' ? $sub : 0, 'created_by' => $user, 'user_id' => $user,
                    ]);
                }
            }

            // The existing TEST job: a foreman, tasks and crew so its list/detail aren't empty.
            $testJob = DB::table('work_jobs')->where('name', 'TEST')->value('id');
            if ($testJob && ! DB::table('job_tasks')->where('job_id', $testJob)->exists()) {
                $scheduleId = DB::table('job_schedules')->where('job_id', $testJob)->value('id')
                    ?? DB::table('job_schedules')->insertGetId([
                        'job_id' => $testJob, 'starts_on' => $now->toDateString(), 'ends_on' => $now->copy()->addDays(30)->toDateString(),
                        'status' => 'draft', 'working_days' => json_encode([1, 2, 3, 4, 5]), 'progress_pct' => 0,
                        'created_by' => $user, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                $taskId = DB::table('job_tasks')->insertGetId([
                    'job_id' => $testJob, 'job_schedule_id' => $scheduleId, 'title' => 'Site walk & layout', 'status' => 'pending',
                    'completion_pct' => 0, 'position' => 1, 'priority' => 'medium', 'foreman_id' => $foremanA, 'estimated_hours' => 16,
                    'starts_on' => $now->toDateString(), 'ends_on' => $now->copy()->addDays(3)->toDateString(),
                    'created_by' => $user, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('job_task_foremen')->insert(['job_task_id' => $taskId, 'foreman_id' => $foremanA, 'slot' => 'runner', 'created_at' => $now, 'updated_at' => $now]);
                foreach (array_slice($members, 0, 2) as $memberId) {
                    DB::table('job_task_assignments')->insert(['job_task_id' => $taskId, 'team_member_id' => $memberId, 'role' => 'electrician', 'assigned_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            // One change order on the demo Oakridge job, waiting for approval, so the list and the
            // detail page have a row that links to a real job (and its client) like any other.
            $oakridge = DB::table('work_jobs')->where('name', 'Demo Oakridge Retrofit')->value('id');
            if ($oakridge && ! DB::table('change_orders')->where('description', 'Demo: add 6 dedicated 20A circuits')->exists()) {
                $coId = DB::table('change_orders')->insertGetId([
                    'job_id' => $oakridge, 'owner_id' => $user, 'created_by' => $user,
                    'number' => ((int) DB::table('change_orders')->max('number')) + 1,
                    'description' => 'Demo: add 6 dedicated 20A circuits', 'status' => 'submitted', 'source' => 'office',
                    'reason_code' => array_key_first(\App\Models\ChangeOrder::REASONS), 'customer_requested' => 1,
                    'reason' => 'Client asked for extra workstation circuits in the east wing after the walk-through.',
                    'labor_hours' => 16, 'labor_cost' => 1360, 'material_cost' => 1250, 'cost_total' => 2610,
                    'markup_pct' => 15, 'sell_total' => 3001.50, 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('change_order_lines')->insert([
                    ['change_order_id' => $coId, 'kind' => 'material', 'description' => '20A breaker', 'quantity' => 6, 'unit' => 'EA', 'unit_cost' => 28, 'total' => 168, 'position' => 1],
                    ['change_order_id' => $coId, 'kind' => 'material', 'description' => '12/2 NM-B cable, 250ft', 'quantity' => 4, 'unit' => 'ROLL', 'unit_cost' => 270.5, 'total' => 1082, 'position' => 2],
                    ['change_order_id' => $coId, 'kind' => 'labor', 'description' => 'Run and terminate circuits', 'quantity' => 16, 'unit' => 'hr', 'unit_cost' => 85, 'total' => 1360, 'position' => 3],
                ]);
                DB::table('change_order_events')->insert([
                    ['change_order_id' => $coId, 'type' => 'created', 'user_id' => $user, 'note' => null, 'sell_total' => 3001.50, 'created_at' => $now],
                    ['change_order_id' => $coId, 'type' => 'submitted', 'user_id' => $user, 'sell_total' => 3001.50, 'note' => 'Sent to the client for approval.', 'created_at' => $now],
                ]);
            }

            // A few days of crew time on the demo jobs, linked to real people, jobs and tasks:
            // approved, waiting for approval, a draft, one long day (overtime) and a GPS check-in.
            $oak = DB::table('work_jobs')->where('name', 'Demo Oakridge Retrofit')->value('id');
            $riv = DB::table('work_jobs')->where('name', 'Demo Riverside Lofts Rewire')->value('id');
            if ($oak && $riv && ! DB::table('time_entries')->where('description', 'like', 'Demo:%')->exists()) {
                $taskOf = fn ($job, $n) => DB::table('job_tasks')->where('job_id', $job)->orderBy('position')->skip($n)->value('id');
                $people = DB::table('team_members')->whereNotNull('user_id')->orderBy('id')->limit(3)->get(['id', 'user_id', 'billable_rate', 'cost_rate']);
                $rows = [
                    // person index, job, task n, days ago, start, end, break, status, source, note
                    [0, $oak, 0, 3, '07:00', '15:30', 30, 'approved', 'manual', 'Demo: conduit rough-in, east wing'],
                    [1, $oak, 0, 3, '07:30', '16:00', 30, 'approved', 'timer', 'Demo: pulling wire'],
                    [0, $oak, 1, 2, '07:00', '17:30', 30, 'submitted', 'timer', 'Demo: long day finishing the panel feed'],
                    [2, $oak, 1, 2, '08:00', '14:00', 0, 'submitted', 'manual', 'Demo: terminations'],
                    [1, $riv, 0, 1, '08:00', '12:00', 0, 'draft', 'manual', 'Demo: site survey'],
                ];
                foreach ($rows as [$who, $job, $n, $ago, $start, $end, $break, $status, $source, $note]) {
                    $p = $people[$who] ?? null;
                    if (! $p) {
                        continue;
                    }
                    $hours = round((strtotime($end) - strtotime($start)) / 3600 - $break / 60, 2);
                    $bill = (float) ($p->billable_rate ?: 65);
                    $cost = (float) ($p->cost_rate ?: 38);
                    $reg = min($hours, 8);
                    $ot = max(0, $hours - 8);
                    DB::table('time_entries')->insert([
                        'date' => $now->copy()->subDays($ago)->toDateString(), 'user_id' => $p->user_id, 'team_member_id' => $p->id,
                        'job_id' => $job, 'job_task_id' => $taskOf($job, $n), 'task_label' => null, 'description' => $note,
                        'start_time' => $start.':00', 'end_time' => $end.':00', 'break_minutes' => $break, 'hours' => $hours,
                        'regular_hours' => $reg, 'overtime_hours' => $ot, 'billable' => 1,
                        'billable_rate' => $bill, 'cost_rate' => $cost,
                        'billable_amount' => round($hours * $bill, 2), 'labor_cost' => round($hours * $cost, 2),
                        'status' => $status, 'source' => $source,
                        'submitted_at' => in_array($status, ['submitted', 'approved'], true) ? $now : null,
                        'approved_at' => $status === 'approved' ? $now : null, 'approved_by' => $status === 'approved' ? $user : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                if ($people->isNotEmpty() && ! DB::table('job_attendances')->where('job_id', $oak)->exists()) {
                    $day = $now->copy()->subDay();
                    DB::table('job_attendances')->insert([
                        'user_id' => $people[0]->user_id, 'job_id' => $oak, 'date' => $day->toDateString(), 'status' => 'checkedOut',
                        'check_in_at' => $day->copy()->setTime(7, 2), 'check_out_at' => $day->copy()->setTime(15, 41),
                        'check_in_method' => 'geofence', 'check_out_method' => 'geofence',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            // Line items for the demo invoices, so their detail pages are not empty.
            foreach (DB::table('invoices')->where('invoice_number', 'like', 'DEMO-%')->get(['id', 'subtotal']) as $invoice) {
                if (DB::table('invoice_items')->where('invoice_id', $invoice->id)->exists()) {
                    continue;
                }
                $half = round((float) $invoice->subtotal * 0.6, 2);
                DB::table('invoice_items')->insert([
                    ['invoice_id' => $invoice->id, 'description' => 'Rough-in labor and materials', 'quantity' => 1, 'unit_price' => $half, 'total' => $half, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
                    ['invoice_id' => $invoice->id, 'description' => 'Fixtures, trim and testing', 'quantity' => 1, 'unit_price' => round((float) $invoice->subtotal - $half, 2), 'total' => round((float) $invoice->subtotal - $half, 2), 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
                ]);
            }

            // Time-off requests from the crew (Will, jack, Robert), in each state the Leave screen shows.
            if (! DB::table('leave_requests')->where('reason', 'like', 'Demo:%')->exists()) {
                $crew = DB::table('users')->whereIn('name', ['Robert', 'Will', 'jack'])->pluck('id', 'name');
                $d = fn (int $n) => $now->copy()->addDays($n)->toDateString();
                $rows = [
                    ['Will', 'vacation', 5, 7, 'pending', 'Demo: family trip, booked months ago.', null, null],
                    ['jack', 'sick', 6, 6, 'pending', 'Demo: dental surgery, doctor’s note available.', null, null],
                    ['Robert', 'personal', 13, 15, 'approved', 'Demo: moving house.', 'Approved — Oakridge is covered.', $now],
                    ['Will', 'unpaid', -12, -10, 'rejected', 'Demo: extended weekend.', 'Panel upgrade crunch that week — please pick another date.', $now->copy()->subDays(14)],
                ];
                foreach ($rows as [$who, $type, $from, $to, $status, $reason, $note, $decidedAt]) {
                    if (! isset($crew[$who])) {
                        continue;
                    }
                    DB::table('leave_requests')->insert([
                        'company_id' => $company, 'user_id' => $crew[$who], 'type' => $type,
                        'start_date' => $d($from), 'end_date' => $d($to), 'status' => $status, 'reason' => $reason,
                        'decision_note' => $note, 'decided_at' => $decidedAt, 'decided_by' => $decidedAt ? $user : null,
                        'created_at' => $now->copy()->subDays(2), 'updated_at' => $now,
                    ]);
                }
            }

            // A handful of notifications for the demo records above, in the app's own types, so the bell and
            // the Notifications screen show linked items (each opens the record it is about).
            if (! DB::table('app_notifications')->where('title', 'like', 'Demo:%')->exists()) {
                $mine = DB::table('users')->where('email', 'claude-test@example.test')->value('id') ?? $user;
                $items = [
                    ['change-order-submitted', 'Demo: change order waiting', 'CO-001 · Demo Oakridge Retrofit — $3,001.50 needs your approval', '/change-orders/3', [['href' => '/change-orders/3', 'label' => 'Review change order']], 0],
                    ['time-entry-submitted', 'Demo: time entry to approve', 'Robert logged 10h on Demo Oakridge Retrofit', '/time-tracking/entries/3', [['href' => '/time-tracking/entries/3', 'label' => 'Review time entry']], 0],
                    ['leave-requested', 'Demo: leave request', 'Will asked for time off, Nov 24 – 26', '/leave', [['href' => '/leave', 'label' => 'Manage leave']], 0],
                    ['invoice-sent', 'Demo: invoice sent', 'DEMO-001 for $19,310.00 went to Demo Client', '/invoices/4', [['href' => '/invoices/4', 'label' => 'View invoice']], 1],
                ];
                foreach ($items as $i => [$type, $title, $detail, $link, $actions, $read]) {
                    DB::table('app_notifications')->insert([
                        'user_id' => $mine, 'type' => $type, 'title' => $title, 'detail' => $detail, 'link' => $link,
                        'data' => json_encode(['actions' => $actions]), 'read_at' => $read ? $now : null,
                        'created_at' => $now->copy()->subHours(($i + 1) * 3), 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Insert the row unless one with these keys exists; return its id. */
    private function once(string $table, array $keys, array $values): int
    {
        $id = DB::table($table)->where($keys)->value('id');

        return $id ?: DB::table($table)->insertGetId($keys + $values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
