<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Certificates;

use Academy\Domain\Certificates\Certificate;

/**
 * Minimal single-page PDF (Helvetica) — no external PDF library.
 */
final class SimpleCertificatePdfRenderer
{
    public function render(Certificate $certificate, string $academyName = 'Academy LMS'): string
    {
        $issued = $certificate->issuedAt->setTimezone(new \DateTimeZone('UTC'))->format('d M Y');
        $lines = [
            $academyName,
            '',
            'Certificate of Completion',
            $certificate->certificateLabel,
            '',
            'This is to certify that',
            $certificate->learnerNameSnapshot,
            '',
            'has successfully completed',
            $certificate->courseTitleSnapshot,
            $certificate->versionTitleSnapshot,
            '',
            'Issued: ' . $issued,
            'Certificate number: ' . $certificate->certificateNumber,
            'Status: ' . ucfirst($certificate->status),
        ];

        return $this->buildPdf($lines);
    }

    /**
     * @param list<string> $lines
     */
    private function buildPdf(array $lines): string
    {
        $content = "BT /F1 14 Tf 50 780 Td 16 TL\n";
        $first = true;
        foreach ($lines as $line) {
            $escaped = $this->escape($line === '' ? ' ' : $line);
            if ($first) {
                $content .= '(' . $escaped . ") Tj\n";
                $first = false;
            } else {
                $content .= "T*\n(" . $escaped . ") Tj\n";
            }
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            . "/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $objects[] = '4 0 obj\n<< /Length ' . strlen($content) . " >>\nstream\n"
            . $content . "endstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); ++$i) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private function escape(string $text): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '?';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}
