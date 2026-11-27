<?php

namespace Classivo\Exams\Database\Seeders;

use Classivo\Exams\Support\DefaultScales;
use Illuminate\Database\Seeder;

/** Seeds the default grading scale and divisions for the current school/branch/session. */
class ExamsDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        DefaultScales::seed();
    }
}
