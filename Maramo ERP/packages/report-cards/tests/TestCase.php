<?php

namespace Classivo\ReportCards\Tests;

use Classivo\Pdf\PdfServiceProvider;
use Classivo\ReportCards\ReportCardsServiceProvider;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [ReportCardsServiceProvider::class, PdfServiceProvider::class];
    }

    protected function assertPdf(string $bytes): void
    {
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }
}
