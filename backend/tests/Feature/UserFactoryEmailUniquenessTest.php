<?php

namespace Tests\Feature;

use App\Models\User;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MySQL-only concurrency tests (M2_20BIsolationTest, InventoryMysqlConcurrencyTest,
 * M2_19_1_InventoryDecreaseIdempotencyTest) DB::commit() so worker processes can see their
 * data, and do not delete it afterwards - so the users they created with Faker emails stay
 * in the database for the rest of the run. Every later test gets a brand-new Faker
 * generator whose unique() tracker knows nothing about those rows, so it can (rarely)
 * re-generate the very same email and fail with SQLSTATE 1062 on users.users_email_unique
 * in some unrelated test's setUp (seen in CI: M2_20CCapabilityTest, 'adach@example.com').
 * The factory must therefore not derive an email from Faker's sequence alone.
 */
class UserFactoryEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_email_does_not_collide_when_a_fresh_faker_repeats_the_same_sequence(): void
    {
        fake()->seed(20260919);
        $leftover = User::factory()->create();

        // Simulate a later test: a brand-new generator (empty unique() tracker) that yields
        // exactly the same values while the earlier committed row is still present.
        app()->forgetInstance(Generator::class.':en_US');
        fake()->seed(20260919);
        $later = User::factory()->create();

        $this->assertNotSame($leftover->email, $later->email);
        $this->assertSame(2, User::whereIn('id', [$leftover->id, $later->id])->count());
    }
}
