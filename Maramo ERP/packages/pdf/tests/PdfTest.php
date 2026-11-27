<?php

namespace Classivo\Pdf\Tests;

use Classivo\Pdf\Drivers\ChromeDriver;
use Classivo\Pdf\Drivers\DompdfDriver;
use Classivo\Pdf\Exceptions\PdfException;
use Classivo\Pdf\PdfManager;
use Classivo\Pdf\PdfRenderer;
use Psr\Log\AbstractLogger;

class FakeRenderer implements PdfRenderer
{
    public int $calls = 0;

    public function __construct(private string $out, private bool $fail = false) {}

    public function render(string $html, array $options = []): string
    {
        $this->calls++;
        if ($this->fail) {
            throw new PdfException('boom');
        }

        return $this->out;
    }
}

class FakeShot
{
    public array $log = [];

    public function __call($m, $a)
    {
        $this->log[] = $m;

        return $this;
    }

    public function pdf()
    {
        return '%PDF-chrome';
    }
}

class MemLog extends AbstractLogger
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class PdfTest extends TestCase
{
    private function mgr(array $cfg, ?FakeRenderer $chrome = null, ?bool $avail = true, ?MemLog $log = null, ?FakeRenderer $dom = null): PdfManager
    {
        return new PdfManager($cfg, $log ?? new MemLog, $dom ?? new FakeRenderer('dom'), $chrome, $avail);
    }

    public function test_container_binds_contract_and_default_config(): void
    {
        $this->assertInstanceOf(PdfManager::class, app(PdfRenderer::class));
        $this->assertSame('dompdf', config('classivo-pdf.driver'));
        $this->assertSame([], config('classivo-pdf.chrome_for_locales'));
        $this->assertInstanceOf(PdfRenderer::class, new DompdfDriver);
        $this->assertInstanceOf(PdfRenderer::class, new ChromeDriver);
    }

    public function test_driver_selection(): void
    {
        $m = $this->mgr(['driver' => 'dompdf', 'chrome_for_locales' => ['hi']]);
        $this->assertSame('dompdf', $m->driverFor('en'));
        $this->assertSame('chrome', $m->driverFor('hi'));
        $this->assertSame('dompdf', $m->driverFor(null));
        $this->assertSame('chrome', $this->mgr(['driver' => 'chrome'])->driverFor('en'));
        $this->assertSame('dompdf', $this->mgr(['driver' => 'bogus'])->driverFor('en'));
    }

    public function test_locale_routes_to_chrome_and_default_to_dompdf(): void
    {
        $chrome = new FakeRenderer('chrome');
        $m = $this->mgr(['chrome_for_locales' => ['hi']], $chrome);
        $this->assertSame('chrome', $m->render('<p>x</p>', ['locale' => 'hi']));
        $this->assertSame('dom', $m->render('<p>x</p>', ['locale' => 'en']));
        $this->assertSame(1, $chrome->calls);
    }

    public function test_falls_back_when_chrome_unavailable(): void
    {
        $log = new MemLog;
        $m = $this->mgr(['chrome_for_locales' => ['hi']], new FakeRenderer('chrome'), false, $log);
        $this->assertSame('dom', $m->render('x', ['locale' => 'hi']));
        $this->assertSame('warning', $log->lines[0][0]);
    }

    public function test_falls_back_when_chrome_fails(): void
    {
        $log = new MemLog;
        $m = $this->mgr(['driver' => 'chrome'], new FakeRenderer('c', true), true, $log);
        $this->assertSame('dom', $m->render('x', ['locale' => 'en']));
        $this->assertStringContainsString('boom', $log->lines[0][1]);
    }

    public function test_chrome_driver_uses_browsershot_like_object(): void
    {
        $shot = new FakeShot;
        $d = new ChromeDriver(['binary' => '/x/chrome', 'no_sandbox' => true], fn ($html) => $shot);
        $this->assertSame('%PDF-chrome', $d->render('<p>x</p>', ['orientation' => 'landscape']));
        $this->assertContains('setChromePath', $shot->log);
        $this->assertContains('noSandbox', $shot->log);
        $this->assertContains('landscape', $shot->log);
    }

    public function test_chrome_driver_wraps_errors(): void
    {
        $this->expectException(PdfException::class);
        (new ChromeDriver([], fn () => throw new \RuntimeException('no chrome')))->render('x');
    }
}
