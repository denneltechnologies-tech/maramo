<?php

namespace Classivo\Pdf;

use Classivo\Pdf\Drivers\ChromeDriver;
use Classivo\Pdf\Drivers\DompdfDriver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Picks a driver per call: Chrome for configured locales (when available),
 * otherwise the configured default. Chrome failures fall back to dompdf.
 */
class PdfManager implements PdfRenderer
{
    public function __construct(
        private array $config,
        private ?LoggerInterface $logger = null,
        private ?PdfRenderer $dompdf = null,
        private ?PdfRenderer $chrome = null,
        private ?bool $chromeAvailable = null,
    ) {}

    public function driverFor(?string $locale = null): string
    {
        $wanted = $this->config['driver'] ?? 'dompdf';
        if ($locale !== null && in_array($locale, (array) ($this->config['chrome_for_locales'] ?? []), true)) {
            $wanted = 'chrome';
        }

        return $wanted === 'chrome' ? 'chrome' : 'dompdf';
    }

    /** @param array{paper?: string, orientation?: string, locale?: string} $options */
    public function render(string $html, array $options = []): string
    {
        $locale = $options['locale'] ?? app()->getLocale();

        if ($this->driverFor($locale) === 'chrome') {
            if (! ($this->chromeAvailable ?? ChromeDriver::available())) {
                $this->warn('Chrome PDF driver requested but spatie/browsershot is not installed; using dompdf.');
            } else {
                try {
                    return $this->chrome()->render($html, $options);
                } catch (Throwable $e) {
                    $this->warn('Chrome PDF rendering failed, using dompdf: '.$e->getMessage());
                }
            }
        }

        return $this->dompdf()->render($html, $options);
    }

    private function dompdf(): PdfRenderer
    {
        return $this->dompdf ??= new DompdfDriver;
    }

    private function chrome(): PdfRenderer
    {
        return $this->chrome ??= new ChromeDriver($this->config['chrome'] ?? []);
    }

    private function warn(string $message): void
    {
        ($this->logger ?? app('log'))->warning($message);
    }
}
