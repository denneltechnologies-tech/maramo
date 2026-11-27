<?php

namespace Database\Seeders;

use App\Support\Modules;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoSchoolSeeder::class,
            // Demo data of each installed package (see App\Support\Modules::DEMO_SEEDERS).
            ...Modules::existing(Modules::DEMO_SEEDERS),
            // Last, so every package's permissions exist before roles get them.
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
