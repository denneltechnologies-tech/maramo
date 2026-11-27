<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo seeder (well-known passwords) refuses to run on production installs. */
class DemoSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            app(DemoSchoolSeeder::class)->run();
            $this->fail('Expected the demo seeder to refuse production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CLASSIVO_ALLOW_DEMO_SEED', $e->getMessage());
        }

        $this->assertFalse(User::where('email', 'admin@example.com')->exists());
    }
}
