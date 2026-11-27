<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skip the test unless the packages providing these classes are installed, so the
     * suite passes with any subset of Classivo packages in app/composer.json.
     */
    protected function requirePackages(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                $this->markTestSkipped("Needs {$class} (package not installed).");
            }
        }
    }
}
