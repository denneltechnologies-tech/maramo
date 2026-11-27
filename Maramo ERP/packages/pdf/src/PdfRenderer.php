<?php

namespace Classivo\Pdf;

interface PdfRenderer
{
    /**
     * Render HTML to PDF bytes.
     *
     * @param  array{paper?: string, orientation?: string, locale?: string, dompdf?: array<string,mixed>}  $options
     */
    public function render(string $html, array $options = []): string;
}
