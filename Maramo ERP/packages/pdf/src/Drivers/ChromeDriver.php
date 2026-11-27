<?php

namespace Classivo\Pdf\Drivers;

use Classivo\Pdf\Exceptions\PdfException;
use Classivo\Pdf\PdfRenderer;
use Closure;
use Spatie\Browsershot\Browsershot;
use Throwable;

class ChromeDriver implements PdfRenderer
{
    /** @param Closure|null $factory returns a Browsershot-like object (injectable for tests) */
    public function __construct(private array $config = [], private ?Closure $factory = null) {}

    public static function available(): bool
    {
        return class_exists(Browsershot::class);
    }

    public function render(string $html, array $options = []): string
    {
        try {
            $shot = $this->factory ? ($this->factory)($html) : Browsershot::html($html);
            $shot->paperSize(...$this->paperMm($options['paper'] ?? 'a4'))
                ->landscape(($options['orientation'] ?? 'portrait') === 'landscape')
                ->showBackground()
                ->timeout((int) ($this->config['timeout'] ?? 60));
            if (! empty($this->config['binary'])) {
                $shot->setChromePath($this->config['binary']);
            }
            if (! empty($this->config['node_binary'])) {
                $shot->setNodeBinary($this->config['node_binary']);
            }
            if (! empty($this->config['npm_binary'])) {
                $shot->setNpmBinary($this->config['npm_binary']);
            }
            if (! empty($this->config['no_sandbox'])) {
                $shot->noSandbox();
            }

            return (string) $shot->pdf();
        } catch (Throwable $e) {
            throw new PdfException('Chrome PDF rendering failed: '.$e->getMessage(), 0, $e);
        }
    }

    /** @return array{0: float, 1: float, 2: string} */
    private function paperMm(string $paper): array
    {
        return match (strtolower($paper)) {
            'a3' => [297, 420, 'mm'],
            'a5' => [148, 210, 'mm'],
            'letter' => [215.9, 279.4, 'mm'],
            'legal' => [215.9, 355.6, 'mm'],
            default => [210, 297, 'mm'],
        };
    }
}
