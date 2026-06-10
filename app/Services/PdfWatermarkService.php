<?php

namespace App\Services;

use Exception;

class PdfWatermarkService
{
    public function applyWatermark(string $sourcePath, string $outputPath, string $watermarkText): void
    {
        if (! file_exists($sourcePath)) {
            throw new Exception('Source PDF file was not found: ' . $sourcePath);
        }

        $directory = dirname($outputPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdf = new WatermarkedFpdi();

        // Prevent extra blank pages.
        $pdf->SetAutoPageBreak(false, 0);

        try {
            $pageCount = $pdf->setSourceFile($sourcePath);
        } catch (\Throwable $e) {
            throw new Exception('Could not read source PDF: ' . $e->getMessage());
        }

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            try {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $width = $size['width'];
                $height = $size['height'];

                $orientation = $width > $height ? 'L' : 'P';

                $pdf->AddPage($orientation, [$width, $height]);

                // Original PDF page
                $pdf->useTemplate($templateId, 0, 0, $width, $height);

                /*
                |--------------------------------------------------------------------------
                | Margin-only watermark
                |--------------------------------------------------------------------------
                | Keeps watermark away from the main question content.
                |--------------------------------------------------------------------------
                */
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetTextColor(175, 175, 175);

                $shortText = $watermarkText;

                // Top margin
                $topTextWidth = $pdf->GetStringWidth($shortText);
                $topX = max(8, ($width - $topTextWidth) / 2);
                $pdf->Text($topX, 8, $shortText);

                // Left margin - vertical
                $pdf->rotatedText(6, $height / 2 + 35, $shortText, 90);

                // Right margin - vertical
                $pdf->rotatedText($width - 4, $height / 2 - 35, $shortText, 90);
            } catch (\Throwable $e) {
                throw new Exception('Failed on page ' . $pageNo . ': ' . $e->getMessage());
            }
        }

        $pdf->Output('F', $outputPath);
    }
}