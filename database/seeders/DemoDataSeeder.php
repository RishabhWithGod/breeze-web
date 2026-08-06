<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Estimate;
use App\Models\FeedItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\PerformancePoint;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the workspace the UI was designed against.
 *
 * Every row here came from the fixture files the React prototype used before
 * this became a Laravel app, so a fresh `migrate --seed` reproduces the exact
 * screens the design was signed off on.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->seedDemoUser();

        $this->seedTeamMembers();
        $this->seedJobs();
        $this->seedEstimates();
        $this->seedJobModule($user);
        $projects = $this->seedProjects($user);
        $this->seedTakeoffResults($projects['Westside Commercial Complex']);
        $this->seedUploads($user);
        $this->seedNotifications($user);
        $this->seedFeedItems();
        $this->seedPerformance();
    }

    private function seedDemoUser(): User
    {
        return User::updateOrCreate(
            ['email' => 'demo@breeze.ai'],
            [
                'name' => 'Alex Morgan',
                'role' => 'Project Manager',
                'password' => 'breeze123',
                'email_verified_at' => now(),
            ],
        );
    }

    private function seedTeamMembers(): void
    {
        $crew = [
            ['Aisha Bello', 'AB', 'Master Electrician'],
            ['Marco Ruiz', 'MR', 'Journeyman Electrician'],
            ['Jen Halvorsen', 'JH', 'Journeyman Electrician'],
            ['Tomas Nowak', 'TN', 'Apprentice'],
            ['Grace Lim', 'GL', 'Estimator'],
            ['Owen Pratt', 'OP', 'Site Supervisor'],
            ['Nadia Farah', 'NF', 'Controls Technician'],
            ['Eli Brandt', 'EB', 'Apprentice'],
        ];

        foreach ($crew as [$name, $initials, $role]) {
            TeamMember::create([
                'name' => $name,
                'initials' => $initials,
                'role' => $role,
            ]);
        }
    }

    /**
     * Gives the first few jobs a crew, notes, an activity trail, a status
     * history and a linked estimate, so the detail screen has real relationships
     * to render on a fresh install.
     */
    private function seedJobModule(User $user): void
    {
        $members = TeamMember::orderBy('id')->get();
        $jobs = Job::orderBy('id')->take(6)->get();

        foreach ($jobs as $index => $job) {
            // Crew: three members per job, rotating through the pool.
            $assigned = $members->slice($index % 3, 3);

            foreach ($assigned as $position => $member) {
                $job->teamMembers()->attach($member->id, [
                    'role_on_job' => $position === 0 ? 'Lead' : null,
                ]);
            }

            $job->statusChanges()->create([
                'user_id' => $user->id,
                'from_status' => null,
                'to_status' => 'planning',
                'created_at' => $job->created_at,
                'updated_at' => $job->created_at,
            ]);

            if ($job->status !== 'planning') {
                $job->statusChanges()->create([
                    'user_id' => $user->id,
                    'from_status' => 'planning',
                    'to_status' => $job->status,
                    'created_at' => $job->created_at->addDay(),
                    'updated_at' => $job->created_at->addDay(),
                ]);
            }

            $job->activities()->create([
                'user_id' => $user->id,
                'type' => 'created',
                'description' => 'Job created',
                'created_at' => $job->created_at,
                'updated_at' => $job->created_at,
            ]);

            $job->activities()->create([
                'user_id' => $user->id,
                'type' => 'team_assigned',
                'description' => $assigned->count().' crew members assigned',
                'created_at' => $job->created_at->addHours(2),
                'updated_at' => $job->created_at->addHours(2),
            ]);

            if ($index < 3) {
                $job->notes()->create([
                    'user_id' => $user->id,
                    'body' => 'Client walked the site with us — panel schedule needs a revision before rough-in.',
                ]);

                $job->activities()->create([
                    'user_id' => $user->id,
                    'type' => 'note_added',
                    'description' => 'Note added',
                    'created_at' => $job->created_at->addHours(3),
                    'updated_at' => $job->created_at->addHours(3),
                ]);
            }
        }

        // Tie the first four estimates to the first four jobs, so the estimate
        // relationship is populated in both directions.
        $estimates = Estimate::orderBy('id')->take(4)->get();

        foreach ($estimates as $index => $estimate) {
            $job = $jobs[$index] ?? null;

            if (! $job) {
                continue;
            }

            $estimate->update(['job_id' => $job->id]);

            $job->activities()->create([
                'user_id' => $user->id,
                'type' => 'estimate_created',
                'description' => "Estimate {$estimate->number} created",
                'meta' => ['estimate_id' => $estimate->id, 'number' => $estimate->number],
                'created_at' => $job->created_at->addHours(4),
                'updated_at' => $job->created_at->addHours(4),
            ]);
        }
    }

    private function seedJobs(): void
    {
        $foremen = collect([
            ['name' => 'Michael Torres', 'initials' => 'MT'],
            ['name' => 'Dana Wu', 'initials' => 'DW'],
            ['name' => 'Priya Raman', 'initials' => 'PR'],
            ['name' => 'Luis Ortega', 'initials' => 'LO'],
            ['name' => 'Sam Okafor', 'initials' => 'SO'],
        ])->map(fn (array $attributes) => Foreman::create($attributes))->all();

        // [name, status, foreman index, start, end, budget]
        $jobs = [
            ['Oakridge Medical Center Retrofit', 'in-progress', 0, '2026-04-12', '2026-10-15', 24850],
            ['Westside Commercial Complex', 'on-hold', 1, '2026-03-02', '2026-09-30', 184250],
            ['Harborview Data Hall', 'delayed', 2, '2026-01-19', '2026-08-21', 412600],
            ['Lakeside Residences Tower A', 'scheduled', 3, '2026-09-01', '2027-02-26', 96400],
            ['Foundry District Mixed-Use', 'planning', 4, '2026-10-05', '2027-06-18', 268900],
            ['Riverbend Logistics Warehouse', 'in-progress', 0, '2026-02-24', '2026-11-13', 138750],
            ['Northgate Retail Fit-out', 'delayed', 1, '2026-05-11', '2026-09-04', 42300],
            ['Civic Center Renovation', 'in-progress', 2, '2026-03-16', '2026-12-11', 205400],
            ['Summit Ridge School Annex', 'scheduled', 3, '2026-08-24', '2027-04-09', 87600],
            ['Beacon Hotel Refurbishment', 'on-hold', 4, '2026-01-06', '2026-07-31', 156200],
            ['Greenfield Sports Complex', 'in-progress', 0, '2026-04-27', '2027-01-22', 321500],
            ['Pinecrest Assisted Living', 'planning', 1, '2026-11-16', '2027-08-06', 74900],
            ['Birchwood Office Complex', 'in-progress', 2, '2026-02-09', '2026-10-30', 198300],
            ['Maplewood Apartments', 'scheduled', 3, '2026-09-14', '2027-05-07', 112450],
            ['Clearwater Residence', 'delayed', 4, '2026-06-01', '2026-09-18', 38700],
            ['Highland Office Park — Building C', 'in-progress', 0, '2026-03-23', '2026-12-04', 243800],
            ['Cedar Point Distribution Center', 'on-hold', 1, '2026-01-26', '2026-08-14', 367900],
            ['Union Station Retail Concourse', 'planning', 2, '2026-12-07', '2027-09-24', 129600],
            ['Westgate Medical Offices', 'in-progress', 3, '2026-05-04', '2027-01-15', 176400],
            ['Riverside Apartments Phase 2', 'scheduled', 4, '2026-10-19', '2027-07-02', 204750],
            ['Aspen Grove Community Center', 'delayed', 0, '2026-02-16', '2026-09-11', 68200],
            ['Fairview Fire Station 7', 'in-progress', 1, '2026-04-06', '2026-11-27', 91300],
            ['Northshore Hotel Tower B', 'planning', 2, '2027-01-11', '2027-10-29', 289500],
            ['Trailhead Logistics Hub', 'on-hold', 3, '2026-03-09', '2026-10-23', 153000],
        ];

        // Clients and sites cycled deterministically so the intake fields the
        // Create New Job screen captures are populated for seeded rows too.
        $clients = [
            'Horizon Builders Inc.',
            'Sterling Health Group',
            'Vertex Infrastructure',
            'Meridian Living',
            'Crestline Developments',
            'Anchor Retail Partners',
            'City of Fairview',
            'Cardinal Hospitality',
        ];

        $locations = [
            'Fairview, CA',
            'Oakridge, CA',
            'Harbor District, CA',
            'Lakeside, CA',
            'Foundry District, CA',
            'Riverbend, CA',
        ];

        $types = ['commercial', 'residential', 'industrial'];

        foreach ($jobs as $index => [$name, $status, $foremanIndex, $start, $end, $budget]) {
            Job::create([
                'name' => $name,
                'client' => $clients[$index % count($clients)],
                'location' => $locations[$index % count($locations)],
                'job_type' => $types[$index % count($types)],
                'status' => $status,
                'foreman_id' => $foremen[$foremanIndex]->id,
                'start_date' => $start,
                'end_date' => $end,
                'budget' => $budget,
            ]);
        }
    }

    /**
     * @return array<string, Project> keyed by project name
     */
    private function seedEstimates(): void
    {
        // [project, client, issued_on, amount, status]
        $rows = [
            ['Office Building Renovation', 'Westview Properties', '2026-08-01', 24850.00, 'approved'],
            ['Westside Commercial Complex', 'Horizon Builders Inc.', '2026-07-30', 184250.00, 'sent'],
            ['Oakwood Medical Center — Phase 2', 'Sterling Health Group', '2026-07-28', 96400.00, 'approved'],
            ['Riverbend Logistics Warehouse', 'Crestline Developments', '2026-07-24', 42300.00, 'draft'],
            ['Lakeside Residences Tower A', 'Meridian Living', '2026-07-21', 138750.00, 'approved'],
            ['Northgate Retail Fit-out', 'Anchor Retail Partners', '2026-07-17', 31980.00, 'rejected'],
            ['Harborview Data Hall', 'Vertex Infrastructure', '2026-07-14', 412600.00, 'sent'],
            ['Civic Center Renovation', 'City of Fairview', '2026-07-10', 205400.00, 'approved'],
            ['Summit Ridge School Annex', 'Fairview School District', '2026-07-07', 87600.00, 'draft'],
            ['Beacon Hotel Refurbishment', 'Cardinal Hospitality', '2026-07-02', 156200.00, 'sent'],
            ['Greenfield Sports Complex', 'Parks & Recreation Authority', '2026-06-29', 321500.00, 'approved'],
            ['Pinecrest Assisted Living', 'Sterling Health Group', '2026-06-25', 74900.00, 'rejected'],
            ['Birchwood Office Complex', 'Horizon Builders Inc.', '2026-06-22', 198300.00, 'approved'],
            ['Maplewood Apartments', 'Meridian Living', '2026-06-18', 112450.00, 'approved'],
            ['Clearwater Residence', 'Private Client', '2026-06-15', 38700.00, 'sent'],
            ['Highland Office Park — Building C', 'Anchor Retail Partners', '2026-06-11', 243800.00, 'draft'],
            ['Cedar Point Distribution Center', 'Crestline Developments', '2026-06-08', 367900.00, 'approved'],
            ['Union Station Retail Concourse', 'City of Fairview', '2026-06-03', 129600.00, 'rejected'],
            ['Westgate Medical Offices', 'Sterling Health Group', '2026-05-29', 176400.00, 'sent'],
            ['Riverside Apartments Phase 2', 'Meridian Living', '2026-05-26', 204750.00, 'approved'],
            ['Aspen Grove Community Center', 'Parks & Recreation Authority', '2026-05-21', 68200.00, 'draft'],
            ['Fairview Fire Station 7', 'City of Fairview', '2026-05-18', 91300.00, 'approved'],
            ['Northshore Hotel Tower B', 'Cardinal Hospitality', '2026-05-14', 289500.00, 'sent'],
            ['Trailhead Logistics Hub', 'Vertex Infrastructure', '2026-05-11', 153000.00, 'approved'],
        ];

        $sequence = 1082;

        foreach ($rows as [$project, $client, $issuedOn, $amount, $status]) {
            Estimate::create([
                'number' => 'EST-'.$sequence++,
                'client' => $client,
                'project' => $project,
                'issued_on' => $issuedOn,
                'amount' => $amount,
                'status' => $status,
            ]);
        }
    }

    private function seedProjects(User $user): array
    {
        // [name, client, date, status, items]
        $rows = [
            ['Westside Commercial Complex', 'Horizon Builders Inc.', '2026-08-03T09:16:42Z', 'completed', 848],
            ['Oakwood Medical Center — Phase 2', 'Sterling Health Group', '2026-07-29T14:02:00Z', 'converted', 612],
            ['Riverbend Logistics Warehouse', 'Crestline Developments', '2026-07-24T11:20:00Z', 'draft', 233],
            ['Lakeside Residences Tower A', 'Meridian Living', '2026-07-18T16:45:00Z', 'completed', 1042],
            ['Northgate Retail Fit-out', 'Anchor Retail Partners', '2026-07-11T09:30:00Z', 'failed', 0],
            ['Civic Center Renovation', 'City of Fairview', '2026-07-04T13:15:00Z', 'converted', 487],
            ['Harborview Data Hall', 'Vertex Infrastructure', '2026-06-27T10:05:00Z', 'completed', 1596],
            ['Summit Ridge School Annex', 'Unified School District 12', '2026-06-19T08:50:00Z', 'draft', 164],
            ['Beacon Hotel Refurbishment', 'Cardinal Hospitality', '2026-06-12T15:35:00Z', 'completed', 731],
            ['Foundry District Mixed-Use', 'Ironwood Capital', '2026-06-05T12:10:00Z', 'processing', 0],
            ['Greenfield Sports Complex', 'Parks & Recreation Authority', '2026-05-30T17:25:00Z', 'completed', 905],
            ['Pinecrest Assisted Living', 'Sterling Health Group', '2026-05-22T09:55:00Z', 'converted', 358],
            ['Birchwood Office Complex', 'Horizon Builders Inc.', '2026-05-14T10:40:00Z', 'completed', 674],
            ['Maplewood Apartments', 'Meridian Living', '2026-05-06T14:18:00Z', 'converted', 512],
            ['Clearwater Residence', 'Private Client', '2026-04-28T09:05:00Z', 'draft', 96],
            ['Highland Office Park — Building C', 'Anchor Retail Partners', '2026-04-19T16:22:00Z', 'completed', 883],
            ['Cedar Point Distribution Center', 'Crestline Developments', '2026-04-11T11:48:00Z', 'completed', 1265],
            ['Union Station Retail Concourse', 'City of Fairview', '2026-04-02T13:37:00Z', 'draft', 204],
            ['Westgate Medical Offices', 'Sterling Health Group', '2026-03-25T08:15:00Z', 'converted', 447],
            ['Riverside Apartments Phase 2', 'Meridian Living', '2026-03-17T15:52:00Z', 'completed', 926],
            ['Aspen Grove Community Center', 'Parks & Recreation Authority', '2026-03-08T10:09:00Z', 'failed', 0],
            ['Fairview Fire Station 7', 'City of Fairview', '2026-02-27T12:44:00Z', 'completed', 318],
            ['Northshore Hotel Tower B', 'Cardinal Hospitality', '2026-02-18T17:03:00Z', 'draft', 152],
            ['Trailhead Logistics Hub', 'Vertex Infrastructure', '2026-02-09T09:31:00Z', 'converted', 1074],
        ];

        $projects = [];

        foreach ($rows as [$name, $client, $date, $status, $items]) {
            $at = Carbon::parse($date);
            $isFinished = in_array($status, ['completed', 'converted'], true);

            $project = Project::create([
                'user_id' => $user->id,
                'name' => $name,
                'client' => $client,
                'status' => $status,
                'items_count' => $items,
                'completed_at' => $isFinished ? $at : null,
            ]);

            // The history table orders on the fixture date, not on seed time.
            $project->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            $projects[$name] = $project;
        }

        return $projects;
    }

    /** Full detail for the one project the results dashboard opens on. */
    private function seedTakeoffResults(Project $project): void
    {
        $project->update([
            'drawing_name' => 'E-201 — Level 2 Lighting & Power Plan',
            'discipline' => 'Electrical',
            'page_count' => 14,
            'overall_confidence' => 0.94,
            'started_at' => Carbon::parse('2026-08-03T09:12:00Z'),
            'completed_at' => Carbon::parse('2026-08-03T09:16:42Z'),
        ]);

        $sheets = [
            ['E-101', 'Level 1 Lighting Plan', 3, '1/8" = 1\'-0"', 142],
            ['E-201', 'Level 2 Lighting & Power Plan', 4, '1/8" = 1\'-0"', 186],
            ['E-301', 'Panel Schedules & Riser', 4, 'NTS', 74],
            ['E-401', 'Fire Alarm & Low Voltage', 3, '1/8" = 1\'-0"', 96],
        ];

        foreach ($sheets as $position => [$code, $title, $pages, $scale, $symbolCount]) {
            $project->sheets()->create([
                'code' => $code,
                'title' => $title,
                'page_count' => $pages,
                'scale' => $scale,
                'symbol_count' => $symbolCount,
                'position' => $position,
            ]);
        }

        $symbols = [
            ['L1', '2x4 LED Troffer', 'Lighting', 148, 0.97],
            ['L2', 'Recessed Downlight 6"', 'Lighting', 92, 0.95],
            ['EX', 'Exit Sign — Edge Lit', 'Lighting', 24, 0.91],
            ['R1', 'Duplex Receptacle 20A', 'Power', 214, 0.96],
            ['R2', 'GFCI Receptacle', 'Power', 38, 0.88],
            ['SW', 'Single Pole Switch', 'Power', 76, 0.93],
            ['DT', 'Data Outlet — Cat6', 'Data', 118, 0.84],
            ['SD', 'Smoke Detector', 'Fire Alarm', 46, 0.90],
            ['HS', 'Horn / Strobe', 'Fire Alarm', 29, 0.72],
            ['PB', 'Panelboard 208Y/120V', 'Distribution', 6, 0.99],
            ['XF', 'Dry Type Transformer 45 kVA', 'Distribution', 3, 0.98],
            ['JB', 'Junction Box', 'Distribution', 54, 0.58],
        ];

        foreach ($symbols as $position => [$code, $name, $category, $count, $confidence]) {
            $project->symbols()->create([
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'count' => $count,
                'confidence' => $confidence,
                'unit' => 'EA',
                'position' => $position,
            ]);
        }

        $metrics = [
            ['Symbols detected', '848', '+12%', 'up', 'vs. previous revision'],
            ['Material cost', '$184,250', '+4.1%', 'up', 'Estimated, RS Means 2026'],
            ['Labor hours', '1,962 hrs', '-3.4%', 'down', 'NECA labour units'],
            ['Avg. confidence', '94%', '+1.8%', 'up', '12 symbol classes'],
        ];

        foreach ($metrics as $position => [$label, $value, $delta, $trend, $hint]) {
            $project->metrics()->create([
                'label' => $label,
                'value' => $value,
                'delta' => $delta,
                'trend' => $trend,
                'hint' => $hint,
                'position' => $position,
            ]);
        }

        $activities = [
            ['Takeoff completed', '848 symbols detected across 14 sheets.', 'success', '2026-08-03T09:16:42Z'],
            ['Legend matched', '12 of 13 legend entries mapped automatically.', 'brand', '2026-08-03T09:15:10Z'],
            ['Low confidence flagged', 'Junction Box (JB) scored 58% — manual review suggested.', 'warning', '2026-08-03T09:14:38Z'],
            ['Sheets rasterised', 'All 14 pages normalised at 400 DPI.', 'info', '2026-08-03T09:13:02Z'],
            ['Upload received', 'Westside_Commercial_E-Series.pdf (18.4 MB).', 'info', '2026-08-03T09:12:00Z'],
        ];

        foreach ($activities as [$title, $description, $tone, $occurredAt]) {
            $project->activities()->create([
                'title' => $title,
                'description' => $description,
                'tone' => $tone,
                'occurred_at' => Carbon::parse($occurredAt),
            ]);
        }
    }

    private function seedUploads(User $user): void
    {
        // [name, format, size in MB, uploaded at, status]
        $uploads = [
            ['Office_Building_Plans.pdf', 'PDF', 18.4, '2026-08-03T08:15:00Z', 'completed'],
            ['Commercial_Specs.dwg', 'DWG', 42.1, '2026-08-03T06:40:00Z', 'processing'],
            ['Residential_Model.bim', 'BIM', 76.9, '2026-08-02T21:05:00Z', 'failed'],
        ];

        foreach ($uploads as [$name, $format, $megabytes, $uploadedAt, $status]) {
            $at = Carbon::parse($uploadedAt);

            $upload = $user->uploads()->create([
                'name' => $name,
                'format' => $format,
                'size_bytes' => (int) round($megabytes * 1024 * 1024),
                'status' => $status,
            ]);

            $upload->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function seedNotifications(User $user): void
    {
        $notifications = [
            ['Site Visit — Oakwood Medical', 'Oct 3, 2026 • 9:00 AM', '2026-08-03T07:00:00Z'],
            ['Takeoff ready for review', 'Westside Commercial Complex • 848 items', '2026-08-03T06:20:00Z'],
            ['Estimate approved', 'Lakeside Residences Tower A', '2026-08-02T18:44:00Z'],
            ['New comment from Dana Wu', '“Can you confirm the panel schedule?”', '2026-08-02T15:12:00Z'],
            ['Invoice #4821 paid', 'Horizon Builders Inc. • $12,400', '2026-08-01T11:03:00Z'],
        ];

        foreach ($notifications as [$title, $detail, $createdAt]) {
            $at = Carbon::parse($createdAt);

            $notification = AppNotification::create([
                'user_id' => $user->id,
                'title' => $title,
                'detail' => $detail,
            ]);

            $notification->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function seedFeedItems(): void
    {
        $dashboardActivity = [
            [[['text' => 'Estimate #E-2023-094 for Clearwater Residence was sent to client']], null, 'Today, 10:23 AM', 'file-text', 'lilac'],
            [[['text' => 'Maplewood Apartments', 'strong' => true], ['text' => ' estimate was approved']], null, 'Today, 10:23 AM', 'file-text', 'lilac'],
            [[['text' => 'AI Takeoff completed for '], ['text' => 'Birchwood Office', 'strong' => true], ['text' => ' Complex']], null, 'Today, 10:23 AM', 'bot', 'butter'],
            [[['text' => 'New job created: '], ['text' => 'Riverside Apartments', 'strong' => true], ['text' => ' Phase 2']], null, 'Today, 10:23 AM', 'briefcase', 'lilac'],
            [[['text' => 'Maplewood Apartments', 'strong' => true], ['text' => ' estimate was approved']], null, 'Today, 10:23 AM', 'file-text', 'lilac'],
        ];

        $dashboardNotifications = [
            [[['text' => 'Site meeting tomorrow', 'strong' => true]], 'Oakwood Medical Center, 9:00 AM', '1 hour ago', 'calendar-check', 'lilac'],
            [[['text' => 'Estimate approved', 'strong' => true]], 'Maplewood Apartments - $87,450', 'Yesterday', 'file-text', 'lilac'],
            [[['text' => 'AI Takeoff completed', 'strong' => true]], 'Birchwood Office Complex - Ready for review', '1 hour ago', 'bot', 'butter'],
            [[['text' => 'Material shortage alert', 'strong' => true]], 'Panel boxes for Highland Office Park', '2 days ago', 'triangle-alert', 'lilac'],
        ];

        $dashboardSchedule = array_fill(0, 3, [
            [['text' => 'Site Visit - Oakwood Medical', 'strong' => true]],
            'Oct 3, 2026 • 9:00 AM',
            null,
            'calendar-check',
            'lilac',
        ]);

        $historyActivity = [
            [[['text' => 'AI Takeoff completed for '], ['text' => 'Birchwood Office Complex', 'strong' => true]], null, 'Today, 10:23 AM', 'bot', 'butter'],
            [[['text' => 'Maplewood Apartments', 'strong' => true], ['text' => ' estimate was approved']], null, 'Today, 10:23 AM', 'file-text', 'lilac'],
            [[['text' => 'AI Takeoff completed for '], ['text' => 'Birchwood Office Complex', 'strong' => true]], null, 'Today, 10:23 AM', 'bot', 'butter'],
            [[['text' => 'Maplewood Apartments', 'strong' => true], ['text' => ' estimate was approved']], null, 'Today, 10:23 AM', 'file-text', 'lilac'],
        ];

        $scopes = [
            FeedItem::DASHBOARD_ACTIVITY => $dashboardActivity,
            FeedItem::DASHBOARD_NOTIFICATIONS => $dashboardNotifications,
            FeedItem::DASHBOARD_SCHEDULE => $dashboardSchedule,
            FeedItem::HISTORY_ACTIVITY => $historyActivity,
        ];

        foreach ($scopes as $scope => $rows) {
            foreach ($rows as $position => [$segments, $detail, $meta, $icon, $tile]) {
                FeedItem::create([
                    'scope' => $scope,
                    'segments' => $segments,
                    'detail' => $detail,
                    'meta' => $meta,
                    'icon' => $icon,
                    'tile' => $tile,
                    'position' => $position,
                ]);
            }
        }
    }

    private function seedPerformance(): void
    {
        $series = [
            'Jan' => 65, 'Feb' => 71, 'Mar' => 58, 'Apr' => 80,
            'May' => 75, 'Jun' => 90, 'Jul' => 85, 'Aug' => 78,
            'Sep' => 82, 'Oct' => 88, 'Nov' => 92, 'Dec' => 95,
        ];

        $position = 0;

        foreach ($series as $month => $value) {
            PerformancePoint::create([
                'month' => $month,
                'value' => $value,
                'position' => $position++,
            ]);
        }
    }
}
