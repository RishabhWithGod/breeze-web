<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\User;
use App\Support\UsPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phone numbers, written the way the United States writes them.
 *
 * The format is a rule rather than a preference here: everyone this app dials
 * is on a US number. What is stored is normalised on the way in, so no screen
 * has to decide for itself how to draw one.
 */
class UsFormattingTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
    }

    public static function typedNumbers(): array
    {
        return [
            'bare digits' => ['4155550134'],
            'dashes' => ['415-555-0134'],
            'dots' => ['415.555.0134'],
            'spaces' => ['415 555 0134'],
            'already formatted' => ['(415) 555-0134'],
            'country code' => ['+1 415 555 0134'],
            'country code, no plus' => ['14155550134'],
        ];
    }

    /**
     * However it is typed, one number comes out.
     *
     * People paste numbers from email signatures, contact cards and their own
     * memory. Refusing the punctuation would be refusing the number.
     *
     * @dataProvider typedNumbers
     */
    public function test_a_number_is_stored_the_same_way_however_it_was_typed(string $typed): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => $typed])
            ->assertSessionHasNoErrors();

        $this->assertSame('(415) 555-0134', Foreman::sole()->phone);
    }

    public static function impossibleNumbers(): array
    {
        return [
            'too short' => ['555'],
            'too long' => ['415555013456'],
            'all zeroes' => ['0000000000'],
            'counting up' => ['1234567890'],
            // The numbering plan issues neither: an area code and an exchange
            // code both begin 2-9.
            'area code starts with one' => ['1155550134'],
            'exchange starts with zero' => ['4150550134'],
            'letters' => ['call me later'],
        ];
    }

    /** @dataProvider impossibleNumbers */
    public function test_a_number_that_could_not_ring_is_refused(string $typed): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => $typed])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Foreman::count());
    }

    public function test_a_foreman_may_have_no_phone_number(): void
    {
        // Optional, and still optional. A foreman exists to be handed work.
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(Foreman::sole()->phone);
    }

    public function test_correcting_a_number_stores_it_formatted_too(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->put(route('foremen.update', $dana), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => '2125551212'])
            ->assertSessionHasNoErrors();

        $this->assertSame('(212) 555-1212', $dana->refresh()->phone);
    }

    public function test_the_register_screens_show_the_stored_number(): void
    {
        $dana = Foreman::create([
            'name' => 'Dana Wu',
            'initials' => 'DW',
            'phone' => '(415) 555-0134',
        ]);

        // Formatted once on the way in, so the list, the detail screen and the
        // edit form all read the column and are already right.
        foreach ([route('teams.index'), route('foremen.show', $dana), route('foremen.edit', $dana)] as $url) {
            $this->actingAs($this->planner)->get($url)
                ->assertOk()
                ->assertSee('(415) 555-0134');
        }
    }

    /** Anything the class cannot read is somebody's data, and is left alone. */
    public function test_a_number_it_cannot_read_is_returned_untouched(): void
    {
        $this->assertSame('ext. 4021', UsPhone::format('ext. 4021'));
        $this->assertNull(UsPhone::format(null));
        $this->assertNull(UsPhone::format(''));
    }
}
