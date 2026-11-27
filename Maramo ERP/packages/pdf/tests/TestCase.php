<?php

namespace Classivo\Pdf\Tests;

use Classivo\Pdf\PdfServiceProvider;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [PdfServiceProvider::class];
    }
}
