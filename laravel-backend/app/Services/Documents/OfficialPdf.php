<?php

namespace App\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders every official document (receipt, prescription, operative report, consent, hospital file) as an
 * A5 PDF with the shared frame and a real "Page n / N" footer, which DomPDF cannot compute in CSS alone.
 */
final class OfficialPdf
{
    public static function render(string $view, array $data): string
    {
        $dompdf = Pdf::loadView($view, $data)->setPaper('a5', 'portrait')->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
            $font = $fontMetrics->getFont('Helvetica');
            $label = "Page {$pageNumber} / {$pageCount}";
            $width = $fontMetrics->getTextWidth($label, $font, 7);
            $canvas->text($canvas->get_width() - 30 - $width, $canvas->get_height() - 17, $label, $font, 7);
        });

        return $dompdf->output();
    }
}
