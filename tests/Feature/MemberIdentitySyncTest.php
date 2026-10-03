<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberIdentitySyncTest extends TestCase
{
    use RefreshDatabase;

    private function linkedMember(): array
    {
        $user = User::factory()->create(['name' => 'Old Name', 'role' => 'Journeyman', 'phone' => '+15550001111']);
        $foreman = new Foreman(['name' => 'Old Name', 'initials' => 'ON', 'role' => 'journeyman']);
        $foreman->user_id = $user->id;
        $foreman->save();
        $member = new TeamMember(['name' => 'Old Name', 'initials' => 'ON', 'role' => 'Journeyman']);
        $member->user_id = $user->id;
        $member->save();

        return [$user, $foreman, $member];
    }

    public function test_editing_the_register_row_updates_the_account_crew_record_and_assignments(): void
    {
        [$user, $foreman, $member] = $this->linkedMember();
        $job = Job::create(['name' => 'J', 'client' => 'C', 'job_type' => 'commercial', 'status' => 'in-progress']);
        DB::table('job_assignments')->insert([
            'job_id' => $job->id, 'user_id' => $user->id, 'team_member_id' => $member->id,
            'role' => 'electrician', 'name' => 'Old Name', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreman->update(['name' => 'New Person', 'initials' => 'NP', 'role' => Foreman::ROLE_FOREMAN]);

        $this->assertSame('New Person', $user->fresh()->name);
        $this->assertSame('NP', $user->fresh()->initials);
        $this->assertSame('Foreman', $user->fresh()->role);
        $this->assertSame('New Person', $member->fresh()->name);
        $this->assertSame('Foreman', $member->fresh()->role);
        $this->assertSame('New Person', DB::table('job_assignments')->value('name'));
    }

    public function test_the_register_never_rewrites_sign_in_identifiers_or_a_managers_role(): void
    {
        [$user, $foreman] = $this->linkedMember();
        $foreman->update(['phone' => '+15559998888', 'email' => 'other@example.com']);
        $this->assertSame('+15550001111', $user->fresh()->phone);
        $this->assertNotSame('other@example.com', $user->fresh()->email);

        $manager = User::factory()->create(['role' => 'Project Manager']);
        $row = new Foreman(['name' => 'Boss', 'initials' => 'B', 'role' => 'foreman']);
        $row->user_id = $manager->id;
        $row->save();
        $this->assertSame('Project Manager', $manager->fresh()->role);
    }

    public function test_editing_the_account_updates_the_register_and_crew_record(): void
    {
        [$user, $foreman, $member] = $this->linkedMember();

        $user->update(['name' => 'Renamed Account', 'phone' => '+15557778888']);

        $this->assertSame('Renamed Account', $foreman->fresh()->name);
        $this->assertSame('RA', $foreman->fresh()->initials);
        $this->assertSame('+15557778888', $foreman->fresh()->phone);
        $this->assertSame('Renamed Account', $member->fresh()->name);
    }
}
