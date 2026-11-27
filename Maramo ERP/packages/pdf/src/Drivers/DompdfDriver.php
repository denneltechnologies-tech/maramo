<?php

namespace Classivo\Pdf\Drivers;

use Barryvdh\DomPDF\Facade\Pdf;
use Classivo\Pdf\PdfRenderer;

class DompdfDriver implements PdfRenderer
{
    /** Options: paper, orientation, dompdf (array of dompdf options such as chroot/fontDir; ignored by Chrome). */
    public function render(string $html, array $options = []): string
    {
        $pdf = Pdf::loadHTML($html)
            ->setPaper($options['paper'] ?? 'a4', $options['orientation'] ?? 'portrait');
        if (! empty($options['dompdf'])) {
            $pdf->setOption($options['dompdf']);
        }

        return (string) $pdf->output();
    }
}
